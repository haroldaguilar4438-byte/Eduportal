<?php
/**
 * Shared Sidebar Partial — EduPortal navigation shell.
 *
 * NON-DESTRUCTIVE: every href, role guard, the $isEnrolledForTerm query
 * and the logout CSRF form are preserved exactly as before. Only the
 * markup and the data source changed: nav items are now declared once
 * in a structure and rendered for desktop rail, mobile drawer and the
 * mobile bottom bar, so the three can never drift apart.
 *
 * @var array|null  $user       Auth::user() row
 * @var string|null $role       Auth::role()
 * @var int         $notifUnread
 */
$user = $user ?? \App\Core\Auth::user();
$role = $role ?? \App\Core\Auth::role();
$isGuest = $user === null;

/* ── Enrollment-aware nav ──────────────────────────────────────────
   Non-destructive additive check: hides "Enroll" once the student is
   already enrolled for the active term (existing behaviour).        */
$isEnrolledForTerm = false;
if (!$isGuest && in_array($role, ['student', 'parent'], true) && isset($user['id'])) {
    try {
        $uid = (int) $user['id'];
        $enrolledCount = (int) (\App\Core\Database::value(
            "SELECT COUNT(*) FROM students WHERE user_id = ? AND status = 'enrolled'",
            [$uid]
        ));
        if ($enrolledCount > 0) {
            $isEnrolledForTerm = true;
        } else {
            $curAy = \App\Core\Database::one("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
            if ($curAy && isset($curAy['id'])) {
                $approved = (int) (\App\Core\Database::value(
                    "SELECT COUNT(*) FROM enrollment_applications WHERE user_id = ? AND status = 'approved' AND academic_year_id = ?",
                    [$uid, (int) $curAy['id']]
                ));
                if ($approved > 0) $isEnrolledForTerm = true;
            }
        }
    } catch (Throwable $e) {
        $isEnrolledForTerm = false; // fail open
    }
}

/* ── Nav definition ─────────────────────────────────────────────────
   Each item: [label, path, icon, badge?, badgeTone?, exactMatch?]     */
$roleLabel = ucfirst((string) ($role ?? 'guest'));

/** @var array<string, array<int, array{0:string,1:string,2:string,3?:string,4?:string,5?:bool}>> */
$NAV = [];

/* Shared across every signed-in role */
$NAV['common'] = [
    ['label' => 'Dashboard',    'path' => '/dashboard',    'icon' => 'bi-grid-1x2-fill', 'exact' => true],
    ['label' => 'Notifications', 'path' => '/notifications', 'icon' => 'bi-bell-fill',    'badge' => 'bell'],
    ['label' => 'Announcements', 'path' => '/announcements', 'icon' => 'bi-megaphone-fill'],
    ['label' => 'School News',  'path' => '/news',         'icon' => 'bi-newspaper'],
];

switch ($role) {
    case 'admin':
        $NAV['admin'] = [
            ['label' => 'Enrollments',   'path' => '/admin/enrollments',   'icon' => 'bi-inbox-fill',            'badge' => 'Queue',    'tone' => 'accent'],
            ['label' => 'Class Offerings', 'path' => '/admin/class-offerings', 'icon' => 'bi-easel2-fill'],
            ['label' => 'Payments',      'path' => '/admin/payments',      'icon' => 'bi-credit-card-2-front-fill', 'badge' => 'PayRex'],
            ['label' => 'Tuition Plans', 'path' => '/admin/tuition-plans', 'icon' => 'bi-cash-stack'],
            ['label' => 'Exam Permits',  'path' => '/admin/permits',       'icon' => 'bi-patch-check-fill',      'badge' => '3-tier'],
            ['label' => 'Clearances',    'path' => '/admin/clearances',    'icon' => 'bi-check2-square'],
            ['label' => 'Academic Calendar', 'path' => '/admin/academic-calendar', 'icon' => 'bi-calendar3'],
            ['label' => 'Evaluations',   'path' => '/admin/evaluations',   'icon' => 'bi-star-fill',             'badge' => 'Open'],
            ['label' => 'Content',       'path' => '/admin/content',       'icon' => 'bi-layout-text-window',     'badge' => 'Blog'],
            ['label' => 'Documents',     'path' => '/accounting/documents', 'icon' => 'bi-folder2-open'],
        ];
        $NAV['people'] = [
            ['label' => 'Teachers',  'path' => '/admin/teachers', 'icon' => 'bi-person-badge-fill', 'badge' => 'Invite'],
            ['label' => 'Staff',     'path' => '/admin/staff',    'icon' => 'bi-person-gear'],
            ['label' => 'All Accounts', 'path' => '/admin/users', 'icon' => 'bi-people-fill',     'badge' => 'DB'],
        ];
        $NAV['system'] = [
            ['label' => 'Backups',  'path' => '/admin/backups', 'icon' => 'bi-archive-fill', 'badge' => 'Safety'],
        ];
        break;

    case 'registrar':
        $NAV['registrar'] = [
            ['label' => 'Enrollment Queue', 'path' => '/registrar/enrollments', 'icon' => 'bi-inbox-fill',     'badge' => 'Queue'],
            ['label' => 'Student Records',  'path' => '/registrar/students',   'icon' => 'bi-people-fill'],
            ['label' => 'Grade Sheets',     'path' => '/registrar/sheets',     'icon' => 'bi-journal-check',  'badge' => 'Review'],
            ['label' => 'Grade Verification', 'path' => '/registrar/grades',   'icon' => 'bi-percent',        'badge' => 'Review'],
            ['label' => 'Exam Permits',     'path' => '/registrar/permits',    'icon' => 'bi-patch-check-fill', 'badge' => 'Academic'],
            ['label' => 'Clearances',       'path' => '/registrar/clearances', 'icon' => 'bi-check2-square'],
            ['label' => 'Documents',        'path' => '/registrar/documents',  'icon' => 'bi-file-earmark-text'],
            ['label' => 'Grade Scale',      'path' => '/registrar/scales',     'icon' => 'bi-list-ol'],
            ['label' => 'Re-enrollment',    'path' => '/registrar/re-enrollment', 'icon' => 'bi-arrow-repeat'],
        ];
        break;

    case 'accounting':
        $NAV['finance'] = [
            ['label' => 'Enrollment Queue', 'path' => '/accounting/enrollments', 'icon' => 'bi-inbox-fill',      'badge' => 'Finance'],
            ['label' => 'Payments',          'path' => '/accounting/payments',   'icon' => 'bi-credit-card-2-front-fill', 'badge' => 'PayRex'],
            ['label' => 'Documents',         'path' => '/accounting/documents',  'icon' => 'bi-folder2-open'],
            ['label' => 'Student Accounts',  'path' => '/accounting/students',   'icon' => 'bi-wallet2'],
            ['label' => 'Financial Clearance', 'path' => '/accounting/clearances', 'icon' => 'bi-check2-square'],
            ['label' => 'Exam Permits',      'path' => '/accounting/permits',    'icon' => 'bi-patch-check-fill', 'badge' => 'Finance'],
        ];
        break;

    case 'teacher':
        $NAV['teaching'] = [
            ['label' => 'Grade Sheets',  'path' => '/teacher/grades',      'icon' => 'bi-journal-check'],
            ['label' => 'Permit Queue',  'path' => '/teacher/permits',     'icon' => 'bi-patch-check-fill', 'badge' => 'Verify'],
            ['label' => 'Clearances',    'path' => '/teacher/clearances',  'icon' => 'bi-check2-square',     'badge' => 'Sign'],
        ];
        break;

    case 'student':
    case 'parent':
        $enrollment = $isEnrolledForTerm
            ? [['label' => 'Next Period', 'path' => '/enrollment/eligibility', 'icon' => 'bi-arrow-repeat']]
            : [['label' => 'Enroll', 'path' => '/enroll', 'icon' => 'bi-file-earmark-text', 'exact' => true]];

        $NAV['enrollment'] = array_merge(
            $enrollment,
            [
                ['label' => 'My Applications', 'path' => '/my-enrollments',          'icon' => 'bi-collection'],
                ['label' => 'Enrollment Documents', 'path' => '/student/enrollment-docs', 'icon' => 'bi-folder-check'],
            ]
        );
        $NAV['academics'] = [
            ['label' => 'Class Offerings', 'path' => '/student/class-offerings', 'icon' => 'bi-easel2-fill'],
            ['label' => 'My Classes',      'path' => '/student/my-classes',      'icon' => 'bi-journal-bookmark-fill'],
            ['label' => 'Schedule',        'path' => '/student/schedule',        'icon' => 'bi-calendar-week-fill'],
            ['label' => 'Grades',          'path' => '/student/grades',          'icon' => 'bi-percent'],
            ['label' => 'Program Flowchart', 'path' => '/student/flowchart',     'icon' => 'bi-diagram-3-fill'],
        ];
        $NAV['records'] = [
            ['label' => 'Exam Permits',  'path' => '/student/permits',    'icon' => 'bi-patch-check-fill', 'badge' => 'Permits'],
            ['label' => 'Clearances',    'path' => '/student/clearances', 'icon' => 'bi-check2-square'],
            ['label' => 'Documents',     'path' => '/student/documents',  'icon' => 'bi-folder2-open'],
            ['label' => 'Ledger',        'path' => '/student/ledger',     'icon' => 'bi-wallet2'],
        ];
        break;
}

$NAV['account'] = [
    ['label' => 'Profile',  'path' => '/profile',  'icon' => 'bi-person-fill'],
    ['label' => 'Security', 'path' => '/security', 'icon' => 'bi-shield-lock-fill'],
];

/* ── Render helpers ───────────────────────────────────────────── */

/** Resolve the live notification count for a 'bell' badge. */
$bellCount = (int) ($notifUnread ?? 0);
$bellLabel = $bellCount > 99 ? '99+' : (string) $bellCount;

/** Render one nav link. */
$renderLink = static function (array $item) use ($bellCount, $bellLabel): string {
    $path   = $item['path'];
    $label  = $item['label'];
    $icon   = $item['icon'];
    $badge  = $item['badge'] ?? null;
    $tone   = $item['tone'] ?? '';
    $exact  = !empty($item['exact']);
    $active = is_active_path($path, $exact);

    $badgeHtml = '';
    if ($badge === 'bell' && $bellCount > 0) {
        $badgeHtml = '<span class="edu-nav-badge edu-nav-badge--accent">' . e($bellLabel) . '</span>';
    } elseif (is_string($badge) && $badge !== '') {
        $cls = 'edu-nav-badge' . ($tone === 'accent' ? ' edu-nav-badge--accent' : '');
        $badgeHtml = '<span class="' . $cls . '">' . e($badge) . '</span>';
    }

    return '<a href="' . e(url($path)) . '"'
        . ' class="edu-sidebar-link' . ($active ? ' edu-nav-active' : '') . '"'
        . ($active ? ' aria-current="page"' : '') . '>'
        . '<i class="bi ' . e($icon) . '" aria-hidden="true"></i>'
        . '<span class="edu-nav-label">' . e($label) . '</span>'
        . $badgeHtml
        . '</a>';
};

/** Render a whole nav group. */
$renderGroup = static function (string $title, array $items) use ($renderLink): string {
    if ($items === []) {
        return '';
    }
    $html = '<div class="edu-nav-group">' . e($title) . '</div>';
    foreach ($items as $item) {
        $html .= $renderLink($item);
    }
    return $html;
};

/** Compose the ordered group list for this role. */
$groups = [];
if ($isGuest) {
    $groups['Portal'] = [
        ['label' => 'School News', 'path' => '/news',    'icon' => 'bi-newspaper'],
        ['label' => 'Sign in',     'path' => '/login',   'icon' => 'bi-box-arrow-in-right', 'exact' => true],
        ['label' => 'Create account', 'path' => '/register', 'icon' => 'bi-person-plus-fill', 'exact' => true],
    ];
} else {
    $groups['Main']    = $NAV['common'];
    foreach (['enrollment', 'academics', 'records', 'admin', 'people', 'registrar', 'finance', 'teaching'] as $key) {
        if (!empty($NAV[$key])) {
            $groups[ucfirst($key)] = $NAV[$key];
        }
    }
    if (!empty($NAV['system'])) {
        $groups['System'] = $NAV['system'];
    }
    $groups['Account'] = $NAV['account'];
}

/** Avatar / identity chip markup, reused in sidebar + drawer. */
$avatarUrl = null;
if (!$isGuest && !empty($user['avatar_path'])) {
    $candidate = ROOT_PATH . '/public_html/' . ltrim((string) $user['avatar_path'], '/');
    if (is_file($candidate)) {
        $avatarUrl = url($user['avatar_path']);
    }
}
$initials = $isGuest ? 'U' : edu_initials($user['first_name'] ?? '', $user['last_name'] ?? '');
$fullName = $isGuest ? 'Guest' : trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($fullName === '') { $fullName = $isGuest ? 'Guest' : (string) ($user['email'] ?? 'User'); }
$userEmail = $isGuest ? '' : (string) ($user['email'] ?? '');

$logoFile = ROOT_PATH . '/public_html/assets/img/school-logo.png';
$hasLogo  = is_file($logoFile);
$logoTag  = $hasLogo
    ? '<img src="' . e(asset('img/school-logo.png')) . '" alt="Datamex College of Saint Adeline" class="edu-brand-logo">'
    : '<span class="edu-brand-logo-fallback" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>';
?>

<!-- ================= Desktop sidebar ================= -->
<aside id="desktopSidebar" class="edu-sidebar hidden lg:flex lg:flex-col edu-sidebar--rail">
  <a href="<?= e(url('/')) ?>" class="edu-brand">
    <?= $logoTag ?>
    <span class="edu-brand-text">
      <span class="edu-brand-name">EduPortal</span>
      <span class="edu-brand-sub">Datamex College of Saint Adeline</span>
    </span>
  </a>

  <?php if (!$isGuest): ?>
  <div class="edu-role-chip">
    <span class="edu-role-chip__dot" aria-hidden="true"></span>
    <span class="edu-role-chip__text"><?= e($roleLabel) ?> workspace</span>
  </div>
  <?php endif; ?>

  <nav class="edu-sidebar-nav" aria-label="Main navigation">
    <?php foreach ($groups as $groupTitle => $items): ?>
      <?= $renderGroup((string) $groupTitle, $items) ?>
    <?php endforeach; ?>
  </nav>

  <?php if (!$isGuest): ?>
  <a href="<?= e(url('/profile')) ?>" class="edu-user-chip">
    <?php if ($avatarUrl !== null): ?>
      <img src="<?= e($avatarUrl) ?>" alt="" class="edu-avatar edu-avatar--sm">
    <?php else: ?>
      <span class="edu-avatar edu-avatar--sm edu-avatar--fallback" aria-hidden="true"><?= e($initials) ?></span>
    <?php endif; ?>
    <span class="edu-user-text min-w-0 flex-1">
      <span class="edu-identity__name"><?= e($fullName) ?></span>
      <span class="edu-identity__meta"><?= e($userEmail) ?></span>
    </span>
    <i class="bi bi-chevron-right edu-user-chevron" aria-hidden="true"></i>
  </a>
  <form method="post" action="<?= e(url('/logout')) ?>" class="edu-sidebar-form">
    <?= \App\Core\Csrf::field() ?>
    <button type="submit" class="edu-signout">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
      <span class="edu-nav-label">Sign out</span>
    </button>
  </form>
  <?php endif; ?>

  <div class="edu-foot">
    <span class="edu-nav-label">&copy; <?= date('Y') ?> EduPortal</span>
    <button type="button" class="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode" onclick="eduToggleTheme()"><i class="bi bi-moon"></i></button>
  </div>
</aside>

<!-- ================= Mobile sticky header ================= -->
<div class="edu-mobile-bar lg:hidden">
  <button id="mobileMenuBtn" type="button" class="edu-topbar-icon" aria-label="Open navigation" aria-controls="mobileDrawer" aria-expanded="false">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <a href="<?= e(url('/')) ?>" class="edu-mobile-bar__brand">
    <?= $logoTag ?>
    <span>EduPortal</span>
  </a>
  <div class="d-flex align-items-center gap-1">
    <?php if (!$isGuest) include __DIR__ . '/notifications_bell.php'; ?>
    <button type="button" class="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode" onclick="eduToggleTheme()"><i class="bi bi-moon"></i></button>
  </div>
</div>

<!-- ================= Mobile drawer ================= -->
<div id="mobileDrawer" class="lg:hidden edu-drawer-shell" hidden>
  <div id="drawerBackdrop" class="edu-drawer-backdrop"></div>
  <div class="edu-drawer-panel" role="dialog" aria-modal="true" aria-label="Navigation">
    <div class="edu-drawer-panel__head">
      <span class="font-display fw-bold" style="color:#fff">Menu</span>
      <button id="drawerClose" type="button" class="edu-drawer-close" aria-label="Close navigation">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <?php if (!$isGuest): ?>
    <a href="<?= e(url('/profile')) ?>" class="edu-drawer-user">
      <?php if ($avatarUrl !== null): ?>
        <img src="<?= e($avatarUrl) ?>" alt="" class="edu-avatar edu-avatar--sm">
      <?php else: ?>
        <span class="edu-avatar edu-avatar--sm edu-avatar--fallback" aria-hidden="true"><?= e($initials) ?></span>
      <?php endif; ?>
      <span class="edu-user-text min-w-0 flex-1">
        <span class="edu-identity__name"><?= e($fullName) ?></span>
        <span class="edu-identity__meta"><?= e($userEmail) ?></span>
      </span>
      <span class="edu-badge edu-badge--primary"><?= e($roleLabel) ?></span>
    </a>
    <?php endif; ?>

    <nav class="edu-drawer-nav" aria-label="Mobile navigation">
      <?php foreach ($groups as $groupTitle => $items): ?>
        <?= $renderGroup((string) $groupTitle, $items) ?>
      <?php endforeach; ?>
    </nav>

    <?php if (!$isGuest): ?>
    <form method="post" action="<?= e(url('/logout')) ?>" class="edu-drawer-form">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="edu-signout edu-signout--block">
        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$isGuest): ?>
<!-- ================= Mobile bottom bar ================= -->
<nav class="edu-bottom-bar lg:hidden" aria-label="Quick navigation">
  <?php
  /* Four role-appropriate destinations, chosen server-side. */
  if ($role === 'admin') {
      $bottom = [
          ['/dashboard',           'bi-grid-1x2-fill',   'Home'],
          ['/admin/enrollments',   'bi-inbox-fill',      'Enroll'],
          ['/admin/payments',      'bi-credit-card-fill', 'Payments'],
          ['/profile',             'bi-person-fill',     'Profile'],
      ];
  } elseif ($role === 'registrar') {
      $bottom = [
          ['/dashboard',         'bi-grid-1x2-fill', 'Home'],
          ['/registrar/enrollments', 'bi-inbox-fill', 'Queue'],
          ['/registrar/sheets',  'bi-journal-check', 'Sheets'],
          ['/profile',           'bi-person-fill',   'Profile'],
      ];
  } elseif ($role === 'accounting') {
      $bottom = [
          ['/dashboard',             'bi-grid-1x2-fill', 'Home'],
          ['/accounting/enrollments', 'bi-inbox-fill',    'Queue'],
          ['/accounting/payments',   'bi-credit-card-fill', 'Payments'],
          ['/profile',               'bi-person-fill',   'Profile'],
      ];
  } elseif ($role === 'teacher') {
      $bottom = [
          ['/dashboard',         'bi-grid-1x2-fill',   'Home'],
          ['/teacher/grades',    'bi-journal-check',   'Grades'],
          ['/teacher/permits',   'bi-patch-check-fill', 'Permits'],
          ['/profile',           'bi-person-fill',     'Profile'],
      ];
  } else {
      /* student / parent */
      $bottom = [
          ['/dashboard',      'bi-grid-1x2-fill', 'Home'],
          [$isEnrolledForTerm ? '/student/my-classes' : '/enroll', $isEnrolledForTerm ? 'bi-journal-bookmark-fill' : 'bi-file-earmark-text', $isEnrolledForTerm ? 'Classes' : 'Enroll'],
          ['/student/schedule', 'bi-calendar-week-fill', 'Schedule'],
          ['/profile',        'bi-person-fill',   'Profile'],
      ];
  }
  ?>
  <?php foreach ($bottom as [$bPath, $bIcon, $bLabel]): ?>
    <a href="<?= e(url($bPath)) ?>" class="edu-bottom-bar__item<?= is_active_path($bPath, true) ? ' is-active' : '' ?>"
       <?= is_active_path($bPath, true) ? 'aria-current="page"' : '' ?>>
      <i class="bi <?= e($bIcon) ?>" aria-hidden="true"></i>
      <span><?= e($bLabel) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<script>
/* Drawer fallback: deferred responsive.js owns the drawer when it loads
   (focus trap + swipe + Escape). This fallback binds only if the deferred
   bundle failed, so open/close never double-fires. */
(function () {
  function bind() {
    var btn = document.getElementById('mobileMenuBtn');
    var drawer = document.getElementById('mobileDrawer');
    if (!btn || !drawer) return;
    if (window.EduResponsive || window.EduDrawer || btn.dataset.mBound || drawer.dataset.rBound) return;
    var closeBtn = document.getElementById('drawerClose');
    var backdrop = document.getElementById('drawerBackdrop');
    function open() {
      drawer.hidden = false;
      requestAnimationFrame(function () { drawer.classList.add('is-open'); });
      document.body.style.overflow = 'hidden';
      btn.setAttribute('aria-expanded', 'true');
      if (closeBtn) closeBtn.focus();
    }
    function hide() {
      drawer.classList.remove('is-open');
      document.body.style.overflow = '';
      btn.setAttribute('aria-expanded', 'false');
      setTimeout(function () { drawer.hidden = true; }, 300);
      btn.focus();
    }
    btn.dataset.mBound = '1';
    btn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', hide);
    if (backdrop) backdrop.addEventListener('click', hide);
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && drawer.classList.contains('is-open')) hide();
    });
    drawer.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', hide); });
  }
  /* Deferred scripts run before DOMContentLoaded, so by then we know
     whether the upgrade loaded. */
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else setTimeout(bind, 0);
})();
</script>
