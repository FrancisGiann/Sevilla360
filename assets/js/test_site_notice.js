(function () {
  'use strict';

  const PUBLIC_KEY = 'sevilla360-test-site-notice-public-v1';
  const BOOKING_KEY = 'sevilla360-test-site-notice-booking-v1';
  const inMemorySeen = Object.create(null);

  function initializeNotice() {
    const backdrop = document.querySelector('[data-test-site-notice]');
    if (!backdrop) return;

    const dialog = backdrop.querySelector('[role="dialog"]');
    const closeButtons = Array.from(backdrop.querySelectorAll('[data-test-site-notice-close]'));
    if (!dialog || closeButtons.length === 0) return;

    const context = backdrop.dataset.noticeContext === 'booking' ? 'booking' : 'public';
    const readSeen = key => {
      try {
        return sessionStorage.getItem(key) === '1' || inMemorySeen[key] === true;
      } catch (error) {
        return inMemorySeen[key] === true;
      }
    };
    const markSeen = key => {
      inMemorySeen[key] = true;
      try {
        sessionStorage.setItem(key, '1');
      } catch (error) {
        // Keep this page functional when storage is blocked by browser policy.
      }
    };

    const noticeKey = context === 'booking' ? BOOKING_KEY : PUBLIC_KEY;
    if (readSeen(noticeKey)) return;
    markSeen(noticeKey);
    // A direct first visit to booking displays one notice that fulfills both
    // the public-site and booking reminders, avoiding two stacked dialogs.
    if (context === 'booking') markSeen(PUBLIC_KEY);

    const previousOverflow = document.body.style.overflow;
    const previousFocus = document.activeElement;
    const inertSiblings = Array.from(document.body.children)
      .filter(element => element !== backdrop && element instanceof HTMLElement)
      .map(element => ({
        element,
        inert: element.inert,
        ariaHidden: element.getAttribute('aria-hidden')
      }));

    inertSiblings.forEach(({ element }) => {
      element.inert = true;
      element.setAttribute('aria-hidden', 'true');
    });
    document.body.style.overflow = 'hidden';
    backdrop.hidden = false;

    const close = () => {
      if (backdrop.hidden) return;
      backdrop.hidden = true;
      document.body.style.overflow = previousOverflow;
      inertSiblings.forEach(({ element, inert, ariaHidden }) => {
        element.inert = inert;
        if (ariaHidden === null) element.removeAttribute('aria-hidden');
        else element.setAttribute('aria-hidden', ariaHidden);
      });
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected && previousFocus !== document.body) {
        previousFocus.focus({ preventScroll: true });
      }
      window.dispatchEvent(new CustomEvent('SevillaTestSiteNoticeClosed'));
    };

    closeButtons.forEach(button => button.addEventListener('click', close));
    dialog.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = closeButtons.filter(button => !button.disabled && button.getClientRects().length > 0);
      if (focusable.length === 0) {
        event.preventDefault();
        dialog.focus();
      } else if (event.shiftKey && document.activeElement === focusable[0]) {
        event.preventDefault();
        focusable[focusable.length - 1].focus();
      } else if (!event.shiftKey && document.activeElement === focusable[focusable.length - 1]) {
        event.preventDefault();
        focusable[0].focus();
      }
    });

    closeButtons[closeButtons.length - 1].focus({ preventScroll: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeNotice, { once: true });
  } else {
    initializeNotice();
  }
}());
