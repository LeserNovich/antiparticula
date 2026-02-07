var swiper = new Swiper('.blog-slider', {
        spaceBetween: 30,
        effect: 'fade',
        loop: true,
        mousewheel: {
          invert: false,
        },
        // autoHeight: true,
        pagination: {
          el: '.blog-slider__pagination',
          clickable: true,
        }
      });

function showMenu(){
  document.getElementById("navLinks").style.right = "0";
}

function hideMenu(){
  document.getElementById("navLinks").style.right = "-220px";
}

function sendWhatsAppMessage() {
            const phoneNumber = '525584323945';
            let message = document.getElementById('whatsapp-message').value;

            if (message.trim() === '') {
                alert('Por favor, escribe un mensaje antes de enviar.');
                return;
            }

            let encodedMessage = encodeURIComponent(message);
            const whatsappURL = `https://wa.me/${phoneNumber}?text=${encodedMessage}`;
            window.open(whatsappURL, '_blank');
        }

function solicitarLinkDescarga() {
            // Abrir modal
            document.getElementById('modalEnviarLink').classList.add('show');
            // Track en TikTok pixel si está disponible
            if (typeof ttq !== 'undefined') {
                ttq.track('ClickButton');
            }
        }

function cerrarModalLink() {
            document.getElementById('modalEnviarLink').classList.remove('show');
        }

function enviarPorWhatsApp() {
            const message = '🚀 Descarga gratis el Punto de Venta Antipartícula para Windows:\n\nhttps://antiparticula.com/puntodeventa\n\n✨ Gratis para siempre\n💻 Solo Windows\n📦 Control total de inventario';
            const encodedMessage = encodeURIComponent(message);
            // Sin número de teléfono, el usuario elige a quién enviar (puede ser a sí mismo)
            const whatsappURL = `https://wa.me/?text=${encodedMessage}`;
            window.open(whatsappURL, '_blank');

            // Track conversión
            if (typeof ttq !== 'undefined') {
                ttq.track('Contact');
            }
            cerrarModalLink();
        }

function enviarPorEmail() {
            const subject = encodeURIComponent('Punto de Venta Antipartícula - Link de Descarga');
            const body = encodeURIComponent('🚀 Descarga gratis el Punto de Venta Antipartícula para Windows:\n\nhttps://antiparticula.com/puntodeventa\n\n✨ Gratis para siempre\n💻 Solo Windows\n📦 Control total de inventario\n🔄 Siempre actualizado');
            // Abre el cliente de email del usuario
            window.location.href = `mailto:?subject=${subject}&body=${body}`;

            // Track conversión
            if (typeof ttq !== 'undefined') {
                ttq.track('Contact');
            }
            cerrarModalLink();
        }

function copiarLink() {
            const link = 'https://antiparticula.com/puntodeventa';
            const copyStatus = document.getElementById('copy-status');

            // Copiar al portapapeles
            navigator.clipboard.writeText(link).then(() => {
                // Feedback visual
                copyStatus.textContent = '¡Copiado! ✓';
                copyStatus.style.color = '#4ade80';

                // Track conversión
                if (typeof ttq !== 'undefined') {
                    ttq.track('Contact');
                }

                // Resetear después de 2 segundos
                setTimeout(() => {
                    copyStatus.textContent = 'Copiar al portapapeles';
                    copyStatus.style.color = '#9ca3af';
                }, 2000);
            }).catch(() => {
                copyStatus.textContent = 'Error al copiar';
                copyStatus.style.color = '#ef4444';

                setTimeout(() => {
                    copyStatus.textContent = 'Copiar al portapapeles';
                    copyStatus.style.color = '#9ca3af';
                }, 2000);
            });
        }

// Cerrar modal al hacer clic fuera
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('modalEnviarLink');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                cerrarModalLink();
            }
        });
    }
});

// Lógica para el toggle de contacto (WhatsApp/Email)
document.addEventListener('DOMContentLoaded', () => {
    const toggleButtons = document.querySelectorAll('.toggle-btn');
    const contactPanels = document.querySelectorAll('.contact-panel');

    toggleButtons.forEach(button => {
        button.addEventListener('click', () => {
            // Obtener el objetivo del botón clickeado
            const targetPanelId = button.dataset.target;

            // Quitar la clase 'active' de todos los botones y paneles
            toggleButtons.forEach(btn => btn.classList.remove('active'));
            contactPanels.forEach(panel => panel.classList.remove('active'));

            // Añadir 'active' al botón clickeado y al panel correspondiente
            button.classList.add('active');
            document.getElementById(targetPanelId).classList.add('active');
        });
    });
});