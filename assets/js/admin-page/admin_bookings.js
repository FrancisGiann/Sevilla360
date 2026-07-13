document.addEventListener("DOMContentLoaded", () => {
  // --- 1. Filter Pills Logic ---
  const filters = document.querySelectorAll(".filter-pill");

  filters.forEach((filter) => {
    filter.addEventListener("click", () => {
      // Remove active class from all
      filters.forEach((f) => f.classList.remove("active"));
      // Add active class to clicked
      filter.classList.add("active");

      const filterType = filter.getAttribute("data-filter");
      // Logic to filter table rows based on filterType goes here
    });
  });

  // --- 2. Modals Setup ---
  const modalOverlay = document.getElementById("modalOverlay");
  const refundModal = document.getElementById("refundModal");
  const rescheduleModal = document.getElementById("rescheduleModal");
  const closeButtons = document.querySelectorAll(".close-modal");

  // Open Refund Modal
  const refundBtns = document.querySelectorAll(".open-refund");
  refundBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      modalOverlay.classList.add("active");
      document
        .querySelectorAll(".admin-modal")
        .forEach((m) => m.classList.remove("active"));
      refundModal.classList.add("active");
    });
  });

  // Open Reschedule Modal
  const rescheduleBtns = document.querySelectorAll(".open-reschedule");
  rescheduleBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      modalOverlay.classList.add("active");
      document
        .querySelectorAll(".admin-modal")
        .forEach((m) => m.classList.remove("active"));
      rescheduleModal.classList.add("active");
      // Ensure calendar is closed when opening modal
      calendarDropdown.classList.remove("active");
    });
  });

  // Close Modals Function
  const closeModal = () => {
    modalOverlay.classList.remove("active");
    document.querySelectorAll(".admin-modal").forEach((m) => {
      m.classList.remove("active");
    });
    // Hide calendar pop-up if open
    calendarDropdown.classList.remove("active");
  };

  closeButtons.forEach((btn) => {
    btn.addEventListener("click", closeModal);
  });

  // Close on background overlay click
  modalOverlay.addEventListener("click", (e) => {
    if (e.target === modalOverlay) {
      closeModal();
    }
  });

  // --- 3. Calendar Popup Logic (Reschedule Modal) ---
  const dateInputWrapper = document.getElementById("rescheduleDateInput");
  const calendarDropdown = document.getElementById(
    "rescheduleCalendarDropdown",
  );
  const selectedNewDateSpan = document.getElementById("selectedNewDate");
  const availableDays = document.querySelectorAll(
    ".calendar-grid .cal-day.available",
  );

  // Toggle calendar popup when input is clicked
  if (dateInputWrapper && calendarDropdown) {
    dateInputWrapper.addEventListener("click", (e) => {
      e.stopPropagation(); // Prevents immediate closing
      calendarDropdown.classList.toggle("active");
    });

    // Date Selection inside calendar
    availableDays.forEach((day) => {
      day.addEventListener("click", (e) => {
        e.stopPropagation();

        // Remove selected class from all
        document
          .querySelectorAll(".calendar-grid .cal-day")
          .forEach((d) => d.classList.remove("selected"));

        // Add selected class to clicked
        day.classList.add("selected");

        // Update text in the input display
        const selectedDateStr =
          day.getAttribute("data-date") || `February ${day.textContent}, 2026`;
        selectedNewDateSpan.textContent = selectedDateStr;

        // Hide calendar after selection
        calendarDropdown.classList.remove("active");
      });
    });

    // Close calendar if clicking anywhere outside the date-picker-wrapper
    document.addEventListener("click", (e) => {
      if (
        !dateInputWrapper.contains(e.target) &&
        !calendarDropdown.contains(e.target)
      ) {
        calendarDropdown.classList.remove("active");
      }
    });
  }

  // --- 4. AJAX Status Updates (Confirm & Cancel) ---
  
  // Reusable function to send the Fetch request
  const processBookingAction = (bookingId, action, buttonElement) => {
    
    // 1. Disable the button to prevent double-clicks
    const originalText = buttonElement.innerText;
    buttonElement.innerText = "Processing...";
    buttonElement.disabled = true;
    buttonElement.style.opacity = "0.7";

    fetch('actions/admin/update_booking_status.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        booking_id: bookingId,
        action: action
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert(data.message);
        window.location.reload(); 
      } else {
        alert("Error: " + data.message);
        // Re-enable button if there was an error
        buttonElement.innerText = originalText;
        buttonElement.disabled = false;
        buttonElement.style.opacity = "1";
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert("An error occurred while communicating with the server.");
      // Re-enable button if there was a network error
      buttonElement.innerText = originalText;
      buttonElement.disabled = false;
      buttonElement.style.opacity = "1";
    });
  };

  // Attach click events to all Confirm buttons
  const confirmBtns = document.querySelectorAll('.btn-confirm');
  confirmBtns.forEach((btn) => {
    btn.addEventListener('click', function() {
      const bookingId = this.getAttribute('data-id');
      if (confirm('Are you sure you want to confirm Booking #' + bookingId + '?')) {
        // Pass 'this' (the button) into the function
        processBookingAction(bookingId, 'confirm', this);
      }
    });
  });

  // Attach click events to all Cancel buttons
  const cancelBtns = document.querySelectorAll('.btn-cancel');
  cancelBtns.forEach((btn) => {
    btn.addEventListener('click', function() {
      const bookingId = this.getAttribute('data-id');
      if (confirm('Are you sure you want to cancel Booking #' + bookingId + '? This action cannot be undone.')) {
         // Pass 'this' (the button) into the function
        processBookingAction(bookingId, 'cancel', this);
      }
    });
  });
});
