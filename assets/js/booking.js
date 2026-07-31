/**
 * ==========================================================================
 * SEVILLA360 - Booking Controller (Refactored)
 * ==========================================================================
 */

class BookingController {
    constructor() {
        this.state = {
            activeTabId: 'event-hall', 
            isDatesLocked: false,
            activeCalendar: null,
            timerInterval: null,
            timeLimit: 1800, 
            summary: { total: 0, amountDue: 0, html: '' },
            calendars: {} 
        };

        window.requestDateConfirmation = this.requestDateConfirmation.bind(this);
        window.showOverrideModal = this.showOverrideModal.bind(this);
        window.calculateSummary = this.calculateSummary.bind(this);

        this.imageMap = {
            "grand-ballroom": "https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=800",
            "garden-pavilion": "https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?auto=format&fit=crop&w=800",
            "rooftop-terrace": "https://images.unsplash.com/photo-1533174000255-11593130c2c3?auto=format&fit=crop&w=800",
            "deluxe": "https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=800",
            "vip": "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=800",
            "standard": "https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=800",
            "casita": "https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=800",
            "hacienda": "https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=800"
        };

        this.init();
    }

    init() {
        this.initCalendars();
        this.bindNavigationAndTabs();
        this.bindUIInteractions();
        this.bindCalculatorTriggers();
        this.bindModalsAndSubmission();
        this.bindUnloadHook();
        this.determineActiveTab();
    }

    initCalendars() {
        if (typeof SevillaCalendar !== 'undefined') {
            this.state.calendars.event = new SevillaCalendar("cal-ui-event");
            this.state.calendars.hotel = new SevillaCalendar("cal-ui-hotel");
            this.state.calendars.villa = new SevillaCalendar("cal-ui-villa");
        }
    }

    bindNavigationAndTabs() {
        const hamburger = this.getEl("hamburger");
        const navLinks = this.getEl("nav-links");
        if (hamburger && navLinks) {
            hamburger.addEventListener("click", () => {
                hamburger.classList.toggle("active");
                navLinks.classList.toggle("active");
                document.body.style.overflow = navLinks.classList.contains("active") ? "hidden" : "auto";
            });
        }

        document.querySelectorAll(".tab-btn").forEach(btn => {
            btn.addEventListener("click", (e) => this.handleTabSwitch(e.target));
        });
    }

    bindUIInteractions() {
        this.setupImageSwap("event-venue", "event-img");
        this.setupImageSwap("hotel-room-type", "hotel-img");
        this.setupImageSwap("villa-type", "villa-img");

        const hotelTypeSelect = this.getEl("hotel-room-type");
        if (hotelTypeSelect) {
            hotelTypeSelect.addEventListener("change", (e) => this.populateSpecificHotelRooms(e.target.value));
            this.getEl("hotel-room-name").addEventListener('change', (e) => {
                if (this.state.calendars.hotel) this.state.calendars.hotel.clearSelection();
                this.calculateSummary();
                const opt = e.target.options[e.target.selectedIndex];
                this.state.calendars.hotel.fetchBookedDates(opt.dataset.type, opt.dataset.name);
            });
        }

        this.getEl('event-venue')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            if (this.state.calendars.event) this.state.calendars.event.fetchBookedDates('Event Hall', opt.text.split('(')[0].trim());
        });

        this.getEl('villa-type')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            if (this.state.calendars.villa) this.state.calendars.villa.fetchBookedDates('Resort Villa', opt.text.split('(')[0].trim());
        });

        document.querySelectorAll('input[name="event-type"]').forEach(radio => {
            radio.addEventListener("change", (e) => {
                const othersInput = this.getEl("event-type-others");
                const sumEvType = this.getEl("sum-ev-type");
                const isOthers = e.target.id === "event-others-radio";
                if (othersInput) othersInput.classList.toggle("hidden", !isOthers);
                if (sumEvType) sumEvType.innerText = isOthers ? (othersInput?.value || "Custom Event") : e.target.dataset.text;
            });
        });

        this.getEl("event-type-others")?.addEventListener("input", (e) => {
            const sumEvType = this.getEl("sum-ev-type");
            if (sumEvType) sumEvType.innerText = e.target.value || "Custom Event";
        });

        document.querySelectorAll('input[name="villa-stay"]').forEach(radio => {
            radio.addEventListener("change", (e) => {
                const isOvernight = e.target.value === "Overnight";
                this.getEl("rule-day")?.classList.toggle("hidden", isOvernight);
                this.getEl("rule-night")?.classList.toggle("hidden", !isOvernight);
            });
        });

        this.setupToggle("check-catering", "catering-options");
        this.setupToggle("check-rooms", "rooms-options");

        document.querySelectorAll(".counter").forEach(counter => {
            const minus = counter.querySelector(".btn-minus");
            const plus = counter.querySelector(".btn-plus");
            const valSpan = counter.querySelector(".val");

            minus.addEventListener("click", () => {
                const current = parseInt(valSpan.innerText) || 0;
                if (current > 0) { valSpan.innerText = current - 1; this.calculateSummary(); }
            });
            plus.addEventListener("click", () => {
                valSpan.innerText = (parseInt(valSpan.innerText) || 0) + 1;
                this.calculateSummary();
            });
        });
    }

    bindCalculatorTriggers() {
        document.querySelectorAll('select, input[type="number"], input[type="radio"], input[type="checkbox"]').forEach(input => {
            input.addEventListener('change', () => this.calculateSummary());
            input.addEventListener('input', () => this.calculateSummary());
        });
    }

    bindModalsAndSubmission() {
        this.getEl("open-terms")?.addEventListener("click", (e) => { e.preventDefault(); this.getEl("tnc-modal")?.classList.add("active"); });
        this.getEl("btn-agree")?.addEventListener("click", () => { this.getEl("tnc-modal")?.classList.remove("active"); this.getEl("terms-check").checked = true; });
        
        this.getEl("btn-cancel")?.addEventListener("click", () => {
            this.stopTimerAndReset();
            this.unlockDatesAPI();
        });

        window.addEventListener("click", (e) => {
            if (e.target.classList.contains("modal-overlay")) e.target.classList.remove("active");
        });

        this.getEl("btn-proceed")?.addEventListener("click", () => this.submitOnlineBooking());
    }

    bindUnloadHook() {
        window.addEventListener('beforeunload', () => {
            if (this.state.isDatesLocked) this.unlockDatesAPI();
        });
    }

    getEl(id) { return document.getElementById(id); }
    safeFloat(val) { return parseFloat(val) || 0; }
    formatCurrency(amount) { return '₱' + this.safeFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    formatSafeDate(dateObj) { return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`; }

    determineActiveTab() {
        const activeBtn = document.querySelector('.tab-btn.active');
        if (activeBtn) this.state.activeTabId = activeBtn.getAttribute('data-tab');
    }

    setupImageSwap(selectId, imgId) {
        const select = this.getEl(selectId);
        const img = this.getEl(imgId);
        if (!select || !img) return;

        select.addEventListener("change", (e) => {
            img.style.opacity = "0";
            setTimeout(() => {
                img.src = this.imageMap[e.target.value] || this.imageMap[Object.keys(this.imageMap)[0]];
                img.style.opacity = "1";
            }, 300);
        });
    }

    setupToggle(checkboxId, targetId) {
        const checkbox = this.getEl(checkboxId);
        const target = this.getEl(targetId);
        if (checkbox && target) {
            checkbox.addEventListener("change", () => target.classList.toggle("hidden", !checkbox.checked));
        }
    }

    populateSpecificHotelRooms(category) {
        if (typeof window.hotelRoomData === "undefined") return;
        const nameSelect = this.getEl("hotel-room-name");
        const rooms = window.hotelRoomData[category];
        if (!rooms || !nameSelect) return;

        nameSelect.innerHTML = '<option value="" disabled selected>Select a specific room...</option>';
        rooms.forEach((room) => {
            const opt = document.createElement("option");
            opt.value = room.base_rate;
            opt.dataset.type = room.room_type;
            opt.dataset.name = room.building_name;
            opt.textContent = `${room.building_name} (${room.base_capacity} pax) - ₱${parseInt(room.base_rate).toLocaleString()} [${room.total_units} units]`;
            nameSelect.appendChild(opt);
        });
        nameSelect.disabled = false;
    }

    handleTabSwitch(btn) {
        if (btn.classList.contains("active")) return;
        const target = btn.getAttribute("data-tab");

        if (this.state.isDatesLocked || (this.state.activeCalendar && this.state.activeCalendar.startDate)) {
            const switchModal = this.getEl('switch-tab-modal');
            if (!switchModal) return;
            
            switchModal.classList.add('active');
            this.replaceElement("btn-confirm-switch").addEventListener("click", () => {
                switchModal.classList.remove("active");
                if (this.state.isDatesLocked) this.unlockDatesAPI();
                this.stopTimerAndReset();
                this.executeTabVisualSwitch(btn, target);
            });
            this.replaceElement("btn-cancel-switch").addEventListener("click", () => switchModal.classList.remove("active"));
            return;
        }
        this.executeTabVisualSwitch(btn, target);
    }

    executeTabVisualSwitch(btn, target) {
        document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));
        document.querySelectorAll(".summary-container").forEach(s => s.classList.remove("active"));

        btn.classList.add("active");
        this.getEl(`tab-${target}`)?.classList.add("active");
        this.getEl(`sum-${target}`)?.classList.add("active");
        
        this.state.activeTabId = target;
        this.calculateSummary();
    }

    startTimer() {
        if (this.state.timerInterval) return;
        const timerBox = this.getEl("timer-box");
        const countdownEl = this.getEl("countdown");
        
        timerBox?.classList.add("running");
        this.getEl("timer-text").style.display = "none";
        this.getEl("countdown-wrapper").style.display = "inline";

        this.state.timerInterval = setInterval(() => {
            const minutes = Math.floor(this.state.timeLimit / 60);
            const seconds = String(this.state.timeLimit % 60).padStart(2, '0');
            if(countdownEl) countdownEl.innerText = `${minutes}:${seconds}`;

            if (this.state.timeLimit <= 0) {
                this.stopTimerAndReset();
                alert("Your session has expired. Please refresh the page to restart your booking.");
                const proceedBtn = this.getEl("btn-proceed");
                if(proceedBtn) { proceedBtn.disabled = true; proceedBtn.style.opacity = "0.5"; }
            }
            this.state.timeLimit--;
        }, 1000);
    }

    stopTimerAndReset() {
        clearInterval(this.state.timerInterval);
        this.state.timerInterval = null;
        this.state.timeLimit = 1800;
        this.state.isDatesLocked = false;

        const timerBox = this.getEl("timer-box");
        timerBox?.classList.remove("running");
        if(this.getEl("timer-text")) this.getEl("timer-text").style.display = "inline";
        if(this.getEl("countdown-wrapper")) this.getEl("countdown-wrapper").style.display = "none";
        if(this.getEl("terms-check")) this.getEl("terms-check").checked = false;

        if (this.state.activeCalendar) this.state.activeCalendar.clearSelection();
        this.calculateSummary();
    }

    async unlockDatesAPI() {
        try { await fetch('actions/bookings/unlock_dates.php'); } 
        catch (error) { console.error("Unlock failed", error); }
    }

    requestDateConfirmation(startDate, endDate, calendarInstance) {
        this.state.activeCalendar = calendarInstance;
        const dateModal = this.getEl("date-confirm-modal");
        
        const opts = { month: "short", day: "numeric", year: "numeric" };
        const startStr = startDate.toLocaleDateString("en-US", opts);
        const endStr = endDate ? endDate.toLocaleDateString("en-US", opts) : startStr;

        if (this.getEl("selected-date-text")) this.getEl("selected-date-text").innerText = `${startStr} — ${endStr}`;
        if (dateModal) dateModal.classList.add("active");

        const confirmBtn = this.replaceElement("btn-confirm-date");
        this.replaceElement("btn-cancel-date").addEventListener("click", () => {
            dateModal.classList.remove("active");
            calendarInstance.clearSelection();
        });

        confirmBtn.addEventListener("click", async () => {
            const lockData = this.getTabContextData();
            if (!lockData.roomName) {
                alert("Please select a specific venue/room from the dropdown first!");
                return;
            }

            const formData = new FormData();
            formData.append('room_type', lockData.roomType);
            formData.append('room_name', lockData.roomName);
            formData.append('start_date', this.formatSafeDate(startDate));
            formData.append('end_date', endDate ? this.formatSafeDate(endDate) : this.formatSafeDate(startDate));

            confirmBtn.innerText = "Locking...";
            confirmBtn.disabled = true;

            try {
                const res = await fetch('actions/bookings/lock_dates.php', { method: 'POST', body: formData });
                const text = await res.text();
                const response = text.split('|');

                if (response[0] === 'Success') {
                    dateModal.classList.remove("active");
                    this.state.isDatesLocked = true;
                    calendarInstance.updateDateDisplay();
                    this.calculateSummary();
                    this.startTimer();
                } else {
                    throw new Error(response[1]);
                }
            } catch (err) {
                alert("Error: " + err.message);
                dateModal.classList.remove("active");
                calendarInstance.clearSelection();
            } finally {
                confirmBtn.innerText = "Confirm";
                confirmBtn.disabled = false;
            }
        });
    }

    showOverrideModal(newDate, calendarInstance) {
        const overrideModal = this.getEl("override-date-modal");
        if(!overrideModal) return;
        overrideModal.classList.add("active");

        this.replaceElement("btn-override-no").addEventListener("click", () => overrideModal.classList.remove("active"));
        this.replaceElement("btn-override-yes").addEventListener("click", () => {
            overrideModal.classList.remove("active");
            this.unlockDatesAPI();
            this.stopTimerAndReset();
            
            this.state.activeCalendar = calendarInstance;
            calendarInstance.startDate = newDate;
            calendarInstance.endDate = null;
            calendarInstance.render();
            calendarInstance.updateDateDisplay();
        });
    }

    replaceElement(id) {
        const oldEl = this.getEl(id);
        if (!oldEl) return null;
        const newEl = oldEl.cloneNode(true);
        oldEl.parentNode.replaceChild(newEl, oldEl);
        return newEl;
    }

    appendSummaryRow(label, amount) {
        this.state.summary.html += `<div class="summary-row" style="display:flex; justify-content:space-between; margin-bottom: 5px;"><span>${label}</span><span>${this.formatCurrency(amount)}</span></div>`;
    }

    calcExtraPax(inputEl, baseCap, feePerHead, labelEl, guestsSumEl) {
        const guests = parseInt(inputEl?.value) || 0;
        if (guestsSumEl) guestsSumEl.innerText = guests > 0 ? guests : "--";
        let extraFee = 0;
        if (guests > baseCap) {
            extraFee = (guests - baseCap) * feePerHead;
            if (labelEl) {
                labelEl.textContent = `Extra Pax Fee: ${this.formatCurrency(extraFee)}`;
                labelEl.classList.remove('hidden');
            }
        } else {
            labelEl?.classList.add('hidden');
        }
        return extraFee;
    }

    calculateSummary() {
        this.state.summary.total = 0;
        this.state.summary.html = '';
        this.determineActiveTab();

        if (!this.state.isDatesLocked) {
            this.getEl('summary-breakdown').innerHTML = '<div class="summary-row" style="color:#b5884e;"><i>Please select and lock dates to calculate.</i></div>';
            this.getEl('summary-total-val').textContent = "₱0.00";
            this.getEl('summary-due-val').textContent = "₱0.00";
            return;
        }

        switch (this.state.activeTabId) {
            case 'hotel-rooms': this.calcHotelMath(); break;
            case 'event-hall': this.calcEventMath(); break;
            case 'resort-villa': this.calcVillaMath(); break;
        }

        let activeRadioName = 'hotel-payment';
        let summaryTextId = 'sum-ht-payment'; 
        let schemePct = 1.0;
        let schemeText = '100% Full';

        const proceedBtn = this.getEl("btn-proceed");

        if (this.state.activeTabId === 'event-hall') {
            summaryTextId = 'sum-ev-payment';
            schemeText = 'To Be Arranged'; 
            schemePct = 0; 
            
            if (proceedBtn) {
                proceedBtn.innerText = "SUBMIT EVENT INQUIRY";
                proceedBtn.style.backgroundColor = "var(--color-dark)";
            }

        } else {
            if (this.state.activeTabId === 'resort-villa') {
                activeRadioName = 'villa-payment';
                summaryTextId = 'sum-vl-payment';
            }

            document.querySelectorAll(`input[name="${activeRadioName}"]`).forEach(radio => {
                if (radio.checked) {
                    schemeText = radio.value; 
                    if (radio.value.includes('50%')) schemePct = 0.5;
                    if (radio.value.includes('20%')) schemePct = 0.2;
                }
            });

            if (proceedBtn) {
                proceedBtn.innerText = "PROCEED TO PAYMENT";
                proceedBtn.style.backgroundColor = "var(--color-gold)";
            }
        }

        this.state.summary.amountDue = this.state.summary.total * schemePct;

        if (this.getEl(summaryTextId)) {
            this.getEl(summaryTextId).innerText = schemeText; 
        }

        this.getEl('summary-breakdown').innerHTML = this.state.summary.html || '<div class="summary-row" style="color:#b5884e;"><i>No items selected</i></div>';
        this.getEl('summary-total-val').textContent = this.formatCurrency(this.state.summary.total);
        this.getEl('summary-due-val').textContent = this.formatCurrency(this.state.summary.amountDue);
    }

    calcHotelMath() {
        const nights = this.state.calendars.hotel?.totalNights || 1;
        const roomRate = this.safeFloat(this.getEl('hotel-room-name')?.value);
        if (roomRate > 0) {
            const roomTotal = roomRate * nights;
            this.state.summary.total += roomTotal; 
            this.appendSummaryRow(`Base Room Rate (x${nights} nights)`, roomTotal);
        }
        const extraFee = this.calcExtraPax(this.getEl('hotel-guests'), 2, 800, this.getEl('hotel-extra-fee'), this.getEl('sum-ht-guests'));
        if (extraFee > 0) { 
            const totalExtra = extraFee * nights; 
            this.state.summary.total += totalExtra; 
            this.appendSummaryRow('Extra Pax Fee', totalExtra); 
        }
    }

    calcEventMath() {
        const days = this.state.calendars.event?.totalNights || 1;
        const venue = this.safeFloat(this.getEl('event-venue')?.value) * days;
        const guestsInput = this.getEl('event-guests');
        if (this.getEl('sum-ev-guests')) this.getEl('sum-ev-guests').innerText = guestsInput?.value || "--";

        this.state.summary.total += venue;
        if (venue > 0) this.appendSummaryRow(`Venue Rate (x${days} days)`, venue);

        if (this.getEl('check-catering')?.checked) {
            const guests = parseInt(guestsInput?.value) || 0;
            const tierPrice = this.safeFloat(document.querySelector('input[name="catering-tier"]:checked')?.value);
            const cateringTotal = tierPrice * guests * days;
            if (cateringTotal > 0) { this.state.summary.total += cateringTotal; this.appendSummaryRow(`Catering (${guests} pax)`, cateringTotal); }
        }

        if (this.getEl('check-rooms')?.checked) {
            const dltQty = parseInt(this.getEl('qty-deluxe')?.textContent) || 0;
            const vipQty = parseInt(this.getEl('qty-vip')?.textContent) || 0;
            const roomTotal = ((dltQty * 4500) + (vipQty * 8500)) * days;
            if (roomTotal > 0) { this.state.summary.total += roomTotal; this.appendSummaryRow(`Reserved Rooms (x${days} nights)`, roomTotal); }
        }

        if (this.getEl('check-av')?.checked) {
            this.state.summary.total += 5000; 
            this.appendSummaryRow('Premium A/V Setup', 5000);
        }
    }

    calcVillaMath() {
        const nights = this.state.calendars.villa?.totalNights || 1;
        const villa = this.safeFloat(this.getEl('villa-type')?.value) * nights;
        
        const activeStayRadio = document.querySelector('input[name="villa-stay"]:checked');
        let stayTypePrice = 0;
        let stayText = 'Day Time Stay';

        if (activeStayRadio) {
            const isOvernight = activeStayRadio.value === 'Overnight';
            stayText = isOvernight ? 'Overnight' : 'Day Time Stay';
            stayTypePrice = isOvernight ? (3000 * nights) : 0; 

            if (this.getEl('sum-vl-stay')) this.getEl('sum-vl-stay').innerText = stayText;
            if (this.getEl('sum-vl-in')) this.getEl('sum-vl-in').innerText = isOvernight ? '2:00 PM' : '7:00 AM';
            if (this.getEl('sum-vl-out')) this.getEl('sum-vl-out').innerText = isOvernight ? '12:00 PM' : '5:00 PM';
        }
        
        this.state.summary.total += villa + stayTypePrice; 
        if (villa > 0) this.appendSummaryRow(`Base Villa Rate (x${nights} days)`, villa);
        if (stayTypePrice > 0) this.appendSummaryRow('Overnight Upgrade', stayTypePrice);
        
        const extraFee = this.calcExtraPax(this.getEl('villa-guests'), 4, 1000, this.getEl('villa-extra-fee'), this.getEl('sum-vl-guests'));
        if (extraFee > 0) { 
            const totalExtra = extraFee * nights; 
            this.state.summary.total += totalExtra; 
            this.appendSummaryRow('Extra Pax Fee', totalExtra); 
        }
    }

    getTabContextData() {
        const context = { roomType: '', roomName: '', baseAmt: 0, guests: 0, activeRadioGroup: 'payment-scheme' };

        if (this.state.activeTabId === 'hotel-rooms') {
            const opt = this.getEl('hotel-room-name')?.options[this.getEl('hotel-room-name')?.selectedIndex];
            context.roomType = opt?.dataset.type;
            context.roomName = opt?.dataset.name;
            context.baseAmt = opt?.value;
            context.guests = this.getEl('hotel-guests')?.value;
            context.activeRadioGroup = 'hotel-payment';

        } else if (this.state.activeTabId === 'event-hall') {
            const opt = this.getEl('event-venue')?.options[this.getEl('event-venue')?.selectedIndex];
            context.roomType = 'Event Hall';
            context.roomName = opt?.text.split('(')[0].trim();
            context.baseAmt = opt?.value;
            context.guests = this.getEl('event-guests')?.value;
            context.activeRadioGroup = 'payment-scheme';

        } else if (this.state.activeTabId === 'resort-villa') {
            const opt = this.getEl('villa-type')?.options[this.getEl('villa-type')?.selectedIndex];
            context.roomType = 'Resort Villa';
            context.roomName = opt?.text.split('(')[0].trim();
            context.baseAmt = opt?.value;
            context.guests = this.getEl('villa-guests')?.value;
            context.activeRadioGroup = 'villa-payment';
        }
        return context;
    }

    async submitOnlineBooking() {
        if (!this.state.isDatesLocked || !this.state.activeCalendar?.startDate) {
            alert("Please select dates on the calendar and confirm them first!");
            return;
        }
        if (!this.getEl('terms-check')?.checked) {
            alert("Please agree to the Terms & Conditions before proceeding.");
            return;
        }

        const btn = this.getEl("btn-proceed");
        const context = this.getTabContextData();
        
        if (!context.roomName) { alert("Please ensure a valid room/venue is selected."); return; }

        let schemeEnum = '100% Full';
        if (this.state.activeTabId === 'event-hall') {
            schemeEnum = '20% Reservation'; 
        } else {
            document.querySelectorAll(`input[name="${context.activeRadioGroup}"]`).forEach(radio => {
                if (radio.checked) schemeEnum = radio.value;
            });
        }

        const formData = new FormData();
        formData.append("room_type", context.roomType);
        formData.append("room_name", context.roomName);
        formData.append("start_date", this.formatSafeDate(this.state.activeCalendar.startDate));
        formData.append("end_date", this.state.activeCalendar.endDate ? this.formatSafeDate(this.state.activeCalendar.endDate) : this.formatSafeDate(this.state.activeCalendar.startDate));
        formData.append("guests", context.guests || 0);
        formData.append("base_amount", context.baseAmt || 0);
        formData.append("total_amount", this.state.summary.total);
        formData.append("payment_scheme", schemeEnum);

        const notesInput = document.getElementById("booking-notes");
        formData.append("custom_notes", notesInput ? notesInput.value.trim() : "");

        if (context.roomType === 'Event Hall') {
            const evTypeTxt = document.getElementById('sum-ev-type')?.innerText || '';
            const evStyleSelect = document.getElementById('event-style');
            const evStyleTxt = evStyleSelect ? evStyleSelect.options[evStyleSelect.selectedIndex].text : '';
            formData.append("event_type", evTypeTxt);
            formData.append("event_style", evStyleTxt.split('-')[0].trim()); 
        }

        if (context.roomType === 'Resort Villa') {
            const stayText = document.getElementById('sum-vl-stay')?.innerText === 'Overnight' ? 'Overnight' : 'Day Time Stay';
            formData.append("stay_type", stayText);
        }

        try {
            btn.innerText = "PROCESSING...";
            btn.disabled = true;

            const res = await fetch('actions/bookings/submit_online.php', { method: 'POST', body: formData });
            const data = await res.text();
            const response = data.split('|');
            
            if (response[0] === 'Success') {
                alert("Success! Redirecting to Dashboard.");
                window.location.href = "user_dashboard.php"; 
            } else {
                throw new Error(response[1]);
            }
        } catch (error) {
            alert("Error: " + error.message);
            btn.innerText = (this.state.activeTabId === 'event-hall') ? "SUBMIT EVENT INQUIRY" : "PROCEED TO PAYMENT";
            btn.disabled = false;
        }
    }
}

document.addEventListener("DOMContentLoaded", () => {
    window.BookingSystem = new BookingController();
});