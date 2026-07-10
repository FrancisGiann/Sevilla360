/**
 * ==========================================================================
 * SEVILLA360 - Admin Walk-In Booking Controller
 * Architecture: ES6 Class-Based Controller Pattern
 * Focus: Front-desk manual entry, no session timers, instant confirmed booking.
 * ==========================================================================
 */

class AdminWalkinController {
    constructor() {
        // 1. Centralized State (Admin Context)
        this.state = {
            activeTabId: 'tab-event', // Admin uses different tab IDs (tab-event, tab-hotel, tab-villa)
            isDatesLocked: false,     // Only a visual lock for the admin UI (No DB expiration)
            activeCalendar: null,
            summary: {
                total: 0,
                amountDue: 0,
                html: ''
            },
            calendars: {}
        };

        // 2. Map global functions required by external calendar.js
        window.requestDateConfirmation = this.requestDateConfirmation.bind(this);
        window.showOverrideModal = this.showOverrideModal.bind(this);
        window.calculateSummary = this.calculateSummary.bind(this);

        // 3. Image Dictionary for Dynamic Swapping
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

    /**
     * ==========================================
     * INITIALIZATION & EVENT BINDINGS
     * ==========================================
     */
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
        // Dynamic Image Swapping
        this.setupImageSwap("event-venue", "event-img");
        this.setupImageSwap("hotel-room-type", "hotel-img");
        this.setupImageSwap("villa-type", "villa-img");

        // Hotel Cascading Dropdown (Category -> Specific Unit)
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

        // Generic Dropdown Fetch Dates
        this.getEl('event-venue')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            if (this.state.calendars.event) this.state.calendars.event.fetchBookedDates('Event Hall', opt.text.split('(')[0].trim());
        });

        this.getEl('villa-type')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            if (this.state.calendars.villa) this.state.calendars.villa.fetchBookedDates('Resort Villa', opt.text.split('(')[0].trim());
        });

        // Event Type "Others" Toggle
        document.querySelectorAll('input[name="event-type"]').forEach(radio => {
            radio.addEventListener("change", (e) => {
                const othersInput = this.getEl("event-type-others");
                if (othersInput) othersInput.classList.toggle("hidden", e.target.id !== "event-others-radio");
            });
        });

        // Add-ons Toggles
        this.setupToggle("check-catering", "catering-options");
        this.setupToggle("check-rooms", "rooms-options");

        // Add-ons Counters
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

        // Admin Specific: Payment Method Toggle (Show/Hide Transaction ID input)
        document.querySelectorAll('input[name="payment-method"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                const transWrapper = this.getEl("transaction-wrapper");
                if (transWrapper) {
                    transWrapper.classList.toggle('hidden', e.target.value === 'cash');
                }
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
        // Modal Confirmation bindings
        const btnConfirmDates = this.getEl("btn-confirm-dates");
        if (btnConfirmDates) {
            btnConfirmDates.addEventListener("click", () => {
                this.getEl("confirm-dates-modal")?.classList.remove("active");
                this.state.isDatesLocked = true; // Visually locked for the admin
                if (this.state.activeCalendar) this.state.activeCalendar.updateDateDisplay();
                this.calculateSummary();
            });
        }

        this.getEl("btn-cancel-dates")?.addEventListener("click", () => {
            this.getEl("confirm-dates-modal")?.classList.remove("active");
            if (this.state.activeCalendar) this.state.activeCalendar.clearSelection();
        });

        // Submit Booking (Admin Confirm)
        document.querySelector(".btn-confirm-walkin")?.addEventListener("click", () => this.submitWalkinBooking());
        
        // Cancel Form
        document.querySelector(".btn-cancel-walkin")?.addEventListener("click", () => {
            if(confirm("Are you sure you want to clear this booking form?")) window.location.reload();
        });
    }

    /**
     * ==========================================
     * DOM & UI HELPER METHODS
     * ==========================================
     */
    getEl(id) { return document.getElementById(id); }

    safeFloat(val) { return parseFloat(val) || 0; }

    formatCurrency(amount) {
        return '₱' + this.safeFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    formatSafeDate(dateObj) {
        return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`;
    }

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

    /**
     * ==========================================
     * TAB NAVIGATION
     * ==========================================
     */
    handleTabSwitch(btn) {
        if (btn.classList.contains("active")) return;
        const targetId = btn.getAttribute("data-target");

        document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));

        btn.classList.add("active");
        this.getEl(targetId)?.classList.add("active");
        this.state.activeTabId = targetId;

        // Force calendar UI updates for the new tab
        if (targetId === "tab-event" && this.state.calendars.event) this.state.calendars.event.updateDateDisplay();
        if (targetId === "tab-hotel" && this.state.calendars.hotel) this.state.calendars.hotel.updateDateDisplay();
        if (targetId === "tab-villa" && this.state.calendars.villa) this.state.calendars.villa.updateDateDisplay();

        this.calculateSummary();
    }

    /**
     * ==========================================
     * VISUAL DATE LOCKING (ADMIN SPECIFIC)
     * ==========================================
     */
    requestDateConfirmation(startDate, endDate, calendarInstance) {
        this.state.activeCalendar = calendarInstance;
        const dateModal = this.getEl("confirm-dates-modal");
        const dateDisplay = this.getEl("confirm-date-display");

        const opts = { month: "short", day: "numeric", year: "numeric" };
        const startStr = startDate.toLocaleDateString("en-US", opts);
        const endStr = endDate ? endDate.toLocaleDateString("en-US", opts) : startStr;
        
        if (dateDisplay) dateDisplay.innerText = `${startStr} — ${endStr}`;
        if (dateModal) dateModal.classList.add("active");
    }

    showOverrideModal(newDate, calendarInstance) {
        const overrideModal = this.getEl("change-dates-modal");
        if (!overrideModal) return;
        overrideModal.classList.add("active");

        const oldYes = this.getEl("btn-override-yes");
        const newYes = oldYes.cloneNode(true);
        oldYes.parentNode.replaceChild(newYes, oldYes);

        const oldNo = this.getEl("btn-override-no");
        const newNo = oldNo.cloneNode(true);
        oldNo.parentNode.replaceChild(newNo, oldNo);

        newYes.addEventListener("click", () => {
            overrideModal.classList.remove("active");
            this.state.isDatesLocked = false;
            if (this.state.activeCalendar) this.state.activeCalendar.clearSelection();

            this.state.activeCalendar = calendarInstance;
            calendarInstance.startDate = newDate;
            calendarInstance.endDate = null;
            calendarInstance.render();
            calendarInstance.updateDateDisplay();
            this.calculateSummary();
        });

        newNo.addEventListener("click", () => overrideModal.classList.remove("active"));
    }

    /**
     * ==========================================
     * DYNAMIC PRICING ENGINE
     * ==========================================
     */
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

    calculateSummary() {
        this.state.summary.total = 0;
        this.state.summary.html = '';
        this.determineActiveTab();

        if (!this.state.isDatesLocked) {
            this.getEl('summary-breakdown').innerHTML = '<div class="summary-row" style="color:#b5884e;"><i>Please select and confirm dates to calculate.</i></div>';
            this.getEl('summary-total-val').textContent = "₱0.00";
            this.getEl('summary-due-val').textContent = "₱0.00";
            return;
        }

        switch (this.state.activeTabId) {
            case 'tab-hotel': this.calcHotelMath(); break;
            case 'tab-event': this.calcEventMath(); break;
            case 'tab-villa': this.calcVillaMath(); break;
        }

        // Admin single payment scheme dropdown
        const schemePct = this.safeFloat(this.getEl("payment-scheme")?.value) || 1;
        this.state.summary.amountDue = this.state.summary.total * schemePct;

        this.getEl('summary-breakdown').innerHTML = this.state.summary.html || '<div class="summary-row"><span>No items selected</span></div>';
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
        const style = this.safeFloat(this.getEl('event-style')?.value) * days;
        
        const typeRadio = document.querySelector('input[name="event-type"]:checked');
        const typeFee = (typeRadio && typeRadio.id !== 'event-others-radio') ? this.safeFloat(typeRadio.value) * days : 0;
        
        const guestsInput = this.getEl('event-guests');

        this.state.summary.total += venue + style + typeFee;
        if (venue > 0) this.appendSummaryRow(`Venue Rate (x${days} days)`, venue);
        if (style > 0) this.appendSummaryRow('Style Upgrade', style);
        if (typeFee > 0) this.appendSummaryRow('Event Setup Fee', typeFee);

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
        const stayType = this.safeFloat(document.querySelector('input[name="stay-type"]:checked')?.value) * nights; // Admin tab uses stay-type
        
        this.state.summary.total += villa + stayType; 
        if (villa > 0) this.appendSummaryRow(`Base Villa Rate (x${nights} days)`, villa);
        if (stayType > 0) this.appendSummaryRow('Overnight Upgrade', stayType);
        
        const extraFee = this.calcExtraPax(this.getEl('villa-guests'), 4, 1000, this.getEl('villa-extra-fee'));
        if (extraFee > 0) { 
            const totalExtra = extraFee * nights; 
            this.state.summary.total += totalExtra; 
            this.appendSummaryRow('Extra Pax Fee', totalExtra); 
        }
    }

    /**
     * ==========================================
     * SUBMISSION & API HANDLING
     * ==========================================
     */
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
        // Validation Guard 1: Missing Guest Info
        const guestName = this.getEl("guest-name")?.value.trim();
        const guestEmail = this.getEl("guest-email")?.value.trim();
        const guestPhone = this.getEl("guest-phone")?.value.trim();

        if (!guestName || !guestEmail || !guestPhone) {
            alert("Please complete the Guest Information section.");
            return;
        }

        // Validation Guard 2: Missing Dates
        if (!this.state.isDatesLocked || !this.state.activeCalendar?.startDate) {
            alert("Please select dates on the calendar and confirm them first!");
            return;
        }

        const context = this.getTabContextData();
        if (!context.roomName) {
            alert("Please ensure a valid specific room/venue is selected.");
            return;
        }

        // Parse Payment Strings for DB
        const schemeVal = this.getEl("payment-scheme")?.value;
        let schemeEnum = "100% Full";
        if (schemeVal === "0.5") schemeEnum = "50% Downpayment";
        if (schemeVal === "0.2") schemeEnum = "20% Reservation";

        const paymentMethod = document.querySelector('input[name="payment-method"]:checked')?.value || "cash";
        const transactionId = this.getEl("transaction-id")?.value.trim() || "";

        if (paymentMethod !== 'cash' && !transactionId) {
            alert("Please provide the Transaction/Reference ID for cashless payments.");
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
        formData.append("total_amount", this.state.summary.total);
        formData.append("payment_scheme", schemeEnum);
        formData.append("payment_method", paymentMethod);
        formData.append("transaction_id", transactionId);

        try {
            btnConfirm.innerText = "PROCESSING...";
            btnConfirm.disabled = true;

            const res = await fetch("actions/bookings/submit_walkin.php", { method: "POST", body: formData });
            const data = await res.text();
            const response = data.split("|");

            if (response[0] === "Success") {
                alert("Walk-in Booking Successful! Reference No: " + response[1]);
                window.location.reload();
            } else {
                throw new Error(response[1]);
            }
        } catch (error) {
            alert("Error: " + error.message);
            btnConfirm.innerText = "CONFIRM WALK-IN BOOKING";
            btnConfirm.disabled = false;
        }
    }
}

// Instantiate Controller on Load
document.addEventListener("DOMContentLoaded", () => {
    window.WalkinSystem = new AdminWalkinController();
});