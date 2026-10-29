<?php
// Siembra la base DESECHABLE de tests/correr_admin.sh con datos inventados pero con forma real: computadoras que
// prueban un día y se van, otras que se quedan meses, descargas, comentarios, paneles con ventas (uno en euros),
// compras de Premium (con renovaciones, OXXO sin pagar y compras de prueba de Stripe), claves a mano y la prueba de
// Excel. Las fechas cuentan desde HOY (hora de México). Nunca contra la base real: solo conoce config_db.test.php.
//
//   php tests/sembrar_admin.php <ruta de config_db.php>
// Deja en stdout un JSON con hechos que la prueba comprueba (IDs especiales).
date_default_timezone_set('America/Mexico_City');
mt_srand(42);
$cfg = require $argv[1];
$c = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password']);
$c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c->exec("SET time_zone = '-06:00'");

function dia($dias) { return date('Y-m-d', strtotime("-$dias days")); }
function momento($dias, $h, $m) { return dia($dias) . sprintf(' %02d:%02d:%02d', $h, $m, mt_rand(0, 59)); }
function elegir(array $a) { return $a[mt_rand(0, count($a) - 1)]; }

$lugares = [['Tecámac', 'México'], ['Ecatepec', 'México'], ['Ciudad de México', 'México'], ['Puebla', 'México'], ['Guadalajara', 'México'],
            ['Monterrey', 'México'], ['Querétaro', 'México'], ['Toluca', 'México'], ['Bogotá', 'Colombia'], ['Madrid', 'España']];
$negocios = ['Abarrotes La Esperanza', 'Pinturas del Valle', 'Ferretería El Clavo', 'Papelería Lupita', 'Tienda Don Beto', 'Farmacia San José',
             'Carnicería Los Primos', 'Dulcería Mimi', 'Refaccionaria Ruiz', 'Minisúper 24', 'Tortillería Lola', 'Estética Bella',
             'Verdulería El Huerto', 'Cremería La Vaca', 'Mercería Hilos', 'Taller Moto Fix', 'Zapatería Paso', 'Boutique Kiara',
             'Cafetería Aroma', 'Vinatería Copa', 'Panadería Trigo', 'Tlapalería Roja', 'Pollería Oro', 'Ópticas Visión'];

// ── Telemetría: 40 computadoras ─────────────────────────────────────────────
$ins = $c->prepare('INSERT INTO uso_punto_venta (tipo_evento, usuario, rol, tipo_sesion, negocio, hardware_id, ip_address, pais, ciudad, fecha_hora)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$equipos = [];
$c->beginTransaction();
for ($i = 0; $i < 40; $i++) {
    $id = strtoupper(substr(hash('sha256', 'equipo' . $i), 0, 16));
    $inicio = mt_rand(0, 300);
    $tipo = mt_rand(1, 10);
    $vida = $tipo <= 3 ? 0 : ($tipo <= 5 ? mt_rand(3, 25) : $inicio);   // un día, unas semanas o hasta hoy
    $lugar = $lugares[$i % 7 === 0 ? 8 + ($i % 2) : mt_rand(0, 7)];
    $negocio = $negocios[$i % count($negocios)] . ($i >= count($negocios) ? ' ' . ($i - count($negocios) + 2) : '');
    $usuarios = [['admin', 'administrador']];
    if (mt_rand(0, 1)) $usuarios[] = ['Caja1', 'cajero'];
    if (mt_rand(0, 3) === 0) $usuarios[] = ['Caja2', 'cajero'];
    for ($d = $inicio; $d >= max(0, $inicio - $vida); $d--) {
        if ($d !== $inicio && mt_rand(1, 10) > 7) continue;    // no abre todos los días
        $u = elegir($usuarios);
        $h = mt_rand(7, 20);
        $ins->execute(['login', $u[0], $u[1], $d === $inicio ? 'nuevo_turno' : elegir(['nuevo_turno', 'nuevo_turno', 'continuacion', 'admin_override', 'modo_informe']),
                       $negocio, $id, '187.190.' . ($i % 250) . '.' . mt_rand(1, 250), $lugar[1], $lugar[0], momento($d, $h, mt_rand(0, 59))]);
        if (mt_rand(0, 2)) $ins->execute(['cierre_turno', $u[0], $u[1], null, $negocio, $id, '187.190.0.1', $lugar[1], $lugar[0], momento($d, min(23, $h + mt_rand(1, 3)), mt_rand(0, 59))]);
        if (mt_rand(0, 1)) $ins->execute(['salida_app', $u[0], $u[1], null, $negocio, $id, '187.190.0.1', $lugar[1], $lugar[0], momento($d, min(23, $h + 3), 30)]);
    }
    $equipos[] = $id;
}
// Uno de pruebas (el que la prueba aparta) y eventos sin ID real (no deben contarse)
$prueba = 'TEST1234567890AB';
for ($d = 0; $d < 12; $d++) $ins->execute(['login', 'TestAdministrador', 'administrador', 'nuevo_turno', 'Tienda de Prueba Central', $prueba, '127.0.0.1', 'Local', 'Localhost', momento($d, 10, 0)]);
$ins->execute(['login', 'x', 'cajero', null, 'Sin ID', 'UNKNOWN', '1.1.1.1', 'México', 'Puebla', momento(1, 9, 0)]);
$ins->execute(['login', 'x', 'cajero', null, 'Sin ID', '', '1.1.1.1', 'México', 'Puebla', momento(1, 9, 0)]);
$c->commit();

// Versiones (sql/2026-10-26_version_pos.sql): las de hasta 3.6.0 no la mandan (NULL). Una de cada tres ya actualizó
// hace 20 días a la 3.7.0, y la mitad de esas otra vez hace 6 a la 3.10.0 (que debe ordenarse DESPUÉS de la 3.9: como
// número, no como texto).
$ver = $c->prepare('UPDATE uso_punto_venta SET version_pos = ? WHERE hardware_id = ? AND fecha_hora >= ?');
foreach ($equipos as $i => $id) {
    if ($i % 3) continue;
    $ver->execute(['3.7.0', $id, dia(20)]);
    if ($i % 2 === 0) $ver->execute(['3.10.0', $id, dia(6)]);
}

// ── Descargas ───────────────────────────────────────────────────────────────
$ins = $c->prepare('INSERT INTO registros_descargas (ip_address, pais, ciudad, fecha_hora) VALUES (?, ?, ?, ?)');
$c->beginTransaction();
for ($d = 0; $d <= 320; $d++) {
    $k = mt_rand(0, 5);
    for ($j = 0; $j < $k; $j++) {
        $l = $lugares[mt_rand(0, 9)];
        $ins->execute(['201.141.' . mt_rand(0, 3) . '.' . mt_rand(1, 60), $l[1], $l[0], momento($d, mt_rand(0, 23), mt_rand(0, 59))]);
    }
}
$c->commit();

// ── Comentarios: del programa (sin nombre) y de la página ───────────────────
$ins = $c->prepare('INSERT INTO comentarios_sugerencias (comentario, email, nombre, tipo_negocio, calificacion, ip_address, pais, ciudad, fecha_hora)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$textos = ['No me deja imprimir el ticket en 58 mm, ¿qué hago?', '¿Cómo agrego productos por Excel sin Premium?', 'Olvidé la contraseña del administrador',
           'Estaría bien poder vender a crédito', 'Me gustaría que el panel del celular mostrara el inventario por categoría',
           'Excelente programa, lo uso diario', '¿Funciona con báscula Torrey?', 'El corte de caja no me cuadra por $20'];
for ($j = 0; $j < 14; $j++) {
    $ins->execute([elegir($textos), $j % 3 ? 'cliente' . $j . '@correo.com' : null, null, null, 5, '9.9.9.' . $j, 'México', elegir(['Tecámac', 'Puebla']), momento(mt_rand(0, 200), 12, 0)]);
}
for ($j = 0; $j < 6; $j++) {
    $ins->execute(['Muy fácil de usar y sin pagar mensualidad, lo recomiendo.', null, $j ? 'Cliente ' . $j : '', elegir(['Abarrotes', 'Ferretería', '']), [5, 4, 5, 3, 5, 4][$j],
                   '8.8.8.' . $j, 'México', 'Toluca', momento(mt_rand(0, 120), 18, 0)]);
}
// Uno con un < > & que debe verse como texto, no como HTML
$ins->execute(['<img src=x onerror=alert(1)> & "comillas"', 'xss@correo.com', null, null, 5, '7.7.7.7', 'México', 'Puebla', momento(0, 8, 0)]);

// Uno de los del programa dejó su teléfono (columna de sql/2026-10-24_comentarios_telefono.sql)
$c->exec("UPDATE comentarios_sugerencias SET telefono = '5512345678' WHERE nombre IS NULL ORDER BY id LIMIT 1");

// ── Paneles del celular: 12 tiendas (una cobra en euros) ────────────────────
$tiendas = array_slice($equipos, 0, 12);
$euro = $tiendas[3];
$insP = $c->prepare('INSERT INTO dashboard_pos (hardware_id, negocio, ultima_sync, ventas_hoy, tickets_hoy, ganancia_hoy, abonos_hoy, turno_usuario, turno_inicio,
                     turno_abierto, ventas_turno, tickets_turno, abonos_turno, ultimas_ventas, historico_semana, historico_anual, extra_json, pin_hash, clave_hash,
                     premium_hasta, hw_licencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insH = $c->prepare('INSERT INTO dashboard_pos_historico (hardware_id, fecha, ventas_centavos, ganancia_centavos, tickets, efectivo_centavos, tarjeta_centavos,
                     transferencia_centavos, comision_centavos, cancelaciones, cancelado_centavos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insV = $c->prepare('INSERT INTO dashboard_pos_ventas (hardware_id, fecha, hora, minuto, cajero, total_centavos, venta_key) VALUES (?, ?, ?, ?, ?, ?, ?)');
$licencias = [];
$c->beginTransaction();
foreach ($tiendas as $k => $id) {
    $lic = $k < 8 ? strtoupper(substr(hash('sha256', 'licencia' . $k), 0, 16)) : null;
    if ($lic) $licencias[$id] = $lic;
    $extra = ['v' => 1, 'moneda' => $id === $euro ? 'EUR' : 'MXN',
              'inventario' => ['agotados' => mt_rand(0, 5), 'bajo_minimo' => mt_rand(0, 12), 'valor_costo' => $k % 4 ? mt_rand(500000, 9000000) : 0,
                               'urgentes' => [['nombre' => 'Coca-Cola 600 ml', 'cantidad' => 2000, 'minimo' => 12000, 'unidad' => 0],
                                              ['nombre' => 'Jamón de pavo', 'cantidad' => 350, 'minimo' => 1000, 'unidad' => 1]]],
              'apartados' => ['activos' => $k % 3 ? 0 : 2, 'por_cobrar' => $k % 3 ? 0 : 85000, 'vencidos' => 0],
              'presupuestos' => ['vigentes' => $k % 5 === 0 ? 3 : 0, 'monto' => $k % 5 === 0 ? 250000 : 0, 'por_vencer' => 0, 'vencidos' => 0],
              'gastos' => ['hoy' => 0, 'mes' => $k % 2 ? 120000 : 0], 'meta_diaria' => $k === 0 ? 500000 : 0,
              'top_productos' => ['hoy' => [], 'semana' => [], 'mes' => [['nombre' => 'Huevo blanco', 'importe' => 450000, 'cantidad' => 120500, 'unidad' => 1],
                                                                       ['nombre' => 'Refresco 2 L', 'importe' => 300000, 'cantidad' => 85000, 'unidad' => 0]]],
              'por_cajero' => [['cajero' => 'admin', 'tickets' => 20, 'ventas' => 150000], ['cajero' => 'Caja1', 'tickets' => 12, 'ventas' => 90000]]];
    $sync = $k === 11 ? momento(40, 10, 0) : date('Y-m-d H:i:s', time() - mt_rand(60, 3000));
    $premium = $k < 5 ? dia(-mt_rand(10, 300)) : ($k === 5 ? dia(20) : null);
    $insP->execute([$id, $negocios[$k], $sync, 523400, 31, 130000, 25000, 'Caja1', date('d-m-Y') . ' 08:00:00', $k % 2, 400000, 25, 0, '[]', '[]', '[]',
                    json_encode($extra), $k % 3 === 0 ? password_hash('123456', PASSWORD_DEFAULT) : null, $k % 3 === 0 ? hash('sha256', 'x') : null, $premium, $lic]);
    for ($d = 0; $d < 200; $d++) {
        if (mt_rand(1, 10) === 1) continue;   // un día cerrado
        $ventas = mt_rand(200000, 1500000);
        $desglose = $d < 40;
        $tarjeta = $desglose ? (int) ($ventas * mt_rand(0, 30) / 100) : null;
        $transf = $desglose ? (int) ($ventas * mt_rand(0, 10) / 100) : null;
        $insH->execute([$id, dia($d), $ventas, (int) ($ventas * 0.27), mt_rand(15, 150), $desglose ? $ventas - $tarjeta - $transf : null, $tarjeta, $transf,
                        $desglose ? (int) ($tarjeta * 0.035) : null, $desglose ? mt_rand(0, 2) : null, $desglose ? mt_rand(0, 3) * 5000 : null]);
        if ($d < 30) for ($j = 0; $j < 6; $j++) $insV->execute([$id, dia($d), elegir([8, 9, 10, 11, 12, 13, 13, 14, 17, 18, 19, 20]), mt_rand(0, 59), 'Caja1', mt_rand(2000, 40000), "k$d-$j"]);
    }
}
$c->commit();
$meses = $c->prepare('INSERT INTO dashboard_pos_meses (hardware_id, mes, ventas_centavos, ganancia_centavos, tickets)
                      SELECT hardware_id, DATE_FORMAT(fecha, \'%Y-%m\'), SUM(ventas_centavos), SUM(ganancia_centavos), SUM(tickets) FROM dashboard_pos_historico GROUP BY 1, 2');
$meses->execute();
$c->prepare('INSERT INTO dashboard_licencias (huella, hardware_id, vence) VALUES (?, ?, ?)')->execute([str_repeat('ab', 14), $tiendas[0], dia(-100)]);

// ── Tienda de Premium ───────────────────────────────────────────────────────
$insC = $c->prepare('INSERT INTO tienda_compras (codigo, plan, tipo, hardware_id, origen, estado, email, monto, stripe_sesion, stripe_suscripcion, creada, pagada)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insPago = $c->prepare('INSERT INTO tienda_pagos (compra_id, stripe_ref, meses, aplicado, creado) VALUES (?, ?, ?, 1, ?)');
$insK = $c->prepare('INSERT INTO tienda_claves (compra_id, hardware_id, tipo, vence_pagado, vence, clave, creada) VALUES (?, ?, ?, ?, ?, ?, ?)');
$precios = ['premium_mensual' => 14900, 'premium_anual' => 129000, 'premium_siempre' => 349000, 'multi_mensual' => 29900, 'multi_anual' => 299000];
$compras = [
    // plan, lic (índice de tienda o null), origen, estado, días atrás, renovaciones, prueba
    ['premium_anual', 0, 'pos', 'pagada', 200, 0, false],
    ['premium_mensual', 1, 'pos', 'pagada', 95, 3, false],
    ['premium_siempre', 2, 'panel', 'pagada', 60, 0, false],
    ['multi_mensual', 4, 'pos', 'pagada', 40, 1, false],
    ['premium_anual', null, 'web', 'pagada', 25, 0, false],
    ['premium_anual', 6, 'pos', 'pendiente', 1, 0, false],
    ['premium_mensual', null, 'web', 'vencida', 10, 0, false],
    ['premium_anual', 7, 'pos', 'pagada', 5, 0, false],
    ['premium_mensual', 1, 'pos', 'pagada', 3, 0, true],
    ['premium_anual', null, 'web', 'pagada', 2, 0, true],
];
$n = 0;
$hechos = ['ingresos_reales' => 0];
foreach ($compras as $x) {
    list($plan, $t, $origen, $estado, $hace, $renov, $esPrueba) = $x;
    $n++;
    $lic = $t === null ? null : $licencias[$tiendas[$t]];
    $sesion = ($esPrueba ? 'cs_test_' : 'cs_live_') . $n;
    $mensual = strpos($plan, 'mensual') !== false;
    $creada = momento($hace, 12, 0);
    $insC->execute([sprintf('%012d', $n), $plan, strpos($plan, 'multi') === 0 ? 'multi' : 'premium', $lic, $origen, $estado, "comprador$n@correo.com",
                    $precios[$plan], $sesion, $mensual ? "sub_$n" : null, $creada, $estado === 'pagada' ? $creada : null]);
    $id = (int) $c->lastInsertId();
    if ($estado !== 'pagada') continue;
    $insPago->execute([$id, $sesion, $mensual ? 1 : ($plan === 'premium_siempre' ? 0 : 12), $creada]);
    if (!$esPrueba) $hechos['ingresos_reales'] += $precios[$plan];
    for ($r = 1; $r <= $renov; $r++) {
        $insPago->execute([$id, "in_{$n}_$r", 1, momento($hace - 30 * $r, 12, 0)]);
        $hechos['ingresos_reales'] += $precios[$plan];
    }
    if ($lic) {
        // Una compra de prueba de Stripe da una clave de 2 días (TIENDA_DIAS_PRUEBA), como en producción
        $vence = $esPrueba ? dia(-2) : ($plan === 'premium_siempre' ? '2199-06-06' : ($mensual ? dia(-(30 - ($hace - 30 * $renov))) : dia($hace - 365)));
        $insK->execute([$id, $lic, strpos($plan, 'multi') === 0 ? 'multi' : 'premium', $vence, $vence, str_repeat('A', 125), $creada]);
    }
}
// El panel ve esas mismas claves: su premium_hasta es lo pagado más los 3 días de gracia (no una clave aparte)
$c->exec("UPDATE dashboard_pos d JOIN (SELECT hardware_id, MAX(vence) AS v FROM tienda_claves GROUP BY hardware_id) k
          ON k.hardware_id COLLATE utf8mb4_unicode_ci = d.hw_licencia
          SET d.premium_hasta = IF(k.v >= '2199-01-01', k.v, k.v + INTERVAL 3 DAY)");
// Claves a mano (de la consola) que reportó el programa: una vigente, una que vence pronto, una vencida hace poco
$insR = $c->prepare('INSERT INTO tienda_reportados (hardware_id, tipo, vence) VALUES (?, ?, ?)');
$insR->execute(['MANUAL0000000001', 'premium', dia(-200)]);
$insR->execute(['MANUAL0000000002', 'premium', dia(-10)]);
$insR->execute(['MANUAL0000000003', 'premium', dia(15)]);
// Prueba de Excel: 20 computadoras; la de la tienda 0 la agotó y compró
$insE = $c->prepare('INSERT INTO pruebas_equipo (equipo, primera_vez, exportar_usados, importar_usados) VALUES (?, ?, ?, ?)');
$insE->execute([$licencias[$tiendas[0]], momento(210, 9, 0), 3, 1]);
for ($j = 1; $j < 20; $j++) $insE->execute([strtoupper(substr(hash('sha256', 'prueba' . $j), 0, 16)), momento(mt_rand(0, 250), 9, 0), $j % 4 ? mt_rand(0, 3) : 0, $j % 5 ? mt_rand(0, 3) : 0]);
$c->prepare('INSERT INTO tienda_eventos (id, tipo, recibido) VALUES (?, ?, ?)')->execute(['evt_1', 'checkout.session.completed', momento(3, 12, 0)]);
$c->prepare('INSERT INTO tienda_eventos (id, tipo, recibido) VALUES (?, ?, ?)')->execute(['evt_2', 'invoice.paid', momento(1, 12, 0)]);
$c->prepare('INSERT INTO tienda_intentos (ip, hora, fallos) VALUES (?, ?, ?)')->execute(['5.5.5.5', date('Y-m-d H', time() - 3600), 4]);

// ── Registros de /gratis: 30 en dos meses; unos descargaron (a distintos ritmos), a unos ya les escribiste, uno pidió BAJA ──
$giros = ['abarrotes', 'ferreteria', 'farmacia', 'papeleria', 'ropa', 'comida', 'otro'];
$insG = $c->prepare('INSERT INTO registros_gratis (codigo, telefono, nombre, giro, ttclid, ttp, ip, user_agent, creado, descargo_en, sistema, contactado_en, baja_en)
                     VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?)');
$ahora = time();
$registros = [];
for ($i = 0; $i < 30; $i++) {
    $creado = strtotime(momento($i * 2, mt_rand(8, 21), mt_rand(0, 59)));
    if ($creado > $ahora) $creado = $ahora - 600;
    $tarda = [5, 60, 90, 1440, 2000, 4320, 6000][$i % 7];   // minutos hasta descargar (60, 1440 y 4320: justo en el borde de cada tramo)
    $descargo = ($i % 5 !== 3 && $creado + $tarda * 60 <= $ahora) ? $creado + $tarda * 60 : null;
    $escrito = ($i % 4 === 0 && $creado + 86400 <= $ahora) ? $creado + 86400 : null;
    $baja = $i === 7 ? $creado + 2 * 86400 : null;
    $f = function ($t) { return $t === null ? null : date('Y-m-d H:i:s', $t); };
    $insG->execute(['TST' . sprintf('%02d', $i), '+5255' . sprintf('%08d', 20000000 + $i), $i % 6 === 5 ? null : elegir(['Ana', 'Beto', 'Carla Ruiz', 'Don José']),
                    $giros[$i % 7], $i % 3 === 0 ? null : 'E.C.P.anuncio' . $i, '10.0.0.' . $i, $f($creado), $f($descargo),
                    $descargo ? elegir(['windows', 'macArm', 'macIntel']) : null, $f($escrito), $f($baja)]);
    $registros[$i] = (int) $c->lastInsertId();
}
$hechos['registro_baja'] = $registros[7];
$hechos['registro_pendiente'] = $registros[1];

$hechos += ['equipos' => $equipos, 'prueba' => $prueba, 'euro' => $euro, 'tiendas' => $tiendas, 'licencias' => $licencias];
echo json_encode($hechos);
