// ────────────────────────────────────────────────────────────────
// Antiparticula Dashboard — PWA Frontend
//
// El refresco de 60s NO reconstruye el DOM. `montar()` arma la
// estructura una sola vez y `pintar()` actualiza textos y datasets en
// su lugar, de modo que no se pierde el scroll ni parpadean las
// gráficas cada minuto.
// ────────────────────────────────────────────────────────────────

const API_BASE   = '/get_dashboard_data.php';
const REFRESH_MS = 60 * 1000;
const STALE_MIN  = 30;          // minutos sin sync del POS => badge rojo
const OFFLINE_MS = 3 * 60 * 1000; // sin respuesta del servidor => banner

const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

let charts       = {};
let refreshTimer = null;
let bannerTimer  = null;
let ultimoOk     = null;   // Date.now() del último fetch exitoso
let estructura   = null;   // firma del DOM montado

// ── Extraer hardware_id de la URL ───────────────────────────────
function getHardwareId() {
    const parts = window.location.pathname.split('/');
    for (let i = 0; i < parts.length; i++) {
        if (parts[i] === 'dashboard' && parts[i + 1]) return parts[i + 1];
    }
    return null;
}

// ── Formato de pesos ────────────────────────────────────────────
// MEDIO-05 fix: maneja NaN, null y valores negativos
function formatMXN(centavos) {
    const num = Number(centavos);
    const val = isNaN(num) ? 0 : num / 100;
    const abs = Math.abs(val);
    const formatted = '$ ' + abs.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return val < 0 ? '-' + formatted : formatted;
}

// ── Tiempo relativo ─────────────────────────────────────────────
// ALTO-05 fix: minutos siempre >= 0
function tiempoRelativo(minutos) {
    const m = Math.max(0, minutos);
    if (m < 1)  return 'ahora mismo';
    if (m < 60) return `hace ${m} min`;
    const h = Math.floor(m / 60);
    const r = m % 60;
    return r > 0 ? `hace ${h}h ${r}min` : `hace ${h}h`;
}

// ── Fecha/hora legible ──────────────────────────────────────────
// BAJO-03 fix: el POS manda turno_inicio en formatos variados (ISO,
// "YYYY-MM-DD HH:MM:SS", solo hora). Antes se mostraba el string crudo.
function formatFechaHora(raw) {
    if (!raw) return '—';
    const s = String(raw).trim();

    const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{1,2}):(\d{2})/);
    if (iso) {
        const d = new Date(+iso[1], +iso[2] - 1, +iso[3], +iso[4], +iso[5]);
        if (!isNaN(d.getTime())) {
            const hoy = new Date();
            const mismoDia = d.getDate()  === hoy.getDate()
                          && d.getMonth() === hoy.getMonth()
                          && d.getFullYear() === hoy.getFullYear();
            const hora = d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
            return mismoDia ? hora : `${d.getDate()} ${MESES[d.getMonth()]}, ${hora}`;
        }
    }

    const soloHora = s.match(/^(\d{1,2}):(\d{2})/);
    if (soloHora) {
        const hh = +soloHora[1], mm = +soloHora[2];
        if (hh >= 0 && hh <= 23 && mm >= 0 && mm <= 59) {
            const d = new Date();
            d.setHours(hh, mm, 0, 0);
            return d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
        }
    }
    return s;   // formato desconocido: mostrarlo tal cual
}

// ── Seguridad ───────────────────────────────────────────────────
// ALTO-06 fix: agregar escape de comilla simple para atributos con '...'
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#x27;');
}

// ────────────────────────────────────────────────────────────────
// Derivación: todos los valores calculados en un solo lugar, para que
// montar() y pintar() no dupliquen lógica y no se desincronicen.
// ────────────────────────────────────────────────────────────────
function derivar(data) {
    const minutos = Math.max(0, data.hace_minutos || 0);

    const ventasHoy     = Number(data.ventas_hoy)   || 0;
    const gananciaHoy   = Number(data.ganancia_hoy) || 0;
    const ticketsHoy    = Number(data.tickets_hoy)  || 0;
    const abonosHoy     = Number(data.abonos_hoy)   || 0;
    const abonosTurno   = Number(data.abonos_turno) || 0;
    const ventasTurno   = Number(data.ventas_turno) || 0;
    const ticketsTurno  = Number(data.tickets_turno) || 0;

    const margenPct = ventasHoy > 0 ? (gananciaHoy / ventasHoy) * 100 : 0;
    const anual     = datosAnuales(data.historico_anual);
    const margenAnualPct = anual.totalVentas > 0
        ? (anual.totalGanancia / anual.totalVentas) * 100
        : 0;

    return {
        negocio:      data.negocio || 'Antiparticula POS',
        minutos,
        stale:        minutos > STALE_MIN,

        ingresosHoy:  ventasHoy + abonosHoy,
        ventasHoy, gananciaHoy, ticketsHoy, abonosHoy,
        ticketProm:   ticketsHoy > 0 ? Math.round(ventasHoy / ticketsHoy) : 0,
        margenPct,
        margenTxt:    margenPct.toFixed(1) + '%',
        margenGood:   margenPct >= 20,
        costoHoy:     Math.max(0, ventasHoy - gananciaHoy),
        costoPct:     (100 - margenPct).toFixed(1),

        turnoUsuario: data.turno_usuario || '—',
        turnoInicio:  formatFechaHora(data.turno_inicio),
        turnoAbierto: !!Number(data.turno_abierto),
        ingresosTurno: ventasTurno + abonosTurno,
        ticketsTurno, abonosTurno,

        horas:        datosHoras(data),
        semana:       datosSemana(data.historico_semana),
        ventas:       Array.isArray(data.ultimas_ventas) ? data.ultimas_ventas : [],

        anual,
        hayAnual:      anual.totalVentas > 0,
        margenAnualTxt: margenAnualPct.toFixed(1) + '%',
        margenAnualGood: margenAnualPct >= 20,
    };
}

// ── Ventas por hora ─────────────────────────────────────────────
// Prefiere `ventas_por_hora`, que el servidor agrega desde el detalle
// acumulado de todo el día (ver sql/001_ventas_por_hora.sql).
// `ultimas_ventas` solo trae las últimas N ventas que mandó el POS, así
// que usarla como fuente dejaba las horas tempranas en blanco.
function datosHoras(data) {
    const buckets = {};
    const vph = Array.isArray(data.ventas_por_hora) ? data.ventas_por_hora : [];

    if (vph.length > 0) {
        vph.forEach(h => {
            const hh = Number(h.hora);
            if (Number.isInteger(hh) && hh >= 0 && hh <= 23) {
                buckets[hh] = (Number(h.total_centavos) || 0) / 100;
            }
        });
    } else {
        // Respaldo mientras la migración 001 no esté aplicada.
        (Array.isArray(data.ultimas_ventas) ? data.ultimas_ventas : []).forEach(v => {
            const hh = parseInt(String(v.hora || '').split(':')[0], 10);
            if (isNaN(hh) || hh < 0 || hh > 23) return;
            buckets[hh] = (buckets[hh] || 0) + (Number(v.total_centavos) || 0) / 100;
        });
    }

    const conVenta = Object.keys(buckets).map(Number).sort((a, b) => a - b);
    if (conVenta.length === 0) return { labels: [], valores: [] };

    // Rellenar el rango completo: si hubo ventas a las 9 y a las 14, las
    // horas intermedias sin venta deben salir en cero y no desaparecer
    // del eje, que hacía ver actividad continua donde no la hubo.
    const labels = [], valores = [];
    for (let h = conVenta[0]; h <= conVenta[conVenta.length - 1]; h++) {
        labels.push(String(h).padStart(2, '0') + ':00');
        valores.push(buckets[h] || 0);
    }
    return { labels, valores };
}

// ── Últimos 7 días ──────────────────────────────────────────────
// MEDIO-09 fix: siempre generar los 7 dias, rellenar con 0 los dias sin datos
function datosSemana(historico) {
    const mapa = {};
    (Array.isArray(historico) ? historico : []).forEach(d => {
        if (d.fecha) mapa[d.fecha] = (Number(d.total_centavos) || 0) / 100;
    });
    const labels = [], valores = [];
    for (let i = 6; i >= 0; i--) {
        const d = new Date();
        d.setDate(d.getDate() - i);
        const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        labels.push(new Date(key + 'T12:00:00').toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric' }));
        valores.push(mapa[key] !== undefined ? mapa[key] : 0);
    }
    return { labels, valores };
}

// ── Datos anuales ───────────────────────────────────────────────
function datosAnuales(historico) {
    const year = new Date().getFullYear();
    const mapa = {};
    (Array.isArray(historico) ? historico : []).forEach(d => {
        if (d.mes) mapa[d.mes] = { v: Number(d.ventas_centavos) || 0, g: Number(d.ganancia_centavos) || 0 };
    });
    let totalVentas = 0, totalGanancia = 0;
    const ventas = [], ganancias = [], meses = [];
    for (let m = 1; m <= 12; m++) {
        const key = `${year}-${String(m).padStart(2, '0')}`;
        const v = mapa[key] ? mapa[key].v : 0;
        const g = mapa[key] ? mapa[key].g : 0;
        ventas.push(v / 100);
        ganancias.push(g / 100);
        meses.push({ nombre: MESES[m - 1], v, g });
        totalVentas   += v;
        totalGanancia += g;
    }
    return { year, ventas, ganancias, meses, totalVentas, totalGanancia };
}

// ────────────────────────────────────────────────────────────────
// Montaje del DOM (una sola vez por firma de estructura)
// ────────────────────────────────────────────────────────────────
function montar(d) {
    document.getElementById('app').innerHTML = `
    <div class="header">
        <div class="header-title">
            <h1 data-k="negocio"></h1>
            <span>Panel de ventas</span>
        </div>
        <div class="header-logo">A</div>
    </div>

    <div class="conn-banner" data-k="banner" hidden></div>

    <div class="sync-badge" data-k="syncBadge">
        <span class="sync-dot"></span>
        <span data-k="sync"></span>
    </div>

    <!-- KPI principales -->
    <div class="cards-grid">
        <div class="card card-green">
            <div class="card-label">Ingresos hoy</div>
            <div class="card-value" data-k="ingresosHoy"></div>
            <div class="card-sub" data-k="ingresosSub"></div>
        </div>
        <div class="card card-blue">
            <div class="card-label">Ganancia hoy</div>
            <div class="card-value" data-k="gananciaHoy"></div>
            <div class="card-sub">&nbsp;</div>
        </div>
    </div>

    <!-- KPI secundarios -->
    <div class="cards-grid cards-grid-3">
        <div class="card">
            <div class="card-label">Tickets</div>
            <div class="card-value card-value-sm" data-k="ticketsHoy"></div>
            <div class="card-sub">hoy</div>
        </div>
        <div class="card">
            <div class="card-label">Ticket prom.</div>
            <div class="card-value card-value-sm" data-k="ticketProm"></div>
            <div class="card-sub">&nbsp;</div>
        </div>
        <div class="card" data-k="cardMargen">
            <div class="card-label">Margen</div>
            <div class="card-value card-value-sm" data-k="margen"></div>
            <div class="card-sub">ganancia</div>
        </div>
    </div>

    <!-- Gráfica por hora -->
    <div class="card section-card">
        <div class="section-title">Ventas por hora — hoy</div>
        <div class="chart-wrap" data-wrap="horas">
            <canvas id="chartHoras"></canvas>
            <p class="chart-empty" hidden>Sin ventas registradas hoy</p>
        </div>
    </div>

    <!-- Turno actual -->
    <div class="card section-card">
        <div class="card-label" style="margin-bottom:12px">Turno actual</div>
        <div class="turno-card">
            <div class="turno-row">
                <span>Cajero</span>
                <span data-k="turnoUsuario"></span>
            </div>
            <div class="turno-row">
                <span>Apertura</span>
                <span data-k="turnoInicio"></span>
            </div>
            <div class="turno-row">
                <span>Ingresos del turno</span>
                <span data-k="ingresosTurno"></span>
            </div>
            <div class="turno-row">
                <span>Estado</span>
                <span data-k="turnoEstado"></span>
            </div>
        </div>
    </div>

    <!-- Gráfica 7 días -->
    <div class="card section-card">
        <div class="section-title">Ventas últimos 7 días</div>
        <div class="chart-wrap">
            <canvas id="chartSemana"></canvas>
        </div>
    </div>

    <!-- Donut: composición de venta -->
    <div class="card section-card">
        <div class="section-title">Composición de venta hoy</div>
        <div class="chart-donut-wrap" data-wrap="donut">
            <canvas id="chartDonut"></canvas>
            <div class="donut-legend">
                <div class="donut-item">
                    <span class="donut-dot dot-ganancia"></span>
                    <div>
                        <div class="donut-label">Ganancia</div>
                        <div class="donut-val" data-k="donutGanancia"></div>
                    </div>
                </div>
                <div class="donut-item">
                    <span class="donut-dot dot-costo"></span>
                    <div>
                        <div class="donut-label">Costo</div>
                        <div class="donut-val" data-k="donutCosto"></div>
                    </div>
                </div>
            </div>
            <p class="chart-empty" hidden>Sin ventas hoy</p>
        </div>
    </div>

    <!-- Últimas ventas -->
    <div class="card section-card">
        <div class="section-title">Últimas ventas</div>
        <div data-k="ultimasVentas"></div>
    </div>

    <!-- Ventas y ganancias por mes del año -->
    <div class="card section-card">
        <div class="section-title">Ventas y ganancias &mdash; ${d.anual.year}</div>
        ${d.hayAnual ? `
        <div class="chart-wrap chart-wrap-anual"><canvas id="chartAnual"></canvas></div>
        <div class="anual-legend">
            <div class="anual-legend-item"><span class="anual-dot anual-dot-ventas"></span>Ventas</div>
            <div class="anual-legend-item"><span class="anual-dot anual-dot-ganancia"></span>Ganancia</div>
        </div>`
        : '<p class="chart-empty">Sin datos anuales aún. Actualiza el POS a la última versión.</p>'}
    </div>

    ${d.hayAnual ? `
    <div class="card section-card">
        <div class="section-title">Detalle por mes</div>
        <div class="anual-header-row">
            <span>Mes</span><span>Ventas</span><span>Ganancia</span><span>Mrg</span>
        </div>
        <div data-k="tablaAnual"></div>
    </div>
    <div class="cards-grid">
        <div class="card card-blue">
            <div class="card-label">Total ventas ${d.anual.year}</div>
            <div class="card-value" data-k="totalVentasAnual"></div>
            <div class="card-sub">12 meses</div>
        </div>
        <div class="card card-green">
            <div class="card-label">Total ganancia ${d.anual.year}</div>
            <div class="card-value" data-k="totalGananciaAnual"></div>
            <div class="card-sub">&nbsp;</div>
        </div>
    </div>
    <div class="cards-grid">
        <div class="card card-full" data-k="cardMargenAnual">
            <div class="card-label">Margen anual ${d.anual.year}</div>
            <div class="card-value card-value-sm" data-k="margenAnual"></div>
            <div class="card-sub">ganancia sobre ventas totales del año</div>
        </div>
    </div>` : ''}
    `;
    destruirCharts();   // los canvas anteriores ya no existen
}

// Al reemplazar el innerHTML los canvas viejos quedan huerfanos pero las
// instancias de Chart.js siguen referenciandolos; hay que destruirlas.
function destruirCharts() {
    Object.keys(charts).forEach(k => {
        try { charts[k].destroy(); } catch (e) { /* ya desmontado */ }
    });
    charts = {};
}

// ────────────────────────────────────────────────────────────────
// Pintado incremental
// ────────────────────────────────────────────────────────────────
function el(key) { return document.querySelector(`[data-k="${key}"]`); }

function setText(key, value) {
    const n = el(key);
    if (n && n.textContent !== value) n.textContent = value;
}

function setHtml(key, value) {
    const n = el(key);
    if (n && n.innerHTML !== value) n.innerHTML = value;
}

function setClass(key, cls, on) {
    const n = el(key);
    if (n) n.classList.toggle(cls, on);
}

function pintar(d) {
    setText('negocio', d.negocio);

    setText('sync', `Última sync: ${tiempoRelativo(d.minutos)}`);
    setClass('syncBadge', 'stale', d.stale);

    setText('ingresosHoy', formatMXN(d.ingresosHoy));
    setText('ingresosSub', d.abonosHoy > 0
        ? `${formatMXN(d.ventasHoy)} ventas + ${formatMXN(d.abonosHoy)} apartados`
        : `${d.ticketsHoy} ticket${d.ticketsHoy !== 1 ? 's' : ''}`);
    setText('gananciaHoy', formatMXN(d.gananciaHoy));
    setText('ticketsHoy',  String(d.ticketsHoy));
    setText('ticketProm',  formatMXN(d.ticketProm));

    setText('margen', d.margenTxt);
    setClass('cardMargen', 'card-margin-good', d.margenGood);
    setClass('cardMargen', 'card-margin-warn', !d.margenGood);

    setText('turnoUsuario', d.turnoUsuario);
    setText('turnoInicio',  d.turnoInicio);
    setHtml('ingresosTurno', formatMXN(d.ingresosTurno) + (d.abonosTurno > 0
        ? ` <span class="turno-nota">(${d.ticketsTurno} tickets + ${escHtml(formatMXN(d.abonosTurno))} aptos)</span>`
        : ` (${d.ticketsTurno} tickets)`));
    setHtml('turnoEstado', d.turnoAbierto
        ? '<span class="badge-open">Abierto</span>'
        : '<span class="badge-closed">Cerrado</span>');

    setText('donutGanancia', `${d.margenTxt} · ${formatMXN(d.gananciaHoy)}`);
    setText('donutCosto',    `${d.costoPct}% · ${formatMXN(d.costoHoy)}`);

    setHtml('ultimasVentas', htmlVentas(d.ventas));

    if (d.hayAnual) {
        setHtml('tablaAnual', htmlTablaAnual(d.anual));
        setText('totalVentasAnual',   formatMXN(d.anual.totalVentas));
        setText('totalGananciaAnual', formatMXN(d.anual.totalGanancia));
        setText('margenAnual',        d.margenAnualTxt);
        setClass('cardMargenAnual', 'card-margin-good',  d.margenAnualGood);
        setClass('cardMargenAnual', 'card-margin-warn', !d.margenAnualGood);
    }

    graficaHoras(d);
    graficaSemana(d);
    graficaDonut(d);
    if (d.hayAnual) graficaAnual(d);
}

// ── Últimas ventas ──────────────────────────────────────────────
function htmlVentas(ventas) {
    if (!ventas || ventas.length === 0) {
        return '<p class="chart-empty">Sin ventas registradas</p>';
    }
    const items = ventas.slice(0, 10).map(v => `
        <li class="venta-item">
            <div class="venta-left">
                <span class="venta-hora">${escHtml(v.hora || '')}</span>
                <span class="venta-cajero">${escHtml(v.cajero || '')}</span>
            </div>
            <span class="venta-total">${formatMXN(v.total_centavos || 0)}</span>
        </li>
    `).join('');
    return `<ul class="ventas-list">${items}</ul>`;
}

// ── Tabla mensual ───────────────────────────────────────────────
function htmlTablaAnual(anual) {
    const rows = anual.meses.map(m => {
        const mrg = m.v > 0 ? ((m.g / m.v) * 100).toFixed(1) + '%' : '—';
        return `<div class="anual-row${m.v === 0 ? ' anual-row-empty' : ''}">
            <span class="anual-mes">${m.nombre}</span>
            <span class="anual-ventas">${m.v ? formatMXN(m.v) : '—'}</span>
            <span class="anual-ganancia">${m.g ? formatMXN(m.g) : '—'}</span>
            <span class="anual-mrg">${mrg}</span>
        </div>`;
    }).join('');

    const tMrgNum = anual.totalVentas > 0 ? (anual.totalGanancia / anual.totalVentas) * 100 : 0;
    return rows + `
    <div class="anual-row anual-totales">
        <span class="anual-mes">Total</span>
        <span class="anual-ventas">${formatMXN(anual.totalVentas)}</span>
        <span class="anual-ganancia">${formatMXN(anual.totalGanancia)}</span>
        <span class="anual-mrg ${tMrgNum >= 20 ? 'anual-mrg-good' : 'anual-mrg-warn'}">${tMrgNum.toFixed(1)}%</span>
    </div>`;
}

// ────────────────────────────────────────────────────────────────
// Gráficas — crean el Chart la primera vez y luego solo actualizan
// ────────────────────────────────────────────────────────────────

// ALTO-04 fix: reintento con limite de intentos en vez de setTimeout unico
function waitForChart(callback, retries) {
    const intentos = (retries === undefined) ? 20 : retries;
    if (typeof Chart !== 'undefined') { callback(); return; }
    if (intentos <= 0) { console.warn('Chart.js no cargo despues de multiples intentos'); return; }
    setTimeout(() => waitForChart(callback, intentos - 1), 300);
}

// Alterna canvas / mensaje vacío sin destruir el canvas: antes se
// reemplazaba el innerHTML del wrap, así que una vez vacío la gráfica
// ya no podía volver a dibujarse sin recargar la página.
function toggleVacio(wrap, vacio) {
    const w = document.querySelector(`[data-wrap="${wrap}"]`);
    if (!w) return;
    const canvas = w.querySelector('canvas');
    const msg    = w.querySelector('.chart-empty');
    const legend = w.querySelector('.donut-legend');
    if (canvas) canvas.hidden = vacio;
    if (msg)    msg.hidden    = !vacio;
    if (legend) legend.hidden = vacio;
}

function barOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 300 },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#202936',
                borderColor: '#2c3e55',
                borderWidth: 1,
                titleColor: '#7ab8d9',
                bodyColor: '#e2e8f0',
                callbacks: {
                    label: ctx => '$ ' + ctx.parsed.y.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                }
            }
        },
        scales: {
            x: {
                ticks: { color: '#4a6278', font: { size: 10 } },
                grid:  { color: 'rgba(44,110,149,0.08)' }
            },
            y: {
                ticks: {
                    color: '#4a6278',
                    font: { size: 10 },
                    callback: v => '$' + (v >= 1000 ? (v / 1000).toFixed(1) + 'k' : v)
                },
                grid: { color: 'rgba(44,110,149,0.08)' }
            }
        }
    };
}

function graficaHoras(d) {
    const canvas = document.getElementById('chartHoras');
    if (!canvas) return;
    if (typeof Chart === 'undefined') { waitForChart(() => graficaHoras(d)); return; }

    const vacio = d.horas.labels.length === 0;
    toggleVacio('horas', vacio);
    if (vacio) return;

    if (charts.horas) {
        charts.horas.data.labels = d.horas.labels;
        charts.horas.data.datasets[0].data = d.horas.valores;
        charts.horas.update();
        return;
    }
    charts.horas = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: d.horas.labels,
            datasets: [{
                data: d.horas.valores,
                backgroundColor: 'rgba(44,110,149,0.3)',
                borderColor: '#2c6e95',
                borderWidth: 1.5,
                borderRadius: 5
            }]
        },
        options: barOptions()
    });
}

function graficaSemana(d) {
    const canvas = document.getElementById('chartSemana');
    if (!canvas) return;
    if (typeof Chart === 'undefined') { waitForChart(() => graficaSemana(d)); return; }

    if (charts.semana) {
        charts.semana.data.labels = d.semana.labels;
        charts.semana.data.datasets[0].data = d.semana.valores;
        charts.semana.update();
        return;
    }
    charts.semana = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: d.semana.labels,
            datasets: [{
                data: d.semana.valores,
                backgroundColor: 'rgba(44,110,149,0.25)',
                borderColor: '#2c6e95',
                borderWidth: 1.5,
                borderRadius: 6
            }]
        },
        options: barOptions()
    });
}

function graficaDonut(d) {
    const canvas = document.getElementById('chartDonut');
    if (!canvas) return;
    if (typeof Chart === 'undefined') { waitForChart(() => graficaDonut(d)); return; }

    const vacio = d.ventasHoy <= 0;
    toggleVacio('donut', vacio);
    if (vacio) return;

    // BAJO-02 fix: clamp a 0 para evitar segmentos negativos si ganancia > ventas
    const valores = [Math.max(0, d.gananciaHoy / 100), Math.max(0, d.costoHoy / 100)];

    if (charts.donut) {
        charts.donut.data.datasets[0].data = valores;
        charts.donut.update();
        return;
    }
    charts.donut = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Ganancia', 'Costo'],
            datasets: [{
                data: valores,
                backgroundColor: ['rgba(52,211,153,0.75)', 'rgba(44,110,149,0.45)'],
                borderColor:     ['#34d399', '#2c6e95'],
                borderWidth: 1.5,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 300 },
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#202936',
                    borderColor: '#2c3e55',
                    borderWidth: 1,
                    titleColor: '#7ab8d9',
                    bodyColor: '#e2e8f0',
                    callbacks: {
                        // MEDIO-07 fix: agregar maximumFractionDigits para consistencia
                        label: ctx => ctx.label + ': $ ' + ctx.parsed.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    }
                }
            }
        }
    });
}

function graficaAnual(d) {
    const canvas = document.getElementById('chartAnual');
    if (!canvas) return;
    if (typeof Chart === 'undefined') { waitForChart(() => graficaAnual(d)); return; }

    if (charts.anual) {
        charts.anual.data.datasets[0].data = d.anual.ventas;
        charts.anual.data.datasets[1].data = d.anual.ganancias;
        charts.anual.update();
        return;
    }
    charts.anual = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: MESES,
            datasets: [
                {
                    label: 'Ventas',
                    data: d.anual.ventas,
                    borderColor: '#2c6e95',
                    backgroundColor: 'rgba(44,110,149,0.15)',
                    borderWidth: 2, fill: true, tension: 0.35,
                    pointRadius: 3, pointHoverRadius: 5,
                    pointBackgroundColor: '#2c6e95'
                },
                {
                    label: 'Ganancia',
                    data: d.anual.ganancias,
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52,211,153,0.10)',
                    borderWidth: 2, fill: true, tension: 0.35,
                    pointRadius: 3, pointHoverRadius: 5,
                    pointBackgroundColor: '#34d399'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 300 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#202936',
                    borderColor: '#2c3e55',
                    borderWidth: 1,
                    titleColor: '#7ab8d9',
                    bodyColor: '#e2e8f0',
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': $ ' + ctx.parsed.y.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#4a6278', font: { size: 10 } },
                    grid:  { color: 'rgba(44,110,149,0.08)' }
                },
                y: {
                    ticks: {
                        color: '#4a6278',
                        font: { size: 10 },
                        callback: v => '$' + (v >= 1000 ? (v / 1000).toFixed(1) + 'k' : v)
                    },
                    grid: { color: 'rgba(44,110,149,0.08)' }
                }
            }
        }
    });
}

// ────────────────────────────────────────────────────────────────
// Estados de conexión
// ────────────────────────────────────────────────────────────────
function showLoading() {
    document.getElementById('app').innerHTML = `
    <div class="loading-spinner">
        <div class="spinner"></div>
        <span>Cargando datos...</span>
    </div>`;
    estructura = null;
    destruirCharts();
}

function showError(msg) {
    document.getElementById('app').innerHTML = `
    <div style="padding:24px">
        <div class="error-box">
            <strong>Sin conexión</strong>
            <p>${escHtml(msg)}</p>
        </div>
    </div>`;
    estructura = null;
    destruirCharts();
}

// Los refrescos de fondo fallaban en silencio (solo console.error), así
// que se seguían viendo cifras viejas como si fueran de ahora.
function actualizarBanner() {
    const n = el('banner');
    if (!n) return;
    if (ultimoOk === null) { n.hidden = true; return; }

    const transcurrido = Date.now() - ultimoOk;
    if (transcurrido < OFFLINE_MS) { n.hidden = true; return; }

    const min = Math.floor(transcurrido / 60000);
    n.textContent = navigator.onLine
        ? `Sin respuesta del servidor — datos de ${tiempoRelativo(min)}`
        : `Sin conexión — datos de ${tiempoRelativo(min)}`;
    n.hidden = false;
}

// ────────────────────────────────────────────────────────────────
// Render
// ────────────────────────────────────────────────────────────────
function render(data) {
    const d = derivar(data);
    // Solo el bloque anual cambia la forma del DOM; lo demás se pinta
    // sobre la estructura ya montada.
    const firma = `${d.anual.year}|${d.hayAnual}`;
    if (estructura !== firma) {
        montar(d);
        estructura = firma;
    }
    pintar(d);
    actualizarBanner();
}

async function fetchData(hwId, isFirstLoad) {
    if (isFirstLoad) showLoading();
    try {
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 12000);
        const res = await fetch(`${API_BASE}?id=${encodeURIComponent(hwId)}&_t=${Date.now()}`, { signal: controller.signal });
        clearTimeout(timeoutId);
        if (res.status === 404) {
            showError('Este POS aún no ha sincronizado datos. Realiza una venta primero.');
            return;
        }
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (data.status !== 'exitoso') throw new Error(data.message || 'Error desconocido');
        ultimoOk = Date.now();
        render(data);
    } catch (err) {
        console.error('Dashboard fetch error:', err);
        if (isFirstLoad || ultimoOk === null) {
            showError('No se pudo conectar al servidor. ' + err.message);
        } else {
            actualizarBanner();   // se conserva la vista con aviso de datos viejos
        }
    }
}

// ── Manifest dinámico ───────────────────────────────────────────
// MEDIO-02 fix: el manifest estático tenía start_url "./", así que la
// PWA instalada abría /dashboard/ sin hardware_id y mostraba el error de
// URL inválida. Se genera en runtime con el id de esta instalación.
function instalarManifest(hwId) {
    try {
        const base = `/dashboard/${encodeURIComponent(hwId)}`;
        const manifest = {
            name: 'Antiparticula Dashboard',
            short_name: 'Dashboard',
            description: 'Panel de ventas en tiempo real para Antiparticula POS',
            start_url: base,
            scope: base,
            display: 'standalone',
            orientation: 'portrait',
            background_color: '#0f1419',
            theme_color: '#202936',
            icons: [
                { src: '/dashboard/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
                { src: '/dashboard/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
                { src: '/dashboard/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' }
            ]
        };
        const url = URL.createObjectURL(new Blob([JSON.stringify(manifest)], { type: 'application/manifest+json' }));
        const link = document.querySelector('link[rel="manifest"]');
        if (link) link.href = url;
    } catch (e) {
        console.warn('No se pudo generar el manifest dinámico:', e);
    }
}

// ── Init ────────────────────────────────────────────────────────
(function init() {
    // BAJO-01 fix: limpiar timer previo de forma defensiva antes de asignar uno nuevo
    if (refreshTimer) clearInterval(refreshTimer);
    if (bannerTimer)  clearInterval(bannerTimer);

    const hwId = getHardwareId();
    if (!hwId) {
        showError('URL inválida. Usa: antiparticula.com/dashboard/TU_HARDWARE_ID');
        return;
    }

    instalarManifest(hwId);

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/dashboard/sw.js', { scope: '/dashboard/' }).catch(() => {});
    }

    fetchData(hwId, true);
    refreshTimer = setInterval(() => fetchData(hwId, false), REFRESH_MS);
    bannerTimer  = setInterval(actualizarBanner, 30000);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchData(hwId, false);
    });
    window.addEventListener('online',  () => fetchData(hwId, false));
    window.addEventListener('offline', actualizarBanner);
})();
