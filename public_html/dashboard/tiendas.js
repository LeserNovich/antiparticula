/* ════════════════════════════════════════════════════════════════
   Panel de TODAS las tiendas (celular del dueño): /dashboard/T-<código>

   Se carga después de dashboard.js, que le presta lo que ya sabe pintar (formatMXN, escHtml,
   ahoraMX, htmlColumnas, pintarPagos, pintarTop, pintarHoras, pintarSemana, pintarMes…) y que
   no arranca cuando el id empieza con «T-». Aquí vive solo lo propio: entrar con el PIN,
   hablar con el servidor multisucursal, el selector Todas · Centro · Norte y las pantallas
   de Tiendas e Inventario.

   Los datos son «lo que cada tienda ya sincronizó» (el servidor dice cuándo habló cada una):
   no son en vivo como el panel de una sola tienda, y por eso cada tienda lleva su «reportó
   hace…» y una que lleva mucho sin hablar sale en ámbar.
   ════════════════════════════════════════════════════════════════ */

// En una PC de desarrollo el servidor corre en el puerto 18080; en producción, en su dominio.
const API_TIENDAS = /^(localhost|127\.0\.0\.1)$/.test(location.hostname)
    ? 'http://' + location.hostname + ':18080/api/v1'
    : 'https://api.antiparticula.com/api/v1';
const FIRMA_DOM_T = 't1';
const STALE_TIENDA_MIN = 30;   // una tienda que lleva más de esto sin hablar con el servidor sale en ámbar

const T = { id: null, sel: 'todas', datos: {}, estructura: null, cargando: false, refreshTimer: null };

// ── Id, sesión y lo guardado en el teléfono ─────────────────────
function idTiendas() {
    const id = getHardwareId();
    return id && /^T-[A-Za-z0-9]{4,32}$/.test(id) ? id : null;
}

function tokenT() {
    try { return localStorage.getItem('apdash:tok:' + T.id); } catch (e) { return null; }
}
function guardarTokenT(t) {
    try { localStorage.setItem('apdash:tok:' + T.id, t); } catch (e) { /* sin almacenamiento: se pedirá el PIN cada vez */ }
}
function borrarTokenT() {
    try { localStorage.removeItem('apdash:tok:' + T.id); } catch (e) { /* nada que borrar */ }
}
function borrarCacheT() {
    try { localStorage.removeItem('apdash:tdatos:' + T.id); } catch (e) { /* nada que borrar */ }
}

// Lo último que llegó, para abrir al instante y sin internet. Solo si es de HOY y esta sesión ya entró con el PIN.
function leerCacheT() {
    try {
        const o = JSON.parse(localStorage.getItem('apdash:tdatos:' + T.id));
        if (!o || !o.t || !o.datos || !o.datos.todas) return null;
        if (ahoraMX(new Date(o.t)).clave !== ahoraMX().clave) return null;
        if (!tokenT()) return null;
        return o;
    } catch (e) { return null; }
}
function guardarCacheT() {
    try { localStorage.setItem('apdash:tdatos:' + T.id, JSON.stringify({ t: Date.now(), datos: T.datos })); }
    catch (e) { /* sin almacenamiento o lleno: solo no se guarda */ }
}

function selGuardada() {
    try { return localStorage.getItem('apdash:tsel:' + T.id) || 'todas'; } catch (e) { return 'todas'; }
}
function guardarSel() {
    try { localStorage.setItem('apdash:tsel:' + T.id, T.sel); } catch (e) { /* solo no se recuerda */ }
}

// ── Pantalla del PIN ────────────────────────────────────────────
function showPinT(msg, bloqueado) {
    document.getElementById('app').innerHTML = `
    <header class="top"><div class="top-in">
        <div class="bar">${MARCA}</div>
        <span class="kicker">Panel de tus tiendas</span>
        <h1>Panel protegido</h1>
    </div></header>
    <main class="sheet"><div class="sheet-in">
        <form class="pin-box" data-k="pinForm" autocomplete="off" novalidate>
            <p class="pin-ayuda">Escribe el PIN de 6 a 8 dígitos que creaste en el programa de tu tienda (Configuración &gt; Panel móvil).</p>
            <label class="pin-lab" for="pin-campo">PIN</label>
            <input id="pin-campo" class="pin-input" data-k="pinCampo" type="password" inputmode="numeric" pattern="[0-9]*"
                   maxlength="8" autocomplete="current-password" ${bloqueado ? 'disabled' : ''}>
            <p class="pin-msg" data-k="pinMsg" role="alert">${escHtml(msg || '')}</p>
            <button type="submit" class="btn-solid" data-k="pinBoton" ${bloqueado ? 'disabled' : ''}>Entrar</button>
        </form>
    </div></main>`;
    T.estructura = null;
    ultimoD = null;
    const c = el('pinCampo');
    if (c && !bloqueado) c.focus();
}

async function enviarPinT(ev) {
    ev.preventDefault();
    const campo = el('pinCampo'), boton = el('pinBoton');
    if (!campo || !T.id) return;
    const pin = campo.value.trim();
    if (!/^\d{6,8}$/.test(pin)) { setText('pinMsg', 'El PIN tiene de 6 a 8 dígitos.'); return; }
    boton.disabled = true;
    setText('pinMsg', 'Comprobando…');
    try {
        const res = await fetch(API_TIENDAS + '/panel/entrar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ codigo: T.id.slice(2), pin }),
        });
        let j = null;
        try { j = await res.json(); } catch (e) { /* respuesta sin cuerpo */ }
        if (res.status === 200 && j && j.token) {
            guardarTokenT(j.token);
            fetchT(true);
            return;
        }
        if (res.status === 429) {
            const min = Math.max(1, Math.ceil(((j && j.reintentarEn) || 900) / 60));
            showPinT(`Demasiados intentos. Prueba otra vez en ${min} minuto${min !== 1 ? 's' : ''}.`, true);
            return;
        }
        if (res.status === 403 && j && j.error === 'licencia_vencida') {
            showPinT('La licencia multisucursal de este negocio no está vigente. Comunícate con soporte.', true);
            return;
        }
        if (res.status === 401) {
            campo.value = '';
            boton.disabled = false;
            setText('pinMsg', 'El PIN no es correcto.');
            campo.focus();
            return;
        }
        boton.disabled = false;
        setText('pinMsg', 'No se pudo comprobar el PIN. Inténtalo de nuevo.');
    } catch (e) {
        boton.disabled = false;
        setText('pinMsg', 'Sin conexión. Revisa tu internet e inténtalo de nuevo.');
    }
}

function cerrarSesionT() {
    const tk = tokenT();
    if (tk) {   // avisarle al servidor; si no hay red, la sesión local se borra igual
        fetch(API_TIENDAS + '/panel/salir', { method: 'POST', headers: { Authorization: 'Bearer ' + tk } }).catch(() => {});
    }
    borrarTokenT();
    borrarCacheT();
    T.datos = {};
    desdeCache = null;
    ultimoOk = null;
    showPinT('Sesión cerrada.');
}

// ── Pedir los datos ─────────────────────────────────────────────
async function pedirT(sel, token) {
    const ctl = new AbortController();
    const to = setTimeout(() => ctl.abort(), 15000);
    try {
        const res = await fetch(API_TIENDAS + '/panel/resumen?sucursal=' + encodeURIComponent(sel),
            { signal: ctl.signal, headers: { Authorization: 'Bearer ' + token } });
        let json = null;
        try { json = await res.json(); } catch (e) { /* sin cuerpo */ }
        return { res, json };
    } finally { clearTimeout(to); }
}

function marcarCargandoT(on) {
    T.cargando = on;
    const b = el('btnRefrescar');
    if (b) b.classList.toggle('is-loading', on);
}

function showErrorT(msg) { showError(msg); T.estructura = null; }

async function fetchT(primera) {
    if (T.cargando) return;
    const token = tokenT();
    if (!token) { showPinT(''); return; }
    if (primera) { showLoading(); T.estructura = null; }
    marcarCargandoT(true);
    const sel = T.sel;
    try {
        // Siempre «todas» (las tarjetas de Tiendas y los chips); y la tienda elegida si es una
        const pedidos = [pedirT('todas', token)];
        if (sel !== 'todas') pedidos.push(pedirT(sel, token));
        const rs = await Promise.all(pedidos);
        if (rs.some(r => r.res.status === 401)) {
            const teniaSesion = !!token;
            borrarTokenT();
            borrarCacheT();
            T.datos = {};
            desdeCache = null;
            ultimoOk = null;
            showPinT(teniaSesion ? 'Tu sesión venció. Escribe el PIN otra vez.' : '');
            return;
        }
        const licencia = rs.find(r => r.res.status === 403);
        if (licencia) {
            showErrorT((licencia.json && licencia.json.mensaje) || 'La licencia multisucursal de este negocio no está vigente.');
            return;
        }
        if (rs[1] && rs[1].res.status === 404) {   // la tienda elegida ya no existe (se desactivó): vuelve a «todas»
            T.sel = 'todas';
            guardarSel();
            rs.pop();
        }
        if (!rs.every(r => r.res.ok && r.json)) throw new Error('HTTP ' + (rs.find(r => !r.res.ok) || rs[0]).res.status);
        T.datos = { todas: rs[0].json };
        if (rs[1]) T.datos[sel] = rs[1].json;
        ultimoOk = Date.now();
        desdeCache = null;
        guardarCacheT();
        renderT();
    } catch (err) {
        console.error('Panel de tiendas, error al pedir los datos:', err);
        if (ultimoOk === null && !desdeCache) {
            showErrorT('No se pudo conectar al servidor. ' + err.message);
        } else {
            actualizarBanner();   // se conserva la vista, con aviso de datos viejos
        }
    } finally {
        marcarCargandoT(false);
        // Eligió otra tienda mientras llegaba esto: sus datos todavía no están
        if (T.sel !== sel && !T.datos[T.sel] && T.id && tokenT()) fetchT(false);
    }
}

// ── Derivar: lo que el servidor manda → lo que las pantallas saben pintar ──
const num = v => Number(v) || 0;

// Minutos desde que una tienda habló con el servidor; Infinity si nunca lo ha hecho
function minutosSinReportar(s) {
    if (!s.ultimoContacto) return Infinity;
    const t = Date.parse(s.ultimoContacto);
    return isNaN(t) ? Infinity : Math.max(0, Math.floor((Date.now() - t) / 60000));
}

// «desde las 08:00 a.m.» · «desde ayer a las 06:00 p.m.» · «desde el 9 Oct a las …». Un turno de otro día merece atención:
// lo más común es que se olvidó cerrar.
function desdeT(inicio) {
    const m = textoMomento(inicio);
    if (!m) return 'desde ' + formatFechaHora(inicio);
    return m.indexOf('a las ') === 0 ? 'desde las ' + m.slice(6) : 'desde ' + m;
}
function turnoDeOtroDiaT(inicio) {
    const m = textoMomento(inicio);
    return !!m && m.indexOf('a las ') !== 0;
}

function derivarT(resp, todas) {
    const ahora = ahoraMX();
    const P = resp.periodos || {};
    const hoy = (P.hoy && P.hoy.total) || {};
    const ayerT = (P.ayer && P.ayer.total) || {};
    const ingresosHoy = num(hoy.ventas);
    const ventasTickets = num(hoy.ventasTickets);
    const gananciaHoy = num(hoy.ganancia);
    const ticketsHoy = num(hoy.tickets);
    const margenPct = ventasTickets > 0 ? gananciaHoy / ventasTickets * 100 : 0;
    const ayer = num(ayerT.ventas);

    // Las mismas piezas que el panel de una tienda, armadas con lo que manda el servidor
    const formas = (P.hoy && P.hoy.formasPago) || {};
    const pagos = datosPagos({ pagos_hoy: { efectivo: formas.efectivo, tarjeta: formas.tarjeta, transferencia: formas.transferencia,
        comision: num(formas.comision) + num(formas.ivaComision) } });
    const lista = p => (p && Array.isArray(p.productos) ? p.productos : [])
        .map(x => ({ nombre: x.nombre, importe: x.importe, cantidad: x.cantidad, unidad: x.pzKg }));
    const top = datosTop({ top_productos: { hoy: lista(P.hoy), semana: lista(P.semana), mes: lista(P.mes) } });
    const porHora = P.hoy && Array.isArray(P.hoy.porHora) ? P.hoy.porHora : [];
    const horas = datosHoras({ ventas_por_hora: porHora.map((v, h) => ({ hora: h, total_centavos: num(v), tickets: 0 }))
        .filter(x => x.total_centavos > 0) }, ahora);
    const semana = datosSemana(((P.semana && P.semana.porDia) || []).map(r => ({ fecha: r.dia, total_centavos: num(r.ventas) })), ahora.clave);

    const dias = {};
    ((P.mes && P.mes.porDia) || []).forEach(r => {
        const o = dias[r.dia] || (dias[r.dia] = { fecha: r.dia, ventas: 0, ganancia: 0 });
        o.ventas += num(r.ventas);
    });
    const mes = datosMes(dias, ingresosHoy, gananciaHoy, ahora);
    const mesTotal = P.mes && P.mes.total ? P.mes.total : {};
    mes.total = num(mesTotal.ventas) || mes.total;
    mes.ganancia = num(mesTotal.ganancia);                       // el servidor no manda la ganancia día por día
    const antVentas = num(P.mesAnterior && P.mesAnterior.total && P.mesAnterior.total.ventas);
    const mesPrevio = ahora.m === 1 ? 12 : ahora.m - 1;
    mes.anterior = antVentas > 0 ? { total: antVentas, pct: Math.round((mes.total / antVentas - 1) * 100), nombre: MESES[mesPrevio - 1] } : null;

    const sucs = (resp.sucursales || []).filter(s => T.sel === 'todas' || s.id === T.sel);
    const suma = (f) => sucs.reduce((a, s) => a + num(f(s)), 0);
    return {
        negocio: resp.negocio || 'Mis tiendas',
        sucursales: sucs,
        ingresosHoy, ventasTickets, gananciaHoy, ticketsHoy,
        abonosHoy: Math.max(0, ingresosHoy - ventasTickets),
        ticketProm: num(hoy.ticketPromedio),
        margenPct, margenTxt: margenPct.toFixed(1) + '%', margenGood: margenPct >= 20,
        vsAyer: ayer > 0 ? { ayer, ratio: ingresosHoy / ayer, supera: ingresosHoy >= ayer, pct: Math.round(ingresosHoy / ayer * 100),
            extra: Math.round((ingresosHoy / ayer - 1) * 100) } : null,
        pagos, top, horas, semana, semanaAnterior: null, mes, utilidad: { hay: false },
        cancelaciones: { cantidad: suma(s => s.cancelaciones && s.cancelaciones.cantidad), monto: suma(s => s.cancelaciones && s.cancelaciones.monto) },
        inventario: { agotados: suma(s => s.inventario && s.inventario.agotados), bajoMinimo: suma(s => s.inventario && s.inventario.bajoMinimo) },
        urgentes: Array.isArray(resp.urgentes) ? resp.urgentes : [],
        porRecibir: suma(s => s.traspasosPorRecibir),
        cajas: sucs.filter(s => s.turno).map(s => ({ tienda: s.nombre, turno: s.turno })),
    };
}

// ── Montaje del DOM (una vez) ───────────────────────────────────
function montarT() {
    document.getElementById('app').innerHTML = `
    <header class="top"><div class="top-in">
        <div class="bar">
            ${MARCA}
            <button type="button" class="icon-btn" data-act="tema" data-k="btnTema" aria-label="Cambiar entre tema claro y oscuro"></button>
            <button type="button" class="icon-btn" data-act="refrescar" data-k="btnRefrescar" aria-label="Actualizar ahora">${ICONO_ACTUAL}</button>
        </div>

        <span class="kicker">Panel de tus tiendas</span>
        <h1 data-k="negocio"></h1>
        <button type="button" class="sync" data-k="syncBadge" data-act="refrescar" aria-live="polite">
            <span class="dot"></span><span data-k="sync"></span>
        </button>

        <section class="hero" aria-label="Ingresos de hoy">
            <div class="hero-label" data-k="heroEtiqueta">Ingresos de hoy</div>
            <div class="hero-value" data-k="ingresosHoy"></div>
            <div class="hero-sub" data-k="ingresosSub"></div>
            <div class="vs" data-k="vs" hidden>
                <div class="vs-line"><span data-k="vsTexto"></span><span data-k="vsAyer"></span></div>
                <div class="vs-track"><div class="vs-fill" data-k="vsFill"></div></div>
            </div>
        </section>
    </div></header>

    <main class="sheet"><div class="sheet-in">
        <div class="sel-tiendas" data-k="selTiendas" role="group" aria-label="Tienda" hidden></div>
        <div class="tabs">
            <div class="seg" role="tablist" aria-label="Sección">
                <button type="button" role="tab" id="tab-hoy"        data-tab="hoy"        aria-controls="panel-hoy"        aria-selected="true">Hoy</button>
                <button type="button" role="tab" id="tab-tiendas"    data-tab="tiendas"    aria-controls="panel-tiendas"    aria-selected="false">Tiendas</button>
                <button type="button" role="tab" id="tab-inventario" data-tab="inventario" aria-controls="panel-inventario" aria-selected="false">Inventario</button>
                <button type="button" role="tab" id="tab-historial"  data-tab="historial"  aria-controls="panel-historial"  aria-selected="false">Historial</button>
            </div>
        </div>
        <div class="banner" data-k="banner" role="status" hidden></div>

        <!-- ═════════ HOY ═════════ -->
        <section class="panel" id="panel-hoy" role="tabpanel" aria-labelledby="tab-hoy" data-panel="hoy">
            <div class="tile">
                <div class="tile-head">
                    <div class="tile-label">Ganancia de hoy</div>
                    <span class="chip" data-k="margenChip" hidden></span>
                </div>
                <div class="kpi-value c-ganancia" data-k="gananciaHoy"></div>
                <div class="split" aria-hidden="true"><i data-k="splitGan"></i></div>
                <div class="tile-note" data-k="splitTxt"></div>
            </div>

            <div class="grid2">
                <div class="tile">
                    <div class="tile-label">Tickets</div>
                    <div class="kpi-value kpi-sm c-tickets" data-k="ticketsHoy"></div>
                    <div class="kpi-sub">cobrados hoy</div>
                </div>
                <div class="tile">
                    <div class="tile-label">Ticket promedio</div>
                    <div class="kpi-value kpi-sm c-promedio" data-k="ticketProm"></div>
                    <div class="kpi-sub">por venta</div>
                </div>
            </div>

            <div class="tile" data-k="cajasTile">
                <div class="tile-head">
                    <div class="tile-label">Cajas</div>
                    <span data-k="cajasChip"></span>
                </div>
                <div data-k="cajasCuerpo"></div>
            </div>

            <div class="tile" data-k="pagosTile">
                <div class="tile-label">Cómo pagaron hoy</div>
                <div data-k="pagosCuerpo"></div>
            </div>

            <div class="tile" data-k="topTile" hidden>
                <div class="tile-label">Lo más vendido</div>
                <div class="seg seg-sm" role="tablist" aria-label="Periodo de lo más vendido">
                    <button type="button" role="tab" data-top="hoy"    aria-selected="true">Hoy</button>
                    <button type="button" role="tab" data-top="semana" aria-selected="false">7 días</button>
                    <button type="button" role="tab" data-top="mes"    aria-selected="false">Mes</button>
                </div>
                <div class="hlist" data-k="topLista"></div>
                <p class="tile-note pie-nota">Importe de cada producto antes de los descuentos que se aplican a todo el ticket.</p>
            </div>

            <div class="tile">
                <div class="tile-label">Ventas por hora</div>
                <div data-k="horasGrafica"></div>
                <div class="readout" data-k="horasLectura"></div>
            </div>

            <div class="tile" data-k="cancelTile">
                <div class="tile-head">
                    <div class="tile-label">Ventas de hoy canceladas</div>
                    <span data-k="cancelChip"></span>
                </div>
                <div data-k="cancelCuerpo"></div>
            </div>

            <button type="button" class="btn-ghost btn-compartir" data-act="compartir">Compartir resumen del día</button>
        </section>

        <!-- ═════════ TIENDAS ═════════ -->
        <section class="panel" id="panel-tiendas" role="tabpanel" aria-labelledby="tab-tiendas" data-panel="tiendas" hidden>
            <div data-k="tiendasLista" class="panel"></div>
            <p class="tile-note pie-nota">Es lo que cada tienda ya sincronizó con el servidor: no es en vivo. Toca una tienda para ver solo la suya.</p>
        </section>

        <!-- ═════════ INVENTARIO ═════════ -->
        <section class="panel" id="panel-inventario" role="tabpanel" aria-labelledby="tab-inventario" data-panel="inventario" hidden>
            <div data-k="invCuerpo" class="panel"></div>
        </section>

        <!-- ═════════ HISTORIAL ═════════ -->
        <section class="panel" id="panel-historial" role="tabpanel" aria-labelledby="tab-historial" data-panel="historial" hidden>
            <div class="seg seg-sm" role="tablist" aria-label="Periodo">
                <button type="button" role="tab" data-sub="semana" aria-selected="true">Semana</button>
                <button type="button" role="tab" data-sub="mes"    aria-selected="false">Mes</button>
            </div>

            <div class="panel" data-subpanel="semana">
                <div class="tile">
                    <div class="tile-label">Últimos 7 días</div>
                    <div class="kpi-value c-ventas" data-k="semanaTotal"></div>
                    <div class="kpi-sub" data-k="semanaSub"></div>
                    <div class="tile-note" data-k="semanaVsAnterior"></div>
                </div>
                <div class="grid2">
                    <div class="tile">
                        <div class="tile-label">Promedio diario</div>
                        <div class="kpi-value kpi-xs c-promedio" data-k="semanaPromedio"></div>
                        <div class="kpi-sub" data-k="semanaPromSub"></div>
                    </div>
                    <div class="tile">
                        <div class="tile-label">Mejor día</div>
                        <div class="kpi-value kpi-xs c-ganancia" data-k="semanaMejor"></div>
                        <div class="kpi-sub" data-k="semanaMejorSub"></div>
                    </div>
                </div>
                <div class="tile">
                    <div class="tile-head">
                        <div class="tile-label">Ventas por día</div>
                        <span class="legend-avg" data-k="semanaLeyenda" hidden>promedio</span>
                    </div>
                    <div data-k="semanaGrafica"></div>
                    <div class="readout" data-k="semanaLectura"></div>
                </div>
                <div class="tile">
                    <div class="tile-label">Detalle por día</div>
                    <div class="hlist" data-k="semanaLista"></div>
                </div>
            </div>

            <div class="panel" data-subpanel="mes" hidden>
                <div class="empty tile" data-k="mesVacio" hidden>Todavía no hay historial de este mes. Aparecerá conforme las tiendas sincronicen sus ventas.</div>
                <div data-k="mesContenido" class="panel">
                    <div class="tile">
                        <div class="tile-label" data-k="mesEtiqueta"></div>
                        <div class="kpi-value c-ventas" data-k="mesTotal"></div>
                        <div class="kpi-sub" data-k="mesSub"></div>
                        <div class="tile-note" data-k="mesVsAnterior"></div>
                    </div>
                    <div class="tile">
                        <div class="tile-label">Ganancia del mes</div>
                        <div class="kpi-value kpi-sm c-ganancia" data-k="mesGanancia"></div>
                    </div>
                    <div class="tile">
                        <div class="tile-label">Ventas por día del mes</div>
                        <div data-k="mesGrafica"></div>
                        <div class="readout" data-k="mesLectura"></div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="foot">
            <div data-k="pie"></div>
            <div><a href="https://www.antiparticula.com/puntodeventa">Antipartícula · Punto de Venta</a></div>
            <div><button type="button" class="foot-link" data-act="salir">Cerrar sesión del panel</button></div>
        </footer>
    </div></main>`;
    Object.keys(htmlPrevio).forEach(k => delete htmlPrevio[k]);
    aplicarTab();
    pintarTema();
}

// ── Pintar ──────────────────────────────────────────────────────
function elegirTienda(id, irAHoy) {
    if (T.sel === id) { if (irAHoy) { ui.tab = 'hoy'; aplicarTab(); volverAlInicioDeLaHoja(); } return; }
    T.sel = id;
    guardarSel();
    ui.sel.horas = null; ui.sel.semana = null; ui.sel.mes = null;
    if (irAHoy) { ui.tab = 'hoy'; aplicarTab(); volverAlInicioDeLaHoja(); }
    renderT();
    if (!T.datos[id]) fetchT(false);
}

function pintarSelectorT(todas) {
    const lista = todas.sucursales || [];
    setHidden('selTiendas', lista.length < 2);
    setHtml('selTiendas', [['todas', 'Todas']].concat(lista.map(s => [s.id, s.nombre])).map(([id, n]) =>
        `<button type="button" class="chip-sel" data-tienda="${escHtml(id)}" aria-pressed="${T.sel === id}">${escHtml(n)}</button>`).join(''));
}

// «Centro sin reportarse hace 3 h» / «Tiendas al día»: lo más atrasado de lo que se está viendo
function pintarFrescuraT(sucs) {
    let peor = null, minPeor = -1;
    sucs.forEach(s => { const m = minutosSinReportar(s); if (m > minPeor) { minPeor = m; peor = s; } });
    const stale = peor !== null && minPeor > STALE_TIENDA_MIN;
    let texto = 'Sin tiendas';
    if (peor) {
        texto = minPeor === Infinity ? `${peor.nombre} nunca se ha reportado`
            : stale ? `${peor.nombre} sin reportarse ${tiempoRelativo(minPeor)}`
            : `${sucs.length > 1 ? 'Tiendas al día' : 'Tienda al día'} · reportó ${tiempoRelativo(minPeor)}`;
    }
    setText('sync', texto);
    setClass('syncBadge', 'stale', stale);
}

function pintarT(d, todas) {
    setText('negocio', d.negocio);
    const unaTienda = T.sel !== 'todas' && d.sucursales.length === 1;
    setText('heroEtiqueta', unaTienda ? `Ingresos de hoy · ${d.sucursales[0].nombre}` : 'Ingresos de hoy');
    pintarFrescuraT(d.sucursales);

    setText('ingresosHoy', formatMXN(d.ingresosHoy));
    setClass('ingresosHoy', 'is-long', formatMXN(d.ingresosHoy).length > 13);
    setText('ingresosSub', d.abonosHoy > 0
        ? `${formatMXN(d.ventasTickets)} en ventas + ${formatMXN(d.abonosHoy)} en apartados`
        : (d.ticketsHoy > 0 ? `${d.ticketsHoy} ticket${d.ticketsHoy !== 1 ? 's' : ''} cobrado${d.ticketsHoy !== 1 ? 's' : ''}` : 'Aún sin ventas hoy'));
    const vs = d.vsAyer;
    setHidden('vs', !vs);
    if (vs) {
        setText('vsTexto', vs.supera ? `Superas lo de ayer (+${vs.extra}%)` : `Llevas el ${vs.pct}% de lo de ayer`);
        setText('vsAyer', `Ayer ${formatMXN(vs.ayer)}`);
        setClass('vs', 'is-over', vs.supera);
        setVar('vsFill', 'width', Math.min(100, Math.max(0, vs.ratio * 100)).toFixed(1) + '%');
    }

    setText('gananciaHoy', formatMXN(d.gananciaHoy));
    setHidden('margenChip', d.ventasTickets <= 0);
    setText('margenChip', `Margen ${d.margenTxt}`);
    setClass('margenChip', 'is-good', d.margenGood);
    setClass('margenChip', 'is-warn', !d.margenGood);
    const pctGan = Math.min(100, Math.max(0, d.margenPct));
    setVar('splitGan', 'width', pctGan.toFixed(1) + '%');
    setText('splitTxt', d.ventasTickets > 0
        ? `De cada $100 vendidos, $${pctGan.toFixed(2)} son ganancia y $${(100 - pctGan).toFixed(2)} costo`
        : 'Sin ventas todavía');
    setText('ticketsHoy', String(d.ticketsHoy));
    setText('ticketProm', formatMXN(d.ticketProm));

    pintarCajasT(d);
    pintarPagos(d);
    pintarTop(d);
    pintarHoras(d);
    pintarCancelacionesT(d);
    pintarTiendasT(d, todas);
    pintarInventarioT(d);
    pintarSemana(d);
    pintarMes(d);
    setText('pie', 'Actualizado ' + new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }));
}

function pintarCajasT(d) {
    const n = d.cajas.length;
    setHtml('cajasChip', n > 0 ? `<span class="chip is-good">${n} abierta${n !== 1 ? 's' : ''}</span>` : '<span class="chip">Cerradas</span>');
    if (n === 0) {
        setHtml('cajasCuerpo', '<p class="tile-note">No hay una caja abierta ahora. Al abrir turno aparece aquí con su efectivo esperado.</p>');
        return;
    }
    setHtml('cajasCuerpo', '<div class="hlist">' + d.cajas.map(c => {
        const t = c.turno, vig = t.vigente !== false, viejo = turnoDeOtroDiaT(t.inicio);
        return `<div class="kv"><span>${escHtml(c.tienda)}<small${viejo || !vig ? ' class="warn"' : ''}>${vig ? '' : 'Turno sin cerrar · '}${escHtml(t.usuario || '—')} · ${escHtml(desdeT(t.inicio))}</small></span>`
            + `<span>${escHtml(formatMXN(t.esperado))}<small>esperado en el cajón</small></span></div>`;
    }).join('') + '</div>');
}

function pintarCancelacionesT(d) {
    const c = d.cancelaciones;
    setHtml('cancelChip', c.cantidad > 0 ? `<span class="chip is-warn">${c.cantidad}</span>` : '<span class="chip is-good">Ninguna</span>');
    let cuerpo = c.cantidad === 0
        ? '<p class="tile-note">Hoy no se canceló ninguna venta.</p>'
        : `<div class="kv"><span>${c.cantidad} ${plural(c.cantidad, 'venta cancelada', 'ventas canceladas')}</span><span>${escHtml(formatMXN(c.monto))}</span></div>`;
    if (c.cantidad > 0 && d.sucursales.length > 1) {
        cuerpo += d.sucursales.filter(s => s.cancelaciones && num(s.cancelaciones.cantidad) > 0).map(s =>
            `<div class="kv"><span>${escHtml(s.nombre)}</span><span>${num(s.cancelaciones.cantidad)} · ${escHtml(formatMXN(s.cancelaciones.monto))}</span></div>`).join('');
    }
    cuerpo += '<p class="tile-note pie-nota">Son ventas de hoy que ya se canceló; el servidor no sabe a qué hora ni quién las canceló (eso lo ve cada tienda en su propio panel).</p>';
    setHtml('cancelCuerpo', cuerpo);
}

// Una tarjeta por tienda, con las cifras de hoy, 7 días y el mes, su caja y lo que necesita atención
function pintarTiendasT(d, todas) {
    const lista = todas.sucursales || [];
    if (lista.length === 0) {
        setHtml('tiendasLista', '<div class="tile"><p class="empty">Todavía no hay tiendas en este negocio.</p></div>');
        return;
    }
    const fila = (periodo, id) => (((todas.periodos || {})[periodo] || {}).sucursales || []).find(x => x.id === id) || {};
    setHtml('tiendasLista', lista.map(s => {
        const h = fila('hoy', s.id), sm = fila('semana', s.id), ms = fila('mes', s.id);
        const margen = num(h.ventasTickets) > 0 ? ` · margen ${(num(h.ganancia) / num(h.ventasTickets) * 100).toFixed(0)}%` : '';
        const t = s.turno;
        const caja = !t ? '<div class="tienda-caja">Sin turno abierto</div>'
            : t.vigente === false
                ? `<div class="tienda-caja warn">Turno sin cerrar de ${escHtml(t.usuario || '—')} (${escHtml(desdeT(t.inicio))})</div>`
                : `<div class="tienda-caja${turnoDeOtroDiaT(t.inicio) ? ' warn' : ''}">Caja abierta · ${escHtml(t.usuario || '—')} ${escHtml(desdeT(t.inicio))}${turnoDeOtroDiaT(t.inicio) ? ' · ¿se olvidó de cerrarla?' : ''} · esperado <b>${escHtml(formatMXN(t.esperado))}</b></div>`;
        const inv = s.inventario || {}, canc = s.cancelaciones || {};
        const chips = []
            .concat(num(inv.agotados) > 0 ? [`<span class="chip is-bad">${num(inv.agotados)} ${plural(num(inv.agotados), 'agotado', 'agotados')}</span>`] : [])
            .concat(num(inv.bajoMinimo) > 0 ? [`<span class="chip is-warn">${num(inv.bajoMinimo)} bajo mínimo</span>`] : [])
            .concat(num(s.traspasosPorRecibir) > 0 ? [`<span class="chip is-warn">${num(s.traspasosPorRecibir)} ${plural(num(s.traspasosPorRecibir), 'traspaso por recibir', 'traspasos por recibir')}</span>`] : [])
            .concat(num(canc.cantidad) > 0 ? [`<span class="chip">${num(canc.cantidad)} ${plural(num(canc.cantidad), 'cancelada hoy', 'canceladas hoy')}</span>`] : []);
        const min = minutosSinReportar(s), stale = min > STALE_TIENDA_MIN;
        const visto = min === Infinity ? 'Nunca se ha reportado' : `Reportó ${tiempoRelativo(min)}`;
        return `<button type="button" class="tile tienda" data-tienda="${escHtml(s.id)}" data-ir="hoy" aria-label="Ver ${escHtml(s.nombre)}">
            <div class="tienda-top"><span class="tienda-nombre">${escHtml(s.nombre)}<small>${escHtml(s.clave || '')}</small></span>
                <span class="kpi-sm c-ventas tienda-hoy">${escHtml(formatMXN(h.ventas))}</span></div>
            <div class="kpi-sub">hoy · ${num(h.tickets)} ${plural(num(h.tickets), 'ticket', 'tickets')}${margen}</div>
            <div class="tienda-cifras"><div><b>${escHtml(compacto(sm.ventas))}</b><span>7 días</span></div>
                <div><b>${escHtml(compacto(ms.ventas))}</b><span>este mes</span></div>
                <div><b>${escHtml(compacto(ms.ganancia))}</b><span>ganancia del mes</span></div></div>
            ${caja}
            ${chips.length ? `<div class="tienda-chips">${chips.join('')}</div>` : ''}
            <div class="tienda-visto${stale ? ' stale' : ''}">${escHtml(visto)}${stale ? ' · sus cifras pueden estar incompletas' : ''}</div></button>`;
    }).join(''));
}

function pintarInventarioT(d) {
    const i = d.inventario;
    const urg = d.urgentes.length === 0
        ? '<p class="empty">Todo en orden: nada agotado ni bajo el mínimo.</p>'
        : '<ul class="vlist">' + d.urgentes.map(u => {
            const agotado = num(u.cantidad) <= 0;
            return `<li class="venta urg">
                <span class="urg-n">${escHtml(u.nombre || '')}<small class="urg-t">${escHtml(u.sucursal || '')}</small></span>
                <span class="urg-c${agotado ? ' neg' : ''}">${escHtml(formatCantidad(u.cantidad, u.unidad))}${num(u.minimo) > 0 ? `<small>mínimo ${num(u.minimo)}</small>` : ''}</span>
                <span class="chip ${agotado ? 'is-bad' : 'is-warn'}">${agotado ? 'Agotado' : 'Bajo'}</span></li>`;
        }).join('') + '</ul>';
    const porTienda = d.sucursales.length > 1
        ? '<div class="tile"><div class="tile-label">Por tienda</div><div class="hlist">' + d.sucursales.map(s => {
            const inv = s.inventario || {};
            return `<div class="kv"><span>${escHtml(s.nombre)}</span><span>${num(inv.agotados)} ${plural(num(inv.agotados), 'agotado', 'agotados')} · ${num(inv.bajoMinimo)} bajo mínimo</span></div>`;
        }).join('') + '</div></div>' : '';
    setHtml('invCuerpo',
        `<div class="grid2">
            <div class="tile"><div class="tile-label">Agotados</div><div class="kpi-value kpi-sm ${i.agotados > 0 ? 'c-alerta' : 'c-ganancia'}">${i.agotados}</div><div class="kpi-sub">productos sin existencia</div></div>
            <div class="tile"><div class="tile-label">Bajo mínimo</div><div class="kpi-value kpi-sm ${i.bajoMinimo > 0 ? 'c-alerta' : 'c-ganancia'}">${i.bajoMinimo}</div><div class="kpi-sub">por reponer pronto</div></div>
        </div>
        ${porTienda}
        <div class="tile"><div class="tile-label">Reponer primero</div>${urg}
            <p class="tile-note pie-nota">Agotado es un producto con inventario que no tiene existencia en esa tienda, incluidos los que esa tienda nunca ha surtido (el catálogo es común a todas).</p></div>`);
}

// ── Render y refresco ───────────────────────────────────────────
function renderT() {
    const todas = T.datos.todas;
    if (!todas) return;
    if (T.estructura !== FIRMA_DOM_T) { montarT(); T.estructura = FIRMA_DOM_T; }
    if (T.sel !== 'todas' && !(todas.sucursales || []).some(s => s.id === T.sel)) { T.sel = 'todas'; guardarSel(); }
    pintarSelectorT(todas);
    const resp = T.datos[T.sel];
    const hoja = document.querySelector('.sheet');
    if (hoja) hoja.classList.toggle('cargando', !resp);
    if (!resp) { actualizarBanner(); return; }
    const d = derivarT(resp, todas);
    ultimoD = d;
    pintarT(d, todas);
    actualizarBanner();
}

// ── Compartir ───────────────────────────────────────────────────
function textoResumenT(d, todas) {
    const dinero = c => formatMXN(c).replace(/\u202f/g, ' ');
    const hoy = ahoraMX();
    const fecha = new Date(hoy.clave + 'T12:00:00Z').toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
    const unaTienda = T.sel !== 'todas' && d.sucursales.length === 1;
    const L = [`*${d.negocio}*`, `Resumen del ${fecha}`, unaTienda ? `Tienda: ${d.sucursales[0].nombre}` : 'Todas las tiendas', ''];
    L.push(`Ingresos: ${dinero(d.ingresosHoy)}` + (d.abonosHoy > 0 ? ` (ventas ${dinero(d.ventasTickets)} + apartados ${dinero(d.abonosHoy)})` : ''));
    L.push(`Ganancia: ${dinero(d.gananciaHoy)}` + (d.ventasTickets > 0 ? ` (margen ${d.margenTxt})` : ''));
    L.push(`Tickets: ${d.ticketsHoy}` + (d.ticketsHoy > 0 ? ` · promedio ${dinero(d.ticketProm)}` : ''));
    if (!unaTienda && (todas.sucursales || []).length > 1) {
        const hoyFilas = ((todas.periodos || {}).hoy || {}).sucursales || [];
        L.push('', 'Por tienda:');
        hoyFilas.forEach(s => L.push(`• ${s.nombre}: ${dinero(s.ventas)} (${num(s.tickets)} ${plural(num(s.tickets), 'ticket', 'tickets')})`));
    }
    if (d.pagos.hay && d.pagos.total > 0) {
        L.push('', 'Cobros: ' + d.pagos.partes.filter(x => x.v > 0).map(x => `${x.label.toLowerCase()} ${dinero(x.v)}`).join(' · '));
    }
    const top = d.top.hay ? d.top.hoy.slice(0, 3) : [];
    if (top.length) {
        L.push('', 'Lo más vendido:');
        top.forEach((x, i) => L.push(`${i + 1}. ${x.nombre} · ${dinero(x.importe)}`));
    }
    if (d.cajas.length) {
        L.push('', 'Cajas abiertas:');
        d.cajas.forEach(c => L.push(`• ${c.tienda}: ${dinero(c.turno.esperado)} esperado`));
    }
    return L.join('\n');
}

async function compartirT() {
    if (!ultimoD || !T.datos.todas) return;
    const texto = textoResumenT(ultimoD, T.datos.todas);
    if (navigator.share) {
        try { await navigator.share({ text: texto }); return; }
        catch (e) { if (e && e.name === 'AbortError') return; /* el sistema no pudo: se cae a WhatsApp */ }
    }
    window.open('https://wa.me/?text=' + encodeURIComponent(texto), '_blank', 'noopener');
}

// ── Clics ───────────────────────────────────────────────────────
function alClicT(ev) {
    const t = ev.target.closest('[data-tienda],[data-act]');
    if (t) {
        if (t.hasAttribute('data-tienda')) { elegirTienda(t.getAttribute('data-tienda'), t.getAttribute('data-ir') === 'hoy'); return; }
        const act = t.getAttribute('data-act');
        if (act === 'refrescar') { fetchT(false); return; }
        if (act === 'salir') { cerrarSesionT(); return; }
        if (act === 'compartir') { compartirT(); return; }
    }
    alClic(ev);   // pestañas, subpestañas, columnas de las gráficas, «lo más vendido» y el tema
}

// ── Arranque ────────────────────────────────────────────────────
function showEnlaceRotoT() {
    document.getElementById('app').innerHTML = `
    <header class="top"><div class="top-in">
        <div class="bar">${MARCA}</div>
        <span class="kicker">Panel de tus tiendas</span>
        <h1>Enlace no válido</h1>
    </div></header>
    <main class="sheet"><div class="sheet-in">
        <div class="error-box">
            <strong>Revisa el enlace</strong>
            <p>Este enlace no es el del panel de tus tiendas. Cópialo otra vez desde Configuración &gt; Panel móvil, en el programa de tu tienda.</p>
        </div>
    </div></main>`;
}

(function iniciarTiendas() {
    const crudo = getHardwareId();
    if (!crudo || !/^T-/.test(crudo)) return;    // un Hardware ID es el panel de una sola tienda: lo atiende dashboard.js
    const id = idTiendas();
    if (!id) { showEnlaceRotoT(); return; }
    T.id = id;
    T.sel = selGuardada();

    const app = document.getElementById('app');
    app.addEventListener('click', alClicT);
    app.addEventListener('submit', ev => { if (ev.target && ev.target.matches('[data-k="pinForm"]')) enviarPinT(ev); });
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const cambio = () => { if (!temaGuardado()) pintarTema(); };
        if (mq.addEventListener) mq.addEventListener('change', cambio);
    }
    instalarManifest(id);
    registrarServiceWorker();

    if (!tokenT()) { showPinT(''); return; }

    // Lo guardado de hoy se muestra de inmediato mientras llega lo nuevo
    const guardado = leerCacheT();
    if (guardado) {
        T.datos = guardado.datos;
        desdeCache = { t: guardado.t };
        renderT();
    }
    fetchT(!guardado);
    T.refreshTimer = setInterval(() => fetchT(false), REFRESH_MS);
    bannerTimer = setInterval(actualizarBanner, 30000);

    document.addEventListener('visibilitychange', () => { if (!document.hidden && tokenT()) fetchT(false); });
    window.addEventListener('online', () => { if (tokenT()) fetchT(false); });
    window.addEventListener('offline', actualizarBanner);
})();
