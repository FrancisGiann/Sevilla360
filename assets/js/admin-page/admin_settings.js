/**
 * SEVILLA360 - Admin Settings Controller
 */
document.addEventListener("DOMContentLoaded", () => {
    
  // =========================================================
  // 1. TAB SWITCHING LOGIC
  // =========================================================
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const tabLinks = document.querySelectorAll(".tab-link");
  const settingsPanels = document.querySelectorAll(".settings-panel");
  const settingsTabSelect = document.getElementById("settingsTabSelect");

  function activateSettingsPanel(targetId) {
    if (!targetId) return;
    tabLinks.forEach((tab) => tab.classList.toggle("active", tab.getAttribute("data-target") === targetId));
    settingsPanels.forEach((panel) => panel.classList.toggle("active", panel.id === targetId));
    if (settingsTabSelect) settingsTabSelect.value = targetId;
  }

  tabLinks.forEach((link) => {
    link.addEventListener("click", () => {
      activateSettingsPanel(link.getAttribute("data-target"));
    });
  });

  settingsTabSelect?.addEventListener("change", () => activateSettingsPanel(settingsTabSelect.value));

  // =========================================================
  // 2. TOAST NOTIFICATION UTILITY
  // =========================================================
  const toast = document.getElementById("settings-toast");
  let toastTimeout;

  function showToast() {
    clearTimeout(toastTimeout);
    toast.classList.add("show");
    toastTimeout = setTimeout(() => toast.classList.remove("show"), 3000);
  }

  document.getElementById("btn-save-profile")?.addEventListener("click", function() {
      const button = this;
      const name = document.getElementById("prof-name").value;
      const phone = document.getElementById("prof-contact").value;
      const currPass = document.getElementById("prof-curr-pass").value;
      const newPass = document.getElementById("prof-new-pass").value;
      const confPass = document.getElementById("prof-conf-pass").value;

      if (newPass !== '' && newPass !== confPass) {
          if (window.showAlert) window.showAlert("Notice", "New password and confirm password do not match.", "error");
          else alert("New password and confirm password do not match.");
          return;
      }
      if (newPass !== '') {
          const passwordPolicy = window.SevillaPasswordPolicy?.validate(newPass);
          if (!passwordPolicy || !passwordPolicy.valid) {
              if (window.showAlert) window.showAlert("Notice", passwordPolicy?.message || "Password does not meet the required policy.", "error");
              else alert(passwordPolicy?.message || "Password does not meet the required policy.");
              return;
          }
      }

      const originalText = button.innerHTML;
      button.innerHTML = "Saving...";
      button.disabled = true;

      fetch("actions/admin/save_profile.php", {
          method: "POST",
          headers: {
              "Content-Type": "application/json",
              "X-CSRF-Token": csrfToken
          },
          body: JSON.stringify({ name, phone, curr_pass: currPass, new_pass: newPass, conf_pass: confPass })
      })
      .then(res => res.json())
      .then(data => {
          button.innerHTML = originalText;
          button.disabled = false;
          if (data.success) {
              if (window.showAlert) window.showAlert("Success", data.message);
              else showToast();
              
              document.getElementById("prof-curr-pass").value = "";
              document.getElementById("prof-new-pass").value = "";
              document.getElementById("prof-conf-pass").value = "";
          } else {
              if (window.showAlert) window.showAlert("Notice", data.message);
              else alert(data.message);
          }
      })
      .catch(err => {
          button.innerHTML = originalText;
          button.disabled = false;
          if (window.showAlert) window.showAlert("Notice", "Failed to save profile.");
      });
  });

  document.querySelectorAll('.password-toggle').forEach(toggle => {
    toggle.addEventListener('click', () => {
      const input = document.getElementById(toggle.dataset.target);
      if (!input) return;
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      toggle.textContent = showing ? 'Show' : 'Hide';
      toggle.setAttribute('aria-label', `${showing ? 'Show' : 'Hide'} ${input.labels?.[0]?.textContent.toLowerCase() || 'password'}`);
    });
  });

  // =========================================================
  // 3. SYSTEM PREFERENCES (AJAX SAVE)
  // =========================================================
  const btnSavePrefs = document.getElementById("btn-save-prefs");
  const formPrefs = document.getElementById("form-prefs");
  const socialList = document.getElementById("social-links-list");
  const socialJson = document.getElementById("social-links-json");
  let isFormDirty = false;

  function addSocialRow() {
    if (!socialList) return;
    const row = document.createElement("div");
    row.className = "social-link-row";
    row.innerHTML = '<input type="text" class="form-control social-label" placeholder="Platform (e.g. Facebook)" maxlength="40"><input type="url" class="form-control social-url" placeholder="https://..." maxlength="500"><button type="button" class="btn btn-danger btn-remove-social">Remove</button>';
    socialList.appendChild(row);
    isFormDirty = true;
  }
  function collectSocialLinks() {
    return [...(socialList?.querySelectorAll('.social-link-row') || [])].map(row => ({label: row.querySelector('.social-label')?.value.trim() || '', url: row.querySelector('.social-url')?.value.trim() || ''})).filter(item => item.label || item.url);
  }
  document.getElementById('btn-add-social')?.addEventListener('click', addSocialRow);
  socialList?.addEventListener('click', event => { const button = event.target.closest('.btn-remove-social'); if (button) { button.closest('.social-link-row')?.remove(); isFormDirty = true; } });

  if (btnSavePrefs && formPrefs) {
    formPrefs.addEventListener("change", () => isFormDirty = true);

    btnSavePrefs.addEventListener("click", () => {
      const feeInput = document.getElementById('refund-fee-percent');
      const feeValue = feeInput?.value.trim() || '';
      if (feeInput && (!/^(?:\d+(?:\.\d{1,2})?|\.\d{1,2})$/.test(feeValue) || Number(feeValue) < 0 || Number(feeValue) > 100 || !Number.isFinite(Number(feeValue)))) {
        feeInput.focus();
        if (window.showAlert) window.showAlert('Invalid fee', 'Enter a payment-processing fee between 0 and 100.', 'error');
        return;
      }
      const originalText = btnSavePrefs.innerHTML;
      btnSavePrefs.innerHTML = "Saving...";
      btnSavePrefs.style.opacity = "0.8";
      btnSavePrefs.style.pointerEvents = "none";

      if (socialJson) socialJson.value = JSON.stringify(collectSocialLinks());
      const formData = new FormData(formPrefs);

      fetch("actions/admin/save_preferences.php", {
        method: "POST",
        headers: { "X-CSRF-Token": csrfToken },
        body: formData,
      })
      .then(res => res.text())
      .then(data => {
          btnSavePrefs.innerHTML = originalText;
          btnSavePrefs.style.opacity = "1";
          btnSavePrefs.style.pointerEvents = "auto";

          if (data.trim() === "Success") {
            showToast();
            isFormDirty = false;
          } else {
            showAlert("Notice", data);
          }
      })
      .catch(error => {
          showAlert("Notice", "System error. Could not save settings.");
          console.error(error);
      });
    });
  }

  // =========================================================
  // 3B. PUBLIC SUPPORT CONTENT
  // =========================================================
  const supportForm = document.getElementById('form-support-content');
  const supportFaqList = document.getElementById('support-faq-list');
  const btnSaveSupport = document.getElementById('btn-save-support');

  function addSupportFaqRow(question = '', answer = '') {
    if (!supportFaqList) return;
    const row = document.createElement('div');
    row.className = 'support-faq-row';
    row.innerHTML = `<div class="form-group"><label>Question</label><input type="text" class="form-control support-faq-question" placeholder="Question" maxlength="240"></div><div class="form-group"><label>Answer</label><textarea class="form-control support-faq-answer" placeholder="Answer" rows="3"></textarea></div><button type="button" class="btn btn-danger btn-remove-support-faq">Remove</button>`;
    row.querySelector('.support-faq-question').value = question;
    row.querySelector('.support-faq-answer').value = answer;
    supportFaqList.appendChild(row);
    isFormDirty = true;
  }
  document.getElementById('btn-add-support-faq')?.addEventListener('click', () => addSupportFaqRow());
  supportFaqList?.addEventListener('click', event => {
    const button = event.target.closest('.btn-remove-support-faq');
    if (button) { button.closest('.support-faq-row')?.remove(); isFormDirty = true; }
  });
  btnSaveSupport?.addEventListener('click', () => {
    const originalText = btnSaveSupport.innerHTML;
    const faqItems = [...(supportFaqList?.querySelectorAll('.support-faq-row') || [])].map(row => ({
      question: row.querySelector('.support-faq-question')?.value.trim() || '',
      answer: row.querySelector('.support-faq-answer')?.value.trim() || ''
    })).filter(item => item.question || item.answer);
    const formData = new FormData(supportForm);
    formData.append('support_faq_json', JSON.stringify(faqItems));
    btnSaveSupport.innerHTML = 'Saving...';
    btnSaveSupport.disabled = true;
    fetch('actions/admin/save_support_content.php', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: formData })
      .then(res => res.json())
      .then(data => {
        btnSaveSupport.innerHTML = originalText;
        btnSaveSupport.disabled = false;
        if (data.success) { showToast(); isFormDirty = false; }
        else if (window.showAlert) window.showAlert('Notice', data.message);
      })
      .catch(() => { btnSaveSupport.innerHTML = originalText; btnSaveSupport.disabled = false; if (window.showAlert) window.showAlert('Notice', 'System error. Could not save support content.'); });
  });

  // =========================================================
  // 4. UNSAVED CHANGES MODAL PROTECTION
  // =========================================================
  const unsavedModal = document.getElementById("unsaved-modal");
  let pendingUrl = "";

  window.addEventListener("beforeunload", function (e) {
    if (isFormDirty) {
      const msg = "You have unsaved changes. Are you sure you want to leave?";
      e.returnValue = msg;
      return msg;
    }
  });

  document.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", function (e) {
      const href = this.getAttribute("href");
      if (href && href !== "#" && !href.startsWith("javascript") && isFormDirty) {
          e.preventDefault();
          pendingUrl = href;
          unsavedModal.classList.add("active");
      }
    });
  });

  document.getElementById("btn-stay-save")?.addEventListener("click", () => unsavedModal.classList.remove("active"));
  document.getElementById("btn-discard-leave")?.addEventListener("click", () => {
      isFormDirty = false; 
      window.location.href = pendingUrl;
  });

  // =========================================================
  // 5. MANAGE VENUES MODAL & AJAX
  // =========================================================
  const venueModal = document.getElementById("venueModal");
  const formVenue = document.getElementById("form-venue");
  const catSelect = document.getElementById("vm-category");

  function setVenueFieldsState(container, enabled) {
    container?.querySelectorAll("input, select, textarea").forEach((field) => {
      field.disabled = !enabled;
      field.required = enabled && field.dataset.required !== "false";
    });
  }

  function toggleDynamicFields(category) {
    document.querySelectorAll(".vm-dynamic").forEach((el) => {
      el.style.display = "none";
      setVenueFieldsState(el, false);
    });

    let targetClass = "";
    if (category === "Event Hall") targetClass = ".vm-event";
    else if (category === "Hotel Room") targetClass = ".vm-hotel";
    else if (category === "Resort Villa") targetClass = ".vm-villa";

    const isEditMode = document.getElementById("vm-id").value !== "";
    if (!targetClass) return;

    document.querySelectorAll(targetClass).forEach((el) => {
      const isBulkSection = el.classList.contains("vm-bulk-section");
      if (isBulkSection && (category !== "Hotel Room" || isEditMode)) return;
      el.style.display = "block";
      setVenueFieldsState(el, true);
    });

    if (category === "Hotel Room" && bulkFields) {
      const bulkEnabled = Boolean(bulkToggle?.checked) && !isEditMode;
      bulkFields.style.display = bulkEnabled ? "grid" : "none";
      setVenueFieldsState(bulkFields, bulkEnabled);
      if (roomNumberField) {
        roomNumberField.parentElement.style.display = bulkEnabled ? "none" : "block";
        roomNumberField.disabled = bulkEnabled;
        roomNumberField.required = !bulkEnabled;
      }
    }
  }

  // Bulk creation toggle logic
  const bulkToggle = document.getElementById("vm-hr-bulk-toggle");
  const bulkFields = document.getElementById("vm-hr-bulk-fields");
  const bulkQty = document.getElementById("vm-hr-bulk-qty");
  const bulkStart = document.getElementById("vm-hr-bulk-start");
  const roomNumberField = document.getElementById("vm-hr-room-number");

  if (bulkToggle) {
      bulkToggle.addEventListener("change", function() {
          const enabled = this.checked;
          bulkFields.style.display = enabled ? "grid" : "none";
          setVenueFieldsState(bulkFields, enabled);
          roomNumberField.disabled = enabled;
          roomNumberField.required = !enabled;
          roomNumberField.parentElement.style.display = enabled ? "none" : "block";
      });
  }

  if (catSelect) {
    catSelect.addEventListener("change", function () { toggleDynamicFields(this.value); });
  }

  document.getElementById("btn-add-venue")?.addEventListener("click", () => {
    formVenue.reset();
    document.getElementById("vm-id").value = "";
    document.getElementById("vm-title").innerText = "Add New Venue";
    catSelect.disabled = false;
    
    // Reset bulk toggle specifically
    if (bulkToggle) {
        bulkToggle.checked = false;
        bulkToggle.dispatchEvent(new Event('change'));
    }

    toggleDynamicFields("");
    venueModal.classList.add("active");
  });

  document.querySelectorAll(".btn-edit-venue").forEach((btn) => {
    btn.addEventListener("click", function () {
      const venueData = window.allVenuesData.find((v) => v.id === this.getAttribute("data-id"));
      if (!venueData) return showAlert("Notice", "Error loading venue data.");

      document.getElementById("vm-title").innerText = "Edit Venue";
      document.getElementById("vm-id").value = venueData.id;
      document.getElementById("vm-name").value = venueData.name;
      document.getElementById("vm-status").value = venueData.status;
      document.getElementById("vm-desc").value = venueData.description || "";
      document.getElementById("vm-amenities").value = venueData.amenities || "";

      catSelect.value = venueData.category;
      catSelect.disabled = true; // Protects DB relational integrity
      
      // Ensure bulk is off and hidden during edit
      if (bulkToggle) {
          bulkToggle.checked = false;
          bulkToggle.dispatchEvent(new Event('change'));
      }

      toggleDynamicFields(venueData.category);

      if (venueData.category === "Event Hall") {
        document.getElementById("vm-base-cap").value = venueData.eh_base;
        document.getElementById("vm-max-cap").value = venueData.eh_max;
        document.getElementById("vm-eh-rate").value = venueData.base_rate;
        document.getElementById("vm-eh-theater").value = venueData.capacity_theater || 0;
        document.getElementById("vm-eh-classroom").value = venueData.capacity_classroom || 0;
        document.getElementById("vm-eh-banquet").value = venueData.capacity_banquet || 0;
      } else if (venueData.category === "Hotel Room") {
        document.getElementById("vm-base-cap").value = venueData.hr_base;
        document.getElementById("vm-max-cap").value = venueData.hr_max;
        document.getElementById("vm-hr-type").value = venueData.room_type;
        document.getElementById("vm-hr-rate").value = venueData.nightly_rate;
        document.getElementById("vm-hr-room-number").value = venueData.room_number || "";
        document.getElementById("vm-hr-bed-count").value = venueData.bed_count || 1;
        document.getElementById("vm-hr-check-in").value = (venueData.check_in_time || "14:00:00").slice(0, 5);
        document.getElementById("vm-hr-check-out").value = (venueData.check_out_time || "12:00:00").slice(0, 5);
        document.getElementById("vm-extra-pax").value = venueData.hr_extra;
      } else if (venueData.category === "Resort Villa") {
        document.getElementById("vm-base-cap").value = venueData.vi_base;
        document.getElementById("vm-max-cap").value = venueData.vi_max;
        document.getElementById("vm-vi-day").value = venueData.day_rate;
        document.getElementById("vm-vi-night").value = venueData.overnight_rate;
        document.getElementById("vm-vi-pool").checked = Number(venueData.has_private_pool) === 1;
        document.getElementById("vm-vi-day-check-in").value = (venueData.day_check_in_time || "07:00:00").slice(0, 5);
        document.getElementById("vm-vi-day-check-out").value = (venueData.day_check_out_time || "17:00:00").slice(0, 5);
        document.getElementById("vm-vi-night-check-in").value = (venueData.overnight_check_in_time || "14:00:00").slice(0, 5);
        document.getElementById("vm-vi-night-check-out").value = (venueData.overnight_check_out_time || "12:00:00").slice(0, 5);
        document.getElementById("vm-vi-day-inclusions").value = venueData.day_stay_inclusions || "";
        document.getElementById("vm-vi-night-inclusions").value = venueData.overnight_stay_inclusions || "";
        document.getElementById("vm-extra-pax").value = venueData.vi_extra;
      }

      venueModal.classList.add("active");
    });
  });

  document.getElementById("btn-close-vmodal")?.addEventListener("click", () => venueModal.classList.remove("active"));
  window.addEventListener('click', (e) => { if (e.target === venueModal) venueModal.classList.remove('active'); });

  if (formVenue) {
    formVenue.addEventListener("submit", function (e) {
      e.preventDefault();

      // Client-side validation for bulk room start number format
      if (bulkToggle && bulkToggle.checked) {
          const startNum = bulkStart.value.trim();
          const match = startNum.match(/^([A-Za-z]+-)?(\d+)$/);
          if (!match) {
              showAlert("Notice", "Starting room number must be numeric (e.g. 101) or have a prefix (e.g. A-101).");
              return;
          }
      }

      const submitBtn = document.getElementById("btn-save-venue");
      const originalText = submitBtn.innerText;
      submitBtn.innerText = "Saving...";
      submitBtn.disabled = true;

      const formData = new FormData(this);
      formData.append("category", catSelect.value); // Re-append because disabled selects aren't sent

      // If bulk is toggled, append an explicit flag for safety
      if (bulkToggle && bulkToggle.checked) {
          formData.append("is_bulk", "1");
      }

      fetch("actions/admin/save_venue.php", { 
          method: "POST", 
          headers: { "X-CSRF-Token": csrfToken },
          body: formData 
      })
      .then(res => res.json())
      .then(data => {
          if (data.success) {
            showAlert("Notice", data.message);
            window.location.reload();
          } else {
            showAlert("Notice", "Error: " + data.message);
            submitBtn.innerText = originalText;
            submitBtn.disabled = false;
          }
      })
      .catch(err => {
          console.error(err);
          showAlert("Notice", "Network error.");
          submitBtn.innerText = originalText;
          submitBtn.disabled = false;
      });
    });
  }

  // =========================================================
  // 6. VENUE TABLE FILTERING LOGIC
  // =========================================================
  const venueFilters = document.querySelectorAll('#venueFilters .venue-filter-btn');
  const venueRows = document.querySelectorAll('.venue-row');
  const venueGroups = document.querySelectorAll('.venue-group-row');

  if (venueFilters.length > 0) {
      const searchInput = document.getElementById('venue-search-input');
      let currentFilter = 'all';

      function applyFilters() {
          const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
          venueRows.forEach(row => {
              const matchesCategory = currentFilter === 'all' || row.getAttribute('data-category') === currentFilter;
              const rowText = row.querySelector('td').textContent.toLowerCase();
              const matchesSearch = searchTerm === '' || rowText.includes(searchTerm);
              const isRoom = row.classList.contains('room-row');
              const groupId = row.getAttribute('data-group-id');
              const group = groupId ? document.querySelector(`.venue-group-toggle[aria-controls="hotel-group-${groupId}"]`) : null;
              const groupText = group ? group.textContent.toLowerCase() : '';
              const matchesGroupSearch = groupText.includes(searchTerm);
              const expanded = group?.getAttribute('aria-expanded') === 'true';

              if (isRoom) {
                  row.classList.toggle('room-row-collapsed', !(matchesCategory && expanded && (matchesSearch || matchesGroupSearch)));
              } else {
                  row.style.display = matchesCategory && matchesSearch ? '' : 'none';
              }
          });

          venueGroups.forEach(group => {
              const groupId = group.querySelector('.venue-group-toggle')?.getAttribute('aria-controls')?.replace('hotel-group-', '');
              const children = [...venueRows].filter(row => row.getAttribute('data-group-id') === groupId);
              const groupText = group.textContent.toLowerCase();
              const hasMatchingChild = children.some(row => row.querySelector('td').textContent.toLowerCase().includes(searchTerm));
              const matchesSearch = searchTerm === '' || groupText.includes(searchTerm) || hasMatchingChild;
              group.style.display = currentFilter === 'all' || currentFilter === 'Hotel Room' ? (matchesSearch ? '' : 'none') : 'none';
          });
      }

      venueGroups.forEach(group => {
          const toggle = group.querySelector('.venue-group-toggle');
          toggle?.addEventListener('click', () => {
              const expanded = toggle.getAttribute('aria-expanded') === 'true';
              toggle.setAttribute('aria-expanded', String(!expanded));
              toggle.querySelector('.venue-group-arrow').textContent = expanded ? '▸' : '▾';
              const groupId = toggle.getAttribute('aria-controls')?.replace('hotel-group-', '');
              const children = [...venueRows].filter(row => row.getAttribute('data-group-id') === groupId);
              const countLabel = toggle.querySelector('.venue-group-count');
              if (countLabel) countLabel.textContent = expanded ? 'Rooms are collapsed' : `${children.length} room${children.length === 1 ? '' : 's'}`;
              applyFilters();
              children.forEach(row => row.classList.toggle('room-row-collapsed', expanded));
          });
      });

      venueFilters.forEach(btn => {
          btn.addEventListener('click', () => {
              venueFilters.forEach(f => f.classList.remove('active'));
              btn.classList.add('active');
              currentFilter = btn.getAttribute('data-filter');
              applyFilters();
          });
      });

      if (searchInput) {
          searchInput.addEventListener('input', applyFilters);
      }
      applyFilters();
  }
});
