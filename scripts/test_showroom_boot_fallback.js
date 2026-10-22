const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const showroomPhp = fs.readFileSync(`${__dirname}/../showroom.php`, 'utf8');
const showroomJs = fs.readFileSync(`${__dirname}/../assets/js/showroom.js`, 'utf8');
const showroomCss = fs.readFileSync(`${__dirname}/../assets/css/showroom.css`, 'utf8');

class FakeElement {
  constructor(id = '') {
    this.id = id;
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.attributes = {};
    this.children = [];
    this.handlers = {};
    this.classList = { add: () => {}, toggle: () => {} };
    this.opened = false;
    this.focused = false;
    this.value = '';
    this.src = '';
  }
  addEventListener(type, handler) { this.handlers[type] = handler; }
  appendChild(child) { this.children.push(child); if (!this.value) this.value = child.value; return child; }
  getAttribute(name) { return this.attributes[name] ?? null; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  removeAttribute(name) { delete this.attributes[name]; if (name === 'src') this.src = ''; }
  showModal() { this.opened = true; }
  close() { this.opened = false; this.handlers.close?.({ target: this }); }
  focus() { this.focused = true; }
  click() { this.handlers.click?.({ target: this }); }
}

function runBootFallback() {
  const ids = [
    'pano-loading-retry', 'pano-loading-photos', 'showroom-boot-gallery', 'showroom-boot-gallery-room',
    'showroom-boot-gallery-image', 'showroom-boot-gallery-message', 'showroom-boot-gallery-count',
    'showroom-boot-gallery-prev', 'showroom-boot-gallery-next', 'showroom-boot-gallery-close',
    'pano-loading-overlay', 'pano-loading-message'
  ];
  const elements = Object.fromEntries(ids.map(id => [id, new FakeElement(id)]));
  let reloads = 0;
  const activeRoom = new FakeElement('active-room');
  activeRoom.setAttribute('data-room', 'venue-b');
  const document = {
    baseURI: 'https://example.test/showroom.php',
    getElementById: id => elements[id] || null,
    querySelector: selector => selector === '.dropdown-item.active' ? activeRoom : null,
    createElement: tag => new FakeElement(tag)
  };
  const window = {
    showroomData: {
      'venue-a': { title: 'Unsafe Gallery', gallery: ['javascript:alert(1)'] },
      'venue-b': { title: 'Garden Hall', gallery: ['/uploads/garden-1.jpg', '/uploads/garden-2.jpg'] }
    },
    location: { reload: () => { reloads += 1; } }
  };
  const scriptTag = showroomPhp.lastIndexOf('<script>', showroomPhp.indexOf('const reloadButton'));
  const start = showroomPhp.indexOf('(() => {', scriptTag);
  const close = showroomPhp.indexOf('})();\n</script>', start);
  assert.ok(start >= 0 && close > start, 'boot fallback binding is present inline before external viewer scripts');
  vm.runInNewContext(showroomPhp.slice(start, close + 5), { window, document, URL });
  return { elements, reloads: () => reloads, document };
}

test('showroom markup renders and wires recovery controls before CDN parser-blocking requests', () => {
  const mainEnd = showroomPhp.indexOf('</main>');
  const inlineBoot = showroomPhp.indexOf('const reloadButton');
  const threeScript = showroomPhp.indexOf('https://cdnjs.cloudflare.com/ajax/libs/three.js/105/three.min.js');
  const panoScript = showroomPhp.indexOf('https://cdn.jsdelivr.net/npm/panolens@0.12.1/build/panolens.min.js');
  assert.ok(mainEnd >= 0 && mainEnd < inlineBoot && inlineBoot < threeScript && threeScript < panoScript);
  assert.match(showroomPhp, /id="pano-loading-overlay" style="display:flex;"/);
  assert.match(showroomPhp, /id="pano-loading-retry">Reload showroom/);
  assert.match(showroomPhp, /id="pano-loading-photos">Browse venue photos/);
  assert.ok(showroomJs.includes("if (!viewer) {\n        showStandardFallback(room);\n        updatePhotoButtonStatus();\n        return;"));
  assert.ok(showroomJs.includes('window.showroomShowBootError?.(\'The 360° viewer\')'));
});

test('boot gallery starts lazily on the current venue and cycles photos without unsafe URLs', () => {
  const f = runBootFallback();
  const { elements: el } = f;
  assert.equal(el['showroom-boot-gallery'].opened, false);
  assert.equal(el['showroom-boot-gallery-image'].src, '');
  assert.equal(el['showroom-boot-gallery-room'].value, 'venue-b');
  assert.equal(el['pano-loading-photos'].hidden, false);

  el['pano-loading-photos'].click();
  assert.equal(el['showroom-boot-gallery'].opened, true);
  assert.equal(el['showroom-boot-gallery-image'].src, 'https://example.test/uploads/garden-1.jpg');
  assert.equal(el['showroom-boot-gallery-close'].focused, true);
  el['showroom-boot-gallery-next'].click();
  assert.equal(el['showroom-boot-gallery-image'].src, 'https://example.test/uploads/garden-2.jpg');
  assert.equal(el['showroom-boot-gallery-count'].textContent, '2 / 2');
  el['showroom-boot-gallery-room'].value = 'venue-a';
  el['showroom-boot-gallery-room'].handlers.change();
  assert.equal(el['showroom-boot-gallery-image'].src, '');
  assert.match(el['showroom-boot-gallery-message'].textContent, /could not be opened/);
  el['showroom-boot-gallery'].close();
  assert.equal(el['pano-loading-photos'].focused, true);
  el['pano-loading-retry'].onclick();
  assert.equal(f.reloads(), 1);
});

test('boot gallery actions remain reachable at narrow widths and report failed CDN loads', () => {
  assert.match(showroomCss, /#pano-loading-photos \{ pointer-events:auto/);
  assert.match(showroomCss, /\.showroom-boot-gallery \{[\s\S]*?width: min\(46rem, calc\(100vw - 2rem\)\)/);
  assert.match(showroomPhp, /onerror="window\.showroomShowBootError\?\.\('Three\.js'\)"/);
  assert.match(showroomPhp, /onerror="window\.showroomShowBootError\?\.\('Panolens'\)"/);
});
