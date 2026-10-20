<?php
// ────────────────────────────────────────────────────────────
// ALTO-01 fix: CORS — sync_pos es llamado por la app Java (servidor a servidor),
//              no necesita CORS en absoluto. Se deja solo por si hay casos de
//              prueba desde navegador, pero restringido.
// BAJO-05 fix: manejar preflight OPTIONS
// CRITICO-01 fix: credenciales cargadas desde archivo externo al webroot
// ALTO-03 fix: validacion de hardware_id y estructura interna de los JSON
// BAJO-08 fix: validacion de coherencia ganancia <= ventas
// ────────────────────────────────────────────────────────────

// Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// sync_pos es invocado por la app Java — CORS abierto solo para POST
// (la app Java no envía Origin header, por lo que esto no afecta seguridad del browser)
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

// Solo acepta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

// Validar campo requerido
$hardware_id = isset($_POST['hardware_id']) ? trim($_POST['hardware_id']) : '';
if (empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'hardware_id es requerido']);
    exit;
}

// ALTO-03 fix: validar formato de hardware_id — solo alfanumerico, guiones y guiones bajos
if (!preg_match('/^[a-zA-Z0-9_\-]{4,50}$/', $hardware_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'hardware_id inválido']);
    exit;
}

// Sanitizar y obtener campos opcionales
$negocio         = isset($_POST['negocio'])         ? substr(trim($_POST['negocio']), 0, 200)  : '';
$ventas_hoy      = isset($_POST['ventas_hoy'])      ? intval($_POST['ventas_hoy'])              : 0;
$tickets_hoy     = isset($_POST['tickets_hoy'])     ? intval($_POST['tickets_hoy'])             : 0;
$ganancia_hoy    = isset($_POST['ganancia_hoy'])    ? intval($_POST['ganancia_hoy'])            : 0;
$turno_usuario   = isset($_POST['turno_usuario'])   ? substr(trim($_POST['turno_usuario']), 0, 100) : '';
$turno_inicio    = isset($_POST['turno_inicio'])    ? substr(trim($_POST['turno_inicio']), 0, 30)   : '';
$turno_abierto   = isset($_POST['turno_abierto'])   ? intval($_POST['turno_abierto'])           : 0;
$ventas_turno    = isset($_POST['ventas_turno'])    ? intval($_POST['ventas_turno'])            : 0;
$tickets_turno   = isset($_POST['tickets_turno'])   ? intval($_POST['tickets_turno'])           : 0;
$abonos_hoy      = isset($_POST['abonos_hoy'])      ? intval($_POST['abonos_hoy'])              : 0;
$abonos_turno    = isset($_POST['abonos_turno'])    ? intval($_POST['abonos_turno'])            : 0;
$ultimas_ventas  = isset($_POST['ultimas_ventas'])  ? $_POST['ultimas_ventas']                  : '[]';
$historico_semana= isset($_POST['historico_semana'])? $_POST['historico_semana']                : '[]';
$historico_anual = isset($_POST['historico_anual']) ? $_POST['historico_anual']                 : '[]';

// ── Panel móvil v2 (todo opcional: un POS anterior no manda nada de esto) ──
// Pagos/caja/cancelaciones/inventario (extra), historial recalculado (dias, meses)
// y la protección con PIN (pin, pin_actual, pin_clear, clave). Ver dashboard_comun.php.
if (!defined('DASH_COMUN_OK')) define('DASH_COMUN_OK', true);
require_once __DIR__ . '/dashboard_comun.php';
$param_clave      = isset($_POST['clave'])      && is_string($_POST['clave'])      ? $_POST['clave']      : '';
$param_pin        = isset($_POST['pin'])        && is_string($_POST['pin'])        ? $_POST['pin']        : '';
$param_pin_actual = isset($_POST['pin_actual']) && is_string($_POST['pin_actual']) ? $_POST['pin_actual'] : '';
$param_pin_clear  = isset($_POST['pin_clear'])  && $_POST['pin_clear'] === '1';
$avisos = [];
if ($param_pin !== '' && !dash_pin_valido($param_pin)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'codigo' => 'pin_invalido', 'message' => 'El PIN debe tener de 6 a 8 dígitos']);
    exit;
}

// BAJO-08 fix: validacion de coherencia — ganancia no puede superar ingresos totales del día
// (ventas_hoy son solo tickets normales; los abonos de apartados son ingresos adicionales)
if ($ganancia_hoy > ($ventas_hoy + $abonos_hoy))  $ganancia_hoy = $ventas_hoy + $abonos_hoy;
if ($tickets_turno > $tickets_hoy)   $tickets_turno = $tickets_hoy;
// Nota: ventas_turno puede diferir de ventas_hoy cuando hay abonos de apartados en el turno

// ALTO-03 fix: validar estructura interna de los JSON y sanitizar sus campos
$hoy_mx  = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$hoy     = $hoy_mx->format('Y-m-d');
$hora_mx = (int) $hoy_mx->format('G');

$uv = json_decode($ultimas_ventas, true);
if (!is_array($uv)) $uv = [];
$ventas_detalle = [];
foreach ($uv as &$v) {
    $hora_raw   = isset($v['hora']) ? (string) $v['hora'] : '';
    $hora_match = [];
    // Extraer HH:MM de cualquier formato (HH:MM, HH:MM:SS, ISO, etc.)
    $hh = $mm = -1;
    if (preg_match('/(\d{1,2}):(\d{2})/', $hora_raw, $hora_match)) {
        $hh = (int) $hora_match[1];
        $mm = (int) $hora_match[2];
    }
    $hora_valida = ($hh >= 0 && $hh <= 23 && $mm >= 0 && $mm <= 59);
    if (!$hora_valida) { $hh = 0; $mm = 0; }

    $v['hora']           = sprintf('%02d:%02d', $hh, $mm);
    $v['total_centavos'] = isset($v['total_centavos']) ? intval($v['total_centavos']) : 0;
    $v['cajero']         = isset($v['cajero'])         ? substr(strip_tags((string)$v['cajero']), 0, 100) : '';

    if (!$hora_valida) continue;

    // Fecha de la venta: la que mande el POS si viene y es reciente, si no hoy.
    // Sin esto, las ventas de anoche que el POS reenvía a las 00:05 se
    // registrarían como del día nuevo.
    $fecha_v = $hoy;
    if (isset($v['fecha']) && preg_match('/^(\d{4}-\d{2}-\d{2})$/', (string)$v['fecha'], $fm)) {
        $dias = (int) $hoy_mx->diff(new DateTime($fm[1], new DateTimeZone('America/Mexico_City')))->days;
        if ($dias <= 2) $fecha_v = $fm[1];
    } elseif ($hh > $hora_mx + 1) {
        // Hora futura respecto al reloj del servidor => es de ayer.
        $fecha_v = (clone $hoy_mx)->modify('-1 day')->format('Y-m-d');
    }

    // Identidad estable de la venta: folio del POS si lo manda, si no un hash.
    $folio = '';
    foreach (['folio', 'ticket', 'venta_id', 'id'] as $k) {
        if (isset($v[$k]) && $v[$k] !== '' && !is_array($v[$k])) {
            $folio = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $v[$k]), 0, 40);
            if ($folio !== '') break;
        }
    }
    $ventas_detalle[] = [
        'fecha'  => $fecha_v,
        'hora'   => $hh,
        'minuto' => $mm,
        'cajero' => $v['cajero'],
        'total'  => $v['total_centavos'],
        'key'    => $folio !== ''
            ? 'f:' . $folio
            : 'h:' . sha1($hh . '|' . $mm . '|' . $v['cajero'] . '|' . $v['total_centavos']),
    ];
}
unset($v);
$ultimas_ventas = json_encode(array_values($uv));

$hs = json_decode($historico_semana, true);
if (!is_array($hs)) $hs = [];
foreach ($hs as &$d) {
    $fecha_raw       = isset($d['fecha']) ? (string) $d['fecha'] : '';
    $fecha_match     = [];
    $d['fecha']          = preg_match('/(\d{4}-\d{2}-\d{2})/', $fecha_raw, $fecha_match) ? $fecha_match[1] : '';
    $d['total_centavos'] = isset($d['total_centavos']) ? intval($d['total_centavos']) : 0;
}
unset($d);
$historico_semana = json_encode(array_values($hs));

$ha = json_decode($historico_anual, true);
if (!is_array($ha)) $ha = [];
foreach ($ha as &$entry) {
    $mes_raw   = isset($entry['mes']) ? (string) $entry['mes'] : '';
    $mes_match = [];
    $entry['mes']               = preg_match('/(\d{4}-\d{2})/', $mes_raw, $mes_match) ? $mes_match[1] : '';
    $entry['ventas_centavos']   = isset($entry['ventas_centavos'])   ? intval($entry['ventas_centavos'])   : 0;
    $entry['ganancia_centavos'] = isset($entry['ganancia_centavos']) ? intval($entry['ganancia_centavos']) : 0;
    // coherencia: ganancia no puede superar ventas
    if ($entry['ganancia_centavos'] > $entry['ventas_centavos'])
        $entry['ganancia_centavos'] = $entry['ventas_centavos'];
}
unset($entry);
$historico_anual = json_encode(array_values($ha));

// CRITICO-01: credenciales desde archivo fuera del webroot.
// El fallback hardcodeado se eliminó: la contraseña que contenía ya fue rotada
// y seguía filtrada en el repo. Si falta el archivo, se falla en vez de
// intentar conectar con credenciales obsoletas.
$cfg_path = dirname(__DIR__) . '/config_db.php';
if (!file_exists($cfg_path)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de configuración']);
    error_log('sync_pos.php: falta config_db.php en ' . $cfg_path);
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

    // ── Puerta de escritura: un POS con PIN solo acepta datos firmados con su clave ──
    // (un POS sin PIN sigue abierto, como siempre). Va ANTES de cualquier escritura.
    $prot = dash_proteccion($conn, $hardware_id);
    $reconectar = false;
    if ($prot['clave_hash'] !== null && !dash_clave_coincide($prot, $param_clave)) {
        if ($param_pin_actual !== '' && dash_clave_valida($param_clave)) {
            // PC reinstalada: perdió su clave y el PIN actual prueba que es el dueño
            $v = dash_verificar_pin($conn, $hardware_id, $param_pin_actual);
            if (!$v['ok']) {
                dash_responder($v['codigo'] === 'bloqueado' ? 429 : 403, [
                    'status' => 'error',
                    'codigo' => $v['codigo'] === 'bloqueado' ? 'bloqueado'
                              : ($v['codigo'] === 'pin_incorrecto' ? 'pin_incorrecto' : 'clave_invalida'),
                    'restantes' => $v['restantes'], 'reintentar_en' => $v['reintentar_en'],
                ]);
            }
            $reconectar = true;
        } else {
            dash_responder(403, ['status' => 'error', 'codigo' => 'clave_invalida']);
        }
    }

    $sql = "INSERT INTO dashboard_pos
                (hardware_id, negocio, ventas_hoy, tickets_hoy, ganancia_hoy,
                 abonos_hoy, turno_usuario, turno_inicio, turno_abierto,
                 ventas_turno, tickets_turno, abonos_turno,
                 ultimas_ventas, historico_semana, historico_anual)
            VALUES
                (:hardware_id, :negocio, :ventas_hoy, :tickets_hoy, :ganancia_hoy,
                 :abonos_hoy, :turno_usuario, :turno_inicio, :turno_abierto,
                 :ventas_turno, :tickets_turno, :abonos_turno,
                 :ultimas_ventas, :historico_semana, :historico_anual)
            ON DUPLICATE KEY UPDATE
                negocio          = VALUES(negocio),
                ultima_sync      = CURRENT_TIMESTAMP,
                ventas_hoy       = VALUES(ventas_hoy),
                tickets_hoy      = VALUES(tickets_hoy),
                ganancia_hoy     = VALUES(ganancia_hoy),
                abonos_hoy       = VALUES(abonos_hoy),
                turno_usuario    = VALUES(turno_usuario),
                turno_inicio     = VALUES(turno_inicio),
                turno_abierto    = VALUES(turno_abierto),
                ventas_turno     = VALUES(ventas_turno),
                tickets_turno    = VALUES(tickets_turno),
                abonos_turno     = VALUES(abonos_turno),
                ultimas_ventas   = VALUES(ultimas_ventas),
                historico_semana = VALUES(historico_semana),
                historico_anual  = VALUES(historico_anual)";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hardware_id',      $hardware_id);
    $stmt->bindParam(':negocio',          $negocio);
    $stmt->bindParam(':ventas_hoy',       $ventas_hoy,       PDO::PARAM_INT);
    $stmt->bindParam(':tickets_hoy',      $tickets_hoy,      PDO::PARAM_INT);
    $stmt->bindParam(':ganancia_hoy',     $ganancia_hoy,     PDO::PARAM_INT);
    $stmt->bindParam(':turno_usuario',    $turno_usuario);
    $stmt->bindParam(':turno_inicio',     $turno_inicio);
    $stmt->bindParam(':turno_abierto',    $turno_abierto,    PDO::PARAM_INT);
    $stmt->bindParam(':abonos_hoy',       $abonos_hoy,       PDO::PARAM_INT);
    $stmt->bindParam(':ventas_turno',     $ventas_turno,     PDO::PARAM_INT);
    $stmt->bindParam(':tickets_turno',    $tickets_turno,    PDO::PARAM_INT);
    $stmt->bindParam(':abonos_turno',     $abonos_turno,     PDO::PARAM_INT);
    $stmt->bindParam(':ultimas_ventas',   $ultimas_ventas);
    $stmt->bindParam(':historico_semana', $historico_semana);
    $stmt->bindParam(':historico_anual',  $historico_anual);
    $stmt->execute();

    // Guardar snapshot diario para historial anual (sin necesidad de actualizar el POS app)
    // $hoy ya viene calculado arriba con el timezone de Mexico City, el mismo
    // que usa MySQL, para evitar duplicados al cruce de medianoche UTC.
    $stmt2 = $conn->prepare(
        "INSERT INTO dashboard_pos_historico
             (hardware_id, fecha, ventas_centavos, ganancia_centavos, tickets)
         VALUES (:hw, :fecha, :ventas, :ganancia, :tickets)
         ON DUPLICATE KEY UPDATE
             ventas_centavos   = VALUES(ventas_centavos),
             ganancia_centavos = VALUES(ganancia_centavos),
             tickets           = VALUES(tickets)"
    );
    $stmt2->execute([
        ':hw'       => $hardware_id,
        ':fecha'    => $hoy,
        ':ventas'   => $ventas_hoy + $abonos_hoy,
        ':ganancia' => $ganancia_hoy,
        ':tickets'  => $tickets_hoy
    ]);

    // Acumular cada venta individual para la gráfica horaria del día completo.
    // El POS reenvía las mismas ventas en syncs consecutivos; la clave única
    // (hardware_id, fecha, venta_key) hace que el INSERT sea idempotente.
    if (!empty($ventas_detalle)) {
        $stmt3 = $conn->prepare(
            "INSERT INTO dashboard_pos_ventas
                 (hardware_id, fecha, hora, minuto, cajero, total_centavos, venta_key)
             VALUES (:hw, :fecha, :hora, :minuto, :cajero, :total, :vkey)
             ON DUPLICATE KEY UPDATE
                 total_centavos = VALUES(total_centavos),
                 cajero         = VALUES(cajero)"
        );
        $conn->beginTransaction();
        foreach (array_slice($ventas_detalle, 0, 500) as $d) {
            $stmt3->execute([
                ':hw'     => $hardware_id,
                ':fecha'  => $d['fecha'],
                ':hora'   => $d['hora'],
                ':minuto' => $d['minuto'],
                ':cajero' => $d['cajero'],
                ':total'  => $d['total'],
                ':vkey'   => $d['key'],
            ]);
        }
        $conn->commit();
    }

    // Purga ocasional (~2% de los syncs) del detalle con más de 120 días.
    if (random_int(1, 50) === 1) {
        $conn->exec("DELETE FROM dashboard_pos_ventas
                     WHERE fecha < (CURRENT_DATE - INTERVAL 120 DAY) LIMIT 5000");
    }

    // ── Panel móvil v2: cada bloque es independiente ─────────────────────────
    // Si la migración 2026-10-09 todavía no se corrió, el bloque cae al catch, se
    // avisa en la respuesta y todo lo anterior (ya guardado arriba) sigue igual.
    $protegido_ahora = $prot['clave_hash'] !== null;

    // (a) PIN y clave. Aquí ya está autorizado: sin PIN es abierto; con PIN la
    //     clave coincidió (o se reconectó con el PIN actual).
    try {
        if ($reconectar) {
            $conn->prepare('UPDATE dashboard_pos SET clave_hash = :c WHERE hardware_id = :id')
                 ->execute([':c' => hash('sha256', $param_clave), ':id' => $hardware_id]);
        }
        if ($param_pin_clear && $protegido_ahora) {
            $conn->prepare('UPDATE dashboard_pos SET pin_hash = NULL, clave_hash = NULL, pin_fallos = 0,
                                   pin_bloqueo_hasta = NULL WHERE hardware_id = :id')
                 ->execute([':id' => $hardware_id]);
            $protegido_ahora = false;
        } elseif ($param_pin !== '') {
            $hash_pin = password_hash($param_pin, PASSWORD_DEFAULT);
            if (!$protegido_ahora) {
                if (dash_clave_valida($param_clave)) {   // activar protección por primera vez
                    $conn->prepare('UPDATE dashboard_pos SET pin_hash = :p, clave_hash = :c, pin_fallos = 0,
                                           pin_bloqueo_hasta = NULL WHERE hardware_id = :id')
                         ->execute([':p' => $hash_pin, ':c' => hash('sha256', $param_clave), ':id' => $hardware_id]);
                    $protegido_ahora = true;
                } else {
                    $avisos[] = 'pin_sin_clave';
                }
            } else {                                      // cambiar el PIN
                $conn->prepare('UPDATE dashboard_pos SET pin_hash = :p, pin_fallos = 0,
                                       pin_bloqueo_hasta = NULL WHERE hardware_id = :id')
                     ->execute([':p' => $hash_pin, ':id' => $hardware_id]);
            }
        }
    } catch (PDOException $e) {
        error_log('sync_pos.php pin: ' . $e->getMessage());
        $avisos[] = 'pin_no_disponible';
    }

    // (b) pagos, caja, cancelaciones e inventario
    $extra_in = dash_json_param('extra', DASH_MAX_EXTRA_BYTES);
    if ($extra_in === false) {
        $avisos[] = 'extra_descartado';
    } elseif ($extra_in !== null) {
        try {
            $extra_limpio = dash_sanear_extra($extra_in);
            if ($extra_limpio) dash_guardar_extra($conn, $hardware_id, $extra_limpio);
        } catch (PDOException $e) {
            error_log('sync_pos.php extra: ' . $e->getMessage());
            $avisos[] = 'extra_no_disponible';
        }
    }

    // (c) días recalculados por el POS: corrige días pasados (idempotente)
    $dias_in = dash_json_param('dias', 131072);
    if ($dias_in === false) {
        $avisos[] = 'dias_descartado';
    } elseif ($dias_in !== null) {
        try {
            dash_guardar_dias($conn, $hardware_id, dash_sanear_dias($dias_in, $hoy));
        } catch (PDOException $e) {
            error_log('sync_pos.php dias: ' . $e->getMessage());
            $avisos[] = 'dias_no_disponible';
        }
    }

    // (d) meses del año recalculados por el POS
    $meses_in = dash_json_param('meses', 16384);
    if ($meses_in === false) {
        $avisos[] = 'meses_descartado';
    } elseif ($meses_in !== null) {
        try {
            dash_guardar_meses($conn, $hardware_id, dash_sanear_meses($meses_in));
        } catch (PDOException $e) {
            error_log('sync_pos.php meses: ' . $e->getMessage());
            $avisos[] = 'meses_no_disponible';
        }
    }

    // (e) Premium: la clave firmada que manda el POS (3.4.0+) y los días gratis del panel. Ver
    //     sql/2026-10-20_panel_premium.sql. Un POS anterior no manda clave: cuando terminen sus días
    //     gratis, el panel le pide actualizar. Sin la migración, acceso = null y el panel sigue abierto.
    $acceso = null;
    $param_licencia = isset($_POST['licencia']) && is_string($_POST['licencia']) ? $_POST['licencia'] : '';
    $param_equipo   = isset($_POST['equipo'])   && is_string($_POST['equipo'])   ? strtoupper(trim($_POST['equipo'])) : '';
    $equipo = dash_equipo_valido($param_equipo) ? $param_equipo : null;
    try {
        if ($equipo !== null) dash_registrar_equipo($conn, $equipo);   // antes de fijar los días: cuentan desde la 1.ª vez de la PC
        dash_fijar_gratis($conn, $hardware_id, $equipo);
        if ($param_licencia !== '') {
            $lic = dash_licencia($cfg, $param_licencia);
            if ($lic === null) {
                $avisos[] = 'licencia_invalida';
            } elseif (!dash_registrar_licencia($conn, $hardware_id, $lic)) {
                $avisos[] = 'licencia_en_otros_paneles';
            }
        }
        $acceso = dash_acceso_de($conn, $hardware_id);
    } catch (PDOException $e) {
        // Como las demás partes nuevas: solo se avisa (y se anota) si el POS mandó algo de esto
        if ($param_licencia !== '' || $equipo !== null) {
            error_log('sync_pos.php acceso: ' . $e->getMessage());
            $avisos[] = 'acceso_no_disponible';
        }
    }

    echo json_encode(['status' => 'exitoso', 'protegido' => $protegido_ahora, 'avisos' => $avisos, 'acceso' => $acceso]);

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
    error_log('sync_pos.php PDO error: ' . $e->getMessage());
}

$conn = null;
?>
