const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/booking.js'), 'utf8');

function fixture(locked = false) {
    const document = { body: { style: { overflow: 'clip' } }, activeElement: null, addEventListener() {} };
    const handlers = {};
    const make = (id) => {
        const classes = new Set();
        return { id, isConnected: true, attributes: {}, listeners: {},
            classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) },
            setAttribute(name, value) { this.attributes[name] = value; },
            getAttribute(name) { return this.attributes[name]; },
            focus() { document.activeElement = this; },
            addEventListener(name, callback) { this.listeners[name] = callback; },
            click() { this.listeners.click(); }
        };
    };
    const modal = make('switch-tab-modal');
    modal.classList.add('modal-overlay');
    const cancel = make('btn-cancel-switch');
    const confirm = make('btn-confirm-switch');
    modal.querySelectorAll = () => [cancel, confirm];
    const elements = { 'switch-tab-modal': modal, 'btn-cancel-switch': cancel, 'btn-confirm-switch': confirm };
    document.getElementById = id => elements[id];
    const context = { document, window: { addEventListener(name, callback) { handlers[name] = callback; } } };
    vm.createContext(context);
    vm.runInContext(source + '\nthis.Controller = BookingController;', context);
    const controller = Object.create(context.Controller.prototype);
    const selection = { startDate: new Date('2026-10-22T00:00:00+08:00') };
    controller.state = { isDatesLocked: locked, activeCalendar: selection, pendingDateConfirmation: null };
    controller.replaceElement = id => { const e = elements[id]; e.listeners = {}; return e; };
    const calls = { unlock: 0, reset: 0, switch: 0 };
    controller.unlockDatesAPI = () => { calls.unlock++; };
    controller.stopTimerAndReset = () => { calls.reset++; };
    controller.executeTabVisualSwitch = () => { calls.switch++; };
    controller.bindModalsAndSubmission();
    const trigger = make('hotel-tab');
    trigger.setAttribute('data-tab', 'hotel-rooms');
    const key = (value, shiftKey = false) => {
        const event = { key: value, shiftKey, prevented: false, preventDefault() { this.prevented = true; } };
        handlers.keydown(event);
        return event;
    };
    return { controller, document, handlers, modal, cancel, confirm, trigger, calls, selection, key };
}

test('opening focuses Cancel; cancel restores focus and scroll without changing the selection or locks', () => {
    const f = fixture();
    f.controller.handleTabSwitch(f.trigger);
    assert.equal(f.document.activeElement, f.cancel);
    assert.equal(f.modal.attributes['aria-hidden'], 'false');
    assert.equal(f.document.body.style.overflow, 'hidden');
    f.cancel.click();
    assert.equal(f.document.activeElement, f.trigger);
    assert.equal(f.document.body.style.overflow, 'clip');
    assert.equal(f.modal.attributes['aria-hidden'], 'true');
    assert.equal(f.controller.state.activeCalendar, f.selection);
    assert.deepEqual(f.calls, { unlock: 0, reset: 0, switch: 0 });
});

test('Escape and backdrop are cancellation paths and never release a held date', () => {
    for (const close of ['escape', 'backdrop']) {
        const f = fixture(true);
        f.controller.handleTabSwitch(f.trigger);
        if (close === 'escape') assert.equal(f.key('Escape').prevented, true);
        else f.handlers.click({ target: f.modal });
        assert.equal(f.modal.classList.contains('active'), false);
        assert.equal(f.document.activeElement, f.trigger);
        assert.equal(f.controller.state.isDatesLocked, true);
        assert.deepEqual(f.calls, { unlock: 0, reset: 0, switch: 0 });
    }
});

test('Tab and Shift+Tab stay in the two dialog actions, including stray background focus', () => {
    const f = fixture();
    f.controller.handleTabSwitch(f.trigger);
    assert.equal(f.key('Tab', true).prevented, true);
    assert.equal(f.document.activeElement, f.confirm);
    assert.equal(f.key('Tab').prevented, true);
    assert.equal(f.document.activeElement, f.cancel);
    f.document.activeElement = f.trigger;
    assert.equal(f.key('Tab').prevented, true);
    assert.equal(f.document.activeElement, f.cancel);
    assert.equal(f.key('Enter').prevented, false);
});

test('only explicit confirmation invokes the existing release/reset/switch path once', () => {
    for (const locked of [false, true]) {
        const f = fixture(locked);
        f.controller.handleTabSwitch(f.trigger);
        f.confirm.click();
        assert.deepEqual(f.calls, { unlock: Number(locked), reset: 1, switch: 1 });
        assert.equal(f.document.activeElement, f.trigger);
        assert.equal(f.document.body.style.overflow, 'clip');
    }
});

test('repeated open requests preserve original scroll state; closed dialogs do not consume Escape', () => {
    const f = fixture();
    f.controller.handleTabSwitch(f.trigger);
    f.controller.handleTabSwitch(f.trigger);
    f.cancel.click();
    assert.equal(f.document.body.style.overflow, 'clip');
    assert.equal(f.key('Escape').prevented, false);
});
