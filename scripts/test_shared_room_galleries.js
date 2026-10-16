#!/usr/bin/env node
/* Focused runtime checks for canonical room presentations and exact booking options. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = vm.createContext({
  Date,
  Intl,
  URLSearchParams,
  document: { addEventListener() {} },
  window: {}
});
const showroomSource = fs.readFileSync(path.join(__dirname, '..', 'assets/js/showroom.js'), 'utf8');
vm.runInContext(`${showroomSource}\nthis.buildRoomBookingUrl = showroomBuildBookingUrl;`, context);

const activeRoomId = 'hotel_abelardo_family';
const activeVariant = { room_group_id: 11, variant_key: 'active-variant' };
const recommendedRoom = {
  id: 'recommended-group-28',
  category: 'Hotel Room',
  room_group_id: 28,
  variant_key: 'recommended-variant',
  venue_name: 'Abelardo',
  room_type: 'Family Room / Superior',
  room_type_code: 'family_room_superior'
};
const recommendedUrl = new URL(context.buildRoomBookingUrl(
  recommendedRoom, '2026-11-02', '2026-11-05', '7-8', 8, activeRoomId, activeVariant
), 'http://localhost/');
assert.equal(recommendedUrl.searchParams.get('room_group_id'), '28', 'a recommendation keeps its exact group even when another showroom room is active');
assert.equal(recommendedUrl.searchParams.get('variant_key'), 'recommended-variant');
assert.equal(recommendedUrl.searchParams.get('check_in'), '2026-11-02');
assert.equal(recommendedUrl.searchParams.get('check_out'), '2026-11-05');
assert.equal(recommendedUrl.searchParams.get('guest_range'), '7-8');
assert.equal(recommendedUrl.searchParams.get('guest_count'), '8');
assert.equal(recommendedUrl.searchParams.has('start_date'), false, 'exact group links use the hotel stay keys');

const selectedUrl = new URL(context.buildRoomBookingUrl(
  { ...recommendedRoom, id: activeRoomId, room_group_id: undefined },
  null,
  null,
  null,
  null,
  activeRoomId,
  activeVariant
), 'http://localhost/');
assert.equal(selectedUrl.searchParams.get('room_group_id'), '11', 'the showroom selector books its active exact variant');

const undatedUrl = new URL(context.buildRoomBookingUrl(
  recommendedRoom, null, null, '7-8', 8, activeRoomId, activeVariant
), 'http://localhost/');
assert.equal(undatedUrl.searchParams.get('room_group_id'), '28', 'undated recommendations keep their exact group');
assert.equal(undatedUrl.searchParams.get('guest_range'), '7-8');
assert.equal(undatedUrl.searchParams.get('guest_count'), '8');
assert.equal(undatedUrl.searchParams.has('check_in'), false, 'undated links omit both stay dates');
assert.equal(undatedUrl.searchParams.has('check_out'), false, 'undated links omit both stay dates');

const genericRoom = {
  id: 'hotel_kristel_dormitory',
  category: 'Hotel Room',
  venue_name: 'Kristel',
  room_type: 'Dormitory Room',
  room_type_code: 'dormitory_room'
};
const genericUrl = new URL(context.buildRoomBookingUrl(genericRoom, '2026-11-02', '2026-11-03'), 'http://localhost/');
assert.equal(genericUrl.searchParams.has('room_group_id'), false, 'a generic type route does not invent a group choice');
assert.equal(genericUrl.searchParams.get('venue_name'), 'Kristel');
assert.equal(genericUrl.searchParams.get('room_type_code'), 'dormitory_room');
assert.equal(genericUrl.searchParams.get('start_date'), '2026-11-02');
assert.equal(genericUrl.searchParams.get('end_date'), '2026-11-03');

console.log('PASS|exact recommendation and selected variant handoffs keep group IDs; generic type links remain unselected');
