<?php
// Los avisos de Stripe (POST). Configurar en Stripe: Developers > Webhooks > Add endpoint
//   https://www.antiparticula.com/tienda/webhook.php
//   Eventos: checkout.session.completed, checkout.session.async_payment_succeeded,
//            checkout.session.async_payment_failed, checkout.session.expired, invoice.paid,
//            charge.refunded, charge.dispute.created, charge.dispute.closed (los tres últimos: reembolsos y
//            contracargos, desde el 2026-10-30; requieren sql/2026-10-30_reembolsos.sql)
// y copiar su «Signing secret» (whsec_…) a config_db.php como 'stripe_webhook_secreto'.
// Responde 200 a lo que ya atendió o no le interesa; 500 si algo falló (Stripe reintenta).
define('TIENDA_COMUN_OK', true);
require __DIR__ . '/../tienda_comun.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$cuerpo = file_get_contents('php://input');
list($conn, $cfg) = dash_conectar();
$firma = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
// El motivo va en la respuesta: Stripe la guarda en el detalle de cada envío (Workbench › Webhooks), que solo ve el dueño
if (empty($cfg['stripe_webhook_secreto'])) {
    dash_responder(400, ['error' => 'firma', 'detalle' => 'falta stripe_webhook_secreto en config_db.php']);
}
if (!tienda_firma_stripe_valida($cuerpo, $firma, $cfg['stripe_webhook_secreto'])) {
    dash_responder(400, ['error' => 'firma', 'detalle' => 'la firma no coincide: stripe_webhook_secreto debe ser el whsec_ de ESTE destino '
                         . 'y del mismo modo (prueba o real) que la llave']);
}
$evento = json_decode($cuerpo, true);
if (!is_array($evento) || empty($evento['id']) || empty($evento['type']) || !isset($evento['data']['object'])) {
    dash_responder(400, ['error' => 'evento']);
}
$st = $conn->prepare('SELECT 1 FROM tienda_eventos WHERE id = :id');
$st->execute([':id' => $evento['id']]);
if ($st->fetchColumn()) dash_responder(200, ['ok' => true, 'repetido' => true]);

// El id de un campo que puede venir como texto («pi_…») o expandido como objeto.
function webhook_id(array $o, $campo) {
    if (!isset($o[$campo])) return null;
    if (is_string($o[$campo])) return $o[$campo];
    return is_array($o[$campo]) && isset($o[$campo]['id']) && is_string($o[$campo]['id']) ? $o[$campo]['id'] : null;
}

// Con qué cobro se pagó una factura: payment_intent en la API 2024-06-20; en las de 2025 en adelante, en sus pagos.
function webhook_cobro_factura(array $f) {
    $pi = webhook_id($f, 'payment_intent');
    if ($pi === null && isset($f['payments']['data']) && is_array($f['payments']['data'])) {
        foreach ($f['payments']['data'] as $p) {
            if (isset($p['payment']) && is_array($p['payment']) && ($pi = webhook_id($p['payment'], 'payment_intent'))) break;
        }
    }
    return $pi;
}

// Una sesión de cobro terminada (pagada con tarjeta, u OXXO esperando pago) o un pago de OXXO que ya llegó
function webhook_sesion(PDO $conn, array $cfg, array $s) {
    $meta = isset($s['metadata']) && is_array($s['metadata']) ? $s['metadata'] : [];
    $plan = isset($meta['plan']) ? $meta['plan'] : '';
    if (tienda_plan($plan) === null) {
        error_log('tienda: sesión ' . (isset($s['id']) ? $s['id'] : '?') . ' sin un plan de la tienda (¿un enlace de pago viejo?)');
        return;
    }
    $hw = strtoupper((string) (isset($meta['hw']) && $meta['hw'] !== '' ? $meta['hw']
                                : (isset($s['client_reference_id']) ? $s['client_reference_id'] : '')));
    $estado_pago = isset($s['payment_status']) ? $s['payment_status'] : '';
    $email = isset($s['customer_details']['email']) ? $s['customer_details']['email']
           : (isset($s['customer_email']) ? $s['customer_email'] : null);
    $r = tienda_registrar_sesion($conn, $cfg, [
        'sesion'      => (string) $s['id'],
        'plan'        => $plan,
        'hw'          => dash_equipo_valido($hw) ? $hw : null,
        'origen'      => isset($meta['origen']) && in_array($meta['origen'], ['pos', 'panel', 'web'], true) ? $meta['origen'] : 'web',
        'email'       => is_string($email) ? substr($email, 0, 255) : null,
        'monto'       => isset($s['amount_total']) ? (int) $s['amount_total'] : 0,
        'suscripcion' => isset($s['subscription']) && is_string($s['subscription']) ? $s['subscription'] : null,
        'pagada'      => $estado_pago === 'paid' || $estado_pago === 'no_payment_required',
        'intento'     => webhook_id($s, 'payment_intent'),   // null en una mensualidad: lo trae su primera factura
        'cliente'     => webhook_id($s, 'customer'),
    ]);
    if ($r['recien_pagada'] && !tienda_correo_compra($cfg, $r['compra'])) {
        error_log('tienda: no se pudo mandar el correo de la compra ' . $r['compra']['id']);
    }
}

// Cada mes de una suscripción. El primero lo dio la sesión (billing_reason = subscription_create): de esa factura
// solo se anota con qué cobro se pagó, para reconocer su reembolso.
function webhook_factura(PDO $conn, array $cfg, array $f) {
    $razon = isset($f['billing_reason']) ? $f['billing_reason'] : '';
    if (($razon !== 'subscription_cycle' && $razon !== 'subscription_create') || empty($f['id'])) return;
    $sus = isset($f['subscription']) && is_string($f['subscription']) ? $f['subscription'] : null;
    if ($sus === null && isset($f['parent']['subscription_details']['subscription'])) {
        $sus = $f['parent']['subscription_details']['subscription'];   // API de Stripe 2025-03 en adelante
    }
    if (!is_string($sus)) return;
    if ($razon === 'subscription_create') {
        tienda_anotar_cobro_inicial($conn, $sus, webhook_cobro_factura($f));
        return;
    }
    if (tienda_renovar($conn, $cfg, $sus, (string) $f['id'], webhook_cobro_factura($f)) === null) {
        error_log('tienda: renovación de una suscripción que no es de la tienda: ' . $sus);
    }
}

// Un reembolso (llega uno por cada reembolso del cobro). Solo el completo retira lo que dio ese pago: uno parcial (una
// cortesía) no quita Premium.
function webhook_reembolso(PDO $conn, array $cargo) {
    $monto = isset($cargo['amount']) ? (int) $cargo['amount'] : 0;
    $completo = !empty($cargo['refunded'])
             || ($monto > 0 && isset($cargo['amount_refunded']) && (int) $cargo['amount_refunded'] >= $monto);
    $pi = webhook_id($cargo, 'payment_intent');
    if (!$completo) {
        error_log('tienda: reembolso parcial de ' . $pi . ': Premium no cambia');
        return;
    }
    $r = tienda_retirar_pago($conn, $pi, webhook_id($cargo, 'customer'), 'reembolso');
    if ($r === null) error_log('tienda: reembolso de un cobro que no es de la tienda (o falta la migración): ' . $pi);
}

// Un contracargo: al abrirse se suspende lo que dio ese pago; al cerrarse ganado, se devuelve.
function webhook_disputa(PDO $conn, array $d, $abierta) {
    $pi = webhook_id($d, 'payment_intent');
    if ($abierta) {
        if (tienda_retirar_pago($conn, $pi, null, 'disputa') === null) {
            error_log('tienda: contracargo de un cobro que no es de la tienda (o falta la migración): ' . $pi);
        }
    } else {
        tienda_cerrar_disputa($conn, $pi, isset($d['status']) ? (string) $d['status'] : '');
    }
}

try {
    $obj = $evento['data']['object'];
    switch ($evento['type']) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            webhook_sesion($conn, $cfg, $obj);
            break;
        case 'checkout.session.async_payment_failed':
        case 'checkout.session.expired':
            if (isset($obj['id'])) tienda_marcar_vencida($conn, (string) $obj['id']);
            break;
        case 'invoice.paid':
            webhook_factura($conn, $cfg, $obj);
            break;
        case 'charge.refunded':
            webhook_reembolso($conn, $obj);
            break;
        case 'charge.dispute.created':
            webhook_disputa($conn, $obj, true);
            break;
        case 'charge.dispute.closed':
            webhook_disputa($conn, $obj, false);
            break;
    }
    $conn->prepare('INSERT IGNORE INTO tienda_eventos (id, tipo) VALUES (:id, :t)')
         ->execute([':id' => $evento['id'], ':t' => substr($evento['type'], 0, 80)]);
    dash_responder(200, ['ok' => true]);
} catch (Exception $e) {
    error_log('tienda/webhook.php (' . $evento['type'] . '): ' . $e->getMessage());
    // Lo más común: la llave de ventas no está donde dice config_db.php (llave_ventas). Stripe reintenta solo.
    dash_responder(500, ['error' => 'interno', 'detalle' => substr($e->getMessage(), 0, 300)]);
}
