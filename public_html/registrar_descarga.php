<?php
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
$servername = $cfg['host'];
$username   = $cfg['username'];
$password   = $cfg['password'];
$dbname     = $cfg['dbname'];

// --- 2. OBTENCIÓN DE DATOS DEL USUARIO ---
// Obtener la dirección IP del visitante
$ip_address = $_SERVER['REMOTE_ADDR'];

// Usar una API gratuita para obtener la ubicación aproximada desde la IP
// Esta API no requiere clave y es muy sencilla de usar
$geo_url = "http://ip-api.com/json/{$ip_address}";
$geo_data_json = file_get_contents($geo_url);
$geo_data = json_decode($geo_data_json);

// Asignar los valores de ubicación (si están disponibles)
$pais = isset($geo_data->country) ? $geo_data->country : 'Desconocido';
$ciudad = isset($geo_data->city) ? $geo_data->city : 'Desconocida';

// --- 3. CONEXIÓN Y REGISTRO EN LA BASE DE DATOS ---
try {
    // Crear conexión usando PDO (más seguro)
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    // Configurar el modo de error de PDO para que lance excepciones
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    date_default_timezone_set('America/Mexico_City'); // cambia según tu zona
    $conn->exec("SET time_zone = '-06:00'");
    // Preparar la sentencia SQL para evitar inyecciones SQL
    $stmt = $conn->prepare("INSERT INTO registros_descargas (ip_address, pais, ciudad) VALUES (:ip, :pais, :ciudad)");

    // Vincular los parámetros
    $stmt->bindParam(':ip', $ip_address);
    $stmt->bindParam(':pais', $pais);
    $stmt->bindParam(':ciudad', $ciudad);

    // Ejecutar la sentencia
    $stmt->execute();

    // Opcional: puedes enviar una respuesta de éxito si lo necesitas
    echo json_encode(['status' => 'exitoso', 'message' => 'Registro registrado en BD.']);

} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
    error_log('registrar_descarga.php PDO error: ' . $e->getMessage());
}

// --- 4. AVISO A TIKTOK ---
// Solo si la página mandó event_id (el mismo que lleva el Pixel: TikTok cuenta uno). Si la descarga viene de un
// link personal de /gratis (código en el POST o en la cookie ap_reg), se anota que ese registro descargó y el
// evento lleva su WhatsApp y el clic de su anuncio: así TikTok une la descarga en la computadora con el anuncio
// que vio en el celular. Ya se contestó al navegador; nada de esto cambia la respuesta.
if (isset($_POST['event_id']) && is_string($_POST['event_id']) && $_POST['event_id'] !== '') {
    define('GRATIS_COMUN_OK', true);
    require_once __DIR__ . '/gratis_comun.php';
    ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    $persona = gratis_visitante();
    $sistema = isset($_POST['sistema']) && in_array($_POST['sistema'], ['windows', 'macArm', 'macIntel'], true) ? $_POST['sistema'] : null;
    $codigo  = gratis_codigo_valido(isset($_POST['codigo']) && $_POST['codigo'] !== '' ? $_POST['codigo']
                                    : (isset($_COOKIE['ap_reg']) ? $_COOKIE['ap_reg'] : ''));
    if ($codigo !== null && isset($conn) && $conn instanceof PDO) {
        try {
            $st = $conn->prepare('SELECT telefono, ttclid FROM registros_gratis WHERE codigo = :c');
            $st->execute([':c' => $codigo]);
            $reg = $st->fetch(PDO::FETCH_ASSOC);
            if ($reg) {
                $conn->prepare('UPDATE registros_gratis SET descargo_en = COALESCE(descargo_en, NOW()), sistema = COALESCE(sistema, :s)
                                WHERE codigo = :c')->execute([':s' => $sistema, ':c' => $codigo]);
                $persona['telefono'] = $reg['telefono'];
                $persona['codigo']   = $codigo;
                if ($reg['ttclid'] !== null) $persona['ttclid'] = $reg['ttclid'];
            }
        } catch (PDOException $e) {
            // Sin la tabla (falta sql/2026-10-27_registros_gratis.sql): el evento sale sin los datos del registro
            error_log('registrar_descarga.php registro de /gratis: ' . $e->getMessage());
        }
    }
    tiktok_evento($cfg, 'Download', gratis_event_id($_POST['event_id']), $persona,
                  ['content_name' => $sistema !== null ? $sistema : 'instalador'],
                  gratis_url(isset($_POST['url']) ? $_POST['url'] : ''));
}

// Cerrar la conexión
$conn = null;
?>