<?php /** @var string $state @var array $check @var string|null $offerLink */
$check = $check ?? ['items' => [], 'reasons' => []];
$cur = $check['current'] ?? null;
$next = $check['next'] ?? null;
$children = $children ?? [];
$activeChild = $activeChild ?? null;
$childName = static function (?array $c): string {
    if ($c === null) return '';
    $n = trim(trim((string) ($c['first_name'] ?? '')) . ' ' . trim((string) ($c['last_name'] ?? '')));
    return $n !== '' ? $n : ('Child #' . (int) ($c['id'] ?? 0));
};
$perLabel = static function (?array $p): string {
    if (!$p) return '—';
    if (!empty($p['period_label'])) return (string) $p['period_label'];
    $s = trim((string)($p['semester'] ?? ''));
    if (in_array($s, ['1st','2nd'], true)) return $s . ' Semester';
    return $s !== '' ? $s : '—';
};
$perNoun = static function (?array $p): string {
    if (!$p) return 'Period';
    $band = $p['band'] ?? 'college';
    if ($band === 'basic') {
        $c = $p['period'] ?? '';
        if (is_string($c) && str_starts_with($c, 'T')) return 'Term';
        return 'Quarter';
    }
    return 'Semester';
};
?>
<a href="<?= e(url('/dashboard')) ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> Dashboard</a>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h1 class="h4 mb-0"><i class="bi bi-arrow-repeat"></i> Next Enrollment Eligibility<?php if ($activeChild !== null): ?> <span class="fs-6 text-muted">— <?= e($childName($activeChild)) ?></span><?php endif; ?></h1>
  <?php if ($state === 'eligible'): ?><span class="badge text-bg-success fs-6">ELIGIBLE</span>
  <?php elseif ($state === 'graduating'): ?><span class="badge text-bg-primary fs-6">PROGRAM COMPLETE</span>
  <?php elseif ($state === 'already_enrolled'): ?><span class="badge text-bg-info fs-6">IN PROGRESS</span>
  <?php elseif ($state === 'first_enrollment'): ?><span class="badge text-bg-secondary fs-6">FIRST ENROLLMENT</span>
  <?php else: ?><span class="badge text-bg-warning text-dark fs-6">NOT YET ELIGIBLE</span><?php endif; ?>
</div>

<?php if (count($children) > 1): ?>
<div class="card p-3 mb-3">
  <div class="small fw-semibold mb-2"><i class="bi bi-people"></i> Check another child</div>
  <div class="d-flex gap-2 flex-wrap">
    <?php foreach ($children as $ch): $cid = (int) ($ch['id'] ?? 0); $isActive = $activeChild !== null && (int) ($activeChild['id'] ?? 0) === $cid; ?>
      <a href="<?= e(url('/enrollment/eligibility?child=' . $cid)) ?>" class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-primary' ?>"><?= e($childName($ch)) ?><?= !empty($ch['level_name']) ? ' · ' . e($ch['level_name']) : '' ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($activeChild !== null || count($children) > 0): ?>
<div class="card p-3 mb-3 border-success">
  <div class="small fw-semibold mb-1"><i class="bi bi-person-plus"></i> Filing for a different child?</div>
  <p class="small text-muted mb-2">This page checks <strong><?= $activeChild !== null ? e($childName($activeChild)) : 'one child' ?></strong> only. To enroll another child, start a new filing — their documents are tracked separately.</p>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= e(url('/enroll')) ?>" class="btn btn-sm btn-success"><i class="bi bi-send"></i> Enroll a new child</a>
    <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-sm btn-outline-secondary">View my applications</a>
  </div>
</div>
<?php endif; ?>

<?php if ($state === 'first_enrollment'): ?>
  <div class="card p-4 text-center"><p class="mb-3">You have no completed enrollment yet. Start with your first application.</p>
  <a href="<?= e(url('/enroll')) ?>" class="btn btn-primary">Start First Enrollment</a></div>
<?php elseif ($state === 'graduating'): ?>
  <div class="card p-4 text-center"><div class="fs-2">🎓</div><h2 class="h5">Program Complete</h2>
  <p class="text-muted small">You finished the final academic period. Please see the Registrar about graduation.</p></div>
<?php else: ?>
<?php $parentGuide = (isset($parentGuide) && is_array($parentGuide)) ? $parentGuide : null; ?>
<?php if ($parentGuide !== null): ?>
  <!-- parent-guide v2 -->
  <div class="card p-4 mb-3">
    <h2 class="h6">What to do next<?php if ($parentGuide['name'] !== ''): ?> — <?= e($parentGuide['name']) ?><?php endif; ?></h2>
    <?php if ($parentGuide['nextLabel'] !== ''): ?><p class="mb-3">Next: <strong><?= e($parentGuide['nextLabel']) ?></strong></p><?php endif; ?>
    <?php if ($state === 'eligible'): ?>
      <a href="<?= e(url($parentGuide['enrollUrl'])) ?>" class="btn btn-success btn-lg w-100"><i class="bi bi-send"></i> Enroll<?= $parentGuide['name'] !== '' ? ' ' . e($parentGuide['name']) : '' ?> Now</a>
    <?php elseif ($state === 'already_enrolled'): ?>
      <p class="text-muted small">Application already filed — just wait for updates.</p>
      <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-info w-100">Track Application</a>
    <?php else: ?>
      <p class="mb-1">Not ready yet.</p>
      <?php if ($parentGuide['step'] !== ''): ?><p class="mb-3">Next step: <strong><?= e($parentGuide['step']) ?></strong></p><?php endif; ?>
      <?php if ($parentGuide['link'] !== ''): ?><a href="<?= e(url($parentGuide['link'])) ?>" class="btn btn-primary w-100"><?= e($parentGuide['label']) ?></a><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="card p-3 h-100"><h2 class="h6">Current Academic Period</h2>
        <?php if ($cur): ?>
        <dl class="row small mb-0">
          <dt class="col-5 text-muted">Level</dt><dd class="col-7 fw-semibold"><?= e($cur['level_name'] ?? '') ?></dd>
          <dt class="col-5 text-muted"><?= e($perNoun($cur)) ?></dt><dd class="col-7"><?= e($perLabel($cur)) ?></dd>
          <dt class="col-5 text-muted">School Year</dt><dd class="col-7"><?= e($cur['ay_label'] ?? '') ?></dd>
        </dl>
        <?php else: ?><p class="text-muted small mb-0">No enrollment record found.</p><?php endif; ?>
        <?php if ($next && empty($next['graduating'])): ?>
        <hr><h2 class="h6">Next Academic Period</h2>
        <dl class="row small mb-0">
          <dt class="col-5 text-muted">Level</dt><dd class="col-7 fw-semibold"><?= e($next['level_name'] ?? '') ?></dd>
          <dt class="col-5 text-muted"><?= e($perNoun($next)) ?></dt><dd class="col-7"><?= e($perLabel($next)) ?></dd>
          <dt class="col-5 text-muted">School Year</dt><dd class="col-7"><?= e($next['ay_label'] ?? '') ?: '<span class="text-muted">to be opened</span>' ?></dd>
        </dl>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12 col-lg-7">
      <div class="card p-3 h-100"><h2 class="h6">Requirements</h2>
        <?php if (empty($check['items'])): ?><p class="text-muted small mb-0">Nothing to evaluate yet.</p><?php endif; ?>
        <ul class="list-group list-group-flush small">
        <?php foreach (($check['items'] ?? []) as $k => $it): ?>
          <li class="list-group-item d-flex gap-2 px-0">
            <span><?= !empty($it['ok']) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-hourglass-split text-warning"></i>' ?></span>
            <span><strong><?= e($it['label'] ?? $k) ?></strong><br><span class="text-muted"><?= e($it['detail'] ?? '') ?></span></span>
          </li>
        <?php endforeach; ?>
        </ul>
        <div class="mt-3 d-flex gap-2 flex-wrap">
        <?php if ($state === 'eligible'): ?>
          <a href="<?= e(url('/enroll' . ($activeChild !== null ? '?child=' . (int) ($activeChild['id'] ?? 0) : ''))) ?>" class="btn btn-success"><i class="bi bi-send"></i> Enroll<?= $activeChild !== null ? ' ' . e($childName($activeChild)) : '' ?> for Next Period</a>
          <?php if (!empty($offerLink)): ?><a href="<?= e(url($offerLink)) ?>" class="btn btn-outline-primary"><i class="bi bi-easel2"></i> View Next-Term Offerings</a><?php endif; ?>
        <?php elseif ($state === 'already_enrolled'): ?>
          <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-info">Track My Application</a>
        <?php else: ?>
          <a href="<?= e(url('/student/clearances')) ?>" class="btn btn-outline-primary">Go to My Clearances</a>
          <a href="<?= e(url('/student/grades')) ?>" class="btn btn-outline-secondary">Check Grades</a>
        <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<div class="small text-muted mt-3">Same account, same student number — each academic period creates a new enrollment record. History is never overwritten.</div>
