/**
 * ==========================================================================
 * SEVILLA360 - Admin Walk-In Booking Controller
 * - Uses CMS images via data-img attributes (no hardcoded Unsplash URLs)
 * - Hotel rooms: individual physical room selection via venue_id
 * - Hotel room add-ons: real inventory via selected room groups
 * - Smart-Sync Line Items for Admin Negotiation
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
        // Image swap via data-img (no Unsplash imageMap)
        this.setupImageSwap("event-venue", "event-img");
        this.setupImageSwap("villa-type", "villa-img");

        const hotelTypeSelect = this.getEl("hotel-room-type");
        if (hotelTypeSelect) {
            hotelTypeSelect.addEventListener("change", (e) => this.populateSpecificHotelRooms(e.target.value));
            this.getEl("hotel-room-name").addEventListener('change', (e) => {
                if (this.state.calendars.hotel) this.state.calendars.hotel.clearSelection();
                this.calculateSummary();
                const opt = e.target.options[e.target.selectedIndex];
                const label = document.getElementById("sum-ht-type");
                if (label) label.innerText = opt.dataset.display || opt.dataset.name || opt.text.split('(')[0].trim();
                // Update hotel image from data-img (CMS-backed)
                const hotelImg = this.getEl('hotel-img');
                if (hotelImg && opt.dataset.img) {
                    hotelImg.style.opacity = '0';
                    setTimeout(() => { hotelImg.src = opt.dataset.img; hotelImg.style.opacity = '1'; }, 300);
                }
                // Fetch dates using group info
                if (opt.dataset.type && opt.dataset.name && this.state.calendars.hotel) {
                    this.state.calendars.hotel.fetchBookedDates(opt.dataset.type, opt.dataset.name);
                }
            });
        }

        this.getEl('event-venue')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            const venueName = opt.text.split('(')[0].trim();
            const label = document.getElementById("sum-ev-venue");
            if (label) label.innerText = venueName;

            if (this.state.calendars.event) this.state.calendars.event.fetchBookedDates('Event Hall', venueName);

            const styleSelect = this.getEl('event-style');
            if (styleSelect && opt.dataset.theater) {
                styleSelect.options[0].text = `Theater Style (${opt.dataset.theater} pax)`;
                styleSelect.options[1].text = `Classroom Style (${opt.dataset.classroom} pax)`;
                styleSelect.options[2].text = `Banquet Type (${opt.dataset.banquet} pax)`;
                
                const guestInput = this.getEl('event-guests');
                if (guestInput) {
                    const maxCap = Math.max(opt.dataset.theater, opt.dataset.classroom, opt.dataset.banquet);
                    guestInput.setAttribute('max', maxCap);
                }
            }
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
                this.calculateSummary();
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
        // HOTEL ROOM ADD-ON: "Add" buttons on room group cards
        // =========================================================================
        document.querySelectorAll('.btn-add-room-group').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const groupKey = e.target.dataset.groupKey;
                this.addRoomGroupToSelection(groupKey);
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

    // =========================================================================
    // HOTEL ROOM ADD-ON MANAGEMENT
    // =========================================================================
    addRoomGroupToSelection(groupKey) {
        const container = document.getElementById('selected-room-groups');
        if (!container) return;

        if (container.querySelector(`[data-sel-key="${CSS.escape(groupKey)}"]`)) {
            showAlert('Notice', 'This room group is already selected. Adjust the quantity in the line items.');
            return;
        }

        const card = document.querySelector(`.room-group-card[data-group-key="${CSS.escape(groupKey)}"]`);
        if (!card) return;

        const building  = card.dataset.building;
        const roomType  = card.dataset.roomType;
        const rate      = parseFloat(card.dataset.rate);
        const inventory = parseInt(card.dataset.inventory) || 1;

        const row = document.createElement('div');
        row.className = 'wi-row selected-room-row';
        row.setAttribute('data-sel-key', groupKey);
        row.setAttribute('data-building', building);
        row.setAttribute('data-room-type', roomType);
        row.setAttribute('data-rate', rate);
        row.style.cssText = 'display:flex; gap:10px; margin-bottom:10px; align-items:center;';
        row.innerHTML = `
            <div style="flex:1; display:flex; flex-direction:column;">
                <strong style="font-size:0.95rem; color:#333;">${building} — ${roomType}</strong>
                <small style="color:#666;">₱${rate.toLocaleString()}/night</small>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="font-size:0.85rem; font-weight:600; color:#555;">Qty:</label>
                <input type="number" class="sel-room-qty" min="1" max="${inventory}" value="1"
                    style="width: 60px; padding: 6px; border: 1px solid #ccc; border-radius: 4px; text-align: center;">
                <button type="button" class="btn-remove-room-sel" style="width: 32px; height: 32px; background: #fee2e2; color: #dc2626; border: none; border-radius: 4px; cursor: pointer; display:flex; align-items:center; justify-content:center;" title="Remove"><i class="fa-solid fa-times"></i></button>
            </div>
        `;
        container.appendChild(row);

        // Also add to line items builder for admin negotiation
        this.syncSystemLineItem(`room_${groupKey}`, `${building} — ${roomType} (×1)`, rate * 1);

        row.querySelector('.sel-room-qty').addEventListener('input', (e) => {
            const qty = parseInt(e.target.value) || 1;
            const days = this.state.calendars.event?.totalNights || 1;
            this.syncSystemLineItem(`room_${groupKey}`, `${building} — ${roomType} (×${qty} rooms)`, rate * qty * days);
            this.calculateSummary();
        });
        row.querySelector('.btn-remove-room-sel').addEventListener('click', () => {
            row.remove();
            this.syncSystemLineItem(`room_${groupKey}`, '', 0);
            this.calculateSummary();
        });

        this.calculateSummary();
    }
    // =========================================================================

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

    // Image swap: reads data-img attribute from selected option
    setupImageSwap(selectId, imgId) {
        const select = this.getEl(selectId);
        const img = this.getEl(imgId);
        if (!select || !img) return;

        select.addEventListener("change", (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            const imgSrc = opt.dataset.img || 'assets/img/placeholder.jpg';
            img.style.opacity = "0";
            setTimeout(() => {
                img.src = imgSrc;
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

        nameSelect.innerHTML = '<option value="" disabled selected>Select a building...</option>';
        rooms.forEach((room) => {
            const opt = document.createElement("option");
            opt.value = room.nightly_rate;
            opt.dataset.type     = room.room_type;
            opt.dataset.name     = room.building_name;
            opt.dataset.inventory = room.total_inventory;
            opt.dataset.img      = room.image || 'assets/img/placeholder.jpg';
            opt.dataset.display  = `${room.building_name}`;
            opt.dataset.baseCap  = room.base_capacity;
            opt.dataset.extraPax = room.extra_pax_rate;
            opt.textContent = `${room.building_name} (${room.total_inventory} Units) — ₱${parseInt(room.nightly_rate).toLocaleString()}/night`;
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

        // Update room availability labels in add-on panel
        this.updateRoomAvailabilityLabels(startDate, endDate);

        this.calculateSummary();
    }

    async updateRoomAvailabilityLabels(startDate, endDate) {
        if (!startDate) return;
        const start = this.formatSafeDate(startDate);
        const end   = endDate ? this.formatSafeDate(endDate) : start;

        document.querySelectorAll('.room-group-card').forEach(async (card) => {
            const building = card.dataset.building;
            const roomType = card.dataset.roomType;
            const label    = card.querySelector('.room-avail-label');
            const addBtn   = card.querySelector('.btn-add-room-group');
            if (!label) return;

            try {
                const url = `actions/bookings/get_room_availability.php?building_name=${encodeURIComponent(building)}&room_type=${encodeURIComponent(roomType)}&start_date=${start}&end_date=${end}`;
                const res  = await fetch(url);
                const data = await res.json();
                if (data.success) {
                    const n = data.available;
                    label.style.color = n > 0 ? '#2a7a3b' : '#c0392b';
                    label.textContent = n > 0 ? `${n} room${n > 1 ? 's' : ''} available` : 'No rooms available';
                    if (addBtn) addBtn.disabled = (n === 0);
                    card.dataset.available = n;
                }
            } catch(e) {}
        });
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

        if (amount <= 0) {
            if (row) row.remove();
            return;
        }

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

            row.querySelector(".wi-item-cost").addEventListener("input", () => {
                row.setAttribute("data-edited", "true");
                this.calculateSummary();
            });
        } 
        else {
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

        this.getEl('summary-breakdown').innerHTML = this.state.summary.html || '<div class="summary-row"><span>No items selected</span></div>';
        this.getEl('summary-total-val').textContent = this.formatCurrency(this.state.summary.total);

        if (!this.state.isDatesLocked || !this.state.activeCalendar?.startDate) {
            this.getEl('summary-due-val').textContent = "₱0.00";
            return;
        }

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

        // Get extra pax rate from DB data attributes
        const nameSelect = this.getEl('hotel-room-name');
        const selectedOpt = nameSelect?.options[nameSelect?.selectedIndex];
        const baseCap = parseInt(selectedOpt?.dataset.baseCap) || 2;
        const extraPaxRate = parseFloat(selectedOpt?.dataset.extraPax) || 800;

        const extraFee = this.calcExtraPax(this.getEl('hotel-guests'), baseCap, extraPaxRate, this.getEl('hotel-extra-fee'));
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

        // EVENT TYPE UPGRADE FEE
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
                this.syncSystemLineItem('type', '', 0);
            }
        }
        
        // EVENT STYLE UPGRADE FEE
        const styleSelect = this.getEl('event-style');
        if (styleSelect) {
            const stylePrice = this.safeFloat(styleSelect.value);
            const styleText = styleSelect.options[styleSelect.selectedIndex].text.split('(+')[0].trim();
            if (stylePrice > 0) {
                this.state.summary.total += stylePrice;
                this.syncSystemLineItem('style', `Event Style: ${styleText}`, stylePrice);
            } else {
                this.syncSystemLineItem('style', '', 0);
            }
        }

        // CATERING ADD-ON
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

        // A/V SETUP ADD-ON
        let avTotal = 0;
        const avCheckbox = this.getEl('check-av');
        if (avCheckbox?.checked) {
            avTotal = this.safeFloat(avCheckbox.value);
        }
        this.syncSystemLineItem('av', 'Premium A/V Setup', avTotal);

        // NOTE: Hotel room add-on totals are tracked in wi-line-items and summed
        // in the main calculateSummary loop (syncSystemLineItem with room_ prefix).
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
        
        // Get extra pax from data attributes
        const villaSelect = this.getEl('villa-type');
        const villaOpt = villaSelect?.options[villaSelect?.selectedIndex];
        const villaCap = parseInt(villaOpt?.dataset.baseCap) || 4;
        const villaExtraPax = parseFloat(villaOpt?.dataset.extraPax) || 1000;

        const extraFee = this.calcExtraPax(this.getEl('villa-guests'), villaCap, villaExtraPax, this.getEl('villa-extra-fee'));
        if (extraFee > 0) { 
            const totalExtra = extraFee * nights; 
            this.state.summary.total += totalExtra; 
            this.appendSummaryRow('Extra Pax Fee', totalExtra); 
        }
    }

    getTabContextData() {
        const context = { roomType: '', roomName: '', venueId: null, baseAmt: 0, guests: 0 };

        if (this.state.activeTabId === 'tab-hotel') {
            const nameSelect = this.getEl('hotel-room-name');
            const opt = nameSelect?.options[nameSelect?.selectedIndex];
            context.venueId  = opt?.dataset.venueId || null;
            context.roomType = opt?.dataset.type;
            context.roomName = opt?.dataset.name;
            context.baseAmt  = opt?.value;
            context.guests   = this.getEl('hotel-guests')?.value;

        } else if (this.state.activeTabId === 'tab-event') {
            const opt = this.getEl('event-venue')?.options[this.getEl('event-venue')?.selectedIndex];
            context.roomType = 'Event Hall';
            context.roomName = opt?.dataset.name || opt?.text.split('(')[0].trim();
            context.baseAmt  = opt?.value;
            context.guests   = this.getEl('event-guests')?.value;

        } else if (this.state.activeTabId === 'tab-villa') {
            const opt = this.getEl('villa-type')?.options[this.getEl('villa-type')?.selectedIndex];
            context.roomType = 'Resort Villa';
            context.roomName = opt?.dataset.name || opt?.text.split('(')[0].trim();
            context.baseAmt  = opt?.value;
            context.guests   = this.getEl('villa-guests')?.value;
        }
        return context;
    }

    async submitWalkinBooking() {
        const guestName  = this.getEl("guest-name")?.value.trim();
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
        if (!context.roomName && !context.venueId) { showAlert("Notice", "Please ensure a valid specific room/venue is selected."); return; }

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
        formData.append("guest_name",  guestName);
        formData.append("guest_email", guestEmail);
        formData.append("guest_phone", guestPhone);
        formData.append("room_type",   context.roomType);
        formData.append("room_name",   context.roomName || '');

        // Hotel rooms: pass venue_id directly
        if (context.venueId) formData.append("venue_id", context.venueId);

        formData.append("start_date",      this.formatSafeDate(this.state.activeCalendar.startDate));
        formData.append("end_date",        this.state.activeCalendar.endDate ? this.formatSafeDate(this.state.activeCalendar.endDate) : this.formatSafeDate(this.state.activeCalendar.startDate));
        formData.append("guests",          context.guests || 0);
        formData.append("base_amount",     context.baseAmt || 0);
        formData.append("total_amount",    this.state.summary.total);
        formData.append("payment_scheme",  schemeEnum);
        formData.append("payment_method",  paymentMethod);
        formData.append("transaction_id",  transactionId);

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

        // Grab all custom line items from the builder
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

        // Hotel room groups for event add-on (server allocates specific rooms)
        let roomGroups = [];
        document.querySelectorAll('.selected-room-row').forEach(row => {
            const building = row.dataset.building;
            const roomType = row.dataset.roomType;
            const qty      = parseInt(row.querySelector('.sel-room-qty')?.value) || 0;
            if (building && roomType && qty > 0) {
                roomGroups.push({ building_name: building, room_type: roomType, quantity: qty });
            }
        });
        if (roomGroups.length > 0) {
            formData.append('room_groups', JSON.stringify(roomGroups));
        }

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