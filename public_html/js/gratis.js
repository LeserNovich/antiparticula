// ─── Página de los anuncios (/gratis) ────────────────────────────────────────
// El anuncio se ve en el celular, pero el programa se instala en la computadora (el recorrido completo está en
// gratis_comun.php). La tarjeta enseña UNA de tres cosas:
//   celular sin registro            → el formulario (WhatsApp, giro); registrar_gratis.php da el link personal
//   celular registrado, o con ?c=   → su link personal (antiparticula.com/d/CODIGO) para mandárselo
//   computadora                     → la descarga; con ?c= (llegó por su link) guarda el código en la cookie
//                                     ap_reg para que registrar_descarga.php sepa de qué registro es
// Usa de mensaje-exito.js: detectarSistemaBasico, guardarCookie, leerCookie, nuevoEventId y descargarPrueba.
(function () {
    'use strict';

    var GUARDADO = 'apgratis:registro';
    var CODIGO = /^[ACDEFGHJKMNPQRTUVWXY34679]{5}$/;   // GRATIS_ALFABETO de gratis_comun.php

    function $(id) { return document.getElementById(id); }

    function leerGuardado() {
        try {
            var r = JSON.parse(localStorage.getItem(GUARDADO) || 'null');
            return r && CODIGO.test(r.codigo) ? r : null;
        } catch (e) { return null; }
    }

    function guardar(registro) {
        try { localStorage.setItem(GUARDADO, JSON.stringify(registro)); } catch (e) { /* sin almacenamiento: no pasa nada */ }
    }

    function codigoDeLaUrl() {
        var m = location.search.match(/[?&]c=([^&#]+)/);
        var c = '';
        try { c = m ? decodeURIComponent(m[1]).trim().toUpperCase() : ''; } catch (e) { c = ''; }
        return CODIGO.test(c) ? c : '';
    }

    function linkDe(codigo) { return 'https://antiparticula.com/d/' + codigo; }

    function ensenar(id) {
        ['grForm', 'grListo', 'grPc'].forEach(function (x) { $(x).hidden = x !== id; });
    }

    // ── Celular: su link personal ────────────────────────────────────────────
    function mostrarListo(codigo, nombre, llegoPorElLink) {
        var link = linkDe(codigo);
        $('grListoTitulo').textContent = llegoPorElLink ? 'Este link es para tu computadora'
            : '¡Listo' + (nombre ? ', ' + nombre : '') + '!';
        $('grLink').textContent = link.replace('https://', '');
        $('grLink').setAttribute('data-link', link);
        $('grMandar').href = 'https://wa.me/?text=' +
            encodeURIComponent('Mi link para instalar el punto de venta en la computadora: ' + link);
        ensenar('grListo');
    }

    // ── Computadora: la descarga ─────────────────────────────────────────────
    function mostrarPc(codigo) {
        if (codigo) {
            $('grPcTitulo').textContent = 'Tu punto de venta está listo';
            $('grPcTexto').textContent = 'Descárgalo en esta computadora: se instala en un par de minutos.';
        }
        ensenar('grPc');
    }

    // ── Formulario ───────────────────────────────────────────────────────────
    function limpiarErrores() {
        $('grError').hidden = true;
        ['grTelefono', 'grGiro', 'grAcepta'].forEach(function (id) { $(id).removeAttribute('aria-invalid'); });
    }

    function mostrarError(mensaje, campo) {
        $('grError').textContent = mensaje;
        $('grError').hidden = false;
        if (campo) {
            campo.setAttribute('aria-invalid', 'true');
            campo.focus();
        }
    }

    function enviar(ev) {
        ev.preventDefault();
        var tel = $('grTelefono'), giro = $('grGiro'), acepta = $('grAcepta'), boton = $('grEnviar');
        limpiarErrores();
        // Lo mismo que revisa registrar_gratis.php, para avisar antes de mandar; el servidor tiene la última palabra
        if (tel.value.replace(/\D/g, '').length < 10) {
            return mostrarError('Revisa tu número de WhatsApp: son 10 dígitos, por ejemplo 55 1234 5678.', tel);
        }
        if (!giro.value) { return mostrarError('Elige el giro de tu negocio.', giro); }
        if (!acepta.checked) {
            return mostrarError('Para mandarte el link necesitamos que aceptes que te escribamos por WhatsApp.', acepta);
        }

        var eventId = nuevoEventId();
        var nombre = $('grNombre').value.trim();
        var datos = new URLSearchParams();
        datos.append('telefono', tel.value);
        datos.append('giro', giro.value);
        datos.append('nombre', nombre);
        datos.append('acepta', '1');
        datos.append('sitio', $('grSitio').value);
        datos.append('event_id', eventId);
        datos.append('ttclid', leerCookie('ap_ttclid'));
        datos.append('url', location.href);

        boton.disabled = true;
        fetch('../registrar_gratis.php', { method: 'POST', body: datos })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
            })
            .then(function (res) {
                if (res.ok && res.j.status === 'ok' && res.j.codigo) {
                    guardar({ codigo: res.j.codigo, nombre: nombre });
                    // El servidor manda el mismo evento con este event_id: TikTok cuenta uno. Si ese WhatsApp ya
                    // estaba registrado (nuevo=false), no se cuenta otra vez.
                    if (res.j.nuevo && typeof ttq !== 'undefined') {
                        ttq.track('CompleteRegistration', { content_name: 'gratis' }, { event_id: eventId });
                    }
                    mostrarListo(res.j.codigo, nombre, false);
                    // La tarjeta se encoge: si quedó arriba de la pantalla, subir hasta ella (debajo del menú fijo)
                    var arriba = $('grListo').getBoundingClientRect().top + window.pageYOffset - 90;
                    if (window.pageYOffset > arriba) { window.scrollTo(0, Math.max(0, arriba)); }
                    return;
                }
                if (res.ok) { return; }   // el campo escondido traía algo: solo los robots lo llenan
                var campos = { telefono: tel, giro: giro, acepta: acepta };
                mostrarError(res.j.message || 'No pudimos guardar tu número. Inténtalo de nuevo.', campos[res.j.campo]);
            })
            .catch(function () {
                mostrarError('No hay conexión. Revisa tu internet e inténtalo de nuevo.');
            })
            .then(function () { boton.disabled = false; });
    }

    function copiarLink() {
        var etiqueta = $('grCopiar').querySelector('span');
        var listo = function () {
            etiqueta.textContent = '¡Copiado!';
            setTimeout(function () { etiqueta.textContent = 'Copiar link'; }, 2000);
        };
        var seleccionar = function () {
            var rango = document.createRange();
            rango.selectNodeContents($('grLink'));
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(rango);
            etiqueta.textContent = 'Mantén presionado el link para copiarlo';
        };
        var link = $('grLink').getAttribute('data-link');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(listo, seleccionar);
        } else {
            seleccionar();
        }
    }

    // ── Cifras reales (get_stats.php): la que no llega, o vale 0, no se enseña ─
    function ponerCifra(id, n) {
        if (typeof n !== 'number' || n <= 0) { return; }
        var el = $(id);
        el.textContent = n.toLocaleString('es-MX');
        if (n === 1) { el.nextElementSibling.textContent = el.nextElementSibling.getAttribute('data-uno'); }
        el.parentNode.hidden = false;
    }

    // ── Preguntas frecuentes: una abierta a la vez ───────────────────────────
    function prepararPreguntas() {
        var preguntas = document.querySelectorAll('.faq-pregunta');
        Array.prototype.forEach.call(preguntas, function (b) {
            b.setAttribute('aria-expanded', 'false');
            b.addEventListener('click', function () {
                var item = b.parentElement;
                var abierta = item.classList.contains('open');
                Array.prototype.forEach.call(preguntas, function (otra) {
                    otra.parentElement.classList.remove('open');
                    otra.setAttribute('aria-expanded', 'false');
                });
                if (!abierta) {
                    item.classList.add('open');
                    b.setAttribute('aria-expanded', 'true');
                }
            });
        });
    }

    // ── Arranque ─────────────────────────────────────────────────────────────
    var so = detectarSistemaBasico();
    var celular = so === 'ios' || so === 'android';
    var codigo = codigoDeLaUrl();
    if (codigo && !celular) { guardarCookie('ap_reg', codigo, 90); }

    if (!celular) {
        mostrarPc(codigo);
    } else if (codigo) {
        mostrarListo(codigo, '', true);
    } else {
        var previo = leerGuardado();
        if (previo) { mostrarListo(previo.codigo, previo.nombre, false); } else { ensenar('grForm'); }
    }

    $('grForm').addEventListener('submit', enviar);
    $('grCopiar').addEventListener('click', copiarLink);
    prepararPreguntas();
    fetch('../get_stats.php')
        .then(function (r) { return r.json(); })
        .then(function (d) { ponerCifra('grTotal', d.total); ponerCifra('grDescargas', d.descargas); })
        .catch(function () { /* sin cifras: no se enseñan */ });
})();
