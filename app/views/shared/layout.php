<?php
/**
 * Shared Application Shell — EduPortal
 *
 * Preserved (non-destructive): App\Core\Auth usage, $user/$role, flash_field(),
 * $content, $title, the live-notification wiring, the announcement banner and
 * every route referenced by the topbar links.
 *
 * Redesigned: the top bar is now a real application bar — breadcrumb trail,
 * global search, academic period selector, notifications, help and a user menu
 * that includes the profile/identity block, so the sidebar no longer has to
 * carry it on every page.
 */
use App\Core\Auth;

$user = Auth::user();
$role = Auth::role();

/* Global notification bell data (fail-open: a missing table never breaks a page) */
$notifUnread = 0;
$notifRecent = [];
if (($user ?? null) !== null) {
    try {
        $notifUnread = \App\Controllers\NotificationController::unreadCountFor((int) ($user['id'] ?? 0));
        $notifRecent = \App\Controllers\NotificationController::recentFor((int) ($user['id'] ?? 0), 8);
    } catch (Throwable $e) {
        $notifUnread = 0;
        $notifRecent = [];
    }
}

/* ---- Academic period (best-effort, never fatal) ------------------------- */
$periodLabel = 'Academic period';
if (($user ?? null) !== null) {
    try {
        $curAy = \App\Core\Database::one("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
        if ($curAy) {
            $periodLabel = trim((string) ($curAy['school_year'] ?? ''));
            $term = trim((string) ($curAy['semester'] ?? $curAy['term'] ?? ''));
            if ($term !== '') {
                $periodLabel .= ($periodLabel !== '' ? ' · ' : '') . $term;
            }
        }
    } catch (Throwable $e) {
        $periodLabel = 'Academic period';
    }
}

/* ---- Identity ---------------------------------------------------------- */
$initials = $user !== null ? edu_initials($user['first_name'] ?? '', $user['last_name'] ?? '') : 'U';
$fullName = 'Guest';
if ($user !== null) {
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    if ($fullName === '') {
        $fullName = (string) ($user['email'] ?? 'User');
    }
}
$avatarUrl = null;
if ($user !== null && !empty($user['avatar_path'])) {
    $candidate = ROOT_PATH . '/public_html/' . ltrim((string) $user['avatar_path'], '/');
    if (is_file($candidate)) {
        $avatarUrl = url($user['avatar_path']);
    }
}

/* ---- Breadcrumb -------------------------------------------------------- */
$crumbs = [['label' => 'Portal', 'url' => url('/dashboard')]];
$pageTitle = (string) ($title ?? '');
if ($pageTitle !== '') {
    $crumbs[] = ['label' => $pageTitle];
} else {
    $crumbs[count($crumbs) - 1]['label'] = ucfirst((string) ($role ?? 'guest')) . ' workspace';
}
?>

<!doctype html>
<html lang="en">
<head>
  <?php include __DIR__ . '/partials/header.php'; ?>
</head>
<body>
<a class="skip-link" href="#eduMain">Skip to main content</a>
<div id="eduPageLoader" aria-hidden="true"></div>

<div class="app-shell edu-shell">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <div class="edu-main-col">
    <!-- ================= Top bar ================= -->
    <header class="edu-topbar hidden lg:flex lg:items-center lg:justify-between lg:gap-4 lg:px-6 lg:py-3 sticky top-0 z-30">
      <div class="d-flex align-items-center gap-3 min-w-0 flex-1">
        <button type="button" data-sidebar-toggle class="edu-collapse-btn sidebar-toggle"
                title="Collapse navigation" aria-label="Collapse navigation">
          <i class="bi bi-layout-sidebar" aria-hidden="true"></i>
        </button>
        <?= edu_breadcrumb($crumbs) ?>
      </div>

      <div class="d-flex align-items-center gap-2 flex-none">
        <!-- Global search -->
        <div class="edu-topbar__search d-none d-xl-block">
          <i class="bi bi-search" aria-hidden="true"></i>
          <label class="visually-hidden" for="eduGlobalSearch">Search the portal</label>
          <input type="search" id="eduGlobalSearch" placeholder="Search students, permits, payments…"
                 autocomplete="off" role="combobox" aria-expanded="false" aria-controls="eduSearchResults"
                 aria-autocomplete="list" aria-describedby="eduSearchHint" data-suggest-url="<?= e(url('/search/suggest')) ?>">
          <span class="edu-topbar__kbd" id="eduSearchHint">/</span>
          <div id="eduSearchResults" class="edu-search-results" role="listbox" aria-label="Search suggestions" hidden></div>
        </div>

        <!-- Academic period -->
        <span class="edu-topbar__period d-none d-xl-inline-flex" title="Current academic year and period">
          <i class="bi bi-calendar3" aria-hidden="true"></i>
          <span><?= e($periodLabel) ?></span>
        </span>

        <?php if (($user ?? null) !== null) include __DIR__ . '/partials/notifications_bell.php'; ?>

        <!-- Help -->
        <a href="<?= e(url('/support')) ?>" class="edu-topbar-icon d-none d-md-inline-flex" title="Help &amp; support" aria-label="Help and support">
          <i class="bi bi-question-circle" aria-hidden="true"></i>
        </a>

        <!-- Dark mode -->
        <button type="button" class="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode" onclick="eduToggleTheme()">
          <i class="bi bi-moon" aria-hidden="true"></i>
        </button>

        <!-- User menu -->
        <?php if (($user ?? null) !== null): ?>
        <div class="dropdown">
          <button type="button" class="edu-user-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
            <?php if ($avatarUrl !== null): ?>
              <img src="<?= e($avatarUrl) ?>" alt="" class="edu-avatar edu-avatar--sm">
            <?php else: ?>
              <span class="edu-avatar edu-avatar--sm edu-avatar--fallback" aria-hidden="true"><?= e($initials) ?></span>
            <?php endif; ?>
            <span class="edu-user-btn__text d-none d-lg-block">
              <span class="edu-identity__name"><?= e($fullName) ?></span>
              <span class="edu-identity__meta"><?= e(ucfirst((string) ($role ?? ''))) ?></span>
            </span>
            <i class="bi bi-chevron-down edu-user-btn__caret" aria-hidden="true"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" style="min-width:230px">
            <li class="px-3 py-2">
              <div class="d-flex align-items-center gap-2">
                <?php if ($avatarUrl !== null): ?>
                  <img src="<?= e($avatarUrl) ?>" alt="" class="edu-avatar edu-avatar--sm">
                <?php else: ?>
                  <span class="edu-avatar edu-avatar--sm edu-avatar--fallback" aria-hidden="true"><?= e($initials) ?></span>
                <?php endif; ?>
                <div class="min-w-0">
                  <div class="edu-identity__name text-truncate"><?= e($fullName) ?></div>
                  <div class="edu-identity__meta text-truncate"><?= e((string) ($user['email'] ?? '')) ?></div>
                </div>
              </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i class="bi bi-person me-2"></i> My profile</a></li>
            <li><a class="dropdown-item" href="<?= e(url('/security')) ?>"><i class="bi bi-shield-lock me-2"></i> Security</a></li>
            <li><a class="dropdown-item" href="<?= e(url('/notifications')) ?>"><i class="bi bi-bell me-2"></i> Notifications</a></li>
            <li><a class="dropdown-item" href="<?= e(url('/')) ?>"><i class="bi bi-house me-2"></i> School website</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="<?= e(url('/logout')) ?>" class="px-2">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="dropdown-item text-danger">
                  <i class="bi bi-box-arrow-right me-2"></i> Sign out
                </button>
              </form>
            </li>
          </ul>
        </div>
        <?php else: ?>
        <a href="<?= e(url('/login')) ?>" class="btn btn-primary btn-sm">Sign in</a>
        <?php endif; ?>
      </div>
    </header>

    <!-- ================= Content ================= -->
    <main id="eduMain" class="edu-content" tabindex="-1">
      <?php
        /* Preserve the original flash rendering (server-side messages). */
        $flashHtml = flash_field();
        if (trim($flashHtml) !== '') {
            echo '<div class="edu-flash" role="status" aria-live="polite">' . $flashHtml . '</div>';
        }
      ?>

      <?php
        /* Role-filtered announcement banner on dashboards (fail-open). */
        try {
          if (($user ?? null) !== null && is_active_path('/dashboard', true)) {
            include __DIR__ . '/partials/announcement_banner.php';
          }
        } catch (Throwable $e) {}
      ?>

      <?= $content ?>
    </main>

    <?php include __DIR__ . '/partials/footer.php'; ?>
  </div>
</div>

<script src="<?= e(asset('js/chatbot.js')) ?>"></script>
<script src="<?= e(asset('js/design-system.js')) ?>" defer></script>
<?php if (($user ?? null) !== null): ?>
<script>
  window.EduLiveConfig = {
    feedUrl: <?= json_encode(url('/notifications/feed')) ?>,
    digestUrl: <?= json_encode(url('/api/live/digest')) ?>,
    userId: <?= (int)($user['id'] ?? 0) ?>,
    role: <?= json_encode($role ?? '') ?>,
    baseInterval: 15000,
    hiddenInterval: 120000
  };
</script>
<script src="<?= e(asset('js/edulive.js')) ?>"></script>
<script>
  (function () {
    if (!window.EduLive) return;

    /* Per-page refresh interests: when a relevant notification arrives we show
       a user-initiated "N new updates — Refresh" affordance. We never force a
       reload, so search / filter / pagination state is never destroyed. */
    var path = <?= json_encode(current_path()) ?>;
    var rules = [
      [['/registrar/sheets', '/registrar/grades', '/teacher/grades'], ['grade', 'sheet', 'submission', 'finalized', 'returned']],
      [['/registrar/permits', '/admin/permits', '/teacher/permits', '/accounting/permits', '/student/permits'], ['permit']],
      [['/registrar/documents', '/accounting/documents', '/student/documents', '/student/enrollment-docs'], ['document']],
      [['/registrar/clearances', '/teacher/clearances', '/admin/clearances', '/student/clearances'], ['clearance']],
      [['/registrar/enrollments', '/admin/enrollments', '/accounting/enrollments', '/my-enrollments', '/enrollment/eligibility', '/enroll'], ['enroll', 'payment', 'clearance']],
      [['/admin/payments', '/accounting/payments', '/student/ledger'], ['payment', 'payrex', 'receipt', 'charge']],
      [['/classrooms/', '/classroom-tasks/'], ['task', 'submission', 'joined', 'member', 'request']],
      [['/student/my-classes', '/student/schedule', '/student/class-offerings'], ['subject', 'classroom', 'joined', 'member']],
      [['/student/grades'], ['grade', 'released', 'finalized']],
      [['/announcements', '/admin/announcements', '/news', '/admin/blog', '/admin/content'], ['announcement', 'news', 'post', 'blog']],
      [['/dashboard', '/notifications'], ['task', 'permit', 'clearance', 'grade', 'enroll', 'payment', 'document', 'announcement', 'joined', 'member']]
    ];

    function hit(prefix) {
      if (prefix.charAt(prefix.length - 1) === '/') prefix = prefix.slice(0, -1);
      return path === prefix || path.indexOf(prefix + '/') === 0;
    }

    for (var i = 0; i < rules.length; i++) {
      for (var j = 0; j < rules[i][0].length; j++) {
        if (hit(rules[i][0][j])) {
          EduLive.refreshOnEvent({ match: rules[i][1] });
          return;
        }
      }
    }
  })();
</script>
<?php endif; ?>
<script>
/* Global search: "/" focuses the field; typing fetches role-scoped
   suggestions from /search/suggest and renders them with textContent only
   (server data is never injected as HTML). */
(function () {
var input = document.getElementById('eduGlobalSearch');
if (!input) return;
var box = document.getElementById('eduSearchResults');
var endpoint = input.getAttribute('data-suggest-url') || '/search/suggest';
var timer = null;
var activeIndex = -1;
var currentItems = [];

document.addEventListener('keydown', function (ev) {
if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey) return;
var tag = (document.activeElement && document.activeElement.tagName) || '';
if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
ev.preventDefault();
input.focus();
});

function closeResults() {
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
  input.setAttribute('aria-expanded', 'false');
  input.removeAttribute('aria-activedescendant');
  activeIndex = -1;
  currentItems = [];
}

function openResults() {
  if (!box) return;
  box.hidden = false;
  input.setAttribute('aria-expanded', 'true');
}

function goTo(url) {
  /* Server links are absolute; only follow same-origin targets so a
     compromised payload can never bounce the user off-site. */
  if (typeof url !== 'string' || url === '') return;
  try {
    var target = new URL(url, window.location.href);
    if (target.origin === window.location.origin) {
      window.location.href = target.href;
    }
  } catch (e) {
    /* Malformed URL — stay put. */
  }
}

function paint(items) {
  currentItems = items;
  activeIndex = -1;
  box.textContent = '';
  if (!items.length) {
    var empty = document.createElement('div');
    empty.className = 'edu-search-empty';
    empty.textContent = 'No matches. Try a name, number, or reference.';
    box.appendChild(empty);
    openResults();
    return;
  }
  var lastGroup = null;
  items.forEach(function (item, idx) {
    if (item.group !== lastGroup) {
      lastGroup = item.group;
      var head = document.createElement('div');
      head.className = 'edu-search-group';
      head.textContent = item.group || 'Results';
      box.appendChild(head);
    }
    var opt = document.createElement('div');
    opt.className = 'edu-search-item';
    opt.id = 'edu-search-opt-' + idx;
    opt.setAttribute('role', 'option');
    var icon = document.createElement('i');
    icon.className = 'bi ' + (item.icon || 'bi-search');
    icon.setAttribute('aria-hidden', 'true');
    var wrap = document.createElement('span');
    wrap.className = 'edu-search-text';
    var title = document.createElement('strong');
    title.textContent = item.title || '';
    var sub = document.createElement('small');
    sub.textContent = item.sub || '';
    wrap.appendChild(title);
    wrap.appendChild(sub);
    opt.appendChild(icon);
    opt.appendChild(wrap);
    opt.addEventListener('mousedown', function (ev) {
      ev.preventDefault();
      goTo(item.url);
    });
    box.appendChild(opt);
  });
  openResults();
}

function highlight(idx) {
  var nodes = box.querySelectorAll('.edu-search-item');
  nodes.forEach(function (n) { n.classList.remove('is-active'); });
  if (idx < 0 || idx >= nodes.length) {
    activeIndex = -1;
    input.removeAttribute('aria-activedescendant');
    return;
  }
  activeIndex = idx;
  nodes[idx].classList.add('is-active');
  input.setAttribute('aria-activedescendant', nodes[idx].id);
  if (nodes[idx].scrollIntoView) {
    nodes[idx].scrollIntoView({ block: 'nearest' });
  }
}

function fetchSuggest(query) {
  fetch(endpoint + '?q=' + encodeURIComponent(query), {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin'
  }).then(function (r) {
    return r.ok ? r.json() : null;
  }).then(function (data) {
    if (input.value.trim() !== query) return;
    if (!data || !data.ok || !Array.isArray(data.items)) {
      closeResults();
      return;
    }
    paint(data.items.slice(0, 12));
  }).catch(function () {
    closeResults();
  });
}

input.addEventListener('input', function () {
  var q = input.value.trim();
  if (timer) clearTimeout(timer);
  if (q.length < 2) {
    closeResults();
    return;
  }
  timer = setTimeout(function () { fetchSuggest(q); }, 250);
});

input.addEventListener('keydown', function (ev) {
  if (box.hidden) return;
  var nodes = box.querySelectorAll('.edu-search-item');
  if (ev.key === 'ArrowDown') {
    ev.preventDefault();
    highlight(nodes.length ? (activeIndex + 1) % nodes.length : -1);
  } else if (ev.key === 'ArrowUp') {
    ev.preventDefault();
    highlight(nodes.length ? (activeIndex - 1 + nodes.length) % nodes.length : -1);
  } else if (ev.key === 'Enter' && activeIndex >= 0 && currentItems[activeIndex]) {
    ev.preventDefault();
    goTo(currentItems[activeIndex].url);
  } else if (ev.key === 'Escape') {
    closeResults();
    input.blur();
  }
});

input.addEventListener('blur', function () {
  setTimeout(closeResults, 150);
});

document.addEventListener('click', function (ev) {
  if (box && !box.hidden && !input.contains(ev.target) && !box.contains(ev.target)) {
    closeResults();
  }
});
})();
</script>
</body>
</html>
