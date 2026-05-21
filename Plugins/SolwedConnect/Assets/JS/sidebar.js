/**
 * SolwedShadcn — Sidebar toggle y submenús
 */

// Toggle sidebar: collapsed en tablet/desktop, off-canvas en móvil
function toggleSidebar() {
    var sidebar = document.getElementById('solwedSidebar');
    var overlay = document.getElementById('sidebarOverlay');

    if (window.innerWidth >= 768) {
        // Tablet/desktop: colapsar a iconos o expandir
        if (sidebar) {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
        }
    } else {
        // Móvil: slide off-canvas con overlay
        if (sidebar) {
            sidebar.classList.toggle('mobile-open');
        }
        if (overlay) {
            overlay.classList.toggle('active');
        }
    }
}

// Toggle submenú del sidebar
function toggleSubmenu(menuId) {
    var submenu = document.getElementById('submenu-' + menuId);
    var chevron = document.getElementById('chevron-' + menuId);

    if (submenu) {
        submenu.classList.toggle('hidden');
    }
    if (chevron) {
        chevron.style.transform = submenu && submenu.classList.contains('hidden')
            ? 'rotate(0deg)'
            : 'rotate(90deg)';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Restaurar estado collapsed del sidebar
    if (window.innerWidth >= 768 && localStorage.getItem('sidebarCollapsed') === '1') {
        var sidebar = document.getElementById('solwedSidebar');
        if (sidebar) {
            sidebar.classList.add('collapsed');
        }
    }

    // Cerrar sidebar al hacer clic en un link (móvil)
    var sidebarLinks = document.querySelectorAll('.solwed-sidebar a[href]:not([href="#"]):not([href="javascript:void(0)"])');
    sidebarLinks.forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 768) {
                toggleSidebar();
            }
        });
    });

    // Scroll al tab activo (para tabs right-aligned con overflow)
    var activeTab = document.querySelector('[role="tablist"] .nav-link.active');
    if (activeTab) {
        activeTab.scrollIntoView({ inline: 'nearest', block: 'nearest' });
    }

    // Abrir submenú que contiene el item activo
    var activeItems = document.querySelectorAll('.solwed-sidebar .bg-accent');
    activeItems.forEach(function (item) {
        var parent = item.closest('[id^="submenu-"]');
        if (parent && parent.classList.contains('hidden')) {
            parent.classList.remove('hidden');
            var menuId = parent.id.replace('submenu-', '');
            var chevron = document.getElementById('chevron-' + menuId);
            if (chevron) {
                chevron.style.transform = 'rotate(90deg)';
            }
        }
    });
});
