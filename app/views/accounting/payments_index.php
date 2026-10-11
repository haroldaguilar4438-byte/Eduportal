<?php
/**
 * Accounting: payment records (spec §32).
 *
 * Non-destructive: the five filter pills keep their hrefs and counts, the
 * paid/failed/other status tone logic is unchanged, the approve form keeps its
 * route (/accounting/payments/{id}/approve), CSRF token and confirm() guard,
 * and the Reject button keeps every data-* attribute the shared modal reads
 * (target, form-action, record, title). The reject_modal include stays.
 *
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 */
$rows   = $rows ?? [];
$status = $status ?? 'pending';
$counts = $counts ?? [];

$filters = [
    'processing' => 'Processing',
    'pending'    => 'Pending',
    'paid'       => 'Paid',
    'failed'     => 'Failed',
    'all'        => 'All',
];

$toneFor = static fn($r) => $r['status'] === 'paid' ? 'success'
    : ($r['status'] === 'failed' ? 'danger' : 'warning');
?>

<?= edu_page_header([
    'eyebrow'     => 'Accounting',
    'title'       => 'Payment records',
    'description' => 'PayRex and counter payments awaiting finance action.',
    'icon'        => 'bi-credit-card',
    'actions'     => edu_badge('PayRex + counter', 'neutral', 'bi-collection'),
]) ?>

<!-- Status filters -->
<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($filters as $k => $l): ?>
        <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= e(url('/accounting/payments?status=' . $k)) ?>">
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
                'No payments',
                'Nothing matches this filter right now.',
                ['icon' => 'bi-receipt', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead><tr><th>Reference</th><th>Student</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $studentName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    ?>
                        <tr>
                            <td>
                                <span class="fw-semibold" style="font-family:var(--font-mono);font-size:.82rem"><?= e($r['transaction_ref']) ?></span>
                                <div class="text-muted"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></div>
                            </td>
                            <td>
                                <strong><?= e($studentName) ?></strong>
                                <div class="text-muted"><?= e($r['student_no'] ?? '') ?></div>
                            </td>
                            <td class="text-end fw-semibold"><?= money($r['amount']) ?></td>
                            <td><?= edu_badge((string) $r['status'], $toneFor($r)) ?></td>
                            <td class="text-end">
                                <?php if (in_array($r['status'], ['pending', 'processing'], true)): ?>
                                    <form method="post" action="<?= e(url('/accounting/payments/' . $r['id'] . '/approve')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-success" onclick="return confirm('Approve payment?')">Approve</button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                            data-form-action="<?= e(url('/accounting/payments/' . $r['id'] . '/reject')) ?>"
                                            data-record="<?= e($r['transaction_ref'] . ' — ' . $studentName) ?>"
                                            data-title="Reject Payment">Reject</button>
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
