/* EduPortal mobile-only JS — no-ops on desktop. Smooth drawer, swipe, to-top. */
(function () {
  var isMobile = function () { return window.matchMedia('(max-width: 1023.98px)').matches; };
  function initDrawer() {
    var btn = document.getElementById('mobileMenuBtn');
    var drawer = document.getElementById('mobileDrawer');
    var close = document.getElementById('drawerClose');
    var backdrop = document.getElementById('drawerBackdrop');
    if (!btn || !drawer) return;
    // responsive.js owns the drawer when present (it handles hidden attr +
    // .is-open + focus trap); skip here to avoid double-binding.
    if (btn.dataset.mBound || drawer.dataset.rBound || window.EduResponsive) return;
    function open() { drawer.hidden = false; drawer.classList.remove('hidden'); requestAnimationFrame(function(){ requestAnimationFrame(function(){ drawer.classList.add('open'); drawer.classList.add('is-open'); }); }); document.body.style.overflow = 'hidden'; btn.setAttribute('aria-expanded', 'true'); }
    function hide() { drawer.classList.remove('open'); drawer.classList.remove('is-open'); document.body.style.overflow = ''; btn.setAttribute('aria-expanded', 'false'); setTimeout(function(){ if (!drawer.classList.contains('open') && !drawer.classList.contains('is-open')) { drawer.hidden = true; } }, 360); }
    btn.dataset.mBound = '1';
    btn.addEventListener('click', open);
    if (close) close.addEventListener('click', hide);
    if (backdrop) backdrop.addEventListener('click', hide);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('open')) hide(); });
    // swipe-to-close (left edge swipe)
    var panel = drawer.querySelector('.m-drawer-panel');
    var sx = null;
    if (panel) {
      panel.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
      panel.addEventListener('touchmove', function (e) {
        if (sx === null) return;
        var dx = e.touches[0].clientX - sx;
        if (dx < -60) { hide(); sx = null; }
      }, { passive: true });
    }
  }
  function initToTop() {
    if (document.getElementById('mToTop')) return;
    var b = document.createElement('button');
    b.id = 'mToTop'; b.type = 'button'; b.setAttribute('aria-label', 'Back to top');
    b.innerHTML = '<i class="bi bi-arrow-up"></i>';
    b.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    document.body.appendChild(b);
    var onScroll = function () {
      if (!isMobile()) { b.classList.remove('show'); return; }
      b.classList.toggle('show', window.scrollY > 600);
    };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  }
  function initCollapsibleFilters() {
    if (!isMobile()) return;
    document.querySelectorAll('form[method="get"] .row.g-2').forEach(function (row) {
      if (row.dataset.mBound || row.closest('.card') === null) return;
      // only collapse long filter rows (6+ fields)
      if (row.children.length < 5) return;
      row.dataset.mBound = '1';
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'btn btn-outline-secondary btn-sm m-filter-toggle mb-2';
      btn.innerHTML = '<i class="bi bi-funnel"></i> Show filters';
      row.parentNode.insertBefore(btn, row);
      row.style.display = 'none';
      btn.addEventListener('click', function () {
        var open = row.style.display !== 'none';
        row.style.display = open ? 'none' : '';
        btn.innerHTML = open ? '<i class="bi bi-funnel"></i> Show filters' : '<i class="bi bi-chevron-up"></i> Hide filters';
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ initDrawer(); initToTop(); initCollapsibleFilters(); });
  else { initDrawer(); initToTop(); initCollapsibleFilters(); }
  window.addEventListener('resize', function(){ initCollapsibleFilters(); });
})();
