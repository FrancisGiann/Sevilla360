/**
 * ==========================================================================
 * SEVILLA360 - Admin Walk-In Booking Controller
 * Features Smart-Sync Line Items for Admin Negotiation
 * ==========================================================================
 */

class AdminWalkinController {
    constructor() {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        this.state = {
            activeTabId: 'tab-event',
            isDatesLocked: false,     
            activeCalendar: null,
            summary: { total: 0, amountDue: 0, html: '' },
            calendars: {}
        };

        window.requestDateConfirmation = this.requestDateConfirmation.bind(this);
        window.showOverrideModal = this.showOverrideModal.bind(this);
        window.calculateSummary = this.calculateSummary.bind(this);

        this.imageMap = {
            "grand-ballroom": "https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=1200&q=80",
            "garden-pavilion": "https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?auto=format&fit=crop&w=1200&q=80",
            "rooftop-terrace": "https://images.unsplash.com/photo-1533174000255-11593130c2c3?auto=format&fit=crop&w=1200&q=80",
            "deluxe": "https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=1200&q=80",
            "vip": "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=1200&q=80",
            "standard": "https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=1200&q=80",
            "casita": "https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=1200&q=80",
            "hacienda": "https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1200&q=80"
        };

        this.init();
    }

    init() {
        this.initCalendars();
        this.bindTabs();
        this.bindUIInteractions();
        this.bindCalculatorTriggers();
        this.bindModalsAndSubmission();
        this.determineActiveTab();
    }

    initCalendars() {
        if (typeof SevillaCalendar !== 'undefined') {
            this.state.calendars.event = new SevillaCalendar("cal-ui-event");
            this.state.calendars.hotel = new SevillaCalendar("cal-ui-hotel");
            this.state.calendars.villa = new SevillaCalendar("cal-ui-villa");
        }
    }

    bindTabs() {
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
                const label = document.getElementById("sum-ht-type");
                if (label) label.innerText = opt.dataset.name || opt.text.split('(')[0].trim();
                this.state.calendars.hotel.fetchBookedDates(opt.dataset.type, opt.dataset.name);
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
                if (othersInput) othersInput.classList.toggle("hidden", e.target.id !== "event-others-radio");
                this.calculateSummary(); // Trigger update for summary
            });
        });
        
        this.getEl("event-type-others")?.addEventListener("input", () => this.calculateSummary());

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

        document.querySelectorAll('input[name="payment-method"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                const transWrapper = this.getEl("transaction-wrapper");
                if (transWrapper) transWrapper.classList.toggle('hidden', e.target.value === 'cash');
            });
        });

        // =========================================================================
        // MANUAL LINE ITEM BUILDER (For purely custom additions)
        // =========================================================================
        const lineItemsContainer = document.getElementById("wi-line-items");
        document.getElementById("wi-btn-add-item")?.addEventListener("click", () => {
            const row = document.createElement("div");
            row.className = "wi-row";
            row.style.cssText = "display:flex; gap:10px; margin-bottom:10px;";
            row.innerHTML = `
                <input type="text" class="wi-item-name" placeholder="Item Description (e.g. Live Band)" style="flex: 2; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <input type="number" class="wi-item-cost" step="0.01" placeholder="Amount (₱)" style="flex: 1; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <button type="button" class="btn-action wi-remove-row" style="flex: 0 0 45px; background: #fee2e2; color: #dc2626; border: none; border-radius: 4px; cursor: pointer; padding: 0;"><i class="fa-solid fa-trash"></i></button>
            `;
            lineItemsContainer.appendChild(row);

            row.querySelector(".wi-item-cost").addEventListener("input", () => this.calculateSummary());
            row.querySelector(".wi-remove-row").addEventListener("click", () => {
                row.remove();
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
        document.querySelector(".btn-confirm-walkin")?.addEventListener("click", () => this.submitWalkinBooking());
        document.querySelector(".btn-cancel-walkin")?.addEventListener("click", () => {
            showConfirm("Confirm Cancellation", "Are you sure you want to clear this booking form?").then(confirmed => {
                if (confirmed) window.location.reload();
            });
        });
    }

    getEl(id) { return document.getElementById(id); }
    safeFloat(val) { return parseFloat(val) || 0; }
    formatCurrency(amount) { return '₱' + this.safeFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    formatSafeDate(dateObj) { return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`; }

    determineActiveTab() {
        const activeBtn = document.querySelector('.tab-btn.active');
        if (activeBtn) this.state.activeTabId = activeBtn.getAttribute('data-target');
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
        if (checkbox && target) checkbox.addEventListener("change", () => target.classList.toggle("hidden", !checkbox.checked));
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
        const targetId = btn.getAttribute("data-target");

        document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));

        btn.classList.add("active");
        this.getEl(targetId)?.classList.add("active");
        this.state.activeTabId = targetId;

        if (targetId === "tab-event" && this.state.calendars.event) this.state.calendars.event.updateDateDisplay();
        if (targetId === "tab-hotel" && this.state.calendars.hotel) this.state.calendars.hotel.updateDateDisplay();
        if (targetId === "tab-villa" && this.state.calendars.villa) this.state.calendars.villa.updateDateDisplay();

        this.calculateSummary();
    }

    requestDateConfirmation(startDate, endDate, calendarInstance) {
        this.state.activeCalendar = calendarInstance;
        this.state.isDatesLocked = true; 
        
        if (this.state.activeCalendar) {
            this.state.activeCalendar.updateDateDisplay();
        }
        this.calculateSummary();
    }

    showOverrideModal(newDate, calendarInstance) {
        this.state.isDatesLocked = false;
        this.state.activeCalendar = calendarInstance;
        
        calendarInstance.clearSelection();
        calendarInstance.startDate = newDate;
        calendarInstance.endDate = null;
        calendarInstance.render();
        calendarInstance.updateDateDisplay();
        this.calculateSummary();
    }

    appendSummaryRow(label, amount) {
        this.state.summary.html += `<div class="summary-row"><span>${label}</span><span>${this.formatCurrency(amount)}</span></div>`;
    }

    calcExtraPax(inputEl, baseCap, feePerHead, labelEl) {
        const guests = parseInt(inputEl?.value) || 0;
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
    // SMART SYNC HELPER: Converts UI Toggles to Editable Line Items
    // =========================================================================
    syncSystemLineItem(id, name, amount) {
        const container = document.getElementById("wi-line-items");
        if (!container) return;
        
        let row = container.querySelector(`.wi-row[data-system="${id}"]`);

        // If the feature was untoggled (amount 0), remove it from the Line Items box
        if (amount <= 0) {
            if (row) row.remove();
            return;
        }

        // Create it if it doesn't exist
        if (!row) {
            row = document.createElement("div");
            row.className = "wi-row";
            row.setAttribute("data-system", id);
            row.style.cssText = "display:flex; gap:10px; margin-bottom:10px;";
            row.innerHTML = `
                <input type="text" class="wi-item-name" value="${name}" style="flex: 2; padding:10px; border:1px solid #ccc; border-radius:4px;" readonly>
                <input type="number" class="wi-item-cost" value="${amount.toFixed(2)}" step="0.01" placeholder="Amount (₱)" style="flex: 1; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <button type="button" class="btn-action" style="flex: 0 0 45px; background: #e0e0e0; color: #666; border: none; border-radius: 4px; cursor: help; padding: 0;" title="System generated item. Uncheck the option above to remove."><i class="fa-solid fa-lock"></i></button>
            `;
            container.appendChild(row);

            // Flag as "Admin Edited" if they manually change the price box!
            row.querySelector(".wi-item-cost").addEventListener("input", () => {
                row.setAttribute("data-edited", "true");
                this.calculateSummary(); // Triggers a recalculation to update grand total
            });
        } 
        else {
            // Update the name and price ONLY IF the Admin hasn't manually overridden it
            if (row.getAttribute("data-edited") !== "true") {
                row.querySelector(".wi-item-name").value = name;
                row.querySelector(".wi-item-cost").value = amount.toFixed(2);
            }
        }
    }
    // =========================================================================


    calculateSummary() {
        this.state.summary.total = 0;
        this.state.summary.html = '';
        this.determineActiveTab();

        // 1. DO THE MATH (Dates locked or not, we show the base calculation)
        switch (this.state.activeTabId) {
            case 'tab-hotel': this.calcHotelMath(); break;
            case 'tab-event': this.calcEventMath(); break;
            case 'tab-villa': this.calcVillaMath(); break;
        }

        // Loop through line item builder for custom add-ons
        document.querySelectorAll(".wi-row").forEach(row => {
            const name = row.querySelector(".wi-item-name").value || "Custom Item";
            const cost = parseFloat(row.querySelector(".wi-item-cost").value) || 0;
            if (cost > 0) {
                this.state.summary.total += cost;
                this.appendSummaryRow(name, cost);
            }
        });

        // OUTPUT BREAKDOWN AND TOTAL IMMEDIATELY
        this.getEl('summary-breakdown').innerHTML = this.state.summary.html || '<div class="summary-row"><span>No items selected</span></div>';
        this.getEl('summary-total-val').textContent = this.formatCurrency(this.state.summary.total);

        // 2. STOP HERE IF DATES ARE NOT LOCKED
        if (!this.state.isDatesLocked || !this.state.activeCalendar?.startDate) {
            this.getEl('summary-due-val').textContent = "₱0.00";
            return;
        }

        // Calculate Amount Due based on Scheme
        const schemePct = this.safeFloat(this.getEl("payment-scheme")?.value) || 1;
        this.state.summary.amountDue = this.state.summary.total * schemePct;

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
        const extraFee = this.calcExtraPax(this.getEl('hotel-guests'), 2, 800, this.getEl('hotel-extra-fee'));
        if (extraFee > 0) { 
            const totalExtra = extraFee * nights; 
            this.state.summary.total += totalExtra; 
            this.appendSummaryRow('Extra Pax Fee', totalExtra); 
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
        
        if (activeStayRadio) {
            const isOvernight = activeStayRadio.value === 'Overnight';
            stayTypePrice = isOvernight ? (3000 * nights) : 0; 
        }
        
        this.state.summary.total += villa + stayTypePrice; 
        if (villa > 0) this.appendSummaryRow(`Base Villa Rate (x${nights} days)`, villa);
        if (stayTypePrice > 0) this.appendSummaryRow('Overnight Upgrade', stayTypePrice);
        
        const extraFee = this.calcExtraPax(this.getEl('villa-guests'), 4, 1000, this.getEl('villa-extra-fee'));
        if (extraFee > 0) { 
            const totalExtra = extraFee * nights; 
            this.state.summary.total += totalExtra; 
            this.appendSummaryRow('Extra Pax Fee', totalExtra); 
        }
    }

    getTabContextData() {
        const context = { roomType: '', roomName: '', baseAmt: 0, guests: 0 };

        if (this.state.activeTabId === 'tab-hotel') {
            const opt = this.getEl('hotel-room-name')?.options[this.getEl('hotel-room-name')?.selectedIndex];
            context.roomType = opt?.dataset.type;
            context.roomName = opt?.dataset.name;
            context.baseAmt = opt?.value;
            context.guests = this.getEl('hotel-guests')?.value;

        } else if (this.state.activeTabId === 'tab-event') {
            const opt = this.getEl('event-venue')?.options[this.getEl('event-venue')?.selectedIndex];
            context.roomType = 'Event Hall';
            context.roomName = opt?.dataset.name || opt?.text.split('(')[0].trim();
            context.baseAmt = opt?.value;
            context.guests = this.getEl('event-guests')?.value;

        } else if (this.state.activeTabId === 'tab-villa') {
            const opt = this.getEl('villa-type')?.options[this.getEl('villa-type')?.selectedIndex];
            context.roomType = 'Resort Villa';
            context.roomName = opt?.dataset.name || opt?.text.split('(')[0].trim();
            context.baseAmt = opt?.value;
            context.guests = this.getEl('villa-guests')?.value;
        }
        return context;
    }

    async submitWalkinBooking() {
        const guestName = this.getEl("guest-name")?.value.trim();
        const guestEmail = this.getEl("guest-email")?.value.trim();
        const guestPhone = this.getEl("guest-phone")?.value.trim();

        if (!guestName || !guestEmail || !guestPhone) {
            showAlert("Notice", "Please complete the Guest Information section.");
            return;
        }

        if (!this.state.activeCalendar || !this.state.activeCalendar.startDate) {
            showAlert("Notice", "Please select dates on the calendar first!");
            return;
        }

        const context = this.getTabContextData();
        if (!context.roomName) { showAlert("Notice", "Please ensure a valid specific room/venue is selected."); return; }

        const schemeVal = this.getEl("payment-scheme")?.value;
        let schemeEnum = "100% Full";
        if (schemeVal === "0.5") schemeEnum = "50% Downpayment";
        if (schemeVal === "0.2") schemeEnum = "20% Reservation";

        const paymentMethod = document.querySelector('input[name="payment-method"]:checked')?.value || "cash";
        const transactionId = this.getEl("transaction-id")?.value.trim() || "";

        if (paymentMethod !== 'cash' && !transactionId) {
            showAlert("Notice", "Please provide the Transaction/Reference ID for cashless payments.");
            return;
        }

        const btnConfirm = document.querySelector(".btn-confirm-walkin");
        
        const formData = new FormData();
        formData.append("guest_name", guestName);
        formData.append("guest_email", guestEmail);
        formData.append("guest_phone", guestPhone);
        formData.append("room_type", context.roomType);
        formData.append("room_name", context.roomName);
        formData.append("start_date", this.formatSafeDate(this.state.activeCalendar.startDate));
        formData.append("end_date", this.state.activeCalendar.endDate ? this.formatSafeDate(this.state.activeCalendar.endDate) : this.formatSafeDate(this.state.activeCalendar.startDate));
        formData.append("guests", context.guests || 0);
        formData.append("base_amount", context.baseAmt || 0);
        
        // SENDS THE ABSOLUTE GRAND TOTAL (Base + ALL Line Items)
        formData.append("total_amount", this.state.summary.total);
        formData.append("payment_scheme", schemeEnum);
        formData.append("payment_method", paymentMethod);
        formData.append("transaction_id", transactionId);

        const notesInput = this.getEl("guest-notes");
        formData.append("custom_notes", notesInput ? notesInput.value.trim() : "");

        if (context.roomType === 'Event Hall') {
            const evTypeRadio = document.querySelector('input[name="event-type"]:checked');
            let evTypeTxt = '';
            if (evTypeRadio) {
                evTypeTxt = evTypeRadio.id === 'event-others-radio' ? this.getEl('event-type-others')?.value : (evTypeRadio.dataset.text || evTypeRadio.parentElement.innerText.trim());
            }
            const evStyleSelect = this.getEl('event-style');
            const evStyleTxt = evStyleSelect ? evStyleSelect.options[evStyleSelect.selectedIndex].text : '';
            
            formData.append("event_type", evTypeTxt);
            formData.append("event_style", evStyleTxt.split('-')[0].trim()); 
        }

        if (context.roomType === 'Resort Villa') {
            const stayRadio = document.querySelector('input[name="villa-stay"]:checked');
            formData.append("stay_type", stayRadio ? stayRadio.value : 'Day Time Stay');
        }

        // =========================================================
        // FINAL PAYLOAD: Grabs everything currently visible in the Line Items box!
        // =========================================================
        let customLineItems = [];
        document.querySelectorAll(".wi-row").forEach(row => {
            const name = row.querySelector(".wi-item-name").value.trim();
            const cost = parseFloat(row.querySelector(".wi-item-cost").value) || 0;
            if (name !== "" && cost > 0) {
                customLineItems.push({ name: name, amount: cost });
            }
        });

        if (customLineItems.length > 0) {
            formData.append('custom_line_items', JSON.stringify(customLineItems));
        }
        // =========================================================

        try {
            btnConfirm.innerText = "PROCESSING...";
            btnConfirm.disabled = true;

            const res = await fetch("actions/bookings/submit_walkin.php", { 
                method: "POST", 
                headers: { "X-CSRF-Token": this.csrfToken }, 
                body: formData 
            });
            const data = await res.text();
            const response = data.split("|");

            if (response[0] === "Success") {
                showAlert("Notice", "Walk-in Booking Successful! Reference No: " + response[1]);
                window.location.reload();
            } else {
                throw new Error(response[1]);
            }
        } catch (error) {
            showAlert("Notice", "Error: " + error.message);
            btnConfirm.innerText = "CONFIRM WALK-IN BOOKING";
            btnConfirm.disabled = false;
        }
    }
}

document.addEventListener("DOMContentLoaded", () => {
    window.WalkinSystem = new AdminWalkinController();
});