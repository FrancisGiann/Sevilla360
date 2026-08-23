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
            pendingDateConfirmation: null,
            addonConfirmedRange: null,
            addonCommittedSelection: [],
            addonLocksHeld: false,
            primaryHoldExpiresAt: null,
            addonHoldExpiresAt: null,
            holdWarningShown: false,
            holdWarningExpiryKey: null,
            holdExpiryNoticeShown: false,
            holdExpiryNoticeExpiryKey: null,
            summary: { total: 0, amountDue: 0, html: '' },
            calendars: {}
        };

        this.addonSyncPromise = null;
        this.addonSyncDesired = null;
        this.addonSyncWaiters = [];
        this.addonSyncGeneration = 0;
        this.addonCommittedRange = null;
        this.addonServerUncertain = false;
        this.addonReleasePromise = null;
        this.addonResetPromise = null;
        this.fullUnlockPromise = null;
        this.tabSwitchPromise = null;
        this.addonQuantityTimers = new WeakMap();
        this.addonAvailabilityRequestId = 0;
        this.lockExtensionPromise = null;
        this.holdWarningPromise = null;
        this.holdWarningToken = null;
        this.holdPromptDismissedUntil = 0;
        this.lockCountdownInterval = null;
        this.dateModalEscapeBound = false;
        this.dateConfirmationInFlight = false;
        window.isDatesLocked = false;

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
        this.updateAdminNotesVisibility();
    }

    initCalendars() {
        if (typeof SevillaCalendar !== 'undefined') {
            this.state.calendars.event = new SevillaCalendar("cal-ui-event");
            this.state.calendars.hotel = new SevillaCalendar("cal-ui-hotel");
            this.state.calendars.villa = new SevillaCalendar("cal-ui-villa");
            this.state.calendars.addonHotel = new SevillaCalendar("cal-ui-addon-hotel", {
                requireHotelRules: true,
                allowSelectionWhilePrimaryLocked: true,
                onRangeSelected: (start, end) => this.handleAddonRangeSelected(start, end)
            });
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
            this.getEl("hotel-room-name").addEventListener('change', async (e) => {
                if (!await this.unlockDatesAPI()) return;
                if (this.state.calendars.hotel) this.state.calendars.hotel.clearSelection();
                this.calculateSummary();
                const opt = e.target.options[e.target.selectedIndex];
                this.updateHotelInformation(opt);
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

        this.getEl('event-venue')?.addEventListener('change', async (e) => {
            if (!await this.unlockDatesAPI()) return;
            if (this.state.calendars.event) this.state.calendars.event.clearSelection();
            await this.resetAddonStayDates({ release: false });
            
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
                    const selectedCapacity = parseInt(opt.dataset[styleSelect.value], 10) || 0;
                    guestInput.setAttribute('max', selectedCapacity || Math.max(opt.dataset.theater, opt.dataset.classroom, opt.dataset.banquet));
                }
            }
            const styleSelectForCapacity = this.getEl('event-style');
            const eventGuestInput = this.getEl('event-guests');
            if (styleSelectForCapacity && eventGuestInput) {
                styleSelectForCapacity.onchange = () => {
                    const selectedCapacity = parseInt(opt.dataset[styleSelectForCapacity.value], 10) || 0;
                    eventGuestInput.setAttribute('max', selectedCapacity || '');
                };
                styleSelectForCapacity.dispatchEvent(new Event('change'));
            }
        });

        this.getEl('villa-type')?.addEventListener('change', async (e) => {
            if (!await this.unlockDatesAPI()) return;
            if (this.state.calendars.villa) this.state.calendars.villa.clearSelection();

            const opt = e.target.options[e.target.selectedIndex];
            const villaName = opt.text.split('(')[0].trim();
            const label = document.getElementById("sum-vl-type");
            if (label) label.innerText = villaName;
            const extraRateLabel = this.getEl('villa-extra-rate');
            if (extraRateLabel) extraRateLabel.textContent = this.formatCurrency(parseFloat(opt.dataset.extraPax) || 0);

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
        this.getEl('check-rooms')?.addEventListener('change', async (e) => {
            if (e.target.checked) {
                if (this.addonReleasePromise || this.addonResetPromise) {
                    e.target.checked = false;
                    this.getEl('rooms-options')?.classList.add('hidden');
                    return;
                }
                this.suggestAddonStayDates();
            }
            else if (!await this.resetAddonStayDates()) {
                e.target.checked = true;
                this.getEl('rooms-options')?.classList.remove('hidden');
            }
        });

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
            btn.addEventListener('click', async (e) => {
                const groupKey = e.currentTarget.dataset.groupKey;
                await this.addRoomGroupToSelection(groupKey);
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
    getAddonStayRange() {
        const range = this.state.addonConfirmedRange;
        if (!range?.start || !range?.end) return null;
        const start = new Date(range.start);
        const end = new Date(range.end);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) return null;
        return { start, end, nights: Math.round((end - start) / 86400000) };
    }

    handleAddonRangeSelected(start, end) {
        if (!(start instanceof Date) || !(end instanceof Date) || end <= start) {
            showAlert('Notice', 'Hotel room add-ons require a stay of at least one night.');
            this.state.calendars.addonHotel?.clearSelectedRange();
            return;
        }
        this.openDateConfirmation('addon', start, end, this.state.calendars.addonHotel);
    }

    suggestAddonStayDates() {
        if (this.addonReleasePromise || this.addonResetPromise) return;
        const calendar = this.state.calendars.addonHotel;
        if (!calendar || this.getAddonStayRange()) return;
        const event = this.state.calendars.event;
        if (!event?.startDate) return;
        const start = new Date(event.startDate);
        const end = event.endDate && event.endDate > start ? new Date(event.endDate) : new Date(start);
        if (end.getTime() === start.getTime()) end.setDate(end.getDate() + 1);
        calendar.setSelection(start, end);
        this.handleAddonRangeSelected(start, end);
    }

    async resetAddonStayDates({ release = true } = {}) {
        if (this.addonResetPromise) return this.addonResetPromise;
        const request = (async () => {
            if (release && !(await this.releaseAddonLocksAPI())) return false;
            const calendar = this.state.calendars.addonHotel;
            if (calendar) { calendar.startDate = null; calendar.endDate = null; calendar.render(); }
            const display = this.getEl('addon-room-date-display');
            if (display) display.textContent = 'Select a stay of at least 1 night';
            document.querySelectorAll('.selected-room-row').forEach(row => {
                this.syncSystemLineItem(`room_${row.dataset.selKey}`, '', 0);
            });
            this.getEl('selected-room-groups')?.replaceChildren();
            this.state.addonConfirmedRange = null;
            this.state.addonCommittedSelection = [];
            this.state.addonLocksHeld = false;
            this.state.addonHoldExpiresAt = null;
            this.state.holdWarningShown = false;
            this.state.holdWarningExpiryKey = null;
            this.state.holdExpiryNoticeShown = false;
            this.state.holdExpiryNoticeExpiryKey = null;
            this.addonCommittedRange = null;
            this.addonServerUncertain = false;
            this.calculateSummary();
            if (!this.state.isDatesLocked) this.stopHoldCountdown();
            return true;
        })();
        this.addonResetPromise = request;
        try { return await request; }
        finally { this.addonResetPromise = null; }
    }

    syncSelectedRoomLineItems() {
        const nights = this.getAddonStayRange()?.nights || 0;
        document.querySelectorAll('.selected-room-row').forEach(row => {
            const qty = parseInt(row.querySelector('.sel-room-qty')?.value) || 1;
            const rate = parseFloat(row.dataset.rate) || 0;
            const key = row.dataset.selKey;
            this.syncSystemLineItem(`room_${key}`, `${row.dataset.building} — ${row.dataset.roomType} (×${qty} rooms, ×${nights} nights)`, rate * qty * nights);
        });
    }

    captureAddonSelection() {
        return Array.from(document.querySelectorAll('.selected-room-row')).map(row => ({
            key: row.dataset.selKey || '',
            building_name: row.dataset.building || '',
            room_type: row.dataset.roomType || '',
            rate: parseFloat(row.dataset.rate) || 0,
            quantity: Math.max(1, parseInt(row.querySelector('.sel-room-qty')?.value, 10) || 1),
            max: parseInt(row.querySelector('.sel-room-qty')?.max, 10) || 1
        })).filter(item => item.key && item.building_name && item.room_type);
    }

    createSelectedRoomRow(selection) {
        const container = document.getElementById('selected-room-groups');
        if (!container || !selection?.key) return null;

        const row = document.createElement('div');
        row.className = 'wi-row selected-room-row';
        row.setAttribute('data-sel-key', selection.key);
        row.setAttribute('data-building', selection.building_name);
        row.setAttribute('data-room-type', selection.room_type);
        row.setAttribute('data-rate', selection.rate);
        row.style.cssText = 'display:flex; gap:10px; margin-bottom:10px; align-items:center;';
        row.innerHTML = `
            <div style="flex:1; display:flex; flex-direction:column;">
                <strong style="font-size:0.95rem; color:#333;"></strong>
                <small style="color:#666;"></small>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="font-size:0.85rem; font-weight:600; color:#555;">Qty:</label>
                <input type="number" class="sel-room-qty" min="1" max="${selection.max || 1}" value="${selection.quantity || 1}"
                    style="width: 60px; padding: 6px; border: 1px solid #ccc; border-radius: 4px; text-align: center;">
                <button type="button" class="btn-remove-room-sel" style="width: 32px; height: 32px; background: #fee2e2; color: #dc2626; border: none; border-radius: 4px; cursor: pointer; display:flex; align-items:center; justify-content:center;" title="Remove"><i class="fa-solid fa-times"></i></button>
            </div>
        `;
        row.querySelector('strong').textContent = `${selection.building_name} — ${selection.room_type}`;
        row.querySelector('small').textContent = `₱${selection.rate.toLocaleString()}/night`;
        container.appendChild(row);

        const qtyInput = row.querySelector('.sel-room-qty');
        qtyInput.addEventListener('input', (e) => {
            const max = parseInt(e.target.max, 10) || 1;
            e.target.value = Math.min(max, Math.max(1, parseInt(e.target.value, 10) || 1));
            this.syncSelectedRoomLineItems();
            this.calculateSummary();
            clearTimeout(this.addonQuantityTimers.get(row));
            this.addonQuantityTimers.set(row, setTimeout(async () => {
                const outcome = await this.syncAddonSelection('quantity');
                if (!outcome.ok && !outcome.stale && !outcome.blocked) {
                    // The queue restores the committed snapshot on its final
                    // failure; refresh derived line items after that rollback.
                    this.syncSelectedRoomLineItems();
                    this.calculateSummary();
                }
            }, 350));
        });

        row.querySelector('.btn-remove-room-sel').addEventListener('click', async () => {
            const previous = this.state.addonCommittedSelection.map(item => ({ ...item }));
            clearTimeout(this.addonQuantityTimers.get(row));
            row.remove();
            this.syncSystemLineItemsForSelection();
            this.calculateSummary();
            const outcome = await this.syncAddonSelection('remove');
            if (!outcome.ok && !outcome.stale && !outcome.blocked) this.restoreAddonSelection(previous);
        });

        this.syncSelectedRoomLineItems();
        return row;
    }

    syncSystemLineItemsForSelection() {
        const selectedKeys = new Set();
        this.captureAddonSelection().forEach(item => selectedKeys.add(`room_${item.key}`));
        document.querySelectorAll('#wi-line-items .wi-row[data-system^="room_"]').forEach(row => {
            if (!selectedKeys.has(row.dataset.system)) row.remove();
        });
        this.syncSelectedRoomLineItems();
    }

    restoreAddonSelection(selection) {
        const container = this.getEl('selected-room-groups');
        if (!container) return;
        container.replaceChildren();
        selection.forEach(item => this.createSelectedRoomRow(item));
        this.state.addonCommittedSelection = selection.map(item => ({ ...item }));
        this.syncSystemLineItemsForSelection();
        this.calculateSummary();
    }

    async addRoomGroupToSelection(groupKey) {
        if (this.fullUnlockPromise || this.addonReleasePromise || this.addonResetPromise) return false;
        const range = this.getAddonStayRange();
        if (!range) {
            showAlert('Notice', 'Confirm a hotel check-in and check-out range before adding rooms.');
            return false;
        }

        const container = this.getEl('selected-room-groups');
        if (!container) return false;
        const escapedKey = CSS.escape(groupKey);
        if (container.querySelector(`[data-sel-key="${escapedKey}"]`)) {
            showAlert('Notice', 'This room group is already selected. Adjust the quantity in the line items.');
            return false;
        }

        const card = document.querySelector(`.room-group-card[data-group-key="${escapedKey}"]`);
        if (!card) return false;

        const available = parseInt(card.dataset.available ?? card.dataset.inventory, 10) || 0;
        if (available < 1) {
            showAlert('Notice', 'No rooms are available for this hotel stay.');
            return false;
        }

        const previous = this.state.addonCommittedSelection.map(item => ({ ...item }));
        const selection = {
            key: groupKey,
            building_name: card.dataset.building || '',
            room_type: card.dataset.roomType || '',
            rate: parseFloat(card.dataset.rate) || 0,
            quantity: 1,
            max: available
        };
        this.createSelectedRoomRow(selection);
        this.calculateSummary();
        const outcome = await this.syncAddonSelection('add');
        if (!outcome.ok && !outcome.stale && !outcome.blocked) this.restoreAddonSelection(previous);
        return outcome.ok;
    }

    cloneAddonSelection(selection) {
        return (selection || []).map(item => ({ ...item }));
    }

    cloneAddonRange(range) {
        if (!range?.start || !range?.end) return null;
        const start = new Date(range.start);
        const end = new Date(range.end);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) return null;
        return { start, end, nights: Math.round((end - start) / 86400000) };
    }

    getCommittedAddonSnapshot() {
        return {
            selection: this.cloneAddonSelection(this.state.addonCommittedSelection),
            range: this.cloneAddonRange(this.addonCommittedRange)
        };
    }

    async postAddonBatch(snapshot) {
        const formData = new FormData();
        formData.append('start_date', this.formatSafeDate(snapshot.range.start));
        formData.append('end_date', this.formatSafeDate(snapshot.range.end));
        formData.append('groups', JSON.stringify(snapshot.selection.map(item => ({
            building_name: item.building_name,
            room_type: item.room_type,
            quantity: item.quantity
        }))));
        formData.append('source', 'walkin');
        try {
            const res = await fetch('actions/bookings/lock_addon_rooms.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfToken },
                body: formData
            });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data?.success) {
                return { ok: false, error: new Error(data?.message || 'The selected hotel rooms could not be temporarily held.') };
            }
            return { ok: true, expiresAt: this.normalizeHoldExpiry(data.expires_at) };
        } catch (error) {
            return { ok: false, error };
        }
    }

    async postAddonRelease() {
        const formData = new FormData();
        formData.append('release_only', '1');
        formData.append('source', 'walkin');
        try {
            const res = await fetch('actions/bookings/lock_addon_rooms.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfToken },
                body: formData
            });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data?.success) {
                return { ok: false, error: new Error(data?.message || 'Room holds could not be released.') };
            }
            return { ok: true };
        } catch (error) {
            return { ok: false, error };
        }
    }

    commitAddonSnapshot(snapshot, expiresAt = undefined) {
        this.state.addonCommittedSelection = this.cloneAddonSelection(snapshot.selection);
        this.addonCommittedRange = this.state.addonCommittedSelection.length ? this.cloneAddonRange(snapshot.range) : null;
        this.state.addonLocksHeld = this.state.addonCommittedSelection.length > 0;
        if (this.state.addonLocksHeld) {
            if (expiresAt !== undefined) this.state.addonHoldExpiresAt = expiresAt;
            if (!this.state.addonHoldExpiresAt) this.state.addonHoldExpiresAt = Date.now() + (60 * 60 * 1000);
        } else {
            this.state.addonHoldExpiresAt = null;
        }
        this.addonServerUncertain = false;
        if (this.state.addonLocksHeld) {
            this.state.holdWarningShown = false;
            this.state.holdWarningExpiryKey = null;
            this.state.holdExpiryNoticeShown = false;
            this.state.holdExpiryNoticeExpiryKey = null;
        }
        this.toggleLockBanner(this.state.isDatesLocked || this.state.addonLocksHeld);
        if (this.state.addonLocksHeld) this.startHoldCountdown();
        else if (!this.state.isDatesLocked) this.stopHoldCountdown();
    }

    restoreCommittedAddonUI(snapshot) {
        this.restoreAddonSelection(snapshot.selection);
        this.state.addonLocksHeld = snapshot.selection.length > 0 && !this.addonServerUncertain;
        if (!this.state.addonLocksHeld) this.state.addonHoldExpiresAt = null;
        this.toggleLockBanner(this.state.isDatesLocked || this.state.addonLocksHeld);
    }

    resolveAddonWaiters(generation, result) {
        const remaining = [];
        this.addonSyncWaiters.forEach(waiter => {
            if (waiter.generation <= generation) waiter.resolve({ ...result });
            else remaining.push(waiter);
        });
        this.addonSyncWaiters = remaining;
    }

    async reconcileAddonSnapshot(snapshot) {
        if (!snapshot.selection.length) return this.postAddonRelease();
        if (!snapshot.range) return { ok: false };
        return this.postAddonBatch(snapshot);
    }

    async runAddonSyncQueue() {
        try {
            while (this.addonSyncDesired) {
                const target = this.addonSyncDesired;
                this.addonSyncDesired = null;
                const previous = this.getCommittedAddonSnapshot();
                const response = await this.postAddonBatch(target);
                let result;
                if (response.ok) {
                    this.commitAddonSnapshot(target, response.expiresAt);
                    result = { ok: true, stale: false, blocked: false };
                } else {
                    const reconciliation = await this.reconcileAddonSnapshot(previous);
                    const reconciled = reconciliation.ok;
                    if (reconciled) {
                        this.commitAddonSnapshot(previous, reconciliation.expiresAt);
                    } else {
                        this.addonServerUncertain = true;
                        this.state.addonLocksHeld = false;
                        this.state.addonHoldExpiresAt = null;
                        this.toggleLockBanner(this.state.isDatesLocked);
                    }
                    // A newer generation may have changed the UI while this
                    // request and its reconciliation were in flight. Leave
                    // that desired state intact; only the final failure may
                    // roll the UI back to the server-confirmed snapshot.
                    if (!this.addonSyncDesired) {
                        this.restoreCommittedAddonUI(previous);
                        if (!reconciled) {
                            this.state.addonLocksHeld = false;
                            this.state.addonHoldExpiresAt = null;
                            this.toggleLockBanner(this.state.isDatesLocked);
                        }
                    }
                    result = { ok: false, stale: false, blocked: false, error: response.error };
                    if (!this.addonSyncDesired) {
                        showAlert('Room Hold Failed', response.error?.message || 'The selected hotel rooms could not be held.', 'error');
                    }
                }

                if (this.addonSyncDesired) {
                    this.resolveAddonWaiters(target.generation, { ok: false, stale: true, blocked: false });
                } else {
                    this.resolveAddonWaiters(target.generation, result);
                }
            }
        } finally {
            this.addonSyncPromise = null;
        }
    }

    enqueueAddonSync(snapshot, reason) {
        const generation = ++this.addonSyncGeneration;
        const target = {
            generation,
            selection: this.cloneAddonSelection(snapshot.selection),
            range: this.cloneAddonRange(snapshot.range),
            reason
        };
        const waiter = new Promise(resolve => this.addonSyncWaiters.push({ generation, resolve }));
        this.addonSyncDesired = target;
        if (!this.addonSyncPromise) this.addonSyncPromise = this.runAddonSyncQueue();
        return waiter;
    }

    async syncAddonSelection(reason = 'selection', rangeOverride = null) {
        if (this.fullUnlockPromise || this.addonReleasePromise || this.addonResetPromise) {
            return { ok: false, stale: false, blocked: true };
        }
        const selection = this.captureAddonSelection();
        const range = rangeOverride || this.getAddonStayRange();
        if (!selection.length) {
            const released = await this.releaseAddonLocksAPI();
            return { ok: released, stale: false, blocked: false };
        }
        if (!range) {
            showAlert('Notice', 'Confirm a valid hotel stay before holding room inventory.');
            this.restoreAddonSelection(this.state.addonCommittedSelection);
            return { ok: false, stale: false, blocked: false };
        }
        return this.enqueueAddonSync({ selection, range }, reason);
    }

    async releaseAddonLocksAPI() {
        if (this.addonReleasePromise) return this.addonReleasePromise;
        const request = (async () => {
            // Supersede any queued (not yet dispatched) batch. An in-flight
            // request is allowed to finish; the release follows it on the same
            // serialized chain so its response cannot be mistaken for success.
            this.addonSyncDesired = null;
            this.resolveAddonWaiters(Number.MAX_SAFE_INTEGER, { ok: false, stale: true, blocked: true });
            const pending = this.addonSyncPromise;
            if (pending) {
                try { await pending; } catch (error) { /* release still needs to be attempted */ }
            }
            const previous = this.getCommittedAddonSnapshot();
            const previousHeld = this.state.addonLocksHeld;
            const response = await this.postAddonRelease();
            if (response.ok) {
                this.state.addonLocksHeld = false;
                this.state.addonHoldExpiresAt = null;
                this.state.holdWarningShown = false;
                this.state.holdWarningExpiryKey = null;
                this.state.holdExpiryNoticeShown = false;
                this.state.holdExpiryNoticeExpiryKey = null;
                this.state.addonCommittedSelection = [];
                this.addonCommittedRange = null;
                this.addonServerUncertain = false;
                this.toggleLockBanner(this.state.isDatesLocked);
                if (!this.state.isDatesLocked) this.stopHoldCountdown();
                this.addonReleasePromise = null;
                return true;
            }
            this.state.addonLocksHeld = previousHeld;
            this.state.addonCommittedSelection = previous.selection;
            this.addonCommittedRange = previous.range;
            this.toggleLockBanner(this.state.isDatesLocked || this.state.addonLocksHeld);
            showAlert('Room Hold Release Failed', response.error?.message || 'Room holds could not be released. Please try again.', 'error');
            this.addonReleasePromise = null;
            return false;
        })();
        this.addonReleasePromise = request;
        return request;
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
            showConfirm("Confirm Cancellation", "Are you sure you want to clear this booking form?").then(async confirmed => {
                if (confirmed) {
                    if (await this.unlockDatesAPI()) window.location.reload();
                }
            });
        });
    }

    getEl(id) { return document.getElementById(id); }
    replaceElement(id) {
        const oldElement = this.getEl(id);
        if (!oldElement?.parentNode) return null;
        const newElement = oldElement.cloneNode(true);
        oldElement.parentNode.replaceChild(newElement, oldElement);
        return newElement;
    }
    safeFloat(val) { return parseFloat(val) || 0; }
    formatCurrency(amount) { return '₱' + this.safeFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    formatSafeDate(dateObj) { return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`; }

    determineActiveTab() {
        const activeBtn = document.querySelector('.tab-btn.active');
        if (activeBtn) this.state.activeTabId = activeBtn.getAttribute('data-target');
    }

    updateAdminNotesVisibility() {
        const row = this.getEl('walkin-admin-notes-row');
        if (row) row.style.display = this.state.activeTabId === 'tab-event' ? '' : 'none';
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
            opt.dataset.description = room.venue_description || '';
            opt.dataset.amenities = room.venue_amenities || '';
            opt.textContent = `${room.building_name} (${room.total_inventory} Units) — ₱${parseInt(room.nightly_rate).toLocaleString()}/night`;
            nameSelect.appendChild(opt);
        });
        nameSelect.disabled = false;
        this.updateHotelInformation(nameSelect.options[nameSelect.selectedIndex]);
    }

    updateHotelInformation(option) {
        const description = this.getEl('hotel-description');
        const amenities = this.getEl('hotel-amenities');
        if (!description || !amenities || !option) return;
        description.textContent = option.dataset.description || 'No additional description is available for this accommodation.';
        amenities.innerHTML = '';
        const items = (option.dataset.amenities || '').split(/[,\n]+/).map(item => item.trim()).filter(Boolean);
        if (items.length === 0) {
            const empty = document.createElement('li');
            empty.textContent = 'No amenities listed.';
            amenities.appendChild(empty);
        } else {
            items.forEach(item => {
                const li = document.createElement('li');
                li.textContent = item;
                amenities.appendChild(li);
            });
        }
    }

    async handleTabSwitch(btn) {
        if (btn.classList.contains("active") || this.tabSwitchPromise) return;
        const targetId = btn.getAttribute("data-target");
        this.tabSwitchPromise = (async () => {
            if (!await this.unlockDatesAPI()) return;
            await this.resetAddonStayDates({ release: false });
            if (this.state.calendars.event) this.state.calendars.event.clearSelection();
            if (this.state.calendars.hotel) this.state.calendars.hotel.clearSelection();
            if (this.state.calendars.villa) this.state.calendars.villa.clearSelection();

            document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
            document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));

            btn.classList.add("active");
            this.getEl(targetId)?.classList.add("active");
            this.state.activeTabId = targetId;
            this.updateAdminNotesVisibility();

            if (targetId === "tab-event" && this.state.calendars.event) this.state.calendars.event.updateDateDisplay();
            if (targetId === "tab-hotel" && this.state.calendars.hotel) this.state.calendars.hotel.updateDateDisplay();
            if (targetId === "tab-villa" && this.state.calendars.villa) this.state.calendars.villa.updateDateDisplay();

            this.calculateSummary();
        })();
        try { await this.tabSwitchPromise; }
        finally { this.tabSwitchPromise = null; }
    }

    requestDateConfirmation(startDate, endDate, calendarInstance) {
        this.openDateConfirmation('primary', startDate, endDate, calendarInstance);
    }

    openDateConfirmation(kind, startDate, endDate, calendarInstance) {
        if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime())) return;
        const actualEnd = endDate instanceof Date && endDate > startDate ? endDate : startDate;
        if (kind === 'primary') {
            const lockData = this.getTabContextData();
            if (!lockData.roomName && !lockData.venueId) {
                showAlert('Notice', 'Please select a specific venue/room from the dropdown first!');
                calendarInstance?.clearSelection();
                return;
            }
        }

        const dateModal = this.getEl('confirm-dates-modal');
        const dateDisplay = this.getEl('confirm-date-display');
        const title = this.getEl('confirm-dates-title');
        const copy = this.getEl('confirm-dates-copy');
        if (!dateModal || !dateDisplay) return;

        this.state.pendingDateConfirmation = { kind, start: new Date(startDate), end: new Date(actualEnd), calendar: calendarInstance };
        const opts = { month: 'short', day: 'numeric', year: 'numeric' };
        dateDisplay.textContent = `${startDate.toLocaleDateString('en-US', opts)} — ${actualEnd.toLocaleDateString('en-US', opts)}`;
        if (title) title.textContent = kind === 'addon' ? 'Confirm Hotel Stay' : 'Confirm Dates';
        if (copy) copy.textContent = kind === 'addon'
            ? 'Confirming accepts this hotel stay range. Concrete rooms will be temporarily held only after you add them.'
            : 'Proceeding will temporarily hold these dates for 60 minutes while you complete this walk-in booking.';
        dateModal.classList.add('active');

        const confirmBtn = this.replaceElement('btn-confirm-dates');
        const cancelBtn = this.replaceElement('btn-cancel-dates');
        cancelBtn?.addEventListener('click', () => this.cancelPendingDateConfirmation());
        confirmBtn?.addEventListener('click', () => this.confirmPendingDateConfirmation(confirmBtn));

        if (!this.dateModalEscapeBound) {
            this.dateModalEscapeBound = true;
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && this.getEl('confirm-dates-modal')?.classList.contains('active')) {
                    this.cancelPendingDateConfirmation();
                }
            });
        }
    }

    cancelPendingDateConfirmation() {
        if (this.dateConfirmationInFlight) return;
        const pending = this.state.pendingDateConfirmation;
        this.state.pendingDateConfirmation = null;
        this.getEl('confirm-dates-modal')?.classList.remove('active');
        if (!pending?.calendar) return;
        if (pending.kind === 'addon') {
            const confirmed = this.getAddonStayRange();
            if (confirmed) pending.calendar.setSelection(confirmed.start, confirmed.end);
            else pending.calendar.clearSelectedRange();
            this.updateAddonDateDisplay();
        } else {
            pending.calendar.clearSelectedRange();
        }
    }

    async confirmPendingDateConfirmation(confirmBtn) {
        const pending = this.state.pendingDateConfirmation;
        if (!pending || !confirmBtn || confirmBtn.disabled) return;
        this.dateConfirmationInFlight = true;
        confirmBtn.disabled = true;
        this.getEl('btn-cancel-dates')?.setAttribute('disabled', 'disabled');
        confirmBtn.textContent = 'HOLDING...';
        let success = false;
        try {
            if (pending.kind === 'addon') success = await this.confirmAddonDateRange(pending.start, pending.end, pending.calendar);
            else success = await this.lockPrimaryDates(pending.start, pending.end, pending.calendar);
        } finally {
            if (this.state.pendingDateConfirmation === pending) this.state.pendingDateConfirmation = null;
            confirmBtn.disabled = false;
            this.getEl('btn-cancel-dates')?.removeAttribute('disabled');
            confirmBtn.textContent = 'CONFIRM';
            this.dateConfirmationInFlight = false;
            if (success) this.getEl('confirm-dates-modal')?.classList.remove('active');
            else {
                this.getEl('confirm-dates-modal')?.classList.remove('active');
                if (pending.kind === 'primary') pending.calendar?.clearSelectedRange();
            }
        }
    }

    async confirmAddonDateRange(startDate, endDate, calendarInstance) {
        const previousRange = this.getAddonStayRange();
        const selected = this.captureAddonSelection();
        const proposed = { start: new Date(startDate), end: new Date(endDate), nights: Math.round((endDate - startDate) / 86400000) };
        if (this.fullUnlockPromise || this.addonReleasePromise || this.addonResetPromise) {
            if (previousRange) calendarInstance.setSelection(previousRange.start, previousRange.end);
            else calendarInstance.clearSelectedRange();
            this.updateAddonDateDisplay();
            return false;
        }
        if (selected.length) {
            const outcome = await this.syncAddonSelection('date-change', proposed);
            if (!outcome.ok) {
                this.state.addonConfirmedRange = previousRange ? { ...previousRange } : null;
                if (previousRange) calendarInstance.setSelection(previousRange.start, previousRange.end);
                else calendarInstance.clearSelectedRange();
                this.updateAddonDateDisplay();
                return false;
            }
        }
        this.state.addonConfirmedRange = proposed;
        this.updateAddonDateDisplay();
        this.updateRoomAvailabilityLabels(startDate, endDate);
        this.syncSelectedRoomLineItems();
        this.calculateSummary();
        return true;
    }

    async lockPrimaryDates(startDate, endDate, calendarInstance) {
        const lockData = this.getTabContextData();
        if (!lockData.roomName && !lockData.venueId) {
            showAlert('Notice', 'Please select a specific venue/room from the dropdown first!');
            return false;
        }
        const formData = new FormData();
        formData.append('start_date', this.formatSafeDate(startDate));
        formData.append('end_date', this.formatSafeDate(endDate || startDate));
        formData.append('source', 'walkin');
        if (lockData.venueId) formData.append('venue_id', lockData.venueId);
        else {
            formData.append('room_type', lockData.roomType);
            formData.append('room_name', lockData.roomName);
        }

        try {
            const res = await fetch('actions/bookings/lock_dates.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfToken },
                body: formData
            });
            const text = await res.text();
            const response = text.split('|');
            if (res.status === 401) throw Object.assign(new Error('Your admin session has expired. Please sign in again.'), { sessionExpired: true });
            if (!res.ok || response[0] !== 'Success') throw new Error(response[1] || 'Dates could not be held.');

            this.state.activeCalendar = calendarInstance;
            this.state.isDatesLocked = true;
            this.state.primaryHoldExpiresAt = this.normalizeHoldExpiry(response[2]) || (Date.now() + (60 * 60 * 1000));
            this.state.holdWarningShown = false;
            this.state.holdWarningExpiryKey = null;
            this.state.holdExpiryNoticeShown = false;
            this.state.holdExpiryNoticeExpiryKey = null;
            window.isDatesLocked = true;
            calendarInstance?.updateDateDisplay();
            this.toggleLockBanner(true);
            this.startHoldCountdown();
            if (this.state.activeTabId === 'tab-event' && this.getEl('check-rooms')?.checked) this.suggestAddonStayDates();
            this.calculateSummary();
            return true;
        } catch (error) {
            this.state.isDatesLocked = false;
            this.state.primaryHoldExpiresAt = null;
            window.isDatesLocked = false;
            if (this.state.addonLocksHeld && this.getAddonStayRange()) this.startHoldCountdown();
            else this.stopHoldCountdown();
            this.toggleLockBanner(this.state.addonLocksHeld);
            if (error.sessionExpired) showAlert('Session Expired', error.message, 'error', true);
            else showAlert('Dates Hold Failed', error.message || 'Dates could not be held.', 'error');
            if (calendarInstance) {
                const context = this.getTabContextData();
                calendarInstance.fetchBookedDates(context.roomType, context.roomName, context.venueId);
            }
            return false;
        }
    }

    async unlockDatesAPI() {
        if (this.fullUnlockPromise) return this.fullUnlockPromise;
        const request = (async () => {
            this.cancelHoldExtensionPrompt();
            const pendingAddonSync = this.addonSyncPromise;
            if (pendingAddonSync) {
                try { await pendingAddonSync; } catch (error) { /* continue with full release */ }
            }
            if (this.addonReleasePromise) {
                try { await this.addonReleasePromise; } catch (error) { /* full release still verifies its own response */ }
            }
            if (this.lockExtensionPromise) {
                try { await this.lockExtensionPromise; } catch (error) { /* the unlock request still verifies server state */ }
            }
            try {
                const res = await fetch('actions/bookings/unlock_dates.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': this.csrfToken }
                });
                const text = await res.text();
                const response = text.split('|');
                if (!res.ok || response[0] !== 'Success') throw new Error(response[1] || 'Temporary holds could not be released.');
                this.state.isDatesLocked = false;
                this.state.primaryHoldExpiresAt = null;
                window.isDatesLocked = false;
                this.state.addonLocksHeld = false;
                this.state.addonHoldExpiresAt = null;
                this.state.addonCommittedSelection = [];
                this.state.addonConfirmedRange = null;
                this.addonCommittedRange = null;
                this.addonServerUncertain = false;
                this.state.holdWarningShown = false;
                this.state.holdWarningExpiryKey = null;
                this.state.holdExpiryNoticeShown = false;
                this.state.holdExpiryNoticeExpiryKey = null;
                this.toggleLockBanner(false);
                this.stopHoldCountdown();
                return true;
            } catch (error) {
                this.toggleLockBanner(this.state.isDatesLocked || this.state.addonLocksHeld);
                this.presentHoldAlert('Release Failed', error.message || 'Temporary holds could not be released. Please try again.', 'error');
                return false;
            } finally {
                this.fullUnlockPromise = null;
            }
        })();
        this.fullUnlockPromise = request;
        return request;
    }

    normalizeHoldExpiry(value) {
        const timestamp = Number(value);
        if (!Number.isFinite(timestamp) || timestamp <= 0) return null;
        // API responses use Unix seconds; accepting milliseconds keeps this
        // helper safe if a future endpoint returns a JS-style timestamp.
        return timestamp < 1e12 ? timestamp * 1000 : timestamp;
    }

    formatLockCountdown(milliseconds) {
        const totalSeconds = Math.max(0, Math.ceil(milliseconds / 1000));
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    getNearestHoldExpiry() {
        const deadlines = [];
        if (this.state.isDatesLocked && this.state.primaryHoldExpiresAt) deadlines.push(this.state.primaryHoldExpiresAt);
        if (this.state.addonLocksHeld && this.state.addonHoldExpiresAt) deadlines.push(this.state.addonHoldExpiresAt);
        return deadlines.length ? Math.min(...deadlines) : null;
    }

    cancelHoldExtensionPrompt() {
        // Invalidate the continuation first, then dismiss the current global
        // confirmation when available. A late Yes can never issue an extension
        // after release, and callers can defer alerts until its hide animation
        // has finished so they do not share a closing overlay.
        const token = this.holdWarningToken;
        this.holdWarningToken = null;
        let dismissed = false;
        if (token?.cancelButton?.click) {
            this.holdPromptDismissedUntil = Date.now() + 400;
            token.cancelButton.click();
            dismissed = true;
        }
        return dismissed;
    }

    presentHoldAlert(title, message, type = 'error') {
        const announce = () => showAlert(title, message, type);
        const delay = Math.max(0, this.holdPromptDismissedUntil - Date.now());
        if (delay > 0) setTimeout(announce, delay);
        else announce();
    }

    clearAllHoldState() {
        this.state.isDatesLocked = false;
        this.state.primaryHoldExpiresAt = null;
        window.isDatesLocked = false;
        this.state.activeCalendar?.clearSelectedRange?.();
        this.state.activeCalendar = null;
        this.state.pendingDateConfirmation = null;
        this.state.addonLocksHeld = false;
        this.state.addonHoldExpiresAt = null;
        this.addonServerUncertain = true;
        this.state.holdWarningShown = false;
        this.state.holdWarningExpiryKey = null;
        this.state.holdExpiryNoticeShown = true;
        this.state.holdExpiryNoticeExpiryKey = null;
        this.toggleLockBanner(false);
        this.stopHoldCountdown();
        this.calculateSummary();
    }

    applyExtensionResponse(data) {
        const primaryExpiry = this.normalizeHoldExpiry(data?.primary_expires_at);
        const addonExpiry = this.normalizeHoldExpiry(data?.addon_expires_at);

        if (primaryExpiry) {
            this.state.isDatesLocked = true;
            this.state.primaryHoldExpiresAt = primaryExpiry;
            window.isDatesLocked = true;
        } else if (this.state.isDatesLocked) {
            this.state.isDatesLocked = false;
            this.state.primaryHoldExpiresAt = null;
            window.isDatesLocked = false;
            this.state.activeCalendar?.clearSelectedRange?.();
            this.state.activeCalendar = null;
        }

        if (addonExpiry) {
            this.state.addonLocksHeld = true;
            this.state.addonHoldExpiresAt = addonExpiry;
        } else {
            this.state.addonLocksHeld = false;
            this.state.addonHoldExpiresAt = null;
        }

        this.addonServerUncertain = false;
        this.state.holdWarningShown = false;
        this.state.holdWarningExpiryKey = null;
        this.state.holdExpiryNoticeShown = false;
        this.state.holdExpiryNoticeExpiryKey = null;
        this.calculateSummary();
        this.toggleLockBanner(this.state.isDatesLocked || this.state.addonLocksHeld);
        if (this.state.isDatesLocked || this.state.addonLocksHeld) this.startHoldCountdown();
        else this.stopHoldCountdown();
        this.expireActiveHolds();
    }

    async extendWalkinLocksAPI() {
        if (this.lockExtensionPromise) return this.lockExtensionPromise;
        if (this.fullUnlockPromise || this.addonReleasePromise || this.addonResetPromise) {
            return { ok: false, blocked: true };
        }

        const request = (async () => {
            try {
                const res = await fetch('actions/bookings/extend_walkin_locks.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': this.csrfToken }
                });
                const data = await res.json().catch(() => null);
                if (!res.ok || !data?.success) {
                    const noActive = res.status === 409 && /No active walk-in holds/i.test(data?.message || '');
                    if (noActive) this.clearAllHoldState();
                    return { ok: false, noActive, error: new Error(data?.message || 'Temporary holds could not be extended.') };
                }
                this.applyExtensionResponse(data);
                return { ok: true, data };
            } catch (error) {
                return { ok: false, error };
            }
        })();
        this.lockExtensionPromise = request;
        request.then(() => {
            if (this.lockExtensionPromise === request) this.lockExtensionPromise = null;
        }, () => {
            if (this.lockExtensionPromise === request) this.lockExtensionPromise = null;
        });
        return request;
    }

    maybePromptHoldExtension() {
        const expiry = this.getNearestHoldExpiry();
        if (!expiry) return;
        if (this.state.holdWarningExpiryKey === expiry || this.holdWarningPromise || this.lockExtensionPromise) return;
        const remaining = expiry - Date.now();
        if (remaining <= 0 || remaining > 5 * 60 * 1000) return;

        this.state.holdWarningShown = true;
        this.state.holdWarningExpiryKey = expiry;
        const token = {};
        this.holdWarningToken = token;
        const prompt = (async () => {
            let confirmed = false;
            try {
                const confirmation = showConfirm(
                    'Extend Temporary Hold?',
                    `Only 5 minutes or less remain on the temporary hold (countdown: ${this.formatLockCountdown(remaining)}). Extend every active hold by 30 minutes?`
                );
                token.cancelButton = document.getElementById('gc-btn-cancel');
                confirmed = await confirmation;
            } catch (error) {
                return { ok: false, error };
            }
            if (this.holdWarningToken !== token || !confirmed) return { ok: false, declined: !confirmed };

            const result = await this.extendWalkinLocksAPI();
            if (!result.ok && result.error) {
                this.presentHoldAlert('Hold Extension Failed', result.error.message || 'Temporary holds could not be extended.', 'error');
            }
            return result;
        })();
        this.holdWarningPromise = prompt;
        prompt.then(() => {
            if (this.holdWarningPromise === prompt) {
                this.holdWarningPromise = null;
                this.holdWarningToken = null;
            }
        }, () => {
            if (this.holdWarningPromise === prompt) {
                this.holdWarningPromise = null;
                this.holdWarningToken = null;
            }
        });
    }

    stopHoldCountdown() {
        if (this.lockCountdownInterval) clearInterval(this.lockCountdownInterval);
        this.lockCountdownInterval = null;
    }

    startHoldCountdown() {
        if (!this.state.isDatesLocked && !this.state.addonLocksHeld) {
            this.stopHoldCountdown();
            return;
        }
        // A legacy response without expiry metadata still gets a visible,
        // conservative countdown. Normal responses replace these fallbacks
        // with the server's authoritative expiry immediately.
        if (this.state.isDatesLocked && !this.state.primaryHoldExpiresAt) {
            this.state.primaryHoldExpiresAt = Date.now() + (60 * 60 * 1000);
        }
        if (this.state.addonLocksHeld && !this.state.addonHoldExpiresAt) {
            this.state.addonHoldExpiresAt = Date.now() + (60 * 60 * 1000);
        }
        this.updateLockCountdown();
        if (!this.lockCountdownInterval) {
            this.lockCountdownInterval = setInterval(() => this.updateLockCountdown(), 1000);
        }
    }

    expireActiveHolds() {
        const now = Date.now();
        const expired = [];
        if (this.state.isDatesLocked && this.state.primaryHoldExpiresAt && this.state.primaryHoldExpiresAt <= now) {
            expired.push({ label: 'dates', expiry: this.state.primaryHoldExpiresAt });
            this.state.isDatesLocked = false;
            this.state.primaryHoldExpiresAt = null;
            window.isDatesLocked = false;
            this.state.activeCalendar?.clearSelectedRange?.();
            this.state.activeCalendar = null;
            this.state.pendingDateConfirmation = null;
        }
        if (this.state.addonLocksHeld && this.state.addonHoldExpiresAt && this.state.addonHoldExpiresAt <= now) {
            expired.push({ label: 'hotel room', expiry: this.state.addonHoldExpiresAt });
            this.state.addonLocksHeld = false;
            this.state.addonHoldExpiresAt = null;
            this.addonServerUncertain = true;
        }
        if (!expired.length) return false;

        const expiredCycleKey = Math.min(...expired.map(item => item.expiry));
        const survivorExpiry = this.getNearestHoldExpiry();
        if (survivorExpiry) {
            const survivorRemaining = survivorExpiry - now;
            // A surviving hold gets its own future warning cycle. If it is
            // already inside the warning window, mark that cycle as warned so
            // the just-dismissed prompt is not duplicated immediately.
            if (survivorRemaining > 0 && survivorRemaining <= 5 * 60 * 1000) {
                this.state.holdWarningShown = true;
                this.state.holdWarningExpiryKey = survivorExpiry;
            } else {
                this.state.holdWarningShown = false;
                this.state.holdWarningExpiryKey = null;
            }
            this.state.holdExpiryNoticeShown = false;
            this.state.holdExpiryNoticeExpiryKey = null;
        } else {
            this.state.holdWarningShown = false;
            this.state.holdWarningExpiryKey = null;
        }

        this.calculateSummary();
        const promptDismissed = this.toggleLockBanner(this.state.isDatesLocked || this.state.addonLocksHeld);
        if (!this.state.isDatesLocked && !this.state.addonLocksHeld) this.stopHoldCountdown();

        if (this.state.holdExpiryNoticeExpiryKey !== expiredCycleKey) {
            this.state.holdExpiryNoticeShown = true;
            this.state.holdExpiryNoticeExpiryKey = expiredCycleKey;
            const message = `The ${expired.map(item => item.label).join(' and ')} hold expired. Please reselect the dates or re-hold the rooms before submitting.`;
            if (promptDismissed) this.presentHoldAlert('Temporary Hold Expired', message, 'error');
            else showAlert('Temporary Hold Expired', message, 'error');
        }
        return true;
    }

    updateLockCountdown() {
        if (this.expireActiveHolds()) return;
        if (!this.state.isDatesLocked && !this.state.addonLocksHeld) {
            this.stopHoldCountdown();
            this.toggleLockBanner(false);
            return;
        }
        this.maybePromptHoldExtension();
        const banner = document.getElementById('walkin-lock-banner');
        const timer = banner?.querySelector('[data-lock-countdown]');
        if (!timer) return;
        const expiry = this.getNearestHoldExpiry();
        const remaining = expiry ? Math.max(0, expiry - Date.now()) : 60 * 60 * 1000;
        const formatted = this.formatLockCountdown(remaining);
        timer.textContent = formatted;
        timer.setAttribute('aria-label', `${formatted} remaining on temporary hold`);
    }

    updateAddonDateDisplay() {
        const display = this.getEl('addon-room-date-display');
        const range = this.getAddonStayRange();
        if (!display) return;
        if (!range) {
            display.textContent = 'Select a stay of at least 1 night';
            return;
        }
        display.textContent = `${this.formatSafeDate(range.start)} to ${this.formatSafeDate(range.end)} (${range.nights} night${range.nights === 1 ? '' : 's'})`;
    }

    toggleLockBanner(show) {
        let banner = document.getElementById("walkin-lock-banner");
        const hasPrimaryHold = this.state.isDatesLocked;
        const hasAddonHold = this.state.addonLocksHeld;
        if (!hasPrimaryHold && !hasAddonHold) {
            if (banner) banner.remove();
            const promptDismissed = this.cancelHoldExtensionPrompt();
            this.stopHoldCountdown();
            return promptDismissed;
        }
        if (!show && banner) {
            banner.remove();
            return false;
        }
        if (!banner && show) {
            banner = document.createElement("div");
            banner.id = "walkin-lock-banner";
            banner.className = 'walkin-lock-banner';
            banner.setAttribute('role', 'status');
            banner.innerHTML = '<i class="fa-solid fa-lock" aria-hidden="true"></i><span class="walkin-lock-message"></span><span class="walkin-lock-countdown" data-lock-countdown role="timer" aria-live="polite"></span>';
            const activeSummary = document.querySelector(".summary-container.active") || this.getEl('summary-breakdown')?.parentNode;
            activeSummary?.parentNode?.insertBefore(banner, activeSummary);
        }
        if (banner) {
            const message = hasPrimaryHold && hasAddonHold
                ? 'Dates and selected hotel rooms are temporarily held for this booking form.'
                : hasAddonHold
                    ? 'Selected hotel rooms are temporarily held for this booking form.'
                    : 'Dates are temporarily held for this booking form.';
            banner.querySelector('.walkin-lock-message')?.replaceChildren(document.createTextNode(message));
            this.updateLockCountdown();
        }
    }

    async updateRoomAvailabilityLabels(startDate, endDate) {
        if (!startDate) return;
        const start = this.formatSafeDate(startDate);
        const end   = endDate ? this.formatSafeDate(endDate) : start;
        const requestId = ++this.addonAvailabilityRequestId;

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
                if (requestId === this.addonAvailabilityRequestId && data.success) {
                    const n = data.available;
                    label.style.color = n > 0 ? '#2a7a3b' : '#c0392b';
                    label.textContent = n > 0 ? `${n} room${n > 1 ? 's' : ''} available` : 'No rooms available';
                    if (addBtn) addBtn.disabled = (n === 0);
                    card.dataset.available = n;
                    const selectedQty = document.querySelector(`.selected-room-row[data-sel-key="${CSS.escape(card.dataset.groupKey)}"] .sel-room-qty`);
                    if (selectedQty) {
                        selectedQty.max = Math.max(n, 1);
                    }
                }
            } catch(e) {}
        });
    }

    showOverrideModal(newDate, calendarInstance) {
        const overrideModal = this.getEl('change-dates-modal');
        if (!overrideModal) return;
        overrideModal.classList.add('active');

        const cancelBtn = this.replaceElement('btn-override-no');
        const confirmBtn = this.replaceElement('btn-override-yes');
        cancelBtn?.addEventListener('click', () => overrideModal.classList.remove('active'));
        confirmBtn?.addEventListener('click', async () => {
            if (confirmBtn.disabled) return;
            confirmBtn.disabled = true;
            let changed = false;
            try {
                if (!await this.unlockDatesAPI()) return;
                await this.resetAddonStayDates({ release: false });
                this.state.activeCalendar = calendarInstance;
                calendarInstance.clearSelection();
                calendarInstance.startDate = newDate;
                calendarInstance.endDate = null;
                calendarInstance.render();
                calendarInstance.updateDateDisplay();
                this.calculateSummary();
                changed = true;
            } finally {
                confirmBtn.disabled = false;
                if (changed) overrideModal.classList.remove('active');
            }
        });
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
        document.querySelectorAll(".wi-row:not(.selected-room-row)").forEach(row => {
            const name = row.querySelector(".wi-item-name")?.value || "Custom Item";
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
        if (stayTypePrice > 0) this.appendSummaryRow('Overnight surcharge (added to day rate)', stayTypePrice);
        
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

        if (this.expireActiveHolds()) return;

        if (!guestName || !guestEmail || !guestPhone) {
            showAlert("Notice", "Please complete the Guest Information section.");
            return;
        }

        if (!this.state.isDatesLocked || !this.state.activeCalendar || !this.state.activeCalendar.startDate) {
            showAlert("Notice", "Please select dates on the calendar first!");
            return;
        }

        if (this.holdWarningPromise && !this.lockExtensionPromise) {
            showAlert('Notice', 'Please respond to the temporary hold extension prompt first.');
            return;
        }
        if (this.addonSyncPromise || this.lockExtensionPromise) {
            try {
                if (this.addonSyncPromise) await this.addonSyncPromise;
                if (this.lockExtensionPromise) await this.lockExtensionPromise;
            } catch (error) {
                showAlert('Notice', 'Please wait for the temporary room hold to finish updating.');
                return;
            }
        }
        if (this.addonReleasePromise || this.addonResetPromise || this.fullUnlockPromise) {
            showAlert('Notice', 'Please wait for the temporary room hold operation to finish.');
            return;
        }
        if (this.expireActiveHolds()) return;

        const context = this.getTabContextData();
        if (!context.roomName && !context.venueId) { showAlert("Notice", "Please ensure a valid specific room/venue is selected."); return; }
        if (context.roomType === 'Event Hall') {
            const hallSelect = this.getEl('event-venue');
            const hallOpt = hallSelect?.options[hallSelect.selectedIndex];
            const styleKey = this.getEl('event-style')?.value || '';
            const capacity = parseInt(hallOpt?.dataset?.[styleKey], 10) || 0;
            const guests = parseInt(context.guests, 10) || 0;
            if (!capacity || guests < 1 || guests > capacity) {
                showAlert("Notice", "The guest count exceeds the selected seating style's capacity.");
                return;
            }
        }

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
            formData.append("event_style_key", evStyleSelect?.value || '');
            formData.append("event_style", evStyleTxt.split('-')[0].trim()); 
            formData.append("admin_notes", this.getEl('admin-notes')?.value.trim() || "");
        }

        if (context.roomType === 'Resort Villa') {
            const stayRadio = document.querySelector('input[name="villa-stay"]:checked');
            formData.append("stay_type", stayRadio ? stayRadio.value : 'Day Time Stay');
        }

        // Grab all custom line items from the builder
        let customLineItems = [];
        document.querySelectorAll(".wi-row:not(.selected-room-row)").forEach(row => {
            if ((row.dataset.system || '').startsWith('room_')) return;
            const name = row.querySelector(".wi-item-name")?.value.trim() || '';
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
            const stay = this.getAddonStayRange();
            if (!stay) {
                showAlert('Notice', 'Please select a hotel check-in and check-out date for the room add-on.');
                return;
            }
            if (!this.state.addonLocksHeld) {
                showAlert('Notice', 'Please wait for the selected hotel rooms to be temporarily held, then try again.');
                return;
            }
            formData.append('room_groups', JSON.stringify(roomGroups));
            formData.append('room_start_date', this.formatSafeDate(stay.start));
            formData.append('room_end_date', this.formatSafeDate(stay.end));
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
                const [status, refNo, guest, venue, dates, payStatus] = response;

                // submit_walkin.php has already deleted this session's locks;
                // clear the client state without issuing a second unlock call.
                this.state.isDatesLocked = false;
                this.state.primaryHoldExpiresAt = null;
                window.isDatesLocked = false;
                this.state.activeCalendar?.clearSelectedRange();
                this.state.activeCalendar = null;
                this.state.pendingDateConfirmation = null;
                this.stopHoldCountdown();
                this.toggleLockBanner(false);
                await this.resetAddonStayDates({ release: false });
                
                const escapeHtml = (unsafe) => {
                    return (unsafe || "").toString()
                         .replace(/&/g, "&amp;")
                         .replace(/</g, "&lt;")
                         .replace(/>/g, "&gt;")
                         .replace(/"/g, "&quot;")
                         .replace(/'/g, "&#039;");
                };
                
                // Construct a rich success modal HTML
                const modalHtml = `
                    <div style="text-align:center; padding: 20px 0;">
                        <i class="fa-solid fa-circle-check" style="font-size: 3.5rem; color: #10b981; margin-bottom: 15px;"></i>
                        <h3 style="margin-bottom: 5px; color: #1f2937;">Booking Confirmed!</h3>
                        <p style="color: #6b7280; font-size: 0.95rem; margin-bottom: 25px;">The walk-in booking has been successfully recorded.</p>
                        
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; text-align: left; margin-bottom: 20px;">
                            <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                                <span style="color:#64748b; font-size: 0.85rem;">Reference No.</span>
                                <strong style="color:#0f172a; font-size: 0.95rem;">${escapeHtml(refNo)}</strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                                <span style="color:#64748b; font-size: 0.85rem;">Guest Name</span>
                                <strong style="color:#0f172a; font-size: 0.95rem;">${escapeHtml(guest)}</strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                                <span style="color:#64748b; font-size: 0.85rem;">Venue</span>
                                <strong style="color:#0f172a; font-size: 0.95rem;">${escapeHtml(venue)}</strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                                <span style="color:#64748b; font-size: 0.85rem;">Dates</span>
                                <strong style="color:#0f172a; font-size: 0.95rem;">${escapeHtml(dates)}</strong>
                            </div>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:#64748b; font-size: 0.85rem;">Payment Status</span>
                                <strong style="color:#10b981; font-size: 0.95rem;">${escapeHtml(payStatus)}</strong>
                            </div>
                        </div>
                    </div>
                `;
                
                await showAlert("Success", modalHtml, "success", true, true);
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
