<?php
// Tu página privada de datos: https://www.antiparticula.com/admin/
// Sin sesión pide la clave; con ella, monta la página y admin.js pide los datos a api.php.
define('ADMIN_COMUN_OK', true);
require __DIR__ . '/../admin_comun.php';

$cfg = admin_config();
admin_cabeceras(true);

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = admin_entrar($cfg, isset($_POST['clave']) ? $_POST['clave'] : '');
    if ($r['ok']) {
        // Después de entrar, un GET: recargar la página no vuelve a mandar la clave
        header('Location: ' . admin_ruta_cookie(), true, 303);
        exit;
    }
    $mensaje = $r['mensaje'];
}
$con_sesion = admin_sesion_valida($cfg);

function v($archivo) {
    $t = @filemtime(__DIR__ . '/' . $archivo);
    return $archivo . '?v=' . ($t ? $t : '1');
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Tus datos · Antipartícula</title>
<link rel="icon" href="../img/marca/favicon.ico" sizes="32x32">
<link rel="icon" href="../img/marca/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= v('admin.css') ?>">
</head>
<?php if (!$con_sesion): ?>
<body class="entrada">
<main class="entrada-caja">
    <img class="entrada-logo" src="../img/marca/icono-192.png" alt="" width="44" height="44">
    <h1>Tus datos</h1>
    <p class="entrada-sub">Antipartícula · solo para ti</p>
    <form method="post" autocomplete="on">
        <label for="clave">Clave</label>
        <input id="clave" name="clave" type="password" autocomplete="current-password" required autofocus maxlength="200">
        <?php if ($mensaje !== ''): ?>
        <p class="entrada-error" role="alert"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <button type="submit" class="btn-relleno">Entrar</button>
    </form>
</main>
</body>
<?php else: ?>
<body>
<header class="cabecera">
    <div class="cabecera-in">
        <a class="marca" href="#resumen"><img src="../img/marca/icono-192.png" alt="" width="26" height="26"><span>Tus datos</span></a>
        <nav class="pestanas" id="pestanas" aria-label="Secciones">
            <a href="#resumen" data-s="resumen">Resumen</a>
            <a href="#descargas" data-s="descargas">Descargas</a>
            <a href="#registros" data-s="registros">Registros</a>
            <a href="#computadoras" data-s="computadoras">Computadoras</a>
            <a href="#tiendas" data-s="tiendas">Tiendas</a>
            <a href="#premium" data-s="premium">Premium</a>
            <a href="#comentarios" data-s="comentarios">Comentarios</a>
        </nav>
        <button type="button" class="btn-texto salir" id="salir">Salir</button>
    </div>
</header>
<div class="filtros" id="filtros">
    <div class="segmentado" id="periodo" role="radiogroup" aria-label="Periodo">
        <button type="button" role="radio" data-p="7">7 días</button>
        <button type="button" role="radio" data-p="30">30 días</button>
        <button type="button" role="radio" data-p="90">90 días</button>
        <button type="button" role="radio" data-p="365">12 meses</button>
        <button type="button" role="radio" data-p="todo">Todo</button>
    </div>
    <span class="filtros-nota" id="filtros-nota"></span>
</div>
<main class="vista" id="vista" aria-live="polite"></main>
<div class="tooltip" id="tooltip" role="tooltip" hidden></div>
<script src="<?= v('admin.js') ?>" defer></script>
</body>
<?php endif; ?>
</html>
