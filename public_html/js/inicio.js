// Inicio: las secciones de la hoja blanca aparecen suavemente al entrar en pantalla.
// Sin JavaScript, sin IntersectionObserver o con movimiento reducido, todo se ve
// desde el principio: la clase que las oculta (.in-anim) solo se pone aquí.
(function () {
    var items = document.querySelectorAll('.in-reveal');
    if (!items.length || !('IntersectionObserver' in window)) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    document.documentElement.classList.add('in-anim');

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -8% 0px' });

    items.forEach(function (el) { observer.observe(el); });
})();
