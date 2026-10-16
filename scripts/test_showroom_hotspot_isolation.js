#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const showroomSource = fs.readFileSync(path.join(root, 'assets/js/showroom.js'), 'utf8');
const helperSource = fs.readFileSync(path.join(root, 'assets/js/hotspot-material.js'), 'utf8');
const attachStart = showroomSource.indexOf('  function attachHotspots(');
const attachEnd = showroomSource.indexOf('  function showHotspotInfoModal(', attachStart);
assert.notEqual(attachStart, -1, 'showroom hotspot attachment function exists');
assert.notEqual(attachEnd, -1, 'showroom hotspot attachment function ends before the info modal helper');

class FakeVector3 {
    constructor(x = 0, y = 0, z = 0) { Object.assign(this, { x, y, z }); }
    copy(other) { Object.assign(this, { x: other.x, y: other.y, z: other.z }); return this; }
}

class FakeTexture {
    constructor() { this.needsUpdate = false; this.disposed = false; }
    clone() { return new FakeTexture(); }
    dispose() { this.disposed = true; }
}

class FakeInfospot {
    constructor() {
        this.position = new FakeVector3();
        this.material = { opacity: 1, visible: true, map: null };
        this.userData = {};
        this.visible = true;
        this.raycastEnabled = true;
        this.listeners = new Map();
        this.hidePending = false;
    }
    addEventListener(type, callback) {
        const callbacks = this.listeners.get(type) || [];
        callbacks.push(callback);
        this.listeners.set(type, callbacks);
    }
    emit(type) { for (const callback of this.listeners.get(type) || []) callback(); }
    addHoverText() {}
    enableRaycast(enabled = true) { this.raycastEnabled = Boolean(enabled); }
    show() {
        this.hidePending = false;
        this.enableRaycast(true);
        this.material.opacity = 1;
    }
    hide() {
        this.hidePending = true;
        this.enableRaycast(false);
    }
    finishHide() {
        this.hidePending = false;
        this.material.opacity = 0;
    }
}

class FakePanorama {
    constructor() { this.children = []; this.listeners = new Map(); }
    add(spot) { this.children.push(spot); spot.parent = this; }
    addEventListener(type, callback) {
        const callbacks = this.listeners.get(type) || [];
        callbacks.push(callback);
        this.listeners.set(type, callbacks);
    }
    emit(type) { for (const callback of this.listeners.get(type) || []) callback(); }
}

const pendingTextureLoads = [];
const THREE = {
    DataTexture: FakeTexture,
    RGBAFormat: 1,
    UnsignedByteType: 1,
    Vector3: FakeVector3,
    TextureLoader: class {
        load(url, onLoad) { pendingTextureLoads.push({ url, onLoad }); }
    }
};
const windowRef = { THREE, PANOLENS: { Infospot: FakeInfospot } };
vm.runInNewContext(helperSource, { window: windowRef }, { filename: 'assets/js/hotspot-material.js' });

const attachSource = showroomSource.slice(attachStart, attachEnd);
let currentPanoIndex = 0;
const viewer = { panorama: null };
let currentRoomId = 'garden-hall';
const navigationAttempts = [];
assert.match(showroomSource, /\(\) => currentRoomId === roomId && currentPanoIndex === index && viewer\.panorama === pano && !room\.panoFailed/,
    'public hotspot actions are scoped to the active panorama');
const attachHotspots = new Function(
    'window', 'THREE', 'PANOLENS', 'resolveHotspotTargetIndex', 'viewerActivity', 'enterPanorama', 'showHotspotInfoModal',
    `let currentPanoIndex = 0;\n${attachSource}\nreturn attachHotspots;`
)(
    windowRef,
    THREE,
    windowRef.PANOLENS,
    (hotspot, room) => room.pano_media_ids.map(Number).indexOf(Number(hotspot.target_media_id)),
    () => {},
    pano => navigationAttempts.push(pano),
    () => {}
);

const room = { pano_urls: ['scene-a.jpg', 'scene-b.jpg'], pano_media_ids: [101, 202] };
const panoramas = [new FakePanorama(), new FakePanorama(), new FakePanorama()];
const gardenPanoramas = panoramas.slice(0, 2);
const otherPanoramas = [panoramas[2]];
const otherRoom = { pano_urls: ['other-room.jpg'], pano_media_ids: [303] };
const hotspotFixtures = [
    [
        { type: 'nav', title: 'Walk to scene B', target_media_id: 202, position_x: 1, position_y: 2, position_z: 3 },
        { type: 'info', title: 'Scene A detail', description: 'A detail', position_x: 3, position_y: 2, position_z: 1 }
    ],
    [
        { type: 'nav', title: 'Walk to scene A', target_media_id: 101, position_x: -1, position_y: 2, position_z: 3 },
        { type: 'info', title: 'Scene B detail', description: 'B detail', position_x: -3, position_y: 2, position_z: 1 }
    ],
    [{ type: 'info', title: 'Other room detail', description: 'Other', position_x: 0, position_y: 2, position_z: -1 }]
];

for (let index = 0; index < panoramas.length; index += 1) {
    const sourceRoom = index < 2 ? room : otherRoom;
    const sourceRoomId = index < 2 ? 'garden-hall' : 'other-room';
    const sourcePanoramas = index < 2 ? gardenPanoramas : otherPanoramas;
    const sourceIndex = index < 2 ? index : 0;
    attachHotspots(
        panoramas[index],
        hotspotFixtures[index],
        viewer,
        sourcePanoramas,
        sourceRoom,
        () => currentRoomId === sourceRoomId && currentPanoIndex === sourceIndex && viewer.panorama === panoramas[index],
        sourceIndex
    );
}

async function run() {
    const spots = panoramas.map(panorama => panorama.children);
    assert.deepEqual(spots.map(sceneSpots => sceneSpots.length), [2, 2, 1], 'each source panorama keeps its own pins exactly once');
    assert.ok(spots.flat().every(spot => spot.material.opacity === 0 && !spot.raycastEnabled), 'new pins start invisible and cannot be hit before their scene is entered');

    currentPanoIndex = 0;
    viewer.panorama = panoramas[0];
    panoramas[0].emit('enter');
    assert.ok(spots[0].every(spot => spot.material.opacity === 1 && spot.raycastEnabled), 'enter shows and enables the active scene pins');
    assert.ok(spots[1].every(spot => spot.material.opacity === 0 && !spot.raycastEnabled), 'enter leaves every other scene hidden');

    panoramas[0].emit('leave');
    const opacityDuringFade = spots[0].map(spot => spot.material.opacity);
    currentPanoIndex = 1;
    viewer.panorama = panoramas[1];
    panoramas[1].emit('enter');
    assert.ok(spots[1].every(spot => spot.material.opacity === 1 && spot.raycastEnabled), 'switching scenes shows that scene pins');
    assert.ok(spots[0].every(spot => spot.userData.hotspotHidden && !spot.raycastEnabled), 'switching scenes immediately disables old pins');

    const staleNavigation = spots[0][0];
    staleNavigation.emit('click');
    assert.equal(navigationAttempts.length, 0, 'a stale pin callback cannot navigate after the active panorama changes');

    // Complete the shared helper texture requests after scene A has been left.
    for (const request of pendingTextureLoads) request.onLoad(new FakeTexture());
    await Promise.resolve();
    assert.deepEqual(spots[0].map(spot => spot.material.opacity), opacityDuringFade, 'late texture callbacks do not interrupt the old scene fade');
    assert.ok(spots[0].every(spot => spot.hidePending && !spot.raycastEnabled), 'late texture callbacks do not re-enable old pins during the exit tween');
    assert.ok(spots[1].every(spot => spot.material.opacity === 1 && spot.raycastEnabled), 'late texture callbacks preserve pins for the active scene');

    spots[0].forEach(spot => spot.finishHide());
    assert.ok(spots[0].every(spot => spot.material.opacity === 0), 'the exit tween completes with all old pins hidden');

    panoramas[1].emit('leave');
    currentRoomId = 'other-room';
    currentPanoIndex = 0;
    viewer.panorama = panoramas[2];
    panoramas[2].emit('enter');
    assert.equal(spots[2][0].material.opacity, 1, 'entering another room shows only that room pins');
    assert.ok(spots[1].every(spot => spot.userData.hotspotHidden && !spot.raycastEnabled), 'leaving a cached room hides its pins');

    panoramas[2].emit('leave');
    currentRoomId = 'garden-hall';
    currentPanoIndex = 0;
    viewer.panorama = panoramas[0];
    panoramas[0].emit('enter');
    assert.ok(spots[0].every(spot => spot.material.opacity === 1 && spot.raycastEnabled), 're-entering a cached scene restores its pins');
    assert.ok(spots[2].every(spot => spot.userData.hotspotHidden && !spot.raycastEnabled), 're-entering a cached room does not expose its former pins');
    assert.deepEqual(spots.map(sceneSpots => sceneSpots.length), [2, 2, 1], 'repeated scene and room switches do not duplicate pin objects');

    console.log('Showroom hotspot isolation checks passed.');
}

run().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
