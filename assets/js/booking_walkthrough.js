(() => {
    'use strict';

    const openButton = document.getElementById('booking-help-open');
    const overlay = document.getElementById('booking-help-overlay');
    if (!openButton || !overlay) return;

    const dialog = document.getElementById('booking-help-dialog');
    const closeButton = document.getElementById('booking-help-close');
    const skipButton = document.getElementById('booking-help-skip');
    const backButton = document.getElementById('booking-help-back');
    const nextButton = document.getElementById('booking-help-next');
    const progress = document.getElementById('booking-help-progress');
    const title = document.getElementById('booking-help-step-title');
    const copy = document.getElementById('booking-help-step-copy');
    const focusable = [closeButton, skipButton, backButton, nextButton];
    const floatingEntry = openButton.closest('.booking-help-entry');
    const bookingMain = document.querySelector('.booking-main');
    let step = 0;
    let returnFocus = null;
    let previousOverflow = '';

    function service() {
        const selected = document.querySelector('[data-booking-tablist] [role="tab"][aria-selected="true"]');
        const tab = selected?.dataset.tab || document.querySelector('[data-booking-tablist] .active')?.dataset.tab;
        if (tab === 'hotel-rooms') return 'hotel';
        if (tab === 'resort-villa') return 'villa';
        return 'event';
    }

    function steps() {
        const kind = service();
        const overnight = document.querySelector('input[name="villa-stay"]:checked')?.value === 'Overnight';
        const dates = kind === 'event'
            ? 'Choose an event hall and inquiry dates, then confirm the selection on the calendar. An inquiry checks availability but does not hold a date.'
            : kind === 'hotel'
                ? 'Choose a room, then select check-in and a later checkout date. Hotel stays are booked by night. Confirm your dates on the calendar to continue.'
                : overnight
                    ? 'Choose a villa and Overnight stay. Select check-in and a later checkout date; the whole range must be available. Confirm the dates on the calendar.'
                    : 'Choose a villa and Day Time Stay. Select one calendar date, then confirm it on the calendar.';
        const details = kind === 'event'
            ? 'Add your event details and guest count, then review the inquiry and contact number. The resort finalizes the quote after reviewing your plans.'
            : 'Add your guest count, choose a payment scheme, then review the amount due, contact number, and terms before submitting a reservation.';
        const proof = kind === 'event'
            ? 'There is no payment at inquiry submission. A coordinator follows up about the final quote; payment instructions come after resort confirmation.'
            : 'After submitting a reservation, use the listed manual payment instructions and deadline. Enter the transfer reference and upload a receipt image as proof.';
        const review = kind === 'event'
            ? 'A coordinator reviews your inquiry and contacts you about the event details and final quote. The date is not secured by sending the inquiry.'
            : 'Staff review the submitted payment proof. Sending proof does not confirm payment or your booking; follow its status under My Bookings.';
        return [
            ['Choose dates', dates],
            ['Add booking details', details],
            ['Payment proof', proof],
            ['Staff verification', review]
        ];
    }

    function render({ focus = false } = {}) {
        const items = steps();
        progress.textContent = `Step ${step + 1} of ${items.length}`;
        title.textContent = items[step][0];
        copy.textContent = items[step][1];
        backButton.disabled = step === 0;
        nextButton.textContent = step === items.length - 1 ? 'Finish' : 'Next';
        if (focus) title.focus();
    }

    function close() {
        if (overlay.hidden) return;
        overlay.hidden = true;
        document.body.style.overflow = previousOverflow;
        if (returnFocus?.isConnected) returnFocus.focus();
        else openButton.focus();
    }

    openButton.addEventListener('click', () => {
        returnFocus = document.activeElement;
        previousOverflow = document.body.style.overflow;
        step = 0;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        render({ focus: true });
    });
    closeButton.addEventListener('click', close);
    skipButton.addEventListener('click', close);
    backButton.addEventListener('click', () => {
        if (step > 0) { step -= 1; render({ focus: true }); }
    });
    nextButton.addEventListener('click', () => {
        if (step === steps().length - 1) close();
        else { step += 1; render({ focus: true }); }
    });
    overlay.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); close(); return; }
        if (event.key !== 'Tab') return;
        const enabled = focusable.filter(element => !element.disabled);
        const first = enabled[0];
        const last = enabled[enabled.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog || document.activeElement === title)) {
            event.preventDefault(); last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first.focus();
        }
    });
    document.addEventListener('focusin', event => {
        if (!overlay.hidden && !dialog.contains(event.target)) closeButton.focus();
    });

    if (floatingEntry && bookingMain) {
        let queued = false;
        const updateFloatingHelp = () => {
            queued = false;
            const area = bookingMain.getBoundingClientRect();
            const help = openButton.getBoundingClientRect();
            const viewportHeight = window.visualViewport?.height || window.innerHeight;
            const keyboardOpen = window.visualViewport && window.innerHeight - viewportHeight > 120;
            let visible = area.top < help.bottom && area.bottom > help.bottom + 8 && !keyboardOpen;
            if (visible && document.activeElement !== openButton && overlay.hidden) {
                const overlaps = element => {
                    const rect = element.getBoundingClientRect();
                    return rect.width > 0 && rect.height > 0
                        && rect.left < help.right + 6 && rect.right > help.left - 6
                        && rect.top < help.bottom + 6 && rect.bottom > help.top - 6;
                };
                visible = !Array.from(bookingMain.querySelectorAll('button, input, select, textarea, a, .calendar-ui'))
                    .some(overlaps)
                    && !Array.from(document.querySelectorAll('[data-receptionist-chat-toggle]')).some(overlaps);
            }
            floatingEntry.classList.toggle('is-visible', visible);
        };
        const scheduleFloatingHelp = () => {
            if (queued) return;
            queued = true;
            window.requestAnimationFrame(updateFloatingHelp);
        };
        window.addEventListener('scroll', scheduleFloatingHelp, { passive: true });
        window.addEventListener('resize', scheduleFloatingHelp);
        window.visualViewport?.addEventListener('resize', scheduleFloatingHelp);
        bookingMain.addEventListener('click', scheduleFloatingHelp);
        bookingMain.addEventListener('change', scheduleFloatingHelp);
        scheduleFloatingHelp();
    }
})();
