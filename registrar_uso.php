<?php
/**
 * Endpoint: registrar_uso.php
 * Descripción: Recibe eventos de telemetría del Punto de Venta
 * Método: POST
 * Respuesta: JSON
 */

// Configuración de headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

// Solo permitir método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Método no permitido. Use POST.'
    ]);
    exit;
}

// Configuración de base de datos
$host = '127.0.0.1';
$dbname = 'u450756829_PaginaWeb';
$username = 'u450756829_Leser';
$password = '#s*/gDkR5Pcuba';

try {
    // Conectar a MySQL con PDO
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Configurar timezone
    $pdo->exec("SET time_zone = '-06:00'");

    // Recibir y validar parámetros
    $tipo_evento = $_POST['tipo_evento'] ?? '';
    $usuario = $_POST['usuario'] ?? '';
    $rol = $_POST['rol'] ?? null;
    $tipo_sesion = $_POST['tipo_sesion'] ?? null;
    $negocio = $_POST['negocio'] ?? null;
    $hardware_id = $_POST['hardware_id'] ?? null;

    // Validaciones
    $errores = [];

    // Validar tipo_evento (obligatorio)
    $eventos_validos = ['login', 'cierre_turno', 'salida_app'];
    if (empty($tipo_evento)) {
        $errores[] = 'El campo tipo_evento es obligatorio';
    } elseif (!in_array($tipo_evento, $eventos_validos)) {
        $errores[] = 'tipo_evento debe ser: login, cierre_turno o salida_app';
    }

    // Validar usuario (obligatorio)
    if (empty($usuario)) {
        $errores[] = 'El campo usuario es obligatorio';
    } elseif (strlen($usuario) > 100) {
        $errores[] = 'El campo usuario no puede exceder 100 caracteres';
    }

    // Validar rol (opcional)
    if ($rol !== null) {
        $roles_validos = ['administrador', 'cajero'];
        if (!in_array($rol, $roles_validos)) {
            $errores[] = 'rol debe ser: administrador o cajero';
        }
    }

    // Validar tipo_sesion (opcional)
    if ($tipo_sesion !== null) {
        $sesiones_validas = ['nuevo_turno', 'continuacion', 'admin_override', 'modo_informe'];
        if (!in_array($tipo_sesion, $sesiones_validas)) {
            $errores[] = 'tipo_sesion debe ser: nuevo_turno, continuacion, admin_override o modo_informe';
        }
    }

    // Validar longitud de campos opcionales
    if ($negocio !== null && strlen($negocio) > 200) {
        $errores[] = 'El campo negocio no puede exceder 200 caracteres';
    }
    if ($hardware_id !== null && strlen($hardware_id) > 50) {
        $errores[] = 'El campo hardware_id no puede exceder 50 caracteres';
    }

    // Si hay errores, retornar
    if (!empty($errores)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Datos inválidos',
            'errores' => $errores
        ]);
        exit;
    }

    // Capturar IP del cliente
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Desconocido';

    // Obtener geolocalización de la IP
    $pais = 'Desconocido';
    $ciudad = 'Desconocido';
    $ubicacion_texto = 'Desconocido';

    if ($ip_address !== 'Desconocido' && $ip_address !== '::1' && $ip_address !== '127.0.0.1') {
        try {
            $geo_url = "http://ip-api.com/json/{$ip_address}?fields=status,country,city&lang=es";
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'ignore_errors' => true
                ]
            ]);

            $geo_response = @file_get_contents($geo_url, false, $context);

            if ($geo_response !== false) {
                $geo_data = json_decode($geo_response, true);
                if ($geo_data && $geo_data['status'] === 'success') {
                    $pais = $geo_data['country'] ?? 'Desconocido';
                    $ciudad = $geo_data['city'] ?? 'Desconocido';
                    $ubicacion_texto = "{$ciudad}, {$pais}";
                }
            }
        } catch (Exception $e) {
            // Si falla la geolocalización, continuar con valores por defecto
            error_log("Error obteniendo geolocalización: " . $e->getMessage());
        }
    } else {
        // Para IPs locales
        $pais = 'Local';
        $ciudad = 'Localhost';
        $ubicacion_texto = 'Local/Testing';
    }

    // Preparar statement SQL
    $sql = "INSERT INTO uso_punto_venta
            (tipo_evento, usuario, rol, tipo_sesion, negocio, hardware_id, ip_address, pais, ciudad, fecha_hora)
            VALUES
            (:tipo_evento, :usuario, :rol, :tipo_sesion, :negocio, :hardware_id, :ip_address, :pais, :ciudad, NOW())";

    $stmt = $pdo->prepare($sql);

    // Ejecutar insert
    $stmt->execute([
        ':tipo_evento' => $tipo_evento,
        ':usuario' => $usuario,
        ':rol' => $rol,
        ':tipo_sesion' => $tipo_sesion,
        ':negocio' => $negocio,
        ':hardware_id' => $hardware_id,
        ':ip_address' => $ip_address,
        ':pais' => $pais,
        ':ciudad' => $ciudad
    ]);

    // Obtener ID del registro insertado
    $id_insertado = $pdo->lastInsertId();

    // Respuesta exitosa
    http_response_code(201);
    echo json_encode([
        'status' => 'exitoso',
        'message' => 'Evento de uso registrado correctamente',
        'id' => (int)$id_insertado,
        'ubicacion' => $ubicacion_texto,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // Error de base de datos
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al registrar el evento',
        'error_code' => 'DB_ERROR'
    ]);

    // Log del error (no expuesto al cliente)
    error_log("Error BD en registrar_uso.php: " . $e->getMessage());

} catch (Exception $e) {
    // Error general
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error interno del servidor',
        'error_code' => 'INTERNAL_ERROR'
    ]);

    error_log("Error general en registrar_uso.php: " . $e->getMessage());
}
?>
