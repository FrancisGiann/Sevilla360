/**
 * ==========================================================================
 * SEVILLA360 - Admin Bookings Controller
 * Handles Server-Side Pagination, table filtering, dynamic modal population, and AJAX actions.
 * ==========================================================================
 */

document.addEventListener("DOMContentLoaded", () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  
    // =========================================================
    // CALENDAR ENGINE OVERRIDES (Prevents calendar.js crashes)
    // =========================================================
    window.requestDateConfirmation = function(startDate, endDate, calendarInstance) {};
    window.calculateSummary = function() {};
    window.showOverrideModal = function(newDate, calendarInstance) {
        calendarInstance.clearSelection();
        calendarInstance.startDate = newDate;
        calendarInstance.render();
    };
    
    // =========================================================
    // 1. MODAL BRIDGES to Global Modals
    // =========================================================
    window.currentAdminBookingId = null;

    const btnAdminPrint = document.getElementById('btn-admin-print');
    if (btnAdminPrint) {
        btnAdminPrint.addEventListener('click', () => {
            if (window.currentAdminBookingId) {
                window.open(`print_receipt.php?booking_id=${window.currentAdminBookingId}`, '_blank');
            }
        });
    }

    const btnAdminResend = document.getElementById('btn-admin-resend');
    if (btnAdminResend) {
        btnAdminResend.addEventListener('click', () => {
            if (!window.currentAdminBookingId) return;
            
            showConfirmModal("Are you sure you want to resend the email receipt to the customer?", () => {
                const originalText = btnAdminResend.innerHTML;
                btnAdminResend.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';
                btnAdminResend.disabled = true;

                fetch('actions/admin/resend_receipt.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ booking_id: window.currentAdminBookingId })
                })
                .then(res => res.json())
                .then(data => {
                    btnAdminResend.innerHTML = originalText;
                    btnAdminResend.disabled = false;
                    
                    if (data.success) {
                        showAlert("Success", "Receipt email has been resent to the customer.", "success");
                    } else {
                        showAlert("Error", data.message || "Failed to resend receipt.", "error");
                    }
                })
                .catch(err => {
                    console.error(err);
                    btnAdminResend.innerHTML = originalText;
                    btnAdminResend.disabled = false;
                    showAlert("Error", "Network or server error.", "error");
                });
            }, 'bookingDetailsModal');
        });
    }
    function showConfirmModal(message, callback, sourceModalId = null) {
        if(sourceModalId) {
            const sm = document.getElementById(sourceModalId);
            if(sm) sm.classList.remove("active");
        }
        window.showConfirm("Confirm Action", message).then(c => {
            if(c && callback) callback();
            if(!c && sourceModalId) {
                const sm = document.getElementById(sourceModalId);
                if(sm) sm.classList.add("active");
            }
        });
    }



    // =========================================================
    // 2. SERVER-SIDE PAGINATION & FILTERING
    // =========================================================
    const searchInput = document.getElementById("table-search");
    const venueFilter = document.getElementById("table-venue-filter");
    const tabFilters = document.querySelectorAll("#bookingFilters .tab-btn");
    const bookingFilterSelect = document.getElementById("bookingFilterSelect");
    const resetFiltersButton = document.getElementById('btn-reset-booking-filters');
    const bookingsResultsStatus = document.getElementById('booking-results-status');
    const tbody = document.getElementById("admin-bookings-tbody");
    
    const btnPrev = document.getElementById("btn-prev-page");
    const btnNext = document.getElementById("btn-next-page");
    const pagCurrent = document.getElementById("pag-current-page");
    const pagTotalPages = document.getElementById("pag-total-pages");
    const pagTotalRows = document.getElementById("pag-total-rows");
    const manualProofModal = document.getElementById('manualPaymentReviewModal');
    const manualProofStatus = document.getElementById('manual-proof-status');
    let proofReviewInvoker = null;

    function renderAdminPaymentHistory(payments, prefix) {
        const section = document.getElementById(`${prefix}-payment-history-section`);
        const list = document.getElementById(`${prefix}-payment-history`);
        if (!section || !list) return;
        list.replaceChildren();
        const entries = Array.isArray(payments) ? payments : [];
        section.hidden = entries.length === 0;
        entries.forEach((payment) => {
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
                const labelEl = document.createElement('span');
                const valueEl = document.createElement('strong');
                labelEl.textContent = label;
                valueEl.textContent = String(value);
                row.append(labelEl, valueEl);
                entry.appendChild(row);
            });
            list.appendChild(entry);
        });
    }

    function renderAdminProofHistory(proofs, prefix) {
        const details = document.getElementById(`${prefix}-proof-history`);
        const list = document.getElementById(`${prefix}-proof-history-list`);
        const count = document.getElementById(`${prefix}-proof-history-count`);
        if (!details || !list) return;
        list.replaceChildren();
        const entries = Array.isArray(proofs) ? proofs : [];
        details.hidden = entries.length === 0;
        details.open = false;
        if (count) count.textContent = entries.length ? `(${entries.length})` : '';
        const statusLabels = { pending: 'Pending review', approved: 'Approved', rejected: 'Rejected' };

        entries.forEach((proof) => {
            const item = document.createElement('article');
            item.className = 'admin-proof-history-entry';
            const fields = [
                ['Status', statusLabels[String(proof?.status || '').toLowerCase()] || 'Unavailable'],
                ['Payment method', proof?.payment_method || 'N/A'],
                ['Submitted reference', proof?.transaction_reference || 'N/A'],
                ['Amount', Number.isFinite(Number(proof?.expected_amount)) ? `₱${Number(proof.expected_amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'N/A'],
                ['Submitted', proof?.submitted_at || 'N/A'],
                ['Reviewed', proof?.reviewed_at || 'Not reviewed']
            ];
            fields.forEach(([label, value]) => {
                const row = document.createElement('p');
                const labelEl = document.createElement('span');
                const valueEl = document.createElement('strong');
                labelEl.textContent = label;
                valueEl.textContent = String(value);
                row.append(labelEl, valueEl);
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
            const proofAvailable = proof?.proof_available === true || Number(proof?.proof_available) === 1;
            viewButton.textContent = proofAvailable ? 'View proof' : 'Proof no longer available';
            viewButton.disabled = !proofAvailable || !Number.isSafeInteger(submissionId) || submissionId < 1;
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

    function renderAdminRefundDetails(cancellation, prefix) {
        const section = document.getElementById(`${prefix}-refund-section`);
        if (!section) return;
        const setText = (suffix, value) => {
            const element = document.getElementById(`${prefix}-refund-${suffix}`);
            if (element) element.textContent = String(value || 'Not provided');
        };
        const details = cancellation && typeof cancellation === 'object' ? cancellation : null;
        section.hidden = !details;
        if (!details) return;

        setText('status', details.status);
        setText('reason', details.reason);
        setText('reply', details.admin_reply);
        const amount = Number(details.refund_amount);
        setText('amount', Number.isFinite(amount) ? `₱${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'Not available');
        setText('method', details.refund_destination_method);
        setText('account-name', details.refund_destination_account_name);
        setText('account-identifier', details.refund_destination_account_identifier);

        const bankLabel = document.getElementById(`${prefix}-refund-bank-label`);
        const bankValue = document.getElementById(`${prefix}-refund-bank`);
        const showBank = details.refund_destination_method === 'Bank Transfer' && Boolean(details.refund_destination_bank_name);
        if (bankLabel) bankLabel.hidden = !showBank;
        if (bankValue) {
            bankValue.hidden = !showBank;
            bankValue.textContent = showBank ? String(details.refund_destination_bank_name) : '';
        }

        const transactionLabel = document.getElementById(`${prefix}-refund-tx-label`);
        const transactionValue = document.getElementById(`${prefix}-refund-tx-value`);
        const transactionId = String(details.refund_transaction_id || '');
        if (transactionLabel) transactionLabel.hidden = transactionId === '';
        if (transactionValue) {
            transactionValue.hidden = transactionId === '';
            transactionValue.textContent = transactionId;
        }
    }
  
    let currentPage = 1;
    const rowsPerPage = 15;
    let searchTimeout = null;
    let bookingsLoadInFlight = false;
    let bookingsLoadQueued = false;
    let queuedLoadSuppressUrlSearchAction = false;
    let bookingsRequestSequence = 0;
    let refundDestinationRequestSequence = 0;
    let realtimeRefreshTimeout = null;

    function getBookingViewState() {
        return {
            page: currentPage,
            activeTab: document.querySelector("#bookingFilters .tab-btn.active")?.getAttribute("data-filter") || bookingFilterSelect?.value || "all",
            search: searchInput?.value.trim() || '',
            venue: venueFilter?.value || 'All'
        };
    }

    function bookingViewStateChanged(state) {
        const currentState = getBookingViewState();
        return state.page !== currentState.page
            || state.activeTab !== currentState.activeTab
            || state.search !== currentState.search
            || state.venue !== currentState.venue;
    }

    function setBookingsResultsStatus(message) {
        if (bookingsResultsStatus) bookingsResultsStatus.textContent = String(message || '');
    }

    function syncBookingFilterControls(selected = getBookingViewState().activeTab) {
        tabFilters.forEach(tab => {
            const isSelected = tab.dataset.filter === selected;
            tab.classList.toggle('active', isSelected);
            tab.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });
        if (bookingFilterSelect) bookingFilterSelect.value = selected;
        if (resetFiltersButton) {
            const hasFilters = Boolean(searchInput?.value.trim())
                || (venueFilter?.value || 'All') !== 'All'
                || selected !== 'all';
            resetFiltersButton.disabled = !hasFilters;
        }
    }

    function renderTableMessage(message, actionLabel = '', actionName = '') {
        if (!tbody) return;
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 7;
        cell.className = 'table-state-cell';
        const copy = document.createElement('p');
        copy.className = 'table-state-message';
        copy.textContent = String(message || 'Bookings are unavailable.');
        cell.appendChild(copy);
        if (actionLabel && actionName) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline table-state-retry';
            button.dataset.tableAction = actionName;
            button.textContent = actionLabel;
            cell.appendChild(button);
        }
        row.appendChild(cell);
        tbody.replaceChildren(row);
    }
  
    function loadBookings(options = {}) {
        if (!tbody) return;

        const suppressUrlSearchAction = options.suppressUrlSearchAction === true;
        if (bookingsLoadInFlight) {
            bookingsLoadQueued = true;
            queuedLoadSuppressUrlSearchAction = queuedLoadSuppressUrlSearchAction || suppressUrlSearchAction;
            return;
        }

        bookingsLoadInFlight = true;
        const requestSequence = ++bookingsRequestSequence;
        const requestState = getBookingViewState();

        setBookingsResultsStatus('Loading bookings…');
        renderTableMessage('Loading bookings…');
  
        fetch('actions/admin/get_bookings_page.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                page: requestState.page,
                limit: rowsPerPage,
                search: requestState.search,
                venue: requestState.venue,
                status: requestState.activeTab,
                ...(urlBookingId !== null ? { booking_id: urlBookingId } : {})
            })
        })
        .then(res => res.json())
        .then(res => {
            if (requestSequence !== bookingsRequestSequence || bookingViewStateChanged(requestState)) return;

            if (!res.success) {
                const detail = typeof res.message === 'string' && res.message.trim() ? ` ${res.message.trim().slice(0, 300)}` : '';
                const message = `We couldn’t load bookings.${detail}`;
                setBookingsResultsStatus(`${message} Try again.`);
                renderTableMessage(message, 'Try again', 'retry');
                return;
            }
            const rows = Array.isArray(res.data) ? res.data : [];
            const rawPagination = res.pagination && typeof res.pagination === 'object' ? res.pagination : {};
            const rawTotalRows = Number(rawPagination.total_rows);
            const totalRows = Number.isSafeInteger(rawTotalRows) && rawTotalRows >= rows.length ? rawTotalRows : rows.length;
            const rawTotalPages = Number(rawPagination.total_pages);
            const totalPages = Number.isSafeInteger(rawTotalPages) && rawTotalPages > 0
                ? rawTotalPages
                : Math.max(1, Math.ceil(totalRows / rowsPerPage));
            const rawCurrentPage = Number(rawPagination.current_page);
            const page = Number.isSafeInteger(rawCurrentPage) && rawCurrentPage > 0 ? rawCurrentPage : requestState.page;
            const pagination = { current_page: page, total_pages: totalPages, total_rows: totalRows };
            renderTableRows(rows);
            updatePaginationUI(pagination);
            if (rows.length === 0) {
                setBookingsResultsStatus('No bookings found. Try changing or clearing the search and filters.');
            } else {
                const total = pagination.total_rows;
                const announcedPage = Math.min(pagination.current_page, pagination.total_pages);
                const start = total > 0 ? ((announcedPage - 1) * rowsPerPage) + 1 : 0;
                const end = Math.min(announcedPage * rowsPerPage, total);
                setBookingsResultsStatus(`Showing ${start}–${end} of ${total} bookings.`);
            }
            bindDynamicButtons(); // Re-attach modal listeners to the new buttons!
  
            // Auto-scroll, highlight, and pop open View Details modal if redirected from Master Calendar / Command Center
            if (!suppressUrlSearchAction && urlBookingId !== null) {
                const exactBookingId = urlBookingId;
                const exactMatch = Array.isArray(res.data)
                    ? res.data.find(booking => String(booking.id) === exactBookingId)
                    : null;
                if (exactMatch) {
                    highlightRow(exactMatch.reference_no);
                    setTimeout(() => {
                        const exactProofButton = openPaymentProofId
                            ? Array.from(document.querySelectorAll('.open-manual-proof')).find(button => String(button.dataset.submissionId) === openPaymentProofId)
                            : null;
                        const exactViewButton = Array.from(document.querySelectorAll('.btn-view')).find(button => String(button.getAttribute('data-id')) === exactBookingId);
                        if (exactProofButton) exactProofButton.click();
                        else if (exactViewButton) exactViewButton.click();
                    }, 300);
                }
                urlBookingId = null;
                openPaymentProofId = null;
            } else if (!suppressUrlSearchAction && urlSearch && res.data && res.data.length > 0) {
                const match = res.data.find(b => String(b.reference_no || '').toLowerCase() === urlSearch.toLowerCase() || String(b.reference_no || '').toLowerCase().includes(urlSearch.toLowerCase()) || String(b.id) === urlSearch);
                const targetRef = match ? match.reference_no : urlSearch;

                highlightRow(targetRef);
                setTimeout(() => {
                    const matchedViewBtn = match
                        ? Array.from(document.querySelectorAll('.btn-view')).find(button => String(button.getAttribute('data-id')) === String(match.id))
                        : null;
                    const viewButton = matchedViewBtn || document.querySelector('.btn-view');
                    if (viewButton) viewButton.click();
                }, 300);
            }
        })
        .catch(err => {
            if (requestSequence !== bookingsRequestSequence || bookingViewStateChanged(requestState)) return;
            setBookingsResultsStatus('We couldn’t load bookings. Check your connection and try again.');
            renderTableMessage('We couldn’t load bookings. Check your connection, then try again.', 'Try again', 'retry');
        })
        .finally(() => {
            bookingsLoadInFlight = false;
            const shouldLoadQueuedRequest = bookingsLoadQueued || bookingViewStateChanged(requestState);
            const suppressQueuedUrlSearchAction = queuedLoadSuppressUrlSearchAction;
            bookingsLoadQueued = false;
            queuedLoadSuppressUrlSearchAction = false;
            if (shouldLoadQueuedRequest) {
                loadBookings({ suppressUrlSearchAction: suppressQueuedUrlSearchAction });
            }
        });
    }

    function scheduleRealtimeBookingRefresh() {
        clearTimeout(realtimeRefreshTimeout);
        realtimeRefreshTimeout = setTimeout(() => {
            realtimeRefreshTimeout = null;
            loadBookings({ suppressUrlSearchAction: true });
        }, 250);
    }

    // Realtime gateway frames are { event_id, channel, event_type, payload }.
    // The table endpoint remains the source of truth; payload is only an invalidation signal.
    window.addEventListener('SevillaRealtimeEvent', event => {
        const detail = event?.detail;
        // Keep booking review current for every admin-side lifecycle transition
        // emitted by booking/payment actions; this is still a bounded allowlist
        // so unrelated admin channel traffic does not churn the table.
        const refreshEvents = new Set([
            'booking.created', 'booking.updated', 'booking.expired', 'booking.cancelled', 'booking.completed',
            'payment.proof_submitted', 'payment.proof_rejected', 'payment.received',
            'cancellation.requested', 'cancellation.approved', 'cancellation.rejected',
            'reschedule.requested', 'reschedule.approved', 'reschedule.rejected'
        ]);
        if (!detail || typeof detail !== 'object' || detail.channel !== 'admin' || !refreshEvents.has(detail.event_type)) return;
        if (tbody) scheduleRealtimeBookingRefresh();
    });
  
    function renderTableRows(bookings) {
        if (bookings.length === 0) {
            renderTableMessage('No bookings found for these filters. Try changing or clearing your search and filters.');
            return;
        }

        let html = '';
        const attr = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
        bookings.forEach(b => {
            const numericId = Number(b?.id);
            const bookingId = Number.isSafeInteger(numericId) && numericId > 0 ? String(numericId) : '';
            const referenceNo = String(b?.reference_no ?? '');
            const venueName = String(b?.venue_name ?? '');
            const customerName = `${String(b?.first_name ?? '')} ${String(b?.last_name ?? '')}`.trim();
            const safeDate = value => {
                const parsed = new Date(value);
                return Number.isNaN(parsed.getTime()) ? null : parsed;
            };
            const sDate = safeDate(b?.start_date);
            const eDate = safeDate(b?.end_date);
            const opts = { month: "short", day: "numeric", year: "numeric" };
            const sDateStr = sDate ? sDate.toLocaleDateString("en-US", opts) : 'Date unavailable';
            const eDateStr = eDate ? eDate.toLocaleDateString("en-US", opts) : 'Date unavailable';
            const dateStr = b?.start_date === b?.end_date || !sDate || !eDate
                ? sDateStr
                : `${sDate.toLocaleDateString("en-US", { month: "short", day: "numeric" })} - ${eDateStr}`;

            const displayStatus = String(b?.display_booking_status || b?.booking_status || 'Pending');
            const isCompleted = displayStatus === 'Completed';
            const hasPendingProof = Number(b.pending_payment_submission_id) > 0;
            const actualRoomType = (b.venue_category === 'Hotel Room') ? String(b.hotel_room_type || '') : String(b.venue_category || '');
            const rawTotal = Number.parseFloat(b.total_amount);
            const rawPaid = Number.parseFloat(b.amount_paid);
            const totalAmt = Number.isFinite(rawTotal) ? rawTotal : 0;
            const amtPaid = Number.isFinite(rawPaid) ? rawPaid : 0;
            const balanceDue = totalAmt - amtPaid;
  
            const isPendingInquiry = (b.venue_category === 'Event Hall' && displayStatus === 'Pending');
            const displayAmount = isPendingInquiry ? '<span style="color:#b5884e; font-style:italic;">To Be Arranged</span>' : `₱${totalAmt.toLocaleString('en-US', {minimumFractionDigits:2})}`;
  
            // Badges
            let badgeClass = 'status-pending'; 
            let statusText = 'Pending';
  
            if (isCompleted) {
                badgeClass = 'status-completed'; statusText = 'Completed';
            } else if (displayStatus === 'Confirmed') {
                if (b.payment_status === 'Paid') { badgeClass = 'status-paid'; statusText = 'Fully Paid'; } 
                else if (b.payment_status === 'Partial') { badgeClass = 'status-partial'; statusText = 'Partially Paid'; } 
                else if (b.payment_status === 'Refunded') { badgeClass = 'status-refunded'; statusText = 'Refunded'; }
                else { badgeClass = 'status-pending'; statusText = 'Unpaid'; }
            } else if (displayStatus === 'Cancelled') {
                badgeClass = 'status-refunded'; statusText = 'Cancelled';
            }

            if (!isCompleted && displayStatus !== 'Cancelled') {
                if (b.cancel_status === 'Pending') { badgeClass = 'status-pending-refund'; statusText = 'Pending Refund'; }
                else if (b.resched_status === 'Pending') { badgeClass = 'status-reschedule'; statusText = 'Resched Req.'; }
            }
            if (hasPendingProof && displayStatus !== 'Cancelled' && !isCompleted) { badgeClass = 'status-pending'; statusText = 'Awaiting Verification'; }
  
            const fadeClass = (displayStatus === 'Cancelled') ? 'faded-text' : '';
  
            // Keep one contextual primary action visible and place remaining
            // existing actions in the native, keyboard-operable disclosure.
            let primaryAction = '';
            const secondaryActions = [];
            const setPrimary = action => {
                if (primaryAction) secondaryActions.unshift(primaryAction);
                primaryAction = action;
            };
            const addSecondary = action => secondaryActions.push(action);
            const rowId = `data-id="${bookingId}"`;
            if (!isCompleted && displayStatus === 'Pending') {
                if (isPendingInquiry) {
                    setPrimary(`<button type="button" class="btn-action open-edit-price" ${rowId}>Edit Price / Finalize</button>`);
                    addSecondary(`<button type="button" class="btn-action btn-cancel open-decline" ${rowId}>Decline</button>`);
                } else {
                    setPrimary(`<button type="button" class="btn-action btn-confirm open-approve" ${rowId}>Approve</button>`);
                    if (!hasPendingProof) addSecondary(`<button type="button" class="btn-action btn-confirm open-payment" ${rowId} data-due="${balanceDue}">Collect Pay</button>`);
                    addSecondary(`<button type="button" class="btn-action btn-cancel open-decline" ${rowId}>Decline</button>`);
                    addSecondary(`<button type="button" class="btn-action open-edit-price" ${rowId}>Edit Price</button>`);
                }
            } 
            else if (!isCompleted && displayStatus === 'Confirmed') {
                if (b.cancel_status === 'Pending') {
                    setPrimary(`<button type="button" class="btn-action btn-refund open-refund" ${rowId} data-ref="${attr(referenceNo)}" data-customer="${attr(customerName)}" data-venue="${attr(venueName)}" data-date="${attr(dateStr)}" data-paid="${amtPaid}" data-fee-percent="${b.cancel_fee_percent === null || b.cancel_fee_percent === '' ? '' : Number(b.cancel_fee_percent)}" data-fee="${b.cancel_fee === null || b.cancel_fee === '' ? '' : Number(b.cancel_fee)}" data-refund="${b.cancel_refund === null || b.cancel_refund === '' ? '' : Number(b.cancel_refund)}" data-reason="${attr(b.cancel_reason)}">Refund Req</button>`);
                } else if (b.resched_status === 'Pending') {
                    setPrimary(`<button type="button" class="btn-action btn-reschedule open-review-resched" ${rowId} data-customer="${attr(customerName)}" data-venue="${attr(venueName)}" data-old="${attr(dateStr)}" data-newstart="${attr(b.new_start_date)}" data-newend="${attr(b.new_end_date)}" data-reason="${attr(b.resched_reason)}" data-conflict="false">Review Resched</button>`);
                } else {
                    if (!hasPendingProof && ['Unpaid', 'Partial'].includes(b.payment_status) && balanceDue > 0) {
                        setPrimary(`<button type="button" class="btn-action btn-confirm open-payment" ${rowId} data-due="${balanceDue}">Collect Pay</button>`);
                    }
                    addSecondary(`<button type="button" class="btn-action btn-reschedule open-reschedule" ${rowId} data-customer="${attr(customerName)}" data-venue="${attr(venueName)}" data-type="${attr(actualRoomType)}" data-date="${attr(dateStr)}">Reschedule</button>`);
                }
            }
            if (hasPendingProof) {
                const submissionIdValue = Number(b.pending_payment_submission_id);
                const submissionId = Number.isSafeInteger(submissionIdValue) && submissionIdValue > 0 ? submissionIdValue : 0;
                setPrimary(`<button type="button" class="btn-action btn-confirm open-manual-proof" ${rowId} data-submission-id="${submissionId}" data-ref="${attr(referenceNo)}" data-customer="${attr(customerName)}" data-venue="${attr(venueName)}" data-date="${attr(dateStr)}" data-amount="${Number(b.pending_payment_expected_amount) || 0}" data-method="${attr(b.pending_payment_method)}" data-reference="${attr(b.pending_payment_reference)}">Review Proof</button>`);
            }
            const viewAction = `<button type="button" class="btn-action btn-view" ${rowId}>View Details</button>`;
            if (primaryAction) addSecondary(viewAction);
            else primaryAction = viewAction;
            const actionMenu = secondaryActions.length
                ? `<details class="booking-action-menu"><summary aria-label="More actions for booking ${attr(referenceNo)}"><span>More actions</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary><div class="booking-action-menu-items">${secondaryActions.join('')}</div></details>`
                : '';

            html += `
            <tr class="${displayStatus === 'Cancelled' ? 'faded-row' : ''}" data-ref="${attr(referenceNo.toLowerCase())}">
                <td data-label="Booking ID" style="font-weight: 600; color: var(--color-gold);">${attr(referenceNo)}</td>
                <td data-label="Venue">${attr(venueName)}</td>
                <td data-label="Customer">${attr(customerName)}</td>
                <td data-label="Date">${attr(dateStr)}</td>
                <td data-label="Amount" class="${fadeClass}">${displayAmount}</td>
                <td data-label="Status"><div class="status-group"><span class="status-badge ${badgeClass}">${statusText}</span>${!isCompleted && Number(b.has_rescheduled) === 1 && displayStatus === 'Confirmed' ? ' <span class="status-note"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><span>Rescheduled</span></span>' : ''}</div></td>
                <td data-label="Actions" class="action-cells"><div class="action-buttons">${primaryAction}${actionMenu}</div></td>
            </tr>`;
        });

        tbody.innerHTML = html;
    }
  
    function updatePaginationUI(pag) {
        const totalRows = Number.isSafeInteger(Number(pag?.total_rows)) && Number(pag.total_rows) > 0 ? Number(pag.total_rows) : 0;
        const totalPages = Math.max(1, Number.isSafeInteger(Number(pag?.total_pages)) && Number(pag.total_pages) > 0 ? Number(pag.total_pages) : 1);
        const current = Math.min(totalPages, Math.max(1, Number.isSafeInteger(Number(pag?.current_page)) ? Number(pag.current_page) : currentPage));
        currentPage = current;
        if (pagCurrent) pagCurrent.textContent = String(current);
        if (pagTotalPages) pagTotalPages.textContent = String(totalPages);
        
        let startItem = 0;
        let endItem = 0;
        if (totalRows > 0) {
            startItem = (current - 1) * rowsPerPage + 1;
            endItem = Math.min(current * rowsPerPage, totalRows);
        }
        
        if (pagTotalRows) pagTotalRows.textContent = `${startItem}-${endItem} of ${totalRows}`;
        
        if (btnPrev) btnPrev.disabled = current <= 1;
        if (btnNext) btnNext.disabled = current >= totalPages || totalRows === 0;
    }
  
    // --- Triggers ---
    if (searchInput) {
        searchInput.addEventListener("input", () => {
            clearTimeout(searchTimeout);
            syncBookingFilterControls();
            searchTimeout = setTimeout(() => { currentPage = 1; updateExportLink(); loadBookings(); }, 400); // 400ms typing delay
        });
    }
    if (venueFilter) venueFilter.addEventListener("change", () => { syncBookingFilterControls(); currentPage = 1; updateExportLink(); loadBookings(); });
    if (btnPrev) btnPrev.addEventListener("click", () => { if (currentPage > 1) { currentPage--; loadBookings(); } });
    if (btnNext) btnNext.addEventListener("click", () => { currentPage++; loadBookings(); });

    tbody?.addEventListener('click', event => {
        if (event.target.closest('[data-table-action="retry"]')) loadBookings();
    });

    resetFiltersButton?.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        if (venueFilter) venueFilter.value = 'All';
        currentPage = 1;
        syncBookingFilterControls('all');
        updateExportLink();
        loadBookings();
        searchInput?.focus();
    });
  
    tabFilters.forEach((tab) => {
        tab.addEventListener("click", () => {
            syncBookingFilterControls(tab.dataset.filter);
            currentPage = 1;
            updateExportLink();
            loadBookings();
        });
    });

    bookingFilterSelect?.addEventListener("change", () => {
        const selected = bookingFilterSelect.value;
        syncBookingFilterControls(selected);
        currentPage = 1;
        updateExportLink();
        loadBookings();
    });

    const btnRefresh = document.getElementById('btn-refresh-bookings');
    const btnExport = document.getElementById('btn-export-bookings');
    function updateExportLink() {
        if (!btnExport) return;
        const activeTab = document.querySelector("#bookingFilters .tab-btn.active")?.getAttribute("data-filter") || bookingFilterSelect?.value || "all";
        const params = new URLSearchParams({ search: searchInput?.value.trim() || '', venue: venueFilter?.value || 'All', status: activeTab });
        btnExport.href = 'actions/admin/export_bookings.php?' + params.toString();
    }
    syncBookingFilterControls();
    updateExportLink();
    if (btnRefresh) {
        btnRefresh.addEventListener("click", () => {
            const icon = btnRefresh.querySelector('i');
            if (icon) icon.classList.add('fa-spin');
            
            // Reload silently but keep current page
            loadBookings();
            
            setTimeout(() => {
                if (icon) icon.classList.remove('fa-spin');
            }, 800);
        });
    }
  
    // URL Params parsing
    const urlParams = new URLSearchParams(window.location.search);
    const urlFilter = urlParams.get('filter');
    const urlSearch = urlParams.get('search');
    let urlBookingId = urlParams.get('booking_id');
    let openPaymentProofId = urlParams.get('open_payment_proof');

    if (urlFilter) {
        const targetTab = Array.from(tabFilters).find(tab => tab.dataset.filter === urlFilter);
        if (targetTab) {
            syncBookingFilterControls(urlFilter);
        }
    }

    if (urlSearch && searchInput) {
        searchInput.value = urlSearch;
    }
    syncBookingFilterControls();
    updateExportLink();
  
    function highlightRow(refNo) {
        const normalizedRef = String(refNo || '').toLowerCase();
        const row = Array.from(document.querySelectorAll('tr[data-ref]'))
            .find(item => item.dataset.ref === normalizedRef);
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.style.transition = "background-color 1s ease";
            row.style.backgroundColor = "rgba(214, 168, 112, 0.4)"; 
            setTimeout(() => row.style.backgroundColor = "", 2000);
        }
    }
  
    // Kickoff first load
    loadBookings();
  
    // =========================================================
    // 3. Shared AJAX Function
    // =========================================================
    const processBookingAction = (bookingId, action, buttonElement, extraData = {}) => {
      const originalText = buttonElement.innerText;
      buttonElement.innerText = "Processing...";
      buttonElement.disabled = true;
      buttonElement.style.opacity = "0.7";
      
      if (typeof window.showGlobalLoader === "function") {
          window.showGlobalLoader("Processing Request...");
      }
  
      const payload = { booking_id: bookingId, action: action, ...extraData };
  
      fetch('actions/admin/update_booking_status.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify(payload)
      })
      .then(response => response.json())
      .then(data => {
        if (typeof window.hideGlobalLoader === "function") window.hideGlobalLoader();
        
        if (data.success) {
          buttonElement.innerText = "Success!";
          buttonElement.style.backgroundColor = "#4ade80"; 
          buttonElement.style.borderColor = "#4ade80";
          showAlert("Success!", data.message, "success", true);
        } else {
          showAlert("Error", data.message, "error", false);
          buttonElement.innerText = originalText;
          buttonElement.disabled = false;
          buttonElement.style.opacity = "1";
        }
      })
      .catch(error => {
        if (typeof window.hideGlobalLoader === "function") window.hideGlobalLoader();
        showAlert("Network Error", "An error occurred while communicating with the server.", "error", false);
        buttonElement.innerText = originalText;
        buttonElement.disabled = false;
        buttonElement.style.opacity = "1";
      });
    };
  
    // =========================================================
    // 4. Modal System Close Logic
    // =========================================================
    const modalOverlay = document.getElementById('modalOverlay');
    const closeModal = () => {
      const restoreProofFocus = manualProofModal?.classList.contains('active') ? proofReviewInvoker : null;
      modalOverlay.classList.remove("active");
      document.querySelectorAll(".admin-modal").forEach((m) => m.classList.remove("active"));
      const refundTx = document.getElementById('refund-transaction-id');
      const refundReason = document.getElementById('refund-rejection-reason');
      if (refundTx) { refundTx.value = ''; refundTx.required = false; }
      if (refundReason) { refundReason.value = ''; refundReason.required = false; }
      if (manualProofStatus) { manualProofStatus.hidden = true; manualProofStatus.textContent = ''; }
      const proofImage = document.getElementById('manual-proof-preview');
      if (proofImage) { proofImage.hidden = true; proofImage.removeAttribute('src'); }
      const rejectionReason = document.getElementById('manual-proof-rejection-reason');
      if (rejectionReason) rejectionReason.value = '';
      if (manualProofModal) delete manualProofModal.dataset.submissionId;
      proofReviewInvoker = null;
      if (restoreProofFocus?.isConnected) window.requestAnimationFrame(() => restoreProofFocus.focus());
    };
  
    document.querySelectorAll(".close-modal").forEach((btn) => btn.addEventListener("click", closeModal));
    modalOverlay.addEventListener("click", (e) => { if (e.target === modalOverlay) closeModal(); });

    const reviewManualProof = async decision => {
      const submissionId = manualProofModal?.dataset.submissionId;
      const reason = document.getElementById('manual-proof-rejection-reason')?.value.trim() || '';
      if (!submissionId) return;
      if (decision === 'reject' && (!reason || reason.length > 500)) {
        manualProofStatus.textContent = 'Enter a rejection reason between 1 and 500 characters.';
        manualProofStatus.hidden = false;
        manualProofStatus.dataset.error = 'true';
        document.getElementById('manual-proof-rejection-reason')?.focus();
        return;
      }
      const approveButton = document.getElementById('btn-approve-manual-proof');
      const rejectButton = document.getElementById('btn-reject-manual-proof');
      approveButton.disabled = true;
      rejectButton.disabled = true;
      const button = decision === 'approve' ? approveButton : rejectButton;
      const originalText = button.textContent;
      button.textContent = 'Saving…';
      manualProofStatus.textContent = 'Recording the review…';
      manualProofStatus.hidden = false;
      manualProofStatus.dataset.error = 'false';
      try {
        const response = await fetch('actions/admin/review_manual_payment.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
          body: JSON.stringify({ submission_id: submissionId, decision, rejection_reason: reason })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Payment review could not be completed.');
        closeModal();
        showAlert(decision === 'approve' ? 'Payment verified' : 'Proof rejected', result.message, 'success');
        loadBookings({ suppressUrlSearchAction: true });
      } catch (error) {
        manualProofStatus.textContent = error.message || 'Payment review could not be completed.';
        manualProofStatus.hidden = false;
        manualProofStatus.dataset.error = 'true';
      } finally {
        approveButton.disabled = false;
        rejectButton.disabled = false;
        button.textContent = originalText;
      }
    };
    document.getElementById('btn-approve-manual-proof')?.addEventListener('click', () => reviewManualProof('approve'));
    document.getElementById('btn-reject-manual-proof')?.addEventListener('click', () => reviewManualProof('reject'));
    document.addEventListener('keydown', event => {
      if (!manualProofModal?.classList.contains('active')) return;
      if (event.key === 'Escape') { event.preventDefault(); closeModal(); return; }
      if (event.key !== 'Tab') return;
      const focusable = Array.from(manualProofModal.querySelectorAll('button:not([disabled]), textarea:not([disabled])')).filter(item => item.offsetParent !== null);
      if (!focusable.length) { event.preventDefault(); manualProofModal.focus(); return; }
      if (event.shiftKey && document.activeElement === focusable[0]) { event.preventDefault(); focusable.at(-1).focus(); }
      else if (!event.shiftKey && document.activeElement === focusable.at(-1)) { event.preventDefault(); focusable[0].focus(); }
    });
  
    // =========================================================
    // 5. HELPER TO RE-BIND DYNAMIC BUTTONS AFTER AJAX LOAD
    // =========================================================
    function bindDynamicButtons() {
        
        // APPROVE & DECLINE MODALS
        const approveModal = document.getElementById("approveModal");
        const declineModal = document.getElementById("declineModal");
      
        document.querySelectorAll('.open-approve').forEach(btn => {
            btn.addEventListener('click', function() {
                const bookingId = this.getAttribute('data-id');
                document.getElementById('approve-booking-id').innerText = bookingId;
                
                // Clear old listener if exists, add new one
                const executeBtn = document.getElementById('btn-execute-approve');
                const newBtn = executeBtn.cloneNode(true);
                executeBtn.parentNode.replaceChild(newBtn, executeBtn);
                
                newBtn.setAttribute('data-id', bookingId);
                newBtn.addEventListener('click', function() { processBookingAction(this.getAttribute('data-id'), 'confirm', this); });
                
                modalOverlay.classList.add('active');
                approveModal.classList.add('active');
            });
        });
      
        document.querySelectorAll('.open-decline').forEach(btn => {
            btn.addEventListener('click', function() {
                const bookingId = this.getAttribute('data-id');
                document.getElementById('decline-booking-id').innerText = bookingId;
                
                const executeBtn = document.getElementById('btn-execute-decline');
                const newBtn = executeBtn.cloneNode(true);
                executeBtn.parentNode.replaceChild(newBtn, executeBtn);
                
                newBtn.setAttribute('data-id', bookingId);
                newBtn.addEventListener('click', function() { processBookingAction(this.getAttribute('data-id'), 'cancel', this); });
                
                modalOverlay.classList.add('active');
                declineModal.classList.add('active');
            });
        });
      
        // REFUND MODAL
        const refundModal = document.getElementById("refundModal");
        document.querySelectorAll('.open-refund').forEach(btn => {
          btn.addEventListener('click', function() {
            const bookingId = this.getAttribute('data-id');
            const referenceId = this.getAttribute('data-ref') || bookingId;
            const requestSequence = ++refundDestinationRequestSequence;
            const totalPaid = parseFloat(this.getAttribute('data-paid')) || 0;
            const feePercentRaw = this.getAttribute('data-fee-percent');
            const parsedFeePercent = feePercentRaw !== null && feePercentRaw !== '' ? Number(feePercentRaw) : 0;
            const feePercent = Number.isFinite(parsedFeePercent) && parsedFeePercent >= 0 ? parsedFeePercent : 0;
            const feeAttr = this.getAttribute('data-fee');
            const refundAttr = this.getAttribute('data-refund');
            const fee = feeAttr !== null && feeAttr !== '' ? Number(feeAttr) : Math.round(totalPaid * feePercent) / 100;
            const refundAmt = refundAttr !== null && refundAttr !== '' ? Number(refundAttr) : Math.max(0, Math.round((totalPaid - fee) * 100) / 100);
      
            const titleEl = document.querySelector('#refundModal .modal-main-title');
            if(titleEl) titleEl.textContent = `Mark refund sent — Booking ${referenceId}`;

            const transactionInput = document.getElementById('refund-transaction-id');
            const rejectionInput = document.getElementById('refund-rejection-reason');
            if (transactionInput) { transactionInput.value = ''; transactionInput.required = true; }
            if (rejectionInput) { rejectionInput.value = ''; rejectionInput.required = false; }
      
            const spans = document.querySelectorAll('#refundModal .summary-grid .value');
            if (spans.length >= 5) {
                spans[0].textContent = this.getAttribute('data-customer') || "Unknown";
                spans[1].textContent = this.getAttribute('data-venue') || "Unknown";
                spans[2].textContent = this.getAttribute('data-date') || "--";
                spans[3].textContent = `₱${totalPaid.toLocaleString()}`;
                spans[4].textContent = fee > 0 ? `₱${fee.toLocaleString(undefined, {minimumFractionDigits: 2})} (${feePercent}%)` : '₱0.00 (no fee deducted)';
            }
            
            const reasonEl = document.getElementById('modal-ref-reason');
            if (reasonEl) reasonEl.textContent = this.getAttribute('data-reason') || "No reason provided by customer.";
      
            const refundTotalEl = document.querySelector('#refundModal .refund-total .amount');
            if (refundTotalEl) refundTotalEl.textContent = `₱${refundAmt.toLocaleString()}`;

            const destinationSection = document.getElementById('refund-destination-section');
            const destinationStatus = document.getElementById('refund-destination-status');
            const destinationMethod = document.getElementById('refund-destination-method');
            const destinationName = document.getElementById('refund-destination-account-name');
            const destinationIdentifier = document.getElementById('refund-destination-account-identifier');
            const destinationBankLabel = document.getElementById('refund-destination-bank-label');
            const destinationBank = document.getElementById('refund-destination-bank');
            if (destinationSection) destinationSection.hidden = true;
            if (destinationStatus) {
              destinationStatus.hidden = false;
              destinationStatus.dataset.error = 'false';
              destinationStatus.textContent = 'Loading the saved refund destination…';
            }
            [destinationMethod, destinationName, destinationIdentifier, destinationBank].forEach(element => {
              if (element) element.textContent = '—';
            });
            if (destinationBankLabel) destinationBankLabel.hidden = true;
            if (destinationBank) destinationBank.hidden = true;
      
            const executeBtn = document.querySelector('#refundModal .btn-modal-refund');
            const newBtn = executeBtn.cloneNode(true);
            executeBtn.parentNode.replaceChild(newBtn, executeBtn);
            newBtn.disabled = true;
            newBtn.setAttribute('data-id', bookingId);
            newBtn.addEventListener('click', function() {
              if (this.disabled) return;
              if (transactionInput) { transactionInput.required = true; transactionInput.reportValidity(); }
              if (rejectionInput) rejectionInput.required = false;
              const refundTxId = document.getElementById('refund-transaction-id').value.trim();
              if (!refundTxId) {
                  showAlert("Missing Data", "Please enter the Refund Transaction / Reference ID.", "error");
                  return;
              }
              if (!/^[A-Za-z0-9][A-Za-z0-9._:/-]{0,159}$/.test(refundTxId)) {
                  showAlert("Invalid reference", "Use up to 160 letters, numbers, periods, underscores, colons, slashes, or hyphens.", "error", 'refundModal');
                  transactionInput?.focus();
                  return;
              }
              showConfirmModal("Have you already sent this refund to the customer’s saved destination? Mark it sent only after the transfer is complete.", () => {
                  processBookingAction(this.getAttribute('data-id'), 'refund', this, { refund_transaction_id: refundTxId });
              }, 'refundModal');
            });

            let newRejectBtn = null;
            const rejectBtn = document.getElementById('btn-reject-refund-inline');
            if (rejectBtn) {
              newRejectBtn = rejectBtn.cloneNode(true);
              rejectBtn.parentNode.replaceChild(newRejectBtn, rejectBtn);
              newRejectBtn.setAttribute('data-id', bookingId);
              newRejectBtn.disabled = true;
              newRejectBtn.addEventListener('click', function() {
                if (transactionInput) transactionInput.required = false;
                if (rejectionInput) rejectionInput.required = true;
                const reason = document.getElementById('refund-rejection-reason')?.value.trim() || '';
                if (!reason) {
                  showAlert('Missing reason', 'A reason is required to reject a refund.', 'error', 'refundModal');
                  return;
                }
                if (reason.length > 500) {
                  showAlert('Invalid reason', 'The rejection reason must be 500 characters or fewer.', 'error', 'refundModal');
                  return;
                }
                showConfirmModal('Reject this refund request? The booking and payment will remain unchanged.', () => {
                  processBookingAction(this.getAttribute('data-id'), 'reject_refund', this, { reason });
                }, 'refundModal');
              });
            }
      
            modalOverlay.classList.add('active');
            refundModal.classList.add('active');
            transactionInput?.focus({ preventScroll: true });

            fetch(`actions/admin/get_booking_details.php?id=${encodeURIComponent(bookingId)}`, { headers: { Accept: 'application/json' } })
              .then(response => response.ok ? response.json() : Promise.reject(new Error('Unable to load booking details.')))
              .then(result => {
                if (requestSequence !== refundDestinationRequestSequence || !refundModal.classList.contains('active')) return;
                const cancellation = result?.success ? result.data?.cancellation : null;
                const destination = cancellation ? {
                  method: String(cancellation.refund_destination_method || ''),
                  accountName: String(cancellation.refund_destination_account_name || ''),
                  accountIdentifier: String(cancellation.refund_destination_account_identifier || ''),
                  bankName: String(cancellation.refund_destination_bank_name || '')
                } : null;
                const methodAllowed = ['GCash', 'Maya', 'Bank Transfer'].includes(destination?.method);
                const hasRequiredDetails = methodAllowed && destination.accountName.trim().length >= 2
                  && destination.accountIdentifier.trim().length >= 4
                  && (destination.method !== 'Bank Transfer' || destination.bankName.trim().length >= 2);
                const requestIsPending = cancellation?.status === 'Pending';
                if (destinationSection) destinationSection.hidden = !cancellation;
                if (destinationMethod) destinationMethod.textContent = destination?.method || 'Not provided';
                if (destinationName) destinationName.textContent = destination?.accountName || 'Not provided';
                if (destinationIdentifier) destinationIdentifier.textContent = destination?.accountIdentifier || 'Not provided';
                const showBank = destination?.method === 'Bank Transfer' && Boolean(destination.bankName);
                if (destinationBankLabel) destinationBankLabel.hidden = !showBank;
                if (destinationBank) {
                  destinationBank.hidden = !showBank;
                  destinationBank.textContent = showBank ? destination.bankName : '';
                }
                if (destinationStatus) {
                  destinationStatus.hidden = requestIsPending && hasRequiredDetails;
                  destinationStatus.dataset.error = String(!requestIsPending || !hasRequiredDetails);
                  destinationStatus.textContent = !cancellation
                    ? 'No cancellation request was found. Refresh the bookings list before continuing.'
                    : !requestIsPending
                      ? 'This refund request is no longer pending. Refresh the bookings list before continuing.'
                      : 'This refund request has incomplete destination details. Contact the customer before sending funds.';
                }
                newBtn.disabled = !requestIsPending || !hasRequiredDetails;
                if (newRejectBtn) newRejectBtn.disabled = !requestIsPending;
              })
              .catch(() => {
                if (requestSequence !== refundDestinationRequestSequence || !refundModal.classList.contains('active')) return;
                if (destinationStatus) {
                  destinationStatus.hidden = false;
                  destinationStatus.dataset.error = 'true';
                  destinationStatus.textContent = 'The saved destination could not be verified. Close and reopen the refund request to try again.';
                }
                newBtn.disabled = true;
              });
          });
        });
      
        // RESCHEDULE MODAL 
        const rescheduleModal = document.getElementById("rescheduleModal");
        let rescheduleCalendar = null;
        if (typeof SevillaCalendar !== 'undefined' && document.getElementById("cal-ui-reschedule")) {
            rescheduleCalendar = new SevillaCalendar("cal-ui-reschedule");
        }
      
        document.querySelectorAll('.open-reschedule').forEach(btn => {
          btn.addEventListener('click', function() {
            const bookingId = this.getAttribute('data-id');
            const venueType = this.getAttribute('data-type') || "Hotel Room"; 
            const venueName = this.getAttribute('data-venue') || "Standard Room"; 
      
            const spans = document.querySelectorAll('#rescheduleModal .summary-grid .value');
            if (spans.length >= 3) {
                spans[0].innerText = this.getAttribute('data-customer') || "Unknown";
                spans[1].innerText = venueName;
                spans[2].innerText = this.getAttribute('data-date') || "--";
            }
      
            if (rescheduleCalendar) {
                rescheduleCalendar.clearSelection();
                rescheduleCalendar.fetchBookedDates(venueType, venueName);
                setTimeout(() => rescheduleCalendar.render(), 100); 
            }
      
            const executeBtn = document.querySelector('#rescheduleModal .btn-modal-refund'); 
            const newBtn = executeBtn.cloneNode(true);
            executeBtn.parentNode.replaceChild(newBtn, executeBtn);
            
            newBtn.setAttribute('data-id', bookingId);
            newBtn.addEventListener('click', function() {
              if (!rescheduleCalendar || !rescheduleCalendar.startDate) {
                  return showAlert("Missing Data", "Please select the new dates from the calendar first!", "error", 'rescheduleModal');
              }
              const formatLocal = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
              const newStart = formatLocal(rescheduleCalendar.startDate);
              const newEnd = rescheduleCalendar.endDate ? formatLocal(rescheduleCalendar.endDate) : newStart;
          
              showConfirmModal(`Confirm rescheduling to ${newStart}?`, () => {
                  processBookingAction(bookingId, 'reschedule', this, { new_start_date: newStart, new_end_date: newEnd });
              }, 'rescheduleModal');
            });
      
            modalOverlay.classList.add('active');
            rescheduleModal.classList.add('active');
          });
        });
      
        // COLLECT PAYMENT MODAL
        const paymentModal = document.getElementById("paymentModal");
        const pmtMethodSelect = document.getElementById("pmt-method");
        const pmtTransWrapper = document.getElementById("pmt-trans-wrapper");
        const pmtAmountInput = document.getElementById("pmt-amount-input");
      
        if (pmtMethodSelect) {
            pmtMethodSelect.addEventListener("change", function() {
                pmtTransWrapper.style.display = (this.value === "Cash") ? "none" : "block";
            });
        }
      
        document.querySelectorAll('.open-payment').forEach(btn => {
            btn.addEventListener('click', function() {
                const balanceDue = parseFloat(this.getAttribute('data-due')) || 0;
                document.getElementById('pmt-balance').innerText = `₱${balanceDue.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                
                if (pmtAmountInput) { pmtAmountInput.value = ""; pmtAmountInput.placeholder = `Enter amount (Max: ₱${balanceDue.toLocaleString()})`; }
                if (pmtMethodSelect) pmtMethodSelect.value = "Cash";
                if (pmtTransWrapper) pmtTransWrapper.style.display = "none";
                if (document.getElementById('pmt-trans-id')) document.getElementById('pmt-trans-id').value = "";
                
                const executeBtn = document.getElementById('btn-execute-payment');
                const newBtn = executeBtn.cloneNode(true);
                executeBtn.parentNode.replaceChild(newBtn, executeBtn);
                
                newBtn.setAttribute('data-id', this.getAttribute('data-id'));
                newBtn.addEventListener('click', function() {
                    const amount = parseFloat(pmtAmountInput.value); 
                    const method = pmtMethodSelect.value;
                    const transId = document.getElementById('pmt-trans-id').value.trim();
          
                    if (isNaN(amount) || amount <= 0) return showAlert("Invalid Amount", "Please enter a valid payment amount.", "error", 'paymentModal');
                    if (method !== 'Cash' && transId === '') return showAlert("Missing Data", "Please enter a Transaction ID for online/bank payments.", "error", 'paymentModal');
          
                    showConfirmModal(`Confirm receipt of ₱${amount.toLocaleString()} via ${method}?`, () => {
                        processBookingAction(this.getAttribute('data-id'), 'add_payment', this, { amount: amount, method: method, transaction_id: transId });
                    }, 'paymentModal');
                });
      
                modalOverlay.classList.add('active');
                paymentModal.classList.add('active');
            });
        });

        document.querySelectorAll('.open-manual-proof').forEach(btn => {
            btn.addEventListener('click', function() {
                proofReviewInvoker = this;
                manualProofModal.dataset.submissionId = this.dataset.submissionId;
                document.getElementById('manual-proof-context').textContent = `Booking ${this.dataset.ref || `#${this.dataset.id}`} · ${this.dataset.customer || 'Customer'} · ${this.dataset.venue || 'Venue'} · ${this.dataset.date || 'Date'}`;
                document.getElementById('manual-proof-amount').textContent = `₱${Number(this.dataset.amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                document.getElementById('manual-proof-method').textContent = this.dataset.method || '—';
                document.getElementById('manual-proof-reference').textContent = this.dataset.reference || '—';
                document.getElementById('manual-proof-rejection-reason').value = '';
                manualProofStatus.hidden = true;
                manualProofStatus.textContent = '';
                const proofImage = document.getElementById('manual-proof-preview');
                manualProofStatus.textContent = 'Loading protected receipt…';
                manualProofStatus.hidden = false;
                manualProofStatus.dataset.error = 'false';
                proofImage.onload = () => { manualProofStatus.hidden = true; manualProofStatus.textContent = ''; };
                proofImage.onerror = () => {
                    proofImage.hidden = true;
                    manualProofStatus.textContent = 'The protected receipt could not be loaded. Refresh the booking list or contact an administrator.';
                    manualProofStatus.hidden = false;
                    manualProofStatus.dataset.error = 'true';
                };
                proofImage.src = `actions/user/payment_proof.php?id=${encodeURIComponent(this.dataset.submissionId)}`;
                proofImage.hidden = false;
                modalOverlay.classList.add('active');
                manualProofModal.classList.add('active');
                window.requestAnimationFrame(() => manualProofModal.focus());
            });
        });
       // REVIEW RESCHEDULE REQUEST MODAL
       const reviewReschedModal = document.getElementById("reviewReschedModal");
      
        document.querySelectorAll('.open-review-resched').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('rr-customer').innerText = this.getAttribute('data-customer');
                document.getElementById('rr-venue').innerText = this.getAttribute('data-venue');
                document.getElementById('rr-old-dates').innerText = this.getAttribute('data-old');
                document.getElementById('rr-reason').innerText = this.getAttribute('data-reason') || "No reason provided.";
                
                const opts = { month: "short", day: "numeric", year: "numeric" };
                const d1 = new Date(this.getAttribute('data-newstart')).toLocaleDateString("en-US", opts);
                const d2 = new Date(this.getAttribute('data-newend')).toLocaleDateString("en-US", opts);
                document.getElementById('rr-new-dates').innerText = (d1 === d2) ? d1 : `${d1} — ${d2}`;
      
                const warningBox = document.getElementById('rr-conflict-warning');
                
                let oldApproveBtn = document.getElementById('btn-approve-resched');
                let newApproveBtn = oldApproveBtn.cloneNode(true);
                oldApproveBtn.parentNode.replaceChild(newApproveBtn, oldApproveBtn);
                
                if (this.getAttribute('data-conflict') === 'true') {
                    warningBox.style.display = 'block';
                    newApproveBtn.disabled = true; newApproveBtn.style.opacity = '0.5'; newApproveBtn.style.cursor = 'not-allowed';
                } else {
                    warningBox.style.display = 'none';
                    newApproveBtn.disabled = false; newApproveBtn.style.opacity = '1'; newApproveBtn.style.cursor = 'pointer';
                }
      
                document.getElementById('rr-reject-box').style.display = 'none';
                document.getElementById('rr-reject-reason').value = "";
                
                let oldRejectBtn = document.getElementById('btn-reject-resched');
                let newRejectBtn = oldRejectBtn.cloneNode(true);
                oldRejectBtn.parentNode.replaceChild(newRejectBtn, oldRejectBtn);
                newRejectBtn.innerText = "Reject Request";
      
                newApproveBtn.setAttribute('data-id', this.getAttribute('data-id'));
                newApproveBtn.setAttribute('data-newstart', this.getAttribute('data-newstart'));
                newApproveBtn.setAttribute('data-newend', this.getAttribute('data-newend'));
                newRejectBtn.setAttribute('data-id', this.getAttribute('data-id'));
                
                newApproveBtn.addEventListener('click', function() {
                    if (this.disabled) return;
                    showConfirmModal("Approve this request? The dates will be permanently moved.", () => {
                        processBookingAction(this.getAttribute('data-id'), 'reschedule', this, { new_start_date: this.getAttribute('data-newstart'), new_end_date: this.getAttribute('data-newend') });
                    }, 'reviewReschedModal');
                });
                
                newRejectBtn.addEventListener('click', function() {
                    const rejectBox = document.getElementById('rr-reject-box');
                    if (rejectBox.style.display === 'none') {
                        rejectBox.style.display = 'block';
                        this.innerText = "Confirm Rejection";
                    } else {
                        const reason = document.getElementById('rr-reject-reason').value.trim();
                        if (reason === "") return showAlert("Error", "Please provide a reason for rejecting this request.", "error", "reviewReschedModal");
              
                        showConfirmModal("Reject this request? The booking will remain on its original dates.", () => {
                            processBookingAction(this.getAttribute('data-id'), 'reject_reschedule', this, { admin_reply: reason });
                        }, 'reviewReschedModal');
                    }
                });
      
                modalOverlay.classList.add('active');
                reviewReschedModal.classList.add('active');
            });
        });
      
        // VIEW DETAILS MODAL
        const viewDetailsModal = document.getElementById("viewDetailsModal");
      
        document.querySelectorAll('.btn-view').forEach(btn => {
          btn.addEventListener('click', function() {
              const originalText = this.innerText;
              this.innerText = "Loading...";
              this.disabled = true;
      
              fetch(`actions/admin/get_booking_details.php?id=${this.getAttribute('data-id')}`)
              .then(response => response.json())
              .then(res => {
                  this.innerText = originalText;
                  this.disabled = false;
      
                  if (!res.success) return showAlert("Error", "Error loading details: " + res.message, "error", false);
      
                  const data = res.data.booking;
                  const specifics = res.data.specifics;
                  const addons = res.data.addons;
                  const lineItems = res.data.line_items; 
                  
                  window.currentAdminBookingId = data.id;
      
                  document.getElementById('vd-title').innerText = `Booking ${data.reference_no}`;
                  
                  const displayStatus = data.display_booking_status || data.booking_status;
                  const isCompleted = displayStatus === 'Completed';
                  const badge = document.getElementById('vd-status-badge');
                  badge.innerText = displayStatus + (Number(data.has_rescheduled) === 1 && displayStatus === 'Confirmed' ? ' — Rescheduled' : '');
                  badge.className = 'status-badge ' + (displayStatus === 'Completed' ? 'status-completed' : (displayStatus === 'Confirmed' ? 'status-paid' : (displayStatus === 'Cancelled' ? 'status-refunded' : 'status-pending')));
      
                  document.getElementById('vd-customer-name').innerText = `${data.first_name} ${data.last_name}`;
                  document.getElementById('vd-customer-email').innerText = data.email;
                  document.getElementById('vd-customer-phone').innerText = data.phone || "N/A";
      
                  document.getElementById('vd-venue').innerText = `${data.venue_name} (${data.venue_category})`;
                  document.getElementById('vd-guests').innerText = data.guests_count;
                  
                  const opts = { month: "short", day: "numeric", year: "numeric" };
                  const sDate = new Date(data.start_date).toLocaleDateString("en-US", opts);
                  const eDate = new Date(data.end_date).toLocaleDateString("en-US", opts);
                  document.getElementById('vd-dates').innerText = (sDate === eDate) ? sDate : `${sDate} — ${eDate}`;
      
                  const specLabel = document.getElementById('vd-specific-label');
                  const specValue = document.getElementById('vd-specific-value');
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

                renderAdminPaymentHistory(res.data.payments, 'vd');
                renderAdminProofHistory(res.data.proof_history, 'vd');

                  renderAdminRefundDetails(res.data.cancellation, 'vd');
      
                  const addonsContainer = document.getElementById('vd-addons-container');
                  const addonsList = document.getElementById('vd-addons-list');
                  const roomAllocations = res.data.room_allocations;
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
                          if (roomAllocations?.length && item.item_name.startsWith('Room Add-on:')) return;
                          addonsList.innerHTML += `<span class="label" style="font-weight:normal; color:#555;">&#8226; ${item.item_name}</span> <span class="value">₱${parseFloat(item.amount).toLocaleString('en-US', {minimumFractionDigits:2})}</span>`;
                      });
                  }

                  if (roomAllocations && roomAllocations.length > 0) {
                      hasExtras = true;
                      roomAllocations.forEach(room => {
                          const rNum = room.room_number ? ` - Rm ${room.room_number}` : '';
                          addonsList.innerHTML += `<span class="label" style="font-weight:normal; color:#555;">&#8226; Room: ${room.building_name} - ${room.room_type}${rNum}<br><small>${room.start_date} to ${room.end_date} (${room.nights} nights)</small></span> <span class="value">₱${parseFloat(room.line_total).toLocaleString('en-US', {minimumFractionDigits:2})}</span>`;
                      });
                  }
      
                  if (hasExtras) addonsContainer.style.display = 'block';
                  else addonsContainer.style.display = 'none';
      
                  const formatCash = (amt) => `₱${parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
                  
                  if (data.venue_category === 'Event Hall' && data.booking_status === 'Pending') {
                      document.getElementById('vd-base-amt').innerText = "TBA";
                      document.getElementById('vd-addons-amt').innerText = "TBA";
                      document.getElementById('vd-extrapax-amt').innerText = "TBA";
                      document.getElementById('vd-total-amt').innerText = "To Be Arranged";
                      document.getElementById('vd-scheme').innerText = "To Be Arranged";
                  } else {
                      document.getElementById('vd-base-amt').innerText = formatCash(data.base_amount);
                      document.getElementById('vd-addons-amt').innerText = formatCash(data.addons_amount);
                      document.getElementById('vd-extrapax-amt').innerText = formatCash(data.extra_pax_amount);
                      document.getElementById('vd-total-amt').innerText = formatCash(data.total_amount);
                      
                      if (data.payment_status === 'Paid') {
                          document.getElementById('vd-scheme').innerText = "100% Fully Paid";
                      } else if (data.payment_status === 'Refunded') {
                          document.getElementById('vd-scheme').innerText = "Refunded / Cancelled";
                      } else {
                          document.getElementById('vd-scheme').innerText = data.payment_scheme;
                      }
                  }
                  
                  document.getElementById('vd-paid-amt').innerText = formatCash(data.amount_paid);

                  const btnAdminPrint = document.getElementById('btn-admin-print');
                  const btnAdminResend = document.getElementById('btn-admin-resend');
                  const canOpenPdfReceipt = displayStatus !== 'Pending'
                      && displayStatus !== 'Cancelled'
                      && data.payment_scheme !== 'To Be Arranged';
                  const canResendReceipt = !isCompleted
                      && displayStatus !== 'Pending'
                      && displayStatus !== 'Cancelled'
                      && data.payment_scheme !== 'To Be Arranged';

                  if (btnAdminPrint) {
                      btnAdminPrint.style.display = canOpenPdfReceipt ? 'inline-flex' : 'none';
                  }
                  if (btnAdminResend) {
                      btnAdminResend.style.display = canResendReceipt ? 'inline-flex' : 'none';
                  }
      
                  modalOverlay.classList.add('active');
                  viewDetailsModal.classList.add('active');
              })
              .catch(err => {
                  console.error(err);
                  this.innerText = originalText;
                  this.disabled = false;
                  showAlert("Network Error", "Network error fetching details.", "error", false);
              });
          });
        });
      
        // EVENT INVOICE / EDIT PRICE MODAL
        const editPriceModal = document.getElementById("editPriceModal");
        const lineItemsContainer = document.getElementById("ep-line-items");
        const baseRateInput = document.getElementById("ep-base-rate");
        const calcTotalDisplay = document.getElementById("ep-calc-total");
        const eventStyleSelect = document.getElementById("ep-event-style");
        const eventStyleHelp = document.getElementById("ep-event-style-help");

        function normalizeEventStyle(style) {
            const value = String(style || '').toLowerCase().replace(/[^a-z]/g, '');
            if (value.startsWith('theater')) return 'theater';
            if (value.startsWith('classroom')) return 'classroom';
            if (value.startsWith('banquet')) return 'banquet';
            return '';
        }

        function updateEventStyleCapacity(capacities) {
            if (!eventStyleSelect) return;
            const guestsInput = document.getElementById('ep-guests');
            const capacity = parseInt(capacities?.[eventStyleSelect.value], 10) || 0;
            if (guestsInput) guestsInput.max = capacity > 0 ? String(capacity) : '';
            if (eventStyleHelp) {
                eventStyleHelp.textContent = capacity > 0
                    ? `Database capacity: ${capacity} guests.`
                    : 'Select a canonical seating style with a configured database capacity.';
            }
        }

        eventStyleSelect?.addEventListener('change', () => updateEventStyleCapacity(eventStyleSelect.dataset.capacities ? JSON.parse(eventStyleSelect.dataset.capacities) : {}));
      
        function calculateInvoiceTotal() {
            let total = parseFloat(baseRateInput.value) || 0;
            document.querySelectorAll(".ep-item-cost").forEach(input => {
                total += (parseFloat(input.value) || 0);
            });
            calcTotalDisplay.innerText = `₱${total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
        }
      
        function addLineItemRow(name = "", amount = "") {
            const row = document.createElement("div");
            row.className = "ep-row";
            row.style.cssText = "display:flex; gap:10px; margin-bottom:10px;";
            row.innerHTML = `
                <input type="text" class="ep-item-name" value="${name}" placeholder="Item Description" style="flex: 2; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <input type="number" class="ep-item-cost ep-calc-trigger" value="${amount}" step="0.01" placeholder="Amount (₱)" style="flex: 1; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <button type="button" class="btn-action ep-remove-row" style="flex: 0 0 45px; background: #fee2e2; color: #dc2626; border: none; border-radius: 4px; cursor: pointer; padding: 0;"><i class="fa-solid fa-trash"></i></button>
            `;
            lineItemsContainer.appendChild(row);
      
            row.querySelector(".ep-calc-trigger").addEventListener("input", calculateInvoiceTotal);
            row.querySelector(".ep-remove-row").addEventListener("click", () => {
                row.remove();
                calculateInvoiceTotal();
            });
        }
      
        // Remove old listeners to prevent duplicates
        const oldAddBtn = document.getElementById("ep-btn-add-item");
        if(oldAddBtn) {
            const newAddBtn = oldAddBtn.cloneNode(true);
            oldAddBtn.parentNode.replaceChild(newAddBtn, oldAddBtn);
            newAddBtn.addEventListener("click", () => addLineItemRow());
        }
        
        baseRateInput?.addEventListener("input", calculateInvoiceTotal);
      
        document.querySelectorAll('.open-edit-price').forEach(btn => {
            btn.addEventListener('click', function() {
                const bookingId = this.getAttribute('data-id');
                const originalText = this.innerText;
                this.innerText = "Loading...";
                
                fetch(`actions/admin/get_booking_details.php?id=${bookingId}`)
                .then(res => res.json())
                .then(res => {
                    this.innerText = originalText;
                    if (!res.success) return showAlert("Error", res.message, "error", false);
      
                    const data = res.data.booking;
                    const specifics = res.data.specifics;
                    const addons = res.data.addons; 
                    const lineItems = res.data.line_items; 
                    const eventStyleCapacities = res.data.event_style_capacities || {};
      
                    document.getElementById('ep-booking-id').innerText = `${data.reference_no}`;
                    document.getElementById('ep-guests').value = data.guests_count;
                    document.getElementById('ep-event-type').value = specifics ? specifics.event_type : "";
                    if (eventStyleSelect) {
                        eventStyleSelect.dataset.capacities = JSON.stringify(eventStyleCapacities);
                        eventStyleSelect.value = normalizeEventStyle(specifics?.event_style);
                        updateEventStyleCapacity(eventStyleCapacities);
                    }
                    if (document.getElementById('ep-admin-notes')) {
                        document.getElementById('ep-admin-notes').value = specifics ? (specifics.admin_notes || "") : "";
                    }
                    baseRateInput.value = parseFloat(data.base_amount).toFixed(2);
                    
                    lineItemsContainer.innerHTML = ""; 
      
                    if (lineItems && lineItems.length > 0) {
                        lineItems.forEach(item => addLineItemRow(item.item_name, item.amount));
                    } else if (addons && addons.length > 0) {
                        addons.forEach(addon => {
                            addLineItemRow(`${addon.name} (x${addon.quantity})`, parseFloat(addon.total_price).toFixed(2));
                        });
                    }
      
                    calculateInvoiceTotal();
      
                    const executeBtn = document.getElementById('btn-execute-edit-price');
                    const newBtn = executeBtn.cloneNode(true);
                    executeBtn.parentNode.replaceChild(newBtn, executeBtn);
                    
                    newBtn.setAttribute('data-id', bookingId);
                    newBtn.addEventListener('click', function() {
                        const guests = document.getElementById('ep-guests').value;
                        const eventType = document.getElementById('ep-event-type').value;
                        const eventStyleKey = eventStyleSelect?.value || '';
                        const baseRate = document.getElementById('ep-base-rate').value;
                        const scheme = document.getElementById('ep-payment-scheme').value;
                        const adminNotes = document.getElementById('ep-admin-notes') ? document.getElementById('ep-admin-notes').value.trim() : "";
                  
                        let lineItemsArr = [];
                        document.querySelectorAll(".ep-row").forEach(row => {
                            const name = row.querySelector(".ep-item-name").value.trim();
                            const cost = parseFloat(row.querySelector(".ep-item-cost").value) || 0;
                            if (name !== "" && cost >= 0) lineItemsArr.push({ name: name, amount: cost });
                        });

                        if (!eventStyleKey) {
                            showAlert("Seating Style Required", "Select Theater, Classroom, or Banquet before finalizing this Event Hall invoice.", "error", false);
                            return;
                        }
                  
                        showConfirmModal(`Finalize invoice and email customer?`, () => {
                            processBookingAction(this.getAttribute('data-id'), 'finalize_event_invoice', this, { 
                                guests: guests, 
                                event_type: eventType, 
                                event_style_key: eventStyleKey,
                                base_rate: baseRate,
                                payment_scheme: scheme,
                                admin_notes: adminNotes,
                                line_items: lineItemsArr
                            });
                        }, 'editPriceModal');
                    });
      
                    modalOverlay.classList.add('active');
                    editPriceModal.classList.add('active');
                });
            });
        });
    }
  });
