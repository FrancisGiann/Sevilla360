<?php
$required_role='customer';require __DIR__.'/includes/auth_guard.php';
$attempt=(string)($_GET['attempt']??'');
if(!preg_match('/\A[a-f0-9]{32}\z/D',$attempt)){http_response_code(400);exit('Invalid attempt.');}
require_once __DIR__.'/config/db_connect.php';
$q=$conn->prepare('SELECT a.amount_cents,b.reference_no FROM pm_attempts a JOIN bookings b ON b.id=a.booking_id JOIN customers c ON c.id=b.customer_id WHERE a.id=? AND c.user_id=?');
$q->bind_param('si',$attempt,$_SESSION['user_id']);$q->execute();$checkout=$q->get_result()->fetch_assoc();
if(!$checkout){http_response_code(403);exit('Checkout unavailable.');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Local test checkout · Sevilla360</title></head><body>
<main><h1>Sevilla360 checkout</h1><p>Local simulation of hosted checkout. This preview does not contact PayMongo.</p>
<p>Booking <?= htmlspecialchars($checkout['reference_no'],ENT_QUOTES) ?> · Test amount: PHP <?= number_format((int)$checkout['amount_cents']/100,2) ?></p>
<p id="status" role="status">Complete the simulated payment to verify your booking automatically.</p>
<button id="pay">Complete simulated payment</button><button id="cancel">Cancel checkout</button><p><a href="/user_dashboard.php">Return to dashboard</a></p></main>
<script>
const attempt=<?= json_encode($attempt) ?>,csrf=<?= json_encode($_SESSION['csrf_token']) ?>;
async function send(path){const response=await fetch(path,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({attempt})});const data=await response.json();if(!response.ok)throw new Error(data.error||'Request failed');return data;}
async function act(cancel){document.querySelectorAll('button').forEach(b=>b.disabled=true);try{if(cancel)await send('/actions/user/close_paymongo_checkout.php');else{await send('/test-settle');await send('/actions/user/sync_payment.php');}location.assign('/user_dashboard.php');}catch(e){document.getElementById('status').textContent=e.message;document.querySelectorAll('button').forEach(b=>b.disabled=false);}}
document.getElementById('pay').onclick=()=>act(false);document.getElementById('cancel').onclick=()=>act(true);
</script></body></html>
