const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../assets/js/panorama-image-loader.js`, 'utf8');
function fixture() {
    const images = [], timers = new Map();
    let id = 0;
    const window = {
        PANOLENS: { ImageLoader: {} },
        Image: class { constructor() { this.naturalWidth = 0; this.naturalHeight = 0; images.push(this); } },
        setTimeout(fn, delay) { timers.set(++id, { fn, delay }); return id; },
        clearTimeout(key) { timers.delete(key); }
    };
    vm.runInNewContext(source, { window });
    const flush = delay => {
        for (const [key, timer] of [...timers]) if (timer.delay === delay) { timers.delete(key); timer.fn(); }
    };
    const loaded = image => { image.naturalWidth = 512; image.naturalHeight = 256; image.onload(); flush(0); };
    return { window, loader: window.PANOLENS.ImageLoader, images, timers, flush, loaded };
}
test('simultaneous panorama/icon requests wait for one decoded image', () => {
    const f = fixture(), results = [];
    const a = f.loader.load('same.png', image => results.push(image));
    const b = f.loader.load('same.png', image => results.push(image));
    f.flush(0);
    assert.equal(a, b); assert.equal(f.images.length, 1); assert.equal(results.length, 0);
    f.loaded(a); assert.equal(results.length, 2); assert.ok(results.every(image => image.naturalWidth > 0));
    f.loader.load('same.png', image => results.push(image));
    assert.equal(results.length, 2); f.flush(0); assert.equal(results.length, 3);
    assert.equal(f.timers.size, 0);
});
test('decode and network errors notify all subscribers and allow a fresh retry', () => {
    for (const failure of ['decode', 'network']) {
        const f = fixture(), errors = [];
        f.loader.load('broken.jpg', () => assert.fail('must not load'), undefined, e => errors.push(e));
        f.loader.load('broken.jpg', () => assert.fail('must not load'), undefined, e => errors.push(e));
        if (failure === 'decode') f.images[0].onload(); else f.images[0].onerror();
        f.flush(0); assert.equal(errors.length, 2);
        let success = false;
        f.loader.load('broken.jpg', () => { success = true; });
        assert.equal(f.images.length, 2); f.loaded(f.images[1]); assert.equal(success, true);
    }
});
test('hung requests expire, cancel their image and ignore late callbacks after retry', () => {
    const f = fixture(); let errors = 0, successes = 0;
    f.loader.load('slow.jpg', () => successes++, undefined, () => errors++);
    const late = f.images[0].onload;
    f.flush(20000); f.flush(0);
    assert.equal(errors, 1); assert.equal(f.images[0].src, '');
    f.loader.load('slow.jpg', () => successes++);
    late(); f.flush(0); assert.equal(successes, 0);
    f.loaded(f.images[1]); assert.equal(successes, 1); assert.equal(f.timers.size, 0);
});
test('a duplicate script does not reset pending requests or their listeners', () => {
    const f = fixture(); let results = 0;
    f.loader.load('icon.png', () => results++);
    vm.runInNewContext(source, { window: f.window });
    f.loader.load('icon.png', () => results++);
    f.loaded(f.images[0]); assert.equal(results, 2); assert.equal(f.images.length, 1);
});

function networkFixture() {
    let now = 0, serial = 0, objectSerial = 0;
    const timers = new Map(), images = [], xhrs = [], revoked = [];
    const window = {
        PANOLENS: { ImageLoader: {} },
        Image: class { constructor() { this.naturalWidth = 0; this.naturalHeight = 0; images.push(this); } },
        XMLHttpRequest: class {
            constructor() { xhrs.push(this); this.aborted = false; }
            open(method, url) { this.method = method; this.url = url; }
            send() { this.sent = true; }
            abort() { this.aborted = true; this.onabort?.(); }
            progress(loaded, total = 1000) { this.onprogress?.({ loaded, total, lengthComputable: true }); }
            complete(status = 200) { this.status = status; this.response = {}; this.onload?.(); }
        },
        URL: { createObjectURL() { return `blob:qa-${++objectSerial}`; }, revokeObjectURL(url) { revoked.push(url); } },
        setTimeout(fn, delay) { timers.set(++serial, { fn, due: now + delay }); return serial; },
        clearTimeout(id) { timers.delete(id); }
    };
    vm.runInNewContext(source, { window });
    const tick = elapsed => {
        const target = now + elapsed;
        for (let i = 0; i < 1000; i++) {
            const next = [...timers].filter(([, t]) => t.due <= target).sort((a, b) => a[1].due - b[1].due || a[0] - b[0])[0];
            if (!next) { now = target; return; }
            now = next[1].due; timers.delete(next[0]); next[1].fn();
        }
        throw Error('Timer loop did not settle');
    };
    const decode = image => { image.naturalWidth = 4096; image.naturalHeight = 2048; image.onload(); tick(0); };
    return { window, loader: window.PANOLENS.ImageLoader, xhrs, images, timers, revoked, tick, decode };
}
test('progressing large downloads survive the old twenty-second wall clock deadline', () => {
    const f = networkFixture(); let success = 0, errors = 0;
    const image = f.loader.load('large.jpg', () => success++, undefined, () => errors++);
    for (let i = 1; i <= 3; i++) { f.tick(15000); f.xhrs[0].progress(i * 100); }
    assert.equal(errors, 0); assert.equal(success, 0);
    f.xhrs[0].complete(); f.decode(image);
    assert.equal(success, 1); assert.equal(errors, 0); assert.equal(f.timers.size, 0);
    assert.deepEqual(f.revoked, ['blob:qa-1']);
});
test('network subscribers share one transfer and only cache a decoded texture', () => {
    const f = networkFixture(), results = [];
    const a = f.loader.load('same.jpg', image => results.push(image));
    const b = f.loader.load('same.jpg', image => results.push(image));
    assert.equal(a, b); assert.equal(f.xhrs.length, 1); assert.equal(results.length, 0);
    f.xhrs[0].complete(); assert.equal(results.length, 0); f.decode(a);
    assert.equal(results.length, 2); f.loader.load('same.jpg', image => results.push(image)); f.tick(0);
    assert.equal(results.length, 3); assert.equal(f.xhrs.length, 1);
});
test('stalled transfers abort and permit a fresh request without stale success', () => {
    const f = networkFixture(); let errors = 0, success = 0;
    f.loader.load('stall.jpg', () => success++, undefined, () => errors++);
    const late = f.xhrs[0].onload;
    f.tick(21000); assert.equal(errors, 1); assert.equal(f.xhrs[0].aborted, true);
    f.loader.load('stall.jpg', () => success++);
    f.xhrs[0].status = 200; f.xhrs[0].response = {}; late(); f.tick(0);
    assert.equal(success, 0); f.xhrs[1].complete(); f.decode(f.images[1]); assert.equal(success, 1);
});
test('HTTP and decode failures remain errors, revoke blobs and do not poison retries', () => {
    for (const failure of ['http', 'decode']) {
        const f = networkFixture(); let errors = 0;
        f.loader.load('bad.jpg', () => assert.fail('must not load invalid image'), undefined, () => errors++);
        if (failure === 'http') f.xhrs[0].complete(404);
        else { f.xhrs[0].complete(); f.images[0].onerror(); }
        f.tick(0); assert.equal(errors, 1); assert.equal(f.timers.size, 0);
        assert.equal(f.revoked.length, failure === 'decode' ? 1 : 0);
        f.loader.load('bad.jpg'); assert.equal(f.xhrs.length, 2);
    }
});
test('a hard bound still terminates indefinitely progressing transfers', () => {
    const f = networkFixture(); let errors = 0;
    f.loader.load('endless.jpg', () => assert.fail('must not load'), undefined, () => errors++);
    for (let i = 1; i <= 11; i++) { f.tick(10000); f.xhrs[0].progress(i * 10); }
    f.tick(10000); assert.equal(errors, 1); assert.equal(f.xhrs[0].aborted, true); assert.equal(f.timers.size, 0);
});

test('completed bytes are not aborted while a busy page delays XHR event delivery', () => {
    const f = networkFixture(); let errors = 0, successes = 0;
    // Resource Timing can be populated before the queued onload task runs.
    f.window.URL = Object.assign(class extends URL {}, f.window.URL);
    f.window.location = { href: 'https://fixture.test/' };
    f.window.performance = { now: () => 1, getEntriesByName: () => [{ startTime: 2, responseEnd: 3, encodedBodySize: 4000 }] };
    const image = f.loader.load('delayed-events.jpg', () => successes++, undefined, () => errors++);
    f.tick(21000); assert.equal(errors, 0); assert.equal(f.xhrs[0].aborted, false);
    f.xhrs[0].complete(); f.decode(image);
    assert.equal(successes, 1); assert.equal(errors, 0); assert.equal(f.timers.size, 0);
});


test('queued completion events get a turn before an idle timer aborts their transfer', () => {
    const f = networkFixture(); let success = 0, errors = 0;
    const image = f.loader.load('queued-response.jpg', () => success++, undefined, () => errors++);
    f.tick(20000); assert.equal(errors, 0); assert.equal(f.xhrs[0].aborted, false);
    // No Resource Timing entry is exposed yet; the queued response then arrives.
    f.xhrs[0].complete(); f.decode(image); f.tick(1000);
    assert.equal(success, 1); assert.equal(errors, 0); assert.equal(f.timers.size, 0);
});
