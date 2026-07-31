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

            // 1. Maintenance Alerts
            const alertsContainer = document.getElementById('maintenance-alerts-container');
            alertsContainer.innerHTML = ''; // Clear old alerts
            if (data.maintenanceAlerts && data.maintenanceAlerts.length > 0) {
                data.maintenanceAlerts.forEach(alert => {
                    alertsContainer.insertAdjacentHTML('beforeend', `
                        <div class="maintenance-alert">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div>
                                <strong>Out of Order: ${escapeHTML(alert.name)}</strong>
                                <p>${escapeHTML(alert.maintenance_type)} - ${escapeHTML(alert.notes || 'No extra notes provided.')}</p>
                            </div>
                        </div>
                    `);
                });
            }

            // 2. Stats
            document.getElementById('stat-monthly-revenue').textContent = currencyFormatter.format(data.monthlyRevenue);
            document.getElementById('stat-action-req').textContent = data.actionRequired; // Combined Cancels/Rescheds/Pending
            document.getElementById('stat-arrivals-today').textContent = data.arrivalsToday;
            document.getElementById('stat-occupancy-rate').textContent = `${data.occupancyRate}%`;

            // 3. Charts & Layout Components
            renderCharts(data.charts);
            renderTodaysOperations(data.todaysOperations);
            renderUpcomingEvents(data.upcomingEvents);
            renderRecentBookings(data.recentBookings);

        } catch (error) {
            console.error('Failed to load dashboard data:', error);
        }
    }
  
    function renderCharts(chartsData) {
        new Chart(document.getElementById("revenueChart").getContext("2d"), {
            type: "bar",
            data: { labels: chartsData.revenue.labels, datasets: [{ label: "Revenue", data: chartsData.revenue.data, backgroundColor: colors.gold, borderRadius: 4, barThickness: 30 }] },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return currencyFormatter.format(context.raw);
                            }
                        }
                    }
                }
            }
        });
  
        new Chart(document.getElementById("statusChart").getContext("2d"), {
            type: "pie",
            data: { labels: ["Confirmed", "Pending", "Cancelled"], datasets: [{ data: chartsData.status, backgroundColor: [colors.green, colors.gold, colors.red], borderWidth: 0, hoverOffset: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom", labels: { usePointStyle: true } } } }
        });
    }

    // --- 1. Today's Itinerary (Widget + Modal) UPDATED WITH TIMES ---
    function renderTodaysOperations(bookings) {
        const widgetList = document.getElementById('widget-today-list');
        const modalBody = document.getElementById('modal-today-tbody');
        widgetList.innerHTML = ''; modalBody.innerHTML = '';

        if (!bookings || bookings.length === 0) {
            widgetList.innerHTML = '<p style="text-align:center; padding: 10px; color:#888;">No active bookings today.</p>';
            modalBody.innerHTML = '<tr><td colspan="3" style="text-align:center;">No active bookings today.</td></tr>';
            return;
        }

        const todayStr = new Date().toLocaleDateString('en-CA');

        bookings.forEach((b, index) => {
            // 1. Determine Status Badge
            let badgeHtml = '';
            if (b.start_date === todayStr) badgeHtml = '<span class="badge" style="background:#dbeafe; color:#1e40af;">Arriving</span>';
            else if (b.end_date === todayStr) badgeHtml = '<span class="badge" style="background:#fee2e2; color:#dc2626;">Checkout</span>';
            else badgeHtml = '<span class="badge" style="background:#dcfce7; color:#166534;">In-House</span>';

            // 2. Determine Check-in / Check-out Times
            let timeString = '';
            if (b.category === 'Hotel Room') {
                timeString = 'In: 2:00 PM | Out: 12:00 PM';
            } else if (b.category === 'Resort Villa') {
                timeString = (b.stay_type === 'Day Time Stay') ? 'In: 7:00 AM | Out: 5:00 PM' : 'In: 2:00 PM | Out: 12:00 PM';
            } else {
                timeString = 'Event Hours';
            }

            // Populate Modal (All items)
            modalBody.insertAdjacentHTML('beforeend', `<tr>
                <td><strong>${escapeHTML(b.first_name)} ${escapeHTML(b.last_name)}</strong></td>
                <td>
                    ${escapeHTML(b.venue_name)}
                    <span style="display:block; font-size: 0.8rem; color: #888; margin-top: 3px;"><i class="fa-regular fa-clock"></i> ${timeString}</span>
                </td>
                <td>${badgeHtml}</td>
            </tr>`);

            // Populate Compact Widget (Max 3 items)
            if (index < 3) {
                widgetList.insertAdjacentHTML('beforeend', `<div class="widget-item">
                    <div class="widget-info">
                        <strong>${escapeHTML(b.last_name)}</strong>
                        <span>${escapeHTML(b.venue_name)}</span>
                        <span style="font-size: 0.75rem; color: #a3a3a3; margin-top: 2px;"><i class="fa-regular fa-clock"></i> ${timeString}</span>
                    </div>
                    ${badgeHtml}
                </div>`);
            }
        });
    }

    // --- 2. Upcoming Events (Widget + Modal) ---
    function renderUpcomingEvents(events) {
        const widgetList = document.getElementById('widget-events-list');
        const modalBody = document.getElementById('modal-events-tbody');
        widgetList.innerHTML = ''; modalBody.innerHTML = '';

        if (!events || events.length === 0) {
            widgetList.innerHTML = '<p style="text-align:center; padding: 10px; color:#888;">No upcoming events.</p>';
            modalBody.innerHTML = '<tr><td colspan="3" style="text-align:center;">No upcoming events.</td></tr>';
            return;
        }

        events.forEach((e, index) => {
            const dateStr = new Date(e.start_date).toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
            const eventType = e.event_type ? e.event_type : 'General Event';
            const eventStyle = e.event_style ? `(${e.event_style})` : '';
            
            let badgeBg = '#f3f4f6'; let badgeText = '#374151'; // Default Gray
            const typeLower = eventType.toLowerCase();
            
            if (typeLower.includes('wedding') || typeLower.includes('nuptial')) { badgeBg = '#fce7f3'; badgeText = '#be185d'; } 
            else if (typeLower.includes('debut') || typeLower.includes('party')) { badgeBg = '#fef08a'; badgeText = '#a16207'; } 
            else if (typeLower.includes('seminar') || typeLower.includes('corporate')) { badgeBg = '#dbeafe'; badgeText = '#1e40af'; }

            const badgeHtml = `<span style="background:${badgeBg}; color:${badgeText}; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">${escapeHTML(eventType)}</span>`;
            
            // NEW: Click action URL
            const clickAction = `window.location.href='admin_dashboard.php?page=bookings&search=${e.id}'`;

            // Modal Row (Hover effect added)
            modalBody.insertAdjacentHTML('beforeend', `<tr style="cursor:pointer;" onclick="${clickAction}" onmouseover="this.style.backgroundColor='rgba(214,168,112,0.1)'" onmouseout="this.style.backgroundColor='transparent'">
                <td style="font-weight:600; color:var(--color-gold);">${dateStr}</td>
                <td><div style="margin-bottom: 5px;">${badgeHtml} <span style="font-size:0.85rem; color:#666;">${escapeHTML(eventStyle)}</span></div>
                <div style="font-size:0.85rem; color:var(--color-dark);">Host: <strong>${escapeHTML(e.last_name)}</strong></div></td>
                <td>${escapeHTML(e.venue_name)}</td>
            </tr>`);

            // Compact Widget Row
            if (index < 3) {
                widgetList.insertAdjacentHTML('beforeend', `<div class="widget-item" style="align-items: flex-start; cursor:pointer;" onclick="${clickAction}" onmouseover="this.style.backgroundColor='rgba(214,168,112,0.05)'" onmouseout="this.style.backgroundColor='transparent'">
                    <div class="widget-info">
                        <strong style="color:var(--color-gold); margin-right: 5px;">${dateStr}</strong> 
                        <strong>${escapeHTML(e.last_name)}</strong>
                        <div style="margin-top: 6px;">${badgeHtml}</div>
                    </div>
                </div>`);
            }
        });
    }
    // --- 3. Recent Bookings (Restored Full Width Table) ---
    function renderRecentBookings(bookings) {
        const tbody = document.getElementById('recent-bookings-tbody');
        tbody.innerHTML = ''; 

        if (!bookings || bookings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No recent bookings found.</td></tr>';
            return;
        }

        bookings.forEach(booking => {
            let badgeClass = 'badge-pending'; let statusText = 'Pending';
            if (booking.booking_status === 'Confirmed' || booking.booking_status === 'Completed') {
                if (booking.payment_status === 'Partial') { badgeClass = 'badge-partial'; statusText = 'Partially Paid'; } 
                else { badgeClass = 'badge-confirmed'; statusText = 'Fully Paid'; }
            } else if (booking.booking_status === 'Cancelled') { badgeClass = 'badge-cancelled'; statusText = 'Cancelled'; }

            if (booking.cancel_status === 'Pending') { badgeClass = 'badge-action'; statusText = 'Cancel Req.'; } 
            else if (booking.resched_status === 'Pending') { badgeClass = 'badge-partial'; statusText = 'Resched Req.'; }

            const dateStr = new Date(booking.start_date).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });

            tbody.insertAdjacentHTML('beforeend', `<tr>
                <td>#${booking.reference_no}</td>
                <td>${escapeHTML(booking.venue_name)}</td>
                <td>${dateStr}</td>
                <td>${currencyFormatter.format(booking.total_amount)}</td>
                <td><span class="badge ${badgeClass}">${statusText}</span></td>
            </tr>`);
        });
    }

    // --- 4. Modal Open/Close Logic ---
    const overlay = document.getElementById('overviewModalOverlay');
    document.querySelectorAll('.btn-open-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.overview-modal').forEach(m => m.classList.remove('active'));
            document.getElementById(btn.getAttribute('data-target')).classList.add('active');
            overlay.classList.add('active');
        });
    });

    document.querySelectorAll('.close-overview-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            overlay.classList.remove('active');
            document.querySelectorAll('.overview-modal').forEach(m => m.classList.remove('active'));
        });
    });
    
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            overlay.classList.remove('active');
            document.querySelectorAll('.overview-modal').forEach(m => m.classList.remove('active'));
        }
    });

    // Initialize immediately on load
    loadDashboardData();

    // The Flex: Auto-refresh the dashboard data every 60 seconds silently
    setInterval(() => {
        loadDashboardData();
    }, 60000); 
});