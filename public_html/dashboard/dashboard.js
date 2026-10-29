// ────────────────────────────────────────────────────────────────
// Antiparticula Dashboard — PWA Frontend
//
// El refresco de 60s NO reconstruye el DOM. `montar()` arma la
// estructura una sola vez y `pintar()` actualiza textos y listas en
// su lugar, de modo que no se pierde el scroll ni parpadea nada cada
// minuto. Las gráficas son HTML/CSS propio (sin librerías): se ven
// igual sin conexión y heredan el tema claro u oscuro.
//
// Pestañas: Hoy · Caja · Inventario · Historial (Semana | Mes | Año).
// Con el POS anterior a la 3.3.0 no llega `extra` (pagos, caja,
// cancelaciones, inventario): esas partes dicen que hay que actualizar
// el POS en vez de esconderse.
// ────────────────────────────────────────────────────────────────

const API_BASE   = '/get_dashboard_data.php';
const API_AUTH   = '/dashboard_auth.php';
const WHATSAPP_VENTAS = '525584323945';   // el mismo WhatsApp de ventas del sitio
const REFRESH_MS = 60 * 1000;
const STALE_MIN  = 30;            // minutos sin sync del POS => badge rojo
const OFFLINE_MS = 3 * 60 * 1000; // sin respuesta del servidor => banner
const TZ         = 'America/Mexico_City'; // la misma que usa el servidor para "hoy"
const FIRMA_DOM  = 'v6';
const TURNO_LARGO_H = 14;         // un turno abierto más de esto merece un aviso

const MESES   = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
const UNIDADES = ['Pz', 'Kg', 'L', 'Ud'];   // PRODUCTOS.PZ_O_KG: 0 pieza, 1 kilogramo, 2 litro, 3 unidad

let refreshTimer = null;
let bannerTimer  = null;
let ultimoOk     = null;   // Date.now() del último fetch exitoso
let estructura   = null;   // firma del DOM montado
let ultimoD      = null;   // últimos datos derivados (para repintar al elegir una columna)
let desdeCache   = null;   // { t } mientras se muestra lo guardado en el teléfono
let cargando     = false;
let hwIdActual   = null;

// Estado de la interfaz (sobrevive a los refrescos porque el DOM no se reconstruye)
const ui = { tab: 'hoy', sub: 'semana', sel: { horas: null, semana: null, mes: null }, masVentas: false, top: 'hoy' };
const htmlPrevio = {};     // último HTML escrito por clave: evita reescribir si no cambió

// ── Extraer hardware_id de la URL ───────────────────────────────
function getHardwareId() {
    const parts = window.location.pathname.split('/');
    for (let i = 0; i < parts.length; i++) {
        if (parts[i] === 'dashboard' && parts[i + 1]) return parts[i + 1];
    }
    return null;
}

// ── Fechas del negocio (hora de México, no la del teléfono) ─────
function claveDe(y, m, d) {
    return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

function ahoraMX(fecha) {
    const f = fecha || new Date();
    try {
        const p = {};
        new Intl.DateTimeFormat('en-CA', {
            timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
        }).formatToParts(f).forEach(x => { p[x.type] = x.value; });
        return { y: +p.year, m: +p.month, d: +p.day, h: (+p.hour) % 24, mi: +p.minute, clave: `${p.year}-${p.month}-${p.day}` };
    } catch (e) {
        return { y: f.getFullYear(), m: f.getMonth() + 1, d: f.getDate(), h: f.getHours(), mi: f.getMinutes(),
                 clave: claveDe(f.getFullYear(), f.getMonth() + 1, f.getDate()) };
    }
}

function sumarDias(clave, n) {
    const [y, m, d] = clave.split('-').map(Number);
    const t = new Date(Date.UTC(y, m - 1, d + n));
    return claveDe(t.getUTCFullYear(), t.getUTCMonth() + 1, t.getUTCDate());
}

function diasEnMes(y, m) {
    return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

function etiquetaDia(clave) {
    return new Date(clave + 'T12:00:00Z')
        .toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', timeZone: 'UTC' })
        .replace(/[.,]/g, '');
}

function nombreDiaSemana(clave) {
    return new Date(clave + 'T12:00:00Z').toLocaleDateString('es-MX', { weekday: 'long', timeZone: 'UTC' });
}

// ── Formato de dinero ───────────────────────────────────────────
// El símbolo lo elige la tienda en su POS (Configuración › Cobro: pesos o euros). Solo cambia lo que
// se ve, no convierte nada. render() lo toma de `extra.moneda` en cada foto; sin el dato (POS anterior), pesos.
let simboloMoneda = '$';
function fijarMoneda(codigo) { simboloMoneda = codigo === 'EUR' ? '€' : '$'; }

// MEDIO-05 fix: maneja NaN, null y valores negativos
function formatMXN(centavos) {
    const num = Number(centavos);
    const val = isNaN(num) ? 0 : num / 100;
    const abs = Math.abs(val);
    // U+202F (espacio fino sin salto): el signo no se separa de la cifra al partir renglón
    const formatted = simboloMoneda + '\u202f' + abs.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return val < 0 ? '-' + formatted : formatted;
}

// "$1.2k", "$12k", "$1.4M": para rótulos que no caben completos
function compacto(centavos) {
    const v = Math.max(0, Number(centavos) || 0) / 100;
    // Los umbrales son los del valor YA redondeado: 9,999.67 es "$10k", no "$10.0k"
    if (v >= 999500) return simboloMoneda + (v / 1e6).toFixed(1) + 'M';
    if (v >= 9950)   return simboloMoneda + Math.round(v / 1000) + 'k';
    if (v >= 1000)   return simboloMoneda + (v / 1e3).toFixed(1) + 'k';
    return simboloMoneda + Math.round(v);
}

// Existencias: PRODUCTOS.CANTIDAD va en milésimas. Negativo = existencia real por debajo de cero
function formatCantidad(milesimas, unidad) {
    const n = (Number(milesimas) || 0) / 1000;
    const abs = Math.abs(n);
    const s = Number.isInteger(abs) ? String(abs) : abs.toFixed(3).replace(/\.?0+$/, '');
    return (n < 0 ? '\u2212' : '') + s + ' ' + (UNIDADES[unidad] || UNIDADES[0]);
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

// ── Fecha/hora que manda el POS ─────────────────────────────────
// El POS manda "dd-MM-yyyy HH:mm:ss" (el formato de sus tablas); versiones anteriores del
// panel solo entendían ISO y mostraban la fecha cruda. Se aceptan los dos.
function parseFechaPOS(raw) {
    if (!raw) return null;
    const s = String(raw).trim();
    let m = s.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{1,2}):(\d{2})/);
    if (m) return { y: +m[1], mo: +m[2], d: +m[3], h: +m[4], mi: +m[5] };
    m = s.match(/^(\d{2})-(\d{2})-(\d{4})[T ](\d{1,2}):(\d{2})/);
    if (m) return { y: +m[3], mo: +m[2], d: +m[1], h: +m[4], mi: +m[5] };
    return null;
}

function horaLegible(h, mi) {
    return new Date(2000, 0, 1, h, mi).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
}

// BAJO-03 fix: el POS manda turno_inicio en formatos variados. Antes se mostraba el string crudo.
function formatFechaHora(raw) {
    if (!raw) return '—';
    const s = String(raw).trim();

    const p = parseFechaPOS(s);
    if (p && p.mo >= 1 && p.mo <= 12 && p.d >= 1 && p.d <= 31 && p.h <= 23 && p.mi <= 59) {
        const hoy = ahoraMX();
        const mismoDia = p.y === hoy.y && p.mo === hoy.m && p.d === hoy.d;
        const hora = horaLegible(p.h, p.mi);
        return mismoDia ? hora : `${p.d} ${MESES[p.mo - 1]}, ${hora}`;
    }

    const soloHora = s.match(/^(\d{1,2}):(\d{2})/);
    if (soloHora) {
        const hh = +soloHora[1], mm = +soloHora[2];
        if (hh >= 0 && hh <= 23 && mm >= 0 && mm <= 59) return horaLegible(hh, mm);
    }
    return s;   // formato desconocido: mostrarlo tal cual
}

// Minutos que lleva abierto un turno (los dos relojes, en hora de México). null si no se puede saber.
function minutosDesde(raw, ahora) {
    const p = parseFechaPOS(raw);
    if (!p) return null;
    const ini = Date.UTC(p.y, p.mo - 1, p.d, p.h, p.mi);
    const fin = Date.UTC(ahora.y, ahora.m - 1, ahora.d, ahora.h, ahora.mi || 0);
    return Math.max(0, Math.round((fin - ini) / 60000));
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
    const ahora   = ahoraMX();
    const minutos = Math.max(0, Number(data.hace_minutos) || 0);

    const ventasHoy     = Number(data.ventas_hoy)    || 0;
    const gananciaHoy   = Number(data.ganancia_hoy)  || 0;
    const ticketsHoy    = Number(data.tickets_hoy)   || 0;
    const abonosHoy     = Number(data.abonos_hoy)    || 0;
    const abonosTurno   = Number(data.abonos_turno)  || 0;
    const ventasTurno   = Number(data.ventas_turno)  || 0;
    const ticketsTurno  = Number(data.tickets_turno) || 0;

    const ingresosHoy = ventasHoy + abonosHoy;
    const margenPct   = ventasHoy > 0 ? (gananciaHoy / ventasHoy) * 100 : 0;
    const semana      = datosSemana(data.historico_semana, ahora.clave);
    const anual       = datosAnuales(data.historico_anual, ahora);
    const margenAnualPct = anual.totalVentas > 0 ? (anual.totalGanancia / anual.totalVentas) * 100 : 0;

    // Historial diario (hasta 100 días, lo manda el servidor). Si falta, solo se pierden las comparaciones.
    const dias = mapaDias(data.historico_dias);
    const extra = data.extra && typeof data.extra === 'object' ? data.extra : null;

    const turnoAbierto = !!Number(data.turno_abierto);
    const minAbierto   = turnoAbierto ? minutosDesde(data.turno_inicio, ahora) : null;
    const pIni         = turnoAbierto ? parseFechaPOS(data.turno_inicio) : null;
    const desdeAyer    = !!(pIni && claveDe(pIni.y, pIni.mo, pIni.d) !== ahora.clave);
    // «desde ayer» solo si de verdad fue ayer; si lleva más días, se dice la fecha
    const turnoDesde   = !pIni ? '' : (claveDe(pIni.y, pIni.mo, pIni.d) === sumarDias(ahora.clave, -1)
        ? 'ayer' : `el ${pIni.d} ${MESES[pIni.mo - 1]}`);

    const mes    = datosMes(dias, ingresosHoy, gananciaHoy, ahora);
    const gastos = datosGastos(extra);

    return {
        negocio:      data.negocio || 'Antiparticula POS',
        minutos,
        stale:        minutos > STALE_MIN,
        protegido:    !!data.protegido,

        ingresosHoy,
        ventasHoy, gananciaHoy, ticketsHoy, abonosHoy,
        ticketProm:   ticketsHoy > 0 ? Math.round(ventasHoy / ticketsHoy) : 0,
        margenPct,
        margenTxt:    margenPct.toFixed(1) + '%',
        margenGood:   margenPct >= 20,
        costoHoy:     Math.max(0, ventasHoy - gananciaHoy),
        costoPct:     (100 - margenPct).toFixed(1),
        vsAyer:       comparativoAyer(ingresosHoy, semana, ahora.clave),
        vsSemanaPasada: comparativoSemanaPasada(ingresosHoy, dias, ahora.clave),

        turnoUsuario: data.turno_usuario || '—',
        turnoInicio:  formatFechaHora(data.turno_inicio),
        turnoAbierto,
        turnoAlerta:  turnoAbierto && minAbierto !== null && (desdeAyer || minAbierto >= TURNO_LARGO_H * 60),
        turnoDesdeAyer: desdeAyer,
        turnoDesde,
        turnoMinutos: minAbierto,
        ingresosTurno: ventasTurno + abonosTurno,
        ticketsTurno, abonosTurno,

        extra,
        pagos:        datosPagos(extra),
        caja:         datosCaja(extra),
        cancel:       datosCancelaciones(extra),
        inv:          datosInventario(extra),
        sesion:       datosSesion(extra, turnoAbierto),
        top:          datosTop(extra),
        cajeros:      datosCajeros(extra),
        apartados:    datosApartados(extra),
        clientes:     datosClientes(extra),
        presupuestos: datosPresupuestos(extra),
        gastos,
        meta:         datosMeta(extra, ingresosHoy),

        horas:        datosHoras(data, ahora),
        semana,
        semanaAnterior: comparativoSemanaAnterior(semana, dias, ahora.clave),
        mes,
        utilidad:     datosUtilidad(mes, gastos),
        ventas:       Array.isArray(data.ultimas_ventas) ? data.ultimas_ventas : [],

        anual,
        hayAnual:      anual.totalVentas > 0,
        margenAnualTxt: margenAnualPct.toFixed(1) + '%',
        margenAnualGood: margenAnualPct >= 20,
    };
}

function mapaDias(lista) {
    const m = {};
    (Array.isArray(lista) ? lista : []).forEach(r => {
        if (r && r.fecha) m[r.fecha] = r;
    });
    return m;
}

// ── Hoy contra ayer ─────────────────────────────────────────────
// Se compara lo de hoy HASTA AHORA con el total de ayer completo (el POS solo manda
// totales por día): sirve para ver si hoy va a alcanzarlo.
function comparativoAyer(ingresosHoy, semana, hoyClave) {
    const ayer = semana.mapa[sumarDias(hoyClave, -1)] || 0;
    if (ayer <= 0) return null;
    const ratio = ingresosHoy / ayer;
    return {
        ayer, ratio,
        supera: ingresosHoy >= ayer,
        pct:    Math.round(ratio * 100),
        extra:  Math.round((ratio - 1) * 100),
    };
}

// El mismo día de la semana pasada (hace 7 días), completo. Necesita historico_dias.
function comparativoSemanaPasada(ingresosHoy, dias, hoyClave) {
    const clave = sumarDias(hoyClave, -7);
    const r = dias[clave];
    if (!r || !(Number(r.ventas) > 0)) return null;
    const total = Number(r.ventas);
    return { clave, total, dia: nombreDiaSemana(clave), supera: ingresosHoy >= total, pct: Math.round(ingresosHoy / total * 100) };
}

// ── Pagos de hoy (extra.pagos_hoy) ──────────────────────────────
function datosPagos(extra) {
    if (!extra) return { hay: false };
    const p = extra.pagos_hoy;
    if (!p) return { hay: false };
    const efectivo = Math.max(0, Number(p.efectivo) || 0);
    const tarjeta  = Math.max(0, Number(p.tarjeta) || 0);
    const transf   = Math.max(0, Number(p.transferencia) || 0);
    const total    = efectivo + tarjeta + transf;
    const parte = (clave, label, v) => ({ clave, label, v, pct: total > 0 ? v / total * 100 : 0 });
    return {
        hay: true, total, comision: Math.max(0, Number(p.comision) || 0),
        partes: [parte('efe', 'Efectivo', efectivo), parte('tar', 'Tarjeta', tarjeta), parte('tra', 'Transferencia', transf)],
    };
}

// ── Caja del turno (extra.caja) ─────────────────────────────────
// estado: 'sin-datos' (POS anterior) | 'cerrado' (no hay turno abierto) | 'abierto'
function datosCaja(extra) {
    if (!extra || !('caja' in extra)) return { estado: 'sin-datos' };
    const c = extra.caja;
    if (!c) return { estado: 'cerrado' };
    const n = k => Number(c[k]) || 0;
    return {
        estado: 'abierto',
        esperado: n('esperado'), fondo: n('fondo'), efectivoVentas: n('efectivo_ventas'),
        ingresos: n('ingresos'), retiros: n('retiros'), tarjeta: n('tarjeta'), transferencia: n('transferencia'),
    };
}

function datosCancelaciones(extra) {
    if (!extra || !extra.cancelaciones_hoy) return { hay: false };
    const c = extra.cancelaciones_hoy;
    const canc = Number(c.cancelaciones) || 0, dev = Number(c.devoluciones) || 0;
    return {
        hay: true, canc, dev,
        montoCanc: Number(c.monto_cancelado) || 0, montoDev: Number(c.monto_devuelto) || 0,
        usuarios: (Array.isArray(c.por_usuario) ? c.por_usuario : []).map(u => ({
            usuario: u.usuario || 'Sin usuario', canc: Number(u.cancelaciones) || 0, dev: Number(u.devoluciones) || 0 })),
        total: canc + dev,
    };
}

function datosInventario(extra) {
    if (!extra || !extra.inventario) return { hay: false };
    const i = extra.inventario;
    return {
        hay: true,
        agotados: Number(i.agotados) || 0, bajo: Number(i.bajo_minimo) || 0, valor: Number(i.valor_costo) || 0,
        urgentes: (Array.isArray(i.urgentes) ? i.urgentes : []).map(u => ({
            nombre: u.nombre || '', cantidad: Number(u.cantidad) || 0, minimo: Number(u.minimo) || 0, unidad: Number(u.unidad) || 0 })),
    };
}

// ── Etapa 2: sesión, más vendidos, cajeros, por cobrar, gastos y meta ──
// Cada bloque es opcional: un POS que no manda la clave deja la tarjeta oculta, sin avisos ni ceros inventados.

// «a las 6:05 p. m.» · «ayer a las …» · «el 14 Oct a las …». Vacío si la fecha no se entiende.
function textoMomento(raw) {
    const p = parseFechaPOS(raw);
    if (!p || p.mo < 1 || p.mo > 12 || p.d < 1 || p.d > 31 || p.h > 23 || p.mi > 59) return '';
    const hoy = ahoraMX();
    const clave = claveDe(p.y, p.mo, p.d);
    const hora = horaLegible(p.h, p.mi);
    if (clave === hoy.clave) return `a las ${hora}`;
    if (clave === sumarDias(hoy.clave, -1)) return `ayer a las ${hora}`;
    return `el ${p.d} ${MESES[p.mo - 1]} a las ${hora}`;
}

// El POS salió a la pantalla de inicio de sesión dejando el turno abierto (extra.sesion)
function datosSesion(extra, turnoAbierto) {
    const s = extra && extra.sesion;
    if (!turnoAbierto || !s || s.cerrada !== true) return { hay: false };
    return { hay: true, cuando: textoMomento(s.desde) };
}

// Lo más vendido por importe: { hoy, semana, mes }, cada uno con hasta 5 renglones
const TOP_VENTANAS = [['hoy', 'Hoy'], ['semana', '7 días'], ['mes', 'Mes']];

function datosTop(extra) {
    const t = extra && extra.top_productos;
    if (!t || typeof t !== 'object') return { hay: false };
    const lista = k => (Array.isArray(t[k]) ? t[k] : []).map(p => ({
        nombre:   String((p && p.nombre) || '').trim() || 'Sin nombre',
        importe:  Math.max(0, Number(p && p.importe)  || 0),
        cantidad: Math.max(0, Number(p && p.cantidad) || 0),
        unidad:   Number(p && p.unidad) || 0,
    }));
    return { hay: true, hoy: lista('hoy'), semana: lista('semana'), mes: lista('mes') };
}

// Ventas de hoy por cajero. Con un solo cajero no dice nada que el total no diga: no se muestra.
function datosCajeros(extra) {
    if (!extra || !Array.isArray(extra.por_cajero)) return { hay: false };
    const items = extra.por_cajero.map(c => ({
        cajero:  String((c && c.cajero) || '').trim() || 'Sin cajero',
        tickets: Math.max(0, Number(c && c.tickets) || 0),
        ventas:  Math.max(0, Number(c && c.ventas)  || 0),
    }));
    return { hay: items.length >= 2, items };
}

// Apartados sin entregar y lo que falta por cobrarles. Sin ninguno, la tarjeta no aparece.
function datosApartados(extra) {
    const a = extra && extra.apartados;
    if (!a || typeof a !== 'object') return { hay: false };
    const activos = Math.max(0, Number(a.activos) || 0), porCobrar = Math.max(0, Number(a.por_cobrar) || 0);
    return { hay: activos > 0 || porCobrar > 0, activos, porCobrar, vencidos: Math.max(0, Number(a.vencidos) || 0) };
}

// Clientes con fiado (POS con «Clientes y crédito», 2026-10-28): lo que deben y cuántos. Sin nadie que deba ni abonos de hoy, la tarjeta no aparece.
function datosClientes(extra) {
    const c = extra && extra.clientes;
    if (!c || typeof c !== 'object') return { hay: false };
    const n = v => Math.max(0, Number(v) || 0);
    const porCobrar = n(c.por_cobrar), abonadoHoy = n(c.abonado_hoy);
    return { hay: porCobrar > 0 || abonadoHoy > 0, porCobrar, deben: n(c.deben), atrasado: n(c.atrasado),
             atrasados: n(c.atrasados), abonadoHoy, abonadoMes: n(c.abonado_mes) };
}

function datosPresupuestos(extra) {
    const p = extra && extra.presupuestos;
    if (!p || typeof p !== 'object') return { hay: false };
    const vigentes = Math.max(0, Number(p.vigentes) || 0);
    return { hay: vigentes > 0, vigentes, monto: Math.max(0, Number(p.monto) || 0),
             porVencer: Math.max(0, Number(p.por_vencer) || 0), vencidos: Math.max(0, Number(p.vencidos) || 0) };
}

function datosGastos(extra) {
    const g = extra && extra.gastos;
    if (!g || typeof g !== 'object') return { hay: false };
    return { hay: true, hoy: Math.max(0, Number(g.hoy) || 0), mes: Math.max(0, Number(g.mes) || 0) };
}

// Ganancia del mes menos los gastos registrados en el POS. No se llama «neta»: lo que no se registre allá no se descuenta.
function datosUtilidad(mes, gastos) {
    if (!gastos.hay || !mes.hayFilas) return { hay: false };
    return { hay: true, ganancia: mes.ganancia, gastos: gastos.mes, valor: mes.ganancia - gastos.mes };
}

// Meta diaria (la define el dueño en el POS). Se compara con los ingresos de hoy: ventas y abonos.
function datosMeta(extra, ingresosHoy) {
    const meta = Math.max(0, Number(extra && extra.meta_diaria) || 0);
    if (!(meta > 0)) return { hay: false };
    const ratio = ingresosHoy / meta;
    return { hay: true, meta, ratio, pct: Math.floor(ratio * 100), cumplida: ratio >= 1 };
}

// ── Ventas por hora ─────────────────────────────────────────────
// Prefiere `ventas_por_hora`, que el servidor agrega desde el detalle
// acumulado de todo el día (ver sql/001_ventas_por_hora.sql).
// `ultimas_ventas` solo trae lo que mandó el POS en el último envío, así
// que es el respaldo mientras la migración no esté aplicada.
function datosHoras(data, ahora) {
    const buckets = {};
    const vph = Array.isArray(data.ventas_por_hora) ? data.ventas_por_hora : [];

    if (vph.length > 0) {
        vph.forEach(h => {
            const hh = Number(h.hora);
            if (Number.isInteger(hh) && hh >= 0 && hh <= 23) {
                buckets[hh] = { total: Number(h.total_centavos) || 0, tickets: Number(h.tickets) || 0 };
            }
        });
    } else {
        (Array.isArray(data.ultimas_ventas) ? data.ultimas_ventas : []).forEach(v => {
            const hh = parseInt(String(v.hora || '').split(':')[0], 10);
            if (isNaN(hh) || hh < 0 || hh > 23) return;
            const b = buckets[hh] || (buckets[hh] = { total: 0, tickets: 0 });
            b.total += Number(v.total_centavos) || 0;
            b.tickets += 1;
        });
    }

    const conVenta = Object.keys(buckets).map(Number).sort((a, b) => a - b);
    if (conVenta.length === 0) return { items: [], pico: null };

    // Rango completo, de la primera venta hasta la hora actual: las horas
    // intermedias sin venta salen en cero y no desaparecen del eje.
    const desde = conVenta[0];
    const hasta = Math.max(conVenta[conVenta.length - 1], Math.min(23, ahora.h));
    let pico = null;
    const items = [];
    for (let h = desde; h <= hasta; h++) {
        const b = buckets[h] || { total: 0, tickets: 0 };
        const it = { key: String(h), hora: h, label: String(h).padStart(2, '0'), valor: b.total, tickets: b.tickets };
        if (b.total > 0 && (!pico || b.total > pico.valor)) pico = it;
        items.push(it);
    }
    items.forEach(it => { it.destacado = (it === pico); });
    return { items, pico };
}

// ── Últimos 7 días ──────────────────────────────────────────────
// MEDIO-09 fix: siempre generar los 7 dias, rellenar con 0 los dias sin datos
function datosSemana(historico, hoyClave) {
    const mapa = {};
    (Array.isArray(historico) ? historico : []).forEach(d => {
        if (d && d.fecha) mapa[d.fecha] = (mapa[d.fecha] || 0) + (Number(d.total_centavos) || 0);
    });
    const dias = [];
    for (let i = 6; i >= 0; i--) {
        const clave = sumarDias(hoyClave, -i);
        dias.push({ clave, label: etiquetaDia(clave), total: mapa[clave] || 0, esHoy: i === 0 });
    }
    const conVenta = dias.filter(x => x.total > 0);
    const total    = dias.reduce((s, x) => s + x.total, 0);
    const mejor    = conVenta.reduce((m, x) => (!m || x.total > m.total ? x : m), null);
    return {
        dias, mapa, total, mejor,
        promedio: conVenta.length ? Math.round(total / conVenta.length) : 0,
        diasConVenta: conVenta.length,
    };
}

// Los 7 días anteriores a esta semana (hace 7 a 13 días), de historico_dias. null si no hay.
function comparativoSemanaAnterior(semana, dias, hoyClave) {
    let total = 0, hay = 0;
    for (let i = 7; i <= 13; i++) {
        const r = dias[sumarDias(hoyClave, -i)];
        if (r) { total += Number(r.ventas) || 0; hay++; }
    }
    if (hay === 0 || total <= 0) return null;
    return { total, pct: Math.round((semana.total / total - 1) * 100) };
}

// ── Este mes contra el anterior (mismos días) ───────────────────
// Necesita historico_dias. Hoy se toma de la foto en vivo, no del renglón guardado: es más fresca.
function datosMes(dias, ingresosHoy, gananciaHoy, ahora) {
    const pref = `${ahora.y}-${String(ahora.m).padStart(2, '0')}`;
    const items = [];
    let total = 0, ganancia = 0, conVenta = 0, hayFilas = false;
    for (let d = 1; d <= ahora.d; d++) {
        const clave = `${pref}-${String(d).padStart(2, '0')}`;
        const r = dias[clave];
        if (r) hayFilas = true;
        const esHoy = d === ahora.d;
        const v = esHoy ? ingresosHoy : (r ? Number(r.ventas) || 0 : 0);
        const g = esHoy ? gananciaHoy : (r ? Number(r.ganancia) || 0 : 0);
        total += v; ganancia += g;
        if (v > 0) conVenta++;
        items.push({ key: clave, label: String(d), valor: v, esHoy, destacado: esHoy, hoy: esHoy, aria: `${d}: ${formatMXN(v)}` });
    }
    // Mes anterior, solo hasta el mismo día del mes
    const py = ahora.m === 1 ? ahora.y - 1 : ahora.y, pm = ahora.m === 1 ? 12 : ahora.m - 1;
    const tope = Math.min(ahora.d, diasEnMes(py, pm));
    let anterior = 0, hayAnterior = false;
    for (let d = 1; d <= tope; d++) {
        const r = dias[`${py}-${String(pm).padStart(2, '0')}-${String(d).padStart(2, '0')}`];
        if (r) { anterior += Number(r.ventas) || 0; hayAnterior = true; }
    }
    return {
        items, total, ganancia, conVenta, hayFilas: hayFilas || ingresosHoy > 0,
        anterior: hayAnterior && anterior > 0 ? { total: anterior, pct: Math.round((total / anterior - 1) * 100), nombre: MESES[pm - 1] } : null,
        nombre: MESES[ahora.m - 1],
    };
}

// ── Datos anuales ───────────────────────────────────────────────
// Solo se listan los meses hasta el actual: los futuros no aportan nada.
function datosAnuales(historico, ahora) {
    const year = ahora.y;
    const mapa = {};
    (Array.isArray(historico) ? historico : []).forEach(d => {
        if (d && d.mes) mapa[d.mes] = { v: Number(d.ventas_centavos) || 0, g: Number(d.ganancia_centavos) || 0 };
    });
    let totalVentas = 0, totalGanancia = 0;
    const meses = [];
    for (let m = 1; m <= 12; m++) {
        const key = `${year}-${String(m).padStart(2, '0')}`;
        const v = mapa[key] ? mapa[key].v : 0;
        const g = mapa[key] ? mapa[key].g : 0;
        totalVentas   += v;
        totalGanancia += g;
        if (m <= ahora.m) meses.push({ nombre: MESES[m - 1], v, g, enCurso: m === ahora.m });
    }
    const mejor = meses.reduce((b, x) => (x.v > 0 && (!b || x.v > b.v) ? x : b), null);
    return { year, meses, totalVentas, totalGanancia, mejor };
}

// ────────────────────────────────────────────────────────────────
// Iconos
// ────────────────────────────────────────────────────────────────
const SVG = (d) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
const ICONO_SOL    = SVG('<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>');
const ICONO_LUNA   = SVG('<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>');
const ICONO_ACTUAL = SVG('<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>');

const MARCA = `<div class="brand"><img src="/dashboard/icon-192.png" alt="" width="32" height="32"><span>Antipartícula</span></div>`;

const AVISO_POS_ANTERIOR = 'Esta parte necesita la versión 3.3.0 o más nueva del punto de venta. Actualiza el programa en la computadora de la tienda y aparecerá aquí.';

// ────────────────────────────────────────────────────────────────
// Montaje del DOM (una sola vez)
// ────────────────────────────────────────────────────────────────
function montar() {
    document.getElementById('app').innerHTML = `
    <header class="top"><div class="top-in">
        <div class="bar">
            ${MARCA}
            <button type="button" class="icon-btn" data-act="tema" data-k="btnTema" aria-label="Cambiar entre tema claro y oscuro"></button>
            <button type="button" class="icon-btn" data-act="refrescar" data-k="btnRefrescar" aria-label="Actualizar ahora">${ICONO_ACTUAL}</button>
        </div>

        <span class="kicker">Panel de ventas</span>
        <h1 data-k="negocio"></h1>
        <button type="button" class="sync" data-k="syncBadge" data-act="refrescar" aria-live="polite">
            <span class="dot"></span><span data-k="sync"></span>
        </button>

        <section class="hero" aria-label="Ingresos de hoy">
            <div class="hero-label">Ingresos de hoy</div>
            <div class="hero-value" data-k="ingresosHoy"></div>
            <div class="hero-sub" data-k="ingresosSub"></div>
            <div class="vs" data-k="vs" hidden>
                <div class="vs-line"><span data-k="vsTexto"></span><span data-k="vsAyer"></span></div>
                <div class="vs-track"><div class="vs-fill" data-k="vsFill"></div></div>
            </div>
            <div class="vs-semana" data-k="vsSemana" hidden></div>
            <div class="vs vs-meta" data-k="meta" hidden>
                <div class="vs-line"><span data-k="metaTexto"></span><span data-k="metaValor"></span></div>
                <div class="vs-track"><div class="vs-fill" data-k="metaFill"></div></div>
            </div>
        </section>
    </div></header>

    <main class="sheet"><div class="sheet-in">
        <div class="tabs">
            <div class="seg" role="tablist" aria-label="Sección">
                <button type="button" role="tab" id="tab-hoy"        data-tab="hoy"        aria-controls="panel-hoy"        aria-selected="true">Hoy</button>
                <button type="button" role="tab" id="tab-caja"       data-tab="caja"       aria-controls="panel-caja"       aria-selected="false">Caja</button>
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

            <div class="tile" data-k="cajerosTile" hidden>
                <div class="tile-label">Ventas por cajero hoy</div>
                <div class="hlist" data-k="cajerosLista"></div>
            </div>

            <div class="tile">
                <div class="tile-label">Ventas por hora</div>
                <div data-k="horasGrafica"></div>
                <div class="readout" data-k="horasLectura"></div>
            </div>

            <div class="tile">
                <div class="tile-head">
                    <div class="tile-label">Turno actual</div>
                    <span data-k="turnoEstado"></span>
                </div>
                <div class="kv"><span>Cajero</span><span data-k="turnoUsuario"></span></div>
                <div class="kv"><span>Apertura</span><span data-k="turnoInicio"></span></div>
                <div class="kv" data-k="sesionFila" hidden><span>Sesión</span><span data-k="sesionTexto"></span></div>
                <div class="kv"><span>Ingresos del turno</span><span data-k="ingresosTurno"></span></div>
            </div>

            <button type="button" class="btn-ghost btn-compartir" data-act="compartir">Compartir resumen del día</button>

            <div class="tile">
                <div class="tile-label">Últimas ventas</div>
                <div data-k="ultimasVentas"></div>
            </div>
        </section>

        <!-- ═════════ CAJA ═════════ -->
        <section class="panel" id="panel-caja" role="tabpanel" aria-labelledby="tab-caja" data-panel="caja" hidden>
            <div class="tile info" data-k="sesionAviso" role="status" hidden></div>
            <div class="tile alerta" data-k="turnoAlerta" role="alert" hidden></div>
            <div data-k="cajaCuerpo" class="panel"></div>
            <div data-k="cobrarCuerpo" class="panel" hidden></div>
            <div class="tile" data-k="cancelTile">
                <div class="tile-head">
                    <div class="tile-label">Cancelaciones y devoluciones de hoy</div>
                    <span data-k="cancelChip"></span>
                </div>
                <div data-k="cancelCuerpo"></div>
            </div>
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
                <button type="button" role="tab" data-sub="anio"   aria-selected="false">Año</button>
            </div>

            <!-- Semana -->
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

            <!-- Mes -->
            <div class="panel" data-subpanel="mes" hidden>
                <div class="empty tile" data-k="mesVacio" hidden>Todavía no hay historial diario de este mes. Aparecerá conforme el punto de venta envíe sus ventas.</div>
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
                    <div class="tile" data-k="utilidadTile" hidden>
                        <div class="tile-label">Utilidad del mes</div>
                        <div class="kpi-value kpi-sm" data-k="utilidadValor"></div>
                        <div class="kpi-sub" data-k="utilidadSub"></div>
                        <p class="tile-note pie-nota">Ganancia menos los gastos registrados en el punto de venta; lo que no se registre allá no se descuenta.</p>
                    </div>
                    <div class="tile">
                        <div class="tile-label">Ventas por día del mes</div>
                        <div data-k="mesGrafica"></div>
                        <div class="readout" data-k="mesLectura"></div>
                    </div>
                </div>
            </div>

            <!-- Año -->
            <div class="panel" data-subpanel="anio" hidden>
                <div class="empty tile" data-k="anualVacio" hidden>Sin datos anuales aún. Aparecerán conforme el punto de venta envíe sus ventas.</div>

                <div data-k="anualContenido" class="panel">
                    <div class="tile">
                        <div class="tile-label" data-k="anualEtiquetaVentas"></div>
                        <div class="kpi-value c-ventas" data-k="totalVentasAnual"></div>
                        <div class="kpi-sub">ventas y apartados cobrados</div>
                    </div>
                    <div class="tile">
                        <div class="tile-head">
                            <div class="tile-label" data-k="anualEtiquetaGanancia"></div>
                            <span class="chip" data-k="margenAnualChip"></span>
                        </div>
                        <div class="kpi-value c-ganancia" data-k="totalGananciaAnual"></div>
                        <div class="kpi-sub">margen sobre ventas del año</div>
                    </div>

                    <div class="tile">
                        <div class="tile-label">Ventas por mes</div>
                        <div class="tile-note" data-k="mejorMes"></div>
                        <div class="hlist" data-k="mesesVentas"></div>
                    </div>
                    <div class="tile">
                        <div class="tile-label">Ganancia por mes</div>
                        <div class="hlist is-ganancia" data-k="mesesGanancia"></div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="foot">
            <div data-k="pie"></div>
            <div><a href="https://www.antiparticula.com/puntodeventa">Antipartícula · Punto de Venta</a></div>
            <div><button type="button" class="foot-link" data-act="salir" data-k="salir" hidden>Cerrar sesión del panel</button></div>
        </footer>
    </div></main>
    `;
    Object.keys(htmlPrevio).forEach(k => delete htmlPrevio[k]);
    aplicarTab();
    pintarTema();
}

// ────────────────────────────────────────────────────────────────
// Pintado incremental
// ────────────────────────────────────────────────────────────────
function el(key) { return document.querySelector(`[data-k="${key}"]`); }

function setText(key, value) {
    const n = el(key);
    if (n && n.textContent !== value) n.textContent = value;
}

// El HTML se compara contra lo último que escribimos, no contra innerHTML
// (el navegador lo normaliza y nunca coincidiría): si no cambió, no se toca.
function setHtml(key, value) {
    const n = el(key);
    if (!n || htmlPrevio[key] === value) return;
    htmlPrevio[key] = value;
    n.innerHTML = value;
}

function setClass(key, cls, on) {
    const n = el(key);
    if (n) n.classList.toggle(cls, on);
}

function setHidden(key, oculto) {
    const n = el(key);
    if (n) n.hidden = oculto;
}

function setVar(key, nombre, valor) {
    const n = el(key);
    if (n) n.style.setProperty(nombre, valor);
}

// Plural explícito: «cancelación» + «es» daría «cancelaciónes»
function plural(n, uno, varios) { return n === 1 ? uno : varios; }

function deltaTxt(pct) {
    return pct > 0 ? `+${pct}%` : (pct < 0 ? `\u2212${Math.abs(pct)}%` : '0%');
}

function pintar(d) {
    setText('negocio', d.negocio);

    setText('sync', d.stale
        ? `Sin datos del POS ${tiempoRelativo(d.minutos)}`
        : `POS actualizado ${tiempoRelativo(d.minutos)}`);
    setClass('syncBadge', 'stale', d.stale);
    setHidden('salir', !d.protegido);

    // ── Hoy ──
    setText('ingresosHoy', formatMXN(d.ingresosHoy));
    setClass('ingresosHoy', 'is-long', formatMXN(d.ingresosHoy).length > 13);
    setText('ingresosSub', d.abonosHoy > 0
        ? `${formatMXN(d.ventasHoy)} en ventas + ${formatMXN(d.abonosHoy)} en apartados`
        : (d.ticketsHoy > 0
            ? `${d.ticketsHoy} ticket${d.ticketsHoy !== 1 ? 's' : ''} cobrado${d.ticketsHoy !== 1 ? 's' : ''}`
            : 'Aún sin ventas hoy'));

    const vs = d.vsAyer;
    setHidden('vs', !vs);
    if (vs) {
        setText('vsTexto', vs.supera ? `Superas lo de ayer (+${vs.extra}%)` : `Llevas el ${vs.pct}% de lo de ayer`);
        setText('vsAyer', `Ayer ${formatMXN(vs.ayer)}`);
        setClass('vs', 'is-over', vs.supera);
        setVar('vsFill', 'width', Math.min(100, Math.max(0, vs.ratio * 100)).toFixed(1) + '%');
    }
    const sp = d.vsSemanaPasada;
    setHidden('vsSemana', !sp);
    if (sp) {
        setText('vsSemana', `${sp.dia.charAt(0).toUpperCase() + sp.dia.slice(1)} pasado cerró en ${formatMXN(sp.total)}`
            + (sp.supera ? ' · ya lo superas' : ` · llevas el ${sp.pct}%`));
    }

    const mt = d.meta;
    setHidden('meta', !mt.hay);
    if (mt.hay) {
        setText('metaTexto', mt.cumplida ? 'Meta del día cumplida' : `Meta del día: llevas el ${mt.pct}%`);
        setText('metaValor', formatMXN(mt.meta));
        setClass('meta', 'is-over', mt.cumplida);
        setVar('metaFill', 'width', Math.min(100, Math.max(0, mt.ratio * 100)).toFixed(1) + '%');
    }

    setText('gananciaHoy', formatMXN(d.gananciaHoy));
    setHidden('margenChip', d.ventasHoy <= 0);
    setText('margenChip', `Margen ${d.margenTxt}`);
    setClass('margenChip', 'is-good', d.margenGood);
    setClass('margenChip', 'is-warn', !d.margenGood);
    const pctGan = Math.min(100, Math.max(0, d.margenPct));
    setVar('splitGan', 'width', pctGan.toFixed(1) + '%');
    setText('splitTxt', d.ventasHoy > 0
        ? `De cada ${simboloMoneda}100 vendidos, ${simboloMoneda}${pctGan.toFixed(2)} son ganancia y ${simboloMoneda}${(100 - pctGan).toFixed(2)} costo`
        : 'Sin ventas todavía');

    setText('ticketsHoy',  String(d.ticketsHoy));
    setText('ticketProm',  formatMXN(d.ticketProm));

    pintarPagos(d);
    pintarTop(d);
    pintarCajeros(d);
    pintarHoras(d);

    setText('turnoUsuario', d.turnoUsuario);
    setText('turnoInicio',  d.turnoInicio);
    setHtml('ingresosTurno', escHtml(formatMXN(d.ingresosTurno)) + `<small>${d.ticketsTurno} ticket${d.ticketsTurno !== 1 ? 's' : ''}`
        + (d.abonosTurno > 0 ? ` + ${escHtml(formatMXN(d.abonosTurno))} en apartados` : '') + '</small>');
    const chipTurno = !d.turnoAbierto
        ? '<span class="chip">Cerrado</span>'
        : (d.turnoAlerta ? `<span class="chip is-warn">${d.turnoDesdeAyer ? 'Abierto desde ' + escHtml(d.turnoDesde) : 'Abierto hace ' + Math.round(d.turnoMinutos / 60) + ' h'}</span>`
                         : '<span class="chip is-good">Abierto</span>');
    setHtml('turnoEstado', `<span class="chips">${chipTurno}${d.sesion.hay ? '<span class="chip">Sesión cerrada</span>' : ''}</span>`);
    setHidden('sesionFila', !d.sesion.hay);
    if (d.sesion.hay) setText('sesionTexto', d.sesion.cuando ? 'Cerrada ' + d.sesion.cuando : 'Cerrada');

    setHtml('ultimasVentas', htmlVentas(d.ventas, ui.masVentas));

    // ── Caja ──
    pintarCaja(d);

    // ── Inventario ──
    pintarInventario(d);

    // ── Historial ──
    pintarSemana(d);
    pintarMes(d);
    pintarAnio(d);

    setText('pie', 'Actualizado ' + new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }));
}

// ── Lo más vendido ──────────────────────────────────────────────
function pintarTop(d) {
    const t = d.top;
    setHidden('topTile', !t.hay);
    if (!t.hay) return;
    document.querySelectorAll('[data-top]').forEach(b =>
        b.setAttribute('aria-selected', String(b.getAttribute('data-top') === ui.top)));
    const lista = t[ui.top] || [];
    if (lista.length === 0) {
        setHtml('topLista', `<p class="empty">${ui.top === 'hoy' ? 'Aún no hay ventas hoy' : 'Sin ventas en este periodo'}</p>`);
        return;
    }
    const max = Math.max(1, ...lista.map(x => x.importe));
    setHtml('topLista', lista.map((x, i) => `<div class="hrow hrow-top">
        <span class="hn">${i + 1}. ${escHtml(x.nombre)}</span>
        <span class="hr"><b>${escHtml(formatMXN(x.importe))}</b><small>${escHtml(formatCantidad(x.cantidad, x.unidad))}</small></span>
        <span class="hbar"><i style="--w:${(x.importe / max * 100).toFixed(1)}%"></i></span></div>`).join(''));
}

// ── Ventas por cajero ───────────────────────────────────────────
function pintarCajeros(d) {
    const c = d.cajeros;
    setHidden('cajerosTile', !c.hay);
    if (!c.hay) return;
    const max = Math.max(1, ...c.items.map(x => x.ventas));
    setHtml('cajerosLista', c.items.map(x => `<div class="hrow hrow-top">
        <span class="hn">${escHtml(x.cajero)}</span>
        <span class="hr"><b>${escHtml(formatMXN(x.ventas))}</b><small>${x.tickets} ${plural(x.tickets, 'ticket', 'tickets')}</small></span>
        <span class="hbar"><i style="--w:${(x.ventas / max * 100).toFixed(1)}%"></i></span></div>`).join(''));
}

// ── Por cobrar, presupuestos y gastos (Caja) ────────────────────
function pintarPorCobrar(d) {
    const partes = [];
    const a = d.apartados;
    if (a.hay) {
        partes.push(`<div class="tile"><div class="tile-head"><div class="tile-label">Apartados por cobrar</div>`
            + (a.vencidos > 0 ? `<span class="chip is-bad">${a.vencidos} ${plural(a.vencidos, 'vencido', 'vencidos')}</span>` : '') + `</div>
            <div class="kpi-value kpi-sm c-promedio">${escHtml(formatMXN(a.porCobrar))}</div>
            <div class="kpi-sub">${a.activos} ${plural(a.activos, 'apartado sin entregar', 'apartados sin entregar')}</div></div>`);
    }
    const c = d.clientes;
    if (c.hay) {
        const sub = [`${c.deben} ${plural(c.deben, 'cliente debe', 'clientes deben')}`];
        if (c.atrasado > 0) sub.push(`${formatMXN(c.atrasado)} con más de 30 días`);
        if (c.abonadoHoy > 0) sub.push(`hoy abonaron ${formatMXN(c.abonadoHoy)}`);
        partes.push(`<div class="tile"><div class="tile-head"><div class="tile-label">Te deben (fiado)</div>`
            + (c.atrasados > 0 ? `<span class="chip is-warn">${c.atrasados} ${plural(c.atrasados, 'atrasado', 'atrasados')}</span>` : '') + `</div>
            <div class="kpi-value kpi-sm c-promedio">${escHtml(formatMXN(c.porCobrar))}</div>
            <div class="kpi-sub">${escHtml(sub.join(' · '))}</div></div>`);
    }
    const p = d.presupuestos;
    if (p.hay) {
        partes.push(`<div class="tile"><div class="tile-head"><div class="tile-label">Presupuestos pendientes</div><span class="chips">`
            + (p.porVencer > 0 ? `<span class="chip is-warn">${p.porVencer} por vencer</span>` : '')
            + (p.vencidos > 0 ? `<span class="chip is-bad">${p.vencidos} ${plural(p.vencidos, 'vencido', 'vencidos')}</span>` : '') + `</span></div>
            <div class="kpi-value kpi-sm c-tickets">${escHtml(formatMXN(p.monto))}</div>
            <div class="kpi-sub">${p.vigentes} ${plural(p.vigentes, 'presupuesto sin respuesta', 'presupuestos sin respuesta')}</div></div>`);
    }
    const g = d.gastos;
    if (g.hay) {
        partes.push(`<div class="tile"><div class="tile-label">Gastos del negocio</div><div class="grid2 gastos">
            <div><div class="kpi-value kpi-sm c-ventas">${escHtml(formatMXN(g.hoy))}</div><div class="kpi-sub">hoy</div></div>
            <div><div class="kpi-value kpi-sm c-ventas">${escHtml(formatMXN(g.mes))}</div><div class="kpi-sub">este mes</div></div></div>
            <p class="tile-note pie-nota">${g.hoy === 0 && g.mes === 0 ? 'Sin gastos registrados este mes. ' : ''}Incluye los gastos pagados con el cajón; lo que no se registre en el punto de venta no aparece.</p></div>`);
    }
    setHtml('cobrarCuerpo', partes.join(''));
    setHidden('cobrarCuerpo', partes.length === 0);
}

// ── Compartir el resumen del día ────────────────────────────────
// WhatsApp no deja adjuntar nada desde un enlace: se comparte texto. «$ 1,200.00» con espacio normal
// (el fino sin salto del panel puede verse raro en otras aplicaciones).
function dineroPlano(c) { return formatMXN(c).replace(/\u202f/g, ' '); }

function textoResumen(d) {
    const hoy = ahoraMX();
    const fecha = new Date(hoy.clave + 'T12:00:00Z')
        .toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
    const L = [`*${d.negocio}*`, `Resumen del ${fecha}`, ''];
    L.push(`Ingresos: ${dineroPlano(d.ingresosHoy)}`
        + (d.abonosHoy > 0 ? ` (ventas ${dineroPlano(d.ventasHoy)} + apartados ${dineroPlano(d.abonosHoy)})` : ''));
    L.push(`Ganancia: ${dineroPlano(d.gananciaHoy)}` + (d.ventasHoy > 0 ? ` (margen ${d.margenTxt})` : ''));
    L.push(`Tickets: ${d.ticketsHoy}` + (d.ticketsHoy > 0 ? ` · promedio ${dineroPlano(d.ticketProm)}` : ''));
    if (d.pagos.hay && d.pagos.total > 0) {
        L.push('Cobros: ' + d.pagos.partes.filter(x => x.v > 0).map(x => `${x.label.toLowerCase()} ${dineroPlano(x.v)}`).join(' · '));
    }
    if (d.meta.hay) {
        L.push(d.meta.cumplida ? `Meta del día: cumplida (${dineroPlano(d.meta.meta)})`
                               : `Meta del día: ${d.meta.pct}% de ${dineroPlano(d.meta.meta)}`);
    }
    const top = d.top.hay ? d.top.hoy.slice(0, 3) : [];
    if (top.length) {
        L.push('', 'Lo más vendido:');
        top.forEach((x, i) => L.push(`${i + 1}. ${x.nombre} · ${dineroPlano(x.importe)}`));
    }
    if (d.caja.estado === 'abierto') L.push('', `Efectivo esperado en caja: ${dineroPlano(d.caja.esperado)}`);
    return L.join('\n');
}

async function compartirResumen() {
    if (!ultimoD) return;
    const texto = textoResumen(ultimoD);
    if (navigator.share) {
        try { await navigator.share({ text: texto }); return; }
        catch (e) { if (e && e.name === 'AbortError') return; /* el sistema no pudo: se cae a WhatsApp */ }
    }
    window.open('https://wa.me/?text=' + encodeURIComponent(texto), '_blank', 'noopener');
}

// ── Gráfica de columnas ─────────────────────────────────────────
// items: [{ key, label, valor, destacado, aria, hoy }]; opts: { id, sel, valores, promedio }
function htmlColumnas(items, opts) {
    const max  = Math.max(1, ...items.map(i => i.valor));
    const paso = items.length > 20 ? 5 : items.length > 14 ? 3 : items.length > 8 ? 2 : 1;
    const cols = items.map(it => {
        const p   = Math.round(it.valor / max * 100);
        const cls = ['col'];
        if (it.destacado) cls.push('is-peak');
        if (opts.sel === it.key) cls.push('is-sel');
        if (it.valor === 0) cls.push('is-zero');
        return `<button type="button" class="${cls.join(' ')}" data-chart="${opts.id}" data-key="${escHtml(it.key)}" `
             + `aria-label="${escHtml(it.aria)}" style="--p:${p}">`
             + `<span class="col-bar"></span>`
             + (opts.valores && it.valor > 0 ? `<span class="col-val">${compacto(it.valor)}</span>` : '')
             + `</button>`;
    }).join('');
    const eje = items.map((it, i) =>
        `<span${it.hoy ? ' class="is-today"' : ''}>${(i % paso === 0 || it.hoy) ? escHtml(it.label) : ''}</span>`).join('');
    const avg = opts.promedio > 0
        ? `<i class="avg" style="--p:${Math.min(100, Math.round(opts.promedio / max * 100))}"></i>` : '';
    return `<div class="plot">${avg}${cols}</div><div class="axis">${eje}</div>`;
}

// ── Cómo pagaron hoy ────────────────────────────────────────────
function pintarPagos(d) {
    const p = d.pagos;
    // Con un POS anterior no hay desglose: la tarjeta no aparece (no hace falta explicar nada en Hoy)
    setHidden('pagosTile', !p.hay);
    if (!p.hay) return;
    if (p.total <= 0) {
        setHtml('pagosCuerpo', '<p class="empty">Aún no hay cobros hoy</p>');
        return;
    }
    const barra = p.partes.filter(x => x.v > 0)
        .map(x => `<i class="s-${x.clave}" style="width:${x.pct.toFixed(2)}%"></i>`).join('');
    const filas = p.partes.map(x => `<li class="leg">
        <span class="leg-dot s-${x.clave}"></span><span class="leg-n">${x.label}</span>
        <b>${escHtml(formatMXN(x.v))}</b><small>${Math.round(x.pct)}%</small></li>`).join('');
    const comision = p.comision > 0
        ? `<p class="tile-note">Comisión de tarjeta: <b>${escHtml(formatMXN(p.comision))}</b> (la paga el negocio)</p>` : '';
    setHtml('pagosCuerpo', `<div class="stack" role="img" aria-label="Efectivo, tarjeta y transferencia">${barra}</div><ul class="legend">${filas}</ul>${comision}`);
}

// ── Caja del turno, aviso de turno largo y cancelaciones ────────
function filaCaja(texto, centavos, signo, fuerte) {
    const cls = signo < 0 ? ' class="neg"' : '';
    return `<div class="kv${fuerte ? ' kv-fuerte' : ''}"><span>${texto}</span><span${cls}>${signo < 0 ? '\u2212' : ''}${escHtml(formatMXN(Math.abs(centavos)))}</span></div>`;
}

function pintarCaja(d) {
    const c = d.caja;
    if (c.estado === 'sin-datos') {
        setHtml('cajaCuerpo', `<div class="tile"><p class="empty">${AVISO_POS_ANTERIOR}</p></div>`);
    } else if (c.estado === 'cerrado') {
        setHtml('cajaCuerpo', '<div class="tile"><div class="tile-label">Efectivo en el cajón</div>'
            + '<p class="empty">No hay un turno abierto, así que no hay efectivo por contar. Al abrir turno aparecerá aquí.</p></div>');
    } else {
        setHtml('cajaCuerpo',
            `<div class="tile"><div class="tile-label">Efectivo esperado en el cajón</div>
              <div class="kpi-value c-ganancia">${escHtml(formatMXN(c.esperado))}</div>
              <div class="kpi-sub">Turno de ${escHtml(d.turnoUsuario)} · ${d.turnoDesdeAyer ? 'abrió el ' : 'abrió a las '}${escHtml(d.turnoInicio)}</div>
              <div class="hlist">`
            + filaCaja('Fondo inicial', c.fondo, 1)
            + filaCaja('+ Ventas en efectivo y abonos', c.efectivoVentas, 1)
            + filaCaja('+ Ingresos al cajón', c.ingresos, 1)
            + filaCaja('\u2212 Retiros y gastos del cajón', c.retiros, -1)
            + filaCaja('Esperado', c.esperado, 1, true)
            + `</div></div>
            <div class="tile"><div class="tile-label">Fuera del cajón (turno actual)</div>
              <p class="tile-note">Entró por banco: no cuenta para el corte de efectivo.</p>`
            + filaCaja('Tarjeta', c.tarjeta, 1)
            + filaCaja('Transferencia', c.transferencia, 1)
            + `</div>`);
    }

    // Sesión cerrada con el turno abierto: informativo, convive con el aviso de turno largo
    setHidden('sesionAviso', !d.sesion.hay);
    if (d.sesion.hay) {
        setHtml('sesionAviso', `<div class="info-t">Sesión cerrada con el turno abierto</div>
            <p>La sesión se cerró${d.sesion.cuando ? ' ' + escHtml(d.sesion.cuando) : ''} y el turno de ${escHtml(d.turnoUsuario)} sigue abierto; mientras no se vuelva a iniciar sesión, nadie atiende el punto de venta.</p>`);
    }
    pintarPorCobrar(d);

    // Turno que se quedó abierto: lo más común es que se olvidó cerrar
    const mostrarAlerta = d.turnoAlerta;
    setHidden('turnoAlerta', !mostrarAlerta);
    if (mostrarAlerta) {
        const horas = Math.round(d.turnoMinutos / 60);
        setHtml('turnoAlerta', `<div class="alerta-t">${d.turnoDesdeAyer ? 'Turno abierto desde ' + escHtml(d.turnoDesde) : 'Turno abierto hace ' + horas + ' horas'}</div>
            <p>¿Se olvidó de cerrarlo? El turno de ${escHtml(d.turnoUsuario)} ${d.turnoDesdeAyer ? 'abrió el ' : 'abrió a las '}${escHtml(d.turnoInicio)}; mientras siga abierto, el corte no refleja el día.</p>`);
    }

    // Cancelaciones y devoluciones
    const x = d.cancel;
    // Con un POS anterior el aviso ya sale arriba, en la tarjeta de caja: aquí no se repite
    setHidden('cancelTile', !x.hay);
    if (!x.hay) {
        setHtml('cancelChip', '');
        setHtml('cancelCuerpo', '');
        return;
    }
    setHtml('cancelChip', x.total > 0 ? `<span class="chip is-warn">${x.total}</span>` : '<span class="chip is-good">Ninguna</span>');
    let cuerpo = '';
    if (x.total === 0) {
        cuerpo = '<p class="tile-note">Hoy no se canceló ni se devolvió nada.</p>';
    } else {
        cuerpo += filaCaja(`Ventas canceladas (${x.canc})`, x.montoCanc, 1);
        cuerpo += filaCaja(`Devoluciones de artículos (${x.dev})`, x.montoDev, 1);
        if (x.usuarios.length) {
            cuerpo += '<div class="tile-label sub">Quién lo hizo</div>' + x.usuarios.map(u =>
                `<div class="kv"><span>${escHtml(u.usuario)}</span><span>${u.canc ? u.canc + ' ' + plural(u.canc, 'cancelación', 'cancelaciones') : ''}`
                + `${u.canc && u.dev ? ' · ' : ''}${u.dev ? u.dev + ' ' + plural(u.dev, 'devolución', 'devoluciones') : ''}</span></div>`).join('');
        }
    }
    cuerpo += '<p class="tile-note pie-nota">Se registra desde la versión 3.3.0 del punto de venta: antes no se guardaba quién ni cuándo.</p>';
    setHtml('cancelCuerpo', cuerpo);
}

// ── Inventario ──────────────────────────────────────────────────
function pintarInventario(d) {
    const i = d.inv;
    if (!i.hay) {
        setHtml('invCuerpo', `<div class="tile"><p class="empty">${AVISO_POS_ANTERIOR}</p></div>`);
        return;
    }
    const urg = i.urgentes.length === 0
        ? '<p class="empty">Todo en orden: nada agotado ni bajo el mínimo.</p>'
        : '<ul class="vlist">' + i.urgentes.map(u => {
            const agotado = u.cantidad <= 0;
            return `<li class="venta urg">
                <span class="urg-n">${escHtml(u.nombre)}</span>
                <span class="urg-c${agotado ? ' neg' : ''}">${escHtml(formatCantidad(u.cantidad, u.unidad))}${u.minimo > 0 ? `<small>mínimo ${u.minimo}</small>` : ''}</span>
                <span class="chip ${agotado ? 'is-bad' : 'is-warn'}">${agotado ? 'Agotado' : 'Bajo'}</span></li>`;
        }).join('') + '</ul>';
    setHtml('invCuerpo',
        `<div class="grid2">
            <div class="tile"><div class="tile-label">Agotados</div><div class="kpi-value kpi-sm ${i.agotados > 0 ? 'c-alerta' : 'c-ganancia'}">${i.agotados}</div><div class="kpi-sub">productos sin existencia</div></div>
            <div class="tile"><div class="tile-label">Bajo mínimo</div><div class="kpi-value kpi-sm ${i.bajo > 0 ? 'c-alerta' : 'c-ganancia'}">${i.bajo}</div><div class="kpi-sub">por reponer pronto</div></div>
        </div>
        <div class="tile"><div class="tile-label">Valor del inventario</div>
            <div class="kpi-value c-promedio">${escHtml(formatMXN(i.valor))}</div>
            <div class="kpi-sub">a precio de costo, solo lo que hay en existencia</div></div>
        <div class="tile"><div class="tile-label">Reponer primero</div>${urg}</div>`);
}

// ── Ventas por hora ─────────────────────────────────────────────
function pintarHoras(d) {
    const { items, pico } = d.horas;
    if (items.length === 0) {
        setHtml('horasGrafica', '<p class="empty">Sin ventas registradas hoy</p>');
        setHtml('horasLectura', '');
        return;
    }
    const sel = items.some(i => i.key === ui.sel.horas) ? ui.sel.horas : null;
    ui.sel.horas = sel;
    setHtml('horasGrafica', htmlColumnas(items.map(it => ({
        key: it.key, label: it.label, valor: it.valor, destacado: it.destacado,
        aria: `${it.label}:00 — ${formatMXN(it.valor)}`,
    })), { id: 'horas', sel, valores: items.length <= 10 }));

    const it = items.find(i => i.key === sel) || pico;
    if (!it) { setHtml('horasLectura', 'Todavía sin ventas por hora.'); return; }
    const hh = String(it.hora).padStart(2, '0'), h2 = String((it.hora + 1) % 24).padStart(2, '0');
    setHtml('horasLectura', `${sel ? '' : 'Hora pico: '}<b>${hh}:00 – ${h2}:00</b> · ${escHtml(formatMXN(it.valor))}`
        + (it.tickets > 0 ? ` · ${it.tickets} ticket${it.tickets !== 1 ? 's' : ''}` : ''));
}

// ── Semana ──────────────────────────────────────────────────────
function textoDelta(valor, promedio) {
    if (!(promedio > 0)) return { txt: '', cls: '' };
    const p = Math.round((valor / promedio - 1) * 100);
    if (p === 0) return { txt: 'como el promedio', cls: '' };
    return p > 0 ? { txt: `+${p}% vs prom.`, cls: 'up' } : { txt: `${p}% vs prom.`.replace('-', '\u2212'), cls: 'down' };
}

function pintarSemana(d) {
    const s = d.semana;
    setText('semanaTotal', formatMXN(s.total));
    setText('semanaSub', s.diasConVenta > 0
        ? `${s.diasConVenta} día${s.diasConVenta !== 1 ? 's' : ''} con ventas`
        : 'Sin ventas en los últimos 7 días');
    const a = d.semanaAnterior;
    setHtml('semanaVsAnterior', a
        ? `Semana anterior: <b>${escHtml(formatMXN(a.total))}</b> · <span class="${a.pct >= 0 ? 'up' : 'down'}">${deltaTxt(a.pct)}</span>` : '');
    setText('semanaPromedio', s.promedio > 0 ? formatMXN(s.promedio) : '—');
    setText('semanaPromSub', 'en días con ventas');
    setText('semanaMejor', s.mejor ? formatMXN(s.mejor.total) : '—');
    setText('semanaMejorSub', s.mejor ? s.mejor.label : '\u00a0');

    const sel = s.dias.some(x => x.clave === ui.sel.semana) ? ui.sel.semana : null;
    ui.sel.semana = sel;
    setHtml('semanaGrafica', htmlColumnas(s.dias.map(x => ({
        key: x.clave, label: x.label, valor: x.total, destacado: x.esHoy, hoy: x.esHoy,
        aria: `${x.label}: ${formatMXN(x.total)}`,
    })), { id: 'semana', sel, valores: true, promedio: s.promedio }));
    setHidden('semanaLeyenda', !(s.promedio > 0));

    const dia = s.dias.find(x => x.clave === sel) || s.dias[s.dias.length - 1];
    const dl  = dia.esHoy ? { txt: 'en curso', cls: '' } : textoDelta(dia.total, s.promedio);
    setHtml('semanaLectura', `<b>${escHtml(dia.label)}${dia.esHoy ? ' (hoy)' : ''}</b> · ${escHtml(formatMXN(dia.total))}`
        + (dl.txt ? ` · ${escHtml(dl.txt)}` : ''));

    const max = Math.max(1, ...s.dias.map(x => x.total));
    setHtml('semanaLista', s.dias.slice().reverse().map(x => {
        const dl2 = x.esHoy ? { txt: 'en curso', cls: '' } : textoDelta(x.total, s.promedio);
        return `<div class="hrow hrow-dia${x.esHoy ? ' is-now' : ''}">
            <span class="hl">${escHtml(x.label)}</span>
            <span class="hbar"><i style="--w:${(x.total / max * 100).toFixed(1)}%"></i></span>
            <span class="hr"><b>${x.total > 0 ? escHtml(formatMXN(x.total)) : '—'}</b>`
            + (x.total > 0 && dl2.txt ? `<small class="${dl2.cls}">${escHtml(dl2.txt)}</small>` : '') + `</span>
        </div>`;
    }).join(''));
}

// ── Mes ─────────────────────────────────────────────────────────
function pintarMes(d) {
    const m = d.mes;
    setHidden('mesVacio', m.hayFilas);
    setHidden('mesContenido', !m.hayFilas);
    if (!m.hayFilas) return;

    setText('mesEtiqueta', `${m.nombre} hasta hoy`);
    setText('mesTotal', formatMXN(m.total));
    setText('mesSub', `${m.conVenta} día${m.conVenta !== 1 ? 's' : ''} con ventas`);
    setHtml('mesVsAnterior', m.anterior
        ? `${m.anterior.nombre} a esta misma fecha: <b>${escHtml(formatMXN(m.anterior.total))}</b> · `
          + `<span class="${m.anterior.pct >= 0 ? 'up' : 'down'}">${deltaTxt(m.anterior.pct)}</span>` : '');
    setText('mesGanancia', formatMXN(m.ganancia));
    const u = d.utilidad;
    setHidden('utilidadTile', !u.hay);
    if (u.hay) {
        setText('utilidadValor', (u.valor < 0 ? '\u2212' : '') + formatMXN(Math.abs(u.valor)));
        setClass('utilidadValor', 'c-ganancia', u.valor >= 0);
        setClass('utilidadValor', 'c-alerta', u.valor < 0);
        setText('utilidadSub', `Ganancia ${formatMXN(u.ganancia)} \u2212 gastos ${formatMXN(u.gastos)}`);
    }

    const sel = m.items.some(x => x.key === ui.sel.mes) ? ui.sel.mes : null;
    ui.sel.mes = sel;
    setHtml('mesGrafica', htmlColumnas(m.items, { id: 'mes', sel, valores: false }));
    const dia = m.items.find(x => x.key === sel) || m.items[m.items.length - 1];
    setHtml('mesLectura', `<b>${escHtml(etiquetaDia(dia.key))}${dia.esHoy ? ' (hoy)' : ''}</b> · ${escHtml(formatMXN(dia.valor))}`);
}

// ── Año ─────────────────────────────────────────────────────────
function filasMeses(anual, campo) {
    const max = Math.max(1, ...anual.meses.map(m => m[campo]));
    return anual.meses.map(m => `<div class="hrow${m.enCurso ? ' is-now' : ''}">
        <span class="hl">${m.nombre}</span>
        <span class="hbar"><i style="--w:${(m[campo] / max * 100).toFixed(1)}%"></i></span>
        <span class="hr"><b>${m[campo] ? escHtml(formatMXN(m[campo])) : '—'}</b>${m.enCurso ? '<small>en curso</small>' : ''}</span>
    </div>`).join('');
}

function pintarAnio(d) {
    setHidden('anualVacio', d.hayAnual);
    setHidden('anualContenido', !d.hayAnual);
    if (!d.hayAnual) return;
    const a = d.anual;

    setText('anualEtiquetaVentas', `Ventas ${a.year}`);
    setText('anualEtiquetaGanancia', `Ganancia ${a.year}`);
    setText('totalVentasAnual',   formatMXN(a.totalVentas));
    setText('totalGananciaAnual', formatMXN(a.totalGanancia));
    setText('margenAnualChip', `Margen ${d.margenAnualTxt}`);
    setClass('margenAnualChip', 'is-good',  d.margenAnualGood);
    setClass('margenAnualChip', 'is-warn', !d.margenAnualGood);

    setHtml('mejorMes', a.mejor ? `Mejor mes: <b>${a.mejor.nombre}</b> · ${escHtml(formatMXN(a.mejor.v))}` : '');
    setHtml('mesesVentas',   filasMeses(a, 'v'));
    setHtml('mesesGanancia', filasMeses(a, 'g'));
}

// ── Últimas ventas ──────────────────────────────────────────────
const VENTAS_VISIBLES = 6;
const VENTAS_MAXIMO   = 50;

function htmlVentas(ventas, expandido) {
    if (!ventas || ventas.length === 0) {
        return '<p class="empty">Sin ventas registradas hoy</p>';
    }
    const lim   = expandido ? VENTAS_MAXIMO : VENTAS_VISIBLES;
    const items = ventas.slice(0, lim).map(v => `
        <li class="venta">
            <span class="venta-hora">${escHtml(v.hora || '')}</span>
            <span class="venta-cajero">${escHtml(v.cajero || '')}</span>
            <span class="venta-total">${escHtml(formatMXN(v.total_centavos || 0))}</span>
        </li>`).join('');
    const boton = ventas.length > VENTAS_VISIBLES
        ? `<button type="button" class="btn-ghost" data-act="masVentas">${expandido ? 'Ver menos' : `Ver todas (${ventas.length})`}</button>`
        : '';
    const nota = expandido && ventas.length > VENTAS_MAXIMO
        ? `<p class="empty">Se muestran las ${VENTAS_MAXIMO} más recientes de ${ventas.length}</p>` : '';
    return `<ul class="vlist">${items}</ul>${nota}${boton}`;
}

// ────────────────────────────────────────────────────────────────
// Interacción
// ────────────────────────────────────────────────────────────────
function aplicarTab() {
    document.querySelectorAll('[data-tab]').forEach(b => {
        b.setAttribute('aria-selected', String(b.getAttribute('data-tab') === ui.tab));
    });
    document.querySelectorAll('[data-panel]').forEach(p => {
        p.hidden = p.getAttribute('data-panel') !== ui.tab;
    });
    document.querySelectorAll('[data-sub]').forEach(b => {
        b.setAttribute('aria-selected', String(b.getAttribute('data-sub') === ui.sub));
    });
    document.querySelectorAll('[data-subpanel]').forEach(p => {
        p.hidden = p.getAttribute('data-subpanel') !== ui.sub;
    });
}

function volverAlInicioDeLaHoja() {
    // Si se estaba leyendo más abajo de la cabecera, volver al inicio de la hoja
    const hoja = document.querySelector('.sheet');
    if (hoja && window.scrollY > hoja.offsetTop) window.scrollTo(0, hoja.offsetTop);
}

function activarTab(nombre) {
    if (ui.tab === nombre) return;
    ui.tab = nombre;
    aplicarTab();
    volverAlInicioDeLaHoja();
}

function activarSub(nombre) {
    if (ui.sub === nombre) return;
    ui.sub = nombre;
    aplicarTab();
}

function seleccionarColumna(grafica, key) {
    ui.sel[grafica] = (ui.sel[grafica] === key) ? null : key;
    if (ultimoD) {
        if (grafica === 'horas') pintarHoras(ultimoD);
        else if (grafica === 'semana') pintarSemana(ultimoD);
        else if (grafica === 'mes') pintarMes(ultimoD);
    }
}

function temaGuardado() {
    try { return localStorage.getItem('apdash:tema'); } catch (e) { return null; }
}

function temaEfectivo() {
    const fijo = document.documentElement.getAttribute('data-theme');
    if (fijo === 'light' || fijo === 'dark') return fijo;
    return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
}

function pintarTema() {
    const b = el('btnTema');
    if (!b) return;
    // El botón muestra a qué tema se pasa al tocarlo
    const oscuro = temaEfectivo() === 'dark';
    const html = oscuro ? ICONO_SOL : ICONO_LUNA;
    if (htmlPrevio.btnTema !== html) { htmlPrevio.btnTema = html; b.innerHTML = html; }
    b.setAttribute('aria-label', oscuro ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro');
}

function alternarTema() {
    const nuevo = temaEfectivo() === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', nuevo);
    try { localStorage.setItem('apdash:tema', nuevo); } catch (e) { /* solo no se recuerda */ }
    pintarTema();
}

function alClic(ev) {
    const t = ev.target.closest('[data-tab],[data-sub],[data-top],[data-chart],[data-act]');
    if (!t) return;
    if (t.hasAttribute('data-tab'))   { activarTab(t.getAttribute('data-tab')); return; }
    if (t.hasAttribute('data-sub'))   { activarSub(t.getAttribute('data-sub')); return; }
    if (t.hasAttribute('data-top'))   { ui.top = t.getAttribute('data-top'); if (ultimoD) pintarTop(ultimoD); return; }
    if (t.hasAttribute('data-chart')) { seleccionarColumna(t.getAttribute('data-chart'), t.getAttribute('data-key')); return; }
    const act = t.getAttribute('data-act');
    if (act === 'tema') alternarTema();
    else if (act === 'refrescar') { if (hwIdActual) fetchData(hwIdActual, false); }
    else if (act === 'salir') cerrarSesion();
    else if (act === 'compartir') compartirResumen();
    else if (act === 'plan') elegirPlan(t.getAttribute('data-plan'));
    else if (act === 'masVentas') {
        ui.masVentas = !ui.masVentas;
        if (ultimoD) setHtml('ultimasVentas', htmlVentas(ultimoD.ventas, ui.masVentas));
    }
}

// ────────────────────────────────────────────────────────────────
// PIN: sesión del panel protegido
// ────────────────────────────────────────────────────────────────
function claveToken(hwId) { return 'apdash:token:' + hwId; }

function tokenGuardado() {
    try { return localStorage.getItem(claveToken(hwIdActual)); } catch (e) { return null; }
}
function guardarToken(t) {
    try { localStorage.setItem(claveToken(hwIdActual), t); } catch (e) { /* sin almacenamiento: se pedirá el PIN cada vez */ }
}
function borrarToken() {
    try { localStorage.removeItem(claveToken(hwIdActual)); } catch (e) { /* nada que borrar */ }
}
function borrarCache(hwId) {
    try { localStorage.removeItem('apdash:datos:' + hwId); } catch (e) { /* nada que borrar */ }
}

function cerrarSesion() {
    borrarToken();
    borrarCache(hwIdActual);
    desdeCache = null;
    ultimoOk = null;
    showPin('Sesión cerrada.');
}

function showPin(msg, bloqueado) {
    document.getElementById('app').innerHTML = `
    <header class="top"><div class="top-in">
        <div class="bar">${MARCA}</div>
        <span class="kicker">Panel de ventas</span>
        <h1>Panel protegido</h1>
    </div></header>
    <main class="sheet"><div class="sheet-in">
        <form class="pin-box" data-k="pinForm" autocomplete="off" novalidate>
            <p class="pin-ayuda">Escribe el PIN de 6 a 8 dígitos que creaste en el programa de tu tienda.</p>
            <label class="pin-lab" for="pin-campo">PIN</label>
            <input id="pin-campo" class="pin-input" data-k="pinCampo" type="password" inputmode="numeric" pattern="[0-9]*"
                   maxlength="8" autocomplete="current-password" ${bloqueado ? 'disabled' : ''}>
            <p class="pin-msg" data-k="pinMsg" role="alert">${escHtml(msg || '')}</p>
            <button type="submit" class="btn-solid" data-k="pinBoton" ${bloqueado ? 'disabled' : ''}>Entrar</button>
        </form>
    </div></main>`;
    estructura = null;
    ultimoD = null;
    const c = el('pinCampo');
    if (c && !bloqueado) c.focus();
}

async function enviarPin(ev) {
    ev.preventDefault();
    const campo = el('pinCampo'), boton = el('pinBoton');
    if (!campo || !hwIdActual) return;
    const pin = campo.value.trim();
    if (!/^\d{6,8}$/.test(pin)) { setText('pinMsg', 'El PIN tiene de 6 a 8 dígitos.'); return; }
    boton.disabled = true;
    setText('pinMsg', 'Comprobando…');
    try {
        const res = await fetch(API_AUTH, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: hwIdActual, pin }).toString(),
        });
        let j = null;
        try { j = await res.json(); } catch (e) { /* respuesta sin cuerpo */ }
        if (res.status === 200 && j && j.status === 'exitoso') {
            if (j.token) guardarToken(j.token);
            fetchData(hwIdActual, true);
            return;
        }
        if (res.status === 429) {
            const min = Math.max(1, Math.ceil(((j && j.reintentar_en) || 900) / 60));
            showPin(`Demasiados intentos. Prueba otra vez en ${min} minuto${min !== 1 ? 's' : ''}.`, true);
            return;
        }
        if (res.status === 401) {
            const r = j && Number.isFinite(j.restantes) ? j.restantes : null;
            campo.value = '';
            boton.disabled = false;
            setText('pinMsg', r !== null ? `PIN incorrecto. Quedan ${r} intento${r !== 1 ? 's' : ''}.` : 'PIN incorrecto.');
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

// ────────────────────────────────────────────────────────────────
// Premium: el panel es parte de la suscripción, y se compra aquí mismo
// ────────────────────────────────────────────────────────────────
// Los planes de Premium (una tienda). Los precios que cuentan son los de /tienda/planes.php (se piden al mostrar el
// muro); estos son para no esperar. Pagar abre /tienda/pagar.php con el ID del panel: el sitio sabe qué PC es y la
// clave le llega sola al programa de la tienda (POS 3.5.0+; si no, el pago da un código para canjear).
// Fuera de México (lo deduce planes.php por la zona horaria y el idioma del celular) los precios se ven aproximados en
// su moneda, porque Stripe cobra en ella; donde Stripe no convierte, con «MXN». Y sin OXXO.
const PLANES_PREMIUM = [
    { id: 'premium_mensual', nombre: 'Mensual',      precio: 14900,  periodo: 'al mes' },
    { id: 'premium_anual',   nombre: 'Anual',        precio: 129000, periodo: 'al año' },
    { id: 'premium_siempre', nombre: 'Para siempre', precio: 349000, periodo: 'pago único' },
];
let planElegido = 'premium_anual';
let monedaPlanes = null;   // { pais, moneda, por_peso } de planes.php: precios aproximados en esa moneda
let paisPlanes = null;     // fuera de México: el país (va a pagar.php)
let comoBase = '';         // el texto de cómo se paga, antes de quitarle OXXO

function urlContratar(negocio) {
    const texto = `Hola, tengo una duda sobre Premium para el panel móvil de ${negocio || 'mi negocio'} (panel ${hwIdActual}).`;
    return `https://wa.me/${WHATSAPP_VENTAS}?text=${encodeURIComponent(texto)}`;
}

// «$1,290»; fuera de México «$ 228.759» o, donde Stripe no convierte, «$1,290». Con completo (el botón de pagar; en las
// tarjetas no cabe y lo dice la nota de abajo): «≈ $ 228.759» y «$1,290 MXN».
function precioPlan(centavos, completo) {
    const m = monedaPlanes;
    if (!m) return '$' + Math.round(centavos / 100).toLocaleString('es-MX') + (paisPlanes && completo ? ' MXN' : '');
    const v = centavos / 100 * m.por_peso;
    const dec = v >= 100 ? 0 : 2;
    let f;
    try {
        f = new Intl.NumberFormat('es-' + m.pais, { style: 'currency', currency: m.moneda, minimumFractionDigits: dec, maximumFractionDigits: dec }).format(v);
    } catch (e) {
        f = m.moneda + ' ' + v.toFixed(dec);
    }
    return (completo ? '≈ ' : '') + f;
}
function urlPagar(plan) {
    return `/tienda/pagar.php?plan=${encodeURIComponent(plan)}&panel=${encodeURIComponent(hwIdActual || '')}` + (paisPlanes ? `&pais=${paisPlanes}` : '');
}
function pintarComo() {
    const el = document.querySelector('[data-k="premiumComo"]');
    if (!el) return;
    if (!paisPlanes) { el.textContent = comoBase; return; }
    el.textContent = comoBase.replace(' o en OXXO', '') + (monedaPlanes
        ? ` Precios aproximados en tu moneda (${monedaPlanes.moneda}); al pagar ves el monto exacto.`
        : ' Precios en pesos mexicanos (MXN); tu banco los convierte a tu moneda.');
}

function htmlPlanes() {
    const mensual = PLANES_PREMIUM.find(p => p.id === 'premium_mensual');
    return PLANES_PREMIUM.map(p => {
        let extra = p.id === 'premium_siempre' ? 'lo pagas una vez' : p.id === 'premium_mensual' ? 'se renueva sola' : '';
        if (p.id === 'premium_anual' && mensual && mensual.precio * 12 > p.precio) extra = 'ahorras ' + precioPlan(mensual.precio * 12 - p.precio);
        return `<button type="button" class="plan${p.id === planElegido ? ' sel' : ''}" data-act="plan" data-plan="${escHtml(p.id)}" aria-pressed="${p.id === planElegido}">
            <span class="plan-nombre">${escHtml(p.nombre)}</span>
            <b class="plan-precio">${escHtml(precioPlan(p.precio))}</b>
            <span class="plan-periodo">${escHtml(p.periodo)}</span>
            ${extra ? `<span class="plan-extra">${escHtml(extra)}</span>` : ''}
        </button>`;
    }).join('');
}

// Un monto largo (otra moneda: «$ 618.882», «$3,490 MXN») se achica hasta caber en su tarjeta, sin partirse
function ajustarPrecios() {
    document.querySelectorAll('.premium-box .plan-precio').forEach(el => {
        let tam = 18;
        el.style.fontSize = '';
        while (el.scrollWidth > el.clientWidth + 1 && tam > 11) el.style.fontSize = (--tam) + 'px';
    });
}
window.addEventListener('resize', ajustarPrecios);

function pintarPlanes() {
    setHtml('planes', htmlPlanes());
    ajustarPrecios();
    const plan = PLANES_PREMIUM.find(p => p.id === planElegido) || PLANES_PREMIUM[1];
    const pagar = document.querySelector('[data-k="premiumPagar"]');
    if (pagar) {
        pagar.setAttribute('href', urlPagar(plan.id));
        pagar.textContent = 'Pagar ' + precioPlan(plan.precio, true);
    }
}

function elegirPlan(id) {
    if (!PLANES_PREMIUM.some(p => p.id === id)) return;
    planElegido = id;
    pintarPlanes();
}

// Los precios vigentes del sitio; si no contesta, se quedan los de arriba
async function cargarPlanes() {
    try {
        let zona = '';
        try { zona = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { /* sin zona */ }
        const idioma = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || '']).join(',');
        const r = await fetch(`/tienda/planes.php?zona=${encodeURIComponent(zona)}&idioma=${encodeURIComponent(idioma)}`, { cache: 'no-store' });
        const j = await r.json();
        paisPlanes = /^[A-Z]{2}$/.test(j.pais || '') && j.pais !== 'MX' ? j.pais : null;
        const l = j.local;
        monedaPlanes = paisPlanes && l && l.por_peso > 0 && /^[A-Z]{3}$/.test(l.moneda || '') && /^[A-Z]{2}$/.test(l.pais || '') ? l : null;
        pintarComo();
        const del_sitio = (j.planes || []).filter(p => p.tipo === 'premium');
        if (del_sitio.length === 3) {
            del_sitio.forEach(p => {
                const local = PLANES_PREMIUM.find(x => x.id === p.id);
                if (local && Number.isFinite(p.precio)) { local.precio = p.precio; local.periodo = p.periodo || local.periodo; }
            });
        }
        pintarPlanes();
    } catch (e) { /* sin conexión: precios incluidos */ }
}

// Sin Premium vigente (el panel es solo de Premium, sin días gratis): se explica y se ofrece comprarlo aquí mismo.
// `desde` es el día en que venció la suscripción; null si nunca la tuvo. `compra_directa`: el programa de la tienda
// ya es 3.5.0+ y la clave le llega sola.
function showPremium(j) {
    const firma = 'premium:' + (j.desde || '') + ':' + (j.compra_directa ? 1 : 0);
    if (estructura === firma) return;   // el refresco de cada minuto no vuelve a montar lo mismo
    const negocio = j.negocio || 'Tu negocio';
    let termino = '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(j.desde || '')) {
        const [y, m, d] = j.desde.split('-').map(Number);
        termino = `Tu suscripción terminó el ${d} ${MESES[m - 1]} ${y}. `;
    }
    comoBase = j.compra_directa
        ? 'Pagas con tarjeta o en OXXO y Premium se activa solo en el programa de tu tienda.'
        : 'Pagas con tarjeta o en OXXO y te damos un código. Actualiza el programa de tu tienda a la versión más reciente y escríbelo en Configuración › Premium.';
    document.getElementById('app').innerHTML = `
    <header class="top"><div class="top-in">
        <div class="bar">${MARCA}</div>
        <span class="kicker">Panel de ventas</span>
        <h1>${escHtml(negocio)}</h1>
    </div></header>
    <main class="sheet"><div class="sheet-in">
        <div class="premium-box" data-k="premiumBox">
            <h2>El panel móvil es parte de Premium</h2>
            <p data-k="premiumTexto">${escHtml(termino)}Con Premium ves desde el celular las ventas, la caja y el inventario de tu tienda.</p>
            <div class="planes" data-k="planes" role="group" aria-label="Planes de Premium"></div>
            <a class="btn-solid" data-k="premiumPagar" href="#" target="_blank" rel="noopener">Pagar</a>
            <p class="nota" data-k="premiumComo"></p>
            <button type="button" class="btn-ghost" data-act="refrescar">Ya lo activé: volver a revisar</button>
            <a class="foot-link" data-k="premiumDudas" href="${escHtml(urlContratar(negocio))}" target="_blank" rel="noopener">¿Dudas? Escríbenos por WhatsApp</a>
        </div>
    </div></main>`;
    estructura = firma;
    ultimoD = null;
    pintarPlanes();
    pintarComo();
    cargarPlanes();
}

// ────────────────────────────────────────────────────────────────
// Estados de conexión
// ────────────────────────────────────────────────────────────────
function showLoading() {
    document.getElementById('app').innerHTML = `
    <header class="top"><div class="top-in">
        <div class="bar">${MARCA}</div>
        <span class="kicker">Panel de ventas</span>
        <div class="skel skel-hero"></div>
    </div></header>
    <main class="sheet"><div class="sheet-in">
        <div class="tabs"><div class="seg" aria-hidden="true"><button type="button" tabindex="-1">Hoy</button><button type="button" tabindex="-1">Caja</button><button type="button" tabindex="-1">Inventario</button><button type="button" tabindex="-1">Historial</button></div></div>
        <div class="panel">
            <div class="skel" style="height:132px"></div>
            <div class="grid2"><div class="skel" style="height:96px"></div><div class="skel" style="height:96px"></div></div>
            <div class="skel" style="height:220px"></div>
        </div>
    </div></main>`;
    estructura = null;
    ultimoD = null;
}

function showError(msg) {
    document.getElementById('app').innerHTML = `
    <header class="top"><div class="top-in">
        <div class="bar">${MARCA}</div>
        <span class="kicker">Panel de ventas</span>
        <h1>Sin conexión</h1>
    </div></header>
    <main class="sheet"><div class="sheet-in">
        <div class="error-box">
            <strong>No se pudo mostrar el panel</strong>
            <p>${escHtml(msg)}</p>
            <button type="button" class="btn-ghost" data-act="refrescar">Reintentar</button>
        </div>
    </div></main>`;
    estructura = null;
    ultimoD = null;
}

// Los refrescos de fondo fallaban en silencio (solo console.error), así
// que se seguían viendo cifras viejas como si fueran de ahora.
function actualizarBanner() {
    const n = el('banner');
    if (!n) return;

    if (desdeCache) {
        const min = Math.floor((Date.now() - desdeCache.t) / 60000);
        n.textContent = `Mostrando lo último guardado en este teléfono (${tiempoRelativo(min)}). Actualizando…`;
        n.hidden = false;
        return;
    }
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
// Último estado guardado en el teléfono: abre al instante y sirve sin
// internet. Solo se usa si es de HOY (las cifras de ayer no son las de hoy)
// y, en un panel con PIN, solo si esta sesión ya entró con él.
// ────────────────────────────────────────────────────────────────
function leerCache(hwId) {
    try {
        const o = JSON.parse(localStorage.getItem('apdash:datos:' + hwId));
        if (!o || !o.t || !o.data) return null;
        if (ahoraMX(new Date(o.t)).clave !== ahoraMX().clave) return null;
        if (o.data.protegido && !tokenGuardado()) return null;
        return o;
    } catch (e) { return null; }
}

function guardarCache(hwId, data) {
    try { localStorage.setItem('apdash:datos:' + hwId, JSON.stringify({ t: Date.now(), data })); }
    catch (e) { /* sin almacenamiento o lleno: solo no se guarda */ }
}

// ────────────────────────────────────────────────────────────────
// Render
// ────────────────────────────────────────────────────────────────
function render(data) {
    fijarMoneda(data && data.extra && typeof data.extra === 'object' ? data.extra.moneda : null);   // antes de derivar: arma textos con importes
    const d = derivar(data);
    if (estructura !== FIRMA_DOM) {
        montar();
        estructura = FIRMA_DOM;
    }
    ultimoD = d;
    pintar(d);
    actualizarBanner();
}

function marcarCargando(on) {
    cargando = on;
    const b = el('btnRefrescar');
    if (b) b.classList.toggle('is-loading', on);
}

async function fetchData(hwId, isFirstLoad) {
    if (cargando) return;
    if (isFirstLoad) showLoading();
    marcarCargando(true);
    try {
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 12000);
        const tk = tokenGuardado();
        const res = await fetch(`${API_BASE}?id=${encodeURIComponent(hwId)}&_t=${Date.now()}`,
            { signal: controller.signal, headers: tk ? { 'X-Dash-Token': tk } : {} });
        clearTimeout(timeoutId);
        if (res.status === 404) {
            desdeCache = null;
            showError('Este POS aún no ha sincronizado datos. Realiza una venta primero.');
            return;
        }
        if (res.status === 401) {
            // Panel con PIN: sin token (o vencido) no se entrega nada
            let j = null;
            try { j = await res.json(); } catch (e) { /* sin cuerpo */ }
            if (j && j.status === 'protegido') {
                const teniaSesion = !!tk;
                borrarToken();
                borrarCache(hwId);
                desdeCache = null;
                ultimoOk = null;
                showPin(teniaSesion ? 'Tu sesión venció. Escribe el PIN otra vez.' : '');
                return;
            }
        }
        if (res.status === 403) {
            // Sin Premium ni días gratis el servidor no entrega datos (y lo guardado en el teléfono tampoco se muestra)
            let j = null;
            try { j = await res.json(); } catch (e) { /* sin cuerpo */ }
            if (j && j.status === 'premium_requerido') {
                borrarCache(hwId);
                desdeCache = null;
                ultimoOk = null;
                showPremium(j);
                return;
            }
        }
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (data.status !== 'exitoso') throw new Error(data.message || 'Error desconocido');
        ultimoOk   = Date.now();
        desdeCache = null;
        guardarCache(hwId, data);
        render(data);
    } catch (err) {
        console.error('Dashboard fetch error:', err);
        if (ultimoOk === null && !desdeCache) {
            showError('No se pudo conectar al servidor. ' + err.message);
        } else {
            actualizarBanner();   // se conserva la vista con aviso de datos viejos
        }
    } finally {
        marcarCargando(false);
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

// ── Service worker ──────────────────────────────────────────────
// Cuando sube una versión nueva del panel, la app instalada la recoge sola
// (una vez): antes seguía sirviendo la copia vieja hasta cerrarla dos veces.
function registrarServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    const habiaControlador = !!navigator.serviceWorker.controller;
    let recargado = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!habiaControlador || recargado) return;   // primera instalación: nada que recargar
        recargado = true;
        window.location.reload();
    });
    navigator.serviceWorker.register('/dashboard/sw.js', { scope: '/dashboard/' }).catch(() => {});
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
    // /dashboard/T-<código> es el panel de todas las tiendas: lo atiende tiendas.js (se carga después de este archivo)
    if (/^T-/.test(hwId)) return;
    hwIdActual = hwId;

    const app = document.getElementById('app');
    app.addEventListener('click', alClic);
    app.addEventListener('submit', ev => { if (ev.target && ev.target.matches('[data-k="pinForm"]')) enviarPin(ev); });
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const cambio = () => { if (!temaGuardado()) pintarTema(); };
        if (mq.addEventListener) mq.addEventListener('change', cambio);
    }

    instalarManifest(hwId);
    registrarServiceWorker();

    // Lo guardado de hoy se muestra de inmediato mientras llega lo nuevo
    const guardado = leerCache(hwId);
    if (guardado) {
        desdeCache = { t: guardado.t };
        const data = Object.assign({}, guardado.data);
        data.hace_minutos = (Number(data.hace_minutos) || 0) + Math.floor((Date.now() - guardado.t) / 60000);
        render(data);
    }

    fetchData(hwId, !guardado);
    refreshTimer = setInterval(() => fetchData(hwId, false), REFRESH_MS);
    bannerTimer  = setInterval(actualizarBanner, 30000);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchData(hwId, false);
    });
    window.addEventListener('online',  () => fetchData(hwId, false));
    window.addEventListener('offline', actualizarBanner);
})();
