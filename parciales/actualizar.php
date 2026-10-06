<?php
/**
 * Copia los bloques compartidos (menú, barra del Punto de Venta, pie de página)
 * en todas las páginas del sitio.
 *
 * Fuente única (edita solo estos archivos):
 *     parciales/menu.html          menú principal
 *     parciales/subnav-pos.html    barra secundaria de las páginas del Punto de Venta
 *     parciales/footer.html        pie de página ({{lema}} y {{next}} los llena este script)
 *     parciales/lema-*.html        texto de marca del pie (lema-general.html por defecto)
 *
 * Destino: cada .html de public_html con los marcadores del parcial:
 *
 *     <!-- parcial:menu -->        ...   <!-- /parcial:menu -->
 *     <!-- parcial:subnav-pos -->  ...   <!-- /parcial:subnav-pos -->
 *     <!-- parcial:footer -->      ...   <!-- /parcial:footer -->
 *
 * Cualquier parciales/NOMBRE.html se puede usar con <!-- parcial:NOMBRE -->.
 *
 * Opciones en el marcador de apertura:
 *     solido                        (menú) barra siempre oscura, para páginas blancas
 *     actual="paginas/x.html"       qué enlace marcar como página actual, si no es
 *                                   la propia página (Novedades marca "Punto de Venta")
 *     lema="pos"                    (footer) usa parciales/lema-pos.html
 *
 * Rutas internas en los parciales: siempre {{raiz}}paginas/..., {{raiz}}img/...
 * (nunca /paginas/...). El script pone '' o '../' según la carpeta de la página,
 * para que funcione en el hosting, con cualquier servidor local y abriendo el
 * archivo directamente.
 *
 * La página actual se marca sola (aria-current) en los enlaces de menú y barra
 * cuya ruta coincide con la del archivo, y el formulario del pie regresa a la
 * misma página.
 *
 * Uso:
 *     php parciales/actualizar.php             reescribe las páginas desactualizadas
 *     php parciales/actualizar.php --revisar   solo avisa; sale con código 1 si alguna lo está
 */

const DOMINIO = 'https://antiparticula.com';

/** Enlaces que pueden llevar aria-current="page" */
const CLASES_NAVEGACION = 'site-nav__link|pv-subnav__link|pv-subnav__titulo';

$dirParciales = __DIR__;
$webroot = dirname(__DIR__) . '/public_html';
$soloRevisar = in_array('--revisar', $argv, true);

function fallar(string $mensaje): void
{
    fwrite(STDERR, "Error: $mensaje\n");
    exit(2);
}

/** Lee un parcial sin el comentario de ayuda inicial (el que empieza en la columna 0). */
function leerParcial(string $ruta): string
{
    $txt = @file_get_contents($ruta);
    if ($txt === false) {
        fallar("no se pudo leer $ruta");
    }
    $txt = str_replace("\r\n", "\n", $txt);
    $txt = preg_replace('/\A<!--.*?-->\n/s', '', $txt);
    return rtrim($txt, "\n");
}

/** `solido lema="pos"` → ['solido' => true, 'lema' => 'pos'] */
function leerOpciones(string $texto): array
{
    preg_match_all('/([a-z]+)(?:="([^"]*)")?/', $texto, $m, PREG_SET_ORDER);
    $opciones = [];
    foreach ($m as $par) {
        $opciones[$par[1]] = $par[2] ?? true;
    }
    return $opciones;
}

/** '' para index.html, '../' para paginas/x.html */
function prefijoRaiz(string $rutaRelativa): string
{
    return str_repeat('../', substr_count($rutaRelativa, '/'));
}

/** Marca con aria-current el enlace de navegación que apunta a $actual ({{raiz}}paginas/x.html). */
function marcarActual(string $html, string $actual): string
{
    return preg_replace_callback(
        '/<a class="(' . CLASES_NAVEGACION . ')" href="([^"]+)"/',
        fn($m) => $m[2] === $actual ? $m[0] . ' aria-current="page"' : $m[0],
        $html
    );
}

function generarFooter(string $plantilla, string $dirParciales, string $rutaRelativa, array $opciones): string
{
    $lema = $opciones['lema'] ?? 'general';
    if (!preg_match('/^[a-z0-9-]+$/', $lema)) {
        fallar("lema inválido \"$lema\" en $rutaRelativa");
    }
    $html = str_replace('{{lema}}', leerParcial("$dirParciales/lema-$lema.html"), $plantilla);
    return str_replace('{{next}}', DOMINIO . '/' . $rutaRelativa . '?enviado=true', $html);
}

function generarBloque(string $nombre, string $plantilla, string $dirParciales, string $rutaRelativa, array $opciones): string
{
    if ($nombre === 'footer') {
        return generarFooter($plantilla, $dirParciales, $rutaRelativa, $opciones);
    }
    $html = $plantilla;
    if ($nombre === 'menu' && !empty($opciones['solido'])) {
        $html = str_replace('class="site-nav"', 'class="site-nav site-nav--solid"', $html);
    }
    $actual = '{{raiz}}' . (is_string($opciones['actual'] ?? null) ? $opciones['actual'] : $rutaRelativa);
    return marcarActual($html, $actual);
}

$plantillas = [];
function plantilla(string $nombre, string $dirParciales, array &$plantillas): string
{
    if (!preg_match('/^[a-z0-9-]+$/', $nombre) || $nombre === 'actualizar') {
        fallar("nombre de parcial inválido \"$nombre\"");
    }
    return $plantillas[$nombre] ??= leerParcial("$dirParciales/$nombre.html");
}

$archivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($webroot, FilesystemIterator::SKIP_DOTS)
);

$patron = '/^([ \t]*)<!-- parcial:([a-z0-9-]+)([^>]*?) -->(\r?\n).*?^([ \t]*)<!-- \/parcial:\2 -->/ms';
$desactualizadas = [];
$revisadas = 0;

foreach ($archivos as $archivo) {
    if ($archivo->getExtension() !== 'html') {
        continue;
    }
    $ruta = $archivo->getPathname();
    $rutaRelativa = str_replace('\\', '/', substr($ruta, strlen($webroot) + 1));
    $original = file_get_contents($ruta);

    $aperturas = preg_match_all('/<!-- parcial:[a-z0-9-]+\b/', $original);
    if ($aperturas === 0) {
        continue;
    }
    $revisadas++;

    $raiz = prefijoRaiz($rutaRelativa);
    $reemplazos = 0;

    $nuevo = preg_replace_callback(
        $patron,
        function ($m) use (&$plantillas, $dirParciales, $rutaRelativa, $raiz, &$reemplazos) {
            [$todo, $sangria, $nombre, $textoOpciones, $salto, $sangriaCierre] = $m;
            $bloque = generarBloque($nombre, plantilla($nombre, $dirParciales, $plantillas),
                                    $dirParciales, $rutaRelativa, leerOpciones($textoOpciones));
            $bloque = str_replace('{{raiz}}', $raiz, $bloque);
            $reemplazos++;
            return "$sangria<!-- parcial:$nombre$textoOpciones -->$salto"
                . str_replace("\n", $salto, $bloque) . $salto
                . "$sangriaCierre<!-- /parcial:$nombre -->";
        },
        $original
    );

    if ($reemplazos !== $aperturas) {
        fallar("$rutaRelativa tiene un marcador <!-- parcial:... --> sin su cierre");
    }

    if ($nuevo !== $original) {
        $desactualizadas[] = $rutaRelativa;
        if (!$soloRevisar) {
            file_put_contents($ruta, $nuevo);
        }
    }
}

sort($desactualizadas);
if (!$desactualizadas) {
    echo "Las $revisadas páginas ya están al día.\n";
    exit(0);
}
$verbo = $soloRevisar ? 'Desactualizadas' : 'Actualizadas';
echo "$verbo (" . count($desactualizadas) . " de $revisadas):\n";
foreach ($desactualizadas as $pagina) {
    echo "  - $pagina\n";
}
exit($soloRevisar ? 1 : 0);
