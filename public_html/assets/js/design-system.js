/* EduPortal Design System — micro-interactions (vanilla JS, no framework).
 * Additive only: never changes form targets, routes, or data.
 * Respects prefers-reduced-motion. Guards against double-binding.
 */
(function () {
  'use strict';
  if (window.EduDS) return;
  window.EduDS = { version: '1.0' };

  var reduceMotion = false;
  try { reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  /* ---------- top page loader ---------- */
  var loader = document.getElementById('eduPageLoader');
  function showLoader() { if (loader) { loader.classList.remove('done'); loader.classList.add('loading'); } }
  function hideLoader() {
    if (!loader) return;
    loader.classList.remove('loading'); loader.classList.add('done');
    setTimeout(function () { loader.classList.remove('done'); }, 400);
  }
  document.addEventListener('submit', function () { showLoader(); }, true);
  window.addEventListener('beforeunload', function () { showLoader(); });
  document.addEventListener('DOMContentLoaded', hideLoader);

  /* ---------- count-up KPI numbers ---------- */
  function countUp(el) {
    if (el.dataset.counted || reduceMotion) return;
    var raw = (el.dataset.target || el.textContent || '').replace(/[^0-9.]/g, '');
    var target = parseFloat(raw);
    if (isNaN(target)) return;
    el.dataset.counted = '1';
    var start = null, dur = 1100;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(eased * target).toLocaleString();
      if (p < 1) requestAnimationFrame(step);
      else el.textContent = target.toLocaleString();
    }
    requestAnimationFrame(step);
  }
  var countIO = null;
  try {
    countIO = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { countUp(en.target); countIO.unobserve(en.target); } });
    }, { threshold: .4 });
  } catch (e) { countIO = null; }
  function bindCounts(root) {
    (root || document).querySelectorAll('.stat-card h2, [data-countup]').forEach(function (el) {
      if (countIO) countIO.observe(el); else countUp(el);
    });
  }

  /* ---------- reveal on scroll ---------- */
  var revealIO = null;
  try {
    revealIO = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('active'); revealIO.unobserve(en.target); } });
    }, { threshold: .1 });
  } catch (e) { revealIO = null; }
  function bindReveal(root) {
    if (reduceMotion) {
      (root || document).querySelectorAll('.reveal').forEach(function (el) { el.classList.add('active'); });
      return;
    }
    (root || document).querySelectorAll('.card:not(.no-reveal), .stat-card, .alert').forEach(function (el) {
      if (!el.classList.contains('reveal')) el.classList.add('reveal');
      if (revealIO) revealIO.observe(el); else el.classList.add('active');
    });
  }

  /* ---------- button press ripple (guarded: header may also bind) ---------- */
  if (!document.documentElement.hasAttribute('data-ripple-bound')) {
    document.documentElement.setAttribute('data-ripple-bound', '1');
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.btn');
      if (!btn || reduceMotion || btn.disabled) return;
      var rect = btn.getBoundingClientRect();
      var s = document.createElement('span');
      s.className = 'ripple-circle';
      s.style.left = (e.clientX - rect.left) + 'px';
      s.style.top = (e.clientY - rect.top) + 'px';
      btn.classList.add('btn-ripple');
      btn.appendChild(s);
      setTimeout(function () { s.remove(); }, 600);
    });
  }

  /* ---------- sidebar collapse (desktop) ---------- */
  function bindCollapse() {
    var toggles = document.querySelectorAll('[data-sidebar-toggle]');
    var shell = document.querySelector('.edu-shell');
    if (!shell || !toggles.length) return;
    try {
      if (window.localStorage && localStorage.getItem('eduSidebar') === 'collapsed' && window.innerWidth >= 1024) {
        shell.classList.add('sidebar-collapsed');
      }
    } catch (e) {}
    toggles.forEach(function (t) {
      t.addEventListener('click', function () {
        shell.classList.toggle('sidebar-collapsed');
        var collapsed = shell.classList.contains('sidebar-collapsed');
        // Tooltips for icon-only mode (a11y + usability)
        shell.querySelectorAll('.edu-sidebar nav a').forEach(function (a) {
          if (collapsed && !a.getAttribute('title')) {
            a.setAttribute('title', a.textContent.replace(/\s+/g, ' ').trim());
          }
        });
        try {
          if (window.localStorage) {
            localStorage.setItem('eduSidebar', collapsed ? 'collapsed' : 'open');
          }
        } catch (e) {}
      });
    });
  }

  /* ---------- bell unread state (subtle shake) ---------- */
  function bindBell() {
    document.querySelectorAll('.notif-bell-wrap').forEach(function (w) {
      var badge = w.querySelector('.notifBadge');
      if (badge && !badge.classList.contains('d-none') && badge.textContent.trim() !== '' && badge.textContent.trim() !== '0') {
        w.classList.add('has-unread');
      }
    });
  }

  /* ---------- auto-dismiss success toasts ---------- */
  function bindToasts() {
    document.querySelectorAll('.alert-success').forEach(function (el) {
      setTimeout(function () {
        el.style.transition = 'opacity .4s, transform .4s';
        el.style.opacity = '0'; el.style.transform = 'translateY(-6px)';
        setTimeout(function () { el.remove(); }, 420);
      }, 4500);
    });
  }

  function init() {
    bindCounts(document);
    bindReveal(document);
    bindCollapse();
    bindBell();
    bindToasts();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  window.EduDS.refresh = init;
})();
