'use strict';

const assert = require('node:assert/strict');
const { suggestRoomSelection } = require('../assets/js/admin-page/admin_seminars.js');

const checks = [];
function check(name, run) {
  run();
  checks.push(name);
  console.log(`PASS ${name}`);
}

check('keeps manual available selections and only adds positive-capacity available rooms', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, max_capacity: 2 },
    { venue_id: 2, available: 1, max_capacity: 2 },
    { venue_id: 3, available: 0, max_capacity: 20 },
    { venue_id: 4, available: 1, max_capacity: 0 },
  ], [1, 3, 4], { people: 4, female: 4, male: 0 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [2]);
  assert.deepEqual(result.selectedIds, [1, 2, 4]);
  assert.equal(result.capacity, 4);
});

check('reports a total-bed inventory shortage without adding every room', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, max_capacity: 4 },
    { venue_id: 2, available: 1, max_capacity: 3 },
  ], [], { people: 8, female: 4, male: 4 });
  assert.equal(result.success, false);
  assert.equal(result.reason, 'total_capacity');
  assert.equal(result.inventoryShortfall, 1);
  assert.deepEqual(result.addedIds, []);
  assert.deepEqual(result.selectedIds, []);
});

check('detects gender partition infeasibility even when total beds are sufficient', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, max_capacity: 8 },
  ], [], { people: 8, female: 4, male: 4 });
  assert.equal(result.success, false);
  assert.equal(result.reason, 'gender_separation');
  assert.equal(result.inventoryCapacity, 8);
  assert.deepEqual(result.addedIds, []);
});

check('adds a second room when one oversized room can only hold one known gender group', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, max_capacity: 8 },
    { venue_id: 2, available: 1, max_capacity: 2 },
    { venue_id: 3, available: 1, max_capacity: 1 },
  ], [], { people: 5, female: 3, male: 2 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [1, 2]);
  assert.deepEqual(result.selectedIds, [1, 2]);
  assert.equal(result.capacity, 10);
});

check('prefers a gender-feasible single building over the globally largest room spread', () => {
  const rooms = [
    { venue_id: 1, available: 1, building_name: '  Sevilla   Palace ', max_capacity: 4 },
    { venue_id: 2, available: 1, building_name: 'sevilla palace', max_capacity: 4 },
    { venue_id: 3, available: 1, building_name: 'River Hotel', max_capacity: 8 },
    { venue_id: 4, available: 1, building_name: 'Garden Inn', max_capacity: 4 },
  ];
  const result = suggestRoomSelection(rooms, [], { people: 8, female: 4, male: 4 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [1, 2]);
  assert.deepEqual(result.selectedIds, [1, 2]);
  assert.equal(result.buildingCount, 1);
  assert.deepEqual(result.buildingNames, ['Sevilla Palace']);
});

check('chooses the smallest adequate single room within a building', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, building_name: 'Abelardo Hotel', max_capacity: 12 },
    { venue_id: 2, available: 1, building_name: 'Abelardo Hotel', max_capacity: 4 },
    { venue_id: 3, available: 1, building_name: 'Abelardo Hotel', max_capacity: 6 },
  ], [], { people: 1, female: 1, male: 0 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [2]);
  assert.equal(result.capacity, 4);
  assert.deepEqual(result.buildingNames, ['Abelardo Hotel']);
});

check('chooses the smaller one-room hotel proposal after room count ties', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, building_name: 'Abelardo Hotel', max_capacity: 12 },
    { venue_id: 2, available: 1, building_name: 'Bellavista Hotel', max_capacity: 2 },
  ], [], { people: 1, female: 1, male: 0 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [2]);
  assert.equal(result.capacity, 2);
  assert.deepEqual(result.buildingNames, ['Bellavista Hotel']);
});

check('prefers fewer rooms before reducing unused beds', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, building_name: 'Abelardo Hotel', max_capacity: 12 },
    { venue_id: 2, available: 1, building_name: 'Courtyard Hotel', max_capacity: 5 },
    { venue_id: 3, available: 1, building_name: 'Courtyard Hotel', max_capacity: 5 },
  ], [], { people: 10, female: 10, male: 0 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [1]);
  assert.equal(result.roomCount, 1);
  assert.equal(result.capacity, 12);
});

check('compares single-building proposals by capacity after room counts tie', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, building_name: 'Alpha Hotel', max_capacity: 8 },
    { venue_id: 2, available: 1, building_name: 'Alpha Hotel', max_capacity: 8 },
    { venue_id: 3, available: 1, building_name: 'Beta Hotel', max_capacity: 6 },
    { venue_id: 4, available: 1, building_name: 'Beta Hotel', max_capacity: 5 },
  ], [], { people: 10, female: 10, male: 0 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [3, 4]);
  assert.equal(result.capacity, 11);
  assert.deepEqual(result.buildingNames, ['Beta Hotel']);
});

check('exhausts the highest-capacity building before adding the next building', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, building_name: 'Alpha House', max_capacity: 4 },
    { venue_id: 2, available: 1, building_name: 'Alpha House', max_capacity: 3 },
    { venue_id: 3, available: 1, building_name: 'Beta House', max_capacity: 5 },
    { venue_id: 4, available: 1, building_name: 'Beta House', max_capacity: 2 },
  ], [], { people: 10, female: 5, male: 5 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [1, 2, 3]);
  assert.deepEqual(result.selectedIds, [1, 2, 3]);
  assert.equal(result.buildingCount, 2);
  assert.deepEqual(result.buildingNames, ['Alpha House', 'Beta House']);
});

check('keeps manual picks and completes within their selected building before adding another', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, building_name: 'Courtyard Hotel', max_capacity: 3 },
    { venue_id: 2, available: 1, building_name: 'Courtyard Hotel', max_capacity: 3 },
    { venue_id: 3, available: 1, building_name: 'Tower Hotel', max_capacity: 10 },
  ], [1], { people: 6, female: 6, male: 0 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [2]);
  assert.deepEqual(result.selectedIds, [1, 2]);
  assert.equal(result.buildingCount, 1);
  assert.deepEqual(result.buildingNames, ['Courtyard Hotel']);
});

check('adds another building when gender-separated groups cannot fit in the first building', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, building_name: 'Main Hotel', max_capacity: 8 },
    { venue_id: 2, available: 1, building_name: 'Main Hotel', max_capacity: 2 },
    { venue_id: 3, available: 1, building_name: 'Annex', max_capacity: 4 },
  ], [], { people: 8, female: 4, male: 4 });
  assert.equal(result.success, true);
  assert.deepEqual(result.addedIds, [1, 2, 3]);
  assert.deepEqual(result.selectedIds, [1, 2, 3]);
  assert.equal(result.buildingCount, 2);
  assert.deepEqual(result.buildingNames, ['Annex', 'Main Hotel']);
});

check('orders tied building choices deterministically and falls back to room names', () => {
  const rooms = [
    { venue_id: 2, available: 1, name: '  Casa   Sevilla ', max_capacity: 4 },
    { venue_id: 1, available: 1, building_name: 'casa sevilla', max_capacity: 4 },
    { venue_id: 3, available: 1, building_name: 'Baja House', max_capacity: 7 },
    { venue_id: 4, available: 1, building_name: 'Baja House', max_capacity: 1 },
  ];
  const first = suggestRoomSelection(rooms, [], { people: 8, female: 4, male: 4 });
  const second = suggestRoomSelection(rooms.slice().reverse(), [], { people: 8, female: 4, male: 4 });
  assert.deepEqual(first.addedIds, [1, 2]);
  assert.deepEqual(second.addedIds, [1, 2]);
  assert.deepEqual(first.buildingNames, ['casa sevilla']);
  assert.deepEqual(second.buildingNames, ['casa sevilla']);
});

check('counts unknown-gender attendees toward beds and returns no assignment data', () => {
  const result = suggestRoomSelection([
    { venue_id: 1, available: 1, max_capacity: 1 },
    { venue_id: 2, available: 1, max_capacity: 1 },
    { venue_id: 3, available: 1, max_capacity: 1 },
  ], [], { people: 3, female: 1, male: 1, unknown: 1 });
  assert.equal(result.success, true);
  assert.equal(result.roster.unknown, 1);
  assert.equal(result.capacity, 3);
  assert.equal(Object.hasOwn(result, 'assignments'), false);
});

check('suggests enough rooms for 300 attendees with strict gender separation', () => {
  const rooms = Array.from({ length: 120 }, (_, index) => ({ venue_id: index + 1, available: 1, max_capacity: 5 }));
  const result = suggestRoomSelection(rooms, [], { people: 300, female: 150, male: 150 });
  assert.equal(result.success, true);
  assert.equal(result.roomCount, 60);
  assert.equal(result.capacity, 300);
});

check('suggests enough rooms for 1,000 attendees across a larger inventory', () => {
  const rooms = Array.from({ length: 500 }, (_, index) => ({ venue_id: index + 1, available: 1, max_capacity: 4 }));
  const result = suggestRoomSelection(rooms, [], { people: 1000, female: 500, male: 500 });
  assert.equal(result.success, true);
  assert.equal(result.roomCount, 250);
  assert.equal(result.capacity, 1000);
});

console.log(`Passed ${checks.length} seminar room suggestion checks.`);
