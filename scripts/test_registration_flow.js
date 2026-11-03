/* Isolated interaction tests. No database, browser, accounts, or email delivery. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const markup = fs.readFileSync(path.join(root, 'auth.php'), 'utf8');
const script = fs.readFileSync(path.join(root, 'assets/js/auth.js'), 'utf8');
const policy = fs.readFileSync(path.join(root, 'assets/js/password_policy.js'), 'utf8');
assert.ok(!markup.includes('register-step-1') && !markup.includes('btn-next-step'), 'account fields are on one screen');
assert.match(markup, /aria-label="Account creation progress"/);
assert.match(markup, /pattern="\[0-9\]\{6\}"/);
assert.ok(!script.includes('sessionStorage') && !script.includes('localStorage'), 'passwords are never persisted in browser storage');

function fixture(search = '', phoneSignup = false) {
    const elements = new Map();
    const listeners = new Map();
    const timers = new Map();
    let requests = 0;
    let apiHandler = null;
    const requestLog = [];
    let fetchResult = { ok: true, json: async () => ({ success: true }) };
    const document = {
        body: { dataset: { phoneSignup: phoneSignup ? '1' : '0' } },
        activeElement: null,
        addEventListener(name, handler) { const list = listeners.get(name) || []; list.push(handler); listeners.set(name, list); },
        getElementById(id) { return elements.get(id) || null; },
        querySelector() { return { getAttribute: () => 'synthetic-csrf' }; },
        querySelectorAll(selector) {
            if (selector === '.password-toggle') return [toggle];
            if (selector === 'button[data-idle-html]') return [submit].filter(button => button.dataset.idleHtml);
            return [];
        }
    };
    class Element {
        constructor(id) {
            this.id = id; this.value = ''; this.type = 'text'; this.disabled = false; this.checked = false;
            this.style = {}; this.dataset = {}; this.attributes = {}; this.handlers = new Map(); this.textContent = ''; this.innerHTML = 'CREATE ACCOUNT & VERIFY EMAIL';
            const classes = new Set();
            this.classList = { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c), toggle: (c, active) => active ? classes.add(c) : classes.delete(c) };
            this.validity = { typeMismatch: false }; elements.set(id, this);
        }
        addEventListener(name, handler) { const list = this.handlers.get(name) || []; list.push(handler); this.handlers.set(name, list); }
        async fire(name, overrides = {}) {
            const event = { target: this, currentTarget: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...overrides };
            for (const handler of this.handlers.get(name) || []) await handler.call(this, event);
            return event;
        }
        focus() { document.activeElement = this; }
        setAttribute(name, value) { this.attributes[name] = value; }
        removeAttribute(name) { delete this.attributes[name]; }
        setCustomValidity(message) { this.error = message; }
        checkValidity() { return !this.error && (!this.required || (this.type === 'checkbox' ? this.checked : Boolean(this.value))); }
        reportValidity() { this.focus(); return this.checkValidity(); }
        querySelector(selector) {
            if (selector === 'button[type="submit"]') return submit;
            return this.id === 'view-user-register' ? elements.get('reg-first-name') : elements.get('login-email');
        }
        querySelectorAll() { return [elements.get('btn-close-verification'), elements.get('verification-code'), submit, elements.get('resend-code-btn')].filter(e => !e.disabled); }
        closest(selector) { return selector === 'form' ? form : null; }
    }
    for (const id of [...markup.matchAll(/\bid="([^"]+)"/g)].map(match => match[1])) new Element(id);
    const toggle = new Element('synthetic-password-toggle');
    toggle.previousElementSibling = elements.get('reg-password');
    const submit = new Element('synthetic-submit');
    const form = elements.get('form-register');
    form.action = 'https://synthetic.invalid/actions/auth/register_process.php';
    const fieldIds = ['reg-first-name', 'reg-last-name', 'reg-email', 'reg-password', 'reg-confirm-password', 'agree-checkbox'];
    if (phoneSignup) fieldIds.push('reg-phone');
    fieldIds.forEach(id => { elements.get(id).required = true; });
    elements.get('agree-checkbox').type = 'checkbox';
    elements.get('reg-password').type = 'password';
    elements.get('reg-confirm-password').type = 'password';
    form.checkValidity = () => fieldIds.every(id => elements.get(id).checkValidity());
    form.reportValidity = () => fieldIds.map(id => elements.get(id)).find(e => !e.checkValidity())?.focus();
    const windowEvents = new Map();
    const window = {
        location: { search, origin: 'https://synthetic.invalid', href: `https://synthetic.invalid/auth.php${search}`, assign(url) { this.href = url; } },
        history: { state: null, replaceState(_state, _title, url) { window.replacedUrl = url; } },
        addEventListener(name, handler) { windowEvents.set(name, handler); }
    };
    const context = {
        document, window, URL, URLSearchParams, encodeURIComponent,
        FormData: class { constructor(form) { this.form = form; } },
        showAlert() {},
        setInterval(handler) { const id = timers.size + 1; timers.set(id, handler); return id; },
        clearInterval(id) { timers.delete(id); },
        async fetch(url, options) {
            requests++; requestLog.push({ url, options });
            if (apiHandler) return apiHandler(url, options);
            if (url === 'actions/auth/check_registration_email.php') {
                assert.equal(options.headers['X-CSRF-Token'], 'synthetic-csrf');
                return { ok: true, json: async () => ({ state: 'available' }) };
            }
            if (url === form.action) return { ok: true, json: async () => ({ state: 'redirect', url: '/auth.php?verify_email=synthetic%40example.invalid' }) };
            assert.equal(url, 'actions/auth/resend_code.php');
            assert.equal(options.headers['X-CSRF-Token'], 'synthetic-csrf');
            return fetchResult;
        }
    };
    vm.createContext(context);
    vm.runInContext(policy, context);
    vm.runInContext(script, context);
    for (const handler of listeners.get('DOMContentLoaded')) handler();
    return { elements, submit, form, document, timers, window, windowEvents, requests: () => requests, requestLog, api: handler => { apiHandler = handler; }, result: value => { fetchResult = value; } };
}

(async () => {
    const f = fixture();
    const get = id => f.elements.get(id);
    await get('link-goto-register').fire('click');
    assert.equal(f.document.activeElement, get('reg-first-name'));
    get('reg-first-name').value = '  Maria  ';
    get('reg-last-name').value = '  Test  ';
    get('reg-email').value = 'synthetic@example.invalid';
    get('reg-password').value = 'Short1!'; get('reg-confirm-password').value = 'Short1!';
    get('agree-checkbox').checked = true;
    let event = await f.form.fire('submit');
    assert.equal(event.defaultPrevented, true); assert.equal(f.submit.disabled, false);
    assert.equal(f.document.activeElement, get('reg-password'));
    get('reg-password').value = 'Valid1!pass'; get('reg-confirm-password').value = 'Other1!pass';
    event = await f.form.fire('submit');
    assert.equal(event.defaultPrevented, true); assert.equal(f.document.activeElement, get('reg-confirm-password'));
    get('reg-confirm-password').value = 'Valid1!pass'; get('agree-checkbox').checked = false;
    event = await f.form.fire('submit');
    assert.equal(event.defaultPrevented, true); assert.equal(f.submit.disabled, false);
    await get('link-goto-terms').fire('click'); await get('btn-back-from-terms').fire('click');
    assert.equal(get('agree-checkbox').checked, false, 'returning from terms is not consent');
    assert.equal(get('reg-password').value, 'Valid1!pass');
    assert.equal(f.document.activeElement, get('link-goto-terms'));
    await get('link-goto-terms').fire('click'); await get('btn-agree-terms').fire('click');
    assert.equal(get('agree-checkbox').checked, true);
    await get('link-goto-login').fire('click'); await get('link-goto-register').fire('click');
    assert.equal(get('reg-first-name').value, 'Maria'); assert.equal(get('reg-password').value, 'Valid1!pass');
    get('reg-first-name').value = '  ';
    event = await f.form.fire('submit'); assert.equal(event.defaultPrevented, true);
    get('reg-first-name').value = 'Maria'; await get('reg-first-name').fire('input');
    event = await f.form.fire('submit');
    assert.equal(event.defaultPrevented, true); assert.equal(f.submit.disabled, true);
    assert.equal(f.form.attributes['aria-busy'], 'true');
    assert.equal(f.requests(), 2, 'availability check and registration POST only');
    assert.match(f.window.location.href, /auth.php\?verify_email=/);
    event = await f.form.fire('submit'); assert.equal(event.defaultPrevented, true, 'duplicate submit blocked');
    f.windowEvents.get('pageshow')({ persisted: true });
    assert.equal(f.submit.disabled, false); assert.equal(f.submit.innerHTML, 'CREATE ACCOUNT & VERIFY EMAIL');
    assert.equal(f.form.attributes['aria-busy'], undefined);
    await f.elements.get('synthetic-password-toggle').fire('click');
    assert.equal(get('reg-password').type, 'text');
    assert.equal(f.elements.get('synthetic-password-toggle').attributes['aria-pressed'], 'true');
    assert.equal(f.requests(), 2);
    console.log('PASS|single-screen async registration, CSRF email precheck, safe server redirect, validation, whitespace names, mismatch, consent, terms return, login return, repeat submit, BFCache, accessible password toggle');

    function fill(f) {
        for (const [id, value] of Object.entries({ 'reg-first-name':'Maria', 'reg-last-name':'Test', 'reg-email':'synthetic@example.invalid', 'reg-password':'Valid1!pass', 'reg-confirm-password':'Valid1!pass', 'reg-phone':'09123456789' })) f.elements.get(id).value=value;
        f.elements.get('agree-checkbox').checked=true;
    }
    for (const failure of ['unavailable', 'duplicate', 'offline', 'server-error']) {
        const f = fixture(); await f.elements.get('link-goto-register').fire('click'); fill(f);
        f.api(async url => {
            if (failure === 'offline') throw new Error('Synthetic offline');
            if (url.includes('check_registration_email')) return { ok:true,json:async()=>({state:failure==='unavailable'?'unavailable':'available'}) };
            return failure === 'duplicate' ? { status:409,ok:false,json:async()=>({state:'unavailable'}) } : {status:400,ok:false,json:async()=>({state:'error',message:'Synthetic validation error'})};
        });
        await f.form.fire('submit');
        assert.equal(f.submit.disabled,false); assert.equal(f.elements.get('reg-password').value,'Valid1!pass');
        assert.equal(f.elements.get('reg-first-name').value,'Maria'); assert.equal(f.elements.get('agree-checkbox').checked,true);
        assert.equal(f.window.location.href,'https://synthetic.invalid/auth.php');
    }
    const pending = fixture(); await pending.elements.get('link-goto-register').fire('click'); fill(pending);
    let finishCheck;
    pending.api(() => new Promise(resolve => {finishCheck=resolve;}));
    const submitting=pending.form.fire('submit'); await pending.form.fire('submit');
    assert.equal(pending.requests(),1,'repeat submit does not duplicate pending check');
    pending.elements.get('reg-email').value='changed@example.invalid'; await pending.elements.get('reg-email').fire('input');
    finishCheck({ok:true,json:async()=>({state:'available'})}); await submitting;
    assert.equal(pending.requests(),1,'stale eligibility result does not submit'); assert.equal(pending.submit.disabled,false);
    const phone = fixture('', true); await phone.elements.get('link-goto-register').fire('click'); fill(phone);
    phone.elements.get('reg-phone').value=''; await phone.form.fire('submit'); assert.equal(phone.requests(),0,'enabled phone signup keeps required mobile validation');
    phone.elements.get('reg-phone').value='09123456789'; await phone.form.fire('submit'); assert.equal(phone.requests(),2);
    console.log('PASS|unavailable/duplicate/offline/server failures preserve account fields; repeated/stale async checks do not submit; optional phone signup stays required');

    const v = fixture('?verify_email=synthetic%40example.invalid&destination=booking_resume');
    const vg = id => v.elements.get(id);
    assert.equal(vg('verification-modal').style.display, 'flex');
    assert.equal(v.document.activeElement, vg('verification-code'));
    assert.equal(vg('verify-email-input').value, 'synthetic@example.invalid');
    vg('resend-code-btn').focus();
    event = await vg('verification-modal').fire('keydown', { key: 'Tab' });
    assert.equal(event.defaultPrevented, true); assert.equal(v.document.activeElement, vg('btn-close-verification'));
    event = await vg('verification-modal').fire('keydown', { key: 'Tab', shiftKey: true });
    assert.equal(event.defaultPrevented, true); assert.equal(v.document.activeElement, vg('resend-code-btn'));
    v.result({ ok: false, json: async () => ({ success: false, message: 'Try again later.' }) });
    await vg('resend-code-btn').fire('click');
    assert.equal(vg('resend-code-btn').disabled, false); assert.equal(vg('resend-code-status').textContent, 'Try again later.');
    v.result({ ok: true, json: async () => ({ success: true }) });
    await vg('resend-code-btn').fire('click'); await vg('resend-code-btn').fire('click');
    assert.equal(v.requests(), 2); assert.equal(vg('resend-code-btn').disabled, true);
    for (let i = 0; i < 60; i++) for (const handler of v.timers.values()) handler();
    assert.equal(vg('resend-code-btn').disabled, false); assert.equal(v.timers.size, 0);
    await vg('verification-modal').fire('keydown', { key: 'Escape' });
    assert.equal(vg('verification-modal').style.display, 'none');
    assert.equal(v.window.replacedUrl, '/auth.php?destination=booking_resume');
    assert.equal(vg('login-email').value, 'synthetic@example.invalid');
    console.log('PASS|verification focus trap, Escape, interrupted destination retained, retryable resend failure, duplicate resend and cooldown (mocked only)');
})().catch(error => { console.error(error); process.exitCode = 1; });
