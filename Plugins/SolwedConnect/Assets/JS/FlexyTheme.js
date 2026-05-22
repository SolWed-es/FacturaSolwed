$(document).on('shown.bs.modal', '.modal', function () {
    var $modal = $(this);

    $modal.find('select.select2').each(function () {
        var $select = $(this);

        if ($select.data('select2')) {
            $select.select2('destroy');
        }

        $select.select2({
            width: 'style',
            theme: 'bootstrap4',
            dropdownParent: $modal
        });
    });
});

// Función para cambiar entre temas
function toggleTheme() {
    const html = document.documentElement;
    const themeIcon = document.getElementById('theme-icon');
    const darkTheme = document.getElementById('dark-theme');
    
    // Alternar entre tema oscuro y claro
    if (html.getAttribute('data-theme-version') === 'dark') {
        // Cambiar a tema claro
        html.setAttribute('data-theme-version', 'light');
        html.setAttribute('data-bs-theme', 'light');
        themeIcon.classList.remove('fa-sun');
        themeIcon.classList.add('fa-moon');
        darkTheme.media = 'none'; // Desactivar tema oscuro
        localStorage.setItem('theme', 'light');
    } else {
        // Cambiar a tema oscuro
        html.setAttribute('data-theme-version', 'dark');
        html.setAttribute('data-bs-theme', 'dark');
        themeIcon.classList.remove('fa-moon');
        themeIcon.classList.add('fa-sun');
        darkTheme.media = 'all'; // Activar tema oscuro
        localStorage.setItem('theme', 'dark');
    }
}

// Cargar el tema guardado al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    const html = document.documentElement;
    const themeIcon = document.getElementById('theme-icon');
    const darkTheme = document.getElementById('dark-theme');
    
    // Aplicar el tema guardado
    html.setAttribute('data-theme-version', savedTheme);
    html.setAttribute('data-bs-theme', savedTheme);
    
    // Actualizar el icono y el tema
    if (savedTheme === 'dark') {
        themeIcon.classList.remove('fa-moon');
        themeIcon.classList.add('fa-sun');
        darkTheme.media = 'all'; // Activar tema oscuro
    } else {
        themeIcon.classList.remove('fa-sun');
        themeIcon.classList.add('fa-moon');
        darkTheme.media = 'none'; // Desactivar tema oscuro
    }
    
    // Añadir evento al botón de cambio de tema
    document.getElementById('theme-toggle').addEventListener('click', function(e) {
        e.preventDefault();
        toggleTheme();
    });
});
