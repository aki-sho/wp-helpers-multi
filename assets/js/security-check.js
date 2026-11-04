/* global wphmSecurityCheck */
(() => {
  'use strict';
  const form = document.getElementById('wphm-sc-form');
  if (!form || !window.wphmSecurityCheck) return;
  const button = document.getElementById('wphm-sc-recheck');
  const report = document.getElementById('wphm-sc-report');
  const status = document.getElementById('wphm-sc-status');
  const error = document.getElementById('wphm-sc-error');
  const pause = document.getElementById('wphm-sc-pause');
  const resume = document.getElementById('wphm-sc-resume');
  let run = '', busy = false, paused = true, timer = null, controller = null, alive = true, generation = 0;
  let groupFilter = 'all', statusFilter = 'all';
  function filterResults() {
    const toolbar = report.querySelector('.wphm-sc-filters');
    if (!toolbar) return;
    toolbar.hidden = false;
    toolbar.querySelector('[data-sc-filter="group"]').value = groupFilter;
    toolbar.querySelector('[data-sc-filter="status"]').value = statusFilter;
    const rows = report.querySelectorAll('.wphm-sc-item[data-check]');
    let visible = 0;
    rows.forEach(row => {
      const groupMatches = groupFilter === 'all' || row.dataset.scGroup === groupFilter;
      const statusMatches = statusFilter === 'all' || (statusFilter === 'review' ? row.dataset.scLevel !== 'low' : row.dataset.scLevel === statusFilter);
      row.hidden = !(groupMatches && statusMatches);
      if (!row.hidden) visible++;
    });
    toolbar.querySelector('[data-sc-filter-count]').textContent = `${visible} / ${rows.length}項目`;
  }
  report.addEventListener('change', event => {
    if (event.target.matches('[data-sc-filter="group"]') && ['all', 'identity', 'processing', 'delivery', 'information'].includes(event.target.value)) groupFilter = event.target.value;
    if (event.target.matches('[data-sc-filter="status"]') && ['all', 'review', 'unknown', 'high'].includes(event.target.value)) statusFilter = event.target.value;
    filterResults();
  });
  report.addEventListener('click', event => {
    if (event.target.closest('[data-sc-collapse]')) report.querySelectorAll('.wphm-sc-accordion, .wphm-sc-source-detail').forEach(node => { node.open = false; });
  });
  const validRun = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  const controls = () => {
    button.disabled = busy || (!!run && !paused);
    pause.hidden = !run || paused;
    resume.hidden = !run || !paused;
    resume.disabled = busy;
    report.setAttribute('aria-busy', String(busy));
    report.querySelectorAll('.wphm-sc-continue-form').forEach(node => { node.hidden = true; });
  };
  const schedule = () => { clearTimeout(timer); timer = setTimeout(() => request('continue'), 2500); };
  async function request(operation) {
    if (busy || !alive || (operation === 'continue' && (!run || paused))) return;
    timer = null; busy = true; controls(); error.textContent = '';
    const previous = !!report.querySelector('[data-check]');
    const sequence = generation;
    status.textContent = operation === 'continue' ? '残りの製品を照合しています…' : '設定・ログ・公式の更新情報を確認しています…';
    const currentController = new AbortController();
    controller = currentController;
    const timeout = setTimeout(() => currentController.abort(), 35000);
    try {
      const body = new URLSearchParams(new FormData(form));
      body.set('action', 'wphm_security_check'); body.set('operation', operation);
      if (operation === 'continue') body.set('run', run);
      const response = await fetch(wphmSecurityCheck.url, { method: 'POST', credentials: 'same-origin', body, signal: currentController.signal });
      let result;
      try { result = await response.json(); } catch (_) { throw new Error('サーバーから正常な応答を取得できませんでした。'); }
      if (!response.ok || !result || !result.success || !result.data || typeof result.data.html !== 'string' || result.data.html.length > 5000000) {
        throw new Error(result && result.data && typeof result.data.message === 'string' ? result.data.message : 'チェック結果を取得できませんでした。');
      }
      const scan = result.data.scan;
      const usersPending = scan && scan.users_pending === true;
      if (!scan || !Number.isInteger(scan.pending) || scan.pending < 0 || scan.pending > 500 || (scan.users_pending !== undefined && typeof scan.users_pending !== 'boolean') || typeof scan.auto !== 'boolean' || typeof scan.stopped !== 'boolean' || (scan.run !== '' && !validRun(scan.run)) || (scan.auto && ((!scan.pending && !usersPending) || !validRun(scan.run) || scan.stopped))) throw new Error('照合の進行情報を確認できませんでした。再チェックしてください。');
      if (!alive || sequence !== generation) return;
      // The capability/nonce protected endpoint escapes all displayed facts.
      const opened = Array.from(report.querySelectorAll('[data-check]')).filter(node => node.querySelector('details')?.open).map(node => node.dataset.check);
      const extraOpened = Array.from(report.querySelectorAll('.wphm-sc-details')).map(node => node.open);
      report.innerHTML = result.data.html;
      report.querySelectorAll('[data-check]').forEach(node => { const details = node.querySelector('details'); if (details) details.open = opened.includes(node.dataset.check); });
      report.querySelectorAll('.wphm-sc-details').forEach((node, index) => { node.open = !!extraOpened[index]; });
      filterResults();
      if (operation === 'recheck') {
        form.querySelectorAll('input[type="password"]').forEach(node => {
          const source = node.name.replace('_token', '');
          node.value = '';
          if (result.data.connections && typeof result.data.connections[source] === 'boolean') node.placeholder = result.data.connections[source] ? '保存済み（空欄で保持）' : '未設定';
        });
        form.querySelectorAll('input[name^="clear_"]').forEach(node => { node.checked = false; });
      }
      run = scan.auto ? scan.run : '';
      if (run && !paused) {
        status.textContent = `未照合 ${scan.pending}製品。${usersPending ? '利用者の確認も分割して続けます。' : ''}自動で続きを確認します…`;
        schedule();
      } else if (run) {
        status.textContent = '自動照合を一時停止しました。「照合を続ける」で再開できます。';
      } else {
        paused = true;
        status.textContent = scan.stopped ? `処理上限で停止しました。未照合 ${scan.pending}製品。${usersPending ? '利用者の残りもあります。' : ''}再チェックで続行できます。` : '再チェックが完了しました。取得失敗・確認範囲外の項目は結果内で確認してください。';
      }
    } catch (failure) {
      if (!alive || sequence !== generation) return;
      paused = true;
      if (failure.name === 'AbortError') failure = new Error('通信待ちの上限に達しました。サーバーの処理終了を待って再試行してください。');
      error.textContent = `${failure.message} ${previous ? '前回の結果を表示しています。' : '結果を取得できていません。'}`;
      status.textContent = '再チェックに失敗しました。';
    } finally {
      clearTimeout(timeout);
      if (sequence === generation) { busy = false; controller = null; controls(); }
    }
  }
  form.addEventListener('submit', event => {
    event.preventDefault(); if (button.disabled) return;
    clearTimeout(timer); run = ''; paused = false; request('recheck');
  });
  pause.addEventListener('click', () => {
    paused = true; clearTimeout(timer); timer = null;
    status.textContent = busy ? '今回の応答を受け取った後に自動照合を停止します。' : '自動照合を一時停止しました。';
    controls();
  });
  resume.addEventListener('click', () => { if (!run || busy) return; paused = false; controls(); status.textContent = '照合を再開します…'; schedule(); });
  document.addEventListener('click', event => {
    const target = event.target.closest && event.target.closest('.wphm-sc-settings-link');
    if (target) { const settings = document.getElementById('wphm-sc-settings'); if (settings) settings.open = true; }
    const link = event.target.closest && event.target.closest('[data-sc-connect]');
    if (link && ['server', 'edge'].includes(link.dataset.scConnect)) {
      const source = link.dataset.scConnect;
      const checkbox = form.querySelector(`[data-sc-source="${source}"]`);
      if (checkbox && !checkbox.checked) { checkbox.checked = true; checkbox.dispatchEvent(new Event('change', { bubbles: true })); }
      const panel = document.getElementById(`wphm-sc-connect-${source}`);
      if (panel) { panel.hidden = false; panel.open = true; }
    }
  });
  function connections() {
    for (const source of ['server', 'edge']) {
      const checkbox = form.querySelector(`[data-sc-source="${source}"]`);
      const panel = form.querySelector(`[data-sc-connection="${source}"]`);
      const picker = form.querySelector(`[data-sc-provider="${source}"]`);
      if (checkbox && panel) panel.hidden = !checkbox.checked;
      const generic = form.querySelector(`[data-sc-reader-fields="${source}"]`);
      if (generic && picker) generic.hidden = ['local', 'cloudflare'].includes(picker.value);
      const help = form.querySelector(`[data-sc-reader-help="${source}"]`);
      if (help && picker) help.textContent = picker.selectedOptions[0]?.dataset.readerHelp || '';
      if (source === 'edge') { const cf = form.querySelector('[data-sc-provider-fields="cloudflare"]'); if (cf && picker) cf.hidden = picker.value !== 'cloudflare'; }
    }
  }
  form.addEventListener('change', event => {
    if (!event.target.matches('input,select')) return;
    generation++; paused = true; run = ''; clearTimeout(timer); timer = null;
    if (controller) controller.abort();
    busy = false; controller = null;
    connections(); controls();
    if (event.target.matches('[data-sc-source]') && event.target.checked) {
      const panel = form.querySelector(`[data-sc-connection="${event.target.dataset.scSource}"]`);
      if (panel) panel.open = true;
    }
    status.textContent = '選択・入力の変更があります。「再チェック」で保存して取得します。現在の結果は前回の取得内容です。';
  });
  window.addEventListener('pagehide', () => {
    alive = false; paused = true; generation++; clearTimeout(timer); timer = null;
    if (controller) controller.abort();
  });
  window.addEventListener('pageshow', event => { if (event.persisted) { alive = true; busy = false; paused = true; controls(); } });
  const existing = report.querySelector('.wphm-sc-scan');
  if (existing && validRun(existing.dataset.run)) run = existing.dataset.run;
  connections();
  filterResults();
  controls();
})();
