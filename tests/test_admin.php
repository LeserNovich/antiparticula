<?php
// Pruebas de la página privada de datos (/admin/) contra PHP y MariaDB REALES (tests/correr_admin.sh).
//   php tests/test_admin.php <url base> <config_db.php> <hechos.json>
// Cada cifra de la página se compara con una consulta escrita aparte, aquí: si la página suma mal, no coincide.
date_default_timezone_set('America/Mexico_City');
$BASE = rtrim($argv[1], '/');
$cfg = require $argv[2];
$hechos = json_decode(file_get_contents($argv[3]), true);
$db = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("SET time_zone = '-06:00'");
const CLAVE = 'clave de prueba 2026';

$ok = 0; $mal = 0;
function prueba($nombre, $cond, $detalle = '') {
    global $ok, $mal;
    if ($cond) { $ok++; return; }
    $mal++;
    echo "  FALLA: $nombre" . ($detalle !== '' ? " — $detalle" : '') . "\n";
}
function seccion($t) { echo "── $t\n"; }

function http($metodo, $url, $datos = null, array $cabeceras = [], $cookie = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_FOLLOWLOCATION => false]);
    if ($datos !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos));
    if ($cookie !== null) $cabeceras[] = 'Cookie: ap_admin=' . $cookie;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);
    $r = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tam = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $cab = substr($r, 0, $tam);
    return ['codigo' => $codigo, 'cab' => $cab, 'cuerpo' => substr($r, $tam), 'json' => json_decode(substr($r, $tam), true)];
}
function cabecera($r, $nombre) {
    return preg_match_all('/^' . preg_quote($nombre, '/') . ':\s*(.*)$/mi', $r['cab'], $m) ? array_map('trim', $m[1]) : [];
}
function uno($sql, array $p = []) { global $db; $st = $db->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
function api($q, $cookie) { global $BASE; return http('GET', "$BASE/admin/api.php?$q", null, [], $cookie); }

$hoy = date('Y-m-d');
$desde30 = date('Y-m-d', strtotime('-29 days'));
$USO_OK = "hardware_id IS NOT NULL AND hardware_id NOT IN ('', 'UNKNOWN')";

seccion('Sin sesión');
$r = http('GET', "$BASE/admin/");
prueba('la entrada responde 200', $r['codigo'] === 200, (string) $r['codigo']);
prueba('pide la clave', strpos($r['cuerpo'], 'name="clave"') !== false);
prueba('no carga la página sin sesión', strpos($r['cuerpo'], 'admin.js') === false);
prueba('CSP sin scripts en línea', strpos(implode(' ', cabecera($r, 'Content-Security-Policy')), "script-src 'self'") !== false);
prueba('no se deja enmarcar', in_array('DENY', cabecera($r, 'X-Frame-Options'), true));
prueba('no se guarda en caché', in_array('no-store', cabecera($r, 'Cache-Control'), true));
prueba('no se indexa', strpos(implode(' ', cabecera($r, 'X-Robots-Tag')), 'noindex') !== false);
$r = http('GET', "$BASE/admin/api.php?s=resumen");
prueba('la API sin sesión da 401', $r['codigo'] === 401, (string) $r['codigo']);
prueba('la API sin sesión no da ni un dato', $r['json'] === ['status' => 'sin_sesion'], $r['cuerpo']);
$r = http('GET', "$BASE/admin_comun.php");
prueba('admin_comun.php abierto directo da 404', $r['codigo'] === 404, (string) $r['codigo']);

seccion('Entrar');
$r = http('POST', "$BASE/admin/", ['clave' => 'otra cosa']);
prueba('clave incorrecta: lo dice', strpos($r['cuerpo'], 'Esa no es la clave') !== false);
prueba('clave incorrecta: sin cookie', !cabecera($r, 'Set-Cookie'));
prueba('clave incorrecta: se anota', (int) uno('SELECT SUM(fallos) FROM admin_intentos') === 1);
$r = http('POST', "$BASE/admin/", ['clave' => CLAVE]);
prueba('clave correcta: 303 a la página', $r['codigo'] === 303 && cabecera($r, 'Location') === ['/admin/'], $r['codigo'] . ' ' . implode(',', cabecera($r, 'Location')));
$sc = implode(' ', cabecera($r, 'Set-Cookie'));
prueba('cookie HttpOnly', stripos($sc, 'HttpOnly') !== false, $sc);
prueba('cookie SameSite=Strict', stripos($sc, 'SameSite=Strict') !== false, $sc);
prueba('cookie solo para /admin/', stripos($sc, 'Path=/admin/') !== false, $sc);
prueba('un acierto limpia los fallos de esa IP', (int) uno('SELECT COUNT(*) FROM admin_intentos') === 0);
preg_match('/ap_admin=([^;]+)/', $sc, $m);
$COOKIE = isset($m[1]) ? $m[1] : '';
$r = http('GET', "$BASE/admin/", null, [], $COOKIE);
prueba('con sesión carga la página', strpos($r['cuerpo'], 'admin.js') !== false && strpos($r['cuerpo'], 'name="clave"') === false);

$partes = explode('.', $COOKIE);
$falsa = $partes[0] . '.' . strrev($partes[1]);
prueba('una firma alterada no entra', api('s=resumen', $falsa)['codigo'] === 401);
$payload = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'exp' => time() + 999999999])), '+/', '-_'), '=');
prueba('un vencimiento alterado no entra', api('s=resumen', $payload . '.' . $partes[1])['codigo'] === 401);
define('ADMIN_COMUN_OK', true);
require '/app/public_html/admin_comun.php';
$vieja = admin_token_emitir($cfg, time() - (ADMIN_SESION_DIAS * 86400 + 60));
prueba('una sesión vencida no entra', api('s=resumen', $vieja)['codigo'] === 401);
$otraClave = $cfg; $otraClave['admin_clave_hash'] = password_hash('otra clave cualquiera', PASSWORD_BCRYPT, ['cost' => 4]);
prueba('cambiar la clave cierra las sesiones de antes', api('s=resumen', admin_token_emitir($otraClave))['codigo'] === 401);

seccion('Resumen');
$r = api('s=resumen&p=30', $COOKIE);
$d = $r['json'];
prueba('200 ok', $r['codigo'] === 200 && $d['status'] === 'ok', $r['cuerpo']);
prueba('nada falta con la base migrada', $d['faltan'] === [], json_encode($d['faltan']));
$desc30 = (int) uno('SELECT COUNT(*) FROM registros_descargas WHERE fecha_hora >= ?', [$desde30]);
prueba('descargas de 30 días', $d['descargas']['actual'] === $desc30, $d['descargas']['actual'] . " vs $desc30");
prueba('la serie suma lo mismo', array_sum($d['descargas']['serie']) === $desc30);
prueba('30 claves por día', count($d['periodo']['claves']) === 30 && $d['periodo']['gran'] === 'dia');
$desc_antes = (int) uno('SELECT COUNT(*) FROM registros_descargas WHERE fecha_hora >= ? AND fecha_hora < ?', [date('Y-m-d', strtotime('-59 days')), $desde30]);
prueba('descargas del periodo anterior', $d['descargas']['anterior'] === $desc_antes, $d['descargas']['anterior'] . " vs $desc_antes");
$act30 = (int) uno("SELECT COUNT(DISTINCT hardware_id) FROM uso_punto_venta WHERE $USO_OK AND fecha_hora >= ?", [$desde30]);
prueba('computadoras activas en 30 días', $d['equipos']['activas'] === $act30, $d['equipos']['activas'] . " vs $act30");
prueba('activas «hoy» y «30 días» cuadran', $d['equipos']['mes'] === $act30);
$total = (int) uno("SELECT COUNT(DISTINCT hardware_id) FROM uso_punto_venta WHERE $USO_OK");
prueba('total de computadoras sin UNKNOWN ni vacío', $d['equipos']['total'] === $total && $total === 41, $d['equipos']['total'] . " vs $total");
$nuevas = (int) uno("SELECT COUNT(*) FROM (SELECT MIN(fecha_hora) f FROM uso_punto_venta WHERE $USO_OK GROUP BY hardware_id) t WHERE f >= ?", [$desde30]);
prueba('computadoras nuevas', $d['equipos']['nuevas'] === $nuevas, $d['equipos']['nuevas'] . " vs $nuevas");
prueba('la serie de nuevas suma lo mismo', array_sum($d['equipos']['serie_nuevas']) === $nuevas);
prueba('el camino empieza con las nuevas', $d['embudo']['nuevas'] === $nuevas && $d['embudo']['volvieron'] <= $nuevas && $d['embudo']['siguen'] <= $nuevas);
prueba('12 tiendas con panel', $d['tiendas']['total'] === 12, json_encode($d['tiendas']));
$ingresos30 = 0;
foreach ($db->query("SELECT p.creado, p.stripe_ref, c.stripe_sesion, c.monto, c.plan FROM tienda_pagos p JOIN tienda_compras c ON c.id = p.compra_id
                     WHERE c.stripe_sesion LIKE 'cs\\_live\\_%'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
    if ($p['creado'] < $desde30) continue;
    $ingresos30 += $p['stripe_ref'] === $p['stripe_sesion'] ? (int) $p['monto'] : ['premium_mensual' => 14900, 'multi_mensual' => 29900][$p['plan']];
}
prueba('ingresos de 30 días sin compras de prueba', $d['ventas']['actual']['ingresos'] === $ingresos30, $d['ventas']['actual']['ingresos'] . " vs $ingresos30");
prueba('una ficha OXXO pendiente', $d['pendientes']['oxxo'] === 1, json_encode($d['pendientes']));
prueba('el último aviso de Stripe', $d['pendientes']['stripe'] !== null);
foreach (['7' => [7, 'dia'], '90' => [90, 'dia'], '365' => [53, 'semana']] as $p => $esp) {
    $x = api("s=resumen&p=$p", $COOKIE)['json'];
    prueba("periodo $p: agrupación", $x['periodo']['gran'] === $esp[1], $x['periodo']['gran']);
    prueba("periodo $p: claves", count($x['periodo']['claves']) >= $esp[0] && count($x['periodo']['claves']) <= $esp[0] + 1, (string) count($x['periodo']['claves']));
}
$x = api('s=resumen&p=todo', $COOKIE)['json'];
prueba('todo: sin periodo anterior', $x['periodo']['antes_desde'] === null && $x['descargas']['anterior'] === null);
prueba('todo: desde la descarga más vieja', $x['periodo']['desde'] === substr(uno('SELECT MIN(fecha_hora) FROM registros_descargas'), 0, 10), $x['periodo']['desde']);
prueba('todo: suma todas las descargas', $x['descargas']['actual'] === (int) uno('SELECT COUNT(*) FROM registros_descargas'));
prueba('un periodo raro cae a «todo»', api('s=resumen&p=abc', $COOKIE)['json']['periodo']['clave'] === 'todo');

seccion('Descargas');
$d = api('s=descargas&p=90', $COOKIE)['json'];
$d90 = date('Y-m-d', strtotime('-89 days'));
$n90 = (int) uno('SELECT COUNT(*) FROM registros_descargas WHERE fecha_hora >= ?', [$d90]);
prueba('descargas de 90 días', $d['datos']['actual'] === $n90, $d['datos']['actual'] . " vs $n90");
prueba('por país suma lo mismo', array_sum(array_map('intval', array_column($d['datos']['paises'], 'n'))) === $n90);
prueba('por hora suma lo mismo', array_sum($d['datos']['horas']) === $n90 && count($d['datos']['horas']) === 24);
prueba('por día de la semana suma lo mismo', array_sum($d['datos']['semana']) === $n90 && count($d['datos']['semana']) === 7);
prueba('IPs distintas', $d['datos']['ips'] === (int) uno('SELECT COUNT(DISTINCT ip_address) FROM registros_descargas WHERE fecha_hora >= ?', [$d90]));
prueba('las 40 más recientes', count($d['datos']['ultimas']) === 40);

seccion('Computadoras');
$d = api('s=computadoras&p=30', $COOKIE)['json'];
prueba('nada falta', $d['faltan'] === [], json_encode($d['faltan']));
prueba('las 41 en la lista', count($d['lista']) === 41, (string) count($d['lista']));
$ids = array_column($d['lista'], 'id');
prueba('sin UNKNOWN ni vacío', !in_array('UNKNOWN', $ids, true) && !in_array('', $ids, true));
$fila = null;
foreach ($d['lista'] as $f) if ($f['id'] === $hechos['tiendas'][0]) $fila = $f;
$dias = (int) uno('SELECT COUNT(DISTINCT DATE(fecha_hora)) FROM uso_punto_venta WHERE hardware_id = ?', [$hechos['tiendas'][0]]);
prueba('días de uso de una computadora', $fila && $fila['dias'] === $dias, ($fila ? $fila['dias'] : 'sin fila') . " vs $dias");
prueba('la que tiene panel lo dice', $fila && $fila['panel'] === true && $fila['premium'] !== null, json_encode($fila));
prueba('el negocio más reciente', $fila && $fila['negocio'] === 'Abarrotes La Esperanza', $fila ? (string) $fila['negocio'] : '');
prueba('ciudad y país separados', $fila && $fila['ciudad'] !== '' && strpos($fila['ciudad'], chr(31)) === false);
prueba('activas igual que el resumen', $d['uso']['activas'] === $act30);
$logins = (int) uno("SELECT COUNT(*) FROM uso_punto_venta WHERE $USO_OK AND tipo_evento = 'login' AND fecha_hora >= ?", [$desde30]);
$mapa = 0; foreach ($d['uso']['horas'] as $r7) $mapa += array_sum($r7);
prueba('el mapa de calor suma los inicios', $mapa === $logins, "$mapa vs $logins");
$coh = $d['cohortes'];
prueba('cohortes: el mes 0 es el total', $coh && array_reduce($coh, function ($a, $c) { return $a && $c['despues'][0] === $c['total']; }, true));
prueba('cohortes: suman todas las computadoras de 12 meses', array_sum(array_column($coh, 'total')) <= 41 && array_sum(array_column($coh, 'total')) > 30);
// En qué versión están: la del último evento de cada computadora activa en el periodo, contada aparte
$esperado = [];
foreach ($db->query("SELECT COALESCE(u.version_pos, '') AS v, COUNT(DISTINCT u.hardware_id) AS n FROM uso_punto_venta u
                     JOIN (SELECT hardware_id, MAX(fecha_hora) AS m FROM uso_punto_venta WHERE $USO_OK AND fecha_hora >= " . $db->quote($desde30) . "
                           GROUP BY hardware_id) t ON t.hardware_id = u.hardware_id AND t.m = u.fecha_hora
                     GROUP BY v")->fetchAll() as $f) $esperado[$f['v']] = (int) $f['n'];
$V = $d['versiones'];
$obtenido = [];
foreach ($V['filas'] as $f) $obtenido[$f['version']] = $f['n'];
ksort($esperado); ksort($obtenido);
prueba('versiones: cada una cuenta lo mismo que la consulta', $obtenido === $esperado, json_encode($obtenido) . ' vs ' . json_encode($esperado));
prueba('versiones: suman las activas', array_sum($obtenido) === $act30, array_sum($obtenido) . " vs $act30");
prueba('versiones: hay de las tres (3.10.0, 3.7.0 y sin versión)', isset($obtenido['3.10.0'], $obtenido['3.7.0'], $obtenido['']), json_encode($obtenido));
prueba('versiones: la 3.10.0 antes que la 3.7.0 y las sin versión al final',
       array_column($V['filas'], 'version') === array_values(array_filter(['3.10.0', '3.7.0', ''], function ($v) use ($obtenido) { return isset($obtenido[$v]); })),
       json_encode(array_column($V['filas'], 'version')));
$publicada = json_decode(http('GET', "$BASE/version.json")['cuerpo'], true)['version'];
prueba('versiones: dice cuál es la publicada (la de version.json)', $V['publicada'] === $publicada, $V['publicada'] . " vs $publicada");
$ultimaVer = function ($id) { return (string) uno("SELECT COALESCE(version_pos, '') FROM uso_punto_venta WHERE hardware_id = ? ORDER BY fecha_hora DESC LIMIT 1", [$id]); };
$bien = true;
foreach ($d['lista'] as $f) $bien = $bien && $f['version'] === $ultimaVer($f['id']);
prueba('la lista trae la versión de cada computadora (la de su último evento)', $bien);

seccion('Tiendas');
$d = api('s=tiendas&p=30', $COOKIE)['json'];
prueba('nada falta', $d['faltan'] === [], json_encode($d['faltan']));
prueba('12 tiendas', count($d['lista']) === 12);
prueba('una en euros', $d['euros'] === 1);
$ventas = (int) uno('SELECT SUM(ventas_centavos) FROM dashboard_pos_historico WHERE fecha >= ? AND hardware_id <> ?', [$desde30, $hechos['euro']]);
prueba('las ventas no suman la tienda en euros', $d['total']['actual']['ventas'] === $ventas, $d['total']['actual']['ventas'] . " vs $ventas");
prueba('la serie de ventas suma lo mismo', array_sum($d['total']['serie_ventas']) === $ventas);
$ef = (int) uno('SELECT SUM(efectivo_centavos) FROM dashboard_pos_historico WHERE fecha >= ? AND hardware_id <> ?', [$desde30, $hechos['euro']]);
prueba('efectivo', $d['pagos']['efectivo'] === $ef, $d['pagos']['efectivo'] . " vs $ef");
prueba('la tienda en euros se ve en euros', array_values(array_filter($d['lista'], function ($t) use ($hechos) { return $t['id'] === $hechos['euro']; }))[0]['moneda'] === 'EUR');
prueba('ventas por hora', array_sum($d['horas']['tickets']) === (int) uno('SELECT COUNT(*) FROM dashboard_pos_ventas WHERE fecha >= ? AND hardware_id <> ?', [$desde30, $hechos['euro']]));
prueba('funciones: PIN en 4 tiendas', $d['funciones']['pin'] === 4, json_encode($d['funciones']));
prueba('funciones: meta en 1', $d['funciones']['meta'] === 1);
prueba('ni un hash sale de la API', strpos(json_encode($d), 'pin_hash') === false && strpos(json_encode($d), '$2y$') === false);

seccion('Premium');
$d = api('s=premium&p=todo', $COOKIE)['json'];
prueba('nada falta', $d['faltan'] === [], json_encode($d['faltan']));
prueba('ingresos desde siempre = lo cobrado de verdad', $d['ventas']['total']['ingresos'] === $hechos['ingresos_reales'], $d['ventas']['total']['ingresos'] . ' vs ' . $hechos['ingresos_reales']);
prueba('las 10 compras', count($d['compras']) === 10);
prueba('2 compras de prueba marcadas', count(array_filter($d['compras'], function ($c) { return $c['prueba']; })) === 2);
prueba('el código con guiones', preg_match('/^AP-\d{4}-\d{4}-\d{4}$/', $d['compras'][0]['codigo']) === 1, $d['compras'][0]['codigo']);
$manual = array_filter($d['licencias'], function ($l) { return strpos((string) $l['lic'], 'MANUAL') === 0; });
prueba('las claves a mano cuentan', count($manual) === 3);
prueba('las claves de prueba de Stripe no dan Premium', !in_array('cs_test', array_column($d['licencias'], 'fuente'), true));
$t1 = array_values(array_filter($d['licencias'], function ($l) use ($hechos) { return $l['equipo'] === $hechos['tiendas'][1] && $l['tipo'] === 'premium'; }));
prueba('la mensual se marca mensual', $t1 && $t1[0]['mensual'] === true, json_encode($t1));
prueba('para siempre llega a 2199', count(array_filter($d['licencias'], function ($l) { return $l['vence'] === '2199-06-06'; })) === 1);
// Solo las tiendas 3 y 5 tienen una clave que nada más vio el panel; las demás compraron en la tienda
prueba('el panel no duplica las claves de la tienda', count(array_filter($d['licencias'], function ($l) { return $l['fuente'] === 'panel'; })) === 2,
       json_encode(array_values(array_filter($d['licencias'], function ($l) { return $l['fuente'] === 'panel'; }))));
$t3 = array_values(array_filter($d['licencias'], function ($l) use ($hechos) { return $l['equipo'] === $hechos['tiendas'][3]; }));
prueba('una clave que solo vio el panel sí cuenta', $t3 && $t3[0]['fuente'] === 'panel', json_encode($t3));
prueba('prueba de Excel: 20 equipos', $d['prueba']['equipos'] === 20);
prueba('prueba de Excel: la que la agotó y compró', $d['prueba']['agotaron_compraron'] >= 1, json_encode($d['prueba']));
prueba('el canje fallido', $d['canjes_fallidos'] === 4);
prueba('ninguna clave entera en la sección', strpos(json_encode($d), str_repeat('A', 125)) === false);

seccion('Comentarios');
$d = api('s=comentarios&p=30', $COOKIE)['json'];
prueba('los 21', count($d['lista']) === 21);
prueba('el texto con < > & llega tal cual (lo escapa la página)', in_array('<img src=x onerror=alert(1)> & "comillas"', array_column($d['lista'], 'comentario'), true));
prueba('los del programa no tienen nombre', count(array_filter($d['lista'], function ($c) { return $c['nombre'] === null; })) === 15);
prueba('cada mensaje trae su teléfono (o nulo)', count(array_filter($d['lista'], function ($c) { return array_key_exists('telefono', $c); })) === 21);
prueba('el que dejó teléfono lo trae tal cual', in_array('5512345678', array_column($d['lista'], 'telefono'), true));

seccion('Registros de /gratis');
$d = api('s=registros&p=30', $COOKIE)['json'];
$manana = date('Y-m-d', strtotime('+1 day'));
$W30 = "creado >= '$desde30' AND creado < '$manana'";
$desde60 = date('Y-m-d', strtotime('-59 days'));
prueba('nada falta', $d['faltan'] === [], json_encode($d['faltan']));
prueba('con seguimiento', $d['seguimiento'] === true);
prueba('registros del periodo', $d['datos']['actual'] === (int) uno("SELECT COUNT(*) FROM registros_gratis WHERE $W30"), json_encode($d['datos']['actual']));
prueba('los del periodo anterior', $d['datos']['anterior'] === (int) uno('SELECT COUNT(*) FROM registros_gratis WHERE creado >= ? AND creado < ?', [$desde60, $desde30]));
prueba('cuántos descargaron', $d['datos']['descargaron'] === (int) uno("SELECT COUNT(*) FROM registros_gratis WHERE $W30 AND descargo_en IS NOT NULL"));
prueba('cuántos llegaron de un anuncio', $d['datos']['de_anuncio'] === (int) uno("SELECT COUNT(*) FROM registros_gratis WHERE $W30 AND ttclid IS NOT NULL"));
prueba('los de siempre', $d['datos']['total'] === 30);
prueba('la gráfica suma lo del periodo', count($d['datos']['serie']) === 30 && array_sum($d['datos']['serie']) === $d['datos']['actual']
       && array_sum($d['datos']['serie_descargaron']) === $d['datos']['descargaron']);
$giros = array_map(function ($g) { return [$g['giro'], (int) $g['n'], (int) $g['descargaron']]; },
                   $db->query("SELECT giro, COUNT(*) AS n, COUNT(descargo_en) AS descargaron FROM registros_gratis WHERE $W30 GROUP BY giro ORDER BY n DESC, giro")->fetchAll(PDO::FETCH_ASSOC));
$girosApi = array_map(function ($g) { return [$g['giro'], (int) $g['n'], (int) $g['descargaron']]; }, $d['datos']['giros']);
prueba('por giro', $girosApi === $giros, json_encode($girosApi) . ' vs ' . json_encode($giros));
$tramos = array_map('intval', $db->query("SELECT SUM(m < 60), SUM(m >= 60 AND m < 1440), SUM(m >= 1440 AND m < 4320), SUM(m >= 4320) FROM
                                          (SELECT TIMESTAMPDIFF(MINUTE, creado, descargo_en) AS m FROM registros_gratis WHERE $W30 AND descargo_en IS NOT NULL) t")->fetch(PDO::FETCH_NUM));
prueba('cuánto tardan en descargar', $d['datos']['tardanza'] === $tramos, json_encode($d['datos']['tardanza']) . ' vs ' . json_encode($tramos));
prueba('los nombres de los giros', $d['giros']['ferreteria'] === 'Ferretería o tlapalería');
prueba('la lista: todos, el más nuevo primero', count($d['lista']) === 30 && $d['lista'][0]['creado'] >= $d['lista'][29]['creado']);
prueba('cada uno con su link personal', count(array_filter($d['lista'], function ($x) { return $x['link'] === 'https://antiparticula.com/d/' . $x['codigo']; })) === 30);
prueba('«de un anuncio» es sí o no', count(array_filter($d['lista'], function ($x) { return is_bool($x['de_anuncio']); })) === 30);
$pend = (int) uno('SELECT COUNT(*) FROM registros_gratis WHERE contactado_en IS NULL AND baja_en IS NULL');
prueba('el resumen dice a cuántos falta escribirles', api('s=resumen&p=30', $COOKIE)['json']['pendientes']['registros'] === $pend);

$rp = $hechos['registro_pendiente']; $rb = $hechos['registro_baja'];
$seg = function ($accion, $id) use ($BASE, $COOKIE) { return http('POST', "$BASE/admin/api.php", ['accion' => $accion, 'id' => $id], ['X-Admin: 1'], $COOKIE); };
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'escrito', 'id' => $rp], [], $COOKIE);
prueba('seguimiento sin la cabecera X-Admin se rechaza', $r['codigo'] === 403);
$r = $seg('escrito', $rp);
prueba('anotar que le escribiste', $r['codigo'] === 200 && uno('SELECT contactado_en FROM registros_gratis WHERE id = ?', [$rp]) !== null, $r['cuerpo']);
prueba('y ya no cuenta como pendiente', api('s=resumen&p=30', $COOKIE)['json']['pendientes']['registros'] === $pend - 1);
$r = $seg('escrito', $rb);
prueba('a quien pidió BAJA no se le anota: 409', $r['codigo'] === 409 && uno('SELECT contactado_en FROM registros_gratis WHERE id = ?', [$rb]) === null, $r['cuerpo']);
$r = $seg('baja', $rp);
prueba('marcar BAJA', $r['codigo'] === 200 && uno('SELECT baja_en FROM registros_gratis WHERE id = ?', [$rp]) !== null);
$r = $seg('quitar_baja', $rp);
prueba('quitar la BAJA', $r['codigo'] === 200 && uno('SELECT baja_en FROM registros_gratis WHERE id = ?', [$rp]) === null);
$r = $seg('borrar_registro', $rp);
prueba('borrar sus datos', $r['codigo'] === 200 && (int) uno('SELECT COUNT(*) FROM registros_gratis WHERE id = ?', [$rp]) === 0
       && (int) uno('SELECT COUNT(*) FROM registros_gratis') === 29);
prueba('borrarlo otra vez: 404', $seg('borrar_registro', $rp)['codigo'] === 404);
prueba('un ID que no es número: 400', $seg('escrito', 'abc')['codigo'] === 400 && $seg('baja', '1 OR 1=1')['codigo'] === 400);

seccion('Una computadora');
$id = $hechos['tiendas'][0];
$lic = $hechos['licencias'][$id];
$d = api('s=equipo&id=' . $id, $COOKIE)['json'];
prueba('nada falta', $d['faltan'] === [], json_encode($d['faltan']));
prueba('se une con su licencia', $d['lic'] === $lic);
prueba('su telemetría', (int) $d['uso']['resumen']['dias'] === $dias);
prueba('su panel', $d['panel']['negocio'] === 'Abarrotes La Esperanza' && $d['panel']['protegido'] === true);
prueba('sin los secretos del panel', !array_key_exists('pin_hash', $d['panel']) && !array_key_exists('clave_hash', $d['panel']) && strpos(json_encode($d), '$2y$') === false);
prueba('su historial y sus meses', count($d['historico']) > 50 && count($d['meses']) >= 6);
prueba('sus compras y claves', count($d['compras']) === 1 && count($d['claves']) === 1 && strlen($d['claves'][0]['clave']) === 125);
prueba('su prueba de Excel', $d['prueba'] && (int) $d['prueba']['exportar_usados'] === 3);
prueba('su versión', $d['versiones']['actual'] === $ultimaVer($id), json_encode($d['versiones']));
$hist = array_column($d['versiones']['historial'], 'version');
$histDb = $db->query('SELECT version_pos FROM uso_punto_venta WHERE hardware_id = ' . $db->quote($id) . ' AND version_pos IS NOT NULL
                      GROUP BY version_pos ORDER BY MAX(fecha_hora) DESC')->fetchAll(PDO::FETCH_COLUMN);
prueba('las versiones que ha tenido, de la más reciente a la más vieja', $hist === $histDb, json_encode($hist) . ' vs ' . json_encode($histDb));
$d2 = api('s=equipo&lic=' . $lic, $COOKIE)['json'];
prueba('por la licencia se llega al mismo equipo', $d2['id'] === $id);
$d3 = api('s=equipo&lic=MANUAL0000000001', $COOKIE)['json'];
prueba('una licencia sin panel: solo lo de la licencia', $d3['id'] === null && !isset($d3['uso']) && $d3['reportadas'] && count($d3['reportadas']) === 1);
prueba('un ID raro da 400', api('s=equipo&id=' . urlencode('../x'), $COOKIE)['codigo'] === 400);
prueba('sin ID da 400', api('s=equipo', $COOKIE)['codigo'] === 400);
prueba('una sección que no existe da 404', api('s=nada', $COOKIE)['codigo'] === 404);

seccion('Apartar un equipo');
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'ocultar', 'id' => $hechos['prueba']], [], $COOKIE);
prueba('sin la cabecera X-Admin se rechaza', $r['codigo'] === 403, (string) $r['codigo']);
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'ocultar', 'id' => $hechos['prueba'], 'nota' => '<b>mía</b>'], ['X-Admin: 1']);
prueba('sin sesión se rechaza', $r['codigo'] === 401, (string) $r['codigo']);
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'ocultar', 'id' => $hechos['prueba'], 'nota' => '<b>mía</b>'], ['X-Admin: 1'], $COOKIE);
prueba('se aparta', $r['codigo'] === 200 && uno('SELECT nota FROM admin_ocultos WHERE hardware_id = ?', [$hechos['prueba']]) === 'mía', $r['cuerpo']);
$d = api('s=resumen&p=30', $COOKIE)['json'];
prueba('ya no cuenta en las activas', $d['equipos']['activas'] === $act30 - 1, $d['equipos']['activas'] . ' vs ' . ($act30 - 1));
prueba('ni en el total', $d['equipos']['total'] === 40);
prueba('el resumen lo dice', $d['ocultos'] === 1);
$d = api('s=computadoras&p=30', $COOKIE)['json'];
$f = array_values(array_filter($d['lista'], function ($x) use ($hechos) { return $x['id'] === $hechos['prueba']; }));
prueba('la lista lo sigue mostrando, marcado', $f && $f[0]['oculto'] === true);
prueba('el mapa de calor ya no lo cuenta', array_sum(array_map('array_sum', $d['uso']['horas'])) === $logins - (int) uno("SELECT COUNT(*) FROM uso_punto_venta WHERE hardware_id = ? AND tipo_evento = 'login' AND fecha_hora >= ?", [$hechos['prueba'], $desde30]));
$d = api('s=equipo&id=' . $hechos['prueba'], $COOKIE)['json'];
prueba('su ficha dice que está apartado', is_array($d['oculto']) && $d['oculto']['nota'] === 'mía');
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'contar', 'id' => $hechos['prueba']], ['X-Admin: 1'], $COOKIE);
prueba('se vuelve a contar', $r['codigo'] === 200 && api('s=resumen&p=30', $COOKIE)['json']['equipos']['activas'] === $act30);
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'borrar', 'id' => 'X'], ['X-Admin: 1'], $COOKIE);
prueba('una acción desconocida da 400', $r['codigo'] === 400);
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'ocultar', 'id' => "x' OR 1=1 -- "], ['X-Admin: 1'], $COOKIE);
prueba('un ID con comillas da 400', $r['codigo'] === 400);

seccion('La telemetría guarda la versión');
// Con salida_app y en la computadora de pruebas: no cambia los inicios de sesión ni las activas que se revisan después
$antes = (int) uno('SELECT MAX(id) FROM uso_punto_venta');
$r = http('POST', "$BASE/registrar_uso.php", ['tipo_evento' => 'salida_app', 'usuario' => 'TestAdministrador', 'rol' => 'administrador',
                                              'hardware_id' => $hechos['prueba'], 'version' => '3.7.0']);
prueba('un evento con versión se guarda', $r['codigo'] === 201 && uno('SELECT version_pos FROM uso_punto_venta WHERE id = ?', [$r['json']['id']]) === '3.7.0', $r['cuerpo']);
$r = http('POST', "$BASE/registrar_uso.php", ['tipo_evento' => 'salida_app', 'usuario' => 'TestAdministrador', 'rol' => 'administrador',
                                              'hardware_id' => $hechos['prueba'], 'version' => "3.7'; DROP TABLE x; --"]);
prueba('una versión ilegible no tumba el evento: se guarda sin ella', $r['codigo'] === 201 && uno('SELECT version_pos FROM uso_punto_venta WHERE id = ?', [$r['json']['id']]) === null, $r['cuerpo']);
$r = http('POST', "$BASE/registrar_uso.php", ['tipo_evento' => 'salida_app', 'usuario' => 'TestAdministrador', 'rol' => 'administrador',
                                              'hardware_id' => $hechos['prueba']]);
prueba('un POS de hasta 3.6.0 (sin versión) se guarda igual', $r['codigo'] === 201 && uno('SELECT version_pos FROM uso_punto_venta WHERE id = ?', [$r['json']['id']]) === null, $r['cuerpo']);

seccion('Tablas que faltan');
// El PHP subido antes que la migración: la telemetría no se pierde y la página no enseña versiones
$db->exec('ALTER TABLE uso_punto_venta DROP COLUMN version_pos');
$r = http('POST', "$BASE/registrar_uso.php", ['tipo_evento' => 'salida_app', 'usuario' => 'TestAdministrador', 'rol' => 'administrador',
                                              'hardware_id' => $hechos['prueba'], 'version' => '3.7.0']);
prueba('sin la columna version_pos el evento se guarda igual', $r['codigo'] === 201 && (int) uno('SELECT MAX(id) FROM uso_punto_venta') > $antes + 3, $r['cuerpo']);
$d = api('s=computadoras&p=30', $COOKIE)['json'];
prueba('sin la columna: computadoras dice qué faltó y no inventa versiones',
       in_array('uso_punto_venta.version_pos', $d['faltan'], true) && $d['versiones'] === null && $d['lista'] && $d['lista'][0]['version'] === null,
       json_encode($d['faltan']));
prueba('sin la columna: la ficha sigue', api('s=equipo&id=' . $hechos['tiendas'][0], $COOKIE)['json']['versiones'] === null);
// La tabla de /gratis creada con la primera versión de su migración (sin el seguimiento), y luego sin la tabla
$db->exec('ALTER TABLE registros_gratis DROP COLUMN baja_en');
$d = api('s=registros&p=30', $COOKIE)['json'];
prueba('sin las columnas del seguimiento: la lista sale, sin botones', $d['seguimiento'] === false && count($d['lista']) === 29 && $d['datos'] !== null
       && in_array('registros_gratis.seguimiento', $d['faltan'], true), json_encode($d['faltan']));
prueba('y el resumen no inventa pendientes', api('s=resumen&p=30', $COOKIE)['json']['pendientes']['registros'] === null);
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'escrito', 'id' => $hechos['registro_baja']], ['X-Admin: 1'], $COOKIE);
prueba('anotar sin las columnas dice qué migración falta', $r['codigo'] === 500 && strpos($r['json']['message'], 'sql/2026-10-27_registros_gratis.sql') !== false, $r['cuerpo']);
$db->exec('DROP TABLE registros_gratis');
$r = api('s=registros&p=30', $COOKIE);
prueba('sin la tabla: responde, sin datos y diciendo qué falta', $r['codigo'] === 200 && $r['json']['datos'] === null && $r['json']['lista'] === null
       && $r['json']['seguimiento'] === false && in_array('registros_gratis', $r['json']['faltan'], true), substr($r['cuerpo'], 0, 300));
$db->exec('DROP TABLE tienda_pagos');
$db->exec('DROP TABLE pruebas_equipo');
$db->exec('DROP TABLE dashboard_pos_ventas');
$db->exec('ALTER TABLE dashboard_pos DROP COLUMN hw_licencia');
foreach (['resumen', 'premium', 'tiendas', 'computadoras'] as $s1) {
    $r = api("s=$s1&p=30", $COOKIE);
    prueba("$s1 sigue respondiendo", $r['codigo'] === 200 && $r['json']['status'] === 'ok', $r['codigo'] . ' ' . substr($r['cuerpo'], 0, 200));
}
$d = api('s=premium&p=30', $COOKIE)['json'];
prueba('dice qué faltó', in_array('tienda_pagos', $d['faltan'], true) && in_array('pruebas_equipo', $d['faltan'], true) && in_array('dashboard_pos.hw_licencia', $d['faltan'], true), json_encode($d['faltan']));
prueba('dice lo que respondió MySQL', isset($d['errores']['tienda_pagos']) && strpos($d['errores']['tienda_pagos'], 'tienda_pagos') !== false, json_encode($d['errores']));
prueba('lo que falta sale null, no en ceros', $d['ventas'] === null && $d['prueba'] === null);
prueba('lo demás sigue', is_array($d['compras']) && count($d['compras']) === 10 && $d['compras'][0]['pagos'] === null);
$d = api('s=tiendas&p=30', $COOKIE)['json'];
prueba('sin el detalle por hora, null', $d['horas'] === null && $d['total'] !== null);

seccion('Freno a quien adivina');
for ($i = 0; $i < ADMIN_FALLOS_IP_HORA; $i++) http('POST', "$BASE/admin/", ['clave' => 'adivinando' . $i]);
$r = http('POST', "$BASE/admin/", ['clave' => CLAVE]);
prueba('tras 10 fallos ni la buena entra', strpos($r['cuerpo'], 'Demasiados intentos') !== false && !cabecera($r, 'Set-Cookie'));
$db->exec('DELETE FROM admin_intentos');
$db->exec('INSERT INTO admin_intentos (ip, hora, fallos) VALUES (\'9.9.9.9\', \'' . date('Y-m-d H') . '\', ' . ADMIN_FALLOS_TODOS_HORA . ')');
$r = http('POST', "$BASE/admin/", ['clave' => CLAVE]);
prueba('tras muchos fallos desde otras IPs, tampoco', strpos($r['cuerpo'], 'Demasiados intentos') !== false);
$db->exec('DROP TABLE admin_intentos');
$r = http('POST', "$BASE/admin/", ['clave' => CLAVE]);
prueba('sin la tabla del freno no se entra (y lo explica)', strpos($r['cuerpo'], 'sql/2026-10-21_admin.sql') !== false && !cabecera($r, 'Set-Cookie'));

seccion('Salir');
$r = http('POST', "$BASE/admin/api.php", ['accion' => 'salir'], ['X-Admin: 1'], $COOKIE);
$sc = implode(' ', cabecera($r, 'Set-Cookie'));
prueba('salir borra la cookie', $r['codigo'] === 200 && strpos($sc, 'ap_admin=;') !== false && strpos($sc, 'Max-Age=-3600') !== false, $sc);

echo "\n$ok bien, $mal mal\n";
exit($mal ? 1 : 0);
