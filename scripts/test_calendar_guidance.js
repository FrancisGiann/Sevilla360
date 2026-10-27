#!/usr/bin/env node
/* Exercise the visible guidance through actual calendar date-button clicks. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Element {
  constructor(tag = 'div') {
    this.tagName = tag;
    this.children = [];
    this.listeners = {};
    this.attributes = {};
    this.dataset = {};
    this.classSet = new Set();
    this.classList = { add: (...names) => names.forEach(name => this.classSet.add(name)) };
  }
  set className(value) { this.classSet = new Set(value.split(/\s+/)); }
  get className() { return [...this.classSet].join(' '); }
  set innerHTML(value) { if (value === '') this.children = []; }
  appendChild(element) { this.children.push(element); return element; }
  addEventListener(name, callback) { this.listeners[name] = callback; }
  setAttribute(name, value) { this.attributes[name] = value; }
  querySelector(selector) {
    if (selector === '[data-calendar-selection-guide]') return this.guide || null;
    return this.children.find(element => element.className === selector.slice(1)) || null;
  }
}

function makeCalendar(mode, id = 'idx-modal-calendar') {
  const guide = { id: `${id}-guide`, textContent: '' };
  const parent = new Element();
  parent.guide = guide;
  const root = new Element();
  root.id = id;
  root.parentElement = parent;
  root.dataset.calendarGuideMode = mode;
  for (const [tag, className] of [['div', 'cal-days-grid'], ['h4', 'cal-month-year'], ['button', 'prev-month'], ['button', 'next-month']]) {
    const child = new Element(tag);
    child.className = className;
    root.appendChild(child);
  }
  const context = { document: { getElementById: key => key === id ? root : null, createElement: tag => new Element(tag), querySelectorAll: () => [] }, window: {}, Date, console };
  vm.createContext(context);
  const source = fs.readFileSync(path.join(__dirname, '../assets/js/calendar.js'), 'utf8');
  vm.runInContext(`${source}\nthis.TestCalendar = SevillaCalendar;`, context);
  const calendar = new context.TestCalendar(id, { onRangeSelected: () => {} });
  calendar.currentDate = new Date(2030, 0, 1);
  calendar.render();
  return { calendar, guide, root };
}

function selectDay(calendar, day) {
  const cell = calendar.grid.children.find(item => item.tagName === 'button' && item.innerText === day);
  assert.ok(cell, `day ${day} is selectable`);
  cell.listeners.click();
}

const event = makeCalendar('event');
assert.match(event.guide.textContent, /select the same date again/i);
assert.equal(event.root.attributes['aria-describedby'], event.guide.id);
selectDay(event.calendar, 10);
assert.match(event.guide.textContent, /now select an end date, or select the same date again/i);
selectDay(event.calendar, 10);
assert.match(event.guide.textContent, /Selected: Jan 10, 2030.*Continue booking/i);

const hotel = makeCalendar('hotel', 'cal-ui-hotel');
hotel.calendar.requireHotelRules = true;
hotel.calendar.render();
assert.match(hotel.guide.textContent, /check-in date.*later check-out date/i);
selectDay(hotel.calendar, 10);
assert.match(hotel.guide.textContent, /Check-in date selected.*later check-out date/i);
selectDay(hotel.calendar, 12);
assert.match(hotel.guide.textContent, /Jan 10, 2030 to Jan 12, 2030.*Confirm/i);

const villa = makeCalendar('villa', 'cal-ui-villa');
villa.calendar.fixedDurationNights = 0;
villa.calendar.updateSelectionGuide();
assert.match(villa.guide.textContent, /one available date/i);
selectDay(villa.calendar, 10);
assert.match(villa.guide.textContent, /Selected: Jan 10, 2030/i);
villa.calendar.clearSelectedRange();
villa.calendar.fixedDurationNights = null;
villa.calendar.updateSelectionGuide();
assert.match(villa.guide.textContent, /check-in date.*later checkout date/i);
selectDay(villa.calendar, 10);
assert.match(villa.guide.textContent, /Now select a later checkout date/i);

console.log('Calendar guidance transitions passed');
