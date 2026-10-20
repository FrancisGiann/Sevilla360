document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('admin-sidebar');
    const toggle = document.getElementById('mobile-nav-toggle');
    const scrim = document.getElementById('mobile-nav-scrim');
    const collapseToggle = document.getElementById('sidebar-collapse-toggle');
    if (!sidebar) return;

    const collapseKey = 'sevilla360-admin-sidebar-collapsed';
    const desktopQuery = window.matchMedia('(min-width: 769px)');
    const readCollapsedState = () => {
        try {
            return window.localStorage.getItem(collapseKey) === '1';
        } catch (error) {
            return false;
        }
    };
    const persistCollapsedState = collapsed => {
        try {
            window.localStorage.setItem(collapseKey, collapsed ? '1' : '0');
        } catch (error) {
            // Private browsing or a restrictive storage policy should not break navigation.
        }
    };

    const setCollapsed = (collapsed, persist = true) => {
        if (!desktopQuery.matches) {
            document.documentElement.classList.remove('admin-sidebar-precollapsed');
            return;
        }
        document.documentElement.classList.toggle('admin-sidebar-precollapsed', collapsed);
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
        if (persist) persistCollapsedState(collapsed);
    };

    if (collapseToggle) {
        const savedCollapsed = readCollapsedState();
        setCollapsed(savedCollapsed, false);
        collapseToggle.addEventListener('click', () => {
            const layout = sidebar.closest('.admin-layout');
            setCollapsed(!layout?.classList.contains('sidebar-collapsed'));
        });
        desktopQuery.addEventListener?.('change', event => {
            if (!event.matches) setCollapsed(false, false);
            else setCollapsed(readCollapsedState(), false);
        });
    }

    const profile = document.getElementById('adminProfile');
    const profileTrigger = document.getElementById('adminProfileTrigger');
    const profileMenu = document.getElementById('adminProfileMenu');
    const notificationBell = document.getElementById('notifBell');
    const notificationDropdown = document.getElementById('notifDropdown');

    if (profile && profileTrigger && profileMenu) {
        const profileItems = [...profileMenu.querySelectorAll('[role="menuitem"]')];
        const closeNotifications = () => {
            notificationDropdown?.classList.remove('show');
            notificationBell?.setAttribute('aria-expanded', 'false');
        };
        const setProfileOpen = (open, focusFirst = false) => {
            profileTrigger.setAttribute('aria-expanded', String(open));
            profileMenu.hidden = !open;
            profileMenu.classList.toggle('show', open);
            if (open) {
                closeNotifications();
                if (focusFirst) window.requestAnimationFrame(() => profileItems[0]?.focus());
            }
        };

        profileTrigger.addEventListener('click', event => {
            event.stopPropagation();
            setProfileOpen(profileMenu.hidden, false);
        });
        profileTrigger.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setProfileOpen(true, true);
            }
        });
        document.addEventListener('click', event => {
            if (!profile.contains(event.target)) setProfileOpen(false);
        }, true);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !profileMenu.hidden) {
                event.preventDefault();
                setProfileOpen(false);
                profileTrigger.focus();
            }
        });
    }

    if (!toggle || !scrim) return;

    const closeMenu = (restoreFocus = false) => {
        const wasOpen = sidebar.classList.contains('mobile-nav-open');
        sidebar.classList.remove('mobile-nav-open');
        scrim.classList.remove('mobile-nav-scrim-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open menu');
        if (wasOpen && restoreFocus === true) toggle.focus();
    };
    const openMenu = () => {
        sidebar.classList.add('mobile-nav-open');
        scrim.classList.add('mobile-nav-scrim-open');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Close menu');
        window.requestAnimationFrame(() => {
            if (sidebar.classList.contains('mobile-nav-open')) sidebar.querySelector('a')?.focus();
        });
    };

    toggle.addEventListener('click', () => {
        sidebar.classList.contains('mobile-nav-open') ? closeMenu(true) : openMenu();
    });
    scrim.addEventListener('click', () => closeMenu(true));
    sidebar.querySelector('#mobile-sidebar-close')?.addEventListener('click', () => closeMenu(true));
    sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', () => closeMenu()));
    desktopQuery.addEventListener?.('change', event => {
        if (event.matches) closeMenu();
    });
    document.addEventListener('keydown', event => {
        if (!sidebar.classList.contains('mobile-nav-open')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeMenu(true);
        } else if (event.key === 'Tab') {
            const controls = [...sidebar.querySelectorAll('a, button:not([disabled])')]
                .filter(control => control.getClientRects().length > 0);
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }
    });
});
