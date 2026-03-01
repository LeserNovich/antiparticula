<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

$servername = "127.0.0.1";
$username   = "u450756829_Leser";
$password   = "#s*/gDkR5Pcuba";
$dbname     = "u450756829_PaginaWeb";

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
