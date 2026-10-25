(() => {
  'use strict';
  if (!window.wphmEditorSearch || !window.WPHMEditorSearchAdapters) return;
  const config = window.wphmEditorSearch;
  if (!config.blockEditor && !document.getElementById('content')) return;
  const { engine, editorDocuments } = window.WPHMEditorSearchAdapters;
  const adapter = window.WPHMEditorSearchAdapters.create(config.blockEditor);
  const state = { open: false, matches: [], active: 0, signature: '', timer: 0, highlights: new Set(), keyDocs: new Set(), returnFocus: null };
  const el = (tag, className, text) => {
    const node = document.createElement(tag); if (className) node.className = className; if (text !== undefined) node.textContent = text; return node;
  };
  const root = el('div', 'wphm-editor-search');
  const button = (text, handler, className) => { const node = el('button', className, text); node.type = 'button'; node.addEventListener('click', handler); return node; };
  const launcher = button('検索・置換', () => open(), 'wphm-es-launch');
  launcher.setAttribute('aria-expanded', 'false'); launcher.setAttribute('aria-controls', 'wphm-editor-search-panel');
  const panel = el('section', 'wphm-es-panel'); panel.id = 'wphm-editor-search-panel'; panel.hidden = true;
  panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', '本文の検索・置換');
  const heading = el('div', 'wphm-es-heading');
  const closeButton = button('×', close); closeButton.setAttribute('aria-label', '検索・置換を閉じる');
  heading.append(el('strong', '', '検索・置換'), closeButton); panel.append(heading);
  function field(text, name) {
    const label = el('label', 'wphm-es-label', text); const input = el('textarea');
    input.rows = 1; input.name = name; input.spellcheck = false; input.maxLength = 10000; label.append(input); panel.append(label); return input;
  }
  const search = field('検索する文字', 'wphm-editor-search');
  const replacement = field('置換後の文字（空欄で削除）', 'wphm-editor-replacement');
  const caseLabel = el('label', 'wphm-es-case'); const caseInput = el('input'); caseInput.type = 'checkbox';
  caseLabel.append(caseInput, document.createTextNode('大文字・小文字を区別')); panel.append(caseLabel);
  const navigation = el('div', 'wphm-es-navigation');
  const count = el('span', 'wphm-es-count', '0 / 0件'); count.setAttribute('role', 'status'); count.setAttribute('aria-live', 'polite');
  const previous = button('前へ', () => move(-1)), next = button('次へ', () => move(1));
  navigation.append(count, previous, next); panel.append(navigation);
  const context = el('p', 'wphm-es-context'); context.hidden = true; panel.append(context);
  const actions = el('div', 'wphm-es-actions');
  const replaceOne = button('この箇所を置換', () => replace(false)), replaceAll = button('すべて置換', () => replace(true)), undo = button('元に戻す', async () => {
    try { await adapter.undo(); state.matches = []; state.active = 0; refresh(true, true); message.textContent = '編集内容を元に戻しました。'; }
    catch (error) { message.textContent = error.message; }
  });
  actions.append(replaceOne, replaceAll, undo); panel.append(actions);
  const message = el('p', 'wphm-es-message'); message.setAttribute('role', 'status'); message.setAttribute('aria-live', 'polite'); panel.append(message);
  const omitted = el('details', 'wphm-es-omitted'); omitted.hidden = true;
  const omittedSummary = el('summary', '', '検索対象外のブロック'); const omittedText = el('p'); omitted.append(omittedSummary, omittedText); panel.append(omitted);
  panel.append(el('p', 'wphm-es-note', '今編集中の記事の本文だけが対象です。置換後は通常の「更新」「下書き保存」で保存してください。'));
  panel.append(el('p', 'wphm-es-note', 'Enter：次へ ／ Shift＋Enter：前へ ／ Esc：閉じる。改行の入力はAlt＋Enter。'));
  root.append(launcher, panel); document.body.append(root);
  function clearHighlights() {
    for (const doc of state.highlights) {
      const view = doc.defaultView;
      if (view && view.CSS && view.CSS.highlights) { view.CSS.highlights.delete('wphm-search'); view.CSS.highlights.delete('wphm-search-current'); }
      doc.querySelectorAll('[data-wphm-search-overlay]').forEach(node => node.remove());
    }
    state.highlights.clear();
  }
  function highlight(reveal) {
    clearHighlights(); if (!state.open || !search.value) return;
    const docs = new Map(), rangesBySource = new Map(); let painted = 0;
    const current = state.matches[state.active];
    if (reveal && current) adapter.reveal(current.source, current.match);
    for (const entry of state.matches) {
      if (!rangesBySource.has(entry.source.key)) rangesBySource.set(entry.source.key, engine.domRanges(entry.source.locate(), search.value, caseInput.checked, config.shortcodes));
      const range = rangesBySource.get(entry.source.key)[entry.ordinal];
      if (!range) continue;
      painted++;
      const doc = range.startContainer.ownerDocument;
      if (!docs.has(doc)) docs.set(doc, { all: [], active: [] });
      docs.get(doc).all.push(range); if (entry === current) docs.get(doc).active.push(range);
    }
    for (const [doc, ranges] of docs) {
      state.highlights.add(doc); const view = doc.defaultView;
      if (view.CSS && view.CSS.highlights && view.Highlight) {
        if (!doc.getElementById('wphm-search-highlight-style')) {
          const style = doc.createElement('style'); style.id = 'wphm-search-highlight-style';
          style.textContent = '::highlight(wphm-search){background-color:#ffe89b;color:#1e1e1e}::highlight(wphm-search-current){background-color:#ff9f43;color:#1e1e1e}'; doc.head.append(style);
        }
        view.CSS.highlights.set('wphm-search', new view.Highlight(...ranges.all));
        view.CSS.highlights.set('wphm-search-current', new view.Highlight(...ranges.active));
      } else {
        const layer = doc.createElement('div'); layer.dataset.wphmSearchOverlay = '1'; layer.setAttribute('aria-hidden', 'true');
        layer.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:20;';
        for (const range of ranges.all) for (const rect of range.getClientRects()) {
          const marker = doc.createElement('span');
          marker.style.cssText = `position:absolute;left:${rect.left + view.scrollX}px;top:${rect.top + view.scrollY}px;width:${rect.width}px;height:${rect.height}px;background:${ranges.active.includes(range) ? '#ff9f4380' : '#ffe89b80'};pointer-events:none;`;
          layer.append(marker);
        }
        // TinyMCE's body is the article. Keep fallback paint outside that editable body.
        doc.documentElement.append(layer);
      }
    }
    if (reveal && current) {
      const ranges = rangesBySource.get(current.source.key); const range = ranges && ranges[current.ordinal];
      if (range) { const node = range.startContainer.parentElement; if (node) node.scrollIntoView({ block: 'center', behavior: 'auto' }); }
    }
    window.clearTimeout(state.paintTimer);
    if (painted < state.matches.length && (state.paintRetries || 0) < 5) {
      state.paintRetries = (state.paintRetries || 0) + 1;
      state.paintTimer = window.setTimeout(() => highlight(false), 180);
    }
    installKeys();
  }
  function render() {
    const total = state.matches.length, current = state.matches[state.active];
    count.textContent = `${total ? state.active + 1 : 0} / ${total}件`;
    [previous, next, replaceOne, replaceAll].forEach(node => { node.disabled = !total; });
    undo.disabled = !adapter.canUndo(); context.hidden = !current; context.replaceChildren();
    if (current) context.append(document.createTextNode(current.match.before), el('mark', '', current.match.text), document.createTextNode(current.match.after));
  }
  function refresh(force = false, reveal = false) {
    if (!state.open) return;
    const data = adapter.collect();
    const signature = JSON.stringify([search.value, caseInput.checked, data.sources.map(source => [source.key, source.html]), data.omitted, data.message]);
    if (!force && signature === state.signature) { undo.disabled = !adapter.canUndo(); return; }
    state.signature = signature;
    const old = state.matches[state.active]; state.matches = []; let limited = false;
    if (data.sources.reduce((size, source) => size + source.html.length, 0) > 2000000) {
      message.textContent = '本文が大きいため検索できません（上限200万文字）。'; render(); clearHighlights(); return;
    }
    for (const source of data.sources) {
      const result = engine.scanHTML(source.html, search.value, caseInput.checked, config.shortcodes);
      result.matches.forEach((match, ordinal) => { if (state.matches.length < 5000) state.matches.push({ source, match, ordinal }); else limited = true; });
      limited = limited || result.limited;
    }
    if (old) {
      const index = state.matches.findIndex(item => item.source.key === old.source.key && (item.match.runIndex > old.match.runIndex || (item.match.runIndex === old.match.runIndex && item.match.start >= old.match.start)));
      state.active = index >= 0 ? index : Math.min(state.active, state.matches.length - 1);
    }
    state.active = Math.max(0, Math.min(state.active, state.matches.length - 1));
    omitted.hidden = !data.omitted.length; omittedText.textContent = data.omitted.map(name => { const type = window.wp && window.wp.blocks && window.wp.blocks.getBlockType(name); return type ? type.title : name; }).join('、');
    replaceAll.hidden = limited;
    message.textContent = data.message || (limited ? '一致箇所は5,000件まで表示します。検索語を絞ると「すべて置換」を使用できます。' : (!search.value ? '検索する文字を入力してください。' : !state.matches.length ? '一致する文字はありません。' : ''));
    state.paintRetries = 0; render(); highlight(reveal);
  }
  function queue() { window.clearTimeout(state.timer); state.timer = window.setTimeout(() => refresh(), 100); }
  function move(direction) {
    refresh(); if (!state.matches.length) return;
    state.active = (state.active + direction + state.matches.length) % state.matches.length;
    render(); highlight(true);
  }
  function replace(all) {
    const signature = state.signature; refresh();
    if (signature !== state.signature) { message.textContent = '本文の変更を反映しました。一致箇所を確認して、もう一度置換してください。'; return; }
    const selected = all ? state.matches : state.matches.slice(state.active, state.active + 1);
    if (!selected.length) return;
    const grouped = new Map();
    selected.forEach(entry => { if (!grouped.has(entry.source.key)) grouped.set(entry.source.key, { source: entry.source, matches: [] }); grouped.get(entry.source.key).matches.push(entry.match); });
    const changes = Array.from(grouped.values(), group => ({ source: group.source, html: engine.replaceHTML(group.source.html, group.matches, replacement.value) })).filter(change => change.html !== change.source.html);
    try {
      if (!changes.length) { message.textContent = '置換前後の文字が同じため、変更はありません。'; return; }
      const size = adapter.collect().sources.reduce((total, source) => total + source.html.length, 0) + changes.reduce((total, change) => total + change.html.length - change.source.html.length, 0);
      if (size > 2000000) throw new Error('置換後の本文が上限の200万文字を超えます。置換する文字を短くしてください。');
      clearHighlights(); adapter.apply(changes); refresh(true, true);
      message.textContent = `${selected.length}箇所を置換しました。編集内容を確認して保存してください。`;
    } catch (error) { refresh(true); message.textContent = error.message; }
  }
  function open() {
    if (!state.open) state.returnFocus = document.activeElement;
    state.open = true; launcher.hidden = true; launcher.setAttribute('aria-expanded', 'true'); panel.hidden = false;
    refresh(true); search.focus();
  }
  function close() {
    state.open = false; panel.hidden = true; launcher.hidden = false; launcher.setAttribute('aria-expanded', 'false'); clearHighlights();
    if (state.returnFocus && state.returnFocus.isConnected) state.returnFocus.focus(); else launcher.focus();
  }
  function keys(event) {
    if (event.isComposing || event.keyCode === 229) return;
    const target = event.target;
    if (state.open && event.key === 'Escape') { event.preventDefault(); close(); return; }
    const doc = target.ownerDocument;
    const inEditor = root.contains(target) || target === document.getElementById('content') || (target.closest && target.closest('.block-editor-writing-flow')) || (doc !== document && editorDocuments().includes(doc));
    if (inEditor && (event.ctrlKey || event.metaKey) && !event.altKey && ['f', 'h'].includes(event.key.toLowerCase())) { event.preventDefault(); event.stopPropagation(); open(); }
  }
  function installKeys() {
    for (const doc of editorDocuments()) if (!state.keyDocs.has(doc)) {
      doc.addEventListener('keydown', keys, true);
      doc.addEventListener('scroll', () => { if (state.open) { window.clearTimeout(state.scrollTimer); state.scrollTimer = window.setTimeout(() => highlight(false), 100); } }, true);
      state.keyDocs.add(doc);
    }
  }
  search.addEventListener('input', event => { if (!event.isComposing) { state.active = 0; queue(); } });
  search.addEventListener('compositionend', () => { state.active = 0; queue(); });
  caseInput.addEventListener('change', () => { state.active = 0; refresh(true); });
  [search, replacement].forEach(input => input.addEventListener('keydown', event => {
    if (event.isComposing || event.keyCode === 229 || event.key !== 'Enter') return;
    if (event.altKey) return;
    event.preventDefault(); if (input === search) move(event.shiftKey ? -1 : 1); else replace(false);
  }));
  adapter.subscribe(queue);
  window.setInterval(() => { installKeys(); if (state.open) refresh(); }, 600);
  window.addEventListener('resize', () => { if (state.open) highlight(false); });
  installKeys(); render();
})();
