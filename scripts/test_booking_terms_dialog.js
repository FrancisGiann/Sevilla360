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
        return { id, isConnected: true, checked: false, attributes: {}, listeners: {},
            classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) },
            setAttribute(n,v) { this.attributes[n] = v; },
            focus() { document.activeElement = this; },
            addEventListener(n,fn) { this.listeners[n] = fn; },
            click() { this.listeners.click({ preventDefault() {} }); }
        };
    };
    const elements = Object.fromEntries(['tnc-modal','open-terms','btn-close-terms','btn-agree','terms-check'].map(id => [id,make(id)]));
    elements['tnc-modal'].classList.add('modal-overlay');
    elements['tnc-modal'].querySelectorAll = () => [elements['btn-close-terms'], elements['btn-agree']];
    elements['tnc-modal'].contains = el => [elements['btn-close-terms'], elements['btn-agree']].includes(el);
    document.getElementById = id => elements[id];
    const context = { document, setTimeout: fn => fn(), window: { addEventListener(n,fn) { handlers[n] = fn; } } };
    vm.createContext(context);
    vm.runInContext(source + '\nthis.Controller=BookingController;', context);
    const controller = Object.create(context.Controller.prototype);
    controller.state = { pendingDateConfirmation: null };
    controller.bindModalsAndSubmission();
    elements['open-terms'].focus();
    elements['open-terms'].click();
    const key = (key,shiftKey=false) => { const e={key,shiftKey,prevented:false,preventDefault(){this.prevented=true;}};handlers.keydown(e);return e; };
    return {controller,document,elements,handlers,key};
}
test('opening labels an active dialog, locks background scroll and focuses Close',()=>{
    const f=fixture();
    assert.equal(f.elements['tnc-modal'].attributes['aria-hidden'],'false');
    assert.equal(f.document.activeElement,f.elements['btn-close-terms']);
    assert.equal(f.document.body.style.overflow,'hidden');
    assert.equal(f.elements['terms-check'].checked,false);
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
test('Tab wraps in both directions and recovers stray background focus',()=>{
    const f=fixture();
    assert.equal(f.key('Tab',true).prevented,true);
    assert.equal(f.document.activeElement,f.elements['btn-agree']);
    assert.equal(f.key('Tab').prevented,true);
    assert.equal(f.document.activeElement,f.elements['btn-close-terms']);
    f.elements['open-terms'].focus();
    assert.equal(f.key('Tab').prevented,true);
    assert.equal(f.document.activeElement,f.elements['btn-close-terms']);
});
test('only I Agree checks consent; repeated opening preserves scroll state',()=>{
    const f=fixture();f.controller.openTermsDialog();f.elements['btn-agree'].click();
    assert.equal(f.elements['terms-check'].checked,true);
    assert.equal(f.document.body.style.overflow,'clip');
    assert.equal(f.key('Escape').prevented,false);
});
