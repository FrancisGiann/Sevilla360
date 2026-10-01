const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const window = {};
vm.runInNewContext(fs.readFileSync("assets/js/receptionist-chat.js", "utf8"), { window, document: {} });
const chat = window.SevillaReceptionistChat;
const checks = [];
const check = (label, callback) => {
  try {
    callback();
    checks.push([label, true]);
  } catch (error) {
    checks.push([label, false, error.message]);
  }
};
const plain = value => JSON.parse(JSON.stringify(value));

check("hotel preference suggestions expose all three valid choices", () => {
  assert.deepEqual(plain(chat.resolveBookingSuggestions({ booking_continuation: true, missing_slots: ["preference"] })), {
    items: ["Best fit", "Lowest price", "Comfort"], actions: [], contextual: true
  });
});
check("event occasion and villa purpose suggestions follow their pending slots", () => {
  assert.deepEqual(plain(chat.resolveBookingSuggestions({ booking_continuation: true, missing_slots: ["occasion"] })).items,
    ["Wedding", "Birthday", "Corporate", "Other"]);
  assert.deepEqual(plain(chat.resolveBookingSuggestions({ booking_continuation: true, missing_slots: ["purpose"] })).items,
    ["Family", "Private", "Relaxation"]);
});
check("room type clarification keeps up to four server choices with active guided results", () => {
  assert.deepEqual(plain(chat.resolveBookingSuggestions({
    missing_slots: ["active_room_group_id"],
    quick_replies: ["Deluxe", "Suite", "Family", "Penthouse", "Extra"]
  })), {
    items: ["Deluxe", "Suite", "Family", "Penthouse"], actions: [], contextual: true
  });
});
check("intent clarification uses server category action ids when present", () => {
  assert.deepEqual(plain(chat.resolveBookingSuggestions({
    booking_continuation: true, missing_slots: ["intent"], quick_actions: ["category_hotel_room"]
  })).actions, ["category_hotel_room"]);
});
check("ordinary FAQ suggestions stay on the default rendering path", () => {
  assert.equal(chat.resolveBookingSuggestions({ booking_continuation: false, quick_replies: ["Support FAQs"] }), null);
});
check("date clear metadata removes expired checkout while retaining replacement check-in", () => {
  const context = { intent: "Hotel Room", startDate: "2026-09-20", endDate: "2026-09-21", groupSizeExact: 2 };
  chat.applyDateSlotPatch(context, { start_date: "2026-10-02" }, ["end_date"]);
  assert.deepEqual(context, { intent: "Hotel Room", startDate: "2026-10-02", endDate: null, groupSizeExact: 2 });
});

const failed = checks.filter(([, passed]) => !passed);
checks.forEach(([label, passed, error]) => console.log(`${passed ? "PASS" : "FAIL"} - ${label}${error ? `: ${error}` : ""}`));
console.log(`RESULT - ${checks.length} checks, ${failed.length} failures`);
process.exitCode = failed.length ? 1 : 0;
