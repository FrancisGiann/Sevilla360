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
    function fetchGlobalData() {
        fetch('actions/admin/get_dashboard_stats.php', {
            headers: { 'X-Sevilla-Background': '1', 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            
            // A. Update Notifications UI
            if (data.notifications) {
                let unreadCount = 0;
                let htmlList = '';

                data.notifications.forEach(b => {
                    const reference = String(b.reference_no ?? '');
                    const venue = String(b.venue_name ?? '');
                    let iconClass = '';
                    let icon = '';
                    let message = '';
                    let timeAgo = new Date(b.start_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'});

                    if (b.cancel_status === 'Pending') {
                        unreadCount++;
                        iconClass = 'bg-red'; icon = 'fa-solid fa-arrow-rotate-left';
                        message = `<strong>Refund Requested</strong> for ${escapeHTML(venue)} (#${escapeHTML(reference)})`;
                    } else if (b.resched_status === 'Pending') {
                        unreadCount++;
                        iconClass = 'bg-blue'; icon = 'fa-solid fa-calendar-day';
                        message = `<strong>Reschedule Request</strong> for ${escapeHTML(venue)} (#${escapeHTML(reference)})`;
                    } else if (b.source === 'Online' && b.booking_status === 'Pending') {
                        unreadCount++;
                        iconClass = 'bg-yellow'; icon = 'fa-solid fa-champagne-glasses';
                        const requestLabel = b.venue_category === 'Event Hall'
                            ? 'New Event Inquiry'
                            : 'New Booking Request';
                        message = `<strong>${requestLabel}</strong> for ${escapeHTML(venue)} (#${escapeHTML(reference)})`;
                    }

                    if (message !== '') {
                        htmlList += `
                            <a href="admin_dashboard.php?page=bookings&search=${encodeURIComponent(reference)}" class="notif-item">
                                <div class="notif-icon ${iconClass}"><i class="${icon}"></i></div>
                                <div class="notif-content">
                                    <p>${message}</p>
                                    <span>Target Date: ${escapeHTML(timeAgo)}</span>
                                </div>
                            </a>
                        `;
                    }
                });

                if (unreadCount > 0) {
                    badge.innerText = unreadCount;
                    badge.style.display = 'block';
                    notifList.innerHTML = htmlList;
                    
                } else {
                    badge.style.display = 'none';
                    notifList.innerHTML = '<div style="padding: 20px; text-align: center; color: #888; font-size: 0.85rem;">You\'re all caught up!</div>';
                }
            }

            // B. BROADCAST DATA TO OTHER SCRIPTS
            // This allows admin_overview.js to receive the exact same payload without making a second fetch!
            const event = new CustomEvent('SevillaDashboardData', { detail: data });
            window.dispatchEvent(event);

        }).catch(() => {});
    }

    // WebSocket events only invalidate the view; the existing authorized
    // dashboard endpoint remains the source of truth and polling fallback.
    window.addEventListener('SevillaRealtimeEvent', event => {
        if (event.detail?.channel === 'admin') fetchGlobalData();
    });

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
