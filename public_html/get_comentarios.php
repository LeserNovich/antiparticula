<?php
header('Content-Type: application/json; charset=UTF-8');

// Solo GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$cfg_path = dirname(__DIR__) . '/config_db.php';
if (!file_exists($cfg_path)) {
    $cfg = ['host' => '127.0.0.1', 'dbname' => 'u450756829_PaginaWeb',
            'username' => 'u450756829_Leser', 'password' => ''];
} else {
    $cfg = require $cfg_path;
}

try {
    $conn = new PDO(
        "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4",
        $cfg['username'],
        $cfg['password']
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET time_zone = '-06:00'");

    // Solo devuelve campos públicos — nunca email ni ip_address.
    // Solo las opiniones que alguien publicó desde el formulario de la página: ese formulario manda siempre
    // «nombre» (vacío si no lo escribe) y las estrellas que eligió. La misma tabla recibe los mensajes de la
    // ventana «Comentarios y sugerencias» del Punto de Venta, que nunca mandan nombre: son privados (dudas,
    // fallas, contraseñas) y registrar_comentario.php les guarda 5 estrellas que nadie puso.
    $stmt = $conn->query(
        "SELECT id, comentario, nombre, tipo_negocio, calificacion, pais, ciudad, fecha_hora
         FROM comentarios_sugerencias
         WHERE nombre IS NOT NULL
         ORDER BY fecha_hora DESC
         LIMIT 30"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'exitoso', 'comentarios' => $rows], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
    error_log('get_comentarios.php PDO error: ' . $e->getMessage());
}
$conn = null;
?>
