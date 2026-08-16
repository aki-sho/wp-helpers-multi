(function () {
  'use strict';

  function setupToolSettings() {
    var form = document.querySelector('[data-wphm-settings-form]');
    if (!form) return;

    var toggles = Array.prototype.slice.call(form.querySelectorAll('[data-wphm-toggle]'));
    var count = document.querySelector('[data-wphm-enabled-count]');
    var saveBar = form.querySelector('[data-wphm-save-bar]');
    var saveStatus = form.querySelector('[data-wphm-save-status]');

    function refresh() {
      var enabledCount = 0;
      toggles.forEach(function (toggle) {
        var card = toggle.closest('[data-wphm-tool-card]');
        var label = card ? card.querySelector('[data-wphm-state-label]') : null;
        if (toggle.checked) enabledCount += 1;
        if (card) {
          card.classList.toggle('is-enabled', toggle.checked);
          card.classList.toggle('is-disabled', !toggle.checked);
        }
        if (label) label.textContent = toggle.checked ? '有効' : '無効';
      });
      if (count) count.textContent = String(enabledCount);
    }

    toggles.forEach(function (toggle) {
      toggle.addEventListener('change', function () {
        refresh();
        if (saveBar) saveBar.classList.add('has-changes');
        if (saveStatus) saveStatus.textContent = '未保存の変更があります。';
      });
    });
  }

  function setupLinkSearch() {
    var input = document.querySelector('[data-wphm-link-search]');
    if (!input) return;

    var links = Array.prototype.slice.call(document.querySelectorAll('[data-wphm-link]'));
    var categories = Array.prototype.slice.call(document.querySelectorAll('[data-wphm-link-category]'));
    var phases = Array.prototype.slice.call(document.querySelectorAll('[data-wphm-link-phase]'));
    var empty = document.querySelector('[data-wphm-link-empty]');

    function normalize(value) {
      return String(value || '').toLocaleLowerCase().replace(/\s+/g, ' ').trim();
    }

    function filterLinks() {
      var query = normalize(input.value);
      var visibleCount = 0;

      links.forEach(function (link) {
        var matches = !query || normalize(link.getAttribute('data-search')).indexOf(query) !== -1;
        link.hidden = !matches;
        if (matches) visibleCount += 1;
      });

      categories.forEach(function (category) {
        category.hidden = !category.querySelector('[data-wphm-link]:not([hidden])');
      });

      phases.forEach(function (phase) {
        phase.hidden = !phase.querySelector('[data-wphm-link-category]:not([hidden])');
      });

      if (empty) empty.hidden = visibleCount !== 0;
    }

    input.addEventListener('input', filterLinks);
  }

  document.addEventListener('DOMContentLoaded', function () {
    setupToolSettings();
    setupLinkSearch();
  });
}());
