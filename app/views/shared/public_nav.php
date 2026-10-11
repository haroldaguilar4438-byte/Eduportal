<?php
/**
 * Shared public navbar — used by /news and /news/{slug} so every public
 * page shares one navigation and one visual identity.
 *
 * $navActive: 'home' | 'news' | '' — highlights the current section.
 * $user: logged-in row or null (passed by BlogController, same as HomeController).
 *
 * @var string|null $navActive
 * @var array|null  $user
 */
$navActive = $navActive ?? '';
$user = $user ?? null;
$initials = $user !== null ? edu_initials($user['first_name'] ?? '', $user['last_name'] ?? '') : 'U';
$avatarUrl = null;
if ($user !== null && !empty($user['avatar_path'])) {
    $candidate = ROOT_PATH . '/public_html/' . ltrim((string) $user['avatar_path'], '/');
    if (is_file($candidate)) {
        $avatarUrl = url($user['avatar_path']);
    }
}
?>
<nav id="pubNav" class="navbar navbar-expand-lg nav-public sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('/')) ?>">
      <?php if (is_file(ROOT_PATH . '/public_html/assets/img/school-logo.png')): ?>
        <img src="<?= e(asset('img/school-logo.png')) ?>" alt="Datamex College of Saint Adeline" style="width:34px;height:34px;object-fit:contain;border-radius:9px;background:#fff;padding:2px">
      <?php else: ?>
        <span class="d-inline-flex align-items-center justify-content-center" style="width:34px;height:34px;border-radius:9px;background:var(--gradient-accent);color:#fff"><i class="bi bi-mortarboard-fill"></i></span>
      <?php endif; ?>
      <span class="d-none d-sm-inline">Datamex <span class="fw-normal text-secondary">College of Saint Adeline</span></span>
      <span class="d-sm-none">Datamex</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pubNavLinks" aria-controls="pubNavLinks" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="pubNavLinks">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
        <li class="nav-item">
          <a class="nav-link <?= $navActive === 'news' ? 'active' : '' ?>" href="<?= e(url('/news')) ?>">News</a>
        </li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/#programs')) ?>">Programs</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/#about')) ?>">About</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/#contact')) ?>">Contact</a></li>
        <li class="nav-item">
          <button type="button" class="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode" onclick="eduToggleTheme()"><i class="bi bi-moon"></i></button>
        </li>

        <?php if (!empty($user)): ?>
          <li class="nav-item ms-lg-2">
            <a class="nav-link" href="<?= e(url('/dashboard')) ?>"><i class="bi bi-grid-1x2 me-1"></i> Dashboard</a>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <?php if ($avatarUrl !== null): ?>
                <img src="<?= e($avatarUrl) ?>" alt="" style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1px solid var(--border)">
              <?php else: ?>
                <span class="edu-avatar edu-avatar--sm edu-avatar--fallback" aria-hidden="true"><?= e($initials) ?></span>
              <?php endif; ?>
              <span class="d-none d-lg-inline"><?= e($user['first_name'] ?? 'Profile') ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><span class="dropdown-header"><?= e(ucfirst((string)($user['role_name'] ?? 'Account'))) ?></span></li>
              <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i class="bi bi-person me-2"></i> Profile</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/security')) ?>"><i class="bi bi-shield-lock me-2"></i> Security</a></li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <form method="post" action="<?= e(url('/logout')) ?>" class="px-2">
                  <?= \App\Core\Csrf::field() ?>
                  <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i> Sign out</button>
                </form>
              </li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item ms-lg-2">
            <a class="btn btn-outline-primary btn-sm px-3" href="<?= e(url('/login')) ?>">Sign in</a>
          </li>
          <li class="nav-item">
            <a class="btn btn-primary btn-sm px-3" href="<?= e(url('/register')) ?>">Enroll now</a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
