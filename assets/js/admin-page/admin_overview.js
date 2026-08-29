document.addEventListener("DOMContentLoaded", () => {
    // Global Chart Configuration & Utilities
    const colors = { gold: "#d6a870", beige: "#fdf2e2", dark: "#2a2522", green: "#88a096", red: "#c27c7c", grid: "rgba(42, 37, 34, 0.05)" };
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = "#4a4440";
  
    const currencyFormatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

    function escapeHTML(str) {
        if (!str) return '';
        return str.toString().replace(/[&<>'"]/g, tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag]));
    }

    function renderMiniCalendar(events) {
        const target = document.getElementById('overview-mini-calendar');
        if (!target) return;
        const now = new Date();
        const year = now.getFullYear();
        const month = now.getMonth();
        const monthStart = new Date(year, month, 1);
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const bookingDates = new Set();
        const maintenanceDates = new Set();
        const markEventDates = (event, targetSet) => {
            const startValue = event?.start || event?.extendedProps?.startDate;
            const endValue = event?.end || event?.extendedProps?.endDate;
            if (!startValue || !endValue) return;
            const start = new Date(`${startValue}T00:00:00`);
            const end = new Date(`${endValue}T00:00:00`);
            if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return;
            for (let day = new Date(start); day < end; day.setDate(day.getDate() + 1)) {
                if (day.getFullYear() === year && day.getMonth() === month) targetSet.add(day.getDate());
            }
        };
        (events || []).forEach(event => {
            const type = event?.extendedProps?.type;
            markEventDates(event, type === 'maintenance' ? maintenanceDates : bookingDates);
        });
        const headings = ['S', 'M', 'T', 'W', 'T', 'F', 'S'].map(day => '<span class="mini-heading">' + day + '</span>').join('');
        const blanks = Array.from({ length: monthStart.getDay() }, () => '<span aria-hidden="true"></span>').join('');
        const days = Array.from({ length: daysInMonth }, (_, index) => {
            const day = index + 1;
            const classes = ['mini-day'];
            if (bookingDates.has(day)) classes.push('has-booking');
            if (maintenanceDates.has(day)) classes.push('has-maintenance');
            if (day === now.getDate()) classes.push('today');
            const label = maintenanceDates.has(day) ? 'Maintenance scheduled' : (bookingDates.has(day) ? 'Booking scheduled' : 'Available');
            return '<span class="' + classes.join(' ') + '" title="' + label + '">' + day + '</span>';
        }).join('');
        target.innerHTML = '<span class="mini-calendar-month">' + now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) + '</span>' + headings + blanks + days;
    }

    function renderMaintenanceSummary(maintenance) {
        const target = document.getElementById('overview-maintenance-summary');
        if (!target) return;
        if (!maintenance?.length) {
            target.innerHTML = '<p class="widget-placeholder-text">No active maintenance alerts.</p>';
            return;
        }
        target.innerHTML = maintenance.slice(0, 5).map(item => {
            const props = item.extendedProps || {};
            const name = item.name || item.venue_name || item.title || 'Maintenance';
            const type = item.maintenance_type || props.task || 'Maintenance';
            const start = item.start || props.startDate || '';
            const end = props.endDate || item.end || start;
            const dates = String(start) + ' – ' + String(end);
            return '<a class="maintenance-summary-item" href="admin_dashboard.php?page=maintenance" style="text-decoration:none;color:inherit"><strong>' + escapeHTML(name) + '</strong><span>' + escapeHTML(type) + ' · ' + escapeHTML(dates) + '</span></a>';
        }).join('');
    }

    function loadOverviewCalendar() {
        fetch('actions/admin/get_master_calendar.php', { headers: { 'Accept': 'application/json' } })
            .then(response => response.ok ? response.json() : null)
            .then(events => {
                if (!Array.isArray(events)) throw new Error('Calendar data unavailable');
                renderMiniCalendar(events);
                renderMaintenanceSummary(events.filter(event => event?.extendedProps?.type === 'maintenance'));
            })
            .catch(() => {
                const calendar = document.getElementById('overview-mini-calendar');
                if (calendar) calendar.innerHTML = '<p class="widget-placeholder-text">Calendar data unavailable.</p>';
                const maintenance = document.getElementById('overview-maintenance-summary');
                if (maintenance) maintenance.innerHTML = '<p class="widget-placeholder-text">Maintenance data unavailable.</p>';
            });
    }

    // =========================================================
    // 1. IN-PLACE OVERVIEW MODAL BRIDGES
    // =========================================================

    // --- Open In-Place Booking Details Modal ---
    window.openOverviewBookingModal = function(bookingId) {
        if (!bookingId) return;

        const overlay = document.getElementById('overviewModalOverlay');
        const modal = document.getElementById('overviewBookingModal');
        if (!overlay || !modal) return;

        document.getElementById('ov-vd-title').innerText = "Loading Details...";
        document.getElementById('ov-vd-customer-name').innerText = "...";
        document.getElementById('ov-vd-customer-email').innerText = "...";
        document.getElementById('ov-vd-customer-phone').innerText = "...";
        document.getElementById('ov-vd-venue').innerText = "...";
        document.getElementById('ov-vd-dates').innerText = "...";
        document.getElementById('ov-vd-guests').innerText = "...";
        if (document.getElementById('ov-vd-specific-label')) document.getElementById('ov-vd-specific-label').style.display = 'none';
        if (document.getElementById('ov-vd-specific-value')) document.getElementById('ov-vd-specific-value').style.display = 'none';

        document.querySelectorAll('.overview-modal').forEach(m => m.classList.remove('active'));
        overlay.classList.add('active');
        modal.classList.add('active');

        fetch(`actions/admin/get_booking_details.php?id=${bookingId}`)
            .then(res => res.json())
            .then(res => {
                if (!res.success) {
                    document.getElementById('ov-vd-title').innerText = "Error loading details";
                    return;
                }

                const data = res.data.booking;
                const specifics = res.data.specifics;
                const addons = res.data.addons;
                const roomAllocations = res.data.room_allocations || [];

                document.getElementById('ov-vd-title').innerText = `Booking ${data.reference_no}`;
                
                const displayStatus = data.display_booking_status || data.booking_status;
                const badge = document.getElementById('ov-vd-status-badge');
                badge.innerText = displayStatus;
                badge.className = 'status-badge ' + (displayStatus === 'Completed' ? 'status-completed' : (displayStatus === 'Confirmed' ? 'status-paid' : (displayStatus === 'Cancelled' ? 'status-refunded' : 'status-pending')));

                document.getElementById('ov-vd-customer-name').innerText = `${data.first_name} ${data.last_name}`;
                document.getElementById('ov-vd-customer-email').innerText = data.email;
                document.getElementById('ov-vd-customer-phone').innerText = data.phone || "N/A";

                document.getElementById('ov-vd-venue').innerText = `${data.venue_name} (${data.venue_category})`;
                document.getElementById('ov-vd-guests').innerText = data.guests_count;

                const opts = { month: "short", day: "numeric", year: "numeric" };
                const sDate = new Date(data.start_date).toLocaleDateString("en-US", opts);
                const eDate = new Date(data.end_date).toLocaleDateString("en-US", opts);
                document.getElementById('ov-vd-dates').innerText = (sDate === eDate) ? sDate : `${sDate} — ${eDate}`;

                const specLabel = document.getElementById('ov-vd-specific-label');
                const specValue = document.getElementById('ov-vd-specific-value');
                if (specifics) {
                    specLabel.style.display = 'block';
                    specValue.style.display = 'block';
                    if (data.venue_category === 'Event Hall') {
                        specLabel.innerText = "Event Details:";
                        let notesHtml = `<strong>${specifics.event_type}</strong> (${specifics.event_style})<br><span class="notes-cust-box"><strong>Customer Requests:</strong> ${specifics.custom_notes || 'No special requests.'}</span>`;
                        if (specifics.admin_notes) {
                            notesHtml += `<span class="notes-prep-box"><strong>Internal Prep Notes (Admin Only):</strong> ${specifics.admin_notes}</span>`;
                        }
                        specValue.innerHTML = notesHtml;
                    } else if (data.venue_category === 'Resort Villa') {
                        specLabel.innerText = "Stay Type:";
                        specValue.innerText = specifics.stay_type;
                    }
                } else {
                    specLabel.style.display = 'none';
                    specValue.style.display = 'none';
                }

                const txLabel = document.getElementById('ov-vd-transaction-label');
                const txValue = document.getElementById('ov-vd-transaction-value');
                if (res.data.transaction_id) {
                    txLabel.style.display = 'block';
                    txValue.style.display = 'block';
                    txValue.innerText = res.data.transaction_id;
                } else {
                    txLabel.style.display = 'none';
                    txValue.style.display = 'none';
                }

                const addonsContainer = document.getElementById('ov-vd-addons-container');
                const addonsList = document.getElementById('ov-vd-addons-list');
                addonsList.innerHTML = ''; 
                let hasExtras = false;

                if (addons && addons.length > 0) {
                    hasExtras = true;
                    addons.forEach(addon => {
                        addonsList.innerHTML += `<span class="label">&#8226; ${addon.name} (x${addon.quantity})</span> <span class="value">₱${parseFloat(addon.total_price).toLocaleString('en-US', {minimumFractionDigits:2})}</span>`;
                    });
                }
                if (roomAllocations.length > 0) {
                    hasExtras = true;
                    roomAllocations.forEach(room => {
                        const number = room.room_number ? ` - Room ${room.room_number}` : '';
                        addonsList.innerHTML += `<span class="label">&#8226; ${room.building_name} — ${room.room_type}${number}<br><small>${room.start_date} to ${room.end_date} (${room.nights} nights)</small></span> <span class="value">₱${parseFloat(room.line_total).toLocaleString('en-US', {minimumFractionDigits:2})}</span>`;
                    });
                }

                addonsContainer.style.display = hasExtras ? 'block' : 'none';

                document.getElementById('ov-vd-base-amt').innerText = currencyFormatter.format(data.base_amount);
                document.getElementById('ov-vd-addons-amt').innerText = currencyFormatter.format(data.addons_amount);
                document.getElementById('ov-vd-extrapax-amt').innerText = currencyFormatter.format(data.extra_pax_amount);
                document.getElementById('ov-vd-total-amt').innerText = currencyFormatter.format(data.total_amount);

                let schemeText = '100% Full Payment';
                if (data.payment_scheme === '50_percent') schemeText = '50% Downpayment';
                else if (data.payment_scheme === '20_percent') schemeText = '20% Reservation Fee';
                document.getElementById('ov-vd-scheme').innerText = schemeText;
                document.getElementById('ov-vd-paid-amt').innerText = currencyFormatter.format(data.amount_paid);

                const manageBtn = document.getElementById('ov-btn-manage-link');
                if (manageBtn) manageBtn.href = `admin_dashboard.php?page=bookings&search=${encodeURIComponent(data.reference_no)}`;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('ov-vd-title').innerText = "Network Error";
            });
    };

    // --- Open In-Place Maintenance Details Modal ---
    window.openOverviewMaintenanceModal = function(maint) {
        if (!maint) return;

        const overlay = document.getElementById('overviewModalOverlay');
        const modal = document.getElementById('modalOverviewMaintenanceDetails');
        if (!overlay || !modal) return;

        document.getElementById('ov-md-ref').innerText = `MAINT-${maint.id}`;
        document.getElementById('ov-md-venue').innerText = maint.venue_name;
        document.getElementById('ov-md-type').innerText = maint.type || maint.maintenance_type || 'General Maintenance';
        
        const opts = { month: "short", day: "numeric", year: "numeric" };
        const sDate = new Date(maint.start_date).toLocaleDateString("en-US", opts);
        const eDate = new Date(maint.end_date).toLocaleDateString("en-US", opts);
        document.getElementById('ov-md-dates').innerText = (sDate === eDate) ? sDate : `${sDate} — ${eDate}`;

        const isBlock = maint.is_blocking == 1 || maint.block_unit == 1;
        document.getElementById('ov-md-block').innerText = isBlock ? "ON (Unit Blocked)" : "OFF (Note Only)";
        document.getElementById('ov-md-block').className = "value " + (isBlock ? "text-balance-red" : "text-sub-muted");

        document.getElementById('ov-md-notes').innerText = maint.notes || maint.custom_notes || "No description provided.";

        document.querySelectorAll('.overview-modal').forEach(m => m.classList.remove('active'));
        overlay.classList.add('active');
        modal.classList.add('active');
    };

    // =========================================================
    // 2. DASHBOARD DATA FETCHING & METRICS ENGINE
    // =========================================================
    function fetchDashboardStats() {
        fetch('actions/admin/get_dashboard_stats.php')
            .then(response => response.json())
            .then(data => {
                if (!data.success) return;

                // --- Update Key Performance Metrics ---
                const revElem = document.getElementById('stat-monthly-revenue');
                if (revElem && data.monthlyRevenue !== null && data.monthlyRevenue !== undefined) {
                    revElem.innerText = currencyFormatter.format(data.monthlyRevenue);
                }

                document.getElementById('stat-action-req').innerText = data.actionRequiredCount || 0;
                document.getElementById('stat-arrivals-today').innerText = data.arrivalsTodayCount || 0;
                document.getElementById('stat-occupancy-rate').innerText = (data.occupancyRate || 0) + '%';

                // --- Update Revenue & Distribution Charts ---
                if (data.monthlyRevenue !== null && data.revenueTrend && window.revenueChartInstance) {
                    window.revenueChartInstance.data.labels = data.revenueTrend.labels;
                    window.revenueChartInstance.data.datasets[0].data = data.revenueTrend.data;
                    window.revenueChartInstance.update();
                }

                if (data.venueDistribution && window.venueChartInstance) {
                    window.venueChartInstance.data.labels = data.venueDistribution.labels;
                    window.venueChartInstance.data.datasets[0].data = data.venueDistribution.data;
                    window.venueChartInstance.update();
                }

                // =========================================================
                // 3. WIDGETS & TABLES (Today's Operations & Major Events)
                // =========================================================
                renderTodaysOperations(data.todaysOperations || []);
                renderUpcomingEvents(data.upcomingEvents || []);
                renderRecentBookings(data.recentBookings || []);
                renderMaintenanceAlerts(data.activeMaintenance || []);
            })
            .catch(err => console.error("Error loading dashboard stats:", err));
    }

    window.addEventListener('SevillaDashboardData', (e) => {
        const data = e.detail;
        if(data.error) return console.error("Dashboard Error:", data.error);

        const alertsContainer = document.getElementById('maintenance-alerts-container');
        if (alertsContainer) {
            alertsContainer.innerHTML = ''; 
            if (data.maintenanceAlerts && data.maintenanceAlerts.length > 0) {
                data.maintenanceAlerts.forEach(alert => {
                    const alertElem = document.createElement('div');
                    alertElem.className = 'maintenance-alert clickable';
                    alertElem.title = 'Click to view maintenance details';
                    alertElem.innerHTML = `
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <strong>${escapeHTML(alert.name)} — Maintenance Alert (${escapeHTML(alert.maintenance_type)})</strong>
                            <p><strong>Maintenance Type:</strong> <span class="maint-pill-type">${escapeHTML(alert.maintenance_type)}</span>${alert.notes ? ` &bull; <strong>Notes:</strong> ${escapeHTML(alert.notes)}` : ''}</p>
                        </div>
                    `;
                    alertElem.addEventListener('click', () => openOverviewMaintenanceModal(alert));
                    alertsContainer.appendChild(alertElem);
                });
            }
        }
        const revStat = document.getElementById('stat-monthly-revenue');
        if (revStat) {
            if (data.monthlyRevenue === null || data.userRole === 'staff') {
                revStat.innerHTML = '<span class="stat-restricted-text">Restricted</span>';
            } else {
                revStat.textContent = currencyFormatter.format(data.monthlyRevenue);
            }
        }
        document.getElementById('stat-action-req').textContent = data.actionRequired;
        document.getElementById('stat-arrivals-today').textContent = data.arrivalsToday;
        document.getElementById('stat-occupancy-rate').textContent = `${data.occupancyRate}%`;

        renderCharts(data.charts, data.userRole);
        renderTodaysOperations(data.todaysOperations);
        renderUpcomingEvents(data.upcomingEvents);
        renderRecentBookings(data.recentBookings);
    });

    loadOverviewCalendar();
  
    function renderCharts(chartsData, userRole) {
        let revChart = Chart.getChart("revenueChart");
        let statChart = Chart.getChart("statusChart");
        if(revChart) revChart.destroy();
        if(statChart) statChart.destroy();

        const revCanvas = document.getElementById("revenueChart");
        if (revCanvas && chartsData.revenue && !chartsData.revenue.restricted && userRole !== 'staff') {
            new Chart(revCanvas.getContext("2d"), {
                type: "bar",
                data: { labels: chartsData.revenue.labels, datasets: [{ label: "Revenue", data: chartsData.revenue.data, backgroundColor: colors.gold, borderRadius: 4, barThickness: 30 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: colors.grid } }, x: { grid: { display: false } } } }
            });
        }
  
        const statusCanvas = document.getElementById("statusChart");
        if (statusCanvas) {
            new Chart(statusCanvas.getContext("2d"), {
                type: "pie",
                data: { labels: ["Confirmed", "Pending", "Cancelled", "Completed"], datasets: [{ data: chartsData.status, backgroundColor: [colors.green, colors.gold, colors.red, "#a8b99d"], borderWidth: 0, hoverOffset: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom", labels: { usePointStyle: true } } } }
            });
        }
    }

    function renderTodaysOperations(bookings) {
        const widgetList = document.getElementById('widget-today-list');
        const modalBody = document.getElementById('modal-today-tbody');
        if (!widgetList || !modalBody) return;

        widgetList.innerHTML = ''; modalBody.innerHTML = '';

        if (!bookings || bookings.length === 0) {
            widgetList.innerHTML = '<p class="widget-placeholder-text">No active bookings today.</p>';
            modalBody.innerHTML = '<tr><td colspan="3" class="table-loading-td">No active bookings today.</td></tr>';
            return;
        }

        const todayStr = new Date().toLocaleDateString('en-CA');

        bookings.forEach((b, index) => {
            const isMaint = b.item_type === 'maintenance';

            let badgeHtml = '';
            let titleText = '';
            let subText = '';

            if (isMaint) {
                badgeHtml = `<span class="badge badge-maint-alert">Maintenance (${escapeHTML(b.maintenance_type)})</span>`;
                titleText = `Out of Order: ${escapeHTML(b.venue_name)}`;
                subText = `<span class="color-red"><i class="fa-solid fa-wrench"></i> ${escapeHTML(b.maintenance_type)}</span>`;
            } else {
                if (b.start_date === todayStr) badgeHtml = '<span class="badge status-partial">Arriving</span>';
                else if (b.end_date === todayStr) badgeHtml = '<span class="badge status-pending-refund">Checkout</span>';
                else badgeHtml = '<span class="badge status-paid">In-House</span>';

                let timeString = '';
                if (b.category === 'Hotel Room') {
                    timeString = 'In: 2:00 PM | Out: 12:00 PM';
                } else if (b.category === 'Resort Villa') {
                    timeString = (b.stay_type === 'Day Time Stay') ? 'In: 7:00 AM | Out: 5:00 PM' : 'In: 2:00 PM | Out: 12:00 PM';
                } else {
                    timeString = 'Event Hours';
                }

                titleText = `${escapeHTML(b.first_name)} ${escapeHTML(b.last_name)}`;
                subText = `${escapeHTML(b.venue_name)} <span><i class="fa-regular fa-clock"></i> ${timeString}</span>`;
            }

            const tr = document.createElement('tr');
            tr.className = 'clickable-row';
            tr.innerHTML = `
                <td><strong>${titleText}</strong></td>
                <td>${subText}</td>
                <td>${badgeHtml}</td>
            `;
            tr.addEventListener('click', () => isMaint ? openOverviewMaintenanceModal(b) : openOverviewBookingModal(b.id));
            modalBody.appendChild(tr);

            if (index < 3) {
                const item = document.createElement('div');
                item.className = 'widget-item clickable';
                item.innerHTML = `
                    <div class="widget-info">
                        <strong>${isMaint ? escapeHTML(b.venue_name) : escapeHTML(b.last_name)}</strong>
                        <span>${isMaint ? escapeHTML(b.maintenance_type) : escapeHTML(b.venue_name)}</span>
                    </div>
                    ${badgeHtml}
                `;
                item.addEventListener('click', () => isMaint ? openOverviewMaintenanceModal(b) : openOverviewBookingModal(b.id));
                widgetList.appendChild(item);
            }
        });
    }

    function renderUpcomingEvents(events) {
        const widgetList = document.getElementById('widget-events-list');
        const modalBody = document.getElementById('modal-events-tbody');
        if (!widgetList || !modalBody) return;

        widgetList.innerHTML = ''; modalBody.innerHTML = '';

        if (!events || events.length === 0) {
            widgetList.innerHTML = '<p class="widget-placeholder-text">No upcoming events.</p>';
            modalBody.innerHTML = '<tr><td colspan="3" class="table-loading-td">No upcoming events.</td></tr>';
            return;
        }

        events.forEach((e, index) => {
            const dateStr = new Date(e.start_date).toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
            const eventType = e.event_type ? e.event_type : 'General Event';
            const eventStyle = e.event_style ? `(${e.event_style})` : '';
            
            let badgeBg = '#f3f4f6'; let badgeText = '#374151'; 
            const typeLower = eventType.toLowerCase();
            
            if (typeLower.includes('wedding') || typeLower.includes('nuptial')) { badgeBg = '#fce7f3'; badgeText = '#be185d'; } 
            else if (typeLower.includes('debut') || typeLower.includes('party') || typeLower.includes('birthday')) { badgeBg = '#fef08a'; badgeText = '#a16207'; } 
            else if (typeLower.includes('seminar') || typeLower.includes('corporate') || typeLower.includes('meeting')) { badgeBg = '#dbeafe'; badgeText = '#1e40af'; }

            const badgeHtml = `<span style="background:${badgeBg}; color:${badgeText}; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">${escapeHTML(eventType)}</span>`;

            const tr = document.createElement('tr');
            tr.className = 'clickable-row';
            tr.innerHTML = `
                <td class="color-gold"><strong>${dateStr}</strong></td>
                <td><div>${badgeHtml} <span>${escapeHTML(eventStyle)}</span></div>
                <div>Host: <strong>${escapeHTML(e.last_name)}</strong></div></td>
                <td>${escapeHTML(e.venue_name)}</td>
            `;
            tr.addEventListener('click', () => openOverviewBookingModal(e.id));
            modalBody.appendChild(tr);

            if (index < 3) {
                const item = document.createElement('div');
                item.className = 'widget-item clickable';
                item.innerHTML = `
                    <div class="widget-info">
                        <strong class="color-gold">${dateStr}</strong> 
                        <strong>${escapeHTML(e.last_name)}</strong>
                        <div>${badgeHtml}</div>
                    </div>
                `;
                item.addEventListener('click', () => openOverviewBookingModal(e.id));
                widgetList.appendChild(item);
            }
        });
    }

    function renderRecentBookings(bookings) {
        const tbody = document.getElementById('recent-bookings-tbody');
        if (!tbody) return;
        tbody.innerHTML = ''; 

        if (!bookings || bookings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="table-loading-td">No recent bookings found.</td></tr>';
            return;
        }

        bookings.forEach(booking => {
            let badgeClass = 'badge-pending'; let statusText = 'Pending';
            const displayStatus = booking.display_booking_status || booking.booking_status;
            if (displayStatus === 'Completed') {
                badgeClass = 'badge-completed'; statusText = 'Completed';
            } else if (displayStatus === 'Confirmed') {
                if (booking.payment_status === 'Partial') { badgeClass = 'badge-partial'; statusText = 'Partially Paid'; } 
                else { badgeClass = 'badge-confirmed'; statusText = 'Fully Paid'; }
            } else if (displayStatus === 'Cancelled') { badgeClass = 'badge-cancelled'; statusText = 'Cancelled'; }

            if (displayStatus !== 'Completed' && booking.cancel_status === 'Pending') { badgeClass = 'badge-action'; statusText = 'Pending Refund'; }
            else if (displayStatus !== 'Completed' && booking.resched_status === 'Pending') { badgeClass = 'badge-partial'; statusText = 'Resched Req.'; }

            const dateStr = new Date(booking.start_date).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });

            let amountText = currencyFormatter.format(booking.total_amount);
            if (booking.venue_category === 'Event Hall' && displayStatus === 'Pending') {
                amountText = '<span class="tba-text">TBA</span>';
            }

            const tr = document.createElement('tr');
            tr.className = 'clickable-row';
            tr.title = 'Click to view booking details';
            tr.innerHTML = `
                <td data-label="Booking ID">#${booking.reference_no}</td>
                <td data-label="Venue">${escapeHTML(booking.venue_name)}</td>
                <td data-label="Date">${dateStr}</td>
                <td data-label="Amount">${amountText}</td>
                <td data-label="Status"><span class="badge ${badgeClass}">${statusText}</span></td>
            `;
            tr.addEventListener('click', () => openOverviewBookingModal(booking.id));
            tbody.appendChild(tr);
        });
    }

    const overlay = document.getElementById('overviewModalOverlay');
    if (overlay) {
        document.querySelectorAll('.btn-open-modal').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.overview-modal').forEach(m => m.classList.remove('active'));
                const target = document.getElementById(btn.getAttribute('data-target'));
                if (target) {
                    target.classList.add('active');
                    overlay.classList.add('active');
                }
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
    }
});
