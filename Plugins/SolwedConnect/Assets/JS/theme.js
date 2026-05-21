/**
 * SolwedShadcn — Inicialización del tema
 */

document.addEventListener('DOMContentLoaded', function () {
    // Inicializar Lucide icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // Clickable rows — navegar al hacer clic en filas de tabla
    $(document).on('click', '.clickableRow', function () {
        var href = $(this).data('href');
        if (href) {
            window.document.location = href;
        }
    });
});
