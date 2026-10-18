#!/usr/bin/env node
// Run the shared dialog controller against keyboard/focus fixtures; no browser or DB.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const sourcePath = process.argv[2] || path.join(__dirname, '../assets/js/global_modals.js');
const document = { activeElement: null, addEventListener() {} };
const pending = [];
const context = vm.createContext({ window: {}, document, setTimeout: callback => pending.push(callback) });
vm.runInContext(fs.readFileSync(sourcePath, 'utf8'), context);
const control = id => ({ id, disabled: false, tabIndex: 0, isConnected: true,
    getClientRects: () => [{}], focus() { document.activeElement = this; }, click() { this.clicked = (this.clicked || 0) + 1; } });
const invoker = control('open'); const cancel = control('cancel'); const ok = control('ok');
const listeners = new Map();
const modal = { querySelectorAll: () => [cancel, ok], addEventListener: (type, callback) => listeners.set(type, callback),
    removeEventListener: (type, callback) => { if (listeners.get(type) === callback) listeners.delete(type); } };
document.activeElement = invoker;
const cleanup = context.manageGlobalModalFocus(modal, cancel, cancel);
assert.equal(document.activeElement, invoker, 'opening focus is deferred until visibility changes can settle');
pending.shift()();
assert.equal(document.activeElement, cancel, 'confirmation initially focuses the safe Cancel action');
const key = (key, shiftKey = false) => { const event = {key,shiftKey,preventDefault() {this.prevented=true;},stopPropagation() {this.stopped=true;}}; listeners.get('keydown')(event); return event; };
assert.equal(key('Tab', true).prevented, true); assert.equal(document.activeElement, ok, 'Shift+Tab wraps to the last action');
assert.equal(key('Tab').prevented, true); assert.equal(document.activeElement, cancel, 'Tab wraps to the first action');
assert.equal(key('Escape').prevented, true); assert.equal(cancel.clicked, 1, 'Escape invokes cancellation, never confirmation');
cleanup(); assert.equal(document.activeElement, invoker, 'dismissal restores the invoking control'); assert.equal(listeners.size, 0, 'dismissal removes keyboard handlers');
document.activeElement = invoker;
context.manageGlobalModalFocus(modal, ok, ok);
context.manageGlobalModalFocus(modal, cancel, cancel);
pending.shift()(); assert.equal(document.activeElement, invoker, 'superseded dialogs cannot steal focus from a later dialog');
pending.shift()(); assert.equal(document.activeElement, cancel);
console.log('PASS shared modal delayed focus, safe initial action, forward/reverse Tab loop, Escape cancellation, focus restoration, listener cleanup, and stale-focus guard');
