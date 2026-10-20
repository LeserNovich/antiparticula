// Prueba del panel de TODAS las tiendas (public_html/dashboard/tiendas.js) con jsdom: monta la página real
// (index.html + dashboard.js + tiendas.js), simula el servidor multisucursal y verifica la entrada con PIN, el
// selector de tienda, cada pestaña, la frescura de las tiendas y el compartir.
//
// Los ejemplos de respuesta (tests/fixtures/panel_tiendas_*.json) NO están escritos a mano: los generó el servidor
// real con PanelTest (mvn test -Dtest=PanelTest -Dpanel.ejemplo=/work/target/ejemplo). Si cambia el contrato,
// se regeneran y esta prueba dice qué dejó de coincidir.
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const REPO = path.resolve(__dirname, '..');
const PUB  = REPO + '/public_html';
const HTML = fs.readFileSync(PUB + '/dashboard/index.html', 'utf8');
const JS_PANEL   = fs.readFileSync(PUB + '/dashboard/dashboard.js', 'utf8');
const JS_TIENDAS = fs.readFileSync(PUB + '/dashboard/tiendas.js', 'utf8');
const SW   = fs.readFileSync(PUB + '/dashboard/sw.js', 'utf8');
const FX_TODAS = JSON.parse(fs.readFileSync(REPO + '/tests/fixtures/panel_tiendas_todas.json', 'utf8'));
const FX_NORTE = JSON.parse(fs.readFileSync(REPO + '/tests/fixtures/panel_tiendas_norte.json', 'utf8'));

const NBSP = String.fromCharCode(0x202f);   // el separador fino que usa formatMXN
const money = s => '$' + NBSP + s;
const norm  = s => String(s).replace(/[  ]/g, ' ').replace(/\s+/g, ' ').trim();
// Texto de un elemento como se LEE: cada etiqueta separa
const leer = el => norm(el.innerHTML.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>'));
const clonar = o => JSON.parse(JSON.stringify(o));

let pass = 0, fail = 0;
function ok(cond, label, extra) {
    if (cond) { pass++; console.log('  \x1b[32m✓\x1b[0m ' + label); }
    else { fail++; console.log('  \x1b[31m✗\x1b[0m ' + label + (extra ? '\n      → ' + extra : '')); }
}
function eq(actual, expected, label) {
    ok(actual === expected, label, `esperado ${JSON.stringify(expected)}, obtenido ${JSON.stringify(actual)}`);
}

// ── El «ahora» de las pruebas: el día de los ejemplos (domingo 11 de octubre de 2026), 15:20 en México ──
const HOY = FX_TODAS.hoy;
const AHORA = { y: 2026, m: 10, d: 11, h: 15, mi: 20, clave: HOY };
const ID = 'T-K7P2M9XQ4T';
const URL_PANEL = 'https://antiparticula.com/dashboard/' + ID;
const CENTRO = FX_TODAS.sucursales.find(s => s.nombre === 'Centro').id;
const NORTE  = FX_TODAS.sucursales.find(s => s.nombre === 'Norte').id;

// Las tiendas hablaron hace tantos minutos (null = nunca)
function conContactos(fx, minutos) {
    const r = clonar(fx);
    const hace = m => m === null ? null : new Date(Date.now() - m * 60000).toISOString();
    r.sucursales.forEach(s => { if (s.nombre in minutos) s.ultimoContacto = hace(minutos[s.nombre]); });
    return r;
}
const frescas = fx => conContactos(fx, { Centro: 2, Norte: 5 });

// ── Entorno: una página real con el servidor simulado ───────────────────────
function crearEntorno({ url = URL_PANEL, almacen = {}, red = true, todas = frescas(FX_TODAS), norte = frescas(FX_NORTE) } = {}) {
    const estado = { red, llamadas: [], todas, norte, resumenStatus: 200, norteStatus: 200,
        entrar: { status: 200, body: { token: 'tok-1', expira: '2026-11-10T00:00:00Z', negocio: 'X' } } };
    const dom = new JSDOM(HTML, { url, runScripts: 'dangerously', pretendToBeVisual: true });
    const win = dom.window;
    Object.keys(almacen).forEach(k => win.localStorage.setItem(k, almacen[k]));

    win.fetch = async (u, opts) => {
        const o = opts || {};
        estado.llamadas.push({ url: String(u), metodo: o.method || 'GET', headers: o.headers || {}, cuerpo: o.body });
        if (!estado.red) throw new TypeError('network down');
        const respuesta = (status, body) => ({ ok: status < 400, status, json: async () => body });
        const m = String(u).match(/\/panel\/(entrar|salir|resumen)(\?sucursal=([^&]*))?/);
        if (!m) return respuesta(404, {});
        if (m[1] === 'entrar') return respuesta(estado.entrar.status, estado.entrar.body);
        if (m[1] === 'salir') return respuesta(200, { ok: true });
        if (estado.resumenStatus !== 200) return respuesta(estado.resumenStatus, { error: 'token_invalido', mensaje: 'La sesión del panel venció.' });
        const sel = decodeURIComponent(m[3] || 'todas');
        if (sel === 'todas') return respuesta(200, estado.todas);
        if (sel === NORTE) return respuesta(estado.norteStatus, estado.norteStatus === 200 ? estado.norte : { error: 'sucursal_inexistente' });
        return respuesta(404, { error: 'sucursal_inexistente' });
    };
    win.URL.createObjectURL = () => 'blob:stub';
    win.matchMedia = () => ({ matches: false, addEventListener() {} });

    // Dos <script> reales y en orden, como en index.html: así los let/const del primero quedan como globales del segundo
    const a = win.document.createElement('script');
    a.textContent = JS_PANEL;
    win.document.body.appendChild(a);
    // «Ahora» fijo, ANTES de que arranque tiendas.js (lo guardado se pinta de inmediato)
    const real = win.ahoraMX;
    win.ahoraMX = f => (f ? real(f) : AHORA);
    const b = win.document.createElement('script');
    b.textContent = JS_TIENDAS;
    win.document.body.appendChild(b);

    const doc = win.document;
    const env = {
        dom, win, doc, estado, real,
        q:    k => doc.querySelector(`[data-k="${k}"]`),
        G:    expr => win.eval(expr),
        tick: () => new Promise(r => setTimeout(r, 25)),
    };
    env.refrescar = async () => { await env.G('fetchT(false)'); await env.tick(); };
    env.clic = el => el.dispatchEvent(new win.MouseEvent('click', { bubbles: true }));
    env.enviarPin = async (pin) => {
        env.q('pinCampo').value = pin;
        env.q('pinForm').dispatchEvent(new win.Event('submit', { bubbles: true, cancelable: true }));
        await env.tick(); await env.tick();
    };
    env.tab = n => env.clic(doc.querySelector(`[data-tab="${n}"]`));
    env.tienda = id => env.clic(doc.querySelector(`.chip-sel[data-tienda="${id}"]`));
    env.lamadasA = ruta => estado.llamadas.filter(l => l.url.indexOf(ruta) >= 0);
    return env;
}
const conSesion = { ['apdash:tok:' + ID]: 'tok-1' };

(async () => {
// ═════════════════════════════════════════════════════════════════
console.log('\n\x1b[1m1. Sin sesión: pide el PIN y no llama a nadie\x1b[0m');
{
    const E = crearEntorno();
    await E.tick();
    ok(!!E.q('pinForm'), 'se muestra la pantalla del PIN');
    eq(E.doc.querySelector('h1').textContent, 'Panel protegido', 'título');
    eq(E.estado.llamadas.length, 0, 'sin sesión no se pide ningún dato');
    eq(E.lamadasA('get_dashboard_data.php').length, 0, 'dashboard.js no arrancó con un id «T-»');
    E.win.close();
}

console.log('\n\x1b[1m2. El PIN: formato, equivocado, bloqueado, sin internet y correcto\x1b[0m');
{
    const E = crearEntorno();
    await E.tick();
    await E.enviarPin('12345');
    eq(E.q('pinMsg').textContent, 'El PIN tiene de 6 a 8 dígitos.', 'un PIN corto se avisa sin llamar al servidor');
    eq(E.estado.llamadas.length, 0, '…y no se hace ninguna petición');

    E.estado.entrar = { status: 401, body: { error: 'panel_credenciales', mensaje: 'El código o el PIN no son correctos' } };
    await E.enviarPin('000000');
    eq(E.q('pinMsg').textContent, 'El PIN no es correcto.', 'PIN equivocado');
    eq(E.q('pinCampo').value, '', 'el campo se vacía');
    ok(!E.q('pinBoton').disabled, 'y se puede volver a intentar');

    E.estado.entrar = { status: 429, body: { error: 'panel_bloqueado', reintentarEn: 900 } };
    await E.enviarPin('000000');
    eq(E.q('pinMsg').textContent, 'Demasiados intentos. Prueba otra vez en 15 minutos.', 'bloqueado: dice cuánto esperar');
    ok(E.q('pinCampo').disabled && E.q('pinBoton').disabled, '…y no deja escribir');

    const F = crearEntorno();
    await F.tick();
    F.estado.entrar = { status: 403, body: { error: 'licencia_vencida' } };
    await F.enviarPin('482915');
    ok(/licencia multisucursal/.test(F.q('pinMsg').textContent), 'licencia vencida: lo explica');

    const G2 = crearEntorno();
    await G2.tick();
    G2.estado.red = false;
    await G2.enviarPin('482915');
    ok(/Sin conexión/.test(G2.q('pinMsg').textContent), 'sin internet: lo dice');

    const H = crearEntorno();
    await H.tick();
    await H.enviarPin('482915');
    const entrar = H.lamadasA('/panel/entrar')[0];
    eq(entrar.url, 'https://api.antiparticula.com/api/v1/panel/entrar', 'entra por el servidor de producción');
    eq(entrar.metodo, 'POST', 'con POST');
    const cuerpo = JSON.parse(entrar.cuerpo);
    eq(cuerpo.codigo, 'K7P2M9XQ4T', 'manda el código sin el «T-»');
    eq(cuerpo.pin, '482915', 'y el PIN');
    eq(H.win.localStorage.getItem('apdash:tok:' + ID), 'tok-1', 'guarda la sesión en el teléfono');
    const datos = H.lamadasA('/panel/resumen');
    ok(datos.length >= 1 && datos[0].headers.Authorization === 'Bearer tok-1', 'pide los datos con su sesión');
    eq(H.q('negocio').textContent, FX_TODAS.negocio, 'ya muestra el panel');
    H.win.close(); E.win.close(); F.win.close(); G2.win.close();
}

console.log('\n\x1b[1m3. Con la sesión guardada abre directo\x1b[0m');
const E = crearEntorno({ almacen: conSesion });
await E.tick();
{
    const { q, doc, estado } = E;
    const res = E.lamadasA('/panel/resumen');
    eq(res.length, 1, 'una sola petición: «todas»');
    ok(res[0].url.endsWith('/panel/resumen?sucursal=todas'), 'a la ruta del panel, para todas las tiendas');
    ok(E.lamadasA('/panel/entrar').length === 0 && E.lamadasA('get_dashboard_data').length === 0, 'sin PIN y sin hablar con el panel de una tienda');
    eq(q('negocio').textContent, FX_TODAS.negocio, 'nombre del negocio');
    ok(!doc.querySelector('[data-k="pinForm"]'), 'no se pide el PIN');
}

console.log('\n\x1b[1m4. Hoy, con todas las tiendas\x1b[0m');
{
    const { q, G } = E;
    eq(q('ingresosHoy').textContent, money('350.00'), 'ingresos de hoy (Centro 300 + Norte 50)');
    eq(norm(q('ingresosSub').textContent), '3 tickets cobrados', 'subtítulo');
    eq(norm(q('heroEtiqueta').textContent), 'Ingresos de hoy', 'etiqueta de todas las tiendas');
    eq(norm(q('vsTexto').textContent), 'Superas lo de ayer (+338%)', 'contra ayer (80 → 350)');
    eq(norm(q('vsAyer').textContent), 'Ayer ' + money('80.00').replace(NBSP, ' '), 'lo de ayer');
    eq(q('gananciaHoy').textContent, money('30.00'), 'ganancia');
    eq(q('margenChip').textContent, 'Margen 8.6%', 'margen sobre las ventas (3000 de 35000)');
    ok(q('margenChip').classList.contains('is-warn'), 'un margen bajo sale en alerta');
    eq(q('ticketsHoy').textContent, '3', 'tickets');
    eq(q('ticketProm').textContent, money('116.67'), 'ticket promedio');
    const pagos = leer(q('pagosCuerpo'));
    ok(/Efectivo \$ 150\.00 43%/.test(pagos) && /Tarjeta \$ 200\.00 57%/.test(pagos), 'cómo pagaron', pagos);
    ok(/Comisión de tarjeta: \$ 6\.73/.test(pagos), 'comisión de tarjeta con su IVA (5.80 + 0.93)', pagos);
    ok(!q('topTile').hidden, 'lo más vendido aparece');
    eq(leer(q('topLista')), '1. Coca 600ml $ 350.00 3 Pz', 'lo más vendido de hoy');
    eq(G('ultimoD.horas.items.length'), 7, 'ventas por hora: de las 9 a las 15 (la hora de ahora)');
    ok(/Hora pico: 11:00 – 12:00 · \$ 200\.00/.test(leer(q('horasLectura'))), 'hora pico', leer(q('horasLectura')));
    const cajas = leer(q('cajasCuerpo'));
    ok(/Centro .*ANA · desde las 0?8:00.* \$ 570\.00 esperado en el cajón/.test(cajas), 'caja abierta de Centro con su efectivo esperado', cajas);
    ok(!/Norte/.test(cajas), 'Norte no tiene turno: no sale');
    ok(/1 abierta/.test(q('cajasChip').textContent), 'chip de cajas abiertas');
    const canc = leer(q('cancelCuerpo'));
    ok(/1 venta cancelada \$ 70\.00/.test(canc) && /Norte 1 · \$ 70\.00/.test(canc), 'cancelaciones de hoy, por tienda', canc);
    eq(q('cancelChip').textContent, '1', 'chip de cancelaciones');
    ok(/Son ventas de hoy que ya se canceló/.test(canc), 'dice de dónde sale (la fecha de la venta)');
}

console.log('\n\x1b[1m4b. Con abonos de apartados\x1b[0m');
{
    const fx = frescas(FX_TODAS);
    fx.periodos.hoy.total.ventas = 40000;   // 35,000 de tickets + 5,000 de abonos
    const F = crearEntorno({ almacen: conSesion, todas: fx });
    await F.tick();
    eq(F.q('ingresosHoy').textContent, money('400.00'), 'los ingresos incluyen los abonos');
    eq(norm(F.q('ingresosSub').textContent), '$ 350.00 en ventas + $ 50.00 en apartados', 'y el subtítulo los separa');
    eq(F.q('margenChip').textContent, 'Margen 8.6%', 'el margen se calcula sobre las ventas, no sobre los abonos');
    F.win.close();
}

console.log('\n\x1b[1m5. El selector Todas · Centro · Norte\x1b[0m');
{
    const { q, doc, estado, G } = E;
    const chips = Array.from(doc.querySelectorAll('.chip-sel'));
    eq(chips.map(c => c.textContent).join('|'), 'Todas|Centro|Norte', 'un chip por tienda, más «Todas»');
    ok(chips[0].getAttribute('aria-pressed') === 'true' && chips[2].getAttribute('aria-pressed') === 'false', '«Todas» está elegida');
    ok(!q('selTiendas').hidden, 'con dos tiendas se ve el selector');
    estado.llamadas.length = 0;
    E.tienda(NORTE);
    await E.tick(); await E.tick();
    const pedidas = E.lamadasA('/panel/resumen').map(l => decodeURIComponent(l.url.split('sucursal=')[1]));
    ok(pedidas.includes(NORTE) && pedidas.includes('todas'), 'pide la tienda elegida y sigue pidiendo «todas»', pedidas.join(','));
    eq(norm(q('heroEtiqueta').textContent), 'Ingresos de hoy · Norte', 'la cabecera dice de qué tienda son las cifras');
    eq(q('ingresosHoy').textContent, money('50.00'), 'ingresos de Norte');
    eq(q('gananciaHoy').textContent, money('10.00'), 'ganancia de Norte');
    ok(doc.querySelector(`.chip-sel[data-tienda="${NORTE}"]`).getAttribute('aria-pressed') === 'true', 'el chip de Norte queda marcado');
    ok(!/ANA/.test(leer(q('cajasCuerpo'))), 'las cajas son solo las de Norte (no tiene)');
    ok(/No hay una caja abierta/.test(q('cajasCuerpo').textContent), '…y lo dice');
    eq(E.win.localStorage.getItem('apdash:tsel:' + ID), NORTE, 'recuerda la tienda elegida');
    E.tienda('todas');
    await E.tick();
    eq(q('ingresosHoy').textContent, money('350.00'), 'volver a «Todas» (ya la tiene guardada: cambia al instante)');
    eq(G('T.sel'), 'todas', 'estado');
}

console.log('\n\x1b[1m6. Tiendas: una tarjeta por tienda\x1b[0m');
{
    const { q, doc } = E;
    E.tab('tiendas');
    const tiles = Array.from(doc.querySelectorAll('.tienda'));
    eq(tiles.length, 2, 'dos tarjetas');
    const centro = leer(tiles[0]), norte = leer(tiles[1]);
    ok(/^Centro CEN \$ 300\.00 hoy · 2 tickets · margen 7%/.test(centro), 'Centro: nombre, clave, ventas de hoy y margen', centro);
    ok(/\$380 7 días \$380 este mes \$30 ganancia del mes/.test(centro), 'Centro: 7 días, mes y ganancia del mes', centro);
    ok(/Caja abierta · ANA desde las 0?8:00.* esperado \$ 570\.00/.test(centro), 'Centro: su caja', centro);
    ok(/1 agotado/.test(centro) && !/bajo mínimo/.test(centro), 'Centro: 1 agotado', centro);
    ok(/Sin turno abierto/.test(norte), 'Norte: sin turno', norte);
    ok(/1 agotado/.test(norte) && /1 bajo mínimo/.test(norte) && /1 traspaso por recibir/.test(norte) && /1 cancelada hoy/.test(norte), 'Norte: todo lo que pide atención', norte);
    ok(/Reportó hace \d+ min|Reportó ahora mismo/.test(centro), 'Centro: cuándo reportó', centro);
    ok(!tiles[0].querySelector('.tienda-visto.stale'), 'una tienda al día no sale en ámbar');
    E.clic(tiles[1]);
    await E.tick(); await E.tick();
    eq(E.doc.querySelector('[data-tab="hoy"]').getAttribute('aria-selected'), 'true', 'tocar una tarjeta lleva a Hoy');
    eq(norm(q('heroEtiqueta').textContent), 'Ingresos de hoy · Norte', '…con esa tienda elegida');
    E.tienda('todas');
    await E.tick();
}

console.log('\n\x1b[1m7. Frescura: una tienda que lleva horas sin reportarse\x1b[0m');
{
    const F = crearEntorno({ almacen: conSesion, todas: conContactos(FX_TODAS, { Centro: 2, Norte: 180 }), norte: conContactos(FX_NORTE, { Centro: 2, Norte: 180 }) });
    await F.tick();
    eq(norm(F.q('sync').textContent), 'Norte sin reportarse hace 3h', 'la cabecera nombra a la más atrasada');
    ok(F.q('syncBadge').classList.contains('stale'), '…en ámbar/rojo');
    F.tab('tiendas');
    const tiles = Array.from(F.doc.querySelectorAll('.tienda'));
    ok(tiles[1].querySelector('.tienda-visto.stale') && /sus cifras pueden estar incompletas/.test(leer(tiles[1])), 'la tarjeta de Norte avisa que sus cifras pueden estar incompletas');
    ok(!tiles[0].querySelector('.tienda-visto.stale'), 'la de Centro no');
    F.tienda(CENTRO);
    await F.tick();
    // «Centro» sola: está al día aunque Norte no (aquí el servidor simulado no tiene su filtro: queda la última)
    F.win.close();

    const N = crearEntorno({ almacen: conSesion, todas: conContactos(FX_TODAS, { Centro: 2, Norte: null }) });
    await N.tick();
    eq(norm(N.q('sync').textContent), 'Norte nunca se ha reportado', 'una tienda que jamás habló');
    ok(N.q('syncBadge').classList.contains('stale'), '…también en alerta');
    N.tab('tiendas');
    ok(/Nunca se ha reportado/.test(leer(N.doc.querySelectorAll('.tienda')[1])), 'su tarjeta lo dice');
    N.win.close();

    eq(norm(E.q('sync').textContent), 'Tiendas al día · reportó hace 5 min', 'con todas al día, lo dice');
    ok(!E.q('syncBadge').classList.contains('stale'), 'sin alerta');
}

console.log('\n\x1b[1m8. Una caja que se quedó sin cerrar\x1b[0m');
{
    const fx = frescas(FX_TODAS);
    fx.sucursales.find(s => s.nombre === 'Centro').turno.vigente = false;
    const F = crearEntorno({ almacen: conSesion, todas: fx });
    await F.tick();
    ok(/Turno sin cerrar · ANA/.test(leer(F.q('cajasCuerpo'))), 'en Hoy: «Turno sin cerrar»', leer(F.q('cajasCuerpo')));
    F.tab('tiendas');
    const caja = F.doc.querySelector('.tienda .tienda-caja');
    ok(caja.classList.contains('warn') && /Turno sin cerrar de ANA/.test(leer(caja)), 'en Tiendas: en ámbar', leer(caja));
    F.win.close();
}

console.log('\n\x1b[1m8b. Una caja abierta desde ayer\x1b[0m');
{
    const fx = frescas(FX_TODAS);
    fx.sucursales.find(s => s.nombre === 'Centro').turno.inicio = '10-10-2026 18:30:00';
    fx.sucursales.find(s => s.nombre === 'Norte').turno = { id: 3, usuario: 'LUIS', inicio: '04-10-2026 09:00:00', vigente: true, esperado: 25000 };
    const F = crearEntorno({ almacen: conSesion, todas: fx });
    await F.tick();
    const cajas = F.doc.querySelectorAll('[data-k="cajasCuerpo"] .kv small.warn');
    eq(cajas.length, 2, 'en Hoy las dos cajas viejas salen en ámbar');
    ok(/desde ayer a las 0?6:30/.test(leer(F.q('cajasCuerpo'))), 'dice «desde ayer a las…»', leer(F.q('cajasCuerpo')));
    ok(/desde el 4 Oct a las 0?9:00/.test(leer(F.q('cajasCuerpo'))), 'y la de hace días, la fecha', leer(F.q('cajasCuerpo')));
    F.tab('tiendas');
    const tiles = Array.from(F.doc.querySelectorAll('.tienda'));
    ok(tiles[0].querySelector('.tienda-caja.warn') && /desde ayer a las 0?6:30.*¿se olvidó de cerrarla\?/.test(leer(tiles[0])), 'en Tiendas: ámbar y «¿se olvidó de cerrarla?»', leer(tiles[0]));
    F.win.close();
}

console.log('\n\x1b[1m9. Inventario\x1b[0m');
{
    const { q, doc } = E;
    E.tab('inventario');
    const iv = leer(q('invCuerpo'));
    ok(/Agotados 2/.test(iv) && /Bajo mínimo 1/.test(iv), 'contadores de todas las tiendas', iv);
    ok(/Centro 1 agotado · 0 bajo mínimo/.test(iv) && /Norte 1 agotado · 1 bajo mínimo/.test(iv), 'por tienda', iv);
    const filas = Array.from(doc.querySelectorAll('[data-k="invCuerpo"] .urg'));
    eq(filas.length, 3, 'tres productos por reponer');
    ok(/Azúcar/.test(filas[0].textContent) && /Centro/.test(filas[0].textContent) && /Agotado/.test(filas[0].textContent), 'agotados primero, con su tienda', norm(filas[0].textContent));
    ok(/Aceite/.test(filas[2].textContent) && /Norte/.test(filas[2].textContent) && /2 Pz/.test(norm(filas[2].textContent)) && /mínimo 5/.test(filas[2].textContent) && /Bajo/.test(filas[2].textContent),
        'el bajo mínimo con su existencia, mínimo y tienda', norm(filas[2].textContent));
    ok(/nunca ha surtido/.test(iv), 'explica qué es «agotado» con un catálogo común');
    E.tienda(NORTE);
    await E.tick(); await E.tick();
    const soloNorte = leer(q('invCuerpo'));
    ok(!/Por tienda/.test(soloNorte), 'con una sola tienda no hay desglose por tienda', soloNorte);
    eq(doc.querySelectorAll('[data-k="invCuerpo"] .urg').length, 2, 'y los urgentes son solo de esa tienda');
    E.tienda('todas');
    await E.tick();

    const limpio = frescas(FX_TODAS);
    limpio.urgentes = [];
    limpio.sucursales.forEach(s => { s.inventario = { agotados: 0, bajoMinimo: 0 }; });
    const F = crearEntorno({ almacen: conSesion, todas: limpio });
    await F.tick();
    ok(/Todo en orden/.test(F.q('invCuerpo').textContent), 'sin nada que reponer: «Todo en orden»');
    F.win.close();
}

console.log('\n\x1b[1m10. Historial\x1b[0m');
{
    const { q, doc } = E;
    E.tab('historial');
    eq(q('semanaTotal').textContent, money('430.00'), 'últimos 7 días');
    eq(doc.querySelectorAll('[data-chart="semana"]').length, 7, 'siete columnas');
    eq(q('semanaPromedio').textContent, money('215.00'), 'promedio de los días con ventas (430 entre 2)');
    E.clic(doc.querySelector('[data-sub="mes"]'));
    eq(q('mesTotal').textContent, money('430.00'), 'ventas del mes');
    eq(q('mesGanancia').textContent, money('40.00'), 'ganancia del mes (la que manda el servidor)');
    eq(doc.querySelectorAll('[data-chart="mes"]').length, 11, 'una columna por cada día del mes que va');
    eq(q('mesVsAnterior').textContent, '', 'sin ventas del mes anterior no se compara');
    ok(!doc.querySelector('[data-sub="anio"]'), 'no hay pestaña «Año» (el servidor no manda datos anuales)');

    const fx = frescas(FX_TODAS);
    fx.periodos.mesAnterior.total.ventas = 21500;
    const F = crearEntorno({ almacen: conSesion, todas: fx });
    await F.tick();
    F.tab('historial');
    F.clic(F.doc.querySelector('[data-sub="mes"]'));
    ok(/Sep a esta misma fecha: \$ 215\.00 · \+100%/.test(leer(F.q('mesVsAnterior'))), 'el mes contra el anterior a la misma fecha', leer(F.q('mesVsAnterior')));
    F.win.close();
}

console.log('\n\x1b[1m11. Más vendidos y compartir\x1b[0m');
{
    const { q, doc, G } = E;
    E.tab('hoy');
    E.clic(doc.querySelector('[data-top="semana"]'));
    ok(doc.querySelector('[data-top="semana"]').getAttribute('aria-selected') === 'true', 'el selector pasa a 7 días');
    ok(/Coca 600ml/.test(q('topLista').textContent), 'con los productos de la semana');
    E.clic(doc.querySelector('[data-top="hoy"]'));

    const texto = G('textoResumenT(ultimoD, T.datos.todas)');
    ok(!/ /.test(texto), 'el texto usa espacios normales');
    const l = texto.split('\n');
    eq(l[0], '*' + FX_TODAS.negocio + '*', 'el negocio en negritas de WhatsApp');
    ok(/^Resumen del domingo,? 11 de octubre$/.test(l[1]), 'la fecha del negocio', l[1]);
    eq(l[2], 'Todas las tiendas', 'de qué tiendas es');
    ok(l.includes('Ingresos: $ 350.00'), 'ingresos', texto);
    ok(l.includes('Ganancia: $ 30.00 (margen 8.6%)'), 'ganancia', texto);
    ok(l.includes('Tickets: 3 · promedio $ 116.67'), 'tickets', texto);
    ok(l.includes('• Centro: $ 300.00 (2 tickets)') && l.includes('• Norte: $ 50.00 (1 ticket)'), 'por tienda, con singular y plural', texto);
    ok(l.includes('Cobros: efectivo $ 150.00 · tarjeta $ 200.00'), 'cómo pagaron', texto);
    ok(l.includes('1. Coca 600ml · $ 350.00'), 'lo más vendido', texto);
    ok(l.includes('Cajas abiertas:') && l.includes('• Centro: $ 570.00 esperado'), 'las cajas abiertas', texto);

    E.win.open = (u, d, r) => { E.abiertas.push({ u, d, r }); return null; };
    E.abiertas = [];
    E.clic(doc.querySelector('[data-act="compartir"]'));
    await E.tick(); await E.tick();
    eq(E.abiertas.length, 1, 'sin «compartir» del sistema se abre WhatsApp');
    ok(E.abiertas[0].u.indexOf('https://wa.me/?text=') === 0 && decodeURIComponent(E.abiertas[0].u.slice(20)) === texto, 'con el texto del resumen');
    eq(E.abiertas[0].r, 'noopener', 'sin darle acceso a la página');
    const compartidos = [];
    Object.defineProperty(E.win.navigator, 'share', { configurable: true, value: async d => { compartidos.push(d); } });
    E.clic(doc.querySelector('[data-act="compartir"]'));
    await E.tick(); await E.tick();
    eq(compartidos.length, 1, 'con «compartir» del sistema se usa');
    eq(E.abiertas.length, 1, '…y no abre WhatsApp aparte');
    delete E.win.navigator.share;
}

console.log('\n\x1b[1m12. Compartir solo una tienda\x1b[0m');
{
    E.tienda(NORTE);
    await E.tick(); await E.tick();
    const t = E.G('textoResumenT(ultimoD, T.datos.todas)').split('\n');
    eq(t[2], 'Tienda: Norte', 'dice cuál tienda es');
    ok(!t.includes('Por tienda:'), 'sin el desglose por tienda');
    ok(t.includes('Ingresos: $ 50.00'), 'con las cifras de esa tienda');
    E.tienda('todas');
    await E.tick();
}

console.log('\n\x1b[1m13. La tienda elegida ya no existe, o la sesión venció\x1b[0m');
{
    const F = crearEntorno({ almacen: conSesion });
    await F.tick();
    F.estado.norteStatus = 404;
    F.tienda(NORTE);
    await F.tick(); await F.tick();
    eq(F.G('T.sel'), 'todas', 'una tienda que ya no existe: vuelve a «Todas»');
    eq(F.q('ingresosHoy').textContent, money('350.00'), '…y sigue mostrando el negocio');

    F.estado.resumenStatus = 401;
    await F.refrescar();
    ok(!!F.q('pinForm'), 'con la sesión vencida vuelve a pedir el PIN');
    ok(/Tu sesión venció/.test(F.q('pinMsg').textContent), '…y dice por qué');
    eq(F.win.localStorage.getItem('apdash:tok:' + ID), null, 'borra la sesión');
    eq(F.win.localStorage.getItem('apdash:tdatos:' + ID), null, '…y lo que tenía guardado');
    F.win.close();
}

console.log('\n\x1b[1m14. Cerrar la sesión del panel\x1b[0m');
{
    const F = crearEntorno({ almacen: conSesion });
    await F.tick();
    F.estado.llamadas.length = 0;
    F.clic(F.doc.querySelector('[data-act="salir"]'));
    await F.tick();
    const salir = F.lamadasA('/panel/salir');
    ok(salir.length === 1 && salir[0].headers.Authorization === 'Bearer tok-1', 'le avisa al servidor con su sesión');
    ok(!!F.q('pinForm') && /Sesión cerrada/.test(F.q('pinMsg').textContent), 'vuelve al PIN');
    eq(F.win.localStorage.getItem('apdash:tok:' + ID), null, 'borra la sesión');
    F.win.close();
}

console.log('\n\x1b[1m15. Nada del servidor se inserta como HTML\x1b[0m');
{
    const fx = frescas(FX_TODAS);
    const malo = '<img src=x onerror="window.__xss=1">';
    fx.negocio = malo;
    fx.sucursales[0].nombre = malo;
    fx.sucursales[0].clave = '<b>';
    fx.sucursales[0].turno.usuario = malo;
    fx.periodos.hoy.productos[0].nombre = malo;
    fx.urgentes[0].nombre = malo;
    fx.urgentes[0].sucursal = malo;
    const F = crearEntorno({ almacen: conSesion, todas: fx });
    await F.tick();
    F.tab('tiendas'); F.tab('inventario'); F.tab('hoy');
    eq(F.doc.querySelectorAll('img').length, 1, 'solo queda la imagen del logotipo (ninguna inyectada)');
    ok(!F.win.__xss, 'no se ejecutó nada');
    ok(/<img src=x/.test(F.doc.body.textContent), 'se ve como texto');
    F.win.close();
}

console.log('\n\x1b[1m16. Un negocio sin ventas: ceros, sin NaN\x1b[0m');
{
    const fx = frescas(FX_TODAS);
    ['hoy', 'ayer', 'semana', 'mes', 'mesAnterior'].forEach(k => {
        const p = fx.periodos[k];
        p.total = { ventas: 0, ventasTickets: 0, tickets: 0, ganancia: 0, ticketPromedio: 0 };
        if (p.sucursales) p.sucursales.forEach(s => { Object.keys(s).forEach(c => { if (typeof s[c] === 'number') s[c] = 0; }); });
        if (p.porDia) p.porDia = [];
        if (p.productos) p.productos = [];
        if (p.porHora) p.porHora = p.porHora.map(() => 0);
        if (p.formasPago) p.formasPago = { efectivo: 0, tarjeta: 0, transferencia: 0, comision: 0, ivaComision: 0 };
    });
    fx.urgentes = [];
    fx.traspasosEnTransito = 0;
    fx.sucursales.forEach(s => { s.turno = null; s.inventario = { agotados: 0, bajoMinimo: 0 }; s.cancelaciones = { cantidad: 0, monto: 0 }; s.traspasosPorRecibir = 0; });
    const F = crearEntorno({ almacen: conSesion, todas: fx });
    await F.tick();
    F.tab('tiendas'); F.tab('inventario'); F.tab('historial'); F.clic(F.doc.querySelector('[data-sub="mes"]')); F.tab('hoy');
    const texto = F.doc.getElementById('app').textContent;
    ok(!/NaN|undefined|null|Infinity/.test(texto), 'ningún NaN, undefined ni null a la vista', (texto.match(/.{20}(NaN|undefined|null|Infinity).{20}/) || [''])[0]);
    eq(F.q('ingresosHoy').textContent, money('0.00'), 'ingresos en cero');
    eq(norm(F.q('ingresosSub').textContent), 'Aún sin ventas hoy', 'lo dice');
    ok(F.q('vs').hidden, 'sin ventas de ayer no hay comparativo');
    ok(/No hay una caja abierta/.test(F.q('cajasCuerpo').textContent), 'sin cajas abiertas');
    ok(/Hoy no se canceló ninguna venta/.test(F.q('cancelCuerpo').textContent), 'sin cancelaciones');
    ok(/Aún no hay ventas hoy/.test(F.q('topLista').textContent), 'sin lo más vendido');
    F.win.close();
}

console.log('\n\x1b[1m17. Una sola tienda: sin selector\x1b[0m');
{
    const fx = frescas(FX_TODAS);
    fx.sucursales = fx.sucursales.slice(0, 1);
    const F = crearEntorno({ almacen: conSesion, todas: fx });
    await F.tick();
    ok(F.q('selTiendas').hidden, 'con una sola tienda no hace falta elegir');
    eq(F.doc.querySelectorAll('.tienda').length, 1, 'y la pestaña Tiendas muestra la única');
    ok(!/Por tienda/.test(F.q('invCuerpo').textContent), 'sin desglose por tienda');
    F.win.close();
}

console.log('\n\x1b[1m18. Lo guardado en el teléfono\x1b[0m');
{
    const hoyGuardado = { t: Date.now(), datos: { todas: frescas(FX_TODAS) } };
    const A = crearEntorno({ almacen: Object.assign({ ['apdash:tdatos:' + ID]: JSON.stringify(hoyGuardado) }, conSesion), red: false });
    ok(A.q('negocio') && A.q('negocio').textContent === FX_TODAS.negocio, 'con lo guardado de hoy abre al instante, sin esperar al servidor');
    await A.tick(); await A.tick();
    ok(!A.q('banner').hidden && /Mostrando lo último guardado/.test(A.q('banner').textContent), 'y avisa que son datos guardados', A.q('banner').textContent);
    A.win.close();

    const ayer = { t: Date.now() - 2 * 24 * 3600 * 1000, datos: { todas: frescas(FX_TODAS) } };
    const B = crearEntorno({ almacen: Object.assign({ ['apdash:tdatos:' + ID]: JSON.stringify(ayer) }, conSesion), red: false });
    ok(!B.q('negocio') || B.q('negocio').textContent !== FX_TODAS.negocio, 'lo guardado de otro día no se usa (no son las cifras de hoy)');
    await B.tick(); await B.tick();   // que termine su petición fallida antes de cerrar la ventana
    B.win.close();

    const C = crearEntorno({ almacen: { ['apdash:tdatos:' + ID]: JSON.stringify(hoyGuardado) }, red: false });
    await C.tick();
    ok(!!C.q('pinForm') && !C.q('negocio'), 'sin sesión no se muestra lo guardado: pide el PIN');
    C.win.close();
}

console.log('\n\x1b[1m19. Sin internet y servidor caído\x1b[0m');
{
    const F = crearEntorno({ almacen: conSesion });
    await F.tick();
    F.estado.red = false;
    await F.refrescar();
    eq(F.q('negocio').textContent, FX_TODAS.negocio, 'si falla un refresco se conserva la vista');
    const G2 = crearEntorno({ almacen: conSesion, red: false });
    await G2.tick(); await G2.tick();
    ok(/No se pudo conectar al servidor/.test(G2.doc.getElementById('app').textContent), 'si nunca llegó nada, lo dice');
    F.win.close(); G2.win.close();
}

console.log('\n\x1b[1m20. Dónde está el servidor, ids que no son de tiendas, enlace roto\x1b[0m');
{
    eq(E.G('API_TIENDAS'), 'https://api.antiparticula.com/api/v1', 'en producción habla con api.antiparticula.com');
    const L = crearEntorno({ url: 'http://127.0.0.1:8099/dashboard/' + ID });
    eq(L.G('API_TIENDAS'), 'http://127.0.0.1:18080/api/v1', 'en una PC de desarrollo, con el servidor local');
    L.win.close();

    const U = crearEntorno({ url: 'https://antiparticula.com/dashboard/ABCDEF0123456789' });
    await U.tick();
    ok(U.lamadasA('get_dashboard_data.php').length === 1, 'un Hardware ID sigue siendo el panel de una tienda');
    eq(U.G('T.id'), null, '…y tiendas.js no hace nada');
    eq(U.lamadasA('/panel/').length, 0, 'sin tocar el servidor de tiendas');
    U.win.close();

    const R = crearEntorno({ url: 'https://antiparticula.com/dashboard/T-AB' });
    await R.tick();
    ok(/Enlace no válido/.test(R.doc.getElementById('app').textContent), 'un enlace «T-» roto lo dice en lugar de quedarse cargando');
    eq(R.estado.llamadas.length, 0, 'sin llamar a nadie');
    R.win.close();
}

console.log('\n\x1b[1m21. Archivos del service worker y sin errores JS\x1b[0m');
{
    ok(/'\/dashboard\/tiendas\.js'/.test(SW), 'tiendas.js está en el shell del service worker');
    eq((SW.match(/CACHE_NAME = '([^']+)'/) || [])[1], 'antiparticula-dashboard-v7', 'versión del caché subida');
    ok(/<script src="\/dashboard\/tiendas\.js" defer><\/script>/.test(HTML), 'index.html carga tiendas.js después de dashboard.js');
    ok(HTML.indexOf('dashboard.js') < HTML.indexOf('tiendas.js'), 'y en ese orden');
    ok(fs.existsSync(PUB + '/dashboard/tiendas.js'), 'existe el archivo');
    ok(!/jsdelivr|cdn\./.test(JS_TIENDAS), 'sin dependencias externas');
    ok(!/[ − ]/.test(JS_TIENDAS), 'los caracteres invisibles van como escapes (\\u202f), no como letras');
    ok(E.doc.getElementById('app').innerHTML.indexOf('ERROR JS') === -1, 'no se disparó el manejador de errores');
}

console.log(`\n\x1b[1mResultado: ${pass} pasadas, ${fail} fallidas\x1b[0m\n`);
E.win.close();
process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('\x1b[31mERROR EN LA PRUEBA:\x1b[0m', e); process.exit(2); });
