(() => {
  'use strict';

  function roomBuilding(room, roomId) {
    const building = String(room?.building_name ?? '').trim().replace(/\s+/g, ' ');
    const fallback = String(room?.name ?? '').trim().replace(/\s+/g, ' ');
    const name = building || fallback || `Hotel room ${roomId}`;
    return { key: name.toLowerCase(), name };
  }

  function compareText(left, right) {
    return left < right ? -1 : left > right ? 1 : 0;
  }

  function suggestRoomSelection(rooms, selectedIds, roster) {
    const toCount = value => {
      const count = Number(value);
      return Number.isSafeInteger(count) && count > 0 ? count : 0;
    };
    const people = toCount(roster?.people);
    const female = Math.min(people, toCount(roster?.female));
    const male = Math.min(people - female, toCount(roster?.male));
    const unknown = Math.max(0, people - female - male);
    const selectedSet = new Set((Array.isArray(selectedIds) ? selectedIds : []).map(Number).filter(id => Number.isSafeInteger(id) && id > 0));
    const available = [];
    const seen = new Set();
    (Array.isArray(rooms) ? rooms : []).forEach((room, index) => {
      const id = Number(room?.venue_id);
      if (Number(room?.available) !== 1 || !Number.isSafeInteger(id) || id <= 0 || seen.has(id)) return;
      seen.add(id);
      const rawCapacity = Number(room.max_capacity);
      const capacity = Number.isFinite(rawCapacity) && rawCapacity > 0 ? Math.floor(rawCapacity) : 0;
      available.push({ id, capacity, index, ...roomBuilding(room, id) });
    });
    const selected = available.filter(room => selectedSet.has(room.id));
    const selectedAvailableIds = selected.map(room => room.id);
    const selectedAvailable = new Set(selectedAvailableIds);
    const groupsByKey = new Map();
    available.slice().sort((left, right) => left.id - right.id).forEach(room => {
      if (!groupsByKey.has(room.key)) groupsByKey.set(room.key, { key: room.key, name: room.name, allRooms: [], rooms: [], capacity: 0 });
      const group = groupsByKey.get(room.key);
      group.allRooms.push(room);
      group.capacity += room.capacity;
      if (room.capacity > 0 && !selectedAvailable.has(room.id)) group.rooms.push(room);
    });
    const groups = [...groupsByKey.values()];
    groups.forEach(group => group.rooms.sort((left, right) => right.capacity - left.capacity || left.id - right.id));
    const rosterCounts = { people, female, male, unknown };
    const inventoryCapacity = available.reduce((sum, room) => sum + room.capacity, 0);
    const selectedCapacity = selected.reduce((sum, room) => sum + room.capacity, 0);
    const inventoryGenderCapacity = available.reduce((sum, room) => sum + Math.min(room.capacity, people), 0);
    const selectedGenderCapacity = selected.reduce((sum, room) => sum + Math.min(room.capacity, people), 0);
    const describeSelection = roomList => {
      const keys = new Set(roomList.map(room => room.key));
      const buildingNames = [...keys].sort(compareText).map(key => groupsByKey.get(key)?.name || key);
      return { buildingCount: buildingNames.length, buildingNames };
    };
    const failure = reason => ({ success: false, reason, addedIds: [], selectedIds: selectedAvailableIds, roomCount: selected.length, capacity: selectedCapacity, ...describeSelection(selected), inventoryCapacity, inventoryShortfall: Math.max(0, people - inventoryCapacity), roster: rosterCounts });
    if (!people) return failure('empty_roster');
    if (inventoryCapacity < people) return failure('total_capacity');

    let subsetLimit = 0;
    if (female && male) {
      const largestGenderRoom = available.reduce((largest, room) => Math.max(largest, Math.min(room.capacity, people)), 0);
      subsetLimit = Math.min(inventoryGenderCapacity - male, female + largestGenderRoom - 1);
    }
    const createGenderTracker = roomsToTrack => {
      if (!female || !male) return null;
      const reachable = new Uint8Array(subsetLimit + 1);
      reachable[0] = 1;
      roomsToTrack.forEach(room => addToGenderCapacity(reachable, room));
      return reachable;
    };
    const addToGenderCapacity = (reachable, room) => {
      if (!reachable || !room.capacity) return;
      const capacity = Math.min(room.capacity, people);
      if (capacity > subsetLimit) return;
      for (let beds = subsetLimit; beds >= capacity; beds--) {
        if (reachable[beds - capacity]) reachable[beds] = 1;
      }
    };
    const fitsGenderGroups = (capacity, genderCapacity, reachable) => {
      if (capacity < people) return false;
      if (!female || !male) return true;
      const upper = Math.min(genderCapacity - male, subsetLimit);
      for (let femaleBeds = female; femaleBeds <= upper; femaleBeds++) {
        if (reachable[femaleBeds]) return true;
      }
      return false;
    };
    let reachable = createGenderTracker(selected);
    if (fitsGenderGroups(selectedCapacity, selectedGenderCapacity, reachable)) {
      return { success: true, reason: '', addedIds: [], selectedIds: selectedAvailableIds, roomCount: selected.length, capacity: selectedCapacity, ...describeSelection(selected), inventoryCapacity, roster: rosterCounts };
    }

    const added = [];
    let chosenCapacity = selectedCapacity;
    let chosenGenderCapacity = selectedGenderCapacity;
    const appendRoomsUntilFit = candidateGroups => {
      for (const group of candidateGroups) {
        for (const room of group.rooms) {
          added.push(room);
          chosenCapacity += room.capacity;
          chosenGenderCapacity += Math.min(room.capacity, people);
          addToGenderCapacity(reachable, room);
          if (fitsGenderGroups(chosenCapacity, chosenGenderCapacity, reachable)) return true;
        }
      }
      return false;
    };

    if (!selected.length) {
      let singleBuildingFit = null;
      const fittingGroups = groups.filter(group => group.capacity >= people)
        .sort((left, right) => compareText(left.key, right.key));
      for (const group of fittingGroups) {
        const singleRoomFit = (!female || !male)
          ? group.rooms.filter(room => room.capacity >= people).sort((left, right) => left.capacity - right.capacity || left.id - right.id)[0]
          : null;
        if (singleRoomFit) {
          const proposal = { group, rooms: [singleRoomFit], capacity: singleRoomFit.capacity };
          if (!singleBuildingFit || proposal.rooms.length < singleBuildingFit.rooms.length
              || (proposal.rooms.length === singleBuildingFit.rooms.length && proposal.capacity < singleBuildingFit.capacity)
              || (proposal.rooms.length === singleBuildingFit.rooms.length && proposal.capacity === singleBuildingFit.capacity && compareText(proposal.group.key, singleBuildingFit.group.key) < 0)) {
            singleBuildingFit = proposal;
          }
          continue;
        }
        let capacity = 0;
        let genderCapacity = 0;
        const groupRooms = [];
        const groupReachable = createGenderTracker([]);
        for (const room of group.rooms) {
          groupRooms.push(room);
          capacity += room.capacity;
          genderCapacity += Math.min(room.capacity, people);
          addToGenderCapacity(groupReachable, room);
          if (!fitsGenderGroups(capacity, genderCapacity, groupReachable)) continue;
          const proposal = { group, rooms: groupRooms.slice(), capacity };
          if (!singleBuildingFit || proposal.rooms.length < singleBuildingFit.rooms.length
              || (proposal.rooms.length === singleBuildingFit.rooms.length && proposal.capacity < singleBuildingFit.capacity)
              || (proposal.rooms.length === singleBuildingFit.rooms.length && proposal.capacity === singleBuildingFit.capacity && compareText(proposal.group.key, singleBuildingFit.group.key) < 0)) {
            singleBuildingFit = proposal;
          }
          break;
        }
      }
      if (singleBuildingFit) {
        const chosen = singleBuildingFit.rooms;
        const chosenSelection = available.filter(room => chosen.some(candidate => candidate.id === room.id));
        return { success: true, reason: '', addedIds: chosen.map(room => room.id), selectedIds: chosenSelection.map(room => room.id), roomCount: chosenSelection.length, capacity: chosenSelection.reduce((sum, room) => sum + room.capacity, 0), ...describeSelection(chosenSelection), inventoryCapacity, roster: rosterCounts };
      }
    }

    const selectedBuildingKeys = new Set(selected.map(room => room.key));
    const selectedBuildingGroups = groups.filter(group => selectedBuildingKeys.has(group.key) && group.rooms.length)
      .sort((left, right) => {
        const leftRemaining = left.rooms.reduce((sum, room) => sum + room.capacity, 0);
        const rightRemaining = right.rooms.reduce((sum, room) => sum + room.capacity, 0);
        return rightRemaining - leftRemaining || compareText(left.key, right.key);
      });
    const newBuildingGroups = groups.filter(group => !selectedBuildingKeys.has(group.key) && group.rooms.length)
      .sort((left, right) => right.capacity - left.capacity || compareText(left.key, right.key));
    const canFitInSelectedBuildings = appendRoomsUntilFit(selectedBuildingGroups);
    const canFit = canFitInSelectedBuildings || appendRoomsUntilFit(newBuildingGroups);
    if (!canFit) return failure('gender_separation');
    const chosenIds = new Set(selectedAvailableIds.concat(added.map(room => room.id)));
    const selectedRoomSet = available.filter(room => chosenIds.has(room.id));
    return { success: true, reason: '', addedIds: added.map(room => room.id), selectedIds: selectedRoomSet.map(room => room.id), roomCount: selectedRoomSet.length, capacity: selectedRoomSet.reduce((sum, room) => sum + room.capacity, 0), ...describeSelection(selectedRoomSet), inventoryCapacity, roster: rosterCounts };
  }

  if (typeof module !== 'undefined' && module.exports) module.exports = { suggestRoomSelection };

  const app = typeof document === 'undefined' ? null : document.getElementById('seminars-app');
  if (!app) return;
  const endpoint = app.dataset.endpoint;
  const workspace = document.getElementById('seminar-workspace');
  const list = document.getElementById('seminar-list');
  const savedPlans = document.getElementById('seminar-saved-plans');
  const feedback = document.getElementById('seminar-feedback');
  const feedbackMessage = feedback.querySelector('[data-feedback-message]');
  const feedbackAction = feedback.querySelector('[data-feedback-action]');
  const confirmationDialog = document.getElementById('seminar-confirm-dialog');
  const confirmationTitle = document.getElementById('seminar-confirm-title');
  const confirmationMessage = document.getElementById('seminar-confirm-message');
  const confirmationCancel = confirmationDialog.querySelector('[data-confirm-cancel]');
  const confirmationAccept = confirmationDialog.querySelector('[data-confirm-accept]');
  const pdfPreviewDialog = document.getElementById('seminar-pdf-dialog');
  const pdfPreviewTitle = document.getElementById('seminar-pdf-title');
  const pdfPreviewContext = pdfPreviewDialog.querySelector('[data-pdf-context]');
  const pdfPreviewStage = pdfPreviewDialog.querySelector('[data-pdf-stage]');
  const pdfPreviewLoading = pdfPreviewDialog.querySelector('[data-pdf-loading]');
  const pdfPreviewError = pdfPreviewDialog.querySelector('[data-pdf-error]');
  const pdfPreviewErrorMessage = pdfPreviewDialog.querySelector('[data-pdf-error-message]');
  const pdfPreviewFallback = pdfPreviewDialog.querySelector('[data-pdf-fallback]');
  const pdfPreviewFrame = pdfPreviewDialog.querySelector('[data-pdf-frame]');
  const pdfPreviewDownload = pdfPreviewDialog.querySelector('[data-pdf-download]');
  const pdfPreviewPrint = pdfPreviewDialog.querySelector('[data-pdf-print]');
  const pdfPreviewPrintHelp = pdfPreviewDialog.querySelector('[data-pdf-print-help]');
  const defaultPdfPrintHelp = pdfPreviewPrintHelp.textContent;
  const pdfPreviewClose = pdfPreviewDialog.querySelector('[data-pdf-close]');
  const pdfViewerReadyTimeout = 4000;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const state = { plans: [], current: null, import: null, validated: null, rooms: [], halls: [], selectedRooms: new Set(), assignments: {}, edits: {}, mixed: new Set(), selectedAttendees: new Set(), roomQuery: '', attendeeQuery: '', wizardStep: 1, wizardMode: 'new', reservationDraft: {}, sheetIndex: 0, mappings: {}, mappingEditorOpen: false, changeFileOpen: false, rosterFeedbackOnValidation: false, rosterUploadPromise: null, validationVersion: 0, hallRequestVersion: 0, hallAvailabilityStatus: '', pdfPreview: null };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const date = value => esc(value || '—');
  const floorDisplayLabel = value => {
    const label = String(value ?? '').trim();
    if (!label) return '';
    if (/\bfloor\b/i.test(label)) return label;
    return /^\d+(?:st|nd|rd|th)?$/i.test(label) ? `Floor ${label}` : label;
  };
  let feedbackActionHandler = null;
  let pendingConfirmation = null;
  const flash = (message, type = 'error', options = null) => {
    feedback.className = `seminar-feedback seminar-feedback--${type}`;
    feedback.setAttribute('role', type === 'error' ? 'alert' : 'status');
    feedback.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    feedbackMessage.textContent = String(message || 'Something went wrong. Please try again.');
    feedbackActionHandler = typeof options?.onAction === 'function' ? options.onAction : null;
    feedbackAction.textContent = options?.actionLabel || '';
    feedbackAction.hidden = !feedbackActionHandler;
    feedback.hidden = false;
  };
  function clearFeedback() {
    feedback.hidden = true;
    feedbackMessage.textContent = '';
    feedbackAction.textContent = '';
    feedbackAction.hidden = true;
    feedbackActionHandler = null;
  }
  function requestConfirmation({ title, message, confirmLabel = 'Continue', destructive = false }) {
    if (pendingConfirmation) return Promise.resolve(false);
    clearFeedback();
    confirmationTitle.textContent = title;
    confirmationMessage.textContent = message;
    confirmationAccept.textContent = confirmLabel;
    confirmationAccept.classList.toggle('seminar-button--danger', destructive);
    confirmationAccept.classList.toggle('seminar-button--primary', !destructive);
    confirmationDialog.returnValue = '';
    const invoker = document.activeElement;
    return new Promise(resolve => {
      pendingConfirmation = { resolve, invoker };
      try {
        if (typeof confirmationDialog.showModal === 'function') confirmationDialog.showModal();
        else {
          confirmationDialog.dataset.fallbackModal = 'true';
          confirmationDialog.setAttribute('open', '');
        }
        confirmationCancel.focus();
      } catch (error) {
        pendingConfirmation = null;
        resolve(false);
        flash('The confirmation window could not be opened. Please try again.');
      }
    });
  }
  function finishConfirmation(accepted) {
    if (!pendingConfirmation) return;
    if (confirmationDialog.open) confirmationDialog.close(accepted ? 'confirm' : 'cancel');
    else confirmationDialog.removeAttribute('open');
  }
  function markButtonBusy(button, label) {
    if (!button || button.disabled) return () => {};
    const original = button.innerHTML;
    button.disabled = true;
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');
    button.textContent = label;
    return () => {
      if (!button.isConnected) return;
      button.disabled = false;
      button.classList.remove('is-loading');
      button.removeAttribute('aria-busy');
      button.innerHTML = original;
    };
  };
  feedback.addEventListener('click', async event => {
    if (event.target.closest('[data-feedback-dismiss]')) { clearFeedback(); return; }
    if (!event.target.closest('[data-feedback-action]') || !feedbackActionHandler) return;
    const run = feedbackActionHandler;
    const restore = markButtonBusy(feedbackAction, 'Working…');
    try { await run(); }
    catch (error) { flash(error.message); }
    finally { restore(); }
  });
  confirmationCancel.addEventListener('click', () => finishConfirmation(false));
  confirmationAccept.addEventListener('click', () => finishConfirmation(true));
  confirmationDialog.addEventListener('close', () => {
    const pending = pendingConfirmation;
    pendingConfirmation = null;
    delete confirmationDialog.dataset.fallbackModal;
    if (!pending) return;
    if (pending.invoker?.isConnected) pending.invoker.focus({ preventScroll: true });
    pending.resolve(confirmationDialog.returnValue === 'confirm');
  });
  confirmationDialog.addEventListener('keydown', event => {
    if (!confirmationDialog.dataset.fallbackModal) return;
    if (event.key === 'Escape') { event.preventDefault(); finishConfirmation(false); return; }
    if (event.key !== 'Tab') return;
    const controls = [...confirmationDialog.querySelectorAll('button:not(:disabled)')];
    if (!controls.length) { event.preventDefault(); return; }
    const first = controls[0]; const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  function pdfRequestUrl(preview) {
    const url = new URL('actions/admin/seminar_pdf.php', document.baseURI);
    url.searchParams.set('id', preview.seminarId);
    url.searchParams.set('type', preview.type);
    if (preview.type === 'room') url.searchParams.set('room_id', preview.roomId);
    return url;
  }
  function resetPdfPreviewView() {
    pdfPreviewLoading.hidden = false;
    pdfPreviewError.hidden = true;
    pdfPreviewFallback.hidden = true;
    pdfPreviewStage.setAttribute('aria-busy', 'true');
    pdfPreviewFrame.hidden = true;
    pdfPreviewPrint.disabled = true;
    pdfPreviewDownload.hidden = true;
    pdfPreviewDownload.removeAttribute('href');
    pdfPreviewDownload.removeAttribute('download');
  }
  function showPdfPreviewError(message, downloadReady = false) {
    pdfPreviewLoading.hidden = true;
    pdfPreviewError.hidden = false;
    pdfPreviewErrorMessage.textContent = message;
    pdfPreviewStage.setAttribute('aria-busy', 'false');
    pdfPreviewPrint.disabled = true;
    if (!downloadReady) {
      pdfPreviewDownload.hidden = true;
      pdfPreviewDownload.removeAttribute('href');
      pdfPreviewDownload.removeAttribute('download');
    }
  }
  function pdfHttpError(status) {
    if (status === 401) return 'Your staff session may have expired. Sign in again, then reopen this preview.';
    if (status === 403) return 'Your account does not have access to this PDF.';
    if (status === 404 || status === 409) return 'This room sheet is no longer available. Refresh the seminar plan and try again.';
    if (status === 422) return 'This PDF request is invalid. Refresh the seminar plan and try again.';
    return `The PDF could not be loaded (HTTP ${status}). Try again.`;
  }
  function showPdfViewerFallback(preview) {
    if (state.pdfPreview !== preview || preview.viewerReady) return;
    window.clearTimeout(preview.viewerTimer);
    preview.viewerTimer = null;
    preview.viewerUnavailable = true;
    pdfPreviewLoading.hidden = true;
    pdfPreviewError.hidden = true;
    pdfPreviewFallback.hidden = false;
    pdfPreviewFrame.hidden = true;
    pdfPreviewStage.setAttribute('aria-busy', 'false');
    pdfPreviewPrint.disabled = true;
    pdfPreviewPrintHelp.textContent = 'This browser cannot print the embedded preview. Download the PDF below to view and print it.';
  }
  function markPdfViewerReady(preview) {
    if (state.pdfPreview !== preview) return;
    window.clearTimeout(preview.viewerTimer);
    preview.viewerTimer = null;
    preview.viewerReady = true;
    preview.viewerUnavailable = false;
    pdfPreviewLoading.hidden = true;
    pdfPreviewError.hidden = true;
    pdfPreviewFallback.hidden = true;
    pdfPreviewFrame.hidden = false;
    pdfPreviewStage.setAttribute('aria-busy', 'false');
    pdfPreviewPrint.disabled = false;
    pdfPreviewPrintHelp.textContent = defaultPdfPrintHelp;
  }
  async function loadPdfPreview(preview) {
    if (!preview || state.pdfPreview !== preview || preview.loading) return;
    preview.loading = true;
    window.clearTimeout(preview.viewerTimer);
    preview.viewerTimer = null;
    preview.viewerReady = false;
    preview.viewerUnavailable = false;
    preview.controller = new AbortController();
    if (preview.objectUrl) {
      URL.revokeObjectURL(preview.objectUrl);
      preview.objectUrl = null;
    }
    pdfPreviewFrame.onload = null;
    pdfPreviewFrame.onerror = null;
    pdfPreviewFrame.removeAttribute('src');
    resetPdfPreviewView();
    try {
      const url = pdfRequestUrl(preview);
      if (url.origin !== window.location.origin) throw new Error('The PDF request must stay on this site.');
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf' },
        signal: preview.controller.signal
      });
      if (!response.ok) throw new Error(pdfHttpError(response.status));
      const contentType = (response.headers.get('Content-Type') || '').split(';', 1)[0].trim().toLowerCase();
      if (contentType !== 'application/pdf') throw new Error('The server returned a sign-in or error page instead of a PDF. Refresh the page and try again.');
      const blob = await response.blob();
      if (!blob.size) throw new Error('The PDF is empty. Try again or contact an administrator.');
      const objectUrl = URL.createObjectURL(blob);
      if (state.pdfPreview !== preview || !pdfPreviewDialog.open) {
        URL.revokeObjectURL(objectUrl);
        return;
      }
      preview.objectUrl = objectUrl;
      const roomSuffix = preview.type === 'room' ? `-${preview.roomId}` : '';
      pdfPreviewDownload.href = objectUrl;
      pdfPreviewDownload.download = `seminar-${preview.seminarId}-${preview.type}${roomSuffix}.pdf`;
      pdfPreviewDownload.hidden = false;
      pdfPreviewFrame.title = `${preview.context} PDF preview`;
      pdfPreviewFrame.onload = () => {
        if (state.pdfPreview !== preview) return;
        let frameLocation = '';
        try { frameLocation = pdfPreviewFrame.contentWindow?.location?.href || ''; } catch (error) { /* Embedded PDF viewers may use an isolated origin. */ }
        if (frameLocation === 'about:blank') return;
        markPdfViewerReady(preview);
      };
      pdfPreviewFrame.onerror = () => {
        showPdfViewerFallback(preview);
      };
      pdfPreviewFrame.hidden = false;
      preview.viewerTimer = window.setTimeout(() => showPdfViewerFallback(preview), pdfViewerReadyTimeout);
      pdfPreviewFrame.src = objectUrl;
    } catch (error) {
      if (error.name === 'AbortError' || state.pdfPreview !== preview) return;
      window.clearTimeout(preview.viewerTimer);
      preview.viewerTimer = null;
      const message = error instanceof TypeError
        ? 'The PDF could not be loaded. Check your connection and try again.'
        : error.message || 'The PDF could not be loaded. Check your connection and try again.';
      showPdfPreviewError(message);
    } finally {
      if (state.pdfPreview === preview) preview.loading = false;
    }
  }
  function openPdfPreview(button) {
    const seminarId = Number(button.dataset.pdfId);
    const type = button.dataset.pdfType;
    const roomId = Number(button.dataset.pdfRoomId || 0);
    if (!Number.isSafeInteger(seminarId) || seminarId < 1 || !['rooms', 'room', 'list'].includes(type) || (type === 'room' && (!Number.isSafeInteger(roomId) || roomId < 1))) {
      flash('This PDF request is invalid. Refresh the seminar plan and try again.');
      return;
    }
    if (typeof pdfPreviewDialog.showModal !== 'function' || typeof URL.createObjectURL !== 'function') {
      flash('This browser cannot open an in-page PDF preview. Update your browser and try again.');
      return;
    }
    const label = button.dataset.pdfLabel || (type === 'list' ? 'Name-to-room list' : type === 'room' ? 'Room sheet' : 'Room sheets');
    const context = [button.dataset.pdfSeminar, label].filter(Boolean).join(' · ');
    const title = type === 'list' ? 'Name-to-room list preview' : type === 'room' ? 'Room sheet preview' : 'Room sheets preview';
    const preview = { seminarId, type, roomId, context, invoker: button, controller: null, objectUrl: null, loading: false };
    state.pdfPreview = preview;
    pdfPreviewTitle.textContent = title;
    pdfPreviewContext.textContent = context;
    pdfPreviewFrame.title = `${context} PDF preview`;
    pdfPreviewErrorMessage.textContent = '';
    pdfPreviewPrintHelp.textContent = defaultPdfPrintHelp;
    resetPdfPreviewView();
    try {
      pdfPreviewDialog.showModal();
      pdfPreviewClose.focus();
      loadPdfPreview(preview);
    } catch (error) {
      state.pdfPreview = null;
      flash('The PDF preview could not be opened. Please try again.');
    }
  }
  function closePdfPreview() {
    if (pdfPreviewDialog.open) pdfPreviewDialog.close();
  }
  function printPdfPreview() {
    const preview = state.pdfPreview;
    if (!preview?.objectUrl || !preview.viewerReady) return;
    try {
      const previewWindow = pdfPreviewFrame.contentWindow;
      if (!previewWindow || typeof previewWindow.print !== 'function') throw new Error('Print is unavailable in this PDF viewer.');
      previewWindow.focus();
      previewWindow.print();
    } catch (error) {
      pdfPreviewPrintHelp.textContent = 'This browser did not open its print dialog. Use the PDF viewer’s print control or download the file and print it from your device.';
    }
  }
  app.addEventListener('click', event => {
    const trigger = event.target.closest('[data-pdf-preview]');
    if (trigger) { event.preventDefault(); openPdfPreview(trigger); return; }
    if (event.target.closest('[data-pdf-close]')) { event.preventDefault(); closePdfPreview(); return; }
    if (event.target.closest('[data-pdf-retry]')) { event.preventDefault(); loadPdfPreview(state.pdfPreview); return; }
    if (event.target.closest('[data-pdf-print]')) { event.preventDefault(); printPdfPreview(); }
  });
  pdfPreviewDialog.addEventListener('close', () => {
    const preview = state.pdfPreview;
    state.pdfPreview = null;
    if (!preview) return;
    preview.controller?.abort();
    window.clearTimeout(preview.viewerTimer);
    if (preview.objectUrl) URL.revokeObjectURL(preview.objectUrl);
    pdfPreviewFrame.onload = null;
    pdfPreviewFrame.onerror = null;
    pdfPreviewFrame.removeAttribute('src');
    pdfPreviewFrame.hidden = true;
    pdfPreviewPrint.disabled = true;
    pdfPreviewDownload.hidden = true;
    pdfPreviewDownload.removeAttribute('href');
    pdfPreviewDownload.removeAttribute('download');
    pdfPreviewLoading.hidden = false;
    pdfPreviewError.hidden = true;
    pdfPreviewFallback.hidden = true;
    pdfPreviewStage.setAttribute('aria-busy', 'false');
    if (preview.invoker?.isConnected) preview.invoker.focus({ preventScroll: true });
  });
  async function request(op, payload = null, method = 'POST') {
    clearFeedback();
    const url = new URL(endpoint, window.location.href);
    if (method === 'GET') {
      url.searchParams.set('op', op);
      Object.entries(payload || {}).forEach(([key, value]) => url.searchParams.set(key, value));
    }
    const response = await fetch(url, { method, headers: method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: method === 'GET' ? undefined : JSON.stringify({ op, ...payload }) });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.success) throw new Error(result.message || 'Unable to complete the request.');
    return result;
  }
  async function listPlans() {
    if (!state.plans.length) {
      savedPlans.hidden = false;
      list.innerHTML = '<p class="seminar-loading-copy" role="status"><span class="seminar-loading-indicator" aria-hidden="true"></span>Loading saved seminar plans…</p>';
    }
    savedPlans.setAttribute('aria-busy', 'true');
    try {
      const result = await request('list', null, 'GET');
      state.plans = result.seminars || [];
      list.innerHTML = state.plans.map(plan => `<button type="button" class="seminar-list-item ${Number(state.current?.id) === Number(plan.id) ? 'is-active' : ''}" data-open="${Number(plan.id)}"><span class="seminar-list-item__title">${esc(plan.name)}</span><span class="seminar-list-item__meta">${esc(plan.status)} · ${Number(plan.attendee_count)} attendees</span><span class="seminar-list-item__meta">${date(plan.hall_start_date)} – ${date(plan.hall_end_date)}</span></button>`).join('');
      savedPlans.hidden = state.plans.length === 0;
    } catch (error) {
      savedPlans.hidden = state.plans.length === 0;
      throw error;
    } finally { savedPlans.removeAttribute('aria-busy'); }
  }
  function showPlanListError(error) {
    flash(`Saved seminar plans could not be loaded. ${error.message}`, 'error', {
      actionLabel: 'Retry',
      onAction: async () => {
        try { await listPlans(); }
        catch (retryError) { showPlanListError(retryError); }
      }
    });
  }
  async function openPlan(id) {
    if (state.rosterUploadPromise) {
      flash('Wait for the roster upload to finish before opening another plan.', 'info');
      return;
    }
    const planId = Number(id);
    const button = list.querySelector(`[data-open="${planId}"]`);
    const restore = markButtonBusy(button, 'Opening plan…');
    try {
      const result = await request('get', { id: planId }, 'GET');
      state.current = result.seminar;
      state.selectedAttendees.clear();
      renderPlan();
    } catch (error) {
      flash(`This seminar could not be opened. ${error.message}`, 'error', { actionLabel: 'Retry', onAction: () => openPlan(planId) });
    } finally { restore(); }
  }
  function emptyScreen() {
    state.current = null;
    state.replaceTarget = null;
    state.import = null;
    state.validated = null;
    state.selectedRooms.clear();
    state.wizardStep = 1;
    state.wizardMode = 'new';
    state.reservationDraft = {};
    state.mappings = {};
    state.sheetIndex = 0;
    state.mappingEditorOpen = false;
    state.changeFileOpen = false;
    state.rosterFeedbackOnValidation = false;
    importScreen();
    listPlans().catch(showPlanListError);
  }
  function mappingForm(sheet) {
    const fields = [['name', 'Attendee name', true], ['gender', 'Gender', true], ['location', 'Location', true], ['contact', 'Contact (optional)', false]];
    const options = ['<option value="">Choose a column</option>'].concat((sheet.headers || []).map((header, index) => `<option value="${index}">${esc(header || `Column ${index + 1}`)}</option>`)).join('');
    return fields.map(([key, label, required]) => `<label class="seminar-field"><span>${label}${required ? ' *' : ''}</span><select data-map="${key}" ${required ? 'required' : ''}>${options}</select></label>`).join('');
  }
  function wizardProgress(step) {
    const labels = ['Import attendees', 'Seminar details', 'Select rooms', 'Review and print'];
    return `<nav class="seminar-wizard-progress" aria-label="Seminar setup steps">${labels.map((label, index) => `<div class="seminar-wizard-progress__step ${index + 1 === step ? 'is-current' : ''} ${index + 1 < step ? 'is-complete' : ''}" ${index + 1 === step ? 'aria-current="step"' : ''}><span>${index + 1}</span><strong>${label}</strong></div>`).join('')}</nav>`;
  }
  function importScreen(replaceTarget = state.replaceTarget) {
    state.replaceTarget = replaceTarget ? Number(replaceTarget) : null;
    state.wizardStep = 1;
    state.wizardMode = state.replaceTarget ? 'replace' : 'new';
    const replaceBack = state.replaceTarget ? '<button class="seminar-button seminar-button--quiet" data-back-list type="button">Back to plan</button>' : '';
    const mappingPanel = state.import ? '<section class="seminar-mapping-panel" id="seminar-mapping-panel"><h3>Review roster</h3><div id="seminar-mapping-content"></div></section>' : '';
    const uploadMarkup = state.import && !state.changeFileOpen
      ? `<div class="seminar-loaded-file"><div><strong>${esc(state.import.filename || 'Roster uploaded')}</strong><span>Current roster preview remains active until a replacement uploads successfully.</span></div><button class="seminar-button seminar-button--quiet" id="seminar-change-file" type="button">Change file</button></div>`
      : `<form id="seminar-upload-form" class="seminar-upload-form"><label class="seminar-dropzone seminar-file-picker"><input class="seminar-file-input" type="file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><span class="seminar-file-picker__button">Choose roster file</span><span class="seminar-upload-copy"><strong data-file-name>Select the client roster to preview it</strong><small>${state.import ? 'Your current preview remains until this upload succeeds.' : 'CSV or XLSX, up to 5 MB. Name, gender and location are required; contact is optional.'}</small></span></label><p class="seminar-upload-status" data-upload-status role="status" aria-live="polite" hidden></p>${state.import ? '<div class="seminar-upload-controls"><button class="seminar-button seminar-button--quiet" id="seminar-keep-file" type="button">Keep current roster</button></div>' : ''}</form>`;
    workspace.innerHTML = `${wizardProgress(1)}<div class="seminar-flow-head"><h2>${state.replaceTarget ? 'Replace the draft roster' : 'Upload the attendee roster'}</h2><p>${state.replaceTarget ? 'Map and check the replacement file before replacing attendees.' : 'CSV and XLSX files up to 5 MB. Required columns: name, gender and location.'}</p></div><section class="seminar-upload-area" aria-label="Upload attendee roster">${uploadMarkup}</section>${mappingPanel}<div class="seminar-actions-row">${replaceBack}<button class="seminar-button seminar-button--primary" id="seminar-continue" type="button" ${state.validated?.count && !state.validated.errors?.length ? '' : 'disabled'}>${state.replaceTarget ? 'Replace draft roster' : 'Continue to seminar details'}</button></div>`;
    workspace.querySelector('#seminar-change-file')?.addEventListener('click', () => { state.changeFileOpen = true; importScreen(); workspace.querySelector('#seminar-upload-form input[type="file"]')?.focus(); });
    workspace.querySelector('#seminar-keep-file')?.addEventListener('click', () => { state.changeFileOpen = false; importScreen(); });
    workspace.querySelector('#seminar-upload-form input[type="file"]')?.addEventListener('change', event => {
      const selected = workspace.querySelector('[data-file-name]');
      if (selected) selected.textContent = event.target.files?.[0]?.name || 'Select the client roster to preview it';
      if (event.target.files?.length) uploadSelectedRoster(event.target.form);
    });
    if (state.import) renderImportMetadata(state.validated?.sheet ?? state.sheetIndex);
  }
  function renderImportMetadata(sheetIndex = 0) {
    const upload = state.import;
    const sheet = upload?.sheets?.[sheetIndex];
    const holder = document.getElementById('seminar-mapping-content');
    if (!sheet || !holder) return;
    state.sheetIndex = sheetIndex;
    const suggested = state.mappings[sheetIndex] || sheet.mapping || {};
    const requiredMapped = ['name', 'gender', 'location'].every(key => suggested[key] !== undefined && suggested[key] !== '' && !!sheet.headers?.[Number(suggested[key])]);
    state.mappingEditorOpen = state.mappingEditorOpen || !requiredMapped;
    const detailsOpen = state.mappingEditorOpen ? ' open' : '';
    holder.innerHTML = `<div class="seminar-mapping-summary" id="seminar-mapping-summary" aria-live="polite"></div><details class="seminar-mapping-editor"${detailsOpen}><summary>Change worksheet or columns</summary><div class="seminar-mapping-editor__body"><label class="seminar-field"><span>Worksheet</span><select id="seminar-sheet">${upload.sheets.map((item, index) => `<option value="${index}" ${index === sheetIndex ? 'selected' : ''}>${esc(item.name)}</option>`).join('')}</select></label><div class="seminar-map-grid">${mappingForm(sheet)}</div></div></details><div id="seminar-preview-result" class="seminar-preview-result"><p class="seminar-muted">Checking roster…</p></div>`;
    holder.querySelectorAll('[data-map]').forEach(select => { if (suggested[select.dataset.map] !== undefined) select.value = String(suggested[select.dataset.map]); });
    const editor = holder.querySelector('.seminar-mapping-editor');
    editor.addEventListener('toggle', () => { state.mappingEditorOpen = editor.open; });
    holder.querySelector('#seminar-sheet').addEventListener('change', event => { saveVisibleMapping(); renderImportMetadata(Number(event.target.value)); });
    renderMappingSummary(sheet);
    validatePreview();
  }
  function renderMappingSummary(sheet = state.import?.sheets?.[state.sheetIndex]) {
    const target = document.getElementById('seminar-mapping-summary');
    if (!target || !sheet) return;
    const mapping = state.mappings[state.sheetIndex] || sheet.mapping || {};
    const isMapped = key => mapping[key] !== undefined && mapping[key] !== '' && !!sheet.headers?.[Number(mapping[key])];
    const missing = [['name', 'Name'], ['gender', 'Gender'], ['location', 'Location']].filter(([key]) => !isMapped(key)).map(([, label]) => label);
    const mappedFields = isMapped('contact') ? 'Name, Gender, Location, and Contact' : 'Name, Gender, and Location';
    const summary = missing.length
      ? `Columns to map: ${missing.join(', ')}`
      : `${mappedFields} columns matched${isMapped('contact') ? '' : ' · Contact optional'}`;
    target.innerHTML = `<span><strong>${esc(sheet.name)} worksheet</strong> · ${esc(summary)}</span>`;
  }
  function saveVisibleMapping() {
    if (!state.import) return;
    const sheet = Number(workspace.querySelector('#seminar-sheet')?.value ?? state.sheetIndex);
    state.mappings[sheet] = readMapping();
  }
  function readMapping() {
    const mapping = {};
    workspace.querySelectorAll('[data-map]').forEach(select => { mapping[select.dataset.map] = select.value; });
    return mapping;
  }
  async function validatePreview() {
    const panel = document.getElementById('seminar-preview-result');
    if (!state.import || !panel) return;
    const continueButton = document.getElementById('seminar-continue');
    if (continueButton) continueButton.disabled = true;
    panel.innerHTML = '<p class="seminar-loading-copy" role="status"><span class="seminar-loading-indicator" aria-hidden="true"></span>Checking roster rows and mapping…</p>';
    const sheet = Number(workspace.querySelector('#seminar-sheet')?.value || 0);
    const mapping = readMapping();
    state.mappings[sheet] = mapping;
    const version = ++state.validationVersion;
    const token = state.import.token;
    try {
      const result = await request('preview_validate', { token, sheet, mapping });
      if (version !== state.validationVersion || state.import?.token !== token || Number(workspace.querySelector('#seminar-sheet')?.value ?? -1) !== sheet) return;
      state.validated = { ...result, sheet, mapping };
      const summary = result.summary || { gender_counts: {}, location_counts: [] };
      const errorRows = (result.errors || []).map(item => `<li><strong>Row ${Number(item.row)}:</strong> ${esc((item.issues || []).join(', '))}</li>`).join('');
      const gender = summary.gender_counts || {};
      const locations = summary.location_counts || [];
      const locationMarkup = (items, className = '') => `<ul class="seminar-roster-locations__list ${className}">${items.map(item => `<li><span>${esc(item.name || 'Unspecified')}</span><strong>${Number(item.count)}</strong></li>`).join('')}</ul>`;
      const shownLocations = locations.slice(0, 6);
      const moreLocations = locations.slice(6);
      panel.innerHTML = `<div class="seminar-roster-summary"><div class="seminar-roster-summary__overview"><p class="seminar-roster-summary__count"><strong>${Number(result.count)}</strong><span>valid attendee${Number(result.count) === 1 ? '' : 's'}</span></p><div class="seminar-roster-summary__gender"><strong>Gender mix</strong><span>${Number(gender.female || 0)} female · ${Number(gender.male || 0)} male · ${Number(gender.unknown || 0)} unknown</span></div></div><div class="seminar-roster-locations"><div class="seminar-roster-locations__head"><strong>Location mix</strong><span>${locations.length} location${locations.length === 1 ? '' : 's'}</span></div>${shownLocations.length ? locationMarkup(shownLocations) : '<p class="seminar-roster-empty">No locations found.</p>'}${moreLocations.length ? `<details class="seminar-roster-locations__more"><summary>Show ${moreLocations.length} more locations</summary>${locationMarkup(moreLocations)}</details>` : ''}</div><div class="seminar-roster-validation ${result.errors?.length ? 'has-errors' : 'is-valid'}" role="status" aria-live="polite"><strong>${result.errors?.length ? `${result.errors.length} row issue${result.errors.length === 1 ? '' : 's'}` : 'All populated rows are valid'}</strong>${errorRows ? `<ul class="seminar-errors-list">${errorRows}</ul>` : ''}</div></div>`;
      if (continueButton?.isConnected) continueButton.disabled = !!state.rosterUploadPromise || !result.count || !!result.errors?.length;
      if (state.rosterFeedbackOnValidation) {
        state.rosterFeedbackOnValidation = false;
        if (result.count && !result.errors?.length) flash(state.replaceTarget
          ? `Roster ready: ${Number(result.count)} attendee${Number(result.count) === 1 ? '' : 's'}. Review it, then replace the draft roster.`
          : `Roster ready: ${Number(result.count)} attendee${Number(result.count) === 1 ? '' : 's'}. Continue to seminar details.`, 'success');
        else flash(result.count ? 'Review the highlighted row issues before continuing.' : 'No attendees were found. Check the roster mapping or file.', 'info');
      }
    } catch (error) {
      if (version !== state.validationVersion) return;
      state.validated = null;
      panel.innerHTML = `<p class="seminar-error">${esc(error.message)}</p>`;
      if (continueButton?.isConnected) continueButton.disabled = true;
      if (state.rosterFeedbackOnValidation) flash(`The roster uploaded, but its preview could not be validated: ${error.message}`);
    }
  }
  function roomGroups(rooms) {
    const groups = new Map();
    const displayNames = new Map();
    rooms.slice().sort((left, right) => Number(left.venue_id) - Number(right.venue_id)).forEach(room => {
      const building = roomBuilding(room, Number(room.venue_id));
      if (!displayNames.has(building.key)) displayNames.set(building.key, building.name);
    });
    rooms.forEach(room => {
      const building = roomBuilding(room, Number(room.venue_id));
      if (!groups.has(building.key)) groups.set(building.key, { name: displayNames.get(building.key), units: [] });
      groups.get(building.key).units.push(room);
    });
    return [...groups.entries()].sort((left, right) => compareText(left[0], right[0])).map(([, { name: building, units }]) => `<details class="seminar-building" open><summary><span>${esc(building)}</span><small>${units.length} room${units.length === 1 ? '' : 's'}</small><button class="seminar-text-link" type="button" data-select-building="${esc(building)}" aria-label="Select available rooms in ${esc(building)}">Select available</button></summary><div class="seminar-room-options">${units.map(room => { const available = Number(room.available) === 1; const floor = String(room.floor_label || '').trim(); const floorText = floorDisplayLabel(floor); const searchText = `${building} ${room.room_label || room.room_type || ''} ${room.room_number || ''} ${floor} ${floorText || 'Floor not recorded'}`.toLowerCase(); return `<label class="seminar-room-option ${available ? '' : 'is-unavailable'}" data-room-option data-search="${esc(searchText)}"><input type="checkbox" data-room-check value="${Number(room.venue_id)}" ${state.selectedRooms.has(Number(room.venue_id)) && available ? 'checked' : ''} ${available ? '' : 'disabled'}><span class="seminar-room-option__copy"><strong>${esc(room.room_number ? `Room ${room.room_number}` : room.name)}</strong><small>${esc(room.room_label || room.room_type || 'Guest room')}</small><small class="${floor ? 'seminar-room-floor' : 'seminar-room-floor seminar-floor-missing'}">${esc(floorText || 'Floor not recorded')}</small>${available ? '' : `<small class="seminar-availability">${esc(room.unavailable_reason || 'Unavailable for these dates')}</small>`}</span><span class="seminar-room-capacity">${Number(room.max_capacity)} beds</span></label>`; }).join('')}</div></details>`).join('');
  }
  function selectedSummary() {
    const selected = state.rooms.filter(room => state.selectedRooms.has(Number(room.venue_id)) && Number(room.available) === 1);
    const capacity = selected.reduce((sum, room) => sum + Number(room.max_capacity || 0), 0);
    const people = Number(state.validated?.count || state.current?.attendees?.length || 0);
    const buildings = new Map();
    state.rooms.slice().sort((left, right) => Number(left.venue_id) - Number(right.venue_id)).forEach(room => {
      const building = roomBuilding(room, Number(room.venue_id));
      if (!buildings.has(building.key)) buildings.set(building.key, building.name);
    });
    const selectedKeys = new Set(selected.map(room => roomBuilding(room, Number(room.venue_id)).key));
    const buildingNames = [...selectedKeys].sort(compareText).map(key => buildings.get(key) || key);
    return { selected, capacity, people, shortfall: Math.max(0, people - capacity), buildingCount: buildingNames.length, buildingNames };
  }
  function currentRosterRoomCounts() {
    const attendees = Array.isArray(state.current?.attendees) ? state.current.attendees : null;
    let female = 0;
    let male = 0;
    let people = 0;
    if (attendees) {
      people = attendees.length;
      attendees.forEach(person => {
        const gender = String(person.gender || '').trim().toLowerCase();
        if (gender === 'female') female++;
        else if (gender === 'male') male++;
      });
    } else {
      people = Number(state.validated?.count || 0);
      const genderCounts = state.validated?.summary?.gender_counts || {};
      female = Number(genderCounts.female || 0);
      male = Number(genderCounts.male || 0);
    }
    people = Number.isSafeInteger(people) && people > 0 ? people : 0;
    female = Number.isSafeInteger(female) && female > 0 ? Math.min(people, female) : 0;
    male = Number.isSafeInteger(male) && male > 0 ? Math.min(people - female, male) : 0;
    return { people, female, male, unknown: Math.max(0, people - female - male) };
  }
  function clearRoomSuggestionStatus() {
    const status = workspace.querySelector('[data-room-suggestion-status]');
    if (status) { status.hidden = true; status.textContent = ''; status.removeAttribute('data-state'); }
  }
  function showRoomSuggestionStatus(result) {
    const status = workspace.querySelector('[data-room-suggestion-status]');
    if (!status) return;
    const roster = result.roster;
    const unknownNote = roster.unknown
      ? ` ${roster.unknown} unknown-gender attendee${roster.unknown === 1 ? '' : 's'} remain for review; attendee assignments happen when you continue.`
      : '';
    const buildingDetail = result.buildingCount
      ? `${result.buildingCount} hotel building${result.buildingCount === 1 ? '' : 's'}${result.buildingNames.length ? ` (${result.buildingNames.join(', ')})` : ''}`
      : 'no hotel buildings';
    let message;
    if (result.success) {
      const currentSelection = result.addedIds.length === 0;
      const roomDetail = `${result.roomCount} selected room${result.roomCount === 1 ? '' : 's'} ${result.roomCount === 1 ? 'provides' : 'provide'} ${result.capacity} beds`;
      const genderDetail = roster.female && roster.male ? ' Female and male groups can fit in separate rooms.' : '';
      message = `${currentSelection ? 'Your current selection already covers the roster.' : `Added ${result.addedIds.length} room${result.addedIds.length === 1 ? '' : 's'}.`} ${roomDetail} across ${buildingDetail} for ${roster.people} attendee${roster.people === 1 ? '' : 's'}.${genderDetail}${unknownNote}`;
      status.dataset.state = 'ready';
    } else if (result.reason === 'total_capacity') {
      const selectedShortfall = Math.max(0, roster.people - result.capacity);
      message = `Available inventory is short by ${result.inventoryShortfall} bed${result.inventoryShortfall === 1 ? '' : 's'} for this ${roster.people}-person roster. Your current ${result.roomCount}-room selection across ${buildingDetail} remains unchanged with ${result.capacity} beds${selectedShortfall ? `, leaving ${selectedShortfall} unreserved` : ''}.${unknownNote}`;
      status.dataset.state = 'warning';
    } else if (result.reason === 'gender_separation') {
      message = `Available inventory has ${result.inventoryCapacity} beds for ${roster.people} attendees, but its room capacities cannot fit ${roster.female} female and ${roster.male} male attendees in separate rooms. Your current selection across ${buildingDetail} remains unchanged.${unknownNote}`;
      status.dataset.state = 'warning';
    } else {
      message = `There are no validated attendees to size rooms for. Your current selection across ${buildingDetail} remains unchanged.`;
      status.dataset.state = 'warning';
    }
    status.textContent = message;
    status.hidden = false;
  }
  async function loadAvailableRooms(checkIn, checkOut, excludeId = null) {
    const query = { check_in: checkIn, check_out: checkOut };
    if (excludeId) query.exclude_id = excludeId;
    const result = await request('room_options', query, 'GET');
    const keep = state.selectedRooms;
    state.rooms = result.rooms || [];
    state.selectedRooms = new Set(state.rooms.filter(room => keep.has(Number(room.venue_id)) && Number(room.available) === 1).map(room => Number(room.venue_id)));
    return state.rooms;
  }
  function captureReservationDraft() {
    const name = document.getElementById('seminar-name')?.value;
    const hallId = document.getElementById('seminar-hall')?.value;
    const dates = formDates();
    state.reservationDraft = { ...state.reservationDraft, name: name ?? state.reservationDraft.name ?? '', hall_venue_id: hallId ?? state.reservationDraft.hall_venue_id ?? '', ...dates };
  }
  function renderDetailsScreen() {
    state.wizardStep = 2;
    const draft = state.reservationDraft;
    const attendees = Number(state.validated?.count || state.current?.attendees?.length || 0);
    workspace.innerHTML = `${wizardProgress(2)}
      <div class="seminar-flow-head">
        <h2>Seminar details and reservations</h2>
        <p>${attendees} attendee${attendees === 1 ? '' : 's'} ready. Set the inclusive Event Hall dates, then the hotel check-in and departure dates.</p>
      </div>
      <div class="seminar-reservation-form">
        <div class="seminar-reservation-name">
          <label class="seminar-field" for="seminar-name"><span>Seminar name *</span><input id="seminar-name" maxlength="180" required value="${esc(draft.name || '')}" placeholder="e.g. Regional Leadership Workshop"></label>
        </div>
        <div class="seminar-reservation-panels">
          <section class="seminar-reservation-group" aria-labelledby="seminar-hall-heading" aria-describedby="seminar-hall-help">
            <h3 id="seminar-hall-heading">Event Hall booking</h3>
            <p class="seminar-reservation-group__help" id="seminar-hall-help">The Hall is reserved on both the start and end dates.</p>
            <div class="seminar-reservation-group__fields seminar-reservation-group__fields--hall">
              <label class="seminar-field seminar-field--hall-select" for="seminar-hall"><span>Event Hall *</span><select id="seminar-hall" required><option value="">Choose an available hall</option>${state.halls.map(hall => { const available = Number(hall.available) === 1; return `<option value="${Number(hall.id)}" ${Number(draft.hall_venue_id) === Number(hall.id) ? 'selected' : ''} ${available ? '' : 'disabled'}>${esc(hall.name)} · capacity ${Number(hall.capacity)}${available ? '' : ` · ${esc(hall.unavailable_reason || 'Unavailable')}`}</option>`; }).join('')}</select><small class="seminar-field-hint" id="seminar-hall-status" aria-live="polite">${esc(state.hallAvailabilityStatus || 'Availability updates when dates change.')}</small></label>
              <label class="seminar-field" for="seminar-hall-start"><span>Hall start date *</span><input type="date" id="seminar-hall-start" required value="${esc(draft.hall_start || '')}"></label>
              <label class="seminar-field" for="seminar-hall-end"><span>Hall end date (inclusive) *</span><input type="date" id="seminar-hall-end" required value="${esc(draft.hall_end || '')}"></label>
            </div>
          </section>
          <section class="seminar-reservation-group" aria-labelledby="seminar-hotel-heading" aria-describedby="seminar-hotel-help">
            <h3 id="seminar-hotel-heading">Hotel stay</h3>
            <p class="seminar-reservation-group__help" id="seminar-hotel-help">Departure is checkout; no overnight stay is counted on that date.</p>
            <div class="seminar-reservation-group__fields seminar-reservation-group__fields--hotel">
              <label class="seminar-field" for="seminar-check-in"><span>Hotel check-in *</span><input type="date" id="seminar-check-in" required value="${esc(draft.hotel_check_in || '')}"></label>
              <label class="seminar-field" for="seminar-check-out"><span>Hotel departure date (checkout) *</span><input type="date" id="seminar-check-out" required value="${esc(draft.hotel_check_out || '')}"></label>
            </div>
          </section>
        </div>
      </div>
      <div class="seminar-actions-row"><button class="seminar-button seminar-button--quiet" type="button" data-wizard-back="1">${state.wizardMode === 'edit' ? 'Back to plan' : 'Back to roster'}</button><button class="seminar-button seminar-button--primary" type="button" id="seminar-details-continue">Continue to room selection</button></div>`;
    workspace.querySelectorAll('#seminar-name,#seminar-hall,#seminar-check-in,#seminar-check-out').forEach(input => input.addEventListener('change', captureReservationDraft));
    workspace.querySelector('#seminar-name')?.addEventListener('input', captureReservationDraft);
    workspace.querySelectorAll('#seminar-hall-start,#seminar-hall-end').forEach(input => input.addEventListener('change', () => {
      captureReservationDraft();
      const { hall_start: start, hall_end: end } = state.reservationDraft;
      if (/^\d{4}-\d{2}-\d{2}$/.test(start) && /^\d{4}-\d{2}-\d{2}$/.test(end) && end >= start) updateHallAvailability(start, end).catch(error => flash(error.message));
    }));
    document.getElementById('seminar-details-continue')?.addEventListener('click', goToRoomSelection);
  }
  function renderRoomSelectionScreen() {
    state.wizardStep = 3;
    const summary = selectedSummary();
    const roomsMarkup = roomGroups(state.rooms);
    const draft = state.reservationDraft;
    const attendees = Number(state.validated?.count || state.current?.attendees?.length || 0);
    const pickerAction = `<div class="seminar-room-picker__suggestion"><button type="button" class="seminar-button seminar-button--quiet" id="seminar-auto-select-rooms" aria-describedby="seminar-auto-select-help">Suggest rooms by building</button><small id="seminar-auto-select-help">Keeps selected rooms; clear selection to start over by building. Assignments happen on continue.</small></div>`;
    const pickerSummary = `<div class="seminar-room-picker__summary"><div class="seminar-room-totals"><span><strong data-total-count>${summary.selected.length}</strong> selected</span><span><strong data-total-capacity>${summary.capacity}</strong> beds</span><span class="${summary.shortfall ? 'has-shortfall' : ''}"><strong data-total-shortfall>${summary.shortfall}</strong> bed shortfall</span><span class="seminar-room-totals__buildings" aria-live="polite" aria-atomic="true"><strong data-total-building-count>${summary.buildingCount}</strong> <span data-total-building-label>hotel building${summary.buildingCount === 1 ? '' : 's'}</span><small data-total-building-names>${summary.buildingNames.length ? esc(summary.buildingNames.join(', ')) : 'No hotel buildings selected'}</small></span></div></div>`;
    const roomFilter = `<div class="seminar-room-filter"><label class="seminar-field"><span>Search rooms/buildings</span><input type="search" id="seminar-room-search" placeholder="Hotel, floor or room number"></label><div class="seminar-room-filter__actions"><button type="button" class="seminar-button seminar-button--quiet" id="seminar-select-visible" disabled>Search to select matches</button><button type="button" class="seminar-button seminar-button--quiet" id="seminar-clear-rooms">Clear selection</button></div><p class="seminar-room-search-status" data-room-search-status role="status" aria-live="polite">${summary.selected.length} room${summary.selected.length === 1 ? '' : 's'} selected in ${summary.buildingCount} hotel building${summary.buildingCount === 1 ? '' : 's'}. Search to target matches; building actions select available matching rooms.</p><p class="seminar-room-suggestion-status" data-room-suggestion-status role="status" aria-live="polite" hidden></p></div>`;
    workspace.innerHTML = `${wizardProgress(3)}<div class="seminar-flow-head"><h2>Select the hotel rooms to hold</h2><p>${attendees} attendee${attendees === 1 ? '' : 's'} · ${esc(draft.hotel_check_in)} to ${esc(draft.hotel_check_out)}. Holds are created only when you continue.</p></div><section class="seminar-room-picker"><div class="seminar-room-picker__head"><div><h3>Hotel inventory</h3><p>Choose available hotel rooms for these dates.</p></div>${pickerAction}</div>${pickerSummary}${roomFilter}<div id="seminar-room-groups" class="seminar-room-groups">${roomsMarkup || '<p class="seminar-muted">No rooms found for these dates.</p>'}</div></section><div class="seminar-actions-row"><button class="seminar-button seminar-button--quiet" type="button" data-wizard-back="2">Back to seminar details</button><button class="seminar-button seminar-button--primary" type="button" id="seminar-create">${state.current ? 'Save reservation changes' : 'Create seminar and holds'}</button></div>`;
    bindReservationEvents();
  }
  async function goToRoomSelection() {
    const button = document.getElementById('seminar-details-continue');
    const restore = markButtonBusy(button, 'Checking hall and room availability…');
    captureReservationDraft();
    const draft = state.reservationDraft;
    try {
      const validDate = value => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!match) return false;
        const parsed = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
        return parsed.getUTCFullYear() === Number(match[1]) && parsed.getUTCMonth() === Number(match[2]) - 1 && parsed.getUTCDate() === Number(match[3]);
      };
      if (!draft.name.trim()) throw new Error('Enter a seminar name.');
      if (!draft.hall_venue_id) throw new Error('Choose an Event Hall.');
      if (![draft.hall_start, draft.hall_end, draft.hotel_check_in, draft.hotel_check_out].every(validDate)) throw new Error('Enter all four dates.');
      const today = new Date(); today.setHours(0, 0, 0, 0);
      if (new Date(`${draft.hall_start}T00:00:00`) < today || new Date(`${draft.hotel_check_in}T00:00:00`) < today) throw new Error('Seminar and hotel dates must not be in the past.');
      if (draft.hall_end < draft.hall_start) throw new Error('Hall end must be on or after hall start.');
      if (draft.hotel_check_out <= draft.hotel_check_in) throw new Error('Hotel checkout must be after check-in.');
      await updateHallAvailability(draft.hall_start, draft.hall_end);
      const chosenHall = state.halls.find(hall => Number(hall.id) === Number(draft.hall_venue_id));
      if (!chosenHall || Number(chosenHall.available) !== 1) throw new Error(chosenHall?.unavailable_reason || 'The selected Event Hall is unavailable for these dates.');
      await loadAvailableRooms(draft.hotel_check_in, draft.hotel_check_out, state.current?.id || null);
      renderRoomSelectionScreen();
    } catch (error) {
      flash(error.message);
    } finally { restore(); }
  }
  function refreshRoomTotals() {
    const summary = selectedSummary();
    const count = workspace.querySelector('[data-total-count]');
    const capacity = workspace.querySelector('[data-total-capacity]');
    const shortfall = workspace.querySelector('[data-total-shortfall]');
    const buildingCount = workspace.querySelector('[data-total-building-count]');
    const buildingLabel = workspace.querySelector('[data-total-building-label]');
    const buildingNames = workspace.querySelector('[data-total-building-names]');
    if (count) count.textContent = summary.selected.length;
    if (capacity) capacity.textContent = summary.capacity;
    if (shortfall) { shortfall.textContent = summary.shortfall; shortfall.parentElement.classList.toggle('has-shortfall', !!summary.shortfall); }
    if (buildingCount) buildingCount.textContent = summary.buildingCount;
    if (buildingLabel) buildingLabel.textContent = `hotel building${summary.buildingCount === 1 ? '' : 's'}`;
    if (buildingNames) buildingNames.textContent = summary.buildingNames.join(', ') || 'No hotel buildings selected';
    const create = document.getElementById('seminar-create');
    if (create) create.disabled = !summary.selected.length || summary.shortfall > 0;
    refreshRoomSearchControls();
  }
  function refreshRoomSearchControls() {
    const query = workspace.querySelector('#seminar-room-search')?.value.trim().toLowerCase() || '';
    const availableMatches = container => [...container.querySelectorAll('[data-room-option]')]
      .filter(option => !option.hidden && !option.querySelector('[data-room-check]')?.disabled);
    const groupsRoot = workspace.querySelector('#seminar-room-groups');
    const searchButton = workspace.querySelector('#seminar-select-visible');
    const matches = groupsRoot ? availableMatches(groupsRoot) : [];
    if (searchButton) {
      searchButton.disabled = !query || !matches.length;
      searchButton.textContent = !query ? 'Search to select matches' : matches.length ? `Select ${matches.length} match${matches.length === 1 ? '' : 'es'}` : 'No available matches';
    }
    workspace.querySelectorAll('.seminar-building').forEach(group => {
      const button = group.querySelector('[data-select-building]');
      if (!button) return;
      const count = availableMatches(group).length;
      const building = group.querySelector('summary > span')?.textContent || 'this building';
      button.disabled = !count;
      button.textContent = !count ? 'No available rooms' : query ? `Select ${count} match${count === 1 ? '' : 'es'}` : 'Select available';
      button.setAttribute('aria-label', query ? `Select ${count} available matching room${count === 1 ? '' : 's'} in ${building}` : `Select ${count} available room${count === 1 ? '' : 's'} in ${building}`);
    });
    const status = workspace.querySelector('[data-room-search-status]');
    if (status) {
      const selected = selectedSummary().selected.length;
      status.textContent = !query
        ? `${selected} room${selected === 1 ? '' : 's'} selected. Search to target matches; building actions select all available rooms in that building.`
        : matches.length
          ? `${matches.length} available match${matches.length === 1 ? '' : 'es'} · ${matches.filter(option => option.querySelector('[data-room-check]')?.checked).length} already selected · ${selected} room${selected === 1 ? '' : 's'} selected total.`
          : `No available rooms match this search · ${selected} room${selected === 1 ? '' : 's'} selected total.`;
    }
  }
  function bindReservationEvents() {
    bindRoomOptionEvents();
    workspace.querySelector('#seminar-room-search')?.addEventListener('input', event => {
      const query = event.target.value.trim().toLowerCase();
      workspace.querySelectorAll('[data-room-option]').forEach(option => { option.hidden = !option.dataset.search.includes(query); });
      workspace.querySelectorAll('.seminar-building').forEach(group => { group.hidden = ![...group.querySelectorAll('[data-room-option]')].some(item => !item.hidden); });
      refreshRoomTotals();
    });
    workspace.querySelector('#seminar-select-visible')?.addEventListener('click', () => {
      const query = workspace.querySelector('#seminar-room-search')?.value.trim();
      if (!query) { flash('Search for a hotel, floor, or room before selecting matching rooms.', 'info'); return; }
      clearRoomSuggestionStatus();
      workspace.querySelectorAll('[data-room-option]:not([hidden]) [data-room-check]:not(:disabled)').forEach(input => { input.checked = true; state.selectedRooms.add(Number(input.value)); });
      refreshRoomTotals();
    });
    workspace.querySelector('#seminar-auto-select-rooms')?.addEventListener('click', () => {
      const result = suggestRoomSelection(state.rooms, [...state.selectedRooms], currentRosterRoomCounts());
      state.selectedRooms = new Set(result.selectedIds);
      workspace.querySelectorAll('[data-room-check]').forEach(input => { input.checked = state.selectedRooms.has(Number(input.value)); });
      refreshRoomTotals();
      showRoomSuggestionStatus(result);
    });
    workspace.querySelectorAll('[data-select-building]').forEach(button => button.addEventListener('click', event => {
      event.preventDefault();
      const group = button.closest('.seminar-building');
      if (!group) return;
      clearRoomSuggestionStatus();
      group.querySelectorAll('[data-room-option]:not([hidden]) [data-room-check]:not(:disabled)').forEach(input => { input.checked = true; state.selectedRooms.add(Number(input.value)); });
      refreshRoomTotals();
    }));
    workspace.querySelector('#seminar-clear-rooms')?.addEventListener('click', () => { clearRoomSuggestionStatus(); state.selectedRooms.clear(); workspace.querySelectorAll('[data-room-check]').forEach(input => { input.checked = false; }); refreshRoomTotals(); });
    document.getElementById('seminar-create')?.addEventListener('click', saveReservation);
    refreshRoomTotals();
  }
  async function updateHallAvailability(start, end) {
    const version = ++state.hallRequestVersion;
    const select = document.getElementById('seminar-hall');
    const status = document.getElementById('seminar-hall-status');
    const selected = Number(select?.value || 0);
    const query = { hall_start: start, hall_end: end };
    if (state.current?.id) query.exclude_id = state.current.id;
    state.hallAvailabilityStatus = 'Checking Event Hall availability…';
    if (status) { status.textContent = state.hallAvailabilityStatus; status.dataset.state = 'loading'; }
    if (select) select.setAttribute('aria-busy', 'true');
    let result;
    try { result = await request('hall_options', query, 'GET'); }
    catch (error) {
      if (version === state.hallRequestVersion) {
        state.hallAvailabilityStatus = 'Availability could not be checked. Adjust a date or retry.';
        if (status) { status.textContent = state.hallAvailabilityStatus; status.dataset.state = 'error'; }
        if (select) select.removeAttribute('aria-busy');
      }
      throw error;
    }
    if (version !== state.hallRequestVersion) return;
    state.halls = result.halls || [];
    const availableCount = state.halls.filter(hall => Number(hall.available) === 1).length;
    state.hallAvailabilityStatus = availableCount ? `${availableCount} Event Hall${availableCount === 1 ? '' : 's'} available for these dates.` : 'No Event Hall is available for these dates.';
    if (status) { status.textContent = state.hallAvailabilityStatus; status.dataset.state = availableCount ? 'ready' : 'empty'; }
    if (select) select.removeAttribute('aria-busy');
    if (select) select.innerHTML = `<option value="">Choose an available hall</option>${state.halls.map(hall => { const available = Number(hall.available) === 1; return `<option value="${Number(hall.id)}" ${Number(hall.id) === selected ? 'selected' : ''} ${available ? '' : 'disabled'}>${esc(hall.name)} · capacity ${Number(hall.capacity)}${available ? '' : ` · ${esc(hall.unavailable_reason || 'Unavailable')}`}</option>`; }).join('')}`;
  }
  function bindRoomOptionEvents() {
    workspace.querySelectorAll('[data-room-check]').forEach(input => input.addEventListener('change', () => { clearRoomSuggestionStatus(); const id = Number(input.value); input.checked ? state.selectedRooms.add(id) : state.selectedRooms.delete(id); refreshRoomTotals(); }));
  }
  function formDates() {
    return { hall_start: document.getElementById('seminar-hall-start').value, hall_end: document.getElementById('seminar-hall-end').value, hotel_check_in: document.getElementById('seminar-check-in').value, hotel_check_out: document.getElementById('seminar-check-out').value };
  }
  async function saveReservation() {
    const button = document.getElementById('seminar-create');
    const isEditing = !!state.current;
    const restore = markButtonBusy(button, isEditing ? 'Saving room holds…' : 'Creating seminar and holds…');
    try {
      if (!state.selectedRooms.size) throw new Error('Select at least one hotel room.');
      if (selectedSummary().shortfall) throw new Error('Selected rooms do not have enough beds for the imported attendees.');
      const draft = state.reservationDraft;
      if (state.current) {
        const result = await request('update_reservation', { id: state.current.id, name: draft.name, hall_venue_id: draft.hall_venue_id, room_ids: [...state.selectedRooms], hall_start: draft.hall_start, hall_end: draft.hall_end, hotel_check_in: draft.hotel_check_in, hotel_check_out: draft.hotel_check_out });
        state.current = result.seminar; renderPlan();
      } else {
        if (!state.import?.token || !state.validated) throw new Error('Import and validate a roster before creating this seminar.');
        const result = await request('create_from_import', { token: state.import.token, sheet: state.validated.sheet, mapping: state.validated.mapping, name: draft.name, hall_venue_id: draft.hall_venue_id, room_ids: [...state.selectedRooms], hall_start: draft.hall_start, hall_end: draft.hall_end, hotel_check_in: draft.hotel_check_in, hotel_check_out: draft.hotel_check_out });
        state.current = result.seminar; state.import = null; state.validated = null; await listPlans(); renderPlan();
      }
      flash(isEditing ? 'Reservation updated.' : 'Seminar created and room holds are active.', 'success');
    } catch (error) { flash(error.message); }
    finally { restore(); }
  }
  function roomsByAttendee() {
    const map = new Map();
    state.current.rooms.forEach(room => map.set(Number(room.venue_id), []));
    state.current.attendees.forEach(person => { const roomId = Number(state.assignments[person.id] ?? person.assigned_venue_id ?? 0); if (roomId && map.has(roomId)) map.get(roomId).push(person); });
    return map;
  }
  function attendeeMarkup(person, roomId) {
    const edit = state.edits[person.id] || person;
    const query = `${person.full_name} ${person.location} ${person.gender}`.toLowerCase();
    return `<article class="seminar-attendee" data-attendee-row data-attendee-search="${esc(query)}" data-attendee-id="${Number(person.id)}"><label class="seminar-select-person"><input type="checkbox" data-attendee-select value="${Number(person.id)}" ${state.selectedAttendees.has(Number(person.id)) ? 'checked' : ''} aria-label="Select ${esc(person.full_name)}"><span></span></label><div class="seminar-attendee__info"><strong>${esc(edit.full_name)}</strong><span>${esc(edit.location)} · ${esc(edit.gender)}</span><details class="seminar-attendee-edit"><summary>Edit attendee</summary><div class="seminar-inline-fields"><input data-edit-field="full_name" value="${esc(edit.full_name)}" aria-label="Attendee name"><input data-edit-field="gender" value="${esc(edit.gender)}" aria-label="Gender"><input data-edit-field="location" value="${esc(edit.location)}" aria-label="Location"><input data-edit-field="contact" value="${esc(edit.contact)}" aria-label="Contact number"></div></details></div><button class="seminar-icon-button" type="button" data-delete-attendee="${Number(person.id)}" title="Remove attendee" aria-label="Remove ${esc(edit.full_name)}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></article>`;
  }
  function renderPlan(resetDraft = true) {
    const plan = state.current;
    if (!plan) { emptyScreen(); return; }
    state.wizardStep = 4;
    if (resetDraft) {
      state.assignments = Object.fromEntries(plan.attendees.map(person => [person.id, Number(person.assigned_venue_id || 0)]));
      state.edits = Object.fromEntries(plan.attendees.map(person => [person.id, { full_name: person.full_name, gender: person.gender, location: person.location, contact: person.contact || '' }]));
      state.mixed = new Set(plan.rooms.filter(room => Number(room.allow_mixed_gender) === 1).map(room => Number(room.venue_id)));
    }
    const isDraft = plan.status === 'draft';
    const roomMap = roomsByAttendee();
    const unassigned = plan.attendees.filter(person => !Number(state.assignments[person.id]));
    const roomCards = plan.rooms.map(room => {
      const roomId = Number(room.venue_id), people = roomMap.get(roomId) || [], capacity = Number(room.max_capacity || 0);
      const genders = new Set(people.map(person => person.gender).filter(value => value === 'female' || value === 'male'));
      const locations = [...new Set(people.map(person => person.location).filter(Boolean))];
      const unknownGenders = people.filter(person => !['female', 'male'].includes(person.gender)).length;
      const warnings = [];
      if (people.length === 1) warnings.push('Solo occupancy allowed'); if (unknownGenders) warnings.push(`${unknownGenders} unknown gender${unknownGenders === 1 ? '' : 's'}`); if (people.length > capacity) warnings.push('Over capacity'); if (genders.size > 1 && !state.mixed.has(roomId)) warnings.push('Mixed genders need approval'); if (locations.length > 1) warnings.push('Multiple locations');
      const floor = String(room.floor_label || '').trim();
      const floorText = floorDisplayLabel(floor);
      if (!floor) warnings.push('Floor not recorded');
      const roomLabel = `${room.room_number ? `Room ${room.room_number}` : room.room_type || 'Room'} · ${room.name || 'Hotel room'}`;
      const roomSearch = `${room.name || ''} ${room.room_type || ''} ${room.room_number || ''} ${floor} ${floorText || 'Floor not recorded'}`.toLowerCase();
      const printAction = people.length && plan.status === 'draft'
        ? `<div class="seminar-room-print"><button class="seminar-button seminar-button--quiet" type="button" disabled aria-describedby="seminar-print-locked-${roomId}">Print this room</button><small id="seminar-print-locked-${roomId}">Available after finalizing the plan.</small></div>`
        : plan.status === 'finalized' && people.length
          ? `<div class="seminar-room-print"><button class="seminar-button seminar-button--quiet" type="button" data-pdf-preview data-pdf-id="${Number(plan.id)}" data-pdf-type="room" data-pdf-room-id="${roomId}" data-pdf-seminar="${esc(plan.name)}" data-pdf-label="${esc(roomLabel)}">Print this room</button></div>`
          : '';
      return `<section class="seminar-room-card" data-room-card="${roomId}" data-room-search="${esc(roomSearch)}"><div class="seminar-room-card__head"><div><h3>${esc(room.room_number ? `Room ${room.room_number}` : room.room_type || 'Room')}</h3><p>${esc(room.name)}${room.room_type ? ` · ${esc(room.room_type)}` : ''}${floorText ? ` · ${esc(floorText)}` : ''}</p></div><div class="seminar-room-card__count" aria-label="${people.length} of ${capacity} beds occupied"><strong>${people.length}</strong><span>of ${capacity} beds</span></div></div>${warnings.length ? `<div class="seminar-room-warnings" role="status">${warnings.map(warning => `<span>${esc(warning)}</span>`).join('')}</div>` : ''}<div class="seminar-room-card__people">${people.map(person => attendeeMarkup(person, roomId)).join('') || '<p class="seminar-muted">Move attendees here.</p>'}</div>${isDraft ? `<label class="seminar-approval"><input type="checkbox" data-mixed="${roomId}" ${state.mixed.has(roomId) ? 'checked' : ''}> Approve mixed-gender room</label>` : ''}${printAction}</section>`;
    }).join('');
    const addAttendeeForm = `<form class="seminar-add-attendee" id="seminar-add-form"><input name="full_name" required maxlength="180" placeholder="Attendee name" aria-label="Attendee name"><input name="gender" required placeholder="Gender" aria-label="Gender"><input name="location" required maxlength="180" placeholder="Location" aria-label="Location"><input name="contact" maxlength="100" placeholder="Contact (optional)" aria-label="Contact number"><button type="submit" class="seminar-button seminar-button--quiet">Add attendee</button></form>`;
    const addAttendeeDisclosure = `<details class="seminar-add-attendee-details"><summary class="seminar-button seminar-button--quiet"><span>Add attendee</span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/></svg></summary>${addAttendeeForm}</details>`;
    const unassignedMarkup = !isDraft ? '' : unassigned.length
      ? `<section class="seminar-unassigned-panel"><div class="seminar-unassigned-panel__head"><div><h3>Unassigned</h3><span>${unassigned.length} attendee${unassigned.length === 1 ? '' : 's'}</span></div>${addAttendeeDisclosure}</div><div class="seminar-unassigned-list">${unassigned.map(person => attendeeMarkup(person, 0)).join('')}</div></section>`
      : `<section class="seminar-unassigned-empty"><div><h3>Unassigned</h3><p>Every attendee has a room assignment.</p></div>${addAttendeeDisclosure}</section>`;
    const primaryActions = isDraft
      ? `<button type="button" class="seminar-button seminar-button--primary" id="seminar-save-plan">Save changes</button><button type="button" class="seminar-button seminar-button--primary" id="seminar-finalize">Finalize plan</button>`
      : plan.status === 'finalized'
        ? `<button type="button" class="seminar-button seminar-button--primary" data-pdf-preview data-pdf-id="${Number(plan.id)}" data-pdf-type="rooms" data-pdf-seminar="${esc(plan.name)}" data-pdf-label="All room sheets">Print room sheets</button>`
        : plan.status === 'cancelled'
          ? `<button type="button" class="seminar-button seminar-button--primary" id="seminar-new">New seminar</button>`
          : `<button type="button" class="seminar-button seminar-button--primary" id="seminar-reopen">Reopen plan</button>`;
    const secondaryActions = [
      ...(plan.status !== 'cancelled' ? [`<button type="button" class="seminar-more-actions__item" id="seminar-new">New seminar</button>`] : []),
      ...(isDraft ? [
        `<button type="button" class="seminar-more-actions__item" id="seminar-regenerate">Regenerate assignments</button>`,
        `<button type="button" class="seminar-more-actions__item" id="seminar-edit-reservation">Edit reservations</button>`,
        `<button type="button" class="seminar-more-actions__item" id="seminar-replace-roster">Replace roster</button>`
      ] : plan.status === 'finalized' ? [
        `<button type="button" class="seminar-more-actions__item" data-pdf-preview data-pdf-id="${Number(plan.id)}" data-pdf-type="list" data-pdf-seminar="${esc(plan.name)}" data-pdf-label="Name-to-room list">Name-to-room list</button>`
      ] : []),
      ...(!isDraft && plan.status === 'finalized' ? [`<button type="button" class="seminar-more-actions__item" id="seminar-reopen">Reopen plan</button>`] : []),
      ...(plan.status !== 'cancelled' ? [`<button type="button" class="seminar-more-actions__item seminar-more-actions__item--danger" id="seminar-cancel">Cancel seminar</button>`] : [])
    ];
    const moreActionsMarkup = secondaryActions.length ? `<details class="seminar-more-actions"><summary class="seminar-button seminar-button--quiet"><span>More actions</span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/></svg></summary><div class="seminar-more-actions__menu" aria-label="More seminar actions">${secondaryActions.join('')}</div></details>` : '';
    const planActions = `<div class="seminar-plan-actions"><div class="seminar-primary-actions">${primaryActions}</div>${moreActionsMarkup}</div>`;
    const hall = plan.hall || {};
    workspace.innerHTML = `${wizardProgress(4)}<div class="seminar-plan-head"><div><h2>${esc(plan.name)}</h2><p>${esc(plan.hall_name)} · Hall ${date(plan.hall_start_date)} – ${date(plan.hall_end_date)} · Hotel ${date(plan.hotel_check_in)} to ${date(plan.hotel_check_out)}</p><p class="seminar-plan-meta">${esc(plan.status)} · ${plan.attendees.length} attendees</p></div>${planActions}</div>${isDraft ? `<div class="seminar-board-tools"><label class="seminar-field"><span>Find an attendee</span><input type="search" id="seminar-attendee-search" placeholder="Name, gender or location"></label><label class="seminar-field"><span>Move selected to</span><select id="seminar-bulk-room"><option value="0">Unassigned</option>${plan.rooms.map(room => `<option value="${Number(room.venue_id)}">${esc(room.name)} · ${esc(room.room_number || '')}</option>`).join('')}</select></label><button type="button" class="seminar-button seminar-button--quiet" id="seminar-bulk-move">Move selected</button><span class="seminar-muted" id="seminar-selected-count">${state.selectedAttendees.size} selected</span></div>${unassignedMarkup}` : ''}${plan.rooms.length ? `<div class="seminar-room-board-filter"><label class="seminar-field"><span>Find a room</span><input type="search" id="seminar-room-board-search" placeholder="Hotel, floor or room number"></label><p id="seminar-room-board-search-status" role="status" aria-live="polite">Showing ${plan.rooms.length} room${plan.rooms.length === 1 ? '' : 's'}.</p></div><p class="seminar-room-filter-empty" data-room-filter-empty hidden>No rooms match this search.</p>` : ''}<div class="seminar-room-board">${roomCards || '<p class="seminar-muted">No hotel rooms are reserved.</p>'}</div>`;
    bindPlanEvents();
    listPlans().catch(showPlanListError);
  }
  function bindPlanEvents() {
    workspace.querySelectorAll('[data-attendee-select]').forEach(input => input.addEventListener('change', () => { const id = Number(input.value); input.checked ? state.selectedAttendees.add(id) : state.selectedAttendees.delete(id); const count = document.getElementById('seminar-selected-count'); if (count) count.textContent = `${state.selectedAttendees.size} selected`; }));
    workspace.querySelectorAll('[data-edit-field]').forEach(input => input.addEventListener('input', () => { const row = input.closest('[data-attendee-row]'); const id = Number(row.dataset.attendeeId); state.edits[id][input.dataset.editField] = input.value; }));
    workspace.querySelectorAll('[data-mixed]').forEach(input => input.addEventListener('change', () => { const id = Number(input.dataset.mixed); input.checked ? state.mixed.add(id) : state.mixed.delete(id); }));
    workspace.querySelector('#seminar-attendee-search')?.addEventListener('input', event => { const query = event.target.value.toLowerCase().trim(); workspace.querySelectorAll('[data-attendee-row]').forEach(row => row.hidden = !row.dataset.attendeeSearch.includes(query) && !row.querySelector('.seminar-attendee__info strong').textContent.toLowerCase().includes(query)); });
    workspace.querySelector('#seminar-room-board-search')?.addEventListener('input', event => {
      const query = event.target.value.toLowerCase().trim();
      const cards = [...workspace.querySelectorAll('[data-room-card]')];
      let visible = 0;
      cards.forEach(card => { card.hidden = query !== '' && !card.dataset.roomSearch.includes(query); if (!card.hidden) visible++; });
      const status = workspace.querySelector('#seminar-room-board-search-status');
      if (status) status.textContent = query ? `${visible} matching room${visible === 1 ? '' : 's'}.` : `Showing ${cards.length} room${cards.length === 1 ? '' : 's'}.`;
      const empty = workspace.querySelector('[data-room-filter-empty]');
      if (empty) empty.hidden = visible > 0;
    });
    workspace.querySelector('#seminar-bulk-move')?.addEventListener('click', bulkMove);
    workspace.querySelector('#seminar-save-plan')?.addEventListener('click', savePlan);
    workspace.querySelector('#seminar-regenerate')?.addEventListener('click', regenerate);
    workspace.querySelector('#seminar-edit-reservation')?.addEventListener('click', editReservation);
    workspace.querySelector('#seminar-replace-roster')?.addEventListener('click', () => beginRosterReplacement(state.current.id));
    workspace.querySelector('#seminar-finalize')?.addEventListener('click', finalizePlan);
    workspace.querySelector('#seminar-reopen')?.addEventListener('click', () => mutatePlan('reopen'));
    workspace.querySelector('#seminar-cancel')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      const accepted = await requestConfirmation({ title: 'Cancel this seminar?', message: 'This releases the Event Hall and all held hotel rooms. You can reopen a cancelled plan later.', confirmLabel: 'Cancel seminar', destructive: true });
      if (accepted) await mutatePlan('cancel', button);
    });
    workspace.querySelectorAll('[data-delete-attendee]').forEach(button => button.addEventListener('click', async () => {
      const attendee = state.current.attendees.find(person => Number(person.id) === Number(button.dataset.deleteAttendee));
      const accepted = await requestConfirmation({ title: 'Remove this attendee?', message: `${attendee?.full_name || 'This attendee'} will be removed from the roster and any room assignment.`, confirmLabel: 'Remove attendee', destructive: true });
      if (!accepted) return;
      const restore = markButtonBusy(button, 'Removing…');
      try { const result = await request('attendee_delete', { id: state.current.id, attendee_id: button.dataset.deleteAttendee }); state.current = result.seminar; renderPlan(); flash('Attendee removed from the draft.', 'success'); }
      catch (error) { flash(error.message); }
      finally { restore(); }
    }));
  }
  function bulkMove() {
    if (!state.selectedAttendees.size) { flash('Select at least one attendee to move.', 'info'); return; }
    const roomId = Number(document.getElementById('seminar-bulk-room').value);
    if (roomId) {
      const room = state.current.rooms.find(item => Number(item.venue_id) === roomId);
      const count = Object.values(state.assignments).filter(value => Number(value) === roomId).length;
      const additions = [...state.selectedAttendees].filter(id => Number(state.assignments[id] || 0) !== roomId).length;
      if (count + additions > Number(room?.max_capacity || 0)) { flash('That move would exceed the room capacity.'); return; }
    }
    state.selectedAttendees.forEach(id => { state.assignments[id] = roomId; });
    state.selectedAttendees.clear(); renderPlan(false);
  }
  async function savePlan() {
    const restore = markButtonBusy(document.getElementById('seminar-save-plan'), 'Saving changes…');
    try {
      const assignments = Object.fromEntries(state.current.attendees.map(person => [person.id, Number(state.assignments[person.id] || 0)]));
      const attendee_edits = Object.entries(state.edits).map(([id, edit]) => ({ id: Number(id), ...edit }));
      const result = await request('save_assignments', { id: state.current.id, assignments, attendee_edits, allow_mixed: [...state.mixed] });
      state.current = result.seminar; state.selectedAttendees.clear(); renderPlan(); flash('Attendees and assignments saved.', 'success');
    } catch (error) { flash(error.message); }
    finally { restore(); }
  }
  async function regenerate() {
    const accepted = await requestConfirmation({ title: 'Regenerate room assignments?', message: 'This replaces manual room moves and clears mixed-gender approvals. You can review the new assignments before saving.', confirmLabel: 'Regenerate assignments' });
    if (!accepted) return;
    const restore = markButtonBusy(document.getElementById('seminar-regenerate'), 'Regenerating…');
    try { const result = await request('allocate', { id: state.current.id }); state.current = result.seminar; state.selectedAttendees.clear(); renderPlan(); flash('Room assignments regenerated. Review and save the plan when ready.', 'success'); } catch (error) { flash(error.message); }
    finally { restore(); }
  }
  async function finalizePlan() {
    const restore = markButtonBusy(document.getElementById('seminar-finalize'), 'Finalizing…');
    try {
      const assignments = Object.fromEntries(state.current.attendees.map(person => [person.id, Number(state.assignments[person.id] || 0)]));
      const attendee_edits = Object.entries(state.edits).map(([id, edit]) => ({ id: Number(id), ...edit }));
      await request('save_assignments', { id: state.current.id, assignments, attendee_edits, allow_mixed: [...state.mixed] });
      const result = await request('finalize', { id: state.current.id });
      state.current = result.seminar; state.selectedAttendees.clear(); renderPlan();
      if (result.warning) flash(result.warning_message || 'Attendee count exceeds Event Hall capacity.', 'info');
      else flash('Seminar finalized. Room sheets are ready to print.', 'success');
    } catch (error) { flash(error.message); }
    finally { restore(); }
  }
  async function mutatePlan(op, button = null) {
    const restore = markButtonBusy(button || workspace.querySelector(`#seminar-${op}`), op === 'cancel' ? 'Cancelling…' : 'Reopening…');
    try { const result = await request(op, { id: state.current.id }); state.current = result.seminar; renderPlan(); flash(op === 'cancel' ? 'Seminar cancelled and room holds released.' : 'Seminar reopened.', 'success'); }
    catch (error) { flash(error.message); }
    finally { restore(); }
  }
  async function editReservation() {
    const restore = markButtonBusy(document.getElementById('seminar-edit-reservation'), 'Loading reservation…');
    try {
      state.validated = { count: state.current.attendees.length };
      state.selectedRooms = new Set(state.current.rooms.map(room => Number(room.venue_id)));
      state.wizardMode = 'edit';
      state.reservationDraft = { name: state.current.name, hall_venue_id: state.current.hall_venue_id, hall_start: state.current.hall_start_date, hall_end: state.current.hall_end_date, hotel_check_in: state.current.hotel_check_in, hotel_check_out: state.current.hotel_check_out };
      await updateHallAvailability(state.current.hall_start_date, state.current.hall_end_date);
      renderDetailsScreen();
    } catch (error) { flash(error.message); }
    finally { restore(); }
  }
  async function startReservation() {
    if (!state.validated) return;
    if (state.wizardMode === 'new' && state.reservationDraft?.hall_start && state.reservationDraft?.hotel_check_out) {
      renderDetailsScreen();
      return;
    }
    // Seed dates with today/tomorrow; availability is refreshed when staff set their interval.
    const today = new Date(); const iso = value => new Date(value.getTime() - value.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const checkIn = iso(today); const next = new Date(today); next.setDate(next.getDate() + 1); const checkOut = iso(next);
    state.reservationDraft = { name: '', hall_venue_id: '', hall_start: checkIn, hall_end: checkIn, hotel_check_in: checkIn, hotel_check_out: checkOut };
    state.wizardMode = 'new';
    await updateHallAvailability(checkIn, checkIn);
    renderDetailsScreen();
  }
  function beginRosterReplacement(id) {
    if (!state.current || Number(id) !== Number(state.current.id) || state.current.status !== 'draft') return;
    state.import = null; state.validated = null; state.mappings = {}; state.sheetIndex = 0; state.mappingEditorOpen = false; state.changeFileOpen = false; state.rosterFeedbackOnValidation = false;
    importScreen(id);
  }
  function startNewWizard() {
    state.current = null; state.replaceTarget = null; state.import = null; state.validated = null;
    state.mappings = {}; state.sheetIndex = 0; state.mappingEditorOpen = false; state.changeFileOpen = false; state.rosterFeedbackOnValidation = false; state.rooms = []; state.halls = [];
    state.selectedRooms.clear(); state.reservationDraft = {};
    state.wizardMode = 'new';
    list.querySelectorAll('.seminar-list-item.is-active').forEach(item => item.classList.remove('is-active'));
    importScreen(null);
  }
  function beginNewSeminar() {
    if (!state.current) return;
    if (state.current.status === 'draft') {
      requestConfirmation({ title: 'Start a new seminar?', message: 'Unsaved attendee and room assignment edits in this draft will be discarded. The saved plan remains available in the list below.', confirmLabel: 'Start new seminar', destructive: true }).then(accepted => { if (accepted) startNewWizard(); });
      return;
    }
    startNewWizard();
  }
  function uploadSelectedRoster(form) {
    if (state.rosterUploadPromise) return state.rosterUploadPromise;
    const input = form?.querySelector('input[type="file"]');
    const selectedFile = input?.files?.[0];
    if (!form?.isConnected || !selectedFile) {
      flash('Choose a roster file first.');
      return Promise.resolve();
    }
    const payload = new FormData(form);
    const status = form.querySelector('[data-upload-status]');
    const continueButton = workspace.querySelector('#seminar-continue');
    const backButton = workspace.querySelector('[data-back-list]');
    if (continueButton) continueButton.disabled = true;
    if (backButton) backButton.disabled = true;
    input.disabled = true; input.setAttribute('aria-busy', 'true'); form.setAttribute('aria-busy', 'true');
    if (status) { status.hidden = false; status.dataset.state = 'loading'; status.textContent = 'Uploading roster and checking its rows…'; }
    flash('Uploading roster and checking its rows…', 'info');
    const uploadPromise = Promise.resolve().then(async () => {
      try {
        const response = await fetch(endpoint, { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: payload });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Upload failed.');
        state.import = { ...result, filename: selectedFile.name };
        state.validated = null; state.mappings = {}; state.sheetIndex = 0;
        state.mappingEditorOpen = false; state.changeFileOpen = false; state.rosterFeedbackOnValidation = true; state.validationVersion++;
        importScreen();
      } catch (error) {
        if (status?.isConnected) { status.hidden = false; status.dataset.state = 'error'; status.textContent = 'Upload failed. Retry this file or choose another roster.'; }
        flash(error.message, 'error', { actionLabel: 'Retry upload', onAction: () => uploadSelectedRoster(form) });
      } finally {
        if (state.rosterUploadPromise === uploadPromise) state.rosterUploadPromise = null;
        if (form.isConnected) {
          input.disabled = false; input.removeAttribute('aria-busy'); form.removeAttribute('aria-busy');
          if (status?.dataset.state === 'loading') status.hidden = true;
          if (continueButton?.isConnected) continueButton.disabled = !state.validated?.count || !!state.validated?.errors?.length;
          if (backButton?.isConnected) backButton.disabled = false;
        }
      }
    });
    state.rosterUploadPromise = uploadPromise;
    return uploadPromise;
  }
  async function replaceDraftRoster() {
    if (!state.validated || !state.current) return;
    const accepted = await requestConfirmation({ title: 'Replace the draft roster?', message: 'All current attendees and room assignments will be replaced by the validated roster.', confirmLabel: 'Replace roster', destructive: true });
    if (!accepted) return;
    const restore = markButtonBusy(document.getElementById('seminar-continue'), 'Replacing roster…');
    try {
      const result = await request('import_replace', { id: state.replaceTarget, token: state.import.token, sheet: state.validated.sheet, mapping: state.validated.mapping });
      state.current = result.seminar; state.import = null; state.validated = null; state.replaceTarget = null; renderPlan();
      flash('Draft roster replaced. Regenerate or assign the rooms, then save.', 'success');
    } catch (error) { flash(error.message); }
    finally { restore(); }
  }
  function bindGlobalEvents() {
    workspace.addEventListener('click', event => {
      if (event.target.closest('#seminar-new')) { event.preventDefault(); beginNewSeminar(); return; }
      if (event.target.closest('[data-back-list]')) { if (state.replaceTarget && state.current) renderPlan(); else emptyScreen(); }
      const back = event.target.closest('[data-wizard-back]');
      if (back) {
        const destination = Number(back.dataset.wizardBack);
        if (destination === 1 && state.wizardMode === 'edit') renderPlan();
        else if (destination === 1) importScreen();
        else if (destination === 2) renderDetailsScreen();
      }
    });
    workspace.addEventListener('submit', async event => {
      if (event.target.id === 'seminar-add-form') {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.target).entries());
        const restore = markButtonBusy(event.target.querySelector('button[type="submit"]'), 'Adding attendee…');
        try { const result = await request('attendee_add', { id: state.current.id, ...values }); state.current = result.seminar; renderPlan(); flash('Attendee added. Assign a room and save.', 'success'); }
        catch (error) { flash(error.message); }
        finally { restore(); }
        return;
      }
      if (event.target.id !== 'seminar-upload-form') return;
      event.preventDefault(); await uploadSelectedRoster(event.target);
    });
    workspace.addEventListener('click', async event => {
      if (!event.target.closest('#seminar-continue')) return;
      if (state.rosterUploadPromise) {
        flash('Wait for the roster upload to finish before continuing.', 'info');
        return;
      }
      if (state.replaceTarget) { await replaceDraftRoster(); return; }
      const button = document.getElementById('seminar-continue');
      const restore = markButtonBusy(button, 'Checking hall availability…');
      try { await startReservation(); }
      catch (error) { flash(error.message); }
      finally { restore(); }
    });
  }
  workspace.addEventListener('change', event => { if (event.target.matches('[data-map]')) { saveVisibleMapping(); renderMappingSummary(); validatePreview(); } });
  list.addEventListener('click', event => { const button = event.target.closest('[data-open]'); if (button) openPlan(button.dataset.open); });
  bindGlobalEvents();
  emptyScreen();
})();
