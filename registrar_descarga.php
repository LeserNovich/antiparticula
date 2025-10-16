<?php
echo "PHP fue invocado correctamente";
// --- AÑADE ESTA VERIFICACIÓN DE SEGURIDAD ---
// Si la solicitud no es de tipo POST, detiene el script.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Opcional: puedes mostrar un mensaje de error o simplemente salir.
    http_response_code(405); // Código de "Método no permitido"
    exit('Acceso denegado.'); 
}
// --- FIN DE LA VERIFICACIÓN ---

$servername = "127.0.0.1"; // O la IP de tu servidor de BD
$username = "u450756829_Leser";    // Tu usuario de la base de datos
$password = "#s*/gDkR5Pcuba"; // Tu contraseña de la base de datos
$dbname = "u450756829_PaginaWeb";     // El nombre de tu base de datos

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
    // Opcional: manejar el error
    echo json_encode(['status' => 'error', 'message' => 'Error al registrar: ' . $e->getMessage()]);
}

// Cerrar la conexión
$conn = null;
?>