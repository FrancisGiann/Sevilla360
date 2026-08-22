/**
 * SEVILLA360 - Global Notification Engine & Master Poller
 * Fetches ALL dashboard stats every 60 seconds. Updates the notification UI,
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

    // 2. MASTER FETCH: Grabs all data and shares it
    function fetchGlobalData() {
        fetch('actions/admin/get_dashboard_stats.php')
        .then(res => res.json())
        .then(data => {
            
            // A. Update Notifications UI
            if (data.notifications) {
                let unreadCount = 0;
                let htmlList = '';

                data.notifications.forEach(b => {
                    let iconClass = '';
                    let icon = '';
                    let message = '';
                    let timeAgo = new Date(b.start_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'});

                    if (b.cancel_status === 'Pending') {
                        unreadCount++;
                        iconClass = 'bg-red'; icon = 'fa-solid fa-arrow-rotate-left';
                        message = `<strong>Refund Requested</strong> for ${b.venue_name} (#${b.reference_no})`;
                    } else if (b.resched_status === 'Pending') {
                        unreadCount++;
                        iconClass = 'bg-blue'; icon = 'fa-solid fa-calendar-day';
                        message = `<strong>Reschedule Request</strong> for ${b.venue_name} (#${b.reference_no})`;
                    } else if (b.venue_category === 'Event Hall' && b.booking_status === 'Pending') {
                        unreadCount++;
                        iconClass = 'bg-yellow'; icon = 'fa-solid fa-champagne-glasses';
                        message = `<strong>New Event Inquiry</strong> for ${b.venue_name} (#${b.reference_no})`;
                    }

                    if (message !== '') {
                        htmlList += `
                            <a href="admin_dashboard.php?page=bookings&search=${b.reference_no}" class="notif-item">
                                <div class="notif-icon ${iconClass}"><i class="${icon}"></i></div>
                                <div class="notif-content">
                                    <p>${message}</p>
                                    <span>Target Date: ${timeAgo}</span>
                                </div>
                            </a>
                        `;
                    }
                });

                if (unreadCount > 0) {
                    badge.innerText = unreadCount;
                    badge.style.display = 'block';
                    notifList.innerHTML = htmlList;
                    
                    let notifiedCount = parseInt(sessionStorage.getItem('adminNotifiedCount')) || 0;
                    if (unreadCount > notifiedCount) {
                        setTimeout(() => {
                            if (typeof playNotificationChime === 'function') playNotificationChime();
                            if (typeof showAlert === 'function') {
                                showAlert("Action Required", `You have ${unreadCount} pending action(s) requiring attention.`, "info");
                            }
                        }, 500);
                    }
                    sessionStorage.setItem('adminNotifiedCount', unreadCount);
                } else {
                    badge.style.display = 'none';
                    notifList.innerHTML = '<div style="padding: 20px; text-align: center; color: #888; font-size: 0.85rem;">You\'re all caught up!</div>';
                    sessionStorage.setItem('adminNotifiedCount', 0);
                }
            }

            // B. BROADCAST DATA TO OTHER SCRIPTS
            // This allows admin_overview.js to receive the exact same payload without making a second fetch!
            const event = new CustomEvent('SevillaDashboardData', { detail: data });
            window.dispatchEvent(event);

        }).catch(() => {});
    }

    // Run instantly on page load, then check every 60 seconds
    fetchGlobalData();
    setInterval(fetchGlobalData, 60000); 
});
