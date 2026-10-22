/* Behavioral coverage for reopening payment on an already-saved booking. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/booking.js'), 'utf8');
const classStart = source.indexOf('class BookingController {');
const bootstrapStart = source.indexOf('\ndocument.addEventListener("DOMContentLoaded"', classStart);
assert.ok(classStart >= 0 && bootstrapStart > classStart, 'BookingController class must be present');

const alerts = [];
const context = {
    document: {},
    window: {},
    console: { error() {} },
    showAlert: (...args) => alerts.push(args),
    clearInterval() {},
    Date,
    Number,
    String,
    Boolean,
    Math,
    Array,
    JSON
};
vm.runInNewContext(`${source.slice(classStart, bootstrapStart)}\nthis.BookingController = BookingController;`, context);
const BookingController = context.BookingController;

const button = { textContent: 'BOOKING RESERVED', disabled: true, style: {} };
const elements = {
    'btn-proceed': button,
    'timer-box': { classList: { remove() {} } },
    'timer-text': { style: {} },
    'countdown-wrapper': { style: {} },
    'terms-check': { checked: true },
    'check-catering': { checked: true },
    'check-av': { checked: true },
    'check-rooms': { checked: true },
    'catering-options': { classList: { add() {} } },
    'rooms-options': { classList: { add() {} } },
    'selected-room-groups': { innerHTML: 'old selection' }
};
let selectionCleared = false;
let summaryRefreshes = 0;
let openedBooking = null;
let paymentRequests = 0;

const controller = Object.create(BookingController.prototype);
controller.state = {
    activeTabId: 'hotel-rooms',
    savedPaymentBookingId: null,
    timerInterval: null,
    timeLimit: 900,
    isDatesLocked: true,
    lockExpiresAt: Math.floor(Date.now() / 1000) + 900,
    confirmedSelectionKey: 'temporary-hold',
    activeCalendar: { clearSelection() { selectionCleared = true; } },
    addonConfirmedRange: null,
    addonAvailabilityToken: 0,
    addonAvailabilityPending: false,
    addonAvailabilityReady: false,
    addonAvailabilityRangeKey: ''
};
controller.getEl = id => elements[id] || null;
controller.resetAddonStayDates = () => {};
controller.calculateSummary = () => { summaryRefreshes++; };
controller.manualPayment = {
    openBooking(id, invoker) { openedBooking = { id, invoker }; }
};
controller.auth = { isCustomer: true };
context.fetch = async () => { paymentRequests++; throw new Error('Reopen must not submit another booking.'); };

assert.equal(controller.rememberSavedPaymentBooking('481'), true, 'valid saved booking ID is retained');
assert.equal(controller.state.savedPaymentBookingId, '481');
assert.equal(controller.state.isDatesLocked, false, 'consumed temporary hold is cleared locally');
assert.equal(controller.state.lockExpiresAt, null, 'saved booking does not retain an expiring hold timer');
assert.equal(selectionCleared, true, 'temporary date selection is cleared after reservation');
assert.equal(summaryRefreshes, 1);
assert.equal(button.textContent, 'SUBMIT PAYMENT PROOF', 'saved booking remains actionable');
assert.equal(button.disabled, false);

controller.state.activeTabId = 'event-hall';
controller.updateBookingSubmitLabel();
assert.equal(button.textContent, 'SUBMIT PAYMENT PROOF', 'tab updates cannot replace the saved-payment action');
assert.equal(button.disabled, false, 'tab updates cannot disable the saved-payment action');

(async () => {
    await controller.submitOnlineBooking();
    assert.deepEqual(openedBooking, { id: '481', invoker: button }, 'button opens payment for the existing booking');
    assert.equal(paymentRequests, 0, 'reopening never posts another booking');
    assert.equal(alerts.length, 0);

    const invalid = Object.create(BookingController.prototype);
    invalid.state = { savedPaymentBookingId: null };
    invalid.getEl = () => button;
    assert.equal(invalid.rememberSavedPaymentBooking('0'), false, 'invalid IDs cannot become payment actions');
    assert.equal(invalid.state.savedPaymentBookingId, null);

    const noDialog = Object.create(BookingController.prototype);
    noDialog.state = { savedPaymentBookingId: '481' };
    noDialog.manualPayment = null;
    noDialog.getEl = () => button;
    await noDialog.submitOnlineBooking();
    assert.equal(paymentRequests, 0, 'missing dialog fallback still cannot create a second reservation');
    assert.equal(alerts.length, 1, 'missing dialog directs the customer to My Bookings');

    console.log('PASS|saved booking ID re-enables the payment action and clears consumed hold state');
    console.log('PASS|button reopens payment for the existing booking without another booking request');
    console.log('PASS|invalid saved IDs are rejected');
    console.log('PASS|missing payment dialog fallback does not submit another booking');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
