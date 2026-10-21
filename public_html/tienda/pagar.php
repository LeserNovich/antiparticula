<?php
// Abre el cobro de un plan (GET): crea la sesión de Stripe Checkout y manda ahí al cliente.
//   ?plan=premium_anual&hw=<Hardware ID>&origen=pos   desde el programa: la clave llega sola a esa PC
//   ?plan=premium_anual&panel=<ID del panel>          desde el panel del celular (se busca la PC del panel)
//   ?plan=premium_anual                               desde la página: se entrega un código para canjear
//   …&pais=ES   (opcional) el país de quien compra, como lo dio planes.php: fuera de México, sin OXXO (Stripe convierte)
define('TIENDA_COMUN_OK', true);
require __DIR__ . '/../tienda_comun.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function pagar_pagina($codigo_http, $titulo, $texto, $detalle = null) {
    http_response_code($codigo_http);
    header('Content-Type: text/html; charset=UTF-8');
    $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $x = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang=\"es\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<title>$t</title><style>body{font-family:system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;color:#1d2433}"
       . "a{color:#2c6e95}</style></head><body><h1 style=\"font-size:22px\">$t</h1><p>$x</p>"
       . "<p><a href=\"https://wa.me/525584323945\">Escríbenos por WhatsApp</a> · <a href=\"/puntodeventa#pricing\">Ver los planes</a></p>"
       . ($detalle !== null ? "<p style=\"margin-top:40px;font-size:12px;color:#8a94a3\">Detalle técnico: "
          . htmlspecialchars($detalle, ENT_QUOTES, 'UTF-8') . "</p>" : '')
       . "</body></html>";
    exit;
}

$plan_id = isset($_GET['plan']) ? (string) $_GET['plan'] : '';
$plan = tienda_plan($plan_id);
if ($plan === null) pagar_pagina(404, 'Ese plan no existe', 'Elige uno de los planes de la página.');

$origen = isset($_GET['origen']) && in_array($_GET['origen'], ['pos', 'panel', 'web'], true) ? $_GET['origen'] : 'web';
$hw = isset($_GET['hw']) ? strtoupper(trim((string) $_GET['hw'])) : '';
$pais = isset($_GET['pais']) && preg_match('/^[A-Z]{2}$/', (string) $_GET['pais']) ? (string) $_GET['pais'] : null;

try {
    list($conn, $cfg) = dash_conectar();
    if ($hw === '' && isset($_GET['panel']) && preg_match('/^[0-9A-Za-z_-]{1,50}$/', (string) $_GET['panel'])) {
        $origen = 'panel';
        try {
            // La columna la agregó sql/2026-10-21_tienda.sql y la llena el POS 3.5.0: sin ella, compra con código
            $st = $conn->prepare('SELECT hw_licencia FROM dashboard_pos WHERE hardware_id = :id LIMIT 1');
            $st->execute([':id' => (string) $_GET['panel']]);
            $hw = strtoupper((string) $st->fetchColumn());
        } catch (PDOException $e) {
            $hw = '';
        }
    }
    $hw = dash_equipo_valido($hw) ? $hw : null;

    if ($hw !== null && $plan['tipo'] === 'premium' && tienda_vence_actual($conn, $hw, 'premium') === TIENDA_PARA_SIEMPRE) {
        header('Location: ' . tienda_url($cfg) . '/compra?ya=siempre', true, 303);
        exit;
    }
    $sesion = tienda_stripe($cfg, '/v1/checkout/sessions', tienda_parametros_sesion($cfg, $plan_id, $hw, $origen, $pais));
} catch (Exception $e) {
    error_log('tienda/pagar.php: ' . $e->getMessage());
    tienda_ultimo_error('error interno: ' . get_class($e));
    $sesion = null;
}
if (!is_array($sesion) || empty($sesion['url']) || !is_string($sesion['url'])) {
    pagar_pagina(503, 'No se pudo abrir el cobro', 'Intenta de nuevo en un momento. Si sigue sin abrir, escríbenos y te ayudamos a contratarlo.',
                 tienda_ultimo_error());
}
header('Location: ' . $sesion['url'], true, 303);
