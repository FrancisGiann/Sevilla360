const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../assets/js/showroom.js`, 'utf8');
const context = {};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('function createShowroomSceneLoader('), source.indexOf('document.addEventListener("DOMContentLoaded"')), context);
function fixture() {
    const events=[],timers=new Set();
    const loader=context.createShowroomSceneLoader({
        show:(label,retry,failed)=>events.push({label,retry,failed}), hide:()=>events.push('hidden'),
        commit:pano=>events.push(pano.name),ready:pano=>events.push(`ready:${pano.name}`),isCurrent:pano=>pano.current,
        setTimer:fn=>{timers.add(fn);return fn;},clearTimer:fn=>timers.delete(fn)
    });
    const pano=name=>({name,loaded:false,material:{},listeners:{},loads:0,
        addEventListener(type,fn){(this.listeners[type]??=new Set()).add(fn);},
        removeEventListener(type,fn){this.listeners[type]?.delete(fn);},
        load(){this.loads++;},emit(type){if(type==='load'){this.loaded=true;this.material.map={};}for(const fn of [...this.listeners[type]??[]])fn();}
    });
    return {loader,events,timers,pano};
}
test('acknowledges destination immediately, preserves view until texture ready, waits for enter',()=>{
    const f=fixture(),p=f.pano('B');f.loader.enter(p,'Garden Hall — Pool',()=>{});
    assert.match(f.events[0].label,/Loading Garden Hall — Pool/);assert.equal(p.loads,1);assert.ok(!f.events.includes('B'));
    p.emit('load');assert.ok(f.events.includes('B'));assert.ok(!f.events.includes('hidden'));
    p.emit('enter-fade-start');assert.equal(f.events.at(-1),'ready:B');assert.equal(f.timers.size,0);
});
test('latest destination wins and stale load, error and transition callbacks cannot commit',()=>{
    const f=fixture(),a=f.pano('A'),b=f.pano('B');f.loader.enter(a,'A',()=>{});
    const oldLoad=[...a.listeners.load][0],oldError=[...a.listeners.error][0];
    f.loader.enter(b,'B',()=>{});oldLoad();oldError();assert.ok(!f.events.includes('A'));
    b.emit('load');b.emit('enter-fade-start');assert.equal(f.events.at(-1),'ready:B');assert.equal(f.timers.size,0);
});
test('timeout offers the exact retry without switching, late success is ignored',()=>{
    const f=fixture(),p=f.pano('Slow');let retries=0;f.loader.enter(p,'Slow pool',()=>retries++);
    const late=[...p.listeners.load][0];[...f.timers][0]();
    assert.equal(f.events.at(-1).failed,true);assert.match(f.events.at(-1).label,/Could not load Slow pool/);
    f.events.at(-1).retry();assert.equal(retries,1);late();assert.ok(!f.events.includes('Slow'));
});
test('load errors and synchronous failures produce recoverable feedback',()=>{
    const f=fixture(),p=f.pano('Broken');f.loader.enter(p,'Broken',()=>{});p.emit('error');assert.equal(f.events.at(-1).failed,true);
    const q=f.pano('Throws');q.load=()=>{throw Error('network');};f.loader.enter(q,'Throws',()=>{});assert.equal(f.events.at(-1).failed,true);
});
test('cached texture enters without reloading; current ready view needs no new transition',()=>{
    const f=fixture(),p=f.pano('Cached');p.loaded=true;p.material.map={};f.loader.enter(p,'Cached',()=>{});
    assert.equal(p.loads,0);assert.ok(f.events.includes('Cached'));p.emit('enter-fade-start');
    p.current=true;f.loader.enter(p,'Cached',()=>{});assert.equal(f.events.at(-1),'ready:Cached');assert.equal(f.timers.size,0);
});
test('cancelled venue cannot reveal stale success or error',()=>{
    const f=fixture(),p=f.pano('Old');f.loader.enter(p,'Old',()=>{});const late=[...p.listeners.load][0];
    f.loader.cancel();late();p.emit('error');assert.ok(!f.events.includes('Old'));assert.equal(f.timers.size,0);
});

test('failed destinations preserve the displayed tour, including cached venue re-entry',()=>{
    const start=source.indexOf('  function handlePanoLoadFailure(');
    const end=source.indexOf('  // Retry is only offered',start);
    const stable={loaded:true,material:{map:{}}},failed={};
    const panoramas=[stable,failed],room={};let disposed=0,fallbacks=0;
    const handler=new Function('dataMap','currentRoomId','roomLoadToken','activePanoramas','viewer','panoCache','disposePanorama','showStandardFallback',
        `${source.slice(start,end)}; return handlePanoLoadFailure;`)(
        {hall:room},'hall',2,panoramas,{panorama:stable},{hall:panoramas},()=>disposed++,()=>fallbacks++);
    handler('hall',1,failed,panoramas,'hall');
    assert.equal(disposed,0);assert.equal(fallbacks,0);assert.equal(room.panoFailed,undefined);
});
