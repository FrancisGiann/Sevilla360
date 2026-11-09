'use strict';
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const money = cents => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
const el = (tag, text, cls) => { const n = document.createElement(tag); if (text) n.textContent = text; if (cls) n.className = cls; return n; };
let busy = false;
function button(label, action, payload = {}, secondary = false) {
  const n = el('button', label, secondary ? 'secondary' : ''); n.type = 'button';
  n.addEventListener('click', () => run(action, payload)); return n;
}
async function run(action, payload) {
  if (busy) return; busy = true;
  document.querySelectorAll('button').forEach(b => b.disabled = true);
  const message = document.getElementById('message'); message.textContent = 'Processing…';
  try {
    if(action==='checkout' && !payload.interrupt) {
      let failure;
      const client = window.SevillaPayMongoCheckout.create({csrfToken:csrf,bindButtons:false,onReady:data=>{
        message.textContent=data.message;
        if(data.checkout_url){ const link=el('a','Continue to hosted test checkout');link.href=data.checkout_url;message.append(document.createTextNode(' '),link); }
      },onError:error=>{failure=error;}});
      await client.openBooking(payload.booking);
      if(failure)throw failure;
      return;
    }
    const response = await fetch('/api', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ action, ...payload }) });
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Request failed.');
    message.textContent = result.message === 'applied' ? 'Payment verified automatically. Your reservation is confirmed.' : result.message;
  } catch (error) { message.textContent = error.message + ' Use Recover if the outcome is uncertain.'; }
  finally { busy = false; await refresh(); document.querySelectorAll('button').forEach(b => b.disabled = false); }
}
async function refresh() {
  try {
    const response = await fetch('/state'); const state = await response.json(); if (!response.ok) throw new Error(state.error);
    const bookings = document.getElementById('bookings'); bookings.replaceChildren();
    state.bookings.forEach(b => {
      const row = el('article', '', 'row'), info = el('div'), actions = el('div', '', 'actions');
      info.append(el('h3', `${b.name} · ${b.reference_no}`), el('p', `${b.start_date} to ${b.end_date}`), el('p', `${money(Number(b.total_amount) * 100)} total · ${money(Number(b.amount_paid) * 100)} applied · ${b.payment_scheme}`), el('p', `${b.booking_status === 'Awaiting Proof' ? 'Awaiting payment' : b.booking_status} / ${b.payment_status}`, 'status'));
      if (b.id === 4) info.append(el('p', 'Inquiry only. An accepted quote is required before payment.'));
      if (b.payment_status !== 'Paid') actions.append(button('Open checkout', 'checkout', { booking: b.id }));
      row.append(info, actions); bookings.append(row);
    });
    const attempts = document.getElementById('attempts'); attempts.replaceChildren();
    if (!state.attempts.length) attempts.append(el('p', 'No checkout started yet.'));
    state.attempts.forEach(a => {
      const row = el('article', '', 'row'), info = el('div'), actions = el('div', '', 'actions');
      info.append(el('h3', `Booking ${a.booking_id} · ${money(Number(a.amount_cents))}`), el('p', `Status: ${a.status} · Expires ${a.expires_at} (Manila)`), el('code', a.id));
      if (state.driver !== 'paymongo_test' && a.status === 'open') actions.append(button('Simulate payment', 'pay', { attempt: a.id }));
      actions.append(button('Recover / refresh', 'recover', { attempt: a.id }, true));
      row.append(info, actions);
      const lab = el('details'), labActions = el('div', '', 'actions'); lab.append(el('summary', 'Simulation controls'));
      labActions.append(button('Withhold webhook', 'delay', { attempt: a.id }, true), button('Expire 15-minute hold', 'expire', { attempt: a.id }, true), button('Deliver late payment', 'late', { attempt: a.id }, true));
      lab.append(labActions); if(state.driver !== 'paymongo_test') row.append(lab); attempts.append(row);
    });
    const receipts = document.getElementById('receipts'); receipts.replaceChildren();
    if (!state.receipts.length) receipts.append(el('p', 'No simulated payment received.'));
    state.receipts.forEach(r => {
      const row = el('div', '', 'row'); row.append(el('code', r.payment_id), el('strong', `${money(Number(r.amount_cents))} · ${r.disposition}`));
      if (r.disposition !== 'applied') row.append(el('p', 'Payment retained for resolution. No automatic refund was sent; do not pay again.'));
      receipts.append(row);
    });
  } catch (error) { document.getElementById('message').textContent = 'Could not refresh: ' + error.message; }
}
const returnedAttempt = new URLSearchParams(window.location.search).get('attempt');
if (returnedAttempt && /^[a-f0-9]{32}$/.test(returnedAttempt)) {
  // A browser return only triggers authoritative retrieval; it never supplies paid status.
  run('recover', {attempt: returnedAttempt});
} else refresh();
setInterval(() => { if (!busy) refresh(); }, 10000);
