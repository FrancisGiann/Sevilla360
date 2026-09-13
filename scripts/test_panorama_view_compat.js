'use strict';

const assert = require('node:assert/strict');
const { applyControlCenter, toPanolensTweenCenter } = require('../assets/js/panorama-view-compat.js');

class Vector3 {
    constructor(x, y, z) { Object.assign(this, { x, y, z }); }
    clone() { return new Vector3(this.x, this.y, this.z); }
}

const captured = new Vector3(-4818.54, -1314.8, -32.2175);
const tweenInput = toPanolensTweenCenter(captured);
assert.deepEqual(tweenInput, new Vector3(4818.54, -1314.8, -32.2175));
assert.notEqual(tweenInput, captured, 'Panolens fallback must not mutate the stored/captured vector');
assert.deepEqual(captured, new Vector3(-4818.54, -1314.8, -32.2175));

let tweenCall = null;
const tweenViewer = { tweenControlCenter: (point, duration) => { tweenCall = { point, duration }; } };
assert.equal(applyControlCenter(tweenViewer, captured, 650), 'tween');
assert.deepEqual(tweenCall, { point: new Vector3(4818.54, -1314.8, -32.2175), duration: 650 });

let directCenter = null;
const directViewer = { setControlCenter: point => { directCenter = point; } };
assert.equal(applyControlCenter(directViewer, captured, 0), 'set');
assert.equal(directCenter, captured, 'direct setter must receive the unmodified captured world-space vector');

console.log('PASS|Panolens tween fallback negates only cloned X while direct setter receives raw world coordinates');
