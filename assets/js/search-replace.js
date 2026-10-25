(() => {
  'use strict';
  const root = document.getElementById('wphm-search-replace');
  if (!root || !window.wphmSearchReplace) return;
  const get = id => document.getElementById(`wphm-sr-${id}`);
  const form = get('form');
  const state = { token: '', articles: [], active: null, replacement: '', busy: false, stop: false, historyPage: 1, historyMore: false, noticeCount: 0 };
  const statuses = { publish: '公開済み', draft: '下書き', pending: '承認待ち', private: '非公開', future: '予約済み' };
  const historyStatuses = { pending: '未確定・要確認', applied: '置換済み', failed: '保存中止', restored: '復元済み' };
  const el = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  const button = (text, handler, small = true) => {
    const node = el('button', text, `button${small ? ' button-small' : ''}`);
    node.type = 'button';
    node.addEventListener('click', handler);
    return node;
  };
  const status = text => { get('status').textContent = text; };
  const notice = text => {
    get('notices').hidden = false;
    state.noticeCount++;
    if (state.noticeCount <= 200) get('notice-list').append(el('li', text));
    else {
      let summary = get('notice-list').querySelector('[data-overflow]');
      if (!summary) { summary = el('li'); summary.dataset.overflow = '1'; get('notice-list').append(summary); }
      summary.textContent = `ほか${state.noticeCount - 200}件の詳細を省略しています。対象記事を絞って再検索できます。`;
    }
  };
  async function api(payload) {
    const body = new URLSearchParams({ action: 'wphm_search_replace', nonce: window.wphmSearchReplace.nonce, payload: JSON.stringify(payload) });
    let response, result;
    try {
      response = await fetch(window.wphmSearchReplace.url, { method: 'POST', credentials: 'same-origin', body });
      result = await response.json();
    } catch (_) {
      throw new Error('通信結果を確認できませんでした。保存操作の場合は、再実行する前に履歴と記事を確認してください。');
    }
    if (!response.ok || !result || result.success !== true) {
      throw new Error(result && result.data && result.data.message ? result.data.message : '処理できませんでした。ログイン状態を確認し、画面を再読み込みしてください。');
    }
    return result.data;
  }
  function selection() {
    return state.articles.filter(article => !article.done && article.selected.size);
  }
  function counters() {
    const articles = selection();
    const count = articles.reduce((sum, article) => sum + article.selected.size, 0);
    get('selected').textContent = `選択中：${articles.length}記事・${count}箇所`;
    get('apply').disabled = state.busy || !state.token || !count;
    get('select-all').disabled = state.busy || !state.articles.some(article => !article.done);
  }
  function busy(value) {
    state.busy = value;
    get('fields').disabled = value;
    get('stop').disabled = !value;
    get('history-refresh').disabled = value;
    root.querySelectorAll('[data-selection]').forEach(node => { node.disabled = value || node.dataset.processed === '1'; });
    get('history').querySelectorAll('button').forEach(node => { node.disabled = value; });
    get('history-prev').disabled = value || state.historyPage <= 1;
    get('history-next').disabled = value || !state.historyMore;
    counters();
  }
  function invalidate() {
    if (state.busy || !state.token) return;
    state.token = '';
    state.articles = [];
    state.active = null;
    renderArticles();
    renderPreview();
    status('条件が変更されました。検索し直してください。');
  }
  form.addEventListener('input', invalidate);
  form.addEventListener('change', event => {
    if (event.target.name === 'category' && event.target.value !== '0') form.elements.type.value = 'post';
    if (event.target.name === 'type' && event.target.value === 'page') form.elements.category.value = '0';
    invalidate();
  });
  function renderArticles() {
    const list = get('results');
    list.replaceChildren();
    if (!state.articles.length) list.append(el('p', '一致した記事がここに並びます。', 'wphm-sr-empty'));
    for (const article of state.articles) {
      const row = el('div', undefined, `wphm-sr-article${state.active === article.id ? ' is-active' : ''}`);
      const check = el('input');
      check.type = 'checkbox';
      check.checked = article.selected.size === article.matches.length && !article.done;
      check.indeterminate = article.selected.size > 0 && article.selected.size < article.matches.length;
      check.disabled = state.busy || article.done;
      check.dataset.selection = '1';
      check.dataset.processed = article.done ? '1' : '0';
      check.setAttribute('aria-label', `${article.title || '(タイトルなし)'}の一致箇所をすべて選択`);
      check.addEventListener('change', () => {
        article.selected = new Set(check.checked ? article.matches.map(match => match.id) : []);
        renderArticles(); renderPreview();
      });
      const open = button('', () => { state.active = article.id; renderArticles(); renderPreview(); });
      open.className = '';
      open.setAttribute('aria-pressed', state.active === article.id ? 'true' : 'false');
      open.append(el('strong', article.title || '(タイトルなし)'));
      open.append(el('small', `${article.type === 'page' ? '固定ページ' : '投稿'} #${article.id} · ${statuses[article.status] || article.status}`));
      open.append(el('small', article.done ? article.result : `${article.matches.length}箇所中 ${article.selected.size}箇所を選択`));
      row.append(check, open);
      list.append(row);
    }
    counters();
  }
  function renderPreview() {
    const panel = get('preview');
    panel.replaceChildren();
    const article = state.articles.find(item => item.id === state.active);
    if (!article) { panel.append(el('p', '検索後、記事を選ぶと置換箇所を確認できます。', 'wphm-sr-empty')); return; }
    const heading = el('div', undefined, 'wphm-sr-preview-heading');
    const title = el('div');
    title.append(el('h3', article.title || '(タイトルなし)'));
    if (article.edit_url) {
      const link = el('a', 'WordPressの記事編集画面を開く');
      link.href = article.edit_url; link.target = '_blank'; link.rel = 'noopener'; title.append(link);
    }
    const nav = el('div');
    let activeMatch = -1;
    function jump(delta) {
      const rows = panel.querySelectorAll('.wphm-sr-match');
      if (!rows.length) return;
      activeMatch = activeMatch < 0 ? (delta > 0 ? 0 : rows.length - 1) : (activeMatch + delta + rows.length) % rows.length;
      rows[activeMatch].focus();
      rows[activeMatch].scrollIntoView({ block: 'nearest' });
    }
    nav.append(button('前の一致', () => jump(-1)), button('次の一致', () => jump(1)));
    heading.append(title, nav); panel.append(heading);
    panel.append(el('p', '一致箇所ごとの差分です。周辺の文章は検索時点の内容を表示します。', 'description'));
    if (article.done) panel.append(el('p', article.result));
    const list = el('div', undefined, 'wphm-sr-match-list');
    for (const match of article.matches) {
      const row = el('div', undefined, 'wphm-sr-match');
      row.tabIndex = -1;
      const label = el('label');
      const check = el('input');
      check.type = 'checkbox'; check.checked = article.done ? Boolean(article.applied && article.applied.has(match.id)) : article.selected.has(match.id);
      check.disabled = state.busy || article.done;
      check.dataset.selection = '1'; check.dataset.processed = article.done ? '1' : '0';
      label.append(check, document.createTextNode(`一致 ${match.id + 1} を置換`)); row.append(label);
      const before = el('div', undefined, 'wphm-sr-diff wphm-sr-before');
      before.append(document.createTextNode(`変更前  …${match.before}`), el('del', match.text), document.createTextNode(`${match.after}…`));
      const after = el('div', undefined, 'wphm-sr-diff wphm-sr-after');
      function updateAfter() {
        after.replaceChildren(document.createTextNode(`変更後  …${match.before}`));
        after.append(check.checked ? el('ins', state.replacement === '' ? '［削除］' : state.replacement) : document.createTextNode(match.text));
        after.append(document.createTextNode(`${match.after}…`));
      }
      check.addEventListener('change', () => {
        if (check.checked) article.selected.add(match.id); else article.selected.delete(match.id);
        updateAfter(); renderArticles();
      });
      updateAfter(); row.append(before, after); list.append(row);
    }
    panel.append(list);
  }
  get('select-all').addEventListener('click', () => {
    const clear = state.articles.every(article => article.done || article.selected.size === article.matches.length);
    state.articles.forEach(article => { if (!article.done) article.selected = new Set(clear ? [] : article.matches.map(match => match.id)); });
    renderArticles(); renderPreview();
  });
  get('stop').addEventListener('click', () => {
    state.stop = true;
    get('stop').disabled = true;
    status('実行中の処理が完了したら停止します。');
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (state.busy) return;
    const data = new FormData(form);
    const settings = Object.fromEntries(data.entries());
    state.replacement = settings.replacement;
    state.token = ''; state.articles = []; state.active = null; state.stop = false;
    state.noticeCount = 0; get('notice-list').replaceChildren(); get('notices').hidden = true;
    renderArticles(); renderPreview(); busy(true); status('検索を開始しています…');
    try {
      const started = await api({ op: 'start', settings });
      state.token = started.token;
      let result;
      do {
        result = await api({ op: 'search', token: state.token });
        for (const item of result.items) state.articles.push({ ...item, selected: new Set(item.matches.map(match => match.id)), done: false, result: '' });
        result.skipped.forEach(item => notice(`#${item.id} ${item.title}: ${item.reason}`));
        if (state.active === null && state.articles.length) state.active = state.articles[0].id;
        renderArticles(); renderPreview();
        status(`${result.scanned}記事を確認 · ${state.articles.length}記事・${result.count}箇所が一致`);
      } while (!result.done && !state.stop);
      const prefix = state.stop && !result.done ? '検索を中断しました。取得済みの結果を表示しています。' : '検索が完了しました。';
      status(`${prefix}\n${result.scanned}記事を確認 · ${state.articles.length}記事・${result.count}箇所が一致`);
      if (result.limited) { notice('検索結果が上限（200記事・2,000箇所）に達しました。表示されていない記事を処理するには条件を絞って再検索してください。'); get('notices').open = true; }
    } catch (error) { status(error.message); }
    finally { busy(false); renderArticles(); renderPreview(); }
  });
  get('apply').addEventListener('click', async () => {
    if (state.busy || !state.token) return;
    const articles = selection();
    const count = articles.reduce((sum, article) => sum + article.selected.size, 0);
    if (!count || !window.confirm(`${articles.length}記事・${count}箇所を置換して保存します。公開済みの記事にも反映されます。実行しますか？`)) return;
    state.stop = false; busy(true);
    let success = 0, failed = 0, processed = 0;
    try {
      for (const article of articles) {
        if (state.stop) break;
        status(`置換中：${processed + 1}/${articles.length}記事`);
        try {
          const result = await api({ op: 'apply', token: state.token, post_id: article.id, selected: [...article.selected] });
          success++;
          article.applied = new Set(article.selected);
          article.result = `${result.count}箇所を置換しました。`;
          if (result.note) notice(`#${article.id}: ${result.note}`);
        } catch (error) {
          failed++; article.result = `要確認：${error.message}`;
          notice(`#${article.id} ${article.title}: ${error.message}`);
        }
        // Never automatically retry a write with an unknown network outcome.
        article.done = true; article.selected.clear(); processed++;
        renderArticles(); renderPreview();
      }
      status(`${state.stop ? '処理を停止しました。' : '置換処理が完了しました。'} ${success}記事成功・${failed}記事要確認・${articles.length - processed}記事未処理`);
      if (failed) get('notices').open = true;
      await loadHistory(1);
    } finally { busy(false); renderArticles(); renderPreview(); }
  });
  function formatDate(value) {
    return value ? new Date(`${value.replace(' ', 'T')}Z`).toLocaleString('ja-JP') : '';
  }
  async function loadHistory(page) {
    const container = get('history');
    try {
      const result = await api({ op: 'history', page });
      state.historyPage = result.page; state.historyMore = result.more;
      container.replaceChildren();
      if (!result.items.length) container.append(el('p', '置換履歴はまだありません。'));
      else {
        const table = el('table', undefined, 'widefat');
        const head = el('thead'), header = el('tr');
        ['日時／実行者', '記事', '件数／状態', '操作'].forEach(text => header.append(el('th', text)));
        head.append(header); table.append(head);
        const body = el('tbody');
        for (const item of result.items) {
          const row = el('tr');
          const date = el('td', formatDate(item.created_at)); date.append(el('br'), document.createTextNode(item.user));
          const title = el('td', `#${item.post_id} ${item.title || '(タイトルなし)'}`);
          const info = el('td', `${item.replacements}箇所 · ${historyStatuses[item.status] || item.status}`);
          if (item.restored_at) info.append(el('br'), document.createTextNode(formatDate(item.restored_at)));
          const actions = el('td');
          const detail = button('本文を確認', async () => {
            if (state.busy) return;
            detail.disabled = true;
            try {
              const result = await api({ op: 'detail', history_id: Number(item.id) });
              get('history-before').value = result.before; get('history-after').value = result.after;
              get('history-note').textContent = result.note || '';
              get('history-detail').hidden = false; get('history-detail').open = true;
              get('history-detail').scrollIntoView({ block: 'start' });
            } catch (error) { notice(error.message); get('notices').open = true; }
            finally { detail.disabled = state.busy; }
          });
          actions.append(detail);
          if (['applied', 'pending'].includes(item.status)) actions.append(button('置換前に復元', async () => {
            if (state.busy || !window.confirm(`記事 #${item.post_id} をこの履歴の置換前の本文に復元しますか？`)) return;
            busy(true);
            try {
              const result = await api({ op: 'restore', history_id: Number(item.id) });
              status(result.message);
              state.token = ''; state.articles = []; state.active = null;
              renderArticles(); renderPreview();
              await loadHistory(state.historyPage);
            } catch (error) { status(error.message); }
            finally { busy(false); }
          }));
          actions.querySelectorAll('button').forEach(node => { node.disabled = state.busy; });
          row.append(date, title, info, actions); body.append(row);
        }
        table.append(body); container.append(table);
      }
      get('history-page').textContent = `${state.historyPage}ページ`;
      get('history-prev').disabled = state.busy || state.historyPage <= 1;
      get('history-next').disabled = state.busy || !state.historyMore;
    } catch (error) { container.replaceChildren(el('p', error.message)); }
  }
  get('history-refresh').addEventListener('click', () => { if (!state.busy) loadHistory(state.historyPage); });
  get('history-prev').addEventListener('click', () => { if (!state.busy && state.historyPage > 1) loadHistory(state.historyPage - 1); });
  get('history-next').addEventListener('click', () => { if (!state.busy && state.historyMore) loadHistory(state.historyPage + 1); });
  loadHistory(1);
})();
