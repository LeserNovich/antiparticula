// Función para descargar el archivo de prueba
function descargarPrueba() {
    console.log('descargarmmetodi');
    // --- AÑADE ESTE BLOQUE PARA REGISTRAR LA DESCARGA ---
    // Llama a tu script PHP en segundo plano para guardar el registro.
    fetch('../registrar_descarga.php', {
        method: 'POST'
    })
        .then(response => {
            // Opcional: puedes verificar si la respuesta fue exitosa
            if (response.ok) {
                console.log('Registro de descarga enviado.');
            } else {
                console.error('Error al enviar el registro.');
            }
        })
        .catch(error => {
            console.error('Error de red al registrar la descarga:', error);
        });
    // --- FIN DEL BLOQUE AÑADIDO ---

    // Crear enlace de descarga
    const link = document.createElement('a');
    link.href = '../downloads/Instalador_Antiparticula_Punto_Venta.exe';
    link.download = 'Instalador_Antiparticula_Punto_Venta.exe';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    // Mostrar mensaje de descarga iniciada
    mostrarMensajeDescarga();
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

function mostrarMensajeDescarga() {
    // Crear overlay de fondo
    const overlay = document.createElement('div');
    overlay.className = 'mensaje-overlay';
    overlay.onclick = function () {
        cerrarMensajeDescarga();
    };

    // Crear elemento del mensaje
    const mensaje = document.createElement('div');
    mensaje.className = 'mensaje-exito';
    mensaje.innerHTML = `
                <button class="mensaje-cerrar" onclick="cerrarMensajeDescarga()">&times;</button>
                <div class="mensaje-contenido">
                    <div class="mensaje-icono">
                        <i class="fa-solid fa-download"></i>
                    </div>
                    <div class="mensaje-texto">
                        <h3>¡Descarga iniciada!</h3>
                        <p>Tu punto de venta gratis se está descargando. Usa todas las funciones sin límite de tiempo.<br><br>
                        ¿Necesitas importar/exportar Excel? Mejora a Premium en cualquier momento.</p>
                    </div>
                    <div class="mensaje-botones">
                        <button class="btn-mensaje btn-primario" onclick="cerrarMensajeDescarga()">Entendido</button>
                    </div>
                </div>
            `;

    // Insertar overlay y mensaje
    document.body.appendChild(overlay);
    document.body.appendChild(mensaje);

    // Remover automáticamente después de 8 segundos
   // setTimeout(() => {
    //    cerrarMensajeDescarga();
    //}, 8000);
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