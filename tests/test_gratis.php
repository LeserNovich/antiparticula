<?php
// Pruebas de /gratis contra una MariaDB real y una Events API de TikTok de imitación (tests/tiktok_falso.php).
// No se corre a mano: lo lanza tests/correr_gratis.sh.
//
//   php test_gratis.php <url_base>
//
// Pega por HTTP a registrar_gratis.php y registrar_descarga.php, revisa la base y lo que le llegó a «TikTok», y
// comprueba que la página, el JavaScript y el PHP digan lo mismo (giros, alfabeto de los códigos, Pixel, rutas).

$BASE = rtrim(isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8080', '/');
$PH = __DIR__ . '/../public_html';

define('GRATIS_COMUN_OK', true);
require $PH . '/gratis_comun.php';   // funciones puras (teléfono, códigos) y tiktok_evento

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

// ── config_db.php de prueba: token, Pixel y código de prueba de mentira; TikTok es el de imitación ──
$cfg['tiktok_token']  = 'token-de-prueba';
$cfg['tiktok_pixel']  = 'PIXELPRUEBA';
$cfg['tiktok_prueba'] = 'TEST00000';
$cfg['tiktok_api']    = 'http://127.0.0.1:8091';
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg, true) . ';');

// ── HTTP y lo que le llegó a TikTok ─────────────────────────────────────────
function http($metodo, $url, $cuerpo = null, $cookies = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 30,
                            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) Prueba']);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($cuerpo));
    if ($cookies) {
        $partes = [];
        foreach ($cookies as $k => $v) $partes[] = $k . '=' . rawurlencode($v);
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $partes));
    }
    $resp = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['codigo' => $codigo, 'json' => json_decode((string) $resp, true), 'cuerpo' => (string) $resp];
}
function registrar(array $campos, $cookies = []) {
    global $BASE;
    return http('POST', "$BASE/registrar_gratis.php", array_merge(['giro' => 'abarrotes', 'acepta' => '1'], $campos), $cookies);
}
function descargar($campos = null, $cookies = []) {
    global $BASE;
    return http('POST', "$BASE/registrar_descarga.php", $campos, $cookies);
}
function eventos() {
    $l = @file('/tmp/tiktok_eventos.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return $l ? array_map(function ($x) { return json_decode($x, true); }, $l) : [];
}
function borrar_eventos() { @unlink('/tmp/tiktok_eventos.jsonl'); }
function fila($tel) {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM registros_gratis WHERE telefono = ?');
    $st->execute([$tel]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function cuantos() { global $pdo; return (int) $pdo->query('SELECT COUNT(*) FROM registros_gratis')->fetchColumn(); }
function descargas() { global $pdo; return (int) $pdo->query('SELECT COUNT(*) FROM registros_descargas')->fetchColumn(); }
function sin_tabla($fn) {
    global $pdo;
    $pdo->exec('RENAME TABLE registros_gratis TO registros_gratis_aparte');
    try { $fn(); } finally { $pdo->exec('RENAME TABLE registros_gratis_aparte TO registros_gratis'); }
}

// ═════════════════════════════════════════════════════════════════════════════
titulo('1. El WhatsApp se guarda en E.164 (gratis_telefono)');
$casos = [
    '5512345678' => '+525512345678',          '55 1234 5678' => '+525512345678',
    '(55) 1234-5678' => '+525512345678',      '55.1234.5678' => '+525512345678',
    '+52 55 1234 5678' => '+525512345678',    '+52 1 55 1234 5678' => '+525512345678',
    '525512345678' => '+525512345678',        '5215512345678' => '+525512345678',
    '044 55 1234 5678' => '+525512345678',    '0052 55 1234 5678' => '+525512345678',
    '33 1234 5678' => '+523312345678',        '+1 415 555 0123' => '+14155550123',
    '+34 612 34 56 78' => '+34612345678',
];
foreach ($casos as $entrada => $esperado) eq(gratis_telefono($entrada), $esperado, "«$entrada» → $esperado");
foreach (['', '12345', '1234567890', '0512345678', '+52 55 1234 567', '+52 55 1234 56789', 'abc', '55 1234 5678 ext 2',
          '+0123456789', '+1234567', '1234567890123456', '+52 0 55 1234 567'] as $malo) {
    eq(gratis_telefono($malo), null, "«$malo» no es un WhatsApp válido");
}

titulo('2. Códigos, event_id, ttclid y URL');
$vistos = [];
for ($i = 0; $i < 300; $i++) $vistos[gratis_codigo_nuevo()] = true;
$todos_validos = true;
foreach (array_keys($vistos) as $c) $todos_validos = $todos_validos && gratis_codigo_valido($c) === $c;
ok($todos_validos, '300 códigos nuevos: todos de 5 caracteres del alfabeto sin letras confusas');
ok(count($vistos) > 290, 'casi sin repetirse (' . count($vistos) . ' distintos de 300)');
eq(gratis_codigo_valido(' k7p3q '), 'K7P3Q', 'el código se acepta en minúsculas y con espacios');
eq(gratis_codigo_valido('K7P0Q'), null, 'un código con 0 (no está en el alfabeto) no es válido');
eq(gratis_codigo_valido('K7P3QQ'), null, 'un código de 6 no es válido');
eq(gratis_event_id('evt-prueba-0001'), 'evt-prueba-0001', 'un event_id con buena forma se respeta');
ok(strpos(gratis_event_id('<script>'), 's-') === 0, 'un event_id raro se reemplaza por uno del servidor');
eq(gratis_ttclid('E.C.P.CtQBabc_123-xyz'), 'E.C.P.CtQBabc_123-xyz', 'un ttclid normal se guarda');
eq(gratis_ttclid('con espacios y <html>'), null, 'un ttclid con espacios o etiquetas no se guarda');
eq(gratis_url('https://antiparticula.com/gratis?ttclid=x'), 'https://antiparticula.com/gratis?ttclid=x', 'URL del sitio: se respeta');
eq(gratis_url('https://otro-sitio.com/gratis'), 'https://antiparticula.com/gratis', 'URL de otro sitio: se cambia por /gratis');
eq(gratis_link('K7P3Q'), 'https://antiparticula.com/d/K7P3Q', 'el link personal es antiparticula.com/d/CODIGO');

titulo('3. registrar_gratis.php: un registro nuevo');
borrar_eventos();
eq(http('GET', "$BASE/registrar_gratis.php")['codigo'], 405, 'GET → 405');
eq(http('GET', "$BASE/gratis_comun.php")['codigo'], 404, 'gratis_comun.php abierto directo → 404');
$r = registrar(['telefono' => '55 1234 5678', 'nombre' => '  Juan <b>Pérez</b> ', 'event_id' => 'evt-registro-0001',
                'ttclid' => 'E.C.P.anuncio-del-celular', 'url' => 'https://antiparticula.com/gratis?ttclid=E.C.P.anuncio-del-celular'],
               ['_ttp' => 'ttp-del-celular-1234']);
eq($r['codigo'], 200, 'registro válido → 200');
$codigo = isset($r['json']['codigo']) ? $r['json']['codigo'] : null;
ok(gratis_codigo_valido($codigo) === $codigo && $codigo !== null, "devuelve un código válido ($codigo)");
eq($r['json']['link'], 'https://antiparticula.com/d/' . $codigo, 'y su link personal');
eq($r['json']['nuevo'], true, 'nuevo = true');
$f = fila('+525512345678');
ok($f !== false, 'quedó en registros_gratis con el WhatsApp en E.164');
eq($f['codigo'], $codigo, 'con el mismo código');
eq($f['nombre'], 'Juan Pérez', 'el nombre sin etiquetas ni espacios de más');
eq($f['giro'], 'abarrotes', 'el giro');
eq($f['ttclid'], 'E.C.P.anuncio-del-celular', 'el ttclid del anuncio');
eq($f['ttp'], 'ttp-del-celular-1234', 'la cookie _ttp del Pixel');
eq($f['ip'], '127.0.0.1', 'la IP');
eq($f['user_agent'], 'Mozilla/5.0 (Linux; Android 14) Prueba', 'el navegador');
eq($f['descargo_en'], null, 'todavía sin descarga');

$ev = eventos();
eq(count($ev), 1, 'TikTok recibió UN evento');
$e = $ev ? $ev[0] : ['token' => null, 'cuerpo' => []];
$c = $e['cuerpo']; $d = isset($c['data'][0]) ? $c['data'][0] : [];
eq($e['token'], 'token-de-prueba', 'con el token de config_db.php en Access-Token');
eq(isset($c['event_source']) ? $c['event_source'] : null, 'web', 'event_source = web');
eq(isset($c['event_source_id']) ? $c['event_source_id'] : null, 'PIXELPRUEBA', 'el Pixel de config_db.php');
eq(isset($c['test_event_code']) ? $c['test_event_code'] : null, 'TEST00000', 'el código de prueba, si config_db.php lo trae');
eq(isset($d['event']) ? $d['event'] : null, 'CompleteRegistration', 'evento CompleteRegistration');
eq(isset($d['event_id']) ? $d['event_id'] : null, 'evt-registro-0001', 'con el event_id del navegador (el mismo del Pixel)');
ok(isset($d['event_time']) && abs($d['event_time'] - time()) < 60, 'event_time = ahora');
eq(isset($d['user']['phone']) ? $d['user']['phone'] : null, hash('sha256', '+525512345678'), 'el WhatsApp cifrado con SHA-256 (en E.164)');
eq(isset($d['user']['external_id']) ? $d['user']['external_id'] : null, hash('sha256', $codigo), 'external_id = el código cifrado');
eq(isset($d['user']['ttclid']) ? $d['user']['ttclid'] : null, 'E.C.P.anuncio-del-celular', 'el ttclid');
eq(isset($d['user']['ttp']) ? $d['user']['ttp'] : null, 'ttp-del-celular-1234', 'la cookie _ttp');
eq(isset($d['user']['ip']) ? $d['user']['ip'] : null, '127.0.0.1', 'la IP');
eq(isset($d['user']['user_agent']) ? $d['user']['user_agent'] : null, 'Mozilla/5.0 (Linux; Android 14) Prueba', 'el navegador');
eq(isset($d['page']['url']) ? $d['page']['url'] : null, 'https://antiparticula.com/gratis?ttclid=E.C.P.anuncio-del-celular', 'la página');
ok(strpos(json_encode($c), '5512345678') === false && strpos(json_encode($c), 'Juan') === false,
   'ni el WhatsApp ni el nombre viajan legibles');

titulo('4. La misma persona otra vez: mismo código, sin contar doble');
borrar_eventos();
$r = registrar(['telefono' => '+52 1 55 1234 5678', 'event_id' => 'evt-registro-0002']);
eq($r['codigo'], 200, 'el mismo WhatsApp escrito de otra forma → 200');
eq($r['json']['codigo'], $codigo, 'recibe el mismo código');
eq($r['json']['nuevo'], false, 'nuevo = false');
eq(cuantos(), 1, 'no se crea otro registro');
eq(count(eventos()), 0, 'y TikTok no recibe otro CompleteRegistration');

titulo('5. Datos que no sirven');
$antes = cuantos();
$r = registrar(['telefono' => '12345']);
eq([$r['codigo'], $r['json']['campo']], [400, 'telefono'], 'WhatsApp de 5 dígitos → 400 campo telefono');
$r = registrar(['telefono' => '5587654321', 'giro' => 'casino']);
eq([$r['codigo'], $r['json']['campo']], [400, 'giro'], 'giro que no está en la lista → 400 campo giro');
$r = http('POST', "$BASE/registrar_gratis.php", ['telefono' => '5587654321', 'giro' => 'farmacia']);
eq([$r['codigo'], $r['json']['campo']], [400, 'acepta'], 'sin aceptar que le escriban → 400 campo acepta');
$r = registrar(['telefono' => '5587654321', 'acepta' => 'on']);
eq([$r['codigo'], $r['json']['campo']], [400, 'acepta'], 'acepta distinto de 1 → 400');
ok(is_string($r['json']['message']) && $r['json']['message'] !== '', 'cada error trae un mensaje para la persona');
$r = registrar(['telefono' => '5587654321', 'sitio' => 'http://spam.example']);
eq([$r['codigo'], $r['json']['codigo']], [200, null], 'el campo escondido lleno (robot) → 200 sin código');
eq(cuantos(), $antes, 'ninguno de esos se guardó');

titulo('6. ttclid de la cookie, event_id y URL que no sirven');
borrar_eventos();
$r = registrar(['telefono' => '5587654321', 'giro' => 'farmacia', 'event_id' => 'x', 'url' => 'https://otro.com/'],
               ['ap_ttclid' => 'E.C.P.de-la-cookie']);
eq($r['json']['nuevo'], true, 'otro WhatsApp → registro nuevo');
eq(fila('+525587654321')['ttclid'], 'E.C.P.de-la-cookie', 'sin ttclid en el formulario, se toma de la cookie ap_ttclid');
$d = eventos()[0]['cuerpo']['data'][0];
ok(strpos($d['event_id'], 's-') === 0, 'event_id que no sirve → uno del servidor');
eq($d['page']['url'], 'https://antiparticula.com/gratis', 'URL de otro sitio → /gratis');
borrar_eventos();
registrar(['telefono' => '5511112222', 'ttclid' => 'tiene espacios <no>']);
eq(fila('+525511112222')['ttclid'], null, 'un ttclid con mala forma no se guarda');
ok(!isset(eventos()[0]['cuerpo']['data'][0]['user']['ttclid']), 'ni se le manda a TikTok');

titulo('7. Si TikTok falla o no hay token, el registro sigue');
borrar_eventos();
touch('/tmp/tiktok_caido');
$r = registrar(['telefono' => '5522223333']);
@unlink('/tmp/tiktok_caido');
eq($r['codigo'], 200, 'TikTok contesta error → el registro igual responde 200');
ok(fila('+525522223333') !== false, 'y queda guardado');
$errores = (string) @file_get_contents('/tmp/php_errores.log');
ok(strpos($errores, 'tiktok: CompleteRegistration no se mandó') !== false, 'el fallo queda en el registro de errores de PHP');
$sin_token = $cfg; unset($sin_token['tiktok_token']);
eq(tiktok_evento($sin_token, 'Download', 'evt-x-0001', []), false, 'sin tiktok_token, tiktok_evento no manda nada');
eq(count(eventos()), 0, 'y no hubo ninguna petición');
eq(tiktok_evento($cfg, 'Download', 'evt-x-0002', ['telefono' => '+525512345678']), true, 'con token y TikTok bien → true');
borrar_eventos();

titulo('8. Sin la tabla (falta la migración)');
sin_tabla(function () {
    $r = registrar(['telefono' => '5533334444']);
    eq($r['codigo'], 503, 'registrar_gratis.php → 503');
    ok(strpos($r['json']['message'], 'WhatsApp') !== false, 'con un mensaje que manda a escribir por WhatsApp');
});

titulo('9. registrar_descarga.php: la descarga desde el link personal');
borrar_eventos();
$n = descargas();
$r = descargar();
eq($r['codigo'], 200, 'sin datos (el JavaScript de antes) → 200 como siempre');
eq(descargas(), $n + 1, 'se cuenta en registros_descargas');
eq(count(eventos()), 0, 'y sin event_id no se le avisa a TikTok (el Pixel viejo no lo llevaría: contaría doble)');

$r = descargar(['event_id' => 'evt-descarga-0001', 'codigo' => $codigo, 'sistema' => 'windows',
                'url' => 'https://antiparticula.com/gratis?c=' . $codigo],
               ['_ttp' => 'ttp-de-la-computadora', 'ap_ttclid' => 'E.C.P.otro-anuncio']);
eq($r['codigo'], 200, 'con event_id y código → 200');
eq(descargas(), $n + 2, 'se cuenta en registros_descargas');
$f = fila('+525512345678');
ok($f['descargo_en'] !== null, 'el registro queda marcado como descargado');
eq($f['sistema'], 'windows', 'con el sistema');
$ev = eventos();
eq(count($ev), 1, 'TikTok recibió UN evento');
$d = $ev ? $ev[0]['cuerpo']['data'][0] : [];
eq($d['event'], 'Download', 'evento Download');
eq($d['event_id'], 'evt-descarga-0001', 'con el event_id del navegador');
eq(isset($d['user']['phone']) ? $d['user']['phone'] : null, hash('sha256', '+525512345678'), 'con el WhatsApp cifrado del registro');
eq(isset($d['user']['external_id']) ? $d['user']['external_id'] : null, hash('sha256', $codigo), 'y su external_id (el mismo del registro)');
eq(isset($d['user']['ttclid']) ? $d['user']['ttclid'] : null, 'E.C.P.anuncio-del-celular', 'el ttclid es el del anuncio que vio en el celular');
eq(isset($d['user']['ttp']) ? $d['user']['ttp'] : null, 'ttp-de-la-computadora', 'la cookie _ttp es la de la computadora');
eq(isset($d['properties']['content_name']) ? $d['properties']['content_name'] : null, 'windows', 'content_name = el sistema');

borrar_eventos();
$primera = $f['descargo_en'];
$pdo->exec("UPDATE registros_gratis SET descargo_en = descargo_en - INTERVAL 1 DAY WHERE telefono = '+525512345678'");
$primera = fila('+525512345678')['descargo_en'];
descargar(['event_id' => 'evt-descarga-0002', 'sistema' => 'macArm'], ['ap_reg' => strtolower($codigo)]);
$f = fila('+525512345678');
eq($f['descargo_en'], $primera, 'otra descarga no cambia la fecha de la primera');
eq($f['sistema'], 'windows', 'ni el sistema de la primera');
$d = eventos()[0]['cuerpo']['data'][0];
eq($d['user']['phone'], hash('sha256', '+525512345678'), 'el código también se toma de la cookie ap_reg (y en minúsculas)');

borrar_eventos();
descargar(['event_id' => 'evt-descarga-0003', 'codigo' => 'KKKKK', 'sistema' => 'macIntel'], ['ap_ttclid' => 'E.C.P.desde-la-compu']);
$d = eventos()[0]['cuerpo']['data'][0];
ok(!isset($d['user']['phone']) && !isset($d['user']['external_id']), 'un código que no existe: el evento sale sin datos de registro');
eq($d['user']['ttclid'], 'E.C.P.desde-la-compu', 'con el ttclid de la cookie de la computadora');
eq($d['properties']['content_name'], 'macIntel', 'y el sistema');

borrar_eventos();
descargar(['event_id' => 'evt-descarga-0004', 'sistema' => 'linux']);
eq(eventos()[0]['cuerpo']['data'][0]['properties']['content_name'], 'instalador', 'un sistema que no existe → «instalador»');

sin_tabla(function () use ($codigo, $n) {
    borrar_eventos();
    $antes = descargas();
    $r = descargar(['event_id' => 'evt-descarga-0005', 'codigo' => $codigo, 'sistema' => 'windows']);
    eq($r['codigo'], 200, 'sin la tabla de /gratis la descarga responde 200');
    eq(descargas(), $antes + 1, 'y se cuenta');
    $ev = eventos();
    eq(count($ev), 1, 'y TikTok recibe el Download igual');
    ok($ev && !isset($ev[0]['cuerpo']['data'][0]['user']['phone']), 'sin los datos del registro');
});

titulo('10. Freno por IP: 10 registros por hora');
$hechos = (int) $pdo->query("SELECT COUNT(*) FROM registros_gratis WHERE ip = '127.0.0.1' AND creado > NOW() - INTERVAL 1 HOUR")->fetchColumn();
$ins = $pdo->prepare("INSERT INTO registros_gratis (codigo, telefono, giro, ip, creado) VALUES (?, ?, 'otro', '127.0.0.1', NOW())");
for ($i = $hechos; $i < GRATIS_MAX_POR_IP_HORA; $i++) $ins->execute(['R' . str_pad($i, 4, '3', STR_PAD_LEFT), '+52990000' . str_pad($i, 4, '0', STR_PAD_LEFT)]);
$r = registrar(['telefono' => '5544445555']);
eq($r['codigo'], 429, 'el registro 11 de la misma IP en una hora → 429');
eq(fila('+525544445555'), false, 'y no se guarda');
$pdo->exec("UPDATE registros_gratis SET creado = NOW() - INTERVAL 2 HOUR");
$r = registrar(['telefono' => '5544445555']);
eq($r['codigo'], 200, 'una hora después → 200');

titulo('11. La página, el JavaScript y el PHP dicen lo mismo');
$html = file_get_contents($PH . '/paginas/gratis.html');
preg_match('#<select[^>]*id="grGiro".*?</select>#s', $html, $m);
preg_match_all('#<option value="([a-z]+)">([^<]+)</option>#', isset($m[0]) ? $m[0] : '', $ops);
eq(array_combine($ops[1], $ops[2]), GRATIS_GIROS, 'los giros del formulario = GRATIS_GIROS (claves y textos)');
$js = file_get_contents($PH . '/js/gratis.js');
ok(strpos($js, '/^[' . GRATIS_ALFABETO . ']{' . GRATIS_LARGO_CODIGO . '}$/') !== false, 'gratis.js valida los códigos con GRATIS_ALFABETO');
foreach (['paginas/gratis.html', 'paginas/puntodeventa.html'] as $p) {
    ok(strpos(file_get_contents("$PH/$p"), "ttq.load('" . GRATIS_PIXEL . "')") !== false, "$p carga el Pixel GRATIS_PIXEL");
}
$ht = file_get_contents($PH . '/.htaccess');
$generica = strpos($ht, 'RewriteRule ^([^/]+)/?$');
foreach (['^gratis/?$', '^d/([A-Za-z0-9]{4,8})/?$', '^privacidad/?$'] as $regla) {
    $pos = strpos($ht, 'RewriteRule ' . $regla);
    ok($pos !== false && $pos < $generica, ".htaccess: $regla antes de la regla genérica de productos");
}
ok(strpos($html, 'href="../paginas/privacidad.html"') !== false, 'el formulario enlaza el aviso de privacidad');
ok(strpos(file_get_contents($PH . '/paginas/privacidad.html'), 'contacto@antiparticula.com') !== false, 'el aviso dice a dónde escribir');

echo "\n" . ($fail === 0 ? "\033[32m" : "\033[31m") . "$pass correctas, $fail fallidas\033[0m\n";
exit($fail === 0 ? 0 : 1);
