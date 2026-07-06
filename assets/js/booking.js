// ==========================================================================
// SEVILLA360 - Booking Logic
// ==========================================================================

document.addEventListener("DOMContentLoaded", () => {
  // --- 1. Mobile Navbar Hamburger ---
  const hamburger = document.getElementById("hamburger");
  const navLinks = document.getElementById("nav-links");

  if (hamburger) {
    hamburger.addEventListener("click", () => {
      hamburger.classList.toggle("active");
      navLinks.classList.toggle("active");
      document.body.style.overflow = navLinks.classList.contains("active")
        ? "hidden"
        : "auto";
    });
  }

  // --- 2. Global Timer & Cancel Logic ---
  let timerInterval;
  let timeLimit = 30 * 60; // 30 minutes
  let timerStarted = false;
  let activeCalendarInstance = null;

  function startTimer() {
    if (timerStarted) return;
    timerStarted = true;

    const timerBox = document.getElementById("timer-box");
    const timerText = document.getElementById("timer-text");
    const countdownWrapper = document.getElementById("countdown-wrapper");
    const countdownEl = document.getElementById("countdown");

    timerBox.classList.add("running");
    timerText.style.display = "none";
    countdownWrapper.style.display = "inline";

    timerInterval = setInterval(() => {
      const minutes = Math.floor(timeLimit / 60);
      let seconds = timeLimit % 60;
      seconds = seconds < 10 ? "0" + seconds : seconds;
      countdownEl.innerText = `${minutes}:${seconds}`;

      if (timeLimit <= 0) {
        stopTimerAndReset();
        alert(
          "Your session has expired. Please refresh the page to restart your booking.",
        );
        const proceedBtn = document.getElementById("btn-proceed");
        proceedBtn.disabled = true;
        proceedBtn.style.opacity = "0.5";
        proceedBtn.style.cursor = "not-allowed";
      }
      timeLimit--;
    }, 1000);
  }

  function stopTimerAndReset() {
    clearInterval(timerInterval);
    timerStarted = false;
    timeLimit = 30 * 60;

    // Reset UI
    const timerBox = document.getElementById("timer-box");
    timerBox.classList.remove("running");
    document.getElementById("timer-text").style.display = "inline";
    document.getElementById("countdown-wrapper").style.display = "none";

    // Reset Terms checkbox
    document.getElementById("terms-check").checked = false;

    // Clear Calendar selection
    if (activeCalendarInstance) {
      activeCalendarInstance.resetSelection();
    }

    // Reset Text in Summary
    document
      .querySelectorAll(".sum-dates-display")
      .forEach((el) => (el.innerText = "--"));
  }

  // Bind Sidebar Cancel Button
  document.getElementById("btn-cancel").addEventListener("click", () => {
    stopTimerAndReset();
  });

  // Modal Date Confirmation Handler
  function requestDateConfirmation(startDate, endDate, calendarInstance) {
    const dateModal = document.getElementById("date-confirm-modal");
    const dateTextEl = document.getElementById("selected-date-text");

    const options = { month: "short", day: "numeric", year: "numeric" };
    const startStr = startDate.toLocaleDateString("en-US", options);

    let displayStr = startStr;
    if (endDate && startDate.getTime() !== endDate.getTime()) {
      const endStr = endDate.toLocaleDateString("en-US", options);
      displayStr = `${startStr} — ${endStr}`;
    }

    dateTextEl.innerText = displayStr;
    dateModal.classList.add("active");

    const oldConfirmBtn = document.getElementById("btn-confirm-date");
    const newConfirmBtn = oldConfirmBtn.cloneNode(true);
    oldConfirmBtn.parentNode.replaceChild(newConfirmBtn, oldConfirmBtn);

    const oldCancelBtn = document.getElementById("btn-cancel-date");
    const newCancelBtn = oldCancelBtn.cloneNode(true);
    oldCancelBtn.parentNode.replaceChild(newCancelBtn, oldCancelBtn);

    newConfirmBtn.addEventListener("click", () => {
      dateModal.classList.remove("active");
      activeCalendarInstance = calendarInstance;

      document
        .querySelectorAll(".sum-dates-display")
        .forEach((el) => (el.innerText = displayStr));
      startTimer();
    });

    newCancelBtn.addEventListener("click", () => {
      dateModal.classList.remove("active");
      calendarInstance.resetSelection();
    });
  }

  // --- 3. Advanced Airbnb-Style Calendar System ---
  
  const calEvent = new SevillaCalendar("cal-ui-event");
  const calHotel = new SevillaCalendar("cal-ui-hotel");
  const calVilla = new SevillaCalendar("cal-ui-villa");

  // --- 4. Tab Switching Logic ---
  const tabBtns = document.querySelectorAll(".tab-btn");
  const tabContents = document.querySelectorAll(".tab-content");
  const summaryContainers = document.querySelectorAll(".summary-container");

  tabBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      tabBtns.forEach((b) => b.classList.remove("active"));
      tabContents.forEach((c) => c.classList.remove("active"));
      summaryContainers.forEach((s) => s.classList.remove("active"));

      btn.classList.add("active");
      const target = btn.getAttribute("data-tab");
      document.getElementById(`tab-${target}`).classList.add("active");
      document.getElementById(`sum-${target}`).classList.add("active");
    });
  });

  // --- 5. Cascading Dropdowns (Event Type) ---
  const typeSelect = document.getElementById("hotel-room-type");
  const nameSelect = document.getElementById("hotel-room-name");

  if (typeSelect && nameSelect) {
    // Listen for the Category change
    typeSelect.addEventListener("change", function () {
      
      // We use window.hotelRoomData to ensure it looks globally at the HTML script
      if (typeof window.hotelRoomData === "undefined") {
        console.error("CRITICAL ERROR: hotelRoomData is missing from the HTML!");
        return;
      }

      const selectedCategory = this.value;
      const rooms = window.hotelRoomData[selectedCategory];

      if (!rooms) {
        console.error("Could not find rooms for category:", selectedCategory);
        return;
      }

      // 1. Clear the second dropdown
      nameSelect.innerHTML = '<option value="" disabled selected>Select a specific room...</option>';

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

    // When the Specific Room changes... fetch the dates for the calendar!
    nameSelect.addEventListener('change', () => {
        
        // Ensure activeCalendar is set to the hotel calendar!
        if (typeof activeCalendar !== 'undefined' && calHotel) {
             calHotel.clearSelection();
        }
        
        if (typeof calculateSummary === "function") {
             calculateSummary(); 
        }
        
        const selectedOption = nameSelect.options[nameSelect.selectedIndex];
        const type = selectedOption.getAttribute('data-type');
        const name = selectedOption.getAttribute('data-name');
        
        if (calHotel) calHotel.fetchBookedDates(type, name);
    });
  }

  // --- 5. Dynamic Image Changing ---
  const imagesDict = {
    "grand-ballroom":
      "https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=800",
    "garden-pavilion":
      "https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?auto=format&fit=crop&w=800",
    "rooftop-terrace":
      "https://images.unsplash.com/photo-1533174000255-11593130c2c3?auto=format&fit=crop&w=800",
    deluxe:
      "https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=800",
    vip: "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=800",
    standard:
      "https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=800",
    casita:
      "https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=800",
    hacienda:
      "https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=800",
  };

  function updateImage(selectId, imgId) {
    const selectEl = document.getElementById(selectId);
    const imgEl = document.getElementById(imgId);
    if (selectEl && imgEl) {
      selectEl.addEventListener("change", (e) => {
        imgEl.style.opacity = "0";
        setTimeout(() => {
          imgEl.src =
            imagesDict[e.target.value] ||
            imagesDict[Object.keys(imagesDict)[0]];
          imgEl.style.opacity = "1";
        }, 300);
      });
    }
  }
  updateImage("event-venue", "event-img");
  updateImage("hotel-type", "hotel-img");
  updateImage("villa-type", "villa-img");

  // --- 6. Event Type "Others" Input Reveal ---
  const eventTypeRadios = document.querySelectorAll('input[name="event-type"]');
  const othersInput = document.getElementById("event-type-others");
  const sumEvType = document.getElementById("sum-ev-type");

  eventTypeRadios.forEach((radio) => {
    radio.addEventListener("change", (e) => {
      if (e.target.value === "Others") {
        othersInput.classList.remove("hidden");
        sumEvType.innerText = othersInput.value
          ? othersInput.value
          : "Custom Event";
      } else {
        othersInput.classList.add("hidden");
        sumEvType.innerText = e.target.value;
      }
    });
  });

  if (othersInput) {
    othersInput.addEventListener("input", (e) => {
      sumEvType.innerText = e.target.value ? e.target.value : "Custom Event";
    });
  }

  // --- 7. Resort Villa Stay Type Details Toggle ---
  const villaStayRadios = document.querySelectorAll('input[name="villa-stay"]');
  const ruleDay = document.getElementById("rule-day");
  const ruleNight = document.getElementById("rule-night");

  villaStayRadios.forEach((radio) => {
    radio.addEventListener("change", (e) => {
      if (e.target.value === "Overnight") {
        ruleDay.classList.add("hidden");
        ruleNight.classList.remove("hidden");
      } else {
        ruleDay.classList.remove("hidden");
        ruleNight.classList.add("hidden");
      }
    });
  });

  // --- 8. Extra Pax Calculators ---
  function enforceConstraintsAndCalculate(
    inputId,
    base,
    max,
    feeRate,
    uiSpanId,
    summarySpanId,
    guestsSumId,
  ) {
    const inputEl = document.getElementById(inputId);
    const uiSpan = document.getElementById(uiSpanId);
    const summarySpan = document.getElementById(summarySpanId);
    const guestsSum = document.getElementById(guestsSumId);

    if (!inputEl) return;

    inputEl.addEventListener("input", () => {
      let val = parseInt(inputEl.value);

      if (val > max) {
        inputEl.value = max;
        val = max;
      } else if (val < 1 || isNaN(val)) {
        uiSpan.innerText = "";
        summarySpan.innerText = "₱0";
        if (guestsSum) guestsSum.innerText = "--";
        return;
      }

      if (guestsSum) guestsSum.innerText = val;

      if (val > base) {
        const extraCharge = (val - base) * feeRate;
        uiSpan.innerText = `(Total Extra: ₱${extraCharge.toLocaleString()})`;
        summarySpan.innerText = `₱${extraCharge.toLocaleString()}`;
      } else {
        uiSpan.innerText = "";
        summarySpan.innerText = "₱0";
      }
    });
  }

  enforceConstraintsAndCalculate(
    "hotel-guests",
    2,
    4,
    800,
    "hotel-extra-fee",
    "sum-ht-fee",
    "sum-ht-guests",
  );
  enforceConstraintsAndCalculate(
    "villa-guests",
    4,
    8,
    1000,
    "villa-extra-fee",
    "sum-vl-fee",
    "sum-vl-guests",
  );

  // --- 9. Toggles & Counters (Add-ons) ---
  function setupToggle(checkboxId, targetId) {
    const checkbox = document.getElementById(checkboxId);
    const target = document.getElementById(targetId);
    if (checkbox && target) {
      checkbox.addEventListener("change", () => {
        if (checkbox.checked) {
          target.classList.remove("hidden");
        } else {
          target.classList.add("hidden");
        }
      });
    }
  }
  setupToggle("check-catering", "catering-options");
  setupToggle("check-rooms", "rooms-options");

  // Summary Add ons
  function updateEventAddonsSummary() {
    const catering = document.getElementById("check-catering").checked
      ? "Catering"
      : "";
    const rooms = document.getElementById("check-rooms").checked ? "Rooms" : "";
    const av = document.getElementById("check-av").checked ? "A/V Setup" : "";

    const addonsArray = [catering, rooms, av].filter(Boolean);
    document.getElementById("sum-ev-addons").innerText =
      addonsArray.length > 0 ? addonsArray.join(", ") : "None";
  }

  document
    .getElementById("check-catering")
    .addEventListener("change", updateEventAddonsSummary);
  document
    .getElementById("check-rooms")
    .addEventListener("change", updateEventAddonsSummary);
  document
    .getElementById("check-av")
    .addEventListener("change", updateEventAddonsSummary);

  document.querySelectorAll(".counter").forEach((counter) => {
    const minusBtn = counter.querySelector(".btn-minus");
    const plusBtn = counter.querySelector(".btn-plus");
    const valSpan = counter.querySelector(".val");

    minusBtn.addEventListener("click", () => {
      let current = parseInt(valSpan.innerText);
      if (current > 0) valSpan.innerText = current - 1;
    });

    plusBtn.addEventListener("click", () => {
      let current = parseInt(valSpan.innerText);
      valSpan.innerText = current + 1;
    });
  });

  // --- 10. Summary Syncs ---
  const updateText = (sourceId, targetId, isSelect = false) => {
    const source = document.getElementById(sourceId);
    const target = document.getElementById(targetId);
    if (source && target) {
      const eventType = isSelect ? "change" : "input";
      source.addEventListener(eventType, () => {
        target.innerText = isSelect
          ? source.options[source.selectedIndex].text
          : source.value;
      });
    }
  };

  updateText("event-venue", "sum-ev-venue", true);
  updateText("event-guests", "sum-ev-guests", false);

  document.querySelectorAll('input[name="payment-scheme"]').forEach((radio) => {
    radio.addEventListener("change", (e) => {
      document.getElementById("sum-ev-payment").innerText = e.target.value;
    });
  });
  document.querySelectorAll('input[name="hotel-payment"]').forEach((radio) => {
    radio.addEventListener("change", (e) => {
      document.getElementById("sum-ht-payment").innerText = e.target.value;
    });
  });
  document.querySelectorAll('input[name="villa-payment"]').forEach((radio) => {
    radio.addEventListener("change", (e) => {
      document.getElementById("sum-vl-payment").innerText = e.target.value;
    });
  }); 

  updateText("hotel-type", "sum-ht-type", true);
  updateText("villa-type", "sum-vl-type", true);

  document.querySelectorAll('input[name="villa-stay"]').forEach((radio) => {
    radio.addEventListener("change", (e) => {
      document.getElementById("sum-vl-stay").innerText = e.target.value;
    });
  });

  // --- 11. T&C Modal Logic ---
  const openTerms = document.getElementById("open-terms");
  const tncModal = document.getElementById("tnc-modal");
  const btnAgree = document.getElementById("btn-agree");
  const termsCheck = document.getElementById("terms-check");

  if (openTerms && tncModal) {
    openTerms.addEventListener("click", (e) => {
      e.preventDefault();
      tncModal.classList.add("active");
    });
  }

  if (btnAgree) {
    btnAgree.addEventListener("click", () => {
      tncModal.classList.remove("active");
      termsCheck.checked = true;
    });
  }

  window.addEventListener("click", (e) => {
    if (e.target.classList.contains("modal-overlay")) {
      e.target.classList.remove("active");
    }
  });

  // --- 12. Reveal Animations (Fix for Footer) ---
  const reveals = document.querySelectorAll(".reveal");
  const revealObserver = new IntersectionObserver(
    (entries, observer) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("active");
          observer.unobserve(entry.target);
        }
      });
    },
    {
      root: null,
      threshold: 0.1,
    },
  );

  reveals.forEach((reveal) => {
    revealObserver.observe(reveal);
  });
});
