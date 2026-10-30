/* Panolens 0.12.1 caches unfinished images in THREE.Cache. Its cache-hit
 * callback can therefore upload an empty texture. Keep pending subscribers
 * separate from decoded images, and propagate decode/network failures. */
(function (global) {
    'use strict';
    const loader = global.PANOLENS?.ImageLoader;
    if (!loader || loader.sevillaDecodedImages) return;
    const requests = new Map();
    const completed = new Map();
    loader.sevillaDecodedImages = true;
    loader.load = function (url, onLoad = () => {}, onProgress = () => {}, onError = () => {}) {
        const key = `${this.crossOrigin ?? 'anonymous'}:${url}`;
        const cached = completed.get(key);
        if (cached) {
            global.setTimeout(() => { onProgress({ loaded: 1, total: 1 }); onLoad(cached); }, 0);
            return cached;
        }
        const subscriber = { onLoad, onProgress, onError };
        const pending = requests.get(key);
        if (pending) {
            pending.subscribers.push(subscriber);
            return pending.image;
        }
        const image = new global.Image();
        const request = { image, subscribers: [subscriber] };
        requests.set(key, request);
        let settled = false;
        let timer;
        const finish = error => {
            if (settled) return;
            settled = true;
            global.clearTimeout(timer);
            image.onload = image.onerror = null;
            requests.delete(key);
            if (!error) completed.set(key, image);
            // Deliver separately so one consumer's exception cannot prevent
            // another panorama or icon from receiving its result.
            request.subscribers.forEach(callbacks => global.setTimeout(() => {
                if (error) callbacks.onError(error);
                else { callbacks.onProgress({ loaded: 1, total: 1 }); callbacks.onLoad(image); }
            }, 0));
        };
        image.onload = () => finish(image.naturalWidth > 0 && image.naturalHeight > 0
            ? null : new Error(`Invalid panorama image: ${url}`));
        image.onerror = () => finish(new Error(`Unable to load panorama image: ${url}`));
        timer = global.setTimeout(() => {
            finish(new Error(`Panorama image timed out: ${url}`));
            image.src = '';
        }, 20000);
        if (!String(url).startsWith('data:')) image.crossOrigin = this.crossOrigin ?? 'anonymous';
        image.src = url;
        return image;
    };
})(window);
