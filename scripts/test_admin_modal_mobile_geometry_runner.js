'use strict';

const fs = require('node:fs');
const { spawn } = require('node:child_process');

const [chrome, fixtureUrl, widthArg, heightArg, profile] = process.argv.slice(2);
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

  try {
    await cdp('Page.enable');
    await cdp('Runtime.enable');
    await cdp('Emulation.setDeviceMetricsOverride', {
      width, height, deviceScaleFactor: 1, mobile: width <= 768,
      screenOrientation: { angle: 0, type: 'portraitPrimary' }
    });
    await cdp('Page.navigate', { url: fixtureUrl });
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
