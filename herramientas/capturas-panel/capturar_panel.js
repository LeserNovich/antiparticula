// Fotografía el panel móvil REAL (public_html/dashboard) con un negocio de ejemplo, para la
// sección «Vigila tus ventas desde donde estés» de paginas/puntodeventa.html.
//
// Sirve public_html tal cual y contesta /get_dashboard_data.php con datos inventados
// («Abarrotes La Esperanza», el mismo negocio de las capturas del POS). No usa la red ni la base.
// Hay que volver a correrlo cada vez que cambie el aspecto del panel.
//
//   NODE_PATH=<carpeta con playwright y sharp> node herramientas/capturas-panel/capturar_panel.js <salida> [ancho=390] [escala=2]
//
// Deja <salida>/panel-movil-hoy.png y, si sharp está, panel-movil-hoy.webp (calidad 90). Copiar el .webp
// a public_html/img/.
// Playwright necesita su Chromium (npx playwright install chromium).
//
// Fuente: el panel usa «Helvetica, Arial, sans-serif». En WSL no hay ninguna de las dos y Chromium cae a
// DejaVu, que no se parece a lo que ve un celular: si existe /mnt/c/Windows/Fonts se usa Arial como Helvetica.
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { chromium } = require('playwright');

const PUB = path.resolve(__dirname, '../../public_html');
const OUT = path.resolve(process.argv[2] || '.');
const ANCHO = Number(process.argv[3]) || 390;
const ESCALA = Number(process.argv[4]) || 2;
const AHORA = new Date('2026-10-23T18:20:00-06:00');      // viernes, 6:20 p. m. en México (UTC-6 fijo)
const HW = 'A1B2C3D4E5F60718';

// ── Datos de ejemplo (centavos) ─────────────────────────────────
function prng(seed) { return () => { seed |= 0; seed = seed + 0x6D2B79F5 | 0; let t = Math.imul(seed ^ seed >>> 15, 1 | seed); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
const rnd = prng(20261023);
const clave = d => d.toISOString().slice(0, 10);
const hoy = new Date(Date.UTC(2026, 9, 23));
const menos = n => new Date(hoy.getTime() - n * 86400000);

const VENTAS_HOY = 1126550, GANANCIA_HOY = 298400, TICKETS_HOY = 87;
const FACTOR_DIA = [0.92, 0.76, 0.79, 0.86, 0.96, 1.05, 1.16];   // dom..sáb

// Historial diario (100 días): fin de semana más fuerte y un crecimiento lento
const dias = [];
for (let i = 100; i >= 1; i--) {
    const f = menos(i);
    const base = 9300 * (1 + 0.0016 * (100 - i)) * FACTOR_DIA[f.getUTCDay()] * (0.93 + rnd() * 0.14);
    const ventas = Math.round(base * 100);
    dias.push({ fecha: clave(f), ventas, ganancia: Math.round(ventas * (0.245 + rnd() * 0.04)), tickets: Math.round(ventas / 12600) });
}
// La semana que se ve en las comparaciones (ayer, viernes pasado), a mano
const fijo = { 1: 1051000, 2: 902400, 3: 823800, 4: 789200, 5: 936000, 6: 1248000, 7: 1098000 };
// 1 jue 22 · 2 mié 21 · 3 mar 20 · 4 lun 19 · 5 dom 18 · 6 sáb 17 · 7 vie 16
Object.keys(fijo).forEach(i => {
    const r = dias.find(x => x.fecha === clave(menos(Number(i))));
    r.ventas = fijo[i]; r.ganancia = Math.round(fijo[i] * 0.262); r.tickets = Math.round(fijo[i] / 12700);
});
const semana = [];
for (let i = 6; i >= 1; i--) { const r = dias.find(x => x.fecha === clave(menos(i))); semana.push({ fecha: r.fecha, total_centavos: r.ventas }); }
semana.push({ fecha: clave(hoy), total_centavos: VENTAS_HOY });

const sumaMes = (pref, conHoy) => dias.filter(r => r.fecha.startsWith(pref)).reduce((s, r) => [s[0] + r.ventas, s[1] + r.ganancia], [conHoy ? VENTAS_HOY : 0, conHoy ? GANANCIA_HOY : 0]);
const anual = [
    ['2025-11', 24800000], ['2025-12', 29600000], ['2026-01', 23100000], ['2026-02', 22400000], ['2026-03', 25900000],
    ['2026-04', 26700000], ['2026-05', 28200000], ['2026-06', 27300000], ['2026-07', 28900000],
].map(([mes, v]) => ({ mes, ventas_centavos: v, ganancia_centavos: Math.round(v * 0.255) }));
['2026-08', '2026-09'].forEach(m => { const [v, g] = sumaMes(m, false); anual.push({ mes: m, ventas_centavos: v, ganancia_centavos: g }); });
{ const [v, g] = sumaMes('2026-10', true); anual.push({ mes: '2026-10', ventas_centavos: v, ganancia_centavos: g }); }

// Ventas por hora: de 8 a 18, con la hora pico a las 13 (suman exactamente lo de hoy)
const pesos = [4, 6, 8, 9, 11, 14, 12, 9, 8, 11, 8];
const porHora = pesos.map((p, k) => ({ hora: 8 + k, total_centavos: Math.round(p * VENTAS_HOY / 100), tickets: Math.max(1, Math.round(p * TICKETS_HOY / 100)) }));
porHora[5].total_centavos += VENTAS_HOY - porHora.reduce((s, h) => s + h.total_centavos, 0);
porHora[5].tickets += TICKETS_HOY - porHora.reduce((s, h) => s + h.tickets, 0);

const topSemana = [
    { nombre: 'Coca-Cola 600 ml', importe: 521000, cantidad: 326000, unidad: 0 },
    { nombre: 'Leche Lala entera 1 L', importe: 702000, cantidad: 252000, unidad: 0 },
    { nombre: 'Huevo blanco', importe: 418000, cantidad: 97000, unidad: 1 },
    { nombre: 'Sabritas Original 45 g', importe: 331000, cantidad: 221000, unidad: 0 },
    { nombre: 'Tortilla de maíz', importe: 296000, cantidad: 148000, unidad: 1 },
];

const datos = {
    status: 'exitoso',
    negocio: 'Abarrotes La Esperanza',
    hace_minutos: 1,
    ventas_hoy: VENTAS_HOY, ganancia_hoy: GANANCIA_HOY, tickets_hoy: TICKETS_HOY, abonos_hoy: 0,
    turno_usuario: 'Martha', turno_inicio: '23-10-2026 08:02:11', turno_abierto: 1,
    ventas_turno: VENTAS_HOY, tickets_turno: TICKETS_HOY, abonos_turno: 0,
    protegido: false,
    ultimas_ventas: [
        ['18:17', 'Martha', 18500], ['18:12', 'Luis', 7250], ['18:09', 'Martha', 31800], ['18:03', 'Luis', 4600],
        ['17:58', 'Martha', 12950], ['17:51', 'Luis', 9400], ['17:44', 'Martha', 22150], ['17:40', 'Luis', 5800],
    ].map(([hora, cajero, total_centavos]) => ({ hora, cajero, total_centavos })),
    historico_semana: semana,
    historico_dias: dias,
    historico_anual: anual,
    ventas_por_hora: porHora,
    extra: {
        v: 1,
        moneda: 'MXN',
        pagos_hoy: { efectivo: 612300, tarjeta: 358750, transferencia: 155500, comision: 14560 },
        caja: { esperado: 542300, fondo: 80000, efectivo_ventas: 612300, ingresos: 0, retiros: 150000, tarjeta: 358750, transferencia: 155500 },
        cancelaciones_hoy: { cancelaciones: 0, monto_cancelado: 0, devoluciones: 0, monto_devuelto: 0, por_usuario: [] },
        inventario: { agotados: 2, bajo_minimo: 5, valor_costo: 18425000, urgentes: [
            { nombre: 'Coca-Cola 600 ml', cantidad: 0, minimo: 24000, unidad: 0 },
            { nombre: 'Huevo blanco', cantidad: 0, minimo: 5000, unidad: 1 },
            { nombre: 'Leche Lala entera 1 L', cantidad: 6000, minimo: 24000, unidad: 0 },
            { nombre: 'Aceite 1-2-3 900 ml', cantidad: 4000, minimo: 12000, unidad: 0 },
            { nombre: 'Frijol negro', cantidad: 3200, minimo: 10000, unidad: 1 },
        ] },
        sesion: null,
        top_productos: {
            hoy: [
                { nombre: 'Coca-Cola 600 ml', importe: 89600, cantidad: 56000, unidad: 0 },
                { nombre: 'Leche Lala entera 1 L', importe: 105640, cantidad: 38000, unidad: 0 },
                { nombre: 'Huevo blanco', importe: 62350, cantidad: 14500, unidad: 1 },
                { nombre: 'Sabritas Original 45 g', importe: 54000, cantidad: 36000, unidad: 0 },
                { nombre: 'Pan Bimbo blanco grande', importe: 49400, cantidad: 13000, unidad: 0 },
            ],
            semana: topSemana,
            mes: topSemana,
        },
        por_cajero: [{ cajero: 'Martha', tickets: 52, ventas: 689400 }, { cajero: 'Luis', tickets: 35, ventas: 437150 }],
        apartados: { activos: 3, por_cobrar: 184000, vencidos: 0 },
        presupuestos: { vigentes: 2, monto: 356000, por_vencer: 1, vencidos: 0 },
        gastos: { hoy: 25000, mes: 1840000 },
        meta_diaria: 1400000,
    },
};

// ── Servidor local: public_html tal cual + la API de imitación ──
const TIPOS = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.png': 'image/png', '.json': 'application/json', '.webp': 'image/webp' };
const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/get_dashboard_data.php') { res.setHeader('content-type', 'application/json'); return res.end(JSON.stringify(datos)); }
    if (/^\/dashboard\/[^/.]+\/?$/.test(u.pathname)) { res.setHeader('content-type', TIPOS['.html']); return res.end(fs.readFileSync(PUB + '/dashboard/index.html')); }
    const f = path.join(PUB, decodeURIComponent(u.pathname));
    if (!f.startsWith(PUB) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.statusCode = 404; return res.end('{}'); }
    res.setHeader('content-type', TIPOS[path.extname(f)] || 'application/octet-stream');
    res.end(fs.readFileSync(f));
});

// Arial como Helvetica, solo si hay fuentes de Windows a la mano (WSL)
function entornoDeFuentes() {
    const env = Object.assign({}, process.env);
    if (!fs.existsSync('/mnt/c/Windows/Fonts/arial.ttf')) return env;
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'capturas-panel-'));
    fs.writeFileSync(path.join(dir, 'fonts.conf'),
        '<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "fonts.dtd"><fontconfig>' +
        '<include ignore_missing="yes">/etc/fonts/fonts.conf</include><dir>/mnt/c/Windows/Fonts</dir>' +
        `<cachedir>${dir}/cache</cachedir>` +
        '<alias binding="same"><family>Helvetica</family><prefer><family>Arial</family></prefer></alias>' +
        '<alias binding="same"><family>sans-serif</family><prefer><family>Arial</family></prefer></alias></fontconfig>');
    env.FONTCONFIG_FILE = path.join(dir, 'fonts.conf');
    return env;
}

(async () => {
    await new Promise(r => srv.listen(0, '127.0.0.1', r));
    const base = 'http://127.0.0.1:' + srv.address().port;
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ env: entornoDeFuentes() });
    const ctx = await browser.newContext({
        viewport: { width: ANCHO, height: 900 }, deviceScaleFactor: ESCALA, isMobile: true, hasTouch: true,
        locale: 'es-MX', timezoneId: 'America/Mexico_City', colorScheme: 'light', serviceWorkers: 'block',
    });
    const page = await ctx.newPage();
    await page.clock.setFixedTime(AHORA);
    page.on('pageerror', e => console.log('pageerror:', e.message));
    page.on('console', m => { if (m.type() === 'error') console.log('console.error:', m.text()); });
    await page.goto(`${base}/dashboard/${HW}`, { waitUntil: 'load' });
    await page.waitForSelector('[data-k="negocio"]');
    await page.waitForTimeout(800);
    // Sin animaciones ni cursor, y la ventana tan alta como el contenido
    await page.addStyleTag({ content: '*{animation:none!important;transition:none!important;caret-color:transparent!important}' });
    const alto = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    await page.setViewportSize({ width: ANCHO, height: alto });
    await page.waitForTimeout(300);
    // Se corta justo debajo de la tarjeta «Ventas por hora»: el celular de la página tiene 540 px de alto
    // y desplaza por dentro; más largo que esto atrapa la rueda del ratón demasiado rato.
    const corte = await page.evaluate(() => {
        const t = document.querySelector('[data-k="horasGrafica"]').closest('.tile');
        return Math.ceil(t.getBoundingClientRect().bottom + window.scrollY + 8);
    });
    const png = path.join(OUT, 'panel-movil-hoy.png');
    await page.screenshot({ path: png, fullPage: false, clip: { x: 0, y: 0, width: ANCHO, height: corte } });
    console.log('panel-movil-hoy.png', ANCHO * ESCALA, 'x', corte * ESCALA, '(página completa:', alto * ESCALA + ' px de alto)');
    await browser.close();
    srv.close();

    try {
        const sharp = require('sharp');
        const webp = png.replace(/\.png$/, '.webp');
        // Con pérdida (calidad 90): sin pérdida pesa 500 KB por los degradados y a 1:1 no se distingue del original;
        // en la página se dibuja a 266 px de ancho. (Las de img/pos/ sí van sin pérdida: se ven a tamaño casi completo.)
        await sharp(png).webp({ quality: 90, smartSubsample: true, effort: 6 }).toFile(webp);
        console.log(path.basename(webp), fs.statSync(webp).size, 'bytes');
    } catch (e) { console.log('sin sharp: queda solo el PNG (' + e.code + ')'); }
})().catch(e => { console.error(e); process.exit(1); });
