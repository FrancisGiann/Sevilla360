'use strict';

/**
 * Browser DOM regression runner; does not take screenshots or contact services.
 * Generate fictional previews with:
 *   php scripts/test_resend_mailer.php --preview /existing/output/directory
 * In a blank browser page invoke testEmailMobileLayout({name: html, ...}) with
 * those generated files. Every iframe is sandboxed and blocks external loads.
 */
async function testEmailMobileLayout(previews) {
  const results = [];
  for (const [name, original] of Object.entries(previews)) {
    for (const width of [320, 360, 390, 600, 601, 1024]) {
      for (const stripped of [false, true]) {
        const frame = document.createElement('iframe');
        frame.style.cssText = `width:${width}px;height:2400px;border:0;display:block;`;
        frame.sandbox = 'allow-same-origin';
        const html = stripped ? original.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '') : original;
        const blocked = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">';
        const loaded = new Promise(resolve => { frame.onload = resolve; });
        frame.srcdoc = html.replace('<head>', '<head>' + blocked);
        document.body.append(frame);
        await loaded;
        const doc = frame.contentDocument;
        const win = frame.contentWindow;
        const failures = [];
        const rect = el => el.getBoundingClientRect();
        if (doc.documentElement.scrollWidth > width + 1) failures.push('document overflow');
        for (const el of doc.querySelectorAll('td, a, h1, h2, p')) {
          const box = rect(el);
          if (box.left < -1 || box.right > width + 1 || el.scrollWidth > el.clientWidth + 1) {
            failures.push('content overflow: ' + el.textContent.trim().slice(0, 60));
          }
        }
        let minValueWidth = null;
        for (const label of doc.querySelectorAll('.email-label')) {
          const value = label.nextElementSibling;
          const a = rect(label), b = rect(value);
          minValueWidth = Math.min(minValueWidth ?? b.width, b.width);
          if (!stripped && width <= 600) {
            if (b.top < a.bottom - 1 || Math.abs(a.left - b.left) > 1 || Math.abs(a.width - b.width) > 1) failures.push('mobile pair did not stack');
            if (b.width < rect(label.closest('table')).width - 1) failures.push('mobile value did not use full details width');
            if (win.getComputedStyle(value).textAlign !== 'left') failures.push('mobile value alignment');
          } else {
            if (b.left < a.right - 1 || Math.abs(a.top - b.top) > 1) failures.push('fallback/desktop pair lost columns');
            if (b.width < 140) failures.push('fallback value too narrow');
          }
        }
        if (name === 'inquiry' && !stripped) {
          for (const phrase of ['ET-4PKVTH', 'To Be Arranged']) {
            const value = [...doc.querySelectorAll('.email-value')].find(el => el.textContent.trim() === phrase);
            if (!value) { failures.push('missing inquiry text: ' + phrase); continue; }
            const range = doc.createRange(); range.selectNodeContents(value);
            if (range.getBoundingClientRect().height > 25) failures.push('short inquiry value wrapped: ' + phrase);
          }
        }
        results.push({ name, width, stripped, minValueWidth, failures });
        frame.remove();
      }
    }
  }
  const failed = results.filter(r => r.failures.length);
  return { passed: !failed.length, cases: results.length, failed, results };
}

if (typeof module !== 'undefined') module.exports = testEmailMobileLayout;
