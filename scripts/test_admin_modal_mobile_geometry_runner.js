'use strict';

const fs = require('node:fs');
const { spawn } = require('node:child_process');

const [chrome, fixtureUrl, widthArg, heightArg, profile, mode = 'geometry'] = process.argv.slice(2);
const width = Number(widthArg);
const height = Number(heightArg);
const child = spawn(chrome, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--remote-debugging-port=0',
  `--user-data-dir=${profile}`, '--no-first-run', '--disable-background-networking', '--disable-sync',
  '--metrics-recording-only', '--disable-default-apps', '--disable-component-update', '--allow-file-access-from-files', 'about:blank'
], { stdio: 'ignore' });

function delay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

async function waitForDevToolsPort() {
  const portFile = `${profile}/DevToolsActivePort`;
  for (let attempt = 0; attempt < 100; attempt++) {
    if (child.exitCode !== null) throw new Error('Chrome exited before DevTools started.');
    try {
      const port = Number(fs.readFileSync(portFile, 'utf8').split(/\s+/)[0]);
      if (Number.isInteger(port) && port > 0) return port;
    } catch (_error) {}
    await delay(25);
  }
  throw new Error('Chrome DevTools did not start.');
}

async function run() {
  const port = await waitForDevToolsPort();
  let targets;
  for (let attempt = 0; attempt < 30; attempt++) {
    try {
      targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
      break;
    } catch (_error) { await delay(25); }
  }
  const target = targets?.find(item => item.type === 'page' && item.webSocketDebuggerUrl);
  if (!target) throw new Error('Chrome did not expose a page target.');

  const socket = new WebSocket(target.webSocketDebuggerUrl);
  const pending = new Map();
  let nextId = 1;
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (!message.id || !pending.has(message.id)) return;
    const request = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) request.reject(new Error(message.error.message || 'DevTools request failed.'));
    else request.resolve(message.result || {});
  });
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });
  const cdp = (method, params = {}) => new Promise((resolve, reject) => {
    const id = nextId++;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
  const evaluate = async expression => {
    const response = await cdp('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (response.exceptionDetails) throw new Error(response.exceptionDetails.text || 'Browser fixture evaluation failed.');
    return response.result?.value;
  };
  const waitFor = async predicate => {
    for (let attempt = 0; attempt < 100; attempt++) {
      if (await evaluate(predicate)) return true;
      await delay(25);
    }
    return false;
  };
  const clickSelector = async selector => {
    const rect = await evaluate(`(() => { const node=document.querySelector(${JSON.stringify(selector)}); if(!node) return null; node.scrollIntoView({block:'center', inline:'center'}); const r=node.getBoundingClientRect(); return {x:r.left+r.width/2,y:r.top+r.height/2}; })()`);
    if (!rect) throw new Error(`Missing clickable fixture element: ${selector}`);
    await cdp('Input.dispatchMouseEvent', { type: 'mouseMoved', x: rect.x, y: rect.y });
    await cdp('Input.dispatchMouseEvent', { type: 'mousePressed', x: rect.x, y: rect.y, button: 'left', clickCount: 1 });
    await cdp('Input.dispatchMouseEvent', { type: 'mouseReleased', x: rect.x, y: rect.y, button: 'left', clickCount: 1 });
  };
  const pressKey = async (key, code, keyCode) => {
    await cdp('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: keyCode, nativeVirtualKeyCode: keyCode });
    await cdp('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode, nativeVirtualKeyCode: keyCode });
  };
  try {
    await cdp('Page.enable');
    await cdp('Runtime.enable');
    await cdp('Emulation.setDeviceMetricsOverride', {
      width, height, deviceScaleFactor: 1, mobile: width <= 768,
      screenOrientation: { angle: 0, type: 'portraitPrimary' }
    });
    await cdp('Page.navigate', { url: fixtureUrl });
    if (mode === 'overview') {
      if (!(await waitFor('window.__overviewReady === true'))) {
        const diagnostic = await evaluate("JSON.stringify({ready:window.__overviewReady,error:window.__overviewError,readyState:document.readyState,tableRows:document.querySelectorAll('#recent-bookings-tbody tr').length})");
        throw new Error(`Overview fixture did not finish rendering: ${diagnostic}`);
      }
      await clickSelector('td[data-label="Venue"]');
      if (!(await waitFor("document.getElementById('overviewBookingModal')?.classList.contains('active') && document.getElementById('ov-vd-title')?.textContent === 'Booking QA-320'"))) throw new Error('Venue-cell tap did not open booking details.');
      const before = await evaluate("(() => { const modal=document.getElementById('overviewBookingModal'), body=document.getElementById('overviewBookingBodyScroll'), header=modal.querySelector('.overview-booking-header'), close=modal.querySelector('.overview-booking-header > .btn-icon-close'), title=modal.querySelector('#ov-vd-title'), badge=modal.querySelector('#ov-vd-status-badge'); body.scrollTop=0; const m=modal.getBoundingClientRect(), h=header.getBoundingClientRect(), c=close.getBoundingClientRect(), t=title.getBoundingClientRect(), b=badge.getBoundingClientRect(); return {viewport:innerWidth,scrollWidth:document.documentElement.scrollWidth,modal:{left:m.left,right:m.right,top:m.top,bottom:m.bottom,scrollTop:modal.scrollTop},header:{top:h.top,bottom:h.bottom},close:{left:c.left,right:c.right,top:c.top,bottom:c.bottom,width:c.width,height:c.height},title:{top:t.top,bottom:t.bottom},badge:{left:b.left,right:b.right},body:{overflow:getComputedStyle(body).overflowY,scrollHeight:body.scrollHeight,clientHeight:body.clientHeight,scrollTop:body.scrollTop}}; })()");
      await evaluate("document.getElementById('overviewBookingBodyScroll').scrollTop=document.getElementById('overviewBookingBodyScroll').scrollHeight");
      const after = await evaluate("(() => { const modal=document.getElementById('overviewBookingModal'), body=document.getElementById('overviewBookingBodyScroll'), header=modal.querySelector('.overview-booking-header'), close=modal.querySelector('.overview-booking-header > .btn-icon-close'); const h=header.getBoundingClientRect(), c=close.getBoundingClientRect(); return {headerTop:h.top,closeTop:c.top,bodyScrollTop:body.scrollTop,modalScrollTop:modal.scrollTop}; })()");
      const geometryPassed = before.scrollWidth <= width + 1 && before.modal.left >= -1 && before.modal.right <= width + 1
        && before.modal.top >= -1 && before.modal.bottom <= height + 1 && before.close.width >= 44 && before.close.height >= 44
        && before.title.top >= before.header.top - 1 && before.title.bottom <= before.header.bottom + 1
        && before.badge.left >= before.modal.left - 1 && before.badge.right <= before.modal.right + 1
        && before.body.overflow === 'auto' && before.body.scrollHeight > before.body.clientHeight
        && after.bodyScrollTop > 0 && after.modalScrollTop === 0
        && Math.abs(before.header.top - after.headerTop) <= 1 && Math.abs(before.close.top - after.closeTop) <= 1;
      const failures = [];
      if (!geometryPassed) failures.push('fixed header/body scroll geometry');

      const closeAndCheckFocus = async () => {
        await pressKey('Escape', 'Escape', 27);
        if (!(await waitFor("!document.getElementById('overviewBookingModal')?.classList.contains('active') && getComputedStyle(document.getElementById('overviewModalOverlay')).visibility === 'hidden'"))) throw new Error('Escape did not close overview booking details.');
        return await evaluate("document.activeElement === document.querySelector('.overview-table-action') && getComputedStyle(document.documentElement).overflow !== 'hidden'");
      };
      if (!(await closeAndCheckFocus())) failures.push('Escape close/focus restoration/background unlock');

      for (const selector of ['td[data-label="Date"]', 'td[data-label="Status"]']) {
        const previousCount = await evaluate('window.__detailFetchCount');
        await clickSelector(selector);
        if (!(await waitFor(`window.__detailFetchCount === ${previousCount + 1} && document.getElementById('overviewBookingModal').classList.contains('active')`))) throw new Error(`${selector} tap did not open exactly one booking detail request.`);
        if (!(await closeAndCheckFocus())) failures.push(`${selector} focus restoration/background unlock`);
      }

      const previousMouseCount = await evaluate('window.__detailFetchCount');
      await clickSelector('.overview-table-action');
      if (!(await waitFor(`window.__detailFetchCount === ${previousMouseCount + 1} && document.getElementById('overviewBookingModal').classList.contains('active')`))) throw new Error('Booking reference click did not open exactly once.');
      if (!(await closeAndCheckFocus())) failures.push('reference-button focus restoration');

      const keyboardReady = await evaluate("(() => { const button=document.querySelector('.overview-table-action'); button.focus(); return button.tagName === 'BUTTON' && button.type === 'button' && !button.disabled && button.tabIndex >= 0 && document.activeElement === button; })()");
      if (!keyboardReady) failures.push('native keyboard-focusable booking reference');

      await evaluate("(() => { const button=document.createElement('button'); button.id='nested-interactive'; button.type='button'; button.textContent='Nested action'; document.querySelector('td[data-label=\\\"Venue\\\"]').appendChild(button); })()");
      const previousNestedCount = await evaluate('window.__detailFetchCount');
      await clickSelector('#nested-interactive');
      await delay(100);
      const nestedStayedClosed = !(await evaluate("document.getElementById('overviewBookingModal').classList.contains('active')"))
        && (await evaluate('window.__detailFetchCount')) === previousNestedCount;
      if (!nestedStayedClosed) failures.push('nested action exclusion');

      const manageAllHref = await evaluate("document.querySelector('.overview-recent-bookings a.view-all-text')?.getAttribute('href')");
      if (manageAllHref !== 'admin_dashboard.php?page=bookings') failures.push('Manage All link');
      const result = { state: failures.length ? 'fail' : 'pass', width, geometry: { before, after }, failures, rowInteractions: { venue: true, date: true, status: true, referenceMouse: true, nativeKeyboardFocusable: keyboardReady, nestedExcluded: nestedStayedClosed, manageAllHref } };
      process.stdout.write(JSON.stringify(result) + '\n');
      if (result.state !== 'pass') process.exitCode = 1;
    } else {
      let result = null;
      for (let attempt = 0; attempt < 100; attempt++) {
        const evaluated = await cdp('Runtime.evaluate', {
          expression: "document.body?.dataset.geometry ? JSON.stringify({state:document.body.dataset.geometry,metrics:document.body.dataset.metrics}) : ''",
          returnByValue: true
        });
        const value = evaluated.result?.value;
        if (value) { result = JSON.parse(value); break; }
        await delay(25);
      }
      if (!result) throw new Error('Mobile geometry fixture did not finish measuring.');
      process.stdout.write(JSON.stringify({ state: result.state, metrics: JSON.parse(result.metrics) }) + '\n');
      if (result.state !== 'pass') process.exitCode = 1;
    }
  } finally {
    socket.close();
  }
}

run().catch(error => {
  process.stderr.write(`${error.message}\n`);
  process.exitCode = 1;
}).finally(() => {
  child.kill('SIGTERM');
});
