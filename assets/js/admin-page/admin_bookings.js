/**
 * ==========================================================================
 * SEVILLA360 - Admin Bookings Controller
 * Handles table filtering, dynamic modal population, and AJAX actions.
 * ==========================================================================
 */

document.addEventListener("DOMContentLoaded", () => {
  
  // --- 1. Filter Pills Logic ---
  const filters = document.querySelectorAll(".tab-btn");
  filters.forEach((filter) => {
    filter.addEventListener("click", () => {
      filters.forEach((f) => f.classList.remove("active"));
      filter.classList.add("active");
      
      const filterType = filter.getAttribute("data-filter");
      // Add table filtering logic here if needed, or trigger a page reload with ?filter=type
    });
  });

  // --- 2. Shared AJAX Function ---
  // Added 'extraData' parameter to handle things like new dates or refund reasons
  const processBookingAction = (bookingId, action, buttonElement, extraData = {}) => {
    const originalText = buttonElement.innerText;
    buttonElement.innerText = "Processing...";
    buttonElement.disabled = true;
    buttonElement.style.opacity = "0.7";

    const payload = {
      booking_id: bookingId,
      action: action,
      ...extraData // Merges any extra data (like new_start_date) into the payload
    };

    fetch('actions/admin/update_booking_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert(data.message);
        window.location.reload(); 
      } else {
        alert("Error: " + data.message);
        buttonElement.innerText = originalText;
        buttonElement.disabled = false;
        buttonElement.style.opacity = "1";
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert("An error occurred while communicating with the server.");
      buttonElement.innerText = originalText;
      buttonElement.disabled = false;
      buttonElement.style.opacity = "1";
    });
  };

  // --- 3. Modal System Setup ---
  const modalOverlay = document.getElementById("modalOverlay");
  const refundModal = document.getElementById("refundModal");
  const rescheduleModal = document.getElementById("rescheduleModal");

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

  // --- 4. REFUND MODAL LOGIC ---
  document.querySelectorAll('.open-refund').forEach(btn => {
    btn.addEventListener('click', function() {
      // 1. Grab data from the button (Ensure you add these data-* attributes to your PHP table!)
      const bookingId = this.getAttribute('data-id');
      const customerName = this.getAttribute('data-customer') || "Unknown";
      const venueName = this.getAttribute('data-venue') || "Unknown";
      const bookDate = this.getAttribute('data-date') || "--";
      const totalPaid = parseFloat(this.getAttribute('data-paid')) || 0;

      // 2. Calculate Refund (e.g., deducting ₱461 PayMongo fee)
      const fee = 461;
      let refundAmt = totalPaid - fee;
      if (refundAmt < 0) refundAmt = 0; // Prevent negative refunds

      // 3. Inject into Modal DOM
      // Note: Make sure to add these ID's to the <span> tags in your admin_bookings.php HTML
      const titleEl = document.querySelector('#refundModal .modal-main-title');
      if(titleEl) titleEl.innerText = `Process Refund - Booking #${bookingId}`;

      // Update text using querySelector matching your structure
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

      // 4. Attach booking ID to the final execute button
      const executeBtn = document.querySelector('.btn-modal-refund');
      executeBtn.setAttribute('data-id', bookingId);

      // 5. Open Modal
      modalOverlay.classList.add('active');
      refundModal.classList.add('active');
    });
  });

  // Execute Refund
  document.querySelector('.btn-modal-refund')?.addEventListener('click', function() {
    const bookingId = this.getAttribute('data-id');
    if (confirm("Are you sure you want to process this refund? This cannot be undone.")) {
      processBookingAction(bookingId, 'refund', this);
    }
  });


  // --- 5. RESCHEDULE MODAL LOGIC ---
  let rescheduleCalendar = null;
  if (typeof SevillaCalendar !== 'undefined' && document.getElementById("cal-ui-reschedule")) {
      rescheduleCalendar = new SevillaCalendar("cal-ui-reschedule");
  }

  document.querySelectorAll('.open-reschedule').forEach(btn => {
    btn.addEventListener('click', function() {
      const bookingId = this.getAttribute('data-id');
      const customerName = this.getAttribute('data-customer') || "Unknown";
      const venueType = this.getAttribute('data-type') || "Hotel Room"; 
      const venueName = this.getAttribute('data-venue') || "Standard Room"; 
      const originalDate = this.getAttribute('data-date') || "--";

      // 1. Inject data into modal
      const spans = document.querySelectorAll('#rescheduleModal .summary-grid .value');
      if (spans.length >= 3) {
          spans[0].innerText = customerName;
          spans[1].innerText = venueName;
          spans[2].innerText = originalDate;
      }

      // 2. Clear old calendar selection and fetch booked dates for this specific room
      if (rescheduleCalendar) {
          rescheduleCalendar.clearSelection();
          rescheduleCalendar.fetchBookedDates(venueType, venueName);
      }

      // 3. Attach ID to the final execute button
      const executeBtn = document.querySelector('#rescheduleModal .btn-modal-refund'); // Note: You might want to change this class name to btn-modal-primary in HTML
      if (executeBtn) executeBtn.setAttribute('data-id', bookingId);

      // 4. Open Modal
      modalOverlay.classList.add('active');
      rescheduleModal.classList.add('active');
    });
  });

  // Execute Reschedule
  document.querySelector('#rescheduleModal .btn-modal-refund')?.addEventListener('click', function() {
    const bookingId = this.getAttribute('data-id');
    
    // Validate Calendar
    if (!rescheduleCalendar || !rescheduleCalendar.startDate) {
        alert("Please select the new dates from the calendar first!");
        return;
    }

    // Format Dates securely
    const formatLocal = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    const newStart = formatLocal(rescheduleCalendar.startDate);
    const newEnd = rescheduleCalendar.endDate ? formatLocal(rescheduleCalendar.endDate) : newStart;

    if (confirm("Confirm rescheduling to these new dates?")) {
        // Send AJAX request, passing the new dates as extra data
        processBookingAction(bookingId, 'reschedule', this, {
            new_start_date: newStart,
            new_end_date: newEnd
        });
    }
  });

  // --- 6. Quick Confirm Button (No Modal needed) ---
  document.querySelectorAll('.btn-confirm').forEach(btn => {
      btn.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          if (confirm('Approve and confirm Booking #' + bookingId + '?')) {
              processBookingAction(bookingId, 'confirm', this);
          }
      });
  });

  // --- 6. VIEW DETAILS MODAL LOGIC ---
  const viewDetailsModal = document.getElementById("viewDetailsModal");

  document.querySelectorAll('.btn-view').forEach(btn => {
    btn.addEventListener('click', function() {
        const bookingId = this.getAttribute('data-id');
        const originalText = this.innerText;
        
        // Show loading state
        this.innerText = "Loading...";
        this.disabled = true;

        // Fetch all details from API
        fetch(`actions/admin/get_booking_details.php?id=${bookingId}`)
        .then(response => response.json())
        .then(res => {
            this.innerText = originalText;
            this.disabled = false;

            if (!res.success) {
                alert("Error loading details: " + res.message);
                return;
            }

            const data = res.data.booking;
            const specifics = res.data.specifics;
            const addons = res.data.addons;

            // 1. Header
            document.getElementById('vd-title').innerText = `Booking #${data.id}`;
            const badge = document.getElementById('vd-status-badge');
            badge.innerText = data.booking_status;
            badge.className = 'status-badge ' + (data.booking_status === 'Confirmed' ? 'status-paid' : (data.booking_status === 'Cancelled' ? 'status-refunded' : 'status-pending'));

            // 2. Customer
            document.getElementById('vd-customer-name').innerText = `${data.first_name} ${data.last_name}`;
            document.getElementById('vd-customer-email').innerText = data.email;
            document.getElementById('vd-customer-phone').innerText = data.phone || "N/A";

            // 3. Booking
            document.getElementById('vd-venue').innerText = `${data.venue_name} (${data.venue_category})`;
            document.getElementById('vd-guests').innerText = data.guests_count;
            
            // Format Dates
            const opts = { month: "short", day: "numeric", year: "numeric" };
            const sDate = new Date(data.start_date).toLocaleDateString("en-US", opts);
            const eDate = new Date(data.end_date).toLocaleDateString("en-US", opts);
            document.getElementById('vd-dates').innerText = (sDate === eDate) ? sDate : `${sDate} — ${eDate}`;

            // Specifics (Event / Villa details)
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

            // 4. Add-ons
            const addonsContainer = document.getElementById('vd-addons-container');
            const addonsList = document.getElementById('vd-addons-list');
            addonsList.innerHTML = ''; // Clear old ones
            if (addons && addons.length > 0) {
                addonsContainer.style.display = 'block';
                addons.forEach(addon => {
                    addonsList.innerHTML += `<span class="label">&#8226; ${addon.name} (x${addon.quantity})</span> <span class="value">₱${parseFloat(addon.total_price).toLocaleString()}</span>`;
                });
            } else {
                addonsContainer.style.display = 'none';
            }

            // 5. Financials
            const formatCash = (amt) => `₱${parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
            document.getElementById('vd-base-amt').innerText = formatCash(data.base_amount);
            document.getElementById('vd-addons-amt').innerText = formatCash(data.addons_amount);
            document.getElementById('vd-extrapax-amt').innerText = formatCash(data.extra_pax_amount);
            document.getElementById('vd-total-amt').innerText = formatCash(data.total_amount);
            
            document.getElementById('vd-scheme').innerText = data.payment_scheme;
            document.getElementById('vd-paid-amt').innerText = formatCash(data.amount_paid);

            // Open Modal
            modalOverlay.classList.add('active');
            viewDetailsModal.classList.add('active');
        })
        .catch(err => {
            console.error(err);
            this.innerText = originalText;
            this.disabled = false;
            alert("Network error fetching details.");
        });
    });
  });

  // --- 7. COLLECT PAYMENT MODAL LOGIC ---
  const paymentModal = document.getElementById("paymentModal");
  const pmtMethodSelect = document.getElementById("pmt-method");
  const pmtTransWrapper = document.getElementById("pmt-trans-wrapper");
  const btnExecutePayment = document.getElementById("btn-execute-payment");

  // Show/Hide Transaction ID based on payment method
  if (pmtMethodSelect) {
      pmtMethodSelect.addEventListener("change", function() {
          if (this.value === "Cash") {
              pmtTransWrapper.classList.add("hidden");
          } else {
              pmtTransWrapper.classList.remove("hidden");
          }
      });
  }

  // Open the Modal
  document.querySelectorAll('.open-payment').forEach(btn => {
      btn.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          const balanceDue = parseFloat(this.getAttribute('data-due')) || 0;

          document.getElementById('pmt-balance').innerText = `₱${balanceDue.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
          
          if (btnExecutePayment) {
              btnExecutePayment.setAttribute('data-id', bookingId);
              btnExecutePayment.setAttribute('data-amt', balanceDue);
          }

          modalOverlay.classList.add('active');
          paymentModal.classList.add('active');
      });
  });

  // Execute Payment
  if (btnExecutePayment) {
      btnExecutePayment.addEventListener('click', function() {
          const bookingId = this.getAttribute('data-id');
          const amount = this.getAttribute('data-amt');
          const method = pmtMethodSelect.value;
          const transId = document.getElementById('pmt-trans-id').value.trim();

          if (method !== 'Cash' && transId === '') {
              alert("Please enter a Transaction ID for online/bank payments.");
              return;
          }

          if (confirm(`Confirm receipt of ₱${parseFloat(amount).toLocaleString()} via ${method}?`)) {
              // We reuse the processBookingAction, adding extra data!
              processBookingAction(bookingId, 'add_payment', this, {
                  amount: amount,
                  method: method,
                  transaction_id: transId
              });
          }
      });
  }
});