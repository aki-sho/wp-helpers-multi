/* Compatibility screen for history created before the editor integration. */
(() => {
  'use strict';
  if (!document.getElementById('wphm-search-replace-history') || !window.wphmSearchReplace) return;
  const get = id => document.getElementById(`wphm-history-${id}`);
  const el = (tag, text) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node; };
  let page = 1, busy = false;
  async function api(payload) {
    const body = new URLSearchParams({ action: 'wphm_search_replace', nonce: window.wphmSearchReplace.nonce, payload: JSON.stringify(payload) });
    const response = await fetch(window.wphmSearchReplace.url, { method: 'POST', credentials: 'same-origin', body });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.data && result.data.message || '履歴を取得できませんでした。');
    return result.data;
  }
  const statuses = { pending: '未確定・要確認', applied: '置換済み', failed: '保存中止', restored: '復元済み' };
  async function load(target) {
    if (busy) return; busy = true;
    get('prev').disabled = true; get('next').disabled = true;
    try {
      const result = await api({ op: 'history', page: target }); page = result.page;
      get('list').replaceChildren();
      if (!result.items.length) get('list').append(el('p', '過去の置換履歴はありません。'));
      else {
        const table = el('table'); table.className = 'widefat';
        const head = el('thead'), titles = el('tr'); ['日時／実行者', '記事', '件数／状態', '操作'].forEach(text => titles.append(el('th', text))); head.append(titles); table.append(head);
        const body = el('tbody');
        for (const item of result.items) {
          const row = el('tr'), actions = el('td');
          const action = (label, handler) => {
            const button = el('button', label); button.type = 'button'; button.className = 'button button-small';
            button.addEventListener('click', async () => { if (busy) return; busy = true; button.disabled = true; try { await handler(); } catch (error) { get('status').textContent = error.message; } finally { busy = false; button.disabled = false; } });
            actions.append(button);
          };
          action('本文を確認', async () => {
            const detail = await api({ op: 'detail', history_id: Number(item.id) });
            get('before').value = detail.before; get('after').value = detail.after; get('detail').hidden = false; get('detail').open = true;
          });
          if (['applied', 'pending'].includes(item.status)) action('置換前に復元', async () => {
            if (!window.confirm(`記事 #${item.post_id} を過去の本文へ復元しますか？`)) return;
            const result = await api({ op: 'restore', history_id: Number(item.id) }); get('status').textContent = result.message;
            busy = false; await load(page);
          });
          row.append(el('td', `${item.created_at} UTC / ${item.user}`), el('td', `#${item.post_id} ${item.title || '(タイトルなし)'}`), el('td', `${item.replacements}箇所 / ${statuses[item.status] || item.status}`), actions); body.append(row);
        }
        table.append(body); get('list').append(table);
      }
      get('page').textContent = `${page}ページ`; get('prev').disabled = page <= 1; get('next').disabled = !result.more;
    } catch (error) { get('status').textContent = error.message; } finally { busy = false; }
  }
  get('prev').addEventListener('click', () => load(page - 1)); get('next').addEventListener('click', () => load(page + 1)); load(1);
})();
