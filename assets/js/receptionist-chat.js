(function (window, document) {
  "use strict";

  const MAX_MESSAGE_LENGTH = 500;
  // Natural mode is bounded to 12s and the legacy provider to 30s; leave room
  // for server work while ensuring a stalled HTTP request cannot leave the
  // chat spinner active forever.
  const CHAT_REQUEST_TIMEOUT_MS = 35000;
  // This is intentionally client-owned; response text and model payloads never supply navigation URLs.
  const SUPPORT_FAQ_HREF = "support.php#faqs";
  const SUPPORT_CONTACT_HREF = "support.php#contact";
  let initializedRoot = null;

  const fetchWithDeadline = (url, options, timeoutMs = CHAT_REQUEST_TIMEOUT_MS, consumeResponse = response => response) => {
    if (typeof window.AbortController !== "function") {
      let timer;
      const deadline = new Promise((resolve, reject) => {
        timer = window.setTimeout(() => {
          const error = new Error("The chat request exceeded its deadline.");
          error.name = "TimeoutError";
          reject(error);
        }, timeoutMs);
      });
      let request;
      try {
        request = fetch(url, options).then(consumeResponse);
      } catch (error) {
        window.clearTimeout(timer);
        throw error;
      }
      return Promise.race([request, deadline]).finally(() => window.clearTimeout(timer));
    }
    const controller = new window.AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    let request;
    try {
      request = fetch(url, { ...options, signal: controller.signal }).then(consumeResponse);
    } catch (error) {
      window.clearTimeout(timer);
      throw error;
    }
    return request.finally(() => window.clearTimeout(timer));
  };

  const readChatResponse = async response => {
    let body;
    try {
      body = await response.text();
    } catch (cause) {
      const error = new Error("The chat response could not be read.");
      error.name = cause?.name || "NetworkError";
      error.httpStatus = Number(response.status) || 0;
      throw error;
    }
    try {
      return JSON.parse(body);
    } catch {
      const error = new Error("The response was not valid JSON.");
      error.httpStatus = Number(response.status) || 0;
      throw error;
    }
  };
  const fetchJsonWithDeadline = (url, options) => fetchWithDeadline(url, options, CHAT_REQUEST_TIMEOUT_MS,
    async response => ({ response, data: await readChatResponse(response) }));
  const isRetryableCommittedReply = message => /(?:I’m having trouble understanding that just now|Nagkaproblema ako sa pag-intindi ngayon|Nagka-issue ako sa pag-intindi ngayon|chat service is unavailable right now|receptionist service timed out|temporary server problem|connection problem with the chat service|Hindi sumagot ang service sa oras|Hindi available ang chat service ngayon|May temporary server problem|May connection issue ang chat service)/iu.test(String(message || ""));

  const resolveBookingSuggestions = data => {
    const missing = Array.isArray(data?.missing_slots) ? data.missing_slots : [];
    const step = missing.find(value => typeof value === "string") || "";
    if (step === "active_room_group_id") {
      return { items: Array.isArray(data.quick_replies) ? data.quick_replies.slice(0, 4) : [], actions: [], contextual: true };
    }
    if (step === "room_type_code") return { items: ["Standard Room", "Dormitory Room", "Family Room / Superior", "Deluxe", "VIP Suite", "Any room type"], actions: [], contextual: true, maxItems: 6 };
    if (data?.booking_continuation !== true && data?.mode !== "natural") return null;
    const choices = {
      occasion: ["Wedding", "Birthday", "Corporate", "Other"],
      purpose: ["Family", "Private", "Relaxation"],
      preference: ["Best fit", "Lowest price", "Comfort", "No preference"]
    };
    if (step === "intent") {
      const categoryActions = ["category_event_hall", "category_hotel_room", "category_resort_villa"];
      const serverActions = Array.isArray(data.quick_actions) ? data.quick_actions : [];
      const actions = categoryActions.filter(action => serverActions.includes(action));
      return { items: [], actions: actions.length ? actions : categoryActions, contextual: true };
    }
    if (Object.prototype.hasOwnProperty.call(choices, step)) {
      return { items: choices[step], actions: [], contextual: true };
    }
    return { items: [], actions: [], contextual: true };
  };

  const applyDateSlotPatch = (context, slots, clearSlots) => {
    if (!context || typeof context !== "object") return context;
    const source = slots && typeof slots === "object" ? slots : {};
    if (source.start_date !== undefined && source.start_date !== null && source.start_date !== "") context.startDate = source.start_date;
    if (source.end_date !== undefined && source.end_date !== null && source.end_date !== "") context.endDate = source.end_date;
    if (Array.isArray(clearSlots)) {
      if (clearSlots.includes("start_date")) context.startDate = null;
      if (clearSlots.includes("end_date")) context.endDate = null;
    }
    return context;
  };

  const applyGateDisabledState = (control, gated, originalStates, keepDisabled = false) => {
    if (gated) {
      if (!originalStates.has(control)) originalStates.set(control, Boolean(control.disabled));
      control.disabled = true;
      return;
    }
    if (!originalStates.has(control)) return;
    const wasDisabled = originalStates.get(control);
    originalStates.delete(control);
    control.disabled = wasDisabled || keepDisabled;
  };

  function init(options) {
    options = options || {};
    const root = options.root || document.getElementById("showroom-receptionist");
    const transcript = document.getElementById("receptionist-chat-transcript");
    const form = document.getElementById("receptionist-chat-form");
    const input = document.getElementById("receptionist-chat-input");
    const locale = document.getElementById("receptionist-chat-locale");
    const send = document.getElementById("receptionist-chat-send");
    const status = document.getElementById("receptionist-chat-status");
    const quickReplies = document.getElementById("receptionist-chat-quick-replies");
    const chatShell = document.getElementById("receptionist-chat-shell");
    const categorySelect = root.querySelector("[data-receptionist-chat-category-select]");
    const chatToggle = root.querySelector("[data-receptionist-chat-toggle]");
    const choices = options.choices || document.getElementById("receptionist-choices");
    const chatConversation = document.getElementById("receptionist-chat-conversation");
    const guidedContent = document.getElementById("receptionist-chat-guided-content");
    if (!root || !transcript || !form || !input || initializedRoot === root || root.dataset.receptionistChatReady === "true") return;
    initializedRoot = root;
    root.dataset.receptionistChatReady = "true";

    const choicesHome = choices?.parentNode || null;
    const choicesHomeNext = choices?.nextSibling || null;
    const choicesHomeAnchor = choicesHome ? document.createComment("receptionist-choices-home") : null;
    if (choicesHome && choicesHomeAnchor) choicesHome.insertBefore(choicesHomeAnchor, choices);
    let preferGuidedContent = false;
    const moveChoicesIntoChat = () => {
      if (choices && guidedContent && choices.parentNode !== guidedContent) guidedContent.appendChild(choices);
    };
    const restoreChoicesHome = () => {
      if (!choices || !choicesHome) return;
      if (choicesHomeAnchor?.parentNode) {
        choicesHomeAnchor.parentNode.insertBefore(choices, choicesHomeAnchor.nextSibling);
      } else {
        const next = choicesHomeNext?.parentNode === choicesHome ? choicesHomeNext : null;
        choicesHome.insertBefore(choices, next);
      }
    };
    const scrollConversationToEnd = () => {
      const scrollArea = chatConversation || transcript;
      if (!scrollArea) return;
      const activeCalendar = chatOpen ? choices?.querySelector(".receptionist-calendar") : null;
      if (activeCalendar && chatConversation) {
        const calendarTop = activeCalendar.getBoundingClientRect().top - scrollArea.getBoundingClientRect().top + scrollArea.scrollTop;
        scrollArea.scrollTop = Math.max(0, calendarTop - 8);
        return;
      }
      if (preferGuidedContent && root.classList.contains("has-chat-guided-content") && choices && guidedContent?.contains(choices)) {
        const choicesTop = choices.getBoundingClientRect().top - scrollArea.getBoundingClientRect().top + scrollArea.scrollTop;
        scrollArea.scrollTop = Math.max(0, choicesTop);
        return;
      }
      scrollArea.scrollTop = scrollArea.scrollHeight;
    };

    // The visible transcript lives in memory. A bounded, sanitized copy can be
    // restored from the same PHP session after navigation or reload.
    let stored = { messages: [], context: {}, revision: 0 };
    let suppressDialogueEvents = 0;

    const normalizeMessages = messages => {
      if (!Array.isArray(messages)) return [];
      const normalized = [];
      messages.forEach(turn => {
        if (!turn || typeof turn.content !== "string") return;
        const content = turn.content.trim();
        if (!content) return;
        const role = turn.role === "user" ? "user" : "assistant";
        const previous = normalized[normalized.length - 1];
        if (previous && previous.role === role && previous.content === content) return;
        normalized.push({ role, content });
      });
      return normalized.slice(-16);
    };
    stored.messages = normalizeMessages(stored.messages);
    let chatOpen = false;
    let gated = true;
    let deferredGate = false;
    const gateDisabledStates = new WeakMap();
    let serverResetPromise = Promise.resolve();
    let serverHistoryPromise = Promise.resolve();
    let guidedSyncPromise = Promise.resolve();
    let conversationGeneration = 0;
    let chatOpenGeneration = 0;
    let pendingChatOpen = false;
    let pendingMessage = "";
    const setNonChatMode = active => {
      root.classList.toggle("is-chat-open", active);
      [root.querySelector(".receptionist-panel-head"), root.querySelector(".receptionist-message"), document.getElementById("receptionist-continue")].filter(Boolean).forEach(element => {
        element.setAttribute("aria-hidden", active ? "true" : (element === choices && gated ? "true" : "false"));
        if ("inert" in element && !(element === choices && gated)) element.inert = active;
        element.querySelectorAll("button, a[href], input, select, textarea, [tabindex]").forEach(control => {
          if (active) {
            if (!control.hasAttribute("data-chat-mode-tabindex")) control.setAttribute("data-chat-mode-tabindex", control.getAttribute("tabindex") ?? "");
            control.setAttribute("tabindex", "-1");
          } else if (control.hasAttribute("data-chat-mode-tabindex")) {
            const original = control.getAttribute("data-chat-mode-tabindex");
            if (original === "") control.removeAttribute("tabindex"); else control.setAttribute("tabindex", original);
            control.removeAttribute("data-chat-mode-tabindex");
          }
        });
      });
    };
    const setChatOpen = (open, restoreFocus = true) => {
      chatOpenGeneration++;
      const next = Boolean(open) && !gated;
      chatOpen = next;
      if (!next) pendingChatOpen = false;
      if (!next && deferredGate) {
        gated = true;
        deferredGate = false;
      }
      if (!next) preferGuidedContent = false;
      if (next) moveChoicesIntoChat();
      else restoreChoicesHome();
      syncGuidedContent();
      setNonChatMode(next);
      if (chatShell) {
        chatShell.hidden = !next;
        chatShell.classList.toggle("is-open", next);
        chatShell.setAttribute("aria-hidden", next ? "false" : "true");
      }
      if (chatToggle) {
        chatToggle.hidden = gated || next;
        chatToggle.disabled = gated;
        chatToggle.setAttribute("aria-expanded", next ? "true" : "false");
      }
      if (next) {
        ensureGreeting();
        window.requestAnimationFrame(() => {
          input.focus();
          scrollConversationToEnd();
        });
        void serverHistoryPromise.then(() => {
          if (chatOpen) window.dispatchEvent(new CustomEvent("SevillaReceptionistChatOpened"));
        });
      } else if (restoreFocus && !gated && chatToggle && !chatToggle.hidden) {
        chatToggle.focus();
      }
    };

    const localeCopy = {
      en: "For your privacy, please do not share payment, account, contact, or personal details.",
      fil: "Para sa privacy mo, huwag magpadala ng payment, account, contact, o personal details.",
      taglish: "For your privacy, huwag mag-share ng payment, account, contact, or personal details."
    };
    const greetingCopy = {
      en: "Hi, I’m your virtual receptionist. Ask me about venues, stays, dates, or policies, and I’ll guide you.",
      fil: "Hi, ako ang virtual receptionist ninyo. Maaari kang magtanong tungkol sa venues, stays, dates, o policies, at gagabayan kita.",
      taglish: "Hi, ako ang virtual receptionist ninyo. Ask me about venues, stays, dates, or policies, and I’ll guide you."
    };
    const selectedLocale = () => (locale?.value === "fil" || locale?.value === "taglish") ? locale.value : "en";
    const guidedCopy = {
      en: "I can still guide you through the venue choices. What would you like to explore?",
      fil: "Maaari pa rin kitang gabayan sa venue choices. Ano ang gusto mong tuklasin?",
      taglish: "Maaari pa rin kitang i-guide sa venue choices. Ano ang gusto mong i-explore?"
    };
    const looksSensitive = message => /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i.test(message)
      || /(?<!\d)(?:\+?63|0)9\d{9}(?!\d)/.test(message.replace(/\D+/g, ""))
      || /\b(?:password|passcode|one[- ]time password|otp|pin)\b/i.test(message)
      || (/\b(?:phone|mobile|contact|call|number|whatsapp|viber)\b/i.test(message) && (message.match(/\d/g) || []).length >= 8)
      || /\b(?:transaction|reference|account|receipt|payment|gcash|maya|card)\b[^\n]{0,24}\b(?=[A-Z0-9-]*\d)[A-Z0-9-]{5,}\b/i.test(message)
      || /\b(?:\d[ -]?){13,19}\b/.test(message);
    const setGated = value => {
      const nextGated = Boolean(value);
      // Guided action rendering can temporarily gate the deterministic
      // choices while typed chat is active. Keep the active chat surface open;
      // apply that gate when the visitor closes chat or the receptionist does.
      if (nextGated && chatOpen) {
        deferredGate = true;
        return;
      }
      gated = nextGated;
      deferredGate = false;
      if (!chatShell) return;
      chatShell.classList.toggle("is-gated", gated);
      chatShell.setAttribute("aria-hidden", gated || !chatOpen ? "true" : "false");
      if ("inert" in chatShell) chatShell.inert = gated;
      if (chatToggle) {
        chatToggle.hidden = gated || chatOpen;
        chatToggle.disabled = gated;
        chatToggle.setAttribute("aria-expanded", chatOpen ? "true" : "false");
      }
      chatShell.querySelectorAll("textarea, select, button").forEach(control => {
        if (gated) {
          if (!control.hasAttribute("data-chat-gate-tabindex")) control.setAttribute("data-chat-gate-tabindex", control.getAttribute("tabindex") ?? "");
          control.setAttribute("tabindex", "-1");
          applyGateDisabledState(control, true, gateDisabledStates);
        } else {
          const original = control.getAttribute("data-chat-gate-tabindex");
          if (original !== null) { if (original === "") control.removeAttribute("tabindex"); else control.setAttribute("tabindex", original); control.removeAttribute("data-chat-gate-tabindex"); }
          applyGateDisabledState(control, false, gateDisabledStates, control === send && form.dataset.busy === "true");
        }
      });
    };

    const safeContext = () => {
      const source = typeof options.getContext === "function" ? options.getContext() : {};
      if (!source || typeof source !== "object") return {};
      const allowed = { intent: "intent", occasion: "occasion", purpose: "purpose", groupSizeExact: "group_size", groupSize: "group_size", group_size_exact: "group_size", group_size: "group_size", preference: "preference", roomTypeCode: "room_type_code", startDate: "start_date", endDate: "end_date", activeVenueId: "active_venue_id", active_venue_id: "active_venue_id", activeRoomGroupId: "active_room_group_id", active_room_group_id: "active_room_group_id" };
      const normalizeGroupSize = value => {
        const text = String(value).trim();
        if (/^\d+$/.test(text)) return Number(text);
        // A display range is not an exact guest count. It is intentionally
        // omitted unless guideContext supplies groupSizeExact separately.
        return null;
      };
      const result = Object.keys(allowed).reduce((result, key) => {
        const mapped = allowed[key];
        if (source[key] === undefined || source[key] === null || source[key] === "") return result;
        const value = mapped === "group_size" ? normalizeGroupSize(source[key]) : source[key];
        if (mapped !== "group_size" || value !== null) result[mapped] = value;
        return result;
      }, {});
      const exact = source.groupSizeExact ?? source.group_size_exact;
      const exactCount = exact === undefined || exact === null || exact === "" ? null : normalizeGroupSize(exact);
      if (exactCount !== null) result.group_size = exactCount;
      return result;
    };
    const guidedContextPayload = () => {
      const source = typeof options.getContext === "function" ? options.getContext() : {};
      if (!source || typeof source !== "object") return {};
      const keys = ["intent", "occasion", "purpose", "groupSizeExact", "preference", "roomTypeCode", "roomTypeRequested", "startDate", "endDate", "activeVenueId", "activeRoomGroupId"];
      return keys.reduce((payload, key) => {
        const value = source[key];
        payload[key] = value === undefined || value === "" ? null : value;
        return payload;
      }, {});
    };
    const save = () => {
      stored.messages = normalizeMessages(stored.messages);
      stored.context = safeContext();
    };
    const setStatus = message => { if (status) status.textContent = message || ""; };
    const appendMessage = (role, message, persist = true, action = null, showSupportFaqCta = false, animate = true, showSupportContactCta = false) => {
      if (!message || typeof message !== "string") return;
      const normalizedRole = role === "user" ? "user" : "assistant";
      const content = message.trim();
      if (!content) return;
      const previousBubble = transcript.lastElementChild;
      if (previousBubble && previousBubble.dataset.role === normalizedRole && previousBubble.textContent === content) {
        if (persist) { stored.messages = normalizeMessages(stored.messages); save(); }
        return;
      }
      const bubble = document.createElement("div");
      bubble.className = "receptionist-chat-message";
      bubble.dataset.role = normalizedRole;
      bubble.setAttribute("role", "article");
      
      if (normalizedRole === "assistant" && animate) {
        const srText = document.createElement("span");
        srText.className = "sr-only";
        srText.style.userSelect = "none";
        srText.textContent = content;
        bubble.appendChild(srText);
        
        const visibleText = document.createElement("span");
        visibleText.setAttribute("aria-hidden", "true");
        bubble.appendChild(visibleText);
        
        transcript.appendChild(bubble);
        while (transcript.children.length > 16) transcript.firstElementChild.remove();
        scrollConversationToEnd();

        let i = 0;
        const typeChar = () => {
          if (document.hidden) i = content.length; // Skip animation if tab is hidden
          if (i < content.length) {
            i += 3; // 3 chars per frame = ~180 chars/sec at 60fps
            visibleText.textContent = content.substring(0, i);
            scrollConversationToEnd();
            requestAnimationFrame(typeChar);
          } else {
             visibleText.textContent = content;
             if ((action === "faq" || showSupportFaqCta === true || showSupportContactCta === true)
               && !bubble.querySelector("[data-receptionist-support-faq], [data-receptionist-support-contact]")) {
               const link = document.createElement("a");
               link.className = "receptionist-chat-inline-link";
               if (showSupportContactCta === true) {
                 link.href = SUPPORT_CONTACT_HREF;
                 link.dataset.receptionistSupportContact = "true";
                 link.textContent = "Contact reception";
               } else {
                 link.href = SUPPORT_FAQ_HREF;
                 link.dataset.receptionistSupportFaq = "true";
                 link.textContent = showSupportFaqCta === true ? "View Support & FAQs" : "View all FAQs";
               }
               bubble.appendChild(link);
               scrollConversationToEnd();
             }
             if (persist) {
               stored.messages = Array.isArray(stored.messages) ? stored.messages : [];
               stored.messages.push({ role: normalizedRole, content });
               stored.messages = normalizeMessages(stored.messages);
               save();
             }
          }
        };
        requestAnimationFrame(typeChar);
      } else {
        bubble.textContent = content;
        transcript.appendChild(bubble);
        while (transcript.children.length > 16) transcript.firstElementChild.remove();
        scrollConversationToEnd();
        if (persist) {
          stored.messages = Array.isArray(stored.messages) ? stored.messages : [];
          stored.messages.push({ role: normalizedRole, content });
          stored.messages = normalizeMessages(stored.messages);
          save();
        }
      }
    };
    const ensureGreeting = () => {
      stored.messages = normalizeMessages(stored.messages);
      if (stored.messages.length || transcript.children.length) return;
      appendMessage("assistant", greetingCopy[selectedLocale()]);
    };
    const restoreServerHistory = async () => {
      const generation = conversationGeneration;
      try {
        const { response, data } = await fetchJsonWithDeadline("actions/public/receptionist_chat_history.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": csrf() },
          body: "{}"
        });
        if (generation !== conversationGeneration || !response.ok || data?.success !== true) return;
        if (Number.isSafeInteger(Number(data.revision)) && Number(data.revision) >= 0) stored.revision = Number(data.revision);
        const messages = normalizeMessages(data.history);
        if (data.natural_state && typeof options.onRestoreState === "function") options.onRestoreState(data.natural_state);
        transcript.replaceChildren();
        stored.messages = messages;
        stored.context = safeContext();
        messages.forEach(turn => appendMessage(turn.role, turn.content, false, null, false, false));
        return true;
      } catch (error) {}
      return false;
    };
    const quickActionLabels = {
      category_event_hall: "Event halls",
      category_hotel_room: "Hotel rooms",
      category_resort_villa: "Resort villas",
      support_faqs: "Support FAQs",
      venue_details: "See details",
      venue_change: "Change venue",
      venue_list: "Browse venues",
      start_over: "Start over",
      retry_provider: "Retry"
    };
    const suggestedReplies = document.createElement("div");
    suggestedReplies.className = "receptionist-chat-quick-reply-group receptionist-chat-suggested-actions";
    suggestedReplies.setAttribute("role", "group");
    suggestedReplies.setAttribute("aria-label", "Suggested replies");
    quickReplies.replaceChildren(suggestedReplies);
    const hasActiveGuidedContent = () => Boolean(choices?.querySelector(
      ".receptionist-calendar, .receptionist-hotel-results-grid, [data-receptionist-venue-card], [data-receptionist-room], [data-receptionist-answer], [data-receptionist-group-input]"
    ));
    const removeRedundantPromptChips = () => {
      if (!hasActiveGuidedContent()) return;
      suggestedReplies.querySelectorAll('[data-receptionist-chat-prompt]:not([data-receptionist-contextual-choice="true"])').forEach(button => button.remove());
    };
    const syncGuidedContent = () => {
      const visibleGuidance = hasActiveGuidedContent();
      root.classList.toggle("has-chat-guided-content", visibleGuidance);
      if (visibleGuidance) removeRedundantPromptChips();
    };
    const legacyQuickActionIds = {
      event: "category_event_hall",
      "event hall": "category_event_hall",
      hotel: "category_hotel_room",
      villa: "category_resort_villa",
      "support faqs": "support_faqs",
      "see details": "venue_details",
      "change venue": "venue_change",
      "see event halls": "venue_list",
      "browse venues": "venue_list",
      "start over": "start_over"
    };
    const renderQuickReplies = (items, actionIds = [], contextualChoices = false, maxItems = 4) => {
      suggestedReplies.replaceChildren();
      const limit = Math.max(1, Math.min(6, Number(maxItems) || 4));
      const addedActionIds = new Set();
      const addAction = id => {
        const safeSuggestionAction = ["category_event_hall", "category_hotel_room", "category_resort_villa", "support_faqs", "retry_provider"].includes(id);
        if (!safeSuggestionAction || addedActionIds.has(id) || suggestedReplies.children.length >= limit) return;
        const button = document.createElement("button");
        button.type = "button";
        button.className = "receptionist-chat-quick-reply";
        button.dataset.receptionistQuickAction = id;
        button.textContent = quickActionLabels[id];
        suggestedReplies.appendChild(button);
        addedActionIds.add(id);
      };
      const addPrompt = (prompt, contextual = false) => {
        const label = typeof prompt === "string" ? prompt.trim() : "";
        if (!label || (hasActiveGuidedContent() && !contextual) || suggestedReplies.children.length >= limit) return;
        const button = document.createElement("button");
        button.type = "button";
        button.className = "receptionist-chat-quick-reply";
        button.dataset.receptionistChatPrompt = label;
        if (contextual) button.dataset.receptionistContextualChoice = "true";
        button.textContent = label;
        suggestedReplies.appendChild(button);
      };
      (Array.isArray(actionIds) ? actionIds : []).forEach(addAction);
      (Array.isArray(items) ? items : []).slice(0, limit).forEach(item => {
        const label = typeof item === "string" ? item.trim() : "";
        if (!label) return;
        const actionId = legacyQuickActionIds[label.toLowerCase()];
        if (actionId === "support_faqs") addAction(actionId);
        else addPrompt(label, contextualChoices);
      });
      removeRedundantPromptChips();
    };
    root.sevillaReceptionistShowGuidedChoices = items => renderQuickReplies(items, [], true, 6);
    if (choices && typeof MutationObserver === "function") {
      const guidedContentObserver = new MutationObserver(syncGuidedContent);
      guidedContentObserver.observe(choices, { childList: true, subtree: true });
    }
    syncGuidedContent();
    let typingShownAt = 0;
    const TYPING_MIN_MS = 800;
    const showTypingIndicator = () => {
      removeTypingIndicator();
      typingShownAt = Date.now();
      const indicator = document.createElement("div");
      indicator.className = "receptionist-typing-indicator";
      indicator.id = "receptionist-typing";
      indicator.setAttribute("role", "status");
      indicator.setAttribute("aria-label", "Receptionist is typing");
      indicator.innerHTML = '<span class="dot"></span><span class="dot"></span><span class="dot"></span>';
      transcript.appendChild(indicator);
      scrollConversationToEnd();
    };
    const waitForTypingMin = (minimumMs = TYPING_MIN_MS) => {
      const elapsed = Date.now() - typingShownAt;
      const remaining = minimumMs - elapsed;
      return remaining > 0 ? new Promise(resolve => setTimeout(resolve, remaining)) : Promise.resolve();
    };
    const removeTypingIndicator = () => {
      const existing = document.getElementById("receptionist-typing");
      if (existing) existing.remove();
    };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const fallbackCopy = {
      busy: "The receptionist is busy right now. You can retry in a moment; your message is still here.",
      visit_limit: "This visit has reached its chat limit. Your message is still here; use the guided choices or contact reception.",
      invalid_request: "Please check your message and try again.",
      invalid_context: "Those saved booking details are no longer valid. Please choose the venue path again.",
      provider_timeout: "The receptionist timed out. Retry when you’re ready; your message is still here.",
      provider_unavailable: "The receptionist service is unavailable. Retry when you’re ready; your message is still here.",
      network_error: "A network problem interrupted the receptionist. Retry when you’re ready; your message is still here.",
      server_error: "A temporary server problem interrupted the receptionist. Retry when you’re ready; your message is still here."
    };
    const guidedFallback = (data, message, retryable = false) => {
      appendMessage("assistant", message || "I’ll keep the chat open while you choose a venue path below.");
      const hasBookingContext = Boolean(safeContext().intent);
      const recoveryActions = hasBookingContext
        ? ["support_faqs"]
        : ["category_event_hall", "category_hotel_room", "category_resort_villa", "support_faqs"];
      renderQuickReplies([], recoveryActions);
      if (retryable) {
        if (suggestedReplies.children.length >= 4) suggestedReplies.lastElementChild.remove();
        const retry = document.createElement("button");
        retry.type = "button";
        retry.className = "receptionist-chat-quick-reply receptionist-chat-retry";
        retry.dataset.receptionistQuickAction = "retry_provider";
        retry.textContent = quickActionLabels.retry_provider;
        suggestedReplies.appendChild(retry);
      }
    };
    const submit = async (message, displayMessage = message, actionId = null) => {
      const clean = String(message || "").trim();
      if (!clean || clean.length > MAX_MESSAGE_LENGTH || form.dataset.busy === "true" || form.dataset.resetting === "true") return;
      preferGuidedContent = false;
      const requestGeneration = conversationGeneration;
      if (looksSensitive(clean)) {
        setStatus(localeCopy[locale?.value] || localeCopy.en);
        input.focus();
        return;
      }
      form.dataset.busy = "true";
      form.setAttribute("aria-busy", "true");
      if (send) send.disabled = true;
      setStatus("");
      await serverHistoryPromise;
      await guidedSyncPromise;
      if (requestGeneration !== conversationGeneration || form.dataset.resetting === "true") {
        form.dataset.busy = "false";
        form.removeAttribute("aria-busy");
        if (send) send.disabled = false;
        return;
      }
      const visibleMessage = String(displayMessage || clean).trim();
      appendMessage("user", visibleMessage);
      pendingMessage = clean;
      showTypingIndicator();
      const context = safeContext();
      stored.context = context;
      save();
      let requestResponseParsed = false;
      try {
        const resetReady = await serverResetPromise;
        if (requestGeneration !== conversationGeneration) return;
        if (resetReady === false) {
          guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, guidedCopy[locale?.value] || guidedCopy.en);
          setStatus("Chat reset could not be confirmed; guided choices are still available.");
          return;
        }
        const requestBody = { message: clean, locale: locale?.value || "auto", context, expected_revision: stored.revision };
        if (["category_event_hall", "category_hotel_room", "category_resort_villa"].includes(actionId)) requestBody.action_id = actionId;
        const { response, data } = await fetchJsonWithDeadline("actions/public/receptionist_chat.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": csrf() },
          body: JSON.stringify(requestBody)
        });
        requestResponseParsed = true;
        if (requestGeneration !== conversationGeneration) return;
        if (response.status === 409 && data?.code === "stale_state") {
          const restored = await restoreServerHistory();
          if (requestGeneration !== conversationGeneration) return;
          const latestMessages = normalizeMessages(stored.messages);
          const latestUserTurn = [...latestMessages].reverse().find(turn => turn.role === "user");
          const latestAssistantTurn = [...latestMessages].reverse().find(turn => turn.role === "assistant");
          if (restored && latestUserTurn?.content === clean && !isRetryableCommittedReply(latestAssistantTurn?.content)) {
            input.value = "";
            pendingMessage = "";
            renderQuickReplies([], []);
            setStatus("Your earlier message was received; its latest reply is above.");
            return;
          }
          input.value = clean;
          pendingMessage = clean;
          if (restored && latestUserTurn?.content === clean) {
            guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] },
              "The previous reply could not be completed. Your message is still here; retry when you’re ready.", true);
            setStatus("The earlier reply was unavailable. Retry keeps your message and uses the refreshed conversation.");
          } else {
            setStatus(restored
              ? "Conversation refreshed. Your message is still in the box; send it again to continue."
              : "I couldn’t refresh the latest chat just now. Your message is still in the box.");
          }
          return;
        }
        if (response.status === 422 && data && data.code === "sensitive_input") {
          const last = transcript.lastElementChild;
          if (last?.dataset.role === "user") last.remove();
          if (Array.isArray(stored.messages)) stored.messages = stored.messages.slice(0, -1);
          save();
          input.value = pendingMessage;
          pendingMessage = "";
          setStatus(data.message || localeCopy[locale?.value] || localeCopy.en);
          input.focus();
          return;
        }
        if (response.status === 422 && data && data.code === "invalid_context") {
          stored.context = {};
          save();
          input.value = pendingMessage;
          pendingMessage = "";
          const handled = typeof options.onInvalidContext === "function" && options.onInvalidContext(data) === true;
          if (!handled) guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, data.message);
          return;
        }
        const naturalFallbackCode = typeof data?.fallback_code === "string" ? data.fallback_code : "";
        if (data?.mode === "natural" && naturalFallbackCode && data.retryable === true) {
          if (Number.isSafeInteger(Number(data.revision)) && Number(data.revision) >= 0) stored.revision = Number(data.revision);
          if (data.natural_state && typeof options.onNaturalState === "function") options.onNaturalState(data.natural_state);
          const last = transcript.lastElementChild;
          if (last?.dataset.role === "user") last.remove();
          stored.messages = normalizeMessages(stored.messages).filter(turn => !(turn.role === "user" && turn.content === visibleMessage));
          input.value = clean;
          pendingMessage = clean;
          const message = fallbackCopy[naturalFallbackCode] || fallbackCopy.server_error;
          guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, message, true);
          setStatus("You can retry without losing your message.");
          return;
        }
        if (!response.ok || !data || data.success !== true) {
          const code = data?.fallback_code || data?.code || (response.status === 429 ? "busy" : response.status >= 500 ? "server_error" : "provider_unavailable");
          const codeCopy = fallbackCopy[code] || fallbackCopy.server_error;
          const retryable = code !== "visit_limit";
          const last = transcript.lastElementChild;
          if (last?.dataset.role === "user") last.remove();
          stored.messages = normalizeMessages(stored.messages).filter(turn => !(turn.role === "user" && turn.content === visibleMessage));
          input.value = pendingMessage;
          pendingMessage = retryable ? clean : "";
          guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, codeCopy, retryable);
          setStatus(code === "busy" || code === "visit_limit" ? codeCopy : "You can retry without losing your message.");
          return;
        }
        input.value = "";
        pendingMessage = "";
        if (Number.isSafeInteger(Number(data.revision)) && Number(data.revision) >= 0) stored.revision = Number(data.revision);
        if (data.natural_state && typeof options.onNaturalState === "function") options.onNaturalState(data.natural_state);
        await waitForTypingMin(data.local_reply === true ? 120 : TYPING_MIN_MS);
        if (requestGeneration !== conversationGeneration) return;
        removeTypingIndicator();
        let keptGuidedStatus = false;
        if (data.mode === "guided") {
          const fallbackCode = typeof data.fallback_code === "string" ? data.fallback_code : "";
          if (fallbackCode && data.retryable !== false) {
            const last = transcript.lastElementChild;
            if (last?.dataset.role === "user") last.remove();
            stored.messages = normalizeMessages(stored.messages).filter(turn => !(turn.role === "user" && turn.content === visibleMessage));
            input.value = clean;
            pendingMessage = clean;
          }
          guidedFallback(data, data.reply, Boolean(fallbackCode && data.retryable !== false));
          setStatus(fallbackCopy[fallbackCode] || "Guided choices are available in chat.");
          keptGuidedStatus = true;
        } else {
          const reply = typeof data.reply === "string" ? data.reply : "I can help with the guided venue choices.";
          suppressDialogueEvents++;
          if (data.mode === "knowledge" && typeof options.onKnowledge === "function") options.onKnowledge(data);
          else if (data.action === "ask" && typeof options.onKnowledge === "function") options.onKnowledge({ ...data, booking_continuation: false });
          else if (typeof options.onAction === "function") options.onAction(data);
          window.setTimeout(() => { if (suppressDialogueEvents > 0) suppressDialogueEvents--; }, 0);
          appendMessage("assistant", reply, true, data.action, data.show_support_faq_cta === true, true, data.show_support_contact_cta === true);
          const bookingSuggestions = resolveBookingSuggestions(data);
          if (bookingSuggestions) renderQuickReplies(bookingSuggestions.items, bookingSuggestions.actions, bookingSuggestions.contextual, bookingSuggestions.maxItems);
          else renderQuickReplies(data.quick_replies, []);
        }
        if (!keptGuidedStatus) setStatus("");
      } catch (error) {
        if (requestGeneration !== conversationGeneration) return;
        const last = transcript.lastElementChild;
        if (last?.dataset.role === "user") last.remove();
        stored.messages = normalizeMessages(stored.messages).filter(turn => !(turn.role === "user" && turn.content === visibleMessage));
        const timedOut = error?.name === "AbortError" || error?.name === "TimeoutError";
        const status = Number(error?.httpStatus) || 0;
        const serverFailure = status >= 500 && status <= 599;
        const retryable = timedOut || serverFailure || (error?.name === "TypeError" && !requestResponseParsed);
        input.value = clean;
        pendingMessage = retryable ? clean : "";
        const recoveryMessage = timedOut
          ? "The receptionist is taking longer than expected. Your message is still here; retry when you’re ready."
          : serverFailure
            ? "The chat service is temporarily unavailable. Your message is still here; retry when you’re ready."
            : "A network problem interrupted the receptionist. Retry when you’re ready; your message is still here.";
        guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, recoveryMessage, retryable);
        setStatus(serverFailure || timedOut
          ? "The request may still be finishing. Use Retry once it is ready."
          : "You can retry without losing your message.");
      } finally {
        removeTypingIndicator();
        form.dataset.busy = "false";
        form.removeAttribute("aria-busy");
        if (send) send.disabled = false;
      }
    };
    const resetServerSession = async () => {
      try {
        const { response, data } = await fetchWithDeadline("actions/public/receptionist_chat_reset.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": csrf() },
          body: JSON.stringify({ expected_revision: stored.revision })
        }, CHAT_REQUEST_TIMEOUT_MS, async response => ({ response, data: await response.json().catch(() => ({})) }));
        if (response.status === 409 && data?.code === "stale_state") {
          await restoreServerHistory();
          setStatus("Conversation refreshed. Start over again to clear the latest details.");
          return false;
        }
        if (!response.ok) {
          setStatus("Chat was reset here, but the server reset could not be confirmed.");
          return false;
        }
        if (Number.isSafeInteger(Number(data.revision)) && Number(data.revision) >= 0) stored.revision = Number(data.revision);
        return true;
      } catch (error) {
        setStatus("Chat was reset here, but the server reset could not be confirmed.");
        return false;
      }
    };
    const syncGuidedContext = () => {
      if (root.dataset.receptionistNaturalEnabled !== "true") return Promise.resolve(null);
      const operation = guidedSyncPromise.then(async () => {
        await serverHistoryPromise;
        if (form.dataset.resetting === "true") return null;
        try {
        const { response, data } = await fetchWithDeadline("actions/public/receptionist_chat.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": csrf() },
          body: JSON.stringify({
            message: "guided search update",
            locale: locale?.value || "auto",
            context: safeContext(),
            guided_context: guidedContextPayload(),
            action_id: "guided_search_update",
            expected_revision: stored.revision
          })
        }, CHAT_REQUEST_TIMEOUT_MS, async response => ({ response, data: await response.json().catch(() => null) }));
        if (response.status === 409 && data?.code === "stale_state") {
          await restoreServerHistory();
          setStatus("Conversation refreshed. Please retry the guided search action.");
          return null;
        }
        if (!response.ok || data?.success !== true) return null;
        if (Number.isSafeInteger(Number(data.revision)) && Number(data.revision) >= 0) stored.revision = Number(data.revision);
        if (data.natural_state && typeof options.onNaturalState === "function") options.onNaturalState(data.natural_state);
        const guidedReply = typeof data.reply === "string" ? data.reply.trim() : "";
        const guidedUserMessage = typeof data.guided_user_message === "string" ? data.guided_user_message.trim() : "";
        if (guidedReply || guidedUserMessage) window.dispatchEvent(new CustomEvent("SevillaReceptionistGuidedServerReply", {
          detail: { reply: guidedReply, user_message: guidedUserMessage, revision: data.revision }
        }));
        return data;
        } catch (error) { return null; }
      });
      guidedSyncPromise = operation.then(() => undefined, () => undefined);
      return operation;
    };
    root.sevillaReceptionistSyncGuidedContext = syncGuidedContext;
    const categoryActionCommands = {
      category_event_hall: "event hall",
      category_hotel_room: "hotel room",
      category_resort_villa: "villa"
    };
    const submitCategoryAction = actionId => {
      const command = categoryActionCommands[actionId];
      if (!command || form.dataset.busy === "true" || form.dataset.resetting === "true") return false;
      void submit(command, command, actionId);
      return true;
    };

    categorySelect?.addEventListener("change", () => {
      const actionId = categorySelect.value;
      categorySelect.value = "";
      if (!categoryActionCommands[actionId]) return;
      categorySelect.closest("details")?.removeAttribute("open");
      suppressDialogueEvents++;
      try { submitCategoryAction(actionId); }
      finally {
        window.setTimeout(() => { if (suppressDialogueEvents > 0) suppressDialogueEvents--; }, 0);
        input.focus();
      }
    });

    serverHistoryPromise = restoreServerHistory();
    setChatOpen(false, false);
    renderQuickReplies([], []);
    save();
    form.addEventListener("submit", event => { event.preventDefault(); submit(input.value); });
    input.addEventListener("keydown", event => {
      if (event.key === "Enter" && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); }
    });
    quickReplies.addEventListener("click", event => {
      const button = event.target.closest(".receptionist-chat-quick-reply");
      if (!button) return;
      const actionId = button.dataset.receptionistQuickAction;
      if (actionId === "retry_provider") {
        if (pendingMessage) form.requestSubmit();
        else input.focus();
        return;
      }
      if (actionId) {
        const isCategoryAction = Object.prototype.hasOwnProperty.call(categoryActionCommands, actionId);
        if (isCategoryAction) suppressDialogueEvents++;
        const handled = typeof options.onQuickAction === "function" && options.onQuickAction(actionId, prompt => submit(prompt)) === true;
        if (isCategoryAction) window.setTimeout(() => { if (suppressDialogueEvents > 0) suppressDialogueEvents--; }, 0);
        if (handled) {
          if (Object.prototype.hasOwnProperty.call(categoryActionCommands, actionId)) submitCategoryAction(actionId);
          else if (actionId !== "start_over" && actionId !== "support_faqs") appendMessage("user", button.textContent.trim());
        }
        return;
      }
      const prompt = button.dataset.receptionistChatPrompt;
      if (!prompt) return;
      input.value = prompt;
      form.requestSubmit();
    });
    root.addEventListener("click", event => {
      const toggle = event.target instanceof Element ? event.target.closest("[data-receptionist-chat-toggle]") : null;
      if (toggle) {
        if (chatOpen) { setChatOpen(false); return; }
        if (pendingChatOpen) {
          pendingChatOpen = false;
          chatOpenGeneration++;
          return;
        }
        pendingChatOpen = true;
        const openGeneration = ++chatOpenGeneration;
        // Guide state is stored separately from chat history. Reconcile it
        // before revealing chat so stale recommendations never paint beside
        // a fresh greeting while the history request is still in flight.
        serverHistoryPromise.then(() => {
          if (!pendingChatOpen || openGeneration !== chatOpenGeneration || chatOpen) return;
          pendingChatOpen = false;
          const hasPriorConversation = stored.messages.some(turn => turn.role === "user");
          if (!hasPriorConversation && typeof options.onFreshChatOpen === "function") options.onFreshChatOpen();
          ensureGreeting();
          setChatOpen(true);
        });
        return;
      }
      const close = event.target instanceof Element ? event.target.closest("[data-receptionist-chat-close]") : null;
      if (close) { setChatOpen(false); return; }
      const categoryTool = event.target instanceof Element ? event.target.closest(".receptionist-chat-tools [data-receptionist-chat-category]") : null;
      if (categoryTool) {
        categoryTool.closest("details")?.removeAttribute("open");
        const actionId = categoryTool.dataset.receptionistChatCategory;
        suppressDialogueEvents++;
        submitCategoryAction(actionId);
        window.setTimeout(() => { if (suppressDialogueEvents > 0) suppressDialogueEvents--; }, 0);
        input.focus();
        return;
      }
      const promptTool = event.target instanceof Element ? event.target.closest(".receptionist-chat-tools [data-receptionist-chat-prompt]") : null;
      if (promptTool) {
        promptTool.closest("details")?.removeAttribute("open");
        submit(promptTool.dataset.receptionistChatPrompt || "");
        input.focus();
        return;
      }
      const target = event.target instanceof Element ? event.target.closest("[data-receptionist-chat-start-over]") : null;
      if (target) {
        target.closest("details")?.removeAttribute("open");
        chatOpenGeneration++;
        conversationGeneration++;
        preferGuidedContent = false;
        serverHistoryPromise = Promise.resolve();
        pendingMessage = "";
        input.value = "";
        removeTypingIndicator();
        stored = { messages: [], context: {}, revision: stored.revision };
        transcript.replaceChildren();
        renderQuickReplies([], []);
        form.dataset.resetting = "true";
        serverResetPromise = resetServerSession().finally(() => { delete form.dataset.resetting; });
        if (typeof options.onStartOver === "function") {
          suppressDialogueEvents++;
          try { options.onStartOver(); } finally { if (suppressDialogueEvents > 0) suppressDialogueEvents--; }
        }
        ensureGreeting();
        if (chatOpen) input.focus();
        return;
      }
      const guidedCategory = event.target instanceof Element ? event.target.closest(".receptionist-choices [data-receptionist-intent]") : null;
      if (chatOpen && event.isTrusted && guidedCategory) {
        const actionId = {
          "Event Hall": "category_event_hall",
          "Hotel Room": "category_hotel_room",
          "Resort Villa": "category_resort_villa"
        }[guidedCategory.getAttribute("data-receptionist-intent")];
        if (actionId) {
          suppressDialogueEvents++;
          submitCategoryAction(actionId);
          window.setTimeout(() => { if (suppressDialogueEvents > 0) suppressDialogueEvents--; }, 0);
        }
        return;
      }
      const guided = event.target instanceof Element ? event.target.closest(".receptionist-choices [data-receptionist-answer], .receptionist-choices [data-receptionist-group-submit]") : null;
      if (chatOpen && root.dataset.receptionistNaturalEnabled !== "true" && event.isTrusted && guided && guided.textContent.trim()) {
        appendMessage("user", guided.textContent.trim());
      }
    });
    root.addEventListener("keydown", event => {
      if (event.key !== "Escape" || !chatOpen || !chatShell?.contains(document.activeElement)) return;
      event.preventDefault();
      event.stopPropagation();
      setChatOpen(false);
    });
    window.addEventListener("SevillaReceptionistGuidedState", event => {
      if (suppressDialogueEvents > 0) { suppressDialogueEvents--; return; }
      if (!chatOpen) return;
      const message = event.detail && typeof event.detail.message === "string" ? event.detail.message : "";
      if (message) {
        preferGuidedContent = Boolean(choices && guidedContent?.contains(choices));
        appendMessage("assistant", message);
      }
    });
    window.addEventListener("SevillaReceptionistGuidedServerReply", event => {
      if (!chatOpen) return;
      const userMessage = event.detail && typeof event.detail.user_message === "string" ? event.detail.user_message.trim() : "";
      const reply = event.detail && typeof event.detail.reply === "string" ? event.detail.reply : "";
      if (userMessage) appendMessage("user", userMessage);
      if (reply) {
        preferGuidedContent = Boolean(choices && guidedContent?.contains(choices));
        appendMessage("assistant", reply);
      }
    });
    window.addEventListener("SevillaReceptionistGateChanged", event => setGated(Boolean(event.detail?.gated)));
    window.addEventListener("SevillaReceptionistClosed", () => setChatOpen(false, false));
    window.addEventListener("SevillaReceptionistOpening", () => setChatOpen(false, false));
    root.classList.add("has-receptionist-chat");
  }

  window.SevillaReceptionistChat = { init, resolveBookingSuggestions, applyDateSlotPatch, applyGateDisabledState };
}(window, document));
