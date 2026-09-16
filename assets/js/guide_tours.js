/* Small dependency-free, keyboard-safe contextual tour used by public/admin surfaces. */
(function (global) {
    'use strict';
    function init(options) {
        const helpButton = options.helpButton;
        const key = String(options.key || '');
        const steps = Array.isArray(options.steps) ? options.steps : [];
        let overlay = null;
        let current = 0;
        let previousFocus = null;
        let autoAttempted = false;
        let highlightRefresh = null;
        const storage = () => {
            try {
                const probe = '__sevilla_tour_probe__';
                localStorage.setItem(probe, '1');
                localStorage.removeItem(probe);
                return localStorage;
            } catch (error) { return null; }
        };
        const store = storage();
        const seen = () => store && key ? store.getItem(key) === '1' : false;
        const remember = () => { try { store?.setItem(key, '1'); } catch (error) {} };
        const resolveTarget = step => typeof step.target === 'function' ? step.target() : document.querySelector(step.target);
        const clearTarget = () => document.querySelectorAll('[data-guide-target]').forEach(element => element.removeAttribute('data-guide-target'));
        const close = (rememberTour = true) => {
            clearTarget();
            if (!overlay) return;
            if (rememberTour) remember();
            overlay.remove();
            overlay = null;
            if (highlightRefresh) window.removeEventListener('resize', highlightRefresh);
            highlightRefresh = null;
            document.documentElement.classList.remove('sevilla-guide-open');
            const focusTarget = previousFocus instanceof HTMLElement && previousFocus.isConnected ? previousFocus : helpButton;
            focusTarget?.focus?.({ preventScroll: true });
        };
        const render = () => {
            if (!overlay || !steps.length) return;
            const step = steps[current];
            const target = resolveTarget(step);
            overlay.querySelector('[data-guide-title]').textContent = String(step.title || 'Quick guide');
            overlay.querySelector('[data-guide-copy]').textContent = String(step.copy || '');
            overlay.querySelector('[data-guide-count]').textContent = `${current + 1} of ${steps.length}`;
            overlay.querySelector('[data-guide-prev]').hidden = current === 0;
            overlay.querySelector('[data-guide-next]').textContent = current === steps.length - 1 ? 'Done' : 'Next';
            document.querySelectorAll('[data-guide-target]').forEach(element => element.removeAttribute('data-guide-target'));
            const ring = overlay.querySelector('.sevilla-guide-target-ring');
            if (ring) ring.hidden = true;
            if (target) {
                target.setAttribute('data-guide-target', 'true');
                target.scrollIntoView?.({ block: 'nearest', inline: 'nearest', behavior: global.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                const positionRing = () => {
                    if (!overlay || !ring || !target.isConnected) return;
                    const bounds = target.getBoundingClientRect();
                    if (bounds.width <= 0 || bounds.height <= 0) return;
                    ring.hidden = false;
                    ring.style.left = `${Math.max(0, bounds.left - 6)}px`;
                    ring.style.top = `${Math.max(0, bounds.top - 6)}px`;
                    ring.style.width = `${bounds.width + 12}px`;
                    ring.style.height = `${bounds.height + 12}px`;
                };
                positionRing();
                global.requestAnimationFrame?.(positionRing);
            }
        };
        const open = (isAuto = false) => {
            if (overlay) {
                render();
                overlay.querySelector('.sevilla-guide-panel')?.focus({ preventScroll: true });
                return;
            }
            if (!steps.length || (isAuto && autoAttempted)) return;
            if (isAuto) autoAttempted = true;
            previousFocus = document.activeElement;
            overlay = document.createElement('div');
            overlay.className = 'sevilla-guide-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-labelledby', 'sevilla-guide-title');
            overlay.innerHTML = `<div class="sevilla-guide-target-ring" aria-hidden="true" hidden></div><div class="sevilla-guide-panel" tabindex="-1"><div class="sevilla-guide-panel-head"><span data-guide-count></span><button type="button" data-guide-close aria-label="Close guide">×</button></div><h2 id="sevilla-guide-title" data-guide-title></h2><p data-guide-copy></p><div class="sevilla-guide-actions"><button type="button" data-guide-skip>Skip</button><button type="button" data-guide-prev>Back</button><button type="button" data-guide-next>Next</button></div></div>`;
            document.body.appendChild(overlay);
            document.documentElement.classList.add('sevilla-guide-open');
            current = 0;
            highlightRefresh = () => {
                if (!overlay || !steps[current]) return;
                const target = resolveTarget(steps[current]);
                const ring = overlay.querySelector('.sevilla-guide-target-ring');
                if (!target || !ring || !target.isConnected) { ring?.setAttribute('hidden', ''); return; }
                const bounds = target.getBoundingClientRect();
                if (bounds.width <= 0 || bounds.height <= 0) { ring.setAttribute('hidden', ''); return; }
                ring.removeAttribute('hidden');
                ring.style.left = `${Math.max(0, bounds.left - 6)}px`;
                ring.style.top = `${Math.max(0, bounds.top - 6)}px`;
                ring.style.width = `${bounds.width + 12}px`;
                ring.style.height = `${bounds.height + 12}px`;
            };
            window.addEventListener('resize', highlightRefresh, { passive: true });
            const panel = overlay.querySelector('.sevilla-guide-panel');
            overlay.querySelector('[data-guide-close]').addEventListener('click', () => close(true));
            overlay.querySelector('[data-guide-skip]').addEventListener('click', () => close(true));
            overlay.querySelector('[data-guide-prev]').addEventListener('click', () => { current = Math.max(0, current - 1); render(); });
            overlay.querySelector('[data-guide-next]').addEventListener('click', () => { if (current >= steps.length - 1) close(true); else { current += 1; render(); } });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
                if (event.key === 'ArrowRight') { event.preventDefault(); overlay.querySelector('[data-guide-next]').click(); return; }
                if (event.key === 'ArrowLeft') { event.preventDefault(); overlay.querySelector('[data-guide-prev]').click(); return; }
                if (event.key !== 'Tab') return;
                const focusable = Array.from(overlay.querySelectorAll('button')).filter(button => !button.hidden);
                if (!focusable.length) return;
                const first = focusable[0], last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            });
            render();
            panel.focus({ preventScroll: true });
        };
        helpButton?.addEventListener('click', () => open(false));
        return {
            open,
            maybeAutoShow() { if (!seen() && !autoAttempted && (!options.ready || options.ready())) open(true); },
            isOpen: () => Boolean(overlay),
            close
        };
    }
    global.SevillaGuideTour = Object.freeze({ init });
})(window);
