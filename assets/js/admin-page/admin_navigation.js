document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('admin-sidebar');
    const toggle = document.getElementById('mobile-nav-toggle');
    const scrim = document.getElementById('mobile-nav-scrim');
    if (!sidebar || !toggle || !scrim) return;

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
