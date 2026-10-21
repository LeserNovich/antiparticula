<?php
// ────────────────────────────────────────────────────────────────────────────
// Tu página privada de datos (/admin/): la clave, la sesión y la conexión.
// NO es un endpoint: admin/index.php y admin/api.php hacen
//     define('ADMIN_COMUN_OK', true); require __DIR__ . '/../admin_comun.php';
// y quien lo abra directo recibe un 404. Compatible con PHP 7.2 (como dashboard_comun.php).
//
// config_db.php (fuera del webroot) trae, además de la base:
//   'admin_clave_hash'  password_hash de tu clave: lo da `php herramientas/clave_admin.php`
//   'admin_secreto'     opcional; si falta, la firma de la sesión sale de la contraseña de la base y del hash
//
// La sesión es una cookie firmada (HMAC, ADMIN_SESION_DIAS días), no una sesión de PHP: en el hosting compartido
// las sesiones de PHP se borran a los 24 minutos sin uso. Cambiar la clave cierra todas las sesiones abiertas.
// ────────────────────────────────────────────────────────────────────────────
if (!defined('ADMIN_COMUN_OK')) {
    http_response_code(404);
    exit;
}

const ADMIN_COOKIE            = 'ap_admin';
const ADMIN_SESION_DIAS       = 7;
const ADMIN_FALLOS_IP_HORA    = 10;   // claves incorrectas por IP en una hora
const ADMIN_FALLOS_TODOS_HORA = 60;   // y de todas las IPs juntas (quien prueba desde muchas)

date_default_timezone_set('America/Mexico_City');

function admin_config() {
    $ruta = dirname(__DIR__) . '/config_db.php';
    if (!file_exists($ruta)) {
        error_log('admin: falta config_db.php en ' . $ruta);
        http_response_code(500);
        exit('Error de configuración');
    }
    return require $ruta;
}

function admin_conectar(array $cfg) {
    $conn = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // La hora de México: la misma con que los endpoints guardan sus fechas (NOW() con esta zona)
    $conn->exec("SET time_zone = '-06:00'");
    return $conn;
}

// Nada de esto se guarda en cachés, se muestra en un marco ajeno ni se indexa
function admin_cabeceras($html) {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    if ($html) {
        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
             . "connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    } else {
        header('Content-Type: application/json; charset=UTF-8');
    }
}

function admin_json($codigo, array $cuerpo) {
    http_response_code($codigo);
    // Un texto mal codificado en una tabla vieja no debe dejar la respuesta vacía
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ── La sesión: una cookie firmada ───────────────────────────────────────────
function admin_configurada(array $cfg) {
    return isset($cfg['admin_clave_hash']) && is_string($cfg['admin_clave_hash']) && strlen($cfg['admin_clave_hash']) >= 20;
}

function admin_b64($bin)  { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function admin_b64d($txt) { return base64_decode(strtr($txt, '-_', '+/')); }

function admin_secreto(array $cfg) {
    if (isset($cfg['admin_secreto']) && is_string($cfg['admin_secreto']) && strlen($cfg['admin_secreto']) >= 16) {
        return $cfg['admin_secreto'];
    }
    // El hash entra en la firma: cambiar la clave invalida las sesiones de antes
    return hash('sha256', 'ap-admin-v1|' . $cfg['password'] . '|' . $cfg['admin_clave_hash'], true);
}

function admin_token_emitir(array $cfg, $ahora = null) {
    $ahora = $ahora === null ? time() : $ahora;
    $payload = admin_b64(json_encode(['v' => 1, 'exp' => $ahora + ADMIN_SESION_DIAS * 86400]));
    return $payload . '.' . admin_b64(hash_hmac('sha256', $payload, admin_secreto($cfg), true));
}

function admin_token_valido(array $cfg, $token, $ahora = null) {
    if (!admin_configurada($cfg) || !is_string($token) || strlen($token) > 400) return false;
    $partes = explode('.', $token);
    if (count($partes) !== 2) return false;
    $esperada = admin_b64(hash_hmac('sha256', $partes[0], admin_secreto($cfg), true));
    if (!hash_equals($esperada, $partes[1])) return false;
    $datos = json_decode((string) admin_b64d($partes[0]), true);
    $ahora = $ahora === null ? time() : $ahora;
    return is_array($datos) && isset($datos['exp']) && (int) $datos['exp'] > $ahora;
}

function admin_sesion_valida(array $cfg) {
    return admin_token_valido($cfg, isset($_COOKIE[ADMIN_COOKIE]) ? $_COOKIE[ADMIN_COOKIE] : null);
}

// La carpeta de la página (/admin/): la cookie solo viaja a ella. Si renombras la carpeta, sigue funcionando.
function admin_ruta_cookie() {
    $dir = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/admin/index.php'));
    return rtrim($dir, '/') . '/';
}

function admin_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
}

// A mano y no con setcookie(): SameSite en setcookie() pide PHP 7.3
function admin_poner_cookie($valor, $segundos) {
    $partes = [ADMIN_COOKIE . '=' . $valor, 'Path=' . admin_ruta_cookie(), 'Max-Age=' . (int) $segundos,
               'Expires=' . gmdate('D, d M Y H:i:s', time() + (int) $segundos) . ' GMT', 'HttpOnly', 'SameSite=Strict'];
    if (admin_https()) $partes[] = 'Secure';
    header('Set-Cookie: ' . implode('; ', $partes), false);
}

function admin_cerrar_sesion() {
    admin_poner_cookie('', -3600);
}

// ── Entrar: la clave, con freno ─────────────────────────────────────────────
function admin_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '?';
}

// Lanza PDOException si falta la tabla (sin freno no se entra: quien llama lo dice)
function admin_bloqueada(PDO $conn, $ip) {
    $hora = date('Y-m-d H');
    $st = $conn->prepare('SELECT COALESCE(SUM(fallos), 0) AS todos, COALESCE(SUM(CASE WHEN ip = :ip THEN fallos ELSE 0 END), 0) AS mios
                          FROM admin_intentos WHERE hora = :h');
    $st->execute([':ip' => $ip, ':h' => $hora]);
    $f = $st->fetch();
    return (int) $f['mios'] >= ADMIN_FALLOS_IP_HORA || (int) $f['todos'] >= ADMIN_FALLOS_TODOS_HORA;
}

function admin_anotar_fallo(PDO $conn, $ip) {
    $conn->prepare('INSERT INTO admin_intentos (ip, hora, fallos) VALUES (:ip, :h, 1) ON DUPLICATE KEY UPDATE fallos = fallos + 1')
         ->execute([':ip' => $ip, ':h' => date('Y-m-d H')]);
    // Lo de hace más de dos días ya no frena nada
    $conn->prepare('DELETE FROM admin_intentos WHERE hora < :viejo')->execute([':viejo' => date('Y-m-d H', time() - 2 * 86400)]);
}

// Devuelve ['ok' => bool, 'mensaje' => texto para la pantalla de entrada]. Si sale bien deja puesta la cookie.
function admin_entrar(array $cfg, $clave) {
    if (!admin_configurada($cfg)) {
        return ['ok' => false, 'mensaje' => 'Falta configurar tu clave: corre «php herramientas/clave_admin.php» y pega la línea que te da en config_db.php.'];
    }
    $clave = is_string($clave) ? substr($clave, 0, 200) : '';
    try {
        $conn = admin_conectar($cfg);
        $ip = admin_ip();
        if (admin_bloqueada($conn, $ip)) {
            return ['ok' => false, 'mensaje' => 'Demasiados intentos. Vuelve a intentarlo a las ' . date('H', time() + 3600) . ':00.'];
        }
        if ($clave === '' || !password_verify($clave, $cfg['admin_clave_hash'])) {
            admin_anotar_fallo($conn, $ip);
            return ['ok' => false, 'mensaje' => 'Esa no es la clave.'];
        }
        $conn->prepare('DELETE FROM admin_intentos WHERE ip = :ip')->execute([':ip' => $ip]);
    } catch (PDOException $e) {
        error_log('admin (entrar): ' . $e->getMessage());
        return ['ok' => false, 'mensaje' => 'No se pudo revisar la clave. ¿Ya corriste sql/2026-10-21_admin.sql en phpMyAdmin?'];
    }
    admin_poner_cookie(admin_token_emitir($cfg), ADMIN_SESION_DIAS * 86400);
    return ['ok' => true, 'mensaje' => ''];
}
