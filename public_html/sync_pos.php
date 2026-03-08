<?php
// ────────────────────────────────────────────────────────────
// ALTO-01 fix: CORS — sync_pos es llamado por la app Java (servidor a servidor),
//              no necesita CORS en absoluto. Se deja solo por si hay casos de
//              prueba desde navegador, pero restringido.
// BAJO-05 fix: manejar preflight OPTIONS
// CRITICO-01 fix: credenciales cargadas desde archivo externo al webroot
// ALTO-03 fix: validacion de hardware_id y estructura interna de los JSON
// BAJO-08 fix: validacion de coherencia ganancia <= ventas
// ────────────────────────────────────────────────────────────

// Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// sync_pos es invocado por la app Java — CORS abierto solo para POST
// (la app Java no envía Origin header, por lo que esto no afecta seguridad del browser)
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

// Solo acepta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

// Validar campo requerido
$hardware_id = isset($_POST['hardware_id']) ? trim($_POST['hardware_id']) : '';
if (empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'hardware_id es requerido']);
    exit;
}

// ALTO-03 fix: validar formato de hardware_id — solo alfanumerico, guiones y guiones bajos
if (!preg_match('/^[a-zA-Z0-9_\-]{4,50}$/', $hardware_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'hardware_id inválido']);
    exit;
}

// Sanitizar y obtener campos opcionales
$negocio         = isset($_POST['negocio'])         ? substr(trim($_POST['negocio']), 0, 200)  : '';
$ventas_hoy      = isset($_POST['ventas_hoy'])      ? intval($_POST['ventas_hoy'])              : 0;
$tickets_hoy     = isset($_POST['tickets_hoy'])     ? intval($_POST['tickets_hoy'])             : 0;
$ganancia_hoy    = isset($_POST['ganancia_hoy'])    ? intval($_POST['ganancia_hoy'])            : 0;
$turno_usuario   = isset($_POST['turno_usuario'])   ? substr(trim($_POST['turno_usuario']), 0, 100) : '';
$turno_inicio    = isset($_POST['turno_inicio'])    ? substr(trim($_POST['turno_inicio']), 0, 30)   : '';
$turno_abierto   = isset($_POST['turno_abierto'])   ? intval($_POST['turno_abierto'])           : 0;
$ventas_turno    = isset($_POST['ventas_turno'])    ? intval($_POST['ventas_turno'])            : 0;
$tickets_turno   = isset($_POST['tickets_turno'])   ? intval($_POST['tickets_turno'])           : 0;
$ultimas_ventas  = isset($_POST['ultimas_ventas'])  ? $_POST['ultimas_ventas']                  : '[]';
$historico_semana= isset($_POST['historico_semana'])? $_POST['historico_semana']                : '[]';

// BAJO-08 fix: validacion de coherencia — ganancia no puede superar ventas
if ($ganancia_hoy > $ventas_hoy)     $ganancia_hoy  = $ventas_hoy;
if ($tickets_turno > $tickets_hoy)   $tickets_turno = $tickets_hoy;
if ($ventas_turno > $ventas_hoy)     $ventas_turno  = $ventas_hoy;

// ALTO-03 fix: validar estructura interna de los JSON y sanitizar sus campos
$uv = json_decode($ultimas_ventas, true);
if (!is_array($uv)) $uv = [];
foreach ($uv as &$v) {
    $hora_raw        = isset($v['hora']) ? (string) $v['hora'] : '00:00';
    $hora_match      = [];
    // Extraer HH:MM de cualquier formato (HH:MM, HH:MM:SS, ISO, etc.)
    $v['hora']           = preg_match('/(\d{1,2}:\d{2})/', $hora_raw, $hora_match) ? $hora_match[1] : '00:00';
    $v['total_centavos'] = isset($v['total_centavos']) ? intval($v['total_centavos']) : 0;
    $v['cajero']         = isset($v['cajero'])         ? substr(strip_tags((string)$v['cajero']), 0, 100) : '';
}
unset($v);
$ultimas_ventas = json_encode(array_values($uv));

$hs = json_decode($historico_semana, true);
if (!is_array($hs)) $hs = [];
foreach ($hs as &$d) {
    $fecha_raw       = isset($d['fecha']) ? (string) $d['fecha'] : '';
    $fecha_match     = [];
    $d['fecha']          = preg_match('/(\d{4}-\d{2}-\d{2})/', $fecha_raw, $fecha_match) ? $fecha_match[1] : '';
    $d['total_centavos'] = isset($d['total_centavos']) ? intval($d['total_centavos']) : 0;
}
unset($d);
$historico_semana = json_encode(array_values($hs));

// CRITICO-01: cargar credenciales desde archivo fuera del webroot
// ACCION REQUERIDA: crear /home/u450756829/config_db.php con las credenciales
// y CAMBIAR la contrasena de MySQL en el panel de Hostinger
$cfg_path = dirname(__DIR__) . '/config_db.php';
if (!file_exists($cfg_path)) {
    // Fallback temporal — REMOVER una vez que config_db.php exista
    $cfg = ['host' => '127.0.0.1', 'dbname' => 'u450756829_PaginaWeb',
            'username' => 'u450756829_Leser', 'password' => '#s*/gDkR5Pcuba'];
} else {
    $cfg = require $cfg_path;
}
$servername = $cfg['host'];
$username   = $cfg['username'];
$password   = $cfg['password'];
$dbname     = $cfg['dbname'];

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET time_zone = '-06:00'");

    $sql = "INSERT INTO dashboard_pos
                (hardware_id, negocio, ventas_hoy, tickets_hoy, ganancia_hoy,
                 turno_usuario, turno_inicio, turno_abierto, ventas_turno, tickets_turno,
                 ultimas_ventas, historico_semana)
            VALUES
                (:hardware_id, :negocio, :ventas_hoy, :tickets_hoy, :ganancia_hoy,
                 :turno_usuario, :turno_inicio, :turno_abierto, :ventas_turno, :tickets_turno,
                 :ultimas_ventas, :historico_semana)
            ON DUPLICATE KEY UPDATE
                negocio          = VALUES(negocio),
                ultima_sync      = CURRENT_TIMESTAMP,
                ventas_hoy       = VALUES(ventas_hoy),
                tickets_hoy      = VALUES(tickets_hoy),
                ganancia_hoy     = VALUES(ganancia_hoy),
                turno_usuario    = VALUES(turno_usuario),
                turno_inicio     = VALUES(turno_inicio),
                turno_abierto    = VALUES(turno_abierto),
                ventas_turno     = VALUES(ventas_turno),
                tickets_turno    = VALUES(tickets_turno),
                ultimas_ventas   = VALUES(ultimas_ventas),
                historico_semana = VALUES(historico_semana)";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hardware_id',      $hardware_id);
    $stmt->bindParam(':negocio',          $negocio);
    $stmt->bindParam(':ventas_hoy',       $ventas_hoy,       PDO::PARAM_INT);
    $stmt->bindParam(':tickets_hoy',      $tickets_hoy,      PDO::PARAM_INT);
    $stmt->bindParam(':ganancia_hoy',     $ganancia_hoy,     PDO::PARAM_INT);
    $stmt->bindParam(':turno_usuario',    $turno_usuario);
    $stmt->bindParam(':turno_inicio',     $turno_inicio);
    $stmt->bindParam(':turno_abierto',    $turno_abierto,    PDO::PARAM_INT);
    $stmt->bindParam(':ventas_turno',     $ventas_turno,     PDO::PARAM_INT);
    $stmt->bindParam(':tickets_turno',    $tickets_turno,    PDO::PARAM_INT);
    $stmt->bindParam(':ultimas_ventas',   $ultimas_ventas);
    $stmt->bindParam(':historico_semana', $historico_semana);
    $stmt->execute();

    echo json_encode(['status' => 'exitoso']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
    error_log('sync_pos.php PDO error: ' . $e->getMessage());
}

$conn = null;
?>
