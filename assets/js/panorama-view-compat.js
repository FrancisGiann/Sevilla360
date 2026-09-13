/*
 * Keep saved panorama directions in their captured world-space convention.
 * Panolens 0.12.1's tweenControlCenter mirrors X internally, so only its
 * fallback input needs a cloned, pre-negated X coordinate. The direct
 * setControlCenter API consumes the unmodified world-space vector.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.PanoramaViewCompat = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function toPanolensTweenCenter(worldCenter) {
        if (!worldCenter || typeof worldCenter.clone !== 'function' ||
            ![worldCenter.x, worldCenter.y, worldCenter.z].every(Number.isFinite)) return null;
        const tweenCenter = worldCenter.clone();
        tweenCenter.x *= -1;
        return tweenCenter;
    }

    function applyControlCenter(viewer, worldCenter, duration) {
        if (!viewer || !worldCenter) return false;
        if (typeof viewer.setControlCenter === 'function') {
            viewer.setControlCenter(worldCenter);
            return 'set';
        }
        if (typeof viewer.tweenControlCenter === 'function') {
            const tweenCenter = toPanolensTweenCenter(worldCenter);
            if (!tweenCenter) return false;
            viewer.tweenControlCenter(tweenCenter, duration);
            return 'tween';
        }
        return false;
    }

    return { toPanolensTweenCenter, applyControlCenter };
});
