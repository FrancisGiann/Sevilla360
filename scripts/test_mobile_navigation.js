const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../includes/header.php'), 'utf8');
const start = source.indexOf('(function() {', source.indexOf('<script>'));
const end = source.indexOf('        if (userMenu)', start);
assert.ok(start > 0 && end > start, 'shared drawer script is available');
const handlers = {};
const events = {};
const attributes = {};
const classes = new Set();
let focused = false;
const linkEvents = {};
const header = {
    classList: {
        toggle(name) { if (classes.has(name)) { classes.delete(name); return false; } classes.add(name); return true; },
        contains(name) { return classes.has(name); },
        remove(name) { classes.delete(name); }
    },
    querySelectorAll() { return [{addEventListener(name, handler) {linkEvents[name] = handler;}}]; }
};
const burger = {
    addEventListener(name, handler) {handlers[name] = handler;},
    setAttribute(name, value) {attributes[name] = value;},
    focus() {focused = true;}
};
vm.runInNewContext(source.slice(start, end) + '})();', {
    window: {scrollY: 0, addEventListener() {}},
    document: {
        getElementById(id) {return id === 'siteHeader' ? header : (id === 'burger' ? burger : null);},
        addEventListener(name, handler) {events[name] = handler;}
    }
});
handlers.click();
assert.equal(attributes['aria-expanded'], 'true');
let prevented = false;
events.keydown({key: 'Tab', preventDefault() {prevented = true;}});
assert.equal(classes.has('is-open'), true, 'ordinary keyboard navigation stays available');
assert.equal(prevented, false);
events.keydown({key: 'Escape', preventDefault() {prevented = true;}});
assert.equal(classes.has('is-open'), false, 'Escape closes the open drawer');
assert.equal(attributes['aria-expanded'], 'false');
assert.equal(focused, true, 'Escape restores focus to the trigger');
assert.equal(prevented, true);
prevented = false;
events.keydown({key: 'Escape', preventDefault() {prevented = true;}});
assert.equal(prevented, false, 'closed drawer does not consume Escape from other controls');
handlers.click();
linkEvents.click();
assert.equal(classes.has('is-open'), false, 'normal navigation still closes the drawer');
assert.equal(attributes['aria-expanded'], 'false');
console.log('PASS|drawer opens, Tab remains available, Escape closes and restores focus, closed Escape is ignored, links close normally');
