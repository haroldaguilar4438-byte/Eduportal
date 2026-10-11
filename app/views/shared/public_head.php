<?php
/**
 * Shared public <head> — styling entry point for the public school website
 * (home page, news/blog). Uses the same EduPortal academic design system
 * as the authenticated portal so the identity is consistent everywhere.
 *
 * @var string|null $title
 * @var string|null $metaDescription
 */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="csrf-token" content="<?= e(\App\Core\Csrf::token()) ?>">
<title><?= e($title ?? 'Datamex College of Saint Adeline') ?> &middot; EduPortal</title>
<meta name="description" content="<?= e($metaDescription ?? 'Datamex College of Saint Adeline — a complete school information system. Enroll online, track grades, permits and payments through EduPortal.') ?>">

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
          navy: { 50: "#F0F5FA", 100: "#E1EAF3", 200: "#C9DAEA", 300: "#A8C4DE", 400: "#7FA9CE", 500: "#4A86B8", 600: "#2E6DA4", 700: "#173B6C", 800: "#122E52", 900: "#0D2340", 950: "#081729" },
          gold: { 50: "#FCF6EA", 100: "#F7EAD0", 200: "#F2DDAE", 300: "#EBC77C", 400: "#E3B459", 500: "#D9A441", 600: "#C08F2E", 700: "#A87A22", 800: "#8A6316" }
        },
        borderRadius: { xl: "12px", "2xl": "14px" }
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

<style id="eduPublicTheme">
  /* Theme bootstrap — applied before first paint. */
  html { background: #F5F7FA; }
  html.dark, html[data-theme="dark"] { background: #0B1524; }

  .section-pad { padding: 68px 0; }
  .section-head { max-width: 60ch; margin-bottom: 2.25rem; }
  .section-eyebrow {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .7rem; font-weight: 800; letter-spacing: .13em; text-transform: uppercase;
    color: var(--accent-700); background: var(--accent-50);
    border: 1px solid var(--accent-200); padding: .3rem .6rem; border-radius: 999px;
    margin-bottom: .75rem;
  }
  .section-title {
    font-family: var(--font-display); font-weight: 800;
    font-size: clamp(1.5rem, 3vw, 2rem); line-height: 1.2;
    color: var(--text-primary); margin: 0 0 .6rem; letter-spacing: -.02em;
  }
  .section-lede { font-size: 1rem; color: var(--text-tertiary); margin: 0; line-height: 1.7; }

  /* Public top navigation */
  .nav-public { background: rgba(255, 255, 255, .93); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); }
  .nav-public .navbar-brand { font-weight: 800; color: var(--primary-900) !important; }
  .nav-public .nav-link { color: var(--text-secondary); font-weight: 500; font-size: .9rem; }
  .nav-public .nav-link:hover { color: var(--primary-700); }
  .nav-public .nav-link.active { color: var(--primary-800) !important; font-weight: 700; }

  @media (prefers-reduced-motion: reduce) {
    .card, .program-card, .reveal { animation: none !important; transition: none !important; }
  }
  @media (max-width: 575.98px) {
    .section-pad { padding: 44px 0; }
  }
</style>

<script>
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
  document.addEventListener('DOMContentLoaded', function () {
    var isDark = document.documentElement.classList.contains('dark');
    document.querySelectorAll('.theme-toggle i').forEach(function (ic) {
      ic.className = isDark ? 'bi bi-sun' : 'bi bi-moon';
    });
    var reduce = false;
    try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
    if (reduce) { document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('active'); }); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add('active'); io.unobserve(en.target); }
      });
    }, { threshold: 0.08 });
    document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
  });
})();
</script>
