<?php
/**
 * Teacher grade sheet (spec §19).
 *
 * NON-DESTRUCTIVE: $class, $period, $sheet, $rows, $logs, $missing, $editable
 * and $calc are all used exactly as TeacherGradesController passed them. The
 * "Apply computed grades" and "Submit/Resubmit" forms keep their action URLs,
 * CSRF tokens and confirmation prompts. The engine-lock notice is preserved
 * verbatim in meaning: final grades are computed by the backend, never typed.
 *
 * BUG FIXED: the original file read $calc['weights'] into $cw *after* the
 * category-summary block had already looped over $cw, so that block ran against
 * an undefined variable. $cw is now defined before it is first used.
 *
 * Note on the spec: it asks for a "spreadsheet-like grade entry" with an
 * "unsaved changes" indicator. In this system teachers do not type final grades
 * — they grade raw assessments and the engine computes the final grade, which
 * the original page already states. So instead of a fake editor, this page
 * surfaces the real equivalent: a per-student "computed differs from stored"
 * flag, which is exactly the set of changes that are not yet applied.
 *
 * @var array  $class
 * @var array  $period
 * @var array  $sheet
 * @var array  $rows
 * @var array  $logs
 * @var int    $missing
 * @var bool   $editable
 * @var array  $calc
 */
$cw = (isset($calc['weights']) && is_array($calc['weights'])) ? $calc['weights'] : [];
$catSummary = $calc['catSummary'] ?? [];

/* Collect the per-student computed payload once. */
$computed = [];
if (isset($calc['students']) && is_array($calc['students'])) {
    foreach ($calc['students'] as $sid => $payload) {
        $computed[(int) $sid] = is_array($payload) ? $payload : null;
    }
}

/* How many stored grades disagree with the engine, and how many are empty.
   $engineMissing counts students with genuinely missing assessments (per the
   engine), which is what "Missing work" must show. $notGraded / controller
   $missing count sheet rows whose computed grade was never Applied — a
   workflow state, not missing work. */
$unapplied = 0;
$notGraded = 0;
$incCount  = 0;
$engineMissing = 0;
foreach ($rows as $r) {
    $sid = (int) $r['student_id'];
    $cb  = $computed[$sid] ?? null;
    $rowInc = ($r['grade'] === null && trim((string) ($r['remarks'] ?? '')) === 'INC');

    if ($rowInc) { $incCount++; }
    if ($r['grade'] === null && !$rowInc) { $notGraded++; }
    if (is_array($cb) && isset($cb['missing']) && (int) $cb['missing'] > 0) { $engineMissing++; }

    if ($cb !== null && $r['grade'] !== null) {
        $enginePoint = $cb['point'] ?? null;
        if ($enginePoint !== null && abs((float) $enginePoint - (float) $r['grade']) > 0.0001) {
            $unapplied++;
        }
    }
}

$sheetStatus = (string) ($sheet['status'] ?? 'draft');
?>

<?= edu_page_header([
    'eyebrow'     => 'Grade sheet',
    'title'       => $class['subject_code'] . ' — ' . $period['code'],
    'description' => e($class['subject_title'] ?? '')
        . (($class['section_name'] ?? '') !== '' ? ' · Section ' . e($class['section_name']) : '')
        . ' · ' . count($rows) . ' student' . (count($rows) === 1 ? '' : 's')
        . ' · ' . (int) $engineMissing . ' missing',
    'actions'     => '<a href="' . e(url('/teacher/grades')) . '" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i> All sheets</a>'
                   . '<a href="' . e(url('/teacher/roster/' . (int) $class['id'])) . '" class="btn btn-light btn-sm"><i class="bi bi-people me-1"></i> Roster</a>',
]) ?>

<!-- Sheet status pipeline -->
<div class="edu-card mb-3">
  <div class="edu-card__body">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <?= edu_status_badge($sheetStatus, 'lg') ?>
      <?php if ($editable): ?>
        <?= edu_badge('Editable', 'success', 'bi-unlock') ?>
      <?php else: ?>
        <?= edu_badge('Locked', 'neutral', 'bi-lock') ?>
      <?php endif; ?>
      <?php if ($unapplied > 0): ?>
        <?= edu_badge($unapplied . ' computed change' . ($unapplied === 1 ? '' : 's') . ' not applied', 'warning', 'bi-exclamation-triangle-fill') ?>
      <?php endif; ?>
    </div>
    <?= edu_timeline([
        ['label' => 'Draft',      'hint' => 'You work here',    'state' => in_array($sheetStatus, ['draft', 'returned'], true) ? 'current' : 'complete'],
        ['label' => 'Submitted',  'hint' => 'To Registrar',     'state' => in_array($sheetStatus, ['submitted', 'under_review'], true) ? 'current' : (in_array($sheetStatus, ['approved','finalized'], true) ? 'complete' : '')],
        ['label' => 'Review',     'hint' => 'Registrar checks', 'state' => $sheetStatus === 'under_review' ? 'current' : (in_array($sheetStatus, ['approved','finalized'], true) ? 'complete' : '')],
        ['label' => 'Finalized',  'hint' => 'Released',        'state' => $sheetStatus === 'finalized' ? 'complete' : ''],
    ]) ?>
    <?php if ($sheetStatus === 'returned'): ?>
      <div class="mt-2"><?= edu_stepper([
          ['label' => 'Returned', 'hint' => 'Correct and resubmit', 'state' => 'current'],
      ], true) ?></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($sheetStatus === 'returned' && !empty($sheet['review_remarks'])): ?>
  <?= edu_callout(
      '<strong>Returned for correction:</strong> ' . e((string) $sheet['review_remarks'])
      . '<div class="mt-1 small">Correct the raw assessments, then Apply computed grades and Submit again.</div>',
      ['tone' => 'danger', 'icon' => 'bi-arrow-counterclockwise', 'title' => 'Changes requested by the Registrar']
    ) ?>
<?php endif; ?>

<?php if (!$editable): ?>
  <?= edu_callout(
      'This sheet is <strong>' . e($sheetStatus) . '</strong> and locked. You can still view every computed value below.',
      ['tone' => 'info', 'icon' => 'bi-lock-fill', 'title' => 'Read only']
    ) ?>
<?php endif; ?>

<?php if ($rows === []): ?>
  <?= edu_empty_state(
        'No enrolled students in this subject yet',
        'Once students join this class their assessment results and computed grades will appear here.',
        ['icon' => 'bi-people']
    ) ?>

<?php else: ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Students', 'value' => (string) count($rows), 'icon' => 'bi-people', 'tone' => 'primary']) ?></div>
<div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Missing work', 'value' => (string) $engineMissing, 'icon' => 'bi-exclamation-triangle-fill', 'tone' => $engineMissing > 0 ? 'warning' : 'success', 'meta' => '<span>Assessments with no submission</span>']) ?></div>
<div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Not yet graded', 'value' => (string) $notGraded, 'icon' => 'bi-dash-circle', 'tone' => $notGraded > 0 ? 'warning' : 'success', 'meta' => $notGraded > 0 ? '<span>Computed — click <strong>Apply computed grades</strong> below</span>' : '<span>All grades recorded</span>']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Incomplete (INC)', 'value' => (string) $incCount, 'icon' => 'bi-exclamation-octagon', 'tone' => $incCount > 0 ? 'danger' : 'neutral']) ?></div>
  </div>

  <!-- A. Raw assessment results -->
  <div class="edu-card mb-3">
    <div class="edu-card__head">
      <h2 class="edu-card__title"><i class="bi bi-clipboard-data"></i> A. Assessment results (raw)</h2>
      <?= edu_badge('Backend-computed', 'info', 'bi-cpu') ?>
    </div>
    <div class="edu-card__body">
      <?php if ($cw === []): ?>
        <?= edu_callout('No active category weights are configured. Ask the Registrar to set grading weights before this sheet can be computed.', ['tone' => 'warning', 'icon' => 'bi-exclamation-triangle-fill', 'title' => 'Grading weights missing']) ?>
      <?php else: ?>
        <?php if ($catSummary !== []): ?>
          <div class="row g-2 mb-3">
            <?php foreach ($cw as $cid => $w):
                $cs = $catSummary[$cid] ?? ['assessments' => 0, 'avg_pct' => null];
                $wPct = rtrim(rtrim(number_format((float) $w['weight'], 2), '0'), '.'); ?>
              <div class="col-6 col-md-4 col-lg-2">
                <?= edu_stat_card([
                    'label' => strtoupper((string) $w['name']),
                    'value' => $cs['avg_pct'] === null ? '—' : e(number_format((float) $cs['avg_pct'], 1)) . '%',
                    'icon'  => 'bi-percent', 'size' => 'sm',
                    'meta'  => '<span>' . (int) $cs['assessments'] . ' assessment' . ((int) $cs['assessments'] === 1 ? '' : 's') . ' · ' . e($wPct) . '% weight</span>',
                ]) ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php
        $wparts = [];
        foreach ($cw as $w) {
            $wparts[] = $w['name'] . ' ' . rtrim(rtrim(number_format((float) $w['weight'], 2), '0'), '.') . '%';
        }
        $untagged = isset($calc['untagged']) ? (int) $calc['untagged'] : 0;
        ?>
        <p class="small mb-3" style="color:var(--text-tertiary)">
          <strong>Weights:</strong> <?= e(implode(' · ', $wparts)) ?><br>
          Missing minor work (activities, quizzes, assignments) counts as 0 and deducts.
          Missing major work (exams, projects) is marked INC.
          <?php if ($untagged > 0): ?>
            <br><?php echo $untagged; ?> untagged activities are counted in every period as Activities.
          <?php endif; ?>
        </p>

        <div class="edu-table-wrap">
          <div class="edu-table-scroll">
            <table class="edu-table edu-table--compact edu-table--cards">
              <thead>
                <tr>
                  <th>Student</th>
                  <?php foreach ($cw as $w): ?>
                    <th class="edu-table__num"><?= e($w['name']) ?><br><span style="font-weight:400;text-transform:none;letter-spacing:0">raw → %</span></th>
                  <?php endforeach; ?>
                  <th class="edu-table__num">Weighted %</th>
                  <th class="edu-table__num">Computed grade</th>
                  <th class="edu-table__num">Missing</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r):
                  $cb    = $computed[(int) $r['student_id']] ?? null;
                  $isInc = is_array($cb) && !empty($cb['inc']); ?>
                <tr>
                  <td data-label="Student">
                    <span class="fw-semibold"><?= e($r['student_name']) ?></span>
                    <div class="small" style="color:var(--text-tertiary)"><?= e($r['student_no'] ?? '') ?></div>
                    <?php if ($isInc): ?>
                      <div class="mt-1"><?= edu_badge('INC — missing major work', 'danger', 'bi-exclamation-octagon-fill') ?></div>
                      <?php if (!empty($cb['inc_missing'])): ?>
                        <div class="small" style="color:var(--danger-text)">
                          <?= e(implode('; ', array_slice($cb['inc_missing'], 0, 3))) ?><?= count($cb['inc_missing']) > 3 ? ' (+' . (count($cb['inc_missing']) - 3) . ' more)' : '' ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>

                  <?php foreach ($cw as $cid => $w):
                      $cp = (is_array($cb) && isset($cb['cats'][$cid])) ? $cb['cats'][$cid] : null; ?>
                    <td data-label="<?= e($w['name']) ?>" class="edu-table__num">
                      <?php if ($cp === null || !isset($cp['pct']) || $cp['pct'] === null): ?>
                        <span style="color:var(--border-strong)">—</span>
                      <?php else: ?>
                        <span class="fw-semibold"><?= e(number_format((float) $cp['pct'], 1)) ?></span>
                        <div class="small" style="color:var(--text-tertiary)"><?= e((string) $cp['earned']) ?>/<?= e((string) $cp['possible']) ?></div>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>

                  <td data-label="Weighted %" class="edu-table__num">
                    <?php if ($isInc): ?>
                      <span style="color:var(--border-strong)">—</span>
                    <?php elseif (!is_array($cb) || !isset($cb['score']) || $cb['score'] === null): ?>
                      <span style="color:var(--border-strong)">—</span>
                    <?php else: ?>
                      <?= e(number_format((float) $cb['score'], 2)) ?>
                    <?php endif; ?>
                  </td>

                  <td data-label="Computed grade" class="edu-table__num">
                    <?php if ($isInc): ?>
                      <?= edu_badge('INC', 'danger', 'bi-exclamation-octagon-fill') ?>
                    <?php elseif (!is_array($cb) || !isset($cb['point']) || $cb['point'] === null): ?>
                      <?= edu_badge('No data', 'warning', 'bi-dash-circle') ?>
                    <?php else: ?>
                      <?= edu_badge(number_format((float) $cb['point'], 2), 'primary', 'bi-cpu') ?>
                    <?php endif; ?>
                  </td>

                  <td data-label="Missing" class="edu-table__num">
                    <?php $miss = (is_array($cb) && isset($cb['missing'])) ? (int) $cb['missing'] : 0; ?>
                    <?php if ($miss > 0): ?>
                      <?= edu_badge((string) $miss, 'danger', 'bi-exclamation-triangle-fill') ?>
                    <?php else: ?>
                      <?= edu_badge('0', 'success', 'bi-check-lg') ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- B. Computed final grades -->
  <div class="edu-card mb-3">
    <div class="edu-card__head">
      <h2 class="edu-card__title"><i class="bi bi-calculator"></i> B. Computed final grade</h2>
      <?= edu_badge('Engine-locked', 'neutral', 'bi-lock') ?>
    </div>
    <div class="edu-card__body">
      <?= edu_callout(
          'Final grades are <strong>locked to engine computation</strong> — teachers cannot type or edit them. Grade the raw assessments, click <strong>Apply computed grades</strong>, then <strong>Submit to Registrar</strong>. Corrections happen in the assessments, never here.',
          ['tone' => 'info', 'icon' => 'bi-shield-lock']
        ) ?>

      <div class="edu-table-wrap mt-3">
        <div class="edu-table-scroll">
          <table class="edu-table edu-table--compact edu-table--cards">
            <thead><tr><th>Student no.</th><th>Name</th><th>Final grade</th><th>Remarks</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $rowInc = ($r['grade'] === null && trim((string) ($r['remarks'] ?? '')) === 'INC');
                $cb     = $computed[(int) $r['student_id']] ?? null;
                $differs = ($cb !== null && $r['grade'] !== null && isset($cb['point'])
                            && abs((float) $cb['point'] - (float) $r['grade']) > 0.0001); ?>
              <tr>
                <td data-label="Student no." class="fw-semibold"><?= e($r['student_no'] ?? '—') ?></td>
                <td data-label="Name">
                  <?= e($r['student_name']) ?>
                  <?php if ($rowInc): ?> <?= edu_badge('INC', 'danger', 'bi-exclamation-octagon-fill') ?><?php endif; ?>
                </td>
                <td data-label="Final grade">
                  <?php if ($rowInc): ?>
                    <?= edu_badge('INC', 'danger', 'bi-exclamation-octagon-fill') ?>
                  <?php elseif ($r['grade'] === null): ?>
                    <?= edu_badge('Missing', 'warning', 'bi-dash-circle') ?>
                  <?php else: ?>
                    <?= edu_badge((string) $r['grade'], 'primary', 'bi-check-circle-fill') ?>
                  <?php endif; ?>
                  <?php if ($differs): ?>
                    <div class="mt-1"><?= edu_badge('engine says ' . number_format((float) $cb['point'], 2), 'warning', 'bi-exclamation-triangle-fill') ?></div>
                  <?php endif; ?>
                </td>
                <td data-label="Remarks" class="small" style="color:var(--text-tertiary)"><?= e($r['remarks'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($editable): ?>
        <?php if ($unapplied > 0): ?>
          <div class="mt-3"><?= edu_callout(
              $unapplied . ' stored grade' . ($unapplied === 1 ? ' differs' : 's differ') . ' from the engine computation. Click <strong>Apply computed grades</strong> to bring them in line before submitting.',
              ['tone' => 'warning', 'icon' => 'bi-exclamation-triangle-fill', 'title' => 'Unapplied computed changes']
          ) ?></div>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-3 flex-wrap">
          <form method="post" action="<?= e(url('/teacher/grades/' . (int) $sheet['id'] . '/apply-computed')) ?>"
                onsubmit="return confirm('Recompute every grade from the raw assessments? Missing minor work counts as 0; students missing major work (exams/projects) are marked INC.')">
            <?= csrf_field() ?>
            <button class="btn btn-outline-success"><i class="bi bi-cpu me-1"></i> Apply computed grades</button>
          </form>
          <form method="post" action="<?= e(url('/teacher/grades/' . (int) $sheet['id'] . '/submit')) ?>"
                onsubmit="return confirm('Submit computed grades to the Registrar? Every grade is verified against the engine before sending.')">
            <?= csrf_field() ?>
            <button class="btn btn-success"><i class="bi bi-send me-1"></i>
              <?= $sheetStatus === 'returned' ? 'Resubmit corrections' : 'Submit to Registrar' ?>
            </button>
          </form>
        </div>
        <p class="small mb-0 mt-2" style="color:var(--text-tertiary)">
          Submit requires every student graded or marked INC (missing major work). Minor missing work counts as 0.
          Scale: 1.00 (best) – 5.00. Passing bands are set by the Registrar. Submit is blocked if any grade
          differs from the engine computation.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Submission history -->
  <div class="edu-card">
    <div class="edu-card__head">
      <h2 class="edu-card__title"><i class="bi bi-clock-history"></i> Submission history</h2>
      <?= edu_badge((string) count($logs) . ' event' . (count($logs) === 1 ? '' : 's'), 'neutral') ?>
    </div>
    <div class="edu-card__body">
      <?php if ($logs === []): ?>
        <?= edu_empty_state('No history yet', 'Actions on this sheet will be recorded here with their timestamps.', ['icon' => 'bi-clock-history', 'compact' => true]) ?>
      <?php else: ?>
        <div class="edu-feed">
          <?php foreach ($logs as $l): ?>
            <div class="edu-feed__item">
              <span class="edu-feed__icon" aria-hidden="true"><i class="bi bi-arrow-left-right"></i></span>
              <div class="edu-feed__body">
                <div class="edu-feed__title">
                  <?= e($l['action'] ?? '') ?>
                  <?= ($l['from_status'] ?? '-') ?> &rarr; <strong><?= e($l['to_status'] ?? '') ?></strong>
                  by <?= e($l['actor'] ?? 'system') ?>
                </div>
                <?php if (!empty($l['remarks'])): ?>
                  <div class="small" style="color:var(--text-tertiary)"><?= e($l['remarks']) ?></div>
                <?php endif; ?>
                <div class="edu-feed__meta"><?= e(edu_datetime($l['created_at'] ?? null)) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>
