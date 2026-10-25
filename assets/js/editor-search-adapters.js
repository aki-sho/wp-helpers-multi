(() => {
  'use strict';
  const engine = window.WPHMEditorSearchEngine;
  const htmlFields = {
    'core/paragraph': ['content'], 'core/heading': ['content'], 'core/list-item': ['content'],
    'core/list': ['values'], 'core/quote': ['value', 'citation'], 'core/pullquote': ['value', 'citation'],
    'core/image': ['caption'], 'core/video': ['caption'], 'core/audio': ['caption'], 'core/embed': ['caption'],
    'core/button': ['text'], 'core/freeform': ['content'],
  };
  const containers = new Set(['core/group', 'core/columns', 'core/column', 'core/cover', 'core/media-text', 'core/gallery', 'core/details']);
  const excluded = new Set(['core/code', 'core/preformatted', 'core/html', 'core/shortcode', 'core/block', 'core/template-part', 'core/post-content']);
  function getPath(object, path) { return path.reduce((value, key) => value == null ? undefined : value[key], object); }
  function htmlString(value) { return typeof value === 'string' ? value : value && typeof value.toHTMLString === 'function' ? value.toHTMLString() : null; }
  function setPath(object, path, value) {
    let cursor = object;
    path.forEach((key, index) => {
      if (index === path.length - 1) cursor[key] = value;
      else { cursor[key] = Array.isArray(cursor[key]) ? cursor[key].slice() : { ...cursor[key] }; cursor = cursor[key]; }
    });
  }
  function editorDocuments() {
    const docs = [document];
    document.querySelectorAll('iframe[name="editor-canvas"], iframe.editor-canvas__iframe, iframe#content_ifr').forEach(frame => {
      try { if (frame.contentDocument) docs.push(frame.contentDocument); } catch (_) { /* Same-origin editor only. */ }
    });
    return docs;
  }
  function blockElement(id) {
    for (const doc of editorDocuments()) {
      const found = Array.from(doc.querySelectorAll('[data-block]')).find(node => node.dataset.block === id);
      if (found) return found;
    }
    return null;
  }
  function fieldElement(source) {
    const root = blockElement(source.blockId); if (!root) return null;
    const key = source.path[0];
    if (source.type === 'core/table') {
      const [section, row, , cell] = source.path;
      const tag = { head: 'thead', body: 'tbody', foot: 'tfoot' }[section];
      return root.querySelector(`${tag} tr:nth-child(${row + 1}) > :nth-child(${cell + 1})`);
    }
    if (key === 'caption') return root.querySelector('figcaption');
    if (key === 'citation') return root.querySelector('cite');
    if (key === 'text') return root.querySelector('.wp-block-button__link');
    if (root.matches('[contenteditable="true"]')) return root;
    return root.querySelector('[contenteditable="true"]') || root;
  }
  function blockAdapter() {
    const wp = window.wp;
    const select = () => wp.data.select('core/block-editor');
    const editingCode = () => {
      const store = wp.data.select('core/editor');
      if (store.getEditorMode) return store.getEditorMode() === 'text';
      const legacy = wp.data.select('core/edit-post');
      return legacy && legacy.getEditorMode && legacy.getEditorMode() === 'text';
    };
    function collect() {
      if (editingCode()) return { sources: [], omitted: [], message: 'ビジュアルエディターに切り替えると、本文を検索・置換できます。' };
      const sources = [], omitted = new Set();
      function visit(blocks) {
        blocks.forEach(block => {
          if (excluded.has(block.name) || (select().getBlockEditingMode && select().getBlockEditingMode(block.clientId) === 'disabled')) { omitted.add(block.name); return; }
          const add = path => {
            const html = htmlString(getPath(block.attributes, path));
            if (typeof html === 'string' && html) sources.push({ key: block.clientId + ':' + path.join('.'), blockId: block.clientId, type: block.name, path, html, locate() { return fieldElement(this); } });
          };
          if (block.name === 'core/table') {
            ['head', 'body', 'foot'].forEach(section => (block.attributes[section] || []).forEach((row, i) => (row.cells || []).forEach((_, j) => add([section, i, 'cells', j, 'content']))));
          } else if (htmlFields[block.name]) htmlFields[block.name].forEach(field => add([field]));
          else if (!containers.has(block.name)) { omitted.add(block.name); return; }
          if (block.innerBlocks) visit(block.innerBlocks);
        });
      }
      visit(select().getBlocks()); return { sources, omitted: Array.from(omitted) };
    }
    return {
      kind: 'block', collect,
      apply(changes) {
        if (editingCode()) throw new Error('ビジュアルエディターに切り替えてください。');
        const attributes = {};
        for (const change of changes) {
          const block = select().getBlock(change.source.blockId);
          if (!block || htmlString(getPath(block.attributes, change.source.path)) !== change.source.html) throw new Error('本文が変更されました。検索結果を更新してから再実行してください。');
          if (!attributes[block.clientId]) attributes[block.clientId] = { ...block.attributes };
          setPath(attributes[block.clientId], change.source.path, change.html);
        }
        const dispatch = wp.data.dispatch('core/block-editor');
        // A single store action creates one undoable edit across all affected blocks.
        if (dispatch.__unstableMarkLastChangeAsPersistent) dispatch.__unstableMarkLastChangeAsPersistent();
        dispatch.updateBlockAttributes(Object.keys(attributes), attributes, true);
        if (dispatch.__unstableMarkLastChangeAsPersistent) dispatch.__unstableMarkLastChangeAsPersistent();
      },
      undo() { return wp.data.dispatch('core/editor').undo(); },
      canUndo() { const store = wp.data.select('core/editor'); return store.hasEditorUndo ? store.hasEditorUndo() : true; },
      reveal(source) {
        const root = source.locate();
        if (root) root.scrollIntoView({ block: 'center', behavior: 'auto' });
      },
      subscribe(callback) { return wp.data.subscribe(callback); },
    };
  }
  function classicAdapter() {
    const textarea = document.getElementById('content');
    let lastFallback = null;
    const tiny = () => window.tinymce && window.tinymce.get('content');
    const activeTiny = () => { const editor = tiny(); return editor && !editor.isHidden() ? editor : null; };
    return {
      kind: 'classic',
      collect() {
        const editor = activeTiny();
        const html = editor ? editor.getContent({ format: 'raw' }) : textarea.value;
        return { sources: [{ key: 'classic', html, locate: () => { const current = activeTiny(); return current ? current.getBody() : null; } }], omitted: [] };
      },
      apply(changes) {
        const change = changes[0]; const editor = activeTiny();
        const current = editor ? editor.getContent({ format: 'raw' }) : textarea.value;
        if (current !== change.source.html) throw new Error('本文が変更されました。検索結果を更新してから再実行してください。');
        if (editor) {
          editor.undoManager.transact(() => { editor.setContent(change.html); editor.fire('change'); });
          editor.save();
        } else {
          const focused = document.activeElement; const start = textarea.selectionStart, end = textarea.selectionEnd;
          textarea.focus(); textarea.setSelectionRange(0, textarea.value.length);
          const inserted = document.execCommand('insertText', false, change.html);
          const fallback = !inserted || textarea.value !== change.html;
          if (fallback) textarea.value = change.html;
          textarea.setSelectionRange(Math.min(start, textarea.value.length), Math.min(end, textarea.value.length));
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
          textarea.dispatchEvent(new Event('change', { bubbles: true }));
          if (fallback) lastFallback = { before: current, after: change.html };
          if (focused && focused !== textarea) focused.focus();
        }
      },
      undo() {
        const editor = activeTiny();
        if (editor) { editor.undoManager.undo(); editor.save(); return; }
        const focused = document.activeElement; textarea.focus();
        if (lastFallback && textarea.value === lastFallback.after) { textarea.value = lastFallback.before; lastFallback = null; }
        else document.execCommand('undo');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.dispatchEvent(new Event('change', { bubbles: true }));
        if (focused && focused !== textarea) focused.focus();
      },
      canUndo() { const editor = activeTiny(); return editor ? editor.undoManager.hasUndo() : true; },
      reveal(source, match) {
        if (activeTiny()) return;
        textarea.focus();
        textarea.setSelectionRange(match.ranges[0][0], match.ranges[match.ranges.length - 1][1]);
        textarea.scrollTop = Math.max(0, textarea.value.slice(0, textarea.selectionStart).split('\n').length * 20 - textarea.clientHeight / 2);
      },
      subscribe(callback) {
        const handler = () => { lastFallback = null; callback(); };
        textarea.addEventListener('input', handler);
        // TinyMCE is initialized asynchronously; the UI also rechecks while open.
        const interval = window.setInterval(callback, 350);
        return () => { textarea.removeEventListener('input', handler); window.clearInterval(interval); };
      },
    };
  }
  window.WPHMEditorSearchAdapters = {
    create(blockEditor) { return blockEditor ? blockAdapter() : classicAdapter(); }, editorDocuments, engine,
  };
})();
