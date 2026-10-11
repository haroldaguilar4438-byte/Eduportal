<?php
/**
 * Teacher grade sheets — subject x period matrix (spec §19, index view).
 *
 * Non-destructive: $classes, $periods and $matrix are used exactly as before
 * and every period cell still links to the same
 * /teacher/grades/{classroom}/{period} URL. The missing-count warning, the
 * status badge and the "Draft -> Submit -> Registrar" guidance are all kept.
 *
 * @var array $classes
 * @var array $periods
 * @var array $matrix
 */
$classes = $classes ?? [];
$periods = $periods ?? [];
$matrix  = $matrix ?? [];

/** Aggregate counts for the summary tiles. */
$counts = ['finalized' => 0, 'in_progress' => 0, 'needs_attention' => 0, 'missing_total' => 0];
foreach ($classes as $c) {
    foreach ($periods as $p) {
        $s  = $matrix[(int) $c['id']][$p['code']] ?? null;
        $st = (string) ($s['status'] ?? 'not_started');
        $miss = (int) ($s['missing'] ?? 0);

        if ($st === 'finalized') {
            $counts['finalized']++;
        } elseif (in_array($st, ['draft', 'submitted', 'under_review'], true)) {
            $counts['in_progress']++;
        }
        if ($miss > 0 && in_array($st, ['draft', 'returned'], true)) {
            $counts['needs_attention']++;
            $counts['missing_total'] += $miss;
        }
    }
}
?>

<?= edu_page_header([
    'eyebrow'     => 'Assessment',
    'title'       => 'Grade sheets',
    'description' => 'Enter grades per subject and period. Save a draft while you work, then submit to the Registrar. Returned sheets show the correction reason; finalized sheets are locked.',
    'actions'     => '<a href="' . e(url('/teacher/permits')) . '" class="btn btn-light btn-sm"><i class="bi bi-patch-check me-1"></i> Permit queue</a>'
                   . '<a href="' . e(url('/teacher/classrooms')) . '" class="btn btn-primary btn-sm"><i class="bi bi-easel2 me-1"></i> My classrooms</a>',
]) ?>

<?php if ($classes === []): ?>
  <?= edu_empty_state(
        'No assigned subjects yet',
        'You will see a grade sheet here once the Registrar assigns you to a class. Contact the Registrar or Admin if this looks wrong.',
        ['icon' => 'bi-journal-x']
    ) ?>
<?php else: ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Subjects assigned', 'value' => (string) count($classes),
        'icon' => 'bi-easel2', 'tone' => 'primary',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Sheets in progress', 'value' => (string) $counts['in_progress'],
        'icon' => 'bi-arrow-repeat', 'tone' => 'info',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Finalized', 'value' => (string) $counts['finalized'],
        'icon' => 'bi-patch-check-fill', 'tone' => 'success',
        'meta'  => '<span>Locked and released</span>',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Missing grades',
        'value' => (string) $counts['missing_total'],
        'icon'  => 'bi-exclamation-triangle-fill',
        'tone'  => $counts['missing_total'] > 0 ? 'warning' : 'success',
        'meta'  => $counts['missing_total'] > 0
            ? 'Across ' . $counts['needs_attention'] . ' sheet' . ($counts['needs_attention'] === 1 ? '' : 's')
            : '<span>All drafts complete</span>',
    ]) ?></div>
  </div>

  <?php foreach ($classes as $c):
      $cPer = trim((string) ($c['period_code'] ?? ''));
      if ($cPer === '') $cPer = trim((string) ($c['semester'] ?? ''));
      if (in_array($cPer, ['1st', '2nd'], true)) $cPer .= ' Semester';
      $classId = (int) $c['id']; ?>

    <article class="edu-card mb-3">
      <div class="edu-card__head">
        <div class="min-w-0">
          <h2 class="edu-card__title mb-1">
            <?= e($c['subject_code']) ?>
            <span style="font-weight:500;color:var(--text-tertiary)">&mdash;</span>
            <?= e($c['subject_title']) ?>
          </h2>
          <div class="small" style="color:var(--text-tertiary)">
            <?= e($c['section_name'] ?? '') ?> &middot; <?= e($cPer !== '' ? $cPer : '—') ?>
            &middot; <?= e($c['school_year'] ?? '') ?>
            &middot; <?= (int) $c['member_count'] ?> student<?= (int) $c['member_count'] === 1 ? '' : 's' ?>
          </div>
        </div>
        <a href="<?= e(url('/teacher/roster/' . $classId)) ?>" class="btn btn-light btn-sm">
          <i class="bi bi-people me-1"></i> Class roster
        </a>
      </div>

      <div class="edu-card__body">
        <div class="row g-2">
          <?php foreach ($periods as $p):
              $s     = $matrix[$classId][$p['code']] ?? null;
              $st    = (string) ($s['status'] ?? 'not_started');
              $label = $st === 'not_started' ? 'Not started' : edu_status_label($st);
              $miss  = (int) ($s['missing'] ?? 0);
              $showMiss = $s !== null && $miss > 0 && in_array($st, ['draft', 'returned'], true); ?>

            <div class="col-6 col-md-4 col-xl-3">
              <a href="<?= e(url('/teacher/grades/' . $classId . '/' . urlencode((string) $p['code']))) ?>"
                 class="edu-card h-100 p-3 text-decoration-none d-block edu-card--hover">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                  <span class="fw-bold" style="color:var(--primary-900)"><?= e($p['code']) ?></span>
                  <?= edu_status_badge($st === 'not_started' ? 'Not started' : $st) ?>
                </div>
                <div class="small" style="color:var(--text-tertiary)">
                  <?= $st === 'finalized'
                      ? 'Locked — released to students'
                      : ($st === 'returned' ? 'Returned for correction' : ($st === 'not_started' ? 'Tap to start' : 'Tap to open')) ?>
                </div>
                <?php if ($showMiss): ?>
                  <div class="mt-2"><?= edu_badge($miss . ' missing', 'warning', 'bi-exclamation-triangle-fill') ?></div>
                <?php endif; ?>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </article>
  <?php endforeach; ?>

<?php endif; ?>
