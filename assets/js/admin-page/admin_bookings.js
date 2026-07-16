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

  // --- 2. Filter Pills Logic ---
  const filters = document.querySelectorAll(".tab-btn");
  filters.forEach((filter) => {
    filter.addEventListener("click", () => {
      filters.forEach((f) => f.classList.remove("active"));
      filter.classList.add("active");
      // Add table filtering logic here if needed
    });
  });

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
      const bookingId = this.getAttribute('data-id');
      const customerName = this.getAttribute('data-customer') || "Unknown";
      const venueName = this.getAttribute('data-venue') || "Unknown";
      const bookDate = this.getAttribute('data-date') || "--";
      const totalPaid = parseFloat(this.getAttribute('data-paid')) || 0;

      const fee = 461;
      let refundAmt = totalPaid - fee;
      if (refundAmt < 0) refundAmt = 0; 

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

            document.getElementById('vd-title').innerText = `Booking #${data.id}`;
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
                    specLabel.innerText = "Event Type:";
                    specValue.innerText = `${specifics.event_type} (${specifics.event_style})`;
                } else if (data.venue_category === 'Resort Villa') {
                    specLabel.innerText = "Stay Type:";
                    specValue.innerText = specifics.stay_type;
                }
            } else {
                specLabel.style.display = 'none';
                specValue.style.display = 'none';
            }

            const addonsContainer = document.getElementById('vd-addons-container');
            const addonsList = document.getElementById('vd-addons-list');
            addonsList.innerHTML = ''; 
            if (addons && addons.length > 0) {
                addonsContainer.style.display = 'block';
                addons.forEach(addon => {
                    addonsList.innerHTML += `<span class="label">&#8226; ${addon.name} (x${addon.quantity})</span> <span class="value">₱${parseFloat(addon.total_price).toLocaleString()}</span>`;
                });
            } else {
                addonsContainer.style.display = 'none';
            }

            const formatCash = (amt) => `₱${parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
            document.getElementById('vd-base-amt').innerText = formatCash(data.base_amount);
            document.getElementById('vd-addons-amt').innerText = formatCash(data.addons_amount);
            document.getElementById('vd-extrapax-amt').innerText = formatCash(data.extra_pax_amount);
            document.getElementById('vd-total-amt').innerText = formatCash(data.total_amount);
            
            document.getElementById('vd-scheme').innerText = data.payment_scheme;
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

});