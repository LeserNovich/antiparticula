// Prueba del dashboard con jsdom: monta la pagina real, simula la API
// y verifica el render incremental.
const fs = require('fs');
const { JSDOM } = require('jsdom');

const REPO = require('path').resolve(__dirname, '..');
const HTML = fs.readFileSync(REPO + '/public_html/dashboard/index.html', 'utf8');
const JS   = fs.readFileSync(REPO + '/public_html/dashboard/dashboard.js', 'utf8');

const NBSP = ' ';                       // separador fino que usa formatMXN
const money = s => '$' + NBSP + s;
const norm  = s => String(s).replace(/[  ]/g, ' ').replace(/\s+/g, ' ').trim();

let pass = 0, fail = 0;
function ok(cond, label, extra) {
    if (cond) { pass++; console.log('  \x1b[32m✓\x1b[0m ' + label); }
    else { fail++; console.log('  \x1b[31m✗\x1b[0m ' + label + (extra ? '\n      → ' + extra : '')); }
}
function eq(actual, expected, label) {
    ok(actual === expected, label, `esperado ${JSON.stringify(expected)}, obtenido ${JSON.stringify(actual)}`);
}

// ── Datos de prueba ─────────────────────────────────────────────
function datosBase(over = {}) {
    return Object.assign({
        status: 'exitoso',
        negocio: 'Abarrotes La Esquina',
        hace_minutos: 2,
        ventas_hoy: 452050,        // $4,520.50
        ganancia_hoy: 135000,      // $1,350.00 -> margen 29.9%
        tickets_hoy: 23,
        abonos_hoy: 0,
        turno_usuario: 'Maria',
        turno_inicio: '2026-09-03 08:15:00',
        turno_abierto: 1,
        ventas_turno: 300000,
        tickets_turno: 15,
        abonos_turno: 0,
        ultimas_ventas: [
            { hora: '14:32', cajero: 'Maria', total_centavos: 12500 },
            { hora: '14:10', cajero: 'Maria', total_centavos:  8000 },
        ],
        historico_semana: [],
        historico_anual: [
            { mes: '2026-08', ventas_centavos: 8000000, ganancia_centavos: 2000000 },
            { mes: '2026-09', ventas_centavos:  452050, ganancia_centavos:  135000 },
        ],
        ventas_por_hora: [
            { hora:  9, total_centavos:  50000, tickets: 3 },
            { hora: 12, total_centavos: 120000, tickets: 8 },
            { hora: 14, total_centavos:  20500, tickets: 2 },
        ],
    }, over);
}

// ── Arranque del entorno ────────────────────────────────────────
let respuesta = { ok: true, status: 200, body: datosBase() };
let chartsCreados = 0, chartsActualizados = 0;

const dom = new JSDOM(HTML, {
    url: 'https://antiparticula.com/dashboard/TEST123',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
});
const win = dom.window;

// Stubs antes de que corra dashboard.js
win.fetch = async () => {
    if (!respuesta.ok) throw new Error('network down');
    return {
        ok: respuesta.status < 400,
        status: respuesta.status,
        json: async () => respuesta.body,
    };
};
win.URL.createObjectURL = () => 'blob:stub';
win.HTMLCanvasElement.prototype.getContext = function () { return {}; };
win.Chart = class {
    constructor(ctx, cfg) { chartsCreados++; this.data = cfg.data; this.options = cfg.options; }
    update() { chartsActualizados++; }
    destroy() {}
};

// Inyectar como <script> real para que los `let` del top level queden
// como bindings lexicos globales (accesibles con win.eval).
const tag = win.document.createElement('script');
tag.textContent = JS;
win.document.body.appendChild(tag);

const doc = win.document;
const q   = k => doc.querySelector(`[data-k="${k}"]`);
const G   = expr => win.eval(expr);
const tick = () => new Promise(r => setTimeout(r, 20));
const refrescar = async () => { await G('fetchData("TEST123", false)'); await tick(); };

(async () => {
console.log('\n\x1b[1m1. Primer render\x1b[0m');
await tick();

eq(q('negocio').textContent, 'Abarrotes La Esquina', 'nombre del negocio');
eq(q('ingresosHoy').textContent, money('4,520.50'), 'ingresos hoy');
eq(q('gananciaHoy').textContent, money('1,350.00'), 'ganancia hoy');
eq(q('ticketsHoy').textContent, '23', 'tickets');
eq(q('ticketProm').textContent, money('196.54'), 'ticket promedio (452050/23)');
eq(q('margen').textContent, '29.9%', 'margen');
ok(q('cardMargen').classList.contains('card-margin-good'), 'margen >= 20% => clase good');
eq(q('sync').textContent, 'Última sync: hace 2 min', 'badge de sync');
ok(!q('syncBadge').classList.contains('stale'), 'sync reciente => no stale');
eq(q('turnoUsuario').textContent, 'Maria', 'cajero del turno');
ok(/^08:15\s*a\.?\s?m\.?$/.test(norm(q('turnoInicio').textContent)),
   'turno_inicio formateado (era ISO crudo)', JSON.stringify(q('turnoInicio').textContent));
ok(q('turnoEstado').innerHTML.includes('badge-open'), 'turno abierto');
eq(norm(q('donutCosto').textContent), '70.1% · $ 3,170.50', 'donut: costo');

console.log('\n\x1b[1m2. Grafica por hora usa ventas_por_hora y rellena huecos\x1b[0m');
ok(!!G('charts.horas'), 'grafica de horas creada');
eq(JSON.stringify(G('charts.horas.data.labels')),
   JSON.stringify(['09:00','10:00','11:00','12:00','13:00','14:00']),
   'rango 9-14 completo, no solo las horas con venta');
eq(JSON.stringify(G('charts.horas.data.datasets[0].data')),
   JSON.stringify([500, 0, 0, 1200, 0, 205]),
   'huecos en cero (antes desaparecian del eje)');

console.log('\n\x1b[1m3. Refresco incremental: no se reconstruye el DOM\x1b[0m');
const nodoTestigo = q('ingresosHoy');
nodoTestigo.dataset.testigo = 'vivo';
const creadosAntes = chartsCreados, actualizadosAntes = chartsActualizados;

respuesta.body = datosBase({ ventas_hoy: 500000, tickets_hoy: 25, hace_minutos: 1 });
await refrescar();

ok(q('ingresosHoy').dataset.testigo === 'vivo', 'el nodo original sobrevive al refresco');
ok(q('ingresosHoy') === nodoTestigo, 'es literalmente el mismo elemento (sin re-render)');
eq(q('ingresosHoy').textContent, money('5,000.00'), 'el valor si se actualizo');
eq(q('ticketsHoy').textContent, '25', 'tickets actualizados');
eq(chartsCreados, creadosAntes, 'no se crearon graficas nuevas');
ok(chartsActualizados > actualizadosAntes, 'las graficas se actualizaron en su lugar');

console.log('\n\x1b[1m4. Respaldo a ultimas_ventas sin migracion aplicada\x1b[0m');
respuesta.body = datosBase({ ventas_por_hora: [] });
await refrescar();
eq(JSON.stringify(G('charts.horas.data.labels')), JSON.stringify(['14:00']),
   'sin ventas_por_hora cae a derivar de ultimas_ventas');

console.log('\n\x1b[1m5. Estado vacio reversible\x1b[0m');
respuesta.body = datosBase({ ventas_por_hora: [], ultimas_ventas: [], ventas_hoy: 0, ganancia_hoy: 0, tickets_hoy: 0 });
await refrescar();
const wrapHoras = doc.querySelector('[data-wrap="horas"]');
const wrapDonut = doc.querySelector('[data-wrap="donut"]');
ok(wrapHoras.querySelector('canvas') !== null, 'el canvas NO se destruye al quedar vacio');
ok(wrapHoras.querySelector('canvas').hidden, 'canvas oculto');
ok(!wrapHoras.querySelector('.chart-empty').hidden, 'mensaje de vacio visible');
ok(wrapDonut.querySelector('.donut-legend').hidden, 'leyenda del donut oculta sin ventas');

respuesta.body = datosBase();
await refrescar();
ok(!wrapHoras.querySelector('canvas').hidden, 'el canvas vuelve al haber datos otra vez');
ok(wrapHoras.querySelector('.chart-empty').hidden, 'mensaje de vacio se oculta');
eq(q('ingresosHoy').textContent, money('4,520.50'), 'valores restaurados');

console.log('\n\x1b[1m6. Banner de conexion en fallo de refresco\x1b[0m');
ok(q('banner').hidden, 'banner oculto mientras hay conexion');
G('ultimoOk = ' + (Date.now() - 10 * 60 * 1000));   // ultimo exito hace 10 min
respuesta.ok = false;
await refrescar();
ok(!q('banner').hidden, 'banner visible tras fallar el refresco');
ok(/datos de hace 10 min/.test(q('banner').textContent), 'banner dice la antiguedad',
   JSON.stringify(q('banner').textContent));
eq(q('ingresosHoy').textContent, money('4,520.50'), 'se conservan los ultimos datos buenos');

respuesta.ok = true;
await refrescar();
ok(q('banner').hidden, 'banner se oculta al recuperar conexion');

console.log('\n\x1b[1m7. Escapado XSS\x1b[0m');
respuesta.body = datosBase({
    negocio: '<img src=x onerror=alert(1)>',
    ultimas_ventas: [{ hora: '10:00', cajero: '<script>alert(2)</script>', total_centavos: 100 }],
});
await refrescar();
eq(q('negocio').textContent, '<img src=x onerror=alert(1)>', 'negocio se muestra como texto literal');
eq(q('negocio').querySelectorAll('img').length, 0, 'no se inyecto ningun <img>');
ok(!/<script>/.test(q('ultimasVentas').innerHTML), 'cajero escapado en la lista de ventas');

console.log('\n\x1b[1m8. Formatos de turno_inicio\x1b[0m');
respuesta.body = datosBase({ turno_inicio: '2026-09-03T08:15:00' });
await refrescar();
ok(/^08:15\s*a\.?\s?m\.?$/.test(norm(q('turnoInicio').textContent)), 'ISO con T (mismo dia)',
   JSON.stringify(q('turnoInicio').textContent));

respuesta.body = datosBase({ turno_inicio: '08:15' });
await refrescar();
ok(/^08:15\s*a\.?\s?m\.?$/.test(norm(q('turnoInicio').textContent)), 'solo hora HH:MM',
   JSON.stringify(q('turnoInicio').textContent));

respuesta.body = datosBase({ turno_inicio: '' });
await refrescar();
eq(q('turnoInicio').textContent, '—', 'vacio muestra guion');

respuesta.body = datosBase({ turno_inicio: 'turno matutino' });
await refrescar();
eq(q('turnoInicio').textContent, 'turno matutino', 'texto libre se respeta');

respuesta.body = datosBase({ turno_inicio: '2026-09-01 20:30:00' });
await refrescar();
ok(/^1 Sep, 08:30\s*p\.?\s?m\.?$/.test(norm(q('turnoInicio').textContent)),
   'otro dia incluye la fecha', JSON.stringify(q('turnoInicio').textContent));

console.log('\n\x1b[1m9. Badge stale y margen bajo\x1b[0m');
respuesta.body = datosBase({ hace_minutos: 95, ventas_hoy: 100000, ganancia_hoy: 5000 });
await refrescar();
ok(q('syncBadge').classList.contains('stale'), 'sync vieja => badge stale');
eq(q('sync').textContent, 'Última sync: hace 1h 35min', 'formato de horas');
eq(q('margen').textContent, '5.0%', 'margen bajo');
ok(q('cardMargen').classList.contains('card-margin-warn'), 'margen < 20% => clase warn');
ok(!q('cardMargen').classList.contains('card-margin-good'), 'clase good removida');

console.log('\n\x1b[1m10. Cambio de estructura: aparecen datos anuales\x1b[0m');
respuesta.body = datosBase({ historico_anual: [] });
await refrescar();
ok(q('tablaAnual') === null, 'sin datos anuales no se monta la tabla');
respuesta.body = datosBase();
await refrescar();
ok(q('tablaAnual') !== null, 'al llegar datos anuales se remonta la seccion');
eq(q('totalVentasAnual').textContent, money('84,520.50'), 'total ventas del anio');
eq(q('totalGananciaAnual').textContent, money('21,350.00'), 'total ganancia del anio');
eq(q('margenAnual').textContent, '25.3%', 'margen anual');
ok(/Ago/.test(q('tablaAnual').innerHTML), 'la tabla lista los meses');

console.log('\n\x1b[1m11. Sin errores JS capturados por window.onerror\x1b[0m');
ok(doc.getElementById('app').innerHTML.indexOf('ERROR JS') === -1, 'no se disparo el handler de error');

console.log(`\n\x1b[1mResultado: ${pass} pasadas, ${fail} fallidas\x1b[0m\n`);
win.close();
process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('\x1b[31mERROR EN LA PRUEBA:\x1b[0m', e); process.exit(2); });
