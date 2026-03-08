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
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    date_default_timezone_set('America/Mexico_City');
    $conn->exec("SET time_zone = '-06:00'");

    // Rate limit: máximo 2 comentarios por IP en 10 minutos; 5 en 24 horas
    $rl = $conn->prepare(
        "SELECT
            SUM(fecha_hora >= NOW() - INTERVAL 10 MINUTE) AS recientes,
            SUM(fecha_hora >= NOW() - INTERVAL 24 HOUR)   AS dia
         FROM comentarios_sugerencias
         WHERE ip_address = ?"
    );
    $rl->execute([$ip_address]);
    $counts = $rl->fetch(PDO::FETCH_ASSOC);

    if ((int)$counts['recientes'] >= 2) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Espera unos minutos antes de enviar otro comentario.']);
        exit;
    }
    if ((int)$counts['dia'] >= 5) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Has alcanzado el límite de comentarios por hoy.']);
        exit;
    }

    // Insertar
    $stmt = $conn->prepare("
        INSERT INTO comentarios_sugerencias
        (comentario, email, ip_address, pais, ciudad, fecha_hora)
        VALUES
        (:comentario, :email, :ip_address, :pais, :ciudad, NOW())
    ");
    $stmt->bindParam(':comentario', $comentario, PDO::PARAM_STR);
    $stmt->bindParam(':email',      $email,      PDO::PARAM_STR);
    $stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
    $stmt->bindParam(':pais',       $pais,       PDO::PARAM_STR);
    $stmt->bindParam(':ciudad',     $ciudad,     PDO::PARAM_STR);
    $stmt->execute();

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'exitoso', 'message' => 'Comentario registrado correctamente']);

} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
    error_log('registrar_comentario.php PDO error: ' . $e->getMessage());
}

$conn = null;
?>
