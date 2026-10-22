const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const showroomPhp = fs.readFileSync(`${__dirname}/../showroom.php`, 'utf8');
const showroomJs = fs.readFileSync(`${__dirname}/../assets/js/showroom.js`, 'utf8');
const galleryJs = fs.readFileSync(`${__dirname}/../assets/js/showroom-gallery.js`, 'utf8');
const showroomCss = fs.readFileSync(`${__dirname}/../assets/css/showroom.css`, 'utf8');

class FakeElement {
  constructor(tagName = 'div', baseURI = 'https://example.test/showroom.php') {
    this.tagName = tagName.toUpperCase();
    this.baseURI = baseURI;
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.attributes = {};
    this.children = [];
    this.handlers = {};
    this.style = {};
    this.className = '';
    this.classNames = new Set();
    this.focused = false;
    this.loading = '';
    this._src = '';
    const tokens = () => new Set([...this.classNames, ...this.className.split(/\s+/).filter(Boolean)]);
    this.classList = {
      add: name => { const next = tokens(); next.add(name); this.classNames = next; this.className = [...next].join(' '); },
      remove: name => { const next = tokens(); next.delete(name); this.classNames = next; this.className = [...next].join(' '); },
      toggle: (name, force) => {
        const next = tokens();
        const active = force === undefined ? !next.has(name) : Boolean(force);
        if (active) next.add(name); else next.delete(name);
        this.classNames = next;
        this.className = [...next].join(' ');
        return active;
      },
      contains: name => tokens().has(name)
    };
  }
  set src(value) { this._src = value ? new URL(String(value), this.baseURI).href : ''; }
  get src() { return this._src; }
  addEventListener(type, handler) { (this.handlers[type] ||= []).push(handler); }
  removeEventListener(type, handler) { this.handlers[type] = (this.handlers[type] || []).filter(item => item !== handler); }
  appendChild(child) { this.children.push(child); return child; }
  replaceChildren(...children) { this.children = children; }
  querySelector(selector) {
    if (selector === '.thumb-img') return this.children.find(child => child.tagName === 'IMG') || null;
    return null;
  }
  querySelectorAll(selector) {
    if (selector === '.photo-thumb') return this.children.filter(child => child.classList.contains('photo-thumb'));
    return [];
  }
  getAttribute(name) { return this.attributes[name] ?? null; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  focus() { this.focused = true; }
  click() {
    this.onclick?.({ target: this, preventDefault() {} });
    (this.handlers.click || []).forEach(handler => handler({ target: this, preventDefault() {} }));
  }
  fire(type, event = {}) { (this.handlers[type] || []).forEach(handler => handler({ target: this, ...event })); }
  get offsetWidth() { return 1; }
  closest(selector) { return selector === '.pill-dropdown-wrapper' ? this.wrapper : null; }
}

function runGallery() {
  const ids = [
    'showroom-wrapper', 'btn-view-photos', 'pano-loading-photos', 'current-slide-img', 'gallery-title',
    'gallery-counter', 'thumbnail-strip', 'slide-prev', 'slide-next', 'btn-back-to-360', 'btn-back-to-360-gallery',
    'pano-loading-overlay', 'pano-loading-retry', 'pano-loading-message'
  ];
  const elements = Object.fromEntries(ids.map(id => [id, new FakeElement(id)]));
  const rooms = [
    new FakeElement('venue-a'),
    new FakeElement('venue-b')
  ];
  rooms[0].setAttribute('data-room', 'venue-a');
  rooms[1].setAttribute('data-room', 'venue-b');
  const active = rooms[1];
  active.classList.add('active');
  const categoryRoom = new FakeElement('venue-a');
  categoryRoom.setAttribute('data-room', 'venue-a');
  const categoryWrapper = new FakeElement('div');
  categoryWrapper.querySelector = selector => selector === '.dropdown-item[data-room]' ? categoryRoom : null;
  categoryRoom.wrapper = categoryWrapper;
  const categoryPill = new FakeElement('button');
  categoryPill.setAttribute('data-category', 'Villa');
  categoryPill.wrapper = categoryWrapper;
  const document = {
    baseURI: 'https://example.test/showroom.php',
    body: new FakeElement('body'),
    getElementById: id => elements[id] || null,
    querySelector: selector => selector === '.dropdown-item.active[data-room]' ? active : null,
    querySelectorAll: selector => {
      if (selector === '.dropdown-item[data-room]') return rooms;
      if (selector === '.master-pill[data-category]') return [categoryPill];
      return [];
    },
    createElement: tag => new FakeElement(tag)
  };
  const events = [];
  const window = {
    showroomData: {
      'venue-a': { title: 'Villa', gallery: ['/uploads/villa-1.jpg'] },
      'venue-b': { title: 'Garden Hall', gallery: ['/uploads/garden-1.jpg', '/uploads/garden-2.jpg'] }
    },
    location: { search: '', hash: '' },
    innerWidth: 390,
    scrollTo: () => {},
    addEventListener: () => {},
    dispatchEvent: event => { events.push(event); }
  };
  const context = { window, document, URL, URLSearchParams, CustomEvent: class CustomEvent { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } } };
  vm.runInNewContext(galleryJs, context);
  return { elements, document, window, events };
}

test('fallback and normal photo actions use one existing full-screen gallery before viewer CDNs', () => {
  const mainEnd = showroomPhp.indexOf('</main>');
  const galleryModule = showroomPhp.indexOf('assets/js/showroom-gallery.js');
  const threeScript = showroomPhp.indexOf('https://cdnjs.cloudflare.com/ajax/libs/three.js/105/three.min.js');
  const panoScript = showroomPhp.indexOf('https://cdn.jsdelivr.net/npm/panolens@0.12.1/build/panolens.min.js');
  assert.ok(mainEnd >= 0 && mainEnd < galleryModule && galleryModule < threeScript && threeScript < panoScript);
  assert.match(showroomPhp, /id="pano-loading-photos">Browse venue photos/);
  assert.doesNotMatch(showroomPhp, /showroom-boot-gallery|<dialog[^>]*Venue photos/i);
  assert.ok(showroomJs.includes('window.SevillaShowroomPhotoGallery?.setGallery(roomId, currentGallery, room.title);'));
  assert.ok(showroomJs.includes('window.SevillaShowroomPhotoGallery?.close();'));
});

test('the loading fallback opens and operates the same existing gallery controls', () => {
  const f = runGallery();
  const { elements: el, window, events } = f;
  assert.equal(el['gallery-title'].textContent, 'Garden Hall Gallery');
  assert.equal(el['current-slide-img'].src, 'https://example.test/uploads/garden-1.jpg');
  assert.equal(el['gallery-counter'].textContent, '1 / 2');
  assert.equal(el['thumbnail-strip'].children.length, 2);
  assert.equal(el['btn-view-photos'].disabled, false);

  el['pano-loading-photos'].click();
  assert.equal(el['showroom-wrapper'].classList.contains('mode-photos'), true);
  assert.equal(f.document.body.classList.contains('no-scroll'), true);
  assert.equal(el['btn-back-to-360'].focused, true);

  el['slide-next'].click();
  assert.equal(el['current-slide-img'].src, 'https://example.test/uploads/garden-2.jpg');
  assert.equal(el['gallery-counter'].textContent, '2 / 2');
  assert.equal(el['thumbnail-strip'].children[1].getAttribute('aria-current'), 'true');
  assert.equal(el['thumbnail-strip'].children[1].children[0].classList.contains('active'), true);
  assert.equal(el['slide-next'].handlers.click.length, 1, 'a single gallery controller owns navigation');
  assert.equal(events.some(event => event.type === 'SevillaShowroomPhotoChanged'), true);

  el['btn-back-to-360-gallery'].click();
  assert.equal(el['showroom-wrapper'].classList.contains('mode-photos'), false);
  assert.equal(f.document.body.classList.contains('no-scroll'), false);
  assert.equal(el['btn-view-photos'].focused, true);

  el['btn-view-photos'].click();
  assert.equal(el['showroom-wrapper'].classList.contains('mode-photos'), true);
  el['btn-back-to-360'].click();
  assert.equal(typeof window.SevillaShowroomPhotoGallery.close, 'function');
});

test('main showroom hands current room media to the existing gallery and fallback remains recoverable', () => {
  const f = runGallery();
  f.elements['pano-loading-photos'].click();
  f.window.SevillaShowroomPhotoGallery.setGallery('venue-a', ['/uploads/new-villa.jpg'], 'Villa');
  assert.equal(f.elements['showroom-wrapper'].classList.contains('mode-photos'), true, 'handoff preserves an already-open photo view');
  assert.equal(f.elements['gallery-title'].textContent, 'Villa Gallery');
  assert.equal(f.elements['current-slide-img'].src, 'https://example.test/uploads/new-villa.jpg');
  assert.equal(f.elements['gallery-counter'].textContent, '1 / 1');
  f.window.SevillaShowroomPhotoGallery.setGallery('venue-a', ['javascript:alert(1)'], 'Villa');
  assert.equal(f.elements['current-slide-img'].src, 'https://example.test/assets/img/placeholder.jpg');
  assert.equal(f.elements['btn-view-photos'].disabled, true);
});

test('boot retry and dependency failure feedback remain available independently of photo mode', () => {
  const f = runGallery();
  let reloaded = false;
  f.window.location.reload = () => { reloaded = true; };
  f.elements['pano-loading-retry'].click();
  assert.equal(reloaded, true);
  f.window.showroomShowBootError('Three.js');
  assert.equal(f.elements['pano-loading-overlay'].style.display, 'flex');
  assert.equal(f.elements['pano-loading-overlay'].classList.contains('is-error'), true);
  assert.match(f.elements['pano-loading-message'].textContent, /Three\.js did not load/);
});

test('malformed URL hash does not prevent boot gallery initialization', () => {
  const f = runGallery();
  f.window.location.hash = '#%E0%A4%A';
  assert.doesNotThrow(() => vm.runInNewContext(galleryJs, {
    window: f.window, document: f.document, URL, URLSearchParams,
    CustomEvent: class CustomEvent { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } }
  }));
});

test('photo mode hides the loading overlay while retaining responsive existing gallery controls', () => {
  assert.match(showroomCss, /\.showroom-wrapper\.mode-photos #pano-loading-overlay\s*\{\s*display:\s*none !important/);
  assert.match(showroomCss, /\.showroom-wrapper\.mode-photos \.slider-image-container/);
  assert.match(showroomCss, /\.showroom-wrapper\.mode-photos \.thumbnail-strip/);
  assert.match(showroomPhp, /onerror="window\.showroomShowBootError\?\.\('Three\.js'\)"/);
  assert.match(showroomPhp, /onerror="window\.showroomShowBootError\?\.\('Panolens'\)"/);
});
