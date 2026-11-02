const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(path.join(process.env.WPHM_NODE_MODULES, 'playwright'));
const root = path.resolve(__dirname, '..');
let checks = 0, failure = '', requests = [], scanMode = '';
const same = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
const files = new Map([['/admin.css', 'assets/css/admin-modern.css'], ['/security.css', 'assets/css/security-check.css'], ['/security.js', 'assets/js/security-check.js']]);
const server = http.createServer(async (request, response) => {
  if (request.url === '/empty') { response.end('<!doctype html><title>Left check page</title>'); return; }
  if (request.url === '/ajax') {
    let body = ''; for await (const part of request) body += part;
    const parameters=Object.fromEntries(new URLSearchParams(body)); requests.push(parameters);
    await new Promise(resolve => setTimeout(resolve, scanMode==='inflight' && parameters.operation==='continue' ? 800 : 200));
    if (failure === 'json') { response.end('<html>not JSON</html>'); return; }
    response.setHeader('Content-Type', 'application/json');
    if (failure) { response.statusCode = 403; response.end(JSON.stringify({ success: false, data: { message: '権限がないか、nonceが失効しています。' } })); return; }
    const result=JSON.parse(fs.readFileSync(path.join(root, '.wphm-test/security-check-ajax.json'),'utf8'));
    if(scanMode && parameters.operation==='recheck') result.data.scan={run:'a'.repeat(32),pending:5,auto:true,stopped:false};
    if(scanMode==='malformed') result.data.scan={run:'javascript:bad',pending:5,auto:true,stopped:false};
    response.end(JSON.stringify(result)); return;
  }
  if (files.has(request.url)) {
    response.setHeader('Content-Type', request.url.endsWith('.js') ? 'application/javascript' : 'text/css');
    response.end(fs.readFileSync(path.join(root, files.get(request.url)))); return;
  }
  const html = fs.readFileSync(path.join(root, '.wphm-test/security-check-page.html'), 'utf8');
  response.setHeader('Content-Type', 'text/html; charset=utf-8');
  response.end('<!doctype html><html lang="ja"><head><meta charset="utf-8"><link rel="stylesheet" href="/admin.css"><link rel="stylesheet" href="/security.css"></head><body class="wp-admin wphm-suite-admin wphm-fontsize-3"><p>画面検証用の固定データ</p>' + html + '<script>window.wphmSecurityCheck={url:"/ajax"}</script><script src="/security.js"></script></body></html>');
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ executablePath: process.env.WPHM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    same(await page.getByRole('heading', { name: 'セキュリティチェック', exact: true }).count(), 1, 'shared page title');
    same(await page.locator('.wphm-sc-results>li').count(), 10, 'ten attack categories');
    same(await page.locator('.wphm-sc-summary>div').count(), 4, 'four status totals');
    same(await page.locator('.wphm-sc-item p strong').count(), 20, 'state and reason for every category');
    same(await page.locator('.wphm-sc-item .wphm-sc-scope').count(), 10, 'scope of every result visible');
    same(await page.locator('.wphm-sc-checks').count(),5,'five focus items show individual check lists');
    same(await page.locator('.wphm-sc-coverage[aria-label]').count(),5,'check coverage separate from categorical risk');
    same(await page.locator('time[datetime]').count(), 1, 'check timestamp visible');
    same(requests.length, 0, 'page view does not initiate a recheck');
    same(await page.locator('.wphm-page-header').count(), 1, 'common plugin header');
    const widths = {};
    for (const level of ['low', 'medium', 'high']) {
      const bar = page.locator(`.wphm-sc-item.wphm-sc-${level} .wphm-sc-bar`).first();
      same(await bar.getAttribute('role'), 'meter', 'known level has accessible meter');
      same(await bar.getAttribute('aria-valuemin'), '1', 'three-level categorical range');
      widths[level] = await bar.evaluate(node => node.firstElementChild.getBoundingClientRect().width / node.getBoundingClientRect().width);
    }
    same(widths.low < widths.medium && widths.medium < widths.high, true, 'bars represent low/middle/high levels');
    same(await page.locator('.wphm-sc-item.wphm-sc-unknown [role="meter"]').count(), 0, 'unknown never shown as a zero-risk meter');
    same(await page.locator('.wphm-sc-item.wphm-sc-unknown [role="img"]').count() > 0, true, 'unknown explicitly labeled');
    same(await page.locator('.wphm-sc-item.wphm-sc-unknown .wphm-sc-bar>span').first().evaluate(node=>node.getBoundingClientRect().width>100),true,'unknown graph has a visible full-width bar');
    same(await page.locator('.wphm-sc-item.wphm-sc-unknown .wphm-sc-bar small').first().textContent(),'未確認','unknown state visibly printed inside bar');
    await page.screenshot({ path: path.join(root, '.wphm-test/security-check-desktop.png'), fullPage: true });
    await page.screenshot({ path: path.join(root, '.wphm-test/security-check-desktop-top.png') });
    const nonce = await page.locator('#wphm-sc-form input[name="nonce"]').inputValue();
    await page.locator('#wphm-sc-recheck').click();
    same(await page.locator('#wphm-sc-recheck').isDisabled(), true, 'recheck prevents double submission');
    same(await page.locator('#wphm-sc-report').getAttribute('aria-busy'), 'true', 'loading announced');
    await page.waitForFunction(() => document.querySelector('#wphm-sc-status').textContent.includes('再チェックが完了'));
    same(await page.locator('#wphm-sc-recheck').isEnabled(), true, 'button restored after response');
    same(await page.locator('#wphm-sc-report').getAttribute('aria-busy'), 'false', 'busy state cleared');
    same(requests[0].nonce, nonce, 'nonce carried with request');
    same(requests[0].action, 'wphm_security_check', 'protected endpoint used');
    same(await page.locator('.wphm-sc-results>li').count(), 10, 'all results updated');
    same(await page.locator('#wphm-sc-error').textContent(), '', 'no stale error after success');
    const previous = await page.locator('#wphm-sc-report').innerHTML();
    for (const mode of ['permission', 'json']) {
      failure = mode; await page.locator('#wphm-sc-recheck').click();
      await page.waitForFunction(() => document.querySelector('#wphm-sc-status').textContent.includes('失敗'));
      same(await page.locator('#wphm-sc-report').innerHTML(), previous, 'failed recheck retains previous result without claiming freshness');
      same(await page.locator('#wphm-sc-error').textContent().then(text => text.includes('前回の結果')), true, 'previous result disclosed on failure');
      same(await page.locator('#wphm-sc-recheck').isEnabled(), true, 'retry available after failure');
    }
    failure = ''; await page.locator('#wphm-sc-recheck').click();
    await page.waitForFunction(() => document.querySelector('#wphm-sc-status').textContent.includes('再チェックが完了'));
    await page.evaluate(() => { window.fixtureSetTimeout=window.setTimeout; window.setTimeout=(callback,ms,...args)=>window.fixtureSetTimeout(callback,ms===35000?25:ms,...args); });
    const beforeTimeout=await page.locator('#wphm-sc-report').innerHTML();
    await page.locator('#wphm-sc-recheck').click();
    await page.waitForFunction(() => document.querySelector('#wphm-sc-error').textContent.includes('通信待ちの上限'));
    same(await page.locator('#wphm-sc-recheck').isEnabled(),true,'network deadline restores retry button');
    same(await page.locator('#wphm-sc-report').innerHTML(),beforeTimeout,'network deadline retains previous report');
    await page.evaluate(() => { window.setTimeout=window.fixtureSetTimeout; });
    await page.locator('#wphm-sc-recheck').click();
    await page.waitForFunction(() => document.querySelector('#wphm-sc-status').textContent.includes('再チェックが完了'));
    same(await page.locator('#wphm-sc-error').textContent(), '', 'successful retry clears error');
    await page.locator('#wphm-sc-report').evaluate(node => { node.replaceChildren(); });
    failure = 'permission'; await page.locator('#wphm-sc-recheck').click();
    await page.waitForFunction(() => document.querySelector('#wphm-sc-status').textContent.includes('失敗'));
    same(await page.locator('#wphm-sc-error').textContent().then(text => text.includes('結果を取得できていません')), true, 'empty initial report is not claimed as a previous result');
    failure = ''; await page.locator('#wphm-sc-recheck').click();
    await page.waitForFunction(() => document.querySelector('#wphm-sc-status').textContent.includes('再チェックが完了'));
    await page.setViewportSize({ width: 390, height: 844 });
    same(await page.locator('#wphm-sc-recheck').isVisible(), true, 'mobile recheck visible');
    same(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'no horizontal page overflow on mobile');
    await page.locator('.wphm-sc-evidence summary').click();
    same(await page.locator('.wphm-sc-evidence').getAttribute('open') !== null, true, 'observation information expandable');
    await page.locator('.wphm-sc-settings summary').click();
    same(await page.locator('input[name="cf_token"]').inputValue(),'','credential field always blank');
    same(await page.locator('select[name="uploads_execution"] option').count(),3,'server attestations include unknown');
    same(await page.locator('.wphm-sc-settings input[name="nonce"]').count(),1,'settings protected by native form nonce');
    same(await page.locator('.wphm-sc-settings input[type="number"]').count(),5,'bounded observation thresholds configurable');
    same(await page.evaluate(() => document.documentElement.scrollWidth<=window.innerWidth),true,'expanded settings fit mobile');
    await page.screenshot({ path: path.join(root, '.wphm-test/security-check-mobile.png'), fullPage: true });
    await page.evaluate(()=>window.scrollTo(0,0));
    await page.screenshot({ path: path.join(root, '.wphm-test/security-check-mobile-top.png') });
    await page.locator('.wphm-sc-settings summary').click();
    await page.locator('.wphm-sc-settings-link').first().click();
    same(await page.locator('.wphm-sc-settings').getAttribute('open')!==null,true,'per-item setting link opens the settings panel');
    await page.setViewportSize({width:1440,height:1100});
    let before=requests.length;
    scanMode='auto'; await page.locator('#wphm-sc-recheck').click();
    await page.waitForFunction(()=>document.querySelector('#wphm-sc-status').textContent.includes('再チェックが完了'));
    same(requests.length-before,2,'one click automatically continues remaining products');
    same(requests.at(-2).operation,'recheck','first request is explicit recheck');
    same(requests.at(-1).operation,'continue','next request is bounded continuation');
    same(requests.at(-1).run,'a'.repeat(32),'continuation carries authorized run token');
    same(requests.at(-1).nonce,nonce,'continuation retains nonce protection');
    same(await page.locator('#wphm-sc-pause').isHidden(),true,'pause control removed at completion');
    scanMode='pause'; before=requests.length; await page.locator('#wphm-sc-recheck').click();
    await page.locator('#wphm-sc-pause').waitFor({state:'visible'}); await page.locator('#wphm-sc-pause').click();
    await new Promise(resolve=>setTimeout(resolve,2800));
    same(requests.length-before,1,'pause cancels queued automatic continuation');
    same(await page.locator('#wphm-sc-resume').isVisible(),true,'paused run can resume');
    await page.locator('#wphm-sc-resume').click();
    await page.waitForFunction(()=>document.querySelector('#wphm-sc-status').textContent.includes('再チェックが完了'));
    same(requests.length-before,2,'resume continues without restarting completed work');
    const beforeMalformed=await page.locator('#wphm-sc-report').innerHTML();
    scanMode='malformed'; before=requests.length; await page.locator('#wphm-sc-recheck').click();
    await page.waitForFunction(()=>document.querySelector('#wphm-sc-error').textContent.includes('進行情報'));
    same(await page.locator('#wphm-sc-report').innerHTML(),beforeMalformed,'malformed scan metadata never replaces previous report');
    await new Promise(resolve=>setTimeout(resolve,2700));
    same(requests.length-before,1,'malformed scan never starts automatic requests');
    scanMode='auto'; before=requests.length; await page.locator('#wphm-sc-recheck').click();
    await page.locator('#wphm-sc-pause').waitFor({state:'visible'}); await page.goto(`http://127.0.0.1:${server.address().port}/empty`);
    await new Promise(resolve=>setTimeout(resolve,2800));
    same(requests.length-before,1,'leaving page stops scheduled continuation');
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    scanMode='inflight'; before=requests.length; await page.locator('#wphm-sc-recheck').click();
    const deadline=Date.now()+10000;
    while(requests.length-before<2 && Date.now()<deadline) await new Promise(resolve=>setTimeout(resolve,30));
    same(requests.length-before,2,'continuation request started before navigation');
    await page.goto(`http://127.0.0.1:${server.address().port}/empty`);
    await new Promise(resolve=>setTimeout(resolve,1100));
    same(requests.length-before,2,'in-flight response after page exit cannot schedule new work');
    same(errors, [], 'no browser runtime errors');
    console.log(`PASS: ${checks} security-check browser assertions.`);
  } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
