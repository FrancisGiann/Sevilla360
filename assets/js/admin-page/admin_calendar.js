// assets/js/admin-page/admin_calendar.js
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('full-master-calendar');

    if (calendarEl && window.FullCalendar?.Calendar) {
        const mobileQuery = window.matchMedia('(max-width: 640px)');
        const supportsListWeek = () => {
            const probeEl = document.createElement('div');
            try {
                const probe = new FullCalendar.Calendar(probeEl, { initialView: 'listWeek' });
                probe.render();
                probe.destroy();
                return true;
            } catch (error) {
                return false;
            }
        };
        const listWeekAvailable = supportsListWeek();
        const mobileView = listWeekAvailable ? 'listWeek' : 'dayGridMonth';
        const viewButtons = listWeekAvailable
            ? 'dayGridMonth,dayGridWeek,listWeek'
            : 'dayGridMonth,dayGridWeek';

        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: mobileQuery.matches ? mobileView : 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: viewButtons
            },
            height: 'auto',
            events: 'actions/admin/get_master_calendar.php',

            buttonText: {
                listWeek: 'Agenda'
            },

            // Keep Month compact while giving List/Week a readable label.
            eventContent: function(arg) {
                const label = document.createElement('div');
                const statusIcon = arg.event.extendedProps.status === 'Pending' ? '⌛ ' : '';
                const title = statusIcon + (arg.event.title || 'Untitled event');
                label.className = 'fc-event-label';
                label.textContent = title;
                label.setAttribute('title', title);
                label.setAttribute('aria-label', title);
                return { domNodes: [label] };
            },

            eventDidMount: function(info) {
                const title = info.event.title || 'Untitled event';
                info.el.setAttribute('title', title);
                info.el.setAttribute('aria-label', title);
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

        mobileQuery.addEventListener?.('change', function(event) {
            const nextView = event.matches ? mobileView : 'dayGridMonth';
            if (calendar.view.type !== nextView) calendar.changeView(nextView);
        });
    }
});
