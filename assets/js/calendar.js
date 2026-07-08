class SevillaCalendar {
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
    if (!room_type || !room_name) return;
    try {
      const response = await fetch(
        `/Sevilla360/actions/bookings/fetch_dates.php?room_type=${encodeURIComponent(room_type)}&room_name=${encodeURIComponent(room_name)}`,
      );
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
        if (this.startDate && cellDate.getTime() === this.startDate.getTime()) {
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
            window.showOverrideModal(cellDate, this);
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
                window.requestDateConfirmation(
                  this.startDate,
                  this.endDate,
                  this,
                );
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
    if (typeof calculateSummary === "function") calculateSummary();
  }

  clearSelection() {
    this.startDate = null;
    this.endDate = null;
    this.render();
    this.updateDateDisplay();
  }
}
