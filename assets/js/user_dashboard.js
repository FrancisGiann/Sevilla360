/**
 * SEVILLA360 - User Dashboard Logic
 * Handles Tabs, Filtering, Modals, and Settings Updates.
 */

document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  // =========================================================
  // 1. MODAL BRIDGES to Global Modals
  // =========================================================
  function showConfirmModal(message, callback) {
      window.showConfirm("Confirm Action", message).then(c => {
          if(c && callback) callback();
      });
  }

  function showAlertModal(title, message, type = "info", reloadOnClose = false) {
      window.showAlert(title, message, type, reloadOnClose);
  }

  // --- 0. Notification Bell ---
  const btnNotifs = document.getElementById('btn-notifications');
  const notifDropdown = document.getElementById('notif-dropdown');
  const btnMarkRead = document.getElementById('btn-mark-read');
  const notifBadge = document.getElementById('notif-badge');

  if (btnNotifs && notifDropdown) {
      // Toggle dropdown
      btnNotifs.addEventListener('click', (e) => {
          e.stopPropagation();
          const isVisible = notifDropdown.style.display === 'block';
          notifDropdown.style.display = isVisible ? 'none' : 'block';
      });

      // Close when clicking outside
      document.addEventListener('click', (e) => {
          if (!btnNotifs.contains(e.target) && !notifDropdown.contains(e.target)) {
              notifDropdown.style.display = 'none';
          }
      });
  }

  if (btnMarkRead) {
      btnMarkRead.addEventListener('click', () => {
          fetch('actions/user/mark_notifications_read.php')
          .then(res => res.json())
          .then(data => {
              if(data.success) {
                  // Hide badge
                  if(notifBadge) notifBadge.style.display = 'none';
                  // Remove unread styling
                  document.querySelectorAll('.notif-item.unread').forEach(item => {
                      item.classList.remove('unread');
                      item.style.background = 'none';
                      item.style.opacity = '0.7';
                  });
                  // Remove button
                  btnMarkRead.remove();
              }
          })
          .catch(err => console.error(err));
      });
  }
  
  // --- 1. Tab Switching Logic ---
  const navItems = document.querySelectorAll(".nav-item");
  const tabPanes = document.querySelectorAll(".tab-pane");

  navItems.forEach((item) => {
    item.addEventListener("click", (e) => {
      if (item.classList.contains('sign-out')) return; // Allow logout link to work naturally
      e.preventDefault();
      navItems.forEach((nav) => nav.classList.remove("active"));
      tabPanes.forEach((pane) => pane.classList.remove("active"));
      item.classList.add("active");
      const targetTab = item.getAttribute("data-tab");
      const pane = document.getElementById(`tab-${targetTab}`);
      if (pane) pane.classList.add("active");
    });
  });

  // --- 2. Table Filtering ---
  const filterPills = document.querySelectorAll(".filter-pill");
  const tableRows = document.querySelectorAll("#bookingsTable tbody tr[data-status]");

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
    if (modals[modalId]) {
        modals[modalId].classList.add("active");
        document.body.style.overflow = "hidden"; 
    }
  }

  function closeModal() {
    Object.values(modals).forEach((modal) => {
        if(modal) modal.classList.remove("active");
    });
    document.body.style.overflow = "";

    const checkboxGrp = document.getElementById("cancel-checkbox-group");
    const refundInfo = document.getElementById("cancel-refund-info-wrapper");
    if (checkboxGrp) checkboxGrp.style.display = "none";
    if (refundInfo) refundInfo.style.display = "none";

    document.querySelectorAll(".modal-box textarea, .modal-box input").forEach((input) => {
        if (input.type === "checkbox") input.checked = false;
        else input.value = "";
    });
  }

  document.querySelectorAll(".close-modal").forEach((btn) => btn.addEventListener("click", closeModal));

  Object.values(modals).forEach((modal) => {
    if(modal) {
        modal.addEventListener("click", (e) => { if (e.target === modal) closeModal(); });
    }
  });

  // --- 4. ACTION BUTTONS ---

  // A. Cancel Button
  document.querySelectorAll(".btn-cancel").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      const bookingId = btn.getAttribute("data-id");
      const venue = btn.getAttribute("data-venue");
      const date = btn.getAttribute("data-date");
      const paidStr = btn.getAttribute("data-paid");
      const amountPaid = parseFloat(paidStr) || 0;

      const cancelVenueEl = document.getElementById("cancel-venue");
      const cancelDateEl = document.getElementById("cancel-date");
      if (cancelVenueEl) cancelVenueEl.textContent = venue;
      if (cancelDateEl) cancelDateEl.textContent = date;

      const refundInfoTop = document.getElementById("cancel-refund-info-wrapper");
      const refundInfoBottom = document.getElementById("cancel-refund-bottom");
      const unpaidInfo = document.getElementById("cancel-unpaid-info");
      const confirmBtn = document.querySelector("#modal-cancel .btn-confirm-red");

      if (amountPaid === 0) {
          if (refundInfoTop) refundInfoTop.style.display = "none";
          if (refundInfoBottom) refundInfoBottom.style.display = "none";
          if (unpaidInfo) unpaidInfo.style.display = "block";
      } else {
          let fee = 461;
          let refundAmt = amountPaid - fee;
          if (refundAmt < 0) refundAmt = 0;

          const cancelPaidEl = document.getElementById("cancel-paid");
          const cancelRefundTotalEl = document.getElementById("cancel-refund-total");
          if(cancelPaidEl) cancelPaidEl.textContent = `₱${amountPaid.toLocaleString()}`;
          if(cancelRefundTotalEl) cancelRefundTotalEl.textContent = `₱${refundAmt.toLocaleString()}`;

          if (refundInfoTop) refundInfoTop.style.display = "block";
          if (refundInfoBottom) refundInfoBottom.style.display = "block";
          if (unpaidInfo) unpaidInfo.style.display = "none";
      }

      if (confirmBtn) confirmBtn.setAttribute("data-id", bookingId);
      openModal("cancel");
    });
  });

  const btnConfirmCancel = document.querySelector("#modal-cancel .btn-confirm-red");
  if (btnConfirmCancel) {
    btnConfirmCancel.addEventListener("click", function () {
      const bookingId = this.getAttribute("data-id");
      const reasonInput = document.querySelector("#modal-cancel textarea");
      const reason = reasonInput ? reasonInput.value.trim() : "";
      
      const refundBottom = document.getElementById("cancel-refund-bottom");
      const isRefundable = refundBottom ? (refundBottom.style.display === "block") : false;
      
      const confirmFee = document.getElementById("confirm-fee");
      const isChecked = confirmFee ? confirmFee.checked : false;

      if (reason === "") return showAlert("Missing Info", "Please provide a reason for the cancellation.", "error");
      if (isRefundable && !isChecked) return showAlert("Required", "Please acknowledge the non-refundable service fee by checking the box.", "error");

      const originalText = this.innerText;
      this.innerText = "Processing...";
      this.disabled = true;

      fetch('actions/user/request_cancel.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
          body: JSON.stringify({ booking_id: bookingId, reason: reason }) 
      })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) showAlert("Success", data.message, "success", true);
        else {
          showAlert("Error", data.message, "error");
          this.innerText = originalText;
          this.disabled = false;
        }
      })
      .catch((error) => {
        showAlert("Network Error", "Network error occurred.", "error");
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

      const rv = document.getElementById("reschedule-venue");
      const rd = document.getElementById("reschedule-date");
      if(rv) rv.textContent = venueName;
      if(rd) rd.textContent = originalDate;
      
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

          if (!userReschedCalendar || !userReschedCalendar.startDate) return showAlert("Missing Data", "Please select your new dates on the calendar.", "error");
          if (reason === "") return showAlert("Missing Info", "Please provide a reason for rescheduling.", "error");
          if (!isChecked) return showAlert("Required", "Please acknowledge the reschedule policy by checking the box.", "error");

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
              if (data.success) showAlert("Success", data.message, "success", true);
              else {
                  showAlert("Error", data.message, "error");
                  this.innerText = originalText;
                  this.disabled = false;
              }
          })
          .catch(err => {
              showAlert("Network Error", "Network error occurred.", "error");
              this.innerText = originalText;
              this.disabled = false;
          });
      });
  }

  // C. View Details Button
  document.querySelectorAll(".btn-details").forEach((btn) => {
    btn.addEventListener("click", function(e) {
        const bookingId = this.getAttribute('data-id');
        const originalHTML = this.innerHTML; 
        
        this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span class="vd-text" style="margin-left:5px;">Loading...</span>';
        this.disabled = true;

        fetch(`actions/user/get_my_booking_details.php?id=${bookingId}`)
        .then(async (response) => {
            if (!response.ok) throw new Error("HTTP " + response.status);
            const text = await response.text();
            try { return JSON.parse(text); } 
            catch (err) {
                console.error("CRITICAL PHP ERROR IN JSON:", text);
                throw new Error("Invalid Server Response.");
            }
        })
        .then(res => {
            this.innerHTML = originalHTML; 
            this.disabled = false;

            if (!res.success) return showAlert("Error", "Error loading details: " + res.message, "error");

            const data = res.data.booking;
            const specifics = res.data.specifics;
            const addons = res.data.addons;

            const displayId = data.reference_no ? data.reference_no : '#' + data.id;
            const titleEl = document.getElementById('ud-title');
            if(titleEl) titleEl.innerText = `Booking ${displayId}`;
            
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

            const badge = document.getElementById('ud-status-badge');
            if(badge) {
                badge.innerText = badgeText;
                badge.className = 'badge ' + badgeClass; 
            }

            const nameEl = document.getElementById('ud-customer-name');
            const venueEl = document.getElementById('ud-venue');
            const guestsEl = document.getElementById('ud-guests');
            if(nameEl) nameEl.innerText = `${data.first_name} ${data.last_name}`;
            if(venueEl) venueEl.innerText = `${data.venue_name} (${data.venue_category})`;
            if(guestsEl) guestsEl.innerText = data.guests_count;

            const opts = { month: "short", day: "numeric", year: "numeric" };
            const sDate = new Date(data.start_date).toLocaleDateString("en-US", opts);
            const eDate = new Date(data.end_date).toLocaleDateString("en-US", opts);
            const datesEl = document.getElementById('ud-dates');
            if(datesEl) datesEl.innerText = (sDate === eDate) ? sDate : `${sDate} — ${eDate}`;

            const specRow = document.getElementById('ud-specific-row');
            const specLabel = document.getElementById('ud-specific-label');
            const specValue = document.getElementById('ud-specific-value');
            
            if (specifics && specRow) {
                specRow.style.display = 'flex';
                if (data.venue_category === 'Event Hall') {
                    specLabel.innerText = "Event Details:";
                    specValue.innerHTML = `<strong>${specifics.event_type}</strong> (${specifics.event_style})<br><span style="color:#666; font-size:0.85rem; display:block; margin-top:5px;"><strong>Your Notes:</strong> ${specifics.custom_notes || 'None'}</span>`;
                } else if (data.venue_category === 'Resort Villa') {
                    specLabel.innerText = "Stay Type:";
                    specValue.innerText = specifics.stay_type;
                }
            } else if(specRow) {
                specRow.style.display = 'none';
            }

            const cancelRow = document.getElementById('ud-cancel-row');
            const cancelReasonEl = document.getElementById('ud-cancel-reason');
            const cancellation = res.data.cancellation;

            if (data.booking_status === 'Cancelled' && cancellation && cancellation.reason && cancelRow) {
                cancelReasonEl.innerText = cancellation.reason;
                cancelRow.style.display = 'flex';
            } else if(cancelRow) {
                cancelRow.style.display = 'none';
            }

            const addonsContainer = document.getElementById('ud-addons-container');
            const addonsList = document.getElementById('ud-addons-list');
            if (addonsList) addonsList.innerHTML = ''; 
            
            if (addons && addons.length > 0 && addonsContainer && addonsList) {
                addonsContainer.style.display = 'block';
                addons.forEach(addon => {
                    addonsList.innerHTML += `<p style="border:none; padding:2px 0;"><span>&#8226; ${addon.name} (x${addon.quantity})</span> <span style="color:var(--color-dark-light);">₱${parseFloat(addon.total_price).toLocaleString()}</span></p>`;
                });
            } else if(addonsContainer) {
                addonsContainer.style.display = 'none';
            }

            const formatCash = (amt) => `₱${parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
            const isPendingEvent = data.venue_category === 'Event Hall' && data.booking_status === 'Pending';

            if (isPendingEvent) {
                if(document.getElementById('ud-base-amt')) document.getElementById('ud-base-amt').innerText = "TBA";
                if(document.getElementById('ud-addons-amt')) document.getElementById('ud-addons-amt').innerText = "TBA";
                if(document.getElementById('ud-extrapax-amt')) document.getElementById('ud-extrapax-amt').innerText = "TBA";
                if(document.getElementById('ud-total-amt')) document.getElementById('ud-total-amt').innerText = "To Be Arranged";
                if(document.getElementById('ud-scheme')) document.getElementById('ud-scheme').innerText = "To Be Arranged";
            } else {
                if(document.getElementById('ud-base-amt')) document.getElementById('ud-base-amt').innerText = formatCash(data.base_amount);
                if(document.getElementById('ud-addons-amt')) document.getElementById('ud-addons-amt').innerText = formatCash(data.addons_amount);
                if(document.getElementById('ud-extrapax-amt')) document.getElementById('ud-extrapax-amt').innerText = formatCash(data.extra_pax_amount);
                if(document.getElementById('ud-total-amt')) document.getElementById('ud-total-amt').innerText = formatCash(data.total_amount);
                
                let schemeText = data.payment_scheme;
                if (data.payment_scheme !== '100% Full' && data.payment_status === 'Paid') {
                    schemeText = `${data.payment_scheme} (Balance Settled)`;
                }
                if(document.getElementById('ud-scheme')) document.getElementById('ud-scheme').innerText = schemeText;
            }
            
            if(document.getElementById('ud-paid-amt')) document.getElementById('ud-paid-amt').innerText = formatCash(data.amount_paid);
            if(document.getElementById('ud-tid')) document.getElementById('ud-tid').innerText = res.data.transaction_id || "--";

            openModal("details");
        })
        .catch(err => {
            showAlert("Error", "Network error fetching details.", "error");
            this.innerHTML = originalHTML; 
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
              showAlert("Success", data.message, "success", payload.action === 'update_profile');
              if (payload.action === 'update_password') {
                  document.getElementById('set-old-pass').value = '';
                  document.getElementById('set-new-pass').value = '';
              }
          } else {
              showAlert("Error", data.message, "error");
          }
          buttonElement.innerText = originalText;
          buttonElement.disabled = false;
      })
      .catch(err => {
          showAlert("Network Error", "Network error occurred.", "error");
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

          this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
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
                  showAlert("Payment Error", data.message, "error");
                  this.innerHTML = originalText;
                  this.disabled = false;
              }
          })
          .catch(err => {
              showAlert("Network Error", "Network error occurred.", "error");
              this.innerHTML = originalText;
              this.disabled = false;
          });
      });
  });
});