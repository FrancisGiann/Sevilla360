const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/booking.js'), 'utf8');
function fixture() {
    const document = { body: { style: { overflow: 'clip' } }, addEventListener() {} };
    const handlers = {};
    const make = id => {
        const classes = new Set();
        return { id, isConnected: true, checked: false, disabled: false, hidden: false, attributes: {}, listeners: {}, dataset: {},
            classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) },
            setAttribute(n,v) { this.attributes[n] = v; },
            focus() { document.activeElement = this; },
            addEventListener(n,fn) { this.listeners[n] = fn; },
            click() { if (!this.disabled) this.listeners.click?.({ preventDefault() {}, currentTarget: this }); }
        };
    };
    const elements = Object.fromEntries(['tnc-modal','open-terms','btn-close-terms','btn-agree','terms-check','terms-content','terms-scroll-hint'].map(id => [id,make(id)]));
    elements['tnc-modal'].classList.add('modal-overlay');
    elements['tnc-modal'].querySelectorAll = () => [elements['btn-close-terms'], elements['terms-content'], elements['btn-agree']].filter(el => el.id === 'terms-content' || !el.disabled);
    elements['tnc-modal'].contains = el => [elements['btn-close-terms'], elements['terms-content'], elements['btn-agree']].includes(el);
    elements['terms-content'].tabIndex = 0;
    elements['terms-content'].scrollTop = 0;
    elements['terms-content'].clientHeight = 200;
    elements['terms-content'].scrollHeight = 600;
    document.getElementById = id => elements[id];
    const context = { document, setTimeout: fn => fn(), window: { SevillaPayMongoCheckout: { policyVersion: 'terms-v4-paymongo-actual-fee' }, addEventListener(n,fn) { handlers[n] = fn; } } };
    vm.createContext(context);
    vm.runInContext(source + '\nthis.Controller=BookingController;', context);
    const controller = Object.create(context.Controller.prototype);
    controller.state = { pendingDateConfirmation: null };
    controller.bindModalsAndSubmission();
    elements['open-terms'].focus();
    elements['open-terms'].click();
    const key = (key,shiftKey=false) => { const e={key,shiftKey,prevented:false,preventDefault(){this.prevented=true;}};handlers.keydown(e);return e; };
    const scrollToBottom = () => { elements['terms-content'].scrollTop = elements['terms-content'].scrollHeight - elements['terms-content'].clientHeight; elements['terms-content'].listeners.scroll(); };
    return {controller,document,elements,handlers,key,scrollToBottom,context};
}
test('opening keeps consent locked until the reader reaches the bottom',()=>{
    const f=fixture();
    assert.equal(f.elements['tnc-modal'].attributes['aria-hidden'],'false');
    assert.equal(f.document.activeElement,f.elements['btn-close-terms']);
    assert.equal(f.document.body.style.overflow,'hidden');
    assert.equal(f.elements['terms-check'].checked,false);
    assert.equal(f.elements['terms-check'].disabled,true);
    assert.equal(f.elements['btn-agree'].disabled,true);
    assert.match(f.elements['terms-scroll-hint'].textContent,/Scroll to the bottom/);
    f.scrollToBottom();
    assert.equal(f.elements['terms-check'].disabled,false);
    assert.equal(f.elements['btn-agree'].disabled,false);
    assert.equal(f.elements['terms-check'].checked,false,'reaching the bottom never accepts terms by itself');
});
test('Close, Escape and backdrop preserve consent and restore focus and scroll',()=>{
    for(const method of ['close','escape','backdrop']) for(const consent of [false,true]) {
        const f=fixture();f.elements['terms-check'].checked=consent;
        if(method==='close')f.elements['btn-close-terms'].click();
        else if(method==='escape')assert.equal(f.key('Escape').prevented,true);
        else f.handlers.click({target:f.elements['tnc-modal']});
        assert.equal(f.elements['terms-check'].checked,consent);
        assert.equal(f.elements['tnc-modal'].classList.contains('active'),false);
        assert.equal(f.elements['tnc-modal'].attributes['aria-hidden'],'true');
        assert.equal(f.document.activeElement,f.elements['open-terms']);
        assert.equal(f.document.body.style.overflow,'clip');
    }
});
test('the terms region is keyboard reachable and the dialog traps focus',()=>{
    const f=fixture();
    assert.equal(f.key('Tab',true).prevented,true);
    assert.equal(f.document.activeElement,f.elements['terms-content']);
    assert.equal(f.key('Tab').prevented,true);
    assert.equal(f.document.activeElement,f.elements['btn-close-terms']);
    f.scrollToBottom();
    f.elements['terms-content'].focus();
    assert.equal(f.key('Tab').prevented,false,'Tab can move from readable terms to I Agree');
    f.elements['btn-agree'].focus();
    assert.equal(f.key('Tab').prevented,true);
    assert.equal(f.document.activeElement,f.elements['btn-close-terms']);
    f.elements['open-terms'].focus();
    assert.equal(f.key('Tab').prevented,true);
    assert.equal(f.document.activeElement,f.elements['btn-close-terms']);
});
test('short non-overflowing terms unlock without auto-accepting',()=>{
    const f=fixture();f.controller.closeTermsDialog();
    f.elements['terms-content'].clientHeight=240;
    f.elements['terms-content'].scrollHeight=180;
    f.controller.openTermsDialog();
    assert.equal(f.elements['btn-agree'].disabled,false);
    assert.equal(f.elements['terms-check'].disabled,false);
    assert.equal(f.elements['terms-check'].checked,false);
});
test('only explicit I Agree accepts and repeated opening preserves same-version consent',()=>{
    const f=fixture();f.scrollToBottom();f.elements['btn-agree'].click();
    assert.equal(f.elements['terms-check'].checked,true);
    assert.equal(f.elements['terms-check'].dataset.policyVersion,'terms-v4-paymongo-actual-fee');
    assert.equal(f.document.body.style.overflow,'clip');
    assert.equal(f.key('Escape').prevented,false);
    f.controller.openTermsDialog();
    f.elements['btn-close-terms'].click();
    assert.equal(f.elements['terms-check'].checked,true,'closing without another I Agree keeps valid consent');
});
test('a policy version change clears old consent and requires the new terms to be read',()=>{
    const f=fixture();
    f.controller.closeTermsDialog();
    f.elements['terms-check'].checked=true;
    f.elements['terms-check'].dataset.policyVersion='terms-v3';
    f.context.window.SevillaPayMongoCheckout.policyVersion='terms-v5';
    f.controller.openTermsDialog();
    assert.equal(f.elements['terms-check'].checked,false);
    assert.equal(f.elements['terms-check'].disabled,true);
    assert.equal(f.elements['btn-agree'].disabled,true);
});
