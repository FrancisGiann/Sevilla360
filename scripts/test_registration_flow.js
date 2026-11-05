/* Isolated interaction tests. No database, browser, accounts, or email delivery. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const markup = fs.readFileSync(path.join(root, 'auth.php'), 'utf8');
const script = fs.readFileSync(path.join(root, 'assets/js/auth.js'), 'utf8');
const policy = fs.readFileSync(path.join(root, 'assets/js/password_policy.js'), 'utf8');
assert.ok(!markup.includes('register-step-1') && !markup.includes('btn-next-step'), 'does not restore the old one-field wizard');
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
    let stages = [];
    let fetchResult = { ok: true, json: async () => ({ success: true }) };
    const document = {
        body: { dataset: { phoneSignup: phoneSignup ? '1' : '0' } },
        activeElement: null,
        addEventListener(name, handler) { const list = listeners.get(name) || []; list.push(handler); listeners.set(name, list); },
        getElementById(id) { return elements.get(id) || null; },
        querySelector() { return { getAttribute: () => 'synthetic-csrf' }; },
        querySelectorAll(selector) {
            if (selector === '[data-registration-stage]') return stages;
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
    stages = [1,2,3].map(stage => { const e = new Element('synthetic-stage-'+stage); e.dataset.registrationStage=String(stage); return e; });
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
        FormData: class {
            constructor(form) {
                this.form = form;
                const names = { 'reg-email':'email', 'reg-password':'password', 'reg-confirm-password':'confirm_password', 'reg-first-name':'first_name', 'reg-last-name':'last_name', 'reg-phone':'phone', 'agree-checkbox':'consent' };
                this.fields = new Map(fieldIds.map(id => elements.get(id)).filter(e => !e.disabled && (e.type !== 'checkbox' || e.checked)).map(e => [names[e.id], e.type === 'checkbox' ? '1' : e.value]));
                this.fields.set('csrf_token','synthetic-csrf');
            }
            get(name) { return this.fields.get(name); }
        },
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

function fillCredentials(f) {
    for (const [id,value] of Object.entries({'reg-email':'synthetic@example.invalid','reg-password':'Valid1!pass','reg-confirm-password':'Valid1!pass'})) f.elements.get(id).value=value;
}
function fillContact(f) {
    for (const [id,value] of Object.entries({'reg-first-name':' Maria ','reg-last-name':' Test ','reg-phone':'09123456789'})) f.elements.get(id).value=value;
    f.elements.get('agree-checkbox').checked=true;
}
async function open(f) { await f.elements.get('link-goto-register').fire('click'); }
async function advance(f) { await f.elements.get('registration-continue').fire('click'); }
(async () => {
    for (const phoneEnabled of [false,true]) {
        const f=fixture('',phoneEnabled); const get=id=>f.elements.get(id); await open(f);
        assert.equal(get('registration-account').hidden,false); assert.equal(get('registration-contact').hidden,true);
        assert.equal(f.form.noValidate,true,'JS owns step validation while input/server constraints remain');
        assert.equal(f.document.activeElement,get('registration-account-title'));
        fillCredentials(f); get('reg-password').value='Short1!';get('reg-confirm-password').value='Short1!';
        await advance(f); assert.equal(f.requests(),0); assert.equal(f.document.activeElement,get('reg-password'));
        fillCredentials(f); get('reg-confirm-password').value='Mismatch1!pass';
        await f.form.fire('submit'); assert.equal(f.requests(),0); assert.equal(f.document.activeElement,get('reg-confirm-password'));
        fillCredentials(f);
        await f.form.fire('submit');
        assert.equal(f.requests(),1,'Enter on Account only checks email, never registers');
        assert.equal(get('registration-account').hidden,true); assert.equal(get('registration-contact').hidden,false);
        assert.equal(f.document.activeElement,get('registration-contact-title'));
        assert.equal(get('reg-email').required,true); assert.equal(get('reg-email').disabled,false);
        fillContact(f); get('agree-checkbox').checked=false;
        await f.form.fire('submit'); assert.equal(f.requests(),1,'missing consent prevents final POST');
        await get('link-goto-terms').fire('click'); await get('btn-back-from-terms').fire('click');
        assert.equal(get('agree-checkbox').checked,false);assert.equal(get('registration-contact').hidden,false);
        assert.equal(f.document.activeElement,get('link-goto-terms'));
        await get('link-goto-login').fire('click'); await open(f);
        assert.equal(get('registration-contact').hidden,false,'login return keeps current group');
        assert.equal(get('reg-password').value,'Valid1!pass');
        await get('registration-back').fire('click');
        assert.equal(get('registration-account').hidden,false);assert.equal(get('registration-contact').hidden,true);
        await advance(f); assert.equal(f.requests(),2,'Continue rechecks email after Back');
        get('reg-first-name').value='  ';await f.form.fire('submit');assert.equal(f.requests(),2);
        get('reg-first-name').value='Maria';await get('reg-first-name').fire('input');
        get('agree-checkbox').checked=true;
        if(phoneEnabled){get('reg-phone').value='';await f.form.fire('submit');assert.equal(f.requests(),2);get('reg-phone').value='09123456789';}
        get('reg-password').value='invalid';await f.form.fire('submit');
        assert.equal(get('registration-account').hidden,false,'invalid hidden credential is revealed before focus');assert.equal(f.document.activeElement,get('reg-password'));
        fillCredentials(f); await advance(f); assert.equal(f.requests(),3);
        await f.form.fire('submit');assert.equal(f.requests(),4);assert.equal(f.submit.disabled,true);
        const post=f.requestLog.at(-1);assert.equal(post.url,f.form.action);assert.equal(post.options.body.get('email'),'synthetic@example.invalid');
        assert.equal(post.options.body.get('password'),'Valid1!pass');assert.equal(post.options.body.get('first_name'),'Maria');assert.equal(post.options.body.get('last_name'),'Test');assert.equal(post.options.body.get('consent'),'1');assert.equal(post.options.body.get('csrf_token'),'synthetic-csrf');
        if(phoneEnabled)assert.equal(post.options.body.get('phone'),'09123456789');
        await f.form.fire('submit');assert.equal(f.requests(),4,'repeat submit is blocked');
        assert.match(f.window.location.href,/auth.php\?verify_email=/);
        f.windowEvents.get('pageshow')({persisted:true});assert.equal(f.submit.disabled,false);assert.equal(get('registration-back').disabled,false);
    }
    console.log('PASS|Account credentials → Contact details → Verification; Enter/Continue/Back; hidden-field focus; required phone and consent; final FormData includes both groups and CSRF');

    for(const failure of ['unavailable','offline','duplicate','server-error']){
        const f=fixture();await open(f);fillCredentials(f);fillContact(f);
        f.api(async url=>{
            if(failure==='offline')throw Error('Synthetic offline');
            if(url.includes('check_registration_email'))return {ok:true,json:async()=>({state:failure==='unavailable'?'unavailable':'available'})};
            return failure==='duplicate'?{status:409,ok:false,json:async()=>({state:'unavailable'})}:{status:400,ok:false,json:async()=>({state:'error',message:'Synthetic error'})};
        });
        await advance(f);
        if(['duplicate','server-error'].includes(failure))await f.form.fire('submit');
        assert.equal(f.elements.get('reg-password').value,'Valid1!pass');assert.equal(f.elements.get('reg-first-name').value.trim(),'Maria');assert.equal(f.submit.disabled,false);
        assert.equal(f.window.location.href,'https://synthetic.invalid/auth.php');
        if(failure==='unavailable'||failure==='duplicate')assert.equal(f.elements.get('registration-account').hidden,false);
    }
    for(const navigation of ['email-edit','login']){
        const f=fixture();await open(f);fillCredentials(f);let finish;
        f.api(()=>new Promise(resolve=>{finish=resolve;}));
        const continuing=f.elements.get('registration-continue').fire('click');await f.form.fire('submit');assert.equal(f.requests(),1);
        if(navigation==='email-edit'){f.elements.get('reg-email').value='changed@example.invalid';await f.elements.get('reg-email').fire('input');}
        else await f.elements.get('link-goto-login').fire('click');
        finish({ok:true,json:async()=>({state:'available'})});await continuing;
        assert.equal(f.elements.get('registration-account').hidden,false);assert.equal(f.elements.get('registration-continue').disabled,false);assert.equal(f.requests(),1);
    }
    const submitting=fixture();await open(submitting);fillCredentials(submitting);fillContact(submitting);await advance(submitting);
    let finishPost;submitting.api(()=>new Promise(resolve=>{finishPost=resolve;}));
    const posting=submitting.form.fire('submit');await submitting.elements.get('registration-back').fire('click');await submitting.form.fire('submit');
    assert.equal(submitting.requests(),2);assert.equal(submitting.elements.get('registration-contact').hidden,false,'Back cannot interrupt an in-flight final POST');
    finishPost({status:409,ok:false,json:async()=>({state:'unavailable'})});await posting;
    assert.equal(submitting.elements.get('registration-account').hidden,false);assert.equal(submitting.elements.get('registration-back').disabled,false);
    console.log('PASS|early and final duplicate-email handling, failures retain input, stale/repeated Continue ignored, login interruption safe, final submit/Back guarded');

    const v=fixture('?verify_email=synthetic%40example.invalid&destination=booking_resume');const vg=id=>v.elements.get(id);
    assert.equal(vg('verification-modal').style.display,'flex');assert.equal(v.document.activeElement,vg('verification-code'));
    vg('resend-code-btn').focus();let event=await vg('verification-modal').fire('keydown',{key:'Tab'});assert.equal(event.defaultPrevented,true);assert.equal(v.document.activeElement,vg('btn-close-verification'));
    event=await vg('verification-modal').fire('keydown',{key:'Tab',shiftKey:true});assert.equal(event.defaultPrevented,true);assert.equal(v.document.activeElement,vg('resend-code-btn'));
    v.result({ok:false,json:async()=>({success:false,message:'Try again later.'})});await vg('resend-code-btn').fire('click');assert.equal(vg('resend-code-btn').disabled,false);
    v.result({ok:true,json:async()=>({success:true})});await vg('resend-code-btn').fire('click');await vg('resend-code-btn').fire('click');assert.equal(v.requests(),2);
    for(let i=0;i<60;i++)for(const handler of v.timers.values())handler();assert.equal(vg('resend-code-btn').disabled,false);
    const verifyForm=vg('form-verify');
    event=await verifyForm.fire('submit');assert.equal(event.defaultPrevented,false);assert.equal(v.submit.disabled,true);
    event=await verifyForm.fire('submit');assert.equal(event.defaultPrevented,true,'duplicate verification submission blocked');
    v.windowEvents.get('pageshow')({persisted:true});assert.equal(v.submit.disabled,false,'interrupted verification can be retried');
    await vg('verification-modal').fire('keydown',{key:'Escape'});assert.equal(v.window.replacedUrl,'/auth.php?destination=booking_resume');
    console.log('PASS|existing verification, resend cooldown, keyboard trap, Escape and interrupted-booking destination remain intact (all mocked)');
})().catch(error=>{console.error(error);process.exitCode=1;});
