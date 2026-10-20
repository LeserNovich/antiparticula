<?php
// ────────────────────────────────────────────────────────────────────────────
// La prueba gratis de Excel (exportar e importar), contada por PC y no por
// instalación. Lo llama el POS (3.4.0+) al iniciar sesión y después de gastar un
// intento; no lo usa ningún navegador (sin CORS).
//
//   POST equipo=<Hardware ID estable, 16 hex>&exportar=<0-3>&importar=<0-3>
//     exportar/importar = los intentos que le QUEDAN al POS
//   200 {status:'exitoso', exportar:n, importar:n}   lo que queda según el servidor
//   400 equipo o cuentas inválidas · 405 método · 503 sin la migración 2026-10-20
//
// El POS se queda con el MENOR de los dos números: reinstalar el programa (otra
// carpeta, otra base de datos) no devuelve intentos, porque la PC es la misma.
// Sin internet el POS sigue con su cuenta local. Ver dashboard_comun.php.
// ────────────────────────────────────────────────────────────────────────────
define('DASH_COMUN_OK', true);
require_once __DIR__ . '/dashboard_comun.php';
dash_cabeceras_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dash_responder(405, ['status' => 'error', 'message' => 'Método no permitido']);
}

$equipo = isset($_POST['equipo']) && is_string($_POST['equipo']) ? strtoupper(trim($_POST['equipo'])) : '';
if (!dash_equipo_valido($equipo)) {
    dash_responder(400, ['status' => 'error', 'codigo' => 'equipo_invalido']);
}
// Las dos cuentas son obligatorias: una que falte no puede leerse como «ya no le queda ninguno»
foreach (['exportar', 'importar'] as $campo) {
    if (!isset($_POST[$campo]) || !is_string($_POST[$campo]) || !preg_match('/^[0-9]$/', $_POST[$campo])) {
        dash_responder(400, ['status' => 'error', 'codigo' => 'cuenta_invalida', 'campo' => $campo]);
    }
}

try {
    list($conn) = dash_conectar();
    $quedan = dash_prueba_conciliar($conn, $equipo, (int) $_POST['exportar'], (int) $_POST['importar']);
    dash_responder(200, ['status' => 'exitoso', 'exportar' => $quedan['exportar'], 'importar' => $quedan['importar']]);
} catch (PDOException $e) {
    error_log('prueba_gratis.php: ' . $e->getMessage());
    // Lo más probable: la migración 2026-10-20 sin correr. El POS sigue con su cuenta local.
    dash_responder(503, ['status' => 'no_disponible']);
}
