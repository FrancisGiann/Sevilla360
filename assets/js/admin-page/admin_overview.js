/**
 * SEVILLA360 - Admin Dashboard Charts & Logic
 */
document.addEventListener("DOMContentLoaded", () => {
    const colors = { gold: "#d6a870", beige: "#fdf2e2", dark: "#2a2522", green: "#88a096", red: "#c27c7c", grid: "rgba(42, 37, 34, 0.05)" };
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = "#4a4440";
  
    const currencyFormatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

    function escapeHTML(str) {
        if (!str) return '';
        return str.toString().replace(/[&<>'"]/g, tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag]));
    }

    // Shared Helper to Open In-Place Booking Details Modal
    window.openOverviewBookingModal = function(bookingId) {
        if (!bookingId) return;

        const overlay = document.getElementById('overviewModalOverlay');
        const modal = document.getElementById('overviewBookingModal');
        if (!overlay || !modal) return;

        // Reset fields to loading
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
                const lineItems = res.data.line_items;

                document.getElementById('ov-vd-title').innerText = `Booking ${data.reference_no}`;
                
                const badge = document.getElementById('ov-vd-status-badge');
                badge.innerText = data.booking_status;
                badge.className = 'status-badge ' + (data.booking_status === 'Confirmed' ? 'status-paid' : (data.booking_status === 'Cancelled' ? 'status-refunded' : 'status-pending'));

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
                        let notesHtml = `<strong>${specifics.event_type}</strong> (${specifics.event_style})<br><span style="color:#666; font-size:0.85rem; display:block; margin-top:5px; background:rgba(0,0,0,0.03); padding:8px; border-radius:4px;"><strong>Customer Requests:</strong> ${specifics.custom_notes || 'No special requests.'}</span>`;
                        if (specifics.admin_notes) {
                            notesHtml += `<span style="color:#2a2522; font-size:0.85rem; display:block; margin-top:5px; background:#fffaf1; border-left:3px solid #d6a870; padding:8px; border-radius:4px;"><strong>Internal Prep Notes (Admin Only):</strong> ${specifics.admin_notes}</span>`;
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
                        addonsList.innerHTML += `<span class="label" style="font-weight:normal; color:#555;">&#8226; ${addon.name} (x${addon.quantity})</span> <span class="value">₱${parseFloat(addon.total_price).toLocaleString('en-US', {minimumFractionDigits:2})}</span>`;
                    });
                }

                if (lineItems && lineItems.length > 0) {
                    hasExtras = true;
                    lineItems.forEach(item => {
                        addonsList.innerHTML += `<span class="label" style="font-weight:normal; color:#555;">&#8226; ${item.item_name}</span> <span class="value">₱${parseFloat(item.amount).toLocaleString('en-US', {minimumFractionDigits:2})}</span>`;
                    });
                }

                addonsContainer.style.display = hasExtras ? 'block' : 'none';

                const formatCash = (amt) => `₱${parseFloat(amt || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                
                if (data.venue_category === 'Event Hall' && data.booking_status === 'Pending') {
                    document.getElementById('ov-vd-base-amt').innerText = "TBA";
                    document.getElementById('ov-vd-addons-amt').innerText = "TBA";
                    document.getElementById('ov-vd-extrapax-amt').innerText = "TBA";
                    document.getElementById('ov-vd-total-amt').innerText = "To Be Arranged";
                    document.getElementById('ov-vd-scheme').innerText = "To Be Arranged";
                } else {
                    document.getElementById('ov-vd-base-amt').innerText = formatCash(data.base_amount);
                    document.getElementById('ov-vd-addons-amt').innerText = formatCash(data.addons_amount);
                    document.getElementById('ov-vd-extrapax-amt').innerText = formatCash(data.extra_pax_amount);
                    document.getElementById('ov-vd-total-amt').innerText = formatCash(data.total_amount);
                    
                    if (data.payment_status === 'Paid') {
                        document.getElementById('ov-vd-scheme').innerText = "100% Fully Paid";
                    } else if (data.payment_status === 'Refunded') {
                        document.getElementById('ov-vd-scheme').innerText = "Refunded / Cancelled";
                    } else {
                        document.getElementById('ov-vd-scheme').innerText = data.payment_scheme;
                    }
                }
                
                document.getElementById('ov-vd-paid-amt').innerText = formatCash(data.amount_paid);

                const manageLink = document.getElementById('ov-btn-manage-link');
                if (manageLink) manageLink.href = `admin_dashboard.php?page=bookings&search=${data.reference_no}`;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('ov-vd-title').innerText = "Network Error";
            });
    };

    // Shared Helper to Open In-Place Maintenance Modal
    window.openOverviewMaintenanceModal = function(alert) {
        if (!alert) return;

        const overlay = document.getElementById('overviewModalOverlay');
        const modal = document.getElementById('modal-maintenance-detail');
        if (!overlay || !modal) return;

        document.getElementById('md-venue').innerText = alert.name || 'Venue';
        document.getElementById('md-type').innerText = alert.maintenance_type || 'General Maintenance';
        
        const opts = { month: "short", day: "numeric", year: "numeric" };
        const sDate = alert.start_date ? new Date(alert.start_date).toLocaleDateString("en-US", opts) : 'N/A';
        const eDate = alert.end_date ? new Date(alert.end_date).toLocaleDateString("en-US", opts) : 'N/A';
        document.getElementById('md-dates').innerText = (sDate === eDate) ? sDate : `${sDate} — ${eDate}`;
        document.getElementById('md-notes').innerText = alert.notes || 'No extra notes provided.';

        document.querySelectorAll('.overview-modal').forEach(m => m.classList.remove('active'));
        overlay.classList.add('active');
        modal.classList.add('active');
    };

    // =========================================================================
    // LISTEN FOR DATA BROADCAST FROM admin_notifications.js
    // =========================================================================
    window.addEventListener('SevillaDashboardData', (e) => {
        const data = e.detail;
        if(data.error) return console.error("Dashboard Error:", data.error);

        // 1. Maintenance Alerts
        const alertsContainer = document.getElementById('maintenance-alerts-container');
        if (alertsContainer) {
            alertsContainer.innerHTML = ''; 
            if (data.maintenanceAlerts && data.maintenanceAlerts.length > 0) {
                data.maintenanceAlerts.forEach(alert => {
                    const alertElem = document.createElement('div');
                    alertElem.className = 'maintenance-alert';
                    alertElem.style.cursor = 'pointer';
                    alertElem.title = 'Click to view maintenance details';
                    alertElem.innerHTML = `
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <strong>${escapeHTML(alert.name)} — Maintenance Alert (${escapeHTML(alert.maintenance_type)})</strong>
                            <p><strong>Maintenance Type:</strong> <span style="background: rgba(220,38,38,0.1); color: #dc2626; padding: 2px 6px; border-radius: 4px; font-weight: 600;">${escapeHTML(alert.maintenance_type)}</span>${alert.notes ? ` &bull; <strong>Notes:</strong> ${escapeHTML(alert.notes)}` : ''}</p>
                        </div>
                    `;
                    alertElem.addEventListener('click', () => openOverviewMaintenanceModal(alert));
                    alertsContainer.appendChild(alertElem);
                });
            }
        }

        // 2. Top Stats Row
        const revStat = document.getElementById('stat-monthly-revenue');
        if (revStat) {
            if (data.monthlyRevenue === null || data.userRole === 'staff') {
                revStat.innerHTML = '<span style="font-size: 1.1rem; color: #a3a3a3; font-style: italic;">Restricted</span>';
            } else {
                revStat.textContent = currencyFormatter.format(data.monthlyRevenue);
            }
        }
        document.getElementById('stat-action-req').textContent = data.actionRequired;
        document.getElementById('stat-arrivals-today').textContent = data.arrivalsToday;
        document.getElementById('stat-occupancy-rate').textContent = `${data.occupancyRate}%`;

        // 3. Render Advanced Sections
        renderCharts(data.charts, data.userRole);
        renderTodaysOperations(data.todaysOperations);
        renderUpcomingEvents(data.upcomingEvents);
        renderRecentBookings(data.recentBookings);
    });
  
    function renderCharts(chartsData, userRole) {
        let revChart = Chart.getChart("revenueChart");
        let statChart = Chart.getChart("statusChart");
        if(revChart) revChart.destroy();
        if(statChart) statChart.destroy();

        // Revenue Chart (Only if not restricted for staff)
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
                data: { labels: ["Confirmed", "Pending", "Cancelled"], datasets: [{ data: chartsData.status, backgroundColor: [colors.green, colors.gold, colors.red], borderWidth: 0, hoverOffset: 4 }] },
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
            widgetList.innerHTML = '<p style="text-align:center; padding: 10px; color:#888;">No active bookings today.</p>';
            modalBody.innerHTML = '<tr><td colspan="3" style="text-align:center;">No active bookings today.</td></tr>';
            return;
        }

        const todayStr = new Date().toLocaleDateString('en-CA');

        bookings.forEach((b, index) => {
            const isMaint = b.item_type === 'maintenance';

            let badgeHtml = '';
            let titleText = '';
            let subText = '';

            if (isMaint) {
                badgeHtml = `<span class="badge" style="background:#fee2e2; color:#dc2626; font-weight:600;">Maintenance (${escapeHTML(b.maintenance_type)})</span>`;
                titleText = `Out of Order: ${escapeHTML(b.venue_name)}`;
                subText = `<span style="display:block; font-size: 0.8rem; color: #dc2626; margin-top: 3px;"><i class="fa-solid fa-wrench"></i> ${escapeHTML(b.maintenance_type)}</span>`;
            } else {
                if (b.start_date === todayStr) badgeHtml = '<span class="badge" style="background:#dbeafe; color:#1e40af;">Arriving</span>';
                else if (b.end_date === todayStr) badgeHtml = '<span class="badge" style="background:#fee2e2; color:#dc2626;">Checkout</span>';
                else badgeHtml = '<span class="badge" style="background:#dcfce7; color:#166534;">In-House</span>';

                let timeString = '';
                if (b.category === 'Hotel Room') {
                    timeString = 'In: 2:00 PM | Out: 12:00 PM';
                } else if (b.category === 'Resort Villa') {
                    timeString = (b.stay_type === 'Day Time Stay') ? 'In: 7:00 AM | Out: 5:00 PM' : 'In: 2:00 PM | Out: 12:00 PM';
                } else {
                    timeString = 'Event Hours';
                }

                titleText = `${escapeHTML(b.first_name)} ${escapeHTML(b.last_name)}`;
                subText = `${escapeHTML(b.venue_name)} <span style="display:block; font-size: 0.8rem; color: #888; margin-top: 3px;"><i class="fa-regular fa-clock"></i> ${timeString}</span>`;
            }

            // Modal Row
            const tr = document.createElement('tr');
            tr.style.cursor = 'pointer';
            tr.innerHTML = `
                <td><strong>${titleText}</strong></td>
                <td>${subText}</td>
                <td>${badgeHtml}</td>
            `;
            tr.addEventListener('click', () => isMaint ? openOverviewMaintenanceModal(b) : openOverviewBookingModal(b.id));
            tr.addEventListener('mouseover', () => tr.style.backgroundColor = 'rgba(214,168,112,0.1)');
            tr.addEventListener('mouseout', () => tr.style.backgroundColor = 'transparent');
            modalBody.appendChild(tr);

            // Compact Widget Item
            if (index < 3) {
                const item = document.createElement('div');
                item.className = 'widget-item';
                item.style.cursor = 'pointer';
                item.innerHTML = `
                    <div class="widget-info">
                        <strong>${isMaint ? escapeHTML(b.venue_name) : escapeHTML(b.last_name)}</strong>
                        <span>${isMaint ? escapeHTML(b.maintenance_type) : escapeHTML(b.venue_name)}</span>
                    </div>
                    ${badgeHtml}
                `;
                item.addEventListener('click', () => isMaint ? openOverviewMaintenanceModal(b) : openOverviewBookingModal(b.id));
                item.addEventListener('mouseover', () => item.style.backgroundColor = 'rgba(214,168,112,0.05)');
                item.addEventListener('mouseout', () => item.style.backgroundColor = 'transparent');
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
            widgetList.innerHTML = '<p style="text-align:center; padding: 10px; color:#888;">No upcoming events.</p>';
            modalBody.innerHTML = '<tr><td colspan="3" style="text-align:center;">No upcoming events.</td></tr>';
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

            // Modal Row
            const tr = document.createElement('tr');
            tr.style.cursor = 'pointer';
            tr.innerHTML = `
                <td style="font-weight:600; color:var(--color-gold);">${dateStr}</td>
                <td><div style="margin-bottom: 5px;">${badgeHtml} <span style="font-size:0.85rem; color:#666;">${escapeHTML(eventStyle)}</span></div>
                <div style="font-size:0.85rem; color:var(--color-dark);">Host: <strong>${escapeHTML(e.last_name)}</strong></div></td>
                <td>${escapeHTML(e.venue_name)}</td>
            `;
            tr.addEventListener('click', () => openOverviewBookingModal(e.id));
            tr.addEventListener('mouseover', () => tr.style.backgroundColor = 'rgba(214,168,112,0.1)');
            tr.addEventListener('mouseout', () => tr.style.backgroundColor = 'transparent');
            modalBody.appendChild(tr);

            // Compact Widget Item
            if (index < 3) {
                const item = document.createElement('div');
                item.className = 'widget-item';
                item.style.alignItems = 'flex-start';
                item.style.cursor = 'pointer';
                item.innerHTML = `
                    <div class="widget-info">
                        <strong style="color:var(--color-gold); margin-right: 5px;">${dateStr}</strong> 
                        <strong>${escapeHTML(e.last_name)}</strong>
                        <div style="margin-top: 6px;">${badgeHtml}</div>
                    </div>
                `;
                item.addEventListener('click', () => openOverviewBookingModal(e.id));
                item.addEventListener('mouseover', () => item.style.backgroundColor = 'rgba(214,168,112,0.05)');
                item.addEventListener('mouseout', () => item.style.backgroundColor = 'transparent');
                widgetList.appendChild(item);
            }
        });
    }

    function renderRecentBookings(bookings) {
        const tbody = document.getElementById('recent-bookings-tbody');
        if (!tbody) return;
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

            // Mask price for pending events
            let amountText = currencyFormatter.format(booking.total_amount);
            if (booking.venue_category === 'Event Hall' && booking.booking_status === 'Pending') {
                amountText = '<span style="color:#b5884e; font-style:italic;">TBA</span>';
            }

            const tr = document.createElement('tr');
            tr.style.cursor = 'pointer';
            tr.title = 'Click to view booking details';
            tr.innerHTML = `
                <td>#${booking.reference_no}</td>
                <td>${escapeHTML(booking.venue_name)}</td>
                <td>${dateStr}</td>
                <td>${amountText}</td>
                <td><span class="badge ${badgeClass}">${statusText}</span></td>
            `;
            tr.addEventListener('click', () => openOverviewBookingModal(booking.id));
            tr.addEventListener('mouseover', () => tr.style.backgroundColor = 'rgba(214,168,112,0.1)');
            tr.addEventListener('mouseout', () => tr.style.backgroundColor = 'transparent');
            tbody.appendChild(tr);
        });
    }

    // Modal Handlers
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