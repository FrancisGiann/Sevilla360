/**
 * SEVILLA360 - User Dashboard Logic
 * Handles Tabs, Filtering, Modals, and Settings Updates.
 */

document.addEventListener("DOMContentLoaded", () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  // =========================================================
  // UNIVERSAL MODAL UTILITIES (Replaces alert and confirm)
  // =========================================================
  const uniConfirmModal = document.getElementById("uniConfirmModal");
  const uniAlertModal = document.getElementById("uniAlertModal");
  let pendingCallback = null;

  function showConfirmModal(message, callback) {
      document.getElementById("uc-message").innerText = message;
      pendingCallback = callback;
      document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
      uniConfirmModal.classList.add("active");
  }

  document.getElementById("uc-btn-no")?.addEventListener("click", () => {
      uniConfirmModal.classList.remove("active");
      pendingCallback = null; 
  });

  document.getElementById("uc-btn-yes")?.addEventListener("click", () => {
      uniConfirmModal.classList.remove("active");
      if (pendingCallback) { pendingCallback(); pendingCallback = null; }
  });

  function showAlertModal(title, message, type = "info", reloadOnClose = false) {
      document.getElementById("ua-title").innerText = title;
      document.getElementById("ua-message").innerText = message;
      
      const icon = document.getElementById("ua-icon");
      if (type === "success") {
          icon.className = "fa-solid fa-circle-check"; icon.style.color = "#4ade80"; 
      } else if (type === "error") {
          icon.className = "fa-solid fa-triangle-exclamation"; icon.style.color = "#e06666"; 
      } else {
          icon.className = "fa-solid fa-circle-info"; icon.style.color = "var(--color-gold)"; 
      }

      document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
      uniAlertModal.classList.add("active");

      const okBtn = document.getElementById("ua-btn-ok");
      const newOkBtn = okBtn.cloneNode(true); 
      okBtn.parentNode.replaceChild(newOkBtn, okBtn);

      newOkBtn.addEventListener("click", () => {
          if (reloadOnClose) window.location.reload();
          else uniAlertModal.classList.remove("active");
      });
  }

  // --- 1. Tab Switching Logic ---
  const navItems = document.querySelectorAll(".nav-item");
  const tabPanes = document.querySelectorAll(".tab-pane");

  navItems.forEach((item) => {
    item.addEventListener("click", (e) => {
      e.preventDefault();
      navItems.forEach((nav) => nav.classList.remove("active"));
      tabPanes.forEach((pane) => pane.classList.remove("active"));
      item.classList.add("active");
      const targetTab = item.getAttribute("data-tab");
      document.getElementById(`tab-${targetTab}`).classList.add("active");
    });
  });

  // --- 2. Table Filtering ---
  const filterPills = document.querySelectorAll(".filter-pill");
  const tableRows = document.querySelectorAll("#bookingsTable tbody tr");

  filterPills.forEach((pill) => {
    pill.addEventListener("click", (e) => {
      filterPills.forEach((p) => p.classList.remove("active"));
      e.target.classList.add("active");

      const filterValue = e.target.getAttribute("data-filter");

      tableRows.forEach((row) => {
        const rowStatus = row.getAttribute("data-status");
        if (filterValue === "All" || rowStatus === filterValue) row.style.display = "";
        else row.style.display = "none";
      });
    });
  });

  // --- 3. Modal Logic ---
  const modals = {
    cancel: document.getElementById("modal-cancel"),
    reschedule: document.getElementById("modal-reschedule"),
    details: document.getElementById("modal-details"),
  };

  function openModal(modalId) {
    modals[modalId].classList.add("active");
    document.body.style.overflow = "hidden"; 
  }

  function closeModal() {
    Object.values(modals).forEach((modal) => modal.classList.remove("active"));
    document.body.style.overflow = "";

    const checkboxGrp = document.getElementById("cancel-checkbox-group");
    const refundInfo = document.getElementById("cancel-refund-info");
    if (checkboxGrp) checkboxGrp.style.display = "none";
    if (refundInfo) refundInfo.style.display = "none";

    document.querySelectorAll(".modal-box textarea, .modal-box input").forEach((input) => {
        if (input.type === "checkbox") input.checked = false;
        else input.value = "";
    });
  }

  document.querySelectorAll(".close-modal").forEach((btn) => btn.addEventListener("click", closeModal));

  Object.values(modals).forEach((modal) => {
    modal.addEventListener("click", (e) => { if (e.target === modal) closeModal(); });
  });

  // --- 4. Bind Action Buttons ---

  // A. Cancel Button
  document.querySelectorAll(".btn-cancel").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      const bookingId = btn.getAttribute("data-id");
      const venue = btn.getAttribute("data-venue");
      const date = btn.getAttribute("data-date");
      const paidStr = btn.getAttribute("data-paid");
      const amountPaid = parseFloat(paidStr) || 0;

      if (amountPaid === 0) {
          showConfirmModal(`Are you sure you want to cancel your reservation for ${venue} on ${date}?`, () => {
              const originalText = btn.innerText;
              btn.innerText = "Cancelling...";
              btn.disabled = true;

              fetch('actions/user/request_cancel.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                  body: JSON.stringify({ booking_id: bookingId, reason: 'Unpaid Auto-Cancel' })
              })
              .then(res => res.json())
              .then(data => {
                  if (data.success) showAlertModal("Cancelled", "Reservation instantly cancelled.", "success", true);
                  else {
                      showAlertModal("Error", data.message, "error");
                      btn.innerText = originalText;
                      btn.disabled = false;
                  }
              });
          });
      } else {
          document.getElementById("cancel-venue").textContent = venue;
          document.getElementById("cancel-date").textContent = date;

          const refundInfo = document.getElementById("cancel-refund-info");
          const checkboxGrp = document.getElementById("cancel-checkbox-group");

          let fee = 461;
          let refundAmt = amountPaid - fee;
          if (refundAmt < 0) refundAmt = 0;

          document.getElementById("cancel-paid").textContent = `₱${amountPaid.toLocaleString()}`;
          document.getElementById("cancel-refund-total").textContent = `₱${refundAmt.toLocaleString()}`;

          refundInfo.style.display = "block";
          checkboxGrp.style.display = "flex";

          const confirmBtn = document.querySelector("#modal-cancel .btn-confirm-red");
          if (confirmBtn) confirmBtn.setAttribute("data-id", bookingId);

          openModal("cancel");
      }
    });
  });

  const btnConfirmCancel = document.querySelector("#modal-cancel .btn-confirm-red");
  if (btnConfirmCancel) {
    btnConfirmCancel.addEventListener("click", function () {
      const bookingId = this.getAttribute("data-id");
      const reasonInput = document.querySelector("#modal-cancel textarea");
      const reason = reasonInput ? reasonInput.value.trim() : "";
      const isRefundable = document.getElementById("cancel-refund-info").style.display === "block";
      const isChecked = document.getElementById("confirm-fee").checked;

      if (reason === "") return showAlertModal("Missing Info", "Please provide a reason for the cancellation.", "error");
      if (isRefundable && !isChecked) return showAlertModal("Required", "Please acknowledge the non-refundable service fee by checking the box.", "error");

      const originalText = this.innerText;
      this.innerText = "Processing...";
      this.disabled = true;

      fetch('actions/user/request_cancel.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                  body: JSON.stringify({ booking_id: bookingId, reason: 'Unpaid Auto-Cancel' }) 
              })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) showAlertModal("Success", data.message, "success", true);
        else {
          showAlertModal("Error", data.message, "error");
          this.innerText = originalText;
          this.disabled = false;
        }
      })
      .catch((error) => {
        showAlertModal("Network Error", "Network error occurred.", "error");
        this.innerText = originalText;
        this.disabled = false;
      });
    });
  }

  // B. Reschedule Button Logic
  let userReschedCalendar = null;
  if (typeof SevillaCalendar !== 'undefined' && document.getElementById("cal-ui-user-resched")) {
      userReschedCalendar = new SevillaCalendar("cal-ui-user-resched");
  }

  document.querySelectorAll(".btn-reschedule").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      const bookingId = btn.getAttribute("data-id");
      const venueName = btn.getAttribute("data-venue");
      const originalDate = btn.getAttribute("data-date");
      const venueType = btn.getAttribute("data-type") || "Hotel Room"; 

      document.getElementById("reschedule-venue").textContent = venueName;
      document.getElementById("reschedule-date").textContent = originalDate;
      
      const submitBtn = document.getElementById("btn-submit-resched");
      if (submitBtn) submitBtn.setAttribute("data-id", bookingId);

      const reasonInput = document.getElementById("reschedule-reason");
      const confirmCheck = document.getElementById("confirm-reschedule");
      if (reasonInput) reasonInput.value = "";
      if (confirmCheck) confirmCheck.checked = false;

      if (userReschedCalendar) {
          userReschedCalendar.clearSelection();
          userReschedCalendar.fetchBookedDates(venueType, venueName);
          setTimeout(() => userReschedCalendar.render(), 100);
      }

      openModal("reschedule");
    });
  });

  const btnSubmitResched = document.getElementById("btn-submit-resched");
  if (btnSubmitResched) {
      btnSubmitResched.addEventListener("click", function() {
          const bookingId = this.getAttribute("data-id");
          const reason = document.getElementById("reschedule-reason")?.value.trim() || "";
          const isChecked = document.getElementById("confirm-reschedule")?.checked;

          if (!userReschedCalendar || !userReschedCalendar.startDate) return showAlertModal("Missing Data", "Please select your new dates on the calendar.", "error");
          if (reason === "") return showAlertModal("Missing Info", "Please provide a reason for rescheduling.", "error");
          if (!isChecked) return showAlertModal("Required", "Please acknowledge the reschedule policy by checking the box.", "error");

          const formatLocal = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
          const newStart = formatLocal(userReschedCalendar.startDate);
          const newEnd = userReschedCalendar.endDate ? formatLocal(userReschedCalendar.endDate) : newStart;

          const originalText = this.innerText;
          this.innerText = "Submitting...";
          this.disabled = true;

          fetch('actions/user/request_reschedule.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
              body: JSON.stringify({ booking_id: bookingId, new_start_date: newStart, new_end_date: newEnd, reason: reason })
          })
          .then(res => res.json())
          .then(data => {
              if (data.success) showAlertModal("Success", data.message, "success", true);
              else {
                  showAlertModal("Error", data.message, "error");
                  this.innerText = originalText;
                  this.disabled = false;
              }
          })
          .catch(err => {
              showAlertModal("Network Error", "Network error occurred.", "error");
              this.innerText = originalText;
              this.disabled = false;
          });
      });
  }

  // C. View Details Button
  document.querySelectorAll(".btn-details").forEach((btn) => {
    btn.addEventListener("click", function(e) {
        const bookingId = this.getAttribute('data-id');
        const originalText = this.innerText;
        
        this.innerText = "Loading...";
        this.disabled = true;

        fetch(`actions/user/get_my_booking_details.php?id=${bookingId}`)
        .then(response => response.json())
        .then(res => {
            this.innerText = originalText;
            this.disabled = false;

            if (!res.success) return showAlertModal("Error", "Error loading details: " + res.message, "error");

            const data = res.data.booking;
            const specifics = res.data.specifics;
            const addons = res.data.addons;

            document.getElementById('ud-title').innerText = `Booking #${data.id}`;
            
            const badge = document.getElementById('ud-status-badge');
            let badgeClass = 'badge-pending';
            let badgeText = data.booking_status;

            if (data.booking_status === 'Confirmed') {
                if (data.payment_status === 'Paid') { badgeClass = 'badge-paid'; badgeText = 'Fully Paid'; }
                else if (data.payment_status === 'Partial') { badgeClass = 'badge-partial'; badgeText = 'Partially Paid'; }
                else { badgeClass = 'badge-pending'; badgeText = 'Unpaid'; }
            } else if (data.booking_status === 'Cancelled') {
                badgeClass = 'badge-cancelled'; badgeText = 'Cancelled';
            } else if (data.booking_status === 'Pending') {
                badgeText = 'Pending';
            }

            badge.innerText = badgeText;
            badge.className = 'badge ' + badgeClass; 

            document.getElementById('ud-customer-name').innerText = `${data.first_name} ${data.last_name}`;
            document.getElementById('ud-venue').innerText = `${data.venue_name} (${data.venue_category})`;
            document.getElementById('ud-guests').innerText = data.guests_count;

            const opts = { month: "short", day: "numeric", year: "numeric" };
            const sDate = new Date(data.start_date).toLocaleDateString("en-US", opts);
            const eDate = new Date(data.end_date).toLocaleDateString("en-US", opts);
            document.getElementById('ud-dates').innerText = (sDate === eDate) ? sDate : `${sDate} — ${eDate}`;

            const specRow = document.getElementById('ud-specific-row');
            const specLabel = document.getElementById('ud-specific-label');
            const specValue = document.getElementById('ud-specific-value');
            
            if (specifics) {
                specRow.style.display = 'flex';
                if (data.venue_category === 'Event Hall') {
                    specLabel.innerText = "Event Details:";
                    specValue.innerHTML = `<strong>${specifics.event_type}</strong> (${specifics.event_style})<br><span style="color:#666; font-size:0.85rem; display:block; margin-top:5px;"><strong>Your Notes:</strong> ${specifics.custom_notes || 'None'}</span>`;
                } else if (data.venue_category === 'Resort Villa') {
                    specLabel.innerText = "Stay Type:";
                    specValue.innerText = specifics.stay_type;
                }
            } else specRow.style.display = 'none';

            // Cancellation reason (only relevant if booking is Cancelled)
            const cancelRow = document.getElementById('ud-cancel-row');
            const cancelReasonEl = document.getElementById('ud-cancel-reason');
            const cancellation = res.data.cancellation;

            if (data.booking_status === 'Cancelled' && cancellation && cancellation.reason) {
            cancelReasonEl.innerText = cancellation.reason;
            cancelRow.style.display = 'flex';
            } else {
            cancelRow.style.display = 'none';
}

            const addonsContainer = document.getElementById('ud-addons-container');
            const addonsList = document.getElementById('ud-addons-list');
            addonsList.innerHTML = ''; 
            if (addons && addons.length > 0) {
                addonsContainer.style.display = 'block';
                addons.forEach(addon => {
                    addonsList.innerHTML += `<p style="border:none; padding:2px 0;"><span>&#8226; ${addon.name} (x${addon.quantity})</span> <span style="color:var(--color-dark-light);">₱${parseFloat(addon.total_price).toLocaleString()}</span></p>`;
                });
            } else addonsContainer.style.display = 'none';

            const formatCash = (amt) => `₱${parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
            const isPendingEvent = data.venue_category === 'Event Hall' && data.booking_status === 'Pending';

            if (isPendingEvent) {
                document.getElementById('ud-base-amt').innerText = "TBA";
                document.getElementById('ud-addons-amt').innerText = "TBA";
                document.getElementById('ud-extrapax-amt').innerText = "TBA";
                document.getElementById('ud-total-amt').innerText = "To Be Arranged";
                document.getElementById('ud-scheme').innerText = "To Be Arranged";
            } else {
                document.getElementById('ud-base-amt').innerText = formatCash(data.base_amount);
                document.getElementById('ud-addons-amt').innerText = formatCash(data.addons_amount);
                document.getElementById('ud-extrapax-amt').innerText = formatCash(data.extra_pax_amount);
                document.getElementById('ud-total-amt').innerText = formatCash(data.total_amount);
                
                // SMART UX FIX: Show (Balance Settled) if they paid their partial scheme!
                let schemeText = data.payment_scheme;
                if (data.payment_scheme !== '100% Full' && data.payment_status === 'Paid') {
                    schemeText = `${data.payment_scheme} (Balance Settled)`;
                }
                document.getElementById('ud-scheme').innerText = schemeText;
            }
            
            document.getElementById('ud-paid-amt').innerText = formatCash(data.amount_paid);
            document.getElementById('ud-tid').innerText = res.data.transaction_id || "--";

            openModal("details");
        })
        .catch(err => {
            showAlertModal("Network Error", "Network error fetching details.", "error");
            this.innerText = originalText;
            this.disabled = false;
        });
    });
  });

  // --- 6. User Settings Logic ---
  function updateSettings(payload, buttonElement) {
      const originalText = buttonElement.innerText;
      buttonElement.innerText = "Saving...";
      buttonElement.disabled = true;

      fetch("actions/user/save_settings.php", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify(payload),
      })
      .then(res => res.json())
      .then(data => {
          if (data.success) {
              showAlertModal("Success", data.message, "success", payload.action === 'update_profile');
              if (payload.action === 'update_password') {
                  document.getElementById('set-old-pass').value = '';
                  document.getElementById('set-new-pass').value = '';
              }
          } else {
              showAlertModal("Error", data.message, "error");
          }
          buttonElement.innerText = originalText;
          buttonElement.disabled = false;
      })
      .catch(err => {
          showAlertModal("Network Error", "Network error occurred.", "error");
          buttonElement.innerText = originalText;
          buttonElement.disabled = false;
      });
  }

  document.getElementById('btn-save-profile')?.addEventListener('click', function() {
      updateSettings({
          action: 'update_profile',
          fname: document.getElementById('set-fname').value,
          lname: document.getElementById('set-lname').value,
          phone: document.getElementById('set-phone').value,
          dob: document.getElementById('set-dob').value 
      }, this);
  });

  document.getElementById('btn-save-prefs')?.addEventListener('click', function() {
      updateSettings({
          action: 'update_prefs',
          prefs: document.getElementById('set-prefs').value
      }, this);
  });

  document.getElementById('btn-update-password')?.addEventListener('click', function() {
      updateSettings({
          action: 'update_password',
          old_pass: document.getElementById('set-old-pass').value,
          new_pass: document.getElementById('set-new-pass').value
      }, this);
  });

  // --- 7. Pay Now Button Logic ---
  document.querySelectorAll(".btn-pay-now").forEach(btn => {
      btn.addEventListener("click", function() {
          const bookingId = this.getAttribute('data-id');
          const originalText = this.innerText;

          this.innerText = "Connecting...";
          this.disabled = true;

          fetch("actions/user/pay_existing.php", {
              method: "POST",
              headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
              body: JSON.stringify({ booking_id: bookingId })
          })
          .then(res => res.json())
          .then(data => {
              if (data.success) window.location.href = data.checkout_url;
              else {
                  showAlertModal("Payment Error", data.message, "error");
                  this.innerText = originalText;
                  this.disabled = false;
              }
          })
          .catch(err => {
              showAlertModal("Network Error", "Network error occurred.", "error");
              this.innerText = originalText;
              this.disabled = false;
          });
      });
  });
});