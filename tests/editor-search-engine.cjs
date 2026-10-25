/* Run with the bundled Playwright package; never opens an existing browser profile. */
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(path.join(process.env.WPHM_NODE_MODULES, 'playwright'));
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.WPHM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent('<main></main>');
    await page.addScriptTag({ path: path.join(__dirname, '../assets/js/editor-search-engine.js') });
    const cases = [
      ['日本語', '<p>旧名称と旧名称</p>', '旧名称', '新名称', '<p>新名称と新名称</p>', 2],
      ['literal metacharacters', '<p>a+b (x) a.b</p>', 'a+b', '$1', '<p>$1 (x) a.b</p>', 1],
      ['case insensitive', '<p>WordPress wordpress WORDPRESS</p>', 'wordpress', 'WP', '<p>WP WP WP</p>', 3],
      ['case sensitive', '<p>WordPress wordpress</p>', 'wordpress', 'WP', '<p>WordPress WP</p>', 1, true],
      ['preserve link attributes', '<p title="旧名称"><a href="/旧名称">旧名称</a></p>', '旧名称', '新名称', '<p title="旧名称"><a href="/旧名称">新名称</a></p>', 1],
      ['preserve inline format', '<p><strong>旧名称</strong></p>', '旧名称', '新名称', '<p><strong>新名称</strong></p>', 1],
      ['across inline format', '<p>旧<strong>名称</strong></p>', '旧名称', '新名称', '<p>新名称<strong></strong></p>', 1],
      ['entity match', '<p>A&amp;B &lt; X</p>', 'A&B', '<&>', '<p>&lt;&amp;&gt; &lt; X</p>', 1],
      ['emoji entity', '<p>&#x1f600;😀</p>', '😀', '😊', '<p>😊😊</p>', 2],
      ['no partial entity', '<p>&fjlig;</p>', 'f', 'X', '<p>&fjlig;</p>', 0],
      ['code ignored', '<p>旧名称</p><pre><code>旧名称</code></pre>', '旧名称', '新名称', '<p>新名称</p><pre><code>旧名称</code></pre>', 1],
      ['script ignored', '<script>旧名称</script><p>旧名称</p>', '旧名称', '新名称', '<script>旧名称</script><p>新名称</p>', 1],
      ['comments ignored', '<!-- wp:p {"title":"旧名称"} --><p>旧名称</p>', '旧名称', '新名称', '<!-- wp:p {"title":"旧名称"} --><p>新名称</p>', 1],
      ['shortcode token', '<p>[sample title="旧名称"]旧名称</p>', '旧名称', '新名称', '<p>[sample title="旧名称"]新名称</p>', 1],
      ['registered shortcode body', '<p>[sample]旧名称[/sample] 旧名称</p>', '旧名称', '新名称', '<p>[sample]旧名称[/sample] 新名称</p>', 1, false, ['sample']],
      ['block boundary', '<p>旧</p><p>名称</p>', '旧名称', '新', '<p>旧</p><p>名称</p>', 0],
      ['line break', '<p>旧<br>名称</p>', '旧\n名称', '新\n名称', '<p>新<br>名称</p>', 1],
      ['CRLF', '<p>旧\r\n名称</p>', '旧\n名称', '新', '<p>新</p>', 1],
      ['empty replacement', '<p>旧名称</p>', '旧名称', '', '<p></p>', 1],
      ['replacement contains search', '<p>aa</p>', 'a', 'aaa', '<p>aaaaaa</p>', 2],
      ['empty query', '<p>旧名称</p>', '', 'X', '<p>旧名称</p>', 0],
      ['HTML escape', '<p>旧名称</p>', '旧名称', '<img src=x onerror=alert(1)>', '<p>&lt;img src=x onerror=alert(1)&gt;</p>', 1],
    ];
    let checks = 0;
    for (const [label, html, needle, replacement, expected, count, caseSensitive = false, shortcodes = []] of cases) {
      const result = await page.evaluate(({ html, needle, replacement, caseSensitive, shortcodes }) => {
        const engine = window.WPHMEditorSearchEngine;
        const found = engine.scanHTML(html, needle, caseSensitive, shortcodes);
        return { count: found.matches.length, html: engine.replaceHTML(html, found.matches, replacement) };
      }, { html, needle, replacement, caseSensitive, shortcodes });
      assert.equal(result.count, count, label + ' count'); assert.equal(result.html, expected, label + ' replacement'); checks += 2;
    }
    const ranges = await page.evaluate(() => {
      document.querySelector('main').innerHTML = '<p>旧<strong>名称</strong> と 旧名称</p>';
      return window.WPHMEditorSearchEngine.domRanges(document.querySelector('main'), '旧名称', true).map(range => range.toString());
    });
    assert.deepEqual(ranges, ['旧名称', '旧名称']); checks++;
    const single = await page.evaluate(() => { const e = window.WPHMEditorSearchEngine, html = '<p>旧名称と旧名称</p>'; return e.replaceHTML(html, e.scanHTML(html, '旧名称', true).matches.slice(1), '新名称'); });
    assert.equal(single, '<p>旧名称と新名称</p>'); checks++;
    const cap = await page.evaluate(() => {
      const engine = window.WPHMEditorSearchEngine, html = '<p>' + 'a'.repeat(5001) + '</p>';
      const result = engine.scanHTML(html, 'a', true);
      return [result.matches.length, result.limited, engine.replaceHTML(html, result.matches, 'b').endsWith('ba</p>')];
    });
    assert.deepEqual(cap, [5000, true, true]); checks++;
    const shortcodeRanges = await page.evaluate(() => {
      const root = document.querySelector('main'); root.innerHTML = '<p>[sample]旧名称[/sample] 旧名称</p>';
      return window.WPHMEditorSearchEngine.domRanges(root, '旧名称', true, ['sample']).map(range => range.toString());
    });
    assert.deepEqual(shortcodeRanges, ['旧名称']); checks++;
    console.log(`PASS: ${checks} browser-engine assertions.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
