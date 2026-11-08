(() => {
  'use strict';
  const enabled = () => document.querySelector('meta[name="sevilla-paymongo-local"]')?.content === 'enabled';
  const attemptPattern = /^[a-f0-9]{32}$/;
  const paidOutcomes = new Set(['applied', 'restored_checkout', 'late_payment', 'booking_changed', 'booking_not_payable', 'amount_mismatch', 'inventory_conflict', 'hold_expired_or_released', 'extra_payment']);
  const currentBookingPolicy = 'terms-v4-paymongo-actual-fee';
  let dashboardClient = null;
  let activeAttempt = null;
  const uncertainBookings = new Set();
  let recoveryModal = null;
  let focusCheckAt = 0;

  const bookingKey = attempt => `sevilla360.paymongo.booking.${attempt}`;
  function rememberBooking(attempt, booking) {
    try { window.localStorage?.setItem(bookingKey(attempt), String(booking)); } catch (_) {}
  }
  function rememberedBooking(attempt) {
    try {
      const value = window.localStorage?.getItem(bookingKey(attempt));
      return /^[1-9][0-9]*$/.test(value || '') ? Number(value) : null;
    } catch (_) { return null; }
  }
  function forgetBooking(attempt) {
    try { window.localStorage?.removeItem(bookingKey(attempt)); } catch (_) {}
  }

  function checkoutFailureMessage(status) {
    if (status === 422) return 'Checkout could not be confirmed. Check your booking status before trying again. Do not pay again while the outcome is uncertain.';
    if (status === 403 || status === 401) return 'Your session could not be verified. Sign in again, then check your booking status before taking another payment action.';
    return 'Checkout could not be confirmed. Review your booking and check the payment status before trying again.';
  }

  function dismissRecovery(record = activeAttempt) {
    if (record && activeAttempt === record && !record.settled && !record.retrySafe) record.dismissed = true;
    try {
      const url = new URL(window.location.href);
      const hadRecoveryParams = url.searchParams.has('attempt') || url.searchParams.has('result');
      url.searchParams.delete('attempt');
      url.searchParams.delete('result');
      if (hadRecoveryParams) window.history.replaceState(window.history.state, '', url.href);
    } catch (_) { /* Dismissal must remain available even if history state cannot be updated. */ }
    if (recoveryModal) recoveryModal.dismiss();
    window.dispatchEvent(new Event('focus'));
  }

  function ensureRecoveryModal(invoker) {
    if (recoveryModal) return recoveryModal;
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay alert-modal-overlay active paymongo-recovery-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'paymongo-recovery-title');
    overlay.setAttribute('aria-describedby', 'paymongo-recovery-message');
    overlay.tabIndex = -1;

    const box = document.createElement('div'); box.className = 'paymongo-recovery-dialog';
    const icon = document.createElement('i'); icon.className = 'fa-solid fa-circle-info paymongo-recovery-icon'; icon.setAttribute('aria-hidden', 'true');
    const title = document.createElement('h2'); title.className = 'paymongo-recovery-title'; title.id = 'paymongo-recovery-title'; title.textContent = 'Payment not confirmed';
    const message = document.createElement('p'); message.id = 'paymongo-recovery-message'; message.className = 'paymongo-recovery-message'; message.setAttribute('role', 'status'); message.setAttribute('aria-live', 'polite');
    const actions = document.createElement('div'); actions.className = 'paymongo-recovery-actions';
    const primary = document.createElement('button'); primary.type = 'button'; primary.className = 'paymongo-recovery-button paymongo-recovery-button--primary';
    const secondary = document.createElement('button'); secondary.type = 'button'; secondary.className = 'paymongo-recovery-button paymongo-recovery-button--secondary';
    const ok = document.createElement('button'); ok.type = 'button'; ok.className = 'paymongo-recovery-button paymongo-recovery-button--primary'; ok.textContent = 'OK';
    actions.append(primary, secondary, ok); box.append(icon, title, message, actions); overlay.append(box); document.body.append(overlay);

    const siblings = Array.from(document.body.children).filter(node => node !== overlay).map(node => ({node, inert: node.inert}));
    siblings.forEach(({node}) => { node.inert = true; });
    const priorOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const priorFocus = invoker || document.activeElement;
    const dismiss = () => {
      overlay.remove(); document.body.style.overflow = priorOverflow;
      siblings.forEach(({node, inert}) => { node.inert = inert; });
      if (priorFocus?.isConnected) { try { priorFocus.focus({preventScroll: true}); } catch (_) { priorFocus.focus(); } }
      recoveryModal = null;
    };
    const focusable = () => Array.from(overlay.querySelectorAll('button:not([hidden]):not(:disabled)'));
    overlay.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        if (!recoveryModal?.dismissible) { event.preventDefault(); return; }
        dismissRecovery(activeAttempt); return;
      }
      if (event.key !== 'Tab') return;
      event.stopPropagation();
      const controls = focusable();
      if (!controls.length) { event.preventDefault(); overlay.focus(); return; }
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    const setAction = (button, label, handler) => {
      button.hidden = typeof handler !== 'function';
      button.style.display = button.hidden ? 'none' : '';
      if (typeof handler === 'function') { button.textContent = label; button.onclick = handler; }
      else button.onclick = null;
    };
    recoveryModal = {
      overlay, message, title, icon, primary, secondary, ok, dismiss,
      set(text, config = {}) {
        title.textContent = config.title || 'Payment not confirmed';
        icon.className = 'fa-solid ' + (config.icon || 'fa-circle-info') + ' paymongo-recovery-icon';
        icon.style.color = config.iconColor || (config.icon === 'fa-circle-check' ? '#16a34a' : '#d6a870');
        message.textContent = text;
        this.dismissible = !!config.dismissible;
        setAction(primary, config.primaryLabel || 'Check payment status', config.onPrimary);
        setAction(secondary, config.secondaryLabel || 'Cancel checkout', config.onSecondary);
        setAction(ok, 'OK', config.hideOk ? null : config.onDismiss || (() => dismissRecovery(activeAttempt)));
        ok.className = 'paymongo-recovery-button paymongo-recovery-button--' + (config.onPrimary || config.onSecondary ? 'secondary' : 'primary');
        const first = focusable()[0]; if (first) first.focus({preventScroll: true}); else overlay.focus({preventScroll: true});
      }
    };
    overlay.focus({preventScroll: true});
    return recoveryModal;
  }

  function renderAttemptOutcome(record, data) {
    const modal = ensureRecoveryModal();
    if (data?.message === 'open') {
      modal.set('Payment is not confirmed yet. Check its status or cancel the checkout securely before taking another payment action.', {
        title: 'Payment not confirmed', dismissible: true,
        primaryLabel: 'Check payment status', onPrimary: () => checkAttempt(record),
        secondaryLabel: 'Cancel checkout', onSecondary: () => checkAttempt(record, true)
      });
      return;
    }
    if (data?.message === 'expired') {
      record.retrySafe = true;
      if (activeAttempt === record) activeAttempt = null;
      record.onResolved?.(data);
      modal.set('This checkout expired. Review your booking status before making another payment.', {
        title: 'Checkout expired', dismissible: true
      });
      return;
    }
    if (data?.message === 'applied') {
      record.settled = true; forgetBooking(record.attempt);
      if (activeAttempt === record) activeAttempt = null;
      record.onResolved?.(data);
      modal.set('Payment confirmed and applied to your booking.', {title: 'Payment successful', icon: 'fa-circle-check', dismissible: true});
      return;
    }
    if (paidOutcomes.has(data?.message)) {
      record.settled = true; forgetBooking(record.attempt);
      if (activeAttempt === record) activeAttempt = null;
      record.onResolved?.(data);
      modal.set('A payment was received, but it needs review before the booking can be updated. Do not pay again.', {title: 'Payment received', dismissible: true});
      return;
    }
    modal.set('We could not verify the payment outcome. Do not pay again while the result is uncertain. Check the status before taking another payment action.', {
      title: 'Payment not confirmed', dismissible: true,
      primaryLabel: 'Check payment status', onPrimary: () => checkAttempt(record)
    });
  }

  async function checkAttempt(record, close = false) {
    if (!record || record.checking) return;
    record.checking = true;
    const modal = ensureRecoveryModal();
    modal.set('Checking payment status with PayMongo…', {title: 'Checking payment status', hideOk: true});
    try {
      const response = await fetch(close ? '/actions/user/close_paymongo_checkout.php' : '/actions/user/sync_payment.php', {
        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': record.csrfToken || ''},
        body: JSON.stringify({attempt: record.attempt})
      });
      const data = await response.json();
      if (!response.ok) throw new Error('unverified');
      renderAttemptOutcome(record, data);
    } catch (_) {
      modal.set('We could not verify the payment outcome. Do not pay again while the result is uncertain. Check the status before taking another payment action.', {
        title: 'Payment not confirmed', dismissible: true,
        primaryLabel: 'Check payment status', onPrimary: () => checkAttempt(record)
      });
    } finally { record.checking = false; }
  }

  function installFocusRecovery() {
    if (installFocusRecovery.installed) return;
    installFocusRecovery.installed = true;
    const refresh = () => {
      if (document.visibilityState === 'hidden' || !activeAttempt || activeAttempt.settled || activeAttempt.retrySafe || activeAttempt.dismissed) return;
      const now = Date.now(); if (now - focusCheckAt < 1200) return;
      focusCheckAt = now; checkAttempt(activeAttempt);
    };
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
  }

  window.SevillaPayMongoCheckout = {
    enabled,
    policyVersion: currentBookingPolicy,
    reconcileReturn(options = {}) {
      if (!enabled()) return;
      const id = new URLSearchParams(window.location.search).get('attempt');
      if (!attemptPattern.test(id || '')) return;
      const start = () => {
        if (recoveryModal) return;
        const record = {attempt: id, bookingId: rememberedBooking(id), csrfToken: options.csrfToken || '', onResolved: options.onResolved, checking: false};
        activeAttempt = record; installFocusRecovery();
        ensureRecoveryModal().set('Checking payment status with PayMongo…', {title: 'Checking payment status', hideOk: true});
        checkAttempt(record);
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
      else start();
      return {check: () => activeAttempt && checkAttempt(activeAttempt)};
    },
    create(options = {}) {
      let pending = false;
      const reportImmediateError = (error, invoker) => {
        if (typeof options.onError === 'function') {
          try { options.onError(error); return; } catch (_) { /* Fall back to the checkout dialog. */ }
        }
        ensureRecoveryModal(invoker).set(error?.message || 'Checkout could not be started. Review your booking and try again.', {
          title: 'Checkout unavailable', dismissible: true
        });
      };
      const client = {
        async openBooking(id, invoker) {
          if (pending) return;
          if (!/^[1-9][0-9]*$/.test(String(id))) {
            reportImmediateError(new Error('Invalid booking.'), invoker);
            return;
          }
          const bookingId = Number(id);
          let policyConsent = false;
          if (invoker && invoker.dataset.policyVersion !== currentBookingPolicy) {
            if (typeof options.requirePolicyConsent !== 'function') {
              reportImmediateError(new Error('Review the updated PayMongo refund terms before paying.'), invoker);
              return;
            }
            pending = true;
            try { policyConsent = await options.requirePolicyConsent(invoker) === true; }
            catch (error) { reportImmediateError(error, invoker); return; }
            finally { pending = false; }
            if (!policyConsent) return;
          }
          if (activeAttempt && !activeAttempt.settled && !activeAttempt.retrySafe) {
            activeAttempt.dismissed = false;
            ensureRecoveryModal(invoker).set('There is already an unresolved checkout. Check its status or cancel it securely before starting another payment.', {
              title: 'Payment not confirmed', dismissible: true,
              primaryLabel: 'Check payment status', onPrimary: () => checkAttempt(activeAttempt),
              secondaryLabel: 'Cancel checkout', onSecondary: () => checkAttempt(activeAttempt, true)
            });
            checkAttempt(activeAttempt);
            return;
          }
          if (uncertainBookings.has(bookingId)) {
            ensureRecoveryModal(invoker).set('We could not verify whether checkout was created. Do not pay again while the result is uncertain.', {
              title: 'Payment not confirmed', dismissible: true
            });
            return;
          }
          pending = true;
          let requestStarted = false;
          if (invoker) invoker.disabled = true;
          const modal = options.onReady ? null : ensureRecoveryModal(invoker);
          modal?.set('Preparing your secure checkout…', {title: 'Preparing checkout', hideOk: true});
          try {
            requestStarted = true;
            const response = await fetch('/actions/user/pay_existing.php', { method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrfToken || '' },
              body: JSON.stringify({ booking: Number(id), policy_consent: policyConsent }) });
            const data = await response.json();
            if (!response.ok) {
              const error = new Error(checkoutFailureMessage(response.status));
              error.checkoutStatus = response.status;
              throw error;
            }
            // A consent prompt is acknowledged client-side only after the
            // server accepts the checkout request and records the version.
            if (policyConsent && invoker) invoker.dataset.policyVersion = currentBookingPolicy;
            if (data.checkout_url) {
              if (typeof options.onReady !== 'function') {
                if (!attemptPattern.test(data.attempt || '')) throw new Error('Checkout status could not be verified.');
                rememberBooking(data.attempt, bookingId);
                activeAttempt = {attempt: data.attempt, bookingId, csrfToken: options.csrfToken || '', onResolved: options.onResolved, checking: false};
              }
              const url = new URL(data.checkout_url, window.location.origin);
              if (!(url.origin === window.location.origin || (url.protocol === 'https:' && url.hostname === 'checkout.paymongo.com' && !url.username && !url.password && !url.port))) throw new Error('Invalid checkout destination.');
              if (options.onReady) options.onReady(data);
              else {
                uncertainBookings.delete(bookingId);
                installFocusRecovery();
                window.location.assign(url.href);
              }
            } else if (options.onReady) options.onReady(data);
            else if (attemptPattern.test(data.attempt || '')) {
              rememberBooking(data.attempt, bookingId);
              activeAttempt = {attempt: data.attempt, bookingId, csrfToken: options.csrfToken || '', onResolved: options.onResolved, checking: false};
              modal.set('Checkout was created, but its payment page did not open. Check its status before taking another payment action.', {
                title: 'Payment not confirmed', dismissible: true,
                primaryLabel: 'Check payment status', onPrimary: () => checkAttempt(activeAttempt),
                secondaryLabel: 'Cancel checkout', onSecondary: () => checkAttempt(activeAttempt, true)
              });
              checkAttempt(activeAttempt);
            } else throw new Error('Checkout response was incomplete.');
            return data;
          } catch (error) {
            if (options.onError) options.onError(error);
            else if (requestStarted && activeAttempt?.bookingId === bookingId) {
              modal?.set('Checkout could not be opened safely. Checking its payment status before another payment action.', {
                title: 'Payment not confirmed', dismissible: true,
                primaryLabel: 'Check payment status', onPrimary: () => checkAttempt(activeAttempt),
                secondaryLabel: 'Cancel checkout', onSecondary: () => checkAttempt(activeAttempt, true)
              });
              checkAttempt(activeAttempt);
            } else {
              if (requestStarted) uncertainBookings.add(bookingId);
              const message = Number.isInteger(error?.checkoutStatus)
                ? checkoutFailureMessage(error.checkoutStatus)
                : 'Checkout could not be verified. Do not pay again while the outcome is uncertain. Review your booking and check the status before another payment action.';
              modal?.set(message, {title: 'Payment not confirmed', dismissible: true});
            }
          } finally { pending = false; if (invoker) invoker.disabled = false; }
        }
      };
      if (options.bindButtons !== false) dashboardClient = client;
      if (options.bindButtons !== false) document.addEventListener('click', event => {
        const button = event.target.closest('.btn-submit-payment');
        if (button) client.openBooking(button.dataset.id, button).catch(error => reportImmediateError(error, button));
      });
      return client;
    }
  };
})();

// The countdown uses database time, avoiding differences in the guest's device clock.
(() => {
  const firstSeen = new WeakMap();
  let refreshQueued = false;
  function updatePaymentTimers() {
    document.querySelectorAll('[data-payment-seconds]').forEach(timer => {
      if (!firstSeen.has(timer)) firstSeen.set(timer, performance.now());
      const initial = Number(timer.dataset.paymentSeconds);
      if (!Number.isFinite(initial) || initial < 0) return;
      const seconds = Math.max(0, initial - Math.floor((performance.now()-firstSeen.get(timer))/1000));
      const paymentTitle = timer.dataset.paymentTitle || 'Payment Due';
      const isEventQuoteDeadline = timer.dataset.paymentEventQuote === '1';
      const isEventCheckoutHold = timer.dataset.paymentEventCheckout === '1';
      const label = timer.querySelector('[data-payment-label]');
      const display = timer.querySelector('[data-payment-countdown]');
      const note = timer.querySelector('[data-payment-note]');
      const formatRemaining = value => {
        const hours = Math.floor(value / 3600);
        const minutes = Math.floor((value % 3600) / 60);
        const remainingSeconds = value % 60;
        const mm = String(minutes).padStart(2, '0');
        const ss = String(remainingSeconds).padStart(2, '0');
        return hours < 100
          ? `${String(hours).padStart(2, '0')}:${mm}:${ss}`
          : `${Math.floor(hours / 24)}:${String(hours % 24).padStart(2, '0')}:${mm}:${ss}`;
      };
      if (seconds > 0) {
        if (label) label.textContent = isEventQuoteDeadline ? 'Event Hall payment deadline' : (isEventCheckoutHold ? 'Checkout hold' : paymentTitle);
        if (display) display.textContent = `${formatRemaining(seconds)} remaining`;
        if (note) note.textContent = isEventQuoteDeadline
          ? 'Pay within 24 hours after quote finalization. Unpaid bookings are cancelled automatically; uncertain payments are verified first.'
          : 'If time runs out, we’ll verify payment before releasing this hold.';
        timer.classList.toggle('is-urgent', seconds <= 60);
        timer.classList.remove('is-expired');
      } else {
        if (label) label.textContent = isEventQuoteDeadline ? 'Payment deadline passed' : (isEventCheckoutHold ? 'Checkout hold ended' : 'Payment window ended');
        if (display) display.textContent = '00:00:00';
        if (note) note.textContent = isEventQuoteDeadline
          ? 'Checking payment status before cancelling the unpaid booking.'
          : 'Checking payment before releasing this hold.';
        timer.classList.remove('is-urgent');
        timer.classList.add('is-expired');
        timer.closest('tr')?.querySelectorAll('.btn-submit-payment').forEach(button => { button.disabled=true; });
        if (initial>0 && !refreshQueued) {
          refreshQueued=true;
          window.dispatchEvent(new Event('focus'));
          setTimeout(()=>{refreshQueued=false;},30000);
        }
      }
    });
  }
  updatePaymentTimers();
  setInterval(updatePaymentTimers,1000);
})();
