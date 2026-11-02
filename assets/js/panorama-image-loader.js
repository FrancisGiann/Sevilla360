/* Keep pending Panolens subscribers separate from decoded images. A large
 * panorama may download for longer than twenty seconds: time out stalled
 * transfers, rather than cancelling one that is still making progress. */
(function (global) {
    'use strict';
    const loader = global.PANOLENS?.ImageLoader;
    if (!loader || loader.sevillaDecodedImages) return;
    const requests = new Map();
    const completed = new Map();
    const idleTimeout = 20000;
    const maximumTimeout = 120000;
    loader.sevillaDecodedImages = true;
    loader.load = function (url, onLoad = () => {}, onProgress = () => {}, onError = () => {}) {
        const key = `${this.crossOrigin ?? 'anonymous'}:${url}`;
        const subscriber = {
            onLoad: typeof onLoad === 'function' ? onLoad : () => {},
            onProgress: typeof onProgress === 'function' ? onProgress : () => {},
            onError: typeof onError === 'function' ? onError : () => {}
        };
        const cached = completed.get(key);
        if (cached) {
            global.setTimeout(() => {
                subscriber.onProgress({ loaded: 1, total: 1 });
                subscriber.onLoad(cached);
            }, 0);
            return cached;
        }
        const pending = requests.get(key);
        if (pending) {
            pending.subscribers.push(subscriber);
            return pending.image;
        }
        const image = new global.Image();
        const request = { image, subscribers: [subscriber] };
        requests.set(key, request);
        let settled = false;
        let idleTimer;
        let maximumTimer;
        let transport;
        let objectUrl;
        let receivedBytes = 0;
        const startedAt = global.performance?.now?.() ?? 0;
        const responseHasCompleted = () => {
            if (!global.performance?.getEntriesByName || !global.location) return false;
            try {
                const absoluteUrl = new global.URL(url, global.location.href).href;
                return global.performance.getEntriesByName(absoluteUrl, 'resource').some(entry =>
                    entry.startTime >= startedAt && entry.responseEnd >= startedAt && entry.encodedBodySize > 0);
            } catch (_) { return false; }
        };
        const finish = error => {
            if (settled) return;
            settled = true;
            global.clearTimeout(idleTimer);
            global.clearTimeout(maximumTimer);
            image.onload = image.onerror = null;
            if (transport) {
                transport.onload = transport.onerror = transport.onabort = transport.onprogress = null;
                if (error) transport.abort();
            }
            if (objectUrl) global.URL.revokeObjectURL(objectUrl);
            requests.delete(key);
            if (!error) completed.set(key, image);
            else image.src = '';
            request.subscribers.forEach(callbacks => global.setTimeout(() => {
                if (error) callbacks.onError(error);
                else { callbacks.onProgress({ loaded: 1, total: 1 }); callbacks.onLoad(image); }
            }, 0));
        };
        const armIdleTimer = () => {
            global.clearTimeout(idleTimer);
            idleTimer = global.setTimeout(() => {
                // A background/busy page can deliver XHR events after timers even
                // when Resource Timing already confirms the bytes arrived. Keep
                // waiting for real decode; the overall deadline still applies.
                if (!transport) { finish(new Error(`Panorama image stalled: ${url}`)); return; }
                // Give already queued network tasks a turn to publish their
                // completion before aborting. Resource Timing may itself still
                // be waiting in that queue when the first timer fires.
                idleTimer = global.setTimeout(() => {
                    if (settled) return;
                    if (responseHasCompleted()) { armIdleTimer(); return; }
                    finish(new Error(`Panorama image stalled: ${url}`));
                }, 1000);
            }, idleTimeout);
        };
        image.onload = () => finish(image.naturalWidth > 0 && image.naturalHeight > 0
            ? null : new Error(`Invalid panorama image: ${url}`));
        image.onerror = () => finish(new Error(`Unable to decode panorama image: ${url}`));
        armIdleTimer();
        maximumTimer = global.setTimeout(() => finish(new Error(`Panorama image timed out: ${url}`)), maximumTimeout);
        if (!String(url).startsWith('data:') && global.XMLHttpRequest && global.URL?.createObjectURL) {
            try {
                transport = new global.XMLHttpRequest();
                transport.open('GET', url, true);
                transport.responseType = 'blob';
                transport.withCredentials = this.crossOrigin === 'use-credentials';
                transport.onprogress = event => {
                    if (settled || event.loaded <= receivedBytes) return;
                    receivedBytes = event.loaded;
                    armIdleTimer();
                    const progress = { loaded: event.loaded, total: event.lengthComputable ? event.total : 0 };
                    request.subscribers.forEach(callbacks => global.setTimeout(() => callbacks.onProgress(progress), 0));
                };
                transport.onload = () => {
                    if (settled) return;
                    if (transport.status < 200 || transport.status >= 300 || !transport.response) {
                        finish(new Error(`Unable to load panorama image (${transport.status}): ${url}`));
                        return;
                    }
                    armIdleTimer();
                    try {
                        objectUrl = global.URL.createObjectURL(transport.response);
                        image.src = objectUrl;
                    } catch (error) { finish(error); }
                };
                transport.onerror = transport.onabort = () => finish(new Error(`Unable to load panorama image: ${url}`));
                transport.send();
            } catch (error) { finish(error); }
        } else {
            if (!String(url).startsWith('data:')) image.crossOrigin = this.crossOrigin ?? 'anonymous';
            image.src = url;
        }
        return image;
    };
})(window);
