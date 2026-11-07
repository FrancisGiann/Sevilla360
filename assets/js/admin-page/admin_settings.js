/**
 * SEVILLA360 - Admin Settings Controller
 */
document.addEventListener("DOMContentLoaded", () => {

  const venueSaveStateKey = "sevilla360AdminVenueSaveState";

  function takeVenueSaveState() {
    let savedState = null;
    try {
      const storedState = sessionStorage.getItem(venueSaveStateKey);
      if (storedState) {
        sessionStorage.removeItem(venueSaveStateKey);
        savedState = JSON.parse(storedState);
      }
    } catch {
      // Continue with history state when session storage is unavailable.
    }

    if (!savedState) {
      const currentHistoryState = window.history.state;
      if (currentHistoryState && typeof currentHistoryState === "object" && currentHistoryState[venueSaveStateKey]) {
        savedState = currentHistoryState[venueSaveStateKey];
        try {
          const nextHistoryState = { ...currentHistoryState };
          delete nextHistoryState[venueSaveStateKey];
          window.history.replaceState(nextHistoryState, "", window.location.href);
        } catch {
          // The state has still been consumed in memory for this page load.
        }
      }
    }

    return savedState && savedState.version === 1 ? savedState : null;
  }

  function storeVenueSaveState(state) {
    const serializedState = JSON.stringify(state);
    try {
      sessionStorage.setItem(venueSaveStateKey, serializedState);
      return true;
    } catch {
      // Fall back to the current history entry so a reload can restore context.
    }

    try {
      const currentHistoryState = window.history.state;
      const baseHistoryState = currentHistoryState && typeof currentHistoryState === "object" && !Array.isArray(currentHistoryState)
        ? currentHistoryState
        : {};
      window.history.replaceState({ ...baseHistoryState, [venueSaveStateKey]: state }, "", window.location.href);
      return true;
    } catch {
      return false;
    }
  }

  const restoredVenueSaveState = takeVenueSaveState();
  let captureVenueListState = () => ({ version: 1 });
    
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

  if (restoredVenueSaveState?.panelId && [...settingsPanels].some((panel) => panel.id === restoredVenueSaveState.panelId)) {
    activateSettingsPanel(restoredVenueSaveState.panelId);
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
  const toastMessage = toast?.querySelector("span");
  const defaultToastMessage = toastMessage?.textContent || "Settings Saved Successfully";
  let toastTimeout;

  function showToast(message) {
    if (!toast) return;
    clearTimeout(toastTimeout);
    if (toastMessage) {
      toastMessage.textContent = typeof message === "string" && message.trim() ? message : defaultToastMessage;
    }
    toast.classList.add("show");
    toastTimeout = setTimeout(() => toast.classList.remove("show"), message ? 4500 : 3000);
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
          body: JSON.stringify({ name, phone, curr_pass: (newPass !== '' || confPass !== '') ? currPass : '', new_pass: newPass, conf_pass: confPass })
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
  const preferencesSaveStatus = document.getElementById('preferences-save-status');
  let isFormDirty = false;
  let preferencesDirty = false;
  let preferencesRevision = 0;
  let preferencesSaving = false;
  const maxCateringMenuItems = 30;

  function setPreferencesSaveStatus(message, state = 'saved') {
    if (!preferencesSaveStatus) return;
    if (preferencesSaveStatus.textContent !== message) preferencesSaveStatus.textContent = message;
    preferencesSaveStatus.dataset.state = state;
  }

  function markPreferencesDirty() {
    preferencesDirty = true;
    preferencesRevision++;
    setPreferencesSaveStatus('Unsaved changes. Save to apply these settings.', 'unsaved');
  }

  function syncCateringMenuEditor(editor, announceCount = false) {
    const list = editor.querySelector('[data-menu-list]');
    if (!list) return [];
    const rows = [...list.querySelectorAll('[data-menu-row]')];
    const packageName = editor.dataset.packageName || 'Catering package';
    const totalCharacters = rows.reduce((total, row) => total + Array.from(row.querySelector('[data-menu-item]')?.value.trim() || '').length, 0);
    rows.forEach((row, index) => {
      const position = row.querySelector('[data-menu-position]');
      if (position) position.textContent = String(index + 1);
      const itemInput = row.querySelector('[data-menu-item]');
      const itemLabel = `${packageName} menu item ${index + 1}`;
      if (itemInput) {
        itemInput.setAttribute('aria-label', itemLabel);
        const validationMessage = itemInput.value.trim() === ''
          ? 'Enter a menu item or remove this row.'
          : (index === 0 && totalCharacters > 3000 ? 'Keep the package menu under 3,000 characters total.' : '');
        itemInput.setCustomValidity(validationMessage);
      }
      row.querySelectorAll('[data-menu-direction]').forEach((button) => {
        const direction = button.dataset.menuDirection;
        button.disabled = direction === 'up' ? index === 0 : index === rows.length - 1;
        button.setAttribute('aria-label', `Move ${itemLabel} ${direction}`);
      });
      const removeButton = row.querySelector('[data-menu-remove]');
      if (removeButton) {
        removeButton.disabled = rows.length <= 1;
        removeButton.setAttribute('aria-label', `Remove ${itemLabel}`);
      }
    });

    const addButton = editor.querySelector('[data-menu-add]');
    if (addButton) addButton.disabled = rows.length >= maxCateringMenuItems;
    const payload = editor.querySelector('[data-menu-payload]');
    if (payload) payload.value = rows.map(row => row.querySelector('[data-menu-item]')?.value.trim() || '').join('\n');
    if (announceCount) {
      const status = editor.querySelector('[data-menu-status]');
      if (status) status.textContent = `${rows.length} menu item${rows.length === 1 ? '' : 's'}. Maximum ${maxCateringMenuItems}.`;
    }
    return rows;
  }

  function makeCateringMenuRow(editor, itemNumber) {
    const packageName = editor.dataset.packageName || 'Catering package';
    const row = document.createElement('li');
    row.className = 'catering-menu-row';
    row.dataset.menuRow = '';
    const position = document.createElement('span');
    position.className = 'catering-menu-position';
    position.dataset.menuPosition = '';
    position.setAttribute('aria-hidden', 'true');
    position.textContent = String(itemNumber);
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control catering-menu-item';
    input.dataset.menuItem = '';
    input.value = '';
    input.maxLength = 120;
    input.required = true;
    input.autocomplete = 'off';
    input.setAttribute('aria-label', `${packageName} menu item ${itemNumber}`);

    const actions = document.createElement('div');
    actions.className = 'catering-menu-row-actions';
    [['up', 'Up'], ['down', 'Down']].forEach(([direction, label]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-outline catering-menu-move';
      button.dataset.menuDirection = direction;
      button.textContent = label;
      actions.appendChild(button);
    });
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-danger catering-menu-remove';
    remove.dataset.menuRemove = '';
    remove.textContent = 'Remove';
    actions.appendChild(remove);

    row.append(position, input, actions);
    return row;
  }

  function addSocialRow() {
    if (!socialList) return;
    const row = document.createElement("div");
    row.className = "social-link-row";
    row.innerHTML = '<input type="text" class="form-control social-label" placeholder="Platform (e.g. Facebook)" maxlength="40"><input type="url" class="form-control social-url" placeholder="https://..." maxlength="500"><button type="button" class="btn btn-danger btn-remove-social">Remove</button>';
    socialList.appendChild(row);
    markPreferencesDirty();
  }
  function collectSocialLinks() {
    return [...(socialList?.querySelectorAll('.social-link-row') || [])].map(row => ({label: row.querySelector('.social-label')?.value.trim() || '', url: row.querySelector('.social-url')?.value.trim() || ''})).filter(item => item.label || item.url);
  }
  document.getElementById('btn-add-social')?.addEventListener('click', addSocialRow);
  socialList?.addEventListener('click', event => { const button = event.target.closest('.btn-remove-social'); if (button) { button.closest('.social-link-row')?.remove(); markPreferencesDirty(); } });

  if (btnSavePrefs && formPrefs) {
    formPrefs.addEventListener('input', (event) => {
      markPreferencesDirty();
    });
    formPrefs.addEventListener('change', markPreferencesDirty);

    btnSavePrefs.addEventListener("click", async () => {
      if (packageDialog?.open || preferencesSaving) return;
      if (!formPrefs.reportValidity()) {
        setPreferencesSaveStatus('Fix the highlighted fields before saving.', 'error');
        return;
      }
      const originalText = btnSavePrefs.innerHTML;
      const submittedRevision = preferencesRevision;
      preferencesSaving = true;
      document.querySelectorAll('[data-catering-edit]').forEach((button) => { button.disabled = true; });
      btnSavePrefs.disabled = true;
      btnSavePrefs.textContent = 'Saving…';
      setPreferencesSaveStatus('Saving preference changes…', 'saving');

      if (socialJson) socialJson.value = JSON.stringify(collectSocialLinks());
      const formData = new FormData(formPrefs);
      try {
        const response = await fetch("actions/admin/save_preferences.php", {
          method: "POST",
          headers: { "X-CSRF-Token": csrfToken },
          body: formData,
        });
        const data = (await response.text()).trim();
        if (!response.ok || data !== 'Success') {
          const message = data.startsWith('Error|') ? data.slice(6) : data;
          throw new Error(message || 'Could not save settings. Try again.');
        }
        showToast();
        if (preferencesRevision === submittedRevision) {
          preferencesDirty = false;
          setPreferencesSaveStatus('Changes saved.', 'saved');
        } else {
          setPreferencesSaveStatus('Submitted changes saved. New edits are still unsaved.', 'unsaved');
        }
      } catch (error) {
        const errorMessage = error.message || '';
        const isNetworkFailure = error instanceof TypeError || /failed to fetch|networkerror|load failed|fetch failed/i.test(errorMessage);
        const message = isNetworkFailure
          ? 'Could not save. Check your connection and try again; your edits are still here.'
          : `Could not save. ${errorMessage || 'Try again.'} Your edits are still here.`;
        setPreferencesSaveStatus(message, 'error');
        if (window.showAlert) window.showAlert('Notice', message);
        else alert(message);
      } finally {
        btnSavePrefs.innerHTML = originalText;
        btnSavePrefs.disabled = false;
        preferencesSaving = false;
        document.querySelectorAll('[data-catering-edit]').forEach((button) => { button.disabled = false; });
      }
    });
  }

  // Catering package editor: each dialog save persists just one package.
  const packageDialog = document.getElementById('catering-package-dialog');
  const packageDialogForm = document.getElementById('catering-package-dialog-form');
  const dialogNameInput = packageDialogForm?.querySelector('[data-dialog-name]');
  const dialogRateInput = packageDialogForm?.querySelector('[data-dialog-rate]');
  const dialogPackageKey = packageDialogForm?.querySelector('[data-dialog-package-key]');
  const dialogTitle = packageDialog?.querySelector('[data-dialog-title]');
  const dialogEditor = packageDialogForm?.querySelector('[data-menu-editor]');
  const dialogMenuList = dialogEditor?.querySelector('[data-menu-list]');
  const dialogMenuPayload = dialogEditor?.querySelector('[data-menu-payload]');
  const dialogStatus = packageDialog?.querySelector('[data-dialog-status]');
  const discardConfirmation = packageDialog?.querySelector('[data-discard-confirm]');
  let packageDialogOpener = null;
  let packageDialogBaseline = '';
  let packageDialogDirty = false;
  let packageDialogSaving = false;
  let previousBodyOverflow = '';

  function getPackageDialogState() {
    return {
      key: dialogPackageKey?.value || '',
      name: dialogNameInput?.value || '',
      rate: dialogRateInput?.value || '',
      menu: [...(dialogMenuList?.querySelectorAll('[data-menu-item]') || [])].map((input) => input.value),
    };
  }

  function refreshPackageDialogDirty() {
    if (!packageDialogForm) return false;
    packageDialogDirty = JSON.stringify(getPackageDialogState()) !== packageDialogBaseline;
    if (discardConfirmation) discardConfirmation.hidden = true;
    if (dialogStatus) {
      const message = packageDialogDirty
        ? 'Unsaved changes. Save this package to apply them.'
        : 'Changes are saved when you choose Save package.';
      if (dialogStatus.textContent !== message) dialogStatus.textContent = message;
      dialogStatus.dataset.state = packageDialogDirty ? 'unsaved' : 'saved';
    }
    return packageDialogDirty;
  }

  function formatPackageSummaryRate(value) {
    const numeric = Number(value);
    const raw = String(value ?? '');
    const fraction = raw.includes('.') ? raw.split('.')[1].replace(/0+$/, '') : '';
    const precision = Math.max(2, Math.min(4, fraction.length));
    return Number.isFinite(numeric)
      ? numeric.toLocaleString('en-US', { minimumFractionDigits: precision, maximumFractionDigits: precision })
      : raw;
  }

  function renderPackageSummary(summary, packageData) {
    if (!summary) return;
    const name = String(packageData.name || 'Catering package');
    const menu = Array.isArray(packageData.menu) ? packageData.menu.map((item) => String(item)) : [];
    const preview = menu.slice(0, 2).join(' · ') + (menu.length > 2 ? ` · +${menu.length - 2} more` : '');
    const packageNumber = summary.dataset.packageNumber || 'package';
    const nameNode = summary.querySelector('[data-summary-name]');
    const rateNode = summary.querySelector('[data-summary-rate]');
    const countNode = summary.querySelector('[data-summary-count]');
    const previewNode = summary.querySelector('[data-summary-preview]');
    if (nameNode) nameNode.textContent = name;
    if (rateNode) rateNode.textContent = formatPackageSummaryRate(packageData.rate);
    if (countNode) countNode.textContent = `${menu.length} menu item${menu.length === 1 ? '' : 's'}`;
    if (previewNode) previewNode.textContent = preview || 'No menu items';
    summary.querySelector('[data-catering-edit]')?.setAttribute('aria-label', `Edit ${name}`);
    const settingName = summary.querySelector('[data-package-setting="name"]');
    const settingRate = summary.querySelector('[data-package-setting="rate"]');
    const settingMenu = summary.querySelector('[data-package-setting="menu"]');
    if (settingName) settingName.value = name;
    if (settingRate) settingRate.value = String(packageData.rate ?? '');
    if (settingMenu) settingMenu.value = menu.join('\n');
  }

  function closePackageDialog() {
    if (!packageDialog?.open) return;
    packageDialog.close();
    packageDialogDirty = false;
    document.body.style.overflow = previousBodyOverflow;
    packageDialogOpener?.focus();
    packageDialogOpener = null;
  }

  function requestPackageDialogClose() {
    if (!packageDialog?.open || packageDialogSaving) return;
    if (packageDialogDirty) {
      if (discardConfirmation) discardConfirmation.hidden = false;
      packageDialog.querySelector('[data-keep-editing]')?.focus();
      return;
    }
    closePackageDialog();
  }

  function openPackageDialog(opener) {
    if (!packageDialog || !packageDialogForm || preferencesSaving) return;
    const summary = opener.closest('[data-package-summary]');
    const key = summary?.dataset.packageKey || '';
    if (!/^(silver|gold|platinum)$/.test(key)) return;
    const nameSetting = summary.querySelector('[data-package-setting="name"]');
    const rateSetting = summary.querySelector('[data-package-setting="rate"]');
    const menuSetting = summary.querySelector('[data-package-setting="menu"]');
    const definitionNumber = summary.dataset.packageNumber || 'package';
    const name = nameSetting?.value || summary.querySelector('[data-summary-name]')?.textContent || '';
    if (dialogPackageKey) dialogPackageKey.value = key;
    if (dialogNameInput) {
      dialogNameInput.name = `catering_${key}_name`;
      dialogNameInput.value = name;
    }
    if (dialogRateInput) {
      dialogRateInput.name = `catering_${key}`;
      dialogRateInput.value = rateSetting?.value || '';
    }
    if (dialogTitle) dialogTitle.textContent = `Edit ${name || definitionNumber}`;
    if (dialogEditor) {
      dialogEditor.dataset.packageName = name || definitionNumber;
      if (dialogMenuList) dialogMenuList.setAttribute('aria-label', `${name || definitionNumber} menu items`);
    }
    if (dialogMenuPayload) dialogMenuPayload.name = `catering_${key}_menu`;
    if (dialogMenuList && dialogEditor) {
      dialogMenuList.replaceChildren();
      const items = (menuSetting?.value || '').split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
      (items.length ? items : ['']).forEach((item, index) => {
        const row = makeCateringMenuRow(dialogEditor, index + 1);
        row.querySelector('[data-menu-item]').value = item;
        dialogMenuList.appendChild(row);
      });
      syncCateringMenuEditor(dialogEditor, true);
    }
    if (discardConfirmation) discardConfirmation.hidden = true;
    if (dialogStatus) {
      dialogStatus.textContent = 'Changes are saved when you choose Save package.';
      dialogStatus.dataset.state = 'saved';
    }
    packageDialogOpener = opener;
    packageDialogBaseline = JSON.stringify(getPackageDialogState());
    packageDialogDirty = false;
    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    packageDialog.showModal();
    dialogNameInput?.focus();
  }

  document.querySelectorAll('[data-catering-edit]').forEach((button) => {
    button.addEventListener('click', () => openPackageDialog(button));
  });
  packageDialog?.querySelectorAll('[data-dialog-close], [data-dialog-cancel]').forEach((button) => {
    button.addEventListener('click', requestPackageDialogClose);
  });
  packageDialog?.addEventListener('cancel', (event) => {
    event.preventDefault();
    requestPackageDialogClose();
  });
  // Keep Tab navigation inside the native modal, including browsers that move focus to BODY at the boundary.
  document.addEventListener('keydown', (event) => {
    if (!packageDialog?.open || event.key !== 'Tab') return;
    const focusable = [...packageDialog.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )].filter((element) => element.tabIndex >= 0
      && !element.closest('[hidden]')
      && element.getAttribute('aria-hidden') !== 'true'
      && element.getClientRects().length > 0);
    if (focusable.length === 0) {
      event.preventDefault();
      packageDialog.focus();
      return;
    }
    const activeIndex = focusable.indexOf(document.activeElement);
    if (event.shiftKey && (activeIndex <= 0)) {
      event.preventDefault();
      focusable[focusable.length - 1].focus();
    } else if (!event.shiftKey && (activeIndex === focusable.length - 1 || activeIndex === -1)) {
      event.preventDefault();
      focusable[0].focus();
    }
  }, true);
  packageDialog?.addEventListener('click', (event) => {
    if (event.target === packageDialog) requestPackageDialogClose();
  });
  packageDialog?.querySelector('[data-keep-editing]')?.addEventListener('click', () => {
    if (discardConfirmation) discardConfirmation.hidden = true;
    (dialogNameInput || packageDialog.querySelector('[data-dialog-save]'))?.focus();
  });
  packageDialog?.querySelector('[data-discard-package]')?.addEventListener('click', closePackageDialog);

  packageDialogForm?.addEventListener('input', (event) => {
    if (event.target.matches('[data-dialog-name]') && dialogEditor) {
      const packageName = dialogNameInput.value.trim() || 'Catering package';
      dialogEditor.dataset.packageName = packageName;
      if (dialogMenuList) dialogMenuList.setAttribute('aria-label', `${packageName} menu items`);
      syncCateringMenuEditor(dialogEditor);
    }
    if (event.target.matches('[data-menu-item]') && dialogEditor) syncCateringMenuEditor(dialogEditor);
    refreshPackageDialogDirty();
  });
  packageDialog?.addEventListener('click', (event) => {
    const editor = event.target.closest('[data-menu-editor]');
    if (!editor || editor !== dialogEditor || packageDialogSaving) return;
    const list = editor.querySelector('[data-menu-list]');
    const status = editor.querySelector('[data-menu-status]');
    const addButton = event.target.closest('[data-menu-add]');
    if (addButton) {
      const rows = syncCateringMenuEditor(editor);
      if (rows.length >= maxCateringMenuItems) return;
      const newRow = makeCateringMenuRow(editor, rows.length + 1);
      list.appendChild(newRow);
      syncCateringMenuEditor(editor, true);
      refreshPackageDialogDirty();
      newRow.querySelector('[data-menu-item]')?.focus();
      if (status) status.textContent = `${editor.dataset.packageName || 'Package'}: added menu item. ${rows.length + 1} of ${maxCateringMenuItems}.`;
      return;
    }
    const removeButton = event.target.closest('[data-menu-remove]');
    if (removeButton) {
      const row = removeButton.closest('[data-menu-row]');
      const rows = [...list.querySelectorAll('[data-menu-row]')];
      if (!row || rows.length <= 1) return;
      const removedName = row.querySelector('[data-menu-item]')?.value.trim() || 'Menu item';
      const focusRow = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      const remaining = syncCateringMenuEditor(editor, true);
      refreshPackageDialogDirty();
      focusRow?.querySelector('[data-menu-item]')?.focus();
      if (status) status.textContent = `Removed ${removedName}. ${remaining.length} menu item${remaining.length === 1 ? '' : 's'} remain.`;
      return;
    }
    const moveButton = event.target.closest('[data-menu-direction]');
    const row = moveButton?.closest('[data-menu-row]');
    if (!moveButton || !row) return;
    const movedName = row.querySelector('[data-menu-item]')?.value.trim() || 'Menu item';
    const direction = moveButton.dataset.menuDirection;
    const sibling = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
    if (!sibling) return;
    if (direction === 'up') list.insertBefore(row, sibling);
    else list.insertBefore(sibling, row);
    const rows = syncCateringMenuEditor(editor, true);
    refreshPackageDialogDirty();
    const position = rows.indexOf(row) + 1;
    const focusButton = row.querySelector(`[data-menu-direction="${direction}"]`);
    (focusButton && !focusButton.disabled ? focusButton : row.querySelector('[data-menu-item]'))?.focus();
    if (status) status.textContent = `Moved ${movedName} to position ${position} of ${rows.length}.`;
  });

  packageDialogForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (packageDialogSaving || preferencesSaving || !dialogEditor) return;
    syncCateringMenuEditor(dialogEditor);
    if (!packageDialogForm.reportValidity()) {
      if (dialogStatus) dialogStatus.textContent = 'Fix the highlighted fields before saving this package.';
      return;
    }
    const summary = document.querySelector(`[data-package-summary][data-package-key="${dialogPackageKey?.value}"]`);
    const formData = new FormData(packageDialogForm);
    const controls = [...packageDialogForm.querySelectorAll('input, button, textarea, select')];
    const previousDisabled = controls.map((control) => control.disabled);
    const closeButton = packageDialog.querySelector('[data-dialog-close]');
    const closeWasDisabled = closeButton?.disabled ?? false;
    packageDialogSaving = true;
    controls.forEach((control) => { control.disabled = true; });
    if (closeButton) closeButton.disabled = true;
    if (dialogStatus) dialogStatus.textContent = 'Saving this package…';
    try {
      const response = await fetch('actions/admin/save_catering_package.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData,
      });
      const data = await response.json();
      if (!response.ok || !data.success || !data.package) {
        throw new Error(data.message || 'Could not save this package. Try again.');
      }
      renderPackageSummary(summary, data.package);
      packageDialogBaseline = JSON.stringify(getPackageDialogState());
      packageDialogDirty = false;
      if (dialogStatus) dialogStatus.textContent = 'Package saved.';
      showToast();
      closePackageDialog();
    } catch (error) {
      const errorMessage = error.message || '';
      const isNetworkFailure = error instanceof TypeError || /failed to fetch|networkerror|load failed|fetch failed/i.test(errorMessage);
      if (dialogStatus) {
        dialogStatus.textContent = isNetworkFailure
          ? 'Could not save. Check your connection and try again; your edits are still here.'
          : `Could not save. ${errorMessage || 'Try again.'} Your edits are still here.`;
        dialogStatus.dataset.state = 'error';
      }
    } finally {
      packageDialogSaving = false;
      controls.forEach((control, index) => { control.disabled = previousDisabled[index]; });
      if (closeButton) closeButton.disabled = closeWasDisabled;
    }
  });

  // 3B. PUBLIC SUPPORT CONTENT
  // =========================================================
  const supportForm = document.getElementById('form-support-content');
  const supportFaqList = document.getElementById('support-faq-list');
  const btnSaveSupport = document.getElementById('btn-save-support');

  const supportFaqCategories = ['General', 'Booking', 'Payments', 'Cancellations & Refunds', 'Venue & Stay', 'Policies'];
  function addSupportFaqRow(question = '', answer = '', category = 'General', phrases = '') {
    if (!supportFaqList) return;
    const row = document.createElement('div');
    row.className = 'support-faq-row';
    row.innerHTML = `<div class="form-group"><label>Question</label><input type="text" class="form-control support-faq-question" placeholder="Question" maxlength="240"></div><div class="form-group"><label>Category</label><select class="form-control support-faq-category">${supportFaqCategories.map(item => `<option value="${item.replace(/&/g, '&amp;')}">${item}</option>`).join('')}</select></div><div class="form-group support-faq-answer-group"><label>Answer</label><textarea class="form-control support-faq-answer" placeholder="Answer" rows="3" maxlength="3000"></textarea></div><input type="hidden" class="support-faq-phrases" value=""><div class="support-faq-actions"><button type="button" class="btn btn-outline btn-move-support-faq" data-direction="up" aria-label="Move FAQ up">↑</button><button type="button" class="btn btn-outline btn-move-support-faq" data-direction="down" aria-label="Move FAQ down">↓</button><button type="button" class="btn btn-danger btn-remove-support-faq">Remove</button></div>`;
    row.querySelector('.support-faq-question').value = question;
    row.querySelector('.support-faq-answer').value = answer;
    row.querySelector('.support-faq-category').value = supportFaqCategories.includes(category) ? category : 'General';
    row.querySelector('.support-faq-phrases').value = phrases;
    supportFaqList.appendChild(row);
    isFormDirty = true;
  }
  document.getElementById('btn-add-support-faq')?.addEventListener('click', () => addSupportFaqRow());
  supportFaqList?.addEventListener('click', event => {
    const button = event.target.closest('.btn-remove-support-faq');
    if (button) { button.closest('.support-faq-row')?.remove(); isFormDirty = true; }
    const moveButton = event.target.closest('.btn-move-support-faq');
    if (moveButton) {
      const row = moveButton.closest('.support-faq-row');
      if (!row) return;
      const sibling = moveButton.dataset.direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
      if (sibling) {
        if (moveButton.dataset.direction === 'up') supportFaqList.insertBefore(row, sibling);
        else supportFaqList.insertBefore(sibling, row);
        isFormDirty = true;
      }
    }
  });
  btnSaveSupport?.addEventListener('click', () => {
    const originalText = btnSaveSupport.innerHTML;
    const faqItems = [...(supportFaqList?.querySelectorAll('.support-faq-row') || [])].map(row => ({
      id: row.dataset.faqId || '',
      question: row.querySelector('.support-faq-question')?.value.trim() || '',
      answer: row.querySelector('.support-faq-answer')?.value.trim() || '',
      category: row.querySelector('.support-faq-category')?.value || 'General',
      phrases: (row.querySelector('.support-faq-phrases')?.value || '').split(/\r?\n/).map(phrase => phrase.trim()).filter(Boolean)
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
  const hasUnsavedSettings = () => isFormDirty || preferencesDirty || (packageDialog?.open && (packageDialogDirty || packageDialogSaving));

  window.addEventListener("beforeunload", function (e) {
    if (hasUnsavedSettings()) {
      const msg = "You have unsaved changes. Are you sure you want to leave?";
      e.returnValue = msg;
      return msg;
    }
  });

  document.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", function (e) {
      const href = this.getAttribute("href");
      if (href && href !== "#" && !href.startsWith("javascript") && hasUnsavedSettings()) {
          e.preventDefault();
          pendingUrl = href;
          unsavedModal.classList.add("active");
      }
    });
  });

  document.getElementById("btn-stay-save")?.addEventListener("click", () => unsavedModal.classList.remove("active"));
  document.getElementById("btn-discard-leave")?.addEventListener("click", () => {
      isFormDirty = false;
      preferencesDirty = false;
      window.location.href = pendingUrl;
  });

  // =========================================================
  // 5. MANAGE VENUES MODAL & AJAX
  // =========================================================
  const venueModal = document.getElementById("venueModal");
  const venueModalContent = venueModal?.querySelector(".venue-modal-content");
  const formVenue = document.getElementById("form-venue");
  const catSelect = document.getElementById("vm-category");
  const venueNameInput = document.getElementById("vm-name");
  const venueNameLabel = document.getElementById("vm-name-label");
  const venueNameHelp = document.getElementById("vm-hotel-name-help");
  const venuePricingSection = document.querySelector("#venueModal .venue-pricing-section");
  const venuePricingTitle = document.getElementById("vm-pricing-title");
  const venueModalTitle = document.getElementById("vm-title");
  const venueSaveButton = document.getElementById("btn-save-venue");
  const floorField = document.getElementById("vm-hr-floor");
  const floorHelp = document.getElementById("vm-hr-floor-help");
  const floorBulkHelp = document.getElementById("vm-hr-floor-bulk-help");
  let venueModalTrigger = null;
  let isSavingVenue = false;

  function updateFloorHelp(bulkEnabled) {
    if (floorHelp) floorHelp.hidden = bulkEnabled;
    if (floorBulkHelp) floorBulkHelp.hidden = !bulkEnabled;
    if (floorField) floorField.setAttribute("aria-describedby", bulkEnabled ? "vm-hr-floor-bulk-help" : "vm-hr-floor-help");
  }

  function openVenueModal(trigger) {
    venueModalTrigger = trigger || document.activeElement;
    if (venueModalContent) venueModalContent.scrollTop = 0;
    venueModal.classList.add("active");
    venueNameInput?.focus({ preventScroll: true });
  }

  function closeVenueModal() {
    venueModal.classList.remove("active");
    venueModalTrigger?.focus?.();
    venueModalTrigger = null;
  }

  function trapVenueModalFocus(event) {
    if (event.key !== "Tab") return;
    const focusable = [...venueModal.querySelectorAll("button:not(:disabled), input:not(:disabled):not([type='hidden']), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex='-1'])")]
      .filter((element) => element.getClientRects().length > 0 && element.getAttribute("aria-hidden") !== "true");
    if (!focusable.length) {
      event.preventDefault();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || !venueModal.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !venueModal.contains(document.activeElement))) {
      event.preventDefault();
      first.focus();
    }
  }

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
    const isHotelRoom = category === "Hotel Room";
    if (venueNameLabel) venueNameLabel.textContent = isHotelRoom ? "Hotel or Building Name" : "Venue Name";
    if (venueNameInput) {
      venueNameInput.placeholder = isHotelRoom ? "e.g. Sevilla Resort" : "e.g. Infinity Hall";
      if (isHotelRoom) venueNameInput.setAttribute("list", "vm-hotel-name-options");
      else venueNameInput.removeAttribute("list");
    }
    if (venueNameHelp) venueNameHelp.hidden = !isHotelRoom;
    venuePricingSection?.classList.toggle("is-hotel", isHotelRoom);
    if (venuePricingTitle) venuePricingTitle.textContent = isHotelRoom ? "Room Setup" : "Pricing & Capacities";
    if (venueModalTitle && venueSaveButton) {
      venueModalTitle.textContent = isHotelRoom ? (isEditMode ? "Edit Hotel Room" : "Add Hotel Room") : (isEditMode ? "Edit Venue" : "Add New Venue");
      venueSaveButton.textContent = isHotelRoom ? (isEditMode ? "Save Room" : (bulkToggle?.checked ? "Create Rooms" : "Add Room")) : "Save Venue";
    }
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
      updateFloorHelp(bulkEnabled);
    }
  }

  // Bulk creation toggle logic
  const bulkToggle = document.getElementById("vm-hr-bulk-toggle");
  const bulkFields = document.getElementById("vm-hr-bulk-fields");
  const bulkQty = document.getElementById("vm-hr-bulk-qty");
  const bulkStart = document.getElementById("vm-hr-bulk-start");
  const roomNumberField = document.getElementById("vm-hr-room-number");

  function validateBulkRoomNumbers(start, quantity) {
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > 100) {
      return "Choose a quantity from 1 to 100 rooms.";
    }
    if (start.length === 0 || start.length > 20) {
      return "Starting room number must be 20 characters or fewer.";
    }

    const roomNumbers = [];
    if (/^[A-Za-z]+$/.test(start)) {
      let current = start;
      for (let offset = 0; offset < quantity; offset += 1) {
        if (current.length > 20) {
          return "Every generated room number must be 20 characters or fewer.";
        }
        roomNumbers.push(current);
        if (offset + 1 < quantity) {
          const letters = current.split("");
          for (let index = letters.length - 1; index >= 0; index -= 1) {
            const code = letters[index].charCodeAt(0);
            const isUppercase = code >= 65 && code <= 90;
            const lastLetter = isUppercase ? 90 : 122;
            if (code !== lastLetter) {
              letters[index] = String.fromCharCode(code + 1);
              break;
            }
            letters[index] = isUppercase ? "A" : "a";
            if (index === 0) {
              letters.unshift(isUppercase ? "A" : "a");
            }
          }
          current = letters.join("");
        }
      }
      return null;
    }

    const match = start.match(/^([A-Za-z]+-)?(\d{1,6})$/);
    if (!match) {
      return "Use letters only (e.g. A), digits (e.g. 101), or a letter prefix and digits (e.g. A-101).";
    }
    const prefix = match[1] || "";
    const numberText = match[2];
    const finalNumber = Number(numberText) + quantity - 1;
    if (finalNumber > 999999) {
      return "The numeric room range cannot exceed 999999.";
    }
    for (let offset = 0; offset < quantity; offset += 1) {
      const roomNumber = prefix + String(Number(numberText) + offset).padStart(numberText.length, "0");
      if (roomNumber.length > 20) {
        return "Every generated room number must be 20 characters or fewer.";
      }
      roomNumbers.push(roomNumber);
    }
    return null;
  }

  if (bulkToggle) {
      bulkToggle.addEventListener("change", function() {
          const enabled = this.checked;
          bulkFields.style.display = enabled ? "grid" : "none";
          setVenueFieldsState(bulkFields, enabled);
          roomNumberField.disabled = enabled;
          roomNumberField.required = !enabled;
          roomNumberField.parentElement.style.display = enabled ? "none" : "block";
          updateFloorHelp(enabled);
          if (catSelect.value === "Hotel Room" && document.getElementById("vm-id").value === "") {
            venueSaveButton.textContent = enabled ? "Create Rooms" : "Add Room";
          }
      });
  }

  if (catSelect) {
    catSelect.addEventListener("change", function () { toggleDynamicFields(this.value); });
  }

  document.getElementById("btn-add-venue")?.addEventListener("click", (event) => {
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
    openVenueModal(event.currentTarget);
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
        document.getElementById("vm-hr-type").value = venueData.room_type_code || "";
        document.getElementById("vm-hr-rate").value = venueData.nightly_rate;
        document.getElementById("vm-hr-room-number").value = venueData.room_number || "";
        document.getElementById("vm-hr-floor").value = venueData.floor_label || "";
        document.getElementById("vm-hr-bed-count").value = venueData.bed_count || 1;
        document.getElementById("vm-hr-check-in").value = (venueData.check_in_time || "14:00:00").slice(0, 5);
        document.getElementById("vm-hr-check-out").value = (venueData.check_out_time || "12:00:00").slice(0, 5);
        document.getElementById("vm-extra-pax").value = venueData.hr_extra;
        const mediaSlotField = document.getElementById("vm-hr-media-slot");
        if (mediaSlotField) mediaSlotField.value = venueData.media_slot_key || "";
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

      openVenueModal(this);
    });
  });

  document.getElementById("btn-close-vmodal")?.addEventListener("click", closeVenueModal);
  document.getElementById("btn-close-vmodal-x")?.addEventListener("click", closeVenueModal);
  window.addEventListener("click", (e) => { if (e.target === venueModal) closeVenueModal(); });
  venueModal?.addEventListener("keydown", (e) => {
    if (!venueModal.classList.contains("active")) return;
    if (e.key === "Escape") closeVenueModal();
    else trapVenueModalFocus(e);
  });

  if (formVenue) {
    formVenue.addEventListener("submit", function (e) {
      e.preventDefault();
      if (isSavingVenue) return;

      // Validate the full generated range before submitting the bulk request.
      if (bulkToggle && bulkToggle.checked) {
          const startNum = bulkStart.value.trim();
          const bulkError = validateBulkRoomNumbers(startNum, Number(bulkQty.value));
          if (bulkError) {
              showAlert("Notice", bulkError);
              bulkStart.focus();
              return;
          }
      }

      const submitBtn = document.getElementById("btn-save-venue");
      const originalText = submitBtn.innerText;
      isSavingVenue = true;
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
            const state = captureVenueListState();
            state.successMessage = typeof data.message === "string" && data.message.trim()
              ? data.message
              : "Venue saved successfully.";
            if (!storeVenueSaveState(state)) {
              isSavingVenue = false;
              submitBtn.innerText = originalText;
              submitBtn.disabled = false;
              closeVenueModal();
              if (window.showAlert) window.showAlert("Success", state.successMessage, "success", true);
              else window.location.reload();
              return;
            }
            window.location.reload();
          } else {
            showAlert("Notice", "Error: " + data.message);
            isSavingVenue = false;
            submitBtn.innerText = originalText;
            submitBtn.disabled = false;
          }
      })
      .catch(err => {
          console.error(err);
          showAlert("Notice", "Network error.");
          isSavingVenue = false;
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
      const autoExpandedGroups = new Set();
      const manuallyCollapsedGroups = new Set();

      function applyFilters() {
          const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
          if (!searchTerm && autoExpandedGroups.size) {
              autoExpandedGroups.forEach(groupId => {
                  const group = document.querySelector(`.venue-group-toggle[aria-controls="hotel-group-${groupId}"]`);
                  if (group?.getAttribute('aria-expanded') === 'true') {
                      group.setAttribute('aria-expanded', 'false');
                      const arrow = group.querySelector('.venue-group-arrow');
                      if (arrow) arrow.textContent = '▸';
                      const countLabel = group.querySelector('.venue-group-count');
                      if (countLabel) countLabel.textContent = 'Rooms are collapsed';
                  }
              });
              autoExpandedGroups.clear();
          }
          if (!searchTerm) manuallyCollapsedGroups.clear();
          if (searchTerm) {
              venueGroups.forEach(group => {
                  if (currentFilter !== 'all' && currentFilter !== 'Hotel Room') return;
                  const groupSearch = (group.dataset.search || group.textContent).toLowerCase();
                  const groupId = group.querySelector('.venue-group-toggle')?.getAttribute('aria-controls')?.replace('hotel-group-', '');
                  const children = [...venueRows].filter(row => row.getAttribute('data-group-id') === groupId);
                  const matchesChild = children.some(row => (row.dataset.search || row.querySelector('td').textContent).toLowerCase().includes(searchTerm));
                  const matchesGroup = groupSearch.includes(searchTerm);
                  const toggle = group.querySelector('.venue-group-toggle');
                  if ((matchesGroup || matchesChild) && toggle?.getAttribute('aria-expanded') !== 'true' && !manuallyCollapsedGroups.has(groupId)) {
                      toggle.setAttribute('aria-expanded', 'true');
                      const arrow = toggle.querySelector('.venue-group-arrow');
                      if (arrow) arrow.textContent = '▾';
                      const countLabel = toggle.querySelector('.venue-group-count');
                      if (countLabel) countLabel.textContent = `${children.length} room${children.length === 1 ? '' : 's'}`;
                      if (groupId) autoExpandedGroups.add(groupId);
                  }
              });
          }
          venueRows.forEach(row => {
              const matchesCategory = currentFilter === 'all' || row.getAttribute('data-category') === currentFilter;
              const rowText = row.querySelector('td').textContent.toLowerCase();
              const rowSearch = (row.dataset.search || rowText).toLowerCase();
              const matchesSearch = searchTerm === '' || rowText.includes(searchTerm);
              const isRoom = row.classList.contains('room-row');
              const groupId = row.getAttribute('data-group-id');
              const group = groupId ? document.querySelector(`.venue-group-toggle[aria-controls="hotel-group-${groupId}"]`) : null;
              const groupText = group ? group.textContent.toLowerCase() : '';
              const groupSearch = group?.closest('.venue-group-row')?.dataset.search?.toLowerCase() || groupText;
              const matchesGroupSearch = groupSearch.includes(searchTerm);
              const expanded = group?.getAttribute('aria-expanded') === 'true';

              if (isRoom) {
                  row.classList.toggle('room-row-collapsed', !(matchesCategory && expanded && (matchesSearch || rowSearch.includes(searchTerm) || matchesGroupSearch)));
              } else {
                  row.style.display = matchesCategory && matchesSearch ? '' : 'none';
              }
          });

          venueGroups.forEach(group => {
              const groupId = group.querySelector('.venue-group-toggle')?.getAttribute('aria-controls')?.replace('hotel-group-', '');
              const children = [...venueRows].filter(row => row.getAttribute('data-group-id') === groupId);
              const groupText = group.textContent.toLowerCase();
              const hasMatchingChild = children.some(row => (row.dataset.search || row.querySelector('td').textContent).toLowerCase().includes(searchTerm));
              const groupSearch = (group.dataset.search || groupText).toLowerCase();
              const matchesSearch = searchTerm === '' || groupSearch.includes(searchTerm) || hasMatchingChild;
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
              autoExpandedGroups.delete(groupId);
              if (groupId) {
                  if (expanded && searchInput?.value.trim()) manuallyCollapsedGroups.add(groupId);
                  else manuallyCollapsedGroups.delete(groupId);
              }
              const children = [...venueRows].filter(row => row.getAttribute('data-group-id') === groupId);
              const countLabel = toggle.querySelector('.venue-group-count');
              if (countLabel) countLabel.textContent = expanded ? 'Rooms are collapsed' : `${children.length} room${children.length === 1 ? '' : 's'}`;
              applyFilters();
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

      function getVenueGroupId(group) {
          const controls = group.querySelector('.venue-group-toggle')?.getAttribute('aria-controls') || '';
          return controls.replace(/^hotel-group-/, '');
      }

      function restoreVenueListState(state) {
          if (!state || typeof state !== 'object') return;

          const selectedFilter = [...venueFilters].find(btn => btn.getAttribute('data-filter') === state.categoryFilter);
          if (selectedFilter) {
              venueFilters.forEach(btn => btn.classList.toggle('active', btn === selectedFilter));
              currentFilter = state.categoryFilter;
          }
          if (searchInput && typeof state.searchValue === 'string') searchInput.value = state.searchValue;

          const expandedIds = new Set(Array.isArray(state.expandedGroupIds) ? state.expandedGroupIds : []);
          const autoExpandedIds = new Set(Array.isArray(state.autoExpandedGroupIds) ? state.autoExpandedGroupIds : []);
          const manuallyCollapsedIds = new Set(Array.isArray(state.manuallyCollapsedGroupIds) ? state.manuallyCollapsedGroupIds : []);
          autoExpandedGroups.clear();
          manuallyCollapsedGroups.clear();

          venueGroups.forEach(group => {
              const groupId = getVenueGroupId(group);
              const toggle = group.querySelector('.venue-group-toggle');
              const expanded = expandedIds.has(groupId);
              const children = [...venueRows].filter(row => row.getAttribute('data-group-id') === groupId);
              toggle?.setAttribute('aria-expanded', String(expanded));
              const arrow = toggle?.querySelector('.venue-group-arrow');
              if (arrow) arrow.textContent = expanded ? '▾' : '▸';
              const countLabel = toggle?.querySelector('.venue-group-count');
              if (countLabel) countLabel.textContent = expanded ? `${children.length} room${children.length === 1 ? '' : 's'}` : 'Rooms are collapsed';
              if (autoExpandedIds.has(groupId) && expanded) autoExpandedGroups.add(groupId);
              if (manuallyCollapsedIds.has(groupId) && !expanded) manuallyCollapsedGroups.add(groupId);
          });
      }

      captureVenueListState = () => ({
          version: 1,
          panelId: [...settingsPanels].find(panel => panel.classList.contains('active'))?.id || 'panel-venues',
          categoryFilter: currentFilter,
          searchValue: searchInput?.value || '',
          expandedGroupIds: [...venueGroups].filter(group => group.querySelector('.venue-group-toggle')?.getAttribute('aria-expanded') === 'true').map(getVenueGroupId),
          autoExpandedGroupIds: [...autoExpandedGroups],
          manuallyCollapsedGroupIds: [...manuallyCollapsedGroups],
          scrollX: Number(window.scrollX) || 0,
          scrollY: Number(window.scrollY) || 0
      });

      restoreVenueListState(restoredVenueSaveState);
      applyFilters();

      if (restoredVenueSaveState) {
          const message = typeof restoredVenueSaveState.successMessage === 'string'
              ? restoredVenueSaveState.successMessage
              : 'Venue saved successfully.';
          showToast(message);
          requestAnimationFrame(() => window.scrollTo({
              left: Number(restoredVenueSaveState.scrollX) || 0,
              top: Number(restoredVenueSaveState.scrollY) || 0,
              behavior: 'instant'
          }));
      }
  }
});
