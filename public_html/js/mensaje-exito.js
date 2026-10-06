// ─── Descarga según el sistema del visitante ────────────────────────────────
// Los instaladores viven en /downloads con nombre fijo (sin versión): esta página no se edita en
// cada release, solo se sube el archivo nuevo con el mismo nombre.
var INSTALADORES = {
    windows:  { archivo: 'Instalador_Antiparticula_Punto_Venta.exe',       etiqueta: 'Windows' },
    macArm:   { archivo: 'Antiparticula_Punto_Venta_Mac_AppleSilicon.dmg', etiqueta: 'Mac con chip Apple (M1, M2, M3, M4…)' },
    macIntel: { archivo: 'Antiparticula_Punto_Venta_Mac_Intel.dmg',        etiqueta: 'Mac con chip Intel' }
};

// { so: 'windows' | 'mac' | 'ios' | 'android' | 'otro', chip: 'arm' | 'intel' | null }
var sistemaDetectado = null;

function detectarSistemaBasico() {
    var ua = navigator.userAgent || '';
    var plataforma = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
    // Un iPad en modo escritorio dice ser un Mac, pero es el único "Mac" con pantalla táctil.
    if (/iPhone|iPad|iPod/i.test(ua) || (/Mac/i.test(plataforma) && navigator.maxTouchPoints > 1)) { return 'ios'; }
    if (/Android/i.test(ua)) { return 'android'; }
    if (/Mac/i.test(plataforma) || /Macintosh|Mac OS X/i.test(ua)) { return 'mac'; }
    if (/Win/i.test(plataforma) || /Windows/i.test(ua)) { return 'windows'; }
    return 'otro';
}

// Un Mac con chip Apple y uno Intel se ven igual para el navegador ("MacIntel" en los dos),
// así que se pregunta de dos maneras. Devuelve una promesa de 'arm', 'intel' o null (no se pudo saber).
function detectarChipMac() {
    var uad = navigator.userAgentData;
    var porNavegador = (uad && uad.getHighEntropyValues)
        ? uad.getHighEntropyValues(['architecture']).then(function (v) {
            return v.architecture === 'arm' ? 'arm' : (v.architecture === 'x86' ? 'intel' : null);
        }).catch(function () { return null; })
        : Promise.resolve(null);   // Safari y Firefox no la exponen
    return porNavegador.then(function (chip) { return chip || chipPorTarjetaGrafica(); });
}

// Un Mac con chip Apple solo reporta una GPU "Apple"; los Intel reportan Intel, AMD o NVIDIA.
function chipPorTarjetaGrafica() {
    try {
        var gl = document.createElement('canvas').getContext('webgl');
        var ext = gl && gl.getExtension('WEBGL_debug_renderer_info');
        var gpu = ext ? String(gl.getParameter(ext.UNMASKED_RENDERER_WEBGL)) : '';
        if (/Intel|AMD|Radeon|NVIDIA|GeForce/i.test(gpu)) { return 'intel'; }
        if (/Apple/i.test(gpu)) { return 'arm'; }
    } catch (e) { /* sin WebGL: no se sabe */ }
    return null;
}

// Qué instalador le toca. En un Mac cuyo chip no se pudo averiguar se ofrece el de chip Apple
// (casi todos los Mac desde 2020) y el mensaje posterior enlaza el de Intel.
function instaladorElegido() {
    var s = sistemaDetectado || { so: detectarSistemaBasico(), chip: null };
    if (s.so === 'windows') { return 'windows'; }
    if (s.so === 'mac') { return s.chip === 'intel' ? 'macIntel' : 'macArm'; }
    return null;
}

// Pone "para Windows" / "para Mac" en los botones marcados con data-descarga y explica qué se detectó.
function actualizarInterfazDescarga() {
    var clave = instaladorElegido();
    var s = sistemaDetectado;
    var sufijo = clave === 'windows' ? ' para Windows' : (clave ? ' para Mac' : '');
    var botones = document.querySelectorAll('[data-descarga]');
    for (var i = 0; i < botones.length; i++) {
        var b = botones[i];
        if (!b.getAttribute('data-texto-base')) { b.setAttribute('data-texto-base', b.textContent.trim()); }
        for (var j = b.childNodes.length - 1; j >= 0; j--) {
            if (b.childNodes[j].nodeType === 3 && b.childNodes[j].textContent.trim()) {
                b.childNodes[j].textContent = ' ' + b.getAttribute('data-texto-base') + sufijo;
                break;
            }
        }
    }
    var nota = document.getElementById('pvOsNota');
    if (!nota || !s) { return; }
    var otro = ' <a href="#" onclick="elegirSistema(); return false;">¿Usas otro sistema?</a>';
    var texto = '';
    if (clave === 'windows') { texto = 'Detectamos <strong>Windows</strong>.' + otro; }
    else if (clave === 'macArm' && s.chip === 'arm') { texto = 'Detectamos un <strong>Mac con chip Apple</strong>.' + otro; }
    else if (clave === 'macIntel') { texto = 'Detectamos un <strong>Mac con chip Intel</strong>.' + otro; }
    else if (clave === 'macArm') { texto = 'Detectamos un <strong>Mac</strong>. Si es de los antiguos (chip Intel), <a href="#" onclick="elegirSistema(); return false;">elige tu versión</a>.'; }
    else if (s.so === 'ios' || s.so === 'android') { texto = 'Estás en tu celular: el punto de venta es para computadora. Mándate el link y descárgalo allá.'; }
    if (texto) { nota.innerHTML = texto; nota.hidden = false; }
}

function iniciarDeteccionSistema() {
    sistemaDetectado = { so: detectarSistemaBasico(), chip: null };
    actualizarInterfazDescarga();
    if (sistemaDetectado.so === 'mac') {
        detectarChipMac().then(function (chip) {
            sistemaDetectado.chip = chip;
            actualizarInterfazDescarga();
        });
    }
}
document.addEventListener('DOMContentLoaded', iniciarDeteccionSistema);

// Es lo que llaman los botones "Descargar" de la página.
function descargarPrueba() {
    var so = (sistemaDetectado || { so: detectarSistemaBasico() }).so;
    // Desde un celular no hay nada que instalar: se ofrece mandarse el link a la computadora.
    if (so === 'ios' || so === 'android') {
        if (typeof solicitarLinkDescarga === 'function') { solicitarLinkDescarga(); }
        return;
    }
    var clave = instaladorElegido();
    if (!clave) { elegirSistema(); return; }   // Linux u otro: que elija
    descargarInstalador(clave);
}

function descargarInstalador(clave) {
    var inst = INSTALADORES[clave];
    if (!inst) { return; }
    cerrarMensajeDescarga();   // si venía del selector o del enlace "descarga la otra versión"
    var url = '../downloads/' + inst.archivo;

    var empezar = function () {
        // Registrar la descarga en segundo plano (cuenta igual para cualquier sistema)
        fetch('../registrar_descarga.php', { method: 'POST' })
            .then(function (response) {
                if (!response.ok) { console.error('Error al enviar el registro.'); }
            })
            .catch(function (error) { console.error('Error de red al registrar la descarga:', error); });
        iniciarArchivo(url, inst.archivo);
        mostrarMensajeDescarga(clave);
    };

    if (clave === 'windows') { empezar(); return; }
    // El .dmg se sube a mano con cada versión: si aún no está, avisar en vez de dejar una descarga rota.
    fetch(url, { method: 'HEAD', cache: 'no-store' })
        .then(function (r) { if (r.ok) { empezar(); } else { mostrarMensajeProximamente(); } })
        .catch(empezar);   // sin respuesta no se sabe: se intenta igual
}

function iniciarArchivo(url, nombre) {
    var link = document.createElement('a');
    link.href = url;
    link.download = nombre;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function descargarFuncional() {
    
    // Crear enlace de descarga
    const link = document.createElement('a');
    link.href = '../downloads/Antiparticua_PuntoDeVenta.exe';
    link.download = 'Antiparticua_PuntoDeVenta.exe';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    // Mostrar mensaje de descarga iniciada
    mostrarMensajeDescarga();
}

// Tarjeta modal con el mismo diseño que usa el aviso de "mensaje enviado" (mensaje-exito.css)
function abrirTarjetaDescarga(contenido) {
    var overlay = document.createElement('div');
    overlay.className = 'mensaje-overlay';
    overlay.onclick = function () { cerrarMensajeDescarga(); };
    var mensaje = document.createElement('div');
    mensaje.className = 'mensaje-exito';
    mensaje.innerHTML = '<button class="mensaje-cerrar" onclick="cerrarMensajeDescarga()">&times;</button>' +
        '<div class="mensaje-contenido">' + contenido + '</div>';
    document.body.appendChild(overlay);
    document.body.appendChild(mensaje);
}

function ayudaWindows() {
    return `
                    <div style="margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 5px; text-align: left;">
                        <h4 style="margin: 0 0 8px 0;">⚠️ ¿Windows te mostró una advertencia?</h4>
                        <p style="margin: 0 0 10px 0;"><strong>Este software es 100% seguro.</strong> Windows marca como "sospechoso"
                        cualquier programa sin firma digital (que cuesta $500 USD/año).</p>
                        <a href="https://www.virustotal.com/gui/file/6494805b628b789aa7288b307a31e2bad4f066907f514b7e88f82feea38cdeb6?nocache=1" target="_blank"
                           style="display: inline-block; margin: 10px 0; padding: 10px 20px;
                           background: #4caf50; color: white; text-decoration: none; border-radius: 5px;">
                            ✓ Verificado en VirusTotal
                        </a>
                        <p style="margin: 8px 0 0 0;">
                            <a href="../paginas/instrucciones-descarga.html"
                               style="color: #1976d2; font-weight: bold;">
                                → Instrucciones paso a paso para instalar
                            </a>
                        </p>
                    </div>`;
}

function ayudaMac(clave) {
    var otra = clave === 'macArm' ? 'macIntel' : 'macArm';
    var cual = clave === 'macArm'
        ? '¿Tu Mac es de los antiguos (chip <strong>Intel</strong>)?'
        : '¿Tu Mac es reciente (chip <strong>Apple</strong>: M1, M2, M3, M4…)?';
    return `
                    <div style="margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 5px; text-align: left;">
                        <h4 style="margin: 0 0 8px 0;">🍎 Primera vez en Mac</h4>
                        <ol style="margin: 0 0 10px 18px; padding: 0; line-height: 1.5;">
                            <li>Abre el archivo <strong>.dmg</strong> y arrastra el programa a <strong>Aplicaciones</strong>.</li>
                            <li>Si macOS dice que el programa <strong>«está dañado y no se puede abrir»</strong> (o que no puede
                            verificar al desarrollador), <strong>no está dañado</strong>: es el bloqueo de macOS para programas que
                            aún no llevan el certificado de Apple. Abre la app <strong>Terminal</strong> (búscala con Spotlight),
                            pega esta línea, pulsa Enter y vuelve a abrir el programa:
                            <code style="display: block; margin: 8px 0; padding: 8px 10px; background: #fff; border-radius: 4px; font-size: 12px; user-select: all; word-break: break-all;">xattr -cr "/Applications/Antiparticula Punto de Venta.app"</code>
                            Si en cambio te ofrece <strong>«Abrir de todos modos»</strong> (Ajustes del Sistema → Privacidad y seguridad), úsalo.</li>
                            <li>Crea tu usuario y contraseña de administrador, y listo.</li>
                        </ol>
                        <p style="margin: 0;">Descargamos la versión para <strong>${INSTALADORES[clave].etiqueta}</strong>.
                        ${cual} <a href="#" onclick="descargarInstalador('${otra}'); return false;"
                        style="color: #1976d2; font-weight: bold;">Descarga la otra versión</a>.
                        Para saber cuál tienes: menú Apple → Acerca de este Mac.</p>
                    </div>`;
}

function mostrarMensajeDescarga(clave) {
    var ayuda = (clave === 'macArm' || clave === 'macIntel') ? ayudaMac(clave) : ayudaWindows();
    abrirTarjetaDescarga(`
                    <div class="mensaje-icono">
                        <i class="fa-solid fa-download"></i>
                    </div>
                    <div class="mensaje-texto">
                        <h3>¡Descarga iniciada!</h3>
                        <p>Tu punto de venta gratis se está descargando. Usa todas las funciones sin límite de tiempo.<br><br>
                        ¿Necesitas importar/exportar Excel? Mejora a Premium en cualquier momento.</p>
                    </div>` + ayuda + `
                    <div class="mensaje-botones">
                        <button class="btn-mensaje btn-primario" onclick="cerrarMensajeDescarga()">Entendido</button>
                    </div>`);
}

// El visitante es de Mac pero el .dmg todavía no está en el servidor
function mostrarMensajeProximamente() {
    abrirTarjetaDescarga(`
                    <div class="mensaje-icono">
                        <i class="fa-brands fa-apple"></i>
                    </div>
                    <div class="mensaje-texto">
                        <h3>La versión para Mac ya casi sale</h3>
                        <p>Estamos terminando de publicarla. Escríbenos y te avisamos en cuanto esté lista.</p>
                    </div>
                    <div class="mensaje-botones">
                        <a class="btn-mensaje btn-primario" style="text-decoration: none; text-align: center;" target="_blank"
                           href="https://wa.me/525584323945?text=${encodeURIComponent('Hola, quiero el Punto de Venta para Mac')}">Avisarme por WhatsApp</a>
                        <button class="btn-mensaje" onclick="cerrarMensajeDescarga()">Cerrar</button>
                    </div>`);
}

// Para quien usa otro sistema, o si la detección se equivocó
function elegirSistema() {
    abrirTarjetaDescarga(`
                    <div class="mensaje-icono">
                        <i class="fa-solid fa-desktop"></i>
                    </div>
                    <div class="mensaje-texto">
                        <h3>Elige tu sistema</h3>
                        <p>El punto de venta es gratis en todos. Si no sabes qué chip tiene tu Mac: menú Apple → Acerca de este Mac.</p>
                    </div>
                    <div class="mensaje-botones" style="flex-direction: column;">
                        <button class="btn-mensaje btn-primario" style="display: flex; align-items: center; justify-content: center; gap: 8px;" onclick="descargarInstalador('windows')"><i class="fa-brands fa-windows"></i> Windows</button>
                        <button class="btn-mensaje btn-primario" style="display: flex; align-items: center; justify-content: center; gap: 8px;" onclick="descargarInstalador('macArm')"><i class="fa-brands fa-apple"></i> Mac con chip Apple (M1, M2, M3, M4…)</button>
                        <button class="btn-mensaje btn-primario" style="display: flex; align-items: center; justify-content: center; gap: 8px;" onclick="descargarInstalador('macIntel')"><i class="fa-brands fa-apple"></i> Mac con chip Intel</button>
                    </div>`);
}

function cerrarMensajeDescarga() {
    const mensaje = document.querySelector('.mensaje-exito');
    const overlay = document.querySelector('.mensaje-overlay');

    if (mensaje) {
        mensaje.classList.add('mensaje-salida');
    }
    if (overlay) {
        overlay.classList.add('overlay-salida');
    }

    setTimeout(() => {
        if (mensaje && mensaje.parentNode) {
            mensaje.remove();
        }
        if (overlay && overlay.parentNode) {
            overlay.remove();
        }
    }, 300);
}

// Script para manejar el mensaje de éxito del formulario
document.addEventListener('DOMContentLoaded', function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('enviado') === 'true') {
        // Mostrar mensaje de éxito
        mostrarMensajeExito();
        // Limpiar formulario
        if (document.getElementById('contactForm')) {
            document.getElementById('contactForm').reset();
        }
        // Limpiar URL después de 3 segundos
        setTimeout(() => {
            window.history.replaceState({}, document.title, window.location.pathname);
        }, 3000);
    }
});

function mostrarMensajeExito() {
    // Crear overlay de fondo
    const overlay = document.createElement('div');
    overlay.className = 'mensaje-overlay';
    overlay.onclick = function () {
        cerrarMensaje();
    };

    // Crear elemento del mensaje
    const mensaje = document.createElement('div');
    mensaje.className = 'mensaje-exito';
    mensaje.innerHTML = `
                <button class="mensaje-cerrar" onclick="cerrarMensaje()">&times;</button>
                <div class="mensaje-contenido">
                    <div class="mensaje-icono">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <div class="mensaje-texto">
                        <h3>¡Mensaje enviado exitosamente!</h3>
                        <p>Gracias por contactarnos. Nuestro equipo de ingenieros te responderá lo antes posible.</p>
                    </div>
                    <div class="mensaje-botones">
                        <button class="btn-mensaje btn-primario" onclick="cerrarMensaje()">Perfecto</button>
                    </div>
                </div>
            `;

    // Insertar overlay y mensaje
    document.body.appendChild(overlay);
    document.body.appendChild(mensaje);

    // Remover automáticamente después de 8 segundos
    //setTimeout(() => {
   //     cerrarMensaje();
 //   }, 8000);
}

function cerrarMensaje() {
    const mensaje = document.querySelector('.mensaje-exito');
    const overlay = document.querySelector('.mensaje-overlay');

    if (mensaje) {
        mensaje.classList.add('mensaje-salida');
    }
    if (overlay) {
        overlay.classList.add('overlay-salida');
    }

    setTimeout(() => {
        if (mensaje && mensaje.parentNode) {
            mensaje.remove();
        }
        if (overlay && overlay.parentNode) {
            overlay.remove();
        }
    }, 300);
}