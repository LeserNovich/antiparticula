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

// ── Claves premium firmadas con una llave de PRUEBA (kid 7) ─────────────────
// El servidor la acepta porque se suma a config_db.php en 'llaves_licencia' (producción no lo hace).
function base32($bin) {
    $alf = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = ''; $buf = 0; $bits = 0;
    for ($i = 0; $i < strlen($bin); $i++) {
        $buf = (($buf << 8) | ord($bin[$i])) & 0xFFFF; $bits += 8;
        while ($bits >= 5) { $s .= $alf[($buf >> ($bits - 5)) & 31]; $bits -= 5; }
    }
    if ($bits > 0) $s .= $alf[($buf << (5 - $bits)) & 31];
    return $s;
}
function der_a_rs($der) {   // SEQUENCE { INTEGER r, INTEGER s } -> r||s de 32 + 32 bytes
    $p = 2; $rs = '';
    for ($k = 0; $k < 2; $k++) {
        $n = ord($der[$p + 1]); $v = ltrim(substr($der, $p + 2, $n), "\x00"); $p += 2 + $n;
        $rs .= str_pad($v, 32, "\x00", STR_PAD_LEFT);
    }
    return $rs;
}
function hacer_licencia($llave, $kid, $vence, $hw, $nonce = null) {
    $dias = (int) round((strtotime("$vence 00:00:00 UTC") - strtotime('2020-01-01 00:00:00 UTC')) / 86400);
    $nonce = $nonce === null ? random_int(0, 0xFFFFFF) : $nonce;
    $datos = chr($kid) . chr($dias >> 8) . chr($dias & 0xFF) . chr($nonce >> 16) . chr(($nonce >> 8) & 0xFF) . chr($nonce & 0xFF) . hex2bin($hw);
    openssl_sign('AP-LIC-1' . $datos, $der, $llave, OPENSSL_ALGO_SHA256);
    return base32($datos . der_a_rs($der));
}
function dentro($n) { global $HOY; return date('Y-m-d', strtotime("$HOY +$n days")); }
function equipoNuevo() { return strtoupper(bin2hex(random_bytes(8))); }
function prueba_gratis($datos) { global $BASE; return http('POST', "$BASE/prueba_gratis.php", $datos); }

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
titulo('2b. extra, etapa 2: sesión, más vendidos, cajeros, por cobrar, gastos y meta');
$id = nuevoId();
if ($MIGRADO) {
    $mas = function ($n) { return array_map(function ($i) { return ['nombre' => "Prod $i <b>x</b>", 'importe' => 1000 * $i, 'cantidad' => 1000 * $i, 'unidad' => 1]; }, range(1, $n)); };
    $e2 = [
        'sesion' => ['cerrada' => true, 'desde' => '09-10-2026 18:05:00'],
        'top_productos' => ['hoy' => $mas(8), 'semana' => $mas(2), 'mes' => [['nombre' => 'Neg', 'importe' => -5, 'cantidad' => -1, 'unidad' => 1]], 'anio' => $mas(1)],
        'por_cajero' => array_map(function ($i) { return ['cajero' => "Cajero $i<script>", 'tickets' => $i, 'ventas' => 100 * $i]; }, range(1, 14)),
        'apartados' => ['activos' => 3, 'por_cobrar' => 125000, 'vencidos' => 1],
        'presupuestos' => ['vigentes' => 4, 'monto' => 900000, 'por_vencer' => 2, 'vencidos' => 1],
        'gastos' => ['hoy' => 15000, 'mes' => 420000],
        'meta_diaria' => 1500000,
        'clave_desconocida' => 'no debe pasar',
    ];
    $r = sync(base(['hardware_id' => $id, 'extra' => json_encode($e2)]));
    eq($r['json']['avisos'] ?? null, [], 'extra de la etapa 2 aceptado');
    $e = leer($id)['json']['extra'] ?? [];
    eq($e['sesion']['cerrada'] ?? null, true, 'sesion.cerrada');
    eq($e['sesion']['desde'] ?? null, '09-10-2026 18:05:00', 'sesion.desde con el formato del POS');
    eq(count($e['top_productos']['hoy'] ?? []), 5, 'más vendidos: tope de 5 por ventana');
    eq(count($e['top_productos']['semana'] ?? []), 2, 'la ventana semana conserva sus 2');
    ok(strpos(json_encode($e['top_productos']), '<b>') === false, 'el HTML de los nombres se quita');
    eq($e['top_productos']['mes'][0]['importe'] ?? null, 0, 'un importe negativo se acota a 0');
    eq($e['top_productos']['mes'][0]['cantidad'] ?? null, 0, 'una cantidad negativa se acota a 0');
    ok(!isset($e['top_productos']['anio']), 'una ventana que no existe se descarta');
    eq(count($e['por_cajero'] ?? []), 10, 'por cajero: tope de 10');
    ok(strpos(json_encode($e['por_cajero']), 'script') === false, 'el nombre del cajero sale sin HTML');
    eq($e['apartados']['por_cobrar'] ?? null, 125000, 'apartados por cobrar');
    eq($e['presupuestos']['por_vencer'] ?? null, 2, 'presupuestos por vencer');
    eq($e['gastos']['mes'] ?? null, 420000, 'gastos del mes');
    eq($e['meta_diaria'] ?? null, 1500000, 'meta diaria');
    ok(!isset($e['clave_desconocida']), 'lo desconocido se descarta');

    // Valores malos
    $r = sync(base(['hardware_id' => $id, 'extra' => json_encode(['sesion' => ['cerrada' => 'true', 'desde' => 'ayer'], 'meta_diaria' => -500])]));
    $e = leer($id)['json']['extra'] ?? [];
    ok(es_null($e, 'sesion'), '«cerrada» como texto no cuenta: solo un true de verdad');
    eq($e['meta_diaria'] ?? null, 0, 'una meta negativa se acota a 0 (= sin meta)');
    sync(base(['hardware_id' => $id, 'extra' => json_encode(['sesion' => ['cerrada' => true, 'desde' => 'ayer por la tarde']])]));
    eq(leer($id)['json']['extra']['sesion']['desde'] ?? 'x', '', 'una fecha de sesión que no tiene el formato queda vacía');

    // Fusión: un envío parcial conserva lo demás; un null explícito sustituye
    sync(base(['hardware_id' => $id, 'extra' => json_encode($e2)]));
    sync(base(['hardware_id' => $id, 'extra' => json_encode(['meta_diaria' => 2000000])]));
    $e = leer($id)['json']['extra'] ?? [];
    eq($e['meta_diaria'] ?? null, 2000000, 'fusión: la clave nueva sustituye a la vieja');
    eq($e['gastos']['hoy'] ?? null, 15000, 'fusión: lo que no se mandó se conserva (gastos)');
    eq(count($e['top_productos']['hoy'] ?? []), 5, 'fusión: …y los más vendidos también');
    sync(base(['hardware_id' => $id, 'extra' => json_encode(['sesion' => null])]));
    ok(es_null(leer($id)['json']['extra'], 'sesion'), 'fusión: un null explícito sustituye (la sesión volvió a estar abierta)');
    sync(base(['hardware_id' => $id, 'extra' => json_encode(['caja' => null])]));
    $e = leer($id)['json']['extra'];
    ok(es_null($e, 'caja') && ($e['gastos']['mes'] ?? null) === 420000, 'fusión: caja=null (turno cerrado) y lo demás sigue');
    // Un envío SIN extra no borra lo guardado
    sync(base(['hardware_id' => $id]));
    eq(leer($id)['json']['extra']['gastos']['mes'] ?? null, 420000, 'un envío sin extra no borra lo guardado');
    // Dos paneles no se mezclan
    $otro = nuevoId();
    sync(base(['hardware_id' => $otro, 'extra' => json_encode(['gastos' => ['hoy' => 1, 'mes' => 2]])]));
    eq(leer($id)['json']['extra']['gastos']['mes'] ?? null, 420000, 'la fusión no toca el extra de otro panel');
} else {
    ok(true, 'sin migrar: extra no se guarda (ya cubierto arriba)');
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

    titulo('6. Premium con la base sin migrar: todo sigue abierto, como antes');
    $id = nuevoId();
    $r = sync(base(['hardware_id' => $id]));
    ok(es_null($r['json'], 'acceso'), 'acceso = null (no se sabe)');
    eq($r['json']['avisos'] ?? null, [], 'un POS anterior no recibe avisos nuevos');
    $r = sync(base(['hardware_id' => $id, 'equipo' => equipoNuevo(), 'licencia' => str_repeat('A', 125)]));
    eq($r['codigo'], 200, 'POS 3.4.0 contra la base vieja: 200');
    ok(in_array('acceso_no_disponible', $r['json']['avisos'] ?? []), '…y avisa acceso_no_disponible');
    $g = leer($id);
    eq($g['codigo'], 200, 'el panel se lee');
    eq($g['json']['acceso']['estado'] ?? null, 'abierto', 'estado «abierto»');
    $p = prueba_gratis(['equipo' => equipoNuevo(), 'exportar' => '3', 'importar' => '3']);
    eq($p['codigo'], 503, 'prueba_gratis sin su tabla: 503 (el POS sigue con su cuenta local)');
    eq($p['json']['status'] ?? null, 'no_disponible', '…status no_disponible');
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

titulo('9. Premium: días gratis, clave firmada y la puerta de lectura');
// La llave de prueba: se suma a config_db.php (el servidor lo relee en cada petición)
$par = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pub = preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($par)['key']);
$cfg_prueba = $cfg; $cfg_prueba['llaves_licencia'] = [7 => $pub];
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg_prueba, true) . ';');
// (la llave de producción no se tiene aquí; la interoperabilidad con el POS la prueba el ejemplo de Java, más abajo)

// Paneles de antes: la migración les dio 30 días desde hoy y correrla otra vez no los alarga
eq($pdo->query("SELECT gratis_hasta FROM dashboard_pos WHERE hardware_id = 'ANTES0001'")->fetchColumn(), dentro(30),
   'panel de antes: 30 días de aviso desde que se corre la migración');
eq($pdo->query("SELECT gratis_hasta FROM dashboard_pos WHERE hardware_id = 'ANTES0002'")->fetchColumn(), dentro(3),
   'correrla otra vez no alarga ni reinicia lo que ya tenía fecha');
$g = leer('ANTES0001');
eq($g['codigo'], 200, 'un panel de antes se sigue viendo en su aviso');
eq($g['json']['acceso'] ?? null, ['estado' => 'prueba', 'hasta' => dentro(30)], '…y el panel recibe la fecha para avisar');
ok(!array_key_exists('gratis_hasta', $g['json']) && !array_key_exists('premium_hasta', $g['json']), 'las columnas internas no salen');

// Un panel nuevo: 15 días gratis
$id = nuevoId(); $eq1 = equipoNuevo();
$r = sync(base(['hardware_id' => $id, 'equipo' => $eq1]));
eq($r['json']['avisos'] ?? null, [], 'sync de un POS 3.4.0 sin clave: sin avisos');
eq($r['json']['acceso'] ?? null, ['estado' => 'prueba', 'hasta' => dentro(15)], 'panel nuevo: 15 días gratis');
eq(leer($id)['json']['acceso']['estado'] ?? null, 'prueba', 'la lectura dice lo mismo');
$r = sync(base(['hardware_id' => $id, 'equipo' => $eq1]));
eq($r['json']['acceso']['hasta'] ?? null, dentro(15), 'otro envío no reinicia los 15 días');
eq((int) $pdo->query("SELECT COUNT(*) FROM pruebas_equipo WHERE equipo = " . $pdo->quote($eq1))->fetchColumn(), 1, 'la PC queda anotada');

// Se acaban los días gratis
$pdo->exec("UPDATE dashboard_pos SET gratis_hasta = " . $pdo->quote(dia(1)) . " WHERE hardware_id = " . $pdo->quote($id));
$g = leer($id);
eq($g['codigo'], 403, 'sin días gratis ni Premium: 403');
eq($g['json']['status'] ?? null, 'premium_requerido', 'status premium_requerido');
eq($g['json']['negocio'] ?? null, 'Abarrotes Prueba', 'dice de qué negocio es');
eq($g['json']['desde'] ?? null, dia(1), 'y desde cuándo');
ok(!isset($g['json']['ventas_hoy']) && !isset($g['json']['extra']) && !isset($g['json']['ultimas_ventas']), '…sin ni una cifra');
$r = sync(base(['hardware_id' => $id, 'equipo' => $eq1]));
eq($r['codigo'], 200, 'el POS puede seguir enviando (para cuando pague)');
eq($r['json']['acceso']['estado'] ?? null, 'vencido', '…y se entera de que venció');

// La clave premium
$hw = equipoNuevo();
$lic = hacer_licencia($par, 7, dentro(365), $hw);
eq(strlen($lic), 125, '(la clave de prueba mide 125 caracteres, como las reales)');
$r = sync(base(['hardware_id' => $id, 'equipo' => $eq1, 'licencia' => $lic]));
eq($r['json']['avisos'] ?? null, [], 'clave válida: sin avisos');
eq($r['json']['acceso'] ?? null, ['estado' => 'premium', 'hasta' => dentro(365)], 'con la clave: premium hasta su vencimiento');
$g = leer($id);
eq($g['codigo'], 200, 'el panel vuelve a abrir');
eq($g['json']['acceso']['estado'] ?? null, 'premium', '…como premium');
eq($g['json']['ventas_hoy'] ?? null, 100000, '…con sus cifras');
$conGuiones = implode('-', str_split(strtolower($lic), 5));
eq(dash_licencia($cfg_prueba, $conGuiones)['vence'] ?? null, dentro(365), 'con guiones y en minúsculas también se lee (como en el POS)');
// una renovación sube la fecha; reenviar la clave vieja no la baja
$lic2 = hacer_licencia($par, 7, dentro(730), $hw);
eq(sync(base(['hardware_id' => $id, 'licencia' => $lic2]))['json']['acceso']['hasta'] ?? null, dentro(730), 'renovación: sube la fecha');
eq(sync(base(['hardware_id' => $id, 'licencia' => $lic]))['json']['acceso']['hasta'] ?? null, dentro(730), 'la clave vieja reenviada no la baja');
// un POS que deja de mandar la clave (o uno anterior) no pierde lo ya verificado
eq(sync(base(['hardware_id' => $id]))['json']['acceso']['estado'] ?? null, 'premium', 'sin clave en un envío: sigue premium');

// Claves malas
$id2 = nuevoId();
sync(base(['hardware_id' => $id2]));
$pdo->exec("UPDATE dashboard_pos SET gratis_hasta = " . $pdo->quote(dia(1)) . " WHERE hardware_id = " . $pdo->quote($id2));
$i = 40; $mala = substr($lic, 0, $i) . ($lic[$i] === 'A' ? 'B' : 'A') . substr($lic, $i + 1);
$r = sync(base(['hardware_id' => $id2, 'licencia' => $mala]));
ok(in_array('licencia_invalida', $r['json']['avisos'] ?? []), 'un carácter cambiado: licencia_invalida');
eq($r['json']['acceso']['estado'] ?? null, 'vencido', '…y no abre nada');
$malas = 0;
for ($i = 0; $i < 125; $i++) {
    $otra = substr($lic, 0, $i) . ($lic[$i] === 'Q' ? 'R' : 'Q') . substr($lic, $i + 1);
    if (dash_licencia($cfg_prueba, $otra) === null) $malas++;
}
eq($malas, 125, 'cambiar CUALQUIERA de los 125 caracteres la invalida');
eq(dash_licencia($cfg_prueba, hacer_licencia($par, 9, dentro(30), $hw)), null, 'llave desconocida (kid 9): no vale');
eq(dash_licencia($cfg, $lic), null, 'sin la llave de prueba en la configuración, la clave de prueba no vale (no hay llave «comodín»)');
$otraLlave = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
eq(dash_licencia($cfg_prueba, hacer_licencia($otraLlave, 7, dentro(30), $hw)), null, 'firmada con otra llave privada: no vale');
eq(dash_licencia($cfg_prueba, substr($lic, 0, 124)), null, 'incompleta: no vale');
eq(dash_licencia($cfg_prueba, substr($lic, 0, 124) . '1'), null, 'con un carácter fuera de Base32: no vale');
// una clave de recuperación de contraseña (otro dominio de firma) no es una clave premium
$rec = (function () use ($par, $hw) {
    $dias = (int) round((strtotime(dentro(3) . ' UTC') - strtotime('2020-01-01 UTC')) / 86400);
    $datos = chr(7) . chr($dias >> 8) . chr($dias & 0xFF) . "\x01\x02\x03" . hex2bin($hw);
    openssl_sign('AP-REC-1' . $datos, $der, $par, OPENSSL_ALGO_SHA256);
    return base32($datos . der_a_rs($der));
})();
eq(dash_licencia($cfg_prueba, $rec), null, 'un código de recuperación (AP-REC-1) no vale como Premium');
// vencida: se registra pero no abre
$vieja = hacer_licencia($par, 7, dia(2), $hw);
$r = sync(base(['hardware_id' => $id2, 'licencia' => $vieja]));
eq($r['json']['acceso']['estado'] ?? null, 'vencido', 'clave vencida: no abre el panel');
eq(leer($id2)['codigo'], 403, '…la lectura sigue cerrada');

// Una clave no se presta: abre 2 paneles (la PC y una reinstalación), no un tercero
$lic3 = hacer_licencia($par, 7, dentro(100), equipoNuevo());
$p1 = nuevoId(); $p2 = nuevoId(); $p3 = nuevoId();
eq(sync(base(['hardware_id' => $p1, 'licencia' => $lic3]))['json']['acceso']['estado'] ?? null, 'premium', '1.er panel con la clave: premium');
eq(sync(base(['hardware_id' => $p2, 'licencia' => $lic3]))['json']['acceso']['estado'] ?? null, 'premium', '2.º panel (reinstalación): premium');
sync(base(['hardware_id' => $p3]));   // el 3.er panel existe y ya sin días gratis
$pdo->exec("UPDATE dashboard_pos SET gratis_hasta = " . $pdo->quote(dia(1)) . " WHERE hardware_id = " . $pdo->quote($p3));
$r = sync(base(['hardware_id' => $p3, 'licencia' => $lic3]));
ok(in_array('licencia_en_otros_paneles', $r['json']['avisos'] ?? []), '3.er panel: licencia_en_otros_paneles');
eq($r['json']['acceso']['estado'] ?? null, 'vencido', '…y NO se abre');
eq($pdo->query("SELECT premium_hasta FROM dashboard_pos WHERE hardware_id = " . $pdo->quote($p3))->fetchColumn(), null, '…ni se le anota premium');
eq(sync(base(['hardware_id' => $p1, 'licencia' => $lic3]))['json']['avisos'] ?? null, [], 'los 2 primeros la siguen usando sin aviso');
$pdo->exec("DELETE FROM dashboard_licencias WHERE hardware_id = " . $pdo->quote($p2));   // soporte libera el panel viejo
eq(sync(base(['hardware_id' => $p3, 'licencia' => $lic3]))['json']['acceso']['estado'] ?? null, 'premium', 'soporte libera un panel: el nuevo ya abre');

// Con PIN, quien no tiene el PIN no se entera ni de eso: 401 antes que 403
$idp = nuevoId(); $kp = clave();
sync(base(['hardware_id' => $idp, 'pin' => '482915', 'clave' => $kp]));
$pdo->exec("UPDATE dashboard_pos SET gratis_hasta = " . $pdo->quote(dia(1)) . " WHERE hardware_id = " . $pdo->quote($idp));
eq(leer($idp)['codigo'], 401, 'panel con PIN y sin Premium, sin token: 401 (no 403)');
$tk = entrar($idp, '482915')['json']['token'] ?? null;
$g = leer($idp, $tk);
eq($g['codigo'], 403, 'con el PIN correcto: 403 premium_requerido');
eq($g['json']['protegido'] ?? null, true, '…y dice que está protegido');

// Los días gratis cuentan desde la primera vez que se vio la PC: otro panel en la misma PC no regala 15 más
$eqv = equipoNuevo();
sync(base(['hardware_id' => nuevoId(), 'equipo' => $eqv]));
$pdo->exec("UPDATE pruebas_equipo SET primera_vez = NOW() - INTERVAL 20 DAY WHERE equipo = " . $pdo->quote($eqv));
$r = sync(base(['hardware_id' => nuevoId(), 'equipo' => $eqv]));
eq($r['json']['acceso'] ?? null, ['estado' => 'vencido', 'hasta' => dia(5)], 'panel nuevo en una PC vista hace 20 días: sin días gratis (terminaron hace 5)');
$r = sync(base(['hardware_id' => nuevoId(), 'equipo' => 'no-hex']));
eq($r['json']['acceso']['estado'] ?? null, 'prueba', 'un equipo inválido se ignora (no rompe el envío)');

// La clave generada por el POS (Java) se lee igual aquí: tests/fixtures/licencia_java.json
$fx_ruta = '/src/tests/fixtures/licencia_java.json';
if (file_exists($fx_ruta)) {
    $fx = json_decode(file_get_contents($fx_ruta), true);
    $cfg_java = $cfg; $cfg_java['llaves_licencia'] = [(int) $fx['kid'] => $fx['publica']];
    $l = dash_licencia($cfg_java, $fx['licencia']);
    eq($l['vence'] ?? null, $fx['vence'], 'clave emitida en Java: mismo vencimiento');
    eq($l['hw'] ?? null, $fx['hardware_id'], 'clave emitida en Java: mismo Hardware ID');
    eq(dash_licencia($cfg_java, $fx['licencia_con_guiones']) !== null, true, 'clave de Java con guiones: también');
    eq(dash_licencia($cfg_java, $fx['alterada']), null, 'clave de Java alterada: no vale');
} else {
    ok(false, 'falta tests/fixtures/licencia_java.json (lo genera PruebaPremiumPanel del POS)');
}

titulo('10. La prueba gratis de Excel, por PC');
$e = equipoNuevo();
eq(prueba_gratis(['equipo' => $e, 'exportar' => '3', 'importar' => '3'])['json'], ['status' => 'exitoso', 'exportar' => 3, 'importar' => 3], 'PC nueva: 3 y 3');
eq(prueba_gratis(['equipo' => $e, 'exportar' => '1', 'importar' => '3'])['json']['exportar'] ?? null, 1, 'gastó 2: le queda 1');
$r = prueba_gratis(['equipo' => $e, 'exportar' => '3', 'importar' => '3']);
eq([$r['json']['exportar'] ?? null, $r['json']['importar'] ?? null], [1, 3], 'reinstaló (otra vez 3 en local): el servidor dice 1');
eq(prueba_gratis(['equipo' => strtolower($e), 'exportar' => '0', 'importar' => '2'])['json'], ['status' => 'exitoso', 'exportar' => 0, 'importar' => 2], 'en minúsculas es la misma PC; solo baja');
eq(prueba_gratis(['equipo' => $e, 'exportar' => '9', 'importar' => '3'])['json']['exportar'] ?? null, 0, 'un número fuera de rango no devuelve intentos');
eq(prueba_gratis(['equipo' => 'XYZ', 'exportar' => '3', 'importar' => '3'])['codigo'], 400, 'equipo inválido: 400');
eq(prueba_gratis(['equipo' => equipoNuevo(), 'exportar' => '3'])['codigo'], 400, 'falta una cuenta: 400 (no se lee como «0»)');
eq(prueba_gratis(['equipo' => equipoNuevo(), 'exportar' => '-1', 'importar' => '3'])['codigo'], 400, 'cuenta negativa: 400');
eq(http('GET', "$BASE/prueba_gratis.php")['codigo'], 405, 'solo POST');
ok(stripos(prueba_gratis(['equipo' => $e, 'exportar' => '0', 'importar' => '0'])['cabeceras'], 'Access-Control-Allow-Origin') === false,
   'sin CORS: ningún navegador lo llama');
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg, true) . ';');

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
