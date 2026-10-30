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
