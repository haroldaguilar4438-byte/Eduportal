<?php
/**
 * Public home page — Datamex College of Saint Adeline.
 *
 * Non-destructive: every value below comes from HomeController ($stats, $k4,
 * $byLevel, $school, $currentAy, $campuses, $latestNews, $user, $role) and is
 * only re-presented. The two extra reads (published announcements and the
 * featured article) are wrapped in try/catch so the page still renders if
 * those tables are unavailable.
 *
 * @var array  $stats
 * @var array  $k4
 * @var array  $byLevel
 * @var array|null $user
 * @var string|null $role
 * @var array  $school
 * @var string $currentAy
 * @var array  $campuses
 * @var array  $latestNews
 */
use App\Core\Database;

$schoolName = (string) ($school['name'] ?? 'Datamex College of Saint Adeline');
$hasLogo = is_file(ROOT_PATH . '/public_html/assets/img/school-logo.png');
$hasHero = is_file(ROOT_PATH . '/public_html/assets/img/school-bg.png');

/* ---- Extra, fail-open reads ---------------------------------------- */
$announcements = [];
try {
    $announcements = Database::all(
        "SELECT id, title, content, type, is_pinned, publish_at, expires_at
           FROM announcements
          WHERE status = 'published'
            AND publish_at <= NOW()
            AND (expires_at IS NULL OR expires_at >= NOW())
          ORDER BY is_pinned DESC, publish_at DESC
          LIMIT 4"
    );
} catch (Throwable $e) { $announcements = []; }

$featured = null;
try {
    if (\App\Models\Blog::tableReady()) {
        $featured = \App\Models\Blog::featured();
    }
} catch (Throwable $e) { $featured = null; }

/* Program cards are derived from the real grade-level data, so the
   marketing section can never drift from the academy's structure. */
$programs = [];
foreach (($byLevel ?? []) as $lvl) {
    $cat = strtolower((string) ($lvl['category'] ?? ''));
    $key = str_contains($cat, 'college') ? 'college'
         : (str_contains($cat, 'junior') || str_contains($cat, 'high') ? 'highschool' : 'basic');
    $programs[$key]['count'] = ($programs[$key]['count'] ?? 0) + 1;
    $programs[$key]['students'] = ($programs[$key]['students'] ?? 0) + (int) ($lvl['cnt'] ?? 0);
    $programs[$key]['levels'][] = (string) ($lvl['name'] ?? $lvl['code'] ?? '');
}

$programMeta = [
    'basic' => [
        'title' => 'Basic Education',
        'range' => 'Kindergarten &rarr; Grade 4',
        'icon'  => 'bi-emoji-smile',
        'tone'  => 'info',
        'desc'  => 'A strong literacy and numeracy foundation, delivered by specialist teachers in a safe, nurturing environment.',
    ],
    'highschool' => [
        'title' => 'Junior High School',
        'range' => 'Grades 5 &rarr; 10',
        'icon'  => 'bi-mortarboard',
        'tone'  => 'primary',
        'desc'  => 'Science, mathematics and the humanities with guided project work that builds independent study habits.',
    ],
    'college' => [
        'title' => 'Senior High &amp; College',
        'range' => 'Senior High &rarr; College Year 4',
        'icon'  => 'bi-mortarboard-fill',
        'tone'  => 'accent',
        'desc'  => 'Academic, TVL, arts and sports tracks, plus degree programmes that lead into professional practice.',
    ],
];

/* Max enrolment across levels, used to scale the level bars. */
$levelMax = 0;
foreach (($byLevel ?? []) as $lvl) {
    $levelMax = max($levelMax, (int) ($lvl['cnt'] ?? 0));
}

$annTypeIcon = static fn (string $t): string => match (strtolower($t)) {
    'exam', 'examination' => 'bi-calendar-check',
    'payment', 'finance'   => 'bi-credit-card',
    'enrollment'          => 'bi-file-earmark-text',
    'event'               => 'bi-stars',
    'urgent'              => 'bi-exclamation-triangle',
    default               => 'bi-megaphone',
};
$annTypeTone = static fn (string $t): string => match (strtolower($t)) {
    'exam', 'examination' => 'info',
    'payment', 'finance'   => 'accent',
    'urgent'              => 'danger',
    'event'               => 'primary',
    default               => 'neutral',
};
?>
<!doctype html>
<html lang="en">
<head>
  <?php include APP_ROOT . '/views/shared/public_head.php'; ?>
</head>
<body>

<?php $navActive = 'home'; include APP_ROOT . '/views/shared/public_nav.php'; ?>

<!-- ============================================================
     1. HERO
     ============================================================ -->
<header class="hero position-relative">
  <?php if ($hasHero): ?>
    <img src="<?= e(asset('img/school-bg.png')) ?>" alt="" aria-hidden="true"
         class="position-absolute top-0 start-0 w-100 h-100"
         style="object-fit:cover;opacity:.22;mix-blend-mode:luminosity">
  <?php endif; ?>

  <div class="container position-relative" style="padding:3rem 12px 2.75rem">
    <div class="row align-items-center g-4">
      <div class="col-12 col-lg-7">
        <span class="section-eyebrow" style="background:rgba(255,255,255,.14);border-color:rgba(255,255,255,.22);color:#F2DDAE">
          <i class="bi bi-stars"></i> School Year <?= e($currentAy !== '' ? $currentAy : date('Y')) ?>
        </span>

        <h1 class="home-hero-title mb-2" style="max-width:18ch">
          Give every learner a <span class="gradient-text">clearer path</span> to success.
        </h1>

        <p class="lead mb-3" style="max-width:58ch;font-size:1.05rem">
          <?= e($schoolName) ?> is a complete learning community from Kindergarten
          through College &mdash; and <?= e((string) ($stats['total_students'] ?? 0)) ?> students
          already study here every day.
        </p>

        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php if (!empty($user)): ?>
            <a href="<?= e(url('/dashboard')) ?>" class="btn btn-accent">
              <i class="bi bi-grid-1x2 me-2"></i> Go to my dashboard
            </a>
          <?php else: ?>
            <a href="<?= e(url('/register')) ?>" class="btn btn-accent">
              <i class="bi bi-pencil-square me-2"></i> Apply for admission
            </a>
            <a href="<?= e(url('/login')) ?>" class="btn btn-outline-light">
              <i class="bi bi-box-arrow-in-right me-2"></i> Sign in
            </a>
          <?php endif; ?>
          <a href="<?= e(url('/#programs')) ?>" class="btn btn-link text-white text-decoration-none">
            <strong class="fw-semibold">Browse programs</strong> <i class="bi bi-arrow-down ms-1"></i>
          </a>
        </div>

        <!-- Trust indicators -->
        <dl class="row g-3 mb-0" style="max-width:32rem">
          <?php
          $proof = [
              ['bi-mortarboard',   (string) ($stats['total_students'] ?? 0), 'Enrolled students'],
              ['bi-person-video3', (string) ($stats['total_teachers'] ?? 0), 'Faculty members'],
              ['bi-people',        (string) ($stats['total_parents']  ?? 0), 'Parents &amp; guardians'],
          ];
          foreach ($proof as [$icon, $value, $label]): ?>
            <div class="col-4">
              <div class="d-flex align-items-center gap-2">
                <i class="bi <?= e($icon) ?> fs-5" style="color:#EBC77C" aria-hidden="true"></i>
                <div>
                  <dt class="d-block fs-5 fw-bold text-white lh-1 mb-0"><?= e($value) ?></dt>
                  <dd class="mb-0 small" style="color:rgba(255,255,255,.72)"><?= $label ?></dd>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </dl>
      </div>

      <div class="col-12 col-lg-5">
        <div class="p-3 rounded-4" style="background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.18);backdrop-filter:blur(10px)">
          <h2 class="h6 fw-bold text-white mb-2">
            <i class="bi bi-lightning-charge me-1" style="color:#EBC77C"></i> Start in minutes
          </h2>
          <ol class="mb-0 ps-3" style="color:rgba(255,255,255,.86);line-height:1.7;font-size:.875rem">
            <li>Create your EduPortal account</li>
            <li>Upload your documents (photo or PDF)</li>
            <li>Submit your application for review</li>
            <li>Pay securely, then track your status</li>
          </ol>
          <div class="mt-2 pt-2 border-top" style="border-color:rgba(255,255,255,.18)!important">
            <a href="<?= e(url('/support')) ?>" class="link-light small text-decoration-none">
              Need help deciding? Talk to admissions <i class="bi bi-arrow-right"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- ============================================================
     2. QUICK ACCESS
     ============================================================ -->
<section class="py-3 border-bottom" style="background:var(--surface)">
  <div class="container">
    <ul class="row g-3 list-unstyled mb-0">
      <?php
      $portals = [
          ['role' => 'student', 'href' => '/login',    'icon' => 'bi-mortarboard-fill',  'title' => 'Student Portal', 'sub' => 'Grades, permits, ledger',         'tone' => 'info'],
          ['role' => 'parent',  'href' => '/login',    'icon' => 'bi-people-fill',       'title' => 'Parent Portal',  'sub' => 'Track your child&#39;s progress', 'tone' => 'primary'],
          ['role' => 'teacher', 'href' => '/login',    'icon' => 'bi-person-video3',     'title' => 'Teacher Portal', 'sub' => 'Classrooms, tasks, grade sheets', 'tone' => 'accent'],
          ['role' => null,      'href' => '/register', 'icon' => 'bi-file-earmark-text', 'title' => 'Admissions',     'sub' => 'Apply online, pay the fee',      'tone' => 'success'],
      ];

      /*
       * Role-filtered quick access: a signed-in visitor only sees their own
       * workspace (retargeted at the dashboard, since /login and /register
       * are meaningless once authenticated). Anonymous visitors keep the
       * full strip. Unknown roles fail open to the full strip.
       */
      $staffPortals = [
          'admin'      => ['role' => 'admin',      'href' => '/dashboard', 'icon' => 'bi-shield-lock-fill', 'title' => 'Admin workspace',      'sub' => 'Users, enrollment, site content', 'tone' => 'primary'],
          'registrar'  => ['role' => 'registrar',  'href' => '/dashboard', 'icon' => 'bi-journal-check',    'title' => 'Registrar workspace',  'sub' => 'Enrollment, records, grades',     'tone' => 'info'],
          'accounting' => ['role' => 'accounting', 'href' => '/dashboard', 'icon' => 'bi-wallet2',          'title' => 'Accounting workspace', 'sub' => 'Payments, clearances, receipts',  'tone' => 'success'],
      ];
      $visiblePortals = $portals;
      if (!empty($user)) {
          $viewerRole = strtolower((string) ($user['role_name'] ?? $user['role'] ?? ''));
          if (in_array($viewerRole, ['student', 'parent', 'teacher'], true)) {
              $visiblePortals = [];
              foreach ($portals as $portal) {
                  if ($portal['role'] === $viewerRole) {
                      $portal['href'] = '/dashboard';
                      $visiblePortals[] = $portal;
                  }
              }
          } elseif (isset($staffPortals[$viewerRole])) {
              $visiblePortals = [$staffPortals[$viewerRole]];
          }
      }
      $portalCol = count($visiblePortals) === 1 ? 'col-12 col-md-8 col-lg-6 mx-auto' : 'col-12 col-sm-6 col-lg-3';
      foreach ($visiblePortals as $portal):
          [$href, $icon, $title, $sub, $tone] = [$portal['href'], $portal['icon'], $portal['title'], $portal['sub'], $portal['tone']];
      ?>
        <li class="<?= $portalCol ?>">
          <a href="<?= e(url($href)) ?>" class="d-block h-100 edu-card edu-card--hover p-2 px-3 text-decoration-none d-flex align-items-center gap-3">
            <span class="edu-stat__icon edu-stat__icon--<?= e($tone) ?> flex-none" style="width:34px;height:34px;font-size:.95rem" aria-hidden="true"><i class="bi <?= e($icon) ?>"></i></span>
            <span class="min-w-0">
              <span class="d-block fw-semibold" style="color:var(--text-primary);font-size:.9rem"><?= e($title) ?></span>
              <span class="d-block" style="color:var(--text-tertiary);font-size:.78rem"><?= $sub ?></span>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ============================================================
     3. ACADEMIC PROGRAMS
     ============================================================ -->
<section id="programs" class="section-pad" style="background:var(--background)">
  <div class="container">
    <div class="section-head">
      <span class="section-eyebrow"><i class="bi bi-diagram-3"></i> Academic programs</span>
      <h2 class="section-title">One school, every stage of learning</h2>
      <p class="section-lede">
        Continuous progression from Kindergarten to College, so a learner never has to
        change institutions as they grow.
      </p>
    </div>

    <?php if (empty($programMeta)): ?>
      <?= edu_empty_state('Program list unavailable', 'The academic structure could not be loaded just now. Please check back shortly.', ['icon' => 'bi-diagram-3']) ?>
    <?php else: ?>
      <div class="row g-4">
        <?php foreach ($programMeta as $key => $meta):
            $data = $programs[$key] ?? ['count' => 0, 'students' => 0, 'levels' => []]; ?>
          <div class="col-12 col-md-4">
            <article class="program-card p-4 h-100 d-flex flex-column">
              <span class="icon-badge edu-stat__icon--<?= e($meta['tone']) ?> mb-3" style="width:46px;height:46px;border-radius:var(--radius);display:inline-flex;align-items:center;justify-content:center;font-size:1.25rem">
                <i class="bi <?= e($meta['icon']) ?>"></i>
              </span>
              <h3 class="h5 fw-bold mb-1" style="color:var(--text-primary)"><?= $meta['title'] ?></h3>
              <p class="small fw-semibold mb-2" style="color:var(--accent-700)"><?= $meta['range'] ?></p>
              <p class="small mb-3" style="color:var(--text-tertiary);line-height:1.7"><?= $meta['desc'] ?></p>

              <div class="d-flex flex-wrap gap-1 mb-3">
                <?php foreach (array_slice($data['levels'], 0, 6) as $lvl): ?>
                  <span class="badge bg-light border"><?= e($lvl) ?></span>
                <?php endforeach; ?>
                <?php if (count($data['levels']) > 6): ?>
                  <span class="badge bg-light border">+<?= count($data['levels']) - 6 ?> more</span>
                <?php endif; ?>
              </div>

              <div class="mt-auto pt-2 d-flex align-items-center justify-content-between">
                <span class="small" style="color:var(--text-tertiary)">
                  <strong style="color:var(--text-primary)"><?= (int) $data['students'] ?></strong> enrolled now
                </span>
                <a href="<?= e(url('/register')) ?>" class="btn btn-outline-primary btn-sm">
                  Learn more <i class="bi bi-arrow-right ms-1"></i>
                </a>
              </div>
            </article>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Level distribution -->
      <?php if (!empty($byLevel) && $levelMax > 0): ?>
        <?php
        /* Server-rendered SVG bar chart (no JS dependency): one bar per
           level, y-axis with nice-number gridlines, peak bar in accent. */
        $chartLevels = [];
        $chartTotal  = 0;
        foreach ($byLevel as $lvl) {
            $c = (int) ($lvl['cnt'] ?? 0);
            $chartTotal += $c;
            $chartLevels[] = [
                'code'  => (string) ($lvl['code'] ?? ''),
                'name'  => (string) ($lvl['name'] ?? $lvl['code'] ?? ''),
                'count' => $c,
            ];
        }
        $chartN = count($chartLevels);
        $chartPeak = 0;
        foreach ($chartLevels as $cl) {
            if ($cl['count'] > $chartLevels[$chartPeak]['count']) {
                $chartPeak = array_search($cl, $chartLevels, true);
            }
        }
        /* Nice y-axis step from {1,2,5,10} x 10^k for 4 intervals. */
        $chartStep = 1;
        if ($levelMax > 4) {
            $raw  = $levelMax / 4;
            $mag  = pow(10, (int) floor(log10($raw)));
            foreach ([1, 2, 5, 10] as $m) {
                if ($m * $mag >= $raw) {
                    $chartStep = (int) ($m * $mag);
                    break;
                }
            }
            $chartStep = max(1, $chartStep);
        }
        /* Floor the axis at 4 so tiny counts (e.g. a single enrolled
           learner) render as proportional bars instead of full-height slabs,
           keeping the graph a consistent, normal size on every dataset. */
        $chartTop  = max(4, (int) (ceil($levelMax / $chartStep) * $chartStep));
        $chartW = 720; $chartH = 240;
        $padL = 36; $padR = 10; $padT = 22; $padB = 52;
        $plotW = $chartW - $padL - $padR;
        $plotH = $chartH - $padT - $padB;
        $slotW = $plotW / max(1, $chartN);
        $barW  = min(30, $slotW * 0.55);
        $peakName = $chartLevels[$chartPeak]['name'] ?? '';
        ?>
        <div class="mt-5">
          <?= edu_section_head('Enrolment by year level', 'Live count of currently enrolled learners', '', 'bi-bar-chart-steps') ?>
          <div class="edu-card">
            <div class="edu-card__body">
              <div class="d-flex gap-2 flex-wrap mb-2">
                <?= edu_badge($chartTotal . ' enrolled total', 'primary', 'bi-people-fill') ?>
                <?php if ($peakName !== ''): ?>
                  <?= edu_badge('Peak: ' . $peakName . ' (' . $chartLevels[$chartPeak]['count'] . ')', 'accent', 'bi-trophy') ?>
                <?php endif; ?>
              </div>
              <div class="chart-scroll enrol-chart-wrap" style="overflow-x:auto">
              <svg class="enrol-chart" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" role="img"
                   style="min-width:520px;width:100%;max-width:720px;margin-inline:auto;height:auto;display:block"
                   aria-label="Bar chart of enrolled learners by year level. Total <?= $chartTotal ?>.">
                <?php for ($g = 0; $g <= $chartTop; $g += $chartStep):
                    $gy = $padT + $plotH - ($g / $chartTop) * $plotH; ?>
                  <line x1="<?= $padL ?>" y1="<?= round($gy, 1) ?>" x2="<?= $chartW - $padR ?>" y2="<?= round($gy, 1) ?>" class="chart-grid" />
                  <text x="<?= $padL - 8 ?>" y="<?= round($gy + 4, 1) ?>" text-anchor="end" class="chart-tick"><?= $g ?></text>
                <?php endfor; ?>
                <line x1="<?= $padL ?>" y1="<?= $padT + $plotH ?>" x2="<?= $chartW - $padR ?>" y2="<?= $padT + $plotH ?>" class="chart-axis" />
                <?php foreach ($chartLevels as $i => $cl):
                    $cx = $padL + $slotW * $i + $slotW / 2;
                    $bh = $chartTop > 0 ? ($cl['count'] / $chartTop) * $plotH : 0;
                    $by = $padT + $plotH - $bh;
                    $isPeak = $i === $chartPeak && $cl['count'] > 0; ?>
                  <g>
                    <title><?= e($cl['name'] . ' — ' . $cl['count'] . ' enrolled') ?></title>
                    <?php if ($cl['count'] > 0): ?>
                      <rect x="<?= round($cx - $barW / 2, 1) ?>" y="<?= round(max($by, $padT), 1) ?>"
                            width="<?= round($barW, 1) ?>" height="<?= round(max($bh, 3), 1) ?>" rx="4"
                            class="chart-bar<?= $isPeak ? ' is-peak' : '' ?>" />
                      <text x="<?= round($cx, 1) ?>" y="<?= round(max($by, $padT) - 7, 1) ?>"
                            text-anchor="middle" class="chart-value"><?= $cl['count'] ?></text>
                    <?php else: ?>
                      <rect x="<?= round($cx - $barW / 2, 1) ?>" y="<?= $padT + $plotH - 2 ?>"
                            width="<?= round($barW, 1) ?>" height="2" rx="1" class="chart-empty" />
                    <?php endif; ?>
                    <text x="<?= round($cx, 1) ?>" y="<?= $padT + $plotH + 16 ?>" text-anchor="end"
                          class="chart-label" transform="rotate(-32 <?= round($cx, 1) ?> <?= $padT + $plotH + 16 ?>)"><?= e($cl['code'] !== '' ? $cl['code'] : $cl['name']) ?></text>
                  </g>
                <?php endforeach; ?>
              </svg>
              </div>
              <p class="small text-muted mb-0 mt-2"><i class="bi bi-info-circle"></i> Counts update live from enrolment records. Hover or tap a bar for the exact figure.</p>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<!-- ============================================================
     4. ANNOUNCEMENTS + IMPORTANT DATES
     ============================================================ -->
<section class="section-pad pt-0" style="background:var(--background)">
  <div class="container">
    <div class="row g-4">
      <div class="col-12 col-lg-7">
        <?= edu_section_head('Latest announcements', 'News and reminders from the school office',
              '<a href="' . e(url('/announcements')) . '" class="btn btn-outline-secondary btn-sm">View all <i class="bi bi-arrow-right ms-1"></i></a>', 'bi-megaphone') ?>

        <?php if (empty($announcements)): ?>
          <?= edu_empty_state('No announcements right now', 'When the school office posts an update it will appear here.', ['icon' => 'bi-megaphone', 'compact' => true]) ?>
        <?php else: ?>
          <div class="d-flex flex-column gap-2">
            <?php foreach ($announcements as $ann):
                $type  = (string) ($ann['type'] ?? 'general');
                $tone  = $annTypeTone($type);
                $icon  = $annTypeIcon($type); ?>
              <a href="<?= e(url('/announcements/' . (int) $ann['id'])) ?>" class="edu-card edu-card--hover p-3 text-decoration-none d-flex gap-3 align-items-start">
                <span class="edu-stat__icon edu-stat__icon--<?= e($tone) ?>" aria-hidden="true"><i class="bi <?= e($icon) ?>"></i></span>
                <span class="min-w-0 flex-1">
                  <span class="d-flex align-items-center gap-2 mb-1">
                    <?php if (!empty($ann['is_pinned'])): ?>
                      <span class="edu-badge edu-badge--accent"><i class="bi bi-pin-angle-fill"></i> Pinned</span>
                    <?php endif; ?>
                    <span class="small" style="color:var(--text-tertiary)"><?= e(edu_date($ann['publish_at'] ?? null)) ?></span>
                  </span>
                  <span class="d-block fw-semibold" style="color:var(--text-primary)"><?= e($ann['title'] ?? '') ?></span>
                  <span class="d-block small" style="color:var(--text-tertiary)">
                    <?= e(mb_strimwidth(strip_tags((string) ($ann['content'] ?? '')), 0, 130, '…')) ?>
                  </span>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-12 col-lg-5">
        <?= edu_section_head('Admissions at a glance', 'Current figures for the open year', '', 'bi-clipboard-data') ?>
        <div class="d-flex flex-column gap-2">
          <?php
          $ayRow = [
              ['bi-check2-circle', 'Enrolled students', (int) ($stats['enrolled'] ?? 0),        'success'],
              ['bi-hourglass-split', 'Applications pending', (int) ($stats['pending_enroll'] ?? 0), 'warning'],
          ];
          foreach ($ayRow as [$icon, $label, $value, $tone]): ?>
            <div class="edu-card p-3 d-flex align-items-center gap-3">
              <span class="edu-stat__icon edu-stat__icon--<?= e($tone) ?>" aria-hidden="true"><i class="bi <?= e($icon) ?>"></i></span>
              <span class="flex-1 min-w-0">
                <span class="d-block small fw-semibold" style="color:var(--text-primary)"><?= e($label) ?></span>
                <?php if ($value === null): ?>
                  <span class="d-block small" style="color:var(--text-tertiary)">Sign in to view</span>
                <?php else: ?>
                  <span class="d-block small" style="color:var(--text-tertiary)"><?= $value ?> this school year</span>
                <?php endif; ?>
              </span>
              <i class="bi bi-chevron-right small" style="color:var(--border-strong)" aria-hidden="true"></i>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================
     5. NEWS / BLOG
     ============================================================ -->
<?php if (!empty($featured) || !empty($latestNews)): ?>
<section class="section-pad pt-0" style="background:var(--background)">
  <div class="container">
    <?= edu_section_head('News & stories', 'Life at ' . e($schoolName),
          '<a href="' . e(url('/news')) . '" class="btn btn-outline-secondary btn-sm">All articles <i class="bi bi-arrow-right ms-1"></i></a>', 'bi-newspaper') ?>

    <div class="row g-4">
      <?php if (!empty($featured)): ?>
        <div class="col-12 col-lg-6">
          <article class="edu-card edu-card--hover h-100 d-flex flex-column overflow-hidden">
            <?php if (!empty($featured['featured_image']) && is_file(ROOT_PATH . '/public_html/' . ltrim((string) $featured['featured_image'], '/'))): ?>
              <div class="news-media news-media--featured" style="overflow:hidden;border-radius:14px 14px 0 0">
                <img src="<?= e(url($featured['featured_image'])) ?>" alt="<?= e($featured['title'] ?? 'Featured article image') ?>" loading="lazy"
                     style="display:block;width:100%;height:230px;object-fit:cover">
              </div>
            <?php else: ?>
              <div class="news-media news-media--featured news-media--fallback" aria-hidden="true">
                <i class="bi bi-newspaper"></i>
              </div>
            <?php endif; ?>
            <div class="p-4 d-flex flex-column flex-grow-1">
              <div class="d-flex align-items-center gap-2 mb-2">
                <span class="edu-badge edu-badge--accent"><i class="bi bi-star-fill"></i> Featured</span>
                <span class="small" style="color:var(--text-tertiary)"><?= e(edu_date($featured['published_at'] ?? null)) ?></span>
              </div>
              <h3 class="h5 fw-bold mb-2" style="color:var(--text-primary)"><?= e($featured['title'] ?? '') ?></h3>
              <p class="small mb-3" style="color:var(--text-tertiary);line-height:1.7">
                <?= e(mb_strimwidth(strip_tags((string) ($featured['summary'] ?? $featured['content'] ?? '')), 0, 180, '…')) ?>
              </p>
              <a href="<?= e(url('/news/' . ($featured['slug'] ?? ''))) ?>" class="btn btn-primary btn-sm align-self-start mt-auto">
                Read the story <i class="bi bi-arrow-right ms-1"></i>
              </a>
            </div>
          </article>
        </div>
      <?php endif; ?>

      <?php
      $others = array_slice(array_filter($latestNews, static fn ($p) => empty($featured) || (string) ($p['slug'] ?? '') !== (string) ($featured['slug'] ?? '')), 0, 3);
      foreach ($others as $post): ?>
        <div class="col-12 col-md-<?= empty($featured) ? '4' : '6 col-lg-3' ?>">
          <article class="edu-card edu-card--hover h-100 d-flex flex-column">
            <?php if (!empty($post['featured_image']) && is_file(ROOT_PATH . '/public_html/' . ltrim((string) $post['featured_image'], '/'))): ?>
              <div class="news-media news-media--small" style="overflow:hidden;border-radius:14px 14px 0 0">
                <img src="<?= e(url($post['featured_image'])) ?>" alt="<?= e($post['title'] ?? 'Article image') ?>" loading="lazy"
                     style="display:block;width:100%;height:130px;object-fit:cover">
              </div>
            <?php else: ?>
              <div class="news-media news-media--small news-media--fallback" aria-hidden="true">
                <i class="bi bi-newspaper"></i>
              </div>
            <?php endif; ?>
            <div class="p-3 d-flex flex-column flex-grow-1">
              <span class="small mb-1" style="color:var(--text-tertiary)"><?= e(edu_date($post['published_at'] ?? null)) ?></span>
              <h3 class="h6 fw-bold mb-2" style="color:var(--text-primary)"><?= e($post['title'] ?? '') ?></h3>
              <a href="<?= e(url('/news/' . ($post['slug'] ?? ''))) ?>" class="small text-decoration-none fw-semibold mt-auto">
                Read more <i class="bi bi-arrow-right"></i>
              </a>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============================================================
     6. WHY US
     ============================================================ -->
<section id="about" class="section-pad" style="background:var(--surface);border-block:1px solid var(--border)">
  <div class="container">
    <div class="row g-5 align-items-center">
      <div class="col-12 col-lg-5">
        <span class="section-eyebrow"><i class="bi bi-building"></i> About the school</span>
        <h2 class="section-title">A school that keeps families informed by default</h2>
        <p class="section-lede">
          EduPortal is our school information system. Everything a family or student
          needs &mdash; enrolment status, grades, exam permits, clearances and payments
          &mdash; lives in one place and updates the moment staff record it.
        </p>
        <?php if (!empty($campuses)): ?>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <?php foreach (array_slice($campuses, 0, 6) as $c): ?>
              <span class="edu-badge edu-badge--primary"><i class="bi bi-geo-alt"></i> <?= e($c['name'] ?? '') ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-12 col-lg-7">
        <div class="row g-3">
          <?php
          $pillars = [
              ['bi-shield-check',   'Secure by default',      'Two-factor authentication, CSRF-protected forms and per-role access control.', 'success'],
              ['bi-clock-history',  'Real-time status',       'See exactly where an application, grade sheet or permit sits in the workflow.', 'info'],
              ['bi-journal-text',   'One record of truth',     'Registrar, accounting and faculty all read from the same student record.', 'primary'],
              ['bi-phone',          'Works on any device',     'Responsive layout from a desktop office PC down to a parent&#39;s phone.', 'accent'],
          ];
          foreach ($pillars as [$icon, $title, $desc, $tone]): ?>
            <div class="col-12 col-sm-6">
              <div class="edu-card edu-card--hover h-100 p-3">
                <span class="edu-stat__icon edu-stat__icon--<?= e($tone) ?> mb-2" aria-hidden="true"><i class="bi <?= e($icon) ?>"></i></span>
                <h3 class="h6 fw-bold mb-1" style="color:var(--text-primary)"><?= e($title) ?></h3>
                <p class="small mb-0" style="color:var(--text-tertiary);line-height:1.65"><?= $desc ?></p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<?php include APP_ROOT . '/views/shared/public_footer.php'; ?>

</body>
</html>
