/* EduPortal content UX: fetch filters, double-submit guard, confirm dialogs, slug helper.
 * Vanilla JS only (no jQuery). Reuses existing CSRF meta + EduLive where present.
 * Fails open: every form still works with full-page submit if JS is disabled.
 */
(function () {
  'use strict';

  function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  // --- Double-submit protection + loading labels (all important forms) ---
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || form.tagName !== 'FORM') return;
    if (form.dataset.submitting === '1') {
      ev.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    var btns = form.querySelectorAll('[type="submit"], button:not([type])');
    btns.forEach(function (b) {
      b.disabled = true;
      var label = b.getAttribute('data-loading');
      if (label && !b.dataset.orig) {
        b.dataset.orig = b.innerHTML;
        b.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + label;
      }
    });
    // Safety: re-enable after 15s in case navigation was blocked
    setTimeout(function () {
      form.dataset.submitting = '';
      btns.forEach(function (b) {
        b.disabled = false;
        if (b.dataset.orig) { b.innerHTML = b.dataset.orig; delete b.dataset.orig; }
      });
    }, 15000);
  });

  // Standalone buttons with data-loading (non-form fetch actions)
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-loading]:not(form [data-loading])');
    if (!b || b.disabled) return;
  });

  // --- Confirm dialogs for destructive actions ---
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (form && form.getAttribute('data-confirm')) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        form.dataset.submitting = '';
        form.querySelectorAll('[disabled]').forEach(function (b) { b.disabled = false; });
      }
    }
  }, true);

  // --- Slug auto-generation (blog editor) ---
  function slugify(s) {
    return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 200) || 'post';
  }
  var titleEl = document.querySelector('[data-slug-source]');
  var slugEl = document.querySelector('[data-slug-target]');
  if (titleEl && slugEl && !slugEl.value) {
    titleEl.addEventListener('input', function () {
      if (slugEl.dataset.touched) return;
      slugEl.value = slugify(titleEl.value);
    });
    slugEl.addEventListener('input', function () { slugEl.dataset.touched = '1'; });
  }

  // --- AJAX list filtering (progressive enhancement) ---
  function bindAjaxFilter(formId, listId) {
    var form = document.getElementById(formId);
    var list = document.getElementById(listId);
    if (!form || !list) return;
    var timer = null;
    function submit() {
      var fd = new FormData(form);
      var qs = new URLSearchParams(fd).toString() + '&partial=list';
      list.setAttribute('aria-busy', 'true');
      list.style.opacity = '0.55';
      fetch(form.action + (form.action.indexOf('?') >= 0 ? '&' : '?') + qs, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      }).then(function (r) {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.text();
      }).then(function (html) {
        list.innerHTML = html;
      }).catch(function () {
        // fail open: full submit
        form.submit();
      }).finally(function () {
        list.removeAttribute('aria-busy');
        list.style.opacity = '';
      });
    }
    form.addEventListener('submit', function (ev) {
      if (window.fetch) { ev.preventDefault(); submit(); }
    });
    var q = form.querySelector('input[name="q"]');
    if (q) {
      q.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(submit, 450);
      });
    }
    form.querySelectorAll('select').forEach(function (s) {
      s.addEventListener('change', submit);
    });
  }
  bindAjaxFilter('blogFilter', 'blogList');
  bindAjaxFilter('annFilter', 'annList');
  bindAjaxFilter('adminBlogFilter', 'adminBlogTable');
  bindAjaxFilter('announcementFilter', 'annList');

  // --- Mark announcement as read via fetch (no reload) ---
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-mark-read]');
    if (!el) return;
    var id = el.getAttribute('data-mark-read');
    fetch('/announcements/' + encodeURIComponent(id) + '/read', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin',
    }).catch(function () {});
  });

  // --- EduLive hookup: refresh announcement badge without full reload ---
  function pollAnnouncements() {
    if (!window.EduLive || !document.getElementById('annList')) return;
    window.EduLive.onDigest(function (d) {
      // digest already carries notification unread; announcement pill below
      // re-fetches only when the server reports announcement keywords
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', pollAnnouncements);
  else pollAnnouncements();
})();
