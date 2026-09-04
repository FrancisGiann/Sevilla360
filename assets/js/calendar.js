function normalizeCalendarDate(value) {
  if (typeof value === 'string') {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!match) return null;

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const localDate = new Date(year, month - 1, day);
    if (localDate.getFullYear() !== year || localDate.getMonth() !== month - 1 || localDate.getDate() !== day) return null;
    localDate.setHours(0, 0, 0, 0);
    return localDate;
  }

  if (!(value instanceof Date) || Number.isNaN(value.getTime())) return null;
  const localDate = new Date(value.getTime());
  localDate.setHours(0, 0, 0, 0);
  return localDate;
}

class SevillaCalendar {
  constructor(containerId, options = {}) {
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
    this.fixedDurationNights = null;
    this.fixedDurationGuard = options.fixedDurationGuard === true;
    this.requireHotelRules = options.requireHotelRules === true;
    this.allowSelectionWhilePrimaryLocked = options.allowSelectionWhilePrimaryLocked === true;
    this.onRangeSelected = typeof options.onRangeSelected === 'function' ? options.onRangeSelected : null;

    this.bookedDatesList = [];
    this.hardBlockedDatesList = [];
    this.maintenanceDatesList = []; // Array of objects {date, type}
    this.isMaintenanceMode = false;
    this.availabilityRequestGeneration = 0;

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

  async fetchMaintenanceCalendarData(venue_id) {
    if (!venue_id) return;
    this.isMaintenanceMode = true;
    try {
      const formData = new FormData();
      formData.append('venue_id', venue_id);

      const response = await fetch('actions/admin/fetch_maintenance_calendar.php', {
          method: 'POST',
          body: formData
      });
      
      const data = await response.json();
      
      // Convert booked_dates array of objects into simple array of date strings for easy checking
      this.bookedDatesList = (data.booked_dates || []).map(b => b.date);
      // Store raw booked objects for tooltips
      this.bookedObjectsList = data.booked_dates || [];
      
      this.maintenanceDatesList = data.maintenance_dates || [];
      
      // Also add blocking maintenance dates to bookedDatesList so drag-selection is prevented
      this.maintenanceDatesList.forEach(m => {
          if (m.is_blocking && !this.bookedDatesList.includes(m.date)) {
              this.bookedDatesList.push(m.date);
          }
      });
      
      const legendMaint = this.container.querySelector('#cal-legend-maint');
      if (legendMaint) legendMaint.style.display = 'inline-block';
      
      this.render();
    } catch (error) {
      console.error("Error fetching maintenance calendar:", error);
    }
  }

  async fetchBookedDates(room_type, room_name, venue_id = null, requireHotelRules = false) {
    if (!room_type && !room_name && !venue_id) return;
    const requestGeneration = ++this.availabilityRequestGeneration;
    this.isMaintenanceMode = false;
    
    // Auto-detect hotel mode from strings if passed, or explicit flag
    if (requireHotelRules || room_type === 'Hotel Room' || (room_type && room_type !== 'Event Hall' && room_type !== 'Resort Villa')) {
        this.requireHotelRules = true;
    } else {
        this.requireHotelRules = false;
    }

    const previousBookedDates = [...this.bookedDatesList];
    const previousHardBlockedDates = [...this.hardBlockedDatesList];
    try {
      const formData = new FormData();
      if (room_type) formData.append('room_type', room_type);
      if (room_name) formData.append('room_name', room_name);
      if (venue_id) formData.append('venue_id', venue_id);

      const response = await fetch('actions/bookings/fetch_dates.php', {
          method: 'POST',
          body: formData
      });

      const data = await response.json();
      if (!response.ok || !data.success || !Array.isArray(data.booked_dates)) {
          throw new Error(data.message || 'Availability could not be loaded.');
      }
      if (requestGeneration !== this.availabilityRequestGeneration) return;
      this.bookedDatesList = data.booked_dates;
      this.hardBlockedDatesList = Array.isArray(data.hard_blocked_dates) ? data.hard_blocked_dates : [];
      this.render();
    } catch (error) {
      if (requestGeneration !== this.availabilityRequestGeneration) return;
      console.error("Error fetching dates:", error);
      // Never turn a failed request into an apparently empty calendar.
      this.bookedDatesList = previousBookedDates;
      this.hardBlockedDatesList = previousHardBlockedDates;
      this.render();
      if (typeof showAlert === 'function') {
          showAlert('Availability Error', error.message || 'Availability could not be loaded. Please try again.', 'error');
      }
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

  isDateUnavailable(date) {
    const dateStr = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
    if (this.bookedDatesList.includes(dateStr) || this.hardBlockedDatesList.includes(dateStr)) return true;
    if (this.isMaintenanceMode) {
      const maintenance = this.maintenanceDatesList.find(item => item.date === dateStr);
      if (maintenance?.is_blocking) return true;
    }
    return false;
  }

  setFixedDurationSelection(startDate) {
    const endDate = new Date(startDate);
    endDate.setDate(endDate.getDate() + this.fixedDurationNights);
    if (this.fixedDurationGuard) {
      const cursor = new Date(startDate);
      while (cursor <= endDate) {
        if (this.isDateUnavailable(cursor)) return null;
        cursor.setDate(cursor.getDate() + 1);
      }
    }
    return endDate;
  }

  render() {
    this.grid.innerHTML = "";
    const year = this.currentDate.getFullYear();
    const month = this.currentDate.getMonth();
    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

    this.monthYearDisplay.innerText = `${monthNames[month]} ${year}`;

    const firstDayIndex = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    // Get today's exact date at midnight for accurate date comparison
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let i = 0; i < firstDayIndex; i++) {
      const emptyCell = document.createElement("div");
      emptyCell.className = "cal-day-cell empty";
      this.grid.appendChild(emptyCell);
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const cellDate = new Date(year, month, day);
      const cellDateStr = `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;

      // Check the availability state before choosing an element type. Future
      // available dates are real buttons so keyboard users receive the same
      // Enter/Space activation as pointer users; past and unavailable dates
      // remain non-interactive cells.
      const isPastDate = cellDate < today;
      const maintObj = this.isMaintenanceMode ? this.maintenanceDatesList.find(m => m.date === cellDateStr) : null;
      const isMaintenance = !!maintObj;
      const isBooked = this.bookedDatesList.includes(cellDateStr);
      const isHardBlocked = this.hardBlockedDatesList.includes(cellDateStr);
      const checkoutBoundary = this.requireHotelRules && this.startDate && !this.endDate && isBooked && cellDate > this.startDate && !this.hasInvalidDaysBetween(this.startDate, cellDate);
      const isInteractive = !isPastDate && !isMaintenance && !isHardBlocked && (!isBooked || checkoutBoundary);
      const cell = document.createElement(isInteractive ? "button" : "div");
      cell.className = "cal-day-cell";
      cell.innerText = day;
      if (isInteractive) {
        cell.type = 'button';
        cell.setAttribute('aria-label', `${cellDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}${checkoutBoundary ? ', available checkout date' : ''}`);
      }

      if (isPastDate) {
        // Past dates are always grey — they're unavailable regardless of booking status.
        cell.classList.add("past-date");
      } 
      else if (isMaintenance || isHardBlocked) {
        cell.classList.add("maintenance");
        cell.title = maintObj ? `Maintenance: ${maintObj.type}` : 'Unavailable due to maintenance';
        // If maintenance is not blocking, we theoretically could book, but the standard behavior is blocks the cell visually.
        if (isHardBlocked || maintObj?.is_blocking) cell.classList.add("booked");
      }
      else if (isBooked) {
        cell.classList.add("booked");
        if (this.isMaintenanceMode) {
            const bObj = this.bookedObjectsList.find(b => b.date === cellDateStr);
            if (bObj) cell.title = `Booked (${bObj.ref_no} - ${bObj.status})`;
        }
        if (checkoutBoundary) {
          cell.classList.add('checkout-boundary');
          cell.title = 'Available as a hotel checkout date';
          cell.addEventListener('click', () => {
            this.endDate = cellDate;
            this.render();
            this.notifyRangeSelected();
          });
        }
      } 
      else {
        // IF IT IS A VALID, FUTURE DATE:
        if (this.startDate && cellDate.getTime() === this.startDate.getTime()) {
          cell.classList.add("selected", "start-date");
        }
        if (this.endDate && cellDate.getTime() === this.endDate.getTime()) {
          cell.classList.add("selected", "end-date");
        }
        if (this.startDate && this.endDate && cellDate > this.startDate && cellDate < this.endDate) {
          cell.classList.add("in-range");
        }

        // Attach click listener ONLY for valid future dates
        cell.addEventListener("click", () => {
          if (window.isDatesLocked && !this.allowSelectionWhilePrimaryLocked && typeof window.showOverrideModal === "function") {
            window.showOverrideModal(cellDate, this);
            return;
          }
          
          if (this.startDate && this.endDate) {
            this.startDate = cellDate;
            this.endDate = null;
            if (this.fixedDurationNights !== null) {
              this.endDate = this.setFixedDurationSelection(this.startDate);
              if (!this.endDate) {
                this.startDate = null;
                showAlert("Notice", "The next checkout date is unavailable. Please choose another Villa date.", "info");
              }
            }
            this.render();
            if (this.fixedDurationNights !== null && this.endDate) this.notifyRangeSelected();
          } else if (!this.startDate) {
            this.startDate = cellDate;
            if (this.fixedDurationNights !== null) {
              this.endDate = this.setFixedDurationSelection(this.startDate);
              if (!this.endDate) {
                this.startDate = null;
                showAlert("Notice", "The next checkout date is unavailable. Please choose another Villa date.", "info");
              }
              this.render();
              if (this.endDate) this.notifyRangeSelected();
            } else {
              this.render();
            }
          } else if (this.startDate && !this.endDate) {
            if (cellDate < this.startDate) {
              this.startDate = cellDate;
              this.render();
            } else if (this.requireHotelRules && cellDate.getTime() === this.startDate.getTime()) {
              showAlert("Notice", "You've selected the same check-in and check-out date. Hotel room bookings require a minimum of 1 night — please select a check-out date that is at least 1 day after your check-in.", "info");
            } else {
              if (this.hasInvalidDaysBetween(this.startDate, cellDate)) {
                showAlert("Notice", "Selection contains unavailable or booked dates.");
                this.startDate = cellDate;
                this.render();
              } else {
                this.endDate = cellDate;
                this.render();
                
                this.notifyRangeSelected();
              }
            }
          }
        });
      }
      this.grid.appendChild(cell);
    }
  }

  updateDateDisplay() {
    // 1. Try to find the single Admin summary date span
    const adminDateDisplay = document.getElementById("summary-dates");
    
    // 2. Try to find the multiple User summary date spans
    const userDateDisplays = document.querySelectorAll(".sum-dates-display");

    let displayStr = "Please select dates";

    if (!this.startDate) {
      this.totalNights = 1;
    } else {
      const opts = { month: "short", day: "numeric", year: "numeric" };
      const startStr = this.startDate.toLocaleDateString("en-US", opts);

      if (this.endDate && this.startDate.getTime() !== this.endDate.getTime()) {
        const endStr = this.endDate.toLocaleDateString("en-US", opts);
        displayStr = `${startStr} — ${endStr}`;
        const diffTime = Math.abs(this.endDate - this.startDate);
        this.totalNights = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
      } else {
        displayStr = startStr;
        this.totalNights = 1;
      }
    }

    // Update whichever elements actually exist on the page!
    if (adminDateDisplay) adminDateDisplay.innerText = displayStr;
    userDateDisplays.forEach(el => el.innerText = displayStr);
    
    // Tell the page to recalculate the money based on the new totalNights!
    if (typeof window.calculateSummary === "function") {
        window.calculateSummary();
    }
  }

  notifyRangeSelected() {
    if (this.onRangeSelected) this.onRangeSelected(this.startDate, this.endDate, this);
    else if (typeof window.requestDateConfirmation === 'function') window.requestDateConfirmation(this.startDate, this.endDate, this);
  }

  setSelection(startDate, endDate) {
    const normalizedStart = normalizeCalendarDate(startDate);
    this.startDate = normalizedStart;
    this.endDate = normalizedStart ? normalizeCalendarDate(endDate) : null;
    if (this.startDate) {
      this.currentDate = new Date(this.startDate.getFullYear(), this.startDate.getMonth(), 1);
    }
    this.render();
  }

  clearSelectedRange() {
    this.startDate = null;
    this.endDate = null;
    this.render();
    this.updateDateDisplay();
  }

  clearSelection() {
    // A venue/context reset invalidates any availability response still in
    // flight. Its result must not overwrite the new empty state or alert the
    // customer after they have moved on.
    this.availabilityRequestGeneration++;
    this.startDate = null;
    this.endDate = null;
    this.bookedDatesList = [];
    this.hardBlockedDatesList = [];
    this.bookedObjectsList = [];
    this.maintenanceDatesList = [];
    this.render();
    this.updateDateDisplay();
  }
}
