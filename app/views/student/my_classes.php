<?php
/**
 * My Classes — course-card view (spec §7).
 *
 * Non-destructive: every variable, query result, link and POST form from the
 * previous table-based version is preserved, including the drop-subject form,
 * the pending join-request cancellation and the parent `child_id` passthrough.
 * Only the presentation changed: rows became course cards, which is what this
 * page is actually about.
 *
 * @var array $rows
 * @var float $totalUnits
 * @var array $student
 * @var array|null $acadCtx
 * @var bool   $isParent
 * @var int|null $childId
 * @var array $pendingRequests
 */
$acadCtx        = $acadCtx ?? null;
$isParentMc     = !empty($isParent);
$childQmc       = ($isParentMc && !empty($childId)) ? '?child_id=' . (int) $childId : '';
$currentRows    = $currentRows ?? array_values(array_filter($rows, static fn ($r) => ($r['reg_status'] ?? '') === 'registered'));
$pastRows       = $pastRows ?? array_values(array_filter($rows, static fn ($r) => ($r['reg_status'] ?? '') !== 'registered'));
$pendingRequests = $pendingRequests ?? [];

$ctxBandMc         = $acadCtx['band'] ?? 'college';
$ctxNounMc         = $acadCtx['noun'] ?? 'Semester';
$ctxPeriodLabelMc  = $acadCtx['period_label'] ?? '';
$ctxLevelLabelMc   = $ctxBandMc === 'college' ? ($acadCtx['short_year'] ?? '') : ($acadCtx['level_name'] ?? '');

$rowPeriodMc = static function (array $r): string {
    $p = trim((string) ($r['period_code'] ?? ''));
    if ($p === '') $p = trim((string) ($r['semester'] ?? ''));
    if (in_array($p, ['1st', '2nd'], true)) return $p . ' Semester';
    return $p !== '' ? $p : '&mdash;';
};

/** Stable per-subject accent so a class is recognisable in the grid. */
$subjectTone = static function (string $seed): string {
    return ['', 'accent', 'info', 'success', 'warning'][abs(crc32($seed)) % 5];
};

/** Render one course card. */
$courseCard = static function (array $r, bool $isPast) use ($childQmc, $subjectTone, $rowPeriodMc): string {
    $code  = (string) ($r['subject_code'] ?? '');
    $title = (string) ($r['subject_title'] ?? '');
    $inst  = trim((string) ($r['instructor'] ?? ''));
    $tone  = $subjectTone($code);
    $sched = (string) ($r['schedule_note'] ?? '');
    $room  = trim((string) ($r['room'] ?? ''));
    $units = (float) ($r['units'] ?? 0);
    $grade = $r['final_grade'] ?? null;

    $detailUrl = url('/student/class-offerings/' . (int) $r['offering_id'] . $childQmc);

    $html = '<article class="edu-card-course">';
    $html .= '<div class="edu-card-course__top">';
    $html .= '<span class="edu-card-course__code">' . e($code) . '</span>';
    $html .= '<h3 class="edu-card-course__title">' . e($title) . '</h3>';
    $html .= '<div class="edu-card-course__meta">'
        . e((string) ($r['section_name'] ?? '')) . ' &middot; ' . e((string) ($r['school_year'] ?? ''))
        . ' &middot; ' . $rowPeriodMc($r) . '</div>';
    $html .= '</div>';

    $html .= '<div class="edu-card-course__body">';
    $html .= '<div class="edu-card-course__row"><i class="bi bi-person"></i><span>'
        . ($inst !== '' ? e($inst) : '<span style="color:var(--text-tertiary)">Instructor TBA</span>') . '</span></div>';
    $html .= '<div class="edu-card-course__row"><i class="bi bi-clock"></i><span>'
        . ($sched !== '' ? e($sched) : '<span style="color:var(--text-tertiary)">Schedule TBA</span>') . '</span></div>';
    $html .= '<div class="edu-card-course__row"><i class="bi bi-geo-alt"></i><span>'
        . ($room !== '' ? e($room) : '<span style="color:var(--text-tertiary)">Room TBA</span>') . '</span></div>';
    $html .= '<div class="edu-card-course__row"><i class="bi bi-book"></i><span>'
        . e(number_format($units, 1)) . ' unit' . ($units == 1.0 ? '' : 's') . '</span></div>';

    if ($grade !== null && $grade !== '') {
        $html .= '<div class="edu-card-course__row"><i class="bi bi-percent"></i><span>Final grade <strong>'
            . e(number_format((float) $grade, 2)) . '</strong></span></div>';
    }

    $html .= '</div>';

    $html .= '<div class="edu-card-course__foot">';
    if ($isPast) {
        $html .= '<span class="edu-badge edu-badge--neutral"><i class="bi bi-archive"></i> Past</span>';
        $html .= '<a href="' . e($detailUrl) . '" class="btn btn-sm btn-outline-secondary">View</a>';
    } else {
        $html .= '<span class="small" style="color:var(--text-tertiary)">Joined '
            . e(edu_date($r['registered_at'] ?? null)) . '</span>';
        $html .= '<div class="d-flex gap-1">'
            . '<a href="' . e($detailUrl) . '" class="btn btn-sm btn-outline-primary" aria-label="Open '
            . e($code) . '">Open classroom</a>'
            . '<form method="post" action="' . e(url('/student/my-classes/' . (int) $r['registration_id'] . '/drop' . $childQmc)) . '"'
            . ' onsubmit="return confirm(\'Drop ' . addslashes($code) . '? The slot will be released.\')">'
            . csrf_field()
            . '<button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Drop subject ' . e($code) . '">Drop</button>'
            . '</form></div>';
    }
    $html .= '</div>';

    return $html . '</article>';
};
?>

<?= edu_page_header([
    'eyebrow'     => 'My registration',
    'title'       => 'My subjects',
    'description' => 'Subjects you joined via invite code · ' . e(number_format((float) $totalUnits, 1)) . ' unit(s) enrolled.',
    'actions'     => '<a href="' . e(url('/student/class-offerings')) . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Browse eligible subjects</a>',
]) ?>

<?php if ($acadCtx !== null && ($acadCtx['has_active_enrollment'] ?? false)): ?>
  <?= edu_callout(
        '<strong>' . e($ctxLevelLabelMc) . ' &middot; ' . e($ctxPeriodLabelMc) . ' &middot; ' . e($acadCtx['ay_label'] ?? '') . '</strong>'
        . (!empty($acadCtx['program_code']) ? ' <span class="text-muted">&middot; ' . e($acadCtx['program_code']) . '</span>' : ''),
        ['icon' => 'bi-calendar-check', 'tone' => 'success', 'title' => 'Current ' . e(strtolower($ctxNounMc))]
    ) ?>
<?php endif; ?>

<?php if ($pendingRequests !== []): ?>
  <?= edu_section_head('Pending join requests', count($pendingRequests) . ' awaiting teacher confirmation', '', 'bi-hourglass-split') ?>
  <div class="edu-table-wrap mb-4">
    <div class="edu-table-scroll">
      <table class="edu-table edu-table--cards">
        <thead><tr><th>Subject</th><th>Term</th><th>Requested</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($pendingRequests as $pr): ?>
          <tr>
            <td data-label="Subject">
              <span class="fw-semibold"><?= e($pr['subject_code']) ?></span>
              <div class="small" style="color:var(--text-tertiary)"><?= e($pr['subject_title']) ?> &middot; <?= e($pr['section_name']) ?></div>
            </td>
            <td data-label="Term" class="small" style="color:var(--text-tertiary)"><?= e($pr['school_year']) ?></td>
            <td data-label="Requested">
              <div class="small" style="color:var(--text-tertiary)"><?= e(edu_date($pr['requested_at'] ?? null)) ?></div>
              <?= edu_badge('Awaiting teacher', 'warning', 'bi-hourglass-split') ?>
            </td>
            <td data-label="Actions" class="edu-table__actions-cell">
              <div class="edu-table__actions">
                <a href="<?= e(url('/student/class-offerings/' . (int) $pr['classroom_id'] . $childQmc)) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                <form method="post" action="<?= e(url('/student/join-requests/' . (int) $pr['id'] . '/cancel')) ?>" class="d-inline"
                      onsubmit="return confirm('Cancel the request for <?= e($pr['subject_code']) ?>?')">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($currentRows === [] && $pastRows === []): ?>
  <?= edu_empty_state(
        "You're not enrolled in any classes yet",
        'Join a subject from the Dashboard, browse eligible offerings, or request to join a class with your teacher\'s approval.',
        [
            'icon'    => 'bi-journal-plus',
            'actions' => '<a href="' . e(url('/student/class-offerings')) . '" class="btn btn-primary btn-sm">Find classes</a>'
                      . '<a href="' . e(url('/dashboard')) . '" class="btn btn-light btn-sm">Go to dashboard</a>',
        ]
    ) ?>
<?php else: ?>

  <?php if ($currentRows !== []): ?>
    <?= edu_section_head(
        'Current ' . strtolower($ctxNounMc) . ' subjects',
        count($currentRows) . ' class' . (count($currentRows) === 1 ? '' : 'es') . ' in progress',
        '<a href="' . e(url('/student/schedule')) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar-week me-1"></i> Weekly schedule</a>',
        'bi-easel2'
    ) ?>
    <div class="row g-3 mb-4">
      <?php foreach ($currentRows as $r): ?>
        <div class="col-12 col-md-6 col-xl-4 d-flex"><?= $courseCard($r, false) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($pastRows !== []): ?>
    <?= edu_section_head(
        'Past ' . strtolower($ctxNounMc) . ' subjects',
        count($pastRows) . ' class' . (count($pastRows) === 1 ? '' : 'es') . ' on record',
        '', 'bi-archive'
    ) ?>
    <div class="row g-3">
      <?php foreach ($pastRows as $r): ?>
        <div class="col-12 col-md-6 col-xl-4 d-flex"><?= $courseCard($r, true) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>
