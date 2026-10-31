const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(path.join(process.env.WPHM_NODE_MODULES, 'playwright'));
const root = path.resolve(__dirname, '..');
let checks = 0, steps = 0, active = false;
const same = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
const raw = JSON.parse(fs.readFileSync(path.join(root, '.wphm-test/site-data-result.json'), 'utf8'));
const calls = [];
const files = new Map([['/admin.css', 'assets/css/admin-modern.css'], ['/site.css', 'assets/css/site-urls.css'], ['/site.js', 'assets/js/site-urls.js']]);
const server = http.createServer(async (request, response) => {
  if (request.url === '/ajax') {
    let body = ''; for await (const part of request) body += part;
    const input = new URLSearchParams(body); calls.push(Object.fromEntries(input));
    const op = input.get('op');
    if (op === 'start') { active = true; steps = 0; }
    if (op === 'step') { await new Promise(resolve => setTimeout(resolve, 100)); steps++; }
    const result = structuredClone(raw);
    result.state.phase = active && steps < 4 ? 'crawl' : 'done';
    result.state.run = 'fixture-run'; result.state.fetched = steps;
    const query = (input.get('q') || '').toLowerCase();
    result.result.rows = result.result.rows.filter(row => `${row.url} ${row.source_url}`.toLowerCase().includes(query));
    result.result.total = result.result.rows.length; result.result.page = 1; result.result.pages = 1;
    response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({ success: true, data: result })); return;
  }
  if (files.has(request.url)) {
    response.setHeader('Content-Type', request.url.endsWith('.js') ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8');
    response.end(fs.readFileSync(path.join(root, files.get(request.url)))); return;
  }
  const post = request.url === '/posts';
  const html = fs.readFileSync(path.join(root, `.wphm-test/site-data-${post ? 'post' : 'urls'}.html`), 'utf8');
  response.setHeader('Content-Type', 'text/html; charset=utf-8');
  response.end('<!doctype html><html lang="ja"><head><meta charset="utf-8"><link rel="stylesheet" href="/admin.css"><link rel="stylesheet" href="/site.css"></head><body class="wp-admin wphm-suite-admin wphm-fontsize-3">' + html + (post ? '' : '<script>window.wphmSiteUrls={url:"/ajax",nonce:"fixture-nonce"}</script><script src="/site.js"></script>') + '</body></html>');
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ executablePath: process.env.WPHM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } }); const errors = [];
    page.on('pageerror', error => errors.push(error.message)); page.on('dialog', dialog => dialog.accept());
    const base = `http://127.0.0.1:${server.address().port}`;
    await page.goto(base + '/posts');
    const headers = await page.locator('table thead th').allTextContents();
    const thumb = headers.findIndex(text => text.includes('アイキャッチ'));
    same(headers[thumb + 1].includes('記事内画像数'), true, 'image count next to thumbnail');
    same(headers[thumb + 2].includes('記事内リンク数'), true, 'link count follows image count');
    same(await page.locator('input[type="checkbox"][value="image_count"]').isChecked(), true, 'image column selected by default');
    same(await page.locator('input[type="checkbox"][value="link_count"]').isChecked(), true, 'link column selected by default');
    same((await page.locator('th.column-image_count a').getAttribute('href')).includes('orderby=image_count'), true, 'image sorting link');
    same((await page.getByRole('link', { name: 'Excelダウンロード（CSV）' }).getAttribute('href')).includes('image_count'), true, 'CSV includes image count');
    await page.screenshot({ path: path.join(root, '.wphm-test/site-data-post.png'), fullPage: true });
    await page.goto(base + '/');
    same(await page.locator('#wphm-su-start').isEnabled(), true, 'start button available');
    same(await page.locator('#wphm-su-rows').textContent().then(text => text.includes('unusual/path/')), true, 'unknown URL shown in native markup');
    await page.locator('#wphm-su-search').fill('only-by-crawling');
    await page.locator('#wphm-su-search-form button').click();
    await page.waitForFunction(() => document.querySelector('#wphm-su-status').textContent.includes('表示対象 1件'));
    same(await page.locator('#wphm-su-rows tr').count(), 1, 'search narrows rows');
    same(new URL(await page.locator('#wphm-su-csv').getAttribute('href')).searchParams.get('q'), 'only-by-crawling', 'CSV follows search');
    await page.locator('#wphm-su-search').fill(''); await page.locator('#wphm-su-start').click();
    await page.waitForFunction(() => !document.querySelector('#wphm-su-stop').disabled);
    await page.locator('#wphm-su-stop').click();
    await page.waitForFunction(() => !document.querySelector('#wphm-su-resume').disabled);
    same(await page.locator('#wphm-su-start').isEnabled(), true, 'paused scan allows controls');
    await page.locator('#wphm-su-resume').click();
    await page.waitForFunction(() => document.querySelector('#wphm-su-status').textContent.includes('収集完了'));
    same(steps, 4, 'resume completes remaining batches');
    same(await page.locator('#wphm-su-resume').isDisabled(), true, 'completed scan cannot resume');
    same(calls.every(call => call.nonce === 'fixture-nonce' && call.action === 'wphm_site_urls'), true, 'requests carry action and nonce');
    same(calls.some(call => call.op === 'step' && call.run === 'fixture-run'), true, 'batches use current run token');
    await page.screenshot({ path: path.join(root, '.wphm-test/site-data-urls.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    same(await page.locator('#wphm-su-start').isVisible(), true, 'mobile controls remain visible');
    same(await page.locator('.wphm-su-table-wrap').evaluate(node => node.scrollWidth > node.clientWidth), true, 'wide URL table scrolls on mobile');
    same(errors, [], 'no browser runtime errors');
    console.log(`PASS: ${checks} browser post-data and URL-inventory assertions.`);
  } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
