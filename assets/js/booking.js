/**
 * ==========================================================================
 * SEVILLA360 - Booking Controller (Refactored & Fully Patched)
 * - Uses CMS images via data-img attributes (no hardcoded Unsplash URLs)
 * - Hotel rooms: individual physical room selection via venue_id
 * - Hotel room add-ons: real inventory using room_groups, not hardcoded prices
 * ==========================================================================
 */

class BookingController {
    constructor() {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        this.auth = window.bookingAuth || { isCustomer: false, isStaff: false, resume: false };
        this.manualPayment = window.ManualPayment?.create({
            csrfToken: this.csrfToken,
            onSubmitted: () => {
                try { window.sessionStorage.setItem('manual-payment-proof-submitted', '1'); } catch (error) { /* Dashboard still shows the submitted booking. */ }
                window.location.assign('user_dashboard.php');
            }
        }) || null;
        this.draftKey = 'sevilla360.booking-draft.v1';
        this.draftTtlMs = 2 * 60 * 60 * 1000;
        this.state = {
            activeTabId: 'event-hall', 
            currentStep: 1,
            isDatesLocked: false,
            activeCalendar: null,
            timerInterval: null,
            timeLimit: 900,
            summary: { total: 0, amountDue: 0, rows: [], bundleDiscount: 0 },
            calendars: {},
            addonConfirmedRange: null,
            pendingDateConfirmation: null,
            addonAvailabilityToken: 0,
            addonAvailabilityPending: false,
            addonAvailabilityReady: false,
            addonAvailabilityRangeKey: '',
            confirmedSelectionKey: ''
        };
        this.state.lockExpiresAt = null;
        window.isDatesLocked = false;
        this.isRestoringDraft = false;

        window.requestDateConfirmation = this.requestDateConfirmation.bind(this);
        window.showOverrideModal = this.showOverrideModal.bind(this);
        window.calculateSummary = this.calculateSummary.bind(this);

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
        this.syncHotelRoomSelection();
        this.syncVenueSelectionDisplays();
        window.addEventListener('pageshow', () => {
            this.syncHotelRoomSelection();
            this.syncVenueSelectionDisplays();
            this.calculateSummary();
        });
        this.preselectFromURL();
        this.preselectDatesFromURL();
        this.restoreDraftIfRequested();
        this.calculateSummary();
        this.updateBookingStepUI();
    }

    isValidDraftDate(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
        const [year, month, day] = value.split('-').map(Number);
        const date = new Date(year, month - 1, day);
        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
    }

    sanitizeDraftDateRange(draft, startKey, endKey, options = {}) {
        const start = draft[startKey] || '';
        const end = draft[endKey] || '';
        const today = this.formatSafeDate(new Date());
        const requiresEnd = options.requiresEnd === true;
        const sameEndAllowed = options.sameEndAllowed !== false;
        const validStart = this.isValidDraftDate(start) && start >= today;
        const validEnd = !end || (this.isValidDraftDate(end) && end >= today && end >= start);
        if (!validStart || !validEnd || (requiresEnd && !end) || (!sameEndAllowed && end === start)) {
            const changed = Boolean(draft[startKey] || draft[endKey]);
            draft[startKey] = '';
            draft[endKey] = '';
            return changed;
        }
        return false;
    }

    getDraft() {
        try {
            const raw = window.sessionStorage.getItem(this.draftKey);
            const draft = raw ? JSON.parse(raw) : null;
            if (!draft || typeof draft !== 'object' || draft.version !== 1) {
                window.sessionStorage.removeItem(this.draftKey);
                return null;
            }
            const createdAt = Number(draft.createdAt);
            const now = Date.now();
            if (!Number.isSafeInteger(createdAt) || createdAt < 1 || createdAt > now || now - createdAt > this.draftTtlMs) {
                window.sessionStorage.removeItem(this.draftKey);
                return null;
            }
            let changed = false;
            const category = String(draft.category || '');
            changed = this.sanitizeDraftDateRange(draft, 'startDate', 'endDate', {
                requiresEnd: category === 'Hotel Room' || (category === 'Resort Villa' && draft.stayType === 'Overnight'),
                sameEndAllowed: category === 'Event Hall' || (category === 'Resort Villa' && draft.stayType !== 'Overnight')
            }) || changed;
            changed = this.sanitizeDraftDateRange(draft, 'addonStartDate', 'addonEndDate', { requiresEnd: true, sameEndAllowed: false }) || changed;
            if (!draft.addons || typeof draft.addons !== 'object') {
                draft.addons = {};
                changed = true;
            }
            if (!Array.isArray(draft.addons.roomGroups)) {
                draft.addons.roomGroups = [];
                changed = true;
            }
            if (typeof draft.confirmedSelectionKey !== 'string') {
                draft.confirmedSelectionKey = '';
                changed = true;
            }
            const draftStep = Number.parseInt(draft.step, 10);
            if (!Number.isInteger(draftStep) || draftStep < 1 || draftStep > 4) {
                draft.step = 1;
                changed = true;
            }
            if (changed) window.sessionStorage.setItem(this.draftKey, JSON.stringify(draft));
            return draft;
        } catch (error) {
            try { window.sessionStorage.removeItem(this.draftKey); } catch (storageError) { /* no-op */ }
            return null;
        }
    }

    buildSelectionKey(calendar = this.state.activeCalendar) {
        if (!calendar?.startDate) return '';
        const toCanonicalDate = value => {
            if (!(value instanceof Date) || Number.isNaN(value.getTime())) return '';
            const local = new Date(value.getTime());
            local.setHours(0, 0, 0, 0);
            const canonical = this.formatSafeDate(local);
            return this.isValidDraftDate(canonical) ? canonical : '';
        };
        const context = this.getTabContextData();
        const startDate = toCanonicalDate(calendar.startDate);
        const endDate = toCanonicalDate(calendar.endDate) || startDate;
        if (!startDate || !endDate || endDate < startDate) return '';
        return JSON.stringify({
            activeTabId: String(this.state.activeTabId || ''),
            venueId: String(context.venueId || ''),
            roomGroupId: String(context.roomGroupId || ''),
            roomName: String(context.roomName || ''),
            roomType: String(context.roomType || ''),
            startDate,
            endDate,
            villaStayType: this.state.activeTabId === 'resort-villa'
                ? String(document.querySelector('input[name="villa-stay"]:checked')?.value || '')
                : ''
        });
    }

    saveDraft() {
        if (this.isRestoringDraft) return;
        const existing = this.getDraft();
        const calendar = this.state.activeCalendar;
        const context = this.getTabContextData();
        const addon = this.getAddonStayRange();
        const guestInputId = this.state.activeTabId === 'event-hall' ? 'event-guests' : (this.state.activeTabId === 'hotel-rooms' ? 'hotel-guests' : 'villa-guests');
        const category = this.state.activeTabId === 'hotel-rooms' ? 'Hotel Room' : (context.roomType || '');
        const today = this.formatSafeDate(new Date());
        const draftDate = date => {
            const value = date ? this.formatSafeDate(date) : '';
            return this.isValidDraftDate(value) && value >= today ? value : '';
        };
        const startDate = draftDate(calendar?.startDate);
        const endDateCandidate = draftDate(calendar?.endDate);
        const endDate = startDate && endDateCandidate && endDateCandidate >= startDate ? endDateCandidate : '';
        const addonStartDate = draftDate(addon?.start);
        const addonEndCandidate = draftDate(addon?.end);
        const addonEndDate = addonStartDate && addonEndCandidate && addonEndCandidate > addonStartDate ? addonEndCandidate : '';
        const currentSelectionKey = this.buildSelectionKey(calendar);
        const confirmedSelectionKey = typeof this.state.confirmedSelectionKey === 'string'
            && this.state.confirmedSelectionKey !== ''
            && this.state.confirmedSelectionKey === currentSelectionKey
            ? currentSelectionKey
            : '';
        if (!confirmedSelectionKey) this.state.confirmedSelectionKey = '';
        const draft = {
            version: 1,
            createdAt: existing?.createdAt || Date.now(),
            activeTabId: this.state.activeTabId,
            step: this.state.currentStep,
            category,
            venueId: context.venueId || '',
            roomGroupId: context.roomGroupId || '',
            venueName: context.roomName || '',
            roomType: context.roomType && !['Event Hall', 'Resort Villa'].includes(context.roomType) ? context.roomType : '',
            buildingName: context.roomType && !['Event Hall', 'Resort Villa'].includes(context.roomType) ? context.roomName || '' : '',
            startDate,
            endDate,
            confirmedSelectionKey,
            addonStartDate,
            addonEndDate,
            stayType: document.querySelector('input[name="villa-stay"]:checked')?.value || '',
            guests: this.getEl(guestInputId)?.value || '',
            paymentChoice: document.querySelector('input[name="' + (context.activeRadioGroup || '') + '"]:checked')?.value || '',
            eventType: document.querySelector('input[name="event-type"]:checked')?.dataset.text || '',
            eventTypeOther: this.getEl('event-type-others')?.value || '',
            eventStyle: this.getEl('event-style')?.value || '',
            addons: {
                catering: this.getEl('check-catering')?.checked === true,
                cateringTier: document.querySelector('input[name="catering-tier"]:checked')?.value || '',
                av: this.getEl('check-av')?.checked === true,
                rooms: this.getEl('check-rooms')?.checked === true,
                roomGroups: Array.from(document.querySelectorAll('.selected-room-row')).map(row => ({
                    roomGroupId: row.dataset.roomGroupId || '',
                    buildingName: row.dataset.building || '',
                    roomType: row.dataset.roomType || '',
                    quantity: Number.parseInt(row.querySelector('.sel-room-qty')?.value, 10) || 1
                })).filter(item => item.buildingName && item.roomType)
            }
        };
        try {
            window.sessionStorage.setItem(this.draftKey, JSON.stringify(draft));
        } catch (error) { /* Storage may be disabled; continue with live state. */ }
    }

    clearDraft() {
        this.state.confirmedSelectionKey = '';
        try { window.sessionStorage.removeItem(this.draftKey); } catch (error) { /* no-op */ }
    }

    async restoreAddonSelections(draft) {
        if (this.state.activeTabId !== 'event-hall' || draft.addons?.rooms !== true) return;
        const roomsToggle = this.getEl('check-rooms');
        const roomsOptions = this.getEl('rooms-options');
        if (!roomsToggle) return;
        roomsToggle.checked = true;
        roomsOptions?.classList.remove('hidden');

        const start = draft.addonStartDate;
        const end = draft.addonEndDate;
        const requestedGroups = Array.isArray(draft.addons?.roomGroups) ? draft.addons.roomGroups : [];
        if (!this.isValidDraftDate(start) || !this.isValidDraftDate(end) || end <= start) {
            roomsToggle.checked = false;
            roomsOptions?.classList.add('hidden');
            this.resetAddonStayDates();
            this.saveDraft();
            if (requestedGroups.length || start || end) showAlert('Hotel add-ons need new dates', 'Your saved hotel add-on dates have expired or are no longer valid. Choose a new stay if you still need rooms.', 'info');
            return;
        }

        const startDate = new Date(`${start}T00:00:00`);
        const endDate = new Date(`${end}T00:00:00`);
        const nights = Math.round((endDate - startDate) / 86400000);
        this.state.addonConfirmedRange = { start: startDate, end: endDate, nights };
        this.state.calendars.addonHotel?.setSelection(startDate, endDate);
        const display = this.getEl('addon-room-date-display');
        if (display) display.textContent = `${start} to ${end} (${nights} night${nights === 1 ? '' : 's'})`;

        await this.updateRoomAvailabilityLabels(startDate, endDate);
        const cards = Array.from(document.querySelectorAll('.room-group-card'));
        const validGroups = [];
        const invalidGroups = [];
        requestedGroups.forEach(group => {
            const buildingName = String(group?.buildingName || '');
            const roomType = String(group?.roomType || '');
            const quantity = Number.parseInt(group?.quantity, 10);
            const roomGroupId = String(group?.roomGroupId || '');
            const card = cards.find(item => roomGroupId ? item.dataset.roomGroupId === roomGroupId : item.dataset.building === buildingName && item.dataset.roomType === roomType);
            const available = Number.parseInt(card?.dataset.available, 10);
            if (card && Number.isInteger(quantity) && quantity > 0 && quantity <= available) {
                validGroups.push({ card, quantity });
            } else {
                invalidGroups.push(buildingName && roomType ? `${buildingName} — ${roomType}` : 'an invalid room group');
            }
        });

        if (requestedGroups.length && !validGroups.length) {
            roomsToggle.checked = false;
            roomsOptions?.classList.add('hidden');
            this.resetAddonStayDates();
            this.saveDraft();
            showAlert('Hotel add-ons changed', 'The saved hotel rooms are no longer available for those dates. Your primary venue selection is still available.', 'info');
            return;
        }
        validGroups.forEach(({ card, quantity }) => {
            this.addRoomGroupToSelection(card.dataset.groupKey);
            const row = document.querySelector(`.selected-room-row[data-sel-key="${CSS.escape(card.dataset.groupKey)}"]`);
            const qtyInput = row?.querySelector('.sel-room-qty');
            if (qtyInput) {
                qtyInput.value = String(quantity);
                qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
        this.saveDraft();
        if (invalidGroups.length) showAlert('Some hotel add-ons changed', `${invalidGroups.join(', ')} could not be restored for the saved dates. Your valid selections remain in place.`, 'info');
    }

    async restoreDraftIfRequested() {
        if (!this.auth.resume) return;
        const draft = this.getDraft();
        if (!draft) return;
        this.isRestoringDraft = true;
        try {
        const tab = document.querySelector('.tab-btn[data-tab="' + CSS.escape(draft.activeTabId || '') + '"]');
        if (tab) this.executeTabVisualSwitch(tab, draft.activeTabId);

        const applyChange = (id, value) => {
            const element = this.getEl(id);
            if (!element || value === '' || value === null || value === undefined) return;
            element.value = String(value);
            element.dispatchEvent(new Event('change', { bubbles: true }));
        };
        if (draft.category === 'Event Hall') {
            const select = this.getEl('event-venue');
            const option = Array.from(select?.options || []).find(item => String(item.dataset.id || '') === String(draft.venueId) || String(item.dataset.name || '') === String(draft.venueName));
            if (option && select) { select.value = option.value; select.dispatchEvent(new Event('change', { bubbles: true })); }
        } else if (draft.category === 'Resort Villa') {
            const select = this.getEl('villa-type');
            const option = Array.from(select?.options || []).find(item => String(item.dataset.id || '') === String(draft.venueId) || String(item.dataset.name || '') === String(draft.venueName));
            if (option && select) { select.value = option.value; select.dispatchEvent(new Event('change', { bubbles: true })); }
            if (draft.stayType) {
                const stay = document.querySelector('input[name="villa-stay"][value="' + CSS.escape(draft.stayType) + '"]');
                if (stay && !stay.checked) { stay.checked = true; stay.dispatchEvent(new Event('change', { bubbles: true })); }
            }
        } else if (draft.roomType) {
            applyChange('hotel-room-type', draft.roomType);
            const roomSelect = this.getEl('hotel-room-name');
            const option = Array.from(roomSelect?.options || []).find(item => (draft.roomGroupId && String(item.dataset.roomGroupId || '') === String(draft.roomGroupId)) || (!draft.roomGroupId && String(item.dataset.name || '') === String(draft.buildingName || draft.venueName)));
            if (option && roomSelect) { roomSelect.value = option.value; roomSelect.dispatchEvent(new Event('change', { bubbles: true })); }
        }

        const guestsId = draft.category === 'Event Hall' ? 'event-guests' : (draft.category === 'Resort Villa' ? 'villa-guests' : 'hotel-guests');
        applyChange(guestsId, draft.guests);
        const activeContext = this.getTabContextData();
        if (draft.paymentChoice && activeContext.activeRadioGroup) {
            const payment = Array.from(document.querySelectorAll('input[type="radio"]')).find(item => item.name === activeContext.activeRadioGroup && item.value === draft.paymentChoice);
            if (payment) { payment.checked = true; payment.dispatchEvent(new Event('change', { bubbles: true })); }
        }
        if (draft.eventStyle) applyChange('event-style', draft.eventStyle);
        if (draft.eventTypeOther) {
            applyChange('event-type-others', draft.eventTypeOther);
            const other = this.getEl('event-others-radio');
            if (other) { other.checked = true; other.dispatchEvent(new Event('change', { bubbles: true })); }
        } else if (draft.eventType) {
            const eventType = Array.from(document.querySelectorAll('input[name="event-type"]')).find(item => item.dataset.text === draft.eventType);
            if (eventType) { eventType.checked = true; eventType.dispatchEvent(new Event('change', { bubbles: true })); }
        }
        const restoreCatering = () => {
            if (this.state.activeTabId !== 'event-hall' || !draft.addons?.catering) return;
            const control = this.getEl('check-catering');
            if (!control) return;
            control.checked = true;
            this.getEl('catering-options')?.classList.remove('hidden');
            const tier = Array.from(document.querySelectorAll('input[name="catering-tier"]'))
                .find(item => item.value === String(draft.addons.cateringTier || ''));
            if (tier) tier.checked = true;
        };
        const restoreAv = () => {
            if (this.state.activeTabId !== 'event-hall' || !draft.addons?.av) return;
            const control = this.getEl('check-av');
            if (!control) return;
            control.checked = true;
            this.getEl('av-options')?.classList.remove('hidden');
        };

        const calendar = this.state.calendars[this.state.activeTabId === 'event-hall' ? 'event' : (this.state.activeTabId === 'hotel-rooms' ? 'hotel' : 'villa')];
        const isDate = value => this.isValidDraftDate(value) && value >= this.formatSafeDate(new Date());
        if (!calendar || !isDate(draft.startDate)) {
            this.state.confirmedSelectionKey = '';
            await this.restoreAddonSelections(draft);
            restoreCatering();
            restoreAv();
            this.calculateSummary();
            return;
        }
        const endDate = isDate(draft.endDate) ? draft.endDate : draft.startDate;
        this.state.activeCalendar = calendar;
        calendar.setSelection(draft.startDate, endDate);
        const context = this.getTabContextData();
        await calendar.fetchBookedDates(context.roomType, context.roomName, context.venueId, false, context.roomGroupId);
        const invalidStart = calendar.isDateUnavailable(calendar.startDate);
        const invalidInterior = calendar.endDate && calendar.endDate > calendar.startDate && calendar.hasInvalidDaysBetween(calendar.startDate, calendar.endDate);
        const invalidEnd = this.state.activeTabId !== 'hotel-rooms' && calendar.endDate && calendar.isDateUnavailable(calendar.endDate);
        if (invalidStart || invalidInterior || invalidEnd) {
            calendar.clearSelectedRange();
            this.state.activeCalendar = null;
            this.state.confirmedSelectionKey = '';
            // Keep the selected venue/options and independently restorable
            // add-ons, but discard only the unavailable primary dates.
            await this.restoreAddonSelections(draft);
            restoreCatering();
            restoreAv();
            this.calculateSummary();
            showAlert('Dates changed', 'Your selected venue is still available, but those dates are no longer available. Please choose new dates.', 'info');
            return;
        }
        await this.restoreAddonSelections(draft);
        restoreCatering();
        restoreAv();
        this.calculateSummary();
        const restoredSelectionKey = typeof draft.confirmedSelectionKey === 'string'
            && draft.confirmedSelectionKey !== ''
            && draft.confirmedSelectionKey === this.buildSelectionKey(calendar)
            ? draft.confirmedSelectionKey
            : '';
        this.state.confirmedSelectionKey = restoredSelectionKey;
        if (restoredSelectionKey && this.auth.isCustomer) {
            if (this.state.activeTabId === 'event-hall') {
                // Event inquiries are non-exclusive: the guest's explicit
                // date confirmation carries through without a second prompt.
                calendar.updateDateDisplay();
            } else {
                // The availability check above is advisory. The lock endpoint
                // remains authoritative and is called once on resume.
                await this.lockSelectedDates(calendar.startDate, calendar.endDate, calendar);
            }
        } else {
            // Legacy drafts and drafts whose venue/category/dates changed must
            // use the explicit confirmation step again.
            this.requestDateConfirmation(calendar.startDate, calendar.endDate, calendar);
        }
        } finally {
            this.isRestoringDraft = false;
            const requestedStep = Math.min(4, Math.max(1, Number.parseInt(draft.step, 10) || 1));
            this.state.currentStep = Math.min(requestedStep, this.getMaximumReachableStep());
            this.clearBookingStepError();
            this.updateBookingStepUI();
            this.saveDraft();
        }
    }

    preselectFromURL() {
        const urlParams = new URLSearchParams(window.location.search);
        const category = urlParams.get('category');
        if (!category) return;

        // Map category to tab ID
        const tabMap = {
            'Event Hall': 'event-hall',
            'Hotel Room': 'hotel-rooms',
            'Resort Villa': 'resort-villa'
        };
        const targetTabId = tabMap[category];
        if (!targetTabId) return;

        // Switch to the correct tab
        const tabBtn = document.querySelector(`.tab-btn[data-tab="${targetTabId}"]`);
        if (tabBtn) this.handleTabSwitch(tabBtn);

        const venueId = urlParams.get('venue_id');
        const hasVenueId = urlParams.has('venue_id');
        const roomGroupId = urlParams.get('room_group_id');
        const roomType = urlParams.get('room_type');
        const venueName = urlParams.get('venue_name');
        const selectVenueFromURL = (select) => {
            const options = Array.from(select?.options || []);
            const option = hasVenueId
                ? options.find(item => String(item.dataset.id || '') === venueId)
                : (venueName ? options.find(item => item.dataset.name === venueName) : null);
            if (!select || !option) return;
            select.selectedIndex = option.index;
            select.dispatchEvent(new Event('change'));
        };

        if (category === 'Event Hall') {
            selectVenueFromURL(this.getEl('event-venue'));
        } else if (category === 'Resort Villa') {
            selectVenueFromURL(this.getEl('villa-type'));
        } else if (category === 'Hotel Room') {
            const typeSelect = this.getEl('hotel-room-type');
            const groupRoom = roomGroupId ? Object.values(window.hotelRoomData || {}).flat().find(item => String(item.room_group_id || '') === String(roomGroupId)) : null;
            const desiredType = roomType || groupRoom?.room_type || '';
            if (typeSelect && desiredType) {
                for (let i = 0; i < typeSelect.options.length; i++) {
                    if (typeSelect.options[i].value === desiredType) {
                        typeSelect.selectedIndex = i;
                        typeSelect.dispatchEvent(new Event('change'));
                        break;
                    }
                }
                const nameSelect = this.getEl('hotel-room-name');
                const option = Array.from(nameSelect?.options || []).find(item => roomGroupId
                    ? String(item.dataset.roomGroupId || '') === String(roomGroupId)
                    : item.dataset.name === venueName);
                if (nameSelect && option) {
                    nameSelect.selectedIndex = option.index;
                    nameSelect.dispatchEvent(new Event('change'));
                }
            }
        }
        if (this.validateBookingStep(1).valid) {
            this.state.currentStep = 2;
            this.updateBookingStepUI();
        }
    }

    async preselectDatesFromURL() {
        const params = new URLSearchParams(window.location.search);
        const start = params.get('check_in') || params.get('start_date');
        const end = params.get('check_out') || params.get('end_date') || start;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(start || '') || !/^\d{4}-\d{2}-\d{2}$/.test(end || '')) return;
        const calendar = this.state.calendars[this.state.activeTabId === 'event-hall' ? 'event' : (this.state.activeTabId === 'hotel-rooms' ? 'hotel' : 'villa')];
        const context = this.getTabContextData();
        if (!calendar || (!context.roomName && !context.venueId)) return;
        this.state.activeCalendar = calendar;
        calendar.setSelection(start, end);
        await calendar.fetchBookedDates(context.roomType, context.roomName, context.venueId, false, context.roomGroupId);
        if (calendar.isDateUnavailable(calendar.startDate) || (calendar.endDate && calendar.endDate > calendar.startDate && calendar.hasInvalidDaysBetween(calendar.startDate, calendar.endDate)) || (this.state.activeTabId !== 'hotel-rooms' && calendar.endDate && calendar.isDateUnavailable(calendar.endDate))) {
            calendar.clearSelectedRange();
            this.state.activeCalendar = null;
            showAlert('Dates changed', 'Those dates are no longer available. Please choose new dates.', 'info');
            return;
        }
        this.calculateSummary();
        this.requestDateConfirmation(calendar.startDate, calendar.endDate, calendar);
        const currentUrl = new URL(window.location.href);
        ['check_in', 'check_out', 'start_date', 'end_date'].forEach(key => currentUrl.searchParams.delete(key));
        window.history.replaceState(window.history.state, '', `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`);
    }

    initCalendars() {
        if (typeof SevillaCalendar !== 'undefined') {
            this.state.calendars.event = new SevillaCalendar("cal-ui-event");
            this.state.calendars.hotel = new SevillaCalendar("cal-ui-hotel");
            this.state.calendars.villa = new SevillaCalendar("cal-ui-villa");
            this.state.calendars.addonHotel = new SevillaCalendar("cal-ui-addon-hotel", {
                requireHotelRules: true,
                onRangeSelected: (start, end) => this.handleAddonRangeSelected(start, end)
            });
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

        const bookingTabs = Array.from(document.querySelectorAll('[data-booking-tablist] [role="tab"][data-tab]'));
        bookingTabs.forEach((btn, index) => {
            btn.addEventListener("click", () => this.handleTabSwitch(btn));
            btn.addEventListener("keydown", (event) => {
                const offsets = { ArrowRight: 1, ArrowLeft: -1 };
                let nextIndex = null;
                if (Object.hasOwn(offsets, event.key)) nextIndex = (index + offsets[event.key] + bookingTabs.length) % bookingTabs.length;
                else if (event.key === 'Home') nextIndex = 0;
                else if (event.key === 'End') nextIndex = bookingTabs.length - 1;
                if (nextIndex === null || !bookingTabs.length) return;
                event.preventDefault();
                bookingTabs[nextIndex].focus();
                this.handleTabSwitch(bookingTabs[nextIndex]);
            });
        });

        document.querySelectorAll('[data-booking-step-nav], [data-booking-step-next], [data-booking-step-back]').forEach(button => {
            button.addEventListener('click', () => {
                const requestedStep = Number.parseInt(button.dataset.bookingStepNav || button.dataset.bookingStepNext || button.dataset.bookingStepBack, 10);
                this.navigateToBookingStep(requestedStep);
            });
        });
        ['input', 'change'].forEach(eventName => document.addEventListener(eventName, event => {
            if (event.target.closest('.tab-content')) this.clearBookingStepError();
        }));
        document.addEventListener('click', event => {
            if (event.target.closest('.cal-day-cell')) this.clearBookingStepError();
        });
    }

    getActiveBookingCalendar() {
        const calendarKey = this.state.activeTabId === 'hotel-rooms'
            ? 'hotel'
            : (this.state.activeTabId === 'resort-villa' ? 'villa' : 'event');
        return this.state.calendars[calendarKey] || null;
    }

    getBookingSelectionIssue() {
        if (this.state.activeTabId === 'hotel-rooms') {
            const roomType = this.getEl('hotel-room-type');
            const roomSelect = this.getEl('hotel-room-name');
            const room = roomSelect?.options[roomSelect.selectedIndex];
            if (!roomType?.value) return { message: 'Choose a room category to continue.', target: roomType };
            if (!room?.dataset.name || roomSelect.disabled) return { message: 'Choose an available building to continue.', target: roomSelect };
            return null;
        }

        const selectId = this.state.activeTabId === 'resort-villa' ? 'villa-type' : 'event-venue';
        const select = this.getEl(selectId);
        const option = select?.options[select.selectedIndex];
        const id = Number.parseInt(option?.dataset.id || '', 10);
        if (!Number.isSafeInteger(id) || id < 1) {
            return {
                message: this.state.activeTabId === 'resort-villa' ? 'Choose a villa to continue.' : 'Choose an event hall to continue.',
                target: select
            };
        }
        return null;
    }

    getGuestCountIssue({ checkEventStyle = this.state.currentStep >= 3 } = {}) {
        const isEvent = this.state.activeTabId === 'event-hall';
        const input = this.getEl(isEvent ? 'event-guests' : (this.state.activeTabId === 'hotel-rooms' ? 'hotel-guests' : 'villa-guests'));
        const count = input?.valueAsNumber;
        const min = Number.parseInt(input?.min || '1', 10);
        const max = input?.max ? Number.parseInt(input.max, 10) : null;
        if (!Number.isInteger(count) || count < min || (Number.isInteger(max) && count > max)) {
            return {
                message: isEvent
                    ? `Enter a whole number of guests from ${min}${Number.isInteger(max) ? ` to ${max}` : ' or more'}.`
                    : `Enter a whole number of guests from ${min}${Number.isInteger(max) ? ` to ${max}` : ' or more'}.`,
                target: input
            };
        }
        if (isEvent && checkEventStyle) {
            const hall = this.getEl('event-venue')?.selectedOptions[0];
            const styleKey = this.getEl('event-style')?.value || '';
            const capacity = Number.parseInt(hall?.dataset[styleKey] || '', 10);
            if (!Number.isInteger(capacity) || capacity < 1) {
                return { message: 'This event hall has no listed capacity for the selected setup. Choose another seating style.', target: this.getEl('event-style') };
            }
            if (count > capacity) {
                return { message: `The selected seating style holds up to ${capacity} guests. Lower the guest count or choose another style.`, target: input };
            }
        }
        return null;
    }

    validateBookingStep(step) {
        if (step === 1) {
            const issue = this.getBookingSelectionIssue();
            return issue ? { valid: false, issue } : { valid: true };
        }
        if (step === 3 && this.state.activeTabId === 'event-hall') {
            const issue = this.getGuestCountIssue({ checkEventStyle: true });
            return issue ? { valid: false, issue: { ...issue, step: 3 } } : { valid: true };
        }
        if (step !== 2) return { valid: true };

        const selectionIssue = this.getBookingSelectionIssue();
        if (selectionIssue) return { valid: false, issue: { ...selectionIssue, step: 1 } };
        const calendar = this.getActiveBookingCalendar();
        if (!calendar?.startDate || !calendar.endDate) {
            return { valid: false, issue: { step: 2, message: 'Choose your date or date range, then confirm it to continue.', target: calendar?.container } };
        }
        const sameDay = calendar.startDate.getTime() === calendar.endDate.getTime();
        if (this.state.activeTabId === 'hotel-rooms' && (!calendar.endDate || sameDay)) {
            return { valid: false, issue: { step: 2, message: 'Hotel stays need at least one night. Choose a checkout date after check-in.', target: calendar.container } };
        }
        if (this.state.activeTabId === 'resort-villa') {
            const stayType = document.querySelector('input[name="villa-stay"]:checked')?.value;
            if ((stayType === 'Overnight' && sameDay) || (stayType !== 'Overnight' && !sameDay)) {
                return { valid: false, issue: { step: 2, message: 'Choose dates that match the selected villa stay, then confirm them.', target: calendar.container } };
            }
        }
        const currentSelectionKey = this.buildSelectionKey(calendar);
        if (!currentSelectionKey || this.state.confirmedSelectionKey !== currentSelectionKey) {
            return { valid: false, issue: { step: 2, message: 'Confirm these dates for the selected room or venue before continuing.', target: calendar.container } };
        }
        if (this.state.activeTabId !== 'event-hall' && this.auth.isCustomer && !this.state.isDatesLocked) {
            return { valid: false, issue: { step: 2, message: 'Your date hold has expired. Confirm the dates again to continue.', target: calendar.container } };
        }
        const guestIssue = this.getGuestCountIssue({ checkEventStyle: false });
        return guestIssue
            ? { valid: false, issue: { ...guestIssue, step: 2 } }
            : { valid: true };
    }

    getMaximumReachableStep() {
        if (!this.validateBookingStep(1).valid) return 1;
        if (!this.validateBookingStep(2).valid) return 2;
        if (!this.validateBookingStep(3).valid) return 3;
        return 4;
    }

    clearBookingStepError() {
        const error = document.querySelector('[data-booking-step-error]');
        if (error) {
            error.textContent = '';
            error.hidden = true;
        }
        document.querySelectorAll('.booking-section [aria-invalid="true"]').forEach(element => element.removeAttribute('aria-invalid'));
    }

    updateBookingStepUI() {
        const currentStep = Math.min(4, Math.max(1, Number.parseInt(this.state.currentStep, 10) || 1));
        this.state.currentStep = currentStep;
        document.querySelectorAll('[data-booking-step-panel]').forEach(panel => {
            const active = Number.parseInt(panel.dataset.bookingStepPanel, 10) === currentStep;
            panel.hidden = !active;
            panel.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        document.querySelectorAll('[data-booking-step-nav]').forEach(button => {
            const step = Number.parseInt(button.dataset.bookingStepNav, 10);
            const current = step === currentStep;
            button.classList.toggle('is-current', current);
            button.classList.toggle('is-complete', step < currentStep);
            if (current) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
        const bookingGrid = document.querySelector('.booking-grid');
        const reviewMount = this.getEl('booking-review-mount');
        const summary = this.getEl('booking-summary');
        const sidebar = document.querySelector('.booking-sidebar');
        const reviewing = currentStep === 4;
        if (bookingGrid) bookingGrid.classList.toggle('is-reviewing', reviewing);
        if (reviewMount) reviewMount.hidden = !reviewing;
        const summaryDestination = reviewing ? reviewMount : sidebar;
        if (summary && summaryDestination && summary.parentElement !== summaryDestination) {
            summaryDestination.appendChild(summary);
        }
        const footer = this.getEl('booking-summary-footer');
        if (footer) footer.hidden = currentStep !== 4;
        if (this.state.activeTabId === 'event-hall') {
            const option = this.getEl('event-venue')?.selectedOptions[0];
            const capacities = ['theater', 'classroom', 'banquet'].map(key => {
                const value = Number.parseInt(option?.dataset[key] || '', 10);
                return Number.isInteger(value) && value > 0 ? value : null;
            });
            this.updateEventGuestLimit(Boolean(option?.dataset.id), capacities, true);
        }
        const summaryJump = document.querySelector('.booking-summary-jump');
        if (summaryJump) {
            summaryJump.textContent = currentStep === 4 ? 'Go to contact and submit' : 'View live summary';
            summaryJump.setAttribute('aria-label', currentStep === 4
                ? 'Jump to the booking summary and submission controls'
                : 'Jump to the live booking summary');
        }
    }

    focusBookingElement(element) {
        if (!element) return;
        const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
        window.requestAnimationFrame(() => {
            element.focus({ preventScroll: true });
            element.scrollIntoView?.({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
        });
    }

    showBookingStepIssue(issue) {
        const error = document.querySelector('[data-booking-step-error]');
        if (error) {
            error.textContent = issue.message;
            error.hidden = false;
        }
        if (issue.target) {
            issue.target.setAttribute?.('aria-invalid', 'true');
            this.focusBookingElement(issue.target);
        }
    }

    setBookingStep(step, { focus = true, save = true } = {}) {
        const nextStep = Math.min(4, Math.max(1, Number.parseInt(step, 10) || 1));
        this.state.currentStep = nextStep;
        this.clearBookingStepError();
        this.updateBookingStepUI();
        if (save) this.saveDraft();
        if (!focus) return;

        const isMobile = window.matchMedia?.('(max-width: 992px)').matches === true;
        if (nextStep === 4 && isMobile) {
            const panel = document.getElementById(`tab-${this.state.activeTabId}`);
            const heading = panel?.querySelector('[data-booking-step-panel="4"] .booking-step-heading');
            this.focusBookingElement(heading || this.getEl('booking-summary'));
            return;
        }
        const panel = document.getElementById(`tab-${this.state.activeTabId}`);
        const heading = panel?.querySelector(`[data-booking-step-panel="${nextStep}"] .booking-step-heading`);
        this.focusBookingElement(heading);
    }

    navigateToBookingStep(requestedStep) {
        const targetStep = Number.parseInt(requestedStep, 10);
        if (!Number.isInteger(targetStep) || targetStep < 1 || targetStep > 4) return;
        if (targetStep <= this.state.currentStep) {
            this.setBookingStep(targetStep);
            return;
        }
        for (let prerequisite = 1; prerequisite < targetStep; prerequisite++) {
            const result = this.validateBookingStep(prerequisite);
            if (result.valid) continue;
            const issue = result.issue || {};
            const issueStep = Math.min(4, Math.max(1, issue.step || prerequisite));
            this.setBookingStep(issueStep, { focus: false });
            this.showBookingStepIssue(issue);
            return;
        }
        this.setBookingStep(targetStep);
    }

    bindUIInteractions() {
        const hotelTypeSelect = this.getEl("hotel-room-type");
        if (hotelTypeSelect) {
            hotelTypeSelect.addEventListener("change", (e) => this.populateSpecificHotelRooms(e.target.value));
            this.getEl("hotel-room-name").addEventListener('change', (e) => {
                if (this.state.calendars.hotel) this.state.calendars.hotel.clearSelection();
                const opt = e.target.options[e.target.selectedIndex];
                this.updateHotelRoomDisplay(opt);
                // Fetch booked dates using group info
                if (opt?.dataset.type && opt?.dataset.name && this.state.calendars.hotel) {
                    this.state.calendars.hotel.fetchBookedDates(opt.dataset.type, opt.dataset.name, null, false, opt.dataset.roomGroupId || null);
                }
                this.calculateSummary();
            });
        }

        this.getEl('event-venue')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            this.updateEventHallSelection(opt, true);
        });

        this.getEl('villa-type')?.addEventListener('change', (e) => {
            const opt = e.target.options[e.target.selectedIndex];
            this.updateVillaSelection(opt, true);
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
            this.saveDraft();
        });

        document.querySelectorAll('input[name="villa-stay"]').forEach(radio => {
            radio.addEventListener("change", async (e) => {
                // Native radio dispatch has already unchecked the previous
                // option, so the only other allowed value is the prior state.
                const previousRadio = Array.from(document.querySelectorAll('input[name="villa-stay"]'))
                    .find(item => item !== e.target);
                if (!await this.configureVillaStayMode(e.target.value)) {
                    if (previousRadio) {
                        e.target.checked = false;
                        previousRadio.checked = true;
                        this.updateVillaStaySelection(previousRadio.value);
                        const help = this.getEl('villa-calendar-help');
                        if (help) help.textContent = previousRadio.value === 'Overnight'
                            ? 'Overnight: one night · checkout is the next calendar day.'
                            : 'Day Time Stay: one calendar date.';
                    } else {
                        e.target.checked = true;
                    }
                    this.calculateSummary();
                    return;
                }
                this.updateVillaStaySelection(e.target.value);
                this.calculateSummary();
            });
        });
        this.configureVillaStayMode(document.querySelector('input[name="villa-stay"]:checked')?.value || 'Day Time Stay', false);

        // RECALCULATE SUMMARY WHEN PAYMENT SCHEME CHANGES
        document.querySelectorAll('input[name="hotel-payment"], input[name="villa-payment"]').forEach(radio => {
            radio.addEventListener("change", () => this.calculateSummary());
        });

        this.setupToggle("check-catering", "catering-options");
        this.setupToggle("check-rooms", "rooms-options");
        this.getEl('check-rooms')?.addEventListener('change', (e) => {
            if (e.target.checked) this.suggestAddonStayDates();
            else this.resetAddonStayDates();
        });

        document.querySelectorAll('input[name="contact-phone-choice"]').forEach(choice => {
            choice.addEventListener('change', (e) => {
                const phone = this.getEl('contact-phone');
                const saveChoice = this.getEl('save-contact-choice');
                if (!phone) return;
                if (e.target.value === 'saved') {
                    phone.value = phone.defaultValue;
                    saveChoice?.classList.add('hidden');
                    const save = this.getEl('save-contact-default');
                    if (save) save.checked = false;
                } else {
                    phone.value = '';
                    saveChoice?.classList.remove('hidden');
                    phone.focus();
                }
            });
        });
        this.getEl('contact-phone')?.addEventListener('input', (e) => {
            if (!e.target.defaultValue || e.target.value === e.target.defaultValue) return;
            const alternate = document.querySelector('input[name="contact-phone-choice"][value="alternate"]');
            if (alternate) alternate.checked = true;
            this.getEl('save-contact-choice')?.classList.remove('hidden');
        });

        // =========================================================================
        // HOTEL ROOM ADD-ON: "Add" buttons on room group cards
        // =========================================================================
        document.querySelectorAll('.btn-add-room-group').forEach(btn => {
            // Availability is unknown until a stay is explicitly confirmed.
            btn.disabled = true;
            btn.addEventListener('click', (e) => {
                const groupKey = e.target.dataset.groupKey;
                this.addRoomGroupToSelection(groupKey);
            });
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
    }

    // =========================================================================
    // HOTEL ROOM ADD-ON MANAGEMENT (Real Inventory)
    // =========================================================================

    getAddonStayRange() {
        const confirmed = this.state.addonConfirmedRange;
        if (!confirmed?.start || !confirmed?.end || confirmed.end <= confirmed.start) return null;
        return {
            start: new Date(confirmed.start),
            end: new Date(confirmed.end),
            nights: confirmed.nights
        };
    }

    handleAddonRangeSelected(start, end) {
        if (!start || !end || end <= start) {
            const display = this.getEl('addon-room-date-display');
            if (display) display.textContent = 'Select a stay of at least 1 night';
            return;
        }
        this.openAddonDateConfirmation(start, end);
    }

    suggestAddonStayDates() {
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

    resetAddonStayDates() {
        this.state.pendingDateConfirmation = null;
        this.state.addonConfirmedRange = null;
        this.state.addonAvailabilityToken++;
        this.state.addonAvailabilityPending = false;
        this.state.addonAvailabilityReady = false;
        this.state.addonAvailabilityRangeKey = '';
        const selectedRoomGroups = this.getEl('selected-room-groups');
        if (selectedRoomGroups) selectedRoomGroups.innerHTML = '';
        const calendar = this.state.calendars.addonHotel;
        if (calendar) {
            calendar.startDate = null;
            calendar.endDate = null;
            calendar.render();
        }
        const display = this.getEl('addon-room-date-display');
        if (display) display.textContent = 'Select a stay of at least 1 night';
        document.querySelectorAll('.room-group-card').forEach(card => {
            card.removeAttribute('data-available');
            const addBtn = card.querySelector('.btn-add-room-group');
            if (addBtn) addBtn.disabled = true;
            const label = card.querySelector('.room-avail-label');
            if (label) label.textContent = `${card.dataset.inventory || 0} total units — select dates to check availability`;
        });
        this.calculateSummary();
    }

    addRoomGroupToSelection(groupKey) {
        const container = document.getElementById('selected-room-groups');
        if (!container) return;

        const roomsEnabled = this.getEl('check-rooms')?.checked === true;
        const confirmed = this.getAddonStayRange();
        const rangeKey = confirmed ? `${this.formatSafeDate(confirmed.start)}|${this.formatSafeDate(confirmed.end)}` : '';
        if (!roomsEnabled || !confirmed || this.state.addonAvailabilityPending || !this.state.addonAvailabilityReady || this.state.addonAvailabilityRangeKey !== rangeKey) {
            showAlert('Notice', 'Confirm a hotel stay and wait for room availability before adding rooms.');
            return;
        }

        // Prevent duplicate entries for the same group
        if (container.querySelector(`[data-sel-key="${CSS.escape(groupKey)}"]`)) {
            showAlert('Notice', 'This room group is already added. Adjust the quantity below.');
            return;
        }

        // Find the source card for metadata
        const card = document.querySelector(`.room-group-card[data-group-key="${CSS.escape(groupKey)}"]`);
        if (!card) return;

        const building  = card.dataset.building;
        const roomType  = card.dataset.roomType;
        const roomGroupId = card.dataset.roomGroupId || '';
        const rate      = parseFloat(card.dataset.rate);
        const inventory = Number.parseInt(card.dataset.available, 10);
        if (!Number.isInteger(inventory) || inventory < 1) {
            showAlert('Notice', 'No rooms are available for this group on the confirmed dates.');
            return;
        }

        const row = document.createElement('div');
        row.className = 'wi-row selected-room-row';
        row.setAttribute('data-sel-key', groupKey);
        row.setAttribute('data-building', building);
        row.setAttribute('data-room-type', roomType);
        row.setAttribute('data-room-group-id', roomGroupId);
        row.setAttribute('data-rate', rate);
        row.style.cssText = 'display:flex; gap:10px; margin-bottom:10px; align-items:center;';
        const roomDetails = document.createElement('div');
        roomDetails.style.cssText = 'flex:1; display:flex; flex-direction:column;';
        const roomLabel = document.createElement('strong');
        roomLabel.style.cssText = 'font-size:0.95rem; color:#333;';
        roomLabel.textContent = `${building} — ${roomType}`;
        const roomRate = document.createElement('small');
        roomRate.style.cssText = 'color:#666;';
        roomRate.textContent = `₱${rate.toLocaleString()}/night`;
        roomDetails.append(roomLabel, roomRate);

        const roomControls = document.createElement('div');
        roomControls.style.cssText = 'display:flex; align-items:center; gap:8px;';
        const quantityLabel = document.createElement('label');
        quantityLabel.style.cssText = 'font-size:0.85rem; font-weight:600; color:#555;';
        quantityLabel.textContent = 'Qty:';
        const quantityInput = document.createElement('input');
        quantityInput.type = 'number';
        quantityInput.className = 'sel-room-qty';
        quantityInput.min = '1';
        quantityInput.max = String(inventory);
        quantityInput.value = '1';
        quantityInput.style.cssText = 'width: 60px; padding: 6px; border: 1px solid #ccc; border-radius: 4px; text-align: center;';
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'btn-remove-room-sel';
        removeButton.style.cssText = 'width: 32px; height: 32px; background: #fee2e2; color: #dc2626; border: none; border-radius: 4px; cursor: pointer; display:flex; align-items:center; justify-content:center;';
        removeButton.title = 'Remove';
        const removeIcon = document.createElement('i');
        removeIcon.className = 'fa-solid fa-times';
        removeButton.appendChild(removeIcon);
        roomControls.append(quantityLabel, quantityInput, removeButton);
        row.append(roomDetails, roomControls);
        container.appendChild(row);

        quantityInput.addEventListener('input', () => { this.calculateSummary(); this.saveDraft(); });
        removeButton.addEventListener('click', () => {
            row.remove();
            this.calculateSummary();
            this.saveDraft();
        });

        this.calculateSummary();
        this.saveDraft();
    }

    // =========================================================================

    bindCalculatorTriggers() {
        document.querySelectorAll('select, input[type="number"], input[type="radio"], input[type="checkbox"]').forEach(input => {
            input.addEventListener('change', () => { this.calculateSummary(); this.saveDraft(); });
            input.addEventListener('input', () => { this.calculateSummary(); this.saveDraft(); });
        });
    }

    bindModalsAndSubmission() {
        this.getEl("open-terms")?.addEventListener("click", (e) => { e.preventDefault(); this.getEl("tnc-modal")?.classList.add("active"); });
        this.getEl("btn-agree")?.addEventListener("click", () => { this.getEl("tnc-modal")?.classList.remove("active"); this.getEl("terms-check").checked = true; });
        
        this.getEl("btn-cancel")?.addEventListener("click", () => {
            this.clearDraft();
            this.stopTimerAndReset();
            this.unlockDatesAPI();
        });

        window.addEventListener("click", (e) => {
            if (!e.target.classList.contains("modal-overlay")) return;
            if (e.target.id === 'date-confirm-modal' && this.state.pendingDateConfirmation) {
                this.cancelPendingDateConfirmation();
                return;
            }
            e.target.classList.remove("active");
        });
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.state.pendingDateConfirmation) {
                e.preventDefault();
                this.cancelPendingDateConfirmation();
            }
        });

        this.getEl("btn-proceed")?.addEventListener("click", () => this.submitOnlineBooking());
    }

    bindUnloadHook() {
        window.addEventListener('beforeunload', () => {
            if (this.state.isDatesLocked && this.auth.isCustomer) this.unlockDatesAPI();
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
            return;
        }
        
        const activeBtn = document.querySelector('.tab-btn.active');
        if (activeBtn) this.state.activeTabId = activeBtn.getAttribute('data-tab');
    }

    syncVenueSelectionDisplays() {
        const eventSelect = this.getEl('event-venue');
        this.updateEventHallSelection(eventSelect?.options[eventSelect.selectedIndex], false);
        const villaSelect = this.getEl('villa-type');
        this.updateVillaSelection(villaSelect?.options[villaSelect.selectedIndex], false);
        const stayType = document.querySelector('input[name="villa-stay"]:checked')?.value || 'Day Time Stay';
        this.configureVillaStayMode(stayType, false);
    }

    updateSelectedItemImage(option, panelId, imageId, placeholderId, itemLabel) {
        const panel = this.getEl(panelId);
        const image = this.getEl(imageId);
        const placeholder = this.getEl(placeholderId);
        if (!panel || !image || !placeholder) return;

        const name = String(option?.dataset.name || '').trim();
        const hasSelection = Boolean(name || String(option?.dataset.id || '').trim());
        const source = String(option?.dataset.img || '').trim();
        const sourcePath = source.split(/[?#]/, 1)[0].toLowerCase();
        const hasPhoto = hasSelection && source !== ''
            && sourcePath !== 'placeholder.jpg' && !sourcePath.endsWith('/placeholder.jpg');
        panel.hidden = !hasSelection;
        panel.classList.toggle('no-photo', hasSelection && !hasPhoto);
        image.hidden = !hasPhoto;
        image.alt = hasPhoto ? `Photo of ${itemLabel} ${name}` : '';
        image.onerror = () => {
            if (image.dataset.source !== source) return;
            image.hidden = true;
            panel.classList.add('no-photo');
            placeholder.textContent = `No photo is available for ${itemLabel} ${name}.`;
            placeholder.hidden = false;
        };
        if (hasPhoto) {
            image.dataset.source = source;
            if (image.getAttribute('src') !== source) image.setAttribute('src', source);
        } else {
            image.dataset.source = '';
            image.removeAttribute('src');
        }
        placeholder.textContent = hasSelection && !hasPhoto
            ? `No photo is available for ${itemLabel} ${name}.`
            : '';
        placeholder.hidden = !hasSelection || hasPhoto;
    }

    updateEventHallSelection(option, refreshAvailability) {
        const hasSelection = Boolean(String(option?.dataset.id || '').trim());
        const name = hasSelection
            ? String(option.dataset.name || option.textContent.split('(')[0].trim())
            : '';
        const summaryVenue = this.getEl('sum-ev-venue');
        if (summaryVenue) summaryVenue.textContent = hasSelection ? name : 'Not selected';
        this.updateVenueInformation(option, 'event-venue-description', 'event-venue-amenities', 'event hall');
        this.updateSelectedItemImage(option, 'event-image-panel', 'event-img', 'event-image-placeholder', 'event hall');

        const styleSelect = this.getEl('event-style');
        const guestInput = this.getEl('event-guests');
        const capacities = ['theater', 'classroom', 'banquet'].map(key => {
            const raw = String(option?.dataset[key] ?? '').trim();
            const parsed = Number.parseInt(raw, 10);
            return raw !== '' && Number.isFinite(parsed) && parsed > 0 ? parsed : null;
        });
        if (styleSelect) {
            const labels = ['Theater Style', 'Classroom Style', 'Banquet Type'];
            Array.from(styleSelect.options).forEach((style, index) => {
                style.textContent = hasSelection && capacities[index] !== null
                    ? `${labels[index]} (${capacities[index]} pax)`
                    : labels[index];
            });
            styleSelect.onchange = () => this.updateEventGuestLimit(hasSelection, capacities);
        }
        this.updateEventGuestLimit(hasSelection, capacities, true);

        const rateRaw = String(option?.value ?? '').trim();
        const rate = Number.parseFloat(rateRaw);
        const facts = {
            'event-base-rate': hasSelection && rateRaw !== '' && Number.isFinite(rate) ? this.formatCurrency(rate) : '—',
            'event-theater-capacity': capacities[0] ? `${capacities[0]} guests` : '—',
            'event-classroom-capacity': capacities[1] ? `${capacities[1]} guests` : '—',
            'event-banquet-capacity': capacities[2] ? `${capacities[2]} guests` : '—'
        };
        Object.entries(facts).forEach(([id, value]) => {
            const fact = this.getEl(id);
            if (fact) {
                fact.textContent = value;
                fact.classList.toggle('fact-placeholder', value === '—');
            }
        });

        if (refreshAvailability && hasSelection && this.state.calendars.event) {
            this.state.calendars.event.fetchBookedDates('Event Hall', name);
        }
    }

    updateEventGuestLimit(hasSelection, capacities, fallbackToAvailable = false) {
        const guestInput = this.getEl('event-guests');
        const styleSelect = this.getEl('event-style');
        if (!guestInput) return;
        if (!hasSelection) {
            guestInput.removeAttribute('max');
            const capacityNote = this.getEl('event-capacity-note');
            if (capacityNote) capacityNote.textContent = 'Select an event hall to view capacity by setup style.';
            return;
        }
        const selectedIndex = ['theater', 'classroom', 'banquet'].indexOf(styleSelect?.value || '');
        const selectedCapacity = selectedIndex >= 0 ? capacities[selectedIndex] : null;
        const availableCapacity = capacities.filter(value => value !== null && value > 0);
        const isSelectingGuests = this.state.currentStep < 3;
        const maxCapacity = isSelectingGuests
            ? (availableCapacity.length ? Math.max(...availableCapacity) : null)
            : (selectedCapacity !== null && selectedCapacity > 0
                ? selectedCapacity
                : (fallbackToAvailable && availableCapacity.length ? Math.max(...availableCapacity) : null));
        if (maxCapacity) guestInput.setAttribute('max', String(maxCapacity));
        else guestInput.removeAttribute('max');
        const capacityNote = this.getEl('event-capacity-note');
        const styleLabels = { theater: 'Theater', classroom: 'Classroom', banquet: 'Banquet' };
        if (capacityNote && isSelectingGuests) capacityNote.textContent = availableCapacity.length
            ? 'Guest count must fit the seating style selected in Options.'
            : 'This event hall has no listed seating capacities.';
        else if (capacityNote) capacityNote.textContent = selectedCapacity
            ? `${styleLabels[styleSelect?.value] || 'Selected setup'} capacity: ${selectedCapacity} guests.`
            : `${styleLabels[styleSelect?.value] || 'Selected setup'} capacity is not listed for this event hall.`;
    }

    updateVillaSelection(option, refreshAvailability) {
        const hasSelection = Boolean(String(option?.dataset.id || '').trim());
        const name = hasSelection
            ? String(option.dataset.name || option.textContent.split('(')[0].trim())
            : '';
        const summaryVilla = this.getEl('sum-vl-type');
        if (summaryVilla) summaryVilla.textContent = hasSelection ? name : 'Not selected';
        this.updateVenueInformation(option, 'villa-description', 'villa-amenities', 'villa');
        this.updateSelectedItemImage(option, 'villa-image-panel', 'villa-img', 'villa-image-placeholder', 'villa');
        this.updateVillaInformation(option);

        const rateRaw = String(option?.dataset.extraPax ?? '').trim();
        const extraRate = Number.parseFloat(rateRaw);
        const extraRateLabel = this.getEl('villa-extra-rate');
        if (extraRateLabel) extraRateLabel.textContent = hasSelection && rateRaw !== '' && Number.isFinite(extraRate)
            ? this.formatCurrency(extraRate)
            : (hasSelection ? 'Rate not listed' : 'configured rate');

        const maxCapacity = Number.parseInt(option?.dataset.maxCap || '', 10);
        const villaGuests = this.getEl('villa-guests');
        if (villaGuests) {
            if (hasSelection && Number.isFinite(maxCapacity) && maxCapacity > 0) {
                villaGuests.max = String(maxCapacity);
                if (Number.parseInt(villaGuests.value, 10) > maxCapacity) villaGuests.value = String(maxCapacity);
            } else if (hasSelection) {
                villaGuests.removeAttribute('max');
            } else {
                villaGuests.max = '1';
            }
        }

        if (refreshAvailability && hasSelection && this.state.calendars.villa) {
            this.state.calendars.villa.fetchBookedDates('Resort Villa', name);
        }
    }

    setupToggle(checkboxId, targetId) {
        const checkbox = this.getEl(checkboxId);
        const target = this.getEl(targetId);
        if (checkbox && target) {
            checkbox.addEventListener("change", () => target.classList.toggle("hidden", !checkbox.checked));
        }
    }

    syncHotelRoomSelection() {
        const typeSelect = this.getEl('hotel-room-type');
        const roomSelect = this.getEl('hotel-room-name');
        const category = String(typeSelect?.value || '');
        if (!roomSelect) return;
        if (!category) {
            this.populateSpecificHotelRooms('');
            return;
        }

        const existingOption = roomSelect.options[roomSelect.selectedIndex];
        this.populateSpecificHotelRooms(category, {
            roomGroupId: existingOption?.dataset.roomGroupId || '',
            buildingName: existingOption?.dataset.name || ''
        });
    }

    populateSpecificHotelRooms(category, requestedSelection = null) {
        if (typeof window.hotelRoomData === "undefined") return;
        const nameSelect = this.getEl("hotel-room-name");
        if (!nameSelect) return;
        if (!category) {
            nameSelect.replaceChildren(new Option('Select category first...', '', true, true));
            nameSelect.disabled = true;
            const label = this.getEl("sum-ht-type");
            if (label) label.textContent = 'Not selected';
            this.updateHotelRoomDisplay(nameSelect.options[0]);
            this.calculateSummary();
            return;
        }
        const rooms = window.hotelRoomData[category];
        if (!rooms) return;

        const requestedGroupId = String(requestedSelection?.roomGroupId || '');
        const requestedBuilding = String(requestedSelection?.buildingName || '');

        nameSelect.innerHTML = '<option value="" disabled selected>Select a building...</option>';
        (Array.isArray(rooms) ? rooms : []).forEach((room) => {
            const opt = document.createElement("option");
            opt.value = room.nightly_rate;
            opt.dataset.roomGroupId = room.room_group_id || '';
            opt.dataset.roomTypeCode = room.room_type_code || '';
            opt.dataset.type     = room.room_type;
            opt.dataset.name     = room.building_name;
            opt.dataset.inventory = room.total_inventory;
            opt.dataset.img      = room.image || 'assets/img/placeholder.jpg';
            opt.dataset.display  = `${room.building_name}`;
            opt.dataset.baseCap  = room.base_capacity;
            opt.dataset.maxCap   = room.max_capacity;
            opt.dataset.extraPax = room.extra_pax_rate;
            opt.dataset.bedCount = room.bed_count || '';
            opt.dataset.checkIn = room.check_in_time || '';
            opt.dataset.checkOut = room.check_out_time || '';
            opt.dataset.description = room.venue_description || '';
            opt.dataset.amenities = room.venue_amenities || '';
            opt.textContent = `${room.building_name} (${room.total_inventory} Units)`;
            nameSelect.appendChild(opt);
        });
        nameSelect.disabled = nameSelect.options.length <= 1;

        const label = this.getEl("sum-ht-type");
        if (label) label.textContent = category;

        const restoredOption = Array.from(nameSelect.options).find(option => option.value && (
            (requestedGroupId && String(option.dataset.roomGroupId || '') === requestedGroupId)
            || (!requestedGroupId && requestedBuilding && String(option.dataset.name || '') === requestedBuilding)
        ));
        if (restoredOption) {
            nameSelect.selectedIndex = restoredOption.index;
            if (requestedSelection) {
                this.updateHotelRoomDisplay(restoredOption);
                this.state.calendars.hotel?.fetchBookedDates(restoredOption.dataset.type, restoredOption.dataset.name, null, false, restoredOption.dataset.roomGroupId || null);
                this.calculateSummary();
            } else {
                nameSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        } else {
            nameSelect.selectedIndex = 0;
            this.updateHotelRoomDisplay(nameSelect.options[0]);
            this.calculateSummary();
        }
    }

    updateHotelRoomDisplay(option) {
        const hasSelection = Boolean(String(option?.dataset.name || '').trim());
        this.updateHotelInformation(option);

        const hotelGuests = this.getEl('hotel-guests');
        if (hotelGuests) {
            hotelGuests.max = String(hasSelection ? (parseInt(option.dataset.maxCap, 10) || 1) : 1);
            if (parseInt(hotelGuests.value, 10) > parseInt(hotelGuests.max, 10)) hotelGuests.value = hotelGuests.max;
        }

        const roomLabel = this.getEl('sum-ht-room');
        if (roomLabel) roomLabel.textContent = hasSelection ? (option.dataset.display || option.text.split('(')[0].trim()) : 'Not selected';
        const checkIn = this.getEl('sum-ht-in');
        const checkOut = this.getEl('sum-ht-out');
        if (checkIn) checkIn.textContent = hasSelection ? this.formatTime(option.dataset.checkIn) : '—';
        if (checkOut) checkOut.textContent = hasSelection ? this.formatTime(option.dataset.checkOut) : '—';

        const imagePanel = this.getEl('hotel-image-panel');
        if (imagePanel) this.updateSelectedItemImage(option, 'hotel-image-panel', 'hotel-img', 'hotel-image-placeholder', 'accommodation');
    }

    updateHotelInformation(option) {
        const description = this.getEl('hotel-description');
        const amenities = this.getEl('hotel-amenities');
        if (!description || !amenities || !option) return;

        const hasSelection = Boolean(String(option.dataset.name || '').trim());
        const hasNightlyRate = option.value !== '' && Number.isFinite(Number(option.value));
        const hasExtraPaxRate = option.dataset.extraPax !== '' && Number.isFinite(Number(option.dataset.extraPax));
        const baseCapacity = parseInt(option.dataset.baseCap, 10);
        const maxCapacity = parseInt(option.dataset.maxCap, 10);
        const bedCount = parseInt(option.dataset.bedCount, 10);
        const hasDescription = hasSelection && Boolean((option.dataset.description || '').trim());
        description.textContent = hasDescription
            ? option.dataset.description
            : (hasSelection ? 'No additional description is available for this accommodation.' : 'Select a building to view its description.');
        description.classList.toggle('venue-description-empty', !hasDescription);
        amenities.innerHTML = '';
        const items = (option.dataset.amenities || '').split(/[,\n]+/).map(item => item.trim()).filter(Boolean);
        if (items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'amenities-empty';
            empty.textContent = hasSelection ? 'No amenities listed.' : 'Select a building to view its amenities.';
            amenities.appendChild(empty);
        } else {
            items.forEach(item => {
                const li = document.createElement('li');
                li.textContent = item;
                amenities.appendChild(li);
            });
        }

        const facts = {
            'hotel-base-capacity': hasSelection && Number.isFinite(baseCapacity) && baseCapacity > 0 ? `${baseCapacity} guests` : '—',
            'hotel-max-capacity': hasSelection && Number.isFinite(maxCapacity) && maxCapacity > 0 ? `${maxCapacity} guests` : '—',
            'hotel-bed-count': hasSelection && Number.isFinite(bedCount) && bedCount > 0 ? `${bedCount}` : '—',
            'hotel-nightly-rate': hasSelection && hasNightlyRate ? this.formatCurrency(option.value) : '—',
            'hotel-extra-rate-fact': hasSelection && hasExtraPaxRate ? this.formatCurrency(option.dataset.extraPax) : '—',
            'hotel-check-times': hasSelection ? `${this.formatTime(option.dataset.checkIn)} – ${this.formatTime(option.dataset.checkOut)}` : '—'
        };
        Object.entries(facts).forEach(([id, value]) => {
            const fact = this.getEl(id);
            if (fact) {
                fact.textContent = value;
                fact.classList.toggle('fact-placeholder', !hasSelection);
            }
        });
        const capacityNote = this.getEl('hotel-capacity-note');
        if (capacityNote) capacityNote.textContent = hasSelection
            ? `Maximum capacity: ${parseInt(option.dataset.maxCap, 10) || 0} guests.`
            : 'Select a building to see its maximum capacity.';
    }

    formatTime(value) {
        const match = String(value || '').match(/^(\d{1,2}):(\d{2})/);
        if (!match) return '—';
        let hour = parseInt(match[1], 10);
        const suffix = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12 || 12;
        return `${hour}:${match[2]} ${suffix}`;
    }

    renderVillaInclusions(id, raw, hasSelection) {
        const container = this.getEl(id);
        if (!container) return;
        container.replaceChildren();
        const items = String(raw || '').split(/[;,\n]+/).map(item => item.trim()).filter(Boolean);
        if (!items.length) {
            const empty = document.createElement('span');
            empty.className = 'villa-inclusion-empty';
            empty.textContent = hasSelection ? 'No additional inclusions listed.' : 'Select a villa to view inclusions.';
            container.appendChild(empty);
            return;
        }
        items.forEach(item => {
            const feature = document.createElement('span');
            feature.className = 'villa-inclusion-item';
            const marker = document.createElement('span');
            marker.className = 'villa-inclusion-marker';
            marker.setAttribute('aria-hidden', 'true');
            marker.textContent = '✓';
            feature.append(marker, document.createTextNode(item));
            container.appendChild(feature);
        });
    }

    updateVillaInformation(option) {
        if (!option) return;
        const hasSelection = Boolean(String(option.dataset.id || '').trim());
        const baseCapacity = Number.parseInt(option.dataset.baseCap || '', 10);
        const maxCapacity = Number.parseInt(option.dataset.maxCap || '', 10);
        const extraPaxRaw = String(option.dataset.extraPax || '').trim();
        const extraPaxRate = Number.parseFloat(extraPaxRaw);
        const dayRateRaw = String(option.value || '').trim();
        const nightRateRaw = String(option.dataset.overnight || '').trim();
        const dayRateNumber = Number.parseFloat(dayRateRaw);
        const nightRateNumber = Number.parseFloat(nightRateRaw);
        const configuredDayRate = dayRateRaw !== '' && Number.isFinite(dayRateNumber);
        const configuredNightRate = nightRateRaw !== '' && Number.isFinite(nightRateNumber);
        const facts = {
            'villa-base-capacity': hasSelection && Number.isFinite(baseCapacity) && baseCapacity > 0 ? `${baseCapacity} guests` : '—',
            'villa-max-capacity': hasSelection && Number.isFinite(maxCapacity) && maxCapacity > 0 ? `${maxCapacity} guests` : '—',
            'villa-extra-rate-fact': hasSelection && extraPaxRaw !== '' && Number.isFinite(extraPaxRate) ? this.formatCurrency(extraPaxRate) : '—',
            'villa-private-pool': hasSelection && option.dataset.privatePool === '1' ? 'Yes'
                : (hasSelection && option.dataset.privatePool === '0' ? 'No' : '—')
        };
        Object.entries(facts).forEach(([id, value]) => {
            const fact = this.getEl(id);
            if (fact) {
                fact.textContent = value;
                fact.classList.toggle('fact-placeholder', !hasSelection);
            }
        });
        const capacityNotes = [this.getEl('villa-capacity-note'), this.getEl('villa-capacity-note-guest')];
        capacityNotes.forEach(note => {
            if (note) note.textContent = !hasSelection
                ? 'Select a villa to view its configured capacity.'
                : (Number.isFinite(maxCapacity) && maxCapacity > 0
                    ? `Maximum capacity: ${maxCapacity} guests.`
                    : 'Capacity details are not available for this villa.');
        });
        const dayRate = !hasSelection ? '—' : (configuredDayRate ? this.formatCurrency(dayRateNumber) : 'Rate not listed');
        const overnightRate = !hasSelection ? '—' : (configuredNightRate ? this.formatCurrency(nightRateNumber) : 'Rate not listed');
        const dayDetails = this.getEl('stay-day-details');
        const nightDetails = this.getEl('stay-night-details');
        if (dayDetails) dayDetails.textContent = hasSelection
            ? `${dayRate} total · One calendar date · ${this.formatTime(option.dataset.dayIn)}–${this.formatTime(option.dataset.dayOut)}`
            : 'Select a villa to view rate and hours.';
        if (nightDetails) nightDetails.textContent = hasSelection
            ? `${overnightRate} total · One night · checkout next day · ${this.formatTime(option.dataset.nightIn)}–${this.formatTime(option.dataset.nightOut)}`
            : 'Select a villa to view rate and hours.';
        this.renderVillaInclusions('stay-day-inclusions', option.dataset.dayInclusions, hasSelection);
        this.renderVillaInclusions('stay-night-inclusions', option.dataset.nightInclusions, hasSelection);
        this.updateVillaStaySelection(document.querySelector('input[name="villa-stay"]:checked')?.value || 'Day Time Stay');
    }

    updateVillaStaySelection(stayType) {
        document.querySelectorAll('.villa-stay-card').forEach(card => {
            card.classList.toggle('selected', card.querySelector('input')?.value === stayType);
        });
        if (this.getEl('sum-vl-stay')) this.getEl('sum-vl-stay').textContent = stayType === 'Overnight' ? 'Overnight' : 'Day Time Stay';
        const option = this.getEl('villa-type')?.options[this.getEl('villa-type')?.selectedIndex];
        const hasSelection = Boolean(String(option?.dataset.id || '').trim());
        if (!hasSelection) {
            if (this.getEl('sum-vl-in')) this.getEl('sum-vl-in').textContent = '—';
            if (this.getEl('sum-vl-out')) this.getEl('sum-vl-out').textContent = '—';
            return;
        }
        const overnight = stayType === 'Overnight';
        const inTime = overnight ? option.dataset.nightIn : option.dataset.dayIn;
        const outTime = overnight ? option.dataset.nightOut : option.dataset.dayOut;
        if (this.getEl('sum-vl-in')) this.getEl('sum-vl-in').innerText = this.formatTime(inTime);
        if (this.getEl('sum-vl-out')) this.getEl('sum-vl-out').innerText = this.formatTime(outTime);
    }

    async configureVillaStayMode(stayType, clearExisting = true) {
        const calendar = this.state.calendars.villa;
        if (!calendar) return true;
        const duration = stayType === 'Overnight' ? 1 : 0;
        const previousDuration = calendar.fixedDurationNights;
        const previousGuard = calendar.fixedDurationGuard;
        const changed = previousDuration !== duration || previousGuard !== true;
        calendar.fixedDurationNights = duration;
        calendar.fixedDurationGuard = true;
        if (clearExisting && changed && (calendar.startDate || calendar.endDate || this.state.isDatesLocked)) {
            if (this.state.isDatesLocked && !(await this.unlockDatesAPI())) {
                calendar.fixedDurationNights = previousDuration;
                calendar.fixedDurationGuard = previousGuard;
                return false;
            }
            calendar.clearSelectedRange();
            this.state.activeCalendar = null;
        }
        const help = this.getEl('villa-calendar-help');
        if (help) help.textContent = stayType === 'Overnight'
            ? 'Overnight: one night · checkout is the next calendar day.'
            : 'Day Time Stay: one calendar date.';
        return true;
    }

    updateVenueInformation(option, descriptionId, amenitiesId, itemLabel = 'venue') {
        const description = this.getEl(descriptionId);
        const amenities = this.getEl(amenitiesId);
        if (!description || !amenities || !option) return;
        const hasSelection = Boolean(String(option.dataset.id || option.dataset.name || '').trim());
        const article = /^[aeiou]/i.test(itemLabel) ? 'an' : 'a';
        const hasDescription = hasSelection && Boolean((option.dataset.description || '').trim());
        description.textContent = hasDescription
            ? option.dataset.description
            : (hasSelection
                ? `No additional description is available for this ${itemLabel}.`
                : `Select ${article} ${itemLabel} to view its description.`);
        description.classList.toggle('venue-description-empty', !hasDescription);
        amenities.replaceChildren();
        const items = (option.dataset.amenities || '').split(/[;,\n]+/).map(item => item.trim()).filter(Boolean);
        if (!items.length) {
            const empty = document.createElement('li');
            empty.className = 'amenities-empty';
            empty.textContent = hasSelection ? 'No amenities listed.' : `Select ${article} ${itemLabel} to view its amenities.`;
            amenities.appendChild(empty);
            return;
        }
        items.forEach(item => {
            const li = document.createElement('li');
            li.textContent = item;
            amenities.appendChild(li);
        });
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
        document.querySelectorAll('[data-booking-tablist] [role="tab"]').forEach(tab => {
            const selected = tab === btn;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        });
        document.querySelectorAll(".tab-content").forEach(panel => {
            const active = panel.id === `tab-${target}`;
            panel.classList.toggle('active', active);
            panel.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        document.querySelectorAll(".summary-container").forEach(summary => {
            const active = summary.id === `sum-${target}`;
            summary.classList.toggle('active', active);
            summary.setAttribute('aria-hidden', active ? 'false' : 'true');
        });

        this.state.activeTabId = target;
        this.state.currentStep = 1;
        this.clearBookingStepError();
        this.updateBookingStepUI();
        this.updateBookingSubmitLabel();
    }

    updateBookingSubmitLabel() {
        const button = this.getEl('btn-proceed');
        if (!button) return;
        const labels = {
            'event-hall': this.auth.isCustomer ? 'SUBMIT EVENT INQUIRY' : 'SIGN IN TO SUBMIT EVENT INQUIRY',
            'hotel-rooms': this.auth.isCustomer ? 'RESERVE HOTEL ROOM' : 'SIGN IN TO RESERVE A HOTEL ROOM',
            'resort-villa': this.auth.isCustomer ? 'RESERVE RESORT VILLA' : 'SIGN IN TO RESERVE A RESORT VILLA'
        };
        button.textContent = labels[this.state.activeTabId] || 'SUBMIT BOOKING';
        button.style.backgroundColor = this.state.activeTabId === 'event-hall' ? 'var(--color-dark)' : 'var(--color-gold)';
    }

    startTimer(expiresAt) {
        if (this.state.timerInterval) return;
        const expiry = Number(expiresAt || this.state.lockExpiresAt);
        if (!Number.isFinite(expiry) || expiry <= Math.floor(Date.now() / 1000)) {
            this.stopTimerAndReset();
            this.returnToDatesStep('Your temporary hold expired. Confirm your dates again to continue.');
            showAlert("Hold unavailable", "The temporary hold could not be confirmed. Please choose your dates again.", "error");
            return;
        }
        this.state.lockExpiresAt = expiry;
        const timerBox = this.getEl("timer-box");
        const countdownEl = this.getEl("countdown");
        
        timerBox?.classList.add("running");
        this.getEl("timer-text").style.display = "none";
        this.getEl("countdown-wrapper").style.display = "inline";

        this.state.timerInterval = setInterval(() => {
            const remaining = Math.max(0, this.state.lockExpiresAt - Math.floor(Date.now() / 1000));
            const minutes = Math.floor(remaining / 60);
            const seconds = String(remaining % 60).padStart(2, '0');
            if(countdownEl) countdownEl.innerText = minutes + ':' + seconds;

            if (remaining <= 0) {
                this.stopTimerAndReset();
                this.returnToDatesStep('Your temporary hold expired. Confirm your dates again to continue.');
                showAlert("Hold expired", "Your temporary hold has expired. Please confirm your dates again.", "warning");
                const proceedBtn = this.getEl("btn-proceed");
                if(proceedBtn) { proceedBtn.disabled = true; proceedBtn.style.opacity = "0.5"; }
            }
        }, 1000);
        const remaining = Math.max(0, this.state.lockExpiresAt - Math.floor(Date.now() / 1000));
        if (countdownEl) countdownEl.innerText = Math.floor(remaining / 60) + ':' + String(remaining % 60).padStart(2, '0');
    }

    stopTimerAndReset() {
        clearInterval(this.state.timerInterval);
        this.state.timerInterval = null;
        this.state.timeLimit = 900;
        this.state.isDatesLocked = false;
        this.state.lockExpiresAt = null;
        this.state.confirmedSelectionKey = '';
        window.isDatesLocked = false;

        const timerBox = this.getEl("timer-box");
        timerBox?.classList.remove("running");
        if(this.getEl("timer-text")) this.getEl("timer-text").style.display = "inline";
        if(this.getEl("countdown-wrapper")) this.getEl("countdown-wrapper").style.display = "none";
        if(this.getEl("terms-check")) this.getEl("terms-check").checked = false;

        // Event-only selections must not survive a tab switch or cancelled
        // booking attempt.
        ['check-catering', 'check-av', 'check-rooms'].forEach(id => {
            const checkbox = this.getEl(id);
            if (checkbox) checkbox.checked = false;
        });
        this.getEl('catering-options')?.classList.add('hidden');
        this.getEl('rooms-options')?.classList.add('hidden');
        const selectedRoomGroups = this.getEl('selected-room-groups');
        if (selectedRoomGroups) selectedRoomGroups.innerHTML = '';
        this.resetAddonStayDates();

        if (this.state.activeCalendar) this.state.activeCalendar.clearSelection();
        this.calculateSummary();
    }

    returnToDatesStep(message) {
        this.setBookingStep(2, { focus: false });
        const calendar = this.getActiveBookingCalendar();
        this.showBookingStepIssue({ step: 2, message, target: calendar?.container });
    }

    async unlockDatesAPI() {
        if (!this.auth.isCustomer) {
            this.state.isDatesLocked = false;
            window.isDatesLocked = false;
            return true;
        }
        try {
            const res = await fetch('actions/bookings/unlock_dates.php', {
                method: 'POST',
                headers: { "X-CSRF-Token": this.csrfToken }
            });
            const response = (await res.text()).split('|');
            if (!res.ok || response[0] !== 'Success') {
                throw new Error(response[1] || 'Temporary holds could not be released.');
            }
            this.state.isDatesLocked = false;
            window.isDatesLocked = false;
            clearInterval(this.state.timerInterval);
            this.state.timerInterval = null;
            this.state.timeLimit = 900;
            this.state.lockExpiresAt = null;
            this.state.confirmedSelectionKey = '';
            this.getEl("timer-box")?.classList.remove("running");
            if (this.getEl("timer-text")) this.getEl("timer-text").style.display = "inline";
            if (this.getEl("countdown-wrapper")) this.getEl("countdown-wrapper").style.display = "none";
            this.state.addonConfirmedRange = null;
            this.state.addonAvailabilityToken++;
            this.state.addonAvailabilityPending = false;
            this.state.addonAvailabilityReady = false;
            this.state.addonAvailabilityRangeKey = '';
            return true;
        } catch (error) {
            console.error("Unlock failed", error);
            return false;
        }
    }

    async lockSelectedDates(startDate, endDate, calendarInstance, { dateModal = null, confirmBtn = null } = {}) {
        const isEventInquiry = this.state.activeTabId === 'event-hall';
        if (!this.auth.isCustomer || isEventInquiry || !calendarInstance?.startDate) return false;

        const lockData = this.getTabContextData();
        if (!lockData.roomName && !lockData.venueId) {
            showAlert('Notice', 'Please select a specific venue/room from the dropdown first!');
            return false;
        }
        const toCanonicalDate = value => {
            if (!(value instanceof Date) || Number.isNaN(value.getTime())) return '';
            const local = new Date(value.getTime());
            local.setHours(0, 0, 0, 0);
            const canonical = this.formatSafeDate(local);
            return this.isValidDraftDate(canonical) ? canonical : '';
        };
        const startValue = toCanonicalDate(startDate);
        const endValue = toCanonicalDate(endDate) || startValue;
        if (!startValue || !endValue || endValue < startValue) {
            showAlert('Notice', 'Please select valid dates before placing a hold.');
            return false;
        }
        const formData = new FormData();
        formData.append('start_date', startValue);
        formData.append('end_date', endValue);
        if (lockData.venueId) formData.append('venue_id', lockData.venueId);
        formData.append('room_type', lockData.roomType || '');
        formData.append('room_name', lockData.roomName || '');
        if (lockData.roomGroupId) formData.append('room_group_id', lockData.roomGroupId);
        if (lockData.roomType !== 'Event Hall' && lockData.guests) formData.append('guests', lockData.guests);
        if (lockData.roomType === 'Resort Villa') {
            formData.append('stay_type', document.querySelector('input[name="villa-stay"]:checked')?.value || 'Day Time Stay');
        }

        let serverLockCreated = false;
        try {
            const res = await fetch('actions/bookings/lock_dates.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfToken },
                body: formData
            });
            if (res.status === 401 || res.status === 403) {
                const sessionError = new Error('Your session has expired. Please sign in again.');
                sessionError.sessionExpired = true;
                sessionError.status = res.status;
                throw sessionError;
            }
            const response = (await res.text()).split('|');
            if (!res.ok || response[0] !== 'Success') {
                throw new Error(response[1] || 'The selected dates could not be held.');
            }
            serverLockCreated = true;
            const expiresAt = Number(response[2] || 0);
            if (!Number.isFinite(expiresAt) || expiresAt <= Math.floor(Date.now() / 1000)) {
                throw new Error('The server did not return a valid hold expiry.');
            }

            this.state.confirmedSelectionKey = this.buildSelectionKey(calendarInstance);
            this.state.isDatesLocked = true;
            window.isDatesLocked = true;
            this.state.lockExpiresAt = expiresAt;
            const proceed = this.getEl('btn-proceed');
            if (proceed) { proceed.disabled = false; proceed.style.opacity = ''; }
            calendarInstance.updateDateDisplay();
            this.calculateSummary();
            this.saveDraft();
            this.startTimer(expiresAt);
            serverLockCreated = false;
            return true;
        } catch (err) {
            if (serverLockCreated) {
                try {
                    const release = await fetch('actions/bookings/unlock_dates.php', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': this.csrfToken }
                    });
                    const releaseResponse = (await release.text()).split('|');
                    if (!release.ok || releaseResponse[0] !== 'Success') {
                        throw new Error(releaseResponse[1] || 'The temporary hold could not be released.');
                    }
                } catch (releaseError) {
                    // Preserve the original lock/setup failure for the user;
                    // leave a diagnostic for operators if cleanup failed.
                    console.error('Fresh hold cleanup failed', releaseError);
                }
            }
            calendarInstance.clearSelectedRange();
            if (this.state.activeCalendar === calendarInstance) this.state.activeCalendar = null;
            this.state.confirmedSelectionKey = '';
            this.state.isDatesLocked = false;
            this.state.lockExpiresAt = null;
            window.isDatesLocked = false;
            clearInterval(this.state.timerInterval);
            this.state.timerInterval = null;
            this.getEl('timer-box')?.classList.remove('running');
            const proceed = this.getEl('btn-proceed');
            if (proceed) { proceed.disabled = true; proceed.style.opacity = '0.5'; }
            if (err.sessionExpired || err.status === 403) {
                showAlert('Session Expired', err.message, 'error', true);
            } else {
                showAlert('Hold unavailable', `Error: ${err.message}`, 'error');
                const refreshData = this.getTabContextData();
                calendarInstance.fetchBookedDates(refreshData.roomType, refreshData.roomName, refreshData.venueId, false, refreshData.roomGroupId);
            }
            this.calculateSummary();
            this.saveDraft();
            dateModal?.classList.remove('active');
            if (confirmBtn) confirmBtn.disabled = false;
            return false;
        }
    }

    requestDateConfirmation(startDate, endDate, calendarInstance) {
        if (calendarInstance === this.state.calendars.addonHotel) {
            this.openAddonDateConfirmation(startDate, endDate);
            return;
        }
        this.state.activeCalendar = calendarInstance;
        const dateModal = this.getEl("date-confirm-modal");
        const isEventInquiry = this.state.activeTabId === 'event-hall';
        
        const opts = { month: "short", day: "numeric", year: "numeric" };
        const startStr = startDate.toLocaleDateString("en-US", opts);
        const endStr = endDate ? endDate.toLocaleDateString("en-US", opts) : startStr;

        const title = dateModal?.querySelector('.modal-title');
        const subtext = dateModal?.querySelector('.modal-subtext');
        const canHold = this.auth.isCustomer && !isEventInquiry;
        if (title) title.textContent = isEventInquiry ? 'Confirm Event Dates' : (canHold ? 'Confirm dates for a 15-minute hold' : 'Confirm your dates');
        if (subtext) subtext.textContent = isEventInquiry
            ? 'Checking availability only; your event quote remains subject to resort review.'
            : (canHold ? 'Your selection is available now. Confirm to place the server-authoritative 15-minute hold.' : 'Available now — not held. Keep this selection and sign in when you are ready to reserve.');
        const dateLabel = endDate && endDate.getTime() !== startDate.getTime()
            ? `${startStr} — ${endStr}`
            : (this.state.activeTabId === 'resort-villa' ? `${startStr} (one calendar date)` : startStr);
        if (this.getEl("selected-date-text")) this.getEl("selected-date-text").innerText = dateLabel;
        if (dateModal) dateModal.classList.add("active");

        const confirmBtn = this.replaceElement("btn-confirm-date");
        this.replaceElement("btn-cancel-date").addEventListener("click", () => {
            dateModal.classList.remove("active");
            calendarInstance.clearSelectedRange();
            this.state.confirmedSelectionKey = '';
            this.saveDraft();
        });

        confirmBtn.addEventListener("click", async () => {
            const lockData = this.getTabContextData();
            if (!lockData.roomName && !lockData.venueId) {
                showAlert("Notice", "Please select a specific venue/room from the dropdown first!");
                return;
            }

            // Event Hall inquiries and guests only confirm a local selection.
            // Guest actions never call a lock endpoint or create booking_locks.
            if (!canHold) {
                dateModal.classList.remove("active");
                this.state.isDatesLocked = false;
                this.state.lockExpiresAt = null;
                this.state.confirmedSelectionKey = this.buildSelectionKey(calendarInstance);
                window.isDatesLocked = false;
                calendarInstance.updateDateDisplay();
                this.saveDraft();
                this.calculateSummary();
                return;
            }

            this.state.confirmedSelectionKey = this.buildSelectionKey(calendarInstance);
            confirmBtn.innerText = "Locking...";
            confirmBtn.disabled = true;
            try {
                const locked = await this.lockSelectedDates(startDate, endDate, calendarInstance, { dateModal, confirmBtn });
                if (locked) dateModal.classList.remove("active");
            } catch (err) {
                dateModal.classList.remove("active");
                showAlert("Hold unavailable", err.message || "The selected dates could not be held.", "error");
            } finally {
                confirmBtn.innerText = "Confirm";
                confirmBtn.disabled = false;
            }
        });
    }

    openAddonDateConfirmation(startDate, endDate) {
        const dateModal = this.getEl('date-confirm-modal');
        const calendar = this.state.calendars.addonHotel;
        if (!dateModal || !calendar || !startDate || !endDate || endDate <= startDate) return;

        const previous = this.state.addonConfirmedRange;
        this.state.pendingDateConfirmation = {
            start: new Date(startDate),
            end: new Date(endDate),
            previous: previous ? { start: new Date(previous.start), end: new Date(previous.end), nights: previous.nights } : null,
            previousAvailabilityReady: this.state.addonAvailabilityReady,
            previousAvailabilityRangeKey: this.state.addonAvailabilityRangeKey
        };
        this.state.addonAvailabilityPending = true;
        this.state.addonAvailabilityReady = false;
        this.state.addonAvailabilityRangeKey = '';
        this.state.addonAvailabilityToken++;
        document.querySelectorAll('.room-group-card').forEach(card => {
            const addBtn = card.querySelector('.btn-add-room-group');
            if (addBtn) addBtn.disabled = true;
            const label = card.querySelector('.room-avail-label');
            if (label) label.textContent = 'Confirm dates to check availability';
        });
        const opts = { month: 'short', day: 'numeric', year: 'numeric' };
        const title = dateModal.querySelector('.modal-title');
        const subtext = dateModal.querySelector('.modal-subtext');
        if (title) title.textContent = 'Confirm Hotel Stay';
        if (subtext) subtext.textContent = 'Confirming these dates checks room availability for this Event Hall add-on. Your event quote remains subject to resort review.';
        if (this.getEl('selected-date-text')) this.getEl('selected-date-text').textContent = `${startDate.toLocaleDateString('en-US', opts)} — ${endDate.toLocaleDateString('en-US', opts)}`;
        dateModal.classList.add('active');

        const confirmBtn = this.replaceElement('btn-confirm-date');
        const cancelBtn = this.replaceElement('btn-cancel-date');
        if (confirmBtn) confirmBtn.addEventListener('click', () => this.confirmAddonDateRange());
        if (cancelBtn) cancelBtn.addEventListener('click', () => this.cancelPendingDateConfirmation());
    }

    confirmAddonDateRange() {
        const pending = this.state.pendingDateConfirmation;
        const dateModal = this.getEl('date-confirm-modal');
        if (!pending) return;
        const nights = Math.round((pending.end - pending.start) / 86400000);
        if (nights < 1) return;
        this.state.addonConfirmedRange = { start: new Date(pending.start), end: new Date(pending.end), nights };
        this.state.pendingDateConfirmation = null;
        const calendar = this.state.calendars.addonHotel;
        calendar?.setSelection(pending.start, pending.end);
        if (dateModal) dateModal.classList.remove('active');
        const display = this.getEl('addon-room-date-display');
        if (display) display.textContent = `${this.formatSafeDate(pending.start)} to ${this.formatSafeDate(pending.end)} (${nights} night${nights === 1 ? '' : 's'})`;
        this.updateRoomAvailabilityLabels(pending.start, pending.end);
        this.calculateSummary();
        this.saveDraft();
    }

    cancelPendingDateConfirmation() {
        const pending = this.state.pendingDateConfirmation;
        const dateModal = this.getEl('date-confirm-modal');
        if (!pending) {
            dateModal?.classList.remove('active');
            return;
        }
        this.state.pendingDateConfirmation = null;
        const calendar = this.state.calendars.addonHotel;
        if (pending.previous) {
            this.state.addonConfirmedRange = pending.previous;
            this.state.addonAvailabilityPending = false;
            this.state.addonAvailabilityReady = pending.previousAvailabilityReady === true;
            this.state.addonAvailabilityRangeKey = pending.previousAvailabilityRangeKey || '';
            calendar?.setSelection(pending.previous.start, pending.previous.end);
            const display = this.getEl('addon-room-date-display');
            if (display) display.textContent = `${this.formatSafeDate(pending.previous.start)} to ${this.formatSafeDate(pending.previous.end)} (${pending.previous.nights} night${pending.previous.nights === 1 ? '' : 's'})`;
            this.updateRoomAvailabilityLabels(pending.previous.start, pending.previous.end);
        } else {
            this.state.addonConfirmedRange = null;
            this.state.addonAvailabilityPending = false;
            this.state.addonAvailabilityReady = false;
            this.state.addonAvailabilityRangeKey = '';
            calendar?.clearSelectedRange();
            const display = this.getEl('addon-room-date-display');
            if (display) display.textContent = 'Select a stay of at least 1 night';
            this.state.addonAvailabilityToken++;
        }
        dateModal?.classList.remove('active');
        this.calculateSummary();
        this.saveDraft();
    }

    // =========================================================================
    // After dates are locked, fetch real availability counts for room group cards
    // =========================================================================
    async updateRoomAvailabilityLabels(startDate, endDate) {
        if (!startDate) return;
        const requestToken = ++this.state.addonAvailabilityToken;
        const start = this.formatSafeDate(startDate);
        const end   = endDate ? this.formatSafeDate(endDate) : start;
        const rangeKey = `${start}|${end}`;
        this.state.addonAvailabilityPending = true;
        this.state.addonAvailabilityReady = false;
        this.state.addonAvailabilityRangeKey = '';
        const cards = Array.from(document.querySelectorAll('.room-group-card'));
        cards.forEach(card => {
            card.removeAttribute('data-available');
            const addBtn = card.querySelector('.btn-add-room-group');
            if (addBtn) addBtn.disabled = true;
            const label = card.querySelector('.room-avail-label');
            if (label) {
                label.style.color = '';
                label.textContent = 'Checking availability…';
            }
        });

        const results = await Promise.all(cards.map(async (card) => {
            const building  = card.dataset.building;
            const roomType  = card.dataset.roomType;
            const label     = card.querySelector('.room-avail-label');
            const addBtn    = card.querySelector('.btn-add-room-group');
            if (!label) return false;

            try {
                const groupParam = card.dataset.roomGroupId && card.dataset.roomGroupId !== "0" ? `&room_group_id=${encodeURIComponent(card.dataset.roomGroupId)}` : '';
                const url = `actions/bookings/get_room_availability.php?building_name=${encodeURIComponent(building)}&room_type=${encodeURIComponent(roomType)}&start_date=${start}&end_date=${end}${groupParam}`;
                const res  = await fetch(url);
                const data = await res.json();
                const confirmed = this.getAddonStayRange();
                if (requestToken !== this.state.addonAvailabilityToken || !confirmed || this.formatSafeDate(confirmed.start) !== start || this.formatSafeDate(confirmed.end) !== end) return false;
                if (data.success && Number.isInteger(Number(data.available)) && Number(data.available) >= 0) {
                    const n = data.available;
                    label.style.color = n > 0 ? '#2a7a3b' : '#c0392b';
                    label.textContent = n > 0 ? `${n} room${n > 1 ? 's' : ''} available` : 'No rooms available for these dates';
                    if (addBtn) addBtn.disabled = (n <= 0);
                    // Store available count on the card for quantity validation
                    card.dataset.available = n;
                    const selectedRow = document.querySelector(`.selected-room-row[data-sel-key="${CSS.escape(card.dataset.groupKey)}"]`);
                    const selectedQty = selectedRow?.querySelector('.sel-room-qty');
                    if (selectedRow && n === 0) {
                        // A newly confirmed range may invalidate a previous
                        // room choice. Remove it so unavailable inventory can
                        // never be submitted or included in the estimate.
                        selectedRow.remove();
                        this.calculateSummary();
                        this.saveDraft();
                    } else if (selectedQty) {
                        selectedQty.max = Math.max(n, 1);
                        if (n > 0 && parseInt(selectedQty.value) > n) {
                            selectedQty.value = n;
                            this.calculateSummary();
                            this.saveDraft();
                        }
                    }
                    return true;
                }
                return false;
            } catch(e) {
                return false;
            }
        }));
        if (requestToken === this.state.addonAvailabilityToken && this.getAddonStayRange() && this.state.addonConfirmedRange && rangeKey === `${this.formatSafeDate(this.state.addonConfirmedRange.start)}|${this.formatSafeDate(this.state.addonConfirmedRange.end)}`) {
            this.state.addonAvailabilityPending = false;
            this.state.addonAvailabilityReady = results.length === cards.length && results.every(Boolean);
            this.state.addonAvailabilityRangeKey = this.state.addonAvailabilityReady ? rangeKey : '';
            if (!this.state.addonAvailabilityReady) {
                cards.forEach(card => {
                    const label = card.querySelector('.room-avail-label');
                    const addBtn = card.querySelector('.btn-add-room-group');
                    if (addBtn) addBtn.disabled = true;
                    if (label) label.textContent = 'Availability could not be confirmed — choose dates again';
                });
                this.calculateSummary();
            }
        }
    }
    // =========================================================================

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
        this.state.summary.rows.push({
            label: String(label),
            amount: Number.isFinite(Number(amount)) ? Number(amount) : 0
        });
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
    // CALCULATION LOGIC
    // =========================================================================
    calculateSummary() {
        this.state.summary.total = 0;
        this.state.summary.rows = [];
        this.state.summary.bundleDiscount = 0;

        const breakdownEl = this.getEl('summary-breakdown');
        const totalValEl = this.getEl('summary-total-val');
        const dueValEl = this.getEl('summary-due-val');
        const pricingSection = this.getEl('pricing-section');
        const eventEstimateCard = this.getEl('event-estimate-card');
        const eventEstimateGuidance = this.getEl('event-estimate-guidance');
        const pricingGuidance = this.getEl('pricing-guidance');
        const context = this.getTabContextData();
        const calendarKey = this.state.activeTabId === 'event-hall' ? 'event'
            : (this.state.activeTabId === 'hotel-rooms' ? 'hotel' : 'villa');
        const calendar = this.state.calendars[calendarKey];
        const hasVenue = this.state.activeTabId === 'hotel-rooms'
            ? Boolean(context.roomType && context.roomName)
            : Boolean(context.venueId);
        const hasDates = this.state.activeTabId === 'hotel-rooms'
            ? Boolean(calendar?.startDate && calendar?.endDate)
            : Boolean(calendar?.startDate);
        const isEventInquiry = this.state.activeTabId === 'event-hall';
        const eventGuests = Number.parseInt(this.getEl('event-guests')?.value, 10) || 0;
        const hasRequiredEstimateInputs = !isEventInquiry || !this.getEl('check-catering')?.checked || eventGuests >= 10;
        const hasPriceInputs = hasVenue && hasDates && hasRequiredEstimateInputs;

        switch (this.state.activeTabId) {
            case 'hotel-rooms': this.calcHotelMath(); break;
            case 'event-hall': this.calcEventMath(); break;
            case 'resort-villa': this.calcVillaMath(); break;
        }
        
        if (breakdownEl) {
            breakdownEl.replaceChildren();
            if (hasPriceInputs && this.state.summary.rows.length === 0) {
                const emptyRow = document.createElement('div');
                emptyRow.className = 'summary-row';
                emptyRow.style.color = '#b5884e';
                const emptyLabel = document.createElement('i');
                emptyLabel.textContent = 'No items selected';
                emptyRow.appendChild(emptyLabel);
                breakdownEl.appendChild(emptyRow);
            } else {
                this.state.summary.rows.forEach(({ label, amount }) => {
                    const row = document.createElement('div');
                    row.className = 'summary-row';
                    row.style.cssText = 'display:flex; justify-content:space-between; margin-bottom: 5px;';
                    const labelEl = document.createElement('span');
                    labelEl.textContent = label;
                    const amountEl = document.createElement('span');
                    amountEl.textContent = this.formatCurrency(amount);
                    row.append(labelEl, amountEl);
                    breakdownEl.appendChild(row);
                });
            }
        }
        if (pricingSection) pricingSection.hidden = isEventInquiry || !hasPriceInputs;
        if (eventEstimateCard) eventEstimateCard.hidden = !isEventInquiry || !hasPriceInputs;
        if (eventEstimateGuidance) eventEstimateGuidance.hidden = !isEventInquiry || hasPriceInputs;
        if (pricingGuidance) {
            pricingGuidance.hidden = isEventInquiry || hasPriceInputs;
            pricingGuidance.textContent = this.state.activeTabId === 'hotel-rooms'
                ? 'Choose a room and dates to see your estimate.'
                : 'Choose a villa and dates to see your estimate.';
        }
        if (totalValEl) totalValEl.textContent = hasPriceInputs ? this.formatCurrency(this.state.summary.total) : '—';
        const eventEstimate = this.getEl('event-estimate-total');
        if (eventEstimate && isEventInquiry && hasPriceInputs) eventEstimate.textContent = this.formatCurrency(this.state.summary.total);

        let activeRadioName = 'hotel-payment';
        let summaryTextId = 'sum-ht-payment'; 
        let schemePct = 1.0;
        let schemeText = '100% Full';

        if (isEventInquiry) {
            summaryTextId = 'sum-ev-payment';
            schemeText = 'To Be Arranged'; 
            schemePct = 0; 
            if (this.getEl("timer-box")) this.getEl("timer-box").style.display = "none";

        } else {
            if (this.getEl("timer-box")) {
                this.getEl("timer-box").style.display = this.state.isDatesLocked ? "block" : "none";
            }
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

        }
        this.updateBookingSubmitLabel();

        this.state.summary.amountDue = this.state.summary.total * schemePct;

        if (this.getEl(summaryTextId)) {
            this.getEl(summaryTextId).innerText = schemeText; 
        }

        if (dueValEl) dueValEl.textContent = hasPriceInputs ? this.formatCurrency(this.state.summary.amountDue) : '—';

        const bundleEstimateEl = this.getEl('event-bundle-estimate');
        const bundleAmountEl = this.getEl('event-bundle-estimate-amount');
        const bundleDiscount = this.state.summary.bundleDiscount;
        if (bundleEstimateEl) {
            bundleEstimateEl.style.display = isEventInquiry && hasPriceInputs && bundleDiscount > 0 ? 'block' : 'none';
        }
        if (bundleAmountEl) bundleAmountEl.textContent = hasPriceInputs ? this.formatCurrency(bundleDiscount) : '—';
    }

    calcHotelMath() {
        const nights = this.state.calendars.hotel?.totalNights || 1;
        const roomRate = this.safeFloat(this.getEl('hotel-room-name')?.value);
        if (roomRate > 0) {
            const roomTotal = roomRate * nights;
            this.state.summary.total += roomTotal; 
            this.appendSummaryRow(`Base Room Rate (x${nights} nights)`, roomTotal);
        }

        // Get extra pax rate from the selected option's data attribute (from DB)
        const nameSelect = this.getEl('hotel-room-name');
        const selectedOpt = nameSelect?.options[nameSelect?.selectedIndex];
        const baseCap = parseInt(selectedOpt?.dataset.baseCap) || 2;
        const extraPaxRate = parseFloat(selectedOpt?.dataset.extraPax) || 800;

        const extraFee = this.calcExtraPax(this.getEl('hotel-guests'), baseCap, extraPaxRate, this.getEl('hotel-extra-fee'), this.getEl('sum-ht-guests'));
        const feeSummary = this.getEl('sum-ht-fee');
        if (feeSummary) feeSummary.textContent = nameSelect?.value ? this.formatCurrency(extraFee) : '—';
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

    getEventBundleDiscountPercent() {
        const raw = Number(this.getEl('event-bundle-estimate')?.dataset.discountPercent);
        return Number.isFinite(raw) && raw >= 0 && raw <= 100 ? raw : 20;
    }

    formatEventBundleDiscountPercent(percent) {
        return Number(percent.toFixed(2)).toString();
    }

    calcEventMath() {
        const days = this.state.calendars.event?.totalNights || 1;
        const venue = this.safeFloat(this.getEl('event-venue')?.value) * days;
        const guestCount = Number(this.getEl('event-guests')?.value);
        const guestSummary = this.getEl('sum-ev-guests');
        if (guestSummary) guestSummary.textContent = Number.isSafeInteger(guestCount) && guestCount > 0 ? String(guestCount) : '--';
        
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
        if (cateringTotal > 0) {
            this.state.summary.total += cateringTotal;
            this.syncSystemLineItem('catering', cateringName, cateringTotal);
        }

        // HOTEL ROOM ADD-ON (Real inventory via selected-room-groups)
        const roomsEnabled = this.getEl('check-rooms')?.checked === true;
        const addonStay = roomsEnabled && !this.state.addonAvailabilityPending && this.state.addonAvailabilityReady ? this.getAddonStayRange() : null;
        const roomNights = addonStay?.nights || 0;
        let roomSubtotal = 0;
        document.querySelectorAll('.selected-room-row').forEach(row => {
            const rate = parseFloat(row.dataset.rate) || 0;
            const qty  = parseInt(row.querySelector('.sel-room-qty')?.value) || 0;
            const building  = row.dataset.building;
            const roomType  = row.dataset.roomType;
            if (rate > 0 && qty > 0 && roomNights > 0) {
                const lineTotal = rate * qty * roomNights;
                roomSubtotal += lineTotal;
                this.state.summary.total += lineTotal;
                this.appendSummaryRow(`${building} — ${roomType} (×${qty} room${qty > 1 ? 's' : ''}, ×${roomNights} night${roomNights > 1 ? 's' : ''})`, lineTotal);
            }
        });

        // Match the administrator's authoritative finalization rule: the
        // configured bundle estimate applies only to hall base + selected
        // room subtotal. Staff still finalizes the quote server-side.
        const bundleDiscountPercent = this.getEventBundleDiscountPercent();
        if (roomSubtotal > 0 && venue > 0) {
            const estimatedDiscount = Math.round((venue + roomSubtotal) * (bundleDiscountPercent / 100) * 100) / 100;
            this.state.summary.bundleDiscount = estimatedDiscount;
            if (estimatedDiscount > 0) {
                this.state.summary.total -= estimatedDiscount;
                this.appendSummaryRow(`Estimated — final quote after resort review: Event Hall + Hotel Bundle Discount (${this.formatEventBundleDiscountPercent(bundleDiscountPercent)}%)`, -estimatedDiscount);
            }
        }
        const bundleLabel = this.getEl('event-bundle-estimate-label');
        if (bundleLabel) bundleLabel.textContent = `Event Hall + Hotel Bundle Discount (${this.formatEventBundleDiscountPercent(bundleDiscountPercent)}%)`;

        // A/V SETUP ADD-ON
        let avTotal = 0;
        const avCheckbox = this.getEl('check-av');
        if (avCheckbox?.checked) {
            avTotal = this.safeFloat(avCheckbox.value);
        }
        if (avTotal > 0) {
            this.state.summary.total += avTotal;
            this.syncSystemLineItem('av', 'Premium A/V Setup', avTotal);
        }
    }

    calcVillaMath() {
        const nights = this.state.calendars.villa?.totalNights || 1;
        const villaSelect = this.getEl('villa-type');
        const villaOpt = villaSelect?.options[villaSelect?.selectedIndex];
        const activeStayRadio = document.querySelector('input[name="villa-stay"]:checked');
        let stayText = 'Day Time Stay';
        let stayRate = this.safeFloat(villaSelect?.value);

        if (activeStayRadio) {
            const isOvernight = activeStayRadio.value === 'Overnight';
            stayText = isOvernight ? 'Overnight' : 'Day Time Stay';
            stayRate = isOvernight ? this.safeFloat(villaOpt?.dataset.overnight) : this.safeFloat(villaOpt?.value);
        }
        this.updateVillaStaySelection(stayText);
        const villa = stayRate * nights;
        this.state.summary.total += villa;
        if (villa > 0) this.appendSummaryRow(`${stayText} Rate (x${nights} day${nights === 1 ? '' : 's'})`, villa);
        
        // Extra pax rate from villa option data attribute
        const villaCap = parseInt(villaOpt?.dataset.baseCap) || 4;
        const villaExtraPax = parseFloat(villaOpt?.dataset.extraPax) || 1000;

        const extraFee = this.calcExtraPax(this.getEl('villa-guests'), villaCap, villaExtraPax, this.getEl('villa-extra-fee'), this.getEl('sum-vl-guests'));
        const feeSummary = this.getEl('sum-vl-fee');
        if (feeSummary) feeSummary.textContent = villaSelect?.value ? this.formatCurrency(extraFee) : '—';
        if (extraFee > 0) { 
            const totalExtra = extraFee * nights; 
            this.state.summary.total += totalExtra; 
            this.appendSummaryRow('Extra Pax Fee', totalExtra); 
        }
    }

    getTabContextData() {
        const context = { roomType: '', roomName: '', venueId: null, roomGroupId: null, baseAmt: 0, guests: 0, activeRadioGroup: 'payment-scheme' };

        if (this.state.activeTabId === 'hotel-rooms') {
            const nameSelect = this.getEl('hotel-room-name');
            const opt = nameSelect?.options[nameSelect?.selectedIndex];
            context.venueId  = opt?.dataset.venueId || null;
            context.roomGroupId = opt?.dataset.roomGroupId || null;
            context.roomType = opt?.dataset.type;
            context.roomName = opt?.dataset.name;
            context.baseAmt  = opt?.value;
            context.guests   = this.getEl('hotel-guests')?.value;
            context.activeRadioGroup = 'hotel-payment';

        } else if (this.state.activeTabId === 'event-hall') {
            const opt = this.getEl('event-venue')?.options[this.getEl('event-venue')?.selectedIndex];
            context.roomType = 'Event Hall';
            context.roomName = opt?.text.split('(')[0].trim();
            context.venueId  = opt?.dataset.id || null;
            context.baseAmt  = opt?.value;
            context.guests   = this.getEl('event-guests')?.value;
            context.activeRadioGroup = 'payment-scheme';

        } else if (this.state.activeTabId === 'resort-villa') {
            const opt = this.getEl('villa-type')?.options[this.getEl('villa-type')?.selectedIndex];
            context.roomType = 'Resort Villa';
            context.roomName = opt?.text.split('(')[0].trim();
            const stayType = document.querySelector('input[name="villa-stay"]:checked')?.value;
            context.baseAmt  = stayType === 'Overnight' ? opt?.dataset.overnight : opt?.value;
            context.guests   = this.getEl('villa-guests')?.value;
            context.activeRadioGroup = 'villa-payment';
        }
        return context;
    }

    // =========================================================================
    // Submit a booking or Event Hall inquiry.
    // =========================================================================
    async submitOnlineBooking() {
        if (!this.state.activeCalendar?.startDate) {
            showAlert("Notice", "Please select dates on the calendar and confirm them first!");
            return;
        }
        if (this.auth.isStaff) {
            showAlert("Customer account required", "Staff and administrators can browse availability, but only a customer account can submit a booking or inquiry.", "info");
            return;
        }
        if (!this.auth.isCustomer) {
            this.saveDraft();
            window.location.href = 'auth.php?destination=booking_resume';
            return;
        }
        if (this.state.activeTabId !== 'event-hall' && !this.state.isDatesLocked) {
            showAlert("Notice", "Please select dates on the calendar and confirm them first!");
            return;
        }
        const termsCheck = this.getEl('terms-check');
        if (!termsCheck?.checked) {
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
        
        if (!context.roomName && !context.venueId) { showAlert("Notice", "Please ensure a valid room/venue is selected."); return; }
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
        formData.append("room_name", context.roomName || '');
        if (context.roomGroupId) formData.append('room_group_id', context.roomGroupId);

        // Hotel rooms: pass venue_id directly; others: use room_type + room_name via session lock
        if (context.venueId) formData.append("venue_id", context.venueId);

        formData.append("start_date", this.formatSafeDate(this.state.activeCalendar.startDate));
        formData.append("end_date", this.state.activeCalendar.endDate ? this.formatSafeDate(this.state.activeCalendar.endDate) : this.formatSafeDate(this.state.activeCalendar.startDate));
        formData.append("guests", context.guests || 0);
        formData.append("base_amount", context.baseAmt || 0);
        formData.append("total_amount", this.state.summary.total);
        formData.append("payment_scheme", schemeEnum);
        formData.append("policy_consent", termsCheck.value === '1' ? "1" : "0");
        formData.append("policy_version", "terms-v3-manual-payment");
        
        formData.append("contact_phone", phoneInput.value.trim());
        formData.append("save_contact_default", this.getEl('save-contact-default')?.checked ? '1' : '0');
        const notesInput = document.getElementById("booking-notes");
        formData.append("custom_notes", notesInput ? notesInput.value.trim() : "");

        if (context.roomType === 'Event Hall') {
            const evTypeTxt = document.getElementById('sum-ev-type')?.innerText || '';
            const evStyleSelect = document.getElementById('event-style');
            const evStyleTxt = evStyleSelect ? evStyleSelect.options[evStyleSelect.selectedIndex].text : '';
            formData.append("event_type", evTypeTxt);
            formData.append("event_style_key", evStyleSelect?.value || '');
            formData.append("event_style", evStyleTxt.split('-')[0].trim()); 
        }

        if (context.roomType === 'Resort Villa') {
            const stayText = document.getElementById('sum-vl-stay')?.innerText === 'Overnight' ? 'Overnight' : 'Day Time Stay';
            formData.append("stay_type", stayText);
        }

        // Capture Event Hall-only line items. Hidden controls from another tab
        // must never affect a Hotel or Villa booking.
        let customLineItems = [];

        // 1. Catering
        const checkCatering = context.roomType === 'Event Hall' ? document.getElementById('check-catering') : null;
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

        // 2. A/V Setup
        const checkAv = context.roomType === 'Event Hall' ? document.getElementById('check-av') : null;
        if (checkAv && checkAv.checked) {
            const avPrice = parseFloat(checkAv.value) || 5000;
            customLineItems.push({ name: `Premium A/V Setup`, amount: avPrice });
        }

        formData.append('custom_line_items', JSON.stringify(customLineItems));

        // 3. Hotel Room Groups (real inventory — server allocates specific rooms)
        const roomsEnabled = context.roomType === 'Event Hall' && this.getEl('check-rooms')?.checked === true;
        let roomGroups = [];
        if (context.roomType === 'Event Hall' && roomsEnabled) document.querySelectorAll('.selected-room-row').forEach(row => {
            const building  = row.dataset.building;
            const roomType  = row.dataset.roomType;
            const roomGroupId = Number.parseInt(row.dataset.roomGroupId, 10) || null;
            const qty       = parseInt(row.querySelector('.sel-room-qty')?.value) || 0;
            if (building && roomType && qty > 0) {
                roomGroups.push({ room_group_id: roomGroupId, building_name: building, room_type: roomType, quantity: qty });
            }
        });
        if (roomGroups.length > 0) {
            const stay = this.getAddonStayRange();
            const rangeKey = stay ? `${this.formatSafeDate(stay.start)}|${this.formatSafeDate(stay.end)}` : '';
            if (!stay || this.state.addonAvailabilityPending || !this.state.addonAvailabilityReady || this.state.addonAvailabilityRangeKey !== rangeKey) {
                showAlert('Notice', 'Please select a hotel check-in and check-out date for the room add-on.');
                return;
            }
            formData.append('room_groups', JSON.stringify(roomGroups));
            formData.append('room_start_date', this.formatSafeDate(stay.start));
            formData.append('room_end_date', this.formatSafeDate(stay.end));
        }

        let bookingSaved = false;
        try {
            btn.innerText = "PROCESSING...";
            btn.disabled = true;

            const res = await fetch('actions/bookings/submit_online.php', { 
                method: 'POST', 
                headers: { "X-CSRF-Token": this.csrfToken }, 
                body: formData 
            });
            if (res.status === 401) {
                showAlert("Session Expired", "Your session has expired. Please sign in again.", "error", true);
                btn.innerText = (this.state.activeTabId === 'event-hall') ? "SUBMIT EVENT INQUIRY" : "SUBMIT BOOKING";
                btn.disabled = false;
                return;
            }
            const data = await res.text();
            const response = data.split('|');
            
            if (response[0] === 'Success') {
                bookingSaved = true;
                this.clearDraft();
                btn.innerText = context.roomType === 'Event Hall' ? "INQUIRY SENT" : "BOOKING RESERVED";
                if (context.roomType === 'Event Hall') {
                    showAlert("Inquiry submitted", "Your Event Hall inquiry is on its way. We will send the finalized quotation to your dashboard.", 'success');
                } else {
                    const bookingId = response[2] || '';
                    if (!this.manualPayment || !/^[1-9]\d*$/.test(bookingId)) {
                        showAlert("Booking reserved", "Your booking was saved, but payment details could not be opened. Go to My Bookings and choose Submit payment to continue.", 'error');
                    } else {
                        this.manualPayment.openBooking(bookingId, btn);
                    }
                }
            } else {
                throw new Error(response[1] || 'Booking could not be submitted. Please try again.');
            }
        } catch (error) {
            showAlert("Notice", "Error: " + error.message);
            if (!bookingSaved) {
                btn.innerText = (this.state.activeTabId === 'event-hall') ? "SUBMIT EVENT INQUIRY" : "SUBMIT BOOKING";
                btn.disabled = false;
            } else {
                btn.innerText = context.roomType === 'Event Hall' ? "INQUIRY SENT" : "BOOKING RESERVED";
            }
        }
    }
}

document.addEventListener("DOMContentLoaded", () => {
    window.BookingSystem = new BookingController();
});
