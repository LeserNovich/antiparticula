<?php
// Precios y beneficios de Premium (GET). Lo leen el programa (Configuración > Premium), el panel del celular y la
// página: un solo lugar donde viven los precios (tienda_planes() en tienda_comun.php).
//   ?zona=Europe/Madrid&idioma=es-ES,en&region=ES   (opcionales) de dónde es quien pregunta: con eso «pais» y, si se
//   le enseña otra moneda, «local» (ver «La moneda de quien ve los precios» en tienda_comun.php). Sin ellos, pesos.
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
$pais = tienda_pais_de($_GET);
echo json_encode(['planes' => $planes, 'beneficios' => tienda_beneficios(),
                  'pais' => $pais,                                        // null = no se sabe
                  'local' => tienda_moneda_local(tienda_config(), $pais)], // null = enseñar pesos
                 JSON_UNESCAPED_UNICODE);
