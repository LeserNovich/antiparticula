<?php
// ============================================
// ENDPOINT: Registro de Comentarios y Sugerencias
// Antiparticula Punto de Venta
// ============================================

// --- 1. VERIFICACIÓN DE SEGURIDAD ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Método no permitido. Use POST.'
    ]);
    exit;
}

// --- 2. CONFIGURACIÓN DE BASE DE DATOS ---
$servername = "127.0.0.1";
$username = "u450756829_Leser";
$password = "#s*/gDkR5Pcuba";
$dbname = "u450756829_PaginaWeb";

// --- 3. OBTENER DATOS DEL FORMULARIO ---
$comentario = isset($_POST['comentario']) ? trim($_POST['comentario']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : null;

// --- 4. VALIDACIONES ---
if (empty($comentario)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'El comentario es obligatorio'
    ]);
    exit;
}

// Validar longitud del comentario
if (strlen($comentario) < 10) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'El comentario debe tener al menos 10 caracteres'
    ]);
    exit;
}

if (strlen($comentario) > 1000) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'El comentario no puede exceder 1000 caracteres'
    ]);
    exit;
}

// Validar email si se proporcionó
if (!empty($email)) {
    if (strlen($email) > 255) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'El email es demasiado largo'
        ]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'El email no tiene un formato válido'
        ]);
        exit;
    }

    // Convertir a NULL si está vacío
    if (empty($email)) {
        $email = null;
    }
}

// --- 5. OBTENER DATOS DEL CLIENTE (IP y Geolocalización) ---
$ip_address = $_SERVER['REMOTE_ADDR'];

// Obtener geolocalización desde ip-api.com
$geo_url = "http://ip-api.com/json/{$ip_address}";
$geo_data_json = @file_get_contents($geo_url);
$geo_data = json_decode($geo_data_json);

$pais = isset($geo_data->country) ? $geo_data->country : 'Desconocido';
$ciudad = isset($geo_data->city) ? $geo_data->city : 'Desconocida';

// --- 6. INSERTAR EN BASE DE DATOS ---
try {
    // Crear conexión PDO
    $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Configurar zona horaria
    date_default_timezone_set('America/Mexico_City');
    $conn->exec("SET time_zone = '-06:00'");

    // Preparar sentencia SQL
    $stmt = $conn->prepare("
        INSERT INTO comentarios_sugerencias
        (comentario, email, ip_address, pais, ciudad, fecha_hora)
        VALUES
        (:comentario, :email, :ip_address, :pais, :ciudad, NOW())
    ");

    // Vincular parámetros
    $stmt->bindParam(':comentario', $comentario, PDO::PARAM_STR);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
    $stmt->bindParam(':pais', $pais, PDO::PARAM_STR);
    $stmt->bindParam(':ciudad', $ciudad, PDO::PARAM_STR);

    // Ejecutar inserción
    $stmt->execute();

    // Respuesta exitosa
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'exitoso',
        'message' => 'Comentario registrado correctamente',
        'id' => $conn->lastInsertId()
    ]);

} catch(PDOException $e) {
    // Error en base de datos
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al guardar en base de datos',
        'error' => $e->getMessage()
    ]);
}

// Cerrar conexión
$conn = null;
?>
