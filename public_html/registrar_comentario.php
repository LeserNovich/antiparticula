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
$comentario   = isset($_POST['comentario'])   ? trim($_POST['comentario'])                  : '';
$email        = isset($_POST['email'])        ? trim($_POST['email'])                       : null;
$telefono     = isset($_POST['telefono'])     ? trim($_POST['telefono'])                    : null;
$nombre       = isset($_POST['nombre'])       ? substr(trim($_POST['nombre']), 0, 100)      : null;
$tipo_negocio = isset($_POST['tipo_negocio']) ? substr(trim($_POST['tipo_negocio']), 0, 100): null;
$calificacion = isset($_POST['calificacion']) ? max(1, min(5, intval($_POST['calificacion']))): 5;

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

// Validar teléfono si se proporcionó: se guarda solo con dígitos y un + opcional al inicio
// (el POS ya lo manda así; aquí se limpia igual por si llega de otro lado). 8 a 15 dígitos.
if ($telefono !== null && $telefono !== '') {
    $telefono = preg_replace('/[\s().\-]+/', '', $telefono);
    if (!preg_match('/^\+?\d{8,15}$/', $telefono)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'El teléfono no tiene un formato válido'
        ]);
        exit;
    }
} else {
    $telefono = null;
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

    // Insertar. Si la columna `telefono` todavía no existe (falta correr sql/2026-10-24_comentarios_telefono.sql),
    // el mensaje se guarda igual y el teléfono viaja al final del comentario: así no se pierde ni se rechaza el mensaje.
    $sql = 'INSERT INTO comentarios_sugerencias
            (comentario, email, %snombre, tipo_negocio, calificacion, ip_address, pais, ciudad, fecha_hora)
            VALUES
            (:comentario, :email, %s:nombre, :tipo_negocio, :calificacion, :ip_address, :pais, :ciudad, NOW())';
    $datos = [
        ':comentario'   => $comentario,
        ':email'        => $email,
        ':nombre'       => $nombre,
        ':tipo_negocio' => $tipo_negocio,
        ':calificacion' => $calificacion,
        ':ip_address'   => $ip_address,
        ':pais'         => $pais,
        ':ciudad'       => $ciudad,
    ];
    try {
        $stmt = $conn->prepare(sprintf($sql, 'telefono, ', ':telefono, '));
        $stmt->execute($datos + [':telefono' => $telefono]);
    } catch (PDOException $e) {
        if (!isset($e->errorInfo[1]) || (int)$e->errorInfo[1] !== 1054) { // 1054 = columna desconocida
            throw $e;
        }
        if ($telefono !== null) {
            $datos[':comentario'] = $comentario . "\n\nTeléfono: " . $telefono;
        }
        $stmt = $conn->prepare(sprintf($sql, '', ''));
        $stmt->execute($datos);
    }

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
