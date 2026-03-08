<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

$cfg_path = dirname(__DIR__) . '/config_db.php';
if (!file_exists($cfg_path)) {
    $cfg = ['host' => '127.0.0.1', 'dbname' => 'u450756829_PaginaWeb',
            'username' => 'u450756829_Leser', 'password' => ''];
} else {
    $cfg = require $cfg_path;
}
$servername = $cfg['host'];
$username   = $cfg['username'];
$password   = $cfg['password'];
$dbname     = $cfg['dbname'];

try {
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Total de negocios activos (IPs únicas)
    $stmt = $conn->query("SELECT COUNT(DISTINCT ip_address) AS total FROM registros_descargas");
    $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Descargas del mes actual
    $stmt2 = $conn->query(
        "SELECT COUNT(*) AS mes FROM registros_descargas
         WHERE MONTH(timestamp) = MONTH(NOW())
           AND YEAR(timestamp)  = YEAR(NOW())"
    );
    $este_mes = (int)$stmt2->fetch(PDO::FETCH_ASSOC)['mes'];

    echo json_encode(['total' => $total, 'este_mes' => $este_mes]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => true]);
}

$conn = null;
?>
