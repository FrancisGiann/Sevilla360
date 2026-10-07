'use strict';

const assert = require('node:assert/strict');
const { formatAgreedSeminarPrice, isValidAgreedSeminarPrice } = require('../assets/js/admin-page/admin_seminars.js');

assert.equal(formatAgreedSeminarPrice(null), 'Not set');
assert.equal(formatAgreedSeminarPrice(undefined), 'Not set');
assert.equal(formatAgreedSeminarPrice(''), 'Not set');
assert.equal(formatAgreedSeminarPrice('0.00'), '₱0.00');
assert.equal(formatAgreedSeminarPrice('1500'), '₱1,500.00');
assert.equal(formatAgreedSeminarPrice('1500.5'), '₱1,500.50');
assert.equal(formatAgreedSeminarPrice('000000000001.00'), '₱1.00');
assert.equal(formatAgreedSeminarPrice('9999999999.99'), '₱9,999,999,999.99');
assert.equal(formatAgreedSeminarPrice('-1.00'), 'Not set');
assert.equal(isValidAgreedSeminarPrice(''), true);
assert.equal(isValidAgreedSeminarPrice('0'), true);
assert.equal(isValidAgreedSeminarPrice('000000000001.00'), true);
assert.equal(isValidAgreedSeminarPrice('9999999999.99'), true);
assert.equal(isValidAgreedSeminarPrice('10000000000.00'), false);
assert.equal(isValidAgreedSeminarPrice('1.001'), false);
assert.equal(isValidAgreedSeminarPrice('1e3'), false);

console.log('Passed seminar agreed price display checks.');
