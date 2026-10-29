/* ================================================================
   Tu página privada de datos (/admin/). Pide cada sección a api.php y
   la pinta. Sin librerías: las gráficas son SVG y HTML propios.

   · Todo texto que viene de la base entra con textContent (h(), s()),
     nunca con innerHTML: nombres de negocio, comentarios y correos los
     escribió otra persona.
   · La CSP no deja estilos en línea: los anchos se ponen con el.style
     (CSSOM) y los colores con clases.
   · Las fechas llegan en hora de México ('YYYY-MM-DD HH:MM:SS') y se
     comparan contra `ahora` del servidor, no contra el reloj de quien mira.
   ================================================================ */
(function () {
    'use strict';

    var SECCIONES = ['resumen', 'descargas', 'registros', 'computadoras', 'tiendas', 'premium', 'comentarios'];
    var vista = document.getElementById('vista');
    var tip = document.getElementById('tooltip');
    var cache = {};
    var hoy = '';
    var ahora = '';
    var observadores = [];

    function leer(clave, porOmision) {
        try { var v = localStorage.getItem('apadmin:' + clave); return v === null ? porOmision : v; } catch (e) { return porOmision; }
    }
    function guardar(clave, valor) {
        try { localStorage.setItem('apadmin:' + clave, valor); } catch (e) { /* sin almacenamiento: no pasa nada */ }
    }
    var periodo = leer('periodo', '30');

    // ── DOM ─────────────────────────────────────────────────────────────
    function agregar(el, hijos) {
        if (hijos === null || hijos === undefined) return;
        if (!Array.isArray(hijos)) hijos = [hijos];
        hijos.forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
        });
    }
    function h(tag, props, hijos) {
        var el = document.createElement(tag);
        if (props) Object.keys(props).forEach(function (k) {
            var v = props[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'class') el.className = v;
            else if (k === 'text') el.textContent = v;
            else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
            else if (k === 'style') Object.keys(v).forEach(function (p) { el.style[p] = v[p]; });
            else el.setAttribute(k, v === true ? '' : v);
        });
        agregar(el, hijos);
        return el;
    }
    var NS = 'http://www.w3.org/2000/svg';
    function s(tag, attrs, hijos) {
        var el = document.createElementNS(NS, tag);
        if (attrs) Object.keys(attrs).forEach(function (k) { if (attrs[k] !== null && attrs[k] !== undefined) el.setAttribute(k, attrs[k]); });
        agregar(el, hijos);
        return el;
    }

    // ── Formatos ────────────────────────────────────────────────────────
    var nf0 = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 });
    var nf1 = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 1 });
    var nf2 = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    var MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    var DIAS_LARGOS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    function n(v) { return v === null || v === undefined || v === '' ? 0 : Number(v); }
    function num(v) { return nf0.format(n(v)); }
    function compacto(v) {
        v = n(v);
        var a = Math.abs(v);
        if (a >= 1e6) return nf1.format(v / 1e6) + ' M';
        if (a >= 1e4) return nf0.format(v / 1e3) + ' mil';
        if (a >= 1e3) return nf1.format(v / 1e3) + ' mil';
        return nf0.format(v);
    }
    function simbolo(moneda) { return moneda === 'EUR' ? '€' : '$'; }
    function dinero(centavos, moneda, conCentavos) {
        var v = n(centavos) / 100;
        var signo = v < 0 ? '−' : '';
        return signo + simbolo(moneda) + (conCentavos ? nf2 : nf0).format(Math.abs(v));
    }
    function dineroCompacto(centavos, moneda) {
        var v = n(centavos) / 100;
        return (v < 0 ? '−' : '') + simbolo(moneda) + compacto(Math.abs(v));
    }
    function pct(x) { return nf0.format(Math.round(x * 100)) + ' %'; }

    function partes(t) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(t || '');
        if (!m) return null;
        return { a: +m[1], m: +m[2], d: +m[3], h: m[4] ? +m[4] : 0, mi: m[5] ? +m[5] : 0, s: m[6] ? +m[6] : 0, conHora: !!m[4] };
    }
    function ms(t) { var p = partes(t); return p ? Date.UTC(p.a, p.m - 1, p.d, p.h, p.mi, p.s) : NaN; }
    function fecha(t) {
        var p = partes(t);
        if (!p) return '—';
        var anio = hoy && +hoy.slice(0, 4) === p.a ? '' : ' ' + p.a;
        return p.d + ' ' + MESES[p.m - 1] + anio;
    }
    function hora(p) { return (p.h < 10 ? '0' : '') + p.h + ':' + (p.mi < 10 ? '0' : '') + p.mi; }
    function fechaHora(t) {
        var p = partes(t);
        if (!p) return '—';
        return fecha(t) + (p.conHora ? ', ' + hora(p) : '');
    }
    function diasDesde(t) { return Math.floor((ms(hoy) - ms((t || '').slice(0, 10))) / 86400000); }
    function hace(t) {
        var d = (ms(ahora) - ms(t)) / 1000;
        if (isNaN(d)) return '—';
        if (d < 60) return 'hace un momento';
        if (d < 3600) return 'hace ' + Math.floor(d / 60) + ' min';
        if (d < 86400) return 'hace ' + Math.floor(d / 3600) + ' h';
        var dias = Math.floor(d / 86400);
        if (dias === 1) return 'ayer';
        if (dias < 60) return 'hace ' + dias + ' días';
        return fecha(t);
    }
    function venceTexto(t) { return t && t.slice(0, 4) === '2199' ? 'Para siempre' : fecha(t); }

    // Cómo se lee cada punto de una serie según su agrupación (día, semana, mes)
    function etiquetaClave(k, gran, larga) {
        var p = partes(k);
        if (!p) return k;
        if (gran === 'mes') return larga ? MESES_LARGOS[p.m - 1] + ' ' + p.a : MESES[p.m - 1] + (larga ? '' : ' ' + String(p.a).slice(2));
        var base = p.d + ' ' + MESES[p.m - 1];
        if (gran === 'semana') return larga ? 'Semana del ' + base + ' ' + p.a : base;
        if (!larga) return base;
        var dia = new Date(Date.UTC(p.a, p.m - 1, p.d)).getUTCDay();
        return DIAS_LARGOS[(dia + 6) % 7] + ' ' + base + ' ' + p.a;
    }
    function textoPeriodoAnterior(P) {
        if (!P || !P.antes_desde) return '';
        return { '7': 'vs. los 7 días anteriores', '30': 'vs. los 30 días anteriores', '90': 'vs. los 90 días anteriores', '365': 'vs. los 12 meses anteriores' }[P.clave] || '';
    }
    // La variación contra el periodo anterior: texto y si es buena (arriba = bueno, salvo `alReves`)
    function variacion(actual, anterior, P, alReves) {
        if (anterior === null || anterior === undefined || !P || !P.antes_desde) return null;
        actual = n(actual); anterior = n(anterior);
        var txt;
        var dir = actual > anterior ? 1 : actual < anterior ? -1 : 0;
        if (anterior === 0) txt = actual === 0 ? 'Igual' : 'Antes: 0';
        else {
            var cambio = (actual - anterior) / anterior;
            txt = (cambio > 0 ? '+' : cambio < 0 ? '−' : '') + nf0.format(Math.abs(Math.round(cambio * 100))) + ' %';
        }
        var bien = alReves ? dir < 0 : dir > 0;
        return { texto: txt + ' ' + textoPeriodoAnterior(P), clase: dir === 0 ? 'igual' : bien ? 'sube' : 'baja' };
    }

    // ── Peticiones ──────────────────────────────────────────────────────
    function api(params) {
        var q = Object.keys(params).filter(function (k) { return params[k] !== null && params[k] !== undefined; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch('api.php?' + q, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
            if (r.status === 401) { location.reload(); throw new Error('sin_sesion'); }
            return r.json().catch(function () { throw new Error('La respuesta no se pudo leer (' + r.status + ')'); }).then(function (d) {
                if (!r.ok || d.status !== 'ok') throw new Error(d.message || 'Error ' + r.status);
                return d;
            });
        });
    }
    function enviar(datos) {
        return fetch('api.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Admin': '1', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(datos).toString()
        }).then(function (r) {
            if (r.status === 401) { location.reload(); throw new Error('sin_sesion'); }
            return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'Error ' + r.status); return d; });
        });
    }

    // ── Piezas ──────────────────────────────────────────────────────────
    function tarjeta(titulo, sub, cuerpo, o) {
        o = o || {};
        var cont = h('div', { class: 'tarjeta-cuerpo' }, cuerpo);
        var cab = h('div', { class: 'tarjeta-cab' }, [h('div', null, [h('h2', { text: titulo }), sub ? h('p', { class: 'sub', text: sub }) : null])]);
        var t = h('section', { class: 'tarjeta' + (o.ancha ? ' ancha' : '') + (o.clase ? ' ' + o.clase : '') }, [cab, cont]);
        if (o.tabla) {
            var tablaEl = null;
            var b = h('button', { type: 'button', class: 'btn-texto chico', text: 'Ver tabla' });
            b.addEventListener('click', function () {
                if (!tablaEl) { tablaEl = tablaSimple(o.tabla.cols, o.tabla.filas); t.appendChild(tablaEl); tablaEl.hidden = true; }
                var mostrar = tablaEl.hidden;
                tablaEl.hidden = !mostrar;
                cont.hidden = mostrar;
                b.textContent = mostrar ? 'Ver gráfica' : 'Ver tabla';
            });
            cab.appendChild(b);
        }
        if (o.accion) cab.appendChild(o.accion);
        return t;
    }
    function dato(etiqueta, valor, o) {
        o = o || {};
        return h('div', { class: 'dato' + (o.clase ? ' ' + o.clase : '') }, [
            h('div', { class: 'dato-etq', text: etiqueta }),
            h('div', { class: 'dato-val', text: valor }),
            o.delta ? h('div', { class: 'dato-delta ' + o.delta.clase, text: o.delta.texto }) : null,
            o.nota ? h('div', { class: 'dato-nota', text: o.nota }) : null
        ]);
    }
    function fila(hijos, clase) { return h('div', { class: 'rejilla' + (clase ? ' ' + clase : '') }, hijos); }
    function vacio(texto) { return h('p', { class: 'vacio', text: texto }); }
    function estadoEtq(clase, texto) { return h('span', { class: 'estado ' + clase }, [h('span', { class: 'punto-estado', 'aria-hidden': 'true' }), texto]); }
    function enlace(texto, href) { return h('a', { href: href, text: texto }); }
    function linkEquipo(texto, id, lic) {
        if (!id && !lic) return document.createTextNode(texto);
        return h('a', { href: id ? '#equipo/' + encodeURIComponent(id) : '#licencia/' + encodeURIComponent(lic), text: texto });
    }
    function nombreEquipo(negocio, id) { return negocio && negocio.trim() ? negocio.trim() : 'Sin nombre · ' + (id || '').slice(0, 8); }
    function suma(a) { return a.reduce(function (x, y) { return x + n(y); }, 0); }

    function mostrarTip(x, y, titulo, filas) {
        tip.textContent = '';
        tip.appendChild(h('div', { class: 'tip-t', text: titulo }));
        filas.forEach(function (f) {
            tip.appendChild(h('div', { class: 'tip-f' }, [
                f.serie ? h('span', { class: 'tip-k ' + f.serie, 'aria-hidden': 'true' }) : null,
                h('strong', { text: f.valor }),
                f.nombre ? h('span', { class: 'tip-n', text: f.nombre }) : null
            ]));
        });
        tip.hidden = false;
        var r = tip.getBoundingClientRect();
        var px = x + 14, py = y + 14;
        if (px + r.width > window.innerWidth - 8) px = x - r.width - 14;
        if (py + r.height > window.innerHeight - 8) py = y - r.height - 14;
        tip.style.left = Math.max(8, px) + 'px';
        tip.style.top = Math.max(8, py) + 'px';
    }
    function ocultarTip() { tip.hidden = true; }

    // Cada gráfica se vuelve a dibujar cuando cambia el ancho de su tarjeta
    function grafica(dibujar) {
        var cont = h('div', { class: 'graf-cont' });
        var ultimo = 0;
        var ro = new ResizeObserver(function (e) {
            var w = Math.floor(e[0].contentRect.width);
            if (w > 0 && Math.abs(w - ultimo) > 2) { ultimo = w; cont.textContent = ''; dibujar(cont, w); }
        });
        ro.observe(cont);
        observadores.push(ro);
        return cont;
    }

    function escala(max, entero) {
        if (!(max > 0)) return { max: entero ? 1 : 1, ticks: [0, 1] };
        var paso0 = max / 4;
        var mag = Math.pow(10, Math.floor(Math.log10(paso0)));
        var r = paso0 / mag;
        var paso = (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * mag;
        if (entero) paso = Math.max(1, Math.round(paso));
        var tope = Math.ceil(max / paso) * paso;
        var ticks = [];
        for (var t = 0; t <= tope + paso / 2; t += paso) ticks.push(t);
        return { max: tope, ticks: ticks };
    }
    function indicesEtiqueta(total, cuantas) {
        if (total <= 0) return [];
        if (total <= cuantas) return Array.from({ length: total }, function (_, i) { return i; });
        var out = [];
        for (var k = 0; k < cuantas; k++) out.push(Math.round(k * (total - 1) / (cuantas - 1)));
        return out.filter(function (v, i, a) { return a.indexOf(v) === i; });
    }

    // Línea (una o dos series de la misma unidad: un solo eje)
    function grafLinea(o) {
        return grafica(function (cont, ancho) {
            var alto = o.alto || 200, m = { t: 14, r: 16, b: 26, l: 50 };
            var iw = Math.max(20, ancho - m.l - m.r), ih = alto - m.t - m.b;
            var total = o.claves.length;
            var max = 0;
            o.series.forEach(function (se) { se.valores.forEach(function (v) { if (n(v) > max) max = n(v); }); });
            var e = escala(max, o.entero);
            var X = function (i) { return m.l + (total <= 1 ? iw / 2 : i * iw / (total - 1)); };
            var Y = function (v) { return m.t + ih - (n(v) / e.max) * ih; };
            var raiz = s('svg', { class: 'graf', width: ancho, height: alto, viewBox: '0 0 ' + ancho + ' ' + alto, tabindex: '0',
                                  role: 'img', 'aria-label': o.titulo || 'Gráfica' });
            e.ticks.forEach(function (t) {
                raiz.appendChild(s('line', { class: 'rejilla-l', x1: m.l, x2: m.l + iw, y1: Y(t), y2: Y(t) }));
                raiz.appendChild(s('text', { class: 'eje', x: m.l - 8, y: Y(t) + 4, 'text-anchor': 'end' }, (o.fmtEje || compacto)(t)));
            });
            indicesEtiqueta(total, Math.max(2, Math.min(7, Math.floor(iw / 78)))).forEach(function (i, k, a) {
                var anc = a.length === 1 ? 'middle' : k === 0 ? 'start' : k === a.length - 1 ? 'end' : 'middle';
                raiz.appendChild(s('text', { class: 'eje', x: X(i), y: alto - 6, 'text-anchor': anc }, etiquetaClave(o.claves[i], o.gran, false)));
            });
            o.series.forEach(function (se, k) {
                var cls = se.color || 's' + (k + 1);
                var d = se.valores.map(function (v, i) { return (i ? 'L' : 'M') + X(i).toFixed(1) + ',' + Y(v).toFixed(1); }).join('');
                if (o.series.length === 1 && total > 1) {
                    raiz.appendChild(s('path', { class: 'area ' + cls, d: d + 'L' + X(total - 1).toFixed(1) + ',' + Y(0) + 'L' + X(0).toFixed(1) + ',' + Y(0) + 'Z' }));
                }
                if (total > 1) raiz.appendChild(s('path', { class: 'linea ' + cls, d: d }));
                var ult = se.valores[total - 1];
                raiz.appendChild(s('circle', { class: 'punto ' + cls, cx: X(total - 1), cy: Y(ult), r: 4 }));
                if (o.series.length === 1) {
                    raiz.appendChild(s('text', { class: 'etq-fin', x: X(total - 1) - 8, y: Math.max(m.t + 10, Y(ult) - 10), 'text-anchor': 'end' },
                                       (o.fmt || num)(ult)));
                }
            });
            var guia = s('line', { class: 'guia', x1: 0, x2: 0, y1: m.t, y2: m.t + ih, visibility: 'hidden' });
            raiz.appendChild(guia);
            var puntos = o.series.map(function (se, k) {
                var c = s('circle', { class: 'punto ' + (se.color || 's' + (k + 1)), r: 4, visibility: 'hidden' });
                raiz.appendChild(c);
                return c;
            });
            var capa = s('rect', { x: m.l - 6, y: 0, width: iw + 12, height: alto, fill: 'transparent' });
            raiz.appendChild(capa);
            var actual = total - 1;
            function marcar(i, cx, cy) {
                actual = i;
                guia.setAttribute('x1', X(i)); guia.setAttribute('x2', X(i)); guia.setAttribute('visibility', 'visible');
                puntos.forEach(function (c, k) {
                    c.setAttribute('cx', X(i)); c.setAttribute('cy', Y(o.series[k].valores[i])); c.setAttribute('visibility', 'visible');
                });
                mostrarTip(cx, cy, etiquetaClave(o.claves[i], o.gran, true), o.series.map(function (se, k) {
                    return { serie: se.color || 's' + (k + 1), valor: (o.fmt || num)(se.valores[i]), nombre: o.series.length > 1 ? se.nombre : '' };
                }));
            }
            function soltar() { guia.setAttribute('visibility', 'hidden'); puntos.forEach(function (c) { c.setAttribute('visibility', 'hidden'); }); ocultarTip(); }
            capa.addEventListener('pointermove', function (ev) {
                var r = raiz.getBoundingClientRect();
                var i = total <= 1 ? 0 : Math.round((ev.clientX - r.left - m.l) / iw * (total - 1));
                marcar(Math.max(0, Math.min(total - 1, i)), ev.clientX, ev.clientY);
            });
            capa.addEventListener('pointerleave', soltar);
            raiz.addEventListener('keydown', function (ev) {
                if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
                ev.preventDefault();
                var i = Math.max(0, Math.min(total - 1, actual + (ev.key === 'ArrowLeft' ? -1 : 1)));
                var r = raiz.getBoundingClientRect();
                marcar(i, r.left + X(i), r.top + m.t);
            });
            raiz.addEventListener('blur', soltar);
            cont.appendChild(raiz);
        });
    }

    // Columnas (apiladas si hay varias series)
    function grafColumnas(o) {
        return grafica(function (cont, ancho) {
            var alto = o.alto || 190, m = { t: 18, r: 8, b: 24, l: 46 };
            var iw = Math.max(20, ancho - m.l - m.r), ih = alto - m.t - m.b;
            var total = o.etiquetas.length;
            var sumas = o.etiquetas.map(function (_, i) { return o.series.reduce(function (a, se) { return a + n(se.valores[i]); }, 0); });
            var max = Math.max.apply(null, sumas.concat([0]));
            var e = escala(max, o.entero);
            var Y = function (v) { return m.t + ih - (v / e.max) * ih; };
            var banda = iw / Math.max(1, total);
            var bw = Math.max(2, Math.min(24, banda - 2));
            var raiz = s('svg', { class: 'graf', width: ancho, height: alto, viewBox: '0 0 ' + ancho + ' ' + alto, role: 'img', 'aria-label': o.titulo || 'Gráfica' });
            e.ticks.forEach(function (t) {
                raiz.appendChild(s('line', { class: 'rejilla-l', x1: m.l, x2: m.l + iw, y1: Y(t), y2: Y(t) }));
                raiz.appendChild(s('text', { class: 'eje', x: m.l - 8, y: Y(t) + 4, 'text-anchor': 'end' }, (o.fmtEje || compacto)(t)));
            });
            var cada = o.cada || Math.max(1, Math.ceil(total / Math.max(2, Math.floor(iw / 44))));
            var imax = sumas.indexOf(max);
            o.etiquetas.forEach(function (etq, i) {
                var x = m.l + i * banda + (banda - bw) / 2;
                var g = s('g', { class: 'col' });
                var base = 0;
                var segs = o.series.map(function (se) { return n(se.valores[i]); });
                var ultimo = -1;
                segs.forEach(function (v, k) { if (v > 0) ultimo = k; });
                segs.forEach(function (v, k) {
                    if (v <= 0) return;
                    var yb = Y(base), yt = Y(base + v);
                    base += v;
                    if (k !== ultimo) yt = Math.min(yb, yt + 2);   // 2px del fondo entre segmentos
                    var hgt = yb - yt;
                    if (hgt <= 0) return;
                    var r = k === ultimo ? Math.min(4, bw / 2, hgt) : 0;
                    var d = 'M' + x + ',' + yb + 'L' + x + ',' + (yt + r) + (r ? 'Q' + x + ',' + yt + ' ' + (x + r) + ',' + yt : '') +
                            'L' + (x + bw - r) + ',' + yt + (r ? 'Q' + (x + bw) + ',' + yt + ' ' + (x + bw) + ',' + (yt + r) : '') +
                            'L' + (x + bw) + ',' + yb + 'Z';
                    g.appendChild(s('path', { class: 'barra ' + (o.series[k].color || 's' + (k + 1)), d: d }));
                });
                if (i === imax && max > 0 && o.etiquetaMax !== false) {
                    g.appendChild(s('text', { class: 'etq-fin', x: x + bw / 2, y: Y(max) - 6, 'text-anchor': 'middle' }, (o.fmtCorto || o.fmt || num)(max)));
                }
                var hit = s('rect', { x: m.l + i * banda, y: m.t, width: banda, height: ih, fill: 'transparent' });
                hit.addEventListener('pointermove', function (ev) {
                    g.classList.add('sobre');
                    var filas = o.series.map(function (se, k) { return { serie: se.color || 's' + (k + 1), valor: (o.fmt || num)(se.valores[i]), nombre: o.series.length > 1 ? se.nombre : '' }; });
                    if (o.series.length > 1) filas.push({ valor: (o.fmt || num)(sumas[i]), nombre: 'en total' });
                    mostrarTip(ev.clientX, ev.clientY, (o.titulos || o.etiquetas)[i], filas);
                });
                hit.addEventListener('pointerleave', function () { g.classList.remove('sobre'); ocultarTip(); });
                g.appendChild(hit);
                raiz.appendChild(g);
                if (i % cada === 0) raiz.appendChild(s('text', { class: 'eje', x: x + bw / 2, y: alto - 6, 'text-anchor': 'middle' }, etq));
            });
            cont.appendChild(raiz);
        });
    }

    function leyenda(series) {
        return h('div', { class: 'leyenda' }, series.map(function (se, k) {
            return h('span', { class: 'leyenda-i' }, [h('span', { class: 'leyenda-k ' + (se.forma || 'linea') + ' ' + (se.color || 's' + (k + 1)), 'aria-hidden': 'true' }), se.nombre]);
        }));
    }

    // Barras horizontales: etiqueta, barra y cifra
    function listaBarras(filas, o) {
        o = o || {};
        if (!filas.length) return vacio(o.vacio || 'Sin datos en este periodo.');
        var max = o.max !== undefined ? o.max : Math.max.apply(null, filas.map(function (f) { return n(f.valor); }).concat([0]));
        return h('div', { class: 'barras' }, filas.map(function (f) {
            var ancho = max > 0 ? Math.max(0.5, n(f.valor) / max * 100) : 0;
            return h('div', { class: 'barras-f' }, [
                h('div', { class: 'barras-etq' }, [f.enlace ? enlace(f.etiqueta, f.enlace) : f.etiqueta, f.nota ? h('span', { class: 'barras-nota', text: f.nota }) : null]),
                h('div', { class: 'barras-pista' }, h('div', { class: 'barras-relleno ' + (f.color || 's1'), style: { width: ancho + '%' } })),
                h('div', { class: 'barras-val', text: (o.fmt || num)(f.valor) })
            ]);
        }));
    }
    // Las primeras N y el resto en «Otros» (nunca más colores: es una sola serie)
    function topYOtros(filas, cuantos) {
        if (filas.length <= cuantos + 1) return filas;
        var resto = filas.slice(cuantos).reduce(function (a, f) { return a + n(f.valor); }, 0);
        return filas.slice(0, cuantos).concat([{ etiqueta: 'Otros (' + (filas.length - cuantos) + ')', valor: resto }]);
    }

    function paso5(v, max) { return v <= 0 || !(max > 0) ? 0 : 1 + Math.min(4, Math.floor(v / max * 5 - 1e-9)); }

    // Día de la semana × hora
    function mapaCalor(matriz, unidad) {
        var max = 0;
        matriz.forEach(function (r) { r.forEach(function (v) { if (v > max) max = v; }); });
        if (max === 0) return vacio('Sin datos en este periodo.');
        var rej = h('div', { class: 'calor', role: 'grid' });
        rej.appendChild(h('span', { class: 'calor-h' }));
        for (var hh = 0; hh < 24; hh++) rej.appendChild(h('span', { class: 'calor-h', text: hh % 6 === 0 ? String(hh) : '' }));
        matriz.forEach(function (r, d) {
            rej.appendChild(h('span', { class: 'calor-d', text: DIAS[d] }));
            r.forEach(function (v, hh) {
                var c = h('span', { class: 'calor-c q' + paso5(v, max), tabindex: '-1' });
                var titulo = DIAS_LARGOS[d] + ', ' + hh + ':00 a ' + hh + ':59';
                c.addEventListener('pointermove', function (ev) { mostrarTip(ev.clientX, ev.clientY, titulo, [{ valor: num(v), nombre: unidad }]); });
                c.addEventListener('pointerleave', ocultarTip);
                rej.appendChild(c);
            });
        });
        return h('div', null, [rej, escalaLeyenda()]);
    }
    function escalaLeyenda() {
        return h('div', { class: 'escala' }, [h('span', { text: 'Menos' })].concat([1, 2, 3, 4, 5].map(function (q) {
            return h('span', { class: 'escala-c q' + q, 'aria-hidden': 'true' });
        })).concat([h('span', { text: 'Más' })]));
    }

    // Cada mes en que empezaron computadoras: qué parte lo siguió usando cada mes después
    function tablaCohortes(cohortes) {
        if (!cohortes || !cohortes.length) return vacio('Todavía no hay meses que comparar.');
        var largo = Math.max.apply(null, cohortes.map(function (c) { return c.despues.length; }));
        var cab = [h('th', { text: 'Empezaron en' }), h('th', { class: 'num', text: 'Equipos' })];
        for (var j = 0; j < largo; j++) cab.push(h('th', { class: 'num', text: j === 0 ? 'Ese mes' : '+' + j }));
        var filas = cohortes.slice().reverse().map(function (c) {
            var p = partes(c.mes + '-01');
            var celdas = [h('td', { text: MESES[p.m - 1] + ' ' + p.a }), h('td', { class: 'num', text: num(c.total) })];
            for (var j = 0; j < largo; j++) {
                if (j >= c.despues.length) { celdas.push(h('td')); continue; }
                var x = c.total ? c.despues[j] / c.total : 0;
                var td = h('td', { class: 'num coh q' + paso5(x, 1), text: pct(x) });
                (function (v, tot, jj, mes) {
                    td.addEventListener('pointermove', function (ev) {
                        mostrarTip(ev.clientX, ev.clientY, mes + (jj === 0 ? ', el mismo mes' : ', ' + jj + (jj === 1 ? ' mes' : ' meses') + ' después'),
                                   [{ valor: num(v) + ' de ' + num(tot), nombre: 'lo usaron' }]);
                    });
                    td.addEventListener('pointerleave', ocultarTip);
                })(c.despues[j], c.total, j, MESES_LARGOS[p.m - 1] + ' ' + p.a);
                celdas.push(td);
            }
            return h('tr', null, celdas);
        });
        return h('div', { class: 'tabla-cont' }, h('table', { class: 'tabla cohortes' }, [h('thead', null, h('tr', null, cab)), h('tbody', null, filas)]));
    }

    // Un año de días: cuáles usó el programa
    function calendario(dias) {
        var m = {};
        var max = 0;
        dias.forEach(function (d) { m[d.d] = n(d.n); if (n(d.n) > max) max = n(d.n); });
        var fin = ms(hoy);
        var dow = (new Date(fin).getUTCDay() + 6) % 7;
        var inicio = fin - (52 * 7 + dow) * 86400000;
        var rej = h('div', { class: 'cal' });
        var mesAnterior = -1;
        var cab = h('div', { class: 'cal-meses' });
        for (var t = inicio, col = 0; t <= fin; t += 86400000) {
            var f = new Date(t);
            var clave = f.toISOString().slice(0, 10);
            var d = (f.getUTCDay() + 6) % 7;
            if (d === 0 || t === inicio) {
                col++;
                if (f.getUTCMonth() !== mesAnterior) {
                    mesAnterior = f.getUTCMonth();
                    var etq = h('span', { text: MESES[mesAnterior] });
                    etq.style.gridColumnStart = String(col);
                    cab.appendChild(etq);
                }
            }
            var v = m[clave] || 0;
            var c = h('span', { class: 'cal-c q' + paso5(v, max) });
            c.style.gridColumnStart = String(col);
            c.style.gridRowStart = String(d + 1);
            (function (cl, val) {
                c.addEventListener('pointermove', function (ev) { mostrarTip(ev.clientX, ev.clientY, etiquetaClave(cl, 'dia', true), [{ valor: num(val), nombre: val === 1 ? 'evento' : 'eventos' }]); });
                c.addEventListener('pointerleave', ocultarTip);
            })(clave, v);
            rej.appendChild(c);
        }
        return h('div', { class: 'cal-cont' }, [cab, rej, escalaLeyenda()]);
    }

    // Tabla con búsqueda, orden, «ver más» y descarga en CSV
    function tabla(cols, filas, o) {
        o = o || {};
        var q = '';
        var filtro = o.filtros ? 0 : -1;
        var orden = o.orden || { clave: cols[0].clave, desc: false };
        var limite = o.porPagina || 50;
        var raiz = h('div', { class: 'tabla-raiz' });
        var barra = h('div', { class: 'tabla-barra' });
        var cuerpo = h('div', { class: 'tabla-cont' });
        var pie = h('div', { class: 'tabla-pie' });
        if (o.buscar) {
            var inp = h('input', { type: 'search', class: 'buscar', placeholder: o.buscar, 'aria-label': o.buscar });
            inp.addEventListener('input', function () { q = inp.value.trim().toLowerCase(); limite = o.porPagina || 50; pintar(); });
            barra.appendChild(inp);
        }
        if (o.filtros) {
            var seg = h('div', { class: 'segmentado chico', role: 'radiogroup' });
            o.filtros.forEach(function (f, i) {
                var b = h('button', { type: 'button', role: 'radio', text: f.etiqueta, 'aria-checked': i === 0 ? 'true' : 'false' });
                b.addEventListener('click', function () {
                    filtro = i; limite = o.porPagina || 50;
                    Array.prototype.forEach.call(seg.children, function (x, k) { x.setAttribute('aria-checked', k === i ? 'true' : 'false'); });
                    pintar();
                });
                seg.appendChild(b);
            });
            barra.appendChild(seg);
        }
        if (o.csv) {
            var bcsv = h('button', { type: 'button', class: 'btn-texto chico', text: 'Descargar CSV' });
            bcsv.addEventListener('click', function () { descargarCsv(o.csv, cols, visibles()); });
            barra.appendChild(bcsv);
        }
        if (barra.children.length) raiz.appendChild(barra);
        raiz.appendChild(cuerpo);
        raiz.appendChild(pie);
        function valor(c, f) { return c.valor ? c.valor(f) : f[c.clave]; }
        function visibles() {
            var lista = filas;
            if (filtro >= 0) lista = lista.filter(o.filtros[filtro].fn);
            if (q) lista = lista.filter(function (f) {
                return cols.some(function (c) { var v = c.buscar ? c.buscar(f) : valor(c, f); return v !== null && v !== undefined && String(v).toLowerCase().indexOf(q) >= 0; });
            });
            var col = cols.filter(function (c) { return c.clave === orden.clave; })[0] || cols[0];
            return lista.slice().sort(function (a, b) {
                var x = valor(col, a), y = valor(col, b);
                var vacioX = x === null || x === undefined || x === '', vacioY = y === null || y === undefined || y === '';
                if (vacioX !== vacioY) return vacioX ? 1 : -1;
                var r = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'es', { numeric: true });
                return orden.desc ? -r : r;
            });
        }
        function pintar() {
            var lista = visibles();
            cuerpo.textContent = '';
            pie.textContent = '';
            if (!lista.length) { cuerpo.appendChild(vacio(q ? 'Nada coincide con «' + q + '».' : (o.vacio || 'Sin datos.'))); return; }
            var cab = h('tr', null, cols.map(function (c) {
                var activa = c.clave === orden.clave;
                var th = h('th', { class: (c.num ? 'num ' : '') + 'ordenable', scope: 'col', 'aria-sort': activa ? (orden.desc ? 'descending' : 'ascending') : 'none' },
                           h('button', { type: 'button', text: c.titulo + (activa ? (orden.desc ? ' ↓' : ' ↑') : '') }));
                th.firstChild.addEventListener('click', function () {
                    orden = activa ? { clave: c.clave, desc: !orden.desc } : { clave: c.clave, desc: !!c.num || !!c.descPrimero };
                    pintar();
                });
                return th;
            }));
            var cuerpoT = h('tbody', null, lista.slice(0, limite).map(function (f) {
                var tr = h('tr', null, cols.map(function (c) {
                    var v = c.fmt ? c.fmt(f) : c.num ? num(valor(c, f)) : valor(c, f);
                    return h('td', { class: c.num ? 'num' : c.clase || null }, v === null || v === undefined || v === '' ? '—' : v);
                }));
                if (o.alClic) {
                    tr.classList.add('clic');
                    tr.tabIndex = 0;
                    tr.addEventListener('click', function (ev) { if (ev.target.closest('a,button')) return; o.alClic(f); });
                    tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') o.alClic(f); });
                }
                return tr;
            }));
            cuerpo.appendChild(h('table', { class: 'tabla' }, [h('thead', null, cab), cuerpoT]));
            if (lista.length > limite) {
                var mas = h('button', { type: 'button', class: 'btn-texto', text: 'Ver ' + Math.min(lista.length - limite, 100) + ' más (son ' + num(lista.length) + ')' });
                mas.addEventListener('click', function () { limite += 100; pintar(); });
                pie.appendChild(mas);
            } else if (lista.length > 10) {
                pie.appendChild(h('span', { class: 'nota', text: num(lista.length) + ' en total' }));
            }
        }
        pintar();
        return raiz;
    }
    function tablaSimple(cols, filas) {
        return h('div', { class: 'tabla-cont' }, h('table', { class: 'tabla' }, [
            h('thead', null, h('tr', null, cols.map(function (c, i) { return h('th', { class: i ? 'num' : null, text: c }); }))),
            h('tbody', null, filas.map(function (f) { return h('tr', null, f.map(function (v, i) { return h('td', { class: i ? 'num' : null, text: v }); })); }))
        ]));
    }
    function descargarCsv(nombre, cols, filas) {
        var esc = function (v) { v = v === null || v === undefined ? '' : String(v); return /[",\n;]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        var lineas = [cols.map(function (c) { return esc(c.titulo); }).join(',')].concat(filas.map(function (f) {
            return cols.map(function (c) { return esc(c.csv ? c.csv(f) : c.valor ? c.valor(f) : f[c.clave]); }).join(',');
        }));
        var url = URL.createObjectURL(new Blob(['﻿' + lineas.join('\n')], { type: 'text/csv;charset=utf-8' }));
        var a = h('a', { href: url, download: nombre });
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function copiar(texto, boton) {
        var listo = function () { var t = boton.textContent; boton.textContent = 'Copiado'; setTimeout(function () { boton.textContent = t; }, 1400); };
        if (navigator.clipboard) navigator.clipboard.writeText(texto).then(listo, function () {});
    }
    function botonCopiar(texto, etiqueta) {
        var b = h('button', { type: 'button', class: 'btn-texto chico', text: etiqueta || 'Copiar' });
        b.addEventListener('click', function () { copiar(texto, b); });
        return b;
    }

    // ── Textos de la base ───────────────────────────────────────────────
    var EVENTOS = { login: 'Inicios de sesión', cierre_turno: 'Cortes de turno', salida_app: 'Salidas del programa' };
    var EVENTO_1 = { login: 'Inició sesión', cierre_turno: 'Hizo corte de turno', salida_app: 'Salió del programa' };
    var SESIONES = { nuevo_turno: 'Abrió un turno nuevo', continuacion: 'Siguió un turno abierto',
                     admin_override: 'Un administrador entró con el turno de otro', modo_informe: 'Entró solo a ver informes', '': 'Sin dato' };
    var ROLES = { administrador: 'Administradores', cajero: 'Cajeros', '': 'Sin dato' };
    var ORIGENES = { pos: 'Desde el programa', panel: 'Desde el panel del celular', web: 'Desde la página' };
    var ESTADOS = { pagada: 'Pagada', pendiente: 'OXXO sin pagar', vencida: 'OXXO vencida' };
    var FUENTES = { tienda: 'Compra en la tienda', a_mano: 'Clave a mano', panel: 'Vista en el panel' };
    var TIPOS = { premium: 'Premium', multi: 'Varias sucursales' };
    var AVISOS_STRIPE = { 'checkout.session.completed': 'Pago terminado (o ficha OXXO creada)', 'checkout.session.async_payment_succeeded': 'OXXO pagado',
                          'checkout.session.async_payment_failed': 'OXXO no se pagó', 'checkout.session.expired': 'Pago abandonado o vencido',
                          'invoice.paid': 'Factura pagada (renovación)' };
    var UNIDADES = ['pz', 'kg', 'L', 'ud'];

    function estadoEquipo(ultima) {
        var d = diasDesde(ultima);
        if (d <= 7) return estadoEtq('bien', 'Activa');
        if (d <= 30) return estadoEtq('aviso', 'Poco activa');
        return estadoEtq('apagado', 'Dormida');
    }
    function cantidad(milesimas, unidad) {
        var v = n(milesimas) / 1000;
        return (Number.isInteger(v) ? nf0 : nf1).format(v) + ' ' + (UNIDADES[unidad] || '');
    }

    // ── Secciones ───────────────────────────────────────────────────────
    function vistaResumen(d) {
        var P = d.periodo, out = [];
        var tiles = [];
        if (d.descargas) tiles.push(dato('Descargas', num(d.descargas.actual), { delta: variacion(d.descargas.actual, d.descargas.anterior, P) }));
        if (d.equipos) {
            tiles.push(dato('Computadoras nuevas', num(d.equipos.nuevas), { delta: variacion(d.equipos.nuevas, d.equipos.nuevas_antes, P) }));
            tiles.push(dato('Computadoras que lo usaron', num(d.equipos.activas), { delta: variacion(d.equipos.activas, d.equipos.activas_antes, P) }));
        }
        if (d.ventas) tiles.push(dato('Ingresos de Premium', dinero(d.ventas.actual.ingresos), {
            delta: variacion(d.ventas.actual.ingresos, d.ventas.anterior ? d.ventas.anterior.ingresos : null, P),
            nota: num(d.ventas.actual.pagos) + (d.ventas.actual.pagos === 1 ? ' pago' : ' pagos')
        }));
        if (tiles.length) out.push(fila(tiles, 'datos'));

        var base = [];
        if (d.equipos) {
            base.push(dato('Hoy', num(d.equipos.hoy), { nota: 'computadoras' }));
            base.push(dato('Últimos 7 días', num(d.equipos.semana), { nota: 'computadoras' }));
            base.push(dato('Últimos 30 días', num(d.equipos.mes), { nota: 'computadoras' }));
            base.push(dato('Desde siempre', num(d.equipos.total), { nota: 'computadoras' }));
        }
        if (d.tiendas) base.push(dato('Con panel del celular', num(d.tiendas.total), { nota: num(d.tiendas.hoy) + ' mandaron datos hoy' }));
        if (d.premium) base.push(dato('Con Premium', num(d.premium.premium), { nota: d.premium.multi ? num(d.premium.multi) + ' con varias sucursales' : 'vigente hoy' }));
        if (base.length) out.push(tarjeta('Quién lo usa', 'Computadoras con el programa abierto, sin contar las apartadas', h('div', { class: 'datos-chicos' }, base)));
        var grids = [];

        if (d.embudo) {
            // No es un embudo estricto (una computadora de anteayer «lo sigue usando» sin haber vuelto otro día):
            // cada paso se cuenta contra las nuevas, no contra el paso anterior
            var e = d.embudo, nuevas = n(e.nuevas);
            var filas = [
                ['Volvieron otro día', e.volvieron, 'lo usaron 2 días o más'],
                ['Lo siguen usando', e.siguen, 'lo abrieron en los últimos 7 días'],
                ['Tienen el panel del celular', e.panel, ''],
                ['Tienen o tuvieron Premium', e.premium, '']
            ].filter(function (p) { return p[1] !== null && p[1] !== undefined; }).map(function (p) {
                return { etiqueta: p[0], valor: p[1], nota: [nuevas ? pct(n(p[1]) / nuevas) + ' de las nuevas' : '', p[2]].filter(Boolean).join(' · ') };
            });
            var sub = 'Empezaron en este periodo';
            if (e.descargas !== null && e.descargas !== undefined) {
                sub = num(e.descargas) + ' descargas en el mismo periodo' + (e.descargas ? ': ' + nf0.format(nuevas / e.descargas * 100) + ' computadoras nuevas por cada 100' : '');
            }
            grids.push(tarjeta(nuevas === 1 ? 'La computadora nueva' : 'Las ' + num(nuevas) + ' computadoras nuevas', sub,
                               nuevas ? listaBarras(filas, { max: nuevas }) : vacio('Ninguna computadora empezó a usarlo en este periodo.')));
        }
        grids.push(tarjeta('Pendientes', 'Lo que conviene revisar', pendientes(d.pendientes || {}, d)));
        out.push(fila(grids));

        var graf = [];
        if (d.descargas) graf.push(tarjeta('Descargas', porGran(P), sumar(d.descargas.serie) ? grafLinea({ claves: P.claves, gran: P.gran, entero: true, titulo: 'Descargas', series: [{ nombre: 'Descargas', valores: d.descargas.serie }] }) : vacio('No hubo descargas en este periodo.'),
                                       { tabla: tablaSerie(P, [['Descargas', d.descargas.serie]]) }));
        if (d.equipos) graf.push(tarjeta('Computadoras que lo usaron', porGran(P), sumar(d.equipos.serie_activas) ? grafLinea({ claves: P.claves, gran: P.gran, entero: true, titulo: 'Computadoras activas', series: [{ nombre: 'Computadoras', valores: d.equipos.serie_activas }] }) : vacio('Nadie abrió el programa en este periodo.'),
                                     { tabla: tablaSerie(P, [['Computadoras', d.equipos.serie_activas]]) }));
        if (graf.length) out.push(fila(graf));
        return out;
    }
    function sumar(a) { return a ? suma(a) : 0; }
    function porGran(P) { return P.gran === 'dia' ? 'Por día' : P.gran === 'semana' ? 'Por semana (de lunes a domingo)' : 'Por mes'; }
    function tablaSerie(P, series, fmt) {
        return { cols: [P.gran === 'dia' ? 'Día' : P.gran === 'semana' ? 'Semana' : 'Mes'].concat(series.map(function (x) { return x[0]; })),
                 filas: P.claves.map(function (k, i) { return [etiquetaClave(k, P.gran, true)].concat(series.map(function (x) { return (fmt || num)(x[1][i]); })); }).reverse() };
    }
    function pendientes(p, d) {
        var items = [];
        var item = function (txt, href) { items.push(h('li', null, href ? enlace(txt, href) : txt)); };
        if (p.registros > 0) item(num(p.registros) + (p.registros === 1 ? ' persona pidió' : ' personas pidieron') + ' el link en /gratis y no les has escrito', '#registros');
        if (p.comentarios > 0) item(num(p.comentarios) + (p.comentarios === 1 ? ' mensaje' : ' mensajes') + ' desde el programa esta semana', '#comentarios');
        if (p.oxxo > 0) item(num(p.oxxo) + (p.oxxo === 1 ? ' ficha OXXO sin pagar' : ' fichas OXXO sin pagar'), '#premium');
        if (p.vencen > 0) item(num(p.vencen) + (p.vencen === 1 ? ' Premium vence' : ' Premium vencen') + ' en los próximos 14 días (sin contar mensuales)', '#premium');
        if (p.se_fueron > 0) item(num(p.se_fueron) + (p.se_fueron === 1 ? ' computadora dejó' : ' computadoras dejaron') + ' de usarlo hace 30 a 60 días (lo habían usado 3 días o más)', '#computadoras');
        if (p.bloqueados > 0) item(num(p.bloqueados) + (p.bloqueados === 1 ? ' panel bloqueado' : ' paneles bloqueados') + ' por PIN incorrecto ahora mismo');
        var lista = items.length ? h('ul', { class: 'pendientes' }, items) : vacio('Nada pendiente.');
        var pie = [];
        if (p.stripe) pie.push(h('p', { class: 'nota', text: 'Último aviso de Stripe: ' + hace(p.stripe) + '.' }));
        if (d.ocultos > 0) pie.push(h('p', { class: 'nota', text: 'Sin contar ' + num(d.ocultos) + (d.ocultos === 1 ? ' equipo apartado' : ' equipos apartados') + ' (tuyos o de pruebas).' }));
        return [lista].concat(pie);
    }

    function vistaDescargas(d) {
        var P = d.periodo, x = d.datos, out = [];
        if (!x) return [vacio('No se pudo leer la tabla de descargas.')];
        var tiles = [
            dato('Descargas', num(x.actual), { delta: variacion(x.actual, x.anterior, P) }),
            dato('Desde direcciones distintas', num(x.ips), { nota: 'aprox. personas o lugares' }),
            dato('Desde siempre', num(x.total))
        ];
        if (d.nuevas !== null && d.nuevas !== undefined && x.actual > 0) {
            tiles.push(dato('Empezaron a usarlo', num(d.nuevas), { nota: 'computadoras nuevas: ' + nf0.format(d.nuevas / x.actual * 100) + ' por cada 100 descargas' }));
        }
        out.push(fila(tiles, 'datos'));
        out.push(tarjeta('Descargas', porGran(P), x.actual ? grafLinea({ claves: P.claves, gran: P.gran, entero: true, titulo: 'Descargas', series: [{ nombre: 'Descargas', valores: x.serie }] }) : vacio('No hubo descargas en este periodo.'),
                         { ancha: true, tabla: tablaSerie(P, [['Descargas', x.serie]]) }));
        var paises = x.paises.map(function (f) { return { etiqueta: f.nombre, valor: n(f.valor !== undefined ? f.valor : f.n) }; });
        var ciudades = x.ciudades.map(function (f) { return { etiqueta: f.ciudad + (f.pais ? ', ' + f.pais : ''), valor: n(f.n) }; });
        out.push(fila([
            tarjeta('Por país', null, listaBarras(topYOtros(paises, 8))),
            tarjeta('Por ciudad', 'Las 15 con más descargas', listaBarras(ciudades.slice(0, 15)))
        ]));
        var horas = x.horas.map(function (_, i) { return String(i); });
        out.push(fila([
            tarjeta('A qué hora descargan', 'Hora de México', grafColumnas({ etiquetas: horas, titulos: horas.map(function (k) { return k + ':00 a ' + k + ':59'; }), entero: true, cada: 3, series: [{ nombre: 'Descargas', valores: x.horas }] }),
                    { tabla: { cols: ['Hora', 'Descargas'], filas: x.horas.map(function (v, i) { return [i + ':00', num(v)]; }) } }),
            tarjeta('Qué día de la semana', null, grafColumnas({ etiquetas: DIAS, titulos: DIAS_LARGOS, entero: true, cada: 1, series: [{ nombre: 'Descargas', valores: x.semana }] }),
                    { tabla: { cols: ['Día', 'Descargas'], filas: x.semana.map(function (v, i) { return [DIAS_LARGOS[i], num(v)]; }) } })
        ]));
        var rep = x.repetidas.ips
            ? num(x.repetidas.ips) + (x.repetidas.ips === 1 ? ' dirección descargó' : ' direcciones descargaron') + ' más de una vez (' + num(x.repetidas.descargas) + ' descargas entre todas).'
            : 'Nadie descargó dos veces desde la misma dirección en este periodo.';
        out.push(tarjeta('Las más recientes', rep, tabla([
            { clave: 'fecha', titulo: 'Cuándo', fmt: function (f) { return fechaHora(f.fecha); } },
            { clave: 'ciudad', titulo: 'Ciudad' },
            { clave: 'pais', titulo: 'País' }
        ], x.ultimas, { orden: { clave: 'fecha', desc: true }, porPagina: 15 }), { ancha: true }));
        return out;
    }

    // ── Registros de /gratis (los anuncios de TikTok) ───────────────────
    // Quien dejó su WhatsApp en antiparticula.com/gratis. El link de descarga no sale de tu número: la página se lo da
    // y la persona se lo manda a sí misma. Aquí va el seguimiento: escribirle con el mensaje ya armado (y anotar que le
    // escribiste), la BAJA que promete el aviso de privacidad y borrar sus datos si lo pide.
    var FIRMA = 'Erick';   // quien firma los mensajes de seguimiento
    var SISTEMAS = { windows: 'Windows', macArm: 'Mac con chip Apple', macIntel: 'Mac con chip Intel' };
    var TRAMOS = ['En menos de 1 hora', 'De 1 a 24 horas', 'De 1 a 3 días', 'Después de 3 días'];

    function mensajeSeguimiento(r) {
        var nombre = (r.nombre || '').trim().split(/\s+/)[0];
        var hola = '¡Hola' + (nombre ? ' ' + nombre : '') + '! Soy ' + FIRMA + ', de Antipartícula. ';
        if (r.descargo_en) return hola + 'Vi que ya descargaste el punto de venta. ¿Pudiste instalarlo? Si quieres, te ayudo a dar de alta tus productos.';
        return hola + 'Vi que pediste el punto de venta gratis. Este es tu link para abrirlo en tu computadora: ' + r.link + ' ¿Te ayudo a instalarlo?';
    }
    function porEscribirles(lista) { return lista.filter(function (r) { return !r.contactado_en && !r.baja_en; }); }
    // +525512345678 → 55 1234 5678 (los de otro país, tal cual)
    function telVisible(t) {
        var m = /^\+52(\d{2})(\d{4})(\d{4})$/.exec(t || '');
        return m ? m[1] + ' ' + m[2] + ' ' + m[3] : t;
    }

    function vistaRegistros(d) {
        var P = d.periodo, x = d.datos, lista = d.lista, giros = d.giros || {}, out = [];
        if (!x || !lista) return [vacio('No se pudo leer la tabla de registros de /gratis.')];
        var tiles = [
            dato('Registros', num(x.actual), { delta: variacion(x.actual, x.anterior, P), nota: 'dejaron su WhatsApp en /gratis' }),
            dato('Descargaron', num(x.descargaron), { nota: x.actual ? pct(x.descargaron / x.actual) + ' de los registros del periodo' : 'de los registros del periodo' }),
            dato('De un anuncio', num(x.de_anuncio), { nota: 'llegaron con el clic de TikTok' })
        ];
        var tilePend = d.seguimiento ? dato('Por escribirles', num(porEscribirles(lista).length), { nota: 'de siempre, sin contar BAJA' }) : null;
        if (tilePend) tiles.splice(2, 0, tilePend);
        out.push(fila(tiles, 'datos'));
        var series = [{ nombre: 'Registros', valores: x.serie, color: 's1' }, { nombre: 'De esos, descargaron', valores: x.serie_descargaron, color: 's3' }];
        out.push(tarjeta('Registros', porGran(P), x.actual ? [leyenda(series), grafLinea({ claves: P.claves, gran: P.gran, entero: true, titulo: 'Registros', series: series })] : vacio('Nadie se registró en este periodo.'),
                         { ancha: true, tabla: tablaSerie(P, [['Registros', x.serie], ['De esos, descargaron', x.serie_descargaron]]) }));
        out.push(fila([
            tarjeta('Por giro', 'Cuántos se registraron y cuántos de ellos descargaron', listaBarras(x.giros.map(function (g) {
                return { etiqueta: giros[g.giro] || g.giro, valor: n(g.n), nota: num(g.descargaron) + ' descargaron' };
            }))),
            tarjeta('Cuánto tardan en descargar', 'Desde que se registran en el celular', x.descargaron
                ? listaBarras(x.tardanza.map(function (v, i) { return { etiqueta: TRAMOS[i], valor: v }; }))
                : vacio('Nadie de este periodo ha descargado todavía.'))
        ]));

        // La lista: de siempre, la más nueva primero
        var filtros = [
            ['Por escribirles', function (r) { return !r.contactado_en && !r.baja_en; }],
            ['Sin descargar', function (r) { return !r.descargo_en && !r.baja_en; }],
            ['Descargaron', function (r) { return !!r.descargo_en; }],
            ['Todos', function () { return true; }],
            ['BAJA', function (r) { return !!r.baja_en; }]
        ];
        if (!d.seguimiento) filtros = filtros.slice(1, 4);
        var filtro = 0, q = '', limite = 40;
        var cont = h('div', { class: 'mensajes' });

        function boton(texto, fn) {
            var b = h('button', { type: 'button', class: 'btn-texto chico', text: texto });
            b.addEventListener('click', function () { fn(b); });
            return b;
        }
        function accion(r, a, b) {
            if (b) b.disabled = true;
            enviar({ accion: a, id: r.id }).then(function (res) {
                if (a === 'escrito') r.contactado_en = res.ahora;
                else if (a === 'baja') r.baja_en = r.baja_en || res.ahora;
                else if (a === 'quitar_baja') r.baja_en = null;
                else if (a === 'borrar_registro') lista.splice(lista.indexOf(r), 1);
                if (tilePend) tilePend.querySelector('.dato-val').textContent = num(porEscribirles(lista).length);
                Object.keys(cache).forEach(function (k) { if (k.indexOf('resumen|') === 0) delete cache[k]; });
                pintarLista();
            }, function (e) {
                if (b) b.disabled = false;
                if (e.message !== 'sin_sesion') window.alert('No se pudo guardar: ' + e.message);
            });
        }
        function tarjetaRegistro(r) {
            var estado = r.baja_en ? estadoEtq('apagado', 'Pidió BAJA ' + hace(r.baja_en))
                : r.descargo_en ? estadoEtq('bien', 'Descargó ' + hace(r.descargo_en) + (SISTEMAS[r.sistema] ? ' · ' + SISTEMAS[r.sistema] : ''))
                : estadoEtq('aviso', 'Todavía no descarga');
            var datos = [h('span', { class: 'nota', text: 'Se registró ' + hace(r.creado) + ' (' + fechaHora(r.creado) + ') · código ' + r.codigo })];
            if (r.contactado_en) datos.push(h('span', { class: 'nota', text: 'Le escribiste ' + hace(r.contactado_en) }));
            var acciones = [];
            if (!r.baja_en) {
                var wa = h('a', { href: 'https://wa.me/' + waNumero(r.telefono) + '?text=' + encodeURIComponent(mensajeSeguimiento(r)),
                                  target: '_blank', rel: 'noopener', text: (r.contactado_en ? 'Volver a escribir' : 'Escribir por WhatsApp') + ' · ' + telVisible(r.telefono) });
                // WhatsApp se abre en otra pestaña con el mensaje ya escrito; aquí solo se anota que le escribiste
                if (d.seguimiento) wa.addEventListener('click', function () { accion(r, 'escrito'); });
                acciones.push(wa);
            }
            acciones.push(h('a', { href: 'tel:' + r.telefono, text: 'Llamar' }));
            if (d.seguimiento) {
                acciones.push(boton(r.baja_en ? 'Quitar BAJA' : 'Pidió BAJA', function (b) { accion(r, r.baja_en ? 'quitar_baja' : 'baja', b); }));
                acciones.push(boton('Borrar sus datos', function (b) {
                    if (window.confirm('¿Borrar para siempre el registro de ' + (r.nombre || telVisible(r.telefono)) + '? Hazlo solo si te pidió que borraras sus datos.')) accion(r, 'borrar_registro', b);
                }));
            }
            return h('article', { class: 'mensaje' + (d.seguimiento && !r.contactado_en && !r.baja_en ? ' nuevo' : '') }, [
                h('div', { class: 'mensaje-cab' }, [
                    h('strong', { text: (r.nombre || 'Sin nombre') + ' · ' + (giros[r.giro] || r.giro) }),
                    r.de_anuncio ? h('span', { class: 'chip', text: 'De un anuncio' }) : null,
                    estado
                ]),
                h('div', { class: 'mensaje-pie' }, datos),
                h('div', { class: 'mensaje-pie' }, acciones)
            ]);
        }
        function pintarLista() {
            cont.textContent = '';
            var vis = lista.filter(filtros[filtro][1]).filter(function (r) {
                return !q || [r.nombre, r.telefono, telVisible(r.telefono), r.telefono.replace(/^\+52/, ''), r.codigo, giros[r.giro]].some(function (v) { return v && String(v).toLowerCase().indexOf(q) >= 0; });
            });
            if (!vis.length) {
                cont.appendChild(vacio(q ? 'Nada coincide con «' + q + '».' : d.seguimiento && filtro === 0 ? 'Ya les escribiste a todos.' : 'Nadie aquí.'));
                return;
            }
            vis.slice(0, limite).forEach(function (r) { cont.appendChild(tarjetaRegistro(r)); });
            if (vis.length > limite) {
                var mas = h('button', { type: 'button', class: 'btn-texto', text: 'Ver más (son ' + num(vis.length) + ')' });
                mas.addEventListener('click', function () { limite += 60; pintarLista(); });
                cont.appendChild(mas);
            }
        }
        var seg = h('div', { class: 'segmentado chico', role: 'radiogroup' }, filtros.map(function (f, i) {
            var b = h('button', { type: 'button', role: 'radio', text: f[0], 'aria-checked': i === 0 ? 'true' : 'false' });
            b.addEventListener('click', function () {
                filtro = i; limite = 40;
                Array.prototype.forEach.call(seg.children, function (y, k) { y.setAttribute('aria-checked', k === i ? 'true' : 'false'); });
                pintarLista();
            });
            return b;
        }));
        var inp = h('input', { type: 'search', class: 'buscar', placeholder: 'Buscar nombre o WhatsApp', 'aria-label': 'Buscar por nombre, WhatsApp o código' });
        inp.addEventListener('input', function () { q = inp.value.trim().toLowerCase(); limite = 40; pintarLista(); });
        pintarLista();
        out.push(tarjeta('Para darles seguimiento', 'El link de descarga no sale de tu número: la página se lo da a cada quien. Aquí les escribes tú, con el mensaje ya armado.',
                         [h('div', { class: 'tabla-barra' }, [inp, seg]), cont], { ancha: true }));
        return out;
    }

    function vistaComputadoras(d) {
        var P = d.periodo, u = d.uso, out = [];
        var lista = d.lista || [];
        var contadas = lista.filter(function (f) { return !f.oculto; });
        if (u) {
            var ev = {};
            u.eventos.forEach(function (e) { ev[e.nombre] = n(e.n); });
            out.push(fila([
                dato('Lo usaron', num(u.activas), { delta: variacion(u.activas, u.activas_antes, P), nota: 'computadoras' }),
                dato('Nuevas', num(u.nuevas), { delta: variacion(u.nuevas, u.nuevas_antes, P), nota: 'empezaron en este periodo' }),
                dato('Inicios de sesión', num(ev.login || 0)),
                dato('Cortes de turno', num(ev.cierre_turno || 0))
            ], 'datos'));
            var series = [{ nombre: 'Lo usaron', valores: u.serie_activas, color: 's1' }, { nombre: 'Nuevas', valores: u.serie_nuevas, color: 's2' }];
            out.push(tarjeta('Computadoras', porGran(P), [leyenda(series), grafLinea({ claves: P.claves, gran: P.gran, entero: true, titulo: 'Computadoras activas y nuevas', series: series })],
                             { ancha: true, tabla: tablaSerie(P, [['Lo usaron', u.serie_activas], ['Nuevas', u.serie_nuevas]]) }));
        }
        var cards = [];
        if (d.cohortes) cards.push(tarjeta('¿Lo siguen usando?', 'Por mes en que empezaron: qué parte lo abrió cada mes después', tablaCohortes(d.cohortes), { ancha: true }));
        if (d.versiones && d.versiones.filas.length) cards.push(tarjetaVersiones(d.versiones));
        if (contadas.length) {
            var viejas = contadas.filter(function (f) { return diasDesde(f.primera) > 30; });
            var tramos = [['Un solo día', 1, 1], ['2 a 3 días', 2, 3], ['4 a 7 días', 4, 7], ['8 a 30 días', 8, 30], ['Más de 30 días', 31, 1e9]];
            var filasT = tramos.map(function (t) {
                var cuantas = viejas.filter(function (f) { return f.dias >= t[1] && f.dias <= t[2]; }).length;
                return { etiqueta: t[0], valor: cuantas, nota: viejas.length ? pct(cuantas / viejas.length) : '' };
            });
            cards.push(tarjeta('¿Cuántos días lo usaron?', 'De las ' + num(viejas.length) + ' que empezaron hace más de 30 días', viejas.length ? listaBarras(filasT) : vacio('Todavía no hay computadoras con más de 30 días.')));
            var usuarios = [['Un usuario', 1, 1], ['2 usuarios', 2, 2], ['3 o más', 3, 1e9]].map(function (t) {
                var c = contadas.filter(function (f) { return f.usuarios >= t[1] && f.usuarios <= t[2]; }).length;
                return { etiqueta: t[0], valor: c, nota: pct(c / contadas.length) };
            });
            cards.push(tarjeta('Cuántas personas usan cada una', 'Usuarios distintos que han entrado, desde siempre', listaBarras(usuarios)));
        }
        if (u) {
            cards.push(tarjeta('Cuándo inician sesión', 'Hora de México, en este periodo', mapaCalor(u.horas, 'inicios de sesión'), { ancha: true }));
            cards.push(tarjeta('Cómo entran', 'Inicios de sesión de este periodo', listaBarras(u.sesiones.map(function (s1) { return { etiqueta: SESIONES[s1.nombre] || s1.nombre, valor: n(s1.n) }; }))));
            cards.push(tarjeta('Quién entra', null, listaBarras(u.roles.map(function (r) {
                return { etiqueta: ROLES[r.nombre] || r.nombre, valor: n(r.n), nota: num(r.personas) + (n(r.personas) === 1 ? ' persona' : ' personas') + ' · inicios de sesión' };
            }))));
        }
        if (contadas.length) {
            var porPais = {};
            contadas.forEach(function (f) { var k = f.pais || 'Desconocido'; porPais[k] = (porPais[k] || 0) + 1; });
            var filasP = Object.keys(porPais).map(function (k) { return { etiqueta: k, valor: porPais[k] }; }).sort(function (a, b) { return b.valor - a.valor; });
            var porCiudad = {};
            contadas.forEach(function (f) { var k = (f.ciudad || 'Desconocida') + (f.pais ? ', ' + f.pais : ''); porCiudad[k] = (porCiudad[k] || 0) + 1; });
            var filasC = Object.keys(porCiudad).map(function (k) { return { etiqueta: k, valor: porCiudad[k] }; }).sort(function (a, b) { return b.valor - a.valor; });
            cards.push(tarjeta('Dónde están', 'Computadoras por país (el último lugar desde el que se conectaron)', listaBarras(topYOtros(filasP, 6))));
            cards.push(tarjeta('Por ciudad', 'Las 12 con más computadoras', listaBarras(filasC.slice(0, 12))));
            var fueron = contadas.filter(function (f) { var x = diasDesde(f.ultima); return f.dias >= 3 && x > 30 && x <= 120; });
            cards.push(tarjeta('Dejaron de usarlo', 'Lo usaron 3 días o más y llevan de 30 a 120 días sin abrirlo', tabla([
                { clave: 'negocio', titulo: 'Negocio', valor: function (f) { return nombreEquipo(f.negocio, f.id); }, fmt: function (f) { return linkEquipo(nombreEquipo(f.negocio, f.id), f.id); } },
                { clave: 'dias', titulo: 'Días de uso', num: true },
                { clave: 'ultima', titulo: 'Última vez', fmt: function (f) { return fecha(f.ultima); }, descPrimero: true }
            ], fueron, { orden: { clave: 'ultima', desc: true }, porPagina: 10, vacio: 'Ninguna: bien.' }), { ancha: true }));
        }
        if (cards.length) out.push(fila(cards));
        if (d.lista) out.push(tarjeta('Todas las computadoras', 'Toca una para ver todo lo que se sabe de ella', tablaEquipos(lista), { ancha: true }));
        return out;
    }
    // Las de hasta 3.6.0 no mandaban la versión: un evento sin ella es de una de esas
    var ULTIMA_SIN_VERSION = '3.6.0';
    function textoVersion(v) { return v === '' ? ULTIMA_SIN_VERSION + ' o anterior' : v; }
    // Como número, parte por parte: la 3.10.0 es más nueva que la 3.9.0
    function compararVersion(a, b) {
        var x = String(a).split('.'), y = String(b).split('.');
        for (var i = 0; i < Math.max(x.length, y.length); i++) {
            var dif = (parseInt(x[i], 10) || 0) - (parseInt(y[i], 10) || 0);
            if (dif) return dif;
        }
        return 0;
    }
    // Junto a la versión de una computadora: si es la publicada, una más nueva (la de pruebas) o cuál es la publicada
    function notaVersion(v, publicada) {
        if (!publicada) return null;
        if (v === publicada) return 'la publicada';
        if (v !== '' && compararVersion(v, publicada) > 0) return 'más nueva que la publicada (' + publicada + ')';
        return 'la publicada es la ' + publicada;
    }
    function tarjetaVersiones(V) {
        var total = V.filas.reduce(function (a, f) { return a + n(f.n); }, 0);
        var sub = 'Las que lo usaron en este periodo, con la versión con que entraron la última vez';
        if (V.publicada) {
            sub += '. La publicada es la ' + V.publicada;
            // Mientras la publicada sea una de las que no mandan su versión no se puede saber quién la tiene
            if (compararVersion(V.publicada, ULTIMA_SIN_VERSION) > 0) {
                var alDia = V.filas.filter(function (f) { return f.version !== '' && compararVersion(f.version, V.publicada) >= 0; })
                                   .reduce(function (a, f) { return a + n(f.n); }, 0);
                sub += ': ' + (alDia === total ? 'todas la tienen' : num(alDia) + ' de ' + num(total) + ' la tienen');
            }
        }
        return tarjeta('En qué versión están', sub, listaBarras(V.filas.map(function (f) {
            return { etiqueta: textoVersion(f.version), valor: n(f.n), nota: (f.version === V.publicada ? 'la publicada · ' : '') + pct(n(f.n) / total) };
        })));
    }

    function tablaEquipos(lista) {
        var conVersion = lista.some(function (f) { return f.version !== null && f.version !== undefined; });
        return tabla([
            { clave: 'negocio', titulo: 'Negocio', valor: function (f) { return nombreEquipo(f.negocio, f.id); },
              buscar: function (f) { return (f.negocio || '') + ' ' + f.id + ' ' + (f.ciudad || ''); },
              fmt: function (f) { return [linkEquipo(nombreEquipo(f.negocio, f.id), f.id), f.oculto ? h('span', { class: 'nota', text: ' (apartada)' }) : null]; } },
            { clave: 'ciudad', titulo: 'Lugar', valor: function (f) { return [f.ciudad, f.pais].filter(Boolean).join(', '); } },
            { clave: 'estado', titulo: 'Estado', valor: function (f) { return diasDesde(f.ultima); }, fmt: function (f) { return estadoEquipo(f.ultima); }, csv: function (f) { return diasDesde(f.ultima) <= 7 ? 'Activa' : diasDesde(f.ultima) <= 30 ? 'Poco activa' : 'Dormida'; } },
            { clave: 'primera', titulo: 'Empezó', fmt: function (f) { return fecha(f.primera); }, descPrimero: true },
            { clave: 'ultima', titulo: 'Última vez', fmt: function (f) { return hace(f.ultima); }, descPrimero: true },
            { clave: 'dias', titulo: 'Días', num: true },
            { clave: 'inicios', titulo: 'Inicios', num: true },
            { clave: 'usuarios', titulo: 'Usuarios', num: true },
            conVersion ? { clave: 'version', titulo: 'Versión', valor: function (f) { return f.version === null ? '' : textoVersion(f.version); } } : null,
            { clave: 'panel', titulo: 'Panel', valor: function (f) { return f.panel ? 'Sí' : ''; } },
            { clave: 'premium', titulo: 'Premium', valor: function (f) { return f.premium || ''; },
              fmt: function (f) { return f.premium ? (f.premium >= hoy ? venceTexto(f.premium) : 'Venció ' + fecha(f.premium)) : ''; } },
            { clave: 'id', titulo: 'ID', clase: 'mono' }
        ].filter(Boolean), lista, {
            buscar: 'Buscar negocio, ciudad o ID', csv: 'computadoras.csv', orden: { clave: 'ultima', desc: true },
            filtros: [
                { etiqueta: 'Todas', fn: function (f) { return !f.oculto; } },
                { etiqueta: 'Activas', fn: function (f) { return !f.oculto && diasDesde(f.ultima) <= 7; } },
                { etiqueta: 'Dormidas', fn: function (f) { return !f.oculto && diasDesde(f.ultima) > 30; } },
                { etiqueta: 'Apartadas', fn: function (f) { return f.oculto; } }
            ],
            alClic: function (f) { location.hash = '#equipo/' + encodeURIComponent(f.id); }
        });
    }

    function vistaTiendas(d) {
        var P = d.periodo, out = [];
        if (!d.lista) return [vacio('Todavía no hay tiendas con el panel del celular.')];
        var t = d.total;
        if (d.euros > 0) out.push(h('p', { class: 'banda-aviso', text: 'Las sumas son en pesos: ' + num(d.euros) + (d.euros === 1 ? ' tienda cobra' : ' tiendas cobran') + ' en euros y no entra' + (d.euros === 1 ? '' : 'n') + ' en ellas (sí en su propia ficha).' }));
        if (t) {
            var a = t.actual;
            var antes = t.anterior;
            out.push(fila([
                dato('Vendieron', dinero(a.ventas), { delta: variacion(a.ventas, antes ? antes.ventas : null, P), nota: 'entre todas, con abonos de apartados' }),
                dato('Tickets', num(a.tickets), { delta: variacion(a.tickets, antes ? antes.tickets : null, P) }),
                dato('Ticket promedio', a.tickets ? dinero(a.ventas / a.tickets, 'MXN', true) : '—'),
                dato('Tiendas que vendieron', num(a.tiendas), { nota: 'de ' + num(d.lista.filter(function (x) { return !x.oculto; }).length) + ' con panel' })
            ], 'datos'));
            out.push(tarjeta('Lo que venden entre todas', porGran(P), a.ventas ? grafLinea({ claves: P.claves, gran: P.gran, titulo: 'Ventas', fmt: function (v) { return dinero(v); }, fmtEje: function (v) { return dineroCompacto(v); },
                                                                                         series: [{ nombre: 'Ventas', valores: t.serie_ventas }] }) : vacio('Sin ventas en este periodo.'),
                             { ancha: true, tabla: tablaSerie(P, [['Ventas', t.serie_ventas], ['Tickets', t.serie_tickets]], null) }));
        }
        var cards = [];
        if (d.pagos) {
            var pg = d.pagos;
            var partesPago = [{ nombre: 'Efectivo', v: pg.efectivo, color: 's1' }, { nombre: 'Tarjeta', v: pg.tarjeta, color: 's2' }, { nombre: 'Transferencia', v: pg.transferencia, color: 's3' }];
            var tot = partesPago.reduce(function (x, y) { return x + y.v; }, 0);
            var cuerpo = [];
            if (tot > 0) {
                var barra = h('div', { class: 'apilada', role: 'img', 'aria-label': 'Cómo cobran' });
                partesPago.forEach(function (p) {
                    if (p.v <= 0) return;
                    var seg = h('div', { class: 'apilada-s ' + p.color, style: { flexGrow: String(p.v) } });
                    seg.addEventListener('pointermove', function (ev) { mostrarTip(ev.clientX, ev.clientY, p.nombre, [{ serie: p.color, valor: dinero(p.v) + ' · ' + pct(p.v / tot) }]); });
                    seg.addEventListener('pointerleave', ocultarTip);
                    barra.appendChild(seg);
                });
                cuerpo.push(barra);
                cuerpo.push(h('div', { class: 'leyenda vertical' }, partesPago.map(function (p) {
                    return h('span', { class: 'leyenda-i' }, [h('span', { class: 'leyenda-k caja ' + p.color, 'aria-hidden': 'true' }), p.nombre, h('strong', { text: dinero(p.v) }), h('span', { class: 'nota', text: tot ? pct(p.v / tot) : '' })]);
                })));
            } else cuerpo.push(vacio('Sin cobros con desglose en este periodo.'));
            cuerpo.push(h('p', { class: 'nota', text: 'Comisión de tarjeta (con IVA): ' + dinero(pg.comision) + '. Cancelaciones y devoluciones: ' + num(pg.cancelaciones) + ' por ' + dinero(pg.cancelado) + '. ' + num(pg.dias) + ' días-tienda con desglose (desde la 3.3.0 del programa).' }));
            cards.push(tarjeta('Cómo les pagan', 'Efectivo incluye los abonos de apartados', cuerpo));
        }
        if (d.horas) {
            var hs = d.horas.tickets.map(function (_, i) { return String(i); });
            cards.push(tarjeta('A qué hora venden', 'Tickets por hora' + (d.horas.desde > P.desde ? ', desde el ' + fecha(d.horas.desde) + ' (el detalle se guarda 120 días)' : ''),
                               grafColumnas({ etiquetas: hs, titulos: hs.map(function (k) { return k + ':00 a ' + k + ':59'; }), entero: true, cada: 3, series: [{ nombre: 'Tickets', valores: d.horas.tickets }] }),
                               { tabla: { cols: ['Hora', 'Tickets', 'Vendido'], filas: d.horas.tickets.map(function (v, i) { return [i + ':00', num(v), dinero(d.horas.ventas[i])]; }) } }));
        }
        if (d.funciones && d.funciones.tiendas) {
            var f = d.funciones, base = f.tiendas;
            var filasF = [
                ['Cobran con tarjeta', f.desglose ? f.tarjeta : null], ['Cobran con transferencia', f.desglose ? f.transferencia : null],
                ['Llevan inventario con costo', f.inventario], ['Tienen apartados', f.apartados], ['Hacen presupuestos', f.presupuestos],
                ['Registran gastos este mes', f.gastos], ['Se pusieron meta diaria', f.meta], ['Protegen el panel con PIN', f.pin], ['Cobran en euros', f.euros]
            ].filter(function (x) { return x[1] !== null; }).map(function (x) { return { etiqueta: x[0], valor: x[1], nota: pct(x[1] / base) }; });
            cards.push(tarjeta('Qué funciones usan', 'De las ' + num(base) + ' tiendas con panel (tarjeta y transferencia: en este periodo)', listaBarras(filasF, { max: base })));
        }
        if (cards.length) out.push(fila(cards));
        var vivas = d.lista;
        out.push(tarjeta('Las tiendas', 'Lo que mandó cada una al panel del celular', tabla([
            { clave: 'negocio', titulo: 'Negocio', valor: function (x) { return nombreEquipo(x.negocio, x.id); },
              fmt: function (x) { return [linkEquipo(nombreEquipo(x.negocio, x.id), x.id), x.oculto ? h('span', { class: 'nota', text: ' (apartada)' }) : null]; } },
            { clave: 'sync', titulo: 'Último dato', fmt: function (x) { return hace(x.sync); }, descPrimero: true },
            { clave: 'ventas', titulo: 'Vendió', num: true, fmt: function (x) { return dinero(x.ventas, x.moneda); } },
            { clave: 'tickets', titulo: 'Tickets', num: true },
            { clave: 'dias', titulo: 'Días con venta', num: true },
            { clave: 'hoy', titulo: 'Hoy', num: true, fmt: function (x) { return dinero(x.hoy, x.moneda); } },
            { clave: 'turno', titulo: 'Turno abierto', valor: function (x) { return x.turno || ''; } },
            { clave: 'premium', titulo: 'Premium', valor: function (x) { return x.premium || ''; }, fmt: function (x) { return x.premium ? (x.premium >= hoy ? venceTexto(x.premium) : 'Venció ' + fecha(x.premium)) : ''; } },
            { clave: 'protegido', titulo: 'PIN', valor: function (x) { return x.protegido ? 'Sí' : ''; } }
        ], vivas, {
            buscar: 'Buscar tienda', csv: 'tiendas.csv', orden: { clave: 'ventas', desc: true },
            filtros: [{ etiqueta: 'Todas', fn: function (x) { return !x.oculto; } }, { etiqueta: 'Apartadas', fn: function (x) { return x.oculto; } }],
            alClic: function (x) { location.hash = '#equipo/' + encodeURIComponent(x.id); }
        }), { ancha: true }));
        return out;
    }

    function vistaPremium(d) {
        var P = d.periodo, out = [];
        var lic = d.licencias || [];
        var vigentes = lic.filter(function (l) { return l.vence >= hoy; });
        if (d.ventas) {
            var a = d.ventas.actual, b = d.ventas.anterior;
            out.push(fila([
                dato('Ingresos', dinero(a.ingresos), { delta: variacion(a.ingresos, b ? b.ingresos : null, P), nota: 'IVA incluido · ' + dinero(d.ventas.total.ingresos) + ' desde siempre' }),
                dato('Compras nuevas', num(a.nuevas), { delta: variacion(a.nuevas, b ? b.nuevas : null, P) }),
                dato('Renovaciones', num(a.renovaciones), { nota: 'cobros mensuales automáticos' }),
                dato('Con Premium hoy', num(vigentes.filter(function (l) { return l.tipo !== 'multi'; }).length),
                     { nota: num(vigentes.filter(function (l) { return l.tipo === 'multi'; }).length) + ' con varias sucursales' })
            ], 'datos'));
            var series = [{ nombre: 'Premium', valores: d.ventas.serie.premium, color: 's1', forma: 'caja' }, { nombre: 'Varias sucursales', valores: d.ventas.serie.multi, color: 's2', forma: 'caja' }];
            var etqs = P.claves.map(function (k) { return etiquetaClave(k, P.gran, false); });
            out.push(tarjeta('Ingresos', porGran(P) + ', sin las compras de prueba de Stripe',
                             a.ingresos ? [leyenda(series), grafColumnas({ etiquetas: etqs, titulos: P.claves.map(function (k) { return etiquetaClave(k, P.gran, true); }), series: series,
                                                                          fmt: function (v) { return dinero(v); }, fmtEje: function (v) { return dineroCompacto(v); }, fmtCorto: function (v) { return dineroCompacto(v); } })]
                                        : vacio('Sin ingresos en este periodo.'),
                             { ancha: true, tabla: tablaSerie(P, [['Premium', d.ventas.serie.premium], ['Varias sucursales', d.ventas.serie.multi]], function (v) { return dinero(v); }) }));
        }
        var cards = [];
        if (d.ventas) {
            cards.push(tarjeta('Por plan', 'Ingresos de este periodo', listaBarras(d.ventas.por_plan.map(function (x) {
                return { etiqueta: d.planes[x.plan] ? d.planes[x.plan].nombre : x.plan, valor: x.ingresos, nota: num(x.pagos) + (x.pagos === 1 ? ' pago' : ' pagos') };
            }), { fmt: function (v) { return dinero(v); }, vacio: 'Sin pagos en este periodo.' })));
        }
        var compras = d.compras || [];
        var reales = compras.filter(function (c) { return !c.prueba; });
        var enPeriodo = reales.filter(function (c) { return c.creada >= P.desde; });
        if (d.compras) {
            var porOrigen = {};
            enPeriodo.forEach(function (c) { porOrigen[c.origen] = (porOrigen[c.origen] || 0) + 1; });
            cards.push(tarjeta('Desde dónde compran', 'Compras empezadas en este periodo', listaBarras(Object.keys(porOrigen).map(function (k) {
                return { etiqueta: ORIGENES[k] || k, valor: porOrigen[k] };
            }).sort(function (x, y) { return y.valor - x.valor; }), { vacio: 'Nadie empezó una compra en este periodo.' })));
            var porEstado = { pagada: 0, pendiente: 0, vencida: 0 };
            enPeriodo.forEach(function (c) { porEstado[c.estado] = (porEstado[c.estado] || 0) + 1; });
            cards.push(tarjeta('Cómo terminaron', 'Compras empezadas en este periodo', listaBarras(Object.keys(porEstado).filter(function (k) { return porEstado[k] > 0 || k !== 'vencida'; }).map(function (k) {
                return { etiqueta: ESTADOS[k] || k, valor: porEstado[k] };
            }), { vacio: 'Nadie empezó una compra en este periodo.' })));
        }
        if (d.prueba) {
            var pr = d.prueba;
            cards.push(tarjeta('Prueba gratis de Excel', 'Contada por computadora, desde siempre', listaBarras([
                { etiqueta: 'Computadoras con la prueba', valor: pr.equipos, nota: num(pr.nuevos) + ' en este periodo' },
                { etiqueta: 'La usaron', valor: pr.usaron, nota: pr.equipos ? pct(pr.usaron / pr.equipos) : '' },
                { etiqueta: 'La agotaron', valor: pr.agotaron, nota: pr.usaron ? pct(pr.agotaron / pr.usaron) + ' de las que la usaron' : '' },
                { etiqueta: 'Tienen o tuvieron Premium', valor: pr.usaron_compraron, nota: pr.usaron ? pct(pr.usaron_compraron / pr.usaron) + ' de las que la usaron' : '' }
            ], { max: pr.equipos })));
        }
        if (d.stripe || d.canjes_fallidos !== null) {
            var st = [];
            if (d.stripe) {
                st.push(h('p', { text: d.stripe.ultimo ? 'Último aviso de Stripe: ' + hace(d.stripe.ultimo) + ' (' + fechaHora(d.stripe.ultimo) + ').' : 'Stripe todavía no ha mandado avisos.' }));
                if (d.stripe.tipos.length) st.push(listaBarras(d.stripe.tipos.map(function (x) { return { etiqueta: AVISOS_STRIPE[x.tipo] || x.tipo, valor: n(x.n) }; })));
            }
            if (d.canjes_fallidos !== null && d.canjes_fallidos !== undefined) st.push(h('p', { class: 'nota', text: 'Códigos mal escritos al canjear (7 días): ' + num(d.canjes_fallidos) + '.' }));
            cards.push(tarjeta('Stripe', 'Avisos de los últimos 30 días', st));
        }
        if (cards.length) out.push(fila(cards));

        var colsLic = [
            { clave: 'negocio', titulo: 'Quién', valor: function (l) { return l.negocio || l.email || l.lic || l.equipo; },
              fmt: function (l) { return linkEquipo(l.negocio || l.email || ('Equipo ' + (l.lic || l.equipo || '').slice(0, 8)), l.equipo, l.lic); } },
            { clave: 'tipo', titulo: 'Plan', valor: function (l) { return TIPOS[l.tipo] || l.tipo; } },
            { clave: 'vence', titulo: 'Vence', fmt: function (l) { return venceTexto(l.vence); } },
            { clave: 'mensual', titulo: 'Cobro', valor: function (l) { return l.mensual ? 'Mensual: se cobra solo' : ''; } },
            { clave: 'fuente', titulo: 'De dónde', valor: function (l) { return FUENTES[l.fuente] || l.fuente; } },
            { clave: 'email', titulo: 'Correo', fmt: function (l) { return l.email ? h('a', { href: 'mailto:' + l.email, text: l.email }) : ''; } }
        ];
        var limite = new Date(ms(hoy) + 45 * 86400000).toISOString().slice(0, 10);
        var hace60 = new Date(ms(hoy) - 60 * 86400000).toISOString().slice(0, 10);
        var pronto = lic.filter(function (l) { return l.vence >= hoy && l.vence <= limite; });
        var vencidas = lic.filter(function (l) { return l.vence < hoy && l.vence >= hace60; });
        out.push(fila([
            tarjeta('Vencen pronto', 'En los próximos 45 días', tabla(colsLic, pronto, { orden: { clave: 'vence', desc: false }, porPagina: 15, vacio: 'Nadie en los próximos 45 días.' })),
            tarjeta('Se les venció', 'En los últimos 60 días: para ofrecerles renovar', tabla(colsLic, vencidas, { orden: { clave: 'vence', desc: true }, porPagina: 15, vacio: 'Nadie en los últimos 60 días.' }))
        ]));
        out.push(tarjeta('Todos los que tienen Premium', num(vigentes.length) + ' vigentes hoy', tabla(colsLic, vigentes, { buscar: 'Buscar', csv: 'premium.csv', orden: { clave: 'vence', desc: false }, porPagina: 20, vacio: 'Nadie tiene Premium hoy.' }), { ancha: true }));
        if (d.compras) {
            out.push(tarjeta('Compras', 'Cada intento de compra en la tienda, la más reciente primero', tabla([
                { clave: 'creada', titulo: 'Fecha', fmt: function (c) { return fechaHora(c.creada); }, descPrimero: true },
                { clave: 'plan_nombre', titulo: 'Plan' },
                { clave: 'monto', titulo: 'Monto', num: true, fmt: function (c) { return dinero(c.monto, 'MXN', true); } },
                { clave: 'estado', titulo: 'Estado', valor: function (c) { return (ESTADOS[c.estado] || c.estado) + (c.prueba ? ' (prueba)' : ''); },
                  fmt: function (c) { return estadoEtq(c.estado === 'pagada' ? 'bien' : c.estado === 'pendiente' ? 'aviso' : 'apagado', (ESTADOS[c.estado] || c.estado) + (c.prueba ? ' · prueba' : '')); } },
                { clave: 'pagos', titulo: 'Cobros', num: true },
                { clave: 'origen', titulo: 'Desde', valor: function (c) { return ORIGENES[c.origen] || c.origen; } },
                { clave: 'email', titulo: 'Correo', fmt: function (c) { return c.email ? h('a', { href: 'mailto:' + c.email, text: c.email }) : ''; } },
                { clave: 'codigo', titulo: 'Código', clase: 'mono' },
                { clave: 'lic', titulo: 'Equipo', fmt: function (c) { return c.lic ? linkEquipo(c.lic.slice(0, 8) + '…', c.equipo, c.lic) : 'Sin canjear'; } }
            ], compras, {
                buscar: 'Buscar correo, código o plan', csv: 'compras.csv', orden: { clave: 'creada', desc: true },
                filtros: [{ etiqueta: 'Reales', fn: function (c) { return !c.prueba; } }, { etiqueta: 'Pruebas de Stripe', fn: function (c) { return c.prueba; } }]
            }), { ancha: true }));
        }
        return out;
    }

    // Número para wa.me: solo dígitos y con lada del país; uno de 10 dígitos es mexicano (52)
    function waNumero(tel) {
        var d = String(tel).replace(/\D/g, '');
        return d.length === 10 ? '52' + d : d;
    }

    function vistaComentarios(d) {
        var P = d.periodo, out = [];
        var lista = d.lista;
        if (!lista) return [vacio('No se pudo leer la tabla de comentarios.')];
        var visto = +leer('comentario_visto', '0');
        var maxId = lista.reduce(function (a, c) { return Math.max(a, +c.id); }, 0);
        var enP = lista.filter(function (c) { return c.fecha_hora >= P.desde; });
        var deWeb = lista.filter(function (c) { return c.nombre !== null; });
        var conNota = deWeb.filter(function (c) { return n(c.calificacion) > 0; });
        out.push(fila([
            dato('Desde el programa', num(enP.filter(function (c) { return c.nombre === null; }).length), { nota: 'en este periodo' }),
            dato('Opiniones de la página', num(enP.filter(function (c) { return c.nombre !== null; }).length), { nota: 'en este periodo' }),
            dato('Calificación de la página', conNota.length ? nf1.format(suma(conNota.map(function (c) { return c.calificacion; })) / conNota.length) + ' de 5' : '—', { nota: num(conNota.length) + ' opiniones, desde siempre' }),
            dato('Dejaron correo', num(enP.filter(function (c) { return c.email; }).length), { nota: 'en este periodo' })
        ], 'datos'));
        var filtro = 0, q = '';
        var cont = h('div', { class: 'mensajes' });
        var filtros = [
            ['Todos', function () { return true; }],
            ['Del programa', function (c) { return c.nombre === null; }],
            ['De la página', function (c) { return c.nombre !== null; }]
        ];
        var limite = 40;
        function pintar() {
            cont.textContent = '';
            var vis = lista.filter(filtros[filtro][1]).filter(function (c) {
                return !q || [c.comentario, c.email, c.telefono, c.nombre, c.ciudad, c.pais, c.tipo_negocio].some(function (v) { return v && String(v).toLowerCase().indexOf(q) >= 0; });
            });
            if (!vis.length) { cont.appendChild(vacio(q ? 'Nada coincide con «' + q + '».' : 'Sin mensajes.')); return; }
            vis.slice(0, limite).forEach(function (c) {
                var meta = [fechaHora(c.fecha_hora), [c.ciudad, c.pais].filter(function (x) { return x && x !== 'Desconocido' && x !== 'Desconocida'; }).join(', ')].filter(Boolean).join(' · ');
                var quien = c.nombre !== null ? [c.nombre || 'Sin nombre', c.tipo_negocio].filter(Boolean).join(' · ') : 'Desde el programa';
                cont.appendChild(h('article', { class: 'mensaje' + (+c.id > visto ? ' nuevo' : '') }, [
                    h('div', { class: 'mensaje-cab' }, [
                        h('strong', { text: quien }),
                        c.nombre !== null && n(c.calificacion) > 0 ? h('span', { class: 'estrellas', text: '★'.repeat(n(c.calificacion)) + '☆'.repeat(5 - n(c.calificacion)), 'aria-label': c.calificacion + ' de 5' }) : null,
                        +c.id > visto ? h('span', { class: 'chip', text: 'Nuevo' }) : null
                    ]),
                    h('p', { class: 'mensaje-txt', text: c.comentario }),
                    h('div', { class: 'mensaje-pie' }, [
                        h('span', { class: 'nota', text: meta }),
                        // El teléfono va primero: es por donde se responde (el correo casi nadie lo lee)
                        c.telefono ? h('a', { href: 'https://wa.me/' + waNumero(c.telefono), target: '_blank', rel: 'noopener', text: 'Responder por WhatsApp · ' + c.telefono }) : null,
                        c.telefono ? h('a', { href: 'tel:' + c.telefono, text: 'Llamar' }) : null,
                        c.email ? h('a', { href: 'mailto:' + c.email + '?subject=' + encodeURIComponent('Tu mensaje a Antipartícula'), text: 'Responder a ' + c.email }) : null
                    ])
                ]));
            });
            if (vis.length > limite) {
                var mas = h('button', { type: 'button', class: 'btn-texto', text: 'Ver más (son ' + num(vis.length) + ')' });
                mas.addEventListener('click', function () { limite += 60; pintar(); });
                cont.appendChild(mas);
            }
        }
        var seg = h('div', { class: 'segmentado chico', role: 'radiogroup' }, filtros.map(function (f, i) {
            var b = h('button', { type: 'button', role: 'radio', text: f[0], 'aria-checked': i === 0 ? 'true' : 'false' });
            b.addEventListener('click', function () {
                filtro = i; limite = 40;
                Array.prototype.forEach.call(seg.children, function (x, k) { x.setAttribute('aria-checked', k === i ? 'true' : 'false'); });
                pintar();
            });
            return b;
        }));
        var inp = h('input', { type: 'search', class: 'buscar', placeholder: 'Buscar en los mensajes', 'aria-label': 'Buscar en los mensajes' });
        inp.addEventListener('input', function () { q = inp.value.trim().toLowerCase(); limite = 40; pintar(); });
        pintar();
        out.push(tarjeta('Mensajes', 'Los del programa son privados (dudas, fallas); los de la página se publican en el sitio', [h('div', { class: 'tabla-barra' }, [inp, seg]), cont], { ancha: true }));
        // Lo que ya se vio queda marcado (solo en este navegador) al salir de la sección
        setTimeout(function () { if (maxId > visto) guardar('comentario_visto', String(maxId)); }, 1500);
        return out;
    }

    function vistaEquipo(d) {
        var out = [];
        var u = d.uso, panel = d.panel;
        var nombre = (u && u.negocios[0] && u.negocios[0].negocio) || (panel && panel.negocio) || '';
        var cab = h('div', { class: 'ficha-cab' }, [
            h('button', { type: 'button', class: 'btn-texto volver', text: 'Volver', onclick: function () {
                if (navegoDentro) history.back(); else location.hash = '#computadoras';
            } }),
            h('h1', { text: nombreEquipo(nombre, d.id || d.lic) }),
            h('div', { class: 'ficha-ids' }, [
                d.id ? h('span', null, ['ID del equipo ', h('code', { text: d.id }), ' ', botonCopiar(d.id)]) : null,
                d.lic ? h('span', null, ['ID de la licencia ', h('code', { text: d.lic }), ' ', botonCopiar(d.lic)]) : null
            ])
        ]);
        if (d.id && d.oculto !== null) {
            var oc = d.oculto;
            var b = h('button', { type: 'button', class: 'btn-texto', text: oc ? 'Volver a contarla en las cifras' : 'No contarla en las cifras (es mía o de pruebas)' });
            b.addEventListener('click', function () {
                var datos = { accion: oc ? 'contar' : 'ocultar', id: d.id };
                if (!oc) datos.nota = window.prompt('Nota para recordar por qué (opcional):', '') || '';
                b.disabled = true;
                enviar(datos).then(function () { cache = {}; cargar(); }, function (e) { b.disabled = false; window.alert(e.message); });
            });
            cab.appendChild(h('div', { class: 'ficha-acc' }, [oc ? h('span', { class: 'nota', text: 'Apartada de las cifras' + (oc.nota ? ': ' + oc.nota : '') + ' · ' }) : null, b]));
        }
        out.push(cab);
        if (u) {
            var r = u.resumen;
            var V = d.versiones;
            out.push(fila([
                dato('Empezó', fecha(r.primera)), dato('Última vez', hace(r.ultima), { nota: fechaHora(r.ultima) }),
                dato('Días de uso', num(r.dias)), dato('Inicios de sesión', num(r.inicios)), dato('Cortes de turno', num(r.cortes)),
                V && V.actual !== null ? dato('Versión', textoVersion(V.actual), { nota: notaVersion(V.actual, V.publicada) }) : null
            ].filter(Boolean), 'datos'));
            out.push(tarjeta('Cuándo lo usó', 'El último año', calendario(u.dias), { ancha: true }));
            var cards = [
                tarjeta('Quiénes entran', null, tabla([
                    { clave: 'usuario', titulo: 'Usuario' },
                    { clave: 'rol', titulo: 'Rol', valor: function (x) { return x.rol === 'administrador' ? 'Administrador' : x.rol === 'cajero' ? 'Cajero' : x.rol; } },
                    { clave: 'inicios', titulo: 'Inicios', num: true, valor: function (x) { return n(x.inicios); } },
                    { clave: 'ultima', titulo: 'Última vez', fmt: function (x) { return hace(x.ultima); }, descPrimero: true }
                ], u.usuarios, { orden: { clave: 'ultima', desc: true }, porPagina: 10 })),
                tarjeta('Desde dónde', 'Según su conexión a internet', tabla([
                    { clave: 'ciudad', titulo: 'Lugar', valor: function (x) { return [x.ciudad, x.pais].filter(Boolean).join(', '); } },
                    { clave: 'n', titulo: 'Veces', num: true, valor: function (x) { return n(x.n); } },
                    { clave: 'ultima', titulo: 'Última vez', fmt: function (x) { return fecha(x.ultima); }, descPrimero: true }
                ], u.lugares, { orden: { clave: 'ultima', desc: true }, porPagina: 10 }))
            ];
            if (V && V.historial.length > 1) cards.push(tarjeta('Versiones que ha tenido', 'Desde que el programa la manda (después de la 3.6.0)', tabla([
                { clave: 'version', titulo: 'Versión' },
                { clave: 'desde', titulo: 'Desde', fmt: function (x) { return fecha(x.desde); } },
                { clave: 'hasta', titulo: 'Hasta', fmt: function (x) { return fecha(x.hasta); }, descPrimero: true }
            ], V.historial, { orden: { clave: 'hasta', desc: true } })));
            if (u.negocios.length > 1) cards.push(tarjeta('Nombres que ha tenido', null, tabla([
                { clave: 'negocio', titulo: 'Negocio' }, { clave: 'ultima', titulo: 'Hasta', fmt: function (x) { return fecha(x.ultima); } }
            ], u.negocios, { orden: { clave: 'ultima', desc: true } })));
            out.push(fila(cards));
        } else if (d.id) {
            out.push(vacio('La telemetría no tiene registros de esta computadora.'));
        }
        if (panel) out = out.concat(fichaPanel(d));
        if (d.compras || d.claves || d.reportadas || d.prueba) out = out.concat(fichaLicencia(d));
        if (u) {
            out.push(tarjeta('Lo último que hizo', 'Hasta 300 eventos', tabla([
                { clave: 'fecha_hora', titulo: 'Cuándo', fmt: function (x) { return fechaHora(x.fecha_hora); }, descPrimero: true },
                { clave: 'tipo_evento', titulo: 'Qué', valor: function (x) { return EVENTO_1[x.tipo_evento] || x.tipo_evento; } },
                { clave: 'usuario', titulo: 'Usuario' },
                { clave: 'tipo_sesion', titulo: 'Cómo', valor: function (x) { return x.tipo_sesion ? SESIONES[x.tipo_sesion] || x.tipo_sesion : ''; } },
                { clave: 'ciudad', titulo: 'Lugar', valor: function (x) { return [x.ciudad, x.pais].filter(Boolean).join(', '); } }
            ], u.eventos, { orden: { clave: 'fecha_hora', desc: true }, porPagina: 25, csv: 'eventos-' + d.id + '.csv' }), { ancha: true }));
        }
        return out;
    }

    function fichaPanel(d) {
        var p = d.panel, e = p.extra || {}, out = [];
        var moneda = e.moneda === 'EUR' ? 'EUR' : 'MXN';
        var din = function (v) { return dinero(v, moneda); };
        var acceso = !p.premium_hasta ? 'Sin Premium' : p.premium_hasta.slice(0, 4) === '2199' ? 'Premium para siempre'
                   : p.premium_hasta >= hoy ? 'Premium hasta el ' + fecha(p.premium_hasta) : 'Premium venció el ' + fecha(p.premium_hasta);
        out.push(h('h2', { class: 'ficha-sec', text: 'Panel del celular' }));
        out.push(fila([
            dato('Último dato', hace(p.ultima_sync), { nota: fechaHora(p.ultima_sync) }),
            dato('Hoy vendió', din(n(p.ventas_hoy) + n(p.abonos_hoy)), { nota: num(p.tickets_hoy) + ' tickets · ganancia ' + din(p.ganancia_hoy) }),
            dato('Turno', +p.turno_abierto === 1 ? 'Abierto' : 'Cerrado', { nota: +p.turno_abierto === 1 ? (p.turno_usuario || '') + (p.turno_inicio ? ' desde ' + p.turno_inicio : '') : '' }),
            dato('Acceso', acceso, { nota: [p.protegido ? 'con PIN' : 'sin PIN', moneda === 'EUR' ? 'cobra en euros' : '', p.bloqueado_hasta && p.bloqueado_hasta > ahora ? 'bloqueado por PIN' : ''].filter(Boolean).join(' · ') })
        ], 'datos'));
        var hist = d.historico || [];
        if (hist.length) {
            var claves = [], ventas = [];
            var porDia = {};
            hist.forEach(function (x) { porDia[x.fecha] = x; });
            for (var t = ms(hoy) - 119 * 86400000; t <= ms(hoy); t += 86400000) {
                var k = new Date(t).toISOString().slice(0, 10);
                claves.push(k);
                ventas.push(porDia[k] ? n(porDia[k].ventas) : 0);
            }
            out.push(tarjeta('Lo que vendió', 'Los últimos 120 días', grafLinea({ claves: claves, gran: 'dia', titulo: 'Ventas', fmt: din, fmtEje: function (v) { return dineroCompacto(v, moneda); }, series: [{ nombre: 'Ventas', valores: ventas }] }),
                             { ancha: true, tabla: { cols: ['Día', 'Ventas', 'Tickets', 'Ganancia'], filas: hist.slice().reverse().map(function (x) { return [etiquetaClave(x.fecha, 'dia', true), din(x.ventas), num(x.tickets), din(x.ganancia)]; }) } }));
        }
        var cards = [];
        if (d.meses && d.meses.length) {
            var ms12 = d.meses.slice(-12);
            cards.push(tarjeta('Por mes', null, grafColumnas({ etiquetas: ms12.map(function (x) { return etiquetaClave(x.mes + '-01', 'mes', false); }), titulos: ms12.map(function (x) { return etiquetaClave(x.mes + '-01', 'mes', true); }),
                                                                series: [{ nombre: 'Ventas', valores: ms12.map(function (x) { return n(x.ventas); }) }], fmt: din, fmtEje: function (v) { return dineroCompacto(v, moneda); }, fmtCorto: function (v) { return dineroCompacto(v, moneda); } }),
                               { tabla: { cols: ['Mes', 'Ventas', 'Ganancia', 'Tickets'], filas: d.meses.slice().reverse().map(function (x) { return [etiquetaClave(x.mes + '-01', 'mes', true), din(x.ventas), din(x.ganancia), num(x.tickets)]; }) } }));
        }
        var conDesglose = hist.filter(function (x) { return x.efectivo !== null && x.efectivo !== undefined; });
        if (conDesglose.length) {
            var tot = { e: 0, t: 0, tr: 0 };
            conDesglose.slice(-30).forEach(function (x) { tot.e += n(x.efectivo); tot.t += n(x.tarjeta); tot.tr += n(x.transferencia); });
            cards.push(tarjeta('Cómo le pagan', 'Últimos 30 días con desglose', listaBarras([
                { etiqueta: 'Efectivo', valor: tot.e }, { etiqueta: 'Tarjeta', valor: tot.t }, { etiqueta: 'Transferencia', valor: tot.tr }
            ], { fmt: din })));
        }
        if (d.horas && suma(d.horas)) {
            var hs = d.horas.map(function (_, i) { return String(i); });
            cards.push(tarjeta('A qué hora vende', 'Tickets de los últimos 30 días', grafColumnas({ etiquetas: hs, titulos: hs.map(function (k) { return k + ':00 a ' + k + ':59'; }), entero: true, cada: 3, series: [{ nombre: 'Tickets', valores: d.horas }] })));
        }
        if (e.inventario) {
            var inv = e.inventario;
            cards.push(tarjeta('Inventario', 'Lo último que mandó', [
                h('div', { class: 'datos-chicos' }, [dato('Agotados', num(inv.agotados)), dato('Bajo el mínimo', num(inv.bajo_minimo)), dato('Valor a costo', din(inv.valor_costo))]),
                inv.urgentes && inv.urgentes.length ? listaBarras(inv.urgentes.map(function (x) {
                    return { etiqueta: x.nombre, valor: Math.max(0, n(x.cantidad)), nota: 'hay ' + cantidad(x.cantidad, x.unidad) + ', mínimo ' + cantidad(x.minimo, x.unidad) };
                }), { fmt: function (v) { return (Number.isInteger(v / 1000) ? nf0 : nf1).format(v / 1000); } }) : null
            ]));
        }
        if (e.top_productos && e.top_productos.mes && e.top_productos.mes.length) {
            cards.push(tarjeta('Lo que más vende', 'Este mes, por importe', listaBarras(e.top_productos.mes.map(function (x) { return { etiqueta: x.nombre, valor: x.importe, nota: cantidad(x.cantidad, x.unidad) }; }), { fmt: din })));
        }
        var otros = [];
        if (e.apartados) otros.push(dato('Apartados', num(e.apartados.activos), { nota: din(e.apartados.por_cobrar) + ' por cobrar · ' + num(e.apartados.vencidos) + ' vencidos' }));
        if (e.presupuestos) otros.push(dato('Presupuestos vigentes', num(e.presupuestos.vigentes), { nota: din(e.presupuestos.monto) + ' · ' + num(e.presupuestos.por_vencer) + ' por vencer' }));
        if (e.gastos) otros.push(dato('Gastos del mes', din(e.gastos.mes), { nota: 'hoy ' + din(e.gastos.hoy) }));
        if (e.meta_diaria) otros.push(dato('Meta diaria', din(e.meta_diaria)));
        if (e.por_cajero && e.por_cajero.length) otros.push(dato('Cajeros hoy', num(e.por_cajero.length), { nota: e.por_cajero.map(function (x) { return x.cajero; }).join(', ') }));
        if (otros.length) cards.push(tarjeta('Más de su negocio', 'Lo último que mandó', h('div', { class: 'datos-chicos' }, otros)));
        if (cards.length) out.push(fila(cards));
        return out;
    }

    function fichaLicencia(d) {
        var out = [h('h2', { class: 'ficha-sec', text: 'Premium y licencias' })];
        var cards = [];
        if (d.compras) cards.push(tarjeta('Compras', null, tabla([
            { clave: 'creada', titulo: 'Fecha', fmt: function (c) { return fecha(c.creada); }, descPrimero: true },
            { clave: 'plan_nombre', titulo: 'Plan' },
            { clave: 'monto', titulo: 'Monto', num: true, fmt: function (c) { return dinero(c.monto, 'MXN', true); } },
            { clave: 'estado', titulo: 'Estado', valor: function (c) { return (ESTADOS[c.estado] || c.estado) + (c.prueba ? ' (prueba)' : ''); } },
            { clave: 'email', titulo: 'Correo', fmt: function (c) { return c.email ? h('a', { href: 'mailto:' + c.email, text: c.email }) : ''; } },
            { clave: 'codigo', titulo: 'Código', clase: 'mono' }
        ], d.compras, { orden: { clave: 'creada', desc: true }, vacio: 'No ha comprado en la tienda.' })));
        if (d.claves) cards.push(tarjeta('Claves que le entregó la tienda', 'Para reenviarlas a mano si hace falta', tabla([
            { clave: 'creada', titulo: 'Entregada', fmt: function (k) { return fecha(k.creada); }, descPrimero: true },
            { clave: 'tipo', titulo: 'Plan', valor: function (k) { return TIPOS[k.tipo] || k.tipo; } },
            { clave: 'vence', titulo: 'Vence', fmt: function (k) { return venceTexto(k.vence); } },
            { clave: 'clave', titulo: 'Clave', fmt: function (k) { return botonCopiar(k.clave, 'Copiar clave'); } }
        ], d.claves, { orden: { clave: 'creada', desc: true }, vacio: 'Ninguna.' })));
        var extra = [];
        if (d.reportadas && d.reportadas.length) d.reportadas.forEach(function (x) { extra.push(dato('Clave a mano · ' + (TIPOS[x.tipo] || x.tipo), venceTexto(x.vence), { nota: 'la reportó el programa ' + hace(x.visto) })); });
        if (d.prueba) extra.push(dato('Prueba de Excel', 'Exportó ' + num(d.prueba.exportar_usados) + ' · importó ' + num(d.prueba.importar_usados), { nota: 'de ' + d.intentos_prueba + ' cada una · empezó ' + fecha(d.prueba.primera_vez) }));
        if (d.paneles_clave && d.paneles_clave.length) extra.push(dato('Claves que abrieron su panel', num(d.paneles_clave.length), { nota: 'la última vista ' + hace(d.paneles_clave[0].visto) }));
        if (extra.length) cards.push(tarjeta('Más', null, h('div', { class: 'datos-chicos' }, extra)));
        if (!d.lic) cards.push(vacio('No se sabe el ID de su licencia (lo manda el programa 3.5.0 o más nuevo con el panel del celular): sus compras no se pueden unir a esta ficha.'));
        out.push(fila(cards));
        return out;
    }

    // ── Navegación ──────────────────────────────────────────────────────
    function ruta() {
        var partesHash = location.hash.slice(1).split('/');
        if (partesHash[0] === 'equipo' && partesHash[1]) return { s: 'equipo', id: decodeURIComponent(partesHash[1]) };
        if (partesHash[0] === 'licencia' && partesHash[1]) return { s: 'equipo', lic: decodeURIComponent(partesHash[1]) };
        return { s: SECCIONES.indexOf(partesHash[0]) >= 0 ? partesHash[0] : 'resumen' };
    }
    function claveDe(r) { return r.s === 'equipo' ? 'equipo|' + (r.id || '') + '|' + (r.lic || '') : r.s + '|' + periodo; }

    var pintores = { resumen: vistaResumen, descargas: vistaDescargas, registros: vistaRegistros, computadoras: vistaComputadoras, tiendas: vistaTiendas,
                     premium: vistaPremium, comentarios: vistaComentarios, equipo: vistaEquipo };
    var ultimaRuta = '';
    var navegoDentro = false;   // para que «Volver» regrese a donde estabas (tiendas, premium…) y no siempre a computadoras

    function pintar(r, d) {
        hoy = d.hoy; ahora = d.ahora;
        observadores.forEach(function (o) { o.disconnect(); });
        observadores = [];
        ocultarTip();
        vista.textContent = '';
        agregar(vista, pintores[r.s](d));
        var faltan = (d.faltan || []).filter(function (x) { return x !== 'admin_ocultos' || r.s === 'equipo'; });
        if (faltan.length) {
            vista.appendChild(h('p', { class: 'nota faltan', text: 'No se muestra lo que depende de: ' + faltan.join(', ') + ' (falta en la base o no se pudo leer).' }));
            var errores = d.errores || {};
            var lineas = faltan.filter(function (x) { return errores[x]; })
                               .map(function (x) { return h('p', { class: 'mono-error', text: x + ': ' + errores[x] }); });
            if (lineas.length) vista.appendChild(h('details', { class: 'nota faltan' }, [h('summary', { text: 'Lo que respondió MySQL' })].concat(lineas)));
        }
        var nota = document.getElementById('filtros-nota');
        if (d.periodo) nota.textContent = 'Del ' + fecha(d.periodo.desde) + ' al ' + fecha(d.periodo.hasta);
    }

    function cargar() {
        var r = ruta();
        var clave = claveDe(r);
        document.getElementById('filtros').hidden = r.s === 'equipo';
        Array.prototype.forEach.call(document.querySelectorAll('#pestanas a'), function (a) {
            var activa = a.getAttribute('data-s') === (r.s === 'equipo' ? 'computadoras' : r.s);
            if (activa) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        Array.prototype.forEach.call(document.querySelectorAll('#periodo button'), function (b) {
            b.setAttribute('aria-checked', b.getAttribute('data-p') === periodo ? 'true' : 'false');
        });
        if (location.hash.slice(1) !== ultimaRuta.split('|')[0]) window.scrollTo(0, 0);
        ultimaRuta = location.hash.slice(1) + '|';
        var enCache = cache[clave];
        if (enCache) pintar(r, enCache.d);
        if (enCache && Date.now() - enCache.t < 60000) return;
        vista.classList.add('cargando');
        var params = r.s === 'equipo' ? { s: 'equipo', id: r.id || null, lic: r.lic || null } : { s: r.s, p: periodo };
        api(params).then(function (d) {
            cache[clave] = { t: Date.now(), d: d };
            if (claveDe(ruta()) !== clave) return;
            pintar(r, d);
        }, function (e) {
            if (e.message === 'sin_sesion' || claveDe(ruta()) !== clave) return;
            vista.textContent = '';
            var b = h('button', { type: 'button', class: 'btn-texto', text: 'Reintentar' });
            b.addEventListener('click', function () { delete cache[clave]; cargar(); });
            vista.appendChild(h('div', { class: 'tarjeta error' }, [h('p', { text: 'No se pudieron leer los datos: ' + e.message }), b]));
        }).then(function () { vista.classList.remove('cargando'); });
    }

    document.getElementById('periodo').addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        periodo = b.getAttribute('data-p');
        guardar('periodo', periodo);
        cargar();
    });
    document.getElementById('salir').addEventListener('click', function () {
        enviar({ accion: 'salir' }).then(function () { location.reload(); }, function () { location.reload(); });
    });
    window.addEventListener('hashchange', function () { navegoDentro = true; cargar(); });
    window.addEventListener('scroll', ocultarTip, { passive: true });
    cargar();
})();
