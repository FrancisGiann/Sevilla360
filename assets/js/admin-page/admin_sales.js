(() => {
  const form = document.getElementById('sales-filter-form');
  const from = document.getElementById('sales-from');
  const to = document.getElementById('sales-to');
  const submit = document.getElementById('sales-apply-filters');
  if (!form || !from || !to || !submit) return;

  const validateRange = () => {
    const reversed = Boolean(from.value && to.value && from.value > to.value);
    from.setCustomValidity(reversed ? 'The start date must be on or before the end date.' : '');
    return !reversed;
  };

  from.addEventListener('input', validateRange);
  to.addEventListener('input', validateRange);
  form.addEventListener('submit', event => {
    if (!validateRange()) {
      event.preventDefault();
      from.reportValidity();
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Loading report…';
  });
})();
