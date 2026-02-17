<?php
// Permitir CORS para que la app Java pueda llamar este endpoint
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
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

// Validar que los JSON sean válidos
if (json_decode($ultimas_ventas) === null)  $ultimas_ventas  = '[]';
if (json_decode($historico_semana) === null) $historico_semana = '[]';

// Conexión a MySQL
$servername = "127.0.0.1";
$username   = "u450756829_Leser";
$password   = "#s*/gDkR5Pcuba";
$dbname     = "u450756829_PaginaWeb";

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
