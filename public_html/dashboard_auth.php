<?php
// ────────────────────────────────────────────────────────────────────────────
// Entrada al panel móvil protegido con PIN.
//
//   POST id=<hardware_id>&pin=<6 a 8 dígitos>
//     200 {status:exitoso, protegido:false}                      el panel no tiene PIN
//     200 {status:exitoso, protegido:true, token, expira}        PIN correcto
//     401 {status:error, codigo:pin_incorrecto, restantes:N}
//     429 {status:error, codigo:bloqueado, reintentar_en:SEG}    8 fallos seguidos: 15 min
//     404 / 400 / 405
//
// El token (30 días) se manda después en la cabecera X-Dash-Token a
// get_dashboard_data.php. Cambiar el PIN en el POS invalida los tokens anteriores.
// Ver dashboard_comun.php para el formato del token y el bloqueo.
// ────────────────────────────────────────────────────────────────────────────

define('DASH_COMUN_OK', true);
require_once __DIR__ . '/dashboard_comun.php';

// El panel y este endpoint son del mismo origen; se deja la misma lista que get_dashboard_data.php
$allowed_origins = ['https://www.antiparticula.com', 'https://antiparticula.com'];
$origin          = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$origen_ok       = in_array($origin, $allowed_origins, true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origen_ok) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    header('Vary: Origin');
    http_response_code(204);
    exit;
}
if ($origen_ok) header("Access-Control-Allow-Origin: $origin");
header('Vary: Origin');
dash_cabeceras_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dash_responder(405, ['status' => 'error', 'message' => 'Método no permitido']);
}

$hardware_id = isset($_POST['id']) && is_string($_POST['id']) ? trim($_POST['id']) : '';
$pin         = isset($_POST['pin']) && is_string($_POST['pin']) ? $_POST['pin'] : '';
if (!preg_match('/^[a-zA-Z0-9_\-]{4,50}$/', $hardware_id)) {
    dash_responder(400, ['status' => 'error', 'message' => 'id inválido']);
}

try {
    list($conn, $cfg) = dash_conectar();
    $prot = dash_proteccion($conn, $hardware_id);

    if (!$prot['existe']) {
        dash_responder(404, ['status' => 'error', 'message' => 'No encontrado']);
    }
    if ($prot['pin_hash'] === null) {
        dash_responder(200, ['status' => 'exitoso', 'protegido' => false]);
    }

    $v = dash_verificar_pin($conn, $hardware_id, $pin);
    if ($v['ok']) {
        $t = dash_token_emitir($hardware_id, $prot['pin_hash'], dash_secreto($cfg));
        dash_responder(200, ['status' => 'exitoso', 'protegido' => true, 'token' => $t['token'], 'expira' => $t['expira']]);
    }
    if ($v['codigo'] === 'bloqueado') {
        dash_responder(429, ['status' => 'error', 'codigo' => 'bloqueado', 'reintentar_en' => $v['reintentar_en']]);
    }
    dash_responder(401, ['status' => 'error', 'codigo' => 'pin_incorrecto', 'restantes' => $v['restantes']]);

} catch (PDOException $e) {
    error_log('dashboard_auth.php PDO error: ' . $e->getMessage());
    dash_responder(500, ['status' => 'error', 'message' => 'Error de base de datos']);
}
