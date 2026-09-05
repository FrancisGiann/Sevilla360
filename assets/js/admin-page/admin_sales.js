document.addEventListener('DOMContentLoaded', () => {
  const money = (value) => `₱${Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
  const iso = (date) => [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
  // Report presets follow the API's Asia/Manila calendar even when an admin's
  // browser is running in another timezone.
  const manilaToday = () => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(new Date()).filter(({type}) => type !== 'literal').map(({type, value}) => [type, value]));
    return new Date(Number(parts.year), Number(parts.month) - 1, Number(parts.day));
  };
  const today = manilaToday();
  const state = {start: iso(new Date(today.getFullYear(), today.getMonth(), 1)), end: iso(today)};
  const custom = document.getElementById('sales-custom-range');
  const load = async () => {
    const query = new URLSearchParams({start_date: state.start, end_date: state.end});
    const response = await fetch(`actions/admin/get_sales_report.php?${query}`, {headers: {'X-Sevilla-Background': 'true'}});
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Sales report could not be loaded.');
    document.getElementById('sales-total').textContent = money(data.totals.total);
    document.getElementById('sales-count').textContent = data.totals.payment_count;
    document.getElementById('sales-average').textContent = money(data.totals.average);
    const rows = document.getElementById('sales-days'); rows.replaceChildren();
    const chart = document.getElementById('sales-chart'); chart.replaceChildren();
    const nonEmpty = data.days.filter((day) => day.payment_count > 0);
    document.getElementById('sales-empty').hidden = nonEmpty.length > 0;
    const max = Math.max(1, ...data.days.map((day) => Number(day.total)));
    data.days.forEach((day) => {
      const row = document.createElement('tr');
      [day.date, String(day.payment_count), money(day.total)].forEach((value) => { const cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell); });
      rows.appendChild(row);
      const bar = document.createElement('span'); bar.className = 'sales-bar'; bar.style.height = `${Math.max(3, (Number(day.total) / max) * 100)}%`; bar.title = `${day.date}: ${money(day.total)}`; chart.appendChild(bar);
    });
  };
  document.querySelectorAll('[data-sales-preset]').forEach((button) => button.addEventListener('click', () => {
    const mode = button.dataset.salesPreset; const now = manilaToday();
    if (mode === 'today') state.start = state.end = iso(now);
    else if (mode === 'last7') { const start = new Date(now); start.setDate(now.getDate() - 6); state.start = iso(start); state.end = iso(now); }
    else if (mode === 'month') { state.start = iso(new Date(now.getFullYear(), now.getMonth(), 1)); state.end = iso(now); }
    else { custom.hidden = false; document.getElementById('sales-start').value = state.start; document.getElementById('sales-end').value = state.end; return; }
    custom.hidden = true; document.querySelectorAll('[data-sales-preset]').forEach((item) => item.classList.toggle('active', item === button)); load().catch((error) => window.showAlert?.('Sales report unavailable', error.message, 'error'));
  }));
  custom?.addEventListener('submit', (event) => { event.preventDefault(); state.start = document.getElementById('sales-start').value; state.end = document.getElementById('sales-end').value; load().catch((error) => window.showAlert?.('Sales report unavailable', error.message, 'error')); });
  load().catch((error) => window.showAlert?.('Sales report unavailable', error.message, 'error'));
});
