// assets/js/admin-page/admin_calendar.js
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('full-master-calendar');

    if (calendarEl) {
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek'
            },
            height: 'auto',
            events: 'actions/admin/get_master_calendar.php',
            
            // Format how the text looks on the pills
            eventContent: function(arg) {
                let statusIcon = '';
                if (arg.event.extendedProps.status === 'Pending') {
                    statusIcon = '⌛ ';
                }
                return { html: `<div style="overflow: hidden; white-space: nowrap; text-overflow: ellipsis;">${statusIcon}${arg.event.title}</div>` };
            },

            // When Admin clicks a booking pill
            eventClick: function(info) {
                const bookingId = info.event.id;
                
                // We use SweetAlert-style confirmation to redirect them to manage it
                if (confirm(`Do you want to manage Booking #${bookingId}?\n\nGuest: ${info.event.title}\nStatus: ${info.event.extendedProps.status}`)) {
                    // Redirects to Bookings page, putting the ID in the search bar!
                    window.location.href = `admin_dashboard.php?page=bookings&search=${bookingId}`;
                }
            }
        });

        calendar.render();
    }
});