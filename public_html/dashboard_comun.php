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
    // ── Etapa 2 (todo opcional) ─────────────────────────────────────────────
    // Sesión cerrada con el turno abierto: solo cuenta si viene un `true` de verdad
    if (array_key_exists('sesion', $e)) {
        if (is_array($e['sesion']) && isset($e['sesion']['cerrada']) && $e['sesion']['cerrada'] === true) {
            $desde = dash_texto(isset($e['sesion']['desde']) ? $e['sesion']['desde'] : '', 30);
            $out['sesion'] = ['cerrada' => true,
                              'desde' => preg_match('/^\d{2}-\d{2}-\d{4} \d{1,2}:\d{2}(:\d{2})?$/', $desde) ? $desde : ''];
        } else {
            $out['sesion'] = null;
        }
    }

    // Más vendidos: hasta 5 por ventana (importe en centavos, cantidad en milésimas)
    if (isset($e['top_productos']) && is_array($e['top_productos'])) {
        $top = [];
        foreach (['hoy', 'semana', 'mes'] as $ventana) {
            $top[$ventana] = [];
            if (!isset($e['top_productos'][$ventana]) || !is_array($e['top_productos'][$ventana])) continue;
            foreach (array_slice($e['top_productos'][$ventana], 0, 5) as $p) {
                if (!is_array($p)) continue;
                $top[$ventana][] = [
                    'nombre'   => dash_texto(isset($p['nombre']) ? $p['nombre'] : '', 80),
                    'importe'  => dash_monto0(isset($p['importe']) ? $p['importe'] : 0),
                    'cantidad' => dash_int(isset($p['cantidad']) ? $p['cantidad'] : 0, 0, 1000000000000),
                    'unidad'   => dash_int(isset($p['unidad']) ? $p['unidad'] : 0, 0, 3),
                ];
            }
        }
        $out['top_productos'] = $top;
    }

    if (isset($e['por_cajero']) && is_array($e['por_cajero'])) {
        $cajeros = [];
        foreach (array_slice($e['por_cajero'], 0, 10) as $c) {
            if (!is_array($c)) continue;
            $cajeros[] = [
                'cajero'  => dash_texto(isset($c['cajero']) ? $c['cajero'] : '', 60),
                'tickets' => dash_int(isset($c['tickets']) ? $c['tickets'] : 0, 0, 100000000),
                'ventas'  => dash_monto0(isset($c['ventas']) ? $c['ventas'] : 0),
            ];
        }
        $out['por_cajero'] = $cajeros;
    }

    if (isset($e['apartados']) && is_array($e['apartados'])) {
        $a = $e['apartados'];
        $out['apartados'] = [
            'activos'    => dash_int(isset($a['activos']) ? $a['activos'] : 0, 0, 100000000),
            'por_cobrar' => dash_monto0(isset($a['por_cobrar']) ? $a['por_cobrar'] : 0),
            'vencidos'   => dash_int(isset($a['vencidos']) ? $a['vencidos'] : 0, 0, 100000000),
        ];
    }

    if (isset($e['presupuestos']) && is_array($e['presupuestos'])) {
        $pr = $e['presupuestos'];
        $out['presupuestos'] = [
            'vigentes'   => dash_int(isset($pr['vigentes']) ? $pr['vigentes'] : 0, 0, 100000000),
            'monto'      => dash_monto0(isset($pr['monto']) ? $pr['monto'] : 0),
            'por_vencer' => dash_int(isset($pr['por_vencer']) ? $pr['por_vencer'] : 0, 0, 100000000),
            'vencidos'   => dash_int(isset($pr['vencidos']) ? $pr['vencidos'] : 0, 0, 100000000),
        ];
    }

    if (isset($e['gastos']) && is_array($e['gastos'])) {
        $out['gastos'] = [
            'hoy' => dash_monto0(isset($e['gastos']['hoy']) ? $e['gastos']['hoy'] : 0),
            'mes' => dash_monto0(isset($e['gastos']['mes']) ? $e['gastos']['mes'] : 0),
        ];
    }

    if (array_key_exists('meta_diaria', $e)) {
        $out['meta_diaria'] = dash_monto0($e['meta_diaria']);   // 0 = sin meta
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
// `extra` se FUSIONA con lo guardado por clave de primer nivel: una clave nueva sustituye a la vieja (un
// `null` explícito también: «sin turno abierto», «sin sesión cerrada») y lo que no se mandó se conserva.
// Así el POS puede mandar las partes pesadas con menos frecuencia sin que el envío siguiente las borre.
// La fila se bloquea (FOR UPDATE) para que dos envíos seguidos no se pisen.
function dash_guardar_extra(PDO $conn, $hardware_id, array $extra) {
    $conn->beginTransaction();
    try {
        $sel = $conn->prepare('SELECT extra_json FROM dashboard_pos WHERE hardware_id = :id FOR UPDATE');
        $sel->execute([':id' => $hardware_id]);
        $viejo = json_decode((string) $sel->fetchColumn(), true);
        $nuevo = is_array($viejo) ? array_replace($viejo, $extra) : $extra;
        $upd = $conn->prepare('UPDATE dashboard_pos SET extra_json = :j WHERE hardware_id = :id');
        $upd->execute([':j' => json_encode($nuevo, JSON_UNESCAPED_UNICODE), ':id' => $hardware_id]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
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

// ── Premium: el panel solo se consulta con la suscripción ──────────────────────────────
// El POS manda su clave premium firmada (la misma de 125 caracteres que activó el cliente)
// y aquí se verifica con la llave PÚBLICA, igual que lo hace el programa
// (raven.Antiparticula.Utils.LicenciaFirmada): nadie puede fabricar una sin la llave privada
// de la consola. Ver sql/2026-10-20_panel_premium.sql. SIN días gratis: el 2026-10-20 (por la
// tarde) el dueño decidió que el panel es solo de Premium desde ya; la columna gratis_hasta que
// llenó la migración ya no se consulta.
const DASH_PANELES_POR_LICENCIA = 2;     // paneles que abre una misma clave (la PC y una reinstalación)
const DASH_PRUEBA_INTENTOS      = 3;     // intentos gratis de Excel = PropiedadesDAO.INTENTOS_PRUEBA_INICIAL del POS
const DASH_LIC_DOMINIO          = 'AP-LIC-1';
// El plan de varias sucursales (POS 3.5.0): incluye Premium, así que también abre el panel
const DASH_LIC_DOMINIO_MULTI    = 'AP-MUL-1';

// Las llaves públicas con que se verifican las claves: las mismas de
// src/raven/resources/licencia/publica.txt del POS (número => base64 del X.509).
// config_db.php puede sumar otras en 'llaves_licencia' (las pruebas lo hacen; producción no).
//   1 = la de la consola del proveedor (claves a mano)
//   2 = la de las ventas automáticas (tienda_comun.php firma con su privada)
function dash_llaves_licencia(array $cfg) {
    $llaves = [
        1 => 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEKy/44mlC/TV1pIztCcL61eTnftOf7Te7HdxFYA0bdg5tHoUEG9l7Rl9OIr0ObyPW2j4n/A2JZ2Cxs80cH2I+wg==',
        2 => 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE6mFpxCzqSaM2nuCgVeLq5z4A23Z9/Lv0DPKmth3SAYDrTlSzSsbJ8qRTc0ktHjrvgSSKkDFV4NrHDmvXHultOw==',
    ];
    if (isset($cfg['llaves_licencia']) && is_array($cfg['llaves_licencia'])) {
        foreach ($cfg['llaves_licencia'] as $kid => $b64) $llaves[(int) $kid] = (string) $b64;
    }
    return $llaves;
}

// Base32 (RFC 4648, sin relleno) canónico: null si sobra un bit de relleno distinto de 0, como en el POS.
function dash_base32($texto) {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0; $bits = 0; $salida = '';
    $n = strlen($texto);
    for ($i = 0; $i < $n; $i++) {
        $v = strpos($alfabeto, $texto[$i]);
        if ($v === false) return null;
        $buffer = (($buffer << 5) | $v) & 0xFFFF;   // nunca hay más de 12 bits pendientes
        $bits += 5;
        if ($bits >= 8) {
            $salida .= chr(($buffer >> ($bits - 8)) & 0xFF);
            $bits -= 8;
        }
    }
    if ($bits > 0 && ($buffer & ((1 << $bits) - 1)) !== 0) return null;
    return $salida;
}

// La firma viaja como r||s (32 + 32 bytes); openssl la quiere en DER.
function dash_der_entero($bytes) {
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') $bytes = "\x00";
    if (ord($bytes[0]) & 0x80) $bytes = "\x00" . $bytes;
    return "\x02" . chr(strlen($bytes)) . $bytes;
}
function dash_firma_der($rs) {
    $r = dash_der_entero(substr($rs, 0, 32));
    $s = dash_der_entero(substr($rs, 32, 32));
    return "\x30" . chr(strlen($r) + strlen($s)) . $r . $s;
}

// Lee y verifica una clave premium. null si no es una clave válida (formato, llave desconocida o firma).
// Devuelve el vencimiento (Y-m-d), el Hardware ID para el que se emitió, su huella (los 14 bytes de datos)
// y el plan: 'premium' o 'multi' (varias sucursales, que incluye Premium).
// Una clave VENCIDA también se devuelve: decidir si sirve hoy es de quien la usa.
function dash_licencia(array $cfg, $texto) {
    if (!is_string($texto) || !function_exists('openssl_verify')) return null;
    $t = strtoupper(preg_replace('/[\s\-]+/', '', $texto));
    if (strlen($t) !== 125 || !preg_match('/^[A-Z2-7]+$/', $t)) return null;
    $b = dash_base32($t);
    if ($b === null || strlen($b) !== 78) return null;
    $kid = ord($b[0]);
    $llaves = dash_llaves_licencia($cfg);
    if (!isset($llaves[$kid])) return null;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($llaves[$kid], 64, "\n") . "-----END PUBLIC KEY-----\n";
    $datos = substr($b, 0, 14);
    $firma = dash_firma_der(substr($b, 14, 64));
    $plan = null;
    foreach ([DASH_LIC_DOMINIO => 'premium', DASH_LIC_DOMINIO_MULTI => 'multi'] as $dominio => $p) {
        if (openssl_verify($dominio . $datos, $firma, $pem, OPENSSL_ALGO_SHA256) === 1) {
            $plan = $p;
            break;
        }
    }
    if ($plan === null) return null;
    $dias = (ord($b[1]) << 8) | ord($b[2]);
    $vence = new DateTime('2020-01-01', new DateTimeZone('UTC'));
    $vence->modify("+$dias days");
    return ['kid' => $kid, 'vence' => $vence->format('Y-m-d'), 'plan' => $plan,
            'hw' => strtoupper(bin2hex(substr($b, 6, 8))), 'huella' => bin2hex($datos)];
}

// El Hardware ID estable de la PC (16 hexadecimales en mayúsculas, como lo da el POS).
function dash_equipo_valido($equipo) {
    return is_string($equipo) && preg_match('/^[0-9A-F]{16}$/', $equipo);
}

// Qué puede ver un panel hoy. $fila trae premium_hasta de dashboard_pos.
//   abierto  la migración 2026-10-20 no se ha corrido: todo como antes
//   premium  con suscripción; hasta = su vencimiento
//   vencido  sin suscripción vigente; hasta = el día en que venció (null si nunca la tuvo)
// (La migración dejó «prueba» con días gratis; ya no existe: el panel es solo de Premium.)
function dash_acceso(array $fila, $hoy) {
    if (!array_key_exists('premium_hasta', $fila)) {
        return ['estado' => 'abierto', 'hasta' => null];
    }
    $premium = dash_fecha_valida((string) $fila['premium_hasta']) ? $fila['premium_hasta'] : null;
    if ($premium !== null && $premium >= $hoy) return ['estado' => 'premium', 'hasta' => $premium];
    return ['estado' => 'vencido', 'hasta' => $premium];
}

function dash_acceso_de(PDO $conn, $hardware_id) {
    $st = $conn->prepare('SELECT premium_hasta FROM dashboard_pos WHERE hardware_id = :id LIMIT 1');
    $st->execute([':id' => $hardware_id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    return $f ? dash_acceso($f, dash_hoy_mx()) : null;
}

// Anota que esta clave abrió este panel y sube premium_hasta (nunca lo baja: una clave vieja reenviada no
// le quita días a una renovación). Una clave abre a lo más DASH_PANELES_POR_LICENCIA paneles: false si ya
// abrió otros tantos (se está prestando). El FOR UPDATE serializa dos envíos de la misma clave a la vez.
function dash_registrar_licencia(PDO $conn, $hardware_id, array $lic) {
    $conn->beginTransaction();
    try {
        $st = $conn->prepare('SELECT hardware_id FROM dashboard_licencias WHERE huella = :h FOR UPDATE');
        $st->execute([':h' => $lic['huella']]);
        $otros = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $panel) {
            if (strcasecmp($panel, $hardware_id) !== 0) $otros++;
        }
        if ($otros >= DASH_PANELES_POR_LICENCIA) {   // este panel sería uno más
            $conn->rollBack();
            return false;
        }
        $conn->prepare('INSERT INTO dashboard_licencias (huella, hardware_id, vence) VALUES (:h, :id, :v)
                        ON DUPLICATE KEY UPDATE vence = VALUES(vence), visto = CURRENT_TIMESTAMP')
             ->execute([':h' => $lic['huella'], ':id' => $hardware_id, ':v' => $lic['vence']]);
        $conn->prepare('UPDATE dashboard_pos SET premium_hasta = GREATEST(COALESCE(premium_hasta, :v1), :v2)
                         WHERE hardware_id = :id')
             ->execute([':v1' => $lic['vence'], ':v2' => $lic['vence'], ':id' => $hardware_id]);
        $conn->commit();
        return true;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

// La prueba gratis de Excel, por PC. El POS manda cuántos intentos le quedan; aquí se guarda lo más gastado
// (usados solo sube) y se contesta cuántos quedan según el servidor. Desinstalar y volver a instalar el
// programa no devuelve intentos: la PC es la misma.
function dash_prueba_conciliar(PDO $conn, $equipo, $exportar_restantes, $importar_restantes) {
    $exportar_usados = DASH_PRUEBA_INTENTOS - dash_int($exportar_restantes, 0, DASH_PRUEBA_INTENTOS);
    $importar_usados = DASH_PRUEBA_INTENTOS - dash_int($importar_restantes, 0, DASH_PRUEBA_INTENTOS);
    $conn->prepare('INSERT INTO pruebas_equipo (equipo, exportar_usados, importar_usados) VALUES (:e, :x, :i)
                    ON DUPLICATE KEY UPDATE exportar_usados = GREATEST(exportar_usados, VALUES(exportar_usados)),
                                            importar_usados = GREATEST(importar_usados, VALUES(importar_usados))')
         ->execute([':e' => $equipo, ':x' => $exportar_usados, ':i' => $importar_usados]);
    $st = $conn->prepare('SELECT exportar_usados, importar_usados FROM pruebas_equipo WHERE equipo = :e');
    $st->execute([':e' => $equipo]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    return ['exportar' => max(0, DASH_PRUEBA_INTENTOS - (int) $f['exportar_usados']),
            'importar' => max(0, DASH_PRUEBA_INTENTOS - (int) $f['importar_usados'])];
}
