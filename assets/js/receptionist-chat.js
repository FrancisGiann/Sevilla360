(function (window, document) {
  "use strict";

  const MAX_MESSAGE_LENGTH = 500;
  // This is intentionally client-owned; response text and model payloads never supply navigation URLs.
  const SUPPORT_FAQ_HREF = "support.php#faqs";
  const SUPPORT_CONTACT_HREF = "support.php#contact";
  let initializedRoot = null;

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
      if (preferGuidedContent && choices && guidedContent?.contains(choices)) {
        const choicesTop = choices.getBoundingClientRect().top - scrollArea.getBoundingClientRect().top + scrollArea.scrollTop;
        scrollArea.scrollTop = Math.max(0, choicesTop);
        return;
      }
      scrollArea.scrollTop = scrollArea.scrollHeight;
    };

    // The visible transcript lives in memory. A bounded, sanitized copy can be
    // restored from the same PHP session after navigation or reload.
    let stored = { messages: [], context: {} };
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
    let serverResetPromise = Promise.resolve();
    let serverHistoryPromise = Promise.resolve();
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
          control.disabled = true;
        } else {
          const original = control.getAttribute("data-chat-gate-tabindex");
          if (original !== null) { if (original === "") control.removeAttribute("tabindex"); else control.setAttribute("tabindex", original); control.removeAttribute("data-chat-gate-tabindex"); }
          if (control !== send || form.dataset.busy !== "true") control.disabled = false;
        }
      });
    };

    const safeContext = () => {
      const source = typeof options.getContext === "function" ? options.getContext() : {};
      if (!source || typeof source !== "object") return {};
      const allowed = { intent: "intent", occasion: "occasion", purpose: "purpose", groupSizeExact: "group_size", groupSize: "group_size", group_size_exact: "group_size", group_size: "group_size", preference: "preference", startDate: "start_date", endDate: "end_date", activeVenueId: "active_venue_id", active_venue_id: "active_venue_id", activeRoomGroupId: "active_room_group_id", active_room_group_id: "active_room_group_id" };
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
        const response = await fetch("actions/public/receptionist_chat_history.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": csrf() },
          body: "{}"
        });
        const data = await response.json();
        if (generation !== conversationGeneration || !response.ok || data?.success !== true) return;
        const messages = normalizeMessages(data.history);
        if (!messages.length) return;
        transcript.replaceChildren();
        stored.messages = messages;
        stored.context = safeContext();
        messages.forEach(turn => appendMessage(turn.role, turn.content, false, null, false, false));
      } catch (error) {}
    };
    const quickActionLabels = {
      category_event_hall: "Event",
      category_hotel_room: "Hotel",
      category_resort_villa: "Villa",
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
    suggestedReplies.setAttribute("aria-label", "Suggested questions");
    quickReplies.replaceChildren(suggestedReplies);
    const hasActiveGuidedContent = () => Boolean(choices?.querySelector(
      "[data-receptionist-answer], [data-receptionist-group-submit], [data-receptionist-date-submit], .receptionist-calendar, .receptionist-hotel-results-grid, [data-receptionist-venue-card]"
    ));
    const removeRedundantPromptChips = () => {
      if (!hasActiveGuidedContent()) return;
      suggestedReplies.querySelectorAll("[data-receptionist-chat-prompt]").forEach(button => button.remove());
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
    const renderQuickReplies = (items, actionIds = []) => {
      suggestedReplies.replaceChildren();
      const addedActionIds = new Set();
      const addAction = id => {
        if (!["support_faqs", "retry_provider"].includes(id)
          || !Object.prototype.hasOwnProperty.call(quickActionLabels, id) || addedActionIds.has(id) || suggestedReplies.children.length >= 4) return;
        const button = document.createElement("button");
        button.type = "button";
        button.className = "receptionist-chat-quick-reply";
        button.dataset.receptionistQuickAction = id;
        button.textContent = quickActionLabels[id];
        suggestedReplies.appendChild(button);
        addedActionIds.add(id);
      };
      const addPrompt = prompt => {
        const label = typeof prompt === "string" ? prompt.trim() : "";
        if (!label || hasActiveGuidedContent() || suggestedReplies.children.length >= 4) return;
        const button = document.createElement("button");
        button.type = "button";
        button.className = "receptionist-chat-quick-reply";
        button.dataset.receptionistChatPrompt = label;
        button.textContent = label;
        suggestedReplies.appendChild(button);
      };
      (Array.isArray(actionIds) ? actionIds : []).forEach(addAction);
      (Array.isArray(items) ? items : []).slice(0, 4).forEach(item => {
        const label = typeof item === "string" ? item.trim() : "";
        if (!label) return;
        const actionId = legacyQuickActionIds[label.toLowerCase()];
        if (actionId) addAction(actionId); else addPrompt(label);
      });
      removeRedundantPromptChips();
    };
    if (choices && typeof MutationObserver === "function") {
      const guidedContentObserver = new MutationObserver(removeRedundantPromptChips);
      guidedContentObserver.observe(choices, { childList: true, subtree: true });
    }
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
    const waitForTypingMin = () => {
      const elapsed = Date.now() - typingShownAt;
      const remaining = TYPING_MIN_MS - elapsed;
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
      renderQuickReplies(Array.isArray(data?.quick_replies) ? data.quick_replies : [], ["category_event_hall", "category_hotel_room", "category_resort_villa", "support_faqs"]);
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
    const submit = async (message, displayMessage = message) => {
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
      try {
        const resetReady = await serverResetPromise;
        if (requestGeneration !== conversationGeneration) return;
        if (resetReady === false) {
          guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, guidedCopy[locale?.value] || guidedCopy.en);
          setStatus("Chat reset could not be confirmed; guided choices are still available.");
          return;
        }
        const response = await fetch("actions/public/receptionist_chat.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": csrf() },
          body: JSON.stringify({ message: clean, locale: locale?.value || "auto", context })
        });
        const data = await response.json();
        if (requestGeneration !== conversationGeneration) return;
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
        if (!response.ok || !data || data.success !== true) {
          const code = data?.fallback_code || data?.code || (response.status === 429 ? "busy" : response.status >= 500 ? "server_error" : "provider_unavailable");
          const codeCopy = fallbackCopy[code] || fallbackCopy.server_error;
          const last = transcript.lastElementChild;
          if (last?.dataset.role === "user") last.remove();
          stored.messages = normalizeMessages(stored.messages).filter(turn => !(turn.role === "user" && turn.content === visibleMessage));
          input.value = pendingMessage;
          pendingMessage = "";
          guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, codeCopy, code !== "visit_limit");
          setStatus(code === "busy" || code === "visit_limit" ? codeCopy : "You can retry without losing your message.");
          return;
        }
        input.value = "";
        pendingMessage = "";
        await waitForTypingMin();
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
          renderQuickReplies(data.quick_replies, data.quick_actions);
        }
        if (!keptGuidedStatus) setStatus("");
      } catch (error) {
        if (requestGeneration !== conversationGeneration) return;
        const last = transcript.lastElementChild;
        if (last?.dataset.role === "user") last.remove();
        stored.messages = normalizeMessages(stored.messages).filter(turn => !(turn.role === "user" && turn.content === visibleMessage));
        input.value = pendingMessage;
        pendingMessage = "";
        guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, "A network problem interrupted the receptionist. Retry when you’re ready; your message is still here.");
        setStatus("You can retry without losing your message.");
      } finally {
        removeTypingIndicator();
        form.dataset.busy = "false";
        form.removeAttribute("aria-busy");
        if (send) send.disabled = false;
      }
    };
    const resetServerSession = async () => {
      try {
        const response = await fetch("actions/public/receptionist_chat_reset.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": csrf() },
          body: "{}"
        });
        if (!response.ok) {
          setStatus("Chat was reset here, but the server reset could not be confirmed.");
          return false;
        }
        return true;
      } catch (error) {
        setStatus("Chat was reset here, but the server reset could not be confirmed.");
        return false;
      }
    };
    const categoryActionCommands = {
      category_event_hall: "event hall",
      category_hotel_room: "hotel room",
      category_resort_villa: "villa"
    };
    const submitCategoryAction = actionId => {
      const command = categoryActionCommands[actionId];
      if (!command || form.dataset.busy === "true" || form.dataset.resetting === "true") return false;
      void submit(command);
      return true;
    };

    serverHistoryPromise = restoreServerHistory();
    setChatOpen(false, false);
    renderQuickReplies([], ["category_event_hall", "category_hotel_room", "category_resort_villa", "support_faqs"]);
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
      const target = event.target instanceof Element ? event.target.closest("[data-receptionist-chat-start-over]") : null;
      if (target) {
        chatOpenGeneration++;
        conversationGeneration++;
        preferGuidedContent = false;
        serverHistoryPromise = Promise.resolve();
        pendingMessage = "";
        input.value = "";
        removeTypingIndicator();
        stored = { messages: [], context: {} };
        transcript.replaceChildren();
        renderQuickReplies([], ["category_event_hall", "category_hotel_room", "category_resort_villa", "support_faqs"]);
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
      if (chatOpen && event.isTrusted && guided && guided.textContent.trim()) appendMessage("user", guided.textContent.trim());
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
    window.addEventListener("SevillaReceptionistGateChanged", event => setGated(Boolean(event.detail?.gated)));
    window.addEventListener("SevillaReceptionistClosed", () => setChatOpen(false, false));
    window.addEventListener("SevillaReceptionistOpening", () => setChatOpen(false, false));
    root.classList.add("has-receptionist-chat");
  }

  window.SevillaReceptionistChat = { init };
}(window, document));
