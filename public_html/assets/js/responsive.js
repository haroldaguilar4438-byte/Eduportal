/* EduPortal Responsive JS — presentation only.
 * No routes, logic, or workflows changed. Defensive: every block guards
 * for missing elements and against double-binding. Respects
 * prefers-reduced-motion. Safe to load alongside mobile.js / design-system.js.
 */
(function () {
  'use strict';
  if (window.EduResponsive) return;
  window.EduResponsive = { version: '1.0' };

  var reduceMotion = false;
  try { reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  function mqMobile() {
    try { return window.matchMedia('(max-width: 1023.98px)').matches; }
    catch (e) { return window.innerWidth < 1024; }
  }

  /* ---------- 1. Drawer: unify inline script + mobile.js behaviour ---------- */
  function initDrawer() {
    var btn = document.getElementById('mobileMenuBtn');
    var drawer = document.getElementById('mobileDrawer');
    if (!btn || !drawer) return;
    if (drawer.dataset.rBound) return;
    drawer.dataset.rBound = '1';
    var closeBtn = document.getElementById('drawerClose');
    var backdrop = document.getElementById('drawerBackdrop');
    var panel = drawer.querySelector('.edu-drawer-panel');
    var lastFocus = null;

    function focusables() {
      return Array.prototype.slice.call(
        drawer.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])')
      ).filter(function (el) { return el.offsetParent !== null; });
    }
    function open(e) {
      if (e) e.preventDefault();
      lastFocus = document.activeElement;
      drawer.hidden = false;
      drawer.classList.remove('hidden');
      // next frame -> transition
      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          drawer.classList.add('is-open');
          drawer.classList.add('open');
        });
      });
      document.body.style.overflow = 'hidden';
      btn.setAttribute('aria-expanded', 'true');
      var f = focusables();
      if (f.length && closeBtn) { try { closeBtn.focus({ preventScroll: true }); } catch (err) { closeBtn.focus(); } }
    }
    function hide(restore) {
      drawer.classList.remove('is-open');
      drawer.classList.remove('open');
      document.body.style.overflow = '';
      btn.setAttribute('aria-expanded', 'false');
      setTimeout(function () {
        if (!drawer.classList.contains('is-open') && !drawer.classList.contains('open')) {
          drawer.hidden = true;
        }
      }, 320);
      if (restore !== false && lastFocus && lastFocus.focus) {
        try { lastFocus.focus({ preventScroll: true }); } catch (err) { btn.focus(); }
      } else { try { btn.focus({ preventScroll: true }); } catch (err) {} }
    }
    // Remove stale listeners risk: mark trigger so mobile.js skips rebinding.
    btn.dataset.mBound = '1';
    btn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', function () { hide(false); closeBtn.blur(); btn.focus(); });
    if (backdrop) backdrop.addEventListener('click', function () { hide(); });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && (drawer.classList.contains('is-open') || drawer.classList.contains('open'))) hide();
      // simple focus trap
      if (ev.key === 'Tab' && (drawer.classList.contains('is-open') || drawer.classList.contains('open'))) {
        var f = focusables();
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
        else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
      }
    });
    // Close after navigation
    drawer.querySelectorAll('.edu-drawer-nav a').forEach(function (a) {
      a.addEventListener('click', function () { hide(false); });
    });
    // Swipe-to-close (leftward swipe on panel)
    if (panel && !reduceMotion) {
      var sx = null;
      panel.addEventListener('touchstart', function (e) {
        if (e.touches && e.touches[0]) sx = e.touches[0].clientX;
      }, { passive: true });
      panel.addEventListener('touchmove', function (e) {
        if (sx === null || !e.touches || !e.touches[0]) return;
        if (e.touches[0].clientX - sx < -60) { hide(); sx = null; }
      }, { passive: true });
    }
    window.EduDrawer = { open: open, close: hide };
  }

  /* ---------- 2. Table data-labels for .edu-table--cards ---------- */
  function labelTables(root) {
    (root || document).querySelectorAll('table.edu-table--cards').forEach(function (tbl) {
      if (tbl.dataset.labeled) return;
      var heads = Array.prototype.map.call(
        tbl.querySelectorAll('thead th'),
        function (th) { return (th.textContent || '').replace(/\s+/g, ' ').trim(); }
      );
      if (!heads.length) return;
      tbl.querySelectorAll('tbody tr').forEach(function (tr) {
        Array.prototype.forEach.call(tr.children, function (td, i) {
          if (td.tagName !== 'TD' && td.tagName !== 'TH') return;
          if (!td.getAttribute('data-label') && heads[i]) td.setAttribute('data-label', heads[i]);
          if (td.classList.contains('text-end') || td.querySelector('.btn')) td.classList.add('edu-table__actions-cell');
        });
      });
      tbl.dataset.labeled = '1';
    });
  }

  /* ---------- 3. Pagination compaction on small screens ---------- */
  function compactPagination() {
    if (!mqMobile()) return;
    document.querySelectorAll('.pagination').forEach(function (pg) {
      if (pg.dataset.compacted) return;
      var items = Array.prototype.slice.call(pg.querySelectorAll('.page-item'));
      // Hide middle numbers when there are many, keep first/last/active/prev/next.
      var nums = items.filter(function (li) {
        return !li.classList.contains('active') && li.querySelector('.page-link') &&
          /^\d+$/.test((li.textContent || '').trim());
      });
      if (nums.length > 5) {
        nums.slice(1, -1).forEach(function (li, idx) {
          if (idx % 2 === 0) li.style.display = 'none';
        });
        pg.dataset.compacted = '1';
      }
    });
  }

  /* ---------- 4. Charts: keep Chart.js canvases inside their cards ---------- */
  function fixCharts() {
    document.querySelectorAll('canvas').forEach(function (cv) {
      var parent = cv.parentElement;
      if (!parent) return;
      var ps = window.getComputedStyle ? window.getComputedStyle(parent) : null;
      if (ps && ps.position === 'static') parent.style.position = 'relative';
      if (!parent.style.minWidth) parent.style.minWidth = '0';
      parent.style.maxWidth = '100%';
      parent.style.overflowX = 'hidden';
      cv.style.maxWidth = '100%';
    });
    // Re-fire Chart.js resize after drawer/layout changes (guarded).
    var t = null;
    window.addEventListener('resize', function () {
      if (t) clearTimeout(t);
      t = setTimeout(function () { window.dispatchEvent(new Event('edu:resize')); }, 200);
    });
  }

  /* ---------- 5. Long filter rows: collapsible on phones (guarded) ---------- */
  function collapsibleFilters() {
    if (!mqMobile()) return;
    document.querySelectorAll('form[method="get"]').forEach(function (form) {
      if (form.dataset.rFilterBound) return;
      var rows = form.querySelectorAll('.row');
      var target = null;
      rows.forEach(function (row) {
        if (row.children.length >= 4 && row.querySelector('select, input[type="date"]')) target = target || row;
      });
      if (!target) return;
      form.dataset.rFilterBound = '1';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-outline-secondary btn-sm w-100 mb-2';
      btn.innerHTML = '<i class="bi bi-funnel"></i> Show filters';
      btn.setAttribute('aria-expanded', 'false');
      target.parentNode.insertBefore(btn, target);
      target.style.display = 'none';
      btn.addEventListener('click', function () {
        var isHidden = target.style.display === 'none';
        target.style.display = isHidden ? '' : 'none';
        btn.innerHTML = isHidden
          ? '<i class="bi bi-chevron-up"></i> Hide filters'
          : '<i class="bi bi-funnel"></i> Show filters';
        btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
      });
    });
  }

  /* ---------- 6. Prevent double form submits (touch double-tap) ---------- */
  function guardSubmits() {
    document.querySelectorAll('form').forEach(function (form) {
      if (form.dataset.rSubmitBound) return;
      form.dataset.rSubmitBound = '1';
      form.addEventListener('submit', function () {
        var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        setTimeout(function () {
          btns.forEach(function (b) {
            if (b.disabled) return;
            b.disabled = true;
            b.classList.add('disabled');
            setTimeout(function () { b.disabled = false; b.classList.remove('disabled'); }, 4000);
          });
        }, 0);
      });
    });
  }

  /* ---------- 7. Sticky action bars never cover the bottom nav ---------- */
  function fixSticky() {
    if (!mqMobile()) return;
    document.querySelectorAll('.m-sticky-actions').forEach(function (el) {
      el.style.bottom = 'calc(68px + 8px + env(safe-area-inset-bottom, 0px))';
    });
  }

  function init() {
    initDrawer();
    labelTables(document);
    compactPagination();
    fixCharts();
    collapsibleFilters();
    guardSubmits();
    fixSticky();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  window.addEventListener('resize', function () { compactPagination(); fixSticky(); });
  // Re-run data-labels after any AJAX-injected content.
  document.addEventListener('edu:content-updated', function () { labelTables(document); });
})();
