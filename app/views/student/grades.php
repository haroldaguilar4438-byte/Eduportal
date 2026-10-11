<?php
/**
 * Student grades — academic performance interface (spec §9).
 *
 * Non-destructive: $rows, $gwa, $totalUnits, $scales, $academicYears, $filters
 * and $periodOptions are exactly what StudentPortalController::grades() passed
 * before, the AY/period filters still submit, and the Grade Slip link is intact.
 *
 * The query returns one row per subject x grading period, so this pivots them
 * into the Prelim / Midterm / Prefinal / Final columns the spec asks for. Only
 * periods that actually exist in the data are shown as columns — nothing is
 * invented. Instructor is omitted because this query does not select it.
 *
 * @var array $rows
 * @var float|null $gwa
 * @var float $totalUnits
 * @var array $academicYears
 * @var array $filters
 */
$f = $filters ?? [];
$rows = $rows ?? [];
$periodOptions = $periodOptions ?? ['1st', '2nd', 'Summer'];
$academicYears = $academicYears ?? [];
$gwa = $gwa ?? null;
$totalUnits = (float) ($totalUnits ?? 0);

/* ---- Pivot: subject => [periodCode => grade] ---------------------------- */
$subjects = [];
$periodsSeen = [];
$unitsPassed = 0.0;
$passingCount = 0;
$incCount = 0;
$gradedCount = 0;

foreach ($rows as $r) {
    $code = (string) ($r['subject_code'] ?? '');
    if ($code === '') {
        continue;
    }
    if (!isset($subjects[$code])) {
        $subjects[$code] = [
            'code'     => $code,
            'title'    => (string) ($r['subject_title'] ?? ''),
            'units'    => (float) ($r['units'] ?? 0),
            'section'  => (string) ($r['section_name'] ?? ''),
            'schoolYear'=> (string) ($r['school_year'] ?? ''),
            'periods'  => [],
            'remarks'  => '',
            'equivalent'=> '',
            'passing'  => true,
            'inc'      => false,
        ];
    }
    $pcode = (string) ($r['period_code'] ?? '');
    if ($pcode !== '') {
        $subjects[$code]['periods'][$pcode] = $r;
        $periodsSeen[$pcode] = true;
    }
    /* Terminal metadata comes from the last row seen for the subject. */
    if (!empty($r['inc'])) {
        $subjects[$code]['inc'] = true;
    } elseif ($r['final_grade'] !== null) {
        $subjects[$code]['remarks']   = (string) ($r['scale_remarks'] ?? $r['remarks'] ?? '');
        $subjects[$code]['equivalent'] = (string) ($r['equivalent'] ?? '');
        $subjects[$code]['passing']   = (bool) ($r['passing'] ?? true);
    }
}

/* Order the period columns the way the school year actually runs. */
$PERIOD_ORDER = ['Prelim', 'Midterm', 'Prefinal', 'Finals', 'Final', 'Pre-final'];
$periodCols = [];
foreach (array_keys($periodsSeen) as $pc) {
    $periodCols[] = $pc;
}
usort($periodCols, static function ($a, $b) use ($PERIOD_ORDER) {
    $ia = array_search($a, $PERIOD_ORDER, true);
    $ib = array_search($b, $PERIOD_ORDER, true);
    $ia = $ia === false ? 99 : $ia;
    $ib = $ib === false ? 99 : $ib;
    return $ia <=> $ib;
});

/* Totals for the summary tiles. */
foreach ($subjects as $s) {
    $gradedCount++;
    if ($s['inc']) {
        $incCount++;
    } elseif ($s['passing']) {
        $unitsPassed += $s['units'];
    }
    if ($s['passing'] && !$s['inc']) {
        $passingCount++;
    }
}

/* Academic standing, derived from the released GWA using the usual PH scale. */
$standing = 'No grades yet';
$standingTone = 'neutral';
if ($gwa !== null) {
    if ($gwa <= 3.00)      { $standing = 'Outstanding';   $standingTone = 'success'; }
    elseif ($gwa <= 5.00)  { $standing = 'Very Good';     $standingTone = 'success'; }
    elseif ($gwa <= 8.00)  { $standing = 'Good';          $standingTone = 'info'; }
    elseif ($gwa <= 10.00) { $standing = 'Needs Improvement'; $standingTone = 'warning'; }
    else                   { $standing = 'Failed';        $standingTone = 'danger'; }
}
?>

<?= edu_page_header([
    'eyebrow'     => 'Academic performance',
    'title'       => 'My grades',
    'description' => 'Only registrar-finalized grades appear here. Drafts and raw task scores are never shown.',
    'actions'     => '<a href="' . e(url('/student/documents')) . '" class="btn btn-light btn-sm"><i class="bi bi-file-earmark-text me-1"></i> Request a grade slip</a>',
]) ?>

<?php if ($rows === []): ?>
  <?= edu_empty_state(
        'No grades have been released yet',
        'Grades appear here after your teachers submit their grade sheets and the Registrar finalizes them.',
        [
            'icon'    => 'bi-mortarboard',
            'actions' => '<a href="' . e(url('/student/my-classes')) . '" class="btn btn-light btn-sm">View my classes</a>'
                      . '<a href="' . e(url('/student/documents')) . '" class="btn btn-primary btn-sm">Request a grade slip</a>',
        ]
    ) ?>
<?php else: ?>

  <!-- Performance summary -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'General weighted average',
        'value' => $gwa === null ? '&mdash;' : e(number_format($gwa, 2)),
        'icon'  => 'bi-graph-up-arrow', 'tone' => 'primary',
        'meta'  => '<span>Across all released finals</span>',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Academic standing',
        'value' => '<span style="font-size:1.25rem">' . e($standing) . '</span>',
        'icon'  => 'bi-award', 'tone' => $standingTone,
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Units earned',
        'value' => e(number_format($unitsPassed, 1)),
        'icon'  => 'bi-check2-circle', 'tone' => 'success',
        'meta'  => '<span>of ' . e(number_format($totalUnits, 1)) . ' taken</span>',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Subjects graded',
        'value' => e((string) $gradedCount),
        'icon'  => 'bi-journal-check', 'tone' => $incCount > 0 ? 'warning' : 'info',
        'meta'  => $incCount > 0
            ? '<span class="edu-badge edu-badge--warning"><i class="bi bi-exclamation-triangle-fill"></i> ' . $incCount . ' incomplete</span>'
            : '<span>' . $passingCount . ' passing</span>',
    ]) ?></div>
  </div>

  <!-- Filters -->
  <form method="get" class="edu-filterbar" aria-label="Filter grades">
    <div class="edu-filterbar__search">
      <i class="bi bi-funnel" aria-hidden="true"></i>
      <label class="visually-hidden" for="gradeAy">School year</label>
      <select name="ay_id" id="gradeAy" class="form-select" onchange="this.form.submit()">
        <option value="">All school years</option>
        <?php foreach ($academicYears as $ay): ?>
          <option value="<?= (int) $ay['id'] ?>" <?= (int) ($f['ay_id'] ?? 0) === (int) $ay['id'] ? 'selected' : '' ?>>
            <?= e($ay['school_year']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="edu-filterbar__group">
      <label class="visually-hidden" for="gradeSem">Semester</label>
      <select name="semester" id="gradeSem" class="form-select" onchange="this.form.submit()">
        <option value="">All periods</option>
        <?php foreach ($periodOptions as $s): ?>
          <option value="<?= e($s) ?>" <?= ($f['semester'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <span class="ms-auto small" style="color:var(--text-tertiary)">
      <?= count($subjects) ?> subject<?= count($subjects) === 1 ? '' : 's' ?>
    </span>
  </form>

  <!-- Grade table: one row per subject, one column per grading period -->
  <div class="edu-table-wrap mb-4">
    <div class="edu-table-scroll">
      <table class="edu-table edu-table--cards">
        <thead>
          <tr>
            <th>Subject</th>
            <?php foreach ($periodCols as $pc): ?><th class="edu-table__num"><?= e($pc) ?></th><?php endforeach; ?>
            <th class="edu-table__num">Units</th>
            <th>Equivalent</th>
            <th>Result</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
          <tr>
            <td data-label="Subject">
              <span class="fw-semibold"><?= e($s['code']) ?></span>
              <div class="small" style="color:var(--text-tertiary)"><?= e($s['title']) ?></div>
              <div class="small" style="color:var(--text-tertiary)">
                <?= e($s['schoolYear']) ?><?= $s['section'] !== '' ? ' &middot; ' . e($s['section']) : '' ?>
              </div>
            </td>

            <?php foreach ($periodCols as $pc):
                $cell = $s['periods'][$pc] ?? null;
                $val  = $cell['final_grade'] ?? null; ?>
              <td data-label="<?= e($pc) ?>" class="edu-table__num">
                <?php if ($val === null): ?>
                  <span style="color:var(--border-strong)">&mdash;</span>
                <?php elseif (!empty($cell['inc'])): ?>
                  <?= edu_badge('INC', 'warning', 'bi-exclamation-triangle-fill') ?>
                <?php else: ?>
                  <span class="fw-semibold"><?= e(number_format((float) $val, 2)) ?></span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>

            <td data-label="Units" class="edu-table__num"><?= e(number_format($s['units'], 1)) ?></td>
            <td data-label="Equivalent"><?= e($s['equivalent'] !== '' ? $s['equivalent'] : '—') ?></td>
            <td data-label="Result">
              <?php if ($s['inc']): ?>
                <?= edu_badge('Incomplete', 'warning', 'bi-exclamation-triangle-fill') ?>
              <?php elseif ($s['passing']): ?>
                <?= edu_badge($s['remarks'] !== '' ? $s['remarks'] : 'Passed', 'success', 'bi-check-circle-fill') ?>
              <?php else: ?>
                <?= edu_badge($s['remarks'] !== '' ? $s['remarks'] : 'Failed', 'danger', 'bi-x-circle-fill') ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($incCount > 0): ?>
    <?= edu_callout(
        'Incomplete (INC) grades are excluded from your GWA. Complete the missing major work and ask your teacher to re-finalize the sheet.',
        ['tone' => 'warning', 'icon' => 'bi-exclamation-triangle-fill', 'title' => 'Incomplete grades on record']
    ) ?>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2">
    <a href="<?= e(url('/student/documents')) ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-printer me-1"></i> Printable grade slip
    </a>
    <a href="<?= e(url('/student/schedule')) ?>" class="btn btn-light btn-sm">
      <i class="bi bi-calendar-week me-1"></i> My schedule
    </a>
  </div>

<?php endif; ?>
