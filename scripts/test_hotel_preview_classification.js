#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/admin-page/admin_cms.js'), 'utf8');
const lightboxStart = source.indexOf('    function cmsLightboxSource(');
const lightboxEnd = source.indexOf('    const cmsPreviewContexts', lightboxStart);
if (lightboxStart < 0 || lightboxEnd < 0) throw new Error('Could not locate the CMS lightbox source guard.');
const lightboxContext = {};
vm.runInNewContext(`${source.slice(lightboxStart, lightboxEnd)}\nglobalThis.cmsLightboxSourceForTest = cmsLightboxSource;`, lightboxContext);
function assert(condition, message) {
    if (!condition) throw new Error(message);
    console.log(`PASS|${message}`);
}
assert(lightboxContext.cmsLightboxSourceForTest({ dataset: { sourceAvailable: 'false' }, src: 'placeholder.jpg' }) === null,
    'known-missing management thumbnails are inert instead of opening an error alert');
assert(lightboxContext.cmsLightboxSourceForTest({ dataset: { sourceAvailable: 'true', originalSrc: 'assets/uploads/photo.jpg' }, src: 'thumbnail.webp' }) === 'assets/uploads/photo.jpg',
    'readable management thumbnails still open their original image in the lightbox');
assert(source.includes('const lightboxSource = cmsLightboxSource(e.target);')
    && !source.slice(source.indexOf('const lightboxSource = cmsLightboxSource(e.target);'), source.indexOf('// SET PRIMARY (STAR)')).includes('showAlert'),
    'missing-image click handling returns quietly before the primary-image controls');

const start = source.indexOf('  function renderHotelPreview(');
const end = source.indexOf('  function makeHotelAction(', start);
if (start < 0 || end < 0) throw new Error('Could not locate the hotel preview renderer.');
const rendererSource = source.slice(start, end);

class Element {
    constructor(tagName) {
        this.tagName = tagName;
        this.children = [];
        this.dataset = {};
        this.attributes = {};
        this.className = '';
        this.textContent = '';
    }
    replaceChildren(...children) { this.children = [...children]; }
    appendChild(child) { this.children.push(child); return child; }
    setAttribute(name, value) { this.attributes[name] = value; }
    querySelectorAll(selector) {
        return selector === 'img[data-cms-preview-id]'
            ? this.children.filter(child => child.tagName === 'img' && child.dataset.cmsPreviewId)
            : [];
    }
}

const observed = [];
const context = {
    window: {
        galleryData: {
            shared_photos: [{ id: 101, is_primary: 1, source_available: true }],
            shared_tour: [{ id: 202, is_primary: 1, source_available: true }, { id: 203, source_available: true }],
        },
    },
    document: { createElement: tagName => new Element(tagName) },
    cmsCardPreviewSources: () => null,
    hotelPreviewSizes: '(max-width: 700px) 100vw, 430px',
    cmsPreviewPath: photo => `thumbnail-${photo.id}`,
    observeCmsPreviewImage: image => observed.push(image.dataset.cmsPreviewId),
    activeHotelTab: 'photos',
};
vm.runInNewContext(`${rendererSource}\nglobalThis.renderHotelPreviewForTest = renderHotelPreview;`, context);

const tourParent = new Element('div');
context.renderHotelPreviewForTest('shared_tour', tourParent, '', 'tour');
const tourStrip = tourParent.children[0];
assert(tourStrip.className.endsWith('--tour')
    && tourStrip.attributes['aria-label'] === 'Tour scene previews, 2 scenes',
    'tour workspace keeps tour preview styling and label after photos was the previous editor tab');

context.activeHotelTab = 'tour';
const photosParent = new Element('div');
context.renderHotelPreviewForTest('shared_photos', photosParent, '', 'photos');
const photoStrip = photosParent.children[0];
assert(photoStrip.className.endsWith('--photos')
    && photoStrip.attributes['aria-label'] === 'Photo previews, 1 photos',
    'photo workspace keeps photo preview styling and label after tour was the previous editor tab');

assert(observed.join(',') === '202,203,101', 'both gallery panels observe their own scene and photo previews');
assert(source.includes("renderHotelPreview(activeSlot, preview, '', kind);"),
    'workspace passes its own media kind into the preview renderer');

const queueStart = source.indexOf('    function queueCmsPreviewRequest(');
const queueEnd = source.indexOf('    const cmsPreviewObserver', queueStart);
if (queueStart < 0 || queueEnd < 0) throw new Error('Could not locate the CMS preview request queue.');
const queueContext = { cmsPreviewQueue: [], activeCmsPreviewRequests: 2 };
vm.runInNewContext(`${source.slice(queueStart, queueEnd)}\nglobalThis.queueCmsPreviewForTest = queueCmsPreviewRequest;`, queueContext);
const queuedGridRequest = queueContext.queueCmsPreviewForTest(() => {}, false);
queueContext.queueCmsPreviewForTest(() => {}, true);
assert(queueContext.cmsPreviewQueue.map(job => job.priority).join(',') === '1,0',
    'hotel and manager preview requests jump ahead of queued grid thumbnails');
queuedGridRequest.promote();
assert(queueContext.cmsPreviewQueue.map(job => job.priority).join(',') === '1,1',
    'an already queued grid request is promoted when the same image enters the editor');
assert(source.includes('while (activeCmsPreviewRequests < 2 && cmsPreviewQueue.length)'),
    'preview prioritization preserves the two-request generation limit');
