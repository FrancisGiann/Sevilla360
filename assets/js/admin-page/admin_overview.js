document.addEventListener("DOMContentLoaded", () => {
    const colors = { gold: "#d6a870", beige: "#fdf2e2", dark: "#2a2522", green: "#88a096", red: "#c27c7c", grid: "rgba(42, 37, 34, 0.05)" };
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = "#4a4440";
  
    const currencyFormatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

    function escapeHTML(str) {
        if (!str) return '';
        return str.toString().replace(/[&<>'"]/g, tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag]));
    }
  
    async function loadDashboardData() {
        try {
            const response = await fetch('actions/admin/get_dashboard_stats.php');
            const data = await response.json();
            
            if(data.error) return console.error("Dashboard Error:", data.error);

            // 1. Top Stats
            document.getElementById('stat-monthly-revenue').textContent = currencyFormatter.format(data.monthlyRevenue);
            document.getElementById('stat-pending-items').textContent = data.pendingItems;
            document.getElementById('stat-arrivals-today').textContent = data.arrivalsToday;
            document.getElementById('stat-upcoming-events').textContent = data.upcomingEventsCount;

            // 2. Charts
            renderCharts(data.charts);
            
            // 3. Tables
            renderTodaysOperations(data.todaysOperations);
            renderUpcomingEvents(data.upcomingEvents);

        } catch (error) {
            console.error('Failed to load dashboard data:', error);
        }
    }
  
    function renderCharts(chartsData) {
        new Chart(document.getElementById("revenueChart").getContext("2d"), {
            type: "bar",
            data: {
                labels: chartsData.revenue.labels,
                datasets: [{ label: "Revenue", data: chartsData.revenue.data, backgroundColor: colors.gold, borderRadius: 4, barThickness: 30 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: colors.grid } }, x: { grid: { display: false } } } }
        });
  
        new Chart(document.getElementById("statusChart").getContext("2d"), {
            type: "pie",
            data: {
                labels: ["Confirmed", "Pending", "Cancelled"], 
                datasets: [{ data: chartsData.status, backgroundColor: [colors.green, colors.gold, colors.red], borderWidth: 0, hoverOffset: 4 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom", labels: { usePointStyle: true } } } }
        });
    }

    function renderTodaysOperations(bookings) {
        const tbody = document.getElementById('todays-operations-tbody');
        tbody.innerHTML = ''; 

        if (!bookings || bookings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; padding: 20px; color: #888;">No active bookings today. Time to relax!</td></tr>';
            return;
        }

        // Get local date string for exact "Today" comparison
        const todayStr = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD format

        bookings.forEach(b => {
            let statusBadge = '';
            
            // Logic to determine if they are checking in today or just staying over
            if (b.start_date === todayStr) {
                statusBadge = '<span class="badge" style="background: #dbeafe; color: #1e40af;">Arriving Today</span>';
            } else if (b.end_date === todayStr) {
                statusBadge = '<span class="badge" style="background: #fee2e2; color: #dc2626;">Checking Out</span>';
            } else {
                statusBadge = '<span class="badge" style="background: #dcfce7; color: #166534;">In-House</span>';
            }

            const row = `<tr>
                <td style="font-weight: 500;">${escapeHTML(b.first_name)} ${escapeHTML(b.last_name)}</td>
                <td>${escapeHTML(b.venue_name)}</td>
                <td>${statusBadge}</td>
            </tr>`;
            tbody.insertAdjacentHTML('beforeend', row);
        });
    }

    function renderUpcomingEvents(events) {
        const tbody = document.getElementById('upcoming-events-tbody');
        tbody.innerHTML = ''; 

        if (!events || events.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; padding: 20px; color: #888;">No major events in the next 30 days.</td></tr>';
            return;
        }

        events.forEach(e => {
            const dateObj = new Date(e.start_date);
            const dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
            
            // Format Event Type (e.g. "Wedding (Banquet)" or just "Event for Smith")
            let eventDesc = e.event_type ? `${e.event_type} <span style="color:#888; font-size: 0.8rem;">(${e.event_style})</span>` : `Event reservation`;
            
            const row = `<tr>
                <td style="font-weight: 600; color: var(--color-gold);">${dateStr}</td>
                <td>
                    <div style="font-weight: 500;">${escapeHTML(eventDesc)}</div>
                    <div style="font-size: 0.8rem; color: var(--color-dark-light);">Host: ${escapeHTML(e.last_name)}</div>
                </td>
                <td>${escapeHTML(e.venue_name)}</td>
            </tr>`;
            tbody.insertAdjacentHTML('beforeend', row);
        });
    }

    loadDashboardData();
});