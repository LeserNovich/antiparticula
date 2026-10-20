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
        header('Access-Control-Allow-Headers: Content-Type, X-Dash-Token, Authorization');
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
// Cifras de ventas: nunca deben quedar en una caché intermedia
header('Cache-Control: no-store');

// Funciones del panel v2 (token, saneado). Ver dashboard_comun.php
define('DASH_COMUN_OK', true);
require_once __DIR__ . '/dashboard_comun.php';

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

// CRITICO-01: credenciales desde archivo fuera del webroot.
// El fallback hardcodeado se eliminó: la contraseña que contenía ya fue rotada
// y seguía filtrada en el repo. Si falta el archivo, se falla en vez de
// intentar conectar con credenciales obsoletas.
$cfg_path = dirname(__DIR__) . '/config_db.php';
if (!file_exists($cfg_path)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de configuración']);
    error_log('get_dashboard_data.php: falta config_db.php en ' . $cfg_path);
    exit;
}
$cfg = require $cfg_path;
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

    // ── Puerta de lectura: con PIN activo solo se entrega con un token vigente ──
    // (sin PIN el panel sigue abierto por ID, como siempre). Nada de datos antes de esto.
    $pin_hash  = (isset($row['pin_hash']) && $row['pin_hash'] !== '') ? $row['pin_hash'] : null;
    $protegido = $pin_hash !== null;
    if ($protegido && !dash_token_valido(dash_token_de_la_peticion(), $hardware_id, $pin_hash, dash_secreto($cfg))) {
        dash_responder(401, ['status' => 'protegido', 'message' => 'Este panel está protegido con PIN']);
    }
    // Lo interno (hash del PIN, clave, contador de fallos) NUNCA sale
    unset($row['pin_hash'], $row['clave_hash'], $row['pin_fallos'], $row['pin_bloqueo_hasta']);
    $row['protegido'] = $protegido;

    // ── Puerta de Premium: sin suscripción ni días gratis, ni un dato (va DESPUÉS del PIN: a quien no
    // tiene el PIN no se le dice ni eso). Sin la migración 2026-10-20 el estado es «abierto», como antes.
    $acceso = dash_acceso($row, dash_hoy_mx());
    unset($row['gratis_hasta'], $row['premium_hasta']);
    if ($acceso['estado'] === 'vencido') {
        dash_responder(403, ['status' => 'premium_requerido', 'negocio' => $row['negocio'],
                             'desde' => $acceso['hasta'], 'protegido' => $protegido]);
    }
    $row['acceso'] = $acceso;

    // Pagos, caja, cancelaciones e inventario (null con un POS anterior a la 3.3.0)
    $extra_decodificado = isset($row['extra_json']) && $row['extra_json'] !== null && $row['extra_json'] !== ''
        ? json_decode($row['extra_json'], true) : null;
    $row['extra'] = is_array($extra_decodificado) ? $extra_decodificado : null;
    unset($row['extra_json']);

    // Decodificar los JSON guardados
    $row['ultimas_ventas']   = json_decode($row['ultimas_ventas'],   true) ?: [];
    $row['historico_semana'] = json_decode($row['historico_semana'], true) ?: [];

    // Historial mensual del año en curso — calculado desde dashboard_pos_historico
    $anio   = (int) date('Y');

    // Con el POS 3.3.0 los 12 meses los recalcula el POS (completos y corregidos).
    // Antes salían de sumar los snapshots diarios desde que empezó a sincronizar.
    $anual_del_pos = [];
    try {
        $stm = $conn->prepare(
            "SELECT mes, ventas_centavos, ganancia_centavos FROM dashboard_pos_meses
             WHERE hardware_id = :id AND mes LIKE :anio ORDER BY mes");
        $stm->execute([':id' => $hardware_id, ':anio' => $anio . '-%']);
        foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $anual_del_pos[] = ['mes' => $m['mes'],
                                'ventas_centavos' => (int) $m['ventas_centavos'],
                                'ganancia_centavos' => (int) $m['ganancia_centavos']];
        }
    } catch (PDOException $e) {
        // migración 2026-10-09 sin correr: se usa el cálculo de siempre
        error_log('get_dashboard_data.php meses: ' . $e->getMessage());
    }

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
    $row['historico_anual'] = $anual_del_pos ? $anual_del_pos : $anual;

    // Días recientes (hasta 100) para comparar: semana pasada, mes anterior, pagos por día.
    // Con la migración sin correr se piden solo las columnas de siempre.
    $row['historico_dias'] = [];
    try {
        $std = $conn->prepare(
            "SELECT fecha, ventas_centavos, ganancia_centavos, tickets,
                    efectivo_centavos, tarjeta_centavos, transferencia_centavos, comision_centavos,
                    cancelaciones, cancelado_centavos
             FROM dashboard_pos_historico
             WHERE hardware_id = :id AND fecha >= (CURRENT_DATE - INTERVAL 100 DAY)
             ORDER BY fecha");
        $std->execute([':id' => $hardware_id]);
    } catch (PDOException $e) {
        $std = $conn->prepare(
            "SELECT fecha, ventas_centavos, ganancia_centavos, tickets
             FROM dashboard_pos_historico
             WHERE hardware_id = :id AND fecha >= (CURRENT_DATE - INTERVAL 100 DAY)
             ORDER BY fecha");
        $std->execute([':id' => $hardware_id]);
    }
    foreach ($std->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $fila = ['fecha' => $d['fecha'],
                 'ventas' => (int) $d['ventas_centavos'],
                 'ganancia' => (int) $d['ganancia_centavos'],
                 'tickets' => (int) $d['tickets']];
        // NULL = día anterior a la 3.3.0 (sin desglose): se omite la clave, no se inventa un 0
        foreach (['efectivo_centavos' => 'efectivo', 'tarjeta_centavos' => 'tarjeta',
                  'transferencia_centavos' => 'transferencia', 'comision_centavos' => 'comision',
                  'cancelaciones' => 'cancelaciones', 'cancelado_centavos' => 'cancelado'] as $col => $clave) {
            if (isset($d[$col]) && $d[$col] !== null) $fila[$clave] = (int) $d[$col];
        }
        $row['historico_dias'][] = $fila;
    }

    // Gráfica horaria del día completo. Se construye desde el detalle acumulado
    // en dashboard_pos_ventas, NO desde ultimas_ventas: ese JSON solo trae las
    // últimas N ventas que mandó el POS, así que las horas tempranas del día
    // salían vacías. Ver sql/001_ventas_por_hora.sql
    $row['ventas_por_hora'] = [];
    try {
        $stmt3 = $conn->prepare(
            "SELECT hora,
                    SUM(total_centavos) AS total_centavos,
                    COUNT(*)            AS tickets
             FROM   dashboard_pos_ventas
             WHERE  hardware_id = :id AND fecha = CURRENT_DATE
             GROUP  BY hora
             ORDER  BY hora"
        );
        $stmt3->bindParam(':id', $hardware_id);
        $stmt3->execute();
        foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $row['ventas_por_hora'][] = [
                'hora'           => (int) $h['hora'],
                'total_centavos' => (int) $h['total_centavos'],
                'tickets'        => (int) $h['tickets'],
            ];
        }
    } catch (PDOException $e) {
        // La tabla puede no existir todavía (migración 001 sin aplicar).
        // El frontend cae de vuelta a derivar las horas de ultimas_ventas.
        error_log('get_dashboard_data.php ventas_por_hora: ' . $e->getMessage());
    }

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
