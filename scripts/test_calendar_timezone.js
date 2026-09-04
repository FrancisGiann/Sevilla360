#!/usr/bin/env node
/* Regression: ISO booking/draft dates must remain local calendar dates. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

assert.equal(process.env.TZ, 'Asia/Manila', 'run this regression with TZ=Asia/Manila');

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName;
    this.children = [];
    this.attributes = {};
    this.classSet = new Set();
    this.classList = {
      add: (...names) => names.forEach(name => this.classSet.add(name)),
      contains: name => this.classSet.has(name)
    };
    this.innerHTML = '';
  }

  set className(value) {
    this.classSet = new Set(String(value).split(/\s+/).filter(Boolean));
  }

  get className() {
    return [...this.classSet].join(' ');
  }

  set innerHTML(value) {
    this._innerHTML = value;
    if (value === '') this.children = [];
  }

  get innerHTML() {
    return this._innerHTML;
  }

  appendChild(child) {
    this.children.push(child);
    return child;
  }

  addEventListener() {}
  setAttribute(name, value) { this.attributes[name] = String(value); }
  querySelector(selector) {
    if (selector === '.cal-days-grid') return this.children.find(child => child.className === 'cal-days-grid') || null;
    if (selector === '.cal-month-year') return this.children.find(child => child.className === 'cal-month-year') || null;
    if (selector === '.prev-month') return this.children.find(child => child.className === 'prev-month') || null;
    if (selector === '.next-month') return this.children.find(child => child.className === 'next-month') || null;
    return null;
  }
}

const root = new FakeElement('div');
root.appendChild(Object.assign(new FakeElement('div'), { className: 'cal-days-grid' }));
root.appendChild(Object.assign(new FakeElement('h4'), { className: 'cal-month-year' }));
root.appendChild(Object.assign(new FakeElement('button'), { className: 'prev-month' }));
root.appendChild(Object.assign(new FakeElement('button'), { className: 'next-month' }));

const document = {
  getElementById: id => id === 'calendar' ? root : null,
  createElement: tagName => new FakeElement(tagName),
  getElementByClassName: () => null,
  querySelectorAll: () => []
};
const context = { Date, document, console, setTimeout, clearTimeout, FormData, fetch: async () => ({}) };
vm.createContext(context);
const source = fs.readFileSync(require('node:path').join(__dirname, '..', 'assets/js/calendar.js'), 'utf8');
vm.runInContext(`${source}\nthis.SevillaCalendar = SevillaCalendar; this.normalizeCalendarDate = normalizeCalendarDate;`, context);

const calendar = new context.SevillaCalendar('calendar');
calendar.setSelection('2035-09-03', '2035-09-04');
assert.equal(calendar.startDate.getHours(), 0);
assert.equal(calendar.endDate.getHours(), 0);
assert.equal(calendar.startDate.getFullYear(), 2035);
assert.equal(calendar.startDate.getMonth(), 8);
assert.equal(calendar.startDate.getDate(), 3);
assert.equal(calendar.endDate.getDate(), 4);
const cells = root.querySelector('.cal-days-grid').children;
assert.ok(cells.some(cell => cell.classList.contains('selected') && cell.classList.contains('start-date')), 'start endpoint renders selected');
assert.ok(cells.some(cell => cell.classList.contains('selected') && cell.classList.contains('end-date')), 'end endpoint renders selected');

calendar.setSelection(new Date('2035-09-06'), new Date('2035-09-07'));
assert.equal(calendar.startDate.getHours(), 0, 'Date inputs normalize to local midnight');
assert.equal(calendar.endDate.getHours(), 0, 'Date inputs normalize to local midnight');
assert.equal(calendar.startDate.getDate(), 6, 'UTC-parsed Date retains local calendar date');

calendar.setSelection('2035-02-30', '2035-09-08');
assert.equal(calendar.startDate, null, 'invalid canonical date is rejected');
assert.equal(calendar.endDate, null, 'end date is cleared when start input is invalid');
calendar.setSelection('2035-09-09', 'not-a-date');
assert.equal(calendar.startDate.getDate(), 9, 'valid start survives invalid end');
assert.equal(calendar.endDate, null, 'invalid end is rejected without poisoning render');

console.log('PASS|calendar timezone normalization and endpoint rendering');
