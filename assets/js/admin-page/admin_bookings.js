/**
 * ==========================================================================
 * SEVILLA360 - Admin Bookings Controller
 * Handles table filtering, dynamic modal population, and AJAX actions.
 * ==========================================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  // =========================================================
  // CALENDAR ENGINE OVERRIDES (Prevents calendar.js crashes)
  // =========================================================
  
  // When the Admin selects dates on the Reschedule Calendar, do nothing special.
  // Just let the calendar highlight the dates in Gold.
  window.requestDateConfirmation = function(startDate, endDate, calendarInstance) {
      // We don't need to pop up a "Lock Dates" modal for the Admin backend.
      // The dates are stored safely in calendarInstance.startDate
  };

  // The calendar engine also looks for this function to update sidebar text.
  // We don't have a sidebar on the Admin Bookings page, so we leave it empty.
  window.calculateSummary = function() {
      // Do nothing
  };

  window.showOverrideModal = function(newDate, calendarInstance) {
      // If the admin clicks a third date, just clear the old selection and start over
      calendarInstance.clearSelection();
      calendarInstance.startDate = newDate;
      calendarInstance.render();
  };
  
  // =========================================================
  // 1. UNIVERSAL MODAL UTILITIES (Replaces alert and confirm)
  // =========================================================
  const modalOverlay = document.getElementById("modalOverlay");
  const uniConfirmModal = document.getElementById("uniConfirmModal");
  const uniAlertModal = document.getElementById("uniAlertModal");
  
  let pendingCallback = null;
  let fallbackModalId = null;

  // Custom Confirm()
  function showConfirmModal(message, callback, sourceModalId = null) {
      document.getElementById("uc-message").innerText = message;
      pendingCallback = callback;
      fallbackModalId = sourceModalId;

      // Hide all current modals
      document.querySelectorAll('.admin-modal').forEach(m => m.classList.remove('active'));
      
      modalOverlay.classList.add("active");
      uniConfirmModal.classList.add("active");
  }

  document.getElementById("uc-btn-no")?.addEventListener("click", () => {
      uniConfirmModal.classList.remove("active");
      if (fallbackModalId) {
          document.getElementById(fallbackModalId).classList.add("active");
      } else {
          modalOverlay.classList.remove("active");
      }
      pendingCallback = null; // Clear callback
  });

  document.getElementById("uc-btn-yes")?.addEventListener("click", () => {
      uniConfirmModal.classList.remove("active");
      if (fallbackModalId) {
          document.getElementById(fallbackModalId).classList.add("active");
      }
      if (pendingCallback) {
          pendingCallback(); 
          pendingCallback = null; // Clear callback after execution
      }
  });

  // Custom Alert()
  function showAlertModal(title, message, type = "info", reloadOnClose = false) {
      document.getElementById("ua-title").innerText = title;
      document.getElementById("ua-message").innerText = message;
      
      const icon = document.getElementById("ua-icon");
      if (type === "success") {
          icon.className = "fa-solid fa-circle-check modal-icon-warning";
          icon.style.color = "#4ade80"; // Green
      } else if (type === "error") {
          icon.className = "fa-solid fa-triangle-exclamation modal-icon-warning";
          icon.style.color = "#e06666"; // Red
      } else {
          icon.className = "fa-solid fa-circle-info modal-icon-warning";
          icon.style.color = "var(--color-gold)"; // Gold
      }

      document.querySelectorAll('.admin-modal').forEach(m => m.classList.remove('active'));
      modalOverlay.classList.add("active");
      uniAlertModal.classList.add("active");

      const okBtn = document.getElementById("ua-btn-ok");
      const newOkBtn = okBtn.cloneNode(true); 
      okBtn.parentNode.replaceChild(newOkBtn, okBtn);

      newOkBtn.addEventListener("click", () => {
          if (reloadOnClose) window.location.reload();
          else {
              uniAlertModal.classList.remove("active");
              modalOverlay.classList.remove("active");
          }
      });
  }

  // --- 2. TABLE FILTERING ---
  const searchInput = document.getElementById("table-search");
  const venueFilter = document.getElementById("table-venue-filter");
  const tabFilters = document.querySelectorAll("#bookingFilters .tab-btn");
  const tableRows = document.querySelectorAll("#admin-bookings-tbody tr");

  function executeTableFilters() {
      if (!searchInput || !venueFilter || !tableRows) return;

      const searchTerm = searchInput.value.toLowerCase();
      const selectedVenue = venueFilter.value;
      const activeTabBtn = document.querySelector("#bookingFilters .tab-btn.active");
      const activeStatus = activeTabBtn ? activeTabBtn.getAttribute("data-filter") : "all";

      tableRows.forEach(row => {
          // Skip the "No bookings found" row
          if (row.querySelector("td[colspan]")) return;

          const rowSearchText = row.getAttribute("data-search") || "";
          const rowVenue = row.getAttribute("data-venue") || "";
          const rowStatus = row.getAttribute("data-status") || "";

          // Check all 3 conditions
          const matchesSearch = rowSearchText.includes(searchTerm);
          const matchesVenue = (selectedVenue === "All") || (rowVenue === selectedVenue);
          const matchesStatus = (activeStatus === "all") || rowStatus.includes(activeStatus);

          // If it matches ALL filters, show it. Otherwise, hide it.
          if (matchesSearch && matchesVenue && matchesStatus) {
              row.style.display = "";
          } else {
              row.style.display = "none";
          }
      });
  }

  // Bind the events so the table updates instantly as you type/click!
  if (searchInput) searchInput.addEventListener("input", executeTableFilters);
  if (venueFilter) venueFilter.addEventListener("change", executeTableFilters);

  tabFilters.forEach((tab) => {
    tab.addEventListener("click", () => {
      tabFilters.forEach((t) => t.classList.remove("active"));
      tab.classList.add("active");
      executeTableFilters();
    });
  });

  // =========================================================================
  // FIX: AUTO-SELECT TABS, SCROLL, AND SOFT HIGHLIGHT (No Hard Filtering!)
  // =========================================================================
  const urlParams = new URLSearchParams(window.location.search);
  const urlFilter = urlParams.get('filter');
  const urlSearch = urlParams.get('search'); // This is the SV-XXXXX number

  // 1. Auto-Switch Tabs
  if (urlFilter) {
      const targetTab = document.querySelector(`.tab-btn[data-filter="${urlFilter}"]`);
      if (targetTab) targetTab.click(); 
  }

  // 2. Soft Highlight & Scroll (No Search Bar Isolation!)
  if (urlSearch) {
      // We do NOT put the text in the searchInput. We just look for the row.
      
      setTimeout(() => {
          let foundRow = null;
          
          tableRows.forEach(row => {
              // Skip empty state row
              if (row.querySelector("td[colspan]")) return;
              
              const rowSearchText = row.getAttribute("data-search") || "";
              
              // If this row contains the SV- number
              if (rowSearchText.includes(urlSearch.toLowerCase())) {
                  foundRow = row;
                  
                  // Highlight Animation (Flashes gold, then fades back)
                  row.style.transition = "background-color 1s ease";
                  row.style.backgroundColor = "rgba(214, 168, 112, 0.4)"; 
                  
                  setTimeout(() => {
                      row.style.backgroundColor = ""; 
                  }, 2000); // Hold the color a bit longer
              }
          });

          // Scroll the browser window directly to the highlighted row!
          if (foundRow) {
              foundRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          
      }, 300); // 300ms delay ensures the table tab has finished animating open
  }
  // =========================================================================
  // --- 3. Shared AJAX Function ---
  const processBookingAction = (bookingId, action, buttonElement, extraData = {}) => {
    const originalText = buttonElement.innerText;
    buttonElement.innerText = "Processing...";
    buttonElement.disabled = true;
    buttonElement.style.opacity = "0.7";

    const payload = { booking_id: bookingId, action: action, ...extraData };

    fetch('actions/admin/update_booking_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        buttonElement.innerText = "Success!";
        buttonElement.style.backgroundColor = "#4ade80"; 
        buttonElement.style.borderColor = "#4ade80";
        showAlertModal("Success!", data.message, "success", true);
      } else {
        showAlertModal("Error", data.message, "error", false);
        buttonElement.innerText = originalText;
        buttonElement.disabled = false;
        buttonElement.style.opacity = "1";
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showAlertModal("Network Error", "An error occurred while communicating with the server.", "error", false);
      buttonElement.innerText = originalText;
      buttonElement.disabled = false;
      buttonElement.style.opacity = "1";
    });
  };

  // --- 4. Modal System Close Logic ---
  const closeModal = () => {
    modalOverlay.classList.remove("active");
    document.querySelectorAll(".admin-modal").forEach((m) => m.classList.remove("active"));
  };

  document.querySelectorAll(".close-modal").forEach((btn) => {
    btn.addEventListener("click", closeModal);
  });

  modalOverlay.addEventListener("click", (e) => {
    if (e.target === modalOverlay) closeModal();
  });

  // --- 5. APPROVE & DECLINE MODALS ---
  const approveModal = document.getElementById("approveModal");
  const declineModal = document.getElementById("declineModal");

  document.querySelectorAll('.open-approve').forEach(btn => {
      btn.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          document.getElementById('approve-booking-id').innerText = bookingId;
          document.getElementById('btn-execute-approve').setAttribute('data-id', bookingId);
          modalOverlay.classList.add('active');
          approveModal.classList.add('active');
      });
  });

  document.getElementById('btn-execute-approve')?.addEventListener('click', function() {
      const bookingId = this.getAttribute('data-id');
      processBookingAction(bookingId, 'confirm', this);
  });

  document.querySelectorAll('.open-decline').forEach(btn => {
      btn.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          document.getElementById('decline-booking-id').innerText = bookingId;
          document.getElementById('btn-execute-decline').setAttribute('data-id', bookingId);
          modalOverlay.classList.add('active');
          declineModal.classList.add('active');
      });
  });

  document.getElementById('btn-execute-decline')?.addEventListener('click', function() {
      const bookingId = this.getAttribute('data-id');
      processBookingAction(bookingId, 'cancel', this);
  });

  // --- 6. REFUND MODAL LOGIC ---
  const refundModal = document.getElementById("refundModal");
  document.querySelectorAll('.open-refund').forEach(btn => {
    btn.addEventListener('click', function() {
      // 1. Grab data from the button
      const bookingId = this.getAttribute('data-id');
      const customerName = this.getAttribute('data-customer') || "Unknown";
      const venueName = this.getAttribute('data-venue') || "Unknown";
      const bookDate = this.getAttribute('data-date') || "--";
      const totalPaid = parseFloat(this.getAttribute('data-paid')) || 0;
      const reason = this.getAttribute('data-reason') || "No reason provided by customer."; // NEW!

      const fee = 461;
      let refundAmt = totalPaid - fee;
      if (refundAmt < 0) refundAmt = 0; 

      // 2. Inject into Modal
      const titleEl = document.querySelector('#refundModal .modal-main-title');
      if(titleEl) titleEl.innerText = `Process Refund - Booking #${bookingId}`;

      const spans = document.querySelectorAll('#refundModal .summary-grid .value');
      if (spans.length >= 5) {
          spans[0].innerText = customerName;
          spans[1].innerText = venueName;
          spans[2].innerText = bookDate;
          spans[3].innerText = `₱${totalPaid.toLocaleString()}`;
          spans[4].innerText = `₱${fee.toLocaleString()}`;
      }
      
      // NEW: Inject the actual reason!
      const reasonEl = document.getElementById('modal-ref-reason');
      if (reasonEl) reasonEl.innerText = reason;

      const refundTotalEl = document.querySelector('#refundModal .refund-total .amount');
      if (refundTotalEl) refundTotalEl.innerText = `₱${refundAmt.toLocaleString()}`;

      const executeBtn = document.querySelector('.btn-modal-refund');
      if (executeBtn) executeBtn.setAttribute('data-id', bookingId);

      modalOverlay.classList.add('active');
      refundModal.classList.add('active');
    });
  });

  document.querySelector('.btn-modal-refund')?.addEventListener('click', function() {
    const bookingId = this.getAttribute('data-id');
    showConfirmModal("Are you sure you want to process this refund? This cannot be undone.", () => {
        processBookingAction(bookingId, 'refund', this);
    }, 'refundModal');
  });

  // --- 7. RESCHEDULE MODAL LOGIC ---
  const rescheduleModal = document.getElementById("rescheduleModal");
  let rescheduleCalendar = null;
  
  // 1. Initialize the calendar if the HTML exists
  if (typeof SevillaCalendar !== 'undefined' && document.getElementById("cal-ui-reschedule")) {
      rescheduleCalendar = new SevillaCalendar("cal-ui-reschedule");
  }

  // 2. Open the Modal & Fetch Booked Dates
  document.querySelectorAll('.open-reschedule').forEach(btn => {
    btn.addEventListener('click', function() {
      const bookingId = this.getAttribute('data-id');
      const customerName = this.getAttribute('data-customer') || "Unknown";
      const venueType = this.getAttribute('data-type') || "Hotel Room"; 
      const venueName = this.getAttribute('data-venue') || "Standard Room"; 
      const originalDate = this.getAttribute('data-date') || "--";

      // Inject text data into modal
      const spans = document.querySelectorAll('#rescheduleModal .summary-grid .value');
      if (spans.length >= 3) {
          spans[0].innerText = customerName;
          spans[1].innerText = venueName;
          spans[2].innerText = originalDate;
      }

      // CRITICAL: Fetch the booked dates from the database for this specific room!
      if (rescheduleCalendar) {
          rescheduleCalendar.clearSelection();
          rescheduleCalendar.fetchBookedDates(venueType, venueName);
          
          // Re-render calendar so it resizes correctly inside the newly opened modal
          setTimeout(() => rescheduleCalendar.render(), 100); 
      }

      const executeBtn = document.querySelector('#rescheduleModal .btn-modal-refund'); 
      if (executeBtn) executeBtn.setAttribute('data-id', bookingId);

      modalOverlay.classList.add('active');
      rescheduleModal.classList.add('active');
    });
  });

  // 3. Execute the Reschedule Action
  document.querySelector('#rescheduleModal .btn-modal-refund')?.addEventListener('click', function() {
    const bookingId = this.getAttribute('data-id');
    
    // Guard: Ensure they actually clicked a new date on the calendar!
    if (!rescheduleCalendar || !rescheduleCalendar.startDate) {
        showAlertModal("Missing Data", "Please select the new dates from the calendar first!", "error", 'rescheduleModal');
        return;
    }

    // Format Dates safely to prevent timezone shifting
    const formatLocal = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    const newStart = formatLocal(rescheduleCalendar.startDate);
    const newEnd = rescheduleCalendar.endDate ? formatLocal(rescheduleCalendar.endDate) : newStart;

    // Use our beautiful Universal Confirm Modal
    showConfirmModal(`Confirm rescheduling to ${newStart}?`, () => {
        processBookingAction(bookingId, 'reschedule', this, { 
            new_start_date: newStart, 
            new_end_date: newEnd 
        });
    }, 'rescheduleModal');
  });

  // --- 8. COLLECT PAYMENT MODAL LOGIC ---
  const paymentModal = document.getElementById("paymentModal");
  const pmtMethodSelect = document.getElementById("pmt-method");
  const pmtTransWrapper = document.getElementById("pmt-trans-wrapper");
  const pmtAmountInput = document.getElementById("pmt-amount-input");
  const btnExecutePayment = document.getElementById("btn-execute-payment");

  if (pmtMethodSelect) {
      pmtMethodSelect.addEventListener("change", function() {
          if (this.value === "Cash") pmtTransWrapper.style.display = "none";
          else pmtTransWrapper.style.display = "block";
      });
  }

  document.querySelectorAll('.open-payment').forEach(btn => {
      btn.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          const balanceDue = parseFloat(this.getAttribute('data-due')) || 0;

          document.getElementById('pmt-balance').innerText = `₱${balanceDue.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
          
          if (pmtAmountInput) {
              pmtAmountInput.value = ""; 
              pmtAmountInput.placeholder = `Enter amount (Max: ₱${balanceDue.toLocaleString()})`;
          }
          
          if (pmtMethodSelect) pmtMethodSelect.value = "Cash";
          if (pmtTransWrapper) pmtTransWrapper.style.display = "none";
          if (document.getElementById('pmt-trans-id')) document.getElementById('pmt-trans-id').value = "";
          
          if (btnExecutePayment) btnExecutePayment.setAttribute('data-id', bookingId);

          modalOverlay.classList.add('active');
          paymentModal.classList.add('active');
      });
  });

  if (btnExecutePayment) {
      btnExecutePayment.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          const amount = parseFloat(pmtAmountInput.value); 
          const method = pmtMethodSelect.value;
          const transId = document.getElementById('pmt-trans-id').value.trim();

          if (isNaN(amount) || amount <= 0) {
              showAlertModal("Invalid Amount", "Please enter a valid payment amount.", "error", 'paymentModal');
              return;
          }
          if (method !== 'Cash' && transId === '') {
              showAlertModal("Missing Data", "Please enter a Transaction ID for online/bank payments.", "error", 'paymentModal');
              return;
          }

          showConfirmModal(`Confirm receipt of ₱${amount.toLocaleString()} via ${method}?`, () => {
              processBookingAction(bookingId, 'add_payment', this, { amount: amount, method: method, transaction_id: transId });
          }, 'paymentModal');
      });
  }

  // --- 8.5 REVIEW RESCHEDULE REQUEST MODAL ---
  const reviewReschedModal = document.getElementById("reviewReschedModal");

  document.querySelectorAll('.open-review-resched').forEach(btn => {
      btn.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          const hasConflict = this.getAttribute('data-conflict') === 'true';

          // Inject into DOM
          document.getElementById('rr-customer').innerText = this.getAttribute('data-customer');
          document.getElementById('rr-venue').innerText = this.getAttribute('data-venue');
          document.getElementById('rr-old-dates').innerText = this.getAttribute('data-old');
          document.getElementById('rr-reason').innerText = this.getAttribute('data-reason') || "No reason provided.";
          
          const opts = { month: "short", day: "numeric", year: "numeric" };
          const d1 = new Date(this.getAttribute('data-newstart')).toLocaleDateString("en-US", opts);
          const d2 = new Date(this.getAttribute('data-newend')).toLocaleDateString("en-US", opts);
          document.getElementById('rr-new-dates').innerText = (d1 === d2) ? d1 : `${d1} — ${d2}`;

          // Handle Conflict Warning & Disable Approve Button
          const warningBox = document.getElementById('rr-conflict-warning');
          const approveBtn = document.getElementById('btn-approve-resched');
          
          if (hasConflict) {
              warningBox.style.display = 'block';
              approveBtn.disabled = true;
              approveBtn.style.opacity = '0.5';
              approveBtn.style.cursor = 'not-allowed';
          } else {
              warningBox.style.display = 'none';
              approveBtn.disabled = false;
              approveBtn.style.opacity = '1';
              approveBtn.style.cursor = 'pointer';
          }

          // Reset Reject Box
          document.getElementById('rr-reject-box').style.display = 'none';
          document.getElementById('rr-reject-reason').value = "";
          document.getElementById('btn-reject-resched').innerText = "Reject Request";

          // Attach data
          approveBtn.setAttribute('data-id', bookingId);
          approveBtn.setAttribute('data-newstart', this.getAttribute('data-newstart'));
          approveBtn.setAttribute('data-newend', this.getAttribute('data-newend'));
          document.getElementById('btn-reject-resched').setAttribute('data-id', bookingId);

          modalOverlay.classList.add('active');
          reviewReschedModal.classList.add('active');
      });
  });

  // Execute Approve Request
  document.getElementById('btn-approve-resched')?.addEventListener('click', function() {
      if (this.disabled) return;
      const bookingId = this.getAttribute('data-id');
      showConfirmModal("Approve this request? The dates will be permanently moved.", () => {
          processBookingAction(bookingId, 'reschedule', this, {
              new_start_date: this.getAttribute('data-newstart'),
              new_end_date: this.getAttribute('data-newend')
          });
      }, 'reviewReschedModal');
  });

  // Execute Reject Request (Two-Step Process)
  document.getElementById('btn-reject-resched')?.addEventListener('click', function() {
      const rejectBox = document.getElementById('rr-reject-box');
      
      // Step 1: Reveal the text box
      if (rejectBox.style.display === 'none') {
          rejectBox.style.display = 'block';
          this.innerText = "Confirm Rejection";
      } 
      // Step 2: Actually submit
      else {
          const reason = document.getElementById('rr-reject-reason').value.trim();
          if (reason === "") {
              showAlertModal("Error", "Please provide a reason for rejecting this request.", "error", "reviewReschedModal");
              return;
          }

          const bookingId = this.getAttribute('data-id');
          showConfirmModal("Reject this request? The booking will remain on its original dates.", () => {
              processBookingAction(bookingId, 'reject_reschedule', this, { admin_reply: reason });
          }, 'reviewReschedModal');
      }
  });

  // --- 8.6 ADMIN FORCE CANCEL MODAL ---
  const forceCancelModal = document.getElementById("forceCancelModal");

  document.querySelectorAll('.open-force-cancel').forEach(btn => {
      btn.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          const customerName = this.getAttribute('data-customer');
          const totalPaid = parseFloat(this.getAttribute('data-paid')) || 0;

          document.getElementById('fc-customer').innerText = customerName;
          document.getElementById('fc-refund-amt').innerText = totalPaid.toLocaleString();
          document.getElementById('fc-reason').value = ""; // Clear old text

          const executeBtn = document.getElementById('btn-execute-force-cancel');
          executeBtn.setAttribute('data-id', bookingId);
          executeBtn.setAttribute('data-paid', totalPaid);

          modalOverlay.classList.add('active');
          forceCancelModal.classList.add('active');
      });
  });

  // Execute Force Cancel
  document.getElementById('btn-execute-force-cancel')?.addEventListener('click', function() {
      const bookingId = this.getAttribute('data-id');
      const refundAmt = this.getAttribute('data-paid');
      const reason = document.getElementById('fc-reason').value.trim();

      if (reason === "") {
          showAlertModal("Missing Data", "You must provide a reason (e.g. Typhoon) for the audit log.", "error", "forceCancelModal");
          return;
      }

      showConfirmModal("Are you absolutely sure? This will instantly cancel the booking and process a full refund.", () => {
          processBookingAction(bookingId, 'admin_force_cancel', this, { 
              reason: reason,
              refund_amount: refundAmt
          });
      }, 'forceCancelModal');
  });

  // --- 9. VIEW DETAILS MODAL LOGIC ---
  const viewDetailsModal = document.getElementById("viewDetailsModal");

  document.querySelectorAll('.btn-view').forEach(btn => {
    btn.addEventListener('click', function() {
        const bookingId = this.getAttribute('data-id');
        const originalText = this.innerText;
        
        this.innerText = "Loading...";
        this.disabled = true;

        fetch(`actions/admin/get_booking_details.php?id=${bookingId}`)
        .then(response => response.json())
        .then(res => {
            this.innerText = originalText;
            this.disabled = false;

            if (!res.success) {
                showAlertModal("Error", "Error loading details: " + res.message, "error", false);
                return;
            }

            const data = res.data.booking;
            const specifics = res.data.specifics;
            const addons = res.data.addons;
            const lineItems = res.data.line_items; // Get the custom line items!

            // FIX: Use reference_no instead of database ID!
            document.getElementById('vd-title').innerText = `Booking ${data.reference_no}`;
            
            const badge = document.getElementById('vd-status-badge');
            badge.innerText = data.booking_status;
            badge.className = 'status-badge ' + (data.booking_status === 'Confirmed' ? 'status-paid' : (data.booking_status === 'Cancelled' ? 'status-refunded' : 'status-pending'));

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
                    specValue.innerHTML = `
                        <strong>${specifics.event_type}</strong> (${specifics.event_style})<br>
                        <span style="color:#666; font-size:0.85rem; display:block; margin-top:5px; background:rgba(0,0,0,0.03); padding:8px; border-radius:4px;">
                            <strong>Notes:</strong> ${specifics.custom_notes || 'No special requests.'}
                        </span>
                    `;
                } else if (data.venue_category === 'Resort Villa') {
                    specLabel.innerText = "Stay Type:";
                    specValue.innerText = specifics.stay_type;
                }
            } else {
                specLabel.style.display = 'none';
                specValue.style.display = 'none';
            }

            // FIX: Render Addons AND Custom Line Items
            const addonsContainer = document.getElementById('vd-addons-container');
            const addonsList = document.getElementById('vd-addons-list');
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
                document.getElementById('vd-scheme').innerText = data.payment_scheme;
            }
            
            document.getElementById('vd-paid-amt').innerText = formatCash(data.amount_paid);

            modalOverlay.classList.add('active');
            viewDetailsModal.classList.add('active');
        })
        .catch(err => {
            console.error(err);
            this.innerText = originalText;
            this.disabled = false;
            showAlertModal("Network Error", "Network error fetching details.", "error", false);
        });
    });
  });

  // --- NEW: EVENT INVOICE / EDIT PRICE MODAL LOGIC ---
  const editPriceModal = document.getElementById("editPriceModal");
  const lineItemsContainer = document.getElementById("ep-line-items");
  const baseRateInput = document.getElementById("ep-base-rate");
  const calcTotalDisplay = document.getElementById("ep-calc-total");

  // Live Math Calculator
  function calculateInvoiceTotal() {
      let total = parseFloat(baseRateInput.value) || 0;
      document.querySelectorAll(".ep-item-cost").forEach(input => {
          total += (parseFloat(input.value) || 0);
      });
      calcTotalDisplay.innerText = `₱${total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
  }

  // Row Builder
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
  
  // Row Builder
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

  // Add Item Button
  document.getElementById("ep-btn-add-item")?.addEventListener("click", () => addLineItemRow());
  baseRateInput?.addEventListener("input", calculateInvoiceTotal);

  // Open Modal (Fetch Data First!)
  document.querySelectorAll('.open-edit-price').forEach(btn => {
      btn.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          const originalText = this.innerText;
          this.innerText = "Loading...";
          
          fetch(`actions/admin/get_booking_details.php?id=${bookingId}`)
          .then(res => res.json())
          .then(res => {
              this.innerText = originalText;
              if (!res.success) return showAlertModal("Error", res.message, "error", false);

              const data = res.data.booking;
              const specifics = res.data.specifics;
              const addons = res.data.addons; // Old initial addons
              const lineItems = res.data.line_items; // Saved custom items (if already edited)

              document.getElementById('ep-booking-id').innerText = `${data.reference_no}`;
              document.getElementById('ep-guests').value = data.guests_count;
              document.getElementById('ep-event-type').value = specifics ? specifics.event_type : "";
              baseRateInput.value = parseFloat(data.base_amount).toFixed(2);
              
              lineItemsContainer.innerHTML = ""; // Clear old rows

              // Populate Rows: If line items exist, use them. Else, convert initial addons!
              if (lineItems && lineItems.length > 0) {
                  lineItems.forEach(item => addLineItemRow(item.item_name, item.amount));
              } else if (addons && addons.length > 0) {
                  addons.forEach(addon => {
                      // Automatically merges qty * price into the line item amount
                      addLineItemRow(`${addon.name} (x${addon.quantity})`, parseFloat(addon.total_price).toFixed(2));
                  });
              }

              calculateInvoiceTotal();

              document.getElementById('btn-execute-edit-price').setAttribute('data-id', bookingId);
              modalOverlay.classList.add('active');
              editPriceModal.classList.add('active');
          });
      });
  });

  // Submit and Save
  document.getElementById('btn-execute-edit-price')?.addEventListener('click', function() {
      const bookingId = this.getAttribute('data-id');
      const guests = document.getElementById('ep-guests').value;
      const eventType = document.getElementById('ep-event-type').value;
      const baseRate = document.getElementById('ep-base-rate').value;

      // Gather Line Items Array
      let lineItemsArr = [];
      document.querySelectorAll(".ep-row").forEach(row => {
          const name = row.querySelector(".ep-item-name").value.trim();
          const cost = parseFloat(row.querySelector(".ep-item-cost").value) || 0;
          if (name !== "" && cost >= 0) {
              lineItemsArr.push({ name: name, amount: cost });
          }
      });

      showConfirmModal(`Finalize invoice and email customer?`, () => {
          processBookingAction(bookingId, 'finalize_event_invoice', this, { 
              guests: guests, 
              event_type: eventType, 
              base_rate: baseRate,
              line_items: lineItemsArr
          });
      }, 'editPriceModal');
  });
});
