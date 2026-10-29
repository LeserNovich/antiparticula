<?php
// ────────────────────────────────────────────────────────────────────────────
// El formulario de /gratis: quien vio el anuncio en el celular deja su WhatsApp y recibe su link personal
// para abrirlo en la computadora (antiparticula.com/d/CODIGO). Ver gratis_comun.php.
//
// POST telefono, giro, acepta=1 y, opcionales, nombre, event_id, ttclid, url
//   200 {status:'ok', codigo, link, nuevo}   nuevo=false: ese WhatsApp ya estaba (mismo código, sin evento)
//   400 {status:'error', campo, message}     campo: telefono | giro | acepta
//   405 no es POST · 429 demasiados registros desde esa IP · 503 falta la tabla (sql/2026-10-27_registros_gratis.sql)
// Un registro nuevo se le avisa a TikTok (CompleteRegistration) DESPUÉS de contestar.
// ────────────────────────────────────────────────────────────────────────────
define('GRATIS_COMUN_OK', true);
require __DIR__ . '/gratis_comun.php';
dash_cabeceras_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dash_responder(405, ['status' => 'error', 'message' => 'Método no permitido']);
}

// Un campo escondido que solo llenan los robots: se les contesta como si nada y no se guarda
if (isset($_POST['sitio']) && trim((string) $_POST['sitio']) !== '') {
    dash_responder(200, ['status' => 'ok', 'codigo' => null, 'link' => null, 'nuevo' => false]);
}

$telefono = gratis_telefono(isset($_POST['telefono']) ? $_POST['telefono'] : '');
$giro     = isset($_POST['giro']) ? (string) $_POST['giro'] : '';
$nombre   = dash_texto(isset($_POST['nombre']) ? $_POST['nombre'] : '', 60);
if ($telefono === null) {
    dash_responder(400, ['status' => 'error', 'campo' => 'telefono',
        'message' => 'Revisa tu número de WhatsApp: son 10 dígitos, por ejemplo 55 1234 5678.']);
}
if (!array_key_exists($giro, GRATIS_GIROS)) {
    dash_responder(400, ['status' => 'error', 'campo' => 'giro', 'message' => 'Elige el giro de tu negocio.']);
}
if (!isset($_POST['acepta']) || $_POST['acepta'] !== '1') {
    dash_responder(400, ['status' => 'error', 'campo' => 'acepta',
        'message' => 'Para mandarte el link necesitamos que aceptes que te escribamos por WhatsApp.']);
}

$visita   = gratis_visitante();
$ttclid   = gratis_ttclid(isset($_POST['ttclid']) ? $_POST['ttclid'] : '');
if ($ttclid === null) $ttclid = $visita['ttclid'];
$event_id = gratis_event_id(isset($_POST['event_id']) ? $_POST['event_id'] : '');
$url      = gratis_url(isset($_POST['url']) ? $_POST['url'] : '');

try {
    list($conn, $cfg) = dash_conectar();

    $st = $conn->prepare('SELECT COUNT(*) FROM registros_gratis WHERE ip = :ip AND creado > NOW() - INTERVAL 1 HOUR');
    $st->execute([':ip' => $visita['ip']]);
    if ((int) $st->fetchColumn() >= GRATIS_MAX_POR_IP_HORA) {
        dash_responder(429, ['status' => 'error', 'message' => 'Demasiados intentos. Espera un rato o escríbenos por WhatsApp.']);
    }

    // Una persona, un código: si ya se registró (otro anuncio, otro día), recibe el mismo link y no cuenta otra vez
    $st = $conn->prepare('SELECT codigo FROM registros_gratis WHERE telefono = :t');
    $st->execute([':t' => $telefono]);
    $ya = $st->fetchColumn();
    if ($ya !== false) {
        dash_responder(200, ['status' => 'ok', 'codigo' => $ya, 'link' => gratis_link($ya), 'nuevo' => false]);
    }

    $ins = $conn->prepare('INSERT INTO registros_gratis (codigo, telefono, nombre, giro, ttclid, ttp, ip, user_agent, creado)
                           VALUES (:c, :t, :n, :g, :clid, :ttp, :ip, :ua, NOW())');
    $codigo = null;
    for ($intento = 0; $intento < 5 && $codigo === null; $intento++) {
        $nuevo = gratis_codigo_nuevo();
        try {
            $ins->execute([':c' => $nuevo, ':t' => $telefono, ':n' => $nombre !== '' ? $nombre : null, ':g' => $giro,
                           ':clid' => $ttclid, ':ttp' => $visita['ttp'], ':ip' => $visita['ip'],
                           ':ua' => $visita['user_agent'] !== '' ? $visita['user_agent'] : null]);
            $codigo = $nuevo;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            // Duplicado: o el código ya existía (se intenta otro) o ese WhatsApp se registró en este mismo instante
            $st->execute([':t' => $telefono]);
            $ya = $st->fetchColumn();
            if ($ya !== false) {
                dash_responder(200, ['status' => 'ok', 'codigo' => $ya, 'link' => gratis_link($ya), 'nuevo' => false]);
            }
        }
    }
    if ($codigo === null) throw new RuntimeException('no hubo un código libre en 5 intentos');
} catch (PDOException $e) {
    error_log('registrar_gratis.php: ' . $e->getMessage());
    // 42S02: la tabla no existe (falta la migración)
    $sin_tabla = $e->getCode() === '42S02';
    dash_responder($sin_tabla ? 503 : 500, ['status' => 'error',
        'message' => 'No pudimos guardar tu número. Escríbenos por WhatsApp y te mandamos el link.']);
} catch (Exception $e) {
    error_log('registrar_gratis.php: ' . $e->getMessage());
    dash_responder(500, ['status' => 'error', 'message' => 'No pudimos guardar tu número. Escríbenos por WhatsApp y te mandamos el link.']);
}

gratis_soltar_respuesta(200, ['status' => 'ok', 'codigo' => $codigo, 'link' => gratis_link($codigo), 'nuevo' => true]);

tiktok_evento($cfg, 'CompleteRegistration', $event_id, [
    'telefono'   => $telefono,
    'codigo'     => $codigo,
    'ttclid'     => $ttclid,
    'ttp'        => $visita['ttp'],
    'ip'         => $visita['ip'],
    'user_agent' => $visita['user_agent'],
], ['content_name' => 'gratis'], $url);
