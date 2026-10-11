<?php
/**
 * Teacher permit verification queue (spec §20).
 *
 * Non-destructive: $permits is used exactly as before. The verify form still
 * posts to /teacher/permits/{id}/verify with the same CSRF token and the same
 * confirmation prompt; the View link, the payment validation state, the
 * reference number and the requested timestamp are all preserved.
 *
 * What changed: the table became a card queue grouped so a teacher can scan
 * and act quickly, with the three-tier approval state spelled out.
 *
 * @var array $permits
 */
$permits = $permits ?? [];

/** How far through the 3-tier pipeline each queued permit already is. */
$pipeline = static function (string $status): array {
    return match ($status) {
        'pending_accounting' => ['stage' => 1, 'label' => 'Accounting',     'tone' => 'warning'],
        'approved_accounting'=> ['stage' => 2, 'label' => 'Registrar',      'tone' => 'warning'],
        'approved_registrar' => ['stage' => 3, 'label' => 'Teacher',        'tone' => 'info'],
        'fully_approved'     => ['stage' => 4, 'label' => 'Fully Approved', 'tone' => 'success'],
        'rejected'           => ['stage' => 0, 'label' => 'Rejected',       'tone' => 'danger'],
        default              => ['stage' => 1, 'label' => 'Pending',        'tone' => 'neutral'],
    };
};
?>

<?= edu_page_header([
    'eyebrow'     => 'Verification queue',
    'title'       => 'Exam permit verification',
    'description' => 'Only permits already approved by the Registrar appear here. Verifying marks the permit fully approved and generates its QR stamp.',
    'actions'     => '<a href="' . e(url('/teacher/grades')) . '" class="btn btn-light btn-sm"><i class="bi bi-journal-check me-1"></i> Grade sheets</a>',
]) ?>

<?= edu_callout(
    "Verifying students from your own subjects is preferred. Verifying another teacher's student is allowed but is recorded as an override.",
    ['tone' => 'info', 'icon' => 'bi-info-circle-fill', 'title' => 'Before you verify']
) ?>

<?php if ($permits === []): ?>
  <?= edu_empty_state(
        'No permits pending your verification',
        'When the Registrar approves a permit for one of your students it will appear here for your final sign-off.',
        [
            'icon'    => 'bi-patch-check',
            'actions' => '<a href="' . e(url('/teacher/grades')) . '" class="btn btn-light btn-sm">Go to grade sheets</a>',
        ]
    ) ?>
<?php else: ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Awaiting you', 'value' => (string) count($permits),
        'icon' => 'bi-hourglass-split', 'tone' => 'warning',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Payment validated',
        'value' => (string) count(array_filter($permits, static fn ($p) => (int) ($p['payment_validated'] ?? 0) === 1)),
        'icon' => 'bi-receipt', 'tone' => 'success',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Distinct students',
        'value' => (string) count(array_unique(array_map(static fn ($p) => (string) ($p['student_no'] ?? $p['id']), $permits))),
        'icon' => 'bi-people', 'tone' => 'info',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Blocking on payment',
        'value' => (string) count(array_filter($permits, static fn ($p) => (int) ($p['payment_validated'] ?? 0) !== 1)),
        'icon' => 'bi-exclamation-triangle-fill', 'tone' => 'danger',
        'meta'  => '<span>Cannot be verified yet</span>',
    ]) ?></div>
  </div>

  <?= edu_section_head('Approval queue', count($permits) . ' permit' . (count($permits) === 1 ? '' : 's') . ' to review', '', 'bi-list-task') ?>

  <div class="d-flex flex-column gap-3">
    <?php foreach ($permits as $p):
        $paid    = (int) ($p['payment_validated'] ?? 0) === 1;
        $fin     = (string) ($p['financial_status'] ?? 'unpaid');
        $meta    = $pipeline((string) ($p['status'] ?? ''));
        $ref     = $p['payment_ref'] ?? $p['receipt_no'] ?? null;
        $name    = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        $finBadge = match ($fin) {
            'fully_paid'       => ['success', 'Fully Paid', 'bi-check-circle-fill'],
            'installment_paid' => ['info',    'Installment Paid', 'bi-check-circle'],
            default            => ['warning', 'Unpaid', 'bi-exclamation-triangle-fill'],
        };
        $stages = [
            ['Accounting', true],
            ['Registrar',  true],
            ['Teacher',    false],
        ]; ?>
      <article class="edu-card">
        <div class="edu-card__head">
          <div class="min-w-0">
            <h2 class="edu-card__title mb-1">
              <i class="bi bi-ticket-perforated"></i>
              <?= e($p['permit_no'] ?? '') ?>
            </h2>
            <div class="small" style="color:var(--text-tertiary)">
              <?= $name !== '' ? e($name) : 'Student' ?>
              &middot; <?= e($p['student_no'] ?? '') ?>
              <?= !empty($p['section_name']) ? '&middot; ' . e($p['section_name']) : '' ?>
              &middot; <?= e($p['school_year'] ?? '') ?>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= edu_badge($p['exam_period'] ?? '', 'primary') ?>
            <?= edu_badge($meta['label'], $meta['tone'], $meta['tone'] === 'success' ? 'bi-patch-check-fill' : 'bi-hourglass-split') ?>
          </div>
        </div>

        <div class="edu-card__body">
          <div class="row g-3">
            <div class="col-12 col-lg-7">
              <!-- Three-tier pipeline: Accounting and Registrar are already done -->
              <div class="d-flex align-items-center gap-2 mb-3">
                <?php foreach ($stages as $i => [$stageName, $done]): ?>
                  <?= edu_badge(($done ? '✓ ' : '⏳ ') . $stageName, $done ? 'success' : 'warning', $done ? 'bi-check-lg' : 'bi-hourglass') ?>
                  <?php if ($i < count($stages) - 1): ?>
                    <i class="bi bi-chevron-right small" style="color:var(--border-strong)" aria-hidden="true"></i>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>

              <?php if ($paid): ?>
                <div class="small" style="color:var(--text-tertiary)">
                  <i class="bi bi-receipt me-1"></i>
                  Reference <code><?= e((string) ($ref ?? '—')) ?></code>
                  &middot; requested <?= e(edu_datetime($p['requested_at'] ?? null)) ?>
                </div>
              <?php else: ?>
                <?= edu_callout(
                    'Payment has not been validated, so this permit cannot be fully approved yet.',
                    ['tone' => 'danger', 'icon' => 'bi-exclamation-octagon-fill', 'title' => 'Blocked on payment']
                ) ?>
              <?php endif; ?>
            </div>

            <div class="col-12 col-lg-5">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-semibold" style="color:var(--text-tertiary)">Payment</span>
                <?= edu_badge($finBadge[1], $finBadge[0], $finBadge[2]) ?>
              </div>

              <div class="d-grid gap-2">
                <form method="post" action="<?= e(url('/teacher/permits/' . (int) $p['id'] . '/verify')) ?>">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-success btn-sm w-100"
                          onclick="return confirm('Mark <?= e($p['permit_no']) ?> as Fully Approved?')"
                          <?= $paid ? '' : 'disabled title="Payment must be validated first"' ?>>
                    <i class="bi bi-patch-check-fill me-1"></i> Approve
                  </button>
                </form>
                <a href="<?= e(url('/teacher/permits/' . (int) $p['id'])) ?>" class="btn btn-outline-secondary btn-sm w-100">
                  <i class="bi bi-eye me-1"></i> View details
                </a>
              </div>
            </div>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

<?php endif; ?>
