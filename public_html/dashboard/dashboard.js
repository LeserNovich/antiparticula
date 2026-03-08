// ────────────────────────────────────────────────────────────────
// Antiparticula Dashboard — PWA Frontend
// ────────────────────────────────────────────────────────────────

const API_BASE   = '/get_dashboard_data.php';
const REFRESH_MS = 60 * 1000;
const STALE_MIN  = 30;

let charts = {};
let refreshTimer = null;

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
    const formatted = '$\u202f' + abs.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

// ── Render principal ────────────────────────────────────────────
function render(data) {
    const app   = document.getElementById('app');
    // ALTO-05 fix: clamp a 0 para evitar badge verde falso con minutos negativos
    const minutos = Math.max(0, data.hace_minutos || 0);
    const stale = minutos > STALE_MIN;

    const ticketProm = data.tickets_hoy > 0
        ? formatMXN(Math.round(data.ventas_hoy / data.tickets_hoy))
        : '$\u202f0.00';
    const margenPct  = data.ventas_hoy > 0
        ? ((data.ganancia_hoy / data.ventas_hoy) * 100).toFixed(1)
        : '0.0';
    const margenClass = parseFloat(margenPct) >= 20 ? 'card-margin-good' : 'card-margin-warn';

    app.innerHTML = `
    <div class="header">
        <div class="header-title">
            <h1>${escHtml(data.negocio || 'Antiparticula POS')}</h1>
            <span>Panel de ventas</span>
        </div>
        <div class="header-logo">A</div>
    </div>

    <div class="sync-badge ${stale ? 'stale' : ''}">
        <span class="sync-dot"></span>
        Última sync: ${tiempoRelativo(minutos)}
    </div>

    <!-- KPI principales -->
    <div class="cards-grid">
        <div class="card card-green">
            <div class="card-label">Ventas hoy</div>
            <div class="card-value">${formatMXN(data.ventas_hoy)}</div>
            <div class="card-sub">${data.tickets_hoy} ticket${data.tickets_hoy !== 1 ? 's' : ''}</div>
        </div>
        <div class="card card-blue">
            <div class="card-label">Ganancia hoy</div>
            <div class="card-value">${formatMXN(data.ganancia_hoy)}</div>
            <div class="card-sub">&nbsp;</div>
        </div>
    </div>

    <!-- KPI secundarios -->
    <div class="cards-grid cards-grid-3">
        <div class="card">
            <div class="card-label">Tickets</div>
            <div class="card-value card-value-sm">${data.tickets_hoy}</div>
            <div class="card-sub">hoy</div>
        </div>
        <div class="card">
            <div class="card-label">Ticket prom.</div>
            <div class="card-value card-value-sm">${ticketProm}</div>
            <div class="card-sub">&nbsp;</div>
        </div>
        <div class="card ${margenClass}">
            <div class="card-label">Margen</div>
            <div class="card-value card-value-sm">${margenPct}%</div>
            <div class="card-sub">ganancia</div>
        </div>
    </div>

    <!-- Gráfica por hora -->
    <div class="card section-card">
        <div class="section-title">Ventas por hora — hoy</div>
        <div class="chart-wrap" id="wrapHoras">
            <canvas id="chartHoras"></canvas>
        </div>
    </div>

    <!-- Turno actual -->
    <div class="card section-card">
        <div class="card-label" style="margin-bottom:12px">Turno actual</div>
        <div class="turno-card">
            <div class="turno-row">
                <span>Cajero</span>
                <span>${escHtml(data.turno_usuario || '—')}</span>
            </div>
            <div class="turno-row">
                <span>Apertura</span>
                <span>${escHtml(data.turno_inicio || '—')}</span>
            </div>
            <div class="turno-row">
                <span>Ventas del turno</span>
                <span>${formatMXN(data.ventas_turno)} (${data.tickets_turno} tickets)</span>
            </div>
            <div class="turno-row">
                <span>Estado</span>
                <span>${data.turno_abierto
                    ? '<span class="badge-open">Abierto</span>'
                    : '<span class="badge-closed">Cerrado</span>'}</span>
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
        <div class="chart-donut-wrap" id="wrapDonut">
            <canvas id="chartDonut"></canvas>
            <div class="donut-legend">
                <div class="donut-item">
                    <span class="donut-dot dot-ganancia"></span>
                    <div>
                        <div class="donut-label">Ganancia</div>
                        <div class="donut-val">${margenPct}% &middot; ${formatMXN(data.ganancia_hoy)}</div>
                    </div>
                </div>
                <div class="donut-item">
                    <span class="donut-dot dot-costo"></span>
                    <div>
                        <div class="donut-label">Costo</div>
                        <div class="donut-val">${(100 - parseFloat(margenPct)).toFixed(1)}% &middot; ${formatMXN(Math.max(0, data.ventas_hoy - data.ganancia_hoy))}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Últimas ventas -->
    <div class="card section-card">
        <div class="section-title">Últimas ventas</div>
        ${renderVentas(data.ultimas_ventas)}
    </div>
    `;

    renderChartHoras(data.ultimas_ventas);
    renderChartSemana(data.historico_semana);
    renderChartDonut(data.ventas_hoy, data.ganancia_hoy);
}

// ── Opciones base para bar charts ───────────────────────────────
function barOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#202936',
                borderColor: '#2c3e55',
                borderWidth: 1,
                titleColor: '#7ab8d9',
                bodyColor: '#e2e8f0',
                callbacks: {
                    label: ctx => '$\u202f' + ctx.parsed.y.toLocaleString('es-MX', { minimumFractionDigits: 2 })
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

// ── Espera a Chart.js con reintentos ────────────────────────────
// ALTO-04 fix: reintento con limite de intentos en vez de setTimeout unico
function waitForChart(callback, retries) {
    const intentos = (retries === undefined) ? 20 : retries;
    if (typeof Chart !== 'undefined') { callback(); return; }
    if (intentos <= 0) { console.warn('Chart.js no cargo despues de multiples intentos'); return; }
    setTimeout(() => waitForChart(callback, intentos - 1), 300);
}

// ── Gráfica por hora ────────────────────────────────────────────
function renderChartHoras(ventas) {
    const canvas = document.getElementById('chartHoras');
    if (!canvas) return;
    if (typeof Chart === 'undefined') { waitForChart(() => renderChartHoras(ventas)); return; }
    if (charts.horas) { charts.horas.destroy(); }

    const hours = {};
    (ventas || []).forEach(v => {
        // MEDIO-06 fix: validar que la hora parseada sea valida (0-23)
        const raw = v.hora || '00:00';
        const h   = parseInt(raw.split(':')[0], 10);
        if (isNaN(h) || h < 0 || h > 23) return;
        hours[h] = (hours[h] || 0) + (v.total_centavos || 0) / 100;
    });
    const sortedH = Object.keys(hours).map(Number).sort((a, b) => a - b);

    if (sortedH.length === 0) {
        const wrap = document.getElementById('wrapHoras');
        if (wrap) wrap.innerHTML = '<p class="chart-empty">Sin ventas registradas hoy</p>';
        return;
    }

    charts.horas = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: sortedH.map(h => `${String(h).padStart(2, '0')}:00`),
            datasets: [{
                data: sortedH.map(h => hours[h]),
                backgroundColor: 'rgba(44,110,149,0.3)',
                borderColor: '#2c6e95',
                borderWidth: 1.5,
                borderRadius: 5
            }]
        },
        options: barOptions()
    });
}

// ── Gráfica 7 días ──────────────────────────────────────────────
function renderChartSemana(historico) {
    const canvas = document.getElementById('chartSemana');
    if (!canvas) return;
    if (typeof Chart === 'undefined') { waitForChart(() => renderChartSemana(historico)); return; }
    if (charts.semana) { charts.semana.destroy(); }

    // MEDIO-09 fix: siempre generar los 7 dias, rellenar con 0 los dias sin datos
    const mapa = {};
    (Array.isArray(historico) ? historico : []).forEach(d => {
        if (d.fecha) mapa[d.fecha] = (d.total_centavos || 0) / 100;
    });
    const labels  = [];
    const valores = [];
    for (let i = 6; i >= 0; i--) {
        const d = new Date();
        d.setDate(d.getDate() - i);
        const key   = d.toISOString().split('T')[0];
        const label = new Date(key + 'T12:00:00').toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric' });
        labels.push(label);
        valores.push(mapa[key] !== undefined ? mapa[key] : 0);
    }

    charts.semana = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: valores,
                backgroundColor: 'rgba(44,110,149,0.25)',
                borderColor: '#2c6e95',
                borderWidth: 1.5,
                borderRadius: 6
            }]
        },
        options: barOptions()
    });
}

// ── Donut ganancia / costo ──────────────────────────────────────
function renderChartDonut(ventasCentavos, gananciaCentavos) {
    const canvas = document.getElementById('chartDonut');
    if (!canvas) return;
    if (typeof Chart === 'undefined') { waitForChart(() => renderChartDonut(ventasCentavos, gananciaCentavos)); return; }
    if (charts.donut) { charts.donut.destroy(); }

    if (ventasCentavos <= 0) {
        const wrap = document.getElementById('wrapDonut');
        if (wrap) wrap.innerHTML = '<p class="chart-empty">Sin ventas hoy</p>';
        return;
    }

    // BAJO-02 fix: clamp a 0 para evitar segmentos negativos si ganancia > ventas
    const ganancia = Math.max(0, gananciaCentavos / 100);
    const costo    = Math.max(0, (ventasCentavos - gananciaCentavos) / 100);

    charts.donut = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Ganancia', 'Costo'],
            datasets: [{
                data: [ganancia, costo],
                backgroundColor: ['rgba(52,211,153,0.75)', 'rgba(44,110,149,0.45)'],
                borderColor:     ['#34d399', '#2c6e95'],
                borderWidth: 1.5,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
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
                        label: ctx => ctx.label + ': $\u202f' + ctx.parsed.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    }
                }
            }
        }
    });
}

// ── Últimas ventas ──────────────────────────────────────────────
function renderVentas(ventas) {
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

// ── Loading / Error ─────────────────────────────────────────────
function showLoading() {
    document.getElementById('app').innerHTML = `
    <div class="loading-spinner">
        <div class="spinner"></div>
        <span>Cargando datos...</span>
    </div>`;
}

function showError(msg) {
    document.getElementById('app').innerHTML = `
    <div style="padding:24px">
        <div class="error-box">
            <strong>Sin conexión</strong>
            <p>${escHtml(msg)}</p>
        </div>
    </div>`;
}

// ── Fetch ───────────────────────────────────────────────────────
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
        render(data);
    } catch (err) {
        if (isFirstLoad) showError('No se pudo conectar al servidor. ' + err.message);
        console.error('Dashboard fetch error:', err);
    }
}

// ── Init ────────────────────────────────────────────────────────
(function init() {
    // BAJO-01 fix: limpiar timer previo de forma defensiva antes de asignar uno nuevo
    if (refreshTimer) clearInterval(refreshTimer);

    const hwId = getHardwareId();
    if (!hwId) {
        showError('URL inválida. Usa: antiparticula.com/dashboard/TU_HARDWARE_ID');
        return;
    }
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('./sw.js').catch(() => {});
    }
    fetchData(hwId, true);
    refreshTimer = setInterval(() => fetchData(hwId, false), REFRESH_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchData(hwId, false);
    });
})();
