document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('admin-sidebar');
    const toggle = document.getElementById('mobile-nav-toggle');
    const scrim = document.getElementById('mobile-nav-scrim');
    const collapseToggle = document.getElementById('sidebar-collapse-toggle');
    if (!sidebar) return;

    const collapseKey = 'sevilla360-admin-sidebar-collapsed';
    const desktopQuery = window.matchMedia('(min-width: 769px)');

    const setCollapsed = (collapsed, persist = true) => {
        if (!desktopQuery.matches) return;
        sidebar.closest('.admin-layout')?.classList.toggle('sidebar-collapsed', collapsed);
        sidebar.querySelectorAll('.nav-link').forEach(link => {
            const label = link.textContent.replace(/\s+/g, ' ').trim();
            if (collapsed) {
                link.setAttribute('title', label);
                link.setAttribute('aria-label', label);
            } else {
                link.removeAttribute('title');
                link.removeAttribute('aria-label');
            }
        });
        collapseToggle?.setAttribute('aria-pressed', String(collapsed));
        collapseToggle?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Minimize sidebar');
        collapseToggle?.setAttribute('title', collapsed ? 'Expand sidebar' : 'Minimize sidebar');
        if (collapseToggle) collapseToggle.innerHTML = `<i class="fa-solid fa-chevron-${collapsed ? 'right' : 'left'}" aria-hidden="true"></i>`;
        if (persist) localStorage.setItem(collapseKey, collapsed ? '1' : '0');
    };

    if (collapseToggle) {
        const savedCollapsed = localStorage.getItem(collapseKey) === '1';
        setCollapsed(savedCollapsed, false);
        collapseToggle.addEventListener('click', () => {
            const layout = sidebar.closest('.admin-layout');
            setCollapsed(!layout?.classList.contains('sidebar-collapsed'));
        });
        desktopQuery.addEventListener?.('change', event => {
            if (!event.matches) setCollapsed(false, false);
            else setCollapsed(localStorage.getItem(collapseKey) === '1', false);
        });
    }

    if (!toggle || !scrim) return;

    const closeMenu = () => {
        sidebar.classList.remove('mobile-nav-open');
        scrim.classList.remove('mobile-nav-scrim-open');
        toggle.setAttribute('aria-expanded', 'false');
    };
    const openMenu = () => {
        sidebar.classList.add('mobile-nav-open');
        scrim.classList.add('mobile-nav-scrim-open');
        toggle.setAttribute('aria-expanded', 'true');
    };

    toggle.addEventListener('click', () => {
        sidebar.classList.contains('mobile-nav-open') ? closeMenu() : openMenu();
    });
    scrim.addEventListener('click', closeMenu);
    sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeMenu();
    });
});
