const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(path.join(process.env.WPHM_NODE_MODULES, 'playwright'));
const fixture = process.env.WPHM_EDITOR_FIXTURE || path.join(__dirname, '../.wphm-test');
const runtime = JSON.parse(fs.readFileSync(path.join(fixture, 'editor-runtime.json'), 'utf8'));
const base = runtime.base || 'http://127.0.0.1:8767';
assert.equal(new URL(base).hostname, '127.0.0.1', 'fixture must be loopback');
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.WPHM_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  let checks = 0;
  const same = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, extraHTTPHeaders: { 'X-WPHM-Test': runtime.key } });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage(); const errors = [];
    page.on('dialog', dialog => dialog.accept());
    page.on('pageerror', error => { errors.push(error.message); });
    const query = () => page.locator('textarea[name="wphm-editor-search"]');
    const replacement = () => page.locator('textarea[name="wphm-editor-replacement"]');
    async function count(expected) {
      try { await page.waitForFunction(text => document.querySelector('.wphm-es-count')?.textContent === text, expected, { timeout: 10000 }); }
      catch (error) { console.error('Search state:', await page.evaluate(() => ({ count: document.querySelector('.wphm-es-count')?.textContent, message: document.querySelector('.wphm-es-message')?.textContent, body: window.wp?.data?.select('core/editor')?.getEditedPostContent?.() })), errors); throw error; }
      checks++;
    }
    const content = () => page.evaluate(() => wp.data.select('core/editor').getEditedPostContent());
    async function saved(id) {
      const nonce = await page.evaluate(() => window.wpApiSettings && window.wpApiSettings.nonce);
      const response = await page.request.get(`${base}/?rest_route=/wp/v2/posts/${id}&context=edit`, { headers: { 'X-WP-Nonce': nonce || '' } });
      assert.equal(response.status(), 200); return (await response.json()).content.raw;
    }
    async function open(id, classic = false) {
      await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit${classic ? '&wphm-classic=1' : ''}`, { waitUntil: 'domcontentloaded' });
      try { await page.locator('.wphm-es-launch').waitFor({ timeout: 12000 }); }
      catch (error) { console.error('Editor load:', page.url(), (await page.locator('body').innerText()).slice(0, 1400), errors); throw error; }
      if (!classic) {
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
        await page.evaluate(() => { const p = wp.data.dispatch('core/preferences'); if (p) { p.set('core/edit-post', 'welcomeGuide', false); p.set('core/editor', 'welcomeGuide', false); } });
      }
      await page.locator('.wphm-es-launch').click();
    }
    await open(runtime.post);
    await page.evaluate(async runtime => {
      const content = '<!-- wp:paragraph --><p>ワードプレスと <strong>ワードプレス</strong>。<a href="https://example.invalid/ワードプレス">ワードプレス</a> &amp; 😀</p><!-- /wp:paragraph -->\n<!-- wp:heading --><h2 class="wp-block-heading">見出しのワードプレス</h2><!-- /wp:heading -->\n<!-- wp:code --><pre class="wp-block-code"><code>ワードプレスはコード内</code></pre><!-- /wp:code -->';
      await wp.apiFetch({ path: `/wp/v2/posts/${runtime.post}`, method: 'POST', data: { content } });
      await wp.apiFetch({ path: `/wp/v2/pages/${runtime.page}`, method: 'POST', data: { content: '<!-- wp:paragraph --><p>固定ページのワードプレス</p><!-- /wp:paragraph -->' } });
      await wp.apiFetch({ path: `/wp/v2/posts/${runtime.classic}`, method: 'POST', data: { content: '<p>ワードプレスと <strong>ワードプレス</strong>。<a href="https://example.invalid/ワードプレス">リンク</a></p>' } });
    }, runtime);
    await open(runtime.post);
    const original = await content(), originalSaved = await saved(runtime.post), otherSaved = await saved(runtime.other);
    await query().fill('ワードプレス'); await count('1 / 4件');
    const highlighted = await page.evaluate(() => {
      const docs = window.WPHMEditorSearchAdapters.editorDocuments();
      return docs.reduce((n, doc) => n + (doc.defaultView.CSS.highlights?.get('wphm-search')?.size || 0), 0);
    });
    same(highlighted, 4, 'visible text is highlighted in the real editor');
    same(await content(), original, 'highlighting never modifies article markup');
    await page.screenshot({ path: path.join(__dirname, '../.wphm-test/editor-search-preview.png'), fullPage: false });
    const switchMode = async mode => page.evaluate(mode => {
      const current = wp.data.dispatch('core/editor');
      (current.switchEditorMode ? current : wp.data.dispatch('core/edit-post')).switchEditorMode(mode);
    }, mode);
    await switchMode('text'); await count('0 / 0件');
    same(await page.locator('.wphm-es-message').innerText(), 'ビジュアルエディターに切り替えると、本文を検索・置換できます。', 'code view does not replace stale parsed blocks');
    await switchMode('visual'); await count('1 / 4件');
    await page.getByRole('button', { name: '次へ', exact: true }).click(); await count('2 / 4件');
    await page.getByRole('button', { name: '前へ', exact: true }).click(); await count('1 / 4件');
    await replacement().fill('WordPress'); await page.getByRole('button', { name: 'この箇所を置換', exact: true }).click(); await count('1 / 3件');
    same((await content()).includes('<strong>ワードプレス</strong>'), true, 'single replacement leaves other matches alone');
    await page.locator('.wphm-es-actions').getByRole('button', { name: '元に戻す', exact: true }).click(); await count('1 / 4件');
    same(await content(), original, 'native Gutenberg undo restores exact content');
    await page.getByRole('button', { name: 'すべて置換', exact: true }).click(); await count('0 / 0件');
    const replaced = await content();
    same(replaced.includes('<strong>WordPress</strong>'), true, 'bold text formatting survives');
    same(replaced.includes('href="https://example.invalid/ワードプレス"'), true, 'link target is preserved');
    same(replaced.includes('<code>ワードプレスはコード内</code>'), true, 'code block is excluded');
    same(await saved(runtime.post), originalSaved, 'replace does not save or publish');
    same(await saved(runtime.other), otherSaved, 'another article is untouched');
    await page.locator('.wphm-es-actions').getByRole('button', { name: '元に戻す', exact: true }).click(); await count('1 / 4件');
    same(await content(), original, 'one undo restores replacement across several blocks');
    const paragraph = await page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].clientId);
    const frame = page.frames().find(frame => frame.name() === 'editor-canvas') || page.mainFrame();
    const editable = frame.locator(`[data-block="${paragraph}"]`);
    await editable.click(); await page.keyboard.press('End'); await page.keyboard.insertText(' 未保存ワードプレス');
    await count('1 / 5件');
    const unsaved = await content();
    same(unsaved.includes('未保存ワードプレス'), true, 'live unsaved editor input is searchable');
    await page.getByRole('button', { name: 'すべて置換', exact: true }).click(); await count('0 / 0件');
    await page.locator('.wphm-es-actions').getByRole('button', { name: '元に戻す', exact: true }).click(); await count('1 / 5件');
    same(await content(), unsaved, 'undo retains editing performed before replacement');
    await replacement().fill('<New & Name>'); await page.getByRole('button', { name: 'すべて置換', exact: true }).click(); await count('0 / 0件');
    same(await page.evaluate(() => wp.data.select('core/block-editor').getBlocks().every(block => block.isValid)), true, 'blocks remain valid after replacement');
    same((await content()).includes('&lt;New &amp; Name&gt;'), true, 'replacement is plain text');
    await page.evaluate(() => wp.data.dispatch('core/editor').savePost());
    same((await saved(runtime.post)).includes('&lt;New &amp; Name&gt;'), true, 'normal editor save persists replacement');
    same(await saved(runtime.other), otherSaved, 'saving still affects only the edited article');
    await page.screenshot({ path: path.join(__dirname, '../.wphm-test/editor-block.png'), fullPage: false });
    await open(runtime.page); await query().fill('ワードプレス'); await count('1 / 1件');
    await replacement().fill('WordPress'); await page.getByRole('button', { name: 'すべて置換', exact: true }).click(); await count('0 / 0件');
    same((await content()).includes('固定ページのWordPress'), true, 'fixed pages use the same editor panel');
    await page.goto(`${base}/wp-admin/post-new.php`, { waitUntil: 'domcontentloaded' });
    await page.locator('.wphm-es-launch').waitFor();
    await page.waitForFunction(() => window.wp?.data?.select('core/editor')?.getCurrentPostId());
    await page.evaluate(() => wp.data.dispatch('core/block-editor').resetBlocks([wp.blocks.createBlock('core/paragraph', { content: '新規未保存のワードプレス' })]));
    await page.locator('.wphm-es-launch').click(); await query().fill('ワードプレス'); await count('1 / 1件');
    await replacement().fill('WordPress'); await page.getByRole('button', { name: 'すべて置換', exact: true }).click(); await count('0 / 0件');
    same((await content()).includes('新規未保存のWordPress'), true, 'new unsaved articles work');
    await context.setExtraHTTPHeaders({ 'X-WPHM-Test': runtime.key, 'X-WPHM-Role': 'author' });
    await open(runtime.own); await query().fill('ワードプレス'); await count('1 / 1件');
    same(await page.locator('.wphm-es-panel').isVisible(), true, 'author can use the panel on their own article');
    await context.setExtraHTTPHeaders({ 'X-WPHM-Test': runtime.key });
    await open(runtime.classic, true);
    await page.waitForFunction(() => window.tinymce?.get('content')?.initialized);
    await query().fill('ワードプレス'); await count('1 / 2件');
    const classicOriginal = await page.evaluate(() => tinymce.get('content').getContent({ format: 'raw' }));
    await replacement().fill('WordPress'); await page.getByRole('button', { name: 'すべて置換', exact: true }).click(); await count('0 / 0件');
    same(await page.evaluate(() => tinymce.get('content').getContent().includes('<strong>WordPress</strong>')), true, 'TinyMCE formatting survives');
    await page.locator('.wphm-es-actions').getByRole('button', { name: '元に戻す', exact: true }).click(); await count('1 / 2件');
    same(await page.evaluate(() => tinymce.get('content').getContent({ format: 'raw' })), classicOriginal, 'TinyMCE native undo restores content');
    await page.evaluate(() => { for (const doc of window.WPHMEditorSearchAdapters.editorDocuments()) Object.defineProperty(doc.defaultView, 'Highlight', { value: undefined, configurable: true }); });
    await page.locator('.wphm-es-case input').check();
    same(await page.evaluate(() => tinymce.get('content').getContent({ format: 'raw' })), classicOriginal, 'fallback highlighting stays outside TinyMCE article HTML');
    same(await page.evaluate(() => tinymce.get('content').getDoc().documentElement.querySelectorAll('[data-wphm-search-overlay]').length), 1, 'fallback highlighting is painted');
    await page.getByRole('button', { name: '検索・置換を閉じる', exact: true }).click();
    await page.locator('#content-html').click(); await page.locator('.wphm-es-launch').click(); await count('1 / 2件');
    const rawOriginal = await page.locator('#content').inputValue();
    await page.getByRole('button', { name: 'すべて置換', exact: true }).click(); await count('0 / 0件');
    same((await page.locator('#content').inputValue()).includes('https://example.invalid/ワードプレス'), true, 'text-mode HTML attributes are protected');
    await page.locator('.wphm-es-actions').getByRole('button', { name: '元に戻す', exact: true }).click(); await count('1 / 2件');
    same(await page.locator('#content').inputValue(), rawOriginal, 'text mode supports native undo');
    await page.getByRole('button', { name: '検索・置換を閉じる', exact: true }).click();
    await page.locator('#content').focus(); await page.keyboard.press('Control+h');
    same(await page.locator('.wphm-es-panel').isVisible(), true, 'Ctrl+H opens the panel from the article editor');
    await page.keyboard.press('Escape'); same(await page.locator('.wphm-es-panel').isVisible(), false, 'Escape closes the panel');
    await page.setViewportSize({ width: 390, height: 844 }); await page.locator('.wphm-es-launch').click();
    const box = await page.locator('.wphm-es-panel').boundingBox(); same(box.x >= 0 && box.x + box.width <= 390, true, 'panel fits a narrow viewport');
    await page.screenshot({ path: path.join(__dirname, '../.wphm-test/editor-mobile.png') });
    same(errors, [], 'no browser runtime errors');
    console.log(`PASS: ${checks} real WordPress editor assertions.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
