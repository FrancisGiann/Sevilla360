/* Regression coverage for checkout policy consent and safe async handling. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/paymongo_checkout.js'), 'utf8');
const bookingSource = fs.readFileSync(path.join(root, 'assets/js/booking.js'), 'utf8');
const requests = [];
const makeContext = () => {
    const context = {
        document: {
            querySelector: () => ({ content: 'enabled' }),
            querySelectorAll: () => [],
            addEventListener() {}
        },
        window: { location: { origin: 'https://misevillas.com' }, addEventListener() {}, dispatchEvent() {} },
        URL,
        Set,
        Event,
        performance,
        setInterval() {},
        fetch: async (url, options) => {
            requests.push({ url, options });
            return {
                ok: true,
                status: 200,
                json: async () => ({ checkout_url: 'https://checkout.paymongo.com/test' })
            };
        }
    };
    vm.createContext(context);
    vm.runInContext(source, context);
    return context;
};

(async () => {
    assert.match(bookingSource, /requirePolicyConsent:\s*\(\)\s*=>\s*this\.getEl\('terms-check'\)\?\.checked === true/,
        'booking page passes its accepted terms checkbox to checkout');
    assert.match(bookingSource, /btn\.dataset\.policyVersion = window\.SevillaPayMongoCheckout\.policyVersion/,
        'booking page carries the accepted server-owned policy version into payment');

    const context = makeContext();
    let onReady = null;
    const accepted = context.window.SevillaPayMongoCheckout.create({
        bindButtons: false,
        requirePolicyConsent: () => true,
        onReady: data => { onReady = data; }
    });
    const button = { dataset: {} };
    await accepted.openBooking('481', button);
    assert.equal(requests.length, 1, 'accepted policy consent starts one checkout request');
    assert.equal(JSON.parse(requests[0].options.body).policy_consent, true,
        'accepted booking policy is propagated to the server');
    assert.equal(button.dataset.policyVersion, 'terms-v4-paymongo-actual-fee',
        'the button records the policy version after the server accepts the request');
    assert.equal(onReady.checkout_url, 'https://checkout.paymongo.com/test');

    const errors = [];
    const missingConsentHandler = context.window.SevillaPayMongoCheckout.create({
        bindButtons: false,
        onError: error => errors.push(error.message)
    });
    await assert.doesNotReject(missingConsentHandler.openBooking('482', { dataset: {} }));
    assert.match(errors[0], /Review the updated PayMongo refund terms/,
        'missing consent UI reports an in-system error instead of rejecting the click promise');
    assert.equal(requests.length, 1, 'missing consent UI never starts a payment request');

    const rejected = context.window.SevillaPayMongoCheckout.create({
        bindButtons: false,
        requirePolicyConsent: async () => { throw new Error('Policy dialog failed.'); },
        onError: error => errors.push(error.message)
    });
    await assert.doesNotReject(rejected.openBooking('483', { dataset: {} }));
    assert.equal(errors.at(-1), 'Policy dialog failed.', 'consent UI failures are caught and reported');
    assert.equal(requests.length, 1, 'failed consent UI never starts a payment request');

    const uncertainAttempt = 'a'.repeat(32);
    context.fetch = async () => ({
        ok: true,
        status: 200,
        json: async () => ({ attempt: uncertainAttempt, message: 'uncertain' })
    });
    let unresolved = null;
    const checking = context.window.SevillaPayMongoCheckout.create({
        bindButtons: false,
        onReady: data => { unresolved = data; }
    });
    await assert.doesNotReject(checking.openBooking('484', { dataset: { policyVersion: 'terms-v4-paymongo-actual-fee' } }));
    assert.equal(unresolved.attempt, uncertainAttempt, 'uncertain provider creation exposes the attempt for reconciliation');
    assert.equal(unresolved.checkout_url, undefined, 'uncertain creation never opens a guessed checkout destination');

    const asyncErrors = [];
    context.fetch = async () => { throw new Error('network unavailable'); };
    const networkFailure = context.window.SevillaPayMongoCheckout.create({
        bindButtons: false,
        onReady() {},
        onError: error => asyncErrors.push(error.message)
    });
    await assert.doesNotReject(networkFailure.openBooking('485', { dataset: { policyVersion: 'terms-v4-paymongo-actual-fee' } }));
    assert.deepEqual(asyncErrors, ['network unavailable'], 'network failures are handled once without an unhandled promise rejection');

    console.log('PASS|booking terms consent is propagated to PayMongo checkout');
    console.log('PASS|missing or failing consent UI cannot reject the click promise or start checkout');
    console.log('PASS|uncertain and network-failed checkout creation remain safely recoverable');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
