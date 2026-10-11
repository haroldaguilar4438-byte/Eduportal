<?php
/**
 * PayRex payment queue (spec §31).
 *
 * Non-destructive: the filter pills keep their hrefs, both row actions keep
 * their routes (/admin/payments/{id}/approve, /reject, /resend-receipt), CSRF
 * tokens, the confirm() guard, and every data-* attribute the shared reject
 * modal reads (data-bs-target, data-form-action, data-record, data-title).
 * The status match() map, PAYREX_MODE badge logic, the reject modal include
 * and the $rows / $status / $counts contract are preserved verbatim.
 *
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 */
$counts = $counts ?? [];
$filters = ['processing' => 'Awaiting Approval', 'paid' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed/Cancelled', 'all' => 'All'];

$awaitingCount = (int) ($counts['processing'] ?? 0);
?>

<?= edu_page_header([
    'eyebrow'     => 'Payments',
    'title'       => 'PayRex queue',
    'description' => $awaitingCount > 0
        ? $awaitingCount . ' payment(s) awaiting your approval.'
        : 'No payments are waiting for approval.',
    'icon'        => 'bi-credit-card',
    'actions'     => '<span class="edu-badge edu-badge--neutral">PK ' . e(substr(PAYREX_PUBLIC_KEY, 0, 12)) . '…</span>'
                   . edu_badge(PAYREX_MODE, PAYREX_MODE === 'live' ? 'danger' : 'warning', 'bi-broadcast'),
]) ?>

<?= edu_callout(
    'Student pays via PayRex (GCash / Maya / Card / QRPh) &rarr; status <code>processing</code> '
    . '(awaiting approval) &rarr; Admin approves here &rarr; status <code>paid</code> + '
    . '<strong>receipt emailed</strong> + enrollment becomes <code>approved</code>. '
    . 'Fake or failed payments can be rejected.',
    ['tone' => 'info', 'icon' => 'bi-diagram-3', 'title' => null]
) ?>

<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($filters as $key => $label):
        $active = $status === $key;
        $cnt = $counts[$key] ?? ($key === 'all' ? array_sum($counts) : 0);
    ?>
        <a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>" href="<?= e(url('/admin/payments?status=' . $key)) ?>">
            <?= e($label) ?>
            <span class="badge text-bg-<?= $active ? 'light' : 'secondary' ?> ms-1"><?= (int) $cnt ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> <?= e($filters[$status] ?? 'All') ?></h2>
        <?= edu_badge((string) count($rows), 'neutral', 'bi-receipt') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if (!$rows): ?>
            <?= edu_empty_state(
                'No payments in this filter',
                'Payments appear here as soon as a student completes PayRex checkout.',
                ['icon' => 'bi-receipt', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Ref</th><th>Student / Email</th><th>App No</th><th>Amount</th>
                            <th>Method</th><th>Status</th><th>Intent</th><th>Created</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="font-monospace small fw-semibold"><?= e($r['transaction_ref']) ?></td>
                            <td>
                                <div class="small fw-semibold"><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '—') ?></div>
                                <div class="small text-muted"><?= e($r['email'] ?? '') ?></div>
                                <div class="small text-muted"><?= e($r['student_no'] ?? '') ?></div>
                            </td>
                            <td class="small"><?= e($r['application_no'] ?? '—') ?></td>
                            <td class="fw-semibold"><?= money($r['amount']) ?></td>
                            <td>
                                <span class="badge text-bg-light border"><?= e($r['payment_method']) ?></span>
                                <div class="small text-muted"><?= e($r['channel']) ?></div>
                            </td>
                            <td><?= edu_status_badge((string) $r['status']) ?></td>
                            <td class="small font-monospace"><?= e(substr($r['payrex_payment_intent_id'] ?? '—', 0, 18)) ?></td>
                            <td class="small text-muted"><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
                            <td class="text-end">
                                <?php if ($r['status'] === 'processing' || $r['status'] === 'pending'): ?>
                                    <form method="post" action="<?= e(url('/admin/payments/' . $r['id'] . '/approve')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-success" onclick="return confirm('Approve <?= e($r['transaction_ref']) ?> and send receipt?')">
                                            <i class="bi bi-check-circle"></i> Approve &amp; send receipt
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                            data-form-action="<?= e(url('/admin/payments/' . $r['id'] . '/reject')) ?>"
                                            data-record="<?= e($r['transaction_ref'] . ' — ' . trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?>"
                                            data-title="Reject Payment">Reject</button>
                                <?php elseif ($r['status'] === 'paid'): ?>
                                    <form method="post" action="<?= e(url('/admin/payments/' . $r['id'] . '/resend-receipt')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-envelope"></i> Resend receipt</button>
                                    </form>
                                <?php else: ?>
                                    <span class="small text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="p-3 small text-muted" style="border-top:1px solid var(--border)">
            <i class="bi bi-shield-check"></i> Webhook: <code><?= e(url('/api/payrex/webhook.php')) ?></code> &mdash;
            register in PayRex Dashboard &rarr; Developers &rarr; Webhooks
            (events: <code>payment_intent.succeeded/failed</code>).
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../shared/partials/reject_modal.php'; ?>
