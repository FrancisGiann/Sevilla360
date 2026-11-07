<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES) ?>"><meta name="sevilla-paymongo-local" content="enabled"><title>Sevilla360 · Local checkout</title><link rel="stylesheet" href="/app.css"><script src="/assets/js/paymongo_checkout.js" defer></script><script src="/app.js" defer></script></head>
<body><header><strong>Sevilla360</strong><span>Local payment prototype</span></header><main>
<h1>Complete your reservation</h1>
<p class="notice"><?php if($driver==='paymongo_test'): ?><strong>PayMongo test adapter.</strong> Fictional bookings only. Test requests require explicit process configuration; no live keys are accepted.<?php else: ?><strong>Simulation only.</strong> These are fictional bookings and payments. No money moves and no PayMongo connection is active.<?php endif; ?></p>
<p>Choose a booking to open a 15-minute checkout. Payment is verified automatically; there is no receipt upload or staff approval for a valid payment.</p>
<div id="message" role="status" aria-live="polite"></div>
<section aria-labelledby="bookings-heading"><h2 id="bookings-heading">Your sample bookings</h2><div id="bookings">Loading bookings…</div></section>
<section aria-labelledby="checkout-heading"><h2 id="checkout-heading">Checkout activity</h2><p>Returning or refreshing never proves payment. Recover checks the simulator’s payment record. A late payment is recorded for resolution without confirming your dates.</p><div id="attempts"></div></section>
<section aria-labelledby="receipts-heading"><h2 id="receipts-heading">Automatically reconciled payments</h2><div id="receipts"></div></section>
<p class="footnote">Fixture customer 1 · PHP · Test data is discarded when the local server stops. Close a checkout to release its hold; closing the browser leaves the server’s 15-minute expiry in place.</p>
</main></body></html>
