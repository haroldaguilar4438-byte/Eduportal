<?php
/**
 * Accounting: exam-permit finance queue (spec §32).
 *
 * Non-destructive: this is the Stage 1 view of the 3-tier pipeline — only
 * pending_accounting rows get the Accounting approve form (route, CSRF,
 * confirm() guard unchanged). The six filter pills, the rejected_reason
 * display, the balance via money() plus payment_ref, and the Reject button
 * with all its data-* attributes are preserved, as is the reject_modal
 * include. Accounting cannot advance later stages from here.
 *
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 */
$rows   = $rows ?? [];
$status = $status ?? 'pending_accounting';
$counts = $counts ?? [];

$filters = [
    'pending_accounting'  => 'Pending',
    'approved_accounting' => '→ Registrar',
    'approved_registrar'  => '→ Teacher',
    'fully_approved'      => 'Approved',
    'rejected'            => 'Rejected',
    'all'                 => 'All',
];
?>

<?= edu_page_header([
    'eyebrow'     => 'Accounting',
    'title'       => 'Exam permits',
    'description' => 'Stage 1: verify payment before the request moves to the Registrar.',
    'icon'        => 'bi-patch-check',
    'actions'     => edu_badge('Stage 1: verify payment', 'info', 'bi-1-circle'),
]) ?>

<!-- Status filters -->
<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($filters as $k => $l): ?>
        <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= e(url('/accounting/permits?status=' . $k)) ?>">
            <?= e($l) ?>
            <span class="badge text-bg-<?= $status === $k ? 'light' : 'secondary' ?> ms-1"><?= (int) ($counts[$k] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> <?= e($filters[$status] ?? 'All') ?></h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state(
                'No permits',
                'Nothing matches this filter right now.',
                ['icon' => 'bi-patch-check', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead><tr><th>Permit</th><th>Student</th><th>Balance</th><th>Status</th><th class="text-end">Finance action</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $studentName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    ?>
                        <tr>
                            <td>
                                <span class="fw-semibold" style="font-family:var(--font-mono);font-size:.82rem"><?= e($r['permit_no']) ?></span>
                                <div class="text-muted"><?= e($r['exam_period']) ?></div>
                            </td>
                            <td>
                                <strong><?= e($studentName) ?></strong>
                                <div class="text-muted"><?= e($r['student_no'] ?? '') ?></div>
                            </td>
                            <td>
                                <span class="fw-semibold"><?= money($r['balance'] ?? 0) ?></span>
                                <div class="small text-muted"><?= e($r['payment_ref'] ?? '') ?></div>
                            </td>
                            <td>
                                <?= edu_status_badge((string) $r['status']) ?>
                                <?php if ($r['status'] === 'rejected' && !empty($r['rejected_reason'])): ?>
                                    <div class="small text-danger mt-1">
                                        <i class="bi bi-exclamation-triangle"></i> <?= e($r['rejected_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($r['status'] === 'pending_accounting'): ?>
                                    <form method="post" action="<?= e(url('/accounting/permits/' . $r['id'] . '/approve')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-primary" onclick="return confirm('Approve by Accounting?')">Approve</button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                            data-form-action="<?= e(url('/accounting/permits/' . $r['id'] . '/reject')) ?>"
                                            data-record="<?= e($r['permit_no'] . ' — ' . $studentName) ?>"
                                            data-title="Reject Permit">Reject</button>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../shared/partials/reject_modal.php'; ?>
