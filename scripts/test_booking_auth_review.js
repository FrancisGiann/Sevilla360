/* Auth interruption tests using real selection keys and price-valued select semantics.
 * All requests/storage/controls are isolated synthetic fixtures. No live I/O. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { BookingController, makeController, context, document, control } = require('./test_booking_auth_resume');
const root = path.resolve(__dirname, '..');
const markup = fs.readFileSync(path.join(root, 'booking.php'), 'utf8');
const originalQuery = document.querySelector.bind(document);
let eventTypes = [];
let contactChoices = [];
document.querySelectorAll = selector => {
    if (selector === 'input[name="event-type"]') return eventTypes;
    if (selector === 'input[name="contact-phone-choice"]') return contactChoices;
    if (selector === 'input[type="radio"]') return eventTypes;
    return [];
};
document.querySelector = selector => {
    if (selector === 'input[name="event-type"]:checked') return eventTypes.find(radio => radio.checked) || null;
    return originalQuery(selector);
};
function syntheticSelect(options, selectedIndex = 0) {
    return {
        options, selectedIndex, dispatchEvent() {}, disabled: false,
        get selectedOptions() { return this.selectedIndex < 0 ? [] : [this.options[this.selectedIndex]]; },
        get value() { return this.options[this.selectedIndex]?.value || ''; },
        set value(value) { this.selectedIndex = this.options.findIndex(option => String(option.value) === String(value)); }
    };
}
function actual(category = 'Event Hall') {
    const c = makeController({ category, eventGuestCount: 50 });
    const isVilla = category === 'Resort Villa';
    const id = isVilla ? '18' : '17';
    const name = isVilla ? 'Synthetic Villa' : 'Infinity Hall';
    const target = isVilla ? 'villa-type' : 'event-venue';
    // Equal prices and equal names must never win against the saved ID.
    const select = syntheticSelect([
        { value: '', text: 'Choose a venue', disabled: true, dataset: {} },
        { value: '15000', text: name, dataset: { id: '16', name, theater: '200' } },
        { value: '15000', text: name, dataset: { id, name, theater: '200' } }
    ], 2);
    const extra = new Map([
        [target, select], ['contact-phone', control('')], ['booking-notes', control('')],
        ['terms-check', control('1')], ['save-contact-default', control()],
        ['save-contact-choice', control()], ['booking-account-fields', control()],
        ['booking-auth-prompt', control()], ['booking-staff-prompt', control()], ['booking-retry-availability', control()]
    ]);
    extra.get('contact-phone').defaultValue = '09123456789';
    const previousGet = c.getEl;
    c.getEl = key => extra.get(key) || previousGet(key);
    c.auth.userId = 42;
    c.draftKey = 'synthetic.booking-draft'; c.authPendingKey = 'synthetic.booking-auth-pending';
    c.getTabContextData = BookingController.prototype.getTabContextData;
    c.buildSelectionKey = BookingController.prototype.buildSelectionKey;
    c.draft.venueId = id; c.draft.venueName = name;
    c.draft.guests = '50'; c.draft.eventType = 'Plain Hall';
    c.draft.notes = 'Synthetic notes to retain'; c.draft.contactPhone = '09999999999';
    c.draft.reviewOwnerId = '42';
    eventTypes = [{ dataset: { text: 'Plain Hall' }, checked: false, dispatchEvent() {} }];
    contactChoices = [{ value: 'saved', checked: true }, { value: 'alternate', checked: false }];
    const delegateFetch = context.fetch;
    c.holdBodies = [];
    context.fetch = async (url, options) => {
        if (url.includes('lock_dates.php')) c.holdBodies.push(options.body);
        return delegateFetch(url, options);
    };
    if (!isVilla) c.draft.endDate = '2099-07-11';
    const calendar = c.state.calendars[isVilla ? 'villa' : 'event'];
    calendar.setSelection(c.draft.startDate, c.draft.endDate);
    c.state.activeCalendar = calendar;
    c.draft.confirmedSelectionKey = c.buildSelectionKey(calendar);
    c.state.activeCalendar = null; select.selectedIndex = 0;
    c.confirmations = 0; c.requestDateConfirmation = () => { c.confirmations++; };
    c.authRedirectPending = false;
    c.storage = new Map();
    context.window.sessionStorage = {
        getItem: key => c.storage.get(key) || null,
        setItem: (key, value) => c.storage.set(key, value),
        removeItem: key => c.storage.delete(key)
    };
    context.window.location = { href: '' };
    c.saveCount = 0;
    c.saveDraft = () => { c.saveCount++; };
    return { c, select, calendar, extra };
}
async function restore(f) { await f.c.restoreDraftIfRequested(); return f; }

(async () => {
    for (const category of ['Event Hall', 'Resort Villa']) {
        let f = actual(category);
        f.c.getEl('terms-check').checked = true; f.c.getEl('save-contact-default').checked = true;
        await restore(f);
        assert.equal(f.select.selectedIndex, 2, category + ' selects exact ID despite equal price/name');
        assert.equal(f.c.state.currentStep, 4); assert.equal(f.c.confirmations, 0);
        assert.equal(f.c.getEl('booking-notes').value, 'Synthetic notes to retain');
        assert.equal(f.c.getEl('contact-phone').value, '09999999999');
        assert.equal(f.c.getEl('terms-check').checked, false);
        assert.equal(f.c.getEl('save-contact-default').checked, false);
        assert.equal(f.c.getEl(category === 'Event Hall' ? 'event-guests' : 'villa-guests').value, '50');
        assert.equal(eventTypes[0].checked, true);
        assert.ok(f.c.requests.every(url => !url.includes('submit_online')));
        assert.equal(f.c.requests.filter(url => url.includes('lock_dates')).length, category === 'Resort Villa' ? 1 : 0);
        assert.equal(f.c.formatSafeDate(f.calendar.startDate), f.c.draft.startDate);
        assert.equal(f.c.formatSafeDate(f.calendar.endDate), f.c.draft.endDate);
        if (category === 'Resort Villa') {
            assert.equal(f.c.getTabContextData().venueId, '18');
            assert.equal(f.c.holdBodies[0].get('venue_id'), '18', 'hold request is bound to the selected Villa ID');
        }
        console.log('PASS|' + category + ': duplicate price/name restores exact venue, dates, guests, type, notes and Review; no consent or submission');

        for (const badId of ['', '999']) {
            f = actual(category); f.c.draft.venueId = badId;
            await restore(f);
            assert.equal(f.select.selectedIndex, 0); assert.equal(f.c.state.currentStep, 1);
            assert.equal(f.c.requests.length, 0); assert.equal(f.c.confirmations, 0);
            assert.match(f.c.issues[0].message, /saved venue is no longer available/);
            assert.equal(f.c.getEl('booking-notes').value, 'Synthetic notes to retain');
        }
        console.log('PASS|' + category + ': missing/stale ID never falls back to equal name or price');
    }

    for (const category of ['Event Hall', 'Resort Villa']) for (const availability of ['unavailable', 'failed', 'stale', 'throws']) {
        const f = actual(category);
        if (availability === 'unavailable') f.calendar.isDateUnavailable = () => true;
        else f.calendar.fetchBookedDates = async () => {
            if (availability === 'throws') throw new Error('Synthetic network failure');
            return availability === 'failed' ? false : undefined;
        };
        await restore(f);
        assert.equal(f.c.state.currentStep, 2); assert.equal(f.c.state.confirmedSelectionKey, '');
        assert.equal(f.select.selectedIndex, 2); assert.equal(f.c.getEl('event-guests').value, '50');
        assert.equal(f.c.getEl('booking-notes').value, 'Synthetic notes to retain');
        assert.equal(f.c.requests.length, 0); assert.equal(f.c.confirmations, 0);
        assert.ok(f.c.issues.length);
        assert.equal(Boolean(f.calendar.startDate), availability !== 'unavailable');
    }
    const retry = actual();
    retry.c.state.activeCalendar = retry.calendar;
    retry.calendar.setSelection('2099-07-10', '2099-07-11');
    retry.select.selectedIndex = 2;
    retry.calendar.fetchBookedDates = async () => false;
    await retry.c.retryBookingAvailability();
    assert.equal(retry.c.confirmations, 0); assert.equal(retry.c.issues[0].retryAvailability, true);
    retry.calendar.fetchBookedDates = async () => true;
    await retry.c.retryBookingAvailability();
    assert.equal(retry.c.confirmations, 1); assert.equal(retry.c.requests.length, 0, 'retry never submits or creates a hold');
    retry.calendar.isDateUnavailable = () => true;
    await retry.c.retryBookingAvailability();
    assert.equal(retry.calendar.startDate, null); assert.match(retry.c.issues.at(-1).message, /no longer available/);
    console.log('PASS|availability retry remains explicit, recovers failure, rechecks dates and never creates a hold or submits');

    const capacity = actual(); capacity.c.draft.guests = '250';
    await restore(capacity);
    assert.equal(capacity.c.state.currentStep, 3); assert.match(capacity.c.issues[0].message, /up to 200 guests/);
    assert.equal(capacity.select.selectedIndex, 2); assert.ok(capacity.calendar.startDate);
    console.log('PASS|unavailable/failed/stale/thrown availability blocks Review; changed capacity explains correction and keeps valid choices');

    const concurrent = actual('Resort Villa');
    let finishAvailability;
    let availabilityChecks = 0;
    concurrent.calendar.fetchBookedDates = () => { availabilityChecks++; return new Promise(resolve => { finishAvailability = resolve; }); };
    const restoring = concurrent.c.restoreDraftIfRequested();
    await concurrent.c.restoreDraftIfRequested();
    assert.equal(availabilityChecks, 1); finishAvailability(true); await restoring;
    assert.equal(concurrent.c.requests.filter(url => url.includes('lock_dates')).length, 1);
    console.log('PASS|concurrent restoration performs one availability check and one authoritative Villa hold');

    const back = actual(); await restore(back);
    back.c.updateBookingStepUI = () => {};
    back.c.focusBookingElement = () => {};
    context.window.matchMedia = () => ({ matches: false });
    document.getElementById = () => null;
    back.c.navigateToBookingStep(3); back.c.navigateToBookingStep(4);
    assert.equal(back.c.state.currentStep, 4); assert.equal(back.select.selectedIndex, 2);
    assert.equal(back.c.getEl('booking-notes').value, 'Synthetic notes to retain');
    assert.equal(back.c.getEl('terms-check').checked, false);
    console.log('PASS|Review Back to Options and forward preserves venue, dates and review text without accepting terms');

    const otherOwner = actual(); otherOwner.c.draft.reviewOwnerId = '999';
    await restore(otherOwner);
    assert.equal(otherOwner.c.getEl('contact-phone').value, ''); assert.equal(otherOwner.c.getEl('booking-notes').value, '');
    console.log('PASS|review contact and notes do not restore into a different customer account');

    for (const role of ['guest', 'customer', 'staff']) {
        const f = actual();
        f.c.auth = { isCustomer: role === 'customer', isStaff: role === 'staff', resume: false };
        f.c.updateReviewAuthUI();
        assert.equal(f.c.getEl('booking-account-fields').hidden, role !== 'customer');
        assert.equal(f.c.getEl('booking-account-fields').disabled, role !== 'customer');
        assert.equal(f.c.getEl('booking-auth-prompt').hidden, role !== 'guest');
        assert.equal(f.c.getEl('booking-staff-prompt').hidden, role !== 'staff');
        f.c.state.activeCalendar = f.calendar; f.calendar.setSelection('2099-07-10', '2099-07-10');
        // All tests below must stop before the real submit path.
        await f.c.submitOnlineBooking();
        if (role === 'guest') {
            assert.equal(context.window.location.href, 'auth.php?destination=booking_resume');
            assert.equal(f.c.storage.get(f.c.authPendingKey), '1');
            assert.equal(f.c.authRedirectPending, true);
            await f.c.submitOnlineBooking(); assert.equal(f.c.saveCount, 1, 'repeat sign-in click saves/redirects only once');
            f.c.handleBookingPageShow({ persisted: true });
            assert.equal(f.c.authRedirectPending, false); assert.equal(f.c.getEl('btn-proceed').disabled, false);
        } else {
            assert.equal(context.window.location.href, ''); assert.equal(f.c.saveCount, 0);
        }
        assert.equal(f.c.requests.length, 0);
    }
    assert.match(markup, /id="booking-account-fields"[^>]*hidden disabled/);
    assert.match(markup, /Signing in does not submit it/);
    console.log('PASS|guest/customer/staff field visibility; guest auth-only action; repeat click/BFCache; staff and missing consent cannot submit');

    for (const category of ['Event Hall', 'Resort Villa']) {
        const f = actual(category);
        f.c.auth = { resume: false, isCustomer: false, isStaff: false };
        f.c.draft.reviewOwnerId = '';
        f.c.storage.set(f.c.authPendingKey, '1');
        await restore(f);
        assert.equal(f.c.state.currentStep, 4); assert.equal(f.c.confirmations, 0); assert.equal(f.c.requests.length, 0);
        assert.equal(f.c.storage.has(f.c.authPendingKey), false);
    }
    const noIntent = actual(); noIntent.c.auth.resume = false;
    await restore(noIntent); assert.equal(noIntent.select.selectedIndex, 0, 'ordinary page load cannot consume a draft');
    console.log('PASS|auth Back/cancel restores guest Review without a hold; ordinary loads do not consume drafts');

    const cancel = actual(); cancel.c.getEl('booking-notes').value = 'Synthetic pending notes';
    cancel.c.storage.set(cancel.c.draftKey, 'draft'); cancel.c.storage.set(cancel.c.authPendingKey, '1');
    cancel.c.stopTimerAndReset = () => { cancel.calendar.clearSelectedRange(); cancel.c.state.confirmedSelectionKey = ''; };
    cancel.c.setBookingStep = step => { cancel.c.state.currentStep = step; };
    await cancel.c.cancelBookingAttempt();
    assert.equal(cancel.c.state.currentStep, 1); assert.equal(cancel.c.storage.size, 0);
    assert.equal(cancel.c.getEl('booking-notes').value, ''); assert.equal(cancel.c.requests.length, 0);
    const blockedCancel = actual('Resort Villa'); blockedCancel.c.state.isDatesLocked = true;
    let finishUnlock; blockedCancel.c.unlockDatesAPI = () => new Promise(resolve => { finishUnlock = resolve; });
    let resets = 0; blockedCancel.c.stopTimerAndReset = () => { resets++; };
    const cancelling = blockedCancel.c.cancelBookingAttempt();
    await blockedCancel.c.cancelBookingAttempt(); finishUnlock(false); await cancelling;
    assert.equal(resets, 0, 'failed hold release keeps the current attempt');
    console.log('PASS|explicit Cancel clears draft and review data; repeated/failed hold release preserves attempt');

    const storage = actual(); storage.c.state.activeCalendar = storage.calendar;
    storage.calendar.setSelection('2099-07-10', '2099-07-11'); storage.c.state.currentStep = 4;
    storage.c.state.confirmedSelectionKey = storage.c.buildSelectionKey(storage.calendar);
    storage.c.getEl('booking-notes').value = 'Retained note'; storage.c.getEl('contact-phone').value = '09999999999';
    storage.c.getAddonStayRange = () => null;
    storage.c.getDraft = () => null;
    BookingController.prototype.saveDraft.call(storage.c);
    const draft = JSON.parse(storage.c.storage.get(storage.c.draftKey));
    assert.equal(draft.reviewOwnerId, '42'); assert.equal(draft.notes, 'Retained note'); assert.equal(draft.contactPhone, '09999999999');
    assert.equal(draft.step, 4); assert.ok(!('policy_consent' in draft)); assert.ok(!('saveContactDefault' in draft));
    console.log('PASS|tab draft stores owner-scoped review text, dates and step; consent/default-account changes are never persisted');
})().catch(error => { console.error(error); process.exitCode = 1; });
