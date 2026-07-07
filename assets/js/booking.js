// ==========================================================================
// SEVILLA360 - Booking Logic (Customer Version)
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

    const timerBox = document.getElementById("timer-box");
    timerBox.classList.remove("running");
    document.getElementById("timer-text").style.display = "inline";
    document.getElementById("countdown-wrapper").style.display = "none";

    const termsCheck = document.getElementById("terms-check");
    if(termsCheck) termsCheck.checked = false;

    if (activeCalendarInstance) {
      activeCalendarInstance.clearSelection();
    }
  }

  const btnCancel = document.getElementById("btn-cancel");
  if(btnCancel) {
      btnCancel.addEventListener("click", () => {
        stopTimerAndReset();
      });
  }

  // ==========================================
  // MODAL TRIGGERS & BACKEND DATE LOCKING
  // ==========================================
  
  window.requestDateConfirmation = function(startDate, endDate, calendarInstance) {
    activeCalendarInstance = calendarInstance;
    const dateModal = document.getElementById("date-confirm-modal");
    const dateTextEl = document.getElementById("selected-date-text");

    const options = { month: "short", day: "numeric", year: "numeric" };
    const startStr = startDate.toLocaleDateString("en-US", options);
    const endStr = endDate ? endDate.toLocaleDateString("en-US", options) : startStr;

    if (dateTextEl) dateTextEl.innerText = `${startStr} — ${endStr}`;
    if (dateModal) dateModal.classList.add("active");

    const oldConfirmBtn = document.getElementById("btn-confirm-date");
    if (!oldConfirmBtn) return;
    const newConfirmBtn = oldConfirmBtn.cloneNode(true);
    oldConfirmBtn.parentNode.replaceChild(newConfirmBtn, oldConfirmBtn);

    const oldCancelBtn = document.getElementById("btn-cancel-date");
    const newCancelBtn = oldCancelBtn.cloneNode(true);
    oldCancelBtn.parentNode.replaceChild(newCancelBtn, oldCancelBtn);

    // WHEN THEY CLICK CONFIRM ON THE MODAL
    newConfirmBtn.addEventListener("click", () => {
        const formatLocal = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        const sDate = formatLocal(calendarInstance.startDate);
        const eDate = calendarInstance.endDate ? formatLocal(calendarInstance.endDate) : sDate;

        // NOTE: We check whichever dropdown is active!
        let roomType = '', roomName = '';
        let activeTabId = '';
        document.querySelectorAll('.tab-btn').forEach(btn => {
            if(btn.classList.contains('active')) activeTabId = btn.getAttribute('data-tab');
        });

        if (activeTabId === 'hotel-rooms') {
            const selectEl = document.getElementById('hotel-room-name');
            if (!selectEl || selectEl.selectedIndex <= 0) {
                alert("Please select a specific room from the dropdown first!");
                return;
            }
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            roomType = selectedOption.getAttribute('data-type');
            roomName = selectedOption.getAttribute('data-name');
        } else if (activeTabId === 'event-hall') {
            const selectEl = document.getElementById('event-venue');
            if (!selectEl || selectEl.selectedIndex <= 0) { alert("Please select a specific hall."); return; }
            roomType = "Event Hall";
            roomName = selectEl.options[selectEl.selectedIndex].text.split('(')[0].trim();
        } else if (activeTabId === 'resort-villa') {
            const selectEl = document.getElementById('villa-type');
            if (!selectEl || selectEl.selectedIndex <= 0) { alert("Please select a specific villa."); return; }
            roomType = "Resort Villa";
            roomName = selectEl.options[selectEl.selectedIndex].text.split('(')[0].trim();
        }

        const formData = new FormData();
        formData.append('room_type', roomType);
        formData.append('room_name', roomName);
        formData.append('start_date', sDate);
        formData.append('end_date', eDate);

        newConfirmBtn.innerText = "Locking...";
        newConfirmBtn.disabled = true;

        // Ask PHP to lock the room in the database!
        fetch('actions/bookings/lock_dates.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(data => {
            newConfirmBtn.innerText = "Confirm";
            newConfirmBtn.disabled = false;

            const response = data.split('|');
            if (response[0] === 'Success') {
                dateModal.classList.remove("active");
                window.isDatesLocked = true;
                
                if (typeof calculateSummary === "function") calculateSummary();
                startTimer(); // Start the 30-minute countdown!
            } else {
                alert("Error: " + response[1]);
                dateModal.classList.remove("active");
                calendarInstance.clearSelection();
            }
        });
    });

    newCancelBtn.addEventListener("click", () => {
      dateModal.classList.remove("active");
      calendarInstance.clearSelection();
    });
  };

  window.showOverrideModal = function(newDate, calendarInstance) {
    const overrideModal = document.getElementById("override-date-modal");
    if(!overrideModal) return;
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
      stopTimerAndReset(); 
      
      if (activeCalendarInstance) activeCalendarInstance.clearSelection();

      activeCalendarInstance = calendarInstance;
      activeCalendarInstance.startDate = newDate;
      activeCalendarInstance.endDate = null;
      activeCalendarInstance.render();
      activeCalendarInstance.updateDateDisplay();
    });

    newNo.addEventListener("click", () => {
      overrideModal.classList.remove("active");
    });
  };

  // --- 3. Initialize Calendars ---
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
      
      if (typeof calculateSummary === "function") calculateSummary();
    });
  });

  // --- 5. Cascading Dropdowns (Hotel Rooms) ---
  const typeSelect = document.getElementById("hotel-room-type");
  const nameSelect = document.getElementById("hotel-room-name");

  if (typeSelect && nameSelect) {
    typeSelect.addEventListener("change", function () {
      if (typeof window.hotelRoomData === "undefined") {
        console.error("CRITICAL ERROR: hotelRoomData is missing from the HTML!");
        return;
      }
      const selectedCategory = this.value;
      const rooms = window.hotelRoomData[selectedCategory];

      if (!rooms) return;

      nameSelect.innerHTML = '<option value="" disabled selected>Select a specific room...</option>';
      rooms.forEach((room) => {
        const option = document.createElement("option");
        option.value = room.base_rate;
        option.setAttribute("data-type", room.room_type);
        option.setAttribute("data-name", room.building_name);
        option.textContent = `${room.building_name} (${room.base_capacity} pax) - ₱${parseInt(room.base_rate).toLocaleString()} [${room.total_units} units]`;
        nameSelect.appendChild(option);
      });
      nameSelect.disabled = false;
    });

    nameSelect.addEventListener('change', () => {
        if (typeof activeCalendar !== 'undefined' && calHotel) calHotel.clearSelection();
        if (typeof calculateSummary === "function") calculateSummary(); 
        
        const selectedOption = nameSelect.options[nameSelect.selectedIndex];
        if(calHotel) calHotel.fetchBookedDates(selectedOption.getAttribute('data-type'), selectedOption.getAttribute('data-name'));
    });
  }

  // Fetch dates for generic dropdowns (Event & Villa)
  document.getElementById('event-venue')?.addEventListener('change', function() {
      if (calEvent) calEvent.fetchBookedDates('Event Hall', this.options[this.selectedIndex].text.split('(')[0].trim());
      if (typeof calculateSummary === "function") calculateSummary();
  });
  document.getElementById('villa-type')?.addEventListener('change', function() {
      if (calVilla) calVilla.fetchBookedDates('Resort Villa', this.options[this.selectedIndex].text.split('(')[0].trim());
      if (typeof calculateSummary === "function") calculateSummary();
  });

  // --- 6. Dynamic Image Changing ---
  const imagesDict = {
    "grand-ballroom": "https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=800",
    "garden-pavilion": "https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?auto=format&fit=crop&w=800",
    "rooftop-terrace": "https://images.unsplash.com/photo-1533174000255-11593130c2c3?auto=format&fit=crop&w=800",
    "deluxe": "https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=800",
    "vip": "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=800",
    "standard": "https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=800",
    "casita": "https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=800",
    "hacienda": "https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=800",
  };

  function updateImage(selectId, imgId) {
    const selectEl = document.getElementById(selectId);
    const imgEl = document.getElementById(imgId);
    if (selectEl && imgEl) {
      selectEl.addEventListener("change", (e) => {
        imgEl.style.opacity = "0";
        setTimeout(() => {
          imgEl.src = imagesDict[e.target.value] || imagesDict[Object.keys(imagesDict)[0]];
          imgEl.style.opacity = "1";
        }, 300);
      });
    }
  }
  updateImage("event-venue", "event-img");
  updateImage("hotel-room-type", "hotel-img"); // Updated to match new ID
  updateImage("villa-type", "villa-img");

  // --- 7. Event Type "Others" Reveal ---
  const eventTypeRadios = document.querySelectorAll('input[name="event-type"]');
  const othersInput = document.getElementById("event-type-others");
  eventTypeRadios.forEach((radio) => {
    radio.addEventListener("change", (e) => {
      if(othersInput) {
          if (e.target.value === "Others") othersInput.classList.remove("hidden");
          else othersInput.classList.add("hidden");
      }
    });
  });

  // --- 8. Resort Villa Stay Type Details Toggle ---
  const villaStayRadios = document.querySelectorAll('input[name="villa-stay"]');
  const ruleDay = document.getElementById("rule-day");
  const ruleNight = document.getElementById("rule-night");
  villaStayRadios.forEach((radio) => {
    radio.addEventListener("change", (e) => {
      if (e.target.value === "2000") { // Check value representing overnight
        if(ruleDay) ruleDay.classList.add("hidden");
        if(ruleNight) ruleNight.classList.remove("hidden");
      } else {
        if(ruleDay) ruleDay.classList.remove("hidden");
        if(ruleNight) ruleNight.classList.add("hidden");
      }
    });
  });

  // --- 9. Toggles & Counters (Add-ons) ---
  function setupToggle(checkboxId, targetId) {
    const checkbox = document.getElementById(checkboxId);
    const target = document.getElementById(targetId);
    if (checkbox && target) {
      checkbox.addEventListener("change", () => {
        if (checkbox.checked) target.classList.remove("hidden");
        else target.classList.add("hidden");
      });
    }
  }
  setupToggle("check-catering", "catering-options");
  setupToggle("check-rooms", "rooms-options");

  document.querySelectorAll(".counter").forEach((counter) => {
    const minusBtn = counter.querySelector(".btn-minus");
    const plusBtn = counter.querySelector(".btn-plus");
    const valSpan = counter.querySelector(".val");

    minusBtn.addEventListener("click", () => {
      let current = parseInt(valSpan.innerText);
      if (current > 0) {
          valSpan.innerText = current - 1;
          if (typeof calculateSummary === "function") calculateSummary(); // Trigger math update!
      }
    });
    plusBtn.addEventListener("click", () => {
      let current = parseInt(valSpan.innerText);
      valSpan.innerText = current + 1;
      if (typeof calculateSummary === "function") calculateSummary(); // Trigger math update!
    });
  });

  // --- 10. UNIFIED SUMMARY GENERATOR ---
  const formatCurrency = (amount) => '₱' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

  function calcExtraPax(guestInputId, baseCapacity, feePerHead, feeLabelId) {
      const guestsInput = document.getElementById(guestInputId);
      if(!guestsInput) return 0;
      
      const guests = parseInt(guestsInput.value) || 0;
      let extraFee = 0;
      const feeLabel = document.getElementById(feeLabelId);
      
      if (guests > baseCapacity) {
          extraFee = (guests - baseCapacity) * feePerHead;
          if(feeLabel) {
              feeLabel.textContent = `Extra Pax Fee: ${formatCurrency(extraFee)}`;
              feeLabel.classList.remove('hidden');
          }
      } else {
          if(feeLabel) feeLabel.classList.add('hidden');
      }
      return extraFee;
  }

  function calculateSummary() {
      let total = 0; let summaryHTML = '';
      const addRow = (label, amount) => { summaryHTML += `<div class="summary-row" style="display:flex; justify-content:space-between; margin-bottom: 5px;"><span>${label}</span><span>${formatCurrency(amount)}</span></div>`; };

      if (!window.isDatesLocked) {
          document.getElementById('summary-breakdown').innerHTML = '<div class="summary-row" style="color:#b5884e;"><i>Please select and lock dates to calculate.</i></div>';
          const totalValEl = document.getElementById('summary-total-val');
          const dueValEl = document.getElementById('summary-due-val');
          if(totalValEl) totalValEl.textContent = "₱0.00";
          if(dueValEl) dueValEl.textContent = "₱0.00";
          return;
      }

      let activeTabId = '';
      document.querySelectorAll('.tab-btn').forEach(btn => {
          if(btn.classList.contains('active')) activeTabId = btn.getAttribute('data-tab');
      });

      // --- HOTEL ROOMS MATH ---
      if (activeTabId === 'hotel-rooms') {
          const nights = (typeof calHotel !== 'undefined' && calHotel) ? calHotel.totalNights : 1;
          const roomEl = document.getElementById('hotel-room-name');
          const roomRate = roomEl && roomEl.value ? parseFloat(roomEl.value) : 0;
          
          if(roomRate > 0) {
              const roomTotal = roomRate * nights;
              total += roomTotal; 
              addRow(`Base Room Rate (x${nights} nights)`, roomTotal);
          }

          const extraFeePerNight = calcExtraPax('hotel-guests', 2, 800, 'hotel-extra-fee');
          if(extraFeePerNight > 0) { 
              const totalExtra = extraFeePerNight * nights; 
              total += totalExtra; 
              addRow('Extra Pax Fee', totalExtra); 
          }
      }
      // --- EVENT HALL MATH ---
      else if (activeTabId === 'event-hall') {
          const daysMultiplier = (typeof calEvent !== 'undefined' && calEvent) ? calEvent.totalNights : 1;
          const venueEl = document.getElementById('event-venue');
          const venue = venueEl && venueEl.value ? parseFloat(venueEl.value) * daysMultiplier : 0;
          const styleEl = document.getElementById('event-style');
          const style = styleEl && styleEl.value ? parseFloat(styleEl.value) * daysMultiplier : 0;
          const typeRadio = document.querySelector('input[name="event-type"]:checked');
          const typeFee = typeRadio && typeRadio.id !== 'event-others-radio' ? parseFloat(typeRadio.value) * daysMultiplier : 0;
          
          total += venue + style + typeFee;
          if(venue > 0) addRow(`Venue Rate (x${daysMultiplier} days)`, venue);
          if(style > 0) addRow('Style Upgrade', style);
          if(typeFee > 0) addRow('Event Setup Fee', typeFee);

          // Add-ons
          if(document.getElementById('check-catering') && document.getElementById('check-catering').checked) {
              const guests = parseInt(document.getElementById('event-guests').value) || 0;
              const tierRadio = document.querySelector('input[name="catering-tier"]:checked');
              const tierPrice = tierRadio ? parseFloat(tierRadio.value) : 0;
              const cateringTotal = tierPrice * guests * daysMultiplier;
              if(cateringTotal > 0) { total += cateringTotal; addRow(`Catering (${guests} pax)`, cateringTotal); }
          }
          if(document.getElementById('check-rooms') && document.getElementById('check-rooms').checked) {
              const dltQty = document.getElementById('qty-deluxe') ? parseInt(document.getElementById('qty-deluxe').textContent) : 0;
              const vipQty = document.getElementById('qty-vip') ? parseInt(document.getElementById('qty-vip').textContent) : 0;
              const roomTotal = ((dltQty * 4500) + (vipQty * 8500)) * daysMultiplier;
              if (roomTotal > 0) { total += roomTotal; addRow(`Reserved Rooms (x${daysMultiplier} nights)`, roomTotal); }
          }
          if (document.getElementById('check-av') && document.getElementById('check-av').checked) {
              total += 5000; addRow('Premium A/V Setup', 5000);
          }
      }
      // --- VILLA MATH ---
      else if (activeTabId === 'resort-villa') {
          const nights = (typeof calVilla !== 'undefined' && calVilla) ? calVilla.totalNights : 1;
          const villaEl = document.getElementById('villa-type');
          const villa = villaEl && villaEl.value ? parseFloat(villaEl.value) * nights : 0;
          const stayRadio = document.querySelector('input[name="villa-stay"]:checked');
          const stayType = stayRadio ? parseFloat(stayRadio.value) * nights : 0;
          
          total += villa + stayType; 
          if(villa > 0) addRow(`Base Villa Rate (x${nights} days)`, villa);
          if(stayType > 0) addRow('Overnight Upgrade', stayType);
          const extraFeePerDay = calcExtraPax('villa-guests', 4, 1000, 'villa-extra-fee');
          if(extraFeePerDay > 0) { const totalExtra = extraFeePerDay * nights; total += totalExtra; addRow('Extra Pax Fee', totalExtra); }
      }

      // --- CALCULATE AMOUNT DUE ---
      let schemePct = 1.0; 
      let activeRadioName = 'hotel-payment';
      if (activeTabId === 'event-hall') activeRadioName = 'payment-scheme';
      if (activeTabId === 'resort-villa') activeRadioName = 'villa-payment';

      const paymentRadios = document.querySelectorAll(`input[name="${activeRadioName}"]`);
      paymentRadios.forEach(radio => {
          if (radio.checked) {
              if (radio.value.includes('50%')) schemePct = 0.5;
              if (radio.value.includes('20%')) schemePct = 0.2;
          }
      });

      const amountDue = total * schemePct;

      document.getElementById('summary-breakdown').innerHTML = summaryHTML || '<div class="summary-row" style="color:#b5884e;"><i>No items selected</i></div>';
      const totalValEl = document.getElementById('summary-total-val');
      const dueValEl = document.getElementById('summary-due-val');
      if(totalValEl) totalValEl.textContent = formatCurrency(total);
      if(dueValEl) dueValEl.textContent = formatCurrency(amountDue);
  }

  // Trigger calculation when any input changes
  document.querySelectorAll('select, input[type="number"], input[type="radio"], input[type="checkbox"]').forEach(input => {
      input.addEventListener('change', calculateSummary); 
      input.addEventListener('input', calculateSummary);
  });
  window.calculateSummary = calculateSummary;

  // --- 11. T&C Modal Logic ---
  const openTerms = document.getElementById("open-terms");
  const tncModal = document.getElementById("tnc-modal");
  const btnAgree = document.getElementById("btn-agree");
  const termsCheck = document.getElementById("terms-check");

  if (openTerms && tncModal) {
    openTerms.addEventListener("click", (e) => { e.preventDefault(); tncModal.classList.add("active"); });
  }
  if (btnAgree) {
    btnAgree.addEventListener("click", () => { tncModal.classList.remove("active"); if(termsCheck) termsCheck.checked = true; });
  }
  window.addEventListener("click", (e) => {
    if (e.target.classList.contains("modal-overlay")) e.target.classList.remove("active");
  });

  // --- 12. Reveal Animations ---
  const reveals = document.querySelectorAll(".reveal");
  const revealObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) { entry.target.classList.add("active"); observer.unobserve(entry.target); }
      });
    }, { root: null, threshold: 0.1 }
  );
  reveals.forEach((reveal) => { revealObserver.observe(reveal); });

  /* ==========================================
       13. SUBMIT ONLINE BOOKING
       ========================================== */
    const btnProceed = document.getElementById('btn-proceed');
    
    if (btnProceed) {
        btnProceed.addEventListener('click', () => {
            
            if (!window.isDatesLocked || (!calHotel.startDate && !calEvent.startDate && !calVilla.startDate)) {
                alert("Please select dates on the calendar and confirm them first!");
                return;
            }

            const termsCheck = document.getElementById('terms-check');
            if (!termsCheck || !termsCheck.checked) {
                alert("Please agree to the Terms & Conditions before proceeding.");
                return;
            }

            let roomType = '', roomName = '', sDate = '', eDate = '', baseAmt = 0, guests = 0;
            const formatLocal = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;

            let activeTabId = '';
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if(btn.classList.contains('active')) activeTabId = btn.getAttribute('data-tab');
            });

            if (activeTabId === 'hotel-rooms') {
                const selectEl = document.getElementById('hotel-room-name');
                if (!selectEl || selectEl.selectedIndex <= 0) { alert("Please select a specific room."); return; }
                const opt = selectEl.options[selectEl.selectedIndex];
                
                roomType = opt.getAttribute('data-type');
                roomName = opt.getAttribute('data-name');
                baseAmt = opt.value;
                guests = document.getElementById('hotel-guests').value;
                sDate = formatLocal(calHotel.startDate);
                eDate = calHotel.endDate ? formatLocal(calHotel.endDate) : sDate;

            } else if (activeTabId === 'event-hall') {
                const selectEl = document.getElementById('event-venue');
                if (!selectEl || selectEl.selectedIndex <= 0) { alert("Please select an Event Hall."); return; }
                const opt = selectEl.options[selectEl.selectedIndex];
                
                roomType = 'Event Hall';
                roomName = opt.text.split('(')[0].trim();
                baseAmt = opt.value;
                guests = document.getElementById('event-guests').value;
                sDate = formatLocal(calEvent.startDate);
                eDate = calEvent.endDate ? formatLocal(calEvent.endDate) : sDate;

            } else if (activeTabId === 'resort-villa') {
                const selectEl = document.getElementById('villa-type');
                if (!selectEl || selectEl.selectedIndex <= 0) { alert("Please select a Villa."); return; }
                const opt = selectEl.options[selectEl.selectedIndex];
                
                roomType = 'Resort Villa';
                roomName = opt.text.split('(')[0].trim();
                baseAmt = opt.value;
                guests = document.getElementById('villa-guests').value;
                sDate = formatLocal(calVilla.startDate);
                eDate = calVilla.endDate ? formatLocal(calVilla.endDate) : sDate;
            }

            const totalAmt = document.getElementById("summary-total-val").innerText.replace(/[₱,]/g, "");
            
            let schemeEnum = '100% Full';
            let activeRadioName = 'hotel-payment';
            if (activeTabId === 'event-hall') activeRadioName = 'payment-scheme';
            if (activeTabId === 'resort-villa') activeRadioName = 'villa-payment';

            document.querySelectorAll(`input[name="${activeRadioName}"]`).forEach(radio => {
                if(radio.checked) schemeEnum = radio.value;
            });

            const formData = new FormData();
            formData.append("room_type", roomType);
            formData.append("room_name", roomName);
            formData.append("start_date", sDate);
            formData.append("end_date", eDate);
            formData.append("guests", guests);
            formData.append("base_amount", baseAmt);
            formData.append("total_amount", totalAmt);
            formData.append('payment_scheme', schemeEnum);

            btnProceed.innerText = "REDIRECTING...";
            btnProceed.disabled = true;

            fetch('actions/bookings/submit_online.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(data => {
                const response = data.split('|');
                if (response[0] === 'Success') {
                    alert("Booking reserved! Redirecting to Dashboard.");
                    window.location.href = "user_dashboard.php"; 
                } else {
                    alert("Error: " + response[1]);
                    btnProceed.innerText = "PROCEED VIA PAYMONGO";
                    btnProceed.disabled = false;
                }
            });
        });
    }
});