'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.resolve(__dirname, '..');

function loadRenderers(relativePath) {
    const source = fs.readFileSync(path.join(root, relativePath), 'utf8');
    const context = vm.createContext({ document: { addEventListener() {} } });
    new vm.Script(source, { filename: relativePath }).runInContext(context);
    return context;
}

const overview = loadRenderers('assets/js/admin-page/admin_overview.js');
const bookings = loadRenderers('assets/js/admin-page/admin_bookings.js');
const hostile = `</strong><img src=x onerror=alert(1)> & "double" 'single'`;
const escaped = '&lt;/strong&gt;&lt;img src=x onerror=alert(1)&gt; &amp; &quot;double&quot; &#39;single&#39;';

for (const [context, render] of [
    [overview, 'buildOverviewEventDetailsMarkup'],
    [bookings, 'buildAdminEventDetailsMarkup']
]) {
    const markup = context[render]({
        event_type: hostile,
        event_style: hostile,
        custom_notes: hostile,
        admin_notes: hostile
    });
    assert.equal((markup.match(new RegExp(escaped.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) || []).length, 4, `${render} should encode all customer and admin supplied detail fields`);
    assert.ok(!markup.includes('<img'));
    assert.ok(markup.includes('notes-cust-box') && markup.includes('notes-prep-box'));
}

const overviewExtras = overview.buildOverviewExtrasMarkup(
    [{ name: hostile, quantity: hostile, total_price: 12 }],
    [{ building_name: hostile, room_type: hostile, room_number: hostile, start_date: hostile, end_date: hostile, nights: hostile, line_total: 20 }]
);
assert.equal(overviewExtras.hasExtras, true);
assert.ok(!overviewExtras.html.includes('<img'));
assert.ok(overviewExtras.html.includes(escaped));

const bookingExtras = bookings.buildAdminBookingExtrasMarkup(
    [{ name: hostile, quantity: hostile, total_price: 12 }],
    [{ item_name: hostile, amount: 8 }, { item_name: null, amount: 1 }],
    [{ building_name: hostile, room_type: hostile, room_number: hostile, start_date: hostile, end_date: hostile, nights: hostile, line_total: 20 }]
);
assert.equal(bookingExtras.hasExtras, true);
assert.ok(!bookingExtras.html.includes('<img'));
assert.ok(bookingExtras.html.includes(escaped));
assert.ok(bookingExtras.html.includes('&#8226; </span>'), 'a null line-item name should render as empty text safely');

const nullItemWithRoom = bookings.buildAdminBookingExtrasMarkup([], [{ item_name: null, amount: 1 }], [{ building_name: 'B', room_type: 'R', line_total: 0 }]);
assert.equal(nullItemWithRoom.hasExtras, true);
assert.ok(!nullItemWithRoom.html.includes('Room Add-on:'), 'null item names must not throw while checking room add-on prefixes');

for (const [source, expression] of [
    [fs.readFileSync(path.join(root, 'assets/js/admin-page/admin_overview.js'), 'utf8'), 'specValue.innerHTML = buildOverviewEventDetailsMarkup(specifics)'],
    [fs.readFileSync(path.join(root, 'assets/js/admin-page/admin_bookings.js'), 'utf8'), 'specValue.innerHTML = buildAdminEventDetailsMarkup(specifics)'],
    [fs.readFileSync(path.join(root, 'assets/js/admin-page/admin_overview.js'), 'utf8'), 'addonsList.innerHTML = extras.html'],
    [fs.readFileSync(path.join(root, 'assets/js/admin-page/admin_bookings.js'), 'utf8'), 'addonsList.innerHTML = extras.html']
]) {
    assert.ok(source.includes(expression), `actual modal path must use tested renderer: ${expression}`);
}

console.log('Admin booking detail rendering checks passed.');
