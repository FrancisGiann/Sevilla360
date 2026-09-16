(function (window, document) {
  "use strict";

  const STORAGE_KEY = "sevilla360-receptionist-chat-v1";
  const MAX_MESSAGE_LENGTH = 500;
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
    if (!root || !transcript || !form || !input || initializedRoot === root) return;
    initializedRoot = root;
    root.dataset.receptionistChatReady = "true";

    // Chat transcript is deliberately page-scoped. Remove the legacy
    // sessionStorage copy so a reload never resurrects another page's chat;
    // the separate guided context remains owned by showroom.js.
    let stored = { messages: [], context: {} };
    let suppressDialogueEvents = 0;
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (error) {}

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
    const setNonChatMode = active => {
      root.classList.toggle("is-chat-open", active);
      [root.querySelector(".receptionist-panel-head"), root.querySelector(".receptionist-message"), document.getElementById("receptionist-continue"), choices].filter(Boolean).forEach(element => {
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
      const next = Boolean(open) && !gated;
      chatOpen = next;
      if (!next && deferredGate) {
        gated = true;
        deferredGate = false;
      }
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
          if (transcript) transcript.scrollTop = transcript.scrollHeight;
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
      const allowed = { intent: "intent", occasion: "occasion", purpose: "purpose", groupSize: "group_size", group_size: "group_size", preference: "preference", startDate: "start_date", endDate: "end_date", activeVenueId: "active_venue_id", active_venue_id: "active_venue_id", activeRoomGroupId: "active_room_group_id", active_room_group_id: "active_room_group_id" };
      const normalizeGroupSize = value => {
        const text = String(value).trim();
        if (/^\d+$/.test(text)) return Number(text);
        const range = text.match(/^(\d+)\s*[-–]\s*(\d+)$/);
        return range ? Number(range[2]) : null;
      };
      return Object.keys(allowed).reduce((result, key) => {
        const mapped = allowed[key];
        if (source[key] === undefined || source[key] === null || source[key] === "") return result;
        const value = mapped === "group_size" ? normalizeGroupSize(source[key]) : source[key];
        if (mapped !== "group_size" || value !== null) result[mapped] = value;
        return result;
      }, {});
    };
    const save = () => {
      stored.messages = normalizeMessages(stored.messages);
      stored.context = safeContext();
    };
    const setStatus = message => { if (status) status.textContent = message || ""; };
    const appendMessage = (role, message, persist = true) => {
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
      bubble.textContent = content;
      transcript.appendChild(bubble);
      while (transcript.children.length > 16) transcript.firstElementChild.remove();
      transcript.scrollTop = transcript.scrollHeight;
      if (persist) {
        stored.messages = Array.isArray(stored.messages) ? stored.messages : [];
        stored.messages.push({ role: normalizedRole, content });
        stored.messages = normalizeMessages(stored.messages);
        save();
      }
    };
    const ensureGreeting = () => {
      stored.messages = normalizeMessages(stored.messages);
      if (stored.messages.length || transcript.children.length) return;
      appendMessage("assistant", greetingCopy[selectedLocale()]);
    };
    const renderQuickReplies = items => {
      quickReplies.replaceChildren();
      if (!Array.isArray(items)) return;
      items.slice(0, 4).forEach(item => {
        const label = typeof item === "string" ? item.trim() : "";
        if (!label) return;
        if (label === "Support FAQs") {
          const link = document.createElement("a");
          link.href = "support.php#faqs";
          link.className = "receptionist-chat-quick-reply";
          link.textContent = label;
          quickReplies.appendChild(link);
          return;
        }
        const button = document.createElement("button");
        button.type = "button";
        button.className = "receptionist-chat-quick-reply";
        button.textContent = label;
        quickReplies.appendChild(button);
      });
    };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const guidedFallback = (data, message) => {
      appendMessage("assistant", message || "I’ll keep the chat open while you choose a venue path below.");
      renderQuickReplies(Array.isArray(data?.quick_replies) && data.quick_replies.length ? data.quick_replies : ["Event", "Hotel", "Villa", "Support FAQs"]);
    };
    const submit = async message => {
      const clean = String(message || "").trim();
      if (!clean || clean.length > MAX_MESSAGE_LENGTH || form.dataset.busy === "true" || form.dataset.resetting === "true") return;
      if (looksSensitive(clean)) {
        setStatus(localeCopy[locale?.value] || localeCopy.en);
        input.focus();
        return;
      }
      appendMessage("user", clean);
      input.value = "";
      form.dataset.busy = "true";
      form.setAttribute("aria-busy", "true");
      if (send) send.disabled = true;
      setStatus("Checking the safest way to help…");
      const context = safeContext();
      stored.context = context;
      save();
      try {
        const resetReady = await serverResetPromise;
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
        if (response.status === 422 && data && data.code === "sensitive_input") {
          const last = transcript.lastElementChild;
          if (last?.dataset.role === "user") last.remove();
          if (Array.isArray(stored.messages)) stored.messages = stored.messages.slice(0, -1);
          save();
          setStatus(data.message || localeCopy[locale?.value] || localeCopy.en);
          input.focus();
          return;
        }
        if (response.status === 422 && data && data.code === "invalid_context") {
          stored.context = {};
          save();
          const handled = typeof options.onInvalidContext === "function" && options.onInvalidContext(data) === true;
          if (!handled) guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, data.message);
          return;
        }
        if (!response.ok || !data || data.success !== true) throw new Error(data?.message || "The receptionist is unavailable.");
        let keptGuidedStatus = false;
        if (data.mode === "guided") {
          guidedFallback(data, data.reply);
          setStatus("Guided choices are available in chat.");
          keptGuidedStatus = true;
        } else {
          const reply = typeof data.reply === "string" ? data.reply : "I can help with the guided venue choices.";
          suppressDialogueEvents++;
          if (data.mode === "knowledge" && typeof options.onKnowledge === "function") options.onKnowledge(data);
          else if (typeof options.onAction === "function") options.onAction(data);
          window.setTimeout(() => { if (suppressDialogueEvents > 0) suppressDialogueEvents--; }, 0);
          appendMessage("assistant", reply);
          renderQuickReplies(data.quick_replies);
        }
        if (!keptGuidedStatus) setStatus("");
      } catch (error) {
        guidedFallback({ quick_replies: ["Event", "Hotel", "Villa", "Support FAQs"] }, guidedCopy[locale?.value] || guidedCopy.en);
        setStatus("Guided choices are available.");
      } finally {
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

    serverResetPromise = resetServerSession();
    setChatOpen(false, false);
    renderQuickReplies(["Event", "Hotel", "Villa", "Support FAQs"]);
    save();
    form.addEventListener("submit", event => { event.preventDefault(); submit(input.value); });
    input.addEventListener("keydown", event => {
      if (event.key === "Enter" && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); }
    });
    quickReplies.addEventListener("click", event => {
      const button = event.target.closest(".receptionist-chat-quick-reply");
      if (!button) return;
      if (button.tagName === "A") return;
      const label = button.textContent.trim();
      if (typeof options.onQuickReply === "function" && options.onQuickReply(label) === true) return;
      input.value = label === "Support FAQs" ? "What policies and FAQs can you help with?" : label;
      form.requestSubmit();
    });
    root.addEventListener("click", event => {
      const toggle = event.target instanceof Element ? event.target.closest("[data-receptionist-chat-toggle]") : null;
      if (toggle) { setChatOpen(!chatOpen); return; }
      const close = event.target instanceof Element ? event.target.closest("[data-receptionist-chat-close]") : null;
      if (close) { setChatOpen(false); return; }
      const target = event.target instanceof Element ? event.target.closest("[data-receptionist-chat-start-over]") : null;
      if (target) {
        stored = { messages: [], context: {} };
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (error) {}
        transcript.replaceChildren();
        renderQuickReplies(["Event", "Hotel", "Villa", "Support FAQs"]);
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
      const guided = event.target instanceof Element ? event.target.closest(".receptionist-choices [data-receptionist-intent], .receptionist-choices [data-receptionist-answer], .receptionist-choices [data-receptionist-group-submit]") : null;
      if (chatOpen && guided && guided.textContent.trim()) appendMessage("user", guided.textContent.trim());
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
      if (message) appendMessage("assistant", message);
    });
    window.addEventListener("SevillaReceptionistGateChanged", event => setGated(Boolean(event.detail?.gated)));
    window.addEventListener("SevillaReceptionistClosed", () => setChatOpen(false, false));
    window.addEventListener("SevillaReceptionistOpening", () => setChatOpen(false, false));
    root.classList.add("has-receptionist-chat");
  }

  window.SevillaReceptionistChat = { init };
}(window, document));
