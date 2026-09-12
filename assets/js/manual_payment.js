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
      this.summary = this.modal?.querySelector('.manual-payment-summary');
      this.booking = document.getElementById('manual-payment-booking');
      this.amount = document.getElementById('manual-payment-amount');
      this.deadline = document.getElementById('manual-payment-deadline');
      this.bookingIdInput = document.getElementById('manual-payment-booking-id');
      this.methodSelect = document.getElementById('manual-payment-method');
      this.account = document.getElementById('manual-payment-account');
      this.qr = document.getElementById('manual-payment-qr');
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
      this.form.addEventListener('submit', (event) => this.submitProof(event));

      if (typeof this.options.openModal !== 'function') {
        this.modal.querySelectorAll('.close-modal').forEach((button) => {
          button.addEventListener('click', () => this.closeStandalone());
        });
        this.modal.addEventListener('click', (event) => {
          if (event.target === this.modal) this.closeStandalone();
        });
        document.addEventListener('keydown', (event) => this.handleKeydown(event));
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
      this.form.reset();
      this.bookingIdInput.value = this.currentBookingId || '';
      this.methodSelect.replaceChildren();
      this.methodSelect.disabled = true;
      this.account.replaceChildren();
      this.qr.hidden = true;
      this.qr.removeAttribute('src');
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
      this.qr.hidden = true;
      this.qr.removeAttribute('src');
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
      if (typeof method.qr_path === 'string' && method.qr_path.trim()) {
        this.qr.src = method.qr_path;
        this.qr.alt = `${method.label} payment QR code`;
        this.qr.hidden = false;
      }
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
        this.submitButton.textContent = 'Submit for verification';
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
