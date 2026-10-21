<?php
// Un Stripe de imitación para tests/correr_tienda.sh (php -S 127.0.0.1:8090 stripe_falso.php).
// Crear una sesión de Checkout: guarda lo que se pidió en /tmp/stripe_ultima.json y contesta un id y una url.
// Con /tmp/stripe_caido presente responde 500 (Stripe caído). Exige la llave secreta de prueba.
header('Content-Type: application/json');
$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
if (file_exists('/tmp/stripe_caido')) { http_response_code(500); echo '{"error":{"message":"caído"}}'; exit; }
// Como responde Stripe si OXXO no está activado en la cuenta (o en el modo de prueba)
if (file_exists('/tmp/stripe_sin_oxxo') && isset($_POST['payment_method_types']) && in_array('oxxo', $_POST['payment_method_types'], true)) {
    http_response_code(400);
    echo '{"error":{"type":"invalid_request_error","param":"payment_method_types","message":"The payment method type \"oxxo\" is invalid. Please ensure the provided type is activated in your dashboard."}}';
    exit;
}
if ($auth !== 'Bearer sk_test_prueba') { http_response_code(401); echo '{"error":{"type":"invalid_request_error","message":"Invalid API Key provided: sk_test_****"}}'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/v1/checkout/sessions') {
    $id = 'cs_test_' . bin2hex(random_bytes(12));
    file_put_contents('/tmp/stripe_ultima.json', json_encode(['id' => $id, 'params' => $_POST,
        'version' => isset($_SERVER['HTTP_STRIPE_VERSION']) ? $_SERVER['HTTP_STRIPE_VERSION'] : null]));
    echo json_encode(['id' => $id, 'object' => 'checkout.session', 'url' => "https://checkout.stripe.test/c/pay/$id"]);
    exit;
}
http_response_code(404);
echo '{"error":{"message":"ruta"}}';
