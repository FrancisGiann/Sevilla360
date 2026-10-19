const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/booking.js'), 'utf8');
const elements = {
    'booking-summary': {hidden:false,contains: () => false},
    'booking-compact-estimate': {hidden:false},
    '.booking-sidebar': {hidden:false},
    '[data-booking-step-nav].is-current': {id:'choose'}
};
const context = {document:{addEventListener() {},activeElement:null,getElementById:id=>elements[id],querySelector:selector=>elements[selector]}};
vm.createContext(context);
vm.runInContext(source+'\nthis.Controller = BookingController;',context);
const controller = Object.create(context.Controller.prototype);
controller.state = {activeTabId:'event-hall',currentStep:1,summary:{total:1000}};
let selected = {};
controller.getTabContextData = () => selected;
function verify(tab, selection, step, hasEstimate, expectedPanel, expectedCompact, message) {
    controller.state.activeTabId=tab; controller.state.currentStep=step; selected=selection;
    const hasSelection=controller.hasBookingSelection();
    controller.updateSummaryVisibility(hasSelection,hasEstimate);
    assert.equal(elements['booking-summary'].hidden,!expectedPanel,'full panel: '+message);
    assert.equal(elements['booking-compact-estimate'].hidden,!expectedCompact,'compact estimate: '+message);
    assert.equal(elements['.booking-sidebar'].hidden,true,'no duplicate sidebar: '+message);
}
for(const tab of ['event-hall','hotel-rooms','resort-villa']) {
    for(const step of [1,2,3,4]) verify(tab,{},step,false,false,false,'empty '+tab+' at step '+step);
}
verify('hotel-rooms',{roomType:'Hotel Room'},1,false,false,false,'category alone is not a room');
for(const [tab,selection] of [['hotel-rooms',{roomType:'Hotel Room',roomName:'QA Lodge'}],['event-hall',{venueId:90}],['resort-villa',{venueId:2000}]]) {
    verify(tab,selection,1,false,false,false,'choice without dates has no estimate');
    for(const step of [1,2,3]) verify(tab,selection,step,true,false,true,'existing choice/Back/valid resumed state at step '+step);
    verify(tab,selection,4,true,true,false,'Review owns the only full breakdown');
}
controller.state.summary.total=0;
verify('event-hall',{venueId:90},1,true,false,true,'zero-priced valid choice and last removed add-on retain a truthful compact estimate');
verify('event-hall',{venueId:90},1,false,false,false,'clamped/invalid resumed dates do not show a stale estimate');
verify('event-hall',{},4,false,false,false,'last primary choice cleared even at Review');
controller.state.summary.total=99999;
verify('hotel-rooms',{},1,true,false,false,'stale prior total cannot reveal an empty new service');
let focusTarget=null;
elements['booking-summary'].contains=()=>true;
controller.focusBookingElement=element=>{focusTarget=element;};
controller.updateSummaryVisibility(false);
assert.equal(focusTarget,elements['[data-booking-step-nav].is-current'],'hiding focused summary returns focus to the current step');
const markup=fs.readFileSync(path.join(__dirname,'../booking.php'),'utf8');
assert.match(markup,/id="booking-compact-estimate"[^>]* hidden>/,'compact total starts hidden before JavaScript initializes');
assert.ok(!markup.includes('class="booking-summary-jump"'),'earlier steps have no summary navigation control');
assert.match(markup,/id="booking-summary"[^>]* hidden>/,'panel starts hidden before JavaScript initializes');
console.log('PASS|Review-only full summary; initial empty states; compact estimate states for all services; Back/resumed/clamped states; zero price; removed add-on; cleared primary; stale total; focus and initial HTML');
