/* Regex runs in a terminable worker, never on the editor's UI thread. */
'use strict';
self.onmessage = event => {
  self.postMessage({ ready: true });
  const { sources, needle, caseSensitive } = event.data;
  try {
    let pattern;
    try { pattern = new RegExp(needle.replace(/\r\n/g, '\n'), caseSensitive ? 'gmu' : 'gimu'); }
    catch (_) { throw new Error('正規表現の書き方を確認してください。括弧や角括弧が閉じているか確認できます。'); }
    let total = 0, capturedSize = 0, limited = false;
    const results = [];
    for (const source of sources) {
      const matches = [];
      for (let runIndex = 0; runIndex < source.length; runIndex++) {
        const text = source[runIndex];
        for (const hit of text.matchAll(pattern)) {
          if (!hit[0].length) throw new Error('空文字に一致する正規表現は使用できません。1文字以上に一致する式を入力してください。');
          if (total >= 5000) { limited = true; break; }
          const captures = hit.slice(1);
          capturedSize += captures.reduce((size, value) => size + (value || '').length, 0);
          if (capturedSize > 4000000) throw new Error('検索結果が大きいため中止しました。正規表現を絞ってください。');
          matches.push({ runIndex, start: hit.index, end: hit.index + hit[0].length, text: hit[0], before: text.slice(Math.max(0, hit.index - 40), hit.index), after: text.slice(hit.index + hit[0].length, hit.index + hit[0].length + 40), captures, groups: hit.groups || null });
          total++;
        }
        if (limited) break;
      }
      results.push({ matches, limited });
      if (limited) break;
    }
    self.postMessage({ results, limited });
  } catch (error) { self.postMessage({ error: error.message }); }
};
