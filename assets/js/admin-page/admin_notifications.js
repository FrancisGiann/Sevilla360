/**
 * SEVILLA360 - Global Notification Engine
 * Fetches Action-Required items (Refunds, Reschedules, Inquiries) and builds the dropdown UI.
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
        dropdown.classList.toggle('show');
    });

    // Close dropdown if clicking anywhere else on the screen
    window.addEventListener('click', (e) => {
        if (!document.getElementById('notifCenter').contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });

    // 2. Fetch and Build Notifications
    function fetchGlobalNotifs() {
        fetch('actions/admin/get_dashboard_stats.php')
        .then(res => res.json())
        .then(data => {
            if (!data.notifications) return;

            let unreadCount = 0;
            let htmlList = '';

            // Loop through the dedicated notifications array!
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
            } else {
                badge.style.display = 'none';
                notifList.innerHTML = '<div style="padding: 20px; text-align: center; color: #888; font-size: 0.85rem;">You\'re all caught up!</div>';
            }

        }).catch(e => console.log('Notif check silent fail', e));
    }

    // Run instantly on page load, then check every 60 seconds
    fetchGlobalNotifs();
    setInterval(fetchGlobalNotifs, 60000); 
});