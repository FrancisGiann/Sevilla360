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
      this.form = document.getElementById('manual-payment-form');
      this.title = document.getElementById('manual-payment-title');
      this.intro = document.getElementById('manual-payment-intro');
      this.summary = this.modal?.querySelector('.manual-payment-summary');
      this.booking = document.getElementById('manual-payment-booking');
      this.amount = document.getElementById('manual-payment-amount');
      this.deadline = document.getElementById('manual-payment-deadline');
      this.bookingIdInput = document.getElementById('manual-payment-booking-id');
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

      if (!this.modal || !this.form || !this.methodSelect || !this.submitButton) return;
      this.bind();
    }

    bind() {
      document.querySelectorAll('.btn-submit-payment').forEach((button) => {
        button.addEventListener('click', () => this.openBooking(button.dataset.id, button));
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

      this.modal.addEventListener('click', (event) => {
        if (event.target !== this.modal) return;
        this.clearQr();
        if (typeof this.options.openModal !== 'function') this.closeStandalone();
      });
      if (typeof MutationObserver === 'function') {
        this.modalObserver = new MutationObserver(() => {
          if (!this.modal.classList.contains('active')) this.clearQr();
        });
        this.modalObserver.observe(this.modal, { attributes: true, attributeFilter: ['class'] });
      }

      document.addEventListener('keydown', (event) => this.handleKeydown(event), true);

      if (typeof this.options.openModal !== 'function') {
        this.modal.querySelectorAll('.close-modal').forEach((button) => {
          button.addEventListener('click', () => this.closeStandalone());
        });
      }
    }

    openBooking(rawId, invoker = document.activeElement) {
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
      this.loadDetails(idText);
    }

    resetView() {
      this.requestController?.abort();
      this.requestController = null;
      this.requestGeneration++;
      this.detailsReady = false;
      this.isSubmitting = false;
      this.setSummaryBusy(false);
      this.methods = [];
      this.submissionMode = 'submit';
      this.submitButtonLabel = 'Submit for verification';
      this.form.reset();
      if (this.title) this.title.textContent = 'Submit payment proof';
      if (this.intro) this.intro.textContent = 'Your booking is saved and reserved while awaiting payment proof. Review the amount and deadline, pay using a listed option, then submit the transfer reference and receipt image.';
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

      try {
        const response = await fetch(`actions/user/get_manual_payment_details.php?id=${encodeURIComponent(bookingId)}`, {
          headers: { Accept: 'application/json' },
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
        this.booking.textContent = data.reference_no.trim();
        this.amount.textContent = new Intl.NumberFormat('en-PH', {
          style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2
        }).format(expectedAmount);
        this.deadline.textContent = this.formatDeadline(data.payment_due_at);
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
          if (this.intro) this.intro.textContent = 'Your current proof is awaiting review. Submit a new receipt and reference to replace it; the updated proof will return to the review queue.';
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
          this.showStatus('The resort has not configured payment methods yet. Your booking is reserved; contact the resort for payment instructions or return later.', { contact: true });
          return;
        }

        this.methodSelect.disabled = false;
        this.renderInstructions();
        this.detailsReady = true;
        this.showStatus('', { error: false });
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
      if (!this.modal.classList.contains('active')) return;
      this.closeModal();
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
        this.closeStandalone();
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
