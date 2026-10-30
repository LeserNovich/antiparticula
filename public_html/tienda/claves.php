<?php
// El programa (o la app del celular) pregunta por sus claves (POST hw, claves). Responde las que la tienda le entregó a esa PC (el
// programa activa las que no tenga), las que se le retiraron por un reembolso o un contracargo (desde el 2026-10-30: el
// programa posterior a la 3.8.0 y la app posterior a la 0.1.6 las borran) y las compras de OXXO que esperan pago. De paso anota lo que la PC ya tenía
// (claves verificadas), para que una renovación se sume desde ahí.
define('TIENDA_COMUN_OK', true);
require __DIR__ . '/../tienda_comun.php';

dash_cabeceras_api();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') dash_responder(405, ['status' => 'error', 'message' => 'Método no permitido']);
$hw = strtoupper(isset($_POST['hw']) ? (string) $_POST['hw'] : '');
if (!dash_equipo_valido($hw)) dash_responder(400, ['status' => 'error', 'message' => 'hw']);
try {
    list($conn, $cfg) = dash_conectar();
    tienda_registrar_reportadas($conn, $cfg, $hw, isset($_POST['claves']) ? substr((string) $_POST['claves'], 0, 4000) : '');
    // Si quien pregunta es el celular que incluye un Premium de PC, su clave al día (dura poco y se renueva aquí)
    tienda_celular_refrescar($conn, $cfg, $hw);
    $st = $conn->prepare('SELECT plan FROM tienda_compras
                           WHERE hardware_id = :hw AND estado = \'pendiente\' AND creada > NOW() - INTERVAL 4 DAY');
    $st->execute([':hw' => $hw]);
    $pendientes = $st->fetchAll(PDO::FETCH_COLUMN);
    // ¿Se renueva sola? (una mensualidad con un pago en los últimos 40 días): el programa no recuerda renovarla
    $st = $conn->prepare('SELECT COUNT(*) FROM tienda_compras c JOIN tienda_pagos p ON p.compra_id = c.id
                           WHERE c.hardware_id = :hw AND c.stripe_suscripcion IS NOT NULL AND p.creado > NOW() - INTERVAL 40 DAY');
    $st->execute([':hw' => $hw]);
    dash_responder(200, ['status' => 'ok', 'claves' => tienda_claves_de($conn, $hw), 'retiradas' => tienda_claves_retiradas($conn, $hw),
                         'pendientes' => $pendientes, 'renovable' => (int) $st->fetchColumn() > 0]);
} catch (Exception $e) {
    error_log('tienda/claves.php: ' . $e->getMessage());
    dash_responder(500, ['status' => 'error', 'message' => 'interno']);
}
