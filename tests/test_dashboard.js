// Prueba del dashboard con jsdom: monta la pagina real, simula la API
// y verifica el render incremental, los estados, las pestañas, los calculos y el PIN.
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const REPO = path.resolve(__dirname, '..');
const PUB  = REPO + '/public_html';
const HTML = fs.readFileSync(PUB + '/dashboard/index.html', 'utf8');
const JS   = fs.readFileSync(PUB + '/dashboard/dashboard.js', 'utf8');
const SW   = fs.readFileSync(PUB + '/dashboard/sw.js', 'utf8');

const NBSP = ' ';                  // separador fino que usa formatMXN
const money = s => '$' + NBSP + s;
const norm  = s => String(s).replace(/[  ]/g, ' ').replace(/\s+/g, ' ').trim();
// Texto de un elemento como se LEE: cada etiqueta separa (textContent pega "$ 250.00" con "37%")
const leer = el => norm(el.innerHTML.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>'));

let pass = 0, fail = 0;
function ok(cond, label, extra) {
    if (cond) { pass++; console.log('  \x1b[32m✓\x1b[0m ' + label); }
    else { fail++; console.log('  \x1b[31m✗\x1b[0m ' + label + (extra ? '\n      → ' + extra : '')); }
}
function eq(actual, expected, label) {
    ok(actual === expected, label, `esperado ${JSON.stringify(expected)}, obtenido ${JSON.stringify(actual)}`);
}

// ── El "ahora" de las pruebas: jueves 3 de septiembre de 2026, 15:20 (México) ──
const HOY   = '2026-09-03';
const AYER  = '2026-09-02';
const AHORA = { y: 2026, m: 9, d: 3, h: 15, mi: 20, clave: HOY };

// ── Datos de prueba ─────────────────────────────────────────────
// turno_inicio va como lo manda el POS: "dd-MM-yyyy HH:mm:ss"
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
        turno_inicio: '03-09-2026 08:15:00',
        turno_abierto: 1,
        ventas_turno: 300000,
        tickets_turno: 15,
        abonos_turno: 0,
        protegido: false,
        ultimas_ventas: [
            { hora: '14:32', cajero: 'Maria', total_centavos: 12500 },
            { hora: '14:10', cajero: 'Maria', total_centavos:  8000 },
        ],
        historico_semana: [],
        historico_dias: [],
        historico_anual: [
            { mes: '2026-08', ventas_centavos: 8000000, ganancia_centavos: 2000000 },
            { mes: '2026-09', ventas_centavos:  452050, ganancia_centavos:  135000 },
        ],
        ventas_por_hora: [
            { hora:  9, total_centavos:  50000, tickets: 3 },
            { hora: 12, total_centavos: 120000, tickets: 8 },
            { hora: 14, total_centavos:  20500, tickets: 2 },
        ],
        extra: {
            v: 1,
            pagos_hoy: { efectivo: 25000, tarjeta: 32000, transferencia: 10000, comision: 673 },
            caja: { esperado: 73000, fondo: 50000, efectivo_ventas: 25000, ingresos: 4000, retiros: 6000, tarjeta: 32000, transferencia: 10000 },
            cancelaciones_hoy: { cancelaciones: 2, monto_cancelado: 48650, devoluciones: 1, monto_devuelto: 8900,
                por_usuario: [{ usuario: 'Luis', cancelaciones: 2, devoluciones: 0 }, { usuario: 'Ana', cancelaciones: 0, devoluciones: 1 }] },
            inventario: { agotados: 2, bajo_minimo: 1, valor_costo: 6350, urgentes: [
                { nombre: 'Refresco B', cantidad: -3000, minimo: 5, unidad: 1 },
                { nombre: 'Azucar A', cantidad: 0, minimo: 5, unidad: 1 },
                { nombre: 'Leche C', cantidad: 2000, minimo: 5, unidad: 0 },
            ] },
        },
    }, over);
}

const dia = (fecha, ventas, extra = {}) => Object.assign({ fecha, ventas, ganancia: Math.round(ventas / 4), tickets: 5 }, extra);

// ── Entorno: una pagina real con la API simulada ────────────────
function crearEntorno({ almacen = {}, red = true, body = datosBase(), ahora = AHORA, requiereToken = null, status = 200 } = {}) {
    const estado = { ok: red, status, body, requiereToken, llamadas: [],
                     auth: { status: 200, body: { status: 'exitoso', protegido: true, token: 'tok-1' } } };
    const dom = new JSDOM(HTML, {
        url: 'https://antiparticula.com/dashboard/TEST123',
        runScripts: 'dangerously',
        pretendToBeVisual: true,
    });
    const win = dom.window;
    Object.keys(almacen).forEach(k => win.localStorage.setItem(k, almacen[k]));

    // Stubs antes de que corra dashboard.js
    win.fetch = async (url, opts) => {
        estado.llamadas.push({ url: String(url), opts: opts || {} });
        if (String(url).indexOf('/dashboard_auth.php') === 0) {
            return { ok: estado.auth.status < 400, status: estado.auth.status, json: async () => estado.auth.body };
        }
        if (!estado.ok) throw new Error('network down');
        if (String(url).indexOf('/tienda/planes.php') === 0) {
            if (!estado.planes) throw new Error('sin planes');
            return { ok: true, status: 200, json: async () => estado.planes };
        }
        const tk = opts && opts.headers && opts.headers['X-Dash-Token'];
        if (estado.requiereToken && tk !== estado.requiereToken) {
            return { ok: false, status: 401, json: async () => ({ status: 'protegido' }) };
        }
        return { ok: estado.status < 400, status: estado.status, json: async () => estado.body };
    };
    win.URL.createObjectURL = () => 'blob:stub';
    win.matchMedia = () => ({ matches: false, addEventListener() {} });

    // Inyectar como <script> real para que los `let` del top level queden
    // como bindings lexicos globales (accesibles con win.eval).
    const tag = win.document.createElement('script');
    tag.textContent = JS;
    win.document.body.appendChild(tag);

    // "Ahora" fijo: lo que depende de la hora no cambia con el dia en que se corre la prueba.
    // (la lectura del cache usa la hora real y no se toca)
    const real = win.ahoraMX;
    if (ahora) win.ahoraMX = f => (f ? real(f) : ahora);

    const doc = win.document;
    const env = {
        dom, win, doc, estado, real,
        q:    k => doc.querySelector(`[data-k="${k}"]`),
        G:    expr => win.eval(expr),
        tick: () => new Promise(r => setTimeout(r, 20)),
    };
    env.refrescar = async () => { await env.G('fetchData("TEST123", false)'); await env.tick(); };
    env.clic = el => el.dispatchEvent(new win.MouseEvent('click', { bubbles: true }));
    env.enviarPin = async (pin) => {
        env.q('pinCampo').value = pin;
        env.q('pinForm').dispatchEvent(new win.Event('submit', { bubbles: true, cancelable: true }));
        await env.tick(); await env.tick();
    };
    return env;
}

(async () => {
const E = crearEntorno();
const { win, doc, q, G, tick, refrescar, clic, estado } = E;

console.log('\n\x1b[1m1. Primer render\x1b[0m');
await tick();

eq(q('negocio').textContent, 'Abarrotes La Esquina', 'nombre del negocio');
eq(q('ingresosHoy').textContent, money('4,520.50'), 'ingresos hoy (ventas + apartados)');
eq(q('gananciaHoy').textContent, money('1,350.00'), 'ganancia hoy');
eq(q('ticketsHoy').textContent, '23', 'tickets');
eq(q('ticketProm').textContent, money('196.54'), 'ticket promedio (452050/23)');
eq(q('margenChip').textContent, 'Margen 29.9%', 'margen');
ok(q('margenChip').classList.contains('is-good'), 'margen >= 20% => is-good');
ok(!q('margenChip').hidden, 'el margen se muestra si hubo ventas');
eq(q('splitGan').style.getPropertyValue('width'), '29.9%', 'barra ganancia/costo');
eq(norm(q('sync').textContent), 'POS actualizado hace 2 min', 'estado de sync');
ok(!q('syncBadge').classList.contains('stale'), 'sync reciente => no stale');
eq(q('turnoUsuario').textContent, 'Maria', 'cajero del turno');
ok(/^08:15\s*a\.?\s?m\.?$/.test(norm(q('turnoInicio').textContent)),
   'turno_inicio en el formato del POS (dd-MM-yyyy HH:mm:ss) se muestra como hora', JSON.stringify(q('turnoInicio').textContent));
ok(q('turnoEstado').innerHTML.includes('is-good') && /Abierto/.test(q('turnoEstado').textContent), 'turno abierto');
eq(norm(q('ingresosSub').textContent), '23 tickets cobrados', 'subtitulo del hero sin apartados');
ok(q('vs').hidden, 'sin dato de ayer no se muestra el comparativo');
ok(q('salir').hidden, 'sin PIN no hay «Cerrar sesión»');

console.log('\n\x1b[1m2. Ventas por hora: usa ventas_por_hora, rellena huecos y llega hasta ahora\x1b[0m');
const cols = () => Array.from(q('horasGrafica').querySelectorAll('.col'));
eq(cols().map(c => c.getAttribute('data-key')).join(','), '9,10,11,12,13,14,15',
   'de la primera venta (9) hasta la hora actual (15): las horas sin venta no desaparecen');
eq(cols().map(c => c.style.getPropertyValue('--p')).join(','), '42,0,0,100,0,17,0',
   'alturas proporcionales a la hora pico (huecos en cero)');
ok(cols()[3].classList.contains('is-peak'), 'la hora pico va marcada');
ok(/Hora pico: 12:00 – 13:00 · \$ 1,200\.00 · 8 tickets/.test(norm(q('horasLectura').textContent)),
   'lectura por omision: hora pico', JSON.stringify(norm(q('horasLectura').textContent)));

console.log('\n\x1b[1m3. Elegir una columna cambia la lectura y sobrevive al refresco\x1b[0m');
clic(cols()[5]);   // las 14:00
ok(cols()[5].classList.contains('is-sel'), 'la columna elegida se marca');
ok(/14:00 – 15:00/.test(q('horasLectura').textContent) && !/Hora pico/.test(q('horasLectura').textContent),
   'la lectura habla de la hora elegida', JSON.stringify(q('horasLectura').textContent));
await refrescar();
ok(cols()[5].classList.contains('is-sel'), 'la seleccion sobrevive al refresco');
clic(cols()[5]);
ok(!cols()[5].classList.contains('is-sel'), 'tocarla otra vez la quita');

console.log('\n\x1b[1m4. Refresco incremental: no se reconstruye el DOM\x1b[0m');
const nodoTestigo = q('ingresosHoy');
nodoTestigo.dataset.testigo = 'vivo';
const graficaAntes = q('horasGrafica').firstElementChild;

estado.body = datosBase({ ventas_hoy: 500000, tickets_hoy: 25, hace_minutos: 1 });
await refrescar();

ok(q('ingresosHoy').dataset.testigo === 'vivo', 'el nodo original sobrevive al refresco');
ok(q('ingresosHoy') === nodoTestigo, 'es literalmente el mismo elemento (sin re-render)');
eq(q('ingresosHoy').textContent, money('5,000.00'), 'el valor si se actualizo');
eq(q('ticketsHoy').textContent, '25', 'tickets actualizados');
ok(q('horasGrafica').firstElementChild === graficaAntes, 'una gráfica que no cambió no se vuelve a escribir');

console.log('\n\x1b[1m5. Respaldo a ultimas_ventas sin migracion aplicada\x1b[0m');
estado.body = datosBase({ ventas_por_hora: [] });
await refrescar();
eq(cols().map(c => c.getAttribute('data-key')).join(','), '14,15',
   'sin ventas_por_hora cae a derivar de ultimas_ventas');
ok(/2 tickets/.test(q('horasLectura').textContent), 'cuenta los tickets de ese respaldo');

console.log('\n\x1b[1m6. Estado vacio reversible\x1b[0m');
estado.body = datosBase({ ventas_por_hora: [], ultimas_ventas: [], ventas_hoy: 0, ganancia_hoy: 0, tickets_hoy: 0 });
await refrescar();
ok(/Sin ventas registradas hoy/.test(q('horasGrafica').textContent), 'mensaje de vacio en la gráfica por hora');
ok(/Sin ventas registradas hoy/.test(q('ultimasVentas').textContent), 'mensaje de vacio en las ultimas ventas');
eq(q('ingresosSub').textContent, 'Aún sin ventas hoy', 'el hero lo dice');
ok(q('margenChip').hidden, 'sin ventas no se muestra un margen de 0%');
eq(q('ticketProm').textContent, money('0.00'), 'ticket promedio sin ventas es 0, no NaN');

estado.body = datosBase();
await refrescar();
ok(cols().length > 0, 'la gráfica vuelve al haber datos otra vez');
ok(!q('margenChip').hidden, 'el margen vuelve');
eq(q('ingresosHoy').textContent, money('4,520.50'), 'valores restaurados');

console.log('\n\x1b[1m7. Banner de conexion en fallo de refresco\x1b[0m');
ok(q('banner').hidden, 'banner oculto mientras hay conexion');
G('ultimoOk = ' + (Date.now() - 10 * 60 * 1000));   // ultimo exito hace 10 min
estado.ok = false;
await refrescar();
ok(!q('banner').hidden, 'banner visible tras fallar el refresco');
ok(/datos de hace 10 min/.test(q('banner').textContent), 'banner dice la antiguedad',
   JSON.stringify(q('banner').textContent));
eq(q('ingresosHoy').textContent, money('4,520.50'), 'se conservan los ultimos datos buenos');

estado.ok = true;
await refrescar();
ok(q('banner').hidden, 'banner se oculta al recuperar conexion');

console.log('\n\x1b[1m8. Escapado XSS\x1b[0m');
estado.body = datosBase({
    negocio: '<img src=x onerror=alert(1)>',
    turno_usuario: '<b>jefe</b>',
    ultimas_ventas: [{ hora: '10:00', cajero: '<script>alert(2)</script>', total_centavos: 100 }],
});
estado.body.extra.cancelaciones_hoy.por_usuario = [{ usuario: '<img src=x onerror=alert(3)>', cancelaciones: 1, devoluciones: 0 }];
estado.body.extra.inventario.urgentes = [{ nombre: '<script>alert(4)</script>', cantidad: 0, minimo: 1, unidad: 0 }];
await refrescar();
eq(q('negocio').textContent, '<img src=x onerror=alert(1)>', 'negocio se muestra como texto literal');
eq(q('negocio').querySelectorAll('img').length, 0, 'no se inyecto ningun <img>');
eq(q('turnoUsuario').querySelectorAll('b').length, 0, 'el cajero del turno no inyecta HTML');
ok(!/<script>/.test(q('ultimasVentas').innerHTML), 'cajero escapado en la lista de ventas');
eq(q('cancelCuerpo').querySelectorAll('img').length, 0, 'el usuario de una cancelación no inyecta HTML');
ok(!/<script>/.test(q('invCuerpo').innerHTML), 'el nombre de un producto no inyecta HTML');

console.log('\n\x1b[1m9. Formatos de turno_inicio (el POS manda dd-MM-yyyy HH:mm:ss)\x1b[0m');
const turno = async (v) => { estado.body = datosBase({ turno_inicio: v }); await refrescar(); return norm(q('turnoInicio').textContent); };
ok(/^08:15\s*a\.?\s?m\.?$/.test(await turno('03-09-2026 08:15:00')), 'formato del POS, mismo dia');
ok(/^08:15\s*a\.?\s?m\.?$/.test(await turno('2026-09-03T08:15:00')), 'ISO con T, mismo dia');
ok(/^08:15\s*a\.?\s?m\.?$/.test(await turno('2026-09-03 08:15:00')), 'ISO con espacio, mismo dia');
ok(/^08:15\s*a\.?\s?m\.?$/.test(await turno('08:15')), 'solo hora HH:MM');
eq(await turno(''), '—', 'vacio muestra guion');
eq(await turno('turno matutino'), 'turno matutino', 'texto libre se respeta');
ok(/^1 Sep, 08:30\s*p\.?\s?m\.?$/.test(await turno('01-09-2026 20:30:00')), 'otro dia (formato del POS) incluye la fecha');
ok(/^1 Sep, 08:30\s*p\.?\s?m\.?$/.test(await turno('2026-09-01 20:30:00')), 'otro dia (ISO) incluye la fecha');
eq(await turno('31-02-2026 99:99:00'), '31-02-2026 99:99:00', 'una fecha imposible se muestra tal cual, sin romper');

console.log('\n\x1b[1m10. Sync vieja del POS y margen bajo\x1b[0m');
estado.body = datosBase({ hace_minutos: 95, ventas_hoy: 100000, ganancia_hoy: 5000 });
await refrescar();
ok(q('syncBadge').classList.contains('stale'), 'sync vieja => estado stale');
eq(norm(q('sync').textContent), 'Sin datos del POS hace 1h 35min', 'formato de horas');
eq(q('margenChip').textContent, 'Margen 5.0%', 'margen bajo');
ok(q('margenChip').classList.contains('is-warn'), 'margen < 20% => is-warn');
ok(!q('margenChip').classList.contains('is-good'), 'clase good removida');

console.log('\n\x1b[1m11. Hoy contra ayer y contra la semana pasada\x1b[0m');
const semanaConAyer = [{ fecha: AYER, total_centavos: 1000000 }, { fecha: HOY, total_centavos: 452050 }];
estado.body = datosBase({ historico_semana: semanaConAyer });
await refrescar();
ok(!q('vs').hidden, 'con dato de ayer se muestra el comparativo');
eq(q('vsTexto').textContent, 'Llevas el 45% de lo de ayer', 'porcentaje de avance');
eq(norm(q('vsAyer').textContent), 'Ayer $ 10,000.00', 'total de ayer');
eq(q('vsFill').style.getPropertyValue('width'), '45.2%', 'la barra avanza lo mismo');
ok(!q('vs').classList.contains('is-over'), 'todavia no supera a ayer');

estado.body = datosBase({ ventas_hoy: 1200000, historico_semana: semanaConAyer });
await refrescar();
eq(q('vsTexto').textContent, 'Superas lo de ayer (+20%)', 'si supera a ayer lo dice');
ok(q('vs').classList.contains('is-over'), 'marcado como superado');
eq(q('vsFill').style.getPropertyValue('width'), '100%', 'la barra no pasa de 100%');

ok(q('vsSemana').hidden, 'sin historial diario no hay comparativo contra la semana pasada');
estado.body = datosBase({ historico_dias: [dia('2026-08-27', 1000000)] });   // hace 7 dias = jueves
await refrescar();
ok(!q('vsSemana').hidden, 'con historial diario aparece');
eq(norm(q('vsSemana').textContent), 'Jueves pasado cerró en $ 10,000.00 · llevas el 45%', 'el mismo dia de la semana pasada');
estado.body = datosBase({ historico_dias: [dia('2026-08-27', 300000)] });
await refrescar();
ok(/ya lo superas/.test(q('vsSemana').textContent), 'si ya lo superó, lo dice', q('vsSemana').textContent);
estado.body = datosBase({ historico_dias: [dia('2026-08-27', 0)] });
await refrescar();
ok(q('vsSemana').hidden, 'un día sin ventas la semana pasada no sirve de comparación');

console.log('\n\x1b[1m12. Semana: totales, promedio, mejor dia y gráfica\x1b[0m');
const dias = [
    ['2026-08-28', 800000], ['2026-08-29', 1200000], ['2026-08-30', 0],   // 30 sin venta
    ['2026-08-31', 1000000], [AYER, 600000], [HOY, 400000],
];
estado.body = datosBase({ historico_semana: dias.filter(d => d[1] > 0).map(d => ({ fecha: d[0], total_centavos: d[1] })) });
await refrescar();
// ventana de 7 dias que termina hoy: 28-ago ... 03-sep
eq(q('semanaTotal').textContent, money('40,000.00'), 'total de los 7 dias');
eq(q('semanaSub').textContent, '5 días con ventas', 'cuenta solo los dias con venta');
eq(q('semanaPromedio').textContent, money('8,000.00'), 'promedio solo sobre dias con venta (40000/5)');
eq(q('semanaMejor').textContent, money('12,000.00'), 'mejor dia: importe');
ok(/29/.test(q('semanaMejorSub').textContent), 'mejor dia: cual fue', JSON.stringify(q('semanaMejorSub').textContent));
const colsSem = () => Array.from(q('semanaGrafica').querySelectorAll('.col'));
eq(colsSem().length, 7, 'siempre 7 columnas');
eq(colsSem().map(c => c.getAttribute('data-key')).join(','),
   '2026-08-28,2026-08-29,2026-08-30,2026-08-31,2026-09-01,2026-09-02,2026-09-03', 'dias consecutivos que terminan hoy');
ok(colsSem()[6].classList.contains('is-peak'), 'hoy va marcado');
ok(colsSem()[2].classList.contains('is-zero'), 'el dia sin venta queda en cero');
ok(q('semanaGrafica').querySelector('.avg') !== null, 'linea de promedio');
ok(/en curso/.test(q('semanaLectura').textContent), 'la lectura por omision es hoy (en curso)', JSON.stringify(q('semanaLectura').textContent));
clic(colsSem()[1]);
ok(/\+50% vs prom\./.test(norm(q('semanaLectura').textContent)), 'dia elegido contra el promedio (12000 vs 8000)',
   JSON.stringify(norm(q('semanaLectura').textContent)));
ok(/en curso/.test(q('semanaLista').textContent), 'el detalle marca hoy como en curso');
clic(colsSem()[1]);
ok(q('semanaVsAnterior').textContent === '', 'sin historial diario no hay «semana anterior»');

estado.body = datosBase({
    historico_semana: [{ fecha: AYER, total_centavos: 600000 }, { fecha: HOY, total_centavos: 452050 }],
    historico_dias: ['2026-08-21', '2026-08-22', '2026-08-23', '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27'].map(f => dia(f, 100000)),
});
await refrescar();
ok(/Semana anterior: \$ 7,000\.00 · \+50%/.test(norm(q('semanaVsAnterior').textContent)),
   'semana anterior: total y variación (1,052,050 vs 700,000)', JSON.stringify(norm(q('semanaVsAnterior').textContent)));

console.log('\n\x1b[1m13. Mes: acumulado contra el mes anterior a la misma fecha\x1b[0m');
estado.body = datosBase({
    historico_dias: [
        dia('2026-09-01', 200000), dia('2026-09-02', 600000, { ganancia: 150000 }),
        dia('2026-08-01', 300000), dia('2026-08-02', 200000), dia('2026-08-03', 100000), dia('2026-08-04', 999999999),   // el 4 no cuenta
        dia(HOY, 111111, { ganancia: 11111 }),   // renglón viejo de hoy: debe mandar la foto EN VIVO, no esto
    ],
});
estado.body.historico_dias[0].ganancia = 50000;
await refrescar();
ok(!q('mesContenido').hidden && q('mesVacio').hidden, 'con datos se muestra el mes');
eq(q('mesEtiqueta').textContent, 'Sep hasta hoy', 'rótulo del mes');
eq(q('mesTotal').textContent, money('12,520.50'), 'acumulado: días 1 y 2 guardados + hoy en vivo (200000 + 600000 + 452050)');
eq(q('mesGanancia').textContent, money('3,350.00'), 'ganancia: 50000 + 150000 + hoy en vivo 135000');
eq(q('mesSub').textContent, '3 días con ventas', 'días con ventas');
ok(/Ago a esta misma fecha: \$ 6,000\.00 · \+109%/.test(norm(q('mesVsAnterior').textContent)),
   'contra el mes anterior hasta el mismo día (el día 4 de agosto NO entra)', JSON.stringify(norm(q('mesVsAnterior').textContent)));
const colsMes = () => Array.from(q('mesGrafica').querySelectorAll('.col'));
eq(colsMes().length, 3, 'una columna por día transcurrido');
ok(colsMes()[2].classList.contains('is-peak') || colsMes()[1].classList.contains('is-peak'), 'hay un día marcado');
clic(colsMes()[0]);
ok(/\$ 2,000\.00/.test(norm(q('mesLectura').textContent)), 'tocar un día muestra su importe', JSON.stringify(norm(q('mesLectura').textContent)));
clic(colsMes()[0]);

estado.body = datosBase({ ventas_hoy: 0, abonos_hoy: 0, historico_dias: [] });
await refrescar();
ok(!q('mesVacio').hidden && q('mesContenido').hidden, 'sin historial diario ni ventas se explica en vez de mostrar ceros');
estado.body = datosBase({ historico_dias: [] });
await refrescar();
ok(q('mesVacio').hidden, 'con ventas hoy el mes se muestra aunque falte el historial');
ok(q('mesVsAnterior').textContent === '', 'sin mes anterior no se inventa la comparación');

console.log('\n\x1b[1m14. Cómo pagaron hoy\x1b[0m');
estado.body = datosBase();
await refrescar();
ok(!q('pagosTile').hidden, 'con extra aparece la tarjeta de pagos');
const pt = leer(q('pagosCuerpo'));
ok(/Efectivo \$ 250\.00 37%/.test(pt), 'efectivo y su porcentaje (25000 de 67000)', pt);
ok(/Tarjeta \$ 320\.00 48%/.test(pt), 'tarjeta', pt);
ok(/Transferencia \$ 100\.00 15%/.test(pt), 'transferencia', pt);
ok(/Comisión de tarjeta: \$ 6\.73/.test(pt), 'comisión de tarjeta', pt);
eq(q('pagosCuerpo').querySelectorAll('.stack i').length, 3, 'la barra apilada tiene sus tres partes');
estado.body = datosBase(); estado.body.extra.pagos_hoy = { efectivo: 0, tarjeta: 0, transferencia: 0, comision: 0 };
await refrescar();
ok(/Aún no hay cobros hoy/.test(q('pagosCuerpo').textContent), 'sin cobros: mensaje, no una barra vacía');
estado.body = datosBase(); estado.body.extra.pagos_hoy = { efectivo: 1000, tarjeta: 0, transferencia: 0, comision: 0 };
await refrescar();
ok(!/Comisión/.test(q('pagosCuerpo').textContent), 'sin comisión no se menciona');
eq(q('pagosCuerpo').querySelectorAll('.stack i').length, 1, 'solo las formas usadas van en la barra');

console.log('\n\x1b[1m15. Caja del turno\x1b[0m');
estado.body = datosBase();
await refrescar();
const cj = leer(q('cajaCuerpo'));
ok(/Efectivo esperado en el cajón \$ 730\.00/.test(cj), 'efectivo esperado', cj);
ok(/Fondo inicial \$ 500\.00/.test(cj), 'fondo inicial', cj);
ok(/Ventas en efectivo y abonos \$ 250\.00/.test(cj), 'ventas en efectivo', cj);
ok(/Ingresos al cajón \$ 40\.00/.test(cj), 'ingresos', cj);
ok(/Retiros y gastos del cajón .\$ 60\.00/.test(cj), 'retiros con signo menos', cj);
ok(/Tarjeta \$ 320\.00/.test(cj) && /Transferencia \$ 100\.00/.test(cj), 'tarjeta y transferencia aparte, fuera del cajón', cj);
ok(q('turnoAlerta').hidden, 'turno de hoy: sin aviso');
estado.body = datosBase(); estado.body.extra.caja = null; estado.body.turno_abierto = 0;
await refrescar();
ok(/No hay un turno abierto/.test(q('cajaCuerpo').textContent), 'sin turno abierto se explica (caja null)');
ok(!/\$ 730/.test(q('cajaCuerpo').textContent), '…y no muestra una caja inventada');
eq(norm(q('turnoEstado').textContent), 'Cerrado', 'la tarjeta de turno dice Cerrado');

console.log('\n\x1b[1m16. Aviso de turno abierto demasiado tiempo\x1b[0m');
estado.body = datosBase({ turno_inicio: '02-09-2026 18:30:00' });
await refrescar();
ok(!q('turnoAlerta').hidden, 'abierto desde ayer: aviso');
ok(/Turno abierto desde ayer/.test(q('turnoAlerta').textContent), 'dice «desde ayer»', q('turnoAlerta').textContent);
ok(!/\.\./.test(q('turnoAlerta').textContent), 'sin doble punto («p.m..»)');
ok(/Abierto desde ayer/.test(q('turnoEstado').textContent), 'la tarjeta de turno también lo marca');
estado.body = datosBase({ turno_inicio: '01-09-2026 18:30:00' });
await refrescar();
ok(/desde el 1 Sep/.test(q('turnoAlerta').textContent), 'si lleva días, dice la fecha y no «ayer»', q('turnoAlerta').textContent);
estado.body = datosBase({ turno_inicio: '03-09-2026 01:00:00' });   // 14 h 20 min
await refrescar();
ok(!q('turnoAlerta').hidden && /hace 14 horas/.test(q('turnoAlerta').textContent), '14 h abierto: aviso por duración', q('turnoAlerta').textContent);
estado.body = datosBase({ turno_inicio: '03-09-2026 02:00:00' });   // 13 h 20 min
await refrescar();
ok(q('turnoAlerta').hidden, '13 h: todavía normal');
estado.body = datosBase({ turno_inicio: '01-09-2026 18:30:00', turno_abierto: 0 });
await refrescar();
ok(q('turnoAlerta').hidden, 'un turno CERRADO nunca avisa');
estado.body = datosBase({ turno_inicio: 'sin fecha' });
await refrescar();
ok(q('turnoAlerta').hidden, 'sin fecha legible no se inventa un aviso');

console.log('\n\x1b[1m17. Cancelaciones y devoluciones\x1b[0m');
estado.body = datosBase();
await refrescar();
const cc = leer(q('cancelCuerpo'));
ok(!q('cancelTile').hidden, 'la tarjeta se muestra');
eq(q('cancelChip').textContent, '3', 'el chip cuenta cancelaciones + devoluciones');
ok(/Ventas canceladas \(2\) \$ 486\.50/.test(cc), 'ventas canceladas y su monto', cc);
ok(/Devoluciones de artículos \(1\) \$ 89\.00/.test(cc), 'devoluciones y su monto', cc);
ok(/Luis 2 cancelaciones/.test(cc) && /Ana 1 devolución/.test(cc), 'quién lo hizo, con el plural bien escrito', cc);
ok(!/ó(nes)\b/.test(cc.replace(/cancelaciones|devoluciones/g, '')) && !/cancelaciónes|devoluciónes/.test(cc), 'sin «cancelaciónes» ni «devoluciónes»');
ok(/desde la versión 3\.3\.0/.test(cc), 'avisa desde cuándo se registra');
estado.body = datosBase(); estado.body.extra.cancelaciones_hoy = { cancelaciones: 1, monto_cancelado: 100, devoluciones: 0, monto_devuelto: 0, por_usuario: [{ usuario: 'Luis', cancelaciones: 1, devoluciones: 0 }] };
await refrescar();
ok(/Luis 1 cancelación(?!es)/.test(leer(q('cancelCuerpo'))), 'singular: «1 cancelación»');
estado.body = datosBase(); estado.body.extra.cancelaciones_hoy = { cancelaciones: 0, monto_cancelado: 0, devoluciones: 0, monto_devuelto: 0, por_usuario: [] };
await refrescar();
ok(/no se canceló ni se devolvió nada/.test(q('cancelCuerpo').textContent), 'ninguna: lo dice');
ok(/Ninguna/.test(q('cancelChip').textContent), 'chip «Ninguna»');

console.log('\n\x1b[1m18. Inventario\x1b[0m');
estado.body = datosBase();
await refrescar();
const iv = leer(q('invCuerpo'));
ok(/Agotados 2/.test(iv) && /Bajo mínimo 1/.test(iv), 'contadores', iv);
ok(/Valor del inventario \$ 63\.50/.test(iv), 'valor a costo (6350 centavos)', iv);
const filasUrg = Array.from(q('invCuerpo').querySelectorAll('.urg'));
eq(filasUrg.length, 3, 'una fila por producto urgente');
ok(/Refresco B/.test(filasUrg[0].textContent) && /−3 Kg/.test(norm(filasUrg[0].textContent)), 'una existencia negativa REAL se ve como −3 Kg');
ok(/Agotado/.test(filasUrg[0].textContent) && /Agotado/.test(filasUrg[1].textContent), 'cero y negativo: Agotado');
ok(/Bajo/.test(filasUrg[2].textContent) && /2 Pz/.test(filasUrg[2].textContent) && /mínimo 5/.test(filasUrg[2].textContent), 'bajo mínimo con su mínimo y su unidad');
estado.body = datosBase(); estado.body.extra.inventario.urgentes = [{ nombre: 'X', cantidad: 1500, minimo: 3, unidad: 2 }, { nombre: 'Y', cantidad: 250, minimo: 3, unidad: 1 }, { nombre: 'Z', cantidad: 1000, minimo: 3, unidad: 3 }];
await refrescar();
const iv2 = leer(q('invCuerpo'));
ok(/1\.5 L/.test(iv2) && /0\.25 Kg/.test(iv2) && /1 Ud/.test(iv2), 'decimales sin ceros de más y las cuatro unidades (Pz, Kg, L, Ud)', iv2);
estado.body = datosBase(); estado.body.extra.inventario = { agotados: 0, bajo_minimo: 0, valor_costo: 100000, urgentes: [] };
await refrescar();
ok(/Todo en orden/.test(q('invCuerpo').textContent), 'sin urgentes: «Todo en orden»');

console.log('\n\x1b[1m19. POS anterior a la 3.3.0 (sin extra)\x1b[0m');
const sinExtra = datosBase(); delete sinExtra.extra; delete sinExtra.historico_dias;
estado.body = sinExtra;
await refrescar();
ok(q('pagosTile').hidden, 'sin extra no aparece «Cómo pagaron»');
ok(/versión 3\.3\.0/.test(q('cajaCuerpo').textContent), 'Caja explica que hay que actualizar el POS');
ok(/versión 3\.3\.0/.test(q('invCuerpo').textContent), 'Inventario también');
ok(q('cancelTile').hidden, 'el aviso no se repite en la tarjeta de cancelaciones');
ok(q('turnoAlerta').hidden, '…ni hay un aviso de turno falso');
eq(q('ingresosHoy').textContent, money('4,520.50'), 'lo de siempre sigue funcionando');
ok(q('vsSemana').hidden, 'sin historial diario no hay comparativo semanal');

console.log('\n\x1b[1m19b. Etapa 2: lo más vendido, cajeros, por cobrar, gastos, meta, sesión y compartir\x1b[0m');
const e2 = (mod) => {
    const b = datosBase();
    Object.assign(b.extra, {
        sesion: null,
        top_productos: {
            hoy: [
                { nombre: 'Pila AA',     importe: 20000, cantidad: 3000, unidad: 0 },
                { nombre: 'Jamón',       importe:  9000, cantidad: 1500, unidad: 1 },
                { nombre: 'Coca 600ml',  importe:  7500, cantidad: 5000, unidad: 0 },
                { nombre: 'Aceite',      importe:  2500, cantidad: 1000, unidad: 2 },
                { nombre: 'Chicle',      importe:   500, cantidad: 1000, unidad: 3 },
            ],
            semana: [{ nombre: 'Jamón', importe: 21000, cantidad: 3500, unidad: 1 }, { nombre: 'Pila AA', importe: 20000, cantidad: 3000, unidad: 0 }],
            mes:    [{ nombre: 'Aceite', importe: 12500, cantidad: 5000, unidad: 2 }],
        },
        por_cajero: [{ cajero: 'ANA', tickets: 7, ventas: 300000 }, { cajero: 'LUIS', tickets: 1, ventas: 100000 }, { cajero: '', tickets: 4, ventas: 5000 }],
        apartados: { activos: 3, por_cobrar: 125050, vencidos: 1 },
        presupuestos: { vigentes: 4, monto: 8000000, por_vencer: 2, vencidos: 1 },
        gastos: { hoy: 25000, mes: 120000 },
        meta_diaria: 600000,
    });
    if (mod) mod(b);
    return b;
};
const cargar = async (mod) => { estado.body = e2(mod); await refrescar(); };
const abierta = (url) => decodeURIComponent(url.replace('https://wa.me/?text=', ''));

// ── Meta diaria ──
await cargar();
ok(!q('meta').hidden, 'con meta aparece la barra en la cabecera');
eq(q('metaTexto').textContent, 'Meta del día: llevas el 75%', 'cuánto lleva (4,520.50 de 6,000 = 75%)');
eq(q('metaValor').textContent, money('6,000.00'), 'la meta, a la derecha');
eq(q('metaFill').style.getPropertyValue('width'), '75.3%', 'la barra llena lo que lleva');
ok(!q('meta').classList.contains('is-over'), 'sin cumplir no está en verde');
await cargar(b => { b.extra.meta_diaria = 400000; });
eq(q('metaTexto').textContent, 'Meta del día cumplida', 'al pasar la meta, «cumplida»');
eq(q('metaFill').style.getPropertyValue('width'), '100%', 'la barra no se sale de su caja');
ok(q('meta').classList.contains('is-over'), 'y se pinta en verde');
await cargar(b => { b.extra.meta_diaria = 452050; });
eq(q('metaTexto').textContent, 'Meta del día cumplida', 'justo la meta ya está cumplida');
await cargar(b => { b.extra.meta_diaria = 452051; });
eq(q('metaTexto').textContent, 'Meta del día: llevas el 99%', 'a un centavo de la meta NO dice 100%');
await cargar(b => { b.extra.meta_diaria = 0; });
ok(q('meta').hidden, 'meta 0 = sin meta: no hay barra');
await cargar(b => { delete b.extra.meta_diaria; });
ok(q('meta').hidden, 'un POS sin ese dato: no hay barra');
await cargar(b => { b.extra.meta_diaria = -5; });
ok(q('meta').hidden, 'una meta negativa no se muestra');

// ── Lo más vendido ──
await cargar();
ok(!q('topTile').hidden, 'aparece «Lo más vendido»');
let tp = leer(q('topLista'));
eq(q('topLista').querySelectorAll('.hrow').length, 5, 'cinco renglones');
ok(/^1\. Pila AA \$ 200\.00 3 Pz 2\. Jamón \$ 90\.00 1\.5 Kg 3\. Coca 600ml \$ 75\.00 5 Pz 4\. Aceite \$ 25\.00 1 L 5\. Chicle \$ 5\.00 1 Ud$/.test(tp),
   'ordenados como los manda el POS, con importe y cantidad en su unidad (Pz, Kg, L, Ud)', tp);
const barras = Array.from(q('topLista').querySelectorAll('.hbar i')).map(i => i.style.getPropertyValue('--w'));
eq(barras[0] + ' ' + barras[1], '100.0% 45.0%', 'las barras son relativas al primero');
ok(doc.querySelector('[data-top="hoy"]').getAttribute('aria-selected') === 'true', 'Hoy está elegido al empezar');
clic(doc.querySelector('[data-top="semana"]'));
tp = leer(q('topLista'));
ok(/^1\. Jamón \$ 210\.00 3\.5 Kg 2\. Pila AA \$ 200\.00 3 Pz$/.test(tp), 'el selector pasa a 7 días', tp);
ok(doc.querySelector('[data-top="semana"]').getAttribute('aria-selected') === 'true'
   && doc.querySelector('[data-top="hoy"]').getAttribute('aria-selected') === 'false', 'y marca cuál está elegido');
await refrescar();
ok(/^1\. Jamón/.test(leer(q('topLista'))), 'el periodo elegido sobrevive al refresco automático');
clic(doc.querySelector('[data-top="mes"]'));
ok(/^1\. Aceite \$ 125\.00 5 L$/.test(leer(q('topLista'))), 'Mes');
await cargar(b => { b.extra.top_productos.mes = []; });
ok(/Sin ventas en este periodo/.test(q('topLista').textContent), 'un periodo sin ventas lo dice');
clic(doc.querySelector('[data-top="hoy"]'));
await cargar(b => { b.extra.top_productos.hoy = []; });
ok(/Aún no hay ventas hoy/.test(q('topLista').textContent), 'hoy sin ventas: «Aún no hay ventas hoy»');
await cargar(b => { b.extra.top_productos.hoy = [{ nombre: '<img src=x onerror=alert(1)>', importe: 100, cantidad: 1000, unidad: 0 }, { nombre: '', importe: 50, cantidad: 0, unidad: 0 }]; });
eq(q('topLista').querySelectorAll('img').length, 0, 'un nombre con HTML no inyecta nada');
ok(/&lt;img|<img/.test(q('topLista').textContent) || /<img/.test(q('topLista').textContent), 'se muestra como texto');
ok(/2\. Sin nombre/.test(leer(q('topLista'))), 'un producto sin nombre no deja el renglón vacío');
await cargar(b => { delete b.extra.top_productos; });
ok(q('topTile').hidden, 'un POS sin ese dato: la tarjeta no aparece');
await cargar(b => { b.extra.top_productos = 'basura'; });
ok(q('topTile').hidden, 'un dato malformado tampoco la muestra (ni rompe)');

// ── Ventas por cajero ──
await cargar();
ok(!q('cajerosTile').hidden, 'aparece «Ventas por cajero»');
const cj2 = leer(q('cajerosLista'));
ok(/^ANA \$ 3,000\.00 7 tickets LUIS \$ 1,000\.00 1 ticket Sin cajero \$ 50\.00 4 tickets$/.test(cj2),
   'importe y tickets por cajero, con singular/plural y «Sin cajero» si el nombre viene vacío', cj2);
await cargar(b => { b.extra.por_cajero = [{ cajero: 'ANA', tickets: 7, ventas: 300000 }]; });
ok(q('cajerosTile').hidden, 'con un solo cajero no se muestra (diría lo mismo que el total)');
await cargar(b => { delete b.extra.por_cajero; });
ok(q('cajerosTile').hidden, 'sin el dato tampoco');
await cargar(b => { b.extra.por_cajero = [{ cajero: '<b>x</b>', tickets: 1, ventas: 100 }, { cajero: 'B', tickets: 1, ventas: 50 }]; });
eq(q('cajerosLista').querySelectorAll('b').length, 2, 'el nombre del cajero va escapado (solo quedan los <b> de los dos importes)');
ok(q('cajerosLista').textContent.indexOf('<b>x</b>') >= 0, 'y se ve como texto');

// ── Sesión cerrada con el turno abierto ──
await cargar();
ok(q('sesionAviso').hidden && q('sesionFila').hidden, 'sin sesión cerrada no hay aviso ni fila');
ok(!/Sesión cerrada/.test(q('turnoEstado').textContent), '…ni chip');
await cargar(b => { b.extra.sesion = { cerrada: true, desde: '03-09-2026 14:05:00' }; });
ok(/Abierto/.test(q('turnoEstado').textContent) && /Sesión cerrada/.test(q('turnoEstado').textContent), 'el turno sigue «Abierto» y además dice «Sesión cerrada»');
ok(!q('sesionFila').hidden && /^Cerrada a las 0?2:05/.test(norm(q('sesionTexto').textContent)), 'la fila Sesión dice a qué hora', q('sesionTexto').textContent);
ok(!q('sesionAviso').hidden, 'Caja muestra el aviso informativo');
const sa = leer(q('sesionAviso'));
ok(/Sesión cerrada con el turno abierto/.test(sa) && /a las 0?2:05/.test(sa) && /turno de Maria sigue abierto/.test(sa), 'dice quién tiene el turno y desde cuándo', sa);
ok(!/\.\./.test(sa), 'sin doble punto («p.m..»)', sa);
ok(q('turnoAlerta').hidden, 'no se confunde con el aviso de turno largo');
await cargar(b => { b.turno_inicio = '02-09-2026 18:30:00'; b.extra.sesion = { cerrada: true, desde: '03-09-2026 14:05:00' }; });
ok(!q('sesionAviso').hidden && !q('turnoAlerta').hidden, 'conviven: turno desde ayer Y sesión cerrada');
await cargar(b => { b.extra.sesion = { cerrada: true, desde: '02-09-2026 21:00:00' }; });
ok(/ayer a las 0?9:00/.test(q('sesionTexto').textContent), 'si fue ayer, lo dice', q('sesionTexto').textContent);
await cargar(b => { b.extra.sesion = { cerrada: true, desde: '01-09-2026 21:00:00' }; });
ok(/el 1 Sep a las 0?9:00/.test(q('sesionTexto').textContent), 'si fue antes, dice la fecha', q('sesionTexto').textContent);
await cargar(b => { b.extra.sesion = { cerrada: true, desde: '' }; });
ok(!q('sesionAviso').hidden && norm(q('sesionTexto').textContent) === 'Cerrada' && /La sesión se cerró y el turno/.test(q('sesionAviso').textContent) && !/ a las /.test(q('sesionAviso').textContent),
   'sin hora legible no inventa una', q('sesionAviso').textContent);
await cargar(b => { b.turno_abierto = 0; b.extra.caja = null; b.extra.sesion = { cerrada: true, desde: '03-09-2026 14:05:00' }; });
ok(q('sesionAviso').hidden && q('sesionFila').hidden && !/Sesión cerrada/.test(q('turnoEstado').textContent), 'con el turno CERRADO la marca vieja no cuenta');
await cargar(b => { b.extra.sesion = { cerrada: false, desde: '03-09-2026 14:05:00' }; });
ok(q('sesionAviso').hidden, '«cerrada: false» no es sesión cerrada');
await cargar(b => { b.extra.sesion = { cerrada: 'true', desde: '03-09-2026 14:05:00' }; });
ok(q('sesionAviso').hidden, 'solo un true de verdad cuenta (no el texto «true»)');
await cargar(b => { b.extra.sesion = { cerrada: true, desde: '<img src=x>' }; });
eq(q('sesionAviso').querySelectorAll('img').length, 0, 'nada del servidor se inyecta como HTML');

// ── Por cobrar, presupuestos y gastos (Caja) ──
await cargar();
ok(!q('cobrarCuerpo').hidden, 'Caja muestra la sección de lo pendiente');
const cb = leer(q('cobrarCuerpo'));
ok(/Apartados por cobrar 1 vencido \$ 1,250\.50 3 apartados sin entregar/.test(cb), 'apartados: saldo, cuántos y cuántos vencidos', cb);
ok(/Presupuestos pendientes 2 por vencer 1 vencido \$ 80,000\.00 4 presupuestos sin respuesta/.test(cb), 'presupuestos: monto, cuántos, por vencer y vencidos', cb);
ok(/Gastos del negocio \$ 250\.00 hoy \$ 1,200\.00 este mes/.test(cb), 'gastos de hoy y del mes', cb);
await cargar(b => { b.extra.apartados = { activos: 1, por_cobrar: 5000, vencidos: 0 }; b.extra.presupuestos = { vigentes: 1, monto: 1000, por_vencer: 0, vencidos: 0 }; });
const cb1 = leer(q('cobrarCuerpo'));
ok(/1 apartado sin entregar/.test(cb1) && !/apartados sin entregar/.test(cb1) && !/vencid/.test(cb1.split('Gastos')[0]), 'singular y sin chips cuando no hay vencidos', cb1);
ok(/1 presupuesto sin respuesta/.test(cb1) && !/por vencer/.test(cb1), 'presupuesto singular, sin chips', cb1);
await cargar(b => { b.extra.apartados = { activos: 0, por_cobrar: 0, vencidos: 0 }; b.extra.presupuestos = { vigentes: 0, monto: 0, por_vencer: 0, vencidos: 0 }; });
const cb0 = leer(q('cobrarCuerpo'));
ok(!/Apartados por cobrar/.test(cb0) && !/Presupuestos pendientes/.test(cb0), 'sin apartados ni presupuestos esas tarjetas no aparecen (no estorban a quien no los usa)', cb0);
ok(/Gastos del negocio/.test(cb0), '…pero gastos sí');
await cargar(b => { b.extra.gastos = { hoy: 0, mes: 0 }; });
ok(/Sin gastos registrados este mes/.test(q('cobrarCuerpo').textContent), 'sin gastos lo dice');
await cargar(b => { delete b.extra.apartados; delete b.extra.presupuestos; delete b.extra.gastos; });
ok(q('cobrarCuerpo').hidden, 'un POS sin esos datos: la sección no aparece');
ok(!/versión/.test(q('cobrarCuerpo').textContent), '…ni cita una versión del programa');
await cargar(b => { b.extra.apartados = { activos: -3, por_cobrar: 'x', vencidos: null }; delete b.extra.presupuestos; delete b.extra.gastos; });
ok(q('cobrarCuerpo').hidden, 'cifras absurdas se vuelven cero y la tarjeta no aparece');

// ── Utilidad del mes (Historial → Mes) ──
await cargar();
ok(!q('utilidadTile').hidden, 'con gastos aparece «Utilidad del mes»');
eq(q('utilidadValor').textContent, money('150.00'), 'ganancia del mes (1,350.00) menos gastos del mes (1,200.00)');
ok(q('utilidadValor').classList.contains('c-ganancia') && !q('utilidadValor').classList.contains('c-alerta'), 'en verde si es positiva');
ok(/Ganancia \$ 1,350\.00 . gastos \$ 1,200\.00/.test(norm(q('utilidadSub').textContent)), 'muestra la resta', q('utilidadSub').textContent);
await cargar(b => { b.extra.gastos.mes = 200000; });
eq(q('utilidadValor').textContent, '−' + money('650.00'), 'si los gastos superan la ganancia, negativa con signo menos');
ok(q('utilidadValor').classList.contains('c-alerta') && !q('utilidadValor').classList.contains('c-ganancia'), '…y en alerta');
await cargar(b => { b.extra.gastos.mes = 135000; });
eq(q('utilidadValor').textContent, money('0.00'), 'justo en cero: $ 0.00, sin signo');
await cargar(b => { delete b.extra.gastos; });
ok(q('utilidadTile').hidden, 'sin el dato de gastos no se calcula una utilidad falsa');

// ── Compartir el resumen ──
await cargar();
const texto = G('textoResumen(ultimoD)');
ok(!/ /.test(texto), 'el texto usa espacios normales (no el fino del panel)');
const lineas = texto.split('\n');
eq(lineas[0], '*Abarrotes La Esquina*', 'el negocio en negritas de WhatsApp');
ok(/^Resumen del jueves,? 3 de septiembre$/.test(lineas[1]), 'la fecha del negocio', lineas[1]);
ok(lineas.includes('Ingresos: $ 4,520.50'), 'ingresos', texto);
ok(lineas.includes('Ganancia: $ 1,350.00 (margen 29.9%)'), 'ganancia y margen', texto);
ok(lineas.includes('Tickets: 23 · promedio $ 196.54'), 'tickets y promedio', texto);
ok(lineas.includes('Cobros: efectivo $ 250.00 · tarjeta $ 320.00 · transferencia $ 100.00'), 'cómo pagaron', texto);
ok(lineas.includes('Meta del día: 75% de $ 6,000.00'), 'la meta', texto);
const iTop = lineas.indexOf('Lo más vendido:');
ok(iTop > 0 && lineas[iTop + 1] === '1. Pila AA · $ 200.00' && lineas[iTop + 3] === '3. Coca 600ml · $ 75.00' && lineas[iTop + 4] === '', 'los 3 más vendidos de hoy (no 5)', texto);
ok(lineas.includes('Efectivo esperado en caja: $ 730.00'), 'la caja esperada, con turno abierto', texto);
await cargar(b => { b.abonos_hoy = 5000; b.extra.caja = null; b.turno_abierto = 0; delete b.extra.top_productos; b.extra.meta_diaria = 400000; });
const texto2 = G('textoResumen(ultimoD)');
ok(/Ingresos: \$ 4,570\.50 \(ventas \$ 4,520\.50 \+ apartados \$ 50\.00\)/.test(texto2), 'con apartados los separa', texto2);
ok(!/Efectivo esperado/.test(texto2) && !/Lo más vendido/.test(texto2), 'sin turno abierto ni tops, esas líneas no van', texto2);
ok(/Meta del día: cumplida \(\$ 4,000\.00\)/.test(texto2), 'meta cumplida', texto2);
const sinNada = datosBase(); delete sinNada.extra;
estado.body = sinNada; await refrescar();
let tSin = ''; try { tSin = G('textoResumen(ultimoD)'); } catch (e) { tSin = 'ERROR ' + e.message; }
ok(/^\*Abarrotes La Esquina\*/.test(tSin) && !/ERROR/.test(tSin), 'con un POS anterior el resumen igual se arma', tSin);

await cargar();
const abiertas = [];
win.open = (url, destino, rasgos) => { abiertas.push({ url, destino, rasgos }); return null; };
const boton = doc.querySelector('[data-act="compartir"]');
ok(!!boton && /Compartir resumen del día/.test(boton.textContent), 'el botón existe en Hoy');
clic(boton); await tick(); await tick();
eq(abiertas.length, 1, 'sin «compartir» del sistema se abre WhatsApp');
ok(abiertas[0] && abiertas[0].url.indexOf('https://wa.me/?text=') === 0 && abierta(abiertas[0].url) === G('textoResumen(ultimoD)'), 'con el texto del resumen, codificado', abiertas[0] && abiertas[0].url);
eq(abiertas[0] && abiertas[0].rasgos, 'noopener', 'la ventana nueva no recibe acceso al panel');

abiertas.length = 0;
const compartidos = [];
Object.defineProperty(win.navigator, 'share', { configurable: true, value: async (datos) => { compartidos.push(datos); } });
clic(boton); await tick(); await tick();
eq(compartidos.length, 1, 'con «compartir» del sistema se usa');
eq(compartidos[0] && compartidos[0].text, G('textoResumen(ultimoD)'), 'con el texto del resumen');
eq(abiertas.length, 0, '…y no abre WhatsApp aparte');

compartidos.length = 0;
Object.defineProperty(win.navigator, 'share', { configurable: true, value: async () => { const e = new Error('cancelado'); e.name = 'AbortError'; throw e; } });
clic(boton); await tick(); await tick();
eq(abiertas.length, 0, 'si la persona cierra la hoja de compartir, no se abre nada más');

Object.defineProperty(win.navigator, 'share', { configurable: true, value: async () => { const e = new Error('no soportado'); e.name = 'NotAllowedError'; throw e; } });
clic(boton); await tick(); await tick();
eq(abiertas.length, 1, 'si «compartir» falla por otra causa, se cae a WhatsApp');
delete win.navigator.share;

// ── Moneda: la tienda eligió euros en su POS ──
await cargar(b => { b.extra.moneda = 'EUR'; });
eq(q('ingresosHoy').textContent, '€' + NBSP + '4,520.50', 'con euros, los importes llevan «€»');
eq(q('metaValor').textContent, '€' + NBSP + '6,000.00', '…también la meta');
ok(/^1\. Pila AA € 200\.00/.test(leer(q('topLista'))), '…y lo más vendido', leer(q('topLista')));
eq(G('compacto(1234500)'), '€12k', 'los rótulos compactos de las gráficas');
const textoEur = G('textoResumen(ultimoD)');
ok(textoEur.split('\n').includes('Ingresos: € 4,520.50') && !/\$/.test(textoEur), 'el resumen para compartir no lleva ningún «$»', textoEur);
{
    // Lo que se ve: un elemento oculto puede guardar el texto de una foto anterior (no se repinta mientras no aplica)
    const visible = doc.getElementById('app').cloneNode(true);
    visible.querySelectorAll('[hidden]').forEach(n => n.remove());
    ok(!/\$\s?\d/.test(leer(visible)), 'en lo que se ve no queda un «$» junto a una cifra', (leer(visible).match(/.{30}\$\s?\d.{10}/) || [])[0]);
}
await cargar(b => { b.extra.moneda = 'MXN'; });
eq(q('ingresosHoy').textContent, money('4,520.50'), 'de vuelta a pesos');
await cargar(b => { b.extra.moneda = 'EUR'; });
await cargar(b => { delete b.extra.moneda; });
eq(q('ingresosHoy').textContent, money('4,520.50'), 'un POS anterior (sin el dato) se ve en pesos, aunque antes hubiera euros');
await cargar(b => { b.extra.moneda = 'USD'; });
eq(q('ingresosHoy').textContent, money('4,520.50'), 'un valor desconocido se ve en pesos');

// Se deja el estado como lo esperan las secciones que siguen
clic(doc.querySelector('[data-top="hoy"]'));
estado.body = sinExtra;
await refrescar();

console.log('\n\x1b[1m20. Pestañas y subpestañas\x1b[0m');
estado.body = datosBase();
await refrescar();
const panel = n => doc.querySelector(`[data-panel="${n}"]`);
const tab   = n => doc.querySelector(`[data-tab="${n}"]`);
const subp  = n => doc.querySelector(`[data-subpanel="${n}"]`);
const sub   = n => doc.querySelector(`[data-sub="${n}"]`);
ok(!panel('hoy').hidden && panel('caja').hidden && panel('inventario').hidden && panel('historial').hidden, 'al abrir solo se ve Hoy');
eq(doc.querySelectorAll('[data-tab]').length, 4, 'cuatro secciones: Hoy, Caja, Inventario, Historial');
clic(tab('caja'));
ok(panel('hoy').hidden && !panel('caja').hidden, 'Caja reemplaza a Hoy');
eq(tab('caja').getAttribute('aria-selected'), 'true', 'aria-selected en la pestaña activa');
eq(tab('hoy').getAttribute('aria-selected'), 'false', 'y quitado de la anterior');
clic(tab('inventario'));
ok(!panel('inventario').hidden && panel('caja').hidden, 'Inventario');
clic(tab('historial'));
ok(!panel('historial').hidden && panel('inventario').hidden, 'Historial');
ok(!subp('semana').hidden && subp('mes').hidden && subp('anio').hidden, 'Historial abre en Semana');
clic(sub('mes'));
ok(!subp('mes').hidden && subp('semana').hidden, 'Mes');
eq(sub('mes').getAttribute('aria-selected'), 'true', 'aria-selected en la subpestaña');
clic(sub('anio'));
ok(!subp('anio').hidden && subp('mes').hidden, 'Año');
await refrescar();
ok(!panel('historial').hidden && !subp('anio').hidden, 'un refresco no devuelve a Hoy ni a Semana');
clic(tab('hoy'));
ok(!panel('hoy').hidden, 'se puede volver a Hoy');
clic(tab('historial'));
ok(!subp('anio').hidden, 'y Historial recuerda en qué subpestaña estaba');
clic(sub('semana'));
clic(tab('hoy'));

console.log('\n\x1b[1m21. Año: solo hasta el mes actual, totales y estado vacio\x1b[0m');
estado.body = datosBase();
await refrescar();
const filasVentas = Array.from(q('mesesVentas').querySelectorAll('.hrow'));
eq(filasVentas.length, 9, 'se listan enero a septiembre (los meses futuros no)');
ok(!/Oct|Nov|Dic/.test(q('mesesVentas').textContent), 'sin octubre, noviembre ni diciembre');
ok(filasVentas[8].classList.contains('is-now') && /en curso/.test(filasVentas[8].textContent), 'el mes actual va marcado');
eq(q('totalVentasAnual').textContent, money('84,520.50'), 'total ventas del año');
eq(q('totalGananciaAnual').textContent, money('21,350.00'), 'total ganancia del año');
eq(q('margenAnualChip').textContent, 'Margen 25.3%', 'margen anual');
eq(q('anualEtiquetaVentas').textContent, 'Ventas 2026', 'rotulo con el año');
ok(/Mejor mes: Ago/.test(q('mejorMes').textContent), 'mejor mes', JSON.stringify(q('mejorMes').textContent));

estado.body = datosBase({ historico_anual: [] });
await refrescar();
ok(!q('anualVacio').hidden && q('anualContenido').hidden, 'sin datos anuales se explica en vez de mostrar ceros');
estado.body = datosBase();
await refrescar();
ok(q('anualVacio').hidden && !q('anualContenido').hidden, 'al llegar datos anuales reaparece el contenido');

console.log('\n\x1b[1m22. Ultimas ventas: se pliegan y se expanden\x1b[0m');
const muchas = Array.from({ length: 20 }, (_, i) => ({ hora: `1${i % 10}:00`, cajero: 'Ana', total_centavos: 1000 + i }));
estado.body = datosBase({ ultimas_ventas: muchas });
await refrescar();
const filasV = () => q('ultimasVentas').querySelectorAll('.venta').length;
eq(filasV(), 6, 'al inicio se ven 6');
ok(/Ver todas \(20\)/.test(q('ultimasVentas').textContent), 'el boton dice cuantas hay');
clic(q('ultimasVentas').querySelector('[data-act="masVentas"]'));
eq(filasV(), 20, 'Ver todas muestra las 20');
await refrescar();
eq(filasV(), 20, 'el refresco respeta que estén expandidas');
clic(q('ultimasVentas').querySelector('[data-act="masVentas"]'));
eq(filasV(), 6, 'Ver menos las pliega');

console.log('\n\x1b[1m23. Tema claro / oscuro\x1b[0m');
const root = doc.documentElement;
ok(!root.getAttribute('data-theme'), 'sin preferencia guardada se sigue al sistema');
clic(q('btnTema'));
eq(root.getAttribute('data-theme'), 'dark', 'el boton fija el oscuro (el sistema era claro)');
eq(win.localStorage.getItem('apdash:tema'), 'dark', 'y lo recuerda');
ok(/claro/.test(q('btnTema').getAttribute('aria-label')), 'el boton ofrece volver al claro');
clic(q('btnTema'));
eq(root.getAttribute('data-theme'), 'light', 'y vuelve al claro');

console.log('\n\x1b[1m24. Lo guardado en el teléfono\x1b[0m');
const guardado = win.localStorage.getItem('apdash:datos:TEST123');
ok(!!guardado, 'tras cada respuesta buena se guarda lo último');
eq(JSON.parse(guardado).data.negocio, 'Abarrotes La Esquina', 'lo guardado es la respuesta');

// Abrir sin internet con lo de hoy guardado: se ve al instante y avisa
const E2 = crearEntorno({ red: false, ahora: null, almacen: { 'apdash:datos:TEST123': JSON.stringify({ t: Date.now() - 5 * 60000, data: datosBase({ negocio: 'Guardado' }) }) } });
await E2.tick();
eq(E2.q('negocio').textContent, 'Guardado', 'se muestra lo guardado aunque no haya red');
ok(!E2.q('banner').hidden && /Mostrando lo último guardado/.test(E2.q('banner').textContent), 'y avisa que es lo guardado',
   JSON.stringify(E2.q('banner').textContent));
ok(/hace \d+ min/.test(norm(E2.q('sync').textContent)), 'la antigüedad suma el tiempo que lleva guardado', JSON.stringify(E2.q('sync').textContent));

// Lo guardado de ayer NO se muestra: las cifras de "hoy" serían las de ayer
const ayerMs = Date.now() - 36 * 3600 * 1000;
const E3 = crearEntorno({ red: false, ahora: null, almacen: { 'apdash:datos:TEST123': JSON.stringify({ t: ayerMs, data: datosBase({ negocio: 'DeAyer' }) }) } });
await E3.tick();
ok(E3.q('negocio') === null, 'lo de ayer no se muestra como si fuera de hoy');
ok(/No se pudo conectar/.test(E3.doc.getElementById('app').textContent), 'se explica que no hay conexión');
E3.win.close(); E2.win.close();

console.log('\n\x1b[1m25. PIN: panel protegido\x1b[0m');
const EP = crearEntorno({ ahora: null, requiereToken: 'tok-1', body: datosBase({ protegido: true, negocio: 'Tienda Secreta' }) });
await EP.tick();
ok(EP.q('pinForm') !== null, 'sin token: el panel pide el PIN');
ok(EP.q('negocio') === null && !/Tienda Secreta|Abarrotes/.test(EP.doc.getElementById('app').textContent), 'y NO muestra ningún dato');
eq(EP.q('pinCampo').getAttribute('inputmode'), 'numeric', 'el campo abre el teclado numérico');
eq(EP.q('pinCampo').getAttribute('type'), 'password', 'y no enseña el PIN');
const sinDatosGuardados = EP.win.localStorage.getItem('apdash:datos:TEST123');
ok(!sinDatosGuardados, 'no se guardó nada del panel protegido');

await EP.enviarPin('123');
ok(/6 a 8 dígitos/.test(EP.q('pinMsg').textContent), 'un PIN corto se rechaza sin molestar al servidor');
ok(!EP.estado.llamadas.some(l => l.url.indexOf('/dashboard_auth.php') === 0), '…no hubo petición de entrada');

EP.estado.auth = { status: 401, body: { status: 'error', codigo: 'pin_incorrecto', restantes: 6 } };
await EP.enviarPin('111111');
eq(EP.q('pinMsg').textContent, 'PIN incorrecto. Quedan 6 intentos.', 'PIN incorrecto: dice cuántos intentos quedan');
eq(EP.q('pinCampo').value, '', 'y limpia el campo');
ok(!EP.q('pinBoton').disabled, 'se puede volver a intentar');
EP.estado.auth = { status: 401, body: { status: 'error', codigo: 'pin_incorrecto', restantes: 1 } };
await EP.enviarPin('111111');
eq(EP.q('pinMsg').textContent, 'PIN incorrecto. Quedan 1 intento.', 'singular: «1 intento»');

EP.estado.auth = { status: 429, body: { status: 'error', codigo: 'bloqueado', reintentar_en: 900 } };
await EP.enviarPin('222222');
ok(/Demasiados intentos\. Prueba otra vez en 15 minutos\./.test(EP.q('pinMsg').textContent), 'bloqueado: dice cuánto esperar', EP.q('pinMsg').textContent);
ok(EP.q('pinCampo').disabled && EP.q('pinBoton').disabled, '…y no deja seguir tecleando');

// PIN bueno
EP.estado.auth = { status: 200, body: { status: 'exitoso', protegido: true, token: 'tok-1' } };
EP.win.document.getElementById('app').innerHTML = '';
EP.G('showPin("")');
await EP.enviarPin('482915');
await EP.tick(); await EP.tick();
eq(EP.win.localStorage.getItem('apdash:token:TEST123'), 'tok-1', 'PIN correcto: guarda el token');
eq(EP.q('negocio') && EP.q('negocio').textContent, 'Tienda Secreta', 'y entra al panel');
const peticionDatos = EP.estado.llamadas.filter(l => l.url.indexOf('/get_dashboard_data.php') === 0).pop();
eq(peticionDatos.opts.headers['X-Dash-Token'], 'tok-1', 'las lecturas siguientes mandan X-Dash-Token');
const peticionAuth = EP.estado.llamadas.filter(l => l.url.indexOf('/dashboard_auth.php') === 0).pop();
ok(/id=TEST123/.test(peticionAuth.opts.body) && /pin=482915/.test(peticionAuth.opts.body), 'la entrada manda id y pin por POST (no por la URL)');
ok(peticionAuth.url.indexOf('482915') === -1, 'el PIN no viaja en la URL');
ok(!EP.q('salir').hidden, 'en un panel con PIN aparece «Cerrar sesión del panel»');
ok(!!EP.win.localStorage.getItem('apdash:datos:TEST123'), 'ya con sesión, sí se guarda lo último');

// token vencido
EP.estado.requiereToken = 'otro-token';
await EP.refrescar();
ok(EP.q('pinForm') !== null && /Tu sesión venció/.test(EP.q('pinMsg').textContent), 'token vencido o cambiado: vuelve a pedir el PIN y lo explica');
eq(EP.win.localStorage.getItem('apdash:token:TEST123'), null, '…y borra el token malo');
eq(EP.win.localStorage.getItem('apdash:datos:TEST123'), null, '…y lo guardado del panel protegido');

// cerrar sesión
EP.estado.requiereToken = 'tok-1';
EP.estado.auth = { status: 200, body: { status: 'exitoso', protegido: true, token: 'tok-1' } };
await EP.enviarPin('482915'); await EP.tick();
ok(EP.q('negocio') !== null, 'otra vez dentro');
EP.clic(EP.q('salir'));
ok(EP.q('pinForm') !== null && /Sesión cerrada/.test(EP.q('pinMsg').textContent), '«Cerrar sesión» vuelve a la pantalla de PIN');
eq(EP.win.localStorage.getItem('apdash:token:TEST123'), null, '…y borra el token');
eq(EP.win.localStorage.getItem('apdash:datos:TEST123'), null, '…y lo guardado');
EP.win.close();

// Un panel protegido guardado en el teléfono NO se abre sin la sesión
const datosProt = JSON.stringify({ t: Date.now() - 60000, data: datosBase({ protegido: true, negocio: 'Secreto' }) });
const E5 = crearEntorno({ red: false, ahora: null, almacen: { 'apdash:datos:TEST123': datosProt } });
await E5.tick();
ok(E5.q('negocio') === null || E5.q('negocio').textContent !== 'Secreto', 'protegido y sin token: lo guardado no se muestra');
const E6 = crearEntorno({ red: false, ahora: null, almacen: { 'apdash:datos:TEST123': datosProt, 'apdash:token:TEST123': 'tok-1' } });
await E6.tick();
eq(E6.q('negocio') && E6.q('negocio').textContent, 'Secreto', 'con la sesión iniciada sí');
E5.win.close(); E6.win.close();

// Sin PIN: ninguna cabecera de token
estado.llamadas.length = 0;
await refrescar();
const sinTk = estado.llamadas.filter(l => l.url.indexOf('/get_dashboard_data.php') === 0).pop();
ok(!sinTk.opts.headers || !sinTk.opts.headers['X-Dash-Token'], 'sin sesión no se manda X-Dash-Token');

console.log('\n\x1b[1m26. Fechas del negocio (hora de México)\x1b[0m');
// 03:30 UTC del 4 de septiembre son las 21:30 del 3 en México
const mx = E.real(new Date('2026-09-04T03:30:00Z'));
eq(mx.clave + ' ' + mx.h + ':' + mx.mi, '2026-09-03 21:30', 'el "hoy" es el de México aunque el teléfono esté en otra zona');
eq(G('sumarDias("2026-03-01", -1)'), '2026-02-28', 'sumarDias cruza de mes');
eq(G('sumarDias("2026-12-31", 1)'), '2027-01-01', 'sumarDias cruza de año');
eq(G('sumarDias("2024-03-01", -1)'), '2024-02-29', 'sumarDias respeta bisiestos');
eq(G('diasEnMes(2026, 2)'), 28, 'febrero 2026 tiene 28 días');
eq(G('diasEnMes(2024, 2)'), 29, 'febrero 2024 tiene 29 días');
const p1 = G('parseFechaPOS("09-10-2026 08:15:00")'), p2 = G('parseFechaPOS("2026-10-09 08:15:00")');
eq(JSON.stringify(p1), JSON.stringify(p2), 'dd-MM-yyyy y ISO dan la misma fecha');
eq(G('minutosDesde("03-09-2026 08:15:00", {y:2026,m:9,d:3,h:15,mi:20})'), 425, 'minutos desde la apertura (7 h 5 min)');
eq(G('minutosDesde("basura", {y:2026,m:9,d:3,h:15,mi:20})'), null, 'fecha ilegible: null');

console.log('\n\x1b[1m27. Rótulos compactos y cantidades\x1b[0m');
const c = v => G(`compacto(${v})`);
eq(c(99900),    '$999',   '$999');
eq(c(100000),   '$1.0k',  '$1,000 => $1.0k');
eq(c(999967),   '$10k',   '$9,999.67 no sale como $10.0k');
eq(c(1234500),  '$12k',   '$12,345 => $12k');
eq(c(120000000),'$1.2M',  '$1.2M');
eq(c(99960000), '$1.0M',  '$999,600 => $1.0M');
eq(c(-500),     '$0',     'un negativo no rompe');
eq(G('formatCantidad(2000, 0)'), '2 Pz', 'cantidad entera');
eq(G('formatCantidad(1500, 2)'), '1.5 L', 'decimales sin ceros de más');
eq(G('formatCantidad(250, 1)'), '0.25 Kg', 'menos de una unidad');
eq(G('formatCantidad(-3000, 1)'), '−3 Kg', 'negativo con signo menos');
eq(G('formatCantidad(1000, 9)'), '1 Pz', 'una unidad desconocida cae en pieza');
eq(G('plural(1, "devolución", "devoluciones")') + '|' + G('plural(2, "devolución", "devoluciones")'), 'devolución|devoluciones', 'plurales bien escritos');

console.log('\n\x1b[1m28. Sin dependencias externas y archivos del service worker\x1b[0m');
ok(!/jsdelivr|cdn\./.test(HTML + JS + SW), 'el panel ya no depende de ningún CDN (funciona sin conexión)');
ok(typeof win.Chart === 'undefined', 'no usa Chart.js');
const shell = [...SW.matchAll(/'(\/dashboard\/[^']+)'/g)].map(m => m[1]);
ok(shell.length >= 5, 'el service worker lista su shell', shell.join(', '));
shell.forEach(f => ok(fs.existsSync(PUB + f), 'existe ' + f));
ok(/cache:\s*'reload'/.test(SW), 'el SW precachea saltando la caché HTTP');
eq((SW.match(/CACHE_NAME = '([^']+)'/) || [])[1], 'antiparticula-dashboard-v11', 'versión del caché subida');
// El CDN de Hostinger guarda css/js 7 días: cada publicación los pide con ?v=<versión> (el número de CACHE_NAME)
{
    const ver = (SW.match(/CACHE_NAME = 'antiparticula-dashboard-v(\d+)'/) || [])[1];
    const pedidos = [...HTML.matchAll(/(?:href|src)="\/dashboard\/(dashboard\.css|dashboard\.js|tiendas\.js)(\?v=\d+)?"/g)];
    eq(pedidos.length, 3, 'index.html pide el css y los dos js');
    ok(pedidos.every(m => m[2] === '?v=' + ver), 'index.html los pide con ?v= igual a la versión del SW', pedidos.map(m => m[1] + (m[2] || '')).join(', '));
    ok(/'\/dashboard\/dashboard\.css' \+ V/.test(SW) && /const V = '\?v=' \+ CACHE_NAME/.test(SW), 'el SW guarda esas mismas URLs');
}
ok(!/\/img\/|\/css\//.test(HTML.replace(/<!--[\s\S]*?-->/g, '')) , 'el shell no depende de archivos fuera de /dashboard/');
ok(/dashboard_auth\.php|get_dashboard_data\.php/.test(SW), 'el SW conoce las rutas de la API (nunca se cachean)');

console.log('\n\x1b[1m29. Premium: el panel es solo de Premium (sin días gratis)\x1b[0m');
const EA = crearEntorno({ body: datosBase({ acceso: { estado: 'premium', hasta: '2027-09-03' } }) });
await EA.tick();
eq(EA.q('ingresosHoy').textContent, money('4,520.50'), 'con Premium se ve el panel');
ok(EA.q('avisoPremium') === null, 'ya no hay aviso de días gratis (no existen)');
EA.estado.body = datosBase({ acceso: { estado: 'prueba', hasta: '2026-09-18' } });
await EA.refrescar();
ok(EA.q('avisoPremium') === null && !/gratis hasta/.test(EA.doc.getElementById('app').textContent),
   'aunque un servidor viejo dijera «prueba», no se anuncia nada');

// Sin Premium vigente: 403 premium_requerido
EA.estado.body = datosBase();
await EA.refrescar();
ok(!!EA.win.localStorage.getItem('apdash:datos:TEST123'), '(antes había datos guardados en el teléfono)');
EA.estado.status = 403;
EA.estado.body = { status: 'premium_requerido', negocio: 'Abarrotes La Esquina', desde: '2026-09-01', protegido: false };
await EA.refrescar();
ok(EA.q('premiumBox') !== null, '403 premium_requerido: pantalla de Premium');
ok(EA.q('ingresosHoy') === null && EA.q('gananciaHoy') === null, '…sin ni una cifra');
ok(/^Tu suscripción terminó el 1 Sep 2026\. Con Premium ves/.test(EA.q('premiumTexto').textContent), 'dice cuándo venció la suscripción',
   EA.q('premiumTexto').textContent);
eq(EA.doc.querySelector('h1').textContent, 'Abarrotes La Esquina', 'con el nombre del negocio');
// Se compra aquí mismo (POS 3.5.0+): tres planes y un solo botón para pagar
eq(EA.doc.querySelectorAll('[data-k="planes"] .plan').length, 3, 'los tres planes de Premium');
eq(EA.doc.querySelector('.plan.sel').getAttribute('data-plan'), 'premium_anual', 'el anual, elegido de entrada');
eq(EA.q('premiumPagar').getAttribute('href'), '/tienda/pagar.php?plan=premium_anual&panel=TEST123', 'Pagar lleva el plan y el ID del panel');
eq(EA.q('premiumPagar').textContent, 'Pagar $1,290', '…y dice cuánto');
ok(/ahorras \$498/.test(EA.q('planes').textContent), 'el anual dice cuánto ahorra');
EA.doc.querySelector('.plan[data-plan="premium_mensual"]').click();
eq(EA.q('premiumPagar').getAttribute('href'), '/tienda/pagar.php?plan=premium_mensual&panel=TEST123', 'elegir el mensual cambia el pago');
eq(EA.q('premiumPagar').textContent, 'Pagar $149', '…y el precio');
eq(EA.doc.querySelector('.plan.sel').getAttribute('data-plan'), 'premium_mensual', '…y la tarjeta elegida');
ok(EA.q('premiumDudas').getAttribute('href').indexOf('https://wa.me/525584323945?text=') === 0, 'las dudas, por WhatsApp');
ok(/te damos un código/.test(EA.q('premiumComo').textContent) && /Actualiza el programa de tu tienda/.test(EA.q('premiumBox').textContent),
   'con un programa anterior a la 3.5.0: el pago da un código, y le dice que actualice');
eq(EA.win.localStorage.getItem('apdash:datos:TEST123'), null, 'lo guardado en el teléfono se borra (no se sigue mostrando)');
const cajaAntes = EA.q('premiumBox');
await EA.refrescar();
ok(EA.q('premiumBox') === cajaAntes, 'el refresco de cada minuto no vuelve a montar la pantalla');
// Con el programa 3.5.0 la clave llega sola; los precios vigentes los da el sitio
EA.estado.planes = { planes: [
    { id: 'premium_mensual', tipo: 'premium', precio: 16900, periodo: 'al mes' },
    { id: 'premium_anual', tipo: 'premium', precio: 139000, periodo: 'al año' },
    { id: 'premium_siempre', tipo: 'premium', precio: 349000, periodo: 'pago único' },
    { id: 'multi_anual', tipo: 'multi', precio: 299000, periodo: 'al año' }] };
EA.estado.body = { status: 'premium_requerido', negocio: 'Abarrotes La Esquina', desde: '2026-09-01', protegido: false, compra_directa: true };
await EA.refrescar(); await EA.tick();
ok(/se activa solo en el programa de tu tienda/.test(EA.q('premiumComo').textContent), 'con el programa 3.5.0: se activa solo');
eq(EA.q('planes').textContent.indexOf('$169') !== -1 && EA.q('planes').textContent.indexOf('$1,390') !== -1, true,
   'los precios del sitio reemplazan a los incluidos');
ok(/ahorras \$638/.test(EA.q('planes').textContent), '…y el ahorro se calcula con ellos');
EA.estado.planes = null;
// Al pagar, el panel vuelve solo
EA.estado.status = 200;
EA.estado.body = datosBase({ acceso: { estado: 'premium', hasta: '2027-09-03' } });
await EA.refrescar();
ok(EA.q('premiumBox') === null && EA.q('ingresosHoy') !== null, 'con Premium el panel vuelve en el siguiente refresco');
eq(EA.q('ingresosHoy').textContent, money('4,520.50'), '…con sus cifras');
// Sin fecha de término no se escribe «undefined»
EA.estado.status = 403;
EA.estado.body = { status: 'premium_requerido', negocio: 'X', desde: null };
await EA.refrescar();
ok(!/undefined|null/.test(EA.q('premiumTexto').textContent), 'sin fecha no sale «undefined»', EA.q('premiumTexto').textContent);
ok(/^Con Premium ves desde el celular/.test(EA.q('premiumTexto').textContent), '…ni una fecha: nunca tuvo Premium', EA.q('premiumTexto').textContent);
ok(!/gratis/.test(EA.q('premiumBox').textContent), 'la pantalla no habla de días gratis');
// Lo guardado no se muestra al abrir si el panel ya no tiene acceso
const EB = crearEntorno({ red: true, ahora: null, status: 403, body: { status: 'premium_requerido', negocio: 'Guardado', desde: '2026-09-01' },
    almacen: { 'apdash:datos:TEST123': JSON.stringify({ t: Date.now() - 60000, data: datosBase({ negocio: 'Guardado' }) }) } });
await EB.tick(); await EB.tick();
ok(EB.q('premiumBox') !== null && EB.q('ingresosHoy') === null, 'al abrir, lo guardado se reemplaza por la pantalla de Premium');
EA.win.close(); EB.win.close();

console.log('\n\x1b[1m30. Sin errores JS capturados por window.onerror\x1b[0m');
ok(doc.getElementById('app').innerHTML.indexOf('ERROR JS') === -1, 'no se disparo el handler de error');

console.log(`\n\x1b[1mResultado: ${pass} pasadas, ${fail} fallidas\x1b[0m\n`);
win.close();
process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('\x1b[31mERROR EN LA PRUEBA:\x1b[0m', e); process.exit(2); });
