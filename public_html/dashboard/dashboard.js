// ────────────────────────────────────────────────────────────────
// Antiparticula Dashboard — PWA Frontend
// ────────────────────────────────────────────────────────────────

const API_BASE    = 'https://www.antiparticula.com/get_dashboard_data.php';
const REFRESH_MS  = 60 * 1000;   // 60 segundos
const STALE_MIN   = 30;          // minutos sin sync → badge rojo

let chartInstance = null;
let refreshTimer  = null;

// ── Extraer hardware_id de la URL ──────────────────────────────
function getHardwareId() {
    const parts = window.location.pathname.split('/');
    // /dashboard/HARDWARE_ID  →  parts = ['', 'dashboard', 'HARDWARE_ID', ...]
    for (let i = 0; i < parts.length; i++) {
        if (parts[i] === 'dashboard' && parts[i + 1]) {
            return parts[i + 1];
        }
    }
    return null;
}

// ── Formato de pesos ───────────────────────────────────────────
function formatMXN(centavos) {
    const val = centavos / 100;
    return '$\u202f' + val.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── Calcular "hace X min / horas" ─────────────────────────────
function tiempoRelativo(minutos) {
    if (minutos < 1)  return 'ahora mismo';
    if (minutos < 60) return `hace ${minutos} min`;
    const h = Math.floor(minutos / 60);
    const m = minutos % 60;
    return m > 0 ? `hace ${h}h ${m}min` : `hace ${h}h`;
}

// ── Render principal ──────────────────────────────────────────
function render(data) {
    const app = document.getElementById('app');
    const stale = data.hace_minutos > STALE_MIN;

    app.innerHTML = `
    <div class="header">
        <div class="header-title">
            <h1>${escHtml(data.negocio || 'Antiparticula POS')}</h1>
            <span>Panel de ventas</span>
        </div>
        <div class="header-logo">&#128200;</div>
    </div>

    <div class="sync-badge ${stale ? 'stale' : ''}">
        <span class="sync-dot"></span>
        Última sync: ${tiempoRelativo(data.hace_minutos)}
    </div>

    <!-- Ventas hoy + Tickets hoy -->
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

        <!-- Turno actual -->
        <div class="card card-yellow card-full">
            <div class="card-label">Turno actual</div>
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
    </div>

    <!-- Gráfica 7 días -->
    <div class="card" style="margin-bottom:16px">
        <div class="section-title">Ventas últimos 7 días</div>
        <div class="chart-wrap">
            <canvas id="chartSemana"></canvas>
        </div>
    </div>

    <!-- Últimas ventas -->
    <div class="card">
        <div class="section-title">Últimas ventas</div>
        ${renderVentas(data.ultimas_ventas)}
    </div>
    `;

    renderChart(data.historico_semana);
}

function renderVentas(ventas) {
    if (!ventas || ventas.length === 0) {
        return '<p style="color:var(--text-muted);font-size:0.85rem;text-align:center;padding:16px 0">Sin ventas registradas</p>';
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

function renderChart(historico) {
    const canvas = document.getElementById('chartSemana');
    if (!canvas) return;

    // Si Chart.js aún no cargó (CDN lento), reintentar en 800ms
    if (typeof Chart === 'undefined') {
        setTimeout(() => renderChart(historico), 800);
        return;
    }

    // Últimos 7 días en orden cronológico
    const dias = Array.isArray(historico) ? historico.slice(-7) : [];
    const labels = dias.map(d => {
        const date = new Date(d.fecha + 'T12:00:00');
        return date.toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric' });
    });
    const valores = dias.map(d => (d.total_centavos || 0) / 100);

    if (chartInstance) { chartInstance.destroy(); }

    const ctx = canvas.getContext('2d');
    chartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Ventas',
                data: valores,
                backgroundColor: 'rgba(74, 222, 128, 0.4)',
                borderColor: 'rgba(74, 222, 128, 0.9)',
                borderWidth: 1.5,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => '$\u202f' + ctx.parsed.y.toLocaleString('es-MX', { minimumFractionDigits: 2 })
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#94a3b8', font: { size: 10 } },
                    grid: { color: 'rgba(255,255,255,0.05)' }
                },
                y: {
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 10 },
                        callback: v => '$' + (v >= 1000 ? (v / 1000).toFixed(1) + 'k' : v)
                    },
                    grid: { color: 'rgba(255,255,255,0.05)' }
                }
            }
        }
    });
}

// ── Seguridad: escapar HTML ────────────────────────────────────
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Mostrar loading / error ───────────────────────────────────
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
            <strong>&#9888; Sin datos</strong>
            <p>${escHtml(msg)}</p>
        </div>
    </div>`;
}

// ── Fetch datos ───────────────────────────────────────────────
async function fetchData(hwId, isFirstLoad) {
    if (isFirstLoad) showLoading();
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 12000);
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

// ── Init ──────────────────────────────────────────────────────
(function init() {
    const hwId = getHardwareId();
    if (!hwId) {
        showError('URL inválida. Usa: antiparticula.com/dashboard/TU_HARDWARE_ID');
        return;
    }

    // Registrar Service Worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('./sw.js').catch(() => {});
    }

    fetchData(hwId, true);
    refreshTimer = setInterval(() => fetchData(hwId, false), REFRESH_MS);

    // Refrescar cuando la pestaña vuelve a estar activa
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchData(hwId, false);
    });
})();
