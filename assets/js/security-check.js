/* global wphmSecurityCheck */
(() => {
  'use strict';
  const form = document.getElementById('wphm-sc-form');
  if (!form || !window.wphmSecurityCheck) return;
  const button = document.getElementById('wphm-sc-recheck');
  const report = document.getElementById('wphm-sc-report');
  const status = document.getElementById('wphm-sc-status');
  const error = document.getElementById('wphm-sc-error');
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (button.disabled) return;
    const hadPreviousResults = !!report.querySelector('[data-check]');
    button.disabled = true; report.setAttribute('aria-busy', 'true'); error.textContent = '';
    status.textContent = '設定・ログ・公式の更新情報を確認しています…';
    try {
      const body = new URLSearchParams(new FormData(form)); body.set('action', 'wphm_security_check');
      const response = await fetch(wphmSecurityCheck.url, { method: 'POST', credentials: 'same-origin', body });
      let result; try { result = await response.json(); } catch (_) { throw new Error('サーバーから正常な応答を取得できませんでした。'); }
      if (!response.ok || !result || !result.success || !result.data || typeof result.data.html !== 'string') throw new Error(result && result.data && result.data.message || 'チェック結果を取得できませんでした。');
      // HTML is produced by the capability/nonce protected endpoint and escapes every fact.
      report.innerHTML = result.data.html;
      status.textContent = '再チェックが完了しました。';
    } catch (failure) {
      error.textContent = `${failure.message} ${hadPreviousResults ? '前回の結果を表示しています。' : '結果を取得できていません。'}`;
      status.textContent = '再チェックに失敗しました。';
    } finally { button.disabled = false; report.setAttribute('aria-busy', 'false'); }
  });
})();
