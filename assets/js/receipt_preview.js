document.addEventListener('DOMContentLoaded', () => {
  const dialog = document.getElementById('receipt-preview-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return;

  const context = document.getElementById('receipt-preview-context');
  const stage = dialog.querySelector('[data-receipt-preview-stage]');
  const loading = dialog.querySelector('[data-receipt-preview-loading]');
  const errorPanel = dialog.querySelector('[data-receipt-preview-error]');
  const errorMessage = dialog.querySelector('[data-receipt-preview-error-message]');
  const fallback = dialog.querySelector('[data-receipt-preview-fallback]');
  const frame = dialog.querySelector('[data-receipt-preview-frame]');
  const download = dialog.querySelector('[data-receipt-preview-download]');
  const retry = dialog.querySelector('[data-receipt-preview-retry]');
  const closeButtons = dialog.querySelectorAll('[data-receipt-preview-close]');
  const viewerTimeoutMs = 10000;
  let activePreview = null;

  function isActive(preview, requestId = preview?.requestId) {
    return activePreview === preview && dialog.open && preview.requestId === requestId;
  }

  function clearFrame() {
    frame.onload = null;
    frame.onerror = null;
    frame.removeAttribute('src');
    frame.hidden = true;
  }

  function revokePreviewUrl(preview) {
    if (!preview?.objectUrl) return;
    URL.revokeObjectURL(preview.objectUrl);
    preview.objectUrl = null;
  }

  function resetView() {
    loading.hidden = false;
    errorPanel.hidden = true;
    fallback.hidden = true;
    stage.setAttribute('aria-busy', 'true');
    download.hidden = true;
    download.removeAttribute('href');
    download.removeAttribute('download');
    clearFrame();
  }

  function errorForStatus(status) {
    if (status === 401) return 'Your session may have expired. Sign in again, then reopen this receipt.';
    if (status === 403) return 'Your account does not have access to this receipt.';
    if (status === 404) return 'This receipt is unavailable for the selected booking.';
    return `The receipt could not be loaded (HTTP ${status}). Try again.`;
  }

  function showError(preview, requestId, message) {
    if (!isActive(preview, requestId)) return;
    loading.hidden = true;
    fallback.hidden = true;
    errorPanel.hidden = false;
    errorMessage.textContent = message;
    stage.setAttribute('aria-busy', 'false');
  }

  function showViewerFallback(preview, requestId) {
    if (!isActive(preview, requestId)) return;
    window.clearTimeout(preview.viewerTimer);
    preview.viewerTimer = null;
    loading.hidden = true;
    errorPanel.hidden = true;
    fallback.hidden = false;
    stage.setAttribute('aria-busy', 'false');
    frame.hidden = true;
  }

  async function loadReceipt(preview) {
    if (activePreview !== preview || !dialog.open) return;
    preview.controller?.abort();
    window.clearTimeout(preview.viewerTimer);
    revokePreviewUrl(preview);
    const requestId = ++preview.requestId;
    const controller = new AbortController();
    preview.controller = controller;
    resetView();

    try {
      const requestUrl = new URL('print_receipt.php', document.baseURI);
      if (requestUrl.origin !== window.location.origin) throw new Error('The receipt request must stay on this site.');
      requestUrl.searchParams.set('booking_id', String(preview.bookingId));
      const response = await fetch(requestUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf' },
        signal: controller.signal
      });
      if (!isActive(preview, requestId)) return;
      if (new URL(response.url, window.location.href).origin !== window.location.origin) {
        throw new Error('The receipt request left this site. Refresh the page and try again.');
      }
      if (!response.ok) throw new Error(errorForStatus(response.status));
      const contentType = (response.headers.get('Content-Type') || '').split(';', 1)[0].trim().toLowerCase();
      if (contentType !== 'application/pdf') {
        throw new Error('The server returned a sign-in or error page instead of a PDF. Refresh the page and try again.');
      }

      const blob = await response.blob();
      if (!isActive(preview, requestId)) return;
      if (!blob.size || (await blob.slice(0, 5).text()) !== '%PDF-') {
        throw new Error('The receipt file is empty or invalid. Try again or contact an administrator.');
      }
      if (!isActive(preview, requestId)) return;

      const objectUrl = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));
      preview.objectUrl = objectUrl;
      download.href = objectUrl;
      download.download = `receipt-${preview.bookingId}.pdf`;
      download.hidden = false;
      frame.title = `${preview.label} PDF preview`;
      frame.onload = () => {
        if (!isActive(preview, requestId)) return;
        let frameLocation = '';
        try { frameLocation = frame.contentWindow?.location?.href || ''; }
        catch (error) { /* Browser PDF viewers may isolate their internal document. */ }
        if (frameLocation === 'about:blank') return;
        window.clearTimeout(preview.viewerTimer);
        preview.viewerTimer = null;
        loading.hidden = true;
        fallback.hidden = true;
        stage.setAttribute('aria-busy', 'false');
      };
      frame.onerror = () => showViewerFallback(preview, requestId);
      frame.hidden = false;
      preview.viewerTimer = window.setTimeout(() => showViewerFallback(preview, requestId), viewerTimeoutMs);
      frame.src = objectUrl;
    } catch (error) {
      if (error?.name === 'AbortError' || !isActive(preview, requestId)) return;
      const message = error instanceof TypeError
        ? 'The receipt could not be loaded. Check your connection and try again.'
        : error.message || 'The receipt could not be loaded. Check your connection and try again.';
      showError(preview, requestId, message);
    }
  }

  function normalizeBookingId(value) {
    const bookingId = typeof value === 'number' ? value : Number(String(value ?? '').trim());
    return Number.isSafeInteger(bookingId) && bookingId > 0 && bookingId <= 2147483647 ? bookingId : null;
  }

  function openReceipt(value, invoker, label = 'Booking receipt') {
    const bookingId = normalizeBookingId(value);
    if (!bookingId) return false;

    if (activePreview) {
      activePreview.controller?.abort();
      window.clearTimeout(activePreview.viewerTimer);
      revokePreviewUrl(activePreview);
    }
    const wasOpen = dialog.open;
    const preview = {
      bookingId,
      label: String(label || 'Booking receipt').trim().slice(0, 180) || 'Booking receipt',
      invoker: invoker instanceof HTMLElement ? invoker : document.activeElement,
      controller: null,
      objectUrl: null,
      viewerTimer: null,
      requestId: 0
    };
    activePreview = preview;
    context.textContent = preview.label;
    resetView();
    if (!wasOpen) dialog.showModal();
    if (!wasOpen) dialog.querySelector('[data-receipt-preview-close]')?.focus({ preventScroll: true });
    loadReceipt(preview);
    return true;
  }

  function closePreview() {
    if (dialog.open) dialog.close();
  }

  closeButtons.forEach(button => button.addEventListener('click', closePreview));
  retry?.addEventListener('click', () => {
    if (activePreview) loadReceipt(activePreview);
  });
  dialog.addEventListener('click', event => {
    if (event.target === dialog) closePreview();
  });
  dialog.addEventListener('cancel', event => event.stopPropagation());
  dialog.addEventListener('keydown', event => event.stopPropagation());
  document.addEventListener('keydown', event => {
    if (dialog.open) event.stopImmediatePropagation();
  }, true);
  dialog.addEventListener('close', () => {
    const preview = activePreview;
    activePreview = null;
    if (!preview) return;
    preview.controller?.abort();
    window.clearTimeout(preview.viewerTimer);
    revokePreviewUrl(preview);
    clearFrame();
    download.hidden = true;
    download.removeAttribute('href');
    download.removeAttribute('download');
    const invoker = preview.invoker;
    if (invoker instanceof HTMLElement && invoker.isConnected && !invoker.disabled) {
      window.requestAnimationFrame(() => {
        if (!invoker.isConnected || invoker.disabled || invoker.getClientRects().length === 0) return;
        const style = window.getComputedStyle(invoker);
        if (style.visibility === 'hidden' || style.display === 'none' || invoker.closest('[inert]')) return;
        invoker.focus({ preventScroll: true });
      });
    }
  });

  window.SevillaReceiptPreview = Object.freeze({ open: openReceipt });
});
