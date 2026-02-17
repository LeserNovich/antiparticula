<?php
// CORS abierto para el dashboard PWA
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

// Solo acepta GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$hardware_id = isset($_GET['id']) ? trim($_GET['id']) : '';
if (empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Parámetro id requerido']);
    exit;
}

$servername = "127.0.0.1";
$username   = "u450756829_Leser";
$password   = "#s*/gDkR5Pcuba";
$dbname     = "u450756829_PaginaWeb";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET time_zone = '-06:00'");

    $stmt = $conn->prepare(
        "SELECT *, TIMESTAMPDIFF(MINUTE, ultima_sync, NOW()) AS hace_minutos
         FROM dashboard_pos
         WHERE hardware_id = :id
         LIMIT 1"
    );
    $stmt->bindParam(':id', $hardware_id);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'No encontrado']);
        exit;
    }

    // Decodificar los JSON guardados
    $row['ultimas_ventas']   = json_decode($row['ultimas_ventas'],   true) ?: [];
    $row['historico_semana'] = json_decode($row['historico_semana'], true) ?: [];
    $row['hace_minutos']     = (int) $row['hace_minutos'];
    $row['status']           = 'exitoso';

    echo json_encode($row, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
    error_log('get_dashboard_data.php PDO error: ' . $e->getMessage());
}

$conn = null;
?>
