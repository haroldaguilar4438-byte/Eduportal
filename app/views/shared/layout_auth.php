<?php
/**
 * Split-screen Authentication Shell — EduPortal
 *
 * Preserved (non-destructive): $content, $title, flash_field(), e(), asset(),
 * the school logo / Datamex branding, the privacy / terms / support links,
 * the chatbot and design-system scripts, and the school backdrop image.
 *
 * Redesigned: a two-column layout — academic brand panel on the left,
 * the authentication form on the right — plus an optional verification
 * stepper (Account → Email → Security → Complete) that each verification
 * view opts into by setting $authStep / $authSteps before rendering.
 *
 * @var string      $content
 * @var string|null $title
 * @var string|null $authHeading
 * @var string|null $authSubheading
 * @var int|null    $authStep      1-based current step (enables the stepper)
 * @var string|null $authBackUrl
 */
$authHeading    = $authHeading ?? ($title ?? 'Welcome back');
$authSubheading = $authSubheading ?? 'Sign in to your EduPortal account to continue.';
$authBackUrl    = $authBackUrl ?? null;
$authStep       = isset($authStep) ? (int) $authStep : null;

$authSteps = $authSteps ?? [
    ['label' => 'Account',  'hint' => 'Your details'],
    ['label' => 'Email',    'hint' => 'Verify address'],
    ['label' => 'Security', 'hint' => 'Protect access'],
    ['label' => 'Complete', 'hint' => 'All set'],
];
?>
<!doctype html>
<html lang="en">
<head>
  <?php include __DIR__ . '/partials/header.php'; ?>
</head>
<body class="auth-body">
  <div class="auth-layout">

    <!-- ================= Left: academic brand panel ================= -->
    <section class="auth-panel d-none d-lg-flex" aria-label="About EduPortal">
      <div class="auth-panel__glow" aria-hidden="true"></div>
      <div class="auth-panel__inner">
        <div class="auth-panel__brand">
          <?php if (is_file(ROOT_PATH . '/public_html/assets/img/school-logo.png')): ?>
            <img src="<?= e(asset('img/school-logo.png')) ?>" alt="Datamex College of Saint Adeline" class="auth-panel__logo">
          <?php else: ?>
            <span class="auth-panel__logo auth-panel__logo--fallback" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
          <?php endif; ?>
          <div>
            <div class="auth-panel__name">EduPortal</div>
            <div class="auth-panel__school">Datamex College of Saint Adeline</div>
          </div>
        </div>

        <div class="auth-panel__message">
          <h2 class="auth-panel__headline">One portal for your entire academic journey.</h2>
          <p class="auth-panel__lede">
            Enroll online, track your grades and permits, settle your account, and
            stay connected to your school — securely, from any device.
          </p>
          <ul class="auth-panel__points">
            <li><i class="bi bi-shield-check" aria-hidden="true"></i><span>Protected accounts with two-factor authentication</span></li>
            <li><i class="bi bi-journal-check" aria-hidden="true"></i><span>Grades, permits and clearances in one place</span></li>
            <li><i class="bi bi-credit-card" aria-hidden="true"></i><span>Online payments with instant receipts</span></li>
          </ul>
        </div>

        <p class="auth-panel__foot">&copy; <?= date('Y') ?> Datamex College of Saint Adeline</p>
      </div>
    </section>

    <!-- ================= Right: form ================= -->
    <section class="auth-form-col">
      <button type="button" class="theme-toggle auth-theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode" onclick="eduToggleTheme()">
        <i class="bi bi-moon" aria-hidden="true"></i>
      </button>
      <div class="auth-form-wrap">
        <!-- Mobile brand -->
        <div class="auth-mobile-brand d-lg-none">
          <?php if (is_file(ROOT_PATH . '/public_html/assets/img/school-logo.png')): ?>
            <img src="<?= e(asset('img/school-logo.png')) ?>" alt="" class="auth-mobile-brand__logo">
          <?php else: ?>
            <span class="auth-mobile-brand__logo auth-mobile-brand__logo--fallback" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
          <?php endif; ?>
          <div>
            <div class="auth-mobile-brand__name">EduPortal</div>
            <div class="auth-mobile-brand__school">Datamex College of Saint Adeline</div>
          </div>
        </div>

        <!-- Verification stepper -->
        <?php if ($authStep !== null): ?>
          <?= edu_stepper($authSteps, true) ?>
        <?php endif; ?>

        <header class="auth-form-head">
          <?php if ($authBackUrl !== null): ?>
            <a href="<?= e($authBackUrl) ?>" class="auth-back">
              <i class="bi bi-arrow-left" aria-hidden="true"></i> Back
            </a>
          <?php endif; ?>
          <h1 class="auth-form-title"><?= e($authHeading) ?></h1>
          <?php if ($authSubheading !== ''): ?>
            <p class="auth-form-sub"><?= e($authSubheading) ?></p>
          <?php endif; ?>
        </header>

        <?php /* Preserve original flash rendering. */ ?>
        <?php $authFlash = flash_field(); ?>
        <?php if (trim($authFlash) !== ''): ?>
          <div class="auth-flash" role="status" aria-live="polite"><?= $authFlash ?></div>
        <?php endif; ?>

        <!-- Preserve original $content (forms, inputs, CSRF — untouched) -->
        <div class="edu-auth-panel"><?= $content ?></div>

        <p class="auth-legal">
          &copy; <?= date('Y') ?> Datamex College of Saint Adeline
          &middot; <a href="<?= e(url('/privacy')) ?>">Privacy</a>
          &middot; <a href="<?= e(url('/terms')) ?>">Terms</a>
          &middot; <a href="<?= e(url('/support')) ?>">Support</a>
        </p>
      </div>
    </section>
  </div>

  <script src="<?= e(asset('js/chatbot.js')) ?>"></script>
  <script src="<?= e(asset('js/design-system.js')) ?>" defer></script>
  <script src="<?= e(asset('js/responsive.js')) ?>" defer></script>
</body>
</html>
