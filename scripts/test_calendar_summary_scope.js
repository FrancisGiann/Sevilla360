const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/calendar.js'), 'utf8');
function fixture() {
    const admin = { innerText: '' }, customer = { innerText: '' };
    let recalculations = 0;
    const context = { Date, console, document: {
        getElementById: id => id === 'summary-dates' ? admin : {querySelector:()=>null},
        querySelectorAll: () => [customer]
    }, window: {calculateSummary:()=>recalculations++} };
    vm.createContext(context);
    vm.runInContext(source+'\nthis.Calendar=SevillaCalendar;',context);
    context.Calendar.prototype.init = function() {};
    context.Calendar.prototype.render = function() {};
    return {admin,customer,Calendar:context.Calendar,recalculations:()=>recalculations};
}
test('hotel add-on stay and clearing leave the primary event summary intact',()=>{
    const f=fixture();
    const event=new f.Calendar('event');event.setSelection('2026-10-27','2026-10-27');
    const eventText=f.customer.innerText;
    assert.match(eventText,/Oct 27, 2026/);
    const addon=new f.Calendar('addon',{requireHotelRules:true,updateSummaryDisplay:false});
    addon.setSelection('2026-10-27','2026-10-29');
    assert.equal(addon.totalNights,2);
    assert.equal(f.customer.innerText,eventText);
    assert.equal(f.admin.innerText,eventText);
    addon.clearSelection();
    assert.equal(f.customer.innerText,eventText);
    assert.equal(f.recalculations(),3,'summary math still recalculates on both calendars');
});
test('primary hotel and villa calendars retain date-range and clear behavior',()=>{
    const f=fixture();const primary=new f.Calendar('primary');
    primary.setSelection('2026-10-27','2026-10-29');
    assert.equal(primary.totalNights,2);
    assert.match(f.customer.innerText,/Oct 27, 2026 — Oct 29, 2026/);
    primary.clearSelection();assert.equal(f.customer.innerText,'Please select dates');
});
test('event add-on constructor opts out while primary calendars keep the default',()=>{
    const booking=fs.readFileSync(path.join(__dirname,'../assets/js/booking.js'),'utf8');
    assert.match(booking,/calendars\.addonHotel = new SevillaCalendar\("cal-ui-addon-hotel", \{\s*requireHotelRules: true,\s*updateSummaryDisplay: false,/);
});
