<?php
/**
 * Student clearances — department-by-department progress (spec §15).
 *
 * Non-destructive: $clearances, $canRequest, $reason, $currentPeriodLabel,
 * $isParent, $linkedChildren and $actingChildId are used exactly as before.
 * The request form (with its CSRF token, disabled state and explanatory title),
 * the print/download link, the public QR verification link and the
 * "check next enrollment" link are all preserved.
 *
 * What changed: each clearance now shows a real progress rail with the offices
 * that sign it (Finance, Registrar, Professor) and a computed overall
 * completion percentage, instead of a stack of badges.
 *
 * @var array $clearances
 * @var bool  $canRequest
 * @var string $reason
 * @var string $currentPeriodLabel
 * @var bool  $isParent
 * @var array $linkedChildren
 * @var int   $actingChildId
 */
$clearances = $clearances ?? [];
$canRequest = $canRequest ?? false;
$reason     = (string) ($reason ?? '');
$currentPeriodLabel = (string) ($currentPeriodLabel ?? '');
$isParent = $isParent ?? false;
$linkedChildren = $linkedChildren ?? [];
$actingChildId = (int) ($actingChildId ?? 0);

$statusMeta = static function (string $s): array {
    return match ($s) {
        'issued' => ['badge' => 'success', 'icon' => 'bi-patch-check-fill'],
        'signed' => ['badge' => 'info',    'icon' => 'bi-pen-fill'],
        'pending'=> ['badge' => 'warning', 'icon' => 'bi-hourglass-split'],
        'used'   => ['badge' => 'neutral', 'icon' => 'bi-archive'],
        default  => ['badge' => 'neutral', 'icon' => 'bi-dash-circle'],
    };
};

/**
 * Offices that sign a clearance, and how each one's stored value maps to a
 * state. Only offices the record actually reports are shown as "not required".
 */
$officeState = static function (string $value, string $approved, string $failed): string {
    $v = strtolower(trim($value));
    if ($v === '' ) return 'na';
    if ($v === $approved) return 'done';
    if ($v === $failed)  return 'failed';
    if (in_array($v, ['pending', 'awaiting', 'processing'], true)) return 'current';
    return 'current';
};

/* Overall completion across the most recent clearance, for the summary tile. */
$latestPct = 0;
if ($clearances !== []) {
    $c0 = $clearances[0];
    $steps = 3;
    $done = 0;
    $fin = $officeState((string) ($c0['financial_status'] ?? ''), 'cleared', 'not_cleared');
    $reg = $officeState((string) ($c0['registrar_status'] ?? ''), 'approved', 'returned');
    if ($fin === 'done') $done++;
    if ($reg === 'done') $done++;
    if (in_array((string) ($c0['status'] ?? ''), ['issued', 'used'], true)) $done++;
    $latestPct = (int) round($done / $steps * 100);
}
?>

<?= edu_page_header([
    'eyebrow'     => 'Clearance progress',
    'title'       => 'My clearances',
    'description' => $currentPeriodLabel !== ''
        ? 'Filing for ' . e($currentPeriodLabel) . '. A clearance unlocks enrolment for the next period.'
        : 'A clearance unlocks enrolment for the next period once every office has signed.',
    'actions'     => $canRequest
        ? '<form method="post" action="' . e(url('/student/clearances/request')) . '" class="d-inline">'
          . csrf_field()
          . ($isParent && $actingChildId > 0 ? '<input type="hidden" name="child_id" value="' . $actingChildId . '">' : '')
          . '<button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Request clearance</button></form>'
        : '<button class="btn btn-outline-secondary btn-sm" disabled title="' . e($reason) . '"><i class="bi bi-lock me-1"></i> Request clearance</button>',
]) ?>

<?php if (!$canRequest && $reason !== ''): ?>
  <?= edu_callout(e($reason), ['tone' => 'warning', 'icon' => 'bi-info-circle-fill', 'title' => 'Not eligible yet']) ?>
<?php endif; ?>

<?php if ($isParent && !empty($linkedChildren)): ?>
  <?= edu_section_head('Viewing on behalf of', 'Switch between your linked children', '', 'bi-people') ?>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($linkedChildren as $lc):
        $lcActive = (int) $lc['id'] === $actingChildId;
        $lcName = trim(implode(' ', array_filter([trim((string) ($lc['first_name'] ?? '')), trim((string) ($lc['middle_name'] ?? '')), trim((string) ($lc['last_name'] ?? '')), trim((string) ($lc['suffix'] ?? ''))])));
        if ($lcName === '') $lcName = 'Student #' . $lc['id']; ?>
      <a href="<?= e(url('/student/clearances?child_id=' . (int) $lc['id'])) ?>"
         class="btn btn-sm <?= $lcActive ? 'btn-primary' : 'btn-outline-secondary' ?>"
         <?= $lcActive ? 'aria-current="true"' : '' ?>><?= e($lcName) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($clearances === []): ?>
  <?= edu_empty_state(
        'No clearances yet',
        'Finish all four Finals permits to become eligible. Once eligible you can file a clearance here and each office will sign it in turn.',
        [
            'icon'    => 'bi-inbox',
            'actions' => '<a href="' . e(url('/student/permits')) . '" class="btn btn-primary btn-sm">Check my exam permits</a>',
        ]
    ) ?>

  <!-- The cycle, explained even with nothing on record -->
  <?= edu_section_head('How clearance works', 'The cycle every student follows', '', 'bi-signpost-split') ?>
  <div class="edu-card">
    <div class="edu-card__body">
      <?= edu_stepper([
          ['label' => 'Enroll',    'hint' => 'Join classes',   'state' => 'current'],
          ['label' => 'Attend',     'hint' => 'Meet classes'],
          ['label' => '4 Exams',    'hint' => 'All permits approved'],
          ['label' => 'Clearance',  'hint' => 'Offices sign'],
          ['label' => 'Next period','hint' => 'Re-enrol'],
      ]) ?>
    </div>
  </div>

<?php else: ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Clearances on file', 'value' => (string) count($clearances),
        'icon' => 'bi-folder-check', 'tone' => 'primary',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Most recent', 'value' => '<span style="font-size:1.15rem">' . e(edu_date($clearances[0]['created_at'] ?? null)) . '</span>',
        'icon' => 'bi-clock-history', 'tone' => 'info',
    ]) ?></div>
    <div class="col-12 col-lg-6">
      <?= edu_stat_card([
        'label' => 'Latest clearance completion',
        'value' => '<span style="font-size:1.15rem">' . $latestPct . '%</span>',
        'icon'  => $latestPct >= 100 ? 'bi-check2-all' : 'bi-hourglass-split',
        'tone'  => $latestPct >= 100 ? 'success' : ($latestPct > 0 ? 'warning' : 'neutral'),
        'meta'  => $latestPct >= 100
            ? '<span class="edu-badge edu-badge--success"><i class="bi bi-check-lg"></i> Eligible for next enrolment</span>'
            : '<span>Offices still to sign</span>',
        'size'  => 'sm',
      ]) ?>
    </div>
  </div>

  <div class="row g-3">
    <?php foreach ($clearances as $c):
        $st   = (string) ($c['status'] ?? '');
        $meta = $statusMeta($st);
        $fin  = $officeState((string) ($c['financial_status'] ?? ''), 'cleared', 'not_cleared');
        $reg  = $officeState((string) ($c['registrar_status'] ?? ''), 'approved', 'returned');
        $prof = in_array($st, ['issued', 'used'], true) ? 'done' : ($st === 'signed' ? 'current' : '');
        $cc   = trim((string) ($c['semester'] ?? ''));
        $ccLabel = in_array($cc, ['1st', '2nd'], true) ? $cc . ' Semester' : ($cc !== '' ? $cc : '—');
        $isIssued = $st === 'issued'; ?>
      <div class="col-12 col-xl-6">
        <article class="edu-card h-100 d-flex flex-column">
          <div class="edu-card__head">
            <div class="min-w-0">
              <h2 class="edu-card__title mb-1">
                <i class="bi bi-clipboard-check"></i>
                <?= e($ccLabel) ?> &middot; <?= e($c['school_year'] ?? '') ?>
              </h2>
              <div class="small" style="color:var(--text-tertiary)">
                ID #<?= e($c['id'] ?? '') ?> &middot; filed <?= e(edu_date($c['created_at'] ?? null)) ?>
              </div>
            </div>
            <?= edu_badge(edu_status_label($st), $meta['badge'], $meta['icon']) ?>
          </div>

          <div class="edu-card__body flex-grow-1">
            <!-- Office progress rail -->
            <div class="edu-track mb-3">
              <?php
              $offices = [
                  ['Finance',   $fin,  $c['financial_remarks'] ?? null],
                  ['Registrar', $reg,  $c['registrar_remarks'] ?? null],
                  ['Professor', $prof, $c['teacher_remarks']  ?? null],
              ];
              $oi = 0;
              foreach ($offices as [$oname, $ostate, $oremark]):
                  $stateCls = $ostate === 'done' ? 'is-done' : ($ostate === 'failed' ? 'is-failed' : ($ostate === 'current' ? 'is-active' : ''));
                  $glyph = $ostate === 'done' ? 'bi-check-lg' : ($ostate === 'failed' ? 'bi-x-lg' : ($ostate === 'na' ? 'bi-dash' : 'bi-hourglass')); ?>
                <div class="edu-track__node <?= $stateCls ?>">
                  <div class="edu-track__dot"><i class="bi <?= $glyph ?>"></i></div>
                  <div class="edu-track__label"><?= e($oname) ?></div>
                  <div class="edu-track__meta">
                    <?= $ostate === 'done' ? 'Signed' : ($ostate === 'failed' ? 'Returned' : ($ostate === 'na' ? 'Not required' : 'Awaiting')) ?>
                  </div>
                </div>
                <?php if ($oi < count($offices) - 1): ?>
                  <div class="edu-track__bar<?= $ostate === 'done' ? ' is-done' : '' ?>" aria-hidden="true"></div>
                <?php endif; ?>
                <?php $oi++; ?>
              <?php endforeach; ?>
            </div>

            <?php if (!empty($c['teacher_remarks'])): ?>
              <?= edu_callout('<strong>Professor note:</strong> ' . e((string) $c['teacher_remarks']), ['tone' => 'warning', 'icon' => 'bi-chat-left-quote']) ?>
            <?php elseif (!empty($c['registrar_remarks'])): ?>
              <?= edu_callout('<strong>Registrar note:</strong> ' . e((string) $c['registrar_remarks']), ['tone' => 'info', 'icon' => 'bi-chat-left-quote']) ?>
            <?php endif; ?>

            <?php if (!empty($c['qr_token'])): ?>
              <div class="small mt-2" style="color:var(--text-tertiary)">
                <i class="bi bi-qr-code"></i> Verification code
                <code><?= e(substr((string) $c['qr_token'], 0, 12)) ?>&hellip;</code>
                <a href="<?= e(url('/verify-clearance/' . $c['qr_token'])) ?>" target="_blank" rel="noopener">Verify publicly</a>
              </div>
            <?php endif; ?>
          </div>

          <div class="edu-card__foot d-flex flex-wrap gap-2 align-items-center">
            <a href="<?= e(url('/clearances/' . (int) $c['id'] . '/print')) ?>" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-printer me-1"></i> View / Download
            </a>
            <?php if ($isIssued): ?>
              <?= edu_badge('Eligible for next enrolment', 'success', 'bi-check-lg') ?>
            <?php endif; ?>
            <a href="<?= e(url('/enrollment/eligibility')) ?>" class="btn btn-sm btn-outline-success ms-auto">
              <i class="bi bi-arrow-repeat me-1"></i> Check next enrolment
            </a>
          </div>
        </article>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<?= edu_callout(
    'Digital copies of permits, clearances and report cards can be downloaded any time from your permits, clearances and subject pages.',
    ['tone' => 'info', 'icon' => 'bi-info-circle-fill', 'title' => 'Where to find your documents']
) ?>
