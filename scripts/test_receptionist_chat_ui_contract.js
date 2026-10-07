const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const chatSource = fs.readFileSync("assets/js/receptionist-chat.js", "utf8");
const showroomSource = fs.readFileSync("assets/js/showroom.js", "utf8");
const showroomCss = fs.readFileSync("assets/css/showroom.css", "utf8");

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

check("hotel priority prompt exposes three choices plus no preference", () => {
  assert.deepEqual(plain(chat.resolveBookingSuggestions({ mode: "natural", missing_slots: ["preference"] })), {
    items: ["Best fit", "Lowest price", "Comfort", "No preference"], actions: [], contextual: true
  });
});
check("guided hotel searches default to any type and ask optional priority before dates", () => {
  assert.ok(showroomSource.includes('guideContext.roomTypeCode = "any"'));
  assert.ok(showroomSource.includes('if (category === "Hotel Room" && (!guideContext.startDate || !guideContext.endDate)) renderQuestion(category, "preference");'));
  assert.ok(showroomSource.includes('options = [["Lowest price", "save"], ["Best fit for my group", "best_fit"], ["Comfort", "comfort"], ["No preference", "best_fit"]]'));
  assert.ok(showroomSource.includes('title = "Choose a room type (optional)"'));
  assert.ok(chatSource.includes('"roomTypeRequested"'));
  assert.ok(showroomSource.includes('guideContext.roomTypeRequested = true;'));
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
check("hotel room type step exposes all six taxonomy choices in chat and keeps guided choices visible", () => {
  assert.deepEqual(plain(chat.resolveBookingSuggestions({ mode: "natural", missing_slots: ["room_type_code"] })), {
    items: ["Standard Room", "Dormitory Room", "Family Room / Superior", "Deluxe", "VIP Suite", "Any room type"],
    actions: [], contextual: true, maxItems: 6
  });
  assert.ok(chatSource.includes("root.sevillaReceptionistShowGuidedChoices = items => renderQuickReplies(items, [], true, 6)"));
  assert.ok(chatSource.includes("[data-receptionist-answer], [data-receptionist-group-input]"));
  assert.ok(showroomSource.includes("receptionistRoot.sevillaReceptionistShowGuidedChoices?.(options.map(([labelText]) => labelText))"));
});
check("selecting a recommended hotel room opens details without replaying the result snapshot", () => {
  const roomSelectionStart = showroomSource.indexOf('if (target.hasAttribute("data-receptionist-room")) {', showroomSource.indexOf('receptionistRoot.addEventListener("click"'));
  const roomSelectionEnd = showroomSource.indexOf('if (target.hasAttribute("data-receptionist-why"))', roomSelectionStart);
  const roomSelection = showroomSource.slice(roomSelectionStart, roomSelectionEnd);
  assert.ok(roomSelection.includes("naturalSyncStartedThisClick = true;"));
  assert.ok(roomSelection.indexOf("naturalSyncStartedThisClick = true;") < roomSelection.indexOf("renderVenue(room)"));
  assert.ok(showroomSource.includes("if (!naturalHybridEnabled || !event.isTrusted || naturalSyncStartedThisClick) return;"));
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
check("ungating chat restores only controls it gated and preserves domain-disabled calendar dates", () => {
  const states = new WeakMap();
  const pastCalendarDay = { disabled: true };
  const ordinaryButton = { disabled: false };
  const createdWhileGated = { disabled: true };
  chat.applyGateDisabledState(pastCalendarDay, true, states);
  chat.applyGateDisabledState(ordinaryButton, true, states);
  chat.applyGateDisabledState(pastCalendarDay, false, states);
  chat.applyGateDisabledState(ordinaryButton, false, states);
  chat.applyGateDisabledState(createdWhileGated, false, states);
  assert.equal(pastCalendarDay.disabled, true, "past date remains unavailable after chat closes");
  assert.equal(ordinaryButton.disabled, false, "an ordinary button returns to its original enabled state");
  assert.equal(createdWhileGated.disabled, true, "a newly rendered disabled control is not enabled during ungating");
});
check("natural guided turns append the server reply once instead of scripted showroom dialogue", () => {
  assert.ok(showroomSource.includes('if (!naturalHybridEnabled) {\n        window.dispatchEvent(new CustomEvent("SevillaReceptionistGuidedState"'));
  assert.ok(chatSource.includes('if (guidedReply || guidedUserMessage) window.dispatchEvent(new CustomEvent("SevillaReceptionistGuidedServerReply"'));
  assert.ok(chatSource.includes('window.addEventListener("SevillaReceptionistGuidedServerReply"'));
});
check("reopening natural chat restores its pending guided date question without a server turn", () => {
  assert.ok(chatSource.includes('window.dispatchEvent(new CustomEvent("SevillaReceptionistChatOpened"))'));
  assert.ok(showroomSource.includes('window.addEventListener("SevillaReceptionistChatOpened"'));
  assert.ok(showroomSource.includes('latestNaturalState.pending_question'));
  assert.ok(showroomSource.includes('if (pending === "start_date" || pending === "end_date") focusNaturalDateChoice();'));
  assert.ok(!showroomSource.includes('detail: { reply: prompt, revision: latestNaturalState.revision, restored: true }'));
});
check("guided date history records validated selections and checkout calendars retain a focusable day", () => {
  assert.ok(chatSource.includes('detail: { reply: guidedReply, user_message: guidedUserMessage, revision: data.revision }'));
  assert.ok(chatSource.includes('if (userMessage) appendMessage("user", userMessage);'));
  assert.ok(showroomSource.includes('const targetFocus = preferredFocus && isSelectable(preferredFocus) ? preferredFocus : firstSelectableInMonth();'));
});
check("mobile hotel cards and results grid stay inside their available column", () => {
  assert.ok(showroomCss.includes('.showroom-receptionist.is-hotel-results .receptionist-hotel-results-grid {\n    display: grid;\n    grid-template-columns: minmax(0, 1fr);\n    gap: .45rem;\n    width: 100%;\n    max-width: 100%;'));
  assert.ok(showroomCss.includes('.showroom-receptionist.is-hotel-results .receptionist-hotel-result > *,'));
  assert.ok(showroomCss.includes('.showroom-receptionist.is-hotel-results .receptionist-hotel-result .receptionist-hotel-explanation {\n    width: auto;\n    margin-inline: 0;'));
});

const failed = checks.filter(([, passed]) => !passed);
checks.forEach(([label, passed, error]) => console.log(`${passed ? "PASS" : "FAIL"} - ${label}${error ? `: ${error}` : ""}`));
console.log(`RESULT - ${checks.length} checks, ${failed.length} failures`);
process.exitCode = failed.length ? 1 : 0;
