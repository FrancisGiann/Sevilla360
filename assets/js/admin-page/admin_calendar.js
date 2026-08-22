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

            // When Admin clicks an event pill
            eventClick: function(info) {
                const props = info.event.extendedProps;
                const formatDate = (value) => value
                    ? new Date(`${value}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                    : 'N/A';
                const dateText = props.startDate && props.endDate && props.startDate !== props.endDate
                    ? `${formatDate(props.startDate)} - ${formatDate(props.endDate)}`
                    : formatDate(props.startDate);

                if (props.type === 'maintenance') {
                    showAlert("Maintenance Block", `Venue: ${info.event.title}\nDates: ${dateText}\nType: ${props.task || 'N/A'}\nCategory: ${props.category}`);
                    return;
                }

                const bookingRef = props.refNo;
                showConfirm("Manage Booking", `Do you want to manage Booking #${bookingRef}?\n\nGuest: ${info.event.title}\nDates: ${dateText}\nStatus: ${props.status}`).then(confirmed => {
                    if (confirmed) window.location.href = `admin_dashboard.php?page=bookings&search=${bookingRef}`;
                });
            }
        });

        calendar.render();
    }
});
