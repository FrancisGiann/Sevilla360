'use strict';

const assert = require('node:assert/strict');
const { groupAttendeesByLocation, normalizeSeminarLocation } = require('../assets/js/admin-page/admin_seminars.js');

const attendees = [
  { id: 1, full_name: 'Zoë Reyes', location: ' Quezon  City' },
  { id: 2, full_name: 'Ben Cruz', location: 'pasig' },
  { id: 3, full_name: 'Ana Santos', location: 'PASIG ' },
  { id: 4, full_name: 'Amir Reyes', location: 'quezon\t city' },
  { id: 5, full_name: 'Bella Cruz', location: '  Pasig' },
];

assert.equal(normalizeSeminarLocation('  PASIG\t City  '), 'pasig city');
assert.equal(normalizeSeminarLocation('\u00a0PASIG\u00a0'), 'pasig');

const groups = groupAttendeesByLocation(attendees);
assert.deepEqual(groups.map(group => group.key), ['pasig', 'quezon city']);
assert.deepEqual(groups.map(group => group.label), ['PASIG', 'quezon city']);
assert.deepEqual(groups.map(group => group.people.map(person => person.full_name)), [
  ['Ana Santos', 'Bella Cruz', 'Ben Cruz'],
  ['Amir Reyes', 'Zoë Reyes'],
]);
assert.deepEqual(groupAttendeesByLocation(attendees.slice().reverse()), groups);

const editedDraft = attendees.map(person => person.id === 5 ? { ...person, location: '  Muntinlupa ' } : person);
const editedGroups = groupAttendeesByLocation(editedDraft);
assert.deepEqual(editedGroups.map(group => group.key), ['muntinlupa', 'pasig', 'quezon city']);
assert.equal(editedGroups[0].label, 'Muntinlupa');
assert.deepEqual(editedGroups[1].people.map(person => person.full_name), ['Ana Santos', 'Ben Cruz']);
assert.equal(attendees.find(person => person.id === 5).location, '  Pasig');

console.log('Passed seminar location grouping checks.');
