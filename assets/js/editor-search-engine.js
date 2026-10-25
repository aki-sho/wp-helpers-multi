/* Literal visible-text matching. Offsets refer to the original HTML, never to reserialized markup. */
(() => {
  'use strict';
  const denied = new Set(['script', 'style', 'code', 'pre', 'textarea', 'iframe', 'svg', 'math', 'object', 'template']);
  const boundary = /^(?:address|article|aside|blockquote|div|dl|dt|dd|figcaption|figure|h[1-6]|hr|li|ol|p|section|table|thead|tbody|tfoot|tr|th|td|ul)$/;
  const escapeRegExp = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const escapeHTML = value => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const decoder = document.createElement('textarea');
  function decode(value) { decoder.innerHTML = value; return decoder.value; }
  function protectedRanges(html, shortcodes) {
    const ranges = [];
    const names = shortcodes.filter(name => /^[\w-]+$/.test(name)).map(escapeRegExp);
    if (names.length) {
      const paired = new RegExp('\\[(' + names.join('|') + ')(?=[\\s\\]/])[^\\]]*\\][\\s\\S]*?\\[/\\1\\s*\\]', 'gi');
      for (const match of html.matchAll(paired)) ranges.push([match.index, match.index + match[0].length]);
    }
    for (const match of html.matchAll(/\[\/?[A-Za-z_][\w-]*(?:\s[^\]\r\n]*)?\/?\]/g)) ranges.push([match.index, match.index + match[0].length]);
    return ranges.sort((a, b) => a[0] - b[0]);
  }
  function htmlRuns(html, shortcodes = []) {
    const runs = []; let run = { text: '', parts: [] }; let ignored = '';
    const protections = protectedRanges(html, shortcodes);
    const flush = () => { if (run.text) runs.push(run); run = { text: '', parts: [] }; };
    function append(raw, offset, special) {
      const map = [offset]; let text = '';
      function appendUnit(unit, index, value) {
        for (let i = 0; i < value.length; i++) map.push(i === value.length - 1 ? offset + index + unit.length : null);
        text += value;
      }
      if (special) appendUnit(special.raw, special.index, special.text);
      else for (const match of raw.matchAll(/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]+);|\r\n|[\s\S]/giu)) {
        const value = match[0]; appendUnit(value, match.index, value === '\r\n' ? '\n' : value[0] === '&' ? decode(value) : value);
      }
      if (text) { run.parts.push({ start: run.text.length, end: run.text.length + text.length, map }); run.text += text; }
    }
    const tokens = /<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<\/?[A-Za-z][^<>"']*(?:(?:"[^"]*"|'[^']*')[^<>"']*)*>/g;
    function textPart(text, offset) {
      if (ignored) return;
      let cursor = offset; const end = offset + text.length;
      for (const [start, finish] of protections) {
        if (finish <= cursor || start >= end) continue;
        if (start > cursor) append(html.slice(cursor, start), cursor);
        flush(); cursor = Math.max(cursor, Math.min(end, finish));
      }
      if (cursor < end) append(html.slice(cursor, end), cursor);
    }
    let cursor = 0;
    for (const token of html.matchAll(tokens)) {
      textPart(html.slice(cursor, token.index), cursor);
      cursor = token.index + token[0].length;
      if (token[0].startsWith('<!')) { flush(); continue; }
      const tag = /^<(\/?)\s*([\w-]+)/.exec(token[0]);
      if (!tag) continue;
      const name = tag[2].toLowerCase();
      if (ignored) { if (tag[1] && name === ignored) { ignored = ''; flush(); } continue; }
      if (denied.has(name)) { flush(); if (!tag[1] && !/\/\s*>$/.test(token[0])) ignored = name; continue; }
      if (name === 'br' && !tag[1]) append(token[0], token.index, { raw: token[0], index: 0, text: '\n' });
      else if (boundary.test(name)) flush();
    }
    textPart(html.slice(cursor), cursor); flush(); return runs;
  }
  function find(runs, needle, caseSensitive, limit = 5000) {
    const matches = []; let limited = false;
    if (!needle) return { matches, limited };
    const pattern = new RegExp(escapeRegExp(needle.replace(/\r\n/g, '\n')), caseSensitive ? 'gu' : 'giu');
    runs.forEach((run, runIndex) => {
      for (const hit of run.text.matchAll(pattern)) {
        if (matches.length >= limit) { limited = true; break; }
        matches.push({ runIndex, start: hit.index, end: hit.index + hit[0].length, text: hit[0], before: run.text.slice(Math.max(0, hit.index - 40), hit.index), after: run.text.slice(hit.index + hit[0].length, hit.index + hit[0].length + 40) });
      }
    });
    return { matches, limited };
  }
  function scanHTML(html, needle, caseSensitive, shortcodes = []) {
    const runs = htmlRuns(html, shortcodes); const result = find(runs, needle, caseSensitive);
    result.matches = result.matches.filter(match => {
      const ranges = [];
      for (const part of runs[match.runIndex].parts) {
        const start = Math.max(part.start, match.start), end = Math.min(part.end, match.end);
        if (start >= end) continue;
        const rawStart = part.map[start - part.start], rawEnd = part.map[end - part.start];
        if (rawStart == null || rawEnd == null) return false;
        ranges.push([rawStart, rawEnd]);
      }
      match.ranges = ranges; return ranges.length > 0;
    });
    return result;
  }
  function replaceHTML(html, matches, replacement) {
    const changes = [];
    for (const match of matches) match.ranges.forEach((range, index) => changes.push({ start: range[0], end: range[1], text: index === 0 ? escapeHTML(replacement.replace(/\r\n/g, '\n')).replace(/\n/g, '<br>') : '' }));
    changes.sort((a, b) => a.start - b.start);
    const result = []; let cursor = 0;
    for (const change of changes) { result.push(html.slice(cursor, change.start), change.text); cursor = change.end; }
    result.push(html.slice(cursor)); return result.join('');
  }
  function domRanges(root, needle, caseSensitive, shortcodes = []) {
    if (!root) return [];
    const runs = []; let run = { text: '', parts: [] };
    const flush = () => { if (run.text) runs.push(run); run = { text: '', parts: [] }; };
    function walk(node) {
      if (node.nodeType === 3) { run.parts.push({ start: run.text.length, end: run.text.length + node.data.length, node }); run.text += node.data; return; }
      if (node.nodeType !== 1) return;
      const tag = node.tagName.toLowerCase();
      if (denied.has(tag) || node.getAttribute('contenteditable') === 'false' || node.hidden) { flush(); return; }
      if (tag === 'br') { run.parts.push({ start: run.text.length, end: run.text.length + 1, node, br: true }); run.text += '\n'; return; }
      if (boundary.test(tag)) flush();
      node.childNodes.forEach(walk);
      if (boundary.test(tag)) flush();
    }
    walk(root); flush();
    return find(runs, needle, caseSensitive).matches.filter(match => !protectedRanges(runs[match.runIndex].text, shortcodes).some(([start, end]) => match.start < end && match.end > start)).map(match => {
      const parts = runs[match.runIndex].parts;
      const first = parts.find(p => p.end > match.start), last = parts.find(p => p.end >= match.end);
      if (!first || !last) return null;
      const range = root.ownerDocument.createRange();
      if (first.br) range.setStartBefore(first.node); else range.setStart(first.node, match.start - first.start);
      if (last.br) range.setEndAfter(last.node); else range.setEnd(last.node, match.end - last.start);
      return range;
    });
  }
  window.WPHMEditorSearchEngine = { scanHTML, replaceHTML, domRanges };
})();
