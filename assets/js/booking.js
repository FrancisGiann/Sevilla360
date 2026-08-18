/**
 * ==========================================================================
 * SEVILLA360 - Booking Controller (Refactored & Fully Patched)
 * ==========================================================================
 */

class BookingController {
    constructor() {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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
                const opt = e.target.options[e.target.selectedIndex];
                const roomLabel = document.getElementById("sum-ht-room");
                if (roomLabel) roomLabel.innerText = opt.dataset.name || opt.text.split('(')[0].trim();
                this.state.calendars.hotel.fetchBookedDates(opt.dataset.type, opt.dataset.name);
                this.calculateSummary();
            });
        }

        this.getEl('event-venue')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            const venueName = opt.text.split('(')[0].trim();
            const label = document.getElementById("sum-ev-venue");
            if (label) label.innerText = venueName;
            
            if (this.state.calendars.event) this.state.calendars.event.fetchBookedDates('Event Hall', venueName);

            // Dynamically update event style dropdown capacities
            const styleSelect = this.getEl('event-style');
            if (styleSelect && opt.dataset.theater) {
                // Update the text of the existing options to include the specific room capacities
                styleSelect.options[0].text = `Theater Style (${opt.dataset.theater} pax)`;
                styleSelect.options[1].text = `Classroom Style (${opt.dataset.classroom} pax)`;
                styleSelect.options[2].text = `Banquet Type (${opt.dataset.banquet} pax)`;
                
                // Also update the max attribute on the guest input so they can't overbook!
                // (Defaults to the highest capacity, which is usually Theater)
                const guestInput = this.getEl('event-guests');
                if (guestInput) {
                    const maxCap = Math.max(opt.dataset.theater, opt.dataset.classroom, opt.dataset.banquet);
                    guestInput.setAttribute('max', maxCap);
                }
            }
            // =========================================================
        });

        this.getEl('villa-type')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            const villaName = opt.text.split('(')[0].trim();
            const label = document.getElementById("sum-vl-type");
            if (label) label.innerText = villaName;

            if (this.state.calendars.villa) this.state.calendars.villa.fetchBookedDates('Resort Villa', villaName);
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

        // RECALCULATE SUMMARY WHEN PAYMENT SCHEME CHANGES
        document.querySelectorAll('input[name="hotel-payment"], input[name="villa-payment"]').forEach(radio => {
            radio.addEventListener("change", () => this.calculateSummary());
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
        const urlParams = new URLSearchParams(window.location.search);
        const urlTab = urlParams.get('tab');
        if (urlTab) {
            const btn = document.querySelector(`.tab-btn[data-tab="${urlTab}"]`);
            if (btn) {
                this.executeTabVisualSwitch(btn, urlTab);
            }
            // Clear the URL param so calculateSummary never re-triggers this
            const cleanUrl = window.location.pathname;
            window.history.replaceState(null, '', cleanUrl);
            return;
        }
        
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
        
        const label = this.getEl("sum-ht-type");
        if (label) label.innerText = category;
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
        this.calculateSummary();
    }

    executeTabVisualSwitch(btn, target) {
        document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));
        document.querySelectorAll(".summary-container").forEach(s => s.classList.remove("active"));

        btn.classList.add("active");
        this.getEl(`tab-${target}`)?.classList.add("active");
        this.getEl(`sum-${target}`)?.classList.add("active");
        
        this.state.activeTabId = target;
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
                showAlert("Notice", "Your session has expired. Please refresh the page to restart your booking.");
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
        try { 
            await fetch('actions/bookings/unlock_dates.php', {
                method: 'POST',
                headers: { "X-CSRF-Token": this.csrfToken }
            }); 
        } 
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
                showAlert("Notice", "Please select a specific venue/room from the dropdown first!");
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
                const res = await fetch('actions/bookings/lock_dates.php', { 
                method: 'POST', 
                headers: { "X-CSRF-Token": this.csrfToken }, 
                body: formData 
            });
                const text = await res.text();
                const response = text.split('|');

                if (response[0] === 'Success') {
                    dateModal.classList.remove("active");
                    this.state.isDatesLocked = true;
                    calendarInstance.updateDateDisplay();
                    this.calculateSummary();
                    
                    // ONLY START THE TIMER IF IT IS NOT AN EVENT INQUIRY
                    if (this.state.activeTabId !== 'event-hall') {
                        this.startTimer();
                    }
                } else {
                    throw new Error(response[1]);
                }
            } catch (err) {
                showAlert("Notice", "Error: " + err.message);
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

    // =========================================================================
    // CALCULATION LOGIC (WITH CRASH FIXES & PRICING HIDER!)
    // =========================================================================
    calculateSummary() {
        this.state.summary.total = 0;
        this.state.summary.html = '';
        // Do NOT call determineActiveTab() here — it reads the URL and causes re-switching
        // activeTabId is maintained by handleTabSwitch and determineActiveTab (run once on init only)

        // 1. SAFELY GRAB DOM ELEMENTS
        const breakdownEl = this.getEl('summary-breakdown');
        const totalValEl = this.getEl('summary-total-val');
        const dueValEl = this.getEl('summary-due-val');
        const pricingSection = this.getEl('pricing-section');

        // 2. DO THE MATH (Dates locked or not, we show the base calculation)
        switch (this.state.activeTabId) {
            case 'hotel-rooms': this.calcHotelMath(); break;
            case 'event-hall': this.calcEventMath(); break;
            case 'resort-villa': this.calcVillaMath(); break;
        }
        
        // OUTPUT BREAKDOWN AND TOTAL IMMEDIATELY
        if (breakdownEl) breakdownEl.innerHTML = this.state.summary.html || '<div class="summary-row" style="color:#b5884e;"><i>No items selected</i></div>';
        if (totalValEl) totalValEl.textContent = this.formatCurrency(this.state.summary.total);

        // (Removed early return to allow live updates of scheme amounts before dates are locked)

        let activeRadioName = 'hotel-payment';
        let summaryTextId = 'sum-ht-payment'; 
        let schemePct = 1.0;
        let schemeText = '100% Full';

        const proceedBtn = this.getEl("btn-proceed");

        // 4. BRANCHING LOGIC: EVENT INQUIRY VS NORMAL BOOKING
        if (this.state.activeTabId === 'event-hall') {
            summaryTextId = 'sum-ev-payment';
            schemeText = 'To Be Arranged'; 
            schemePct = 0; 
            
            if (proceedBtn) {
                proceedBtn.innerText = "SUBMIT EVENT INQUIRY";
                proceedBtn.style.backgroundColor = "var(--color-dark)";
            }
            if (this.getEl("timer-box")) this.getEl("timer-box").style.display = "none";
            if (pricingSection) pricingSection.style.display = "none"; // HIDES PRICING

        } else {
            // Only show timer if dates are actually locked
            if (this.getEl("timer-box")) {
                this.getEl("timer-box").style.display = this.state.isDatesLocked ? "block" : "none";
            }
            if (pricingSection) pricingSection.style.display = "block"; // SHOWS PRICING

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

        if (dueValEl) dueValEl.textContent = this.formatCurrency(this.state.summary.amountDue);
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

    syncSystemLineItem(id, name, amount) {
        if (amount > 0 && name) {
            this.appendSummaryRow(name, amount);
        }
    }

    calcEventMath() {
        const days = this.state.calendars.event?.totalNights || 1;
        const venue = this.safeFloat(this.getEl('event-venue')?.value) * days;
        
        this.state.summary.total += venue;
        if (venue > 0) this.appendSummaryRow(`Venue Rate (x${days} days)`, venue);

        // --- NEW: EVENT TYPE UPGRADE FEE ---
        const evTypeRadio = document.querySelector('input[name="event-type"]:checked');
        let evTypeText = 'Plain Hall';
        if (evTypeRadio) {
            if (evTypeRadio.id === 'event-others-radio') evTypeText = this.getEl('event-type-others')?.value || 'Custom Event';
            else evTypeText = evTypeRadio.dataset.text || 'Plain Hall';
            
            const typePrice = this.safeFloat(evTypeRadio.value);
            if (typePrice > 0) {
                this.state.summary.total += typePrice;
                this.syncSystemLineItem('type', `Event Type: ${evTypeText}`, typePrice);
            } else {
                this.syncSystemLineItem('type', '', 0); // Clear it if 0
            }
        }
        
        // --- NEW: EVENT STYLE UPGRADE FEE ---
        const styleSelect = this.getEl('event-style');
        if (styleSelect) {
            const stylePrice = this.safeFloat(styleSelect.value);
            const styleText = styleSelect.options[styleSelect.selectedIndex].text.split('(+')[0].trim();
            if (stylePrice > 0) {
                this.state.summary.total += stylePrice;
                this.syncSystemLineItem('style', `Event Style: ${styleText}`, stylePrice);
            } else {
                this.syncSystemLineItem('style', '', 0); // Clear it if 0
            }
        }

        // --- SYNC ADDONS TO LINE ITEM BUILDER ---
        let cateringTotal = 0; let cateringName = '';
        if (this.getEl('check-catering')?.checked) {
            const guestsInput = this.getEl('event-guests');
            const guests = parseInt(guestsInput?.value) || 0;
            const activeTier = document.querySelector('input[name="catering-tier"]:checked');
            const tierPrice = this.safeFloat(activeTier?.value);
            
            cateringTotal = tierPrice * guests * days;
            const tierName = activeTier?.parentElement.querySelector('h4')?.innerText || 'Catering';
            cateringName = `Catering: ${tierName} (${guests} pax)`;
        }
        this.syncSystemLineItem('catering', cateringName, cateringTotal);

        let roomTotal = 0; let roomName = '';
        if (this.getEl('check-rooms')?.checked) {
            const dltQty = parseInt(this.getEl('qty-deluxe')?.textContent) || 0;
            const vipQty = parseInt(this.getEl('qty-vip')?.textContent) || 0;
            roomTotal = ((dltQty * 4500) + (vipQty * 8500)) * days;
            
            let parts = [];
            if(dltQty > 0) parts.push(`Deluxe Room (x${dltQty})`);
            if(vipQty > 0) parts.push(`VIP Suite (x${vipQty})`);
            roomName = `Reserved Rooms: ${parts.join(', ')}`;
        }
        this.syncSystemLineItem('rooms', roomName, roomTotal);

        let avTotal = 0;
        const avCheckbox = this.getEl('check-av');
        if (avCheckbox?.checked) {
            avTotal = this.safeFloat(avCheckbox.value); // DYNAMIC VALUE!
        }
        this.syncSystemLineItem('av', 'Premium A/V Setup', avTotal);
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

    // =========================================================================
    // SUBMISSION & PAYMONGO REDIRECT
    // =========================================================================
    async submitOnlineBooking() {
        if (!this.state.isDatesLocked || !this.state.activeCalendar?.startDate) {
            showAlert("Notice", "Please select dates on the calendar and confirm them first!");
            return;
        }
        if (!this.getEl('terms-check')?.checked) {
            showAlert("Notice", "Please agree to the Terms & Conditions before proceeding.");
            return;
        }

        // REQUIRE PHONE NUMBER
        const phoneInput = document.getElementById("contact-phone");
        if (!phoneInput || phoneInput.value.trim() === "") {
            showAlert("Notice", "Please provide a contact number so our team can call you!");
            return;
        }

        const btn = this.getEl("btn-proceed");
        const context = this.getTabContextData();
        
        if (!context.roomName) { showAlert("Notice", "Please ensure a valid room/venue is selected."); return; }

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
        
        // APPEND PHONE & NOTES
        formData.append("contact_phone", phoneInput.value.trim());
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

        // Capture custom HTML add-ons as line items
        let customLineItems = [];

        // 1. Catering
        const checkCatering = document.getElementById('check-catering');
        if (checkCatering && checkCatering.checked) {
            const activeTier = document.querySelector('input[name="catering-tier"]:checked');
            if (activeTier) {
                const tierName = activeTier.parentElement.querySelector('h4').innerText;
                const tierPrice = parseFloat(activeTier.value) || 0;
                const guestCount = parseInt(context.guests) || 0;
                const totalCatering = tierPrice * guestCount;
                customLineItems.push({
                    name: `Catering: ${tierName} (${guestCount} pax)`,
                    amount: totalCatering
                });
            }
        }

        // 2. Hotel Rooms
        const checkRooms = document.getElementById('check-rooms');
        if (checkRooms && checkRooms.checked) {
            const deluxeQty = parseInt(document.getElementById('qty-deluxe')?.innerText) || 0;
            const vipQty = parseInt(document.getElementById('qty-vip')?.innerText) || 0;
            
            if (deluxeQty > 0) customLineItems.push({ name: `Reserved Deluxe Room (x${deluxeQty})`, amount: (deluxeQty * 4500) });
            if (vipQty > 0) customLineItems.push({ name: `Reserved VIP Suite (x${vipQty})`, amount: (vipQty * 8500) });
        }

        // 3. A/V Setup
        const checkAv = document.getElementById('check-av');
        if (checkAv && checkAv.checked) {
            customLineItems.push({ name: `Premium A/V Setup`, amount: 5000 });
        }

        // Append as a JSON string so PHP can read it easily
        formData.append('custom_line_items', JSON.stringify(customLineItems));
        // =========================================================

        try {
            btn.innerText = "PROCESSING...";
            btn.disabled = true;

            const res = await fetch('actions/bookings/submit_online.php', { 
                method: 'POST', 
                headers: { "X-CSRF-Token": this.csrfToken }, 
                body: formData 
            });
            const data = await res.text();
            const response = data.split('|');
            
            // IF PAYMONGO SENDS A LINK, GO THERE
            if (response[0] === 'CheckoutUrl') {
                window.location.href = response[1]; 
            } 
            // OTHERWISE GO TO DASHBOARD
            else if (response[0] === 'Success') {
                showAlert("Notice", "Success! Redirecting to Dashboard.");
                window.location.href = "user_dashboard.php"; 
            } else {
                throw new Error(response[1]);
            }
        } catch (error) {
            showAlert("Notice", "Error: " + error.message);
            btn.innerText = (this.state.activeTabId === 'event-hall') ? "SUBMIT EVENT INQUIRY" : "PROCEED TO PAYMENT";
            btn.disabled = false;
        }
    }
}

document.addEventListener("DOMContentLoaded", () => {
    window.BookingSystem = new BookingController();
});