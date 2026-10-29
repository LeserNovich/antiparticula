<?php
// Vincular el celular que incluye un Premium de PC (POST codigo, hw del teléfono, claves): la app de Android manda el
// código de la compra de la PC y recibe su propia clave. Responde como canjear.php: ok, motivo, mensaje y claves.
define('TIENDA_COMUN_OK', true);
require __DIR__ . '/../tienda_comun.php';

dash_cabeceras_api();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') dash_responder(405, ['status' => 'error', 'message' => 'Método no permitido']);
$hw = strtoupper(isset($_POST['hw']) ? (string) $_POST['hw'] : '');
if (!dash_equipo_valido($hw)) dash_responder(400, ['status' => 'error', 'message' => 'hw']);
try {
    list($conn, $cfg) = dash_conectar();
    $ip = tienda_ip();
    if (tienda_bloqueada($conn, $ip)) {
        dash_responder(429, ['status' => 'ok', 'ok' => false, 'claves' => [],
                             'mensaje' => 'Demasiados códigos equivocados. Espera una hora e intenta de nuevo.']);
    }
    $codigo = tienda_codigo_normalizar(isset($_POST['codigo']) ? $_POST['codigo'] : '');
    if ($codigo === null) {
        tienda_anotar_fallo($conn, $ip);
        dash_responder(200, ['status' => 'ok', 'ok' => false, 'claves' => [],
                             'mensaje' => 'Ese código no tiene la forma correcta. Es AP- y doce letras y números.']);
    }
    $r = tienda_vincular_celular($conn, $cfg, $codigo, $hw);
    if ($r['motivo'] === 'no_existe') tienda_anotar_fallo($conn, $ip);
    dash_responder(200, ['status' => 'ok', 'ok' => $r['ok'], 'motivo' => $r['motivo'], 'mensaje' => $r['mensaje'], 'claves' => $r['claves']]);
} catch (Exception $e) {
    error_log('tienda/celular.php: ' . $e->getMessage());
    dash_responder(500, ['status' => 'error', 'message' => 'interno']);
}
