(() => {
  const focusableSelector = [
    'a[href]', 'button:not([disabled])', 'input:not([disabled])',
    'select:not([disabled])', 'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  const isVisible = (element) => Boolean(element && element.getClientRects().length
    && window.getComputedStyle(element).visibility !== 'hidden'
    && window.getComputedStyle(element).display !== 'none');

  class ManualPaymentDialog {
    constructor(options = {}) {
      this.options = options;
      this.modal = document.getElementById('modal-manual-payment');
      this.closeButtons = Array.from(this.modal?.querySelectorAll('.close-modal') || []);
      this.form = document.getElementById('manual-payment-form');
      this.title = document.getElementById('manual-payment-title');
      this.intro = document.getElementById('manual-payment-intro');
      this.summary = this.modal?.querySelector('.manual-payment-summary');
      this.booking = document.getElementById('manual-payment-booking');
      this.amount = document.getElementById('manual-payment-amount');
      this.deadline = document.getElementById('manual-payment-deadline');
      this.bookingIdInput = document.getElementById('manual-payment-booking-id');
      this.holdTokenInput = document.getElementById('manual-payment-hold-token');
      this.methodSelect = document.getElementById('manual-payment-method');
      this.referenceInput = document.getElementById('manual-payment-reference');
      this.account = document.getElementById('manual-payment-account');
      this.qr = document.getElementById('manual-payment-qr');
      this.qrPanel = document.getElementById('manual-payment-qr-panel');
      this.qrView = document.getElementById('manual-payment-qr-view');
      this.qrSave = document.getElementById('manual-payment-qr-save');
      this.qrGuidance = document.getElementById('manual-payment-qr-guidance');
      this.qrLightbox = document.getElementById('manual-payment-qr-lightbox');
      this.qrLightboxStage = document.getElementById('manual-payment-qr-stage');
      this.qrLightboxClose = document.getElementById('manual-payment-qr-close');
      this.qrActions = this.qrView?.parentElement || null;
      this.status = document.getElementById('manual-payment-status');
      this.statusMessage = document.getElementById('manual-payment-status-message');
      this.contact = document.getElementById('manual-payment-contact');
      this.retry = document.getElementById('manual-payment-retry');
      this.submitButton = document.getElementById('manual-payment-submit');
      this.methods = [];
      this.currentBookingId = null;
      this.requestGeneration = 0;
      this.requestController = null;
      this.isSubmitting = false;
      this.detailsReady = false;
      this.activeInvoker = null;
      this.previousBodyOverflow = '';
      this.qrZoomInvoker = null;
      this.submissionMode = 'submit';
      this.submitButtonLabel = 'Submit for verification';
      this.pendingHoldToken = null;
      this.holdTimer = null;
      this.holdExpiryMs = 0;

      if (!this.modal || !this.form || !this.methodSelect || !this.submitButton) return;
      this.bind();
    }

    bind() {
      document.addEventListener('click', (event) => {
        const button = event.target.closest('.btn-submit-payment');
        if (button) this.openBooking(button.dataset.id, button);
      });
      this.retry?.addEventListener('click', () => {
        if (this.currentBookingId) this.loadDetails(this.currentBookingId);
      });
      this.methodSelect.addEventListener('change', () => this.renderInstructions());
      this.qrView?.addEventListener('click', () => this.openQrZoom());
      this.qrLightboxClose?.addEventListener('click', () => this.closeQrZoom());
      this.qrLightbox?.addEventListener('click', (event) => {
        const clickedOutsideQr = event.target === this.qrLightbox
          || (this.qrLightboxStage?.contains(event.target) && !this.qr.contains(event.target));
        if (clickedOutsideQr) this.closeQrZoom();
      });
      this.form.addEventListener('submit', (event) => this.submitProof(event));
      window.addEventListener('pagehide', () => { if (!this.isSubmitting) this.releaseHold(true); });
      document.addEventListener('visibilitychange', () => { if (!document.hidden) this.expireHoldLocally(); });
      window.addEventListener('focus', () => this.expireHoldLocally());
      window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        const wasOpen = this.modal.classList.contains('active');
        this.releaseHold();
        this.resetView();
        if (wasOpen && this.currentBookingId) this.loadDetails(this.currentBookingId);
      });

      this.modal.addEventListener('click', (event) => {
        if (event.target !== this.modal) return;
        if (this.isSubmitting) return;
        this.clearQr();
        if (typeof this.options.openModal !== 'function') this.closeStandalone();
      });
      if (typeof MutationObserver === 'function') {
        this.modalObserver = new MutationObserver(() => {
          if (!this.modal.classList.contains('active')) {
            this.clearQr();
            if (!this.isSubmitting) this.releaseHold();
          }
        });
        this.modalObserver.observe(this.modal, { attributes: true, attributeFilter: ['class'] });
      }

      document.addEventListener('keydown', (event) => this.handleKeydown(event), true);

      if (typeof this.options.openModal !== 'function') {
        this.closeButtons.forEach((button) => {
          button.addEventListener('click', () => this.closeStandalone());
        });
      }
    }

    openBooking(rawId, invoker = document.activeElement) {
      if (this.isSubmitting) return;
      const previousRelease = this.releaseHold();
      this.activeInvoker = invoker instanceof HTMLElement ? invoker : document.activeElement;
      const idText = String(rawId ?? '');
      if (!/^[1-9]\d*$/.test(idText) || !Number.isSafeInteger(Number(idText))) {
        this.currentBookingId = null;
        this.resetView();
        this.booking.textContent = 'Unavailable';
        this.amount.textContent = 'Unavailable';
        this.deadline.textContent = 'Unavailable';
        this.showStatus('The booking identifier is unavailable. Open My Bookings and choose Submit payment to continue.', { error: true, contact: true });
        this.openModal(invoker);
        return;
      }

      this.currentBookingId = idText;
      this.resetView();
      this.booking.textContent = 'Loading…';
      this.amount.textContent = 'Loading…';
      this.deadline.textContent = 'Loading…';
      this.setSummaryBusy(true);
      this.showStatus('Loading secure payment details…', { error: false });
      this.openModal(this.activeInvoker);
      const openGeneration = this.requestGeneration;
      Promise.resolve(previousRelease).finally(() => {
        if (openGeneration === this.requestGeneration) this.loadDetails(idText);
      });
    }

    resetView() {
      this.requestController?.abort();
      this.requestController = null;
      this.requestGeneration++;
      this.detailsReady = false;
      if (this.holdTimer) clearTimeout(this.holdTimer);
      this.isSubmitting = false;
      this.closeButtons.forEach((button) => { button.disabled = false; });
      this.setSummaryBusy(false);
      this.methods = [];
      this.submissionMode = 'submit';
      this.submitButtonLabel = 'Submit for verification';
      this.form.reset();
      if (this.title) this.title.textContent = 'Submit payment proof';
      if (this.intro) this.intro.textContent = 'Opening a short payment hold before showing transfer instructions…';
      if (this.holdTokenInput) this.holdTokenInput.value = '';
      this.bookingIdInput.value = this.currentBookingId || '';
      this.methodSelect.replaceChildren();
      this.methodSelect.disabled = true;
      this.account.replaceChildren();
      this.clearQr();
      this.submitButton.disabled = true;
      this.submitButton.textContent = 'Submit for verification';
      this.showStatus('', { error: false });
    }

    setSummaryBusy(isBusy) {
      this.summary?.setAttribute('aria-busy', isBusy ? 'true' : 'false');
    }

    showStatus(message, options = {}) {
      if (!this.status || !this.statusMessage) return;
      this.statusMessage.textContent = message;
      this.status.hidden = !message;
      this.status.dataset.error = options.error === false ? 'false' : 'true';
      if (this.contact) this.contact.hidden = options.contact !== true;
      if (this.retry) this.retry.hidden = options.retry !== true;
    }

    async loadDetails(bookingId) {
      await this.releaseHold();
      this.requestController?.abort();
      const controller = new AbortController();
      this.requestController = controller;
      const requestId = ++this.requestGeneration;
      this.currentBookingId = bookingId;
      this.detailsReady = false;
      this.methodSelect.disabled = true;
      this.submitButton.disabled = true;
      this.booking.textContent = 'Loading…';
      this.amount.textContent = 'Loading…';
      this.deadline.textContent = 'Loading…';
      this.setSummaryBusy(true);
      this.showStatus('Loading secure payment details…', { error: false });
      const tokenBytes = new Uint8Array(16);
      window.crypto.getRandomValues(tokenBytes);
      const holdToken = Array.from(tokenBytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
      this.pendingHoldToken = holdToken;

      try {
        const body = new FormData();
        body.set('id', bookingId);
        body.set('hold_token', holdToken);
        const response = await fetch('actions/user/get_manual_payment_details.php', {
          method: 'POST', body,
          headers: { Accept: 'application/json', 'X-CSRF-Token': this.options.csrfToken || '' },
          signal: controller.signal
        });
        let result;
        try {
          result = await response.json();
        } catch {
          throw new Error('The payment service returned an unreadable response.');
        }
        if (!response.ok || !result?.success || !result?.data || typeof result.data !== 'object') {
          throw new Error(typeof result?.message === 'string' && result.message.trim()
            ? result.message
            : 'Payment details are unavailable right now.');
        }

        const data = result.data;
        const dataId = Number(data.booking_id);
        const expectedAmount = Number(data.expected_amount);
        if (!Number.isSafeInteger(dataId) || dataId < 1 || String(dataId) !== String(bookingId)
          || typeof data.reference_no !== 'string' || !data.reference_no.trim()
          || !Number.isFinite(expectedAmount) || expectedAmount <= 0
          || !Array.isArray(data.methods)
          || (data.payment_due_at !== null && typeof data.payment_due_at !== 'string')) {
          throw new Error('The payment service returned incomplete booking details.');
        }
        if (requestId !== this.requestGeneration) return;

        if (data.methods.some((method) => !method || typeof method.key !== 'string'
          || !method.key.trim() || typeof method.label !== 'string' || !method.label.trim())) {
          throw new Error('The payment service returned incomplete payment methods.');
        }
        this.methods = data.methods;
        if (this.holdTokenInput) this.holdTokenInput.value = data.hold_token || '';
        if (data.hold_token && data.hold_expires_at) {
          this.deadline.textContent = `Payment hold ends ${this.formatDeadline(data.hold_expires_at)}`;
          const expiresAt = new Date(`${data.hold_expires_at.replace(' ', 'T')}+08:00`).getTime();
          this.holdExpiryMs = expiresAt;
          this.holdTimer = setTimeout(() => this.expireHoldLocally(), Math.max(0, expiresAt - Date.now()));
          if (this.intro) this.intro.textContent = 'This room is held for 15 minutes while you transfer and submit proof. Leaving this step releases the hold.';
        } else if (data.reconciliation_only) {
          if (this.intro) this.intro.textContent = 'This date is not held. Do not make a new transfer. If you already paid, submit the receipt for staff resolution; this is not a reservation.';
        }
        this.booking.textContent = data.reference_no.trim();
        this.amount.textContent = new Intl.NumberFormat('en-PH', {
          style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2
        }).format(expectedAmount);
        if (!data.hold_expires_at) this.deadline.textContent = data.reconciliation_only ? 'Staff resolution only; date not held' : this.formatDeadline(data.payment_due_at);
        this.bookingIdInput.value = String(dataId);
        this.setSummaryBusy(false);

        this.methods.forEach((method) => {
          const option = document.createElement('option');
          option.value = method.key;
          option.textContent = method.label;
          this.methodSelect.appendChild(option);
        });

        const latestSubmission = data.latest_submission && typeof data.latest_submission === 'object'
          ? data.latest_submission
          : null;
        this.submissionMode = ['replace_pending', 'resubmit_rejected'].includes(data.submission_mode)
          ? data.submission_mode
          : 'submit';
        if (this.submissionMode === 'replace_pending') {
          if (this.title) this.title.textContent = 'Replace payment proof';
          if (this.intro) this.intro.textContent = data.booking_status === 'Awaiting Proof'
            ? 'Your proof is awaiting staff review, but another reservation holds the date. Staff must resolve your transferred payment or offer another date. Replacing the receipt will not claim this date.'
            : data.review_overdue === true
              ? 'Your proof has passed the staff review target, but the date remains held while staff reviews it. Contact the resort for an update; replace the receipt only if its details need correction.'
              : 'Your current proof is awaiting review and the date remains held, even if the original deadline passes. Replace the receipt only if its details need correction.';
          this.deadline.textContent = data.booking_status === 'Awaiting Proof'
            ? 'Staff resolution needed; date not held'
            : 'Awaiting staff review; date held';
          this.submitButtonLabel = 'Replace proof';
        } else if (this.submissionMode === 'resubmit_rejected') {
          if (this.title) this.title.textContent = 'Submit corrected payment proof';
          if (this.intro) this.intro.textContent = 'Your previous proof was rejected. Correct the details if needed and select a new receipt image to submit it again.';
          this.submitButtonLabel = 'Submit corrected proof';
        }
        this.submitButton.textContent = this.submitButtonLabel;
        if (latestSubmission && ['replace_pending', 'resubmit_rejected'].includes(this.submissionMode)) {
          const existingMethod = String(latestSubmission.payment_method || '');
          if (this.methods.some((method) => method.label === existingMethod)) {
            this.methodSelect.value = this.methods.find((method) => method.label === existingMethod).key;
          }
          if (this.referenceInput && typeof latestSubmission.transaction_reference === 'string') {
            this.referenceInput.value = latestSubmission.transaction_reference;
          }
        }

        if (!this.methods.length) {
          this.showStatus('The resort has not configured payment methods yet. No payment hold was started; contact the resort for instructions.', { contact: true });
          return;
        }

        this.methodSelect.disabled = false;
        this.renderInstructions();
        this.detailsReady = true;
        if (data.reconciliation_only) this.showStatus('Do not make a new transfer. Submit proof only for a transfer already sent; staff will resolve it. Retry to check whether the room has become available.', { contact: true, retry: true });
        else this.showStatus('', { error: false });
        this.submitButton.disabled = false;
      } catch (error) {
        if (error?.name === 'AbortError' || requestId !== this.requestGeneration) return;
        this.booking.textContent = 'Unavailable';
        this.amount.textContent = 'Unavailable';
        this.deadline.textContent = 'Unavailable';
        this.setSummaryBusy(false);
        this.showStatus(`${error?.message || 'Payment details could not be loaded.'} You can retry or contact the resort.`, { retry: true, contact: true });
      }
    }

    formatDeadline(value) {
      if (!value) return 'No deadline is available; contact the resort.';
      const timestamp = new Date(`${value.replace(' ', 'T')}+08:00`);
      if (Number.isNaN(timestamp.getTime())) throw new Error('The payment service returned an invalid deadline.');
      return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(timestamp);
    }

    renderInstructions() {
      this.account.replaceChildren();
      this.clearQr();
      const method = this.methods.find((item) => item.key === this.methodSelect.value);
      if (!method) return;
      const addLine = (label, value) => {
        if (typeof value !== 'string' || !value.trim()) return;
        const line = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = `${label}: `;
        line.append(strong, document.createTextNode(value));
        this.account.appendChild(line);
      };
      addLine('Account name', method.account_name);
      addLine('Account number', method.account_number);
      addLine('Instructions', method.details);
      const qrPath = typeof method.qr_path === 'string' ? method.qr_path.trim() : '';
      const qrMatch = qrPath.match(/^assets\/uploads\/payment-qrs\/[a-f0-9]{48}\.(jpg|png|webp)$/i);
      const methodFiles = {
        gcash: { name: 'gcash-payment-qr', label: 'GCash' },
        maya: { name: 'maya-payment-qr', label: 'Maya' },
        bank_transfer: { name: 'bank-transfer-payment-qr', label: 'Bank Transfer' }
      };
      const safeMethod = Object.hasOwn(methodFiles, method.key) ? methodFiles[method.key] : null;
      if (!qrMatch) return;
      const methodSlug = method.label.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'payment-method';
      const methodName = safeMethod?.name || `${methodSlug}-payment-qr`;
      const methodLabel = safeMethod?.label || method.label;

      this.qr.src = qrPath;
      this.qr.alt = `${methodLabel} payment QR code`;
      this.qr.hidden = false;
      this.qrView.setAttribute('aria-label', `Enlarge ${methodLabel} payment QR`);
      this.qrView.setAttribute('aria-expanded', 'false');
      this.qrView.hidden = false;
      this.qrSave.href = qrPath;
      this.qrSave.download = `${methodName}.${qrMatch[1].toLowerCase()}`;
      this.qrSave.setAttribute('aria-label', `Save ${methodLabel} payment QR image`);
      this.qrSave.hidden = false;
      this.qrGuidance.textContent = method.key === 'gcash'
        ? 'On this phone, save the QR and upload it in GCash’s QR scanner. Enlarge it here to scan from another device.'
        : 'On this phone, save the QR and upload it in your payment app’s QR scanner. Enlarge it here to scan from another device.';
      this.qrPanel.hidden = false;
    }

    openQrZoom() {
      if (!this.qrLightbox || !this.qrLightboxStage || !this.qr || this.qr.hidden || !this.qr.getAttribute('src')) return;
      this.qrZoomInvoker = this.qrView;
      this.qrLightbox.hidden = false;
      this.qrLightbox.setAttribute('aria-hidden', 'false');
      this.qrView?.setAttribute('aria-expanded', 'true');
      this.qr.classList.add('is-zoomed');
      this.qrLightboxStage.appendChild(this.qr);
      window.requestAnimationFrame(() => this.qrLightboxClose?.focus({ preventScroll: true }));
    }

    closeQrZoom(options = {}) {
      if (!this.qrLightbox || this.qrLightbox.hidden) return;
      this.qr.classList.remove('is-zoomed');
      if (this.qrPanel && this.qr.parentElement !== this.qrPanel) {
        this.qrPanel.insertBefore(this.qr, this.qrActions);
      }
      this.qrLightbox.hidden = true;
      this.qrLightbox.setAttribute('aria-hidden', 'true');
      this.qrView?.setAttribute('aria-expanded', 'false');
      const invoker = this.qrZoomInvoker;
      this.qrZoomInvoker = null;
      if (options.restoreFocus !== false && invoker?.isConnected && !invoker.hidden) {
        window.requestAnimationFrame(() => invoker.focus({ preventScroll: true }));
      }
    }

    clearQr() {
      this.closeQrZoom({ restoreFocus: false });
      this.qrPanel.hidden = true;
      this.qr.hidden = true;
      this.qr.removeAttribute('src');
      this.qr.alt = '';
      this.qrView.hidden = true;
      this.qrView.removeAttribute('aria-label');
      this.qrView.setAttribute('aria-expanded', 'false');
      this.qrSave.hidden = true;
      this.qrSave.removeAttribute('href');
      this.qrSave.removeAttribute('download');
      this.qrSave.removeAttribute('aria-label');
      this.qrGuidance.textContent = '';
    }

    async submitProof(event) {
      event.preventDefault();
      if (this.isSubmitting || !this.detailsReady || !this.methods.length) return;
      if (!this.form.reportValidity()) return;

      this.isSubmitting = true;
      this.closeButtons.forEach((button) => { button.disabled = true; });
      this.submitButton.disabled = true;
      this.submitButton.textContent = 'Submitting…';
      this.form.setAttribute('aria-busy', 'true');
      this.showStatus('Uploading your receipt securely…', { error: false });
      try {
        const response = await fetch('actions/user/submit_manual_payment.php', {
          method: 'POST',
          headers: { 'X-CSRF-Token': this.options.csrfToken || '', Accept: 'application/json' },
          body: new FormData(this.form)
        });
        let result;
        try {
          result = await response.json();
        } catch {
          throw new Error('The payment service returned an unreadable response. Please try again.');
        }
        if (!response.ok || !result?.success) {
          throw new Error(typeof result?.message === 'string' && result.message.trim()
            ? result.message
            : 'Payment proof could not be submitted. Please try again.');
        }

        this.closeModal();
        if (typeof this.options.onSubmitted === 'function') {
          this.options.onSubmitted(result);
        } else if (typeof window.showAlert === 'function') {
          window.showAlert('Proof submitted', result.message || 'Your payment is awaiting verification.', 'success', true);
        }
      } catch (error) {
        this.showStatus(error?.message || 'Payment proof could not be submitted. Please try again.', { retry: false, contact: true });
      } finally {
        this.isSubmitting = false;
        this.closeButtons.forEach((button) => { button.disabled = false; });
        this.form.removeAttribute('aria-busy');
        this.submitButton.textContent = this.submitButtonLabel;
        this.submitButton.disabled = !this.detailsReady;
      }
    }

    openModal(invoker) {
      if (typeof this.options.openModal === 'function') {
        this.options.openModal('manual-payment', invoker);
        return;
      }
      this.previousBodyOverflow = document.body.style.overflow;
      this.modal.classList.add('active');
      document.body.style.overflow = 'hidden';
      const closeButton = this.modal.querySelector('.manual-payment-close');
      window.requestAnimationFrame(() => closeButton?.focus({ preventScroll: true }));
    }

    closeModal() {
      this.clearQr();
      this.releaseHold();
      if (typeof this.options.closeModal === 'function') {
        this.options.closeModal();
        return;
      }
      this.modal.classList.remove('active');
      document.body.style.overflow = this.previousBodyOverflow;
      const target = this.activeInvoker && !this.activeInvoker.disabled && document.contains(this.activeInvoker)
        ? this.activeInvoker
        : document.querySelector('main h1, h1, main');
      if (target instanceof HTMLElement) {
        if (!/^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test(target.tagName) && !target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
        target.focus({ preventScroll: true });
      }
    }

    closeStandalone() {
      if (this.isSubmitting || !this.modal.classList.contains('active')) return;
      this.closeModal();
    }

    expireHoldLocally() {
      if (!this.holdExpiryMs || Date.now() < this.holdExpiryMs) return;
      this.holdExpiryMs = 0;
      this.methods = this.methods.map(({ key, label }) => ({ key, label }));
      this.renderInstructions();
      if (this.holdTokenInput) this.holdTokenInput.value = '';
      this.deadline.textContent = 'Payment hold expired';
      this.showStatus('The 15-minute hold ended. Do not make a new transfer. If you already paid, submit the receipt for staff resolution; reopening this step checks availability again.', { contact: true, retry: true });
    }

    releaseHold(beacon = false) {
      const token = this.pendingHoldToken;
      const bookingId = this.currentBookingId;
      this.pendingHoldToken = null;
      if (this.holdTimer) clearTimeout(this.holdTimer);
      this.holdTimer = null;
      this.holdExpiryMs = 0;
      if (this.holdTokenInput) this.holdTokenInput.value = '';
      this.methods = this.methods.map(({ key, label }) => ({ key, label }));
      this.renderInstructions();
      if (!token || !bookingId) return Promise.resolve();
      const body = new FormData();
      body.set('booking_id', bookingId);
      body.set('hold_token', token);
      body.set('csrf_token', this.options.csrfToken || '');
      if (beacon && navigator.sendBeacon) {
        navigator.sendBeacon('actions/user/release_manual_payment_hold.php', body);
        return Promise.resolve();
      } else {
        return fetch('actions/user/release_manual_payment_hold.php', { method: 'POST', body, keepalive: true }).catch(() => {});
      }
    }

    handleKeydown(event) {
      if (!this.modal.classList.contains('active')) return;
      if (this.qrLightbox && !this.qrLightbox.hidden) {
        if (event.key === 'Escape') {
          event.preventDefault();
          event.stopPropagation();
          event.stopImmediatePropagation();
          this.closeQrZoom();
          return;
        }
        if (event.key === 'Tab') {
          event.preventDefault();
          event.stopPropagation();
          event.stopImmediatePropagation();
          this.qrLightboxClose?.focus({ preventScroll: true });
        }
        return;
      }
      if (typeof this.options.openModal === 'function') return;
      if (event.key === 'Escape') {
        event.preventDefault();
        if (!this.isSubmitting) this.closeStandalone();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusables = Array.from(this.modal.querySelectorAll(focusableSelector)).filter(isVisible);
      if (!focusables.length) {
        event.preventDefault();
        this.modal.querySelector('.manual-payment-close')?.focus();
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (!this.modal.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }

  window.ManualPayment = {
    create(options = {}) {
      return new ManualPaymentDialog(options);
    }
  };
})();
