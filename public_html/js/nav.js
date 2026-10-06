// Menú principal: menú móvil y fondo de vidrio al hacer scroll.
// Archivo aparte de script.js porque éste truena en páginas sin Swiper.
(function () {
  var nav = document.getElementById('siteNav');
  if (!nav) return;

  var toggle = nav.querySelector('.site-nav__toggle');
  var menu = document.getElementById('siteNavMenu');
  var mobile = window.matchMedia('(max-width: 1000px)');

  function onScroll() {
    nav.classList.toggle('is-scrolled', window.scrollY > 8);
  }

  function setOpen(open) {
    nav.classList.toggle('is-open', open);
    document.documentElement.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
  }

  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  toggle.addEventListener('click', function () {
    setOpen(!nav.classList.contains('is-open'));
  });

  // Un enlace a una sección de la misma página no recarga: hay que cerrar a mano.
  menu.addEventListener('click', function (e) {
    if (e.target.closest('a')) setOpen(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && nav.classList.contains('is-open')) {
      setOpen(false);
      toggle.focus();
    }
  });

  var onBreakpoint = function () { setOpen(false); };
  if (mobile.addEventListener) mobile.addEventListener('change', onBreakpoint);
  else mobile.addListener(onBreakpoint);
})();
