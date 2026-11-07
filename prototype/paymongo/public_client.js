'use strict';
let attempt=null,busy=false;
const csrf=document.querySelector('meta[name="csrf-token"]').content;
async function refresh(){
  const response=await fetch('?action=state');if(!response.ok)throw new Error('Demo state unavailable');
  const state=await response.json();attempt=state.attempt?.id||null;
  document.getElementById('state').textContent='FICTIONAL DEMO ONLY\nTest amount recorded: PHP '+state.booking.amount_paid+'\nTest payment state: '+state.booking.payment_status+'\nTest checkout state: '+(state.attempt?.status||'not started')+'\nNo real reservation has been made.';
}
async function act(action){
 if(busy)return;busy=true;document.querySelectorAll('button').forEach(b=>b.disabled=true);
 try{
  const response=await fetch('?action='+action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({attempt})});
  const data=await response.json();if(!response.ok)throw new Error(data.error||'Test action failed');
  document.getElementById('message').textContent=(data.message==='applied'?'Test payment verified. No real reservation.':data.message||'Test checkout ready.');
  document.getElementById('link').replaceChildren();
  if(data.checkout_url){const url=new URL(data.checkout_url);if(url.origin!=='https://checkout.paymongo.com'||url.username||url.password)throw new Error('Unexpected checkout destination');
   const link=document.createElement('a');link.href=url.href;link.textContent='Continue to PayMongo TEST checkout — no real reservation';link.rel='noreferrer';document.getElementById('link').append(link);}
  await refresh();
 }catch(e){document.getElementById('message').textContent=e.message;}finally{busy=false;document.querySelectorAll('button').forEach(b=>b.disabled=false);}
}
document.querySelectorAll('button').forEach(button=>button.addEventListener('click',()=>act(button.dataset.action)));
refresh().then(()=>{if(new URLSearchParams(location.search).has('attempt')&&attempt)act('recover');}).catch(()=>{document.getElementById('message').textContent='Demo unavailable. No real reservation.';});
