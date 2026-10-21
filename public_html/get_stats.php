<?php
/**
 * Cifras de la barra de la página del Punto de Venta. Deben ser REALES: si una consulta falla, esa cifra va como null
 * y la página no enseña nada en su lugar (nunca un número de relleno).
 *
 *  - total: negocios activos = computadoras distintas (hardware_id) que iniciaron sesión en el Punto de Venta en los
 *    últimos 30 días. Lo manda el POS en cada inicio de sesión (TelemetriaTracker -> registrar_uso.php ->
 *    uso_punto_venta). Un negocio con varias computadoras cuenta una vez por computadora.
 *  - descargas: todas las descargas registradas desde siempre, una por clic en «Descargar»
 *    (registrar_descarga.php -> registros_descargas).
 *
 * Antes «total» eran las IPs distintas que alguna vez descargaron: eso no son negocios activos.
 */
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
// Cambian despacio: que el navegador no pregunte en cada visita
header('Cache-Control: public, max-age=600');

$cfg_path = dirname(__DIR__) . '/config_db.php';
if (!file_exists($cfg_path)) {
    $cfg = ['host' => '127.0.0.1', 'dbname' => 'u450756829_PaginaWeb',
            'username' => 'u450756829_Leser', 'password' => ''];
} else {
    $cfg = require $cfg_path;
}

/** Corre una consulta de un solo número; null (y al registro de errores) si falla. */
function cifra($conn, $sql, $nombre) {
    try {
        return (int)$conn->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        error_log("get_stats.php ($nombre): " . $e->getMessage());
        return null;
    }
}

try {
    $conn = new PDO(
        "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4",
        $cfg['username'],
        $cfg['password']
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // La de registrar_uso.php, que guarda fecha_hora con NOW() en hora de México: así cuadran los 30 días
    $conn->exec("SET time_zone = '-06:00'");
} catch (PDOException $e) {
    error_log('get_stats.php (conexión): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['total' => null, 'descargas' => null]);
    exit;
}

$total = cifra($conn,
    "SELECT COUNT(DISTINCT hardware_id) FROM uso_punto_venta
     WHERE tipo_evento = 'login'
       AND hardware_id IS NOT NULL AND hardware_id <> ''
       AND fecha_hora >= NOW() - INTERVAL 30 DAY",
    'negocios activos');

$descargas = cifra($conn, "SELECT COUNT(*) FROM registros_descargas", 'descargas');

echo json_encode(['total' => $total, 'descargas' => $descargas]);
$conn = null;
