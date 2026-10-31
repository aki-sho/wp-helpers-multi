/* global wphmSiteUrls */
(() => {
  'use strict';
  const root = document.getElementById('wphm-site-urls');
  if (!root || !window.wphmSiteUrls) return;
  const el = id => document.getElementById(`wphm-su-${id}`);
  let running = false, requestBusy = false, leaving = false, generation = 0, run = root.dataset.run, phase = root.dataset.phase, page = Number(root.dataset.page) || 1, pages = 1;
  let orderby = root.dataset.orderby || 'url', order = root.dataset.order || 'ASC';
  let filterVersion = 0, appliedFilterVersion = 0;
  const filterInputs = [...root.querySelectorAll('[data-filter], input[name="wphm-su-mode"]')];
  const filters = () => {
    const value = { mode: root.querySelector('input[name="wphm-su-mode"]:checked').value };
    for (const input of root.querySelectorAll('[data-filter]')) value[input.dataset.filter] = input.checked ? '1' : '0';
    return value;
  };
  const clearStorageKey = `wphm-su-departure:${new URL(wphmSiteUrls.url, window.location.href).pathname}`;
  const controls = () => { el('start').disabled = running || requestBusy; el('resume').disabled = running || requestBusy || !run || phase === 'done'; el('stop').disabled = !running; el('prev').disabled = requestBusy || page <= 1; el('next').disabled = requestBusy || page >= pages; el('csv').setAttribute('aria-disabled', requestBusy || filterVersion !== appliedFilterVersion ? 'true' : 'false'); };
  const anchor = value => { const node = document.createElement('a'); node.href = value; node.textContent = value; node.target = '_blank'; node.rel = 'noopener noreferrer'; return node; };
  function render(data) {
    const state = data.state || {}, result = data.result;
    run = state.run || ''; phase = state.phase || ''; page = result.page; pages = result.pages;
    orderby = result.orderby || orderby; order = result.order || order;
    el('status').textContent = `${phase === 'done' ? '収集完了' : running ? '収集中' : run ? '収集途中' : '未収集'}：表示対象 ${result.total}件 ／ 発見 ${state.total || 0}URL ／ 通信 ${state.fetched || 0}回`;
    el('notice').textContent = state.limited ? '上限に達した箇所があります。収集済みの範囲を表示しています。' : '';
    if (data.filter_summary) el('applied-filters').textContent = `適用中：${data.filter_summary}`;
    const classification = data.classification || {};
    el('classification').textContent = `種類の確認待ち ${classification.pending || 0}件 ／ 転送先を判定できないURL ${classification.unknown || 0}件`;
    const body = el('rows'); body.replaceChildren();
    for (const row of result.rows) {
      const tr = document.createElement('tr');
      for (const [index, value] of [row.url, row.source_url, row.source_label, Number(row.http_status) || '—', row.confirmation_label || row.note || (row.crawl_state === 'queued' ? '巡回待ち' : '取得済み'), row.access_label || '未確認'].entries()) {
        const td = document.createElement('td'); if (index === 0) td.append(anchor(value)); else td.textContent = String(value); tr.append(td);
      }
      body.append(tr);
    }
    if (!result.rows.length) { const tr = document.createElement('tr'), td = document.createElement('td'); td.colSpan = 6; td.textContent = '表示するURLがありません。'; tr.append(td); body.append(tr); }
    el('pages').textContent = `${page} / ${pages}`;
    const csv = new URL(el('csv').href); csv.searchParams.set('q', el('search').value); csv.searchParams.set('orderby', orderby); csv.searchParams.set('order', order); el('csv').href = csv.toString();
    for (const link of root.querySelectorAll('a[data-sort]')) {
      const active = link.dataset.sort === orderby; link.closest('th').setAttribute('aria-sort', active ? order === 'ASC' ? 'ascending' : 'descending' : 'none');
      link.querySelector('.wphm-su-sort-indicator').textContent = active ? order === 'ASC' ? '▲' : '▼' : '';
      const href = new URL(link.href); href.searchParams.set('q', el('search').value); href.searchParams.set('order', active && order === 'ASC' ? 'DESC' : 'ASC'); link.href = href.toString();
    }
    controls();
  }
  async function request(op) {
    if (requestBusy) return null;
    requestBusy = true; controls();
    const requestGeneration = generation;
    const requestFilterVersion = filterVersion;
    try {
      const body = new URLSearchParams({ action: 'wphm_site_urls', nonce: wphmSiteUrls.nonce, op, run, q: el('search').value, paged: String(page), orderby, order });
      if (op === 'start' || op === 'filters') for (const [key, value] of Object.entries(filters())) body.set(key, value);
      const response = await fetch(wphmSiteUrls.url, { method: 'POST', credentials: 'same-origin', body });
      let data; try { data = await response.json(); } catch (_) { throw new Error('サーバーから正常な応答を取得できませんでした。画面を更新して続きから再開してください。'); }
      if (!response.ok || !data.success) throw new Error(data.data && data.data.message || '収集結果を取得できませんでした。');
      if (leaving || generation !== requestGeneration) return null;
      if (op === 'start' || op === 'filters') {
        appliedFilterVersion = requestFilterVersion;
        if (appliedFilterVersion === filterVersion) el('filter-status').textContent = '選択を反映しました。';
      }
      render(data.data); return data.data;
    } finally { requestBusy = false; controls(); }
  }
  async function syncFilters() {
    while (!leaving && filterVersion !== appliedFilterVersion) {
      if (requestBusy) { await new Promise(resolve => setTimeout(resolve, 50)); continue; }
      await request('filters');
    }
  }
  let filterSync = null;
  const saveFilters = () => {
    if (!filterSync) filterSync = syncFilters().catch(error => { el('filter-status').textContent = `反映できませんでした：${error.message}。選択を変更すると再試行します。`; }).finally(() => { filterSync = null; });
    return filterSync;
  };
  for (const input of filterInputs) input.addEventListener('change', () => {
    filterVersion++; page = 1; el('filter-status').textContent = '選択を反映中…'; controls(); saveFilters();
  });
  el('csv').addEventListener('click', event => { if (el('csv').getAttribute('aria-disabled') === 'true') { event.preventDefault(); el('filter-status').textContent = '処理の完了後にCSV出力できます。'; } });
  async function collect(start) {
    if (running || requestBusy) return;
    running = true; controls();
    try {
      if (start) { page = 1; await request('start'); }
      while (running && phase !== 'done') { if (filterSync) await filterSync; await request('step'); if (running && phase !== 'done') await new Promise(resolve => setTimeout(resolve, 350)); }
    } catch (error) { el('notice').textContent = error.message; }
    finally { running = false; controls(); if (phase !== 'done' && run) el('status').textContent += '（停止中・続きから再開できます）'; }
  }
  el('start').addEventListener('click', () => { if (!run || window.confirm('前回の収集結果を置き換えて、再収集しますか？')) collect(true); });
  el('resume').addEventListener('click', () => collect(false));
  el('stop').addEventListener('click', () => { running = false; el('status').textContent += '（現在の処理後に一時停止します）'; controls(); });
  el('search-form').addEventListener('submit', async event => { event.preventDefault(); if (requestBusy) return; page = 1; try { await request('list'); } catch (error) { el('notice').textContent = error.message; } });
  for (const [id, delta] of [['prev', -1], ['next', 1]]) el(id).addEventListener('click', async () => { if (requestBusy) return; page = Math.max(1, Math.min(pages, page + delta)); try { await request('list'); } catch (error) { el('notice').textContent = error.message; } });
  for (const link of root.querySelectorAll('a[data-sort]')) link.addEventListener('click', async event => {
    event.preventDefault(); if (requestBusy) return;
    const key = link.dataset.sort; order = key === orderby && order === 'ASC' ? 'DESC' : 'ASC'; orderby = key; page = 1;
    try { await request('list'); } catch (error) { el('notice').textContent = error.message; }
  });
  function clearPayload(value) { return new URLSearchParams({ action: 'wphm_site_urls', nonce: wphmSiteUrls.nonce, op: 'clear', run: value }); }
  function emptyView() { render({ state: {}, result: { rows: [], total: 0, page: 1, pages: 1, orderby, order } }); }
  function depart() {
    if (leaving) return;
    leaving = true; generation++; running = false;
    if (!run) return;
    const departedRun = run;
    try { localStorage.setItem(clearStorageKey, JSON.stringify({ run: departedRun, at: Date.now() })); } catch (_) { /* Beacon still works when browser storage is unavailable. */ }
    const body = clearPayload(departedRun);
    if (!navigator.sendBeacon || !navigator.sendBeacon(wphmSiteUrls.url, body)) fetch(wphmSiteUrls.url, { method: 'POST', credentials: 'same-origin', body, keepalive: true }).catch(() => {});
    emptyView();
  }
  async function retryDeparture() {
    let pending; try { pending = JSON.parse(localStorage.getItem(clearStorageKey)); } catch (_) { return; }
    if (!pending || !/^[a-f0-9]{32}$/.test(pending.run)) return;
    if (Date.now() - pending.at > 86400000) { try { localStorage.removeItem(clearStorageKey); } catch (_) {} return; }
    if (pending.run === run) emptyView();
    try {
      const response = await fetch(wphmSiteUrls.url, { method: 'POST', credentials: 'same-origin', body: clearPayload(pending.run), keepalive: true });
      const data = await response.json();
      if (response.ok && data.success) { try { if (JSON.parse(localStorage.getItem(clearStorageKey))?.run === pending.run) localStorage.removeItem(clearStorageKey); } catch (_) {} }
    } catch (_) { /* Keep the small run marker for the next visit. It contains no collected URLs. */ }
  }
  window.addEventListener('pagehide', depart);
  window.addEventListener('pageshow', event => { if (event.persisted) { leaving = false; running = false; controls(); retryDeparture(); } });
  retryDeparture();
  pages = Number(el('pages').textContent.split('/')[1]) || 1; controls();
  if (run && root.dataset.classify === '1') { phase = 'crawl'; collect(false); }
})();
