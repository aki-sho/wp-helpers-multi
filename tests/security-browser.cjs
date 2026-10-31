const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const modules = process.env.WPHM_NODE_MODULES;
const { chromium } = require(path.join(modules, 'playwright'));
const jsQR = require(path.join(modules, 'jsqr'));
const root = path.resolve(__dirname, '..');
const html = execFileSync('php', ['-n'], { cwd: root, input: '<?php define("ABSPATH", __DIR__); function current_user_can($cap) { return true; } function wphm_render_header($title) { echo "<h1>".$title."</h1>"; } require "tools/qr.php"; wphm_render_qr_tool_page();', encoding: 'utf8' });
let checks = 0;
const same = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
const files = new Map([['/vendor.js', 'assets/vendor/qrcodegen.js'], ['/qr.js', 'assets/js/qr.js'], ['/engine.js', 'assets/js/editor-search-engine.js']]);
const server = http.createServer((request, response) => {
  response.setHeader('Content-Type', request.url.endsWith('.js') ? 'application/javascript; charset=utf-8' : 'text/html; charset=utf-8');
  if (request.url === '/') response.end('<!doctype html><meta charset="utf-8">' + html + '<script src="/vendor.js"></script><script src="/qr.js"></script><script src="/engine.js"></script>');
  else if (files.has(request.url)) response.end(fs.readFileSync(path.join(root, files.get(request.url))));
  else { response.statusCode = 404; response.end(); }
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ executablePath: process.env.WPHM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  try {
    const page = await browser.newPage(); const requests = [], errors = [];
    page.on('request', request => requests.push(request.url())); page.on('pageerror', error => errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    const baseline = requests.length;
    for (const payload of ['https://example.org/?secret=fixture', 'WIFI:T:WPA;S:fixture;P:private-test-value;;', '日本語と絵文字😀']) {
      await page.locator('#wphm-qr-text').fill(payload); await page.locator('#wphm-qr-generate').click();
      await page.locator('#wphm-qr-result').waitFor({ state: 'visible' });
      const pixels = await page.locator('canvas').evaluate(canvas => ({ data: Array.from(canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data), width: canvas.width, height: canvas.height }));
      const decoded = jsQR(new Uint8ClampedArray(pixels.data), pixels.width, pixels.height);
      same(decoded && decoded.data, payload, 'QR decodes exact Unicode/private payload');
      same((await page.locator('#wphm-qr-download').getAttribute('href')).startsWith('blob:'), true, 'download uses local blob');
    }
    same(requests.length, baseline, 'QR generation has no network requests');
    await page.screenshot({ path: path.join(root, '.wphm-test/security-qr.png'), fullPage: true });
    await page.locator('#wphm-qr-clear').click();
    same(await page.locator('#wphm-qr-result').isHidden(), true, 'clear removes result');
    same(await page.locator('#wphm-qr-download').getAttribute('href'), null, 'clear removes download URL');
    await page.locator('#wphm-qr-text').fill('あ'.repeat(1000)); await page.locator('#wphm-qr-generate').click();
    same(await page.locator('#wphm-qr-message').textContent().then(text => text.includes('2,953')), true, 'UTF-8 payload length enforced');
    same(await page.locator('#wphm-qr-result').isHidden(), true, 'oversized QR has no stale result');
    const limits = await page.evaluate(() => {
      const e = window.WPHMEditorSearchEngine, result = [];
      const big = 'A'.repeat(40000), matches = [{ text: big, ranges: [[0, big.length]] }];
      for (const [replacement, regex] of [['$&'.repeat(100), true], ['X'.repeat(10000), false]]) {
        try { result.push(e.replaceHTML(big, matches, replacement, regex).length); } catch (error) { result.push(error.message.includes('200万')); }
      }
      const source = '<p>A&amp;B</p>', hit = e.scanHTML(source, 'A&B', true).matches;
      const text = '<&>\r\nline';
      const actual = e.replaceHTML(source, hit, text);
      let complex = false;
      const empty = Array.from({ length: 1000 }, (_, index) => ({ text: 'A', captures: [''], ranges: [[index, index + 1]] }));
      try { e.replaceHTML('A'.repeat(1000), empty, '$1'.repeat(5000), true); } catch (error) { complex = error.message.includes('複雑'); }
      const exactMatches = Array.from({ length: 5000 }, (_, index) => ({ text: 'A', ranges: [[index, index + 1]] }));
      const exact = e.replaceHTML('A'.repeat(5000), exactMatches, 'B'.repeat(400)).length;
      return { result, measured: e.measureReplacementHTML(source, hit, text), actual: actual.length, escaped: actual, complex, exact };
    });
    same(limits.result, [true, 10000], 'expansion rejected before generating a large result');
    same(limits.measured, limits.actual, 'measurement includes escaping/newlines');
    same(limits.escaped, '<p>&lt;&amp;&gt;<br>line</p>', 'escaping retained');
    same(limits.complex, true, 'empty capture expansion work is bounded');
    same(limits.exact, 2000000, 'exact output limit accepted');
    same(errors, [], 'no browser runtime errors');
    console.log(`PASS: ${checks} security browser assertions (QR decode/network and bounded replacement).`);
  } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
