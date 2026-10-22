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
check("venue detail menu preserves the card and actions across repeated opens and venue changes", () => {
  class Node {
    constructor(name = "div") { this.name = name; this.children = []; this.dataset = {}; this.attributes = {}; }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this.children = children; }
    querySelector(selector) {
      if (selector === "[data-receptionist-venue-details]") return this.children.find(child => child.dataset.receptionistVenueDetails) || null;
      if (selector === "[data-receptionist-menu]") return this.children.find(child => child.attributes["data-receptionist-menu"]) || null;
      if (selector === "button") return this.children.find(child => child.name === "button") || null;
      return null;
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    focus() { this.focused = true; }
  }
  const choices = new Node();
  const card = new Node();
  const book = new Node("a");
  const tour = new Node("button");
  const menu = new Node("button");
  menu.attributes["data-receptionist-menu"] = "true";
  choices.replaceChildren(card, book, tour, menu);
  const classes = new Set(["is-venue-overview"]);
  const context = {
    document: { createElement: name => new Node(name) },
    receptionistChoices: choices,
    receptionistRoot: { classList: { add: name => classes.add(name), remove: (...names) => names.forEach(name => classes.delete(name)) } },
    guideState: {}, guideContext: { startDate: "2026-10-24" }, receptionistState: { activeCategory: "Event Hall" },
    window: { matchMedia: () => ({ matches: true }) },
    setDialogue: () => {},
    createChoice: (label, className, attributes) => { const node = new Node("button"); node.label = label; node.attributes = attributes; return node; }
  };
  const start = showroomSource.indexOf("    const venueDetailsRegion = () => {");
  const end = showroomSource.indexOf("    const renderResponse = (room, mode) => {", start);
  assert.ok(start > 0 && end > start);
  const renderMenu = vm.runInNewContext(`${showroomSource.slice(start, end)}; renderVenueMenu`, context);
  renderMenu({ category: "Event Hall" });
  let region = choices.querySelector("[data-receptionist-venue-details]");
  assert.equal(choices.children.length, 5);
  assert.deepEqual(choices.children.slice(0, 4), [card, book, tour, menu]);
  assert.equal(menu.attributes["aria-expanded"], "true");
  assert.equal(region.children.at(-1).label, "Collapse details");
  assert.equal(region.children[0].focused, true);
  renderMenu({ category: "Resort Villa" });
  assert.equal(choices.querySelector("[data-receptionist-venue-details]"), region);
  assert.equal(choices.children.length, 5);
  assert.equal(context.guideContext.startDate, "2026-10-24");
  assert.ok(chatSource.includes("[data-receptionist-venue-details]"));
  assert.ok(showroomCss.includes(".receptionist-venue-details"));
  assert.ok(showroomSource.includes('menu?.setAttribute("aria-expanded", "false")'));
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
check("start-over confirmation overrides the global margin reset and stays inside viewport safe areas", () => {
  assert.ok(chatSource.includes('position: "fixed"'));
  assert.ok(chatSource.includes('inset: "0"'));
  assert.ok(chatSource.includes('margin: "auto"'));
  assert.ok(chatSource.includes('maxHeight: "calc(100dvh - max(16px,env(safe-area-inset-top,0px)) - max(16px,env(safe-area-inset-bottom,0px)))"'));
  assert.ok(chatSource.includes('if (!completed) trigger.focus();'), "cancelling still restores focus to the invoking control");
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
check("chat API requests have a bounded abort deadline and always clear its timer", () => {
  assert.ok(chatSource.includes("const CHAT_REQUEST_TIMEOUT_MS = 35000;"));
  assert.ok(chatSource.includes("new window.AbortController()"));
  assert.ok(chatSource.includes("controller.abort()"));
  assert.ok(chatSource.includes("request.finally(() => window.clearTimeout(timer))"));
  assert.ok(chatSource.includes("async response => ({ response, data: await readChatResponse(response) })"), "the deadline includes reading and parsing response bodies");
  assert.equal((chatSource.match(/\bfetch\(/g) || []).length, 2, "raw fetch calls stay inside the deadline wrapper");
  assert.equal((chatSource.match(/fetchWithDeadline\(/g) || []).length, 3, "the shared wrapper serves history/send parsing, reset, and guided sync");
  assert.equal((chatSource.match(/fetchJsonWithDeadline\(/g) || []).length, 2, "history and send include bounded JSON body parsing");
  assert.ok(chatSource.includes("Promise.race([request, deadline])"));
  assert.ok(chatSource.includes('error.name = "TimeoutError";'), "browsers without AbortController still get a UI deadline");
});
check("timeout recovery keeps the typed message and avoids resending a turn already committed by the server", () => {
  assert.ok(chatSource.includes('const timedOut = error?.name === "AbortError" || error?.name === "TimeoutError";'));
  assert.ok(chatSource.includes("The request may still be finishing. Use Retry once it is ready."));
  assert.ok(chatSource.includes('const latestUserTurn = [...latestMessages].reverse().find(turn => turn.role === "user");'));
  assert.ok(chatSource.includes("latestUserTurn?.content === clean && !isRetryableCommittedReply(latestAssistantTurn?.content)"));
  assert.ok(chatSource.includes("Your earlier message was received; its latest reply is above."));
});
check("gateway HTML failures use generic user copy, stay retryable, and expose one manual retry action", () => {
  assert.ok(chatSource.includes("body = await response.text();"));
  assert.ok(chatSource.includes("error.httpStatus = Number(response.status) || 0;"));
  assert.ok(chatSource.includes("The chat service is temporarily unavailable."));
  assert.ok(!chatSource.includes("The server gateway returned") && !chatSource.includes("The chat server returned"));
  assert.ok(chatSource.includes("pendingMessage = retryable ? clean : \"\";"));
  assert.ok(chatSource.includes("guidedFallback({ quick_replies: [\"Event\", \"Hotel\", \"Villa\", \"Support FAQs\"] }, recoveryMessage, retryable);"));
  assert.ok(chatSource.includes('retry.dataset.receptionistQuickAction = "retry_provider";'));
});
check("a stale-state duplicate is suppressed after success but manual retry remains available after a committed outage", () => {
  assert.ok(chatSource.includes("!isRetryableCommittedReply(latestAssistantTurn?.content)"));
  assert.ok(chatSource.includes("pendingMessage = clean;"));
  assert.ok(chatSource.includes("The earlier reply was unavailable."));
});
check("mobile hotel cards and results grid stay inside their available column", () => {
  assert.ok(showroomCss.includes('.showroom-receptionist.is-hotel-results .receptionist-hotel-results-grid {\n    display: grid;\n    grid-template-columns: minmax(0, 1fr);\n    gap: .45rem;\n    width: 100%;\n    max-width: 100%;'));
  assert.ok(showroomCss.includes('.showroom-receptionist.is-hotel-results .receptionist-hotel-result > *,'));
  assert.ok(showroomCss.includes('.showroom-receptionist.is-hotel-results .receptionist-hotel-result .receptionist-hotel-explanation {\n    width: auto;\n    margin-inline: 0;'));
});

// Exercise the real chat controller with a small DOM fixture and mocked HTTP.
// No application endpoint, database, or AI provider is contacted.
const flush = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
const response = (data, status = 200) => ({ status, ok: status >= 200 && status < 300,
  text: async () => JSON.stringify(data), json: async () => data });
const starterActions = ["category_event_hall", "category_hotel_room", "category_resort_villa"];
const savedHotelAnswers = { intent: "Hotel Room", groupSizeExact: 4, startDate: "2037-11-12", endDate: "2037-11-15" };

function chatFixture({ history = [], context = {}, historyResponse, resetResponse, chatResponse } = {}) {
  const document = { hidden: true, activeElement: null };
  class Element {
    constructor(tag = "div", attributes = {}) {
      this.tagName = tag; this.attributes = { ...attributes }; this.dataset = {}; this.children = [];
      this.listeners = {}; this.className = ""; this.style = {}; this.value = ""; this.disabled = false;
      this.classList = { contains: name => this.className.split(" ").includes(name),
        toggle: (name, enabled) => { const names = new Set(this.className.split(" ").filter(Boolean));
          (enabled ?? !names.has(name)) ? names.add(name) : names.delete(name); this.className = [...names].join(" "); },
        add: name => this.classList.toggle(name, true), remove: name => this.classList.toggle(name, false) };
    }
    get textContent() { return (this.text || "") + this.children.map(child => child.textContent).join(""); }
    set textContent(value) { this.text = value; this.replaceChildren(); }
    get firstElementChild() { return this.children[0]; }
    get lastElementChild() { return this.children.at(-1); }
    get nextSibling() { return this.parentNode?.children[this.parentNode.children.indexOf(this) + 1] || null; }
    getAttribute(name) { return name.startsWith("data-")
      ? this.dataset[name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] ?? this.attributes[name] ?? null
      : this.attributes[name] ?? null; }
    hasAttribute(name) { return this.getAttribute(name) !== null; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    appendChild(child) { child.remove(); child.parentNode = this; this.children.push(child); return child; }
    insertBefore(child, next) { if (!next) return this.appendChild(child); child.remove(); child.parentNode = this; this.children.splice(this.children.indexOf(next), 0, child); return child; }
    replaceChildren(...children) { this.children.forEach(child => { child.parentNode = null; }); this.children = []; children.forEach(child => this.appendChild(child)); }
    remove() { if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(child => child !== this); this.parentNode = null; }
    contains(child) { return child === this || this.children.some(item => item.contains(child)); }
    matches(selector) {
      return selector.split(",").some(part => {
        const terms = part.trim().split(/\s+/); const last = terms.pop();
        if (terms.length && !this.parentNode?.closest(terms.join(" "))) return false;
        if (last.startsWith(".")) return this.classList.contains(last.slice(1));
        const attribute = /^\[([^=\]]+)(?:="([^"]*)")?\]$/.exec(last);
        return attribute ? this.hasAttribute(attribute[1]) && (attribute[2] === undefined || this.getAttribute(attribute[1]) === attribute[2]) : this.tagName === last;
      });
    }
    closest(selector) { return this.matches(selector) ? this : this.parentNode?.closest(selector) || null; }
    querySelectorAll(selector) { return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
    dispatchEvent(event) { event.target ||= this; (this.listeners[event.type] || []).forEach(listener => listener(event)); return !event.prevented; }
    focus() { document.activeElement = this; }
    getClientRects() { return this.hidden ? [] : [{}]; }
    showModal() { this.open = true; }
    close() { this.open = false; this.dispatchEvent({ type: "close" }); }
    click() { if (!this.disabled) this.dispatchEvent({ type: "click" }); }
    getBoundingClientRect() { return { top: 0 }; }
    requestSubmit() { this.dispatchEvent({ type: "submit", preventDefault() {} }); }
  }
  const elements = Object.fromEntries(["showroom-receptionist", "receptionist-chat-transcript", "receptionist-chat-form",
    "receptionist-chat-input", "receptionist-chat-locale", "receptionist-chat-send", "receptionist-chat-status",
    "receptionist-chat-quick-replies", "receptionist-chat-shell", "receptionist-choices", "receptionist-chat-conversation",
    "receptionist-chat-guided-content"].map(id => [id, new Element()]));
  const root = elements["showroom-receptionist"];
  const shell = elements["receptionist-chat-shell"];
  const input = elements["receptionist-chat-input"]; input.tagName = "textarea";
  const form = elements["receptionist-chat-form"]; form.tagName = "form";
  const choices = elements["receptionist-choices"];
  const quickReplies = elements["receptionist-chat-quick-replies"];
  const transcript = elements["receptionist-chat-transcript"];
  const toggle = new Element("button", { "data-receptionist-chat-toggle": "" });
  const close = new Element("button", { "data-receptionist-chat-close": "" });
  const reset = new Element("button", { "data-receptionist-chat-start-over": "" });
  const send = elements["receptionist-chat-send"]; send.tagName = "button";
  root.appendChild(toggle); root.appendChild(choices); root.appendChild(shell);
  shell.appendChild(close); shell.appendChild(reset); shell.appendChild(quickReplies); shell.appendChild(form);
  shell.appendChild(elements["receptionist-chat-conversation"]);
  elements["receptionist-chat-conversation"].appendChild(transcript);
  elements["receptionist-chat-conversation"].appendChild(elements["receptionist-chat-guided-content"]);
  form.appendChild(input); form.appendChild(send);
  document.getElementById = id => elements[id] || root.querySelectorAll("div").find(item => item.id === id) || null;
  document.createElement = tag => new Element(tag);
  document.createComment = () => new Element("comment");
  document.querySelector = () => null;
  const events = {};
  const requests = [];
  let guideContext = { ...context };
  const window = { setTimeout: (callback, delay) => { if (delay < 1000) queueMicrotask(callback); return 1; }, clearTimeout() {},
    requestAnimationFrame: callback => callback(),
    addEventListener: (type, listener) => (events[type] ||= []).push(listener),
    dispatchEvent: event => (events[event.type] || []).forEach(listener => listener(event)) };
  const fetch = async (url, options) => {
    requests.push({ url, body: JSON.parse(options.body) });
    if (url.endsWith("_history.php")) return historyResponse ? await historyResponse : response({ success: true, revision: 4, history });
    if (url.endsWith("_reset.php")) return typeof resetResponse === "function" ? await resetResponse() : resetResponse ? await resetResponse : response({ success: true, revision: 5 });
    return chatResponse ? await chatResponse : response({ success: true, mode: "natural", action: "ask", local_reply: true,
      revision: 5, reply: "What are you celebrating?", missing_slots: ["occasion"], slots: { intent: "Event Hall" } });
  };
  vm.runInNewContext(chatSource, { window, document, Element, fetch, queueMicrotask, setTimeout: window.setTimeout,
    requestAnimationFrame: window.requestAnimationFrame, CustomEvent: class { constructor(type, { detail } = {}) { this.type = type; this.detail = detail; } } });
  window.SevillaReceptionistChat.init({ root, choices, getContext: () => guideContext,
    onQuickAction: () => true, onStartOver: () => { guideContext = {}; choices.replaceChildren(); },
    onRestoreState: state => { guideContext = state.slots || {}; },
    onKnowledge: data => { guideContext = data.slots || guideContext; } });
  window.dispatchEvent({ type: "SevillaReceptionistGateChanged", detail: { gated: false } });
  const click = target => root.dispatchEvent({ type: "click", target });
  return { root, shell, input, form, choices, quickReplies, transcript, toggle, close, reset, send, requests, document, Element,
    click, open: () => click(toggle), closeChat: () => click(close), startOver: () => click(reset),
    context: () => guideContext, confirmation: () => shell.querySelector("dialog"),
    cancelReset: () => shell.querySelector("dialog").querySelectorAll("button")[0].click(),
    confirmReset: () => shell.querySelector("dialog").querySelectorAll("button")[1].click(),
    actionIds: () => quickReplies.children[0].children.map(button => button.dataset.receptionistQuickAction),
    clickSuggestion: index => quickReplies.dispatchEvent({ type: "click", target: quickReplies.children[0].children[index] }) };
}

async function interactionChecks() {
  const checkAsync = async (label, callback) => {
    try { await callback(); checks.push([label, true]); }
    catch (error) { checks.push([label, false, error.message]); }
  };
  await checkAsync("fresh chat waits for history then exposes three accessible category buttons alongside free text", async () => {
    const history = deferred(); const fixture = chatFixture({ historyResponse: history.promise });
    fixture.open(); await flush();
    assert.deepEqual(fixture.actionIds(), []);
    assert.equal(fixture.shell.hidden, true);
    history.resolve(response({ success: true, history: [] })); await flush();
    assert.deepEqual(fixture.actionIds(), starterActions);
    assert.equal(fixture.shell.hidden, false);
    assert.equal(fixture.quickReplies.children[0].getAttribute("role"), "group");
    assert.equal(fixture.quickReplies.children[0].getAttribute("aria-label"), "Suggested replies");
    assert.equal(fixture.quickReplies.children[0].classList.contains("receptionist-chat-category-actions"), true);
    assert.deepEqual(fixture.quickReplies.children[0].children.map(button => button.textContent), ["Event", "Hotel", "Villa"]);
    fixture.quickReplies.children[0].children.forEach(button => { assert.equal(button.type, "button"); assert.ok(button.textContent); assert.equal(button.disabled, false); });
    assert.equal(fixture.input.disabled, false);
    assert.equal(fixture.document.activeElement, fixture.input);
  });
  await checkAsync("each starter button uses its existing category action exactly once", async () => {
    for (let index = 0; index < starterActions.length; index++) {
      const fixture = chatFixture(); fixture.open(); await flush(); fixture.clickSuggestion(index); await flush();
      const turns = fixture.requests.filter(request => request.url.endsWith("receptionist_chat.php"));
      assert.equal(turns.length, 1); assert.equal(turns[0].body.action_id, starterActions[index]);
      assert.equal(fixture.input.disabled, false);
      assert.equal(fixture.document.activeElement, fixture.input);
      assert.equal(fixture.quickReplies.children[0].classList.contains("receptionist-chat-category-actions"), false);
      assert.deepEqual(fixture.quickReplies.children[0].children.map(button => button.textContent), ["Wedding", "Birthday", "Corporate", "Other"]);
    }
  });
  await checkAsync("typed answers and suggested answers share chat while reopen preserves conversation and draft", async () => {
    const fixture = chatFixture(); fixture.open(); await flush();
    fixture.input.value = "An event for 27 guests"; fixture.form.requestSubmit(); await flush();
    assert.equal(fixture.requests[1].body.message, "An event for 27 guests");
    assert.equal(fixture.requests[1].body.action_id, undefined);
    fixture.clickSuggestion(0); await flush();
    assert.equal(fixture.requests[2].body.message, "Wedding");
    const before = fixture.transcript.textContent; fixture.input.value = "Keep this draft";
    fixture.closeChat(); fixture.open(); await flush();
    assert.equal(fixture.transcript.textContent, before);
    assert.equal(fixture.input.value, "Keep this draft");
    assert.equal(fixture.requests.length, 3);
    assert.ok(!fixture.actionIds().some(id => starterActions.includes(id)));
  });
  await checkAsync("restored history and existing guided input do not get fresh category chips", async () => {
    const fixture = chatFixture({ history: [{ role: "user", content: "Hotel for three guests" }, { role: "assistant", content: "When would you check in?" }] });
    fixture.open(); await flush(); assert.deepEqual(fixture.actionIds(), []);
    assert.ok(fixture.transcript.textContent.includes("When would you check in?"));
    const guided = chatFixture();
    guided.choices.appendChild(new guided.Element("input", { "data-receptionist-group-input": "" }));
    guided.open(); await flush(); assert.deepEqual(guided.actionIds(), []);
  });
  await checkAsync("Back to welcome offers compact categories without discarding the conversation or draft", async () => {
    const fixture = chatFixture({ history: [{ role: "user", content: "Hotel for 3 guests" }], context: { intent: "Hotel Room", groupSizeExact: 3 } });
    fixture.open(); await flush(); const before = fixture.transcript.textContent;
    const back = new fixture.Element("button", { "data-receptionist-back": "" }); fixture.choices.appendChild(back);
    fixture.input.value = "Can we arrive late?"; fixture.click(back);
    assert.deepEqual(fixture.actionIds(), starterActions);
    assert.equal(fixture.transcript.textContent, before); assert.equal(fixture.input.value, "Can we arrive late?");
    assert.equal(fixture.document.activeElement, fixture.input); assert.equal(fixture.requests.length, 1);
  });
  await checkAsync("Start over asks first and Cancel preserves chat, draft, guided answers and focus", async () => {
    const fixture = chatFixture({ history: [{ role: "user", content: "Hotel" }], context: savedHotelAnswers });
    fixture.open(); await flush(); fixture.input.value = "Keep my draft";
    const guided = new fixture.Element("input", { "data-receptionist-group-input": "" }); guided.value = "4"; fixture.choices.appendChild(guided);
    const before = fixture.transcript.textContent; fixture.startOver(); await flush();
    const dialog = fixture.confirmation(); assert.equal(dialog.open, true);
    assert.equal(dialog.getAttribute("aria-labelledby"), "receptionist-reset-title");
    assert.equal(dialog.getAttribute("aria-describedby"), "receptionist-reset-description");
    assert.equal(dialog.children[0].style.color, "#fffaf1", "heading overrides the site's dark global heading color");
    assert.equal(dialog.children[1].style.color, "#fffaf1", "description keeps explicit contrast in every reset state");
    assert.equal(fixture.document.activeElement.textContent, "Cancel");
    assert.equal(fixture.requests.length, 1, "opening confirmation never calls reset");
    fixture.cancelReset(); assert.equal(fixture.confirmation(), null);
    assert.equal(fixture.transcript.textContent, before); assert.equal(fixture.input.value, "Keep my draft");
    assert.deepEqual(plain(fixture.context()), savedHotelAnswers); assert.equal(fixture.choices.children[0], guided);
    assert.equal(fixture.document.activeElement, fixture.reset);
    fixture.startOver(); fixture.confirmation().dispatchEvent({ type: "cancel" }); fixture.confirmation().close();
    assert.equal(fixture.requests.length, 1, "Escape cancellation also leaves the server untouched");
    assert.equal(fixture.input.value, "Keep my draft");
  });
  await checkAsync("user confirmation waits for server acknowledgement before clearing any state", async () => {
    const reset = deferred(); const fixture = chatFixture({ history: [{ role: "user", content: "Hotel" }], context: savedHotelAnswers, resetResponse: reset.promise });
    fixture.open(); await flush(); fixture.input.value = "Keep until acknowledgement";
    const before = fixture.transcript.textContent; fixture.startOver(); fixture.confirmReset(); await flush();
    assert.deepEqual(fixture.actionIds(), []); assert.equal(fixture.form.dataset.resetting, "true");
    assert.equal(fixture.transcript.textContent, before); assert.equal(fixture.input.value, "Keep until acknowledgement");
    assert.deepEqual(plain(fixture.context()), savedHotelAnswers);
    assert.equal(fixture.document.activeElement, fixture.confirmation().children[1], "pending status keeps keyboard events inside the dialog when both buttons are disabled");
    fixture.confirmation().querySelectorAll("button").forEach(button => assert.equal(button.disabled, true));
    for (const shiftKey of [false, true]) {
      const tab = { type: "keydown", key: "Tab", shiftKey, stopPropagation() {}, preventDefault() { this.prevented = true; } };
      fixture.confirmation().dispatchEvent(tab); assert.equal(tab.prevented, true, "pending reset keeps both Tab directions in the locked dialog");
    }
    const cancel = { type: "cancel", preventDefault() { this.prevented = true; } }; fixture.confirmation().dispatchEvent(cancel);
    assert.equal(cancel.prevented, true, "an in-flight server reset cannot be cancelled locally");
    reset.resolve(response({ success: true, revision: 5 })); await flush();
    assert.deepEqual(fixture.actionIds(), starterActions);
    assert.equal(fixture.form.dataset.resetting, undefined);
    assert.equal(fixture.confirmation(), null); assert.equal(fixture.input.value, "");
    assert.deepEqual(plain(fixture.context()), {});
    assert.equal(fixture.transcript.textContent.includes("Hotel"), false); assert.equal(fixture.document.activeElement, fixture.input);
    assert.deepEqual(fixture.requests[1].body, { expected_revision: 4 });
    fixture.closeChat(); fixture.open(); await flush(); assert.deepEqual(fixture.actionIds(), starterActions);
    assert.equal(fixture.requests.length, 2);
  });
  await checkAsync("failed and stale resets retain state and allow Cancel then continued typing", async () => {
    for (const result of [response({ success: false }, 500), response({ success: false }), response({}), response({ code: "stale_state" }, 409), () => { throw new Error("simulated connection failure"); }]) {
      const fixture = chatFixture({ history: [{ role: "user", content: "Existing hotel search" }], context: savedHotelAnswers, resetResponse: result });
      fixture.open(); await flush(); fixture.input.value = "My saved draft";
      const before = fixture.transcript.textContent; const guided = new fixture.Element("input", { "data-receptionist-group-input": "" }); fixture.choices.appendChild(guided);
      fixture.startOver(); fixture.confirmReset(); await flush();
      assert.deepEqual(fixture.actionIds(), []);
      assert.equal(fixture.transcript.textContent, before); assert.equal(fixture.input.value, "My saved draft");
      assert.deepEqual(plain(fixture.context()), savedHotelAnswers); assert.equal(fixture.choices.children[0], guided);
      assert.equal(fixture.confirmation().children[1].getAttribute("role"), "alert");
      assert.equal(fixture.document.activeElement.textContent, "Cancel");
      fixture.cancelReset(); assert.equal(fixture.document.activeElement, fixture.reset);
      fixture.closeChat(); fixture.open(); await flush(); assert.deepEqual(fixture.actionIds(), []);
      assert.equal(fixture.transcript.textContent, before); assert.equal(fixture.input.value, "My saved draft");
      fixture.form.requestSubmit(); await flush();
      assert.equal(fixture.requests[2].body.message, "My saved draft"); assert.equal(fixture.requests[2].body.context.group_size, 4);
      assert.equal(fixture.requests[2].body.context.start_date, savedHotelAnswers.startDate);
      assert.equal(fixture.requests[2].body.context.end_date, savedHotelAnswers.endDate);
    }
  });
  await checkAsync("reset dialog wraps forward and backward focus across visible enabled actions", async () => {
    const fixture = chatFixture(); fixture.open(); await flush(); fixture.startOver();
    const dialog = fixture.confirmation(); const [cancel, confirm] = dialog.querySelectorAll("button");
    const tab = shiftKey => { const event = { type: "keydown", key: "Tab", shiftKey, stopPropagation() {}, preventDefault() { this.prevented = true; } }; dialog.dispatchEvent(event); return event; };
    cancel.focus(); assert.equal(tab(true).prevented, true); assert.equal(fixture.document.activeElement, confirm);
    assert.equal(tab(false).prevented, true); assert.equal(fixture.document.activeElement, cancel);
    assert.equal(tab(false).prevented, undefined, "ordinary forward navigation remains native");
    confirm.focus(); assert.equal(tab(true).prevented, undefined, "ordinary backward navigation remains native");
    confirm.hidden = true; cancel.focus();
    assert.equal(tab(false).prevented, true); assert.equal(fixture.document.activeElement, cancel);
    assert.equal(tab(true).prevented, true); assert.equal(fixture.document.activeElement, cancel);
    confirm.hidden = false; confirm.disabled = true;
    assert.equal(tab(false).prevented, true); assert.equal(tab(true).prevented, true);
    assert.equal(fixture.document.activeElement, cancel);
  });
  await checkAsync("reset confirmation owns keyboard events and a failed attempt can be retried", async () => {
    let attempts = 0; const fixture = chatFixture({ history: [{ role: "user", content: "Keep this chat" }],
      resetResponse: () => ++attempts === 1 ? response({ success: false }, 503) : response({ success: true, revision: 5 }) });
    fixture.open(); await flush(); fixture.startOver();
    for (const key of ["Tab", "Escape"]) {
      const event = { type: "keydown", key, stopPropagation() { this.stopped = true; } }; fixture.confirmation().dispatchEvent(event);
      assert.equal(event.stopped, true, "the showroom must not handle native dialog keyboard events");
    }
    fixture.confirmReset(); await flush(); assert.equal(fixture.confirmation().querySelectorAll("button")[1].textContent, "Try again");
    fixture.confirmReset(); await flush(); assert.equal(attempts, 2); assert.equal(fixture.confirmation(), null);
    assert.deepEqual(fixture.actionIds(), starterActions); assert.equal(fixture.document.activeElement, fixture.input);
  });
  await checkAsync("cancelled opens stay closed after delayed history and keyboard Escape restores toggle focus", async () => {
    const history = deferred(); const fixture = chatFixture({ historyResponse: history.promise });
    fixture.open(); fixture.open(); history.resolve(response({ success: true, history: [] })); await flush();
    assert.equal(fixture.shell.hidden, true); assert.deepEqual(fixture.actionIds(), []);
    fixture.open(); await flush();
    fixture.root.dispatchEvent({ type: "keydown", key: "Escape", preventDefault() {}, stopPropagation() {} });
    assert.equal(fixture.shell.hidden, true); assert.equal(fixture.document.activeElement, fixture.toggle);
  });
  await checkAsync("keyboard Enter sends free text while Shift+Enter keeps editing", async () => {
    const fixture = chatFixture(); fixture.open(); await flush(); fixture.input.value = "Hotel for 3 guests";
    const key = shiftKey => ({ type: "keydown", key: "Enter", shiftKey, preventDefault() { this.prevented = true; } });
    const newline = key(true); fixture.input.dispatchEvent(newline); await flush();
    assert.equal(newline.prevented, undefined); assert.equal(fixture.requests.length, 1);
    const enter = key(false); fixture.input.dispatchEvent(enter); await flush();
    assert.equal(enter.prevented, true); assert.equal(fixture.requests[1].body.message, "Hotel for 3 guests");
  });
}

interactionChecks().then(() => {
  const failed = checks.filter(([, passed]) => !passed);
  checks.forEach(([label, passed, error]) => console.log(`${passed ? "PASS" : "FAIL"} - ${label}${error ? `: ${error}` : ""}`));
  console.log(`RESULT - ${checks.length} checks, ${failed.length} failures`);
  process.exitCode = failed.length ? 1 : 0;
});
