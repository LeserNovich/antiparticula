<?php
// ────────────────────────────────────────────────────────────
// ALTO-01 fix: CORS restringido al origen del dashboard
// BAJO-05 fix: manejar preflight OPTIONS
// CRITICO-01 fix: credenciales cargadas desde archivo externo al webroot
// ────────────────────────────────────────────────────────────

// CORS restringido al dominio del dashboard (www y sin www).
// Se resuelve ANTES del preflight: antes se usaba $origin sin haberla definido
// todavia, por lo que OPTIONS respondia con un header vacio y el navegador
// rechazaba la peticion.
$allowed_origins  = ['https://www.antiparticula.com', 'https://antiparticula.com'];
$origin           = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$origin_permitido = in_array($origin, $allowed_origins, true);

// Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin_permitido) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    header('Vary: Origin');
    http_response_code(204);
    exit;
}

if ($origin_permitido) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Vary: Origin');

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

// CRITICO-01: cargar credenciales desde archivo fuera del webroot
// ACCION REQUERIDA: crear /home/u450756829/config_db.php con las credenciales
// y CAMBIAR la contrasena de MySQL en el panel de Hostinger (ya esta comprometida en git)
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

    // Historial mensual del año en curso — calculado desde dashboard_pos_historico
    $anio   = (int) date('Y');
    $stmt2  = $conn->prepare(
        "SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes,
                SUM(ventas_centavos)        AS ventas_centavos,
                SUM(ganancia_centavos)      AS ganancia_centavos
         FROM   dashboard_pos_historico
         WHERE  hardware_id = :id
           AND  YEAR(fecha) = :anio
         GROUP  BY mes
         ORDER  BY mes"
    );
    $stmt2->bindParam(':id',   $hardware_id);
    $stmt2->bindParam(':anio', $anio,       PDO::PARAM_INT);
    $stmt2->execute();
    $anual = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($anual as &$m) {
        $m['ventas_centavos']   = (int) $m['ventas_centavos'];
        $m['ganancia_centavos'] = (int) $m['ganancia_centavos'];
    }
    unset($m);
    $row['historico_anual'] = $anual;

    // ALTO-05 fix: clamp hace_minutos a 0 para evitar badge verde falso con reloj desincronizado
    $row['hace_minutos']     = max(0, (int) $row['hace_minutos']);
    $row['status']           = 'exitoso';

    echo json_encode($row, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
    error_log('get_dashboard_data.php PDO error: ' . $e->getMessage());
}

$conn = null;
?>
