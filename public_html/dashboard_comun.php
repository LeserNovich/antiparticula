<?php
// ────────────────────────────────────────────────────────────────────────────
// Funciones compartidas del panel móvil (sync_pos, get_dashboard_data,
// dashboard_auth). NO es un endpoint: los endpoints hacen
//     define('DASH_COMUN_OK', true); require __DIR__ . '/dashboard_comun.php';
// y quien lo abra directo recibe un 404.
//
// Compatible con PHP 7.2 (hosting compartido): sin funciones flecha, sin
// propiedades tipadas, sin str_contains.
// ────────────────────────────────────────────────────────────────────────────
if (!defined('DASH_COMUN_OK')) {
    http_response_code(404);
    exit;
}

const DASH_PIN_MAX_FALLOS    = 8;        // PINs incorrectos seguidos antes de bloquear
const DASH_PIN_BLOQUEO_MIN   = 15;       // minutos de bloqueo
const DASH_TOKEN_DIAS        = 30;       // vigencia del token de lectura
const DASH_MAX_EXTRA_BYTES   = 65536;
const DASH_MAX_DIAS          = 120;
const DASH_MAX_MESES         = 24;

// ── Respuesta JSON y cabeceras ──────────────────────────────────────────────
function dash_responder($codigo_http, array $cuerpo) {
    http_response_code($codigo_http);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

function dash_cabeceras_api() {
    header('Content-Type: application/json; charset=UTF-8');
    // Cifras de ventas: nunca deben quedar en una caché intermedia
    header('Cache-Control: no-store');
}

// ── Conexión ────────────────────────────────────────────────────────────────
// Devuelve [PDO, $cfg]. Las credenciales viven fuera del webroot (config_db.php).
function dash_conectar() {
    $cfg_path = dirname(__DIR__) . '/config_db.php';
    if (!file_exists($cfg_path)) {
        error_log('dashboard: falta config_db.php en ' . $cfg_path);
        dash_responder(500, ['status' => 'error', 'message' => 'Error de configuración']);
    }
    $cfg  = require $cfg_path;
    $conn = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET time_zone = '-06:00'");
    return [$conn, $cfg];
}

// ── Entradas acotadas y saneadas ────────────────────────────────────────────
function dash_int($valor, $min, $max) {
    $n = is_numeric($valor) ? (int) $valor : 0;
    return max($min, min($max, $n));
}

function dash_texto($valor, $largo) {
    return substr(trim(strip_tags((string) $valor)), 0, $largo);
}

// Lee un parámetro POST que trae JSON. null = no vino; false = vino pero no sirve
// (demasiado grande o no es una lista/objeto). Nunca lanza: un campo roto no debe
// impedir que el resto de la foto se guarde.
function dash_json_param($nombre, $max_bytes) {
    if (!isset($_POST[$nombre]) || !is_string($_POST[$nombre]) || $_POST[$nombre] === '') return null;
    if (strlen($_POST[$nombre]) > $max_bytes) return false;
    $v = json_decode($_POST[$nombre], true);
    return is_array($v) ? $v : false;
}

function dash_fecha_valida($texto) {
    if (!is_string($texto) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// ── Saneado de lo que manda el POS ──────────────────────────────────────────
const DASH_MONTO_MAX = 90000000000000; // 900 mil millones de pesos en centavos: nadie llega

function dash_monto($v)  { return dash_int($v, -DASH_MONTO_MAX, DASH_MONTO_MAX); }
function dash_monto0($v) { return dash_int($v, 0, DASH_MONTO_MAX); }

// El bloque `extra` se reconstruye campo por campo: lo desconocido se descarta y
// lo conocido queda acotado. Devuelve null si ni siquiera es un objeto.
function dash_sanear_extra($e) {
    if (!is_array($e)) return null;
    $out = ['v' => 1];

    if (isset($e['pagos_hoy']) && is_array($e['pagos_hoy'])) {
        $p = $e['pagos_hoy'];
        $out['pagos_hoy'] = [
            'efectivo'      => dash_monto0($p['efectivo'] ?? 0),
            'tarjeta'       => dash_monto0($p['tarjeta'] ?? 0),
            'transferencia' => dash_monto0($p['transferencia'] ?? 0),
            'comision'      => dash_monto0($p['comision'] ?? 0),
        ];
    }

    if (array_key_exists('caja', $e)) {
        if (is_array($e['caja'])) {
            $c = $e['caja'];
            $out['caja'] = [
                'esperado'        => dash_monto($c['esperado'] ?? 0),
                'fondo'           => dash_monto($c['fondo'] ?? 0),
                'efectivo_ventas' => dash_monto($c['efectivo_ventas'] ?? 0),
                'ingresos'        => dash_monto0($c['ingresos'] ?? 0),
                'retiros'         => dash_monto0($c['retiros'] ?? 0),
                'tarjeta'         => dash_monto0($c['tarjeta'] ?? 0),
                'transferencia'   => dash_monto0($c['transferencia'] ?? 0),
            ];
        } else {
            $out['caja'] = null;   // sin turno abierto
        }
    }

    if (isset($e['cancelaciones_hoy']) && is_array($e['cancelaciones_hoy'])) {
        $c = $e['cancelaciones_hoy'];
        $por = [];
        if (isset($c['por_usuario']) && is_array($c['por_usuario'])) {
            foreach (array_slice($c['por_usuario'], 0, 10) as $u) {
                if (!is_array($u)) continue;
                $por[] = [
                    'usuario'       => dash_texto($u['usuario'] ?? '', 60),
                    'cancelaciones' => dash_int($u['cancelaciones'] ?? 0, 0, 1000000),
                    'devoluciones'  => dash_int($u['devoluciones'] ?? 0, 0, 1000000),
                ];
            }
        }
        $out['cancelaciones_hoy'] = [
            'cancelaciones'   => dash_int($c['cancelaciones'] ?? 0, 0, 1000000),
            'monto_cancelado' => dash_monto0($c['monto_cancelado'] ?? 0),
            'devoluciones'    => dash_int($c['devoluciones'] ?? 0, 0, 1000000),
            'monto_devuelto'  => dash_monto0($c['monto_devuelto'] ?? 0),
            'por_usuario'     => $por,
        ];
    }

    if (isset($e['inventario']) && is_array($e['inventario'])) {
        $i = $e['inventario'];
        $urg = [];
        if (isset($i['urgentes']) && is_array($i['urgentes'])) {
            foreach (array_slice($i['urgentes'], 0, 10) as $u) {
                if (!is_array($u)) continue;
                $urg[] = [
                    'nombre'   => dash_texto($u['nombre'] ?? '', 80),
                    'cantidad' => dash_int($u['cantidad'] ?? 0, -1000000000000, 1000000000000), // milésimas
                    'minimo'   => dash_int($u['minimo'] ?? 0, 0, 1000000000),
                    'unidad'   => dash_int($u['unidad'] ?? 0, 0, 3),
                ];
            }
        }
        $out['inventario'] = [
            'agotados'    => dash_int($i['agotados'] ?? 0, 0, 100000000),
            'bajo_minimo' => dash_int($i['bajo_minimo'] ?? 0, 0, 100000000),
            'valor_costo' => dash_monto0($i['valor_costo'] ?? 0),
            'urgentes'    => $urg,
        ];
    }
    return $out;
}

// `dias`: una fila por día. Se descartan las que no tienen fecha real o están
// fuera de una ventana razonable (ni futuras ni de hace más de ~14 meses).
function dash_sanear_dias($lista, $hoy_clave) {
    $out = [];
    $desde = date('Y-m-d', strtotime($hoy_clave . ' -430 days'));
    $hasta = date('Y-m-d', strtotime($hoy_clave . ' +2 days'));
    foreach (array_slice($lista, 0, DASH_MAX_DIAS) as $d) {
        if (!is_array($d) || !isset($d['fecha']) || !dash_fecha_valida($d['fecha'])) continue;
        if ($d['fecha'] < $desde || $d['fecha'] > $hasta) continue;
        $out[$d['fecha']] = [
            'fecha'         => $d['fecha'],
            'ventas'        => dash_monto($d['ventas'] ?? 0),
            'ganancia'      => dash_monto($d['ganancia'] ?? 0),
            'tickets'       => dash_int($d['tickets'] ?? 0, 0, 100000000),
            'efectivo'      => dash_monto($d['efectivo'] ?? 0),
            'tarjeta'       => dash_monto0($d['tarjeta'] ?? 0),
            'transferencia' => dash_monto0($d['transferencia'] ?? 0),
            'comision'      => dash_monto0($d['comision'] ?? 0),
            'cancelaciones' => dash_int($d['cancelaciones'] ?? 0, 0, 1000000),
            'cancelado'     => dash_monto0($d['cancelado'] ?? 0),
        ];
    }
    return array_values($out);
}

function dash_sanear_meses($lista) {
    $out = [];
    foreach (array_slice($lista, 0, DASH_MAX_MESES) as $m) {
        if (!is_array($m) || !isset($m['mes']) || !is_string($m['mes'])) continue;
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $m['mes'])) continue;
        $out[$m['mes']] = [
            'mes'      => $m['mes'],
            'ventas'   => dash_monto($m['ventas'] ?? 0),
            'ganancia' => dash_monto($m['ganancia'] ?? 0),
            'tickets'  => dash_int($m['tickets'] ?? 0, 0, 100000000),
        ];
    }
    return array_values($out);
}

// ── Escrituras nuevas (cada una se llama dentro de su propio try/catch) ─────
function dash_guardar_extra(PDO $conn, $hardware_id, array $extra) {
    $st = $conn->prepare('UPDATE dashboard_pos SET extra_json = :j WHERE hardware_id = :id');
    $st->execute([':j' => json_encode($extra, JSON_UNESCAPED_UNICODE), ':id' => $hardware_id]);
}

function dash_guardar_dias(PDO $conn, $hardware_id, array $dias) {
    if (!$dias) return;
    $st = $conn->prepare(
        'INSERT INTO dashboard_pos_historico
            (hardware_id, fecha, ventas_centavos, ganancia_centavos, tickets,
             efectivo_centavos, tarjeta_centavos, transferencia_centavos, comision_centavos,
             cancelaciones, cancelado_centavos)
         VALUES (:hw, :fecha, :ventas, :ganancia, :tickets, :efe, :tar, :tra, :com, :canc, :cancm)
         ON DUPLICATE KEY UPDATE
             ventas_centavos = VALUES(ventas_centavos), ganancia_centavos = VALUES(ganancia_centavos),
             tickets = VALUES(tickets), efectivo_centavos = VALUES(efectivo_centavos),
             tarjeta_centavos = VALUES(tarjeta_centavos), transferencia_centavos = VALUES(transferencia_centavos),
             comision_centavos = VALUES(comision_centavos), cancelaciones = VALUES(cancelaciones),
             cancelado_centavos = VALUES(cancelado_centavos)');
    $conn->beginTransaction();
    try {
        foreach ($dias as $d) {
            $st->execute([
                ':hw' => $hardware_id, ':fecha' => $d['fecha'], ':ventas' => $d['ventas'],
                ':ganancia' => $d['ganancia'], ':tickets' => $d['tickets'], ':efe' => $d['efectivo'],
                ':tar' => $d['tarjeta'], ':tra' => $d['transferencia'], ':com' => $d['comision'],
                ':canc' => $d['cancelaciones'], ':cancm' => $d['cancelado'],
            ]);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

function dash_guardar_meses(PDO $conn, $hardware_id, array $meses) {
    if (!$meses) return;
    $st = $conn->prepare(
        'INSERT INTO dashboard_pos_meses (hardware_id, mes, ventas_centavos, ganancia_centavos, tickets)
         VALUES (:hw, :mes, :v, :g, :t)
         ON DUPLICATE KEY UPDATE ventas_centavos = VALUES(ventas_centavos),
             ganancia_centavos = VALUES(ganancia_centavos), tickets = VALUES(tickets)');
    $conn->beginTransaction();
    try {
        foreach ($meses as $m) {
            $st->execute([':hw' => $hardware_id, ':mes' => $m['mes'], ':v' => $m['ventas'],
                          ':g' => $m['ganancia'], ':t' => $m['tickets']]);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

// ── Protección con PIN ──────────────────────────────────────────────────────
// Estado de protección de un POS. Si las columnas todavía no existen (migración
// sin correr) o el POS no está registrado, cuenta como "sin protección".
function dash_proteccion(PDO $conn, $hardware_id) {
    try {
        $st = $conn->prepare('SELECT pin_hash, clave_hash FROM dashboard_pos WHERE hardware_id = :id LIMIT 1');
        $st->execute([':id' => $hardware_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['existe' => false, 'pin_hash' => null, 'clave_hash' => null];
        return ['existe' => true,
                'pin_hash'   => ($r['pin_hash'] !== null && $r['pin_hash'] !== '') ? $r['pin_hash'] : null,
                'clave_hash' => ($r['clave_hash'] !== null && $r['clave_hash'] !== '') ? $r['clave_hash'] : null];
    } catch (PDOException $e) {
        return ['existe' => false, 'pin_hash' => null, 'clave_hash' => null, 'sin_migrar' => true];
    }
}

function dash_clave_valida($clave) {
    return is_string($clave) && preg_match('/^[a-f0-9]{64}$/', $clave);
}

function dash_pin_valido($pin) {
    return is_string($pin) && preg_match('/^\d{6,8}$/', $pin);
}

function dash_comparar($a, $b) {
    return is_string($a) && is_string($b) && $a !== '' && hash_equals($a, $b);
}

function dash_clave_coincide(array $prot, $clave) {
    return $prot['clave_hash'] !== null && dash_clave_valida($clave)
        && dash_comparar($prot['clave_hash'], hash('sha256', $clave));
}

// Verifica un PIN llevando la cuenta de fallos. Una fila bloqueada con FOR UPDATE
// serializa los intentos del mismo POS: ráfagas en paralelo no se saltan el tope.
// Devuelve ['ok'=>bool, 'codigo'=>'', 'restantes'=>n, 'reintentar_en'=>seg].
function dash_verificar_pin(PDO $conn, $hardware_id, $pin) {
    $conn->beginTransaction();
    try {
        $st = $conn->prepare(
            'SELECT pin_hash, pin_fallos,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), pin_bloqueo_hasta)) AS bloqueo_seg
             FROM dashboard_pos WHERE hardware_id = :id FOR UPDATE');
        $st->execute([':id' => $hardware_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        if (!$r || $r['pin_hash'] === null || $r['pin_hash'] === '') {
            $conn->commit();
            return ['ok' => false, 'codigo' => 'sin_pin', 'restantes' => 0, 'reintentar_en' => 0];
        }
        if ((int) $r['bloqueo_seg'] > 0) {
            $conn->commit();
            return ['ok' => false, 'codigo' => 'bloqueado', 'restantes' => 0, 'reintentar_en' => (int) $r['bloqueo_seg']];
        }
        if (dash_pin_valido($pin) && password_verify($pin, $r['pin_hash'])) {
            $conn->prepare('UPDATE dashboard_pos SET pin_fallos = 0, pin_bloqueo_hasta = NULL WHERE hardware_id = :id')
                 ->execute([':id' => $hardware_id]);
            $conn->commit();
            return ['ok' => true, 'codigo' => '', 'restantes' => DASH_PIN_MAX_FALLOS, 'reintentar_en' => 0];
        }
        $fallos = (int) $r['pin_fallos'] + 1;
        $bloquea = $fallos >= DASH_PIN_MAX_FALLOS;
        $conn->prepare(
            'UPDATE dashboard_pos SET pin_fallos = :f,
                    pin_bloqueo_hasta = ' . ($bloquea ? 'DATE_ADD(NOW(), INTERVAL ' . (int) DASH_PIN_BLOQUEO_MIN . ' MINUTE)' : 'pin_bloqueo_hasta') . '
             WHERE hardware_id = :id')
             ->execute([':f' => $fallos, ':id' => $hardware_id]);
        $conn->commit();
        if ($bloquea) {
            return ['ok' => false, 'codigo' => 'bloqueado', 'restantes' => 0, 'reintentar_en' => DASH_PIN_BLOQUEO_MIN * 60];
        }
        return ['ok' => false, 'codigo' => 'pin_incorrecto', 'restantes' => DASH_PIN_MAX_FALLOS - $fallos, 'reintentar_en' => 0];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

// ── Token de lectura ────────────────────────────────────────────────────────
// payload.firma, ambos en base64 URL-safe. La firma es HMAC-SHA256; el payload lleva
// el id, el vencimiento y una huella del pin_hash: al cambiar el PIN todos los
// tokens anteriores dejan de servir.
function dash_b64($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function dash_b64d($txt) { return base64_decode(strtr($txt, '-_', '+/')); }

function dash_secreto(array $cfg) {
    if (isset($cfg['dash_secret']) && is_string($cfg['dash_secret']) && strlen($cfg['dash_secret']) >= 16) {
        return $cfg['dash_secret'];
    }
    return hash('sha256', $cfg['password'] . '|' . $cfg['dbname'] . '|panel-movil');
}

function dash_huella_pin($pin_hash) {
    return substr(hash('sha256', 'pin|' . $pin_hash), 0, 16);
}

function dash_token_emitir($hardware_id, $pin_hash, $secreto, $ahora = null) {
    $ahora = $ahora === null ? time() : $ahora;
    $exp = $ahora + DASH_TOKEN_DIAS * 86400;
    $payload = dash_b64(json_encode(['i' => $hardware_id, 'e' => $exp, 'v' => dash_huella_pin($pin_hash)]));
    return ['token' => $payload . '.' . dash_b64(hash_hmac('sha256', $payload, $secreto, true)), 'expira' => $exp];
}

function dash_token_valido($token, $hardware_id, $pin_hash, $secreto, $ahora = null) {
    $ahora = $ahora === null ? time() : $ahora;
    if (!is_string($token) || substr_count($token, '.') !== 1 || strlen($token) > 600) return false;
    list($payload, $firma) = explode('.', $token);
    $esperada = dash_b64(hash_hmac('sha256', $payload, $secreto, true));
    if (!hash_equals($esperada, $firma)) return false;
    $p = json_decode((string) dash_b64d($payload), true);
    if (!is_array($p) || !isset($p['i'], $p['e'], $p['v'])) return false;
    return $p['i'] === $hardware_id && (int) $p['e'] > $ahora && hash_equals(dash_huella_pin($pin_hash), (string) $p['v']);
}

// El token viaja en X-Dash-Token (los hostings compartidos a veces no pasan
// Authorization a PHP); se acepta también Authorization: Bearer como respaldo.
function dash_token_de_la_peticion() {
    if (!empty($_SERVER['HTTP_X_DASH_TOKEN'])) return trim($_SERVER['HTTP_X_DASH_TOKEN']);
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION']
          : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');
    if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
    return '';
}

// Fecha de hoy en México (la misma zona que usa todo el panel)
function dash_hoy_mx() {
    $d = new DateTime('now', new DateTimeZone('America/Mexico_City'));
    return $d->format('Y-m-d');
}
