const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(`${__dirname}/../assets/js/booking.js`, 'utf8');
function fixture(publicBooking = true) {
    const node = tag => ({tag, children: [], attributes: {}, classes: new Set(), textContent: '',
        classList: {contains(c) { return this.owner.className === c; }, toggle() {}},
        appendChild(child) { this.children.push(child); return child; },
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        setAttribute(k,v) { this.attributes[k]=v; },
        addEventListener(k,fn) { this[k]=fn; },
        closest() { return publicBooking ? {} : null; },
        after(child) { this.nextElementSibling=child; child.remove=()=>{this.nextElementSibling=null;}; }
    });
    const context = { document: { createElement: node, addEventListener() {} } };
    vm.createContext(context);
    vm.runInContext(source+'\nthis.Controller = BookingController;',context);
    return { controller: Object.create(context.Controller.prototype), list: node('ul') };
}
for (const count of [0,1,3,4,9,1000]) test(`public preview handles ${count} amenities`,()=>{
    const f=fixture(); const items=Array.from({length:count},(_,i)=>`Feature ${i}`);
    f.controller.renderAmenityList(f.list,items,'No amenities listed.');
    assert.equal(f.list.children.length,count ? Math.min(3,count) : 1);
    if(count>3){assert.equal(f.list.nextElementSibling.children[0].textContent,`${count} included amenities`);assert.equal(f.list.nextElementSibling.children[1].attributes['aria-haspopup'],'dialog');}
    else assert.equal(f.list.nextElementSibling,undefined);
});
test('staff keeps its complete list and public replacement removes stale disclosure',()=>{
    const staff=fixture(false);staff.controller.renderAmenityList(staff.list,Array(9).fill('Feature'),'Empty');assert.equal(staff.list.children.length,9);
    const f=fixture();f.controller.renderAmenityList(f.list,Array(9).fill('Feature'),'Empty');f.list.nextElementSibling.classList.owner=f.list.nextElementSibling;
    f.controller.renderAmenityList(f.list,[],'Empty');assert.equal(f.list.nextElementSibling,null);assert.equal(f.list.children[0].textContent,'Empty');
});
test('labels remain plain text including HTML-like and long input',()=>{
    const f=fixture();const label='<img src=x onerror=alert(1)>'+ 'LongName'.repeat(100);
    f.controller.renderAmenityList(f.list,[label],'Empty');assert.equal(f.list.children[0].children[0].textContent,label);assert.equal(f.list.children[0].children[0].children.length,0);
});
test('booking amenities use meaningful decorative icons and a neutral custom fallback',()=>{
    const f=fixture();
    for(const [label,icon] of [['Free Wi-Fi','fa-wifi'],['Tables and chairs','fa-chair'],['sound system','fa-volume-high'],['microphones','fa-microphone'],['Parking area','fa-square-parking'],['Air conditioning','fa-snowflake'],['Swimming pool','fa-water-ladder'],['Custom package','fa-circle'],['Blackout curtains','fa-circle']]) {
        const item=f.controller.createBookingAmenityItem(label);
        assert.equal(item.children[0].textContent,label);
        assert.equal(item.children[1].className,`booking-amenity-icon fa-solid ${icon}`);
        assert.equal(item.children[1].attributes['aria-hidden'],'true');
    }
    const staff=fixture(false);staff.controller.renderAmenityList(staff.list,['Free Wi-Fi'],'Empty');
    assert.equal(staff.list.children[0].children.length,1,'shared Staff renderer keeps its existing text-only DOM');
});
