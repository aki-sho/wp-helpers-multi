const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(path.join(process.env.WPHM_NODE_MODULES, 'playwright'));
const root = path.join(__dirname, '..');
const files = new Map([
  ['/engine.js', ['application/javascript', 'assets/js/editor-search-engine.js']],
  ['/adapters.js', ['application/javascript', 'assets/js/editor-search-adapters.js']],
  ['/ui.js', ['application/javascript', 'assets/js/editor-search.js']],
  ['/worker.js', ['application/javascript', 'assets/js/editor-search-worker.js']],
  ['/style.css', ['text/css', 'assets/css/editor-search.css']],
]);
const server = http.createServer((request, response) => {
  if (request.url === '/') {
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end('<!doctype html><html lang="ja"><head><link rel="stylesheet" href="/style.css"></head><body><textarea id="content" style="width:60%;height:500px"></textarea><script>window.wphmEditorSearch={blockEditor:false,shortcodes:["sample"],workerUrl:"/worker.js"}</script><script src="/engine.js"></script><script src="/adapters.js"></script><script src="/ui.js"></script></body></html>'); return;
  }
  const item = files.get(request.url);
  if (!item) { response.statusCode = 404; response.end('Not found'); return; }
  response.setHeader('Content-Type', item[0] + '; charset=utf-8'); response.end(fs.readFileSync(path.join(root, item[1])));
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ executablePath: process.env.WPHM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  let checks = 0;
  const same = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 900 } }); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    const cases = [
      ['requested number pattern', '<p>1. あ<br>12.\tい<br>3.う</p>', '\\d+\\.[ \\t]*', '', '<p>あ<br>い<br>う</p>', 3],
      ['multiline anchors', '<p>1. あ<br>12.い<br>x3.う</p>', '^\\d+\\.[ \\t]*', '', '<p>あ<br>い<br>x3.う</p>', 2],
      ['capture replacement', '<p>1. あ<br>12. い</p>', '(\\d+)\\.[ \\t]*', '番号$1：', '<p>番号1：あ<br>番号12：い</p>', 2],
      ['whole match and literal dollar', '<p>12.</p>', '(\\d+)\\.', '$$:$0:$&:$1:$10', '<p>$:12.:12.:12:120</p>', 1],
      ['named capture', '<p>12.</p>', '(?<number>\\d+)\\.', '$<number>件', '<p>12件</p>', 1],
      ['optional capture', '<p>a b</p>', '(a)|(b)', '$1-$2', '<p>a- -b</p>', 2],
      ['unknown capture retained', '<p>12.</p>', '\\d+\\.', '$1', '<p>$1</p>', 1],
      ['case folding', '<p>WP1 wp2</p>', 'wp\\d', 'X', '<p>X X</p>', 2],
      ['case sensitive', '<p>WP1 wp2</p>', 'wp\\d', 'X', '<p>WP1 X</p>', 1, true],
      ['entity and emoji', '<p>A&amp;B 😀</p>', 'A&B|😀', '<$&>', '<p>&lt;A&amp;B&gt; &lt;😀&gt;</p>', 2],
      ['attributes retained', '<p><a href="/1.">2. 名称</a></p>', '\\d+\\.[ \\t]*', '', '<p><a href="/1.">名称</a></p>', 1],
      ['inline formatting', '<p>1<strong>.</strong> 名称</p>', '\\d+\\.[ \\t]*', '', '<p><strong></strong>名称</p>', 1],
      ['shortcode and code protection', '<p>[sample]1. 名[/sample] 2. 外</p><pre>3. code</pre>', '\\d+\\.[ \\t]*', '', '<p>[sample]1. 名[/sample] 外</p><pre>3. code</pre>', 1],
    ];
    for (const [label, html, needle, replacement, expected, count, caseSensitive = false] of cases) {
      const result = await page.evaluate(async args => {
        const e = window.WPHMEditorSearchEngine, runs = e.htmlRuns(args.html, ['sample']);
        const worker = new Worker('/worker.js');
        const response = await new Promise((resolve, reject) => { worker.onmessage = event => { if (!event.data.ready) resolve(event.data); }; worker.onerror = reject; worker.postMessage({ sources: [runs.map(run => run.text)], needle: args.needle, caseSensitive: args.caseSensitive }); });
        worker.terminate(); if (response.error) throw new Error(response.error);
        const matches = e.attachRanges(runs, response.results[0]).matches;
        return { count: matches.length, html: e.replaceHTML(args.html, matches, args.replacement, true) };
      }, { html, needle, replacement, caseSensitive });
      same(result.count, count, label + ' count'); same(result.html, expected, label + ' replacement');
    }
    await page.locator('#content').fill('<p>1. あ<br>12.\tい<br>3.う</p>');
    await page.locator('.wphm-es-launch').click();
    const query = page.locator('textarea[name="wphm-editor-search"]');
    const regex = page.locator('input[name="wphm-editor-regex"]');
    const counter = async text => { await page.waitForFunction(text => document.querySelector('.wphm-es-count').textContent === text, text); checks++; };
    await query.fill('\\d+\\.[ \\t]*'); await counter('0 / 0件');
    await regex.check(); await counter('1 / 3件');
    await page.getByRole('button', { name: 'すべて置換', exact: true }).click(); await counter('0 / 0件');
    same(await page.locator('#content').inputValue(), '<p>あ<br>い<br>う</p>', 'real UI removes number prefixes');
    await page.locator('.wphm-es-actions').getByRole('button', { name: '元に戻す', exact: true }).click(); await counter('1 / 3件');
    same(await page.locator('#content').inputValue(), '<p>1. あ<br>12.\tい<br>3.う</p>', 'regex replacement participates in undo');
    await query.fill('['); await page.waitForFunction(() => document.querySelector('.wphm-es-message').textContent.includes('正規表現の書き方'));
    same(await page.getByRole('button', { name: 'すべて置換', exact: true }).isDisabled(), true, 'invalid expression cannot replace');
    await query.fill('^'); await page.waitForFunction(() => document.querySelector('.wphm-es-message').textContent.includes('空文字'));
    same(await page.getByRole('button', { name: 'この箇所を置換', exact: true }).isDisabled(), true, 'zero-width expressions cannot modify content');
    await page.locator('#content').fill('a'.repeat(35) + '!'); await query.fill('(a+)+$');
    await page.waitForFunction(() => document.querySelector('.wphm-es-count').textContent === '検索中…');
    const elapsed = await page.evaluate(() => new Promise(resolve => { const start = performance.now(); setTimeout(() => resolve(performance.now() - start), 30); }));
    same(elapsed < 400, true, 'expensive regex leaves editor thread responsive');
    await page.waitForFunction(() => document.querySelector('.wphm-es-message').textContent.includes('長いため中止'), null, { timeout: 5000 }); checks++;
    await page.locator('#content').fill('<p>1. 再検索</p>'); await query.fill('\\d+\\.[ \\t]*'); await counter('1 / 1件');
    same(await page.getByRole('button', { name: 'すべて置換', exact: true }).isEnabled(), true, 'search recovers after timeout');
    await page.locator('#content').fill('a'.repeat(35) + '!'); await query.fill('(a+)+$');
    await page.waitForFunction(() => document.querySelector('.wphm-es-count').textContent === '検索中…');
    await page.locator('#content').fill('<p>9. 新しい検索</p>'); await query.fill('\\d+\\.[ \\t]*'); await counter('1 / 1件');
    same(await page.locator('#content').inputValue(), '<p>9. 新しい検索</p>', 'changing query cancels an old expensive search');
    await page.locator('#content').fill('a'.repeat(35) + '!'); await query.fill('(a+)+$');
    await page.waitForFunction(() => document.querySelector('.wphm-es-count').textContent === '検索中…');
    await page.getByRole('button', { name: '検索・置換を閉じる', exact: true }).click();
    same(await page.locator('.wphm-es-launch').isVisible(), true, 'closing cancels an active worker');
    await page.locator('#content').fill('<p>12. 再開</p>'); await page.locator('.wphm-es-launch').click(); await query.fill('\\d+\\.[ \\t]*'); await counter('1 / 1件');
    same(await page.locator('#content').inputValue(), '<p>12. 再開</p>', 'stale cancelled results cannot change the article');
    same(errors, [], 'regex UI has no runtime errors');
    console.log(`PASS: ${checks} regex worker and editor UI assertions.`);
  } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
