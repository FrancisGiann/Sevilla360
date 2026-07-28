/**
 * SEVILLA360 - Admin Dashboard Charts
 * Fetches live data from get_dashboard_stats.php
 */

document.addEventListener("DOMContentLoaded", () => {
    // Theme Colors array referencing style.css
    const colors = {
      gold: "#d6a870",
      beige: "#fdf2e2",
      dark: "#2a2522",
      green: "#88a096", 
      red: "#c27c7c", 
      softBlue: "#8ea4b5",
      grid: "rgba(42, 37, 34, 0.05)",
    };
  
    // Global Defaults for Typography
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = "#4a4440";
  
    // Currency Formatter
    const currencyFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    });

    // --- MISSING FUNCTION ADDED HERE ---
    // HTML Escaper for Security (XSS Protection)
    function escapeHTML(str) {
        if (!str) return '';
        return str.toString().replace(/[&<>'"]/g, 
            tag => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[tag])
        );
    }
  
    // Fetch Data from Backend
    async function loadDashboardData() {
        try {
            // Adjust this URL to match your folder structure
            const response = await fetch('actions/admin/get_dashboard_stats.php');
            const data = await response.json();
            
            if(data.error) {
                console.error("Dashboard Error:", data.error);
                return;
            }

            updateTopStats(data);
            renderCharts(data.charts);
            renderRecentBookings(data.recentBookings);

        } catch (error) {
            console.error('Failed to load dashboard data:', error);
        }
    }
  
    function updateTopStats(data) {
        document.getElementById('stat-bookings-today').textContent = data.bookingsToday;
        document.getElementById('stat-monthly-revenue').textContent = currencyFormatter.format(data.monthlyRevenue);
        document.getElementById('stat-pending-items').textContent = data.pendingItems;
        document.getElementById('stat-occupancy-rate').textContent = `${data.occupancyRate}%`;
    }
  
    function renderCharts(chartsData) {
        /* 1. Revenue Bar Chart */
        const ctxRevenue = document.getElementById("revenueChart").getContext("2d");
        new Chart(ctxRevenue, {
            type: "bar",
            data: {
                labels: chartsData.revenue.labels,
                datasets: [{
                    label: "Revenue",
                    data: chartsData.revenue.data,
                    backgroundColor: colors.gold,
                    borderRadius: 4,
                    barThickness: 30,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: colors.grid } },
                    x: { grid: { display: false } },
                },
            },
        });
  
        /* 2. Booking Status Pie Chart */
        const ctxStatus = document.getElementById("statusChart").getContext("2d");
        new Chart(ctxStatus, {
            type: "pie",
            data: {
                labels: ["Confirmed", "Pending", "Cancelled"], // Matches order in PHP array
                datasets: [{
                    data: chartsData.status,
                    backgroundColor: [colors.green, colors.gold, colors.red],
                    borderWidth: 0,
                    hoverOffset: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: "bottom", labels: { usePointStyle: true } },
                },
            },
        });
  
        /* 3. Occupancy Donut Chart */
        const ctxOccupancy = document.getElementById("occupancyChart").getContext("2d");
        new Chart(ctxOccupancy, {
            type: "doughnut",
            data: {
                labels: chartsData.occupancy.labels,
                datasets: [{
                    data: chartsData.occupancy.data,
                    backgroundColor: [colors.dark, colors.gold, colors.softBlue, colors.green],
                    borderWidth: 0,
                    hoverOffset: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "70%",
                plugins: {
                    legend: { position: "bottom", labels: { usePointStyle: true } },
                },
            },
        });
    }

    function renderRecentBookings(bookings) {
        const tbody = document.getElementById('recent-bookings-tbody');
        tbody.innerHTML = ''; 

        if (!bookings || bookings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No recent bookings found.</td></tr>';
            return;
        }

        bookings.forEach(booking => {
            // Replicate admin_bookings granular status logic
            let badgeClass = 'badge-pending';
            let statusText = 'Pending';

            if (booking.booking_status === 'Confirmed' || booking.booking_status === 'Completed') {
                if (booking.payment_status === 'Partial') {
                    badgeClass = 'badge-partial';
                    statusText = 'Partially Paid';
                } else {
                    badgeClass = 'badge-confirmed';
                    statusText = 'Fully Paid';
                }
            } else if (booking.booking_status === 'Cancelled') {
                badgeClass = 'badge-cancelled';
                statusText = 'Cancelled';
            }

            // Override for Action Required (Requests)
            if (booking.cancel_status === 'Pending') {
                badgeClass = 'badge-action';
                statusText = 'Cancel Req.';
            } else if (booking.resched_status === 'Pending') {
                badgeClass = 'badge-partial'; 
                statusText = 'Resched Req.';
            }

            const dateObj = new Date(booking.start_date);
            const dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
            const safeVenueName = escapeHTML(booking.venue_name); // Now this works!

            const row = `
                <tr>
                    <td>#${booking.reference_no}</td>
                    <td>${safeVenueName}</td>
                    <td>${dateStr}</td>
                    <td>${currencyFormatter.format(booking.total_amount)}</td>
                    <td><span class="badge ${badgeClass}">${statusText}</span></td>
                </tr>
            `;
            tbody.insertAdjacentHTML('beforeend', row);
        });
    }
    // Initialize
    loadDashboardData();
});