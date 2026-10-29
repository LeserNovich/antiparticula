<?php
// ────────────────────────────────────────────────────────────────────────────
// Los datos de tu página privada (/admin/), en JSON. Solo con la sesión de la clave (cookie de admin/index.php).
//
//   GET  api.php?s=resumen|descargas|registros|computadoras|tiendas|premium|comentarios&p=7|30|90|365|todo
//   GET  api.php?s=equipo&id=<ID del panel y la telemetría>   (o &lic=<Hardware ID de la licencia>)
//   POST api.php  (cabecera X-Admin: 1)   accion=ocultar&id=…&nota=…  |  accion=contar&id=…  |  accion=salir
//                                         accion=escrito|baja|quitar_baja|borrar_registro&id=<id de registros_gratis>
//
// Solo LEE la base, salvo admin_ocultos (los equipos que no cuentan) y el seguimiento de registros_gratis (a quién le
// escribiste, quién pidió BAJA y borrar a quien pida que se borren sus datos). Cada bloque es independiente: si falta una
// tabla o una columna, ese bloque sale null y su nombre va en `faltan`; la página no lo enseña (nunca un cero
// inventado). Las cruces entre tablas de distinto origen (telemetría, panel, tienda) se hacen aquí en PHP y no con
// JOIN: las tablas creadas a mano en phpMyAdmin pueden tener otra intercalación y MySQL rechazaría la comparación.
//
// Dos IDs de computadora: el de la telemetría y el panel (uso_punto_venta, dashboard_pos.hardware_id; «equipo») y
// el de la licencia (tienda_*, pruebas_equipo; «lic»). Los une dashboard_pos.hw_licencia (POS 3.5.0 o más nuevo).
// ────────────────────────────────────────────────────────────────────────────
define('ADMIN_COMUN_OK', true);
require __DIR__ . '/../admin_comun.php';
// Los planes y sus precios viven solo en tienda_planes() (y con ella llega dashboard_comun.php)
define('TIENDA_COMUN_OK', true);
require_once __DIR__ . '/../tienda_comun.php';
// Los giros de /gratis y el link personal de cada registro viven en gratis_comun.php
define('GRATIS_COMUN_OK', true);
require_once __DIR__ . '/../gratis_comun.php';

$cfg = admin_config();
admin_cabeceras(false);
if (!admin_sesion_valida($cfg)) admin_json(401, ['status' => 'sin_sesion']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Otra página no puede mandar esta cabecera sin pedir permiso (CORS), y la cookie ya es SameSite=Strict
    if (!isset($_SERVER['HTTP_X_ADMIN']) || $_SERVER['HTTP_X_ADMIN'] !== '1') admin_json(403, ['status' => 'error']);
    $accion = isset($_POST['accion']) ? (string) $_POST['accion'] : '';
    if ($accion === 'salir') {
        admin_cerrar_sesion();
        admin_json(200, ['status' => 'ok']);
    }
}

try {
    $conn = admin_conectar($cfg);
} catch (PDOException $e) {
    error_log('admin/api (conexión): ' . $e->getMessage());
    admin_json(500, ['status' => 'error', 'message' => 'No se pudo conectar a la base']);
}

const ADM_TODOS_ANTES  = '2000-01-01';
const ADM_PARA_SIEMPRE = '2199-06-06';
const ADM_LISTA_MAX    = 3000;

$FALTAN = [];
$ERRORES = [];   // el primer mensaje de MySQL de cada bloque que falló: solo lo ve el dueño, y dice qué arreglar

// ── Utilidades ──────────────────────────────────────────────────────────────
function adm_todos(PDO $c, $sql, array $p = []) {
    $st = $c->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function adm_uno(PDO $c, $sql, array $p = []) {
    $st = $c->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
}

// Corre un bloque; si falla (tabla o columna que no existe) deja null y anota cuál faltó
function adm_bloque($nombre, $fn) {
    global $FALTAN, $ERRORES;
    try {
        return $fn();
    } catch (PDOException $e) {
        error_log("admin/api ($nombre): " . $e->getMessage());
        $FALTAN[] = $nombre;
        if (!isset($ERRORES[$nombre])) $ERRORES[$nombre] = $e->getMessage();
        return null;
    }
}

function adm_dia($ymd, $dias) {
    $d = new DateTime($ymd);
    if ($dias !== 0) $d->modify(($dias > 0 ? '+' : '') . $dias . ' days');
    return $d->format('Y-m-d');
}

function adm_dias_entre($a, $b) {
    return (int) (new DateTime($a))->diff(new DateTime($b))->days;
}

function adm_int($v) { return $v === null ? null : (int) $v; }

function adm_id_valido($id) {
    return is_string($id) && preg_match('/^[A-Za-z0-9._-]{1,50}$/', $id) === 1;
}

// El periodo: desde (incluido) hasta hoy, el anterior del mismo largo y cómo se agrupan las gráficas.
// $minimo: la fecha más vieja de los datos de la sección (solo para «todo»).
function adm_periodo($p, $minimo) {
    $hoy = date('Y-m-d');
    $largos = ['7' => 7, '30' => 30, '90' => 90, '365' => 365];
    if (isset($largos[$p])) {
        $dias = $largos[$p];
        $desde = adm_dia($hoy, -($dias - 1));
        $antes_desde = adm_dia($desde, -$dias);
    } else {
        $desde = $minimo ? substr($minimo, 0, 10) : adm_dia($hoy, -29);
        if ($desde > $hoy) $desde = $hoy;
        $dias = adm_dias_entre($desde, $hoy) + 1;
        $antes_desde = null;   // «todo» no tiene periodo anterior
    }
    $gran = $dias <= 92 ? 'dia' : ($dias <= 400 ? 'semana' : 'mes');
    return [
        'clave' => isset($largos[$p]) ? $p : 'todo',
        'desde' => $desde, 'hasta' => $hoy, 'fin' => adm_dia($hoy, 1), 'dias' => $dias,
        'antes_desde' => $antes_desde, 'antes_fin' => $antes_desde ? $desde : null,
        'gran' => $gran, 'claves' => adm_claves($desde, $hoy, $gran),
    ];
}

function adm_inicio_bucket($ymd, $gran) {
    $d = new DateTime($ymd);
    if ($gran === 'semana') $d->modify('-' . ((int) $d->format('N') - 1) . ' days');   // lunes
    if ($gran === 'mes') $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1);
    return $d->format('Y-m-d');
}

function adm_claves($desde, $hasta, $gran) {
    $claves = [];
    $d = new DateTime(adm_inicio_bucket($desde, $gran));
    $fin = new DateTime($hasta);
    $paso = $gran === 'dia' ? '+1 day' : ($gran === 'semana' ? '+7 days' : '+1 month');
    while ($d <= $fin && count($claves) < 2000) {
        $claves[] = $d->format('Y-m-d');
        $d->modify($paso);
    }
    return $claves;
}

// La expresión SQL que agrupa una fecha como las claves de adm_claves()
function adm_bucket($col, $gran) {
    if ($gran === 'semana') return "DATE_FORMAT(DATE($col) - INTERVAL WEEKDAY($col) DAY, '%Y-%m-%d')";
    if ($gran === 'mes') return "DATE_FORMAT($col, '%Y-%m-01')";
    return "DATE_FORMAT($col, '%Y-%m-%d')";
}

// Filas [k, campos…] → un arreglo por cada clave del periodo (0 donde no hubo nada)
function adm_serie(array $filas, array $claves, $campo) {
    $m = [];
    foreach ($filas as $f) $m[$f['k']] = $f[$campo] === null ? 0 : $f[$campo] + 0;
    $out = [];
    foreach ($claves as $k) $out[] = isset($m[$k]) ? $m[$k] : 0;
    return $out;
}

// " AND col NOT IN ('a','b')" con los equipos que no cuentan (citados por PDO: se comparan como literales, sin
// choque de intercalaciones)
function adm_excluir(PDO $c, $col, array $ids) {
    if (!$ids) return '';
    $q = [];
    foreach ($ids as $id) $q[] = $c->quote($id);
    return " AND $col NOT IN (" . implode(',', $q) . ')';
}

// ── Lo que varias secciones comparten ───────────────────────────────────────
function adm_ocultos(PDO $c) {
    $r = adm_bloque('admin_ocultos', function () use ($c) {
        $m = [];
        foreach (adm_todos($c, 'SELECT hardware_id, nota, creado FROM admin_ocultos') as $f) $m[$f['hardware_id']] = $f;
        return $m;
    });
    return $r === null ? [] : $r;
}

// Los paneles: [ID del equipo => negocio, última sync, hw_licencia, premium_hasta]. Cada columna nueva por separado.
function adm_paneles(PDO $c) {
    $base = adm_bloque('dashboard_pos', function () use ($c) {
        $m = [];
        foreach (adm_todos($c, 'SELECT hardware_id, negocio, ultima_sync FROM dashboard_pos') as $f) {
            $f['hw_licencia'] = null;
            $f['premium_hasta'] = null;
            $m[$f['hardware_id']] = $f;
        }
        return $m;
    });
    if ($base === null) return null;
    adm_bloque('dashboard_pos.premium_hasta', function () use ($c, &$base) {
        foreach (adm_todos($c, 'SELECT hardware_id, premium_hasta FROM dashboard_pos WHERE premium_hasta IS NOT NULL') as $f) {
            if (isset($base[$f['hardware_id']])) $base[$f['hardware_id']]['premium_hasta'] = $f['premium_hasta'];
        }
    });
    adm_bloque('dashboard_pos.hw_licencia', function () use ($c, &$base) {
        foreach (adm_todos($c, 'SELECT hardware_id, hw_licencia FROM dashboard_pos WHERE hw_licencia IS NOT NULL') as $f) {
            if (isset($base[$f['hardware_id']])) $base[$f['hardware_id']]['hw_licencia'] = $f['hw_licencia'];
        }
    });
    return $base;
}

function adm_equipo_de_licencia($paneles) {
    $m = [];
    foreach ((array) $paneles as $id => $p) if ($p['hw_licencia']) $m[$p['hw_licencia']] = $id;
    return $m;
}

// Quién tiene (o tuvo) Premium. Junta las tres fuentes: las claves que vendió la tienda (sin las compras de
// prueba de Stripe), las de la consola que el programa reportó (tienda_reportados) y el premium_hasta que vio el
// panel. Una entrada por computadora y tipo: [clave => lic, equipo, tipo, vence, fuente, mensual, email].
function adm_licencias(PDO $c, $paneles) {
    $m = [];
    $poner = function ($clave, array $e) use (&$m) {
        if (!isset($m[$clave]) || $e['vence'] > $m[$clave]['vence']) {
            if (isset($m[$clave]) && !$e['email']) $e['email'] = $m[$clave]['email'];
            $m[$clave] = $e;
        }
    };
    $equipo_de = adm_equipo_de_licencia($paneles);
    adm_bloque('tienda_claves', function () use ($c, $poner, $equipo_de) {
        $filas = adm_todos($c, "SELECT k.hardware_id, k.tipo, k.vence_pagado, c.stripe_suscripcion, c.email
                                FROM tienda_claves k JOIN tienda_compras c ON c.id = k.compra_id
                                WHERE c.stripe_sesion NOT LIKE 'cs\\_test\\_%'");
        foreach ($filas as $f) {
            $poner($f['hardware_id'] . '|' . $f['tipo'], [
                'lic' => $f['hardware_id'], 'equipo' => isset($equipo_de[$f['hardware_id']]) ? $equipo_de[$f['hardware_id']] : null,
                'tipo' => $f['tipo'], 'vence' => $f['vence_pagado'], 'fuente' => 'tienda',
                'mensual' => $f['stripe_suscripcion'] !== null, 'email' => $f['email'],
            ]);
        }
    });
    adm_bloque('tienda_reportados', function () use ($c, $poner, $equipo_de) {
        foreach (adm_todos($c, 'SELECT hardware_id, tipo, vence FROM tienda_reportados') as $f) {
            $poner($f['hardware_id'] . '|' . $f['tipo'], [
                'lic' => $f['hardware_id'], 'equipo' => isset($equipo_de[$f['hardware_id']]) ? $equipo_de[$f['hardware_id']] : null,
                'tipo' => $f['tipo'], 'vence' => $f['vence'], 'fuente' => 'a_mano', 'mensual' => false, 'email' => null,
            ]);
        }
    });
    // El panel solo sabe «Premium» (la clave de varias sucursales también lo abre) y su premium_hasta sale de las
    // mismas claves, con los días de gracia de la tienda: solo suma una clave que las otras dos fuentes no vieron
    // (una a mano en un programa 3.4.0, que todavía no las reporta), es decir, una que llega más lejos que la gracia.
    foreach ((array) $paneles as $id => $p) {
        if (!$p['premium_hasta']) continue;
        $lic = $p['hw_licencia'];
        $ya = null;
        foreach (['premium', 'multi'] as $t) {
            if ($lic && isset($m[$lic . '|' . $t]) && ($ya === null || $m[$lic . '|' . $t]['vence'] > $ya)) $ya = $m[$lic . '|' . $t]['vence'];
        }
        if ($ya !== null && $p['premium_hasta'] <= adm_dia(min($ya, '2199-01-01'), TIENDA_GRACIA_DIAS)) continue;
        $poner(($lic ? $lic : 'panel:' . $id) . '|premium', ['lic' => $lic, 'equipo' => $id, 'tipo' => 'premium', 'vence' => $p['premium_hasta'],
                                                            'fuente' => 'panel', 'mensual' => false, 'email' => null]);
    }
    return array_values($m);
}

// Cada pago real (sin el modo de prueba de Stripe) con su importe: el primero es lo que cobró Stripe en la sesión;
// una renovación no guarda importe y vale el precio de su plan.
function adm_pagos(PDO $c) {
    $planes = tienda_planes();
    $filas = adm_todos($c, "SELECT p.creado, p.stripe_ref, p.meses, c.id AS compra, c.stripe_sesion, c.monto, c.plan, c.tipo, c.origen
                            FROM tienda_pagos p JOIN tienda_compras c ON c.id = p.compra_id
                            WHERE c.stripe_sesion NOT LIKE 'cs\\_test\\_%' ORDER BY p.creado");
    $out = [];
    foreach ($filas as $f) {
        $renovacion = $f['stripe_ref'] !== $f['stripe_sesion'];
        $precio = isset($planes[$f['plan']]) ? $planes[$f['plan']]['precio'] : 0;
        $out[] = ['fecha' => $f['creado'], 'monto' => $renovacion ? $precio : (int) $f['monto'], 'renovacion' => $renovacion,
                  'plan' => $f['plan'], 'tipo' => $f['tipo'], 'origen' => $f['origen'], 'compra' => (int) $f['compra']];
    }
    return $out;
}

function adm_suma_pagos(array $pagos, $desde, $fin) {
    $r = ['ingresos' => 0, 'pagos' => 0, 'renovaciones' => 0, 'nuevas' => 0];
    foreach ($pagos as $p) {
        if ($p['fecha'] < $desde || $p['fecha'] >= $fin) continue;
        $r['ingresos'] += $p['monto'];
        $r['pagos']++;
        $r[$p['renovacion'] ? 'renovaciones' : 'nuevas']++;
    }
    return $r;
}

// Telemetría: solo filas con un ID real y sin los equipos que no cuentan
function adm_uso(PDO $c, array $ocultos) {
    return "uso_punto_venta WHERE hardware_id IS NOT NULL AND hardware_id NOT IN ('', 'UNKNOWN')"
         . adm_excluir($c, 'hardware_id', array_keys($ocultos));
}

// Una fila por computadora con lo que dice la telemetría (todas, también las ocultas: la lista las marca)
function adm_lista_equipos(PDO $c) {
    $filas = adm_todos($c, "SELECT hardware_id AS id, MIN(fecha_hora) AS primera, MAX(fecha_hora) AS ultima,
                COUNT(DISTINCT DATE(fecha_hora)) AS dias, SUM(tipo_evento = 'login') AS inicios,
                SUM(tipo_evento = 'cierre_turno') AS cortes, COUNT(DISTINCT usuario) AS usuarios,
                SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(negocio, '') ORDER BY fecha_hora DESC SEPARATOR '\n'), '\n', 1) AS negocio,
                SUBSTRING_INDEX(GROUP_CONCAT(CONCAT_WS(CHAR(31 USING utf8mb4), ciudad, pais) ORDER BY fecha_hora DESC SEPARATOR '\n'), '\n', 1) AS lugar
         FROM uso_punto_venta WHERE hardware_id IS NOT NULL AND hardware_id NOT IN ('', 'UNKNOWN')
         GROUP BY hardware_id ORDER BY ultima DESC LIMIT " . ADM_LISTA_MAX);
    foreach ($filas as &$f) {
        $lugar = explode(chr(31), (string) $f['lugar']);
        $f['ciudad'] = $lugar[0];
        $f['pais'] = isset($lugar[1]) ? $lugar[1] : '';
        unset($f['lugar']);
        foreach (['dias', 'inicios', 'cortes', 'usuarios'] as $k) $f[$k] = (int) $f[$k];
    }
    return $filas;
}

// La versión con que entró cada computadora la última vez: [ID => '3.7.0', o '' si ese evento no la trajo: los POS de
// hasta 3.6.0 no la mandan]. $donde es el FROM … WHERE de la consulta. Va en su propio bloque (uso_punto_venta.version_pos):
// sin la columna, lo demás de la página sigue.
function adm_versiones(PDO $c, $donde, array $par = []) {
    $m = [];
    foreach (adm_todos($c, "SELECT hardware_id AS id,
                SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(version_pos, '') ORDER BY fecha_hora DESC SEPARATOR '\n'), '\n', 1) AS v
                $donde GROUP BY hardware_id", $par) as $f) {
        $m[$f['id']] = (string) $f['v'];
    }
    return $m;
}

// La versión publicada (la cabecera de version.json, la que el POS ofrece al actualizar); null si no se puede leer
function adm_version_publicada() {
    $j = @json_decode((string) @file_get_contents(dirname(__DIR__) . '/version.json'), true);
    return is_array($j) && isset($j['version']) && is_string($j['version']) ? $j['version'] : null;
}

// ── Secciones ───────────────────────────────────────────────────────────────
function sec_resumen(PDO $c, $p) {
    $ocultos = adm_ocultos($c);
    $USO = adm_uso($c, $ocultos);
    $minimos = array_filter([
        adm_bloque('registros_descargas', function () use ($c) { return adm_uno($c, 'SELECT MIN(fecha_hora) FROM registros_descargas'); }),
        adm_bloque('uso_punto_venta', function () use ($c) { return adm_uno($c, 'SELECT MIN(fecha_hora) FROM uso_punto_venta'); }),
    ]);
    $minimo = $minimos ? min($minimos) : null;
    $P = adm_periodo($p, $minimo);
    $hoy = $P['hasta'];
    $r = ['periodo' => $P];

    $r['descargas'] = adm_bloque('registros_descargas', function () use ($c, $P) {
        $contar = function ($d, $f) use ($c) {
            return (int) adm_uno($c, 'SELECT COUNT(*) FROM registros_descargas WHERE fecha_hora >= :d AND fecha_hora < :f', [':d' => $d, ':f' => $f]);
        };
        $b = adm_bucket('fecha_hora', $P['gran']);
        $filas = adm_todos($c, "SELECT $b AS k, COUNT(*) AS n FROM registros_descargas WHERE fecha_hora >= :d AND fecha_hora < :f GROUP BY k",
                           [':d' => $P['desde'], ':f' => $P['fin']]);
        return ['actual' => $contar($P['desde'], $P['fin']),
                'anterior' => $P['antes_desde'] ? $contar($P['antes_desde'], $P['antes_fin']) : null,
                'serie' => adm_serie($filas, $P['claves'], 'n')];
    });

    $r['equipos'] = adm_bloque('uso_punto_venta', function () use ($c, $P, $USO, $hoy) {
        $activas = function ($d, $f) use ($c, $USO) {
            return (int) adm_uno($c, "SELECT COUNT(DISTINCT hardware_id) FROM $USO AND fecha_hora >= :d AND fecha_hora < :f", [':d' => $d, ':f' => $f]);
        };
        $primeras = adm_todos($c, "SELECT hardware_id, MIN(fecha_hora) AS primera, MAX(fecha_hora) AS ultima,
                                          COUNT(DISTINCT DATE(fecha_hora)) AS dias FROM $USO GROUP BY hardware_id");
        $nuevas = function ($d, $f) use ($primeras) {
            $n = 0;
            foreach ($primeras as $x) if ($x['primera'] >= $d && $x['primera'] < $f) $n++;
            return $n;
        };
        $b = adm_bucket('fecha_hora', $P['gran']);
        $serie_act = adm_todos($c, "SELECT $b AS k, COUNT(DISTINCT hardware_id) AS n FROM $USO AND fecha_hora >= :d AND fecha_hora < :f GROUP BY k",
                               [':d' => $P['desde'], ':f' => $P['fin']]);
        $porNueva = [];
        foreach ($primeras as $x) {
            if ($x['primera'] < $P['desde']) continue;
            $k = adm_inicio_bucket(substr($x['primera'], 0, 10), $P['gran']);
            $porNueva[$k] = (isset($porNueva[$k]) ? $porNueva[$k] : 0) + 1;
        }
        $serie_new = [];
        foreach ($P['claves'] as $k) $serie_new[] = isset($porNueva[$k]) ? $porNueva[$k] : 0;
        // Se fueron: lo usaron 3 días o más y llevan de 30 a 60 días sin abrirlo
        $se_fueron = 0;
        foreach ($primeras as $x) {
            $u = substr($x['ultima'], 0, 10);
            if ((int) $x['dias'] >= 3 && $u <= adm_dia($hoy, -30) && $u > adm_dia($hoy, -60)) $se_fueron++;
        }
        return [
            'activas' => $activas($P['desde'], $P['fin']),
            'activas_antes' => $P['antes_desde'] ? $activas($P['antes_desde'], $P['antes_fin']) : null,
            'nuevas' => $nuevas($P['desde'], $P['fin']),
            'nuevas_antes' => $P['antes_desde'] ? $nuevas($P['antes_desde'], $P['antes_fin']) : null,
            'hoy' => $activas($hoy, $P['fin']),
            'semana' => $activas(adm_dia($hoy, -6), $P['fin']),
            'mes' => $activas(adm_dia($hoy, -29), $P['fin']),
            'total' => count($primeras),
            'se_fueron' => $se_fueron,
            'serie_activas' => adm_serie($serie_act, $P['claves'], 'n'),
            'serie_nuevas' => $serie_new,
            '_primeras' => $primeras,
        ];
    });

    $paneles = adm_paneles($c);
    $licencias = adm_licencias($c, $paneles);

    // El camino de las computadoras nuevas del periodo
    if ($r['equipos'] !== null) {
        $tuvo = [];
        foreach ($licencias as $l) {
            if ($l['equipo']) $tuvo[$l['equipo']] = true;
        }
        $e = ['descargas' => $r['descargas'] ? $r['descargas']['actual'] : null, 'nuevas' => 0, 'volvieron' => 0,
              'siguen' => 0, 'panel' => $paneles === null ? null : 0, 'premium' => 0];
        foreach ($r['equipos']['_primeras'] as $x) {
            if ($x['primera'] < $P['desde']) continue;
            $e['nuevas']++;
            if ((int) $x['dias'] >= 2) $e['volvieron']++;
            if (substr($x['ultima'], 0, 10) >= adm_dia($hoy, -6)) $e['siguen']++;
            if ($paneles !== null && isset($paneles[$x['hardware_id']])) $e['panel']++;
            if (isset($tuvo[$x['hardware_id']])) $e['premium']++;
        }
        $r['embudo'] = $e;
        unset($r['equipos']['_primeras']);
    }

    if ($paneles !== null) {
        $tiendas = ['total' => 0, 'hoy' => 0];
        foreach ($paneles as $id => $x) {
            if (isset($ocultos[$id])) continue;
            $tiendas['total']++;
            if (strtotime($x['ultima_sync']) >= time() - 86400) $tiendas['hoy']++;
        }
        $r['tiendas'] = $tiendas;
    }

    $vigentes = ['premium' => 0, 'multi' => 0];
    foreach ($licencias as $l) if ($l['vence'] >= $hoy) $vigentes[$l['tipo'] === 'multi' ? 'multi' : 'premium']++;
    $r['premium'] = $vigentes;

    $r['ventas'] = adm_bloque('tienda_pagos', function () use ($c, $P) {
        $pagos = adm_pagos($c);
        return ['actual' => adm_suma_pagos($pagos, $P['desde'], $P['fin']),
                'anterior' => $P['antes_desde'] ? adm_suma_pagos($pagos, $P['antes_desde'], $P['antes_fin']) : null];
    });

    // Lo que pide atención
    $pend = [];
    $pend['comentarios'] = adm_bloque('comentarios_sugerencias', function () use ($c) {
        return (int) adm_uno($c, 'SELECT COUNT(*) FROM comentarios_sugerencias WHERE nombre IS NULL AND fecha_hora >= NOW() - INTERVAL 7 DAY');
    });
    $pend['oxxo'] = adm_bloque('tienda_compras', function () use ($c) {
        return (int) adm_uno($c, "SELECT COUNT(*) FROM tienda_compras WHERE estado = 'pendiente' AND stripe_sesion NOT LIKE 'cs\\_test\\_%'");
    });
    $vencen = 0;
    foreach ($licencias as $l) {
        if ($l['vence'] >= $hoy && $l['vence'] <= adm_dia($hoy, 14) && !$l['mensual']) $vencen++;
    }
    $pend['vencen'] = $vencen;
    $pend['bloqueados'] = adm_bloque('dashboard_pos.pin_bloqueo_hasta', function () use ($c) {
        return (int) adm_uno($c, 'SELECT COUNT(*) FROM dashboard_pos WHERE pin_bloqueo_hasta > NOW()');
    });
    $pend['stripe'] = adm_bloque('tienda_eventos', function () use ($c) {
        return adm_uno($c, 'SELECT MAX(recibido) FROM tienda_eventos') ?: null;
    });
    $pend['se_fueron'] = $r['equipos'] ? $r['equipos']['se_fueron'] : null;
    // Quien pidió el link en /gratis y todavía no le escribes (ni pidió BAJA)
    $pend['registros'] = adm_bloque('registros_gratis.seguimiento', function () use ($c) {
        return (int) adm_uno($c, 'SELECT COUNT(*) FROM registros_gratis WHERE contactado_en IS NULL AND baja_en IS NULL');
    });
    $r['pendientes'] = $pend;
    $r['ocultos'] = count($ocultos);
    return $r;
}

function sec_descargas(PDO $c, $p) {
    $minimo = adm_bloque('registros_descargas', function () use ($c) {
        return adm_uno($c, 'SELECT MIN(fecha_hora) FROM registros_descargas');
    });
    $P = adm_periodo($p, $minimo);
    $r = ['periodo' => $P];
    $W = 'FROM registros_descargas WHERE fecha_hora >= :d AND fecha_hora < :f';
    $par = [':d' => $P['desde'], ':f' => $P['fin']];
    $r['datos'] = adm_bloque('registros_descargas', function () use ($c, $P, $W, $par) {
        $contar = function ($d, $f) use ($c) {
            return adm_todos($c, 'SELECT COUNT(*) AS n, COUNT(DISTINCT ip_address) AS ips FROM registros_descargas
                                  WHERE fecha_hora >= :d AND fecha_hora < :f', [':d' => $d, ':f' => $f])[0];
        };
        $act = $contar($P['desde'], $P['fin']);
        $ant = $P['antes_desde'] ? $contar($P['antes_desde'], $P['antes_fin']) : null;
        $b = adm_bucket('fecha_hora', $P['gran']);
        $serie = adm_todos($c, "SELECT $b AS k, COUNT(*) AS n $W GROUP BY k", $par);
        $paises = adm_todos($c, "SELECT COALESCE(NULLIF(pais, ''), 'Desconocido') AS nombre, COUNT(*) AS n $W GROUP BY nombre ORDER BY n DESC", $par);
        $ciudades = adm_todos($c, "SELECT COALESCE(NULLIF(ciudad, ''), 'Desconocida') AS ciudad, COALESCE(pais, '') AS pais, COUNT(*) AS n
                                   $W GROUP BY ciudad, pais ORDER BY n DESC LIMIT 25", $par);
        $horas = adm_todos($c, "SELECT HOUR(fecha_hora) AS k, COUNT(*) AS n $W GROUP BY k", $par);
        $semana = adm_todos($c, "SELECT WEEKDAY(fecha_hora) AS k, COUNT(*) AS n $W GROUP BY k", $par);
        $repetidas = adm_todos($c, "SELECT COUNT(*) AS ips, COALESCE(SUM(n), 0) AS descargas FROM
                                    (SELECT ip_address, COUNT(*) AS n $W GROUP BY ip_address HAVING n > 1) t", $par)[0];
        $ultimas = adm_todos($c, 'SELECT fecha_hora AS fecha, ciudad, pais FROM registros_descargas ORDER BY fecha_hora DESC LIMIT 40');
        $porHora = array_fill(0, 24, 0);
        foreach ($horas as $h) $porHora[(int) $h['k']] = (int) $h['n'];
        $porDia = array_fill(0, 7, 0);
        foreach ($semana as $h) $porDia[(int) $h['k']] = (int) $h['n'];
        return [
            'actual' => (int) $act['n'], 'ips' => (int) $act['ips'], 'anterior' => $ant ? (int) $ant['n'] : null,
            'total' => (int) adm_uno($c, 'SELECT COUNT(*) FROM registros_descargas'),
            'serie' => adm_serie($serie, $P['claves'], 'n'),
            'paises' => $paises, 'ciudades' => $ciudades, 'horas' => $porHora, 'semana' => $porDia,
            'repetidas' => ['ips' => (int) $repetidas['ips'], 'descargas' => (int) $repetidas['descargas']],
            'ultimas' => $ultimas,
        ];
    });
    // Computadoras que aparecieron en el mismo periodo (para «de cada 100 descargas…»)
    $ocultos = adm_ocultos($c);
    $USO = adm_uso($c, $ocultos);
    $r['nuevas'] = adm_bloque('uso_punto_venta', function () use ($c, $P, $USO) {
        return (int) adm_uno($c, "SELECT COUNT(*) FROM (SELECT MIN(fecha_hora) AS f FROM $USO GROUP BY hardware_id) t WHERE f >= :d AND f < :f",
                             [':d' => $P['desde'], ':f' => $P['fin']]);
    });
    return $r;
}

// Quien dejó su WhatsApp en /gratis (los anuncios de TikTok): cuántos, cuántos descargaron, de qué giro, y la lista
// para darles seguimiento. La lista es de siempre (no del periodo): lo pendiente no caduca. Sin las columnas del
// seguimiento (contactado_en, baja_en: la segunda versión de la migración) la lista sale igual, 'seguimiento' = false y
// 'registros_gratis.seguimiento' va en faltan: la página no enseña los botones que las escriben.
function sec_registros(PDO $c, $p) {
    $minimo = adm_bloque('registros_gratis', function () use ($c) {
        return adm_uno($c, 'SELECT MIN(creado) FROM registros_gratis');
    });
    $P = adm_periodo($p, $minimo);
    $r = ['periodo' => $P, 'giros' => GRATIS_GIROS];
    $W = 'FROM registros_gratis WHERE creado >= :d AND creado < :f';
    $par = [':d' => $P['desde'], ':f' => $P['fin']];
    $r['datos'] = adm_bloque('registros_gratis', function () use ($c, $P, $W, $par) {
        $contar = function ($d, $f) use ($c) {
            return adm_todos($c, 'SELECT COUNT(*) AS n, COUNT(descargo_en) AS descargaron, COALESCE(SUM(ttclid IS NOT NULL), 0) AS de_anuncio
                                  FROM registros_gratis WHERE creado >= :d AND creado < :f', [':d' => $d, ':f' => $f])[0];
        };
        $act = $contar($P['desde'], $P['fin']);
        $ant = $P['antes_desde'] ? $contar($P['antes_desde'], $P['antes_fin']) : null;
        $b = adm_bucket('creado', $P['gran']);
        $serie = adm_todos($c, "SELECT $b AS k, COUNT(*) AS n, COUNT(descargo_en) AS descargaron $W GROUP BY k", $par);
        // Cuánto tardan en descargar desde que se registran: dice cuándo conviene escribirles
        $tramos = [0, 0, 0, 0];   // menos de 1 h · 1 a 24 h · 1 a 3 días · más de 3 días
        foreach (adm_todos($c, "SELECT TIMESTAMPDIFF(MINUTE, creado, descargo_en) AS m $W AND descargo_en IS NOT NULL", $par) as $f) {
            $m = (int) $f['m'];
            $tramos[$m < 60 ? 0 : ($m < 1440 ? 1 : ($m < 4320 ? 2 : 3))]++;
        }
        return [
            'actual' => (int) $act['n'], 'anterior' => $ant ? (int) $ant['n'] : null,
            'descargaron' => (int) $act['descargaron'], 'de_anuncio' => (int) $act['de_anuncio'],
            'total' => (int) adm_uno($c, 'SELECT COUNT(*) FROM registros_gratis'),
            'serie' => adm_serie($serie, $P['claves'], 'n'),
            'serie_descargaron' => adm_serie($serie, $P['claves'], 'descargaron'),
            'giros' => adm_todos($c, "SELECT giro, COUNT(*) AS n, COUNT(descargo_en) AS descargaron $W GROUP BY giro ORDER BY n DESC, giro", $par),
            'tardanza' => $tramos,
        ];
    });
    $seguimiento = true;
    $r['lista'] = adm_bloque('registros_gratis', function () use ($c, &$seguimiento) {
        global $FALTAN, $ERRORES;
        $cols = 'id, codigo, telefono, nombre, giro, ttclid IS NOT NULL AS de_anuncio, creado, descargo_en, sistema';
        try {
            $filas = adm_todos($c, "SELECT $cols, contactado_en, baja_en FROM registros_gratis ORDER BY creado DESC, id DESC LIMIT " . ADM_LISTA_MAX);
        } catch (PDOException $e) {
            // La tabla de la primera versión de la migración: sin el seguimiento
            $seguimiento = false;
            $FALTAN[] = 'registros_gratis.seguimiento';
            $ERRORES['registros_gratis.seguimiento'] = $e->getMessage();
            $filas = adm_todos($c, "SELECT $cols, NULL AS contactado_en, NULL AS baja_en FROM registros_gratis ORDER BY creado DESC, id DESC LIMIT " . ADM_LISTA_MAX);
        }
        foreach ($filas as &$f) {
            $f['id'] = (int) $f['id'];
            $f['de_anuncio'] = (bool) $f['de_anuncio'];
            $f['link'] = gratis_link($f['codigo']);
        }
        return $filas;
    });
    $r['seguimiento'] = $r['lista'] !== null && $seguimiento;
    return $r;
}

function sec_computadoras(PDO $c, $p) {
    $ocultos = adm_ocultos($c);
    $USO = adm_uso($c, $ocultos);
    $minimo = adm_bloque('uso_punto_venta', function () use ($c) {
        return adm_uno($c, 'SELECT MIN(fecha_hora) FROM uso_punto_venta');
    });
    $P = adm_periodo($p, $minimo);
    $r = ['periodo' => $P];
    $par = [':d' => $P['desde'], ':f' => $P['fin']];
    $EN = "FROM $USO AND fecha_hora >= :d AND fecha_hora < :f";

    $r['uso'] = adm_bloque('uso_punto_venta', function () use ($c, $P, $USO, $EN, $par) {
        $activas = function ($d, $f) use ($c, $USO) {
            return (int) adm_uno($c, "SELECT COUNT(DISTINCT hardware_id) FROM $USO AND fecha_hora >= :d AND fecha_hora < :f", [':d' => $d, ':f' => $f]);
        };
        $nuevas = function ($d, $f) use ($c, $USO) {
            return (int) adm_uno($c, "SELECT COUNT(*) FROM (SELECT MIN(fecha_hora) AS p FROM $USO GROUP BY hardware_id) t WHERE p >= :d AND p < :f",
                                 [':d' => $d, ':f' => $f]);
        };
        $b = adm_bucket('fecha_hora', $P['gran']);
        $serie_act = adm_todos($c, "SELECT $b AS k, COUNT(DISTINCT hardware_id) AS n $EN GROUP BY k", $par);
        $bp = adm_bucket('p', $P['gran']);
        $serie_new = adm_todos($c, "SELECT $bp AS k, COUNT(*) AS n FROM (SELECT MIN(fecha_hora) AS p FROM $USO GROUP BY hardware_id) t
                                    WHERE p >= :d AND p < :f GROUP BY k", $par);
        $eventos = adm_todos($c, "SELECT tipo_evento AS nombre, COUNT(*) AS n $EN GROUP BY tipo_evento", $par);
        $sesiones = adm_todos($c, "SELECT COALESCE(tipo_sesion, '') AS nombre, COUNT(*) AS n $EN AND tipo_evento = 'login' GROUP BY nombre ORDER BY n DESC", $par);
        $roles = adm_todos($c, "SELECT COALESCE(rol, '') AS nombre, COUNT(*) AS n, COUNT(DISTINCT hardware_id, usuario) AS personas
                                $EN AND tipo_evento = 'login' GROUP BY nombre ORDER BY n DESC", $par);
        $horas = adm_todos($c, "SELECT WEEKDAY(fecha_hora) AS d, HOUR(fecha_hora) AS h, COUNT(*) AS n $EN AND tipo_evento = 'login' GROUP BY d, h", $par);
        $mapa = [];
        for ($d = 0; $d < 7; $d++) $mapa[] = array_fill(0, 24, 0);
        foreach ($horas as $h) $mapa[(int) $h['d']][(int) $h['h']] = (int) $h['n'];
        return [
            'activas' => $activas($P['desde'], $P['fin']),
            'activas_antes' => $P['antes_desde'] ? $activas($P['antes_desde'], $P['antes_fin']) : null,
            'nuevas' => $nuevas($P['desde'], $P['fin']),
            'nuevas_antes' => $P['antes_desde'] ? $nuevas($P['antes_desde'], $P['antes_fin']) : null,
            'serie_activas' => adm_serie($serie_act, $P['claves'], 'n'),
            'serie_nuevas' => adm_serie($serie_new, $P['claves'], 'n'),
            'eventos' => $eventos, 'sesiones' => $sesiones, 'roles' => $roles, 'horas' => $mapa,
        ];
    });

    // ¿Siguen usándolo? Por mes en que empezó cada computadora: qué parte lo usó cada mes después
    $r['cohortes'] = adm_bloque('uso_punto_venta', function () use ($c, $USO) {
        $filas = adm_todos($c, "SELECT hardware_id, DATE_FORMAT(fecha_hora, '%Y-%m') AS m FROM $USO GROUP BY hardware_id, m");
        $meses = [];
        foreach ($filas as $f) $meses[$f['hardware_id']][] = $f['m'];
        $indice = function ($m) { return (int) substr($m, 0, 4) * 12 + (int) substr($m, 5, 2) - 1; };
        $hoy = $indice(date('Y-m'));
        $cohortes = [];
        foreach ($meses as $lista) {
            sort($lista);
            $inicio = $lista[0];
            if ($hoy - $indice($inicio) > 11) continue;   // los últimos 12 meses
            if (!isset($cohortes[$inicio])) $cohortes[$inicio] = ['mes' => $inicio, 'total' => 0, 'despues' => array_fill(0, $hoy - $indice($inicio) + 1, 0)];
            $cohortes[$inicio]['total']++;
            foreach ($lista as $m) $cohortes[$inicio]['despues'][$indice($m) - $indice($inicio)]++;
        }
        ksort($cohortes);
        return array_values($cohortes);
    });

    // En qué versión están: las que lo usaron en el periodo, por la versión con que entraron la última vez
    $r['versiones'] = adm_bloque('uso_punto_venta.version_pos', function () use ($c, $EN, $par) {
        $cuenta = [];
        foreach (adm_versiones($c, $EN, $par) as $v) $cuenta[$v] = (isset($cuenta[$v]) ? $cuenta[$v] : 0) + 1;
        $filas = [];
        foreach ($cuenta as $v => $n) $filas[] = ['version' => (string) $v, 'n' => $n];
        // De la más nueva a la más vieja; las que no la mandan («3.6.0 o anterior») al final
        usort($filas, function ($a, $b) {
            if ($a['version'] === '' || $b['version'] === '') return $a['version'] === '' ? 1 : -1;
            return version_compare($b['version'], $a['version']);
        });
        return ['filas' => $filas, 'publicada' => adm_version_publicada()];
    });

    $paneles = adm_paneles($c);
    $licencias = adm_licencias($c, $paneles);
    $premium = [];
    foreach ($licencias as $l) {
        if ($l['equipo'] && (!isset($premium[$l['equipo']]) || $l['vence'] > $premium[$l['equipo']])) $premium[$l['equipo']] = $l['vence'];
    }
    $versiones = adm_bloque('uso_punto_venta.version_pos', function () use ($c) {
        return adm_versiones($c, "FROM uso_punto_venta WHERE hardware_id IS NOT NULL AND hardware_id NOT IN ('', 'UNKNOWN')");
    });
    $r['lista'] = adm_bloque('uso_punto_venta', function () use ($c, $paneles, $premium, $ocultos, $versiones) {
        $lista = adm_lista_equipos($c);
        foreach ($lista as &$f) {
            $id = $f['id'];
            $f['panel'] = $paneles !== null && isset($paneles[$id]);
            $f['sync'] = $f['panel'] ? $paneles[$id]['ultima_sync'] : null;
            if ($f['panel'] && !$f['negocio']) $f['negocio'] = $paneles[$id]['negocio'];
            $f['premium'] = isset($premium[$id]) ? $premium[$id] : null;
            $f['oculto'] = isset($ocultos[$id]);
            // null = no se sabe (falta la columna); '' = la mandó un POS de hasta 3.6.0
            $f['version'] = $versiones === null ? null : (isset($versiones[$id]) ? $versiones[$id] : '');
        }
        return $lista;
    });
    return $r;
}

function sec_tiendas(PDO $c, $p) {
    $ocultos = adm_ocultos($c);
    $minimo = adm_bloque('dashboard_pos_historico', function () use ($c) {
        return adm_uno($c, 'SELECT MIN(fecha) FROM dashboard_pos_historico');
    });
    $P = adm_periodo($p, $minimo);
    $r = ['periodo' => $P];

    $tiendas = adm_bloque('dashboard_pos', function () use ($c) {
        return adm_todos($c, 'SELECT hardware_id AS id, negocio, ultima_sync, ventas_hoy, abonos_hoy, tickets_hoy, ganancia_hoy,
                                     turno_abierto, turno_usuario, turno_inicio FROM dashboard_pos ORDER BY ultima_sync DESC');
    });
    if ($tiendas === null) return $r;
    $extra = adm_bloque('dashboard_pos.extra_json', function () use ($c) {
        $m = [];
        foreach (adm_todos($c, 'SELECT hardware_id, extra_json, pin_hash IS NOT NULL AS protegido FROM dashboard_pos') as $f) {
            $e = $f['extra_json'] ? json_decode($f['extra_json'], true) : null;
            $m[$f['hardware_id']] = ['e' => is_array($e) ? $e : null, 'protegido' => (bool) $f['protegido']];
        }
        return $m;
    });
    $paneles = adm_paneles($c);
    $licencias = adm_licencias($c, $paneles);
    $premium = [];
    foreach ($licencias as $l) {
        if ($l['equipo'] && (!isset($premium[$l['equipo']]) || $l['vence'] > $premium[$l['equipo']])) $premium[$l['equipo']] = $l['vence'];
    }

    // Lo de cada tienda en el periodo
    $hist = adm_bloque('dashboard_pos_historico', function () use ($c, $P) {
        $m = [];
        $filas = adm_todos($c, 'SELECT hardware_id, SUM(ventas_centavos) AS ventas, SUM(ganancia_centavos) AS ganancia, SUM(tickets) AS tickets,
                                       SUM(tickets > 0) AS dias FROM dashboard_pos_historico WHERE fecha >= :d AND fecha <= :h GROUP BY hardware_id',
                           [':d' => $P['desde'], ':h' => $P['hasta']]);
        foreach ($filas as $f) $m[$f['hardware_id']] = $f;
        return $m;
    });
    $desglose = adm_bloque('dashboard_pos_historico.desglose', function () use ($c, $P) {
        $m = [];
        $filas = adm_todos($c, 'SELECT hardware_id, SUM(tarjeta_centavos) AS tarjeta, SUM(transferencia_centavos) AS transferencia
                                FROM dashboard_pos_historico WHERE fecha >= :d AND fecha <= :h GROUP BY hardware_id',
                           [':d' => $P['desde'], ':h' => $P['hasta']]);
        foreach ($filas as $f) $m[$f['hardware_id']] = $f;
        return $m;
    });

    $lista = [];
    $euros = [];
    foreach ($tiendas as $t) {
        $id = $t['id'];
        $e = $extra && isset($extra[$id]) ? $extra[$id]['e'] : null;
        $moneda = $e && isset($e['moneda']) && $e['moneda'] === 'EUR' ? 'EUR' : 'MXN';
        if ($moneda === 'EUR') $euros[] = $id;
        $h = $hist && isset($hist[$id]) ? $hist[$id] : null;
        $lista[] = [
            'id' => $id, 'negocio' => $t['negocio'], 'sync' => $t['ultima_sync'], 'moneda' => $moneda,
            'hoy' => (int) $t['ventas_hoy'] + (int) $t['abonos_hoy'], 'tickets_hoy' => (int) $t['tickets_hoy'],
            'turno' => (int) $t['turno_abierto'] === 1 ? $t['turno_usuario'] : null,
            'ventas' => $h ? (int) $h['ventas'] : 0, 'ganancia' => $h ? (int) $h['ganancia'] : 0,
            'tickets' => $h ? (int) $h['tickets'] : 0, 'dias' => $h ? (int) $h['dias'] : 0,
            'premium' => isset($premium[$id]) ? $premium[$id] : null,
            'protegido' => $extra && isset($extra[$id]) ? $extra[$id]['protegido'] : null,
            'oculto' => isset($ocultos[$id]),
        ];
    }
    $r['lista'] = $lista;

    // Las sumas, solo en pesos: los importes de una tienda en euros no se pueden sumar con los demás
    $fuera = array_unique(array_merge(array_keys($ocultos), $euros));
    $X = adm_excluir($c, 'hardware_id', $fuera);
    $r['euros'] = count(array_diff($euros, array_keys($ocultos)));
    $r['total'] = adm_bloque('dashboard_pos_historico', function () use ($c, $P, $X) {
        $suma = function ($d, $h) use ($c, $X) {
            $f = adm_todos($c, "SELECT COALESCE(SUM(ventas_centavos), 0) AS ventas, COALESCE(SUM(ganancia_centavos), 0) AS ganancia,
                                       COALESCE(SUM(tickets), 0) AS tickets, COUNT(DISTINCT CASE WHEN tickets > 0 THEN hardware_id END) AS tiendas
                                FROM dashboard_pos_historico WHERE fecha >= :d AND fecha <= :h $X", [':d' => $d, ':h' => $h])[0];
            return array_map('intval', $f);
        };
        $b = adm_bucket('fecha', $P['gran']);
        $serie = adm_todos($c, "SELECT $b AS k, SUM(ventas_centavos) AS ventas, SUM(tickets) AS tickets FROM dashboard_pos_historico
                                WHERE fecha >= :d AND fecha <= :h $X GROUP BY k", [':d' => $P['desde'], ':h' => $P['hasta']]);
        return ['actual' => $suma($P['desde'], $P['hasta']),
                'anterior' => $P['antes_desde'] ? $suma($P['antes_desde'], adm_dia($P['desde'], -1)) : null,
                'serie_ventas' => adm_serie($serie, $P['claves'], 'ventas'),
                'serie_tickets' => adm_serie($serie, $P['claves'], 'tickets')];
    });
    // Cómo cobran (días desde la 3.3.0 del POS: los de antes no traen desglose y no cuentan como ceros)
    $r['pagos'] = adm_bloque('dashboard_pos_historico.desglose', function () use ($c, $P, $X) {
        $f = adm_todos($c, "SELECT SUM(efectivo_centavos) AS efectivo, SUM(tarjeta_centavos) AS tarjeta, SUM(transferencia_centavos) AS transferencia,
                                   SUM(comision_centavos) AS comision, SUM(cancelaciones) AS cancelaciones, SUM(cancelado_centavos) AS cancelado,
                                   COUNT(efectivo_centavos) AS dias
                            FROM dashboard_pos_historico WHERE fecha >= :d AND fecha <= :h $X", [':d' => $P['desde'], ':h' => $P['hasta']])[0];
        return (int) $f['dias'] === 0 ? null : array_map('intval', $f);
    });
    // Ventas por hora: el detalle se guarda 120 días
    $r['horas'] = adm_bloque('dashboard_pos_ventas', function () use ($c, $P, $X) {
        $desde = max($P['desde'], adm_dia($P['hasta'], -119));
        $filas = adm_todos($c, "SELECT hora AS k, COUNT(*) AS n, SUM(total_centavos) AS v FROM dashboard_pos_ventas
                                WHERE fecha >= :d AND fecha <= :h $X GROUP BY hora", [':d' => $desde, ':h' => $P['hasta']]);
        $n = array_fill(0, 24, 0);
        $v = array_fill(0, 24, 0);
        foreach ($filas as $f) {
            $n[(int) $f['k']] = (int) $f['n'];
            $v[(int) $f['k']] = (int) $f['v'];
        }
        return ['desde' => $desde, 'tickets' => $n, 'ventas' => $v];
    });

    // Qué funciones del programa usan (de las tiendas que mandan datos, sin las ocultas)
    if ($extra !== null) {
        $f = ['tiendas' => 0, 'tarjeta' => 0, 'transferencia' => 0, 'inventario' => 0, 'apartados' => 0, 'presupuestos' => 0,
              'gastos' => 0, 'meta' => 0, 'pin' => 0, 'euros' => 0, 'desglose' => $desglose !== null];
        foreach ($tiendas as $t) {
            $id = $t['id'];
            if (isset($ocultos[$id])) continue;
            $f['tiendas']++;
            $e = isset($extra[$id]) ? $extra[$id]['e'] : null;
            $d = $desglose && isset($desglose[$id]) ? $desglose[$id] : null;
            if ($d && (int) $d['tarjeta'] > 0) $f['tarjeta']++;
            if ($d && (int) $d['transferencia'] > 0) $f['transferencia']++;
            if ($e && isset($e['inventario']['valor_costo']) && $e['inventario']['valor_costo'] > 0) $f['inventario']++;
            if ($e && isset($e['apartados']) && ($e['apartados']['activos'] > 0 || $e['apartados']['vencidos'] > 0)) $f['apartados']++;
            if ($e && isset($e['presupuestos']) && ($e['presupuestos']['vigentes'] + $e['presupuestos']['por_vencer'] + $e['presupuestos']['vencidos']) > 0) $f['presupuestos']++;
            if ($e && isset($e['gastos']['mes']) && $e['gastos']['mes'] > 0) $f['gastos']++;
            if ($e && isset($e['meta_diaria']) && $e['meta_diaria'] > 0) $f['meta']++;
            if (isset($extra[$id]) && $extra[$id]['protegido']) $f['pin']++;
            if ($e && isset($e['moneda']) && $e['moneda'] === 'EUR') $f['euros']++;
        }
        $r['funciones'] = $f;
    }
    return $r;
}

function sec_premium(PDO $c, $p) {
    $minimo = adm_bloque('tienda_compras', function () use ($c) {
        return adm_uno($c, 'SELECT MIN(creada) FROM tienda_compras');
    });
    $P = adm_periodo($p, $minimo);
    $hoy = $P['hasta'];
    $r = ['periodo' => $P];
    $paneles = adm_paneles($c);
    $equipo_de = adm_equipo_de_licencia($paneles);
    $planes = tienda_planes();
    $r['planes'] = [];
    foreach ($planes as $id => $pl) $r['planes'][$id] = ['nombre' => $pl['nombre'], 'tipo' => $pl['tipo'], 'precio' => $pl['precio']];

    $r['ventas'] = adm_bloque('tienda_pagos', function () use ($c, $P) {
        $pagos = adm_pagos($c);
        $serie = ['premium' => array_fill(0, count($P['claves']), 0), 'multi' => array_fill(0, count($P['claves']), 0)];
        $pos = array_flip($P['claves']);
        $porPlan = [];
        foreach ($pagos as $x) {
            if ($x['fecha'] < $P['desde'] || $x['fecha'] >= $P['fin']) continue;
            $k = adm_inicio_bucket(substr($x['fecha'], 0, 10), $P['gran']);
            if (isset($pos[$k])) $serie[$x['tipo'] === 'multi' ? 'multi' : 'premium'][$pos[$k]] += $x['monto'];
            if (!isset($porPlan[$x['plan']])) $porPlan[$x['plan']] = ['plan' => $x['plan'], 'pagos' => 0, 'ingresos' => 0];
            $porPlan[$x['plan']]['pagos']++;
            $porPlan[$x['plan']]['ingresos'] += $x['monto'];
        }
        usort($porPlan, function ($a, $b) { return $b['ingresos'] - $a['ingresos']; });
        return ['actual' => adm_suma_pagos($pagos, $P['desde'], $P['fin']),
                'anterior' => $P['antes_desde'] ? adm_suma_pagos($pagos, $P['antes_desde'], $P['antes_fin']) : null,
                'total' => adm_suma_pagos($pagos, ADM_TODOS_ANTES, $P['fin']),
                'serie' => $serie, 'por_plan' => array_values($porPlan)];
    });

    $r['compras'] = adm_bloque('tienda_compras', function () use ($c, $planes, $equipo_de) {
        $filas = adm_todos($c, "SELECT id, codigo, plan, tipo, hardware_id, origen, estado, email, monto, creada, pagada,
                                       stripe_sesion, stripe_suscripcion IS NOT NULL AS suscripcion
                                FROM tienda_compras ORDER BY creada DESC, id DESC LIMIT 1000");
        $cobros = adm_bloque('tienda_pagos', function () use ($c) {
            $m = [];
            foreach (adm_todos($c, 'SELECT compra_id, COUNT(*) AS n FROM tienda_pagos GROUP BY compra_id') as $f) $m[$f['compra_id']] = (int) $f['n'];
            return $m;
        });
        $out = [];
        foreach ($filas as $f) {
            $out[] = [
                'id' => (int) $f['id'], 'codigo' => tienda_codigo_mostrar($f['codigo']), 'plan' => $f['plan'],
                'plan_nombre' => isset($planes[$f['plan']]) ? $planes[$f['plan']]['nombre'] : $f['plan'], 'tipo' => $f['tipo'],
                'lic' => $f['hardware_id'], 'equipo' => $f['hardware_id'] && isset($equipo_de[$f['hardware_id']]) ? $equipo_de[$f['hardware_id']] : null,
                'origen' => $f['origen'], 'estado' => $f['estado'], 'email' => $f['email'], 'monto' => (int) $f['monto'],
                'creada' => $f['creada'], 'pagada' => $f['pagada'], 'prueba' => tienda_es_prueba($f),
                'suscripcion' => (bool) $f['suscripcion'],
                'pagos' => $cobros === null ? null : (isset($cobros[$f['id']]) ? $cobros[$f['id']] : 0),
            ];
        }
        return $out;
    });

    $licencias = adm_licencias($c, $paneles);
    foreach ($licencias as &$l) {
        $l['negocio'] = $l['equipo'] && $paneles && isset($paneles[$l['equipo']]) ? $paneles[$l['equipo']]['negocio'] : null;
    }
    unset($l);
    $r['licencias'] = $licencias;

    // La prueba gratis de Excel, contada por PC (con el ID de la licencia)
    $r['prueba'] = adm_bloque('pruebas_equipo', function () use ($c, $licencias, $P) {
        $tiene = [];
        foreach ($licencias as $l) if ($l['lic']) $tiene[$l['lic']] = true;
        $f = ['equipos' => 0, 'usaron' => 0, 'agotaron' => 0, 'agotaron_compraron' => 0, 'usaron_compraron' => 0, 'nuevos' => 0];
        foreach (adm_todos($c, 'SELECT equipo, primera_vez, exportar_usados, importar_usados FROM pruebas_equipo') as $x) {
            $f['equipos']++;
            if ($x['primera_vez'] >= $P['desde']) $f['nuevos']++;
            $usados = (int) $x['exportar_usados'] + (int) $x['importar_usados'];
            if ($usados === 0) continue;
            $f['usaron']++;
            if (isset($tiene[$x['equipo']])) $f['usaron_compraron']++;
            if ((int) $x['exportar_usados'] >= DASH_PRUEBA_INTENTOS || (int) $x['importar_usados'] >= DASH_PRUEBA_INTENTOS) {
                $f['agotaron']++;
                if (isset($tiene[$x['equipo']])) $f['agotaron_compraron']++;
            }
        }
        return $f;
    });

    $r['stripe'] = adm_bloque('tienda_eventos', function () use ($c) {
        return ['ultimo' => adm_uno($c, 'SELECT MAX(recibido) FROM tienda_eventos') ?: null,
                'tipos' => adm_todos($c, 'SELECT tipo, COUNT(*) AS n FROM tienda_eventos WHERE recibido >= NOW() - INTERVAL 30 DAY GROUP BY tipo ORDER BY n DESC')];
    });
    $r['canjes_fallidos'] = adm_bloque('tienda_intentos', function () use ($c) {
        return (int) adm_uno($c, 'SELECT COALESCE(SUM(fallos), 0) FROM tienda_intentos WHERE hora >= :h', [':h' => date('Y-m-d H', time() - 7 * 86400)]);
    });
    return $r;
}

function sec_comentarios(PDO $c, $p) {
    $minimo = adm_bloque('comentarios_sugerencias', function () use ($c) {
        return adm_uno($c, 'SELECT MIN(fecha_hora) FROM comentarios_sugerencias');
    });
    $P = adm_periodo($p, $minimo);
    $r = ['periodo' => $P];
    $r['lista'] = adm_bloque('comentarios_sugerencias', function () use ($c) {
        try {
            return adm_todos($c, 'SELECT id, comentario, email, telefono, nombre, tipo_negocio, calificacion, pais, ciudad, fecha_hora
                                  FROM comentarios_sugerencias ORDER BY fecha_hora DESC, id DESC LIMIT 1000');
        } catch (PDOException $e) {
            // Sin la columna `telefono` (falta sql/2026-10-24_comentarios_telefono.sql)
            try {
                return adm_todos($c, 'SELECT id, comentario, email, NULL AS telefono, nombre, tipo_negocio, calificacion, pais, ciudad, fecha_hora
                                      FROM comentarios_sugerencias ORDER BY fecha_hora DESC, id DESC LIMIT 1000');
            } catch (PDOException $e2) {
                // La tabla de antes del formulario de opiniones de la página: todo vino del programa
                return adm_todos($c, 'SELECT id, comentario, email, NULL AS telefono, NULL AS nombre, NULL AS tipo_negocio, NULL AS calificacion, pais, ciudad, fecha_hora
                                      FROM comentarios_sugerencias ORDER BY fecha_hora DESC, id DESC LIMIT 1000');
            }
        }
    });
    return $r;
}

// Una computadora: lo que dice la telemetría, su panel, sus compras y claves, y su prueba de Excel
function sec_equipo(PDO $c, $id, $lic) {
    $r = ['id' => $id, 'lic' => $lic];
    $paneles = adm_paneles($c);
    if (!$id && $lic) {
        $equipo_de = adm_equipo_de_licencia($paneles);
        $id = isset($equipo_de[$lic]) ? $equipo_de[$lic] : null;
        $r['id'] = $id;
    }
    if ($id && !$lic && $paneles && isset($paneles[$id])) {
        $lic = $paneles[$id]['hw_licencia'];
        $r['lic'] = $lic;
    }
    $hoy = date('Y-m-d');
    $par = [':id' => $id];

    if ($id) {
        $r['uso'] = adm_bloque('uso_punto_venta', function () use ($c, $par, $hoy) {
            $res = adm_todos($c, "SELECT MIN(fecha_hora) AS primera, MAX(fecha_hora) AS ultima, COUNT(DISTINCT DATE(fecha_hora)) AS dias,
                                         SUM(tipo_evento = 'login') AS inicios, SUM(tipo_evento = 'cierre_turno') AS cortes,
                                         SUM(tipo_evento = 'salida_app') AS salidas, COUNT(*) AS eventos
                                  FROM uso_punto_venta WHERE hardware_id = :id", $par)[0];
            if ((int) $res['eventos'] === 0) return null;
            return [
                'resumen' => $res,
                'eventos' => adm_todos($c, 'SELECT fecha_hora, tipo_evento, usuario, rol, tipo_sesion, negocio, ciudad, pais
                                            FROM uso_punto_venta WHERE hardware_id = :id ORDER BY fecha_hora DESC LIMIT 300', $par),
                'usuarios' => adm_todos($c, "SELECT usuario, SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(rol, '') ORDER BY fecha_hora DESC), ',', 1) AS rol,
                                                    SUM(tipo_evento = 'login') AS inicios, MIN(fecha_hora) AS primera, MAX(fecha_hora) AS ultima
                                             FROM uso_punto_venta WHERE hardware_id = :id GROUP BY usuario ORDER BY ultima DESC", $par),
                'lugares' => adm_todos($c, 'SELECT ciudad, pais, COUNT(*) AS n, MIN(fecha_hora) AS primera, MAX(fecha_hora) AS ultima
                                            FROM uso_punto_venta WHERE hardware_id = :id GROUP BY ciudad, pais ORDER BY ultima DESC', $par),
                'dias' => adm_todos($c, "SELECT DATE_FORMAT(fecha_hora, '%Y-%m-%d') AS d, COUNT(*) AS n FROM uso_punto_venta
                                         WHERE hardware_id = :id AND fecha_hora >= :desde GROUP BY d", $par + [':desde' => adm_dia($hoy, -370)]),
                'negocios' => adm_todos($c, "SELECT negocio, MAX(fecha_hora) AS ultima FROM uso_punto_venta
                                             WHERE hardware_id = :id AND negocio IS NOT NULL AND negocio <> '' GROUP BY negocio ORDER BY ultima DESC", $par),
            ];
        });

        // Las versiones que ha tenido, de la más reciente a la más vieja (sin las de hasta 3.6.0, que no la mandaban)
        $r['versiones'] = adm_bloque('uso_punto_venta.version_pos', function () use ($c, $par) {
            $actual = adm_versiones($c, 'FROM uso_punto_venta WHERE hardware_id = :id', $par);
            return [
                'actual' => isset($actual[$par[':id']]) ? $actual[$par[':id']] : null,
                'historial' => adm_todos($c, 'SELECT version_pos AS version, MIN(fecha_hora) AS desde, MAX(fecha_hora) AS hasta
                                              FROM uso_punto_venta WHERE hardware_id = :id AND version_pos IS NOT NULL
                                              GROUP BY version_pos ORDER BY hasta DESC', $par),
                'publicada' => adm_version_publicada(),
            ];
        });

        $r['panel'] = adm_bloque('dashboard_pos', function () use ($c, $par) {
            $f = adm_todos($c, 'SELECT negocio, ultima_sync, ventas_hoy, abonos_hoy, tickets_hoy, ganancia_hoy, turno_usuario, turno_inicio,
                                       turno_abierto, ventas_turno, tickets_turno, abonos_turno FROM dashboard_pos WHERE hardware_id = :id', $par);
            return $f ? $f[0] : null;
        });
        if ($r['panel']) {
            $panel = &$r['panel'];
            adm_bloque('dashboard_pos.extra_json', function () use ($c, $par, &$panel) {
                $f = adm_todos($c, 'SELECT extra_json, pin_hash IS NOT NULL AS protegido, pin_bloqueo_hasta FROM dashboard_pos WHERE hardware_id = :id', $par)[0];
                $panel['extra'] = $f['extra_json'] ? json_decode($f['extra_json'], true) : null;
                $panel['protegido'] = (bool) $f['protegido'];
                $panel['bloqueado_hasta'] = $f['pin_bloqueo_hasta'];
            });
            adm_bloque('dashboard_pos.premium_hasta', function () use ($c, $par, &$panel) {
                $panel['premium_hasta'] = adm_uno($c, 'SELECT premium_hasta FROM dashboard_pos WHERE hardware_id = :id', $par) ?: null;
            });
            unset($panel);
            $r['historico'] = adm_bloque('dashboard_pos_historico', function () use ($c, $par, $hoy) {
                $p = $par + [':d' => adm_dia($hoy, -119)];
                try {
                    return adm_todos($c, 'SELECT fecha, ventas_centavos AS ventas, ganancia_centavos AS ganancia, tickets,
                                                 efectivo_centavos AS efectivo, tarjeta_centavos AS tarjeta, transferencia_centavos AS transferencia,
                                                 comision_centavos AS comision, cancelaciones, cancelado_centavos AS cancelado
                                          FROM dashboard_pos_historico WHERE hardware_id = :id AND fecha >= :d ORDER BY fecha', $p);
                } catch (PDOException $e) {
                    return adm_todos($c, 'SELECT fecha, ventas_centavos AS ventas, ganancia_centavos AS ganancia, tickets
                                          FROM dashboard_pos_historico WHERE hardware_id = :id AND fecha >= :d ORDER BY fecha', $p);
                }
            });
            $r['meses'] = adm_bloque('dashboard_pos_meses', function () use ($c, $par) {
                return adm_todos($c, 'SELECT mes, ventas_centavos AS ventas, ganancia_centavos AS ganancia, tickets
                                      FROM dashboard_pos_meses WHERE hardware_id = :id ORDER BY mes', $par);
            });
            $r['horas'] = adm_bloque('dashboard_pos_ventas', function () use ($c, $par, $hoy) {
                $filas = adm_todos($c, 'SELECT hora AS k, COUNT(*) AS n, SUM(total_centavos) AS v FROM dashboard_pos_ventas
                                        WHERE hardware_id = :id AND fecha >= :d GROUP BY hora', $par + [':d' => adm_dia($hoy, -29)]);
                $n = array_fill(0, 24, 0);
                foreach ($filas as $f) $n[(int) $f['k']] = (int) $f['n'];
                return $n;
            });
            $r['paneles_clave'] = adm_bloque('dashboard_licencias', function () use ($c, $par) {
                return adm_todos($c, 'SELECT vence, visto FROM dashboard_licencias WHERE hardware_id = :id ORDER BY visto DESC', $par);
            });
        }
        $r['oculto'] = adm_bloque('admin_ocultos', function () use ($c, $par) {
            $f = adm_todos($c, 'SELECT nota, creado FROM admin_ocultos WHERE hardware_id = :id', $par);
            return $f ? $f[0] : false;
        });
    }

    if ($lic) {
        $pl = [':lic' => $lic];
        $planes = tienda_planes();
        $r['compras'] = adm_bloque('tienda_compras', function () use ($c, $pl, $planes) {
            $out = [];
            foreach (adm_todos($c, 'SELECT id, codigo, plan, origen, estado, email, monto, creada, pagada, stripe_sesion,
                                           stripe_suscripcion IS NOT NULL AS suscripcion
                                    FROM tienda_compras WHERE hardware_id = :lic ORDER BY creada DESC', $pl) as $f) {
                $out[] = ['id' => (int) $f['id'], 'codigo' => tienda_codigo_mostrar($f['codigo']),
                          'plan_nombre' => isset($planes[$f['plan']]) ? $planes[$f['plan']]['nombre'] : $f['plan'],
                          'origen' => $f['origen'], 'estado' => $f['estado'], 'email' => $f['email'], 'monto' => (int) $f['monto'],
                          'creada' => $f['creada'], 'pagada' => $f['pagada'], 'prueba' => tienda_es_prueba($f), 'suscripcion' => (bool) $f['suscripcion']];
            }
            return $out;
        });
        $r['claves'] = adm_bloque('tienda_claves', function () use ($c, $pl) {
            return adm_todos($c, 'SELECT tipo, vence_pagado, vence, creada, clave FROM tienda_claves WHERE hardware_id = :lic ORDER BY id DESC LIMIT 50', $pl);
        });
        $r['reportadas'] = adm_bloque('tienda_reportados', function () use ($c, $pl) {
            return adm_todos($c, 'SELECT tipo, vence, visto FROM tienda_reportados WHERE hardware_id = :lic', $pl);
        });
        $r['prueba'] = adm_bloque('pruebas_equipo', function () use ($c, $pl) {
            $f = adm_todos($c, 'SELECT primera_vez, exportar_usados, importar_usados, actualizado FROM pruebas_equipo WHERE equipo = :lic', $pl);
            return $f ? $f[0] : false;
        });
        $r['intentos_prueba'] = DASH_PRUEBA_INTENTOS;
    }
    return $r;
}

// ── Escrituras: el seguimiento de /gratis ───────────────────────────────────
// escrito: le escribiste por WhatsApp (no a quien pidió BAJA: 409) · baja / quitar_baja · borrar_registro: borra sus
// datos para siempre (quien lo pida, como promete el aviso de privacidad). id = el número del registro.
$SEGUIMIENTO = [
    'escrito'         => 'UPDATE registros_gratis SET contactado_en = NOW() WHERE id = :id AND baja_en IS NULL',
    'baja'            => 'UPDATE registros_gratis SET baja_en = COALESCE(baja_en, NOW()) WHERE id = :id',
    'quitar_baja'     => 'UPDATE registros_gratis SET baja_en = NULL WHERE id = :id',
    'borrar_registro' => 'DELETE FROM registros_gratis WHERE id = :id',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($SEGUIMIENTO[$accion])) {
    $rid = isset($_POST['id']) ? (string) $_POST['id'] : '';
    if (!preg_match('/^[0-9]{1,10}$/', $rid)) admin_json(400, ['status' => 'error', 'message' => 'ID inválido']);
    try {
        $existe = adm_uno($conn, 'SELECT COUNT(*) FROM registros_gratis WHERE id = :id', [':id' => (int) $rid]);
        if (!(int) $existe) admin_json(404, ['status' => 'error', 'message' => 'Ese registro ya no existe']);
        $st = $conn->prepare($SEGUIMIENTO[$accion]);
        $st->execute([':id' => (int) $rid]);
        if ($accion === 'escrito' && $st->rowCount() === 0) {
            admin_json(409, ['status' => 'error', 'message' => 'Pidió que no le escribieran más (BAJA)']);
        }
    } catch (PDOException $e) {
        error_log('admin/api (seguimiento): ' . $e->getMessage());
        admin_json(500, ['status' => 'error', 'message' => 'Falta correr sql/2026-10-27_registros_gratis.sql']);
    }
    admin_json(200, ['status' => 'ok', 'ahora' => date('Y-m-d H:i:s')]);
}

// ── Escrituras: apartar o volver a contar un equipo ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
    if (!adm_id_valido($id)) admin_json(400, ['status' => 'error', 'message' => 'ID inválido']);
    try {
        if ($accion === 'ocultar') {
            $nota = isset($_POST['nota']) ? substr(trim(strip_tags((string) $_POST['nota'])), 0, 100) : '';
            $conn->prepare('INSERT INTO admin_ocultos (hardware_id, nota, creado) VALUES (:id, :n, NOW()) ON DUPLICATE KEY UPDATE nota = VALUES(nota)')
                 ->execute([':id' => $id, ':n' => $nota]);
        } elseif ($accion === 'contar') {
            $conn->prepare('DELETE FROM admin_ocultos WHERE hardware_id = :id')->execute([':id' => $id]);
        } else {
            admin_json(400, ['status' => 'error', 'message' => 'Acción desconocida']);
        }
    } catch (PDOException $e) {
        error_log('admin/api (ocultar): ' . $e->getMessage());
        admin_json(500, ['status' => 'error', 'message' => 'Falta correr sql/2026-10-21_admin.sql']);
    }
    admin_json(200, ['status' => 'ok']);
}

// ── Lectura ─────────────────────────────────────────────────────────────────
$s = isset($_GET['s']) ? (string) $_GET['s'] : 'resumen';
$p = isset($_GET['p']) ? (string) $_GET['p'] : '30';
try {
    switch ($s) {
        case 'resumen':      $datos = sec_resumen($conn, $p); break;
        case 'descargas':    $datos = sec_descargas($conn, $p); break;
        case 'registros':    $datos = sec_registros($conn, $p); break;
        case 'computadoras': $datos = sec_computadoras($conn, $p); break;
        case 'tiendas':      $datos = sec_tiendas($conn, $p); break;
        case 'premium':      $datos = sec_premium($conn, $p); break;
        case 'comentarios':  $datos = sec_comentarios($conn, $p); break;
        case 'equipo':
            $id = isset($_GET['id']) && $_GET['id'] !== '' ? (string) $_GET['id'] : null;
            $lic = isset($_GET['lic']) && $_GET['lic'] !== '' ? (string) $_GET['lic'] : null;
            if (($id !== null && !adm_id_valido($id)) || ($lic !== null && !adm_id_valido($lic)) || ($id === null && $lic === null)) {
                admin_json(400, ['status' => 'error', 'message' => 'ID inválido']);
            }
            $datos = sec_equipo($conn, $id, $lic);
            break;
        default:
            admin_json(404, ['status' => 'error', 'message' => 'Sección desconocida']);
    }
} catch (Exception $e) {
    error_log('admin/api (' . $s . '): ' . $e->getMessage());
    admin_json(500, ['status' => 'error', 'message' => 'Error al leer los datos']);
}
$datos['status'] = 'ok';
$datos['faltan'] = array_values(array_unique($FALTAN));
$datos['errores'] = (object) $ERRORES;
$datos['hoy'] = date('Y-m-d');
$datos['ahora'] = date('Y-m-d H:i:s');
admin_json(200, $datos);
