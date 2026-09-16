/**
 * SEVILLA360 - Global Notification Engine & Master Poller
 * Fetches ALL dashboard stats with a visibility-aware polling interval. Updates the notification UI,
 * then broadcasts the data globally so page-specific scripts (like overview) can use it without double-fetching.
 */
document.addEventListener("DOMContentLoaded", () => {
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const badge = document.getElementById('global-notif-badge');
    const notifList = document.getElementById('notifList');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    if (!bell || !dropdown || !badge || !notifList) return;

    // 1. Toggle Dropdown on Bell Click
    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = dropdown.classList.toggle('show');
        bell.setAttribute('aria-expanded', String(isOpen));
    });

    // Close dropdown if clicking anywhere else on the screen
    window.addEventListener('click', (e) => {
        if (!document.getElementById('notifCenter').contains(e.target)) {
            dropdown.classList.remove('show');
            bell.setAttribute('aria-expanded', 'false');
        }
    });

    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[character]));
    }

    // 2. MASTER FETCH: Grabs all data and shares it
    async function fetchGlobalData() {
        try {
            const response = await fetch('actions/admin/get_dashboard_stats.php', {
                headers: { 'X-Sevilla-Background': '1', 'Accept': 'application/json' }
            });
            const data = await response.json();
            if (!response.ok || !data || typeof data !== 'object' || data.error || data.success === false) {
                throw new Error('Dashboard data unavailable');
            }
            
            // A. Update Notifications UI
            if (Array.isArray(data.notifications)) {
                const items = data.notifications;
                const unreadCount = items.filter(item => !item.is_read).length;
                badge.innerText = String(unreadCount);
                badge.style.display = unreadCount > 0 ? 'block' : 'none';
                if (!items.length) {
                    notifList.innerHTML = '<div style="padding: 20px; text-align: center; color: #888; font-size: 0.85rem;">No unresolved actions.</div>';
                } else {
                    const iconFor = kind => ({
                        cancellation_request: ['bg-red', 'fa-solid fa-arrow-rotate-left'],
                        reschedule_request: ['bg-blue', 'fa-solid fa-calendar-day'],
                        payment_proof: ['bg-green', 'fa-solid fa-receipt'],
                        new_booking: ['bg-yellow', 'fa-solid fa-champagne-glasses']
                    }[kind] || ['bg-yellow', 'fa-solid fa-bell']);
                    notifList.innerHTML = '<div class="notif-section-label">Needs action</div>' + items.map(item => {
                        const [iconClass, icon] = iconFor(item.kind);
                        const readLabel = item.is_read ? 'Read' : 'Unread';
                        const timestamp = new Date(item.timestamp).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                        return `<a href="${escapeHTML(item.target_url)}" class="notif-item ${item.is_read ? 'is-read' : 'is-unread'}" data-notification-key="${escapeHTML(item.key)}" data-read="${item.is_read ? 'true' : 'false'}">
                            <div class="notif-icon ${iconClass}"><i class="${icon}" aria-hidden="true"></i></div>
                            <div class="notif-content"><p><strong>${escapeHTML(item.title)}</strong> ${escapeHTML(item.message)}</p><span>${escapeHTML(timestamp)} · <span class="notif-read-state">${readLabel}</span></span></div>
                        </a>`;
                    }).join('');
                }
            }

            // B. BROADCAST DATA TO OTHER SCRIPTS
            // This allows admin_overview.js to receive the exact same payload without making a second fetch!
            const event = new CustomEvent('SevillaDashboardData', { detail: data });
            window.dispatchEvent(event);
        } catch (error) {
            window.dispatchEvent(new CustomEvent('SevillaDashboardError', {
                detail: { message: 'Dashboard data could not be loaded. Please retry.' }
            }));
        }
    }

    notifList.addEventListener('click', async event => {
        const item = event.target.closest('[data-notification-key]');
        if (!item || !notifList.contains(item)) return;
        const key = item.dataset.notificationKey || '';
        if (!key || item.dataset.read === 'true') return;
        event.preventDefault();
        try {
            const response = await fetch('actions/admin/mark_notification_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
                body: `key=${encodeURIComponent(key)}`
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result?.success) throw new Error(result?.message || 'Notification could not be marked read.');
            item.dataset.read = 'true';
            item.classList.remove('is-unread');
            item.classList.add('is-read');
            const state = item.querySelector('.notif-read-state');
            if (state) state.textContent = 'Read';
            const count = Math.max(0, (Number.parseInt(badge.textContent, 10) || 0) - 1);
            badge.textContent = String(count);
            badge.style.display = count > 0 ? 'block' : 'none';
            window.location.href = item.href;
        } catch (error) {
            item.classList.add('read-error');
            item.setAttribute('aria-label', 'Could not save read state. The action remains unread.');
            window.setTimeout(() => item.classList.remove('read-error'), 2800);
            // A failed read-state write must not prevent the administrator
            // from reviewing the underlying action. The server will keep it
            // unread on the next refresh.
            window.location.href = item.href;
        }
    });

    // WebSocket events only invalidate the view; the existing authorized
    // dashboard endpoint remains the source of truth and polling fallback.
    window.addEventListener('SevillaRealtimeEvent', event => {
        if (event.detail?.channel === 'admin') fetchGlobalData();
    });
    window.addEventListener('SevillaDashboardRefreshRequested', fetchGlobalData);

    // Run instantly on page load, then poll while visible with visibility-aware
    // backoff. The bell/list is the notification surface; no automatic popup.
    fetchGlobalData();
    let pollTimer = null;
    const schedulePoll = () => {
        clearTimeout(pollTimer);
        const delay = document.visibilityState === 'visible' ? 30000 : 120000;
        pollTimer = setTimeout(() => {
            fetchGlobalData();
            schedulePoll();
        }, delay);
    };
    document.addEventListener('visibilitychange', schedulePoll);
    schedulePoll();
});
