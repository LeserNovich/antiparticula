<?php
// Canjear el código de una compra en una PC (POST codigo, hw, claves): compra hecha en la página, reinstalación,
// o cambio de computadora (uno automático cada 12 meses). Responde ok, mensaje (para el cliente) y las claves.
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
    tienda_registrar_reportadas($conn, $cfg, $hw, isset($_POST['claves']) ? substr((string) $_POST['claves'], 0, 4000) : '');
    $r = tienda_canjear($conn, $cfg, $codigo, $hw);
    if ($r['motivo'] === 'no_existe') tienda_anotar_fallo($conn, $ip);
    dash_responder(200, ['status' => 'ok', 'ok' => $r['ok'], 'motivo' => $r['motivo'], 'mensaje' => $r['mensaje'], 'claves' => $r['claves']]);
} catch (Exception $e) {
    error_log('tienda/canjear.php: ' . $e->getMessage());
    dash_responder(500, ['status' => 'error', 'message' => 'interno']);
}
