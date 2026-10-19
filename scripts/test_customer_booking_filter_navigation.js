const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const source=require('node:fs').readFileSync(require('node:path').join(__dirname,'../assets/js/user_dashboard.js'),'utf8');
const block=source.match(/statusFilter\.addEventListener\("change", \(e\) => \{([\s\S]*?)\n    \}\);/)[1];
for(const filter of ['Pending','Partially Paid','All'])test(`changing to ${filter} resets the page and preserves the server filter`,()=>{
 let assigned;const window={location:{href:'https://example.test/user_dashboard.php?section=settings&booking_page=5&booking_status=Completed#booking-1',assign:value=>assigned=value}};
 vm.runInNewContext(block,{window,URL,e:{target:{value:filter}}});
 const url=new URL(assigned);assert.equal(url.origin,'https://example.test');assert.equal(url.searchParams.get('section'),'bookings');assert.equal(url.searchParams.get('booking_page'),null);assert.equal(url.searchParams.get('booking_status'),filter==='All'?null:filter);assert.equal(url.hash,'');
});
