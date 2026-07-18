/**
 * SEVILLA360 - User Dashboard Logic
 * Handles Tabs, Filtering, and Modals (Cancel, Reschedule, Details).
 */

document.addEventListener("DOMContentLoaded", () => {
  // --- 1. Tab Switching Logic ---
  const navItems = document.querySelectorAll(".nav-item");
  const tabPanes = document.querySelectorAll(".tab-pane");

  navItems.forEach((item) => {
    item.addEventListener("click", (e) => {
      e.preventDefault();

      // Remove active classes
      navItems.forEach((nav) => nav.classList.remove("active"));
      tabPanes.forEach((pane) => pane.classList.remove("active"));

      // Add active to clicked nav
      item.classList.add("active");

      // Show corresponding tab
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
        if (filterValue === "All" || rowStatus === filterValue) {
          row.style.display = "";
        } else {
          row.style.display = "none";
        }
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
    document.body.style.overflow = "hidden"; // Prevent background scrolling
  }

  function closeModal() {
    Object.values(modals).forEach((modal) => modal.classList.remove("active"));
    document.body.style.overflow = "";

    // Reset cancel modal state on close
    const checkboxGrp = document.getElementById("cancel-checkbox-group");
    const refundInfo = document.getElementById("cancel-refund-info");
    if (checkboxGrp) checkboxGrp.style.display = "none";
    if (refundInfo) refundInfo.style.display = "none";

    // Reset inputs
    document
      .querySelectorAll(".modal-box textarea, .modal-box input")
      .forEach((input) => {
        if (input.type === "checkbox") input.checked = false;
        else input.value = "";
      });
  }

  // Close buttons inside modals
  document.querySelectorAll(".close-modal").forEach((btn) => {
    btn.addEventListener("click", closeModal);
  });

  // Close on overlay click
  Object.values(modals).forEach((modal) => {
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal();
    });
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
          // INSTANT CANCEL: If they haven't paid, don't ask for a reason. Just ask "Are you sure?"
          if (confirm(`Are you sure you want to cancel your reservation for ${venue} on ${date}?`)) {
              
              const originalText = btn.innerText;
              btn.innerText = "Cancelling...";
              btn.disabled = true;

              fetch('actions/user/request_cancel.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ booking_id: bookingId, reason: 'Unpaid Auto-Cancel' })
              })
              .then(res => res.json())
              .then(data => {
                  if (data.success) {
                      alert("Reservation instantly cancelled.");
                      window.location.reload();
                  } else {
                      alert("Error: " + data.message);
                      btn.innerText = originalText;
                      btn.disabled = false;
                  }
              });
          }
      } else {
          // REFUND REQUEST: If they HAVE paid, show the modal to explain the fees and ask for a reason.
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

  // B. Reschedule Button
  document.querySelectorAll(".btn-reschedule").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      document.getElementById("reschedule-venue").textContent =
        btn.getAttribute("data-venue");
      document.getElementById("reschedule-date").textContent =
        btn.getAttribute("data-date");
      openModal("reschedule");
    });
  });

  // C. View Details Button
  document.querySelectorAll(".btn-details").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      const status = btn.getAttribute("data-status");
      const paid = btn.getAttribute("data-paid");

      const statusBadge = document.getElementById("details-status-badge");
      statusBadge.textContent = status;

      // Color code the status in modal
      if (status === "Paid") {
        statusBadge.className = "text-green";
      } else if (status === "Cancelled") {
        statusBadge.style.color = "#d32f2f";
      }

      document.getElementById("details-venue").textContent =
        btn.getAttribute("data-venue");
      document.getElementById("details-date").textContent =
        btn.getAttribute("data-date");
      document.getElementById("details-paid").textContent =
        `₱${parseInt(paid).toLocaleString()}`;
      document.getElementById("details-tid").textContent =
        btn.getAttribute("data-tid");

      openModal("details");
    });
  });

  // --- 5. Modal Confirm Actions ---
  const btnConfirmCancel = document.querySelector(
    "#modal-cancel .btn-confirm-red",
  );
  if (btnConfirmCancel) {
    btnConfirmCancel.addEventListener("click", function () {
      const bookingId = this.getAttribute("data-id");
      const reasonInput = document.querySelector("#modal-cancel textarea");
      const reason = reasonInput ? reasonInput.value.trim() : "";
      const isRefundable =
        document.getElementById("cancel-refund-info").style.display === "block";
      const isChecked = document.getElementById("confirm-fee").checked;

      if (reason === "") {
        alert("Please provide a reason for the cancellation.");
        return;
      }

      if (isRefundable && !isChecked) {
        alert(
          "Please acknowledge the non-refundable service fee by checking the box.",
        );
        return;
      }

      // UX: Disable button while loading
      const originalText = this.innerText;
      this.innerText = "Processing...";
      this.disabled = true;

      // Send to our new PHP backend!
      fetch("actions/user/request_cancel.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          booking_id: bookingId,
          reason: reason,
        }),
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            alert(data.message);
            window.location.reload(); // Refresh to show "Cancel Requested" badge
          } else {
            alert("Error: " + data.message);
            this.innerText = originalText;
            this.disabled = false;
          }
        })
        .catch((error) => {
          console.error(error);
          alert("Network error occurred.");
          this.innerText = originalText;
          this.disabled = false;
        });
    });
  }
});
