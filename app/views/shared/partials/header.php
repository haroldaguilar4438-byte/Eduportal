<?php
/**
 * Shared Header Partial — Global meta, fonts, CSS/JS entry points.
 * Datamex College of Saint Adeline — EduPortal
 *
 * The visual identity (navy + gold) lives in assets/css/design-system.css.
 * Only page-load choreography, theme bootstrapping and small motion helpers
 * live here, so the stylesheet stays the single source of truth.
 */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="csrf-token" content="<?= e(\App\Core\Csrf::token()) ?>">
<title><?= e($title ?? APP_NAME) ?> &middot; <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset('img/school-logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    darkMode: 'class',
    theme: {
      extend: {
        fontFamily: { sans: ["Inter", "system-ui", "sans-serif"], display: ["Plus Jakarta Sans", "Inter", "sans-serif"] },
        colors: {
          /* Academic navy identity */
          navy:  { 50: "#F0F5FA", 100: "#E1EAF3", 200: "#C9DAEA", 300: "#A8C4DE", 400: "#7FA9CE", 500: "#4A86B8", 600: "#2E6DA4", 700: "#173B6C", 800: "#122E52", 900: "#0D2340", 950: "#081729" },
          /* Restrained gold accent */
          gold:  { 50: "#FCF6EA", 100: "#F7EAD0", 200: "#F2DDAE", 300: "#EBC77C", 400: "#E3B459", 500: "#D9A441", 600: "#C08F2E", 700: "#A87A22", 800: "#8A6316" }
        },
        borderRadius: { xl: "12px", "2xl": "14px" },
        boxShadow: {
          soft:   "0 2px 8px rgba(13,35,64,.06)",
          medium: "0 8px 24px rgba(13,35,64,.08)",
          large:  "0 18px 44px rgba(13,35,64,.12)"
        }
      }
    }
  };
</script>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
<link href="<?= e(asset('css/mobile.css')) ?>" rel="stylesheet">
<link href="<?= e(asset('css/design-system.css')) ?>" rel="stylesheet">
<link href="<?= e(asset('css/responsive.css')) ?>" rel="stylesheet">

<style id="eduThemeCritical">
  /* Theme bootstrap — applied before first paint to avoid a flash of the wrong theme. */
  html { background: #F5F7FA; }
  html.dark, html[data-theme="dark"] { background: #0B1524; }

  /* Small, purposeful motion only — no blanket icon animation. */
  @keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
  @keyframes ripple   { from { transform: scale(0); opacity: .45; } to { transform: scale(4); opacity: 0; } }
  @keyframes countUp  { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
  @keyframes dotPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.3); } }

  .btn { transition: transform var(--dur-fast, .15s) var(--ease-out, ease), box-shadow var(--dur-fast, .15s), background var(--dur-fast, .15s); }
  .btn:hover { transform: translateY(-1px); }
  .btn:active { transform: translateY(0) scale(.98); }
  .btn-ripple { position: relative; overflow: hidden; }
  .ripple-circle {
    position: absolute; border-radius: 50%; background: rgba(255, 255, 255, .4);
    pointer-events: none; transform: scale(0); animation: ripple .55s linear;
    width: 20px; height: 20px; margin: -10px 0 0 -10px;
  }
  .stat-count { display: inline-block; animation: countUp .5s var(--ease-out, ease) both; }
  .stat-count-pulse, .badge-pulse {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%;
    background: var(--danger, #C73E3A); animation: dotPulse 1.4s ease-in-out infinite;
  }
  .badge-pulse.amber { background: var(--warning, #D99A00); }
  .badge-pulse.green { background: var(--success, #198754); }

  /* Gold accent gradient text — used sparingly on public hero headings. */
  .gradient-text {
    background: linear-gradient(100deg, #FFFFFF 0%, #EBC77C 60%, #D9A441 100%);
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent; background-size: 200% 100%;
  }
  .section-wave { line-height: 0; overflow: hidden; display: block; }
  .section-wave svg { display: block; width: 100%; height: 48px; }
  .section-wave.flip { transform: scaleY(-1); }
  .edu-blob {
    position: absolute; border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%;
    filter: blur(46px); opacity: .16; pointer-events: none;
  }
  .edu-blob.gold   { background: radial-gradient(circle at 30% 30%, #D9A441, #173B6C); width: 340px; height: 280px; }
  .edu-blob.navy   { background: radial-gradient(circle at 30% 30%, #2E6DA4, #0D2340); width: 400px; height: 340px; }

  .theme-toggle { color: var(--text-secondary, #475467); }
  .blink-dot {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%;
    background: var(--success, #198754); animation: dotPulse 1.2s ease-in-out infinite; margin-right: 4px;
  }
</style>

<style id="eduMobileCritical">
  /* Critical mobile shell: the sidebar becomes a drawer and content never overflows. */
  @media (max-width: 1023.98px) {
    body > div.flex.min-h-screen, .app-shell { flex-direction: column !important; }
    body > div.flex.min-h-screen > aside#desktopSidebar, .app-shell > aside#desktopSidebar { display: none !important; }
    body > div.flex.min-h-screen > div.flex-1, .app-shell > div.flex-1 { width: 100% !important; min-width: 0 !important; max-width: 100vw !important; overflow-x: clip; }
    body > div.flex.min-h-screen > div.flex-1 > main, .app-shell main { width: 100% !important; max-width: 100vw !important; }
    body > div.flex.min-h-screen > div.flex-1 > main > div, .app-shell main > div, .app-shell main .max-w-7xl { max-width: 100% !important; min-width: 0 !important; }
    .card, .edu-card, .alert, .table-responsive, .edu-table-wrap { max-width: 100% !important; min-width: 0 !important; }
  }
</style>

<script>
/* Theme: read stored preference (or OS preference) and apply before paint. */
(function () {
  var key = 'eduTheme';
  var saved = null;
  try { saved = localStorage.getItem(key); } catch (e) {}
  var prefers = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  var theme = saved || prefers;
  if (theme === 'dark') {
    document.documentElement.classList.add('dark');
    document.documentElement.setAttribute('data-theme', 'dark');
  }
  window.eduToggleTheme = function () {
    var isDark = document.documentElement.classList.contains('dark');
    var next = isDark ? 'light' : 'dark';
    document.documentElement.classList.toggle('dark', next === 'dark');
    if (next === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    else document.documentElement.removeAttribute('data-theme');
    try { localStorage.setItem(key, next); } catch (e) {}
    document.querySelectorAll('.theme-toggle i').forEach(function (ic) {
      ic.className = next === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
    });
  };
})();
</script>

<script>
/* Sync every theme-toggle icon, and add one to containers that lack it. */
(function () {
  function syncIcons() {
    var isDark = document.documentElement.classList.contains('dark');
    document.querySelectorAll('.theme-toggle i').forEach(function (ic) {
      ic.className = isDark ? 'bi bi-sun' : 'bi bi-moon';
    });
  }
  function ensureToggles() {
    var authLayout = document.querySelector('.auth-layout, .auth-hero');
    if (authLayout && !document.querySelector('.auth-layout .theme-toggle, .auth-hero .theme-toggle')) {
      var f = document.createElement('button');
      f.type = 'button';
      f.className = 'theme-toggle';
      f.title = 'Toggle dark mode';
      f.setAttribute('aria-label', 'Toggle dark mode');
      f.style.cssText = 'position:fixed;right:14px;top:14px;z-index:9999';
      f.onclick = window.eduToggleTheme;
      document.body.appendChild(f);
      syncIcons();
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { syncIcons(); ensureToggles(); });
  else { syncIcons(); ensureToggles(); }
})();
</script>

<script>
/* Reveal-on-scroll for cards and stat tiles. Respects reduced motion. */
(function () {
  var reduce = false;
  try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
  function initReveal() {
    var els = document.querySelectorAll('.card:not(.no-reveal), .stat-card, .edu-stat, .edu-card--hover, .program-card, .alert');
    if (reduce) { els.forEach(function (el) { el.classList.add('active'); }); return; }
    var io;
    try {
      io = new IntersectionObserver(function (entries) {
        entries.forEach(function (ent) {
          if (ent.isIntersecting) { ent.target.classList.add('active'); io.unobserve(ent.target); }
        });
      }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    } catch (e) { els.forEach(function (el) { el.classList.add('active'); }); return; }
    els.forEach(function (el, i) {
      if (!el.classList.contains('reveal')) {
        el.classList.add('reveal');
        el.style.transitionDelay = (Math.min(i % 6, 5) * 0.05) + 's';
      }
      io.observe(el);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initReveal);
  else initReveal();
})();
</script>

<script>
/* Count-up for KPI figures declared with .stat-count[data-target]. */
(function () {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () {
    var els = document.querySelectorAll('.stat-count');
    if (!els.length) return;
    var reduce = false;
    try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
    els.forEach(function (el) {
      var target = parseInt(el.dataset.target || el.textContent.replace(/[^0-9]/g, ''), 10) || 0;
      var suffix = el.dataset.suffix || '';
      if (reduce) { el.textContent = target.toLocaleString() + suffix; return; }
      el.dataset.counted = '1';
      var t0 = null;
      function step(ts) {
        if (!t0) t0 = ts;
        var p = Math.min((ts - t0) / 1000, 1);
        var eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.floor(eased * target).toLocaleString() + (p < 1 ? '' : suffix);
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  });
})();
</script>
