<?php
/**
 * Admin: exam-permit approvals (spec §29).
 *
 * Non-destructive: the six filter pills keep their hrefs and counts, the
 * 3-tier pipeline semantics are unchanged — Stage 1 Accounting verifies
 * payment, Stage 2 Registrar verifies academic clearance, Stage 3 Teacher
 * verifies to Fully Approved. The financial-status and pipeline match() maps,
 * the payment_validated branch (ref + balance_at_request via money()), every
 * per-stage form route with CSRF and confirm() guards, the rejected_reason
 * display, the print Card link, the ever-present QR Verify link and the
 * shared reject modal with all its data-* attributes are preserved verbatim.
 *
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 */
$status = $status ?? 'pending_accounting';
$counts = $counts ?? [];
$rows   = $rows ?? [];

$filters = [
    'pending_accounting'  => 'Pending Accounting (Stage 1)',
    'approved_accounting' => 'Accounting ✓ → Registrar',
    'approved_registrar'  => 'Registrar ✓ → Teacher',
    'fully_approved'      => 'Fully Approved',
    'rejected'            => 'Rejected',
    'all'                 => 'All',
];

$finTone  = static fn($r) => match ($r['financial_status'] ?? 'unpaid') {
    'fully_paid'       => 'success',
    'installment_paid' => 'info',
    default            => 'warning',
};
$finLabel = static fn($r) => match ($r['financial_status'] ?? 'unpaid') {
    'fully_paid'       => 'Fully Paid',
    'installment_paid' => 'Installment Paid',
    default            => 'Unpaid',
};
$pipeLabel = static fn($r) => match ($r['status']) {
    'pending_accounting'  => 'Stage 1: Accounting',
    'approved_accounting' => 'Stage 2: Registrar',
    'approved_registrar'  => 'Stage 3: Teacher',
    'fully_approved'      => 'Fully Approved',
    'rejected'            => 'Rejected',
    default               => $r['status'],
};
$stageBadge = static function ($r): string {
    return match ($r['status']) {
        'pending_accounting'  => edu_badge('Pending Accounting', 'warning', 'bi-hourglass-split'),
        'approved_accounting' => edu_badge('✓ Accounting → Registrar', 'info', 'bi-arrow-right'),
        'approved_registrar'  => edu_badge('✓ Registrar → Teacher', 'warning', 'bi-arrow-right'),
        'fully_approved'      => edu_badge('Fully Approved', 'success', 'bi-patch-check-fill'),
        default               => edu_badge((string) $r['status'], 'neutral'),
    };
};

$awaitingCount = (int) ($counts['pending_accounting'] ?? 0);
?>

<?= edu_page_header([
    'eyebrow'     => 'Permits',
    'title'       => 'Exam permit approvals',
    'description' => $awaitingCount > 0
        ? $awaitingCount . ' permit(s) waiting at Stage 1 (Accounting).'
        : 'Sequential 3-tier pipeline: Accounting → Registrar → Teacher.',
    'icon'        => 'bi-patch-check',
    'actions'     => edu_badge(number_format($counts[$status] ?? count($rows)) . ' shown', 'neutral', 'bi-list-ul'),
]) ?>

<!-- Stage filters -->
<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($filters as $key => $label):
        $active = $status === $key; ?>
        <a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>" href="<?= e(url('/admin/permits?status=' . $key)) ?>">
            <?= e($label) ?>
            <span class="badge text-bg-<?= $active ? 'light' : 'secondary' ?> ms-1"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-diagram-3"></i> <?= e($filters[$status] ?? 'All') ?></h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state(
                'No permits in this filter',
                'Permit requests appear here as students submit them.',
                ['icon' => 'bi-patch-check', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>Permit no.</th><th>Student</th><th>Period</th><th>Payment</th>
                            <th>Status / pipeline</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $studentName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    ?>
                        <tr>
                            <td>
                                <span class="fw-semibold"><?= e($r['permit_no']) ?></span>
                                <div class="small text-muted"><?= e($r['exam_period']) ?> · <?= e($r['school_year']) ?></div>
                            </td>
                            <td>
                                <strong><?= e($studentName) ?></strong>
                                <div class="small text-muted"><?= e($r['student_no'] ?? '') ?> · <?= e($r['section_name'] ?? '') ?></div>
                                <div class="small text-muted"><?= e($r['email'] ?? '') ?></div>
                            </td>
                            <td><span class="badge text-bg-dark"><?= e($r['exam_period']) ?></span></td>
                            <td>
                                <?php if ((int) ($r['payment_validated'] ?? 0)): ?>
                                    <?= edu_badge($finLabel($r), $finTone($r), 'bi-check-circle') ?>
                                    <div class="small text-success mt-1">
                                        <i class="bi bi-receipt"></i> Payment Validated<br>
                                        <?= e($r['payment_ref'] ?? $r['receipt_no'] ?? '—') ?>
                                    </div>
                                    <div class="small text-muted">Bal: <?= money($r['balance_at_request'] ?? 0) ?></div>
                                <?php else: ?>
                                    <?= edu_badge('Not validated', 'danger', 'bi-exclamation-circle') ?>
                                    <div class="small text-muted">Bal: <?= money($r['balance_at_request'] ?? 0) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $stageBadge($r) ?>
                                <div class="small text-muted"><?= e(date('M j, Y', strtotime($r['requested_at']))) ?></div>
                            </td>
                            <td class="text-end">
                                <?php if ($r['status'] === 'pending_accounting'): ?>
                                    <form method="post" action="<?= e(url('/admin/permits/' . $r['id'] . '/approve-accounting')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Approve by Accounting?')">
                                            <i class="bi bi-check-lg"></i> Accounting Approve
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                            data-form-action="<?= e(url('/admin/permits/' . $r['id'] . '/reject')) ?>"
                                            data-record="<?= e($r['permit_no'] . ' — ' . $studentName) ?>"
                                            data-title="Reject Permit">Reject</button>

                                <?php elseif ($r['status'] === 'approved_accounting'): ?>
                                    <form method="post" action="<?= e(url('/admin/permits/' . $r['id'] . '/approve-registrar')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Approve by Registrar?')">
                                            <i class="bi bi-check-lg"></i> Registrar Approve
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                            data-form-action="<?= e(url('/admin/permits/' . $r['id'] . '/reject')) ?>"
                                            data-record="<?= e($r['permit_no'] . ' — ' . $studentName) ?>"
                                            data-title="Reject Permit">Reject</button>

                                <?php elseif ($r['status'] === 'approved_registrar'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                            data-form-action="<?= e(url('/admin/permits/' . $r['id'] . '/reject')) ?>"
                                            data-record="<?= e($r['permit_no'] . ' — ' . $studentName) ?>"
                                            data-title="Reject Permit">Reject</button>

                                <?php elseif ($r['status'] === 'fully_approved'): ?>
                                    <a href="<?= e(url('/student/permits/' . $r['id'] . '/print')) ?>" target="_blank" class="btn btn-sm btn-success">
                                        <i class="bi bi-printer"></i> Card
                                    </a>

                                <?php elseif ($r['status'] === 'rejected' && !empty($r['rejected_reason'])): ?>
                                    <div class="small text-danger text-start">
                                        <i class="bi bi-exclamation-triangle"></i> <?= e($r['rejected_reason']) ?>
                                    </div>

                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>

                                <a href="<?= e(url('/verify-permit/' . $r['qr_token'])) ?>" target="_blank" rel="noopener"
                                   class="btn btn-sm btn-outline-secondary" title="Verify QR" aria-label="Verify QR">
                                    <i class="bi bi-qr-code"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="p-3 small text-muted" style="border-top:1px solid var(--border)">
            <i class="bi bi-diagram-3"></i> Sequential 3-tier: Accounting verifies payment
            (PayRex/receipt) &rarr; Registrar verifies academic clearance &rarr; Teacher verifies
            (Fully Approved).
        </div>
    </div>
</div>

<?php include __DIR__ . '/../shared/partials/reject_modal.php'; ?>
