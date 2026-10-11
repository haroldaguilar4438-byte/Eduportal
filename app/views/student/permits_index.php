<?php
/**
 * Exam Permits — stage tracker (spec §13).
 *
 * Non-destructive: $permits, $isParent, $linkedChildren and $actingChild are
 * used exactly as before, and the parent request form, the print link, the
 * "request again" link and the rejection reason are all preserved. The table
 * became a four-stage tracker (Prelim → Midterm → Prefinal → Finals) so a
 * student can see at a glance how far through the year they are, plus a
 * per-permit approval timeline.
 *
 * @var array $permits
 * @var bool  $isParent
 * @var array $linkedChildren
 * @var array|null $actingChild
 */
$permits = $permits ?? [];
$periodsOrder = ['Prelim', 'Midterm', 'Prefinal', 'Finals'];
$isParent = $isParent ?? false;
$linkedChildren = $linkedChildren ?? [];
$actingChild = $actingChild ?? null;

/** Index permits by exam period for the stage tracker. */
$byPeriod = [];
foreach ($permits as $p) {
    $byPeriod[(string) ($p['exam_period'] ?? '')] = $p;
}

/** Which approval gates a status has cleared. */
$permitStages = static function (string $status): array {
    $order = ['pending_accounting', 'approved_accounting', 'approved_registrar', 'fully_approved'];
    $pos   = array_search($status, $order, true);
    $done  = $pos === false ? 0 : (int) $pos;
    return [
        'accounting' => $done >= 1,
        'registrar'  => $done >= 2,
        'teacher'    => $done >= 3,
    ];
};

$permitStatusMeta = static function (string $status): array {
    return match ($status) {
        'pending_accounting' => ['badge' => 'warning', 'label' => 'Pending Accounting', 'icon' => 'bi-clock-fill'],
        'approved_accounting'=> ['badge' => 'info',    'label' => 'Approved by Accounting', 'icon' => 'bi-check-circle-fill'],
        'approved_registrar' => ['badge' => 'warning', 'label' => 'Approved by Registrar', 'icon' => 'bi-clock-fill'],
        'fully_approved'     => ['badge' => 'success', 'label' => 'Fully Approved', 'icon' => 'bi-patch-check-fill'],
        'rejected'           => ['badge' => 'danger',  'label' => 'Rejected', 'icon' => 'bi-x-circle-fill'],
        default              => ['badge' => 'neutral', 'label' => $status === '' ? 'Unknown' : ucwords(str_replace('_', ' ', $status)), 'icon' => 'bi-dash-circle-fill'],
    };
};

$finMeta = static function (?string $fin): array {
    return match ($fin) {
        'fully_paid'      => ['badge' => 'success', 'label' => 'Fully Paid'],
        'installment_paid'=> ['badge' => 'info',    'label' => 'Installment Paid'],
        default           => ['badge' => 'warning', 'label' => 'Payment Pending'],
    };
};
?>

<?= edu_page_header([
    'eyebrow'     => 'Assessment access',
    'title'       => 'My exam permits',
    'description' => 'Each permit is cleared by Accounting, the Registrar and your teacher. Payment must be validated first.',
    'actions'     => '<a href="' . e(url('/dashboard')) . '" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to dashboard</a>',
]) ?>

<?php if ($isParent && !empty($linkedChildren)): ?>
  <?= edu_section_head('Viewing on behalf of', 'Switch between your linked children', '', 'bi-people') ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($linkedChildren as $lc):
        $lcActive = $actingChild && (int) $lc['id'] === (int) $actingChild['id'];
        $lcName = trim(implode(' ', array_filter([trim((string) ($lc['first_name'] ?? '')), trim((string) ($lc['middle_name'] ?? '')), trim((string) ($lc['last_name'] ?? '')), trim((string) ($lc['suffix'] ?? ''))])));
        if ($lcName === '') $lcName = 'Student #' . $lc['id']; ?>
      <a href="<?= e(url('/student/permits?child_id=' . (int) $lc['id'])) ?>"
         class="btn btn-sm <?= $lcActive ? 'btn-primary' : 'btn-outline-secondary' ?>"
         <?= $lcActive ? 'aria-current="true"' : '' ?>><?= e($lcName) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($actingChild): ?>
    <div class="edu-card mb-4">
      <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-send"></i> Request a permit</h2>
      </div>
      <div class="edu-card__body">
        <form method="post" action="<?= e(url('/student/permits/request')) ?>" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="child_id" value="<?= (int) $actingChild['id'] ?>">
          <div class="col-12 col-md-6">
            <label class="form-label" for="permitPeriodParent">Exam period for <?= e($actingChild['name']) ?></label>
            <select name="exam_period" id="permitPeriodParent" class="form-select" required>
              <option value="">— Select period —</option>
              <?php foreach ($periodsOrder as $pp): ?><option><?= e($pp) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <button class="btn btn-primary w-100"><i class="bi bi-send me-1"></i> Request permit</button>
            <div class="form-text">Sequential (Prelim first) and payment-validated, same as student accounts.</div>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($permits === []): ?>
  <?= edu_empty_state(
        'No permits requested yet',
        'Request a permit from your dashboard for each exam period, in order. Payment is validated first, then Accounting, the Registrar and your teacher sign off.',
        [
            'icon'    => 'bi-card-checklist',
            'actions' => '<a href="' . e(url('/dashboard')) . '" class="btn btn-primary btn-sm">Go to dashboard</a>',
        ]
    ) ?>
<?php else: ?>

  <!-- Year tracker: where the student is across the four exam periods -->
  <?= edu_section_head('Permit year at a glance', 'Each stage unlocks the next once fully approved', '', 'bi-diagram-3') ?>
  <div class="edu-card mb-4">
    <div class="edu-card__body">
      <div class="edu-track">
        <?php foreach ($periodsOrder as $i => $period):
            $p       = $byPeriod[$period] ?? null;
            $status  = (string) ($p['status'] ?? '');
            $meta    = $permitStatusMeta($status);
            $isReady = $status === 'fully_approved';
            $isRej   = $status === 'rejected';
            $hasIt   = $p !== null;
            $state   = $isRej ? 'is-failed' : ($isReady ? 'is-done' : ($hasIt ? 'is-active' : ''));
            $icon    = $isRej ? 'bi-x-lg' : ($isReady ? 'bi-check-lg' : ($hasIt ? 'bi-hourglass' : (string) ($i + 1))); ?>
          <div class="edu-track__node <?= $state ?>">
            <div class="edu-track__dot"><?= $isRej || $isReady || $hasIt ? '<i class="bi ' . $meta['icon'] . '"></i>' : e((string) ($i + 1)) ?></div>
            <div class="edu-track__label"><?= e($period) ?></div>
            <div class="edu-track__meta">
              <?= $hasIt ? e($meta['label']) : 'Not requested' ?>
            </div>
          </div>
          <?php if ($i < count($periodsOrder) - 1): ?>
            <div class="edu-track__bar<?= $isReady ? ' is-done' : '' ?>" aria-hidden="true"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Per-permit detail with approval timeline -->
  <?= edu_section_head('Your permits', count($permits) . ' permit' . (count($permits) === 1 ? '' : 's') . ' on record', '', 'bi-patch-check') ?>
  <div class="d-flex flex-column gap-3">
    <?php foreach ($permits as $p):
        $status = (string) ($p['status'] ?? '');
        $meta   = $permitStatusMeta($status);
        $stg    = $permitStages($status);
        $fin    = $finMeta($p['financial_status'] ?? 'unpaid');
        $paid   = (int) ($p['payment_validated'] ?? 0) === 1;
        $ref    = $p['payment_ref'] ?? $p['receipt_no'] ?? null; ?>

      <article class="edu-card">
        <div class="edu-card__head">
          <div class="d-flex align-items-center gap-2 flex-wrap min-w-0">
            <h2 class="edu-card__title mb-0"><i class="bi bi-ticket-perforated"></i> <?= e($p['permit_no'] ?? '') ?></h2>
            <?= edu_badge($p['exam_period'] ?? '', 'primary') ?>
            <?= edu_badge($meta['label'], $meta['badge'], $meta['icon']) ?>
          </div>
          <div class="edu-card__body" style="padding:0;border:0"></div>
        </div>

        <div class="edu-card__body">
          <div class="row g-3">
            <div class="col-12 col-lg-7">
              <?= edu_section_head('Approval pipeline', 'Every sign-off is recorded', '', 'bi-diagram-2') ?>
              <?= edu_timeline([
                  [
                      'title' => 'Payment validated',
                      'meta'  => $paid ? ($ref !== null && $ref !== '' ? 'Reference ' . e((string) $ref) : 'Reference on file') : 'Awaiting payment validation',
                      'body'  => $paid ? '<span>' . e($fin['label']) . '</span>' : '',
                      'state' => $paid ? 'complete' : 'current',
                  ],
                  [
                      'title' => 'Accounting',
                      'meta'  => $stg['accounting'] ? 'Verified' : 'Awaiting review',
                      'state' => $stg['accounting'] ? 'complete' : ($paid ? 'current' : ''),
                  ],
                  [
                      'title' => 'Registrar',
                      'meta'  => $stg['registrar'] ? 'Verified' : 'Awaiting review',
                      'state' => $stg['registrar'] ? 'complete' : ($stg['accounting'] ? 'current' : ''),
                  ],
                  [
                      'title' => 'Teacher',
                      'meta'  => $stg['teacher'] ? 'Verified' : 'Awaiting review',
                      'state' => $stg['teacher'] ? 'complete' : ($stg['registrar'] ? 'current' : ''),
                  ],
              ]) ?>

              <?php if ($status === 'rejected' && !empty($p['rejected_reason'])): ?>
                <?= edu_callout('<strong>Reason:</strong> ' . e((string) $p['rejected_reason']), ['tone' => 'danger', 'icon' => 'bi-x-octagon-fill']) ?>
              <?php endif; ?>

              <?php if ($status === 'fully_approved'): ?>
                <?= edu_callout('This permit is fully approved. Download a printable copy for the entrance.', ['tone' => 'success', 'icon' => 'bi-patch-check-fill', 'title' => 'Permit ready']) ?>
              <?php endif; ?>
            </div>

            <div class="col-12 col-lg-5">
              <?= edu_section_head('Details', '', '', 'bi-info-circle') ?>
              <?= edu_dl([
                  'School year'  => e($p['school_year'] ?? '—'),
                  'Exam period'  => e($p['exam_period'] ?? '—'),
                  'Payment'      => $paid
                      ? edu_badge($fin['label'], $fin['badge'], $paid ? 'bi-check-circle-fill' : null)
                      : edu_badge('Not validated', 'warning', 'bi-exclamation-triangle-fill'),
                  'Reference'    => e((string) ($ref ?? '—')),
              ]) ?>

              <?php if ($status === 'fully_approved' && !empty($p['qr_token'])): ?>
                <div class="mt-3">
                  <?= edu_badge('QR ' . e(substr((string) $p['qr_token'], 0, 10)) . '…', 'success', 'bi-qr-code') ?>
                </div>
              <?php endif; ?>

              <div class="d-grid gap-2 mt-3">
                <a href="<?= e(url('/student/permits/' . $p['id'])) ?>" class="btn btn-outline-primary btn-sm">
                  <i class="bi bi-eye me-1"></i> View full permit
                </a>
                <?php if ($status === 'fully_approved'): ?>
                  <a href="<?= e(url('/student/permits/' . $p['id'] . '/print')) ?>" target="_blank" rel="noopener" class="btn btn-success btn-sm">
                    <i class="bi bi-printer me-1"></i> Download permit
                  </a>
                <?php elseif ($status === 'rejected'): ?>
                  <a href="<?= e(url('/student/permits/' . $p['id'])) ?>" class="btn btn-warning btn-sm">
                    <i class="bi bi-arrow-repeat me-1"></i> Request again
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

<?php endif; ?>
