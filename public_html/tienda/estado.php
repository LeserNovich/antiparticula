<?php
// Lo que muestra la página /compra después de pagar (GET sesion=cs_…). Stripe manda al cliente de vuelta antes o
// después de avisarnos: mientras el aviso no llega, «esperando».
define('TIENDA_COMUN_OK', true);
require __DIR__ . '/../tienda_comun.php';

dash_cabeceras_api();
$sesion = isset($_GET['sesion']) ? (string) $_GET['sesion'] : '';
if (!preg_match('/^cs_[A-Za-z0-9_]{10,250}$/', $sesion)) dash_responder(400, ['status' => 'error', 'message' => 'sesion']);
try {
    list($conn, $cfg) = dash_conectar();
    $c = tienda_compra_por($conn, 'sesion', $sesion);
    if ($c === null) dash_responder(200, ['status' => 'ok', 'estado' => 'esperando']);
    $plan = tienda_plan($c['plan']);
    dash_responder(200, [
        'status'       => 'ok',
        'estado'       => $c['estado'],                    // pendiente (OXXO) | pagada | vencida | reembolsada | disputada
        'nombre'       => $plan ? $plan['nombre'] : 'Premium',
        'tipo'         => $c['tipo'],
        'codigo'       => tienda_codigo_mostrar($c['codigo']),
        'con_pc'       => (bool) $c['hardware_id'],          // la clave llega sola a esa PC
        'para_siempre' => $plan ? $plan['meses'] === 0 : false,
        'renovable'    => $plan ? $plan['modo'] === 'suscripcion' : false,
        'email'        => $c['email'],
        'prueba'       => tienda_es_prueba($c),   // modo de prueba de Stripe: la clave dura 2 días
    ]);
} catch (Exception $e) {
    error_log('tienda/estado.php: ' . $e->getMessage());
    dash_responder(500, ['status' => 'error', 'message' => 'interno']);
}
