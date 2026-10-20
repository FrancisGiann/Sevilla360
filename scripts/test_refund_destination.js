'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const api = require('../assets/js/refund_destination.js');
let assertions = 0;
function equal(actual, expected) { assert.deepEqual(actual, expected); assertions++; }
function valid(method, value, allowCash = false) { return api.identifierError(method, value, allowCash) === ''; }

for (const method of ['GCash', 'Maya']) {
    for (const number of ['09123456789', '9123456789', '639123456789', '+639123456789', ' 0912 345-6789 ', '+63 912-345-6789']) equal(valid(method, number), true);
    for (const number of ['', '0912345678', '00123456789', '+6391234567890', '0912abc6789', '\n09123456789', '09123456789\t', '0912\x003456789', '\x7f09123456789', '\u00a009123456789', 9123456789, null, [], {}]) equal(valid(method, number), false);
}
for (const value of ['0', '000012345', 'AB-001 02', 'a'.repeat(64), ' a' + ' '.repeat(90) + 'b ']) equal(valid('Bank Transfer', value), true);
for (const value of ['', ' --- ', 'a'.repeat(65), '123/45', '１２３', '\n000123', '12\t34', '12\u00a034', null, 123, {}]) equal(valid('Bank Transfer', value), false);
for (const method of ['', 'cash', 'GCASH', 'Other', 'GCash\n', null, {}]) equal(valid(method, '09123456789'), false);
equal(valid('Cash', null), false);
equal(valid('Cash', null, true), true);

function field(value = '') {
    const listeners = {};
    return {value, hidden: false, disabled: false, required: false, validityMessage: '', textContent: '',
        addEventListener(name, listener) { listeners[name] = listener; },
        setCustomValidity(message) { this.validityMessage = message; },
        fire(name) { listeners[name](); }};
}
function fixture(methodValue = 'GCash', allowCash = false) {
    const fields = {method: field(methodValue), identifier: field('0012345'), identifierLabel: field(), identifierHelp: field(), bankField: field(), bank: field('Maya Bank, Inc.'), identifierField: field(), allowCash};
    return {...fields, controller: api.bind(fields)};
}
const f = fixture();
equal(f.identifier.value, '0012345'); // Setup preserves stored strings and leading zeros.
equal([f.bankField.hidden, f.bank.disabled, f.bank.required], [true, true, false]);
equal([f.identifierField.hidden, f.identifier.disabled, f.identifier.required], [false, false, true]);
equal([f.identifier.inputMode, f.identifier.placeholder, f.identifierLabel.textContent], ['tel', '09XX XXX XXXX', 'GCash registered mobile number']);
equal(f.controller.validate(), false);
f.identifier.value = '0912 345-6789'; f.identifier.fire('input');
equal(f.identifier.validityMessage, ''); equal(f.identifier.value, '0912 345-6789');
f.identifier.value = 'bad'; f.identifier.fire('change'); equal(f.identifier.validityMessage !== '', true);
f.bank.setCustomValidity('old error'); f.method.value = 'Bank Transfer'; f.method.fire('change');
equal([f.identifier.value, f.identifier.validityMessage, f.bank.validityMessage], ['', '', '']);
equal([f.bankField.hidden, f.bank.disabled, f.bank.required], [false, false, true]);
equal([f.identifier.inputMode, f.identifier.placeholder, f.identifierLabel.textContent], ['text', 'As shown by your bank', 'Bank account number']);
f.identifier.value = '000012'; equal(f.controller.validate(), true); equal(f.identifier.value, '000012');
f.controller.sync(); equal(f.identifier.value, '000012');
f.method.value = 'Maya'; f.method.fire('change');
equal([f.identifier.value, f.bank.value], ['', '']);
equal(f.identifierLabel.textContent, 'Maya Wallet registered mobile number');
equal(f.identifierHelp.textContent.includes('Maya Savings'), true);
const cash = fixture('Bank Transfer', true);
cash.method.value = 'Cash'; cash.method.fire('change');
equal([cash.identifierField.hidden, cash.identifier.disabled, cash.identifier.required], [true, true, false]);
equal([cash.bankField.hidden, cash.bank.disabled, cash.bank.required, cash.bank.value], [true, true, false, '']);
equal(cash.controller.validate(), true);
cash.method.value = 'GCash'; cash.method.fire('change');
equal([cash.identifierField.hidden, cash.identifier.disabled, cash.identifier.required], [false, false, true]);
const noCash = fixture('Cash'); equal(noCash.controller.validate(), false);
const minimal = {method: field('Maya'), identifier: field('09123456789'), identifierLabel: field(), bankField: field(), bank: field()};
equal(api.bind(minimal).validate(), true); // Optional wrapper/help are not required.
const browser = {};
vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/refund_destination.js'), 'utf8'), browser);
equal(typeof browser.SevillaRefundDestination.bind, 'function');
console.log(`${assertions} refund destination assertions passed`);
