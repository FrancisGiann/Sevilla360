/* Behavioral regression coverage for post-auth booking restoration. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/booking.js'), 'utf8');
const classStart = source.indexOf('class BookingController {');
const bootstrapStart = source.indexOf('\ndocument.addEventListener("DOMContentLoaded"', classStart);
assert.ok(classStart >= 0 && bootstrapStart > classStart, 'BookingController class must be present');
assert.match(source, /window\.addEventListener\('pageshow', event => this\.handleBookingPageShow\(event\)\);/,
    'the pageshow lifecycle event must use the guarded refresh handler');

const document = {
    checkedVillaStay: null,
    villaStayOption: null,
    querySelector(selector) {
        if (selector.startsWith('.tab-btn[data-tab=')) return { dataset: { tab: selector.match(/data-tab="([^"]+)/)?.[1] || '' } };
        if (selector === 'input[name="villa-stay"]:checked') return this.checkedVillaStay;
        if (selector.startsWith('input[name="villa-stay"][value=')) return this.villaStayOption;
        return null;
    },
    querySelectorAll() { return []; }
};
const context = {
    document,
    window: {},
    CSS: { escape: value => String(value) },
    Event,
    FormData,
    Date,
    Number,
    Array,
    String,
    Boolean,
    Math,
    JSON,
    setInterval,
    clearInterval,
    console: { error() {} },
    showAlert() {},
    fetch: async () => { throw new Error('Unexpected request'); }
};
vm.runInNewContext(`${source.slice(classStart, bootstrapStart)}\nthis.BookingController = BookingController;`, context);
const BookingController = context.BookingController;

function control(value = '') {
    return {
        value,
        get valueAsNumber() { return Number(this.value); },
        checked: false,
        disabled: false,
        options: [],
        selectedIndex: 0,
        dataset: {},
        dispatchEvent() {},
        classList: { add() {}, remove() {} },
        style: {}
    };
}

function makeController({ category, lockResult = 'success', complete = true, eventGuestCount = 100 }) {
    const isHotel = category === 'Hotel Room';
    const isVilla = category === 'Resort Villa';
    const activeTabId = isHotel ? 'hotel-rooms' : (isVilla ? 'resort-villa' : 'event-hall');
    const selectionKey = 'restored-selection';
    const venueOption = { dataset: { id: '17', name: 'Grand Hall', theater: '200' }, textContent: 'Grand Hall' };
    const villaOption = { dataset: { id: '18', name: 'Garden Villa' }, textContent: 'Garden Villa' };
    const villaStayOption = { value: 'Overnight', checked: false, dispatchEvent() {} };
    const roomOption = { dataset: { id: '25', venueId: '25', roomGroupId: '8', name: 'North Wing', type: 'Deluxe' } };
    const elements = {
        'event-venue': control(),
        'event-guests': control(String(eventGuestCount)),
        'event-style': control('theater'),
        'event-type-others': control(),
        'event-others-radio': control(),
        'villa-type': control(),
        'hotel-room-type': control('Deluxe'),
        'hotel-room-name': control(),
        'hotel-guests': control('2'),
        'villa-guests': control('2'),
        'timer-box': control(),
        'btn-proceed': control()
    };
    elements['event-venue'].options = [venueOption];
    elements['event-venue'].selectedOptions = [venueOption];
    elements['villa-type'].options = [villaOption];
    elements['villa-type'].selectedOptions = [villaOption];
    elements['hotel-room-name'].options = [roomOption];
    elements['hotel-room-name'].disabled = false;
    document.checkedVillaStay = isVilla ? villaStayOption : null;
    document.villaStayOption = isVilla ? villaStayOption : null;
    const calendar = {
        startDate: null,
        endDate: null,
        container: {},
        setSelection(start, end) { this.startDate = new Date(`${start}T00:00:00`); this.endDate = new Date(`${end}T00:00:00`); },
        async fetchBookedDates() { this.onFetch?.(); return true; },
        isDateUnavailable() { return false; },
        hasInvalidDaysBetween() { return false; },
        updateDateDisplay() {},
        clearSelectedRange() { this.startDate = null; this.endDate = null; }
    };
    const draft = {
        version: 1,
        createdAt: Date.now(),
        activeTabId,
        step: 4,
        category,
        venueId: '17',
        roomGroupId: '8',
        venueName: isHotel ? 'North Wing' : 'Grand Hall',
        roomType: isHotel ? 'Deluxe' : '',
        buildingName: isHotel ? 'North Wing' : '',
        startDate: complete ? '2099-07-10' : '',
        endDate: complete ? (isHotel || isVilla ? '2099-07-12' : '2099-07-10') : '',
        confirmedSelectionKey: selectionKey,
        addonStartDate: '',
        addonEndDate: '',
        stayType: isVilla ? 'Overnight' : '',
        guests: isHotel ? '2' : String(eventGuestCount),
        paymentChoice: isHotel ? '50% Downpayment' : '',
        eventType: 'Plain Hall',
        eventTypeOther: '',
        eventStyle: 'theater',
        addons: { catering: false, av: false, rooms: false, roomGroups: [] }
    };
    const requests = [];
    context.fetch = async url => {
        requests.push(String(url));
        if (String(url).includes('fetch_dates.php')) {
            return { ok: true, json: async () => ({ success: true, booked_dates: [] }) };
        }
        if (String(url).includes('lock_dates.php')) {
            if (lockResult === 'success') {
                return { ok: true, status: 200, text: async () => `Success|held|${Math.floor(Date.now() / 1000) + 900}` };
            }
            return { ok: false, status: 409, text: async () => 'Error|Dates unavailable' };
        }
        throw new Error(`Unexpected request: ${url}`);
    };

    const controller = Object.create(BookingController.prototype);
    controller.auth = { resume: true, isCustomer: true, isStaff: false };
    controller.state = {
        activeTabId,
        currentStep: 1,
        activeCalendar: null,
        calendars: { event: calendar, hotel: calendar, villa: calendar },
        confirmedSelectionKey: '',
        isDatesLocked: false,
        lockExpiresAt: null,
        timerInterval: null,
        addonConfirmedRange: null,
        addonAvailabilityPending: false,
        addonAvailabilityReady: false,
        addonAvailabilityRangeKey: ''
    };
    controller.csrfToken = 'csrf';
    controller.isRestoringDraft = false;
    controller.getDraft = () => ({ ...draft });
    controller.executeTabVisualSwitch = (_tab, tabId) => { controller.state.activeTabId = tabId; };
    controller.getEl = id => elements[id] || null;
    controller.getTabContextData = () => isHotel
        ? { roomType: 'Deluxe', roomName: 'North Wing', venueId: '25', roomGroupId: '8', guests: '2', activeRadioGroup: 'hotel-payment' }
        : (isVilla
            ? { roomType: 'Resort Villa', roomName: 'Garden Villa', venueId: '18', roomGroupId: null, guests: '2', activeRadioGroup: 'villa-payment' }
            : { roomType: 'Event Hall', roomName: 'Grand Hall', venueId: '17', roomGroupId: null, guests: '100', activeRadioGroup: 'payment-scheme' });
    controller.buildSelectionKey = () => selectionKey;
    controller.restoreAddonSelections = async () => {};
    controller.pageShowRefreshes = 0;
    controller.syncHotelRoomSelection = () => { controller.pageShowRefreshes++; };
    controller.syncVenueSelectionDisplays = () => {};
    controller.updateVillaStaySelection = () => {};
    controller.calculateSummary = () => {};
    controller.updateBookingStepUI = () => {};
    controller.clearBookingStepError = () => {};
    controller.saveDraft = () => {};
    controller.requestDateConfirmation = () => { throw new Error('Unexpected date confirmation prompt'); };
    controller.startTimer = () => {};
    controller.updateHoldTimerVisibility = () => {};
    controller.getActiveBookingCalendar = () => calendar;
    controller.draft = draft;
    controller.requests = requests;
    calendar.onFetch = () => controller.handleBookingPageShow({ persisted: false });
    return controller;
}

async function restore({ category, lockResult, complete = true, eventGuestCount = 100 }) {
    const controller = makeController({ category, lockResult, complete, eventGuestCount });
    await BookingController.prototype.restoreDraftIfRequested.call(controller);
    return controller;
}

(async () => {
    const event = await restore({ category: 'Event Hall' });
    assert.equal(event.state.currentStep, 4, 'valid event drafts return to review');
    assert.equal(event.requests.some(url => url.includes('lock_dates.php')), false, 'event inquiry does not require a hold');
    const invalidEvent = await restore({ category: 'Event Hall', eventGuestCount: 250 });
    assert.equal(invalidEvent.state.currentStep, 3, 'event details exceeding capacity return to options');

    const hotel = await restore({ category: 'Hotel Room', lockResult: 'success' });
    assert.equal(hotel.state.currentStep, 4, 'hotel drafts return to review after a successful authoritative hold');
    assert.equal(hotel.state.isDatesLocked, true);
    assert.equal(hotel.pageShowRefreshes, 0, 'normal pageshow during restoration cannot start a competing availability refresh');

    const villa = await restore({ category: 'Resort Villa', lockResult: 'success' });
    assert.equal(villa.state.currentStep, 4, 'villa drafts return to review after a successful authoritative hold');
    assert.equal(villa.state.isDatesLocked, true);

    const failedLock = await restore({ category: 'Hotel Room', lockResult: 'failure' });
    assert.equal(failedLock.state.currentStep, 2, 'failed hotel hold returns to date selection');
    assert.equal(failedLock.state.isDatesLocked, false);
    assert.equal(failedLock.state.calendars.hotel.startDate, null);

    const incomplete = await restore({ category: 'Event Hall', complete: false });
    assert.equal(incomplete.state.currentStep, 2, 'incomplete draft cannot skip required dates');

    let pageShowRefreshes = 0;
    const pageShowController = Object.create(BookingController.prototype);
    pageShowController.isRestoringDraft = false;
    pageShowController.syncHotelRoomSelection = () => { pageShowRefreshes++; };
    pageShowController.syncVenueSelectionDisplays = () => {};
    pageShowController.calculateSummary = () => {};
    pageShowController.handleBookingPageShow({ persisted: false });
    assert.equal(pageShowRefreshes, 0, 'ordinary pageshow must not launch a competing availability refresh');
    pageShowController.handleBookingPageShow({ persisted: true });
    assert.equal(pageShowRefreshes, 1, 'BFCache pageshow still refreshes selections');
    pageShowController.isRestoringDraft = true;
    pageShowController.handleBookingPageShow({ persisted: true });
    assert.equal(pageShowRefreshes, 1, 'BFCache refresh waits until draft restoration finishes');

    console.log('PASS|valid event resume restores review without a hold');
    console.log('PASS|invalid event details remain on options for correction');
    console.log('PASS|hotel resume restores review only after successful hold');
    console.log('PASS|villa resume restores review only after successful hold');
    console.log('PASS|failed hotel hold returns to date selection');
    console.log('PASS|incomplete draft remains behind date validation');
    console.log('PASS|pageshow only refreshes after BFCache return and outside restoration');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
