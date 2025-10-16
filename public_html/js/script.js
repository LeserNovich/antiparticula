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