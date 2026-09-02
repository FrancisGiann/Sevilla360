/* Client-side password guidance. Server-side validation remains authoritative. */
window.SevillaPasswordPolicy = (() => {
  const message = 'Use 8–72 characters with a capital letter, lowercase letter, number, and symbol (like ! or @).';

  function byteLength(value) {
    if (window.TextEncoder) return new TextEncoder().encode(value).length;
    return encodeURIComponent(value).replace(/%[0-9A-F]{2}|./g, 'x').length;
  }

  function validate(value) {
    if (typeof value !== 'string' || value.includes('\0')) {
      return { valid: false, message };
    }

    const bytes = byteLength(value);
    const valid = bytes >= 8 && bytes <= 72
      && /[A-Z]/.test(value)
      && /[a-z]/.test(value)
      && /[0-9]/.test(value)
      && /[^A-Za-z0-9]/.test(value);
    return { valid, message: valid ? '' : message };
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-password-policy-input]').forEach(input => {
      input.form?.addEventListener('submit', event => {
        const result = validate(input.value);
        input.setCustomValidity(result.valid ? '' : result.message);
        if (!result.valid) {
          event.preventDefault();
          input.reportValidity();
        }
      });
    });
  });

  return { message, validate };
})();
