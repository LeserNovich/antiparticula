<?php
// Prueba de la logica de parseo de sync_pos.php.
// Incluye el archivo real con $_POST simulado y captura $ventas_detalle
// al terminar. La conexion PDO falla (no hay driver) pero el parseo ya
// ocurrio: es justo lo que queremos verificar.

$REPO = dirname(__DIR__);

function correr($ultimas_ventas) {
    global $REPO;
    $payload = json_encode($ultimas_ventas);
    $script  = <<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'hardware_id'    => 'TESTHW01',
    'negocio'        => 'Prueba',
    'ventas_hoy'     => 100000,
    'tickets_hoy'    => 5,
    'ganancia_hoy'   => 30000,
    'ultimas_ventas' => $ARGV_JSON,
];
register_shutdown_function(function () {
    fwrite(STDOUT, "\n___DETALLE___" . json_encode($GLOBALS['ventas_detalle'] ?? null));
});
ob_start();
include $ARGV_FILE;
ob_end_clean();
PHP;
    $script = str_replace('$ARGV_JSON', var_export($payload, true), $script);
    $script = str_replace('$ARGV_FILE', var_export($REPO . '/public_html/sync_pos.php', true), $script);

    $tmp = tempnam(sys_get_temp_dir(), 'sync') . '.php';
    file_put_contents($tmp, "<?php\n" . $script);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
    unlink($tmp);

    $pos = strpos($out, '___DETALLE___');
    return $pos === false ? null : json_decode(substr($out, $pos + strlen('___DETALLE___')), true);
}

$pass = 0; $fail = 0;
function ok($cond, $label, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
    else { $fail++; echo "  \033[31m✗\033[0m $label" . ($extra ? "\n      → $extra" : '') . "\n"; }
}
function eq($a, $b, $label) { ok($a === $b, $label, 'esperado ' . json_encode($b) . ', obtenido ' . json_encode($a)); }

$hoy_mx  = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$hoy     = $hoy_mx->format('Y-m-d');
$ayer    = (clone $hoy_mx)->modify('-1 day')->format('Y-m-d');
$hora_mx = (int) $hoy_mx->format('G');
echo "\nHora Mexico City: {$hora_mx}:00 — hoy {$hoy}\n";

echo "\n\033[1m1. Parseo basico de horas\033[0m\n";
// Se manda 'fecha' explicita para aislar el parseo de hora de la
// heuristica de atribucion de dia (que se prueba aparte en la seccion 5).
$r = correr([
    ['hora' => '09:05',    'cajero' => 'Ana', 'total_centavos' => 15000, 'fecha' => $hoy],
    ['hora' => '09:47:33', 'cajero' => 'Ana', 'total_centavos' => 8000,  'fecha' => $hoy],
]);
ok(is_array($r) && count($r) === 2, 'dos ventas parseadas', json_encode($r));
eq($r[0]['hora'], 9,  'hora extraida');
eq($r[0]['minuto'], 5, 'minuto extraido');
eq($r[1]['minuto'], 47, 'HH:MM:SS -> minuto correcto');
eq($r[0]['cajero'], 'Ana', 'cajero preservado');
eq($r[0]['total'], 15000, 'importe preservado');
eq($r[0]['fecha'], $hoy, 'fecha explicita respetada');

echo "\n\033[1m2. Formato ISO en el campo hora\033[0m\n";
$r = correr([['hora' => '2026-09-03T16:22:10', 'cajero' => 'Luis', 'total_centavos' => 5000, 'fecha' => $hoy]]);
ok(count($r) === 1, 'venta ISO aceptada');
eq($r[0]['hora'], 16, 'hora desde ISO');
eq($r[0]['minuto'], 22, 'minuto desde ISO');

echo "\n\033[1m3. Horas invalidas se descartan\033[0m\n";
$r = correr([
    ['hora' => '25:00', 'cajero' => 'X', 'total_centavos' => 100],
    ['hora' => '10:75', 'cajero' => 'X', 'total_centavos' => 100],
    ['hora' => 'sin hora', 'cajero' => 'X', 'total_centavos' => 100],
    ['cajero' => 'X', 'total_centavos' => 100],
    ['hora' => '11:30', 'cajero' => 'X', 'total_centavos' => 100, 'fecha' => $hoy],
]);
eq(count($r), 1, 'solo la venta con hora valida entra al detalle');
eq($r[0]['hora'], 11, 'la valida es la de las 11');

echo "\n\033[1m4. Identidad de la venta (dedup)\033[0m\n";
$r = correr([
    ['hora' => '12:00', 'cajero' => 'Ana', 'total_centavos' => 5000],
    ['hora' => '12:00', 'cajero' => 'Ana', 'total_centavos' => 5000],
    ['hora' => '12:00', 'cajero' => 'Ana', 'total_centavos' => 7000],
]);
eq($r[0]['key'], $r[1]['key'], 'ventas identicas comparten clave (el UNIQUE las colapsa)');
ok($r[0]['key'] !== $r[2]['key'], 'importe distinto => clave distinta');
ok(strpos($r[0]['key'], 'h:') === 0, 'sin folio usa clave hash');

$r = correr([
    ['hora' => '12:00', 'cajero' => 'Ana', 'total_centavos' => 5000, 'folio' => 'A-1001'],
    ['hora' => '12:00', 'cajero' => 'Ana', 'total_centavos' => 5000, 'folio' => 'A-1002'],
]);
eq($r[0]['key'], 'f:A-1001', 'folio usado como clave');
ok($r[0]['key'] !== $r[1]['key'], 'dos ventas iguales con folio distinto NO se colapsan');

$r = correr([['hora' => '12:00', 'cajero' => 'Ana', 'total_centavos' => 5000, 'ticket' => 99]]);
eq($r[0]['key'], 'f:99', 'campo alterno "ticket" tambien sirve de folio');

echo "\n\033[1m5. Atribucion de fecha\033[0m\n";
$r = correr([['hora' => '10:00', 'cajero' => 'A', 'total_centavos' => 100, 'fecha' => $ayer]]);
eq($r[0]['fecha'], $ayer, 'fecha explicita del POS se respeta');

$r = correr([['hora' => '10:00', 'cajero' => 'A', 'total_centavos' => 100, 'fecha' => '2020-01-01']]);
eq($r[0]['fecha'], $hoy, 'fecha muy vieja se ignora, cae a hoy');

if ($hora_mx <= 21) {
    $futura = sprintf('%02d:00', 23);
    $r = correr([['hora' => $futura, 'cajero' => 'A', 'total_centavos' => 100]]);
    eq($r[0]['fecha'], $ayer, "hora futura ($futura > " . ($hora_mx + 1) . ") se atribuye a ayer");
} else {
    echo "  \033[33m—\033[0m prueba de hora futura omitida (son las {$hora_mx}h, no hay hora futura posible)\n";
}
$actual = sprintf('%02d:00', $hora_mx);
$r = correr([['hora' => $actual, 'cajero' => 'A', 'total_centavos' => 100]]);
eq($r[0]['fecha'], $hoy, "hora actual ($actual) se queda en hoy");

// El caso que motiva la heuristica: a las 00:0x el POS todavia reenvia las
// ventas de anoche. Sin esto se registrarian como del dia nuevo e inflarian
// la grafica horaria de un dia que apenas empieza.
if ($hora_mx === 0) {
    $r = correr([['hora' => '21:40', 'cajero' => 'A', 'total_centavos' => 100]]);
    eq($r[0]['fecha'], $ayer, 'a medianoche, una venta de las 21:40 es de ayer');
}

// Tolerancia de +1h para husos de Mexico adelantados (Quintana Roo, UTC-5):
// el POS reporta una hora mas que el reloj del servidor y aun asi es de hoy.
$mas_uno = sprintf('%02d:00', ($hora_mx + 1) % 24);
if ($hora_mx < 23) {
    $r = correr([['hora' => $mas_uno, 'cajero' => 'A', 'total_centavos' => 100]]);
    eq($r[0]['fecha'], $hoy, "hora +1 ($mas_uno) sigue siendo hoy (tolerancia de huso)");
}

echo "\n\033[1m6. Saneado de entrada\033[0m\n";
$r = correr([['hora' => '13:00', 'cajero' => '<script>alert(1)</script>Ana', 'total_centavos' => '  4200  ']]);
eq($r[0]['cajero'], 'alert(1)Ana', 'etiquetas HTML removidas del cajero');
eq($r[0]['total'], 4200, 'importe como texto se castea a entero');

$r = correr([['hora' => '13:00', 'cajero' => 'A', 'total_centavos' => 100, 'folio' => "../../etc/passwd'; DROP TABLE--"]]);
ok(preg_match('/^f:[a-zA-Z0-9_\-]+$/', $r[0]['key']) === 1, 'folio saneado a alfanumerico',
   json_encode($r[0]['key']));

$r = correr([['hora' => '13:00', 'cajero' => 'A', 'total_centavos' => 100, 'folio' => ['array']]]);
ok(strpos($r[0]['key'], 'h:') === 0, 'folio de tipo array se ignora sin romper');

echo "\n\033[1m7. Entradas degeneradas\033[0m\n";
foreach ([[], null] as $i => $entrada) {
    $r = correr($entrada === null ? [] : $entrada);
    ok(is_array($r) && count($r) === 0, 'lista vacia no produce detalle (' . $i . ')', json_encode($r));
}

echo "\n\033[1mResultado: $pass pasadas, $fail fallidas\033[0m\n\n";
exit($fail === 0 ? 0 : 1);
