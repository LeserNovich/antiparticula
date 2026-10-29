<?php
// La Events API de TikTok de imitación para tests/correr_gratis.sh (php -S 127.0.0.1:8091 tiktok_falso.php).
// Anota cada evento en /tmp/tiktok_eventos.jsonl (cuerpo y token) y contesta como TikTok: {"code":0,"message":"OK"}.
// Con /tmp/tiktok_caido contesta un error (como un token inválido), para probar que nada se cae por eso.
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/v1.3/event/track/') {
    http_response_code(404);
    echo '{"code":40404,"message":"ruta"}';
    return true;
}
if (file_exists('/tmp/tiktok_caido')) {
    echo '{"code":40105,"message":"Access token is incorrect or has been revoked."}';
    return true;
}
file_put_contents('/tmp/tiktok_eventos.jsonl', json_encode([
    'token'  => isset($_SERVER['HTTP_ACCESS_TOKEN']) ? $_SERVER['HTTP_ACCESS_TOKEN'] : null,
    'cuerpo' => json_decode(file_get_contents('php://input'), true),
]) . "\n", FILE_APPEND);
echo '{"code":0,"message":"OK","request_id":"prueba","data":{}}';
return true;
