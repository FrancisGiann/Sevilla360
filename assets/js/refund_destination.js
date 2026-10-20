(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.SevillaRefundDestination = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const controls = /[\x00-\x1f\x7f]/;
    const trimSpaces = value => value.replace(/^ +| +$/g, '');
    const methodValue = value => typeof value === 'string' && !controls.test(value) ? trimSpaces(value) : '';
    const allowed = (method, allowCash) => ['GCash', 'Maya', 'Bank Transfer'].includes(method) || (allowCash && method === 'Cash');

    function identifierError(method, value, allowCash = false) {
        method = methodValue(method);
        if (!allowed(method, allowCash)) return 'Choose a refund destination method.';
        if (method === 'Cash') return '';
        if (typeof value !== 'string' || controls.test(value) || trimSpaces(value) === '') {
            return 'Enter a valid wallet mobile number or bank account number.';
        }
        if (method === 'Bank Transfer') {
            const account = trimSpaces(value).replace(/ +/g, ' ');
            return account.length <= 64 && /^[A-Za-z0-9 -]+$/.test(account) && /[A-Za-z0-9]/.test(account)
                ? '' : 'Enter a bank account number using letters, numbers, spaces, or hyphens (up to 64 characters).';
        }
        const mobile = trimSpaces(value).replace(/[ -]+/g, '');
        return /^(?:09[0-9]{9}|9[0-9]{9}|639[0-9]{9}|\+639[0-9]{9})$/.test(mobile)
            ? '' : 'Enter a valid Philippine mobile number, such as 09XX XXX XXXX or +639XX XXX XXXX.';
    }

    function bind({method, identifier, identifierLabel, identifierHelp, bankField, bank, identifierField, allowCash = false}) {
        let previousMethod = methodValue(method.value);

        function sync() {
            const selected = methodValue(method.value);
            const isBank = selected === 'Bank Transfer';
            const isCash = allowCash && selected === 'Cash';
            if (selected !== previousMethod) {
                identifier.value = '';
                if (previousMethod === 'Bank Transfer') bank.value = '';
            }
            previousMethod = selected;
            bankField.hidden = !isBank;
            bank.disabled = !isBank;
            bank.required = isBank;
            if (identifierField) identifierField.hidden = isCash;
            identifier.disabled = isCash;
            identifier.required = allowed(selected, allowCash) && !isCash;
            identifier.inputMode = isBank ? 'text' : 'tel';
            identifier.placeholder = isBank ? 'As shown by your bank' : '09XX XXX XXXX';
            identifierLabel.textContent = !selected ? 'Refund account number' : isBank ? 'Bank account number'
                : selected === 'Maya' ? 'Maya Wallet registered mobile number' : 'GCash registered mobile number';
            if (identifierHelp) identifierHelp.textContent = !selected ? 'Choose a destination method first.' : isBank
                ? 'Use the account number shown by your bank. For Maya Savings, choose Maya Bank, Inc. as the bank.'
                : 'Use the mobile number registered to your wallet. Maya Wallet uses a mobile number; for Maya Savings, choose Bank Transfer and Maya Bank, Inc.';
            identifier.setCustomValidity('');
            bank.setCustomValidity('');
        }

        function validate() {
            const error = identifierError(method.value, identifier.value, allowCash);
            identifier.setCustomValidity(error);
            return error === '';
        }

        method.addEventListener('change', sync);
        identifier.addEventListener('input', validate);
        identifier.addEventListener('change', validate);
        sync();
        return {sync, validate};
    }

    return {bind, identifierError};
});
