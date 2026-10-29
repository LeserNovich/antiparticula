<?php
// ────────────────────────────────────────────────────────────────────────────
// La página de los anuncios (/gratis) y los eventos que el servidor le manda a TikTok.
// NO es un endpoint: registrar_gratis.php y registrar_descarga.php hacen
//     define('GRATIS_COMUN_OK', true); require __DIR__ . '/gratis_comun.php';
// y quien lo abra directo recibe un 404. Compatible con PHP 7.2 (como dashboard_comun.php).
//
// El recorrido: el anuncio se ve en el celular, pero el programa se instala en la computadora.
//   1. /gratis en el celular: deja su WhatsApp → registrar_gratis.php guarda el registro con el ttclid del
//      anuncio y le da un link personal (antiparticula.com/d/CODIGO) → evento CompleteRegistration
//   2. Abre ese link en la computadora (.htaccess lo manda a /gratis?c=CODIGO) y descarga →
//      registrar_descarga.php reconoce el código → evento Download con los datos del registro, y así
//      TikTok sabe de qué anuncio venía una descarga hecha en otro aparato
// El Pixel del navegador manda los mismos eventos con el mismo event_id: TikTok cuenta uno solo.
//
// config_db.php (fuera del webroot) trae, además de la base:
//   'tiktok_token'   el token de acceso de la Events API (Administrador de eventos › el Pixel › Configuración)
//   'tiktok_pixel'   opcional; el ID del Pixel (si no, GRATIS_PIXEL)
//   'tiktok_prueba'  opcional; un código TEST… de «Probar eventos»: los eventos solo salen ahí, no en las cifras
//   'tiktok_api'     solo pruebas (otra API de TikTok)
// ────────────────────────────────────────────────────────────────────────────
if (!defined('GRATIS_COMUN_OK')) {
    http_response_code(404);
    exit;
}
if (!defined('DASH_COMUN_OK')) define('DASH_COMUN_OK', true);
require_once __DIR__ . '/dashboard_comun.php';

const GRATIS_PIXEL           = 'DBG6JEBC77U8I4RJ0MD0';   // el mismo que cargan las páginas (ttq.load)
const GRATIS_MAX_POR_IP_HORA = 10;                       // registros por IP y hora: frena a los robots
const GRATIS_LARGO_CODIGO    = 5;
// Sin 0/O, 1/I/L, 2/Z, 5/S ni 8/B (el mismo de los códigos de la tienda): se dicta y se copia sin confundir letras
const GRATIS_ALFABETO        = 'ACDEFGHJKMNPQRTUVWXY34679';
// Lo que se guarda => lo que ve quien llena el formulario (el <select> de paginas/gratis.html usa estas claves)
const GRATIS_GIROS = [
    'abarrotes'  => 'Abarrotes o minisúper',
    'ferreteria' => 'Ferretería o tlapalería',
    'farmacia'   => 'Farmacia',
    'papeleria'  => 'Papelería',
    'ropa'       => 'Ropa, calzado o accesorios',
    'comida'     => 'Restaurante o comida',
    'otro'       => 'Otro',
];

// ── Lo que llega del navegador ──────────────────────────────────────────────
// El WhatsApp en formato E.164 (+52 y 10 dígitos en México), o null si no parece un número. Acepta espacios,
// guiones, paréntesis y puntos. Sin «+» se entiende de México; el «1» que llevaban los celulares después del 52
// (+52 1 55…) y los prefijos 044/045 ya no se usan desde 2020: se quitan.
function gratis_telefono($texto) {
    $t = preg_replace('/[\s().\/-]+/', '', (string) $texto);
    if (!preg_match('/^(\+|00)?(\d+)$/', $t, $m)) return null;
    $d = $m[2];
    if ($m[1] === '') {
        if (strlen($d) === 10) $mx = $d;
        elseif (strlen($d) === 13 && preg_match('/^04[45]/', $d)) $mx = substr($d, 3);
        elseif (strlen($d) === 12 && strpos($d, '52') === 0) $mx = substr($d, 2);
        elseif (strlen($d) === 13 && strpos($d, '521') === 0) $mx = substr($d, 3);
        else return null;
    } elseif (strpos($d, '52') === 0) {
        if (strlen($d) === 12) $mx = substr($d, 2);
        elseif (strlen($d) === 13 && strpos($d, '521') === 0) $mx = substr($d, 3);
        else return null;
    } else {
        // Otro país: lo que diga, mientras quepa en E.164
        return (strlen($d) >= 8 && strlen($d) <= 15 && $d[0] !== '0') ? '+' . $d : null;
    }
    // En México ninguna lada empieza con 0 ni con 1
    return preg_match('/^[2-9]\d{9}$/', $mx) ? '+52' . $mx : null;
}

function gratis_codigo_nuevo() {
    $c = '';
    for ($i = 0; $i < GRATIS_LARGO_CODIGO; $i++) $c .= GRATIS_ALFABETO[random_int(0, strlen(GRATIS_ALFABETO) - 1)];
    return $c;
}

// El código de un link personal tal como llegue (minúsculas, espacios), o null si no puede ser uno
function gratis_codigo_valido($texto) {
    $c = strtoupper(trim((string) $texto));
    return preg_match('/^[' . GRATIS_ALFABETO . ']{' . GRATIS_LARGO_CODIGO . '}$/', $c) ? $c : null;
}

// El event_id que generó el navegador (el mismo que lleva el Pixel), o uno nuevo si no sirve
function gratis_event_id($texto) {
    $t = (string) $texto;
    return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $t) ? $t : 's-' . bin2hex(random_bytes(12));
}

// ttclid: lo agrega TikTok a la URL del anuncio. _ttp: la cookie del Pixel. Solo se guardan si tienen su forma.
function gratis_ttclid($texto) {
    $t = trim((string) $texto);
    return preg_match('#^[A-Za-z0-9._~+/=-]{8,500}$#', $t) ? $t : null;
}

function gratis_ttp($texto) {
    $t = trim((string) $texto);
    return preg_match('/^[A-Za-z0-9._-]{8,100}$/', $t) ? $t : null;
}

// La página desde la que se mandó (para el evento): solo del sitio; si no, /gratis
function gratis_url($texto) {
    $t = substr(trim((string) $texto), 0, 500);
    return preg_match('#^https://(www\.)?antiparticula\.com/#', $t) ? $t : 'https://antiparticula.com/gratis';
}

function gratis_link($codigo) {
    return 'https://antiparticula.com/d/' . $codigo;
}

// Lo que el navegador sabe de sí mismo y TikTok usa para reconocerlo: IP, navegador, cookie del Pixel y el clic
// del anuncio (la cookie ap_ttclid la pone mensaje-exito.js al llegar con ?ttclid=)
function gratis_visitante() {
    return [
        'ip'         => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
        'ttp'        => gratis_ttp(isset($_COOKIE['_ttp']) ? $_COOKIE['_ttp'] : ''),
        'ttclid'     => gratis_ttclid(isset($_COOKIE['ap_ttclid']) ? $_COOKIE['ap_ttclid'] : ''),
    ];
}

// Contesta ya al navegador y sigue trabajando: avisarle a TikTok tarda y nadie tiene por qué esperarlo.
// Hostinger corre PHP con LiteSpeed; los demás servidores, con FPM. Con `php -S` (pruebas) no hay ninguno y
// la respuesta sale al terminar, que también sirve.
function gratis_soltar_respuesta($codigo_http, array $cuerpo) {
    ignore_user_abort(true);
    http_response_code($codigo_http);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

// ── Events API de TikTok ────────────────────────────────────────────────────
// Manda un evento. Nunca lanza ni detiene a quien llama: si falla, queda en el registro de errores y devuelve
// false. Sin 'tiktok_token' en config_db.php no manda nada.
//   $persona: telefono (E.164), codigo (el del link: sirve de external_id), ttclid, ttp, ip, user_agent.
//   El teléfono y el código viajan cifrados con SHA-256, como pide TikTok; lo demás, tal cual.
function tiktok_evento(array $cfg, $evento, $event_id, array $persona, array $propiedades = [], $url = null) {
    if (empty($cfg['tiktok_token'])) return false;
    $usuario = [];
    if (!empty($persona['telefono'])) $usuario['phone'] = hash('sha256', $persona['telefono']);
    if (!empty($persona['codigo']))   $usuario['external_id'] = hash('sha256', $persona['codigo']);
    foreach (['ttclid', 'ttp', 'ip', 'user_agent'] as $k) {
        if (!empty($persona[$k])) $usuario[$k] = (string) $persona[$k];
    }
    $dato = ['event' => $evento, 'event_time' => time(), 'event_id' => (string) $event_id, 'user' => $usuario];
    if ($propiedades) $dato['properties'] = $propiedades;
    if ($url) $dato['page'] = ['url' => $url];
    $cuerpo = [
        'event_source'    => 'web',
        'event_source_id' => !empty($cfg['tiktok_pixel']) ? $cfg['tiktok_pixel'] : GRATIS_PIXEL,
        'data'            => [$dato],
    ];
    if (!empty($cfg['tiktok_prueba'])) $cuerpo['test_event_code'] = $cfg['tiktok_prueba'];

    $api = !empty($cfg['tiktok_api']) ? rtrim($cfg['tiktok_api'], '/') : 'https://business-api.tiktok.com/open_api';
    $ch = curl_init($api . '/v1.3/event/track/');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Access-Token: ' . $cfg['tiktok_token']],
        CURLOPT_POSTFIELDS     => json_encode($cuerpo),
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $resp === false ? curl_error($ch) : '';
    curl_close($ch);
    $json = is_string($resp) ? json_decode($resp, true) : null;
    if ($http !== 200 || !is_array($json) || !isset($json['code']) || (int) $json['code'] !== 0) {
        error_log("tiktok: $evento no se mandó: " . ($error !== '' ? $error : "HTTP $http " . substr((string) $resp, 0, 300)));
        return false;
    }
    return true;
}
