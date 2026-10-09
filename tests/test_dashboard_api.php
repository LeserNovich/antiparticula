<?php
// Pruebas del lado servidor del panel móvil contra una MariaDB real.
// No se corre a mano: lo lanza tests/correr_api.sh, que levanta la base, el
// servidor PHP y este script en contenedores.
//
//   php test_dashboard_api.php <url_base> <migrado|sin-migrar>
//
// Pega por HTTP a los endpoints reales (sync_pos.php, get_dashboard_data.php,
// dashboard_auth.php) y consulta la base directamente solo para preparar
// situaciones (p. ej. adelantar el reloj del bloqueo).

$BASE  = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$MODO  = $argv[2] ?? 'migrado';
$MIGRADO = $MODO === 'migrado';

define('DASH_COMUN_OK', true);
require __DIR__ . '/../public_html/dashboard_comun.php';   // token_emitir/valido para forjar casos
$cfg = require '/app/config_db.php';
$pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET time_zone = '-06:00'");

$pass = 0; $fail = 0;
function ok($c, $label, $extra = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
    else { $fail++; echo "  \033[31m✗\033[0m $label" . ($extra !== '' ? "\n      → $extra" : '') . "\n"; }
}
function eq($a, $b, $label) { ok($a === $b, $label, 'esperado ' . json_encode($b) . ', obtenido ' . json_encode($a)); }
function titulo($t) { echo "\n\033[1m$t\033[0m\n"; }

// ── HTTP ─────────────────────────────────────────────────────────────────────
function http($metodo, $url, $datos = null, $cabeceras = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo,
                            CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
    if ($datos !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos));
    if ($cabeceras) curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);
    $resp = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $cuerpo = substr((string) $resp, $hlen);
    return ['codigo' => $codigo, 'json' => json_decode($cuerpo, true), 'cuerpo' => $cuerpo, 'cabeceras' => substr((string) $resp, 0, $hlen)];
}
function sync($datos) { global $BASE; return http('POST', "$BASE/sync_pos.php", $datos); }
function leer($id, $token = null) {
    global $BASE;
    return http('GET', "$BASE/get_dashboard_data.php?id=" . urlencode($id), null, $token ? ["X-Dash-Token: $token"] : []);
}
function entrar($id, $pin) { global $BASE; return http('POST', "$BASE/dashboard_auth.php", ['id' => $id, 'pin' => $pin]); }

$HOY  = (new DateTime('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
function dia($n) { global $HOY; return date('Y-m-d', strtotime("$HOY -$n days")); }
function base($extra = []) {
    return array_merge(['negocio' => 'Abarrotes Prueba', 'ventas_hoy' => 100000, 'tickets_hoy' => 5, 'ganancia_hoy' => 25000,
        'abonos_hoy' => 0, 'turno_usuario' => 'Ana', 'turno_inicio' => '2026-10-09 08:00:00', 'turno_abierto' => 1,
        'ventas_turno' => 100000, 'tickets_turno' => 5, 'abonos_turno' => 0,
        'ultimas_ventas' => '[]', 'historico_semana' => '[]'], $extra);
}
// El valor null es legítimo (extra/caja sin dato): ?? no distingue «null» de «no existe»
function es_null($arr, $clave) { return is_array($arr) && array_key_exists($clave, $arr) && $arr[$clave] === null; }
function nuevoId() { static $n = 0; $n++; return 'TEST' . strtoupper(dechex(mt_rand(0x10000, 0xFFFFF))) . $n; }
function clave() { return bin2hex(random_bytes(32)); }

echo "\nModo: $MODO · PHP " . PHP_VERSION . " · $BASE\n";

// ═════════════════════════════════════════════════════════════════════════════
titulo('1. Lo de siempre sigue funcionando (POS anterior, sin campos nuevos)');
$id = nuevoId();
$r = sync(base(['hardware_id' => $id]));
eq($r['codigo'], 200, 'sync sin campos nuevos: 200');
eq($r['json']['status'] ?? null, 'exitoso', 'status exitoso');
eq($r['json']['protegido'] ?? null, false, 'sin PIN: protegido=false');
eq($r['json']['avisos'] ?? null, [], 'sin avisos');
$g = leer($id);
eq($g['codigo'], 200, 'lectura: 200');
eq($g['json']['negocio'] ?? null, 'Abarrotes Prueba', 'devuelve los datos');
eq($g['json']['protegido'] ?? null, false, 'protegido=false');
ok(es_null($g['json'], 'extra'), 'sin extra => la clave existe y vale null');
ok(isset($g['json']['historico_dias']) && is_array($g['json']['historico_dias']), 'historico_dias es una lista');
ok(!isset($g['json']['pin_hash']) && !isset($g['json']['clave_hash']) && !isset($g['json']['extra_json']),
   'NO filtra campos internos (pin_hash, clave_hash, extra_json)');
ok(strpos($g['cabeceras'], 'no-store') !== false, 'Cache-Control: no-store');
eq(sync(['negocio' => 'x'])['codigo'], 400, 'sin hardware_id: 400');
eq(sync(['hardware_id' => 'a/b'])['codigo'], 400, 'hardware_id inválido: 400');
eq(http('GET', "$BASE/get_dashboard_data.php")['codigo'], 400, 'lectura sin id: 400');
eq(leer('NOEXISTE9999')['codigo'], 404, 'id desconocido: 404');
eq(http('GET', "$BASE/dashboard_comun.php")['codigo'], 404, 'dashboard_comun.php no es accesible directo');

// ═════════════════════════════════════════════════════════════════════════════
titulo('2. extra: pagos, caja, cancelaciones e inventario');
$id = nuevoId();
$extra = ['v' => 1,
    'pagos_hoy' => ['efectivo' => 60000, 'tarjeta' => 30000, 'transferencia' => 10000, 'comision' => 1160],
    'caja' => ['esperado' => 150000, 'fondo' => 50000, 'efectivo_ventas' => 100000, 'ingresos' => 0, 'retiros' => 0, 'tarjeta' => 30000, 'transferencia' => 10000],
    'cancelaciones_hoy' => ['cancelaciones' => 2, 'monto_cancelado' => 4500, 'devoluciones' => 1, 'monto_devuelto' => 800,
        'por_usuario' => [['usuario' => '<b>Luis</b>', 'cancelaciones' => 2, 'devoluciones' => 1]]],
    'inventario' => ['agotados' => 3, 'bajo_minimo' => 5, 'valor_costo' => 25000000, 'urgentes' => array_map(function ($i) {
        return ['nombre' => "Producto $i <script>", 'cantidad' => $i * 1000, 'minimo' => 10, 'unidad' => $i % 4, 'basura' => 'x'];
    }, range(1, 14))],
    'campo_desconocido' => 'no debe pasar'];
$r = sync(base(['hardware_id' => $id, 'extra' => json_encode($extra)]));
if ($MIGRADO) {
    eq($r['json']['avisos'] ?? null, [], 'extra aceptado sin avisos');
    $e = leer($id)['json']['extra'] ?? null;
    eq($e['pagos_hoy']['tarjeta'] ?? null, 30000, 'pagos_hoy.tarjeta');
    eq($e['caja']['esperado'] ?? null, 150000, 'caja.esperado');
    eq($e['cancelaciones_hoy']['monto_cancelado'] ?? null, 4500, 'cancelaciones_hoy');
    eq($e['cancelaciones_hoy']['por_usuario'][0]['usuario'] ?? null, 'Luis', 'usuario sin HTML');
    eq(count($e['inventario']['urgentes'] ?? []), 10, 'urgentes acotados a 10');
    ok(strpos(json_encode($e), 'script') === false, 'el HTML de los nombres se quita');
    ok(!isset($e['campo_desconocido']) && !isset($e['inventario']['urgentes'][0]['basura']), 'lo desconocido se descarta');
    eq($e['inventario']['urgentes'][3]['unidad'] ?? null, 0, 'unidad dentro de 0..3');

    $r = sync(base(['hardware_id' => $id, 'extra' => json_encode(['caja' => null, 'pagos_hoy' => ['efectivo' => -5]])]));
    $e = leer($id)['json']['extra'] ?? null;
    ok(array_key_exists('caja', $e) && $e['caja'] === null, 'caja=null (turno cerrado) se conserva como null');
    eq($e['pagos_hoy']['efectivo'] ?? null, 0, 'un monto de pago negativo se acota a 0');

    $r = sync(base(['hardware_id' => $id, 'extra' => str_repeat('x', 70000)]));
    eq($r['json']['avisos'] ?? null, ['extra_descartado'], 'extra gigante: se descarta y avisa');
    ok(es_null(leer($id)['json']['extra'], 'caja'), 'y lo anterior queda igual');
    $r = sync(base(['hardware_id' => $id, 'extra' => '{no es json']));
    eq($r['json']['avisos'] ?? null, ['extra_descartado'], 'extra roto: se descarta y avisa');
    eq($r['codigo'], 200, 'un campo roto NO impide guardar el resto');
} else {
    eq($r['codigo'], 200, 'sin migrar: el sync no falla');
    eq($r['json']['avisos'] ?? null, ['extra_no_disponible'], 'sin migrar: avisa que extra no se guardó');
    ok(es_null(leer($id)['json'], 'extra'), 'sin migrar: extra sigue null');
}

// ═════════════════════════════════════════════════════════════════════════════
titulo('3. dias: historial recalculado e idempotente');
$id = nuevoId();
sync(base(['hardware_id' => $id]));
$dias = [
    ['fecha' => dia(1), 'ventas' => 80000, 'ganancia' => 20000, 'tickets' => 4, 'efectivo' => 50000, 'tarjeta' => 30000, 'transferencia' => 0, 'comision' => 1160, 'cancelaciones' => 1, 'cancelado' => 900],
    ['fecha' => dia(8), 'ventas' => 70000, 'ganancia' => 17000, 'tickets' => 3, 'efectivo' => 70000, 'tarjeta' => 0, 'transferencia' => 0, 'comision' => 0, 'cancelaciones' => 0, 'cancelado' => 0],
    ['fecha' => '2026-02-31', 'ventas' => 1],                 // fecha imposible
    ['fecha' => date('Y-m-d', strtotime("$HOY +30 days")), 'ventas' => 1],   // futura
    ['fecha' => dia(900), 'ventas' => 1],                      // demasiado vieja
    ['ventas' => 5],                                           // sin fecha
];
$r = sync(base(['hardware_id' => $id, 'dias' => json_encode($dias)]));
if ($MIGRADO) {
    eq($r['json']['avisos'] ?? null, [], 'dias aceptado');
    $h = leer($id)['json']['historico_dias'] ?? [];
    $por = []; foreach ($h as $f) $por[$f['fecha']] = $f;
    eq($por[dia(1)]['efectivo'] ?? null, 50000, 'ayer: efectivo');
    eq($por[dia(1)]['comision'] ?? null, 1160, 'ayer: comisión');
    eq($por[dia(1)]['cancelaciones'] ?? null, 1, 'ayer: cancelaciones');
    ok(isset($por[dia(8)]), 'hace 8 días está (sirve para «mismo día de la semana pasada»)');
    ok(!isset($por['2026-02-31']), 'fecha imposible descartada');
    eq(count(array_filter($h, function ($f) use ($HOY) { return $f['fecha'] > $HOY; })), 0, 'fecha futura descartada');
    ok(isset($por[$HOY]) && !isset($por[$HOY]['efectivo']), 'hoy (escrito por el código viejo) no inventa un desglose: sin clave, no 0');

    // idempotente + corrige el pasado
    $antes = (int) $pdo->query("SELECT COUNT(*) FROM dashboard_pos_historico WHERE hardware_id = " . $pdo->quote($id))->fetchColumn();
    sync(base(['hardware_id' => $id, 'dias' => json_encode($dias)]));
    $despues = (int) $pdo->query("SELECT COUNT(*) FROM dashboard_pos_historico WHERE hardware_id = " . $pdo->quote($id))->fetchColumn();
    eq($despues, $antes, 'reenviar los mismos días no duplica filas');
    $dias[0]['ventas'] = 60000; $dias[0]['cancelado'] = 20000;     // el POS cancela un ticket de ayer
    sync(base(['hardware_id' => $id, 'dias' => json_encode($dias)]));
    $por = []; foreach (leer($id)['json']['historico_dias'] as $f) $por[$f['fecha']] = $f;
    eq($por[dia(1)]['ventas'] ?? null, 60000, 'una cancelación de un día pasado se CORRIGE');
    eq($por[dia(1)]['cancelado'] ?? null, 20000, 'y se refleja el monto cancelado');

    // hoy: lo recalculado por el POS manda sobre la foto vieja
    sync(base(['hardware_id' => $id, 'dias' => json_encode([['fecha' => $HOY, 'ventas' => 123456, 'ganancia' => 1, 'tickets' => 9, 'efectivo' => 100000, 'tarjeta' => 23456]])]));
    $por = []; foreach (leer($id)['json']['historico_dias'] as $f) $por[$f['fecha']] = $f;
    eq($por[$HOY]['ventas'] ?? null, 123456, 'hoy: dias sobrescribe la foto del código anterior');
    eq($por[$HOY]['tarjeta'] ?? null, 23456, 'hoy: con su desglose');

    // límites
    $muchos = []; for ($i = 1; $i <= 200; $i++) $muchos[] = ['fecha' => dia($i), 'ventas' => $i];
    sync(base(['hardware_id' => $id, 'dias' => json_encode($muchos)]));
    $n = (int) $pdo->query("SELECT COUNT(*) FROM dashboard_pos_historico WHERE hardware_id = " . $pdo->quote($id))->fetchColumn();
    ok($n <= 125, 'se aceptan a lo más 120 días por envío', "filas: $n");
    $r = sync(base(['hardware_id' => $id, 'dias' => '[' . str_repeat('1,', 140000) . '1]']));
    eq($r['json']['avisos'] ?? null, ['dias_descartado'], 'dias gigante: se descarta y avisa');
} else {
    eq($r['json']['avisos'] ?? null, ['dias_no_disponible'], 'sin migrar: avisa que dias no se guardó');
    $g = leer($id);
    eq($g['codigo'], 200, 'sin migrar: la lectura sigue funcionando');
    $f = $g['json']['historico_dias'][0] ?? null;
    ok($f !== null && !isset($f['efectivo']), 'sin migrar: historico_dias con la forma de siempre');
}

// ═════════════════════════════════════════════════════════════════════════════
titulo('4. meses: el año completo lo manda el POS');
$id = nuevoId();
$anio = substr($HOY, 0, 4);
sync(base(['hardware_id' => $id]));
$sin = leer($id)['json']['historico_anual'] ?? null;
ok(is_array($sin), 'sin meses: historico_anual es el de siempre (suma de snapshots)');
$meses = [['mes' => "$anio-01", 'ventas' => 4000000, 'ganancia' => 900000, 'tickets' => 300],
          ['mes' => "$anio-02", 'ventas' => 3500000, 'ganancia' => 800000, 'tickets' => 280],
          ['mes' => '2026-13', 'ventas' => 1], ['mes' => 'enero', 'ventas' => 1]];
$r = sync(base(['hardware_id' => $id, 'meses' => json_encode($meses)]));
if ($MIGRADO) {
    eq($r['json']['avisos'] ?? null, [], 'meses aceptado');
    $a = leer($id)['json']['historico_anual'] ?? [];
    eq(count($a), 2, 'solo los 2 meses válidos');
    eq($a[0]['mes'] ?? null, "$anio-01", 'enero');
    eq($a[0]['ventas_centavos'] ?? null, 4000000, 'enero: ventas completas, no solo desde que sincroniza');
    $meses[0]['ventas'] = 3900000;
    sync(base(['hardware_id' => $id, 'meses' => json_encode($meses)]));
    eq(leer($id)['json']['historico_anual'][0]['ventas_centavos'] ?? null, 3900000, 'reenviar corrige el mes (idempotente)');
} else {
    eq($r['json']['avisos'] ?? null, ['meses_no_disponible'], 'sin migrar: avisa que meses no se guardó');
    ok(is_array(leer($id)['json']['historico_anual'] ?? null), 'sin migrar: el año sigue saliendo del cálculo de siempre');
}

// ═════════════════════════════════════════════════════════════════════════════
if (!$MIGRADO) {
    titulo('5. PIN con la base sin migrar');
    $id = nuevoId(); $k = clave();
    $r = sync(base(['hardware_id' => $id, 'pin' => '123456', 'clave' => $k]));
    eq($r['codigo'], 200, 'pedir PIN sin migrar no rompe el sync');
    eq($r['json']['protegido'] ?? null, false, 'queda SIN protección (el POS lo detecta con protegido=false)');
    ok(in_array('pin_no_disponible', $r['json']['avisos'] ?? []), 'avisa pin_no_disponible');
    eq(leer($id)['codigo'], 200, 'el panel sigue abierto y legible');
    echo "\n\033[1mResultado: $pass pasadas, $fail fallidas\033[0m\n";
    exit($fail === 0 ? 0 : 1);
}

titulo('5. PIN: activar, leer con token y escribir con clave');
$id = nuevoId(); $k = clave(); $PIN = '482915';
sync(base(['hardware_id' => $id]));
eq(sync(base(['hardware_id' => $id, 'pin' => '1234', 'clave' => $k]))['codigo'], 400, 'PIN de 4 dígitos: 400');
eq(sync(base(['hardware_id' => $id, 'pin' => 'abcdef', 'clave' => $k]))['codigo'], 400, 'PIN con letras: 400');
$r = sync(base(['hardware_id' => $id, 'pin' => $PIN]));
ok(in_array('pin_sin_clave', $r['json']['avisos'] ?? []) && ($r['json']['protegido'] ?? true) === false, 'PIN sin clave: no activa nada');
eq(leer($id)['codigo'], 200, '…y el panel sigue abierto');

$r = sync(base(['hardware_id' => $id, 'pin' => $PIN, 'clave' => $k]));
eq($r['json']['protegido'] ?? null, true, 'activar PIN: protegido=true');
$g = leer($id);
eq($g['codigo'], 401, 'protegido: leer SIN token da 401');
eq($g['json']['status'] ?? null, 'protegido', 'status protegido');
ok(!isset($g['json']['negocio']) && !isset($g['json']['ventas_hoy']), '…y NO entrega ningún dato');
ok(strpos($g['cuerpo'], 'Abarrotes') === false, 'el cuerpo no contiene datos del negocio');

$e = entrar($id, '000000');
eq($e['codigo'], 401, 'PIN incorrecto: 401');
eq($e['json']['codigo'] ?? null, 'pin_incorrecto', 'codigo pin_incorrecto');
eq($e['json']['restantes'] ?? null, 7, 'avisa cuántos intentos quedan');
$e = entrar($id, $PIN);
eq($e['codigo'], 200, 'PIN correcto: 200');
$token = $e['json']['token'] ?? '';
ok(strlen($token) > 40 && substr_count($token, '.') === 1, 'devuelve un token');
ok(($e['json']['expira'] ?? 0) > time() + 29 * 86400, 'vigencia ~30 días');
$g = leer($id, $token);
eq($g['codigo'], 200, 'con token: 200');
eq($g['json']['negocio'] ?? null, 'Abarrotes Prueba', 'con token entrega los datos');
eq($g['json']['protegido'] ?? null, true, 'protegido=true');
ok(!isset($g['json']['pin_hash']) && !isset($g['json']['clave_hash']) && !isset($g['json']['pin_fallos']), 'protegido: sigue sin filtrar campos internos');
$gb = http('GET', "$BASE/get_dashboard_data.php?id=" . urlencode($id), null, ["Authorization: Bearer $token"]);
eq($gb['codigo'], 200, 'también sirve Authorization: Bearer (respaldo)');
eq(entrar($id, $PIN)['json']['protegido'] ?? null, true, 'entrar reinicia el contador de fallos');

// tokens malos
$partes = explode('.', $token);
eq(leer($id, $partes[0] . '.' . strrev($partes[1]))['codigo'], 401, 'firma alterada: 401');
eq(leer($id, 'basura')['codigo'], 401, 'token sin forma: 401');
$otro = nuevoId(); sync(base(['hardware_id' => $otro, 'pin' => $PIN, 'clave' => clave()]));
eq(leer($otro, $token)['codigo'], 401, 'el token de un panel NO sirve en otro');
$prot = dash_proteccion($pdo, $id);
$viejo = dash_token_emitir($id, $prot['pin_hash'], dash_secreto($cfg), time() - 31 * 86400);
eq(leer($id, $viejo['token'])['codigo'], 401, 'token vencido (31 días): 401');
ok(dash_token_valido($token, $id, $prot['pin_hash'], dash_secreto($cfg)), 'sanidad: el token bueno valida');
$otra_clave_secreta = dash_token_emitir($id, $prot['pin_hash'], 'otro-secreto-distinto-123456');
eq(leer($id, $otra_clave_secreta['token'])['codigo'], 401, 'firmado con otro secreto: 401');

// escritura
$antes = (int) $pdo->query("SELECT ventas_hoy FROM dashboard_pos WHERE hardware_id = " . $pdo->quote($id))->fetchColumn();
$r = sync(base(['hardware_id' => $id, 'ventas_hoy' => 999999]));
eq($r['codigo'], 403, 'escribir sin clave: 403');
eq($r['json']['codigo'] ?? null, 'clave_invalida', 'codigo clave_invalida');
$r = sync(base(['hardware_id' => $id, 'ventas_hoy' => 999999, 'clave' => clave()]));
eq($r['codigo'], 403, 'escribir con otra clave: 403');
$desp = (int) $pdo->query("SELECT ventas_hoy FROM dashboard_pos WHERE hardware_id = " . $pdo->quote($id))->fetchColumn();
eq($desp, $antes, 'un intento no autorizado NO cambia nada en la base');
$r = sync(base(['hardware_id' => $id, 'ventas_hoy' => 777777, 'clave' => $k, 'extra' => json_encode(['caja' => null])]));
eq($r['codigo'], 200, 'escribir con la clave: 200');
eq($r['json']['protegido'] ?? null, true, '…y sigue protegido');
eq((int) leer($id, $token)['json']['ventas_hoy'], 777777, 'el dato nuevo se ve');
$ph = $pdo->query("SELECT pin_hash, clave_hash FROM dashboard_pos WHERE hardware_id = " . $pdo->quote($id))->fetch(PDO::FETCH_ASSOC);
ok($ph['pin_hash'] !== $PIN && strpos($ph['pin_hash'], '$') === 0, 'el PIN se guarda con password_hash, no en claro');
ok($ph['clave_hash'] === hash('sha256', $k) && $ph['clave_hash'] !== $k, 'la clave se guarda como sha256, no en claro');

titulo('6. PIN: cambiar, quitar, reconectar');
$PIN2 = '735190';
$r = sync(base(['hardware_id' => $id, 'clave' => $k, 'pin' => $PIN2]));
eq($r['json']['protegido'] ?? null, true, 'cambiar PIN con la clave: sigue protegido');
eq(leer($id, $token)['codigo'], 401, 'tras cambiar el PIN, los tokens anteriores dejan de servir');
eq(entrar($id, $PIN)['codigo'], 401, 'el PIN anterior ya no entra');
$token = entrar($id, $PIN2)['json']['token'] ?? '';
eq(leer($id, $token)['codigo'], 200, 'el PIN nuevo entra');

// reconectar: la PC perdió su clave (reinstalación)
$k2 = clave();
$r = sync(base(['hardware_id' => $id, 'clave' => $k2, 'pin_actual' => '111111']));
eq($r['codigo'], 403, 'reconectar con PIN equivocado: 403');
eq($r['json']['codigo'] ?? null, 'pin_incorrecto', '…con codigo pin_incorrecto (no clave_invalida)');
eq($r['json']['restantes'] ?? null, 7, '…y cuántos intentos quedan');
eq(sync(base(['hardware_id' => $id, 'clave' => $k2]))['codigo'], 403, 'una clave nueva SIN pin_actual no reemplaza a la vieja');
$r = sync(base(['hardware_id' => $id, 'clave' => $k2, 'pin_actual' => $PIN2, 'ventas_hoy' => 555000]));
eq($r['codigo'], 200, 'reconectar con el PIN actual: 200');
eq($r['json']['protegido'] ?? null, true, '…y sigue protegido');
eq((int) leer($id, $token)['json']['ventas_hoy'], 555000, 'lo enviado al reconectar se guardó');
eq(sync(base(['hardware_id' => $id, 'clave' => $k]))['codigo'], 403, 'la clave anterior ya no sirve');
eq(sync(base(['hardware_id' => $id, 'clave' => $k2]))['codigo'], 200, 'la clave nueva sí');

// quitar
$r = sync(base(['hardware_id' => $id, 'clave' => 'x' . substr($k2, 1), 'pin_clear' => '1']));
eq($r['codigo'], 403, 'quitar PIN con clave mala: 403');
eq(leer($id)['codigo'], 401, '…y sigue protegido');
$r = sync(base(['hardware_id' => $id, 'clave' => $k2, 'pin_clear' => '1']));
eq($r['codigo'], 200, 'quitar PIN con la clave: 200');
eq($r['json']['protegido'] ?? null, false, 'protegido=false');
eq(leer($id)['codigo'], 200, 'el panel vuelve a abrir sin token');
$ph = $pdo->query("SELECT pin_hash, clave_hash, pin_fallos FROM dashboard_pos WHERE hardware_id = " . $pdo->quote($id))->fetch(PDO::FETCH_ASSOC);
ok($ph['pin_hash'] === null && $ph['clave_hash'] === null, 'se borran pin_hash y clave_hash');
eq(entrar($id, '123456')['json']['protegido'] ?? null, false, 'dashboard_auth: panel sin PIN avisa protegido=false');
eq(sync(base(['hardware_id' => $id]))['codigo'], 200, 'sin PIN se vuelve a poder escribir sin clave');
// protegerlo otra vez (cualquiera puede reclamarlo mientras no tenga PIN: es lo documentado)
eq(sync(base(['hardware_id' => $id, 'pin' => $PIN, 'clave' => $k2]))['json']['protegido'] ?? null, true, 'se puede volver a activar');

titulo('7. PIN: bloqueo por fuerza bruta');
$id = nuevoId(); $k = clave();
sync(base(['hardware_id' => $id, 'pin' => $PIN, 'clave' => $k]));
$ultimo = null;
for ($i = 1; $i <= 7; $i++) $ultimo = entrar($id, sprintf('%06d', $i));
eq($ultimo['json']['restantes'] ?? null, 1, 'tras 7 fallos queda 1 intento');
$ultimo = entrar($id, '999999');
eq($ultimo['codigo'], 429, 'el 8.º fallo bloquea: 429');
eq($ultimo['json']['codigo'] ?? null, 'bloqueado', 'codigo bloqueado');
ok(($ultimo['json']['reintentar_en'] ?? 0) > 14 * 60, 'dice cuánto falta (~15 min)');
$e = entrar($id, $PIN);
eq($e['codigo'], 429, 'bloqueado: ni el PIN CORRECTO entra');
ok(!isset($e['json']['token']), '…y no entrega token');
$r = sync(base(['hardware_id' => $id, 'clave' => clave(), 'pin_actual' => $PIN]));
eq($r['codigo'], 429, 'bloqueado: tampoco se puede reconectar con el PIN correcto');
// pasa el tiempo
$pdo->exec("UPDATE dashboard_pos SET pin_bloqueo_hasta = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE hardware_id = " . $pdo->quote($id));
eq(entrar($id, $PIN)['codigo'], 200, 'pasado el bloqueo, el PIN correcto entra');
eq((int) $pdo->query("SELECT pin_fallos FROM dashboard_pos WHERE hardware_id = " . $pdo->quote($id))->fetchColumn(), 0, 'y el contador vuelve a 0');
// a mitad de camino un acierto reinicia la cuenta
for ($i = 1; $i <= 5; $i++) entrar($id, sprintf('%06d', $i));
entrar($id, $PIN);
eq(entrar($id, '000001')['json']['restantes'] ?? null, 7, 'un acierto reinicia la cuenta de fallos');
// tras expirar un bloqueo, un fallo vuelve a bloquear de inmediato (el contador no se limpia solo)
$id3 = nuevoId(); sync(base(['hardware_id' => $id3, 'pin' => $PIN, 'clave' => clave()]));
for ($i = 1; $i <= 8; $i++) entrar($id3, sprintf('%06d', $i));
$pdo->exec("UPDATE dashboard_pos SET pin_bloqueo_hasta = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE hardware_id = " . $pdo->quote($id3));
eq(entrar($id3, '555555')['codigo'], 429, 'tras un bloqueo vencido, el siguiente fallo bloquea otra vez');

titulo('8. CORS y método');
$op = http('OPTIONS', "$BASE/get_dashboard_data.php", null, ['Origin: https://www.antiparticula.com']);
eq($op['codigo'], 204, 'preflight de lectura: 204');
ok(stripos($op['cabeceras'], 'X-Dash-Token') !== false, 'el preflight permite X-Dash-Token');
$op = http('OPTIONS', "$BASE/dashboard_auth.php", null, ['Origin: https://www.antiparticula.com']);
eq($op['codigo'], 204, 'preflight de dashboard_auth: 204');
eq(http('GET', "$BASE/dashboard_auth.php")['codigo'], 405, 'dashboard_auth solo acepta POST');
eq(http('POST', "$BASE/dashboard_auth.php", ['id' => 'a', 'pin' => '1'])['codigo'], 400, 'dashboard_auth: id inválido 400');
eq(http('POST', "$BASE/dashboard_auth.php", ['id' => 'NOEXISTE9999', 'pin' => '123456'])['codigo'], 404, 'dashboard_auth: id desconocido 404');

echo "\n\033[1mResultado: $pass pasadas, $fail fallidas\033[0m\n";
exit($fail === 0 ? 0 : 1);
