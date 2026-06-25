document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementById("maint-calendar-grid")) return;

  // --- 1. Dynamic Venue Data ---
  const venueOptions = {
    "Event Hall": ["Grand Ballroom", "Garden Pavilion", "Rooftop Terrace"],
    "Resort Villa": ["Casita (Standard Villa)", "Hacienda (Family Villa)"],
    "Hotel Room": ["Standard Room", "Deluxe Room", "VIP Suite"],
  };

  const maintTabs = document.querySelectorAll("#maintenance-tabs .tab-btn");
  const specificVenueSelect = document.getElementById("maint-specific-venue");
  const specificVenueLabel = document.getElementById("label-specific-venue");

  const sumMaintCategory = document.getElementById("sum-maint-category");
  const sumMaintUnit = document.getElementById("sum-maint-unit");

  // Function to populate the dropdown based on selected tab
  function populateSpecificVenues(category) {
    specificVenueSelect.innerHTML = ""; // Clear existing options

    // Create default unselected option
    const defaultOpt = document.createElement("option");
    defaultOpt.value = "";
    defaultOpt.disabled = true;
    defaultOpt.selected = true;
    defaultOpt.innerText = `Select ${category}...`;
    specificVenueSelect.appendChild(defaultOpt);

    // Populate actual options
    if (venueOptions[category]) {
      venueOptions[category].forEach((venue) => {
        const opt = document.createElement("option");
        opt.value = venue;
        opt.innerText = venue;
        specificVenueSelect.appendChild(opt);
      });
    }

    // Update the label text
    specificVenueLabel.innerText = `WHICH ${category.toUpperCase()}?`;

    // Reset Summary Unit
    sumMaintUnit.innerText = "--";
  }

  // Initialize with the default active tab (Event Hall)
  populateSpecificVenues("Event Hall");

  // Tab Clicking Logic
  maintTabs.forEach((tab) => {
    tab.addEventListener("click", (e) => {
      maintTabs.forEach((t) => t.classList.remove("active"));
      e.target.classList.add("active");

      const categoryName = e.target.getAttribute("data-venue");

      // Update Summary Mirror
      sumMaintCategory.innerText = categoryName;

      // Update Dropdown
      populateSpecificVenues(categoryName);

      // Reset Calendar selection when category changes
      maintCalendar.resetSelection();
    });
  });

  // --- 2. Form Inputs Mirroring ---
  const inputArea = document.getElementById("maint-area");
  const selectType = document.getElementById("maint-type");
  const toggleBlock = document.getElementById("maint-block");

  const sumArea = document.getElementById("sum-maint-area");
  const sumType = document.getElementById("sum-maint-type");
  const sumBlock = document.getElementById("sum-maint-block");

  // Listen to the new Specific Venue dropdown
  specificVenueSelect.addEventListener("change", (e) => {
    sumMaintUnit.innerText = e.target.value;
  });

  inputArea.addEventListener("input", (e) => {
    sumArea.innerText = e.target.value.trim() !== "" ? e.target.value : "--";
  });

  selectType.addEventListener("change", (e) => {
    sumType.innerText = e.target.value;
  });

  toggleBlock.addEventListener("change", (e) => {
    if (e.target.checked) {
      sumBlock.innerText = "ON";
      sumBlock.style.color = "#e06666"; // Soft red
    } else {
      sumBlock.innerText = "OFF";
      sumBlock.style.color = "#888"; // Inactive grey
    }
  });

  // --- 3. Maintenance Calendar System ---
  class MaintenanceCalendar {
    constructor() {
      this.grid = document.getElementById("maint-calendar-grid");
      this.monthYearDisplay = document.getElementById("maint-month-year");
      this.prevBtn = document.getElementById("maint-prev-month");
      this.nextBtn = document.getElementById("maint-next-month");

      this.currentDate = new Date();
      this.currentDate.setDate(1);

      this.startDate = null;
      this.endDate = null;

      // Mock Data: Pre-booked dates
      this.bookedDays = [5, 6, 18, 19];

      this.init();
    }

    init() {
      this.render();
      this.prevBtn.addEventListener("click", () => {
        this.currentDate.setMonth(this.currentDate.getMonth() - 1);
        this.render();
      });
      this.nextBtn.addEventListener("click", () => {
        this.currentDate.setMonth(this.currentDate.getMonth() + 1);
        this.render();
      });
    }

    resetSelection() {
      this.startDate = null;
      this.endDate = null;
      this.updateSummary();
      this.render();
    }

    updateSummary() {
      const sumDate = document.getElementById("sum-maint-date");
      const sumDuration = document.getElementById("sum-maint-duration");
      const options = { month: "short", day: "numeric" };

      if (!this.startDate) {
        sumDate.innerText = "--";
        sumDuration.innerText = "--";
        return;
      }

      const startStr = this.startDate.toLocaleDateString("en-US", options);

      if (this.startDate && !this.endDate) {
        sumDate.innerText = startStr;
        sumDuration.innerText = "1 day";
      } else if (this.startDate && this.endDate) {
        const endStr = this.endDate.toLocaleDateString("en-US", options);
        sumDate.innerText = `${startStr} - ${endStr}`;

        const diffTime = Math.abs(this.endDate - this.startDate);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        sumDuration.innerText = `${diffDays} days`;
      }
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

        if (this.bookedDays.includes(day)) {
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
            if (this.startDate && this.endDate) {
              this.startDate = cellDate;
              this.endDate = null;
            } else if (!this.startDate) {
              this.startDate = cellDate;
            } else if (this.startDate && !this.endDate) {
              if (cellDate < this.startDate) {
                this.startDate = cellDate;
              } else {
                this.endDate = cellDate;
              }
            }
            this.updateSummary();
            this.render();
          });
        }
        this.grid.appendChild(cell);
      }
    }
  }

  const maintCalendar = new MaintenanceCalendar();

  // --- 4. Clear Form & Submit Buttons ---
  document.getElementById("btn-clear-maint").addEventListener("click", () => {
    // Reset Form Fields
    specificVenueSelect.selectedIndex = 0;
    inputArea.value = "";
    selectType.selectedIndex = 0;
    document.getElementById("maint-notes").value = "";
    toggleBlock.checked = false;

    // Reset Calendar
    maintCalendar.resetSelection();

    // Reset Summary Text
    sumMaintUnit.innerText = "--";
    inputArea.dispatchEvent(new Event("input"));
    toggleBlock.dispatchEvent(new Event("change"));
    sumType.innerText = "--";
  });

  document
    .getElementById("btn-schedule-maint")
    .addEventListener("click", () => {
      // Simple validation
      if (!specificVenueSelect.value) {
        alert("Please select a specific Unit/Venue first.");
        return;
      }
      if (!maintCalendar.startDate) {
        alert("Please select dates from the Availability Calendar.");
        return;
      }
      if (!selectType.value) {
        alert("Please select a Maintenance Type.");
        return;
      }

      alert(
        `Maintenance successfully scheduled for ${sumMaintUnit.innerText}!`,
      );
      document.getElementById("btn-clear-maint").click();
    });
});
