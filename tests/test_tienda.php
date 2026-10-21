<?php
// Pruebas de la tienda de Premium contra una MariaDB real y un Stripe de imitación (tests/stripe_falso.php).
// No se corre a mano: lo lanza tests/correr_tienda.sh.
//
//   php test_tienda.php <url_base>
//
// Pega por HTTP a los endpoints reales de public_html/tienda/ y consulta la base solo para revisar o preparar casos.
// Con /ejemplo montado (correr_tienda.sh <carpeta>) deja clave_tienda.json para que el POS compruebe que acepta las
// claves que firma el PHP.

$BASE = rtrim(isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8080', '/');

define('TIENDA_COMUN_OK', true);
require __DIR__ . '/../public_html/tienda_comun.php';   // funciones puras (fechas, códigos) y dash_licencia

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

// ── Llaves de PRUEBA: la de ventas (2) y una «de la consola» (1); config_db.php las usa en vez de las reales ──
function llave_nueva() { return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']); }
function publica_b64($llave) { return preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($llave)['key']); }
$VENTAS = llave_nueva();
$CONSOLA = llave_nueva();
openssl_pkey_export($VENTAS, $pem);
file_put_contents('/tmp/llave_ventas.pem', $pem);
$cfg_prueba = $cfg;
$cfg_prueba['llaves_licencia']        = [1 => publica_b64($CONSOLA), 2 => publica_b64($VENTAS)];
$cfg_prueba['llave_ventas']           = '/tmp/llave_ventas.pem';
$cfg_prueba['stripe_secreto']         = 'sk_test_prueba';
$cfg_prueba['stripe_webhook_secreto'] = 'whsec_prueba';
$cfg_prueba['stripe_api']             = 'http://127.0.0.1:8090';
$cfg_prueba['tienda_url']             = 'https://www.antiparticula.com';
$cfg_prueba['correo_archivo']         = '/tmp/correos.txt';
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg_prueba, true) . ';');
$cfg = $cfg_prueba;
@unlink('/tmp/correos.txt');

// ── HTTP ─────────────────────────────────────────────────────────────────────
function http($metodo, $url, $cuerpo = null, $cabeceras = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HEADER => true,
                            CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($cuerpo) ? http_build_query($cuerpo) : $cuerpo);
    if ($cabeceras) curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);
    $resp = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $cab = substr((string) $resp, 0, $hlen);
    $cuerpo = substr((string) $resp, $hlen);
    preg_match('/^Location:\s*(\S+)/mi', $cab, $m);
    return ['codigo' => $codigo, 'json' => json_decode($cuerpo, true), 'cuerpo' => $cuerpo, 'location' => isset($m[1]) ? $m[1] : null];
}
function pagar($query) { global $BASE; return http('GET', "$BASE/tienda/pagar.php?$query"); }
function stripe_pedido() { return json_decode(@file_get_contents('/tmp/stripe_ultima.json'), true); }
function aviso(array $evento, $secreto = 'whsec_prueba', $t = null) {
    global $BASE;
    $cuerpo = json_encode($evento);
    $t = $t === null ? time() : $t;
    $firma = "t=$t,v1=" . hash_hmac('sha256', "$t.$cuerpo", $secreto);
    return http('POST', "$BASE/tienda/webhook.php", $cuerpo, ["Stripe-Signature: $firma", 'Content-Type: application/json']);
}
function evento($tipo, array $objeto) { return ['id' => 'evt_' . bin2hex(random_bytes(8)), 'type' => $tipo, 'data' => ['object' => $objeto]]; }
function sesion($id, $plan, $hw, $pago = 'paid', $extra = []) {
    return array_merge(['id' => $id, 'object' => 'checkout.session', 'payment_status' => $pago, 'client_reference_id' => $hw,
        'metadata' => ['plan' => $plan, 'hw' => $hw === null ? '' : $hw, 'origen' => $hw === null ? 'web' : 'pos'],
        'customer_details' => ['email' => 'cliente@prueba.mx'], 'amount_total' => 129000, 'subscription' => null], $extra);
}
// Las compras de verdad (cs_live_); las del modo de prueba de Stripe (cs_test_) se prueban aparte: dan 2 días
function nueva_sesion($prueba = false) { return ($prueba ? 'cs_test_' : 'cs_live_') . bin2hex(random_bytes(10)); }
function equipo() { return strtoupper(bin2hex(random_bytes(8))); }
function claves($hw, $mias = '') { global $BASE; return http('POST', "$BASE/tienda/claves.php", ['hw' => $hw, 'claves' => $mias]); }
function canjear($codigo, $hw) { global $BASE; return http('POST', "$BASE/tienda/canjear.php", ['codigo' => $codigo, 'hw' => $hw]); }
function compra($sesion) {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM tienda_compras WHERE stripe_sesion = :s');
    $st->execute([':s' => $sesion]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function cuantas_claves($hw) {
    global $pdo;
    $st = $pdo->prepare('SELECT COUNT(*) FROM tienda_claves WHERE hardware_id = :hw');
    $st->execute([':hw' => $hw]);
    return (int) $st->fetchColumn();
}
function lic($clave) { global $cfg; return dash_licencia($cfg, $clave); }
function correos() { return (string) @file_get_contents('/tmp/correos.txt'); }
// Una clave «de la consola» (llave 1): la que alguien compró a mano por WhatsApp
function clave_consola($hw, $vence, $dominio = 'AP-LIC-1') {
    global $CONSOLA;
    $dias = (int) round((strtotime("$vence 00:00:00 UTC") - strtotime('2020-01-01 00:00:00 UTC')) / 86400);
    $n = random_int(0, 0xFFFFFF);
    $datos = chr(1) . chr($dias >> 8) . chr($dias & 0xFF) . chr($n >> 16) . chr(($n >> 8) & 0xFF) . chr($n & 0xFF) . hex2bin($hw);
    openssl_sign($dominio . $datos, $der, $CONSOLA, OPENSSL_ALGO_SHA256);
    return tienda_base32($datos . tienda_der_a_rs($der));
}

$HOY = dash_hoy_mx();
function mas($ymd, $meses, $dias = TIENDA_GRACIA_DIAS) { return tienda_sumar_dias(tienda_sumar_meses($ymd, $meses), $dias); }

echo "\nPHP " . PHP_VERSION . " · $BASE · hoy $HOY\n";

// ═════════════════════════════════════════════════════════════════════════════
titulo('1. Fechas, códigos y firma (funciones)');
eq(tienda_sumar_meses('2027-01-31', 1), '2027-02-28', '31-ene + 1 mes = 28-feb (no 3-mar)');
eq(tienda_sumar_meses('2028-01-31', 1), '2028-02-29', '... 29-feb en año bisiesto');
eq(tienda_sumar_meses('2026-10-20', 12), '2027-10-20', '+12 meses');
eq(tienda_sumar_meses('2026-03-31', 11), '2027-02-28', '+11 meses desde un día 31');
eq(tienda_sumar_dias('2026-12-30', 3), '2027-01-02', '+3 días cruzando el año');
$c = tienda_codigo_nuevo();
ok(strlen($c) === 12 && strspn($c, TIENDA_ALFABETO_CODIGO) === 12, 'código: 12 caracteres sin letras confundibles');
eq(tienda_codigo_normalizar(strtolower(tienda_codigo_mostrar($c))), $c, 'código: se acepta como se muestra (con AP- y guiones, en minúsculas)');
eq(tienda_codigo_normalizar(' ' . substr($c, 0, 4) . ' ' . substr($c, 4) . ' '), $c, '... y sin el AP-, con espacios');
eq(tienda_codigo_normalizar('AP-123'), null, 'un código incompleto no');
$hw = equipo();
$k = tienda_firmar($cfg, 'premium', $hw, '2027-05-01');
$l = lic($k);
ok($l !== null && $l['plan'] === 'premium' && $l['hw'] === $hw && $l['vence'] === '2027-05-01' && $l['kid'] === 2,
   'firma Premium: la verifica dash_licencia (llave 2, esa PC, ese día)', json_encode($l));
eq(strlen($k), 125, '... de 125 caracteres');
$l = lic(tienda_firmar($cfg, 'multi', $hw, '2027-05-01'));
eq($l ? $l['plan'] : null, 'multi', 'firma de varias sucursales: plan multi');
eq(lic(tienda_firmar($cfg, 'premium', $hw, TIENDA_PARA_SIEMPRE))['vence'], '2199-06-06', 'para siempre = 2199-06-06 (el último día que cabe)');
ok(tienda_firma_stripe_valida('{}', 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.{}', 's'), 's'), 'firma de Stripe: válida');
ok(!tienda_firma_stripe_valida('{}', 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.{"x":1}', 's'), 's'), '... otro cuerpo: no');
ok(!tienda_firma_stripe_valida('{}', 't=' . (time() - 600) . ',v1=' . hash_hmac('sha256', (time() - 600) . '.{}', 's'), 's'), '... de hace 10 minutos: no');

// ═════════════════════════════════════════════════════════════════════════════
titulo('2. Planes');
$r = http('GET', "$BASE/tienda/planes.php");
eq($r['codigo'], 200, 'planes.php: 200');
$ids = array_column($r['json']['planes'], 'id');
eq($ids, ['premium_mensual', 'premium_anual', 'premium_siempre', 'multi_mensual', 'multi_anual'], 'los cinco planes, en orden');
$precios = array_column($r['json']['planes'], 'precio', 'id');
eq([$precios['premium_mensual'], $precios['premium_anual'], $precios['premium_siempre'], $precios['multi_mensual'], $precios['multi_anual']],
   [14900, 129000, 349000, 29900, 299000], 'precios elegidos por el dueño: 149 · 1,290 · 3,490 · 299 · 2,990');
eq(count($r['json']['beneficios']['premium']), 6, 'beneficios de Premium (los mismos que Premium.INCLUYE del POS)');
eq($r['json']['beneficios']['multi'][0], 'Todo lo de Premium', 'el de sucursales incluye Premium');
$porId = []; foreach ($r['json']['planes'] as $p) $porId[$p['id']] = $p;
eq([$porId['premium_mensual']['renovable'], $porId['premium_mensual']['oxxo']], [true, false], 'mensual: se renueva sola y sin OXXO');
eq([$porId['premium_anual']['renovable'], $porId['premium_anual']['oxxo']], [false, true], 'anual: un pago, también con OXXO');

// ═════════════════════════════════════════════════════════════════════════════
titulo('3. Abrir el cobro (pagar.php)');
$r = pagar('plan=no_existe');
eq($r['codigo'], 404, 'un plan que no existe: 404');
$hw = equipo();
$r = pagar("plan=premium_anual&hw=$hw&origen=pos");
eq($r['codigo'], 303, 'desde el programa: 303 a Stripe');
$p = stripe_pedido();
eq($r['location'], "https://checkout.stripe.test/c/pay/{$p['id']}", '... a la página de cobro que dio Stripe');
$q = $p['params'];
eq([$q['mode'], $q['line_items'][0]['price_data']['currency'], $q['line_items'][0]['price_data']['unit_amount']],
   ['payment', 'mxn', '129000'], 'anual: un pago de $1,290 MXN');
eq($q['payment_method_types'], ['card', 'oxxo'], '... con tarjeta u OXXO');
eq([$q['client_reference_id'], $q['metadata']['plan'], $q['metadata']['hw'], $q['metadata']['origen']],
   [$hw, 'premium_anual', $hw, 'pos'], '... sabe la PC y el plan');
eq($q['success_url'], 'https://www.antiparticula.com/compra?sesion={CHECKOUT_SESSION_ID}', '... y vuelve a /compra con la sesión');
eq([$q['locale'], $p['version']], ['es-419', TIENDA_STRIPE_VERSION], '... en español y con la versión de la API fija');
pagar("plan=premium_mensual&hw=$hw&origen=pos");
$q = stripe_pedido()['params'];
eq([$q['mode'], $q['line_items'][0]['price_data']['recurring']['interval'], $q['payment_method_types']],
   ['subscription', 'month', ['card']], 'mensual: suscripción cada mes, solo tarjeta');
eq($q['subscription_data']['metadata']['hw'], $hw, '... y la suscripción también sabe la PC');
pagar('plan=multi_anual');
$q = stripe_pedido()['params'];
ok(!isset($q['client_reference_id']) && $q['metadata']['hw'] === '' && $q['metadata']['origen'] === 'web',
   'desde la página: sin PC (se entregará un código)');
eq($q['line_items'][0]['price_data']['unit_amount'], '299000', '... varias sucursales anual: $2,990');
$panel = 'PANEL' . bin2hex(random_bytes(4));
$hwPanel = equipo();
$pdo->prepare('INSERT INTO dashboard_pos (hardware_id, negocio, hw_licencia) VALUES (:p, :n, :hw)')
    ->execute([':p' => $panel, ':n' => 'Tienda del panel', ':hw' => $hwPanel]);
pagar("plan=premium_anual&panel=$panel");
$q = stripe_pedido()['params'];
eq([$q['client_reference_id'], $q['metadata']['origen']], [$hwPanel, 'panel'], 'desde el panel del celular: la PC que mandó el programa');
pagar('plan=premium_anual&panel=NO_EXISTE');
eq(isset(stripe_pedido()['params']['client_reference_id']), false, '... un panel sin PC conocida: como la página (con código)');
touch('/tmp/stripe_caido');
$r = pagar("plan=premium_anual&hw=$hw");
unlink('/tmp/stripe_caido');
eq($r['codigo'], 503, 'Stripe caído: 503 con una página que lo explica');
ok(strpos($r['cuerpo'], 'No se pudo abrir el cobro') !== false && strpos($r['cuerpo'], 'wa.me') !== false, '... y ofrece WhatsApp');
ok(strpos($r['cuerpo'], 'Detalle técnico: Stripe respondió 500') !== false, '... con el detalle técnico (Stripe respondió 500)');
touch('/tmp/stripe_sin_oxxo');
$r = pagar("plan=premium_anual&hw=$hw");
unlink('/tmp/stripe_sin_oxxo');
ok(strpos($r['cuerpo'], 'param payment_method_types') !== false && strpos($r['cuerpo'], 'oxxo') !== false,
   'OXXO sin activar en Stripe: el detalle lo dice (param payment_method_types)');
$cfg_mala = $cfg; $cfg_mala['stripe_secreto'] = 'rk_test_equivocada';
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg_mala, true) . ';');
$r = pagar("plan=premium_anual&hw=$hw");
ok(strpos($r['cuerpo'], 'Stripe respondió 401: la llave no es válida') !== false, 'una llave equivocada: el detalle lo dice');
ok(strpos($r['cuerpo'], 'sk_test_****') === false, '... sin repetir lo que Stripe dice de la llave');
unset($cfg_mala['stripe_secreto']);
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg_mala, true) . ';');
ok(strpos(pagar("plan=premium_anual&hw=$hw")['cuerpo'], 'falta stripe_secreto en config_db.php') !== false, 'sin la llave en config_db.php: lo dice');
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg, true) . ';');

// ═════════════════════════════════════════════════════════════════════════════
titulo('4. El aviso de Stripe: firma');
$s1 = nueva_sesion();
$e = evento('checkout.session.completed', sesion($s1, 'premium_anual', $hw));
eq(aviso($e, 'whsec_otro')['codigo'], 400, 'firmado con otro secreto: 400');
ok(strpos((string) aviso($e, 'whsec_otro')['json']['detalle'], 'la firma no coincide') === 0, '... y dice por qué (Stripe lo enseña al dueño)');
eq(aviso($e, 'whsec_prueba', time() - 900)['codigo'], 400, 'de hace 15 minutos (repetido por alguien): 400');
eq(compra($s1), null, '... y no se anotó nada');

// ═════════════════════════════════════════════════════════════════════════════
titulo('5. Compra desde el programa: la clave llega sola');
$r = aviso($e);
eq($r['codigo'], 200, 'aviso de pago: 200');
$c1 = compra($s1);
eq([$c1['estado'], $c1['plan'], $c1['tipo'], $c1['hardware_id'], $c1['email']],
   ['pagada', 'premium_anual', 'premium', $hw, 'cliente@prueba.mx'], 'compra pagada, de esa PC');
$r = claves($hw);
eq(count($r['json']['claves']), 1, 'el programa pregunta y hay una clave');
$l = lic($r['json']['claves'][0]);
eq([$l['plan'], $l['hw'], $l['vence']], ['premium', $hw, mas($HOY, 12)], '... Premium para esa PC, hasta hoy + 12 meses + 3 días de gracia');
ok(strpos(correos(), 'Para: cliente@prueba.mx') !== false && strpos(correos(), tienda_codigo_mostrar($c1['codigo'])) !== false,
   'llega el correo con el código');
ok(strpos(correos(), 'Se activa solo') !== false, '... que dice que se activa solo');
eq(aviso($e)['json']['repetido'] ?? null, true, 'el mismo aviso otra vez: se reconoce como repetido');
$e2 = evento('checkout.session.completed', sesion($s1, 'premium_anual', $hw));   // otro id de evento, misma sesión
eq(aviso($e2)['codigo'], 200, 'otro aviso de la misma sesión: 200');
eq(cuantas_claves($hw), 1, '... y no se emite otra clave');
eq(substr_count(correos(), 'Para: cliente@prueba.mx'), 1, '... ni otro correo');
$r = http('GET', "$BASE/tienda/estado.php?sesion=$s1");
eq([$r['json']['estado'], $r['json']['con_pc'], $r['json']['codigo']], ['pagada', true, tienda_codigo_mostrar($c1['codigo'])],
   'la página /compra: pagada, la clave llega sola, y el código');
eq(http('GET', "$BASE/tienda/estado.php?sesion=" . nueva_sesion())['json']['estado'], 'esperando', '... una sesión sin aviso todavía: esperando');
eq(http('GET', "$BASE/tienda/estado.php?sesion=x';--")['codigo'], 400, '... una sesión mal formada: 400');

titulo('6. Renovar se suma; la mensualidad se renueva sola');
$s2 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s2, 'premium_mensual', $hw, 'paid', ['subscription' => 'sub_prueba1', 'amount_total' => 14900])));
$ultima = lic(claves($hw)['json']['claves'][0]);
eq($ultima['vence'], mas(tienda_sumar_meses($HOY, 12), 1), 'un mes más, sumado a lo pagado (no a la gracia): hoy + 13 meses + 3 días');
eq(compra($s2)['stripe_suscripcion'], 'sub_prueba1', 'la compra recuerda su suscripción');
aviso(evento('invoice.paid', ['id' => 'in_crea1', 'billing_reason' => 'subscription_create', 'subscription' => 'sub_prueba1']));
eq(cuantas_claves($hw), 2, 'la primera factura de la suscripción no da otro mes (ya lo dio la sesión)');
aviso(evento('invoice.paid', ['id' => 'in_ciclo1', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_prueba1']));
eq(lic(claves($hw)['json']['claves'][0])['vence'], mas(tienda_sumar_meses($HOY, 12), 2), 'la renovación del mes: un mes más');
aviso(evento('invoice.paid', ['id' => 'in_ciclo1', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_prueba1']));
eq(cuantas_claves($hw), 3, '... la misma factura otra vez no suma');
aviso(evento('invoice.paid', ['id' => 'in_ciclo2', 'billing_reason' => 'subscription_cycle',
                               'parent' => ['subscription_details' => ['subscription' => 'sub_prueba1']]]));
eq(lic(claves($hw)['json']['claves'][0])['vence'], mas(tienda_sumar_meses($HOY, 12), 3), 'la factura con la forma nueva de la API (parent): también');
eq(aviso(evento('invoice.paid', ['id' => 'in_x', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_ajena']))['codigo'], 200,
   'una suscripción que no es de la tienda: 200 y nada');

titulo('7. Lo que la PC ya tenía (clave de la consola) no se pierde');
$hw2 = equipo();
$deConsola = clave_consola($hw2, tienda_sumar_dias($HOY, 100));
claves($hw2, $deConsola . ',' . clave_consola(equipo(), '2030-01-01') . ',basura');
$s3 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s3, 'premium_anual', $hw2)));
eq(lic(claves($hw2)['json']['claves'][0])['vence'], mas(tienda_sumar_dias($HOY, 100), 12),
   'tenía 100 días: el año se suma desde ahí (solo cuenta su clave verificada de esa PC)');
$hw3 = equipo();
claves($hw3, clave_consola($hw3, tienda_sumar_dias($HOY, -30)));
$s4 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s4, 'premium_anual', $hw3)));
eq(lic(claves($hw3)['json']['claves'][0])['vence'], mas($HOY, 12), 'la suya venció hace 30 días: empieza hoy');

titulo('8. OXXO: la clave llega cuando paga');
$hw4 = equipo();
$s5 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s5, 'premium_siempre', $hw4, 'unpaid', ['amount_total' => 349000])));
eq(compra($s5)['estado'], 'pendiente', 'ficha de OXXO: compra pendiente');
$r = claves($hw4);
eq([$r['json']['claves'], $r['json']['pendientes']], [[], ['premium_siempre']], '... sin clave, y el programa sabe que falta pagar');
eq(http('GET', "$BASE/tienda/estado.php?sesion=$s5")['json']['estado'], 'pendiente', '... la página dice pendiente');
eq(substr_count(correos(), 'Premium para siempre'), 0, '... y todavía no hay correo');
aviso(evento('checkout.session.async_payment_succeeded', sesion($s5, 'premium_siempre', $hw4, 'paid', ['amount_total' => 349000])));
$l = lic(claves($hw4)['json']['claves'][0]);
eq([compra($s5)['estado'], $l['vence']], ['pagada', TIENDA_PARA_SIEMPRE], 'pagó en OXXO: Premium para siempre');
eq(substr_count(correos(), 'Tu Premium para siempre'), 1, '... y un correo');
$r = pagar("plan=premium_anual&hw=$hw4");
eq([$r['codigo'], $r['location']], [303, 'https://www.antiparticula.com/compra?ya=siempre'], 'quien ya tiene para siempre no puede volver a comprar Premium');
$r = pagar("plan=multi_mensual&hw=$hw4");
ok(strpos((string) $r['location'], 'checkout.stripe.test') !== false, '... pero sí el plan de sucursales');
$s6 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s6, 'premium_anual', equipo(), 'unpaid')));
aviso(evento('checkout.session.async_payment_failed', sesion($s6, 'premium_anual', null, 'unpaid')));
eq(compra($s6)['estado'], 'vencida', 'una ficha de OXXO que venció sin pagarse: vencida');

titulo('9. Varias sucursales');
$hw5 = equipo();
$s7 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s7, 'multi_anual', $hw5, 'paid', ['amount_total' => 299000])));
$l = lic(claves($hw5)['json']['claves'][0]);
eq([$l['plan'], $l['vence']], ['multi', mas($HOY, 12)], 'una clave del plan de sucursales, por un año');
eq(compra($s7)['tipo'], 'multi', '... y la compra es de ese tipo');

titulo('10. Compra desde la página: código que se canjea');
$s8 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s8, 'premium_anual', null)));
$c8 = compra($s8);
eq([$c8['estado'], $c8['hardware_id']], ['pagada', null], 'pagada y sin PC');
$r = http('GET', "$BASE/tienda/estado.php?sesion=$s8");
eq([$r['json']['con_pc'], $r['json']['codigo']], [false, tienda_codigo_mostrar($c8['codigo'])], 'la página da el código para canjear');
ok(strpos(correos(), 'Para activarlo: en el programa abre Configuración > Premium') !== false, '... y el correo explica cómo canjearlo');
$pcA = equipo(); $pcB = equipo(); $pcC = equipo();
$r = canjear(strtolower(tienda_codigo_mostrar($c8['codigo'])), $pcA);
eq([$r['json']['ok'], count($r['json']['claves'])], [true, 1], 'la primera PC lo canjea (como se lo dictaron, en minúsculas)');
eq(lic($r['json']['claves'][0])['hw'], $pcA, '... y la clave es de esa PC');
eq(compra($s8)['ultimo_cambio_pc'], null, '... canjear la primera vez no cuenta como cambio de PC');
$r = canjear($c8['codigo'], $pcA);
eq([$r['json']['ok'], $r['json']['motivo']], [true, 'ok'], 'la misma PC otra vez (reinstaló): sus claves de nuevo');
eq(cuantas_claves($pcA), 1, '... sin emitir otra');
$r = canjear($c8['codigo'], $pcB);
eq($r['json']['ok'], true, 'otra PC: cambio automático');
eq([lic($r['json']['claves'][0])['hw'], lic($r['json']['claves'][0])['vence']], [$pcB, mas($HOY, 12)], '... con lo que quedaba de lo pagado');
eq(compra($s8)['hardware_id'], $pcB, '... la compra ya es de la PC nueva');
$r = canjear($c8['codigo'], $pcC);
eq([$r['json']['ok'], $r['json']['motivo']], [false, 'cambio_reciente'], 'otro cambio antes de 12 meses: no');
ok(strpos($r['json']['mensaje'], 'WhatsApp') !== false, '... y dice a partir de cuándo, o por WhatsApp');
$pdo->prepare('UPDATE tienda_compras SET ultimo_cambio_pc = NOW() - INTERVAL 13 MONTH WHERE id = :id')->execute([':id' => $c8['id']]);
eq(canjear($c8['codigo'], $pcC)['json']['ok'], true, '... pasados 12 meses: sí');
$r = canjear('AP-' . str_repeat('A', 4) . '-CCCC-DDDD', $pcC);
eq([$r['json']['ok'], $r['json']['motivo']], [false, 'no_existe'], 'un código que no existe: no');
$r = canjear(tienda_codigo_mostrar(compra($s6)['codigo']), $pcC);
eq($r['json']['motivo'], 'no_pagada', 'el código de una ficha de OXXO vencida: no');
$s9 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s9, 'premium_anual', null, 'unpaid')));
eq(canjear(compra($s9)['codigo'], $pcC)['json']['motivo'], 'pendiente', 'el código de una ficha sin pagar todavía: espera');
eq(http('POST', "$BASE/tienda/canjear.php", ['codigo' => 'X', 'hw' => 'no-es-un-id'])['codigo'], 400, 'sin un Hardware ID válido: 400');
$ip_bloqueada = false;
for ($i = 0; $i < 25; $i++) {
    $r = canjear('AP-' . strtoupper(substr(str_shuffle(str_repeat(TIENDA_ALFABETO_CODIGO, 3)), 0, 12)), $pcC);
    if ($r['codigo'] === 429) { $ip_bloqueada = true; break; }
}
ok($ip_bloqueada, 'muchos códigos inventados desde la misma IP: se frena (429)');
eq(canjear($c8['codigo'], $pcC)['codigo'], 429, '... también para un código bueno, durante esa hora');
$pdo->exec('DELETE FROM tienda_intentos');

titulo('11. Avisos que no son de la tienda');
$s10 = nueva_sesion();
$ajena = sesion($s10, 'premium_anual', equipo());
$ajena['metadata'] = [];   // un enlace de pago viejo de Stripe (sin plan)
eq(aviso(evento('checkout.session.completed', $ajena))['codigo'], 200, 'una sesión sin plan de la tienda: 200');
eq(compra($s10), null, '... y no se anota');
eq(aviso(evento('customer.created', ['id' => 'cus_1']))['codigo'], 200, 'un evento que no interesa: 200');

titulo('12. Modo de prueba de Stripe: la clave dura 2 días');
$hwP = equipo();
$sP = nueva_sesion(true);
aviso(evento('checkout.session.completed', sesion($sP, 'premium_siempre', $hwP, 'paid', ['amount_total' => 349000])));
$l = lic(claves($hwP)['json']['claves'][0]);
eq($l['vence'], tienda_sumar_dias($HOY, 2), 'para siempre con la tarjeta de prueba: solo 2 días (la firma es la real)');
eq(http('GET', "$BASE/tienda/estado.php?sesion=$sP")['json']['prueba'], true, 'la página /compra sabe que fue de prueba');
ok(strpos(correos(), 'Asunto: [PRUEBA] Tu Premium para siempre') !== false, 'el correo lo dice en el asunto');
$sP2 = nueva_sesion(true);
aviso(evento('checkout.session.completed', sesion($sP2, 'premium_anual', null)));
$pcP = equipo();
$r = canjear(compra($sP2)['codigo'], $pcP);
eq([$r['json']['ok'], lic($r['json']['claves'][0])['vence']], [true, tienda_sumar_dias($HOY, 2)],
   'una compra de prueba en la página, canjeada: también 2 días');
$s12 = nueva_sesion();
aviso(evento('checkout.session.completed', sesion($s12, 'premium_anual', $pcP)));
eq(lic(claves($pcP)['json']['claves'][0])['vence'], mas($HOY, 12), '... y no le suma nada a una compra real después');

titulo('13. Si no se puede firmar, Stripe reintenta');
$cfg_rota = $cfg; $cfg_rota['llave_ventas'] = '/tmp/no_existe.pem';
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg_rota, true) . ';');
$s11 = nueva_sesion();
$e11 = evento('checkout.session.completed', sesion($s11, 'premium_anual', equipo()));
$r11 = aviso($e11);
eq($r11['codigo'], 500, 'sin la llave de ventas: 500 (Stripe vuelve a avisar)');
ok(strpos((string) $r11['json']['detalle'], 'Falta la llave de ventas') === 0, '... y el detalle dice que falta la llave');
eq(compra($s11), null, '... y no queda una compra a medias');
file_put_contents('/app/config_db.php', '<?php return ' . var_export($cfg, true) . ';');
eq(aviso($e11)['codigo'], 200, 'con la llave de vuelta, el reintento sale bien');
eq(compra($s11)['estado'], 'pagada', '... y la compra queda pagada');

// ── Ejemplo para el POS: claves firmadas aquí, que Java debe aceptar ──────────
if (is_dir('/ejemplo')) {
    $hwJ = '79350C063DFC1727';
    file_put_contents('/ejemplo/clave_tienda.json', json_encode([
        'kid' => 2, 'publica' => publica_b64($VENTAS), 'hw' => $hwJ,
        'premium' => tienda_firmar($cfg, 'premium', $hwJ, '2027-12-31'),
        'multi' => tienda_firmar($cfg, 'multi', $hwJ, '2027-12-31'),
        'siempre' => tienda_firmar($cfg, 'premium', $hwJ, TIENDA_PARA_SIEMPRE),
    ], JSON_PRETTY_PRINT));
    echo "\n  (ejemplo para el POS escrito en clave_tienda.json)\n";
}

echo "\n" . ($fail === 0 ? "\033[32mTODO BIEN\033[0m" : "\033[31m$fail FALLO(S)\033[0m") . " · $pass comprobaciones\n";
exit($fail === 0 ? 0 : 1);
