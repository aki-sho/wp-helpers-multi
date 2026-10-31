/* global wphmSiteUrls */
(() => {
  'use strict';
  const root = document.getElementById('wphm-site-urls');
  if (!root || !window.wphmSiteUrls) return;
  const el = id => document.getElementById(`wphm-su-${id}`);
  let running = false, requestBusy = false, run = root.dataset.run, phase = root.dataset.phase, page = Number(root.dataset.page) || 1, pages = 1;
  const controls = () => { el('start').disabled = running || requestBusy; el('resume').disabled = running || requestBusy || !run || phase === 'done'; el('stop').disabled = !running; el('prev').disabled = requestBusy || page <= 1; el('next').disabled = requestBusy || page >= pages; };
  const anchor = value => { const node = document.createElement('a'); node.href = value; node.textContent = value; node.target = '_blank'; node.rel = 'noopener noreferrer'; return node; };
  function render(data) {
    const state = data.state || {}, result = data.result;
    run = state.run || ''; phase = state.phase || ''; page = result.page; pages = result.pages;
    el('status').textContent = `${phase === 'done' ? '収集完了' : running ? '収集中' : run ? '収集途中' : '未収集'}：表示対象 ${result.total}件 ／ 発見 ${state.total || 0}URL ／ 通信 ${state.fetched || 0}回`;
    el('notice').textContent = state.limited ? '上限に達した箇所があります。収集済みの範囲を表示しています。' : '';
    const body = el('rows'); body.replaceChildren();
    for (const row of result.rows) {
      const tr = document.createElement('tr');
      for (const [index, value] of [row.url, row.source_url, row.source_label, Number(row.http_status) || '—', row.note || (row.crawl_state === 'queued' ? '巡回待ち' : '取得済み')].entries()) {
        const td = document.createElement('td'); if (index === 0) td.append(anchor(value)); else td.textContent = String(value); tr.append(td);
      }
      body.append(tr);
    }
    if (!result.rows.length) { const tr = document.createElement('tr'), td = document.createElement('td'); td.colSpan = 5; td.textContent = '表示するURLがありません。'; tr.append(td); body.append(tr); }
    el('pages').textContent = `${page} / ${pages}`;
    const csv = new URL(el('csv').href); csv.searchParams.set('q', el('search').value); el('csv').href = csv.toString(); controls();
  }
  async function request(op) {
    if (requestBusy) return null;
    requestBusy = true; controls();
    try {
      const body = new URLSearchParams({ action: 'wphm_site_urls', nonce: wphmSiteUrls.nonce, op, run, q: el('search').value, paged: String(page) });
      const response = await fetch(wphmSiteUrls.url, { method: 'POST', credentials: 'same-origin', body });
      let data; try { data = await response.json(); } catch (_) { throw new Error('サーバーから正常な応答を取得できませんでした。画面を更新して続きから再開してください。'); }
      if (!response.ok || !data.success) throw new Error(data.data && data.data.message || '収集結果を取得できませんでした。');
      render(data.data); return data.data;
    } finally { requestBusy = false; controls(); }
  }
  async function collect(start) {
    if (running || requestBusy) return;
    running = true; controls();
    try {
      if (start) { page = 1; await request('start'); }
      while (running && phase !== 'done') { await request('step'); if (running && phase !== 'done') await new Promise(resolve => setTimeout(resolve, 350)); }
    } catch (error) { el('notice').textContent = error.message; }
    finally { running = false; controls(); if (phase !== 'done' && run) el('status').textContent += '（停止中・続きから再開できます）'; }
  }
  el('start').addEventListener('click', () => { if (!run || window.confirm('前回の収集結果を置き換えて、再収集しますか？')) collect(true); });
  el('resume').addEventListener('click', () => collect(false));
  el('stop').addEventListener('click', () => { running = false; el('status').textContent += '（現在の処理後に一時停止します）'; controls(); });
  el('search-form').addEventListener('submit', async event => { event.preventDefault(); if (requestBusy) return; page = 1; try { await request('list'); } catch (error) { el('notice').textContent = error.message; } });
  for (const [id, delta] of [['prev', -1], ['next', 1]]) el(id).addEventListener('click', async () => { if (requestBusy) return; page = Math.max(1, Math.min(pages, page + delta)); try { await request('list'); } catch (error) { el('notice').textContent = error.message; } });
  window.addEventListener('pagehide', () => { running = false; });
  pages = Number(el('pages').textContent.split('/')[1]) || 1; controls();
})();
