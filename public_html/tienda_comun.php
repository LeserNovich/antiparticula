<?php
// ────────────────────────────────────────────────────────────────────────────
// La tienda de Premium: los planes, el cobro con Stripe y la clave que llega sola.
// NO es un endpoint: los de public_html/tienda/ hacen
//     define('TIENDA_COMUN_OK', true); require __DIR__ . '/../tienda_comun.php';
// y quien lo abra directo recibe un 404. Compatible con PHP 7.2 (como dashboard_comun.php).
//
// Cómo funciona:
//   tienda/pagar.php    crea la sesión de cobro de Stripe (Checkout) y manda ahí al cliente
//   tienda/webhook.php  Stripe avisa que se pagó → se anota la compra y, si se sabe la PC,
//                       se firma su clave con la llave de ventas (número 2). Un reembolso completo o un
//                       contracargo retira lo que dio ese pago (claves.php le dice al programa qué borrar)
//   tienda/claves.php   el programa pregunta por sus claves nuevas y las activa solo
//   tienda/canjear.php  el código de la compra: para quien pagó en la página (no se sabe su PC)
//                       y para cambiar de PC (uno automático cada 12 meses)
//   tienda/estado.php   lo que muestra /compra después de pagar
//   tienda/planes.php   precios y beneficios (programa, panel y página), y la moneda de quien pregunta
//
// config_db.php (fuera del webroot) trae, además de la base:
//   'stripe_secreto'          sk_live_… (basta una llave restringida con «Checkout Sessions: escritura»)
//   'stripe_webhook_secreto'  whsec_… (el del endpoint https://www.antiparticula.com/tienda/webhook.php)
//   'llave_ventas'            ruta del PEM de la llave privada número 2, FUERA del webroot
//   'tienda_url'              opcional; https://www.antiparticula.com
//   'tienda_correo_de'        opcional; ventas@antiparticula.com
//   'stripe_api', 'correo_archivo'   solo pruebas (otra API de Stripe; correos a un archivo)
//   'tasas_urls', 'tasas_archivo'    solo pruebas (otra fuente de tipos de cambio; dónde guardarlos)
// Los tipos de cambio del día se guardan en tasas_mxn.json, junto a config_db.php (PHP debe poder escribir ahí).
// ────────────────────────────────────────────────────────────────────────────
if (!defined('TIENDA_COMUN_OK')) {
    http_response_code(404);
    exit;
}
if (!defined('DASH_COMUN_OK')) define('DASH_COMUN_OK', true);
require_once __DIR__ . '/dashboard_comun.php';

const TIENDA_KID              = 2;            // la llave de ventas: renglón «2:» de publica.txt del POS
const TIENDA_GRACIA_DIAS      = 3;            // la clave vence 3 días después de lo pagado: tiempo para que llegue la renovación
const TIENDA_CAMBIO_PC_MESES  = 12;           // un cambio de PC automático cada 12 meses
const TIENDA_PARA_SIEMPRE     = '2199-06-06'; // 2020-01-01 + 0xFFFF días = LicenciaFirmada.PARA_SIEMPRE del POS
const TIENDA_MAX_FALLOS_HORA  = 20;           // canjes fallidos por IP y hora
const TIENDA_STRIPE_VERSION   = '2024-06-20';
// Sin 0/O, 1/I/L, 2/Z, 5/S ni 8/B: se dicta y se copia sin confundir letras
const TIENDA_ALFABETO_CODIGO  = 'ACDEFGHJKMNPQRTUVWXY34679';
// Una compra en el modo de prueba de Stripe (tarjeta 4242…) no cuesta nada, pero la clave se firma con la llave de
// ventas REAL: dura solo estos días (alcanza para probar que se activa; no regala Premium a quien encuentre pagar.php).
const TIENDA_DIAS_PRUEBA      = 2;

// ¿Es una compra del modo de prueba de Stripe? Sus sesiones empiezan con cs_test_ (las reales con cs_live_).
function tienda_es_prueba(array $compra) {
    return strpos((string) $compra['stripe_sesion'], 'cs_test_') === 0;
}

// ── Planes ──────────────────────────────────────────────────────────────────
// Precios en centavos, IVA incluido (decisión del dueño, 2026-10-20). meses = 0: pago único para siempre.
// modo: 'suscripcion' se renueva sola con tarjeta; 'pago' es un solo cobro con tarjeta u OXXO.
function tienda_planes() {
    return [
        'premium_mensual' => ['tipo' => 'premium', 'meses' => 1,  'precio' => 14900,  'modo' => 'suscripcion',
                              'nombre' => 'Premium mensual', 'periodo' => 'al mes'],
        'premium_anual'   => ['tipo' => 'premium', 'meses' => 12, 'precio' => 129000, 'modo' => 'pago',
                              'nombre' => 'Premium anual', 'periodo' => 'al año'],
        'premium_siempre' => ['tipo' => 'premium', 'meses' => 0,  'precio' => 349000, 'modo' => 'pago',
                              'nombre' => 'Premium para siempre', 'periodo' => 'pago único'],
        'multi_mensual'   => ['tipo' => 'multi',   'meses' => 1,  'precio' => 29900,  'modo' => 'suscripcion',
                              'nombre' => 'Varias sucursales mensual', 'periodo' => 'al mes, por computadora'],
        'multi_anual'     => ['tipo' => 'multi',   'meses' => 12, 'precio' => 299000, 'modo' => 'pago',
                              'nombre' => 'Varias sucursales anual', 'periodo' => 'al año, por computadora'],
    ];
}

// Lo mismo que Premium.INCLUYE e INCLUYE_MULTISUCURSAL del POS: si cambia allá, cambia aquí.
function tienda_beneficios() {
    return [
        'premium' => [
            'Clientes y crédito: fiado con límite, abonos y cobro por WhatsApp',
            'Exportar e importar productos en Excel, sin límite',
            'Panel móvil: ventas, caja e inventario en el celular',
            'La app de punto de venta en un celular, incluida',
            'Resumen completo: meses, años y comparaciones',
            'Usuarios sin límite (administradores y cajeros)',
            'Encabezado del ticket con todas las líneas que quieras',
            'Etiquetas con código de barras de tus productos, sin límite',
            'Opción de quitar la marca Antiparticula del ticket',
            'Soporte y actualizaciones',
        ],
        'multi' => [
            'Todo lo de Premium',
            'Une tus tiendas: inventario, precios y productos de todas',
            'Traspasos de mercancía entre tiendas',
            'Tickets, cortes y reportes de cada tienda desde cualquiera',
            'Panel del celular con todas tus tiendas',
        ],
    ];
}

function tienda_plan($id) {
    $planes = tienda_planes();
    return is_string($id) && isset($planes[$id]) ? $planes[$id] : null;
}

function tienda_url(array $cfg) {
    return rtrim(isset($cfg['tienda_url']) ? $cfg['tienda_url'] : 'https://www.antiparticula.com', '/');
}

// config_db.php sin abrir la base (planes.php no la necesita); [] si no está
function tienda_config() {
    $ruta = dirname(__DIR__) . '/config_db.php';
    $cfg = file_exists($ruta) ? require $ruta : [];
    return is_array($cfg) ? $cfg : [];
}

// ── La moneda de quien ve los precios ───────────────────────────────────────
// Se cobra en pesos y Stripe convierte al pagar («Adaptive Pricing», encendido en el panel de Stripe): cada cliente paga
// en su moneda y aquí llegan pesos. La página, el programa y el panel enseñan el precio APROXIMADO en esa moneda.
// El país sale de lo que el equipo dice de sí mismo, sin servicios de geolocalización (pistas, de más a menos confiable):
//   region  el «País o región» de Windows (lo manda el programa)
//   zona    la zona horaria del navegador o del sistema (Europe/Madrid)
//   idioma  las regiones del idioma del navegador o del programa (es-ES,en-US)
// México gana si cualquier pista lo dice: es el precio real. Sin país, o fuera de los mercados donde Stripe convierte,
// se queda en pesos.
const TIENDA_CONVERSION     = 1.03; // Stripe le suma al cliente 2–4 % al convertir: el aproximado usa el punto medio
const TIENDA_TASAS_HORAS    = 12;   // cada cuánto se piden tipos de cambio nuevos
const TIENDA_TASAS_REINTENTO = 1800; // si la fuente no contestó, no volver a intentar antes de esto (segundos)
const TIENDA_TASAS_VIGENCIA = 7;    // días que sirve el último tipo de cambio si la fuente no contesta

// Los mercados donde Stripe convierte (docs.stripe.com/payments/currencies/localize-prices/adaptive-pricing, 2026-10-21)
// y su moneda. Panamá cobra en dólares.
function tienda_monedas_por_pais() {
    return [
        // América del Norte y el Caribe
        'AG' => 'XCD', 'AW' => 'AWG', 'BS' => 'BSD', 'BB' => 'BBD', 'BZ' => 'BZD', 'BM' => 'BMD', 'CA' => 'CAD',
        'KY' => 'KYD', 'CR' => 'CRC', 'DM' => 'XCD', 'DO' => 'DOP', 'GD' => 'XCD', 'GT' => 'GTQ', 'HT' => 'HTG',
        'HN' => 'HNL', 'MX' => 'MXN', 'JM' => 'JMD', 'PA' => 'USD', 'KN' => 'XCD', 'LC' => 'XCD', 'VC' => 'XCD',
        'TT' => 'TTD', 'US' => 'USD',
        // América del Sur
        'BR' => 'BRL', 'BO' => 'BOB', 'CO' => 'COP', 'CL' => 'CLP', 'FK' => 'FKP', 'GY' => 'GYD', 'PY' => 'PYG',
        'PE' => 'PEN', 'UY' => 'UYU',
        // Europa
        'AL' => 'ALL', 'AD' => 'EUR', 'AT' => 'EUR', 'BE' => 'EUR', 'BA' => 'BAM', 'HR' => 'EUR', 'CY' => 'EUR',
        'CZ' => 'CZK', 'DK' => 'DKK', 'EE' => 'EUR', 'FI' => 'EUR', 'FR' => 'EUR', 'DE' => 'EUR', 'GI' => 'GIP',
        'GR' => 'EUR', 'HU' => 'HUF', 'IS' => 'ISK', 'IE' => 'EUR', 'IT' => 'EUR', 'LV' => 'EUR', 'LT' => 'EUR',
        'LU' => 'EUR', 'MT' => 'EUR', 'MC' => 'EUR', 'MD' => 'MDL', 'ME' => 'EUR', 'NL' => 'EUR', 'MK' => 'MKD',
        'NO' => 'NOK', 'PL' => 'PLN', 'PT' => 'EUR', 'RO' => 'RON', 'SM' => 'EUR', 'RS' => 'RSD', 'SK' => 'EUR',
        'SI' => 'EUR', 'ES' => 'EUR', 'SE' => 'SEK', 'CH' => 'CHF', 'UA' => 'UAH', 'GB' => 'GBP', 'VA' => 'EUR',
        // Asia
        'AF' => 'AFN', 'AM' => 'AMD', 'AZ' => 'AZN', 'BD' => 'BDT', 'BN' => 'BND', 'KH' => 'KHR', 'CN' => 'CNY',
        'GE' => 'GEL', 'HK' => 'HKD', 'IN' => 'INR', 'ID' => 'IDR', 'IL' => 'ILS', 'JP' => 'JPY', 'KZ' => 'KZT',
        'KG' => 'KGS', 'MO' => 'MOP', 'MY' => 'MYR', 'MV' => 'MVR', 'MN' => 'MNT', 'NP' => 'NPR', 'PK' => 'PKR',
        'PH' => 'PHP', 'QA' => 'QAR', 'SA' => 'SAR', 'SG' => 'SGD', 'KR' => 'KRW', 'LK' => 'LKR', 'TW' => 'TWD',
        'TJ' => 'TJS', 'TH' => 'THB', 'TR' => 'TRY', 'AE' => 'AED', 'UZ' => 'UZS', 'VN' => 'VND', 'YE' => 'YER',
        // Oceanía
        'AU' => 'AUD', 'PF' => 'XPF', 'NC' => 'XPF', 'NZ' => 'NZD', 'WF' => 'XPF',
        // África
        'AO' => 'AOA', 'DZ' => 'DZD', 'BJ' => 'XOF', 'BW' => 'BWP', 'BF' => 'XOF', 'BI' => 'BIF', 'CM' => 'XAF',
        'CV' => 'CVE', 'CF' => 'XAF', 'TD' => 'XAF', 'CI' => 'XOF', 'DJ' => 'DJF', 'GQ' => 'XAF', 'GA' => 'XAF',
        'GM' => 'GMD', 'GN' => 'GNF', 'GW' => 'XOF', 'KE' => 'KES', 'LR' => 'LRD', 'MG' => 'MGA', 'ML' => 'XOF',
        'MU' => 'MUR', 'MA' => 'MAD', 'MZ' => 'MZN', 'NA' => 'NAD', 'NE' => 'XOF', 'CG' => 'XAF', 'RW' => 'RWF',
        'SH' => 'SHP', 'ST' => 'STN', 'SN' => 'XOF', 'ZA' => 'ZAR', 'TZ' => 'TZS', 'TG' => 'XOF', 'UG' => 'UGX',
        'ZM' => 'ZMW',
    ];
}

// El país de una zona horaria. Chrome (ICU) y Java todavía dan nombres viejos que getLocation() no ubica.
function tienda_pais_de_zona($zona) {
    if (!is_string($zona) || !preg_match('#^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$#', $zona)) return null;
    $viejas = ['Asia/Calcutta' => 'IN', 'Asia/Katmandu' => 'NP', 'Asia/Saigon' => 'VN', 'Asia/Dacca' => 'BD',
               'Asia/Ulan_Bator' => 'MN', 'Asia/Macao' => 'MO', 'Asia/Istanbul' => 'TR', 'Asia/Tel_Aviv' => 'IL',
               'Europe/Kiev' => 'UA', 'Europe/Belfast' => 'GB', 'America/Indianapolis' => 'US',
               'America/Louisville' => 'US', 'America/Fort_Wayne' => 'US', 'America/Coral_Harbour' => 'CA',
               'America/Montreal' => 'CA'];
    if (isset($viejas[$zona])) return $viejas[$zona];
    foreach (['US/' => 'US', 'Canada/' => 'CA', 'Mexico/' => 'MX', 'Australia/' => 'AU', 'Brazil/' => 'BR', 'Chile/' => 'CL'] as $prefijo => $pais) {
        if (strpos($zona, $prefijo) === 0) return $pais;
    }
    try {
        $lugar = (new DateTimeZone($zona))->getLocation();
    } catch (Exception $e) {
        return null;
    }
    return is_array($lugar) && isset($lugar['country_code']) && preg_match('/^[A-Z]{2}$/', $lugar['country_code']) ? $lugar['country_code'] : null;
}

// El país de quien pide, con las pistas de la petición (region, zona, idioma). null si ninguna sirve.
// Dos zonas piden que otra pista confirme el país: muchas computadoras de México quedaron con la de Centroamérica
// (Guatemala) o la del centro de EE. UU. cuando se quitó el horario de verano.
function tienda_pais_de(array $q) {
    $region = isset($q['region']) && is_string($q['region']) && preg_match('/^[A-Za-z]{2}$/', $q['region']) ? strtoupper($q['region']) : null;
    $idiomas = [];
    if (isset($q['idioma']) && is_string($q['idioma'])) {
        foreach (array_slice(explode(',', $q['idioma']), 0, 6) as $etiqueta) {
            if (preg_match('/^[a-z]{2,3}[-_]([A-Za-z]{2})$/i', trim($etiqueta), $m)) $idiomas[] = strtoupper($m[1]);
        }
    }
    $zona = isset($q['zona']) && is_string($q['zona']) ? $q['zona'] : '';
    $de_zona = tienda_pais_de_zona($zona);
    if (in_array($zona, ['America/Guatemala', 'America/Chicago'], true) && $region !== $de_zona && !in_array($de_zona, $idiomas, true)) {
        $de_zona = null;
    }
    $pistas = array_values(array_filter(array_merge([$region, $de_zona], $idiomas)));
    if (in_array('MX', $pistas, true)) return 'MX';
    return $pistas ? $pistas[0] : null;
}

// GET de un JSON con tiempo límite corto (alguien espera la página). null si falla.
function tienda_bajar_json($url) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
                            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2]);
    $cuerpo = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = $codigo === 200 && is_string($cuerpo) ? json_decode($cuerpo, true) : null;
    return is_array($json) ? $json : null;
}

// Cuánto vale un peso en cada moneda, del día: ['fecha' => Y-m-d, 'tasas' => ['EUR' => 0.0483, …]] o null.
// Fuente gratuita y sin llave (github.com/fawazahmed0/exchange-api, CC0), en dos espejos. Se guarda junto a
// config_db.php para no pedirla en cada visita; si la fuente no contesta, sirve la guardada hasta TIENDA_TASAS_VIGENCIA días.
function tienda_tasas(array $cfg) {
    $archivo = isset($cfg['tasas_archivo']) ? $cfg['tasas_archivo'] : dirname(__DIR__) . '/tasas_mxn.json';
    $guardadas = is_readable($archivo) ? json_decode((string) @file_get_contents($archivo), true) : null;
    if (!is_array($guardadas)) $guardadas = [];
    $bajadas = isset($guardadas['bajadas']) ? (int) $guardadas['bajadas'] : 0;
    $intento = isset($guardadas['intento']) ? (int) $guardadas['intento'] : 0;
    $vigentes = $bajadas > 0 && isset($guardadas['tasas']) && is_array($guardadas['tasas'])
              && time() - $bajadas < TIENDA_TASAS_VIGENCIA * 86400 ? $guardadas : null;
    if (time() - $bajadas < TIENDA_TASAS_HORAS * 3600 || time() - $intento < TIENDA_TASAS_REINTENTO) return $vigentes;

    $urls = isset($cfg['tasas_urls']) ? $cfg['tasas_urls'] : [
        'https://latest.currency-api.pages.dev/v1/currencies/mxn.json',
        'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/mxn.json',
    ];
    foreach ($urls as $url) {
        $j = tienda_bajar_json($url);
        if (!$j || !isset($j['mxn']) || !is_array($j['mxn']) || !isset($j['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $j['date'])) continue;
        $tasas = [];
        foreach ($j['mxn'] as $moneda => $tasa) {
            if (is_string($moneda) && preg_match('/^[a-z]{3}$/', $moneda) && is_numeric($tasa) && $tasa > 0) $tasas[strtoupper($moneda)] = (float) $tasa;
        }
        if (count($tasas) < 20 || !isset($tasas['USD'], $tasas['EUR'])) continue;
        $nuevas = ['fecha' => $j['date'], 'bajadas' => time(), 'tasas' => $tasas];
        if (@file_put_contents($archivo, json_encode($nuevas), LOCK_EX) === false) error_log('tienda: no se pudo guardar ' . $archivo);
        return $nuevas;
    }
    error_log('tienda: no se pudieron bajar los tipos de cambio');
    @file_put_contents($archivo, json_encode(array_merge($guardadas, ['intento' => time()])), LOCK_EX);
    return $vigentes;
}

// Lo que necesita quien enseña precios en otra moneda: ['pais', 'moneda', 'por_peso' (con la conversión de Stripe),
// 'fecha']. null = enseñar pesos (México, país desconocido, fuera de los mercados de Stripe o sin tipo de cambio).
function tienda_moneda_local(array $cfg, $pais) {
    $monedas = tienda_monedas_por_pais();
    if (!is_string($pais) || !isset($monedas[$pais]) || $monedas[$pais] === 'MXN') return null;
    $tasas = tienda_tasas($cfg);
    $moneda = $monedas[$pais];
    if ($tasas === null || empty($tasas['tasas'][$moneda])) return null;
    return ['pais' => $pais, 'moneda' => $moneda, 'por_peso' => round($tasas['tasas'][$moneda] * TIENDA_CONVERSION, 8),
            'fecha' => $tasas['fecha']];
}

// ── Fechas (Y-m-d, sin hora) ────────────────────────────────────────────────
function tienda_fecha($ymd) {
    return DateTime::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
}

// Suma meses sin desbordar: 31-ene + 1 mes = 28/29-feb (DateTime::modify daría 3-mar).
function tienda_sumar_meses($ymd, $meses) {
    $d = tienda_fecha($ymd);
    $dia = (int) $d->format('j');
    $d->modify('first day of this month');
    $d->modify('+' . (int) $meses . ' months');
    $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min($dia, (int) $d->format('t')));
    return $d->format('Y-m-d');
}

function tienda_sumar_dias($ymd, $dias) {
    $d = tienda_fecha($ymd);
    $d->modify('+' . (int) $dias . ' days');
    return $d->format('Y-m-d');
}

function tienda_min_fecha($a, $b) { return strcmp($a, $b) <= 0 ? $a : $b; }
function tienda_max_fecha($a, $b) {
    if ($a === null) return $b;
    if ($b === null) return $a;
    return strcmp($a, $b) >= 0 ? $a : $b;
}

// ── Código de la compra ─────────────────────────────────────────────────────
function tienda_codigo_nuevo() {
    $s = '';
    $n = strlen(TIENDA_ALFABETO_CODIGO);
    for ($i = 0; $i < 12; $i++) $s .= TIENDA_ALFABETO_CODIGO[random_int(0, $n - 1)];
    return $s;
}

// «ap-acde-fghj-kmnp», «ACDEFGHJKMNP»… → «ACDEFGHJKMNP»; null si no tiene forma de código
function tienda_codigo_normalizar($texto) {
    $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $texto));
    if (strlen($t) === 14 && strpos($t, 'AP') === 0) $t = substr($t, 2);
    return preg_match('/^[A-Z0-9]{12}$/', $t) ? $t : null;
}

function tienda_codigo_mostrar($codigo) {
    return 'AP-' . substr($codigo, 0, 4) . '-' . substr($codigo, 4, 4) . '-' . substr($codigo, 8, 4);
}

// ── Firmar la clave (el mismo formato que LicenciaFirmada del POS) ───────────
function tienda_base32($bin) {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = ''; $buffer = 0; $bits = 0;
    $n = strlen($bin);
    for ($i = 0; $i < $n; $i++) {
        $buffer = (($buffer << 8) | ord($bin[$i])) & 0xFFFF;
        $bits += 8;
        while ($bits >= 5) {
            $s .= $alfabeto[($buffer >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    if ($bits > 0) $s .= $alfabeto[($buffer << (5 - $bits)) & 31];
    return $s;
}

// openssl firma en DER (SEQUENCE de dos INTEGER); en la clave va r||s en 64 bytes fijos.
function tienda_der_a_rs($der) {
    $i = 0;
    if (strlen($der) < 8 || ord($der[$i++]) !== 0x30) throw new RuntimeException('Firma DER mal formada');
    $largo = ord($der[$i++]);
    if ($largo === 0x81) $largo = ord($der[$i++]);
    $rs = '';
    for ($p = 0; $p < 2; $p++) {
        if (ord($der[$i++]) !== 0x02) throw new RuntimeException('Firma DER mal formada (entero)');
        $n = ord($der[$i++]);
        $entero = ltrim(substr($der, $i, $n), "\x00");
        $i += $n;
        if (strlen($entero) > 32) throw new RuntimeException('Firma DER mal formada (tamaño)');
        $rs .= str_pad($entero, 32, "\x00", STR_PAD_LEFT);
    }
    return $rs;
}

// La clave de 125 caracteres para esa PC. $tipo: 'premium' o 'multi'. Antes de entregarla se verifica con la
// llave PÚBLICA (como la consola): una clave que el programa no aceptaría nunca sale de aquí.
function tienda_firmar(array $cfg, $tipo, $hw, $vence) {
    $dias = (int) round((tienda_fecha($vence)->getTimestamp() - tienda_fecha('2020-01-01')->getTimestamp()) / 86400);
    if ($dias < 0 || $dias > 0xFFFF) throw new RuntimeException("Vencimiento fuera de rango: $vence");
    if (!dash_equipo_valido($hw)) throw new RuntimeException("Hardware ID no válido: $hw");
    if (empty($cfg['llave_ventas']) || !is_readable($cfg['llave_ventas'])) {
        throw new RuntimeException('Falta la llave de ventas (config_db.php: llave_ventas)');
    }
    $privada = openssl_pkey_get_private(file_get_contents($cfg['llave_ventas']));
    if ($privada === false) throw new RuntimeException('La llave de ventas no se pudo leer');
    $nonce = random_int(0, 0xFFFFFF);
    $datos = chr(TIENDA_KID) . chr($dias >> 8) . chr($dias & 0xFF)
           . chr($nonce >> 16) . chr(($nonce >> 8) & 0xFF) . chr($nonce & 0xFF) . hex2bin($hw);
    $dominio = $tipo === 'multi' ? DASH_LIC_DOMINIO_MULTI : DASH_LIC_DOMINIO;
    $der = '';
    if (!openssl_sign($dominio . $datos, $der, $privada, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('openssl_sign falló');
    }
    $clave = tienda_base32($datos . tienda_der_a_rs($der));
    $lic = dash_licencia($cfg, $clave);
    if ($lic === null || $lic['hw'] !== $hw || $lic['vence'] !== $vence || $lic['plan'] !== $tipo || $lic['kid'] !== TIENDA_KID) {
        throw new RuntimeException('La clave recién firmada no pasa la verificación (¿la pública 2 no es de esta privada?)');
    }
    return $clave;
}

// ── Claves retiradas (reembolsos y contracargos, 2026-10-30) ───────────────
// ¿Ya corrió sql/2026-10-30_reembolsos.sql? Sin ella todo sigue como antes: no se retira nada.
function tienda_con_retiros(PDO $conn) {
    static $hay = null;
    if ($hay === null) {
        $hay = (int) $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                   AND TABLE_NAME = 'tienda_claves' AND COLUMN_NAME = 'retirada'")->fetchColumn() > 0;
    }
    return $hay;
}

// Para sumar al WHERE de una consulta sobre tienda_claves: solo las que siguen valiendo ($alias: «k.»).
function tienda_vigentes(PDO $conn, $alias = '') {
    return tienda_con_retiros($conn) ? " AND {$alias}retirada IS NULL" : '';
}

// Guarda una clave emitida. $pago_id: el pago que la generó (null si es un cambio de PC o el celular).
function tienda_guardar_clave(PDO $conn, $compra_id, $hw, $tipo, $pagado, $vence, $clave, $pago_id = null) {
    $con_pago = $pago_id !== null && tienda_con_retiros($conn);
    $conn->prepare('INSERT INTO tienda_claves (compra_id, hardware_id, tipo, vence_pagado, vence, clave, creada'
                   . ($con_pago ? ', pago_id' : '') . ') VALUES (:c, :hw, :t, :p, :v, :k, NOW()' . ($con_pago ? ', :pago' : '') . ')')
         ->execute([':c' => $compra_id, ':hw' => $hw, ':t' => $tipo, ':p' => $pagado, ':v' => $vence, ':k' => $clave]
                   + ($con_pago ? [':pago' => $pago_id] : []));
}

// ── Lo que cada PC ya tiene ─────────────────────────────────────────────────
// Hasta dónde llega lo pagado de ese plan en esa PC: lo que entregó la tienda (sin la gracia y sin lo reembolsado) o lo
// que ya tenía (claves de la consola que el programa reportó). null si nada.
function tienda_vence_actual(PDO $conn, $hw, $tipo) {
    $st = $conn->prepare('SELECT MAX(vence_pagado) FROM tienda_claves WHERE hardware_id = :hw AND tipo = :t' . tienda_vigentes($conn));
    $st->execute([':hw' => $hw, ':t' => $tipo]);
    $tienda = $st->fetchColumn() ?: null;
    $st = $conn->prepare('SELECT vence FROM tienda_reportados WHERE hardware_id = :hw AND tipo = :t');
    $st->execute([':hw' => $hw, ':t' => $tipo]);
    $reportado = $st->fetchColumn() ?: null;
    return tienda_max_fecha($tienda, $reportado);
}

// Anota las claves que ya tiene una PC (el programa las manda): verificadas con la llave pública, de esa PC.
// Las de la tienda (llave 2) no: ya están en tienda_claves sin la gracia, y contarlas aquí la sumaría cada mes.
function tienda_registrar_reportadas(PDO $conn, array $cfg, $hw, $claves) {
    if (!is_string($claves) || $claves === '') return;
    $lista = array_slice(explode(',', $claves), 0, 20);
    foreach ($lista as $texto) {
        $lic = dash_licencia($cfg, trim($texto));
        if ($lic === null || $lic['hw'] !== $hw || $lic['kid'] === TIENDA_KID) continue;
        $conn->prepare('INSERT INTO tienda_reportados (hardware_id, tipo, vence) VALUES (:hw, :t, :v)
                        ON DUPLICATE KEY UPDATE vence = GREATEST(vence, VALUES(vence))')
             ->execute([':hw' => $hw, ':t' => $lic['plan'], ':v' => $lic['vence']]);
    }
}

// ── Compras y pagos ─────────────────────────────────────────────────────────
function tienda_compra_por(PDO $conn, $campo, $valor, $bloquear = false) {
    $campos = ['id' => 'id', 'codigo' => 'codigo', 'sesion' => 'stripe_sesion', 'suscripcion' => 'stripe_suscripcion'];
    $st = $conn->prepare('SELECT * FROM tienda_compras WHERE ' . $campos[$campo] . ' = :v ORDER BY id LIMIT 1'
                         . ($bloquear ? ' FOR UPDATE' : ''));
    $st->execute([':v' => $valor]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    return $f ?: null;
}

// Convierte en claves los pagos de la compra que aún no lo son. Solo si la compra ya tiene PC.
// Cada pago se suma desde lo más lejano entre hoy y lo que esa PC ya tenía de ese plan: renovar antes de tiempo
// no pierde días, y quien llega vencido empieza hoy. Debe correr dentro de una transacción.
// Devuelve las claves nuevas.
function tienda_aplicar(PDO $conn, array $cfg, array $compra) {
    $hw = $compra['hardware_id'];
    if (!$hw) return [];
    $st = $conn->prepare('SELECT id, meses FROM tienda_pagos WHERE compra_id = :c AND aplicado = 0 ORDER BY id FOR UPDATE');
    $st->execute([':c' => $compra['id']]);
    $nuevas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pago) {
        $actual = tienda_vence_actual($conn, $hw, $compra['tipo']);
        if (tienda_es_prueba($compra)) {
            // Modo de prueba: siempre 2 días desde hoy, sin sumarse a nada (y sin contar para lo que la PC ya tiene)
            $pagado = tienda_sumar_dias(dash_hoy_mx(), TIENDA_DIAS_PRUEBA);
            $clave = tienda_firmar($cfg, $compra['tipo'], $hw, $pagado);
            tienda_guardar_clave($conn, $compra['id'], $hw, $compra['tipo'], dash_hoy_mx(), $pagado, $clave, $pago['id']);
            $nuevas[] = $clave;
        } elseif ($actual !== TIENDA_PARA_SIEMPRE) {
            if ((int) $pago['meses'] === 0) {
                $pagado = TIENDA_PARA_SIEMPRE;
                $vence = TIENDA_PARA_SIEMPRE;
            } else {
                $base = tienda_max_fecha(dash_hoy_mx(), $actual);
                $pagado = tienda_min_fecha(tienda_sumar_meses($base, (int) $pago['meses']), TIENDA_PARA_SIEMPRE);
                $vence = tienda_min_fecha(tienda_sumar_dias($pagado, TIENDA_GRACIA_DIAS), TIENDA_PARA_SIEMPRE);
            }
            $clave = tienda_firmar($cfg, $compra['tipo'], $hw, $vence);
            tienda_guardar_clave($conn, $compra['id'], $hw, $compra['tipo'], $pagado, $vence, $clave, $pago['id']);
            $nuevas[] = $clave;
        }
        // Ya es para siempre: un pago más no cambia nada (pagar.php no lo deja comprar)
        $conn->prepare('UPDATE tienda_pagos SET aplicado = 1 WHERE id = :id')->execute([':id' => $pago['id']]);
    }
    // El celular que incluye la compra se renueva con la PC
    if ($nuevas) tienda_celular_al_dia($conn, $cfg, $compra);
    return $nuevas;
}

// Lo que Stripe confirmó de una sesión de cobro (webhook): crea o actualiza la compra y, si quedó pagada, anota el
// pago y entrega la clave si se sabe la PC. Repetido (Stripe reintenta) no duplica nada.
// $s: sesion, plan, hw, origen, email, monto, suscripcion, pagada (bool) y, opcionales, intento (pi_…) y cliente (cus_…).
// Devuelve ['compra' => fila, 'nuevas' => claves, 'recien_pagada' => bool].
function tienda_registrar_sesion(PDO $conn, array $cfg, array $s) {
    $plan = tienda_plan($s['plan']);
    if ($plan === null) throw new RuntimeException('Plan desconocido: ' . $s['plan']);
    $conn->beginTransaction();
    try {
        $compra = tienda_compra_por($conn, 'sesion', $s['sesion'], true);
        $recien = false;
        if ($compra === null) {
            $codigo = tienda_codigo_nuevo();
            while (tienda_compra_por($conn, 'codigo', $codigo) !== null) $codigo = tienda_codigo_nuevo();
            $conn->prepare('INSERT INTO tienda_compras (codigo, plan, tipo, hardware_id, origen, estado, email, monto,
                                                        stripe_sesion, stripe_suscripcion, creada, pagada)
                            VALUES (:c, :p, :t, :hw, :o, :e, :m, :mo, :s, :su, NOW(), ' . ($s['pagada'] ? 'NOW()' : 'NULL') . ')')
                 ->execute([':c' => $codigo, ':p' => $s['plan'], ':t' => $plan['tipo'], ':hw' => $s['hw'],
                            ':o' => $s['origen'], ':e' => $s['pagada'] ? 'pagada' : 'pendiente', ':m' => $s['email'],
                            ':mo' => (int) $s['monto'], ':s' => $s['sesion'], ':su' => $s['suscripcion']]);
            $compra = tienda_compra_por($conn, 'sesion', $s['sesion'], true);
            $recien = $s['pagada'];
        } elseif ($s['pagada'] && $compra['estado'] !== 'pagada') {
            $conn->prepare('UPDATE tienda_compras SET estado = \'pagada\', pagada = NOW(),
                                   email = COALESCE(email, :e), stripe_suscripcion = COALESCE(stripe_suscripcion, :su)
                             WHERE id = :id')
                 ->execute([':e' => $s['email'], ':su' => $s['suscripcion'], ':id' => $compra['id']]);
            $compra = tienda_compra_por($conn, 'id', $compra['id'], true);
            $recien = true;
        }
        if (!empty($s['cliente']) && tienda_con_retiros($conn)) {
            $conn->prepare('UPDATE tienda_compras SET stripe_cliente = COALESCE(stripe_cliente, :cl) WHERE id = :id')
                 ->execute([':cl' => $s['cliente'], ':id' => $compra['id']]);
        }
        $nuevas = [];
        if ($compra['estado'] === 'pagada') {
            $conn->prepare('INSERT IGNORE INTO tienda_pagos (compra_id, stripe_ref, meses, creado) VALUES (:c, :r, :m, NOW())')
                 ->execute([':c' => $compra['id'], ':r' => $s['sesion'], ':m' => $plan['meses']]);
            tienda_anotar_cobro($conn, $s['sesion'], isset($s['intento']) ? $s['intento'] : null);
            $nuevas = tienda_aplicar($conn, $cfg, $compra);
        }
        $conn->commit();
        return ['compra' => $compra, 'nuevas' => $nuevas, 'recien_pagada' => $recien];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

// Un pago de OXXO que venció sin pagarse.
function tienda_marcar_vencida(PDO $conn, $sesion) {
    $conn->prepare('UPDATE tienda_compras SET estado = \'vencida\' WHERE stripe_sesion = :s AND estado = \'pendiente\'')
         ->execute([':s' => $sesion]);
}

// La renovación de una suscripción (cada mes): un mes más para la PC de esa compra. null si la suscripción no es
// de una compra conocida.
function tienda_renovar(PDO $conn, array $cfg, $suscripcion, $factura, $intento = null) {
    $conn->beginTransaction();
    try {
        $compra = tienda_compra_por($conn, 'suscripcion', $suscripcion, true);
        if ($compra === null) {
            $conn->rollBack();
            return null;
        }
        $plan = tienda_plan($compra['plan']);
        $conn->prepare('INSERT IGNORE INTO tienda_pagos (compra_id, stripe_ref, meses, creado) VALUES (:c, :r, :m, NOW())')
             ->execute([':c' => $compra['id'], ':r' => $factura, ':m' => $plan ? $plan['meses'] : 1]);
        tienda_anotar_cobro($conn, $factura, $intento);
        $nuevas = tienda_aplicar($conn, $cfg, $compra);
        $conn->commit();
        return ['compra' => $compra, 'nuevas' => $nuevas];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

// Las claves que la tienda entregó a esa PC y siguen valiendo (las más nuevas primero).
function tienda_claves_de(PDO $conn, $hw) {
    $st = $conn->prepare('SELECT clave FROM tienda_claves WHERE hardware_id = :hw' . tienda_vigentes($conn) . ' ORDER BY id DESC LIMIT 50');
    $st->execute([':hw' => $hw]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// Las que se le retiraron por un reembolso o un contracargo: el programa y la app las borran al preguntar.
function tienda_claves_retiradas(PDO $conn, $hw) {
    if (!tienda_con_retiros($conn)) return [];
    $st = $conn->prepare('SELECT clave FROM tienda_claves WHERE hardware_id = :hw AND retirada IS NOT NULL ORDER BY id DESC LIMIT 50');
    $st->execute([':hw' => $hw]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// ── Canjear el código: atar la compra a una PC, o cambiarla de PC ────────────
// Devuelve ['ok' => bool, 'motivo' => no_existe|pendiente|no_pagada|cambio_reciente|vencida|ok, 'mensaje' => texto
// para el cliente, 'claves' => [...]].
function tienda_canjear(PDO $conn, array $cfg, $codigo, $hw) {
    $conn->beginTransaction();
    try {
        $compra = tienda_compra_por($conn, 'codigo', $codigo, true);
        if ($compra === null) {
            $conn->rollBack();
            return ['ok' => false, 'motivo' => 'no_existe', 'mensaje' => 'Ese código no existe. Revisa que esté completo (AP-XXXX-XXXX-XXXX).', 'claves' => []];
        }
        if ($compra['estado'] === 'pendiente') {
            $conn->rollBack();
            return ['ok' => false, 'motivo' => 'pendiente', 'mensaje' => 'Ese pago todavía no se confirma. Si pagaste en OXXO, puede tardar unas horas.', 'claves' => []];
        }
        if ($anulada = tienda_compra_anulada($conn, $compra)) {
            $conn->rollBack();
            return ['ok' => false, 'motivo' => $anulada[0], 'mensaje' => $anulada[1], 'claves' => []];
        }
        if ($compra['estado'] !== 'pagada') {
            $conn->rollBack();
            return ['ok' => false, 'motivo' => 'no_pagada', 'mensaje' => 'Ese pago no se completó (la ficha de OXXO venció).', 'claves' => []];
        }
        $nombre = tienda_plan($compra['plan']) ? tienda_plan($compra['plan'])['nombre'] : 'Premium';

        if (!$compra['hardware_id']) {
            // Comprado en la página: la primera PC que lo canjea se queda con él (no cuenta como cambio)
            $conn->prepare('UPDATE tienda_compras SET hardware_id = :hw WHERE id = :id')->execute([':hw' => $hw, ':id' => $compra['id']]);
            $compra['hardware_id'] = $hw;
            tienda_aplicar($conn, $cfg, $compra);
            $conn->commit();
            return ['ok' => true, 'motivo' => 'ok', 'mensaje' => "$nombre activado en esta computadora.", 'claves' => tienda_claves_compra($conn, $compra['id'], $hw)];
        }
        if ($compra['hardware_id'] === $hw) {
            // La misma PC (reinstaló el programa): sus claves otra vez
            tienda_aplicar($conn, $cfg, $compra);
            $conn->commit();
            return ['ok' => true, 'motivo' => 'ok', 'mensaje' => "$nombre ya era de esta computadora: se activó otra vez.", 'claves' => tienda_claves_compra($conn, $compra['id'], $hw)];
        }

        // Otra PC: un cambio cada 12 meses
        $hoy = dash_hoy_mx();
        if ($compra['ultimo_cambio_pc']) {
            $siguiente = tienda_sumar_meses(substr($compra['ultimo_cambio_pc'], 0, 10), TIENDA_CAMBIO_PC_MESES);
            if (strcmp($hoy, $siguiente) < 0) {
                $conn->rollBack();
                $f = tienda_fecha($siguiente)->format('d/m/Y');
                return ['ok' => false, 'motivo' => 'cambio_reciente', 'mensaje' => "Esta compra ya cambió de computadora este año. El siguiente cambio automático "
                        . "es a partir del $f; si lo necesitas antes, escríbenos por WhatsApp al 55 8432 3945.", 'claves' => []];
            }
        }
        $st = $conn->prepare('SELECT MAX(vence_pagado) FROM tienda_claves WHERE compra_id = :c' . tienda_vigentes($conn));
        $st->execute([':c' => $compra['id']]);
        $pagado = $st->fetchColumn() ?: null;
        if ($pagado === null || strcmp($pagado, $hoy) < 0) {
            $conn->rollBack();
            return ['ok' => false, 'motivo' => 'vencida', 'mensaje' => 'Esa compra ya venció: no hay nada que pasar a esta computadora.', 'claves' => []];
        }
        $vence = tienda_es_prueba($compra) ? tienda_sumar_dias($hoy, TIENDA_DIAS_PRUEBA)
               : ($pagado === TIENDA_PARA_SIEMPRE ? $pagado
               : tienda_min_fecha(tienda_sumar_dias($pagado, TIENDA_GRACIA_DIAS), TIENDA_PARA_SIEMPRE));
        $clave = tienda_firmar($cfg, $compra['tipo'], $hw, $vence);
        tienda_guardar_clave($conn, $compra['id'], $hw, $compra['tipo'], $pagado, $vence, $clave);
        // Las renovaciones de una suscripción ya van a la PC nueva
        $conn->prepare('UPDATE tienda_compras SET hardware_id = :hw, ultimo_cambio_pc = NOW() WHERE id = :id')
             ->execute([':hw' => $hw, ':id' => $compra['id']]);
        $conn->commit();
        return ['ok' => true, 'motivo' => 'ok', 'mensaje' => "$nombre pasó a esta computadora. El siguiente cambio automático podrá ser en 12 meses.",
                'claves' => [$clave]];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

function tienda_claves_compra(PDO $conn, $compra_id, $hw) {
    $st = $conn->prepare('SELECT clave FROM tienda_claves WHERE compra_id = :c AND hardware_id = :hw' . tienda_vigentes($conn) . ' ORDER BY id DESC');
    $st->execute([':c' => $compra_id, ':hw' => $hw]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// ── Reembolsos y contracargos (2026-10-30) ──────────────────────────────────
// Un reembolso COMPLETO o un contracargo retira lo que dio ese pago: sus claves ya no se entregan (claves.php le dice
// al programa y a la app cuáles borrar), el código ya no se canjea, el celular no se renueva y no se suma a la siguiente
// compra. En un pago único (anual, para siempre) es toda la compra; en una mensualidad, ese mes. Un contracargo ganado
// lo devuelve. Un reembolso parcial no cambia nada. Lo decidió el dueño el 2026-10-30. Una PC sin internet conserva su
// clave hasta que pregunte (la firma se verifica sin conexión): eso no tiene arreglo del lado de la tienda.

// Anota con qué cobro de Stripe (pi_…) se hizo el pago $ref (la sesión o la factura): así se reconoce su reembolso.
function tienda_anotar_cobro(PDO $conn, $ref, $intento) {
    if (!is_string($intento) || $intento === '' || !tienda_con_retiros($conn)) return;
    $conn->prepare('UPDATE tienda_pagos SET payment_intent = :pi WHERE stripe_ref = :r AND payment_intent IS NULL')
         ->execute([':pi' => $intento, ':r' => $ref]);
}

// La primera factura de una mensualidad: su mes ya lo dio la sesión; solo se anota su cobro en el pago de la sesión.
// Si llega antes que la sesión no hay compra todavía: su reembolso se reconoce por el cliente (tienda_pago_de_cobro).
function tienda_anotar_cobro_inicial(PDO $conn, $suscripcion, $intento) {
    $compra = tienda_compra_por($conn, 'suscripcion', $suscripcion);
    if ($compra !== null) tienda_anotar_cobro($conn, $compra['stripe_sesion'], $intento);
}

// ¿La compra ya no vale? ['reembolsada' | 'disputada', mensaje para el cliente], o null. Una mensualidad sigue
// «pagada» aunque se reembolse un mes: no vale si ya no le queda ningún pago bueno.
function tienda_compra_anulada(PDO $conn, array $compra) {
    $estado = $compra['estado'];
    if ($estado === 'pagada' && tienda_con_retiros($conn)) {
        $st = $conn->prepare('SELECT COUNT(*) AS total, SUM(reembolsado IS NULL AND disputado IS NULL) AS buenos,
                                     SUM(disputado IS NOT NULL) AS disputados FROM tienda_pagos WHERE compra_id = :c');
        $st->execute([':c' => $compra['id']]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if ((int) $f['total'] > 0 && (int) $f['buenos'] === 0) $estado = (int) $f['disputados'] > 0 ? 'disputada' : 'reembolsada';
    }
    if ($estado === 'reembolsada') return ['reembolsada', 'Esa compra se reembolsó: ya no activa Premium.'];
    if ($estado === 'disputada') {
        return ['disputada', 'Ese pago está en aclaración con el banco: mientras se resuelve, no activa Premium. '
                . 'Si tienes dudas, escríbenos por WhatsApp al 55 8432 3945.'];
    }
    return null;
}

// El pago de la tienda que corresponde a un cobro de Stripe: por su pi_… o, si no se conoce (la primera mensualidad
// pudo llegar antes que su sesión), el último pago bueno de la mensualidad de ese cliente. null si no es de la tienda.
function tienda_pago_de_cobro(PDO $conn, $intento, $cliente) {
    if (is_string($intento) && $intento !== '') {
        $st = $conn->prepare('SELECT * FROM tienda_pagos WHERE payment_intent = :pi ORDER BY id LIMIT 1 FOR UPDATE');
        $st->execute([':pi' => $intento]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if ($f) return $f;
    }
    if (is_string($cliente) && $cliente !== '') {
        $st = $conn->prepare('SELECT p.* FROM tienda_pagos p JOIN tienda_compras c ON c.id = p.compra_id
                               WHERE c.stripe_cliente = :cl AND c.stripe_suscripcion IS NOT NULL
                                 AND p.reembolsado IS NULL AND p.disputado IS NULL
                               ORDER BY p.id DESC LIMIT 1 FOR UPDATE');
        $st->execute([':cl' => $cliente]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if ($f) return $f;
    }
    return null;
}

// Retira lo que dio el pago de ese cobro. $motivo: 'reembolso' | 'disputa'. Devuelve ['compra' => fila,
// 'retiradas' => cuántas claves], o null si el cobro no es de la tienda (o falta la migración).
function tienda_retirar_pago(PDO $conn, $intento, $cliente, $motivo) {
    if (!tienda_con_retiros($conn)) return null;
    $conn->beginTransaction();
    try {
        $pago = tienda_pago_de_cobro($conn, $intento, $cliente);
        if ($pago === null) {
            $conn->rollBack();
            return null;
        }
        $compra = tienda_compra_por($conn, 'id', $pago['compra_id'], true);
        $columna = $motivo === 'reembolso' ? 'reembolsado' : 'disputado';
        $conn->prepare("UPDATE tienda_pagos SET $columna = COALESCE($columna, NOW()) WHERE id = :id")->execute([':id' => $pago['id']]);
        $etiqueta = $motivo . ':' . $pago['id'];   // al ganar el contracargo se devuelven justo estas
        $retiradas = 0;
        $retirar = function ($donde, array $valores) use ($conn, $etiqueta, &$retiradas) {
            $st = $conn->prepare("UPDATE tienda_claves SET retirada = NOW(), retiro = :etq WHERE retirada IS NULL AND $donde");
            $st->execute([':etq' => $etiqueta] + $valores);
            $retiradas += $st->rowCount();
        };
        $plan = tienda_plan($compra['plan']);
        if ($plan === null || $plan['modo'] !== 'suscripcion') {
            // Pago único: toda la compra (su PC, la PC a la que se cambió y el celular que incluye)
            $retirar('compra_id = :c', [':c' => $compra['id']]);
            $conn->prepare('UPDATE tienda_compras SET estado = :e WHERE id = :id')
                 ->execute([':e' => $motivo === 'reembolso' ? 'reembolsada' : 'disputada', ':id' => $compra['id']]);
        } else {
            // Mensualidad: la clave de ese mes y todo lo firmado más allá de lo que cubren los meses que quedan
            $retirar('pago_id = :p', [':p' => $pago['id']]);
            $st = $conn->prepare('SELECT MAX(vence) FROM tienda_claves WHERE compra_id = :c AND pago_id IS NOT NULL AND retirada IS NULL');
            $st->execute([':c' => $compra['id']]);
            $retirar('compra_id = :c AND vence > :v', [':c' => $compra['id'], ':v' => $st->fetchColumn() ?: '2000-01-01']);
        }
        $conn->commit();
        return ['compra' => tienda_compra_por($conn, 'id', $compra['id']), 'retiradas' => $retiradas];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

// Un contracargo cerrado. Ganado (won) o una consulta cerrada sin cargo (warning_closed): se devuelve lo que se retiró
// por él. Perdido (lost): queda retirado. Devuelve la compra si se devolvió algo, o null.
function tienda_cerrar_disputa(PDO $conn, $intento, $estatus) {
    if (!tienda_con_retiros($conn) || !in_array($estatus, ['won', 'warning_closed'], true)
            || !is_string($intento) || $intento === '') {
        return null;
    }
    $conn->beginTransaction();
    try {
        $st = $conn->prepare('SELECT * FROM tienda_pagos WHERE payment_intent = :pi AND disputado IS NOT NULL ORDER BY id LIMIT 1 FOR UPDATE');
        $st->execute([':pi' => $intento]);
        $pago = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pago || $pago['reembolsado']) {   // se reembolsó mientras tanto: sigue retirado
            $conn->rollBack();
            return null;
        }
        $conn->prepare('UPDATE tienda_pagos SET disputado = NULL WHERE id = :id')->execute([':id' => $pago['id']]);
        $conn->prepare('UPDATE tienda_claves SET retirada = NULL, retiro = NULL WHERE retiro = :etq')
             ->execute([':etq' => 'disputa:' . $pago['id']]);
        $conn->prepare('UPDATE tienda_compras SET estado = \'pagada\' WHERE id = :id AND estado = \'disputada\'')
             ->execute([':id' => $pago['compra_id']]);
        $compra = tienda_compra_por($conn, 'id', $pago['compra_id']);
        $conn->commit();
        return $compra;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

// ── El celular que incluye el Premium de PC (2026-10-29) ───────────────────
// Cada compra de Premium (o de varias sucursales) para PC incluye UN celular con la app de Android. El teléfono se
// vincula con el código de la compra (tienda/celular.php) y recibe su propia clave, atada a su ANDROID_ID, de Premium.
// Sus claves duran a lo más TIENDA_CELULAR_DIAS y se renuevan solas mientras siga vinculado y la PC tenga su plan
// (al pagar la PC y cada vez que el teléfono pregunta en claves.php): así, cambiar de celular no deja a los dos con
// Premium para siempre. Cambiar de celular: uno cada TIENDA_CAMBIO_CELULAR_DIAS. Sin la migración 2026-10-29 nada
// de esto corre y la tienda sigue igual.
define('TIENDA_CELULAR_DIAS', 35);
define('TIENDA_CELULAR_RENOVAR_DIAS', 25);   // se firma otra cuando a la que tiene le quedan menos días que esto
define('TIENDA_CAMBIO_CELULAR_DIAS', 30);

// El celular vinculado a la compra, o null (también sin la tabla).
function tienda_celular_de(PDO $conn, $compra_id, $bloquear = false) {
    try {
        $st = $conn->prepare('SELECT hardware_id, vinculado FROM tienda_celulares WHERE compra_id = :c' . ($bloquear ? ' FOR UPDATE' : ''));
        $st->execute([':c' => $compra_id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        return $f ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

// Firma una clave nueva para el celular de la compra si la que tiene se acaba pronto y la PC aún tiene plan: hasta lo
// que tiene la PC, sin pasar de hoy + TIENDA_CELULAR_DIAS. Dentro de la transacción de quien llama. La clave o null.
function tienda_celular_al_dia(PDO $conn, array $cfg, array $compra) {
    $cel = tienda_celular_de($conn, $compra['id']);
    if ($cel === null || !$compra['hardware_id'] || $compra['estado'] !== 'pagada') return null;
    $st = $conn->prepare('SELECT vence_pagado, vence FROM tienda_claves WHERE compra_id = :c AND hardware_id = :hw'
                         . tienda_vigentes($conn) . ' ORDER BY vence DESC LIMIT 1');
    $st->execute([':c' => $compra['id'], ':hw' => $compra['hardware_id']]);
    $pc = $st->fetch(PDO::FETCH_ASSOC);
    $hoy = dash_hoy_mx();
    if (!$pc || strcmp($pc['vence'], $hoy) < 0) return null;
    $meta = tienda_min_fecha($pc['vence'], tienda_sumar_dias($hoy, TIENDA_CELULAR_DIAS));
    $st = $conn->prepare('SELECT MAX(vence) FROM tienda_claves WHERE compra_id = :c AND hardware_id = :hw' . tienda_vigentes($conn));
    $st->execute([':c' => $compra['id'], ':hw' => $cel['hardware_id']]);
    $tiene = $st->fetchColumn() ?: null;
    if ($tiene !== null && (strcmp($tiene, $meta) >= 0
            || strcmp($tiene, tienda_sumar_dias($hoy, TIENDA_CELULAR_RENOVAR_DIAS)) >= 0)) {
        return null;
    }
    $clave = tienda_firmar($cfg, 'premium', $cel['hardware_id'], $meta);
    tienda_guardar_clave($conn, $compra['id'], $cel['hardware_id'], 'premium', tienda_min_fecha($pc['vence_pagado'], $meta), $meta, $clave);
    return $clave;
}

// claves.php: si quien pregunta es un celular vinculado, su clave al día (de cada compra que lo incluye).
function tienda_celular_refrescar(PDO $conn, array $cfg, $hw) {
    try {
        $st = $conn->prepare('SELECT compra_id FROM tienda_celulares WHERE hardware_id = :hw');
        $st->execute([':hw' => $hw]);
        $compras = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return;
    }
    foreach ($compras as $id) {
        $conn->beginTransaction();
        try {
            $compra = tienda_compra_por($conn, 'id', $id, true);
            if ($compra !== null) tienda_celular_al_dia($conn, $cfg, $compra);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }
}

// Vincula el celular [hw] a la compra del código. Devuelve lo mismo que tienda_canjear: ['ok', 'motivo'
// (no_existe|pendiente|no_pagada|sin_pc|vencida|cambio_reciente|ok), 'mensaje', 'claves'].
function tienda_vincular_celular(PDO $conn, array $cfg, $codigo, $hw) {
    $conn->beginTransaction();
    try {
        $compra = tienda_compra_por($conn, 'codigo', $codigo, true);
        $no = function ($motivo, $mensaje) use ($conn) {
            $conn->rollBack();
            return ['ok' => false, 'motivo' => $motivo, 'mensaje' => $mensaje, 'claves' => []];
        };
        if ($compra === null) return $no('no_existe', 'Ese código no existe. Revisa que esté completo (AP-XXXX-XXXX-XXXX).');
        if ($compra['estado'] === 'pendiente') return $no('pendiente', 'Ese pago todavía no se confirma. Si pagaste en OXXO, puede tardar unas horas.');
        if ($anulada = tienda_compra_anulada($conn, $compra)) return $no($anulada[0], $anulada[1]);
        if ($compra['estado'] !== 'pagada') return $no('no_pagada', 'Ese pago no se completó (la ficha de OXXO venció).');
        if (!$compra['hardware_id']) {
            return $no('sin_pc', 'Primero activa ese Premium en tu computadora (Configuración > Premium) y después vincula el celular.');
        }
        $hoy = dash_hoy_mx();
        $st = $conn->prepare('SELECT MAX(vence) FROM tienda_claves WHERE compra_id = :c AND hardware_id = :hw' . tienda_vigentes($conn));
        $st->execute([':c' => $compra['id'], ':hw' => $compra['hardware_id']]);
        $vence = $st->fetchColumn() ?: null;
        if ($vence === null || strcmp($vence, $hoy) < 0) {
            return $no('vencida', 'Ese Premium ya venció. Renuévalo en la computadora y vuelve a intentar.');
        }
        $cel = tienda_celular_de($conn, $compra['id'], true);
        if ($cel === null) {
            $conn->prepare('INSERT INTO tienda_celulares (compra_id, hardware_id, vinculado) VALUES (:c, :hw, NOW())')
                 ->execute([':c' => $compra['id'], ':hw' => $hw]);
        } elseif ($cel['hardware_id'] !== $hw) {
            $siguiente = tienda_sumar_dias(substr($cel['vinculado'], 0, 10), TIENDA_CAMBIO_CELULAR_DIAS);
            if (strcmp($hoy, $siguiente) < 0) {
                $f = tienda_fecha($siguiente)->format('d/m/Y');
                return $no('cambio_reciente', "Ese Premium ya incluye otro celular. Puedes pasarlo a este a partir del $f; "
                    . 'si lo necesitas antes, escríbenos por WhatsApp al 55 8432 3945.');
            }
            $conn->prepare('UPDATE tienda_celulares SET hardware_id = :hw, vinculado = NOW() WHERE compra_id = :c')
                 ->execute([':c' => $compra['id'], ':hw' => $hw]);
        }
        tienda_celular_al_dia($conn, $cfg, $compra);
        $conn->commit();
        return ['ok' => true, 'motivo' => 'ok', 'mensaje' => 'Listo: el Premium de tu computadora ya incluye este celular.',
                'claves' => tienda_claves_compra($conn, $compra['id'], $hw)];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

// Canjes fallidos por IP: el código tiene 25^12 combinaciones, pero no se deja probar sin freno.
function tienda_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '?';
}
function tienda_bloqueada(PDO $conn, $ip) {
    $st = $conn->prepare('SELECT fallos FROM tienda_intentos WHERE ip = :ip AND hora = :h');
    $st->execute([':ip' => $ip, ':h' => date('Y-m-d H')]);
    return (int) $st->fetchColumn() >= TIENDA_MAX_FALLOS_HORA;
}
function tienda_anotar_fallo(PDO $conn, $ip) {
    $conn->prepare('INSERT INTO tienda_intentos (ip, hora, fallos) VALUES (:ip, :h, 1) ON DUPLICATE KEY UPDATE fallos = fallos + 1')
         ->execute([':ip' => $ip, ':h' => date('Y-m-d H')]);
}

// ── Stripe ──────────────────────────────────────────────────────────────────
// Por qué falló la última llamada a Stripe, en corto y sin secretos: pagar.php lo enseña como «detalle técnico»
// (sin acceso al registro de errores del hosting es la única forma de saberlo).
function tienda_ultimo_error($nuevo = null) {
    static $ultimo = null;
    if ($nuevo !== null) $ultimo = $nuevo;
    return $ultimo;
}

// POST a la API con los parámetros como formulario (Stripe entiende a[b][c]=…). null si falla.
function tienda_stripe(array $cfg, $ruta, array $params) {
    if (empty($cfg['stripe_secreto'])) {
        tienda_ultimo_error('falta stripe_secreto en config_db.php');
        return null;
    }
    if (!function_exists('curl_init')) {
        tienda_ultimo_error('el hosting no tiene la extensión curl de PHP');
        return null;
    }
    $base = isset($cfg['stripe_api']) ? rtrim($cfg['stripe_api'], '/') : 'https://api.stripe.com';
    $ch = curl_init($base . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cfg['stripe_secreto'], 'Stripe-Version: ' . TIENDA_STRIPE_VERSION],
    ]);
    $respuesta = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error_red = curl_error($ch);
    curl_close($ch);
    $json = is_string($respuesta) ? json_decode($respuesta, true) : null;
    if ($codigo !== 200 || !is_array($json)) {
        error_log('tienda: Stripe ' . $ruta . ' respondió ' . $codigo . ': ' . substr((string) $respuesta, 0, 500));
        $e = is_array($json) && isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
        $d = $codigo === 0 ? 'no se pudo conectar con Stripe (' . $error_red . ')' : 'Stripe respondió ' . $codigo;
        if ($codigo === 401) $d .= ': la llave no es válida (¿copiada completa? ¿de modo de prueba o real, según toque?)';
        if ($codigo === 403) $d .= ': a la llave le faltan permisos';
        foreach (['type', 'code', 'param'] as $k) {
            if (!empty($e[$k]) && is_string($e[$k])) $d .= ' · ' . $k . ' ' . $e[$k];
        }
        // El mensaje de un 400 dice qué parámetro no le gustó (p. ej. OXXO sin activar); el de 401/403 trae la cuenta: no
        if ($codigo === 400 && !empty($e['message']) && is_string($e['message'])) $d .= ' · ' . substr($e['message'], 0, 200);
        tienda_ultimo_error($d);
        return null;
    }
    return $json;
}

// Los parámetros de la sesión de cobro de un plan. $hw puede ser null (compra desde la página: se entrega un código).
// $pais: el de quien compra, si se sabe. Fuera de México no se ofrece OXXO: solo cobra en pesos y en México, y Stripe
// no convierte a la moneda local una sesión que lo incluya.
function tienda_parametros_sesion(array $cfg, $plan_id, $hw, $origen, $pais = null) {
    $plan = tienda_plan($plan_id);
    $url = tienda_url($cfg);
    $precio = ['currency' => 'mxn', 'unit_amount' => $plan['precio'], 'product_data' => ['name' => $plan['nombre']]];
    if ($plan['modo'] === 'suscripcion') $precio['recurring'] = ['interval' => 'month'];
    $meta = ['plan' => $plan_id, 'hw' => $hw === null ? '' : $hw, 'origen' => $origen];
    $extranjero = is_string($pais) && preg_match('/^[A-Z]{2}$/', $pais) && $pais !== 'MX';
    if ($extranjero) $meta['pais'] = $pais;
    $p = [
        'mode'                 => $plan['modo'] === 'suscripcion' ? 'subscription' : 'payment',
        'line_items'           => [['price_data' => $precio, 'quantity' => 1]],
        'metadata'             => $meta,
        'locale'               => 'es-419',
        'allow_promotion_codes' => 'true',
        'success_url'          => $url . '/compra?sesion={CHECKOUT_SESSION_ID}',
        'cancel_url'           => $url . '/compra?cancelada=1',
    ];
    if ($hw !== null) $p['client_reference_id'] = $hw;
    if ($plan['modo'] === 'suscripcion') {
        // OXXO no permite cobros automáticos: la mensualidad es con tarjeta
        $p['payment_method_types'] = ['card'];
        $p['subscription_data'] = ['metadata' => $meta];
    } elseif ($extranjero) {
        $p['payment_method_types'] = ['card'];
    } else {
        $p['payment_method_types'] = ['card', 'oxxo'];
        $p['payment_method_options'] = ['oxxo' => ['expires_after_days' => 3]];
    }
    return $p;
}

// ¿La firma del aviso es de Stripe? Cabecera «t=…,v1=…»: HMAC-SHA256 de «t.cuerpo» con el secreto del endpoint;
// fuera de 5 minutos se rechaza (un aviso viejo repetido).
function tienda_firma_stripe_valida($cuerpo, $cabecera, $secreto, $ahora = null, $tolerancia = 300) {
    if (!is_string($cabecera) || !is_string($secreto) || $secreto === '') return false;
    $t = null;
    $firmas = [];
    foreach (explode(',', $cabecera) as $parte) {
        $kv = explode('=', trim($parte), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $t = $kv[1];
        elseif ($kv[0] === 'v1') $firmas[] = $kv[1];
    }
    // Sin ctype_digit: la extensión ctype no siempre está instalada
    if ($t === null || !preg_match('/^\d{1,12}$/', $t) || !$firmas) return false;
    if (abs(($ahora === null ? time() : $ahora) - (int) $t) > $tolerancia) return false;
    $esperada = hash_hmac('sha256', $t . '.' . $cuerpo, $secreto);
    foreach ($firmas as $f) {
        if (hash_equals($esperada, $f)) return true;
    }
    return false;
}

// ── Correo ──────────────────────────────────────────────────────────────────
function tienda_correo(array $cfg, $para, $asunto, $texto) {
    if (!is_string($para) || !filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    if (!empty($cfg['correo_archivo'])) {
        return file_put_contents($cfg['correo_archivo'], "Para: $para\nAsunto: $asunto\n\n$texto\n-----\n", FILE_APPEND) !== false;
    }
    $de = isset($cfg['tienda_correo_de']) ? $cfg['tienda_correo_de'] : 'ventas@antiparticula.com';
    $cabeceras = "From: =?UTF-8?B?" . base64_encode('Antipartícula') . "?= <$de>\r\n"
               . "Reply-To: $de\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $texto, $cabeceras);
}

// El correo de una compra recién pagada: el código siempre (sirve para reinstalar o cambiar de PC).
function tienda_correo_compra(array $cfg, array $compra) {
    $plan = tienda_plan($compra['plan']);
    $nombre = $plan ? $plan['nombre'] : 'Premium';
    $codigo = tienda_codigo_mostrar($compra['codigo']);
    $l = [];
    if (tienda_es_prueba($compra)) {
        $l[] = "COMPRA DE PRUEBA (modo de prueba de Stripe): no se cobró nada y la clave dura " . TIENDA_DIAS_PRUEBA . " días.";
        $l[] = "";
    }
    $l[] = "¡Gracias por tu compra!";
    $l[] = "";
    $l[] = "Plan: $nombre";
    $l[] = "Tu código: $codigo";
    $l[] = "";
    if ($compra['hardware_id']) {
        $l[] = "Se activa solo en la computadora donde lo compraste: en cuanto el programa esté abierto y con internet.";
        $l[] = "Si no lo ves en unos minutos, abre Configuración > Premium > «Tengo un código» y escribe tu código.";
    } else {
        $l[] = "Para activarlo: en el programa abre Configuración > Premium > «Tengo un código» y escribe tu código.";
    }
    $l[] = "";
    $l[] = "Guarda este correo. Si cambias de computadora, escribe el mismo código en la nueva (un cambio cada 12 meses).";
    if ($plan && $plan['modo'] === 'suscripcion') {
        $l[] = "Tu plan se renueva solo cada mes con tu tarjeta; la clave nueva le llega sola al programa.";
    }
    $l[] = "";
    $l[] = "¿Dudas? WhatsApp 55 8432 3945 · " . tienda_url($cfg);
    return tienda_correo($cfg, $compra['email'], (tienda_es_prueba($compra) ? '[PRUEBA] ' : '') . "Tu $nombre · Antipartícula Punto de Venta",
                         implode("\n", $l));
}
