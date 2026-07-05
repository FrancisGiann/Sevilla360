/**
 * SEVILLA360 - Admin Walk-in Booking Logic
 */

document.addEventListener("DOMContentLoaded", () => {
  const formatCurrency = (amount) =>
    "₱" +
    parseFloat(amount).toLocaleString("en-US", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

  window.isDatesLocked = false;
  let activeCalendar = null;

  /* ==========================================
       1. AIRBNB-STYLE ADMIN CALENDAR
       ========================================== */
  class AdminBookingCalendar {
    constructor(containerId) {
      this.container = document.getElementById(containerId);
      if (!this.container) return;

      this.grid = this.container.querySelector(".cal-days-grid");
      this.monthYearDisplay = this.container.querySelector(".cal-month-year");
      this.prevBtn = this.container.querySelector(".prev-month");
      this.nextBtn = this.container.querySelector(".next-month");

      this.currentDate = new Date();
      this.currentDate.setDate(1);

      this.startDate = null;
      this.endDate = null;
      this.totalNights = 1;

      this.bookedDatesList = [];
      this.init();
    }

    init() {
      this.render();
      this.prevBtn.addEventListener("click", (e) => {
        e.preventDefault();
        this.currentDate.setMonth(this.currentDate.getMonth() - 1);
        this.render();
      });
      this.nextBtn.addEventListener("click", (e) => {
        e.preventDefault();
        this.currentDate.setMonth(this.currentDate.getMonth() + 1);
        this.render();
      });
    }

    async fetchBookedDates(room_type, room_name) {
            if(!room_type || !room_name) return;
            try {
                const response = await fetch(`/Sevilla360/actions/bookings/fetch_dates.php?room_type=${encodeURIComponent(room_type)}&room_name=${encodeURIComponent(room_name)}`);
                const data = await response.json();
                this.bookedDatesList = data; 
                this.render(); 
            } catch (error) {
                console.error("Error fetching dates:", error);
            }
        }

    hasInvalidDaysBetween(start, end) {
      let current = new Date(start);
      current.setDate(current.getDate() + 1);
      while (current < end) {
        const checkStr = `${current.getFullYear()}-${String(current.getMonth() + 1).padStart(2, "0")}-${String(current.getDate()).padStart(2, "0")}`;
        if (this.bookedDatesList.includes(checkStr)) return true;
        current.setDate(current.getDate() + 1);
      }
      return false;
    }

    render() {
      this.grid.innerHTML = "";
      const year = this.currentDate.getFullYear();
      const month = this.currentDate.getMonth();
      const monthNames = [
        "January",
        "February",
        "March",
        "April",
        "May",
        "June",
        "July",
        "August",
        "September",
        "October",
        "November",
        "December",
      ];

      this.monthYearDisplay.innerText = `${monthNames[month]} ${year}`;

      const firstDayIndex = new Date(year, month, 1).getDay();
      const daysInMonth = new Date(year, month + 1, 0).getDate();

      for (let i = 0; i < firstDayIndex; i++) {
        const emptyCell = document.createElement("div");
        emptyCell.className = "cal-day-cell empty";
        this.grid.appendChild(emptyCell);
      }

      for (let day = 1; day <= daysInMonth; day++) {
        const cellDate = new Date(year, month, day);
        const cell = document.createElement("div");
        cell.className = "cal-day-cell";
        cell.innerText = day;

        const cellDateStr = `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;

        if (this.bookedDatesList.includes(cellDateStr)) {
          cell.classList.add("booked");
        } else {
          if (
            this.startDate &&
            cellDate.getTime() === this.startDate.getTime()
          ) {
            cell.classList.add("selected", "start-date");
          }
          if (this.endDate && cellDate.getTime() === this.endDate.getTime()) {
            cell.classList.add("selected", "end-date");
          }
          if (
            this.startDate &&
            this.endDate &&
            cellDate > this.startDate &&
            cellDate < this.endDate
          ) {
            cell.classList.add("in-range");
          }

          cell.addEventListener("click", () => {
            if (window.isDatesLocked) {
              showOverrideModal(cellDate, this);
              return;
            }
            if (this.startDate && this.endDate) {
              this.startDate = cellDate;
              this.endDate = null;
              this.render();
            } else if (!this.startDate) {
              this.startDate = cellDate;
              this.render();
            } else if (this.startDate && !this.endDate) {
              if (cellDate < this.startDate) {
                this.startDate = cellDate;
                this.render();
              } else {
                if (this.hasInvalidDaysBetween(this.startDate, cellDate)) {
                  alert("Selection contains unavailable or booked dates.");
                  this.startDate = cellDate;
                  this.render();
                } else {
                  this.endDate = cellDate;
                  this.render();
                  requestDateConfirmation(this.startDate, this.endDate, this);
                }
              }
            }
          });
        }
        this.grid.appendChild(cell);
      }
    }

    updateDateDisplay() {
      const dateDisplayEl = document.getElementById("summary-dates");
      if (!this.startDate || !window.isDatesLocked) {
        dateDisplayEl.innerText = "Please select dates";
        this.totalNights = 1;
        return;
      }
      const opts = { month: "short", day: "numeric", year: "numeric" };
      const startStr = this.startDate.toLocaleDateString("en-US", opts);

      if (this.endDate && this.startDate.getTime() !== this.endDate.getTime()) {
        const endStr = this.endDate.toLocaleDateString("en-US", opts);
        dateDisplayEl.innerText = `${startStr} — ${endStr}`;
        const diffTime = Math.abs(this.endDate - this.startDate);
        this.totalNights = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
      } else {
        dateDisplayEl.innerText = startStr;
        this.totalNights = 1;
      }
    }

    clearSelection() {
      this.startDate = null;
      this.endDate = null;
      this.render();
      this.updateDateDisplay();
    }
  }

  // Modal Triggers
  function requestDateConfirmation(startDate, endDate, calendarInstance) {
    activeCalendar = calendarInstance;
    const dateModal = document.getElementById("confirm-dates-modal");
    const dateDisplay = document.getElementById("confirm-date-display");

    const opts = { month: "short", day: "numeric", year: "numeric" };
    const startStr = startDate.toLocaleDateString("en-US", opts);
    const endStr = endDate.toLocaleDateString("en-US", opts);
    dateDisplay.innerText = `${startStr} — ${endStr}`;

    dateModal.classList.add("active");
  }

  function showOverrideModal(newDate, calendarInstance) {
    const overrideModal = document.getElementById("change-dates-modal");
    overrideModal.classList.add("active");

    const oldYes = document.getElementById("btn-override-yes");
    const newYes = oldYes.cloneNode(true);
    oldYes.parentNode.replaceChild(newYes, oldYes);

    const oldNo = document.getElementById("btn-override-no");
    const newNo = oldNo.cloneNode(true);
    oldNo.parentNode.replaceChild(newNo, oldNo);

    newYes.addEventListener("click", () => {
      overrideModal.classList.remove("active");
      window.isDatesLocked = false;
      if (activeCalendar) activeCalendar.clearSelection();

      activeCalendar = calendarInstance;
      activeCalendar.startDate = newDate;
      activeCalendar.endDate = null;
      activeCalendar.render();
      activeCalendar.updateDateDisplay();
      calculateSummary();
    });

    newNo.addEventListener("click", () => {
      overrideModal.classList.remove("active");
    });
  }

  const btnConfirmDates = document.getElementById("btn-confirm-dates");
  if(btnConfirmDates) {
      btnConfirmDates.addEventListener("click", () => {
          document.getElementById("confirm-dates-modal").classList.remove("active");
          window.isDatesLocked = true;
          if(activeCalendar) activeCalendar.updateDateDisplay();
          calculateSummary();
      });
  }

  document.getElementById("btn-cancel-dates").addEventListener("click", () => {
    document.getElementById("confirm-dates-modal").classList.remove("active");
    activeCalendar.clearSelection();
  });

  // Initialize 3 separate calendars
  const calEvent = new AdminBookingCalendar("cal-ui-event");
  const calHotel = new AdminBookingCalendar("cal-ui-hotel");
  const calVilla = new AdminBookingCalendar("cal-ui-villa");

  /* ==========================================
       2. TABS & DYNAMIC IMAGES
       ========================================== */
  const tabBtns = document.querySelectorAll(".tab-btn");
  const tabContents = document.querySelectorAll(".tab-content");
  let currentTab = "tab-event";

  tabBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      tabBtns.forEach((b) => b.classList.remove("active"));
      tabContents.forEach((c) => c.classList.remove("active"));

      btn.classList.add("active");
      const targetId = btn.getAttribute("data-target");
      document.getElementById(targetId).classList.add("active");
      currentTab = targetId;

      if (currentTab === "tab-event") calEvent.updateDateDisplay();
      if (currentTab === "tab-hotel") calHotel.updateDateDisplay();
      if (currentTab === "tab-villa") calVilla.updateDateDisplay();

      calculateSummary();
    });
  });

  /* ==========================================
       3. CASCADING DROPDOWNS (Hotel Rooms)
       ========================================== */
  const typeSelect = document.getElementById("hotel-room-type");
  const nameSelect = document.getElementById("hotel-room-name");

  if (typeSelect && nameSelect) {
    // Listen for the Category change
    typeSelect.addEventListener("change", function () {
      // Wait to grab the data until the user actually clicks!
      // We use window.hotelRoomData to ensure it looks globally at the HTML script
      if (typeof window.hotelRoomData === "undefined") {
        console.error(
          "CRITICAL ERROR: hotelRoomData is missing from the HTML!",
        );
        return;
      }

      const selectedCategory = this.value;
      const rooms = window.hotelRoomData[selectedCategory];

      // If something goes wrong and it can't find the rooms, stop.
      if (!rooms) {
        console.error("Could not find rooms for category:", selectedCategory);
        return;
      }

      // 1. Clear the second dropdown
      nameSelect.innerHTML =
        '<option value="" disabled selected>Select a specific room...</option>';

      // 2. Fill it with the correct rooms
      rooms.forEach((room) => {
        const option = document.createElement("option");
        option.value = room.base_rate;
        option.setAttribute("data-type", room.room_type);
        option.setAttribute("data-name", room.building_name);

        // Format the text beautifully
        option.textContent = `${room.building_name} (${room.base_capacity} pax) - ₱${parseInt(room.base_rate).toLocaleString()} [${room.total_units} units]`;

        nameSelect.appendChild(option);
      });

      // 3. Unlock the dropdown!
      nameSelect.disabled = false;
    });

    // When the Specific Room changes... update everything else
    nameSelect.addEventListener('change', () => {
            calHotel.clearSelection();
            calculateSummary(); 
            
            const selectedOption = nameSelect.options[nameSelect.selectedIndex];
            const type = selectedOption.getAttribute('data-type');
            const name = selectedOption.getAttribute('data-name');
            
            calHotel.fetchBookedDates(type, name);
        });
  }

  /* ==========================================
       4. SUMMARY GENERATOR
       ========================================== */
  function calcExtraPax(guestInputId, baseCapacity, feePerHead, feeLabelId) {
    const guests = parseInt(document.getElementById(guestInputId).value) || 0;
    let extraFee = 0;
    const feeLabel = document.getElementById(feeLabelId);
    if (guests > baseCapacity) {
      extraFee = (guests - baseCapacity) * feePerHead;
      feeLabel.textContent = `Extra Pax Fee: ${formatCurrency(extraFee)}`;
      feeLabel.classList.remove("hidden");
    } else {
      feeLabel.classList.add("hidden");
    }
    return extraFee;
  }

  function calculateSummary() {
    let total = 0;
    let summaryHTML = "";
    const addRow = (label, amount) => {
      summaryHTML += `<div class="summary-row"><span>${label}</span><span>${formatCurrency(amount)}</span></div>`;
    };

    if (!window.isDatesLocked) {
      document.getElementById("summary-breakdown").innerHTML =
        '<div class="summary-row" style="color:#b5884e;"><i>Please select and lock dates to calculate.</i></div>';
      document.getElementById("summary-total-val").textContent = "₱0.00";
      document.getElementById("summary-due-val").textContent = "₱0.00";
      return;
    }

    if (currentTab === "tab-hotel") {
      const nights = calHotel.totalNights;
      // CHANGED: Pull value from hotel-room-name!
      const roomVal = document.getElementById("hotel-room-name").value;
      if (!roomVal) return;

      const room = parseFloat(roomVal) * nights;
      total += room;
      addRow(`Base Room Rate (x${nights} nights)`, room);

      const extraFeePerNight = calcExtraPax(
        "hotel-guests",
        2,
        800,
        "hotel-extra-fee",
      );
      if (extraFeePerNight > 0) {
        const totalExtra = extraFeePerNight * nights;
        total += totalExtra;
        addRow("Extra Pax Fee", totalExtra);
      }
    }
    // (Other tabs simplified for brevity. You can re-add your event/villa calculations here)

    const schemePct = parseFloat(
      document.getElementById("payment-scheme").value,
    );
    const amountDue = total * schemePct;

    document.getElementById("summary-breakdown").innerHTML =
      summaryHTML ||
      '<div class="summary-row"><span>No items selected</span></div>';
    document.getElementById("summary-total-val").textContent =
      formatCurrency(total);
    document.getElementById("summary-due-val").textContent =
      formatCurrency(amountDue);
  }

  document.querySelectorAll('select, input[type="number"]').forEach((input) => {
    input.addEventListener("change", calculateSummary);
    input.addEventListener("input", calculateSummary);
  });

  /* ==========================================
       5. SUBMIT BOOKING TO DATABASE
       ========================================== */
    const btnConfirm = document.querySelector(".btn-confirm-walkin");
    
    if (btnConfirm) {
        btnConfirm.addEventListener("click", () => {
            
            // 1. Validate Calendar
            if (!window.isDatesLocked || !calHotel.startDate) {
                alert("Please select dates on the calendar and lock them first!");
                return;
            }

            // 2. Validate Room Selection
            const selectEl = document.getElementById("hotel-room-name");
            if (selectEl.selectedIndex <= 0) {
                alert("Please select a specific room.");
                return;
            }
            const selectedOption = selectEl.options[selectEl.selectedIndex];

            // 3. Format Dates & Money
             const formatLocal = (dateObj) => {
                const y = dateObj.getFullYear();
                const m = String(dateObj.getMonth() + 1).padStart(2, '0');
                const d = String(dateObj.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            };

            const sDate = formatLocal(calHotel.startDate);
            let eDate = calHotel.endDate ? formatLocal(calHotel.endDate) : sDate;
            const totalAmt = document.getElementById("summary-total-val").innerText.replace(/[₱,]/g, "");

            // 4. Determine Payment Scheme Text (MUST BE INSIDE THE CLICK EVENT!)
            const schemeVal = document.getElementById('payment-scheme').value;
            let schemeEnum = '100% Full';
            if (schemeVal === '0.5') schemeEnum = '50% Downpayment';
            if (schemeVal === '0.2') schemeEnum = '20% Reservation';

            // 5. Determine Payment Method & Transaction ID
            const paymentMethod = document.querySelector('input[name="payment-method"]:checked').value;
            const transactionId = document.getElementById('transaction-id').value;

            // 6. Build the FormData
            const formData = new FormData();
            formData.append("guest_name", document.getElementById("guest-name").value);
            formData.append("guest_email", document.getElementById("guest-email").value);
            formData.append("guest_phone", document.getElementById("guest-phone").value);
            
            formData.append("room_type", selectedOption.getAttribute("data-type"));
            formData.append("room_name", selectedOption.getAttribute("data-name"));
            
            formData.append("start_date", sDate);
            formData.append("end_date", eDate);
            formData.append("guests", document.getElementById("hotel-guests").value);
            formData.append("base_amount", selectedOption.value);
            formData.append("total_amount", totalAmt);
            
            formData.append('payment_scheme', schemeEnum);
            formData.append('payment_method', paymentMethod);
            formData.append('transaction_id', transactionId);

            // 7. Send to PHP
            btnConfirm.innerText = "PROCESSING...";
            btnConfirm.disabled = true;

            fetch('actions/bookings/submit_walkin.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(data => {
                const response = data.split('|');
                if (response[0] === 'Success') {
                    alert("Booking Successful! Reference No: " + response[1]);
                    window.location.reload(); 
                } else {
                    alert("Error: " + response[1]);
                    btnConfirm.innerText = "CONFIRM WALK-IN BOOKING";
                    btnConfirm.disabled = false;
                }
            });
        });
    }
}); 