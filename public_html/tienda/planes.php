<?php
// Precios y beneficios de Premium (GET). Lo leen el programa (Configuración > Premium), el panel del celular y la
// página: un solo lugar donde viven los precios (tienda_planes() en tienda_comun.php).
define('TIENDA_COMUN_OK', true);
require __DIR__ . '/../tienda_comun.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=600');

$planes = [];
foreach (tienda_planes() as $id => $p) {
    $planes[] = [
        'id'        => $id,
        'tipo'      => $p['tipo'],
        'nombre'    => $p['nombre'],
        'precio'    => $p['precio'],          // centavos, IVA incluido
        'periodo'   => $p['periodo'],
        'meses'     => $p['meses'],           // 0 = para siempre
        'renovable' => $p['modo'] === 'suscripcion',
        'oxxo'      => $p['modo'] === 'pago',
    ];
}
echo json_encode(['planes' => $planes, 'beneficios' => tienda_beneficios()], JSON_UNESCAPED_UNICODE);
