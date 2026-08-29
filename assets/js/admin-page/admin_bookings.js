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
    const tbody = document.getElementById("admin-bookings-tbody");
    
    const btnPrev = document.getElementById("btn-prev-page");
    const btnNext = document.getElementById("btn-next-page");
    const pagCurrent = document.getElementById("pag-current-page");
    const pagTotalPages = document.getElementById("pag-total-pages");
    const pagTotalRows = document.getElementById("pag-total-rows");
  
    let currentPage = 1;
    const rowsPerPage = 15;
    let searchTimeout = null;
  
    function loadBookings() {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 40px; color: #888;">
                            <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 1.5rem; margin-bottom: 10px; color: var(--color-gold);"></i><br>
                            Loading Bookings...
                           </td></tr>`;
        
        const activeTab = document.querySelector("#bookingFilters .tab-btn.active")?.getAttribute("data-filter") || bookingFilterSelect?.value || "all";
        const searchTerm = searchInput.value.trim();
        const venue = venueFilter.value;
  
        fetch('actions/admin/get_bookings_page.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                page: currentPage,
                limit: rowsPerPage,
                search: searchTerm,
                venue: venue,
                status: activeTab
            })
        })
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: red;">Error: ${res.message}</td></tr>`;
                return;
            }
  
            renderTableRows(res.data);
            updatePaginationUI(res.pagination);
            bindDynamicButtons(); // Re-attach modal listeners to the new buttons!
  
            // Auto-scroll, highlight, and pop open View Details modal if redirected from Master Calendar / Command Center
            if (urlSearch && res.data && res.data.length > 0) {
                const match = res.data.find(b => b.reference_no.toLowerCase() === urlSearch.toLowerCase() || b.reference_no.toLowerCase().includes(urlSearch.toLowerCase()) || b.id.toString() === urlSearch);
                const targetRef = match ? match.reference_no : urlSearch;

                highlightRow(targetRef);
                setTimeout(() => {
                    const firstViewBtn = document.querySelector('.btn-view');
                    if (firstViewBtn) {
                        firstViewBtn.click();
                    }
                }, 300);
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: red;">Network Error occurred.</td></tr>`;
        });
    }
  
    function renderTableRows(bookings) {
        if (bookings.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 40px;">No bookings found.</td></tr>`;
            return;
        }
  
        let html = '';
        bookings.forEach(b => {
            // Date Formatting
            const sDate = new Date(b.start_date);
            const eDate = new Date(b.end_date);
            const opts = { month: "short", day: "numeric", year: "numeric" };
            const sDateStr = sDate.toLocaleDateString("en-US", opts);
            const eDateStr = eDate.toLocaleDateString("en-US", opts);
            const dateStr = (b.start_date === b.end_date) ? sDateStr : `${sDate.toLocaleDateString("en-US", { month: "short", day: "numeric" })} - ${eDateStr}`;
  
            const customerName = `${b.first_name} ${b.last_name}`;
            const displayStatus = b.display_booking_status || b.booking_status;
            const isCompleted = displayStatus === 'Completed';
            const actualRoomType = (b.venue_category === 'Hotel Room') ? b.hotel_room_type : b.venue_category;
            const totalAmt = parseFloat(b.total_amount) || 0;
            const amtPaid = parseFloat(b.amount_paid) || 0;
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
  
            const fadeClass = (displayStatus === 'Cancelled') ? 'faded-text' : '';
  
            // Build Action Buttons
            let actionBtns = '';
            if (!isCompleted && displayStatus === 'Pending') {
                if (isPendingInquiry) {
                    actionBtns += `<button class="btn-action btn-cancel open-decline" data-id="${b.id}">Decline</button>
                                   <button class="btn-action open-edit-price" style="background-color: #64748b; color: white;" data-id="${b.id}">Edit Price / Finalize</button>`;
                } else {
                    actionBtns += `<button class="btn-action btn-confirm open-approve" data-id="${b.id}">Approve</button>
                                   <button class="btn-action btn-confirm open-payment" data-id="${b.id}" data-due="${balanceDue}">Collect Pay</button>
                                   <button class="btn-action btn-cancel open-decline" data-id="${b.id}">Decline</button>
                                   <button class="btn-action open-edit-price" style="background-color: #64748b; color: white;" data-id="${b.id}">Edit Price</button>`;
                }
            } 
            else if (!isCompleted && displayStatus === 'Confirmed') {
                if (b.cancel_status === 'Pending') {
                    actionBtns += `<button class="btn-action btn-refund open-refund" data-id="${b.id}" data-ref="${b.reference_no}" data-customer="${customerName}" data-venue="${b.venue_name}" data-date="${dateStr}" data-paid="${amtPaid}" data-fee-percent="${b.cancel_fee_percent || window.refundFeePercent || 3}" data-fee="${b.cancel_fee || ''}" data-refund="${b.cancel_refund || ''}" data-reason="${b.cancel_reason || ''}">Refund Req</button>
                                   `;
                } else if (b.resched_status === 'Pending') {
                    actionBtns += `<button class="btn-action btn-reschedule open-review-resched" data-id="${b.id}" data-customer="${customerName}" data-venue="${b.venue_name}" data-old="${dateStr}" data-newstart="${b.new_start_date}" data-newend="${b.new_end_date}" data-reason="${b.resched_reason || ''}" data-conflict="false">Review Resched</button>`;
                } else {
                    if (['Unpaid', 'Partial'].includes(b.payment_status) && balanceDue > 0) {
                        actionBtns += `<button class="btn-action btn-confirm open-payment" data-id="${b.id}" data-due="${balanceDue}">Collect Pay</button>`;
                    }
                    actionBtns += `<button class="btn-action btn-reschedule open-reschedule" data-id="${b.id}" data-customer="${customerName}" data-venue="${b.venue_name}" data-type="${actualRoomType}" data-date="${dateStr}">Reschedule</button>
                                   <button class="btn-action btn-cancel open-force-cancel" data-id="${b.id}" data-customer="${customerName}" data-paid="${amtPaid}">Force Cancel</button>`;
                }
            }
            actionBtns += `<button class="btn-action btn-view" data-id="${b.id}">View Details</button>`;
            if (!isCompleted && Number(b.has_checkout_session) === 1 && b.payment_status !== 'Paid' && displayStatus !== 'Cancelled') {
                actionBtns += `<button class="btn-action btn-confirm open-reconcile" data-id="${b.id}">Sync PayMongo</button>`;
            }

            html += `
            <tr class="${displayStatus === 'Cancelled' ? 'faded-row' : ''}" data-ref="${b.reference_no.toLowerCase()}">
                <td data-label="Booking ID" style="font-weight: 600; color: var(--color-gold);">${b.reference_no}</td>
                <td data-label="Venue">${b.venue_name}</td>
                <td data-label="Customer">${customerName}</td>
                <td data-label="Date">${dateStr}</td>
                <td data-label="Amount" class="${fadeClass}">${displayAmount}</td>
                <td data-label="Status"><div class="status-group"><span class="status-badge ${badgeClass}">${statusText}</span>${!isCompleted && Number(b.has_rescheduled) === 1 && displayStatus === 'Confirmed' ? ' <span class="status-note"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><span>Rescheduled</span></span>' : ''}</div></td>
                <td data-label="Actions" class="action-cells"><div class="action-buttons">${actionBtns}</div></td>
            </tr>`;
        });

        tbody.innerHTML = html;
    }
  
    function updatePaginationUI(pag) {
        pagCurrent.innerText = pag.current_page;
        pagTotalPages.innerText = pag.total_pages;
        
        let startItem = 0;
        let endItem = 0;
        if (pag.total_rows > 0) {
            startItem = (pag.current_page - 1) * rowsPerPage + 1;
            endItem = Math.min(pag.current_page * rowsPerPage, pag.total_rows);
        }
        
        pagTotalRows.innerText = `${startItem}-${endItem} of ${pag.total_rows}`;
        
        btnPrev.disabled = (pag.current_page <= 1);
        btnNext.disabled = (pag.current_page >= pag.total_pages);
    }
  
    // --- Triggers ---
    if (searchInput) {
        searchInput.addEventListener("input", () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => { currentPage = 1; updateExportLink(); loadBookings(); }, 400); // 400ms typing delay
        });
    }
    if (venueFilter) venueFilter.addEventListener("change", () => { currentPage = 1; updateExportLink(); loadBookings(); });
    if (btnPrev) btnPrev.addEventListener("click", () => { if (currentPage > 1) { currentPage--; loadBookings(); } });
    if (btnNext) btnNext.addEventListener("click", () => { currentPage++; loadBookings(); });
  
    tabFilters.forEach((tab) => {
        tab.addEventListener("click", () => {
            tabFilters.forEach(t => t.classList.remove("active"));
            tab.classList.add("active");
            if (bookingFilterSelect) bookingFilterSelect.value = tab.dataset.filter;
            currentPage = 1;
            updateExportLink();
            loadBookings();
        });
    });

    bookingFilterSelect?.addEventListener("change", () => {
        const selected = bookingFilterSelect.value;
        tabFilters.forEach(tab => tab.classList.toggle("active", tab.dataset.filter === selected));
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

    if (urlFilter) {
        const targetTab = document.querySelector(`.tab-btn[data-filter="${urlFilter}"]`);
        if (targetTab) {
            tabFilters.forEach(t => t.classList.remove("active"));
            targetTab.classList.add("active");
            if (bookingFilterSelect) bookingFilterSelect.value = urlFilter;
        }
    }

    if (urlSearch && searchInput) {
        searchInput.value = urlSearch;
    }
    updateExportLink();
  
    function highlightRow(refNo) {
        const row = document.querySelector(`tr[data-ref="${refNo.toLowerCase()}"]`);
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
    const closeModal = () => {
      modalOverlay.classList.remove("active");
      document.querySelectorAll(".admin-modal").forEach((m) => m.classList.remove("active"));
      const refundTx = document.getElementById('refund-transaction-id');
      const refundReason = document.getElementById('refund-rejection-reason');
      if (refundTx) { refundTx.value = ''; refundTx.required = false; }
      if (refundReason) { refundReason.value = ''; refundReason.required = false; }
    };
  
    document.querySelectorAll(".close-modal").forEach((btn) => btn.addEventListener("click", closeModal));
    modalOverlay.addEventListener("click", (e) => { if (e.target === modalOverlay) closeModal(); });
  
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
            const totalPaid = parseFloat(this.getAttribute('data-paid')) || 0;
            const feePercent = Number(this.getAttribute('data-fee-percent') || window.refundFeePercent || 3);
            const feeAttr = this.getAttribute('data-fee');
            const refundAttr = this.getAttribute('data-refund');
            const fee = feeAttr !== null && feeAttr !== '' ? Number(feeAttr) : Math.round(totalPaid * feePercent) / 100;
            const refundAmt = refundAttr !== null && refundAttr !== '' ? Number(refundAttr) : Math.max(0, Math.round((totalPaid - fee) * 100) / 100);
      
            const titleEl = document.querySelector('#refundModal .modal-main-title');
            if(titleEl) titleEl.innerText = `Process Refund - Booking #${referenceId}`;

            const transactionInput = document.getElementById('refund-transaction-id');
            const rejectionInput = document.getElementById('refund-rejection-reason');
            if (transactionInput) { transactionInput.value = ''; transactionInput.required = false; }
            if (rejectionInput) rejectionInput.value = '';
      
            const spans = document.querySelectorAll('#refundModal .summary-grid .value');
            if (spans.length >= 5) {
                spans[0].innerText = this.getAttribute('data-customer') || "Unknown";
                spans[1].innerText = this.getAttribute('data-venue') || "Unknown";
                spans[2].innerText = this.getAttribute('data-date') || "--";
                spans[3].innerText = `₱${totalPaid.toLocaleString()}`;
                spans[4].innerText = `₱${fee.toLocaleString(undefined, {minimumFractionDigits: 2})} (${feePercent}% )`;
            }
            
            const reasonEl = document.getElementById('modal-ref-reason');
            if (reasonEl) reasonEl.innerText = this.getAttribute('data-reason') || "No reason provided by customer.";
      
            const refundTotalEl = document.querySelector('#refundModal .refund-total .amount');
            if (refundTotalEl) refundTotalEl.innerText = `₱${refundAmt.toLocaleString()}`;
      
            const executeBtn = document.querySelector('#refundModal .btn-modal-refund');
            const newBtn = executeBtn.cloneNode(true);
            executeBtn.parentNode.replaceChild(newBtn, executeBtn);
            
            newBtn.setAttribute('data-id', bookingId);
            newBtn.addEventListener('click', function() {
              if (transactionInput) transactionInput.required = true;
              if (rejectionInput) rejectionInput.required = false;
              const refundTxId = document.getElementById('refund-transaction-id').value.trim();
              if (!refundTxId) {
                  showAlert("Missing Data", "Please enter the Refund Transaction / Reference ID.", "error");
                  return;
              }
              showConfirmModal("Are you sure you want to process this refund? This cannot be undone.", () => {
                  processBookingAction(this.getAttribute('data-id'), 'refund', this, { refund_transaction_id: refundTxId });
              }, 'refundModal');
            });

            const rejectBtn = document.getElementById('btn-reject-refund-inline');
            if (rejectBtn) {
              const newRejectBtn = rejectBtn.cloneNode(true);
              rejectBtn.parentNode.replaceChild(newRejectBtn, rejectBtn);
              newRejectBtn.setAttribute('data-id', bookingId);
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


        document.querySelectorAll('.open-reconcile').forEach(btn => {
            btn.addEventListener('click', function() {
                const button = this;
                button.disabled = true;
                button.textContent = 'Syncing...';
                fetch('actions/admin/reconcile_payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ booking_id: button.getAttribute('data-id') })
                }).then(r => r.json()).then(data => {
                    if (data.success) showAlert('Payment Synced', data.message, 'success');
                    else showAlert('Sync Failed', data.message || 'Unable to reconcile payment.', 'error');
                    loadBookings();
                }).catch(() => showAlert('Sync Failed', 'Network or server error.', 'error'))
                  .finally(() => { button.disabled = false; button.textContent = 'Sync PayMongo'; });
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
      
        // ADMIN FORCE CANCEL MODAL
        const forceCancelModal = document.getElementById("forceCancelModal");
      
        document.querySelectorAll('.open-force-cancel').forEach(btn => {
            btn.addEventListener('click', function() {
                const totalPaid = parseFloat(this.getAttribute('data-paid')) || 0;
                document.getElementById('fc-customer').innerText = this.getAttribute('data-customer');
                document.getElementById('fc-refund-amt').innerText = totalPaid.toLocaleString();
                document.getElementById('fc-reason').value = ""; 
      
                const executeBtn = document.getElementById('btn-execute-force-cancel');
                const newBtn = executeBtn.cloneNode(true);
                executeBtn.parentNode.replaceChild(newBtn, executeBtn);
                
                newBtn.setAttribute('data-id', this.getAttribute('data-id'));
                newBtn.setAttribute('data-paid', totalPaid);
                
                newBtn.addEventListener('click', function() {
                    const reason = document.getElementById('fc-reason').value.trim();
                    if (reason === "") return showAlert("Missing Data", "You must provide a reason (e.g. Typhoon) for the audit log.", "error", "forceCancelModal");
              
                    showConfirmModal("Are you absolutely sure? This will instantly cancel the booking and process a full refund.", () => {
                        processBookingAction(this.getAttribute('data-id'), 'admin_force_cancel', this, { reason: reason, refund_amount: this.getAttribute('data-paid') });
                    }, 'forceCancelModal');
                });
      
                modalOverlay.classList.add('active');
                forceCancelModal.classList.add('active');
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

                  const txLabel = document.getElementById('vd-transaction-label');
                  const txValue = document.getElementById('vd-transaction-value');
                  if (res.data.transaction_id) {
                      txLabel.style.display = 'block';
                      txValue.style.display = 'block';
                      txValue.innerText = res.data.transaction_id;
                  } else {
                      txLabel.style.display = 'none';
                      txValue.style.display = 'none';
                  }

                  const refTxLabel = document.getElementById('vd-refund-tx-label');
                  const refTxValue = document.getElementById('vd-refund-tx-value');
                  if (res.data.cancellation && res.data.cancellation.refund_transaction_id) {
                      refTxLabel.style.display = 'block';
                      refTxValue.style.display = 'block';
                      refTxValue.innerText = res.data.cancellation.refund_transaction_id;
                  } else {
                      refTxLabel.style.display = 'none';
                      refTxValue.style.display = 'none';
                  }
      
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
