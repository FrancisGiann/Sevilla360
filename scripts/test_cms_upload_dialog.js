const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/admin-page/admin_cms.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '../includes/admin-page/admin_cms.php'), 'utf8');
const revoked = [];
const created = [];

function element(tagName = 'div') {
  return {
    tagName,
    children: [],
    attributes: {},
    handlers: {},
    style: {},
    hidden: false,
    value: '',
    isConnected: true,
    getClientRects: () => [{}],
    focus() { document.activeElement = this; },
    addEventListener(name, handler) { this.handlers[name] = handler; },
    append(...nodes) { this.children.push(...nodes); },
    appendChild(node) { this.children.push(node); return node; },
    replaceChildren(...nodes) { this.children = [...nodes]; },
    setAttribute(name, value) { this.attributes[name] = String(value); },
    removeAttribute(name) { delete this.attributes[name]; if (name === 'src') delete this.src; },
    get textContent() { return this._textContent || this.children.map(child => child.textContent || '').join(''); },
    set textContent(value) { this._textContent = String(value); this.children = []; },
  };
}

const dropText = element('p');
const previewList = element('div');
const dragDropArea = { querySelector: selector => selector === '.drop-text' ? dropText : null, handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; } };
const fileInputEl = { value: '', files: [], handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; }, click() {} };
const document = {
  body: { style: { overflow: 'scroll' } },
  activeElement: null,
  createElement: tag => element(tag),
  createTextNode: text => ({ textContent: String(text) }),
  getElementById: id => id === 'upload-file-previews' ? previewList : id === 'fileInput' ? fileInputEl : null,
};
const objectUrl = {
  createObjectURL(file) {
    if (file.failPreview) throw new Error('synthetic preview failure');
    const url = `blob:preview-${created.length}`;
    created.push({ file, url });
    return url;
  },
  revokeObjectURL(url) { revoked.push(url); },
};
const window = { URL: objectUrl, requestAnimationFrame: callback => callback() };
const context = vm.createContext({ document, window, dragDropArea, fileInputEl, uploadPreviewList: previewList });

const previewStart = source.indexOf('  const uploadPreviewList =');
const previewEnd = source.indexOf('  let uploadReturnFocus = null;', previewStart);
assert(previewStart > 0 && previewEnd > previewStart, 'upload preview logic is present');
vm.runInContext(source.slice(previewStart, previewEnd), context);

const dangerousName = '<img src=x onerror=alert(1)>.jpg';
const files = [{ name: dangerousName, size: 2048 }, { name: 'panorama.webp', size: 2 * 1024 * 1024 }];
context.files = files;
vm.runInContext('renderUploadPreviews(files)', context);
assert.equal(previewList.children.length, 2, 'every selected file gets a preview row');
assert.equal(previewList.hidden, false);
assert.equal(previewList.children[0].children[1].children[0].textContent, dangerousName, 'filename is rendered as text, not markup');
assert.match(previewList.children[0].children[0].children[0].src, /^blob:/, 'standard image gets a local object URL preview');
assert.match(previewList.children[1].children[0].children[0].src, /^blob:/, 'panorama gets a local object URL preview');
assert.equal(previewList.children[1].children[1].children[1].textContent, '2.0 MB');

const failedImage = previewList.children[0].children[0].children[0];
const unavailable = previewList.children[0].children[0].children[1];
failedImage.handlers.error();
assert.equal(failedImage.hidden, true, 'failed image is hidden');
assert.equal(unavailable.hidden, false, 'failed image receives a readable fallback');

context.files = [{ name: 'replacement.png', size: 0, failPreview: true }];
vm.runInContext('renderUploadPreviews(files)', context);
assert.deepEqual(revoked, created.slice(0, 2).map(item => item.url), 'replacing files revokes all prior object URLs');
assert.equal(previewList.children.length, 1);
assert.equal(previewList.children[0].children[0].children[1].hidden, false, 'object URL failures receive a fallback');

fileInputEl.value = 'selected';
vm.runInContext('clearUploadSelection()', context);
assert.equal(fileInputEl.value, '', 'reset clears the native file selection');
assert.equal(previewList.children.length, 0);
assert.equal(previewList.hidden, true);
assert.equal(revoked.length, created.length, 'reset revokes the remaining object URL');

const modalStart = source.indexOf('  let uploadReturnFocus = null;');
const modalEnd = source.indexOf('  // Progress Bar Elements', modalStart);
assert(modalStart > 0 && modalEnd > modalStart);
const control = () => ({ isConnected: true, handlers: {}, getClientRects: () => [{}], focus() { document.activeElement = this; }, addEventListener(name, handler) { this.handlers[name] = handler; } });
const invoker = control();
const picker = control();
const typeDropdown = control();
const slot = control();
const cancel = control();
const submit = control();
document.activeElement = invoker;
const active = new Set();
let chooserClicks = 0;
const uploadModal = { classList: { contains: key => active.has(key), add: key => active.add(key), remove: key => active.delete(key) }, handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; }, querySelectorAll: () => [picker, typeDropdown, slot, cancel, submit] };
context.uploadModal = uploadModal;
context.btnCloseModal = null;
context.btnOpenUpload = null;
context.typeDropdown = typeDropdown;
context.slotOptions = [];
context.fileInputEl = { value: '', click() { chooserClicks++; } };
context.dragDropArea = { querySelector: () => null, addEventListener(name, handler) { picker.handlers[name] = handler; } };
vm.runInContext(source.slice(modalStart, modalEnd), context);
vm.runInContext('openUploadModal()', context);
assert.equal(document.activeElement, typeDropdown);
assert.equal(document.body.style.overflow, 'hidden');
document.activeElement = picker;
uploadModal.handlers.keydown({ key: 'Tab', shiftKey: true, preventDefault() {} });
assert.equal(document.activeElement, submit);
uploadModal.handlers.keydown({ key: 'Tab', preventDefault() {} });
assert.equal(document.activeElement, picker);
uploadModal.handlers.keydown({ key: 'Escape', preventDefault() {}, stopPropagation() {} });
assert.equal(active.has('active'), false);
assert.equal(document.activeElement, invoker);
assert.equal(document.body.style.overflow, 'scroll');
picker.handlers.keydown({ key: 'Enter', preventDefault() {} });
assert.equal(chooserClicks, 1);

assert.match(php, /id="uploadModal" role="dialog" aria-modal="true" aria-labelledby="cms-upload-title"/);
assert.match(php, /id="upload-file-previews" role="list"/);
assert.match(php, /<label for="modal-media-type">/);
assert.match(php, /<label for="modal-website-slot">/);
console.log('PASS CMS upload: per-file/panorama previews, safe filenames, image/URL failure fallback, URL revocation, native reset, dialog focus and scroll-lock behavior');
