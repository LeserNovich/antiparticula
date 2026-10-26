<?php
// Genera la línea de tu clave para la página privada de datos (/admin/).
//
//   php herramientas/clave_admin.php
//
// Te pide la clave dos veces (no se ve al escribirla) y te da una línea así:
//     'admin_clave_hash' => '$2y$12$…',
// que se pega dentro del arreglo de config_db.php (el del hosting, fuera de public_html).
// La clave NO se guarda en ningún lado: solo su hash. Para cambiarla, vuelve a correr esto y reemplaza la línea
// (las sesiones abiertas se cierran solas).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function pedir($texto) {
    fwrite(STDOUT, $texto);
    $oculto = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($oculto) shell_exec('stty -echo');
    $linea = fgets(STDIN);
    if ($oculto) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return rtrim((string) $linea, "\r\n");
}

$clave = pedir('Clave nueva (12 caracteres o más): ');
if (strlen($clave) < 12) {
    fwrite(STDERR, "Muy corta: usa 12 caracteres o más (una frase de 3 o 4 palabras sirve).\n");
    exit(1);
}
if (strlen($clave) > 72) {
    fwrite(STDERR, "Muy larga: hasta 72 caracteres.\n");
    exit(1);
}
if (pedir('Otra vez: ') !== $clave) {
    fwrite(STDERR, "No coinciden.\n");
    exit(1);
}

$hash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
echo PHP_EOL . "Pega esta línea dentro del arreglo de config_db.php:" . PHP_EOL . PHP_EOL;
echo "    'admin_clave_hash' => '" . $hash . "'," . PHP_EOL . PHP_EOL;
