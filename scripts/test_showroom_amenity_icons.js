#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/showroom.js'), 'utf8');
const start = source.indexOf('  function showroomAmenityIconClass(');
const end = source.indexOf('  function updateHotelVariantDetails(', start);
if (start < 0 || end < 0) throw new Error('Could not locate the showroom amenity renderer.');

class Element {
    constructor(tagName) {
        this.tagName = tagName;
        this.attributes = {};
        this.children = [];
        this.className = '';
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    append(...children) { this.children.push(...children); }
}

const context = {
    document: {
        createElement: tagName => new Element(tagName),
        createTextNode: textContent => ({ textContent }),
    },
};
vm.runInNewContext(`${source.slice(start, end)}\nglobalThis.iconForAmenity = showroomAmenityIconClass; globalThis.renderAmenity = renderShowroomAmenity;`, context);

const cases = [
    ['Tables & chairs', 'fa-chair'],
    ['SOUND-SYSTEM', 'fa-volume-high'],
    ['Microphones', 'fa-microphone'],
    ['Event lighting', 'fa-lightbulb'],
    ['Stage area', 'fa-masks-theater'],
    ['Catering area', 'fa-utensils'],
    ['Guest restrooms', 'fa-restroom'],
    ['Free parking', 'fa-square-parking'],
    ['Parking fees apply', 'fa-square-parking'],
    ['Free Wi-Fi', 'fa-wifi'],
    ['Fully air-conditioned', 'fa-snowflake'],
    ['Private pool', 'fa-water-ladder'],
    ['Smart TV', 'fa-tv'],
    ['Mini-fridge', 'fa-temperature-arrow-down'],
    ['Breakfast', 'fa-utensils'],
    ['Fitness center', 'fa-dumbbell'],
    ['Beds', 'fa-bed'],
    ['Guest shower', 'fa-bath'],
    ['Unknown amenity', 'fa-check'],
];
for (const [label, expected] of cases) {
    const actual = context.iconForAmenity(label);
    if (actual !== expected) throw new Error(`${label}: expected ${expected}, got ${actual}`);
    console.log(`PASS|${label} maps to ${expected}`);
}

const rendered = context.renderAmenity('<script>alert(1)</script>');
if (!rendered || rendered.className !== 'amenity'
    || rendered.children[0]?.className !== 'fa-solid fa-check'
    || rendered.children[0]?.attributes['aria-hidden'] !== 'true'
    || rendered.children[1]?.textContent !== ' <script>alert(1)</script>') {
    throw new Error('Amenity rendering must keep unknown labels as text and hide decorative icons from assistive technology.');
}
console.log('PASS|renderer keeps labels as text and marks icons aria-hidden');

const showroomPhp = fs.readFileSync(path.join(root, 'showroom.php'), 'utf8');
const gridStart = showroomPhp.indexOf('<div class="amenities-grid">');
const gridEnd = showroomPhp.indexOf('</div>\n                <section class="showroom-reviews"', gridStart);
const staticGrid = gridStart >= 0 && gridEnd >= 0 ? showroomPhp.slice(gridStart, gridEnd) : '';
const staticIcons = staticGrid.match(/<i\b[^>]*>/g) || [];
if (staticIcons.length !== 4 || staticIcons.some(icon => !/\baria-hidden="true"/.test(icon))) {
    throw new Error('Static showroom amenity icons must be decorative for assistive technology.');
}
console.log('PASS|static showroom amenity icons are aria-hidden');
