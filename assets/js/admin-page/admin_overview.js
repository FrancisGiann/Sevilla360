document.addEventListener("DOMContentLoaded", () => {
    // Global Chart Configuration & Utilities
    const colors = { gold: "#d6a870", beige: "#fdf2e2", dark: "#2a2522", green: "#88a096", red: "#c27c7c", grid: "rgba(42, 37, 34, 0.05)" };
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = "#4a4440";
  
    const currencyFormatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    let overviewBookingRequestSequence = 0;

    function escapeHTML(str) {
        if (!str) return '';
        return str.toString().replace(/[&<>'"]/g, tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag]));
    }

    function renderOverviewPaymentHistory(payments) {
        const section = document.getElementById('ov-vd-payment-history-section');
        const list = document.getElementById('ov-vd-payment-history');
        if (!section || !list) return;
        list.replaceChildren();
        const entries = Array.isArray(payments) ? payments : [];
        section.hidden = entries.length === 0;
        entries.forEach(payment => {
            const entry = document.createElement('article');
            entry.className = 'admin-payment-history-entry';
            const amount = Number(payment?.amount);
            const fields = [
                ['Payment method', payment?.payment_method || 'N/A'],
                ['Transaction/reference ID', payment?.transaction_reference || 'N/A'],
                ['Amount', Number.isFinite(amount) ? `₱${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'N/A'],
                ['Date', payment?.payment_date || 'N/A']
            ];
            fields.forEach(([label, value]) => {
                const row = document.createElement('p');
                const labelElement = document.createElement('span');
                const valueElement = document.createElement('strong');
                labelElement.textContent = label;
                valueElement.textContent = String(value);
                row.append(labelElement, valueElement);
                entry.appendChild(row);
            });
            list.appendChild(entry);
        });
    }

    function renderOverviewRefundDetails(cancellation) {
        const section = document.getElementById('ov-vd-refund-section');
        if (!section) return;
        section.hidden = !cancellation || typeof cancellation !== 'object';
        if (section.hidden) return;
        const setText = (suffix, value) => {
            const element = document.getElementById(`ov-vd-refund-${suffix}`);
            if (element) element.textContent = String(value || 'Not provided');
        };
        setText('status', cancellation.status);
        setText('reason', cancellation.reason);
        setText('reply', cancellation.admin_reply);
        const amount = Number(cancellation.refund_amount);
        setText('amount', Number.isFinite(amount) ? `₱${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'Not available');
        setText('method', cancellation.refund_destination_method);
        setText('account-name', cancellation.refund_destination_account_name);
        setText('account-identifier', cancellation.refund_destination_account_identifier);
        const showBank = cancellation.refund_destination_method === 'Bank Transfer' && Boolean(cancellation.refund_destination_bank_name);
        const bankLabel = document.getElementById('ov-vd-refund-bank-label');
        const bankValue = document.getElementById('ov-vd-refund-bank');
        if (bankLabel) bankLabel.hidden = !showBank;
        if (bankValue) {
            bankValue.hidden = !showBank;
            bankValue.textContent = showBank ? String(cancellation.refund_destination_bank_name) : '';
        }
        const transactionId = String(cancellation.refund_transaction_id || '');
        const transactionLabel = document.getElementById('ov-vd-refund-tx-label');
        const transactionValue = document.getElementById('ov-vd-refund-tx-value');
        if (transactionLabel) transactionLabel.hidden = transactionId === '';
        if (transactionValue) {
            transactionValue.hidden = transactionId === '';
            transactionValue.textContent = transactionId;
        }
    }

    function renderOverviewProofHistory(proofs) {
        const details = document.getElementById('ov-vd-proof-history');
        const list = document.getElementById('ov-vd-proof-history-list');
        const count = document.getElementById('ov-vd-proof-history-count');
        if (!details || !list) return;
        list.replaceChildren();
        const entries = Array.isArray(proofs) ? proofs : [];
        details.hidden = entries.length === 0;
        details.open = false;
        if (count) count.textContent = entries.length ? `(${entries.length})` : '';
        const statuses = { pending: 'Pending review', approved: 'Approved', rejected: 'Rejected' };
        entries.forEach(proof => {
            const item = document.createElement('article');
            item.className = 'admin-proof-history-entry';
            const amount = Number(proof?.expected_amount);
            const fields = [
                ['Status', statuses[String(proof?.status || '').toLowerCase()] || 'Unavailable'],
                ['Payment method', proof?.payment_method || 'N/A'],
                ['Submitted reference', proof?.transaction_reference || 'N/A'],
                ['Amount', Number.isFinite(amount) ? `₱${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'N/A'],
                ['Submitted', proof?.submitted_at || 'N/A'],
                ['Reviewed', proof?.reviewed_at || 'Not reviewed']
            ];
            fields.forEach(([label, value]) => {
                const row = document.createElement('p');
                const labelElement = document.createElement('span');
                const valueElement = document.createElement('strong');
                labelElement.textContent = label;
                valueElement.textContent = String(value);
                row.append(labelElement, valueElement);
                item.appendChild(row);
            });
            if (String(proof?.status).toLowerCase() === 'rejected' && proof?.rejection_reason) {
                const rejection = document.createElement('p');
                rejection.className = 'admin-proof-rejection-reason';
                const label = document.createElement('strong');
                label.textContent = 'Rejection reason: ';
                rejection.append(label, document.createTextNode(String(proof.rejection_reason)));
                item.appendChild(rejection);
            }

            const submissionId = Number(proof?.id);
            const viewButton = document.createElement('button');
            viewButton.type = 'button';
            viewButton.className = 'admin-proof-view-button';
            const available = proof?.proof_available === true || Number(proof?.proof_available) === 1;
            viewButton.textContent = available ? 'View proof' : 'Proof no longer available';
            viewButton.disabled = !available || !Number.isSafeInteger(submissionId) || submissionId < 1;
            const preview = document.createElement('figure');
            preview.className = 'admin-proof-history-preview';
            preview.hidden = true;
            const image = document.createElement('img');
            image.alt = `Submitted payment proof for ${String(proof?.payment_method || 'payment')}`;
            image.decoding = 'async';
            image.loading = 'lazy';
            const feedback = document.createElement('p');
            feedback.className = 'admin-proof-history-feedback';
            feedback.setAttribute('role', 'status');
            feedback.hidden = true;
            preview.append(image, feedback);
            viewButton.addEventListener('click', () => {
                if (!preview.hidden) {
                    image.removeAttribute('src');
                    preview.hidden = true;
                    feedback.hidden = true;
                    viewButton.textContent = 'View proof';
                    return;
                }
                feedback.textContent = 'Loading protected proof…';
                feedback.hidden = false;
                preview.hidden = false;
                viewButton.textContent = 'Hide proof';
                image.onload = () => { feedback.hidden = true; feedback.textContent = ''; };
                image.onerror = () => {
                    image.removeAttribute('src');
                    feedback.textContent = 'This proof could not be loaded. It may have expired or the file may be missing.';
                    viewButton.textContent = 'Hide proof';
                };
                image.src = `actions/user/payment_proof.php?id=${encodeURIComponent(String(submissionId))}`;
            });
            item.append(viewButton, preview);
            list.appendChild(item);
        });
    }

    const overlay = document.getElementById('overviewModalOverlay');
    let activeModal = null;
    let modalOpener = null;

    function getModalFocusableElements(modal) {
        return [...modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            .filter(element => !element.hidden && element.getAttribute('aria-hidden') !== 'true' && element.getClientRects().length > 0);
    }

    function openOverviewModal(modal, opener = document.activeElement) {
        if (!overlay || !modal) return;
        document.querySelectorAll('.overview-modal').forEach(dialog => {
            dialog.classList.remove('active');
            dialog.setAttribute('aria-hidden', 'true');
        });
        activeModal = modal;
        modalOpener = opener instanceof HTMLElement ? opener : null;
        overlay.classList.add('active');
        overlay.setAttribute('aria-hidden', 'false');
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        window.requestAnimationFrame(() => {
            const heading = modal.querySelector('[id][tabindex="-1"]');
            (heading || getModalFocusableElements(modal)[0] || modal).focus();
        });
    }

    function closeOverviewModal(restoreFocus = true) {
        if (!overlay) return;
        document.querySelectorAll('.overview-modal').forEach(dialog => {
            dialog.classList.remove('active');
            dialog.setAttribute('aria-hidden', 'true');
        });
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
        activeModal = null;
        const opener = modalOpener;
        modalOpener = null;
        if (restoreFocus && opener?.isConnected) opener.focus();
    }

    function setMetricValue(element, value, formatter = candidate => String(candidate)) {
        if (!element) return;
        const numericValue = value === null || value === undefined || value === '' ? NaN : Number(value);
        element.classList.remove('dashboard-metric-pending');
        element.textContent = Number.isFinite(numericValue) ? formatter(numericValue) : 'Unavailable';
    }

    function setDashboardUnavailable() {
        ['stat-action-req', 'stat-arrivals-today', 'stat-occupancy-rate', 'stat-monthly-sales'].forEach(id => {
            const metric = document.getElementById(id);
            if (metric) {
                metric.textContent = 'Unavailable';
                metric.classList.remove('dashboard-metric-pending');
            }
        });
        const chartState = document.getElementById('overview-pipeline-state');
        if (chartState) chartState.textContent = 'Booking pipeline unavailable.';
        const chart = document.getElementById('statusChart');
        if (chart) chart.hidden = true;
        ['widget-today-list', 'widget-events-list'].forEach(id => {
            const target = document.getElementById(id);
            if (target) target.innerHTML = '<p class="widget-placeholder-text">Dashboard data unavailable.</p>';
        });
        const recentBookings = document.getElementById('recent-bookings-tbody');
        if (recentBookings) recentBookings.innerHTML = '<tr><td colspan="5" class="table-loading-td">Dashboard data unavailable.</td></tr>';
        const alerts = document.getElementById('maintenance-alerts-container');
        if (alerts) {
            alerts.innerHTML = '<p class="widget-placeholder-text">Maintenance alerts unavailable.</p>';
            setMaintenanceAlertsState('error');
        }
    }

    let hasSuccessfulDashboardData = false;

    function setDashboardError(message) {
        const region = document.getElementById('dashboard-status-region');
        const messageElement = document.getElementById('dashboard-status-message');
        const retryButton = document.getElementById('dashboard-retry');
        if (region && messageElement && retryButton) {
            messageElement.textContent = message || 'Dashboard data could not be loaded.';
            retryButton.hidden = false;
            region.hidden = false;
        }
        const alerts = document.getElementById('maintenance-alerts-container');
        if (alerts) {
            if (!alerts.childElementCount) {
                alerts.innerHTML = '<p class="widget-placeholder-text">Maintenance alerts unavailable.</p>';
            }
            setMaintenanceAlertsState('error');
        }
        if (!hasSuccessfulDashboardData) setDashboardUnavailable();
    }

    function setMaintenanceAlertsState(state) {
        const alerts = document.getElementById('maintenance-alerts-container');
        const layout = alerts?.closest('.overview-attention-items');
        if (!alerts || !layout) return;
        const isEmpty = state === 'empty';
        alerts.hidden = isEmpty;
        layout.classList.toggle('alerts-empty', isEmpty);
        layout.classList.toggle('alerts-visible', !isEmpty);
    }

    document.getElementById('dashboard-retry')?.addEventListener('click', () => {
        window.dispatchEvent(new CustomEvent('SevillaDashboardRefreshRequested'));
    });

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
        target.replaceChildren();
        const maintenanceModule = target.closest('.overview-maintenance');
        const supportGrid = maintenanceModule?.closest('.overview-support-grid');
        const hasMaintenance = Array.isArray(maintenance) && maintenance.length > 0;
        if (maintenanceModule) maintenanceModule.hidden = !hasMaintenance;
        supportGrid?.classList.toggle('maintenance-empty', !hasMaintenance);
        if (!hasMaintenance) {
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

    function setMaintenanceSummaryMessage(message) {
        const target = document.getElementById('overview-maintenance-summary');
        if (!target) return;
        const maintenanceModule = target.closest('.overview-maintenance');
        const supportGrid = maintenanceModule?.closest('.overview-support-grid');
        if (maintenanceModule) maintenanceModule.hidden = false;
        supportGrid?.classList.remove('maintenance-empty');
        target.innerHTML = '<p class="widget-placeholder-text">' + escapeHTML(message) + '</p>';
    }

    function loadOverviewCalendar() {
        const calendar = document.getElementById('overview-mini-calendar');
        if (calendar) calendar.innerHTML = '<p class="widget-placeholder-text">Loading calendar…</p>';
        setMaintenanceSummaryMessage('Loading maintenance…');
        fetch('actions/admin/get_master_calendar.php', { headers: { 'Accept': 'application/json' } })
            .then(response => response.ok ? response.json() : null)
            .then(events => {
                if (!Array.isArray(events)) throw new Error('Calendar data unavailable');
                renderMiniCalendar(events);
                renderMaintenanceSummary(events.filter(event => event?.extendedProps?.type === 'maintenance'));
            })
            .catch(() => {
                if (calendar) calendar.innerHTML = '<p class="widget-placeholder-text">Calendar data unavailable.</p>';
                setMaintenanceSummaryMessage('Maintenance data unavailable.');
            });
    }

    // =========================================================
    // 1. IN-PLACE OVERVIEW MODAL BRIDGES
    // =========================================================

    // --- Open In-Place Booking Details Modal ---
    window.openOverviewBookingModal = function(bookingId) {
        if (!bookingId) return;
        const requestSequence = ++overviewBookingRequestSequence;

        const overlay = document.getElementById('overviewModalOverlay');
        const modal = document.getElementById('overviewBookingModal');
        if (!overlay || !modal) return;

        document.getElementById('ov-vd-title').innerText = "Loading Details...";
        const refundSection = document.getElementById('ov-vd-refund-section');
        if (refundSection) refundSection.hidden = true;
        document.getElementById('ov-vd-customer-name').innerText = "...";
        document.getElementById('ov-vd-customer-email').innerText = "...";
        document.getElementById('ov-vd-customer-phone').innerText = "...";
        document.getElementById('ov-vd-venue').innerText = "...";
        document.getElementById('ov-vd-dates').innerText = "...";
        document.getElementById('ov-vd-guests').innerText = "...";
        if (document.getElementById('ov-vd-specific-label')) document.getElementById('ov-vd-specific-label').style.display = 'none';
        if (document.getElementById('ov-vd-specific-value')) document.getElementById('ov-vd-specific-value').style.display = 'none';

        openOverviewModal(modal);

        fetch(`actions/admin/get_booking_details.php?id=${bookingId}`)
            .then(res => res.json())
            .then(res => {
                if (requestSequence !== overviewBookingRequestSequence) return;
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

                renderOverviewPaymentHistory(res.data.payments);
                renderOverviewProofHistory(res.data.proof_history);
                renderOverviewRefundDetails(res.data.cancellation);

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
                if (requestSequence !== overviewBookingRequestSequence) return;
                console.error(err);
                document.getElementById('ov-vd-title').innerText = "Network Error";
            });
    };

    // --- Open In-Place Maintenance Details Modal ---
    window.openOverviewMaintenanceModal = function(maint) {
        if (!maint) return;

        const modal = document.getElementById('modal-maintenance-detail');
        if (!overlay || !modal) return;

        document.getElementById('md-venue').textContent = maint.venue_name || maint.name || 'Venue unavailable';
        document.getElementById('md-type').textContent = maint.maintenance_type || maint.type || 'General Maintenance';
        const opts = { month: "short", day: "numeric", year: "numeric" };
        const startDate = maint.start_date || maint.start || '';
        const endDate = maint.end_date || maint.end || startDate;
        const sDate = startDate ? new Date(startDate).toLocaleDateString("en-US", opts) : 'Date unavailable';
        const eDate = endDate ? new Date(endDate).toLocaleDateString("en-US", opts) : sDate;
        document.getElementById('md-dates').textContent = sDate === eDate ? sDate : `${sDate} — ${eDate}`;
        document.getElementById('md-notes').textContent = maint.notes || maint.custom_notes || 'No description provided.';
        openOverviewModal(modal);
    };

    // =========================================================
    // 2. DASHBOARD DATA FETCHING & METRICS ENGINE
    // =========================================================
    window.addEventListener('SevillaDashboardData', (e) => {
        const data = e.detail;
        if (!data || typeof data !== 'object' || data.error || data.success === false) return;
        hasSuccessfulDashboardData = true;

        const statusRegion = document.getElementById('dashboard-status-region');
        const statusMessage = document.getElementById('dashboard-status-message');
        const retryButton = document.getElementById('dashboard-retry');
        if (statusRegion) statusRegion.hidden = true;
        if (statusMessage) statusMessage.textContent = '';
        if (retryButton) retryButton.hidden = true;

        const alertsContainer = document.getElementById('maintenance-alerts-container');
        if (alertsContainer) {
            alertsContainer.replaceChildren();
            (Array.isArray(data.maintenanceAlerts) ? data.maintenanceAlerts : []).forEach(alert => {
                const alertElem = document.createElement('button');
                const venueName = String(alert.name || alert.venue_name || 'Venue');
                const maintenanceType = String(alert.maintenance_type || 'Maintenance');
                alertElem.type = 'button';
                alertElem.className = 'maintenance-alert';
                alertElem.title = 'View maintenance details';
                alertElem.setAttribute('aria-label', `View maintenance details for ${venueName}`);
                alertElem.innerHTML = `
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <span class="maintenance-alert-content">
                        <strong>${escapeHTML(venueName)} — Maintenance Alert (${escapeHTML(maintenanceType)})</strong>
                        <span class="maintenance-alert-detail"><strong>Maintenance Type:</strong> <span class="maint-pill-type">${escapeHTML(maintenanceType)}</span>${alert.notes ? ` &bull; <strong>Notes:</strong> ${escapeHTML(alert.notes)}` : ''}</span>
                    </span>
                `;
                alertElem.addEventListener('click', () => openOverviewMaintenanceModal(alert));
                alertsContainer.appendChild(alertElem);
            });
            setMaintenanceAlertsState(alertsContainer.childElementCount ? 'alerts' : 'empty');
        }
        const salesStat = document.getElementById('stat-monthly-sales');
        setMetricValue(document.getElementById('stat-action-req'), data.actionRequired);
        setMetricValue(document.getElementById('stat-arrivals-today'), data.arrivalsToday);
        setMetricValue(document.getElementById('stat-occupancy-rate'), data.occupancyRate, value => `${value}%`);
        if (salesStat) setMetricValue(salesStat, data.monthlySales, value => currencyFormatter.format(value));

        renderCharts(data.charts, data.userRole);
        renderTodaysOperations(data.todaysOperations);
        renderUpcomingEvents(data.upcomingEvents);
        renderRecentBookings(data.recentBookings);
    });

    window.addEventListener('SevillaDashboardError', event => {
        setDashboardError(event.detail?.message);
    });

    loadOverviewCalendar();
  
    function renderCharts(chartsData, userRole) {
        let statChart = Chart.getChart("statusChart");
        if(statChart) statChart.destroy();

        const statusCanvas = document.getElementById("statusChart");
        const chartState = document.getElementById('overview-pipeline-state');
        if (!statusCanvas || !Array.isArray(chartsData?.status)) {
            if (statusCanvas) statusCanvas.hidden = true;
            if (chartState) {
                chartState.hidden = false;
                chartState.textContent = 'Booking pipeline unavailable.';
            }
            return;
        }
        statusCanvas.hidden = false;
        if (chartState) chartState.hidden = true;
        if (statusCanvas) {
            new Chart(statusCanvas.getContext("2d"), {
                type: "doughnut",
                data: { labels: ["Confirmed", "Pending", "Cancelled", "Completed"], datasets: [{ data: chartsData.status, backgroundColor: [colors.green, colors.gold, colors.red, "#a8b99d"], borderWidth: 0, hoverOffset: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: "68%", plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 10, boxHeight: 10, padding: 12, font: { size: 10 } } } } }
            });
        }
    }

    function renderTodaysOperations(bookings) {
        const widgetList = document.getElementById('widget-today-list');
        const modalBody = document.getElementById('modal-today-tbody');
        if (!widgetList || !modalBody) return;

        widgetList.innerHTML = ''; modalBody.innerHTML = '';

        if (!Array.isArray(bookings)) {
            widgetList.innerHTML = '<p class="widget-placeholder-text">Itinerary data unavailable.</p>';
            modalBody.innerHTML = '<tr><td colspan="3" class="table-loading-td">Itinerary data unavailable.</td></tr>';
            return;
        }
        if (bookings.length === 0) {
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

            const actionLabel = isMaint
                ? `View maintenance details for ${b.venue_name || 'venue'}`
                : `View booking details for ${b.first_name || ''} ${b.last_name || ''} at ${b.venue_name || 'venue'}`;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><button type="button" class="overview-table-action"><strong>${titleText}</strong></button></td>
                <td>${subText}</td>
                <td>${badgeHtml}</td>
            `;
            const detailButton = tr.querySelector('.overview-table-action');
            detailButton.setAttribute('aria-label', actionLabel.trim());
            detailButton.addEventListener('click', () => isMaint ? openOverviewMaintenanceModal(b) : openOverviewBookingModal(b.id));
            modalBody.appendChild(tr);

            if (index < 3) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'widget-item overview-widget-action';
                item.setAttribute('aria-label', actionLabel.trim());
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

        if (!Array.isArray(events)) {
            widgetList.innerHTML = '<p class="widget-placeholder-text">Events data unavailable.</p>';
            modalBody.innerHTML = '<tr><td colspan="3" class="table-loading-td">Events data unavailable.</td></tr>';
            return;
        }
        if (events.length === 0) {
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

            const actionLabel = `View booking details for event hosted by ${e.last_name || 'guest'}`;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="color-gold"><button type="button" class="overview-table-action color-gold"><strong>${dateStr}</strong></button></td>
                <td><div>${badgeHtml} <span>${escapeHTML(eventStyle)}</span></div>
                <div>Host: <strong>${escapeHTML(e.last_name)}</strong></div></td>
                <td>${escapeHTML(e.venue_name)}</td>
            `;
            const detailButton = tr.querySelector('.overview-table-action');
            detailButton.setAttribute('aria-label', actionLabel);
            detailButton.addEventListener('click', () => openOverviewBookingModal(e.id));
            modalBody.appendChild(tr);

            if (index < 3) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'widget-item overview-widget-action';
                item.setAttribute('aria-label', actionLabel);
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

        if (!Array.isArray(bookings)) {
            tbody.innerHTML = '<tr><td colspan="5" class="table-loading-td">Bookings data unavailable.</td></tr>';
            return;
        }
        if (bookings.length === 0) {
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
            tr.innerHTML = `
                <td data-label="Booking ID"><button type="button" class="overview-table-action"></button></td>
                <td data-label="Venue">${escapeHTML(booking.venue_name)}</td>
                <td data-label="Date">${dateStr}</td>
                <td data-label="Amount">${amountText}</td>
                <td data-label="Status"><span class="badge ${badgeClass}">${statusText}</span></td>
            `;
            const detailButton = tr.querySelector('.overview-table-action');
            detailButton.textContent = `#${booking.reference_no ?? ''}`;
            detailButton.setAttribute('aria-label', `View booking details for ${booking.reference_no || 'booking'}`);
            detailButton.addEventListener('click', () => openOverviewBookingModal(booking.id));
            tbody.appendChild(tr);
        });
    }

    if (overlay) {
        document.querySelectorAll('.btn-open-modal').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = document.getElementById(btn.getAttribute('data-target'));
                if (target) openOverviewModal(target, btn);
            });
        });

        document.querySelectorAll('.close-overview-modal').forEach(btn => {
            btn.addEventListener('click', () => closeOverviewModal());
        });
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeOverviewModal();
        });

        document.addEventListener('keydown', event => {
            if (!activeModal) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                closeOverviewModal();
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = getModalFocusableElements(activeModal);
            if (!focusable.length) {
                event.preventDefault();
                activeModal.querySelector('[id][tabindex="-1"]')?.focus();
                return;
            }
            const activeIndex = focusable.indexOf(document.activeElement);
            if (activeIndex === -1) {
                event.preventDefault();
                focusable[event.shiftKey ? focusable.length - 1 : 0].focus();
            } else if (event.shiftKey && activeIndex === 0) {
                event.preventDefault();
                focusable[focusable.length - 1].focus();
            } else if (!event.shiftKey && activeIndex === focusable.length - 1) {
                event.preventDefault();
                focusable[0].focus();
            }
        });
    }
});
