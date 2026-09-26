#!/usr/bin/env node
/* Regression: ISO booking/draft dates must remain local calendar dates. */
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
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
const context = { Date, document, console, window: {}, setTimeout, clearTimeout, FormData, fetch: async () => ({}) };
vm.createContext(context);
const source = fs.readFileSync(require('node:path').join(__dirname, '..', 'assets/js/calendar.js'), 'utf8');
vm.runInContext(`${source}\nthis.SevillaCalendar = SevillaCalendar; this.normalizeCalendarDate = normalizeCalendarDate; this.calendarNightDifference = calendarNightDifference;`, context);
assert.ok(source.includes('calendarNightDifference(this.startDate, cellDate)'), 'minimum-night validation uses date-only calendar arithmetic');
assert.ok(source.includes('calendarNightDifference(this.startDate, this.endDate)'), 'displayed night totals use date-only calendar arithmetic');

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

calendar.bookedDatesList = ['2035-09-04'];
calendar.hardBlockedDatesList = [];
calendar.isMaintenanceMode = false;
assert.equal(calendar.hasUnavailableDatesInclusive(new Date(2035, 8, 3), new Date(2035, 8, 5)), true, 'an occupied interior Villa date blocks a multi-night range');
calendar.bookedDatesList = ['2035-09-05'];
assert.equal(calendar.hasUnavailableDatesInclusive(new Date(2035, 8, 3), new Date(2035, 8, 5)), true, 'an occupied checkout Villa date blocks the range');
calendar.bookedDatesList = [];
calendar.hardBlockedDatesList = ['2035-09-04'];
assert.equal(calendar.hasUnavailableDatesInclusive(new Date(2035, 8, 3), new Date(2035, 8, 5)), true, 'blocking maintenance is unavailable throughout an inclusive Villa range');
calendar.hardBlockedDatesList = [];
calendar.bookedDatesList = ['2035-09-04'];
calendar.isMaintenanceMode = true;
calendar.maintenanceDatesList = [{ date: '2035-09-04', is_blocking: true }];
assert.equal(calendar.hasUnavailableDatesInclusive(new Date(2035, 8, 3), new Date(2035, 8, 5)), true, 'maintenance-calendar blockers are unavailable in an inclusive Villa range');
calendar.maintenanceDatesList = [{ date: '2035-09-04', is_blocking: false }];
assert.equal(calendar.hasUnavailableDatesInclusive(new Date(2035, 8, 3), new Date(2035, 8, 5)), true, 'an active Villa hold in booked dates blocks an inclusive range');
calendar.bookedDatesList = [];
assert.equal(calendar.hasUnavailableDatesInclusive(new Date(2035, 8, 3), new Date(2035, 8, 5)), false, 'an available two-night Villa range remains selectable');
assert.equal(context.calendarNightDifference(new Date(2035, 8, 3), new Date(2035, 8, 4)), 1, 'minimum-night checks use calendar dates rather than elapsed hours');

const dstRegression = spawnSync(process.execPath, ['-e', `
  const assert = require('node:assert/strict');
  const calendarNightDifference = (${context.calendarNightDifference.toString()});
  const springStart = new Date(2026, 2, 7);
  const springEnd = new Date(2026, 2, 9);
  const fallStart = new Date(2026, 9, 31);
  const fallEnd = new Date(2026, 10, 2);
  assert.equal(springEnd - springStart, 47 * 60 * 60 * 1000, 'fixture crosses a 23-hour DST night');
  assert.equal(fallEnd - fallStart, 49 * 60 * 60 * 1000, 'fixture crosses a 25-hour DST night');
  assert.equal(calendarNightDifference(springStart, springEnd), 2);
  assert.equal(calendarNightDifference(fallStart, fallEnd), 2);
`], {
  encoding: 'utf8',
  env: { ...process.env, TZ: 'America/New_York' }
});
assert.equal(dstRegression.status, 0, `calendar-night arithmetic must survive daylight-saving transitions: ${dstRegression.stderr}`);

console.log('PASS|calendar timezone normalization, inclusive Villa availability, and DST-safe night arithmetic');
