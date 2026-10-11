(function () {
  'use strict';

  const rootSelector = '[data-page-note]';
  const closeTimers = new WeakMap();

  function hasHoverInput() {
    return window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  }

  function clearCloseTimer(root) {
    const timer = closeTimers.get(root);
    if (timer) window.clearTimeout(timer);
    closeTimers.delete(root);
  }

  function openNote(root) {
    if (!root) return;

    clearCloseTimer(root);
    closeAll(root);

    const toggle = root.querySelector('[data-page-note-toggle]');
    const panel = root.querySelector('[data-page-note-panel]');

    root.classList.add('is-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    if (panel) panel.hidden = false;
  }

  function closeNote(root) {
    if (!root) return;

    clearCloseTimer(root);

    const toggle = root.querySelector('[data-page-note-toggle]');
    const panel = root.querySelector('[data-page-note-panel]');

    root.classList.remove('is-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    if (panel) panel.hidden = true;
  }

  function scheduleClose(root) {
    clearCloseTimer(root);
    closeTimers.set(root, window.setTimeout(function () {
      closeNote(root);
    }, 180));
  }

  function closeAll(exceptRoot) {
    document.querySelectorAll(rootSelector).forEach(function (root) {
      if (root !== exceptRoot) closeNote(root);
    });
  }

  document.addEventListener('pointerover', function (event) {
    if (!hasHoverInput()) return;

    const root = event.target.closest(rootSelector);
    if (!root || root.contains(event.relatedTarget)) return;

    openNote(root);
  });

  document.addEventListener('pointerout', function (event) {
    if (!hasHoverInput()) return;

    const root = event.target.closest(rootSelector);
    if (!root || root.contains(event.relatedTarget)) return;

    scheduleClose(root);
  });

  document.addEventListener('focusin', function (event) {
    const root = event.target.closest(rootSelector);
    if (root) openNote(root);
  });

  document.addEventListener('focusout', function (event) {
    const root = event.target.closest(rootSelector);
    if (!root || root.contains(event.relatedTarget)) return;

    scheduleClose(root);
  });

  document.addEventListener('click', function (event) {
    const closeButton = event.target.closest('[data-page-note-close]');
    if (closeButton) {
      event.preventDefault();
      closeNote(closeButton.closest(rootSelector));
      return;
    }

    const toggle = event.target.closest('[data-page-note-toggle]');

    if (toggle) {
      event.preventDefault();

      const root = toggle.closest(rootSelector);
      if (!hasHoverInput()) {
        const panel = root ? root.querySelector('[data-page-note-panel]') : null;
        panel && panel.hidden ? openNote(root) : closeNote(root);
      }

      return;
    }

    document.querySelectorAll(rootSelector + '.is-open').forEach(function (root) {
      if (!root.contains(event.target)) closeNote(root);
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;

    const openNotes = document.querySelectorAll(rootSelector + '.is-open');
    openNotes.forEach(closeNote);

    const lastOpen = openNotes.item(openNotes.length - 1);
    if (lastOpen) lastOpen.querySelector('[data-page-note-toggle]')?.focus();
  });
})();
