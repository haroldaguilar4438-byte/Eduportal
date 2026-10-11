<?php
/**
 * Accounting: financial clearance (spec §32).
 *
 * Non-destructive: the six filter pills keep their hrefs and counts, the
 * cleared/not_cleared/other badge logic and the ₱0-balance rule callout are
 * unchanged, every per-row form keeps its route
 * (/accounting/clearances/{id}/status), CSRF token, `remarks` and
 * `financial_status` field names with the same five options and the current
 * selection, and the client-side guard is preserved — a reason of at least 5
 * characters is required for Not cleared / On hold, then a confirm dialog.
 * The history list and its columns are unchanged.
 *
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 * @var array  $history
 */
$rows    = $rows ?? [];
$status  = $status ?? 'pending';
$counts  = $counts ?? [];
$history = $history ?? [];

$filters = [
    'pending'     => 'Pending',
    'for_review'  => 'For review',
    'cleared'     => 'Cleared',
    'not_cleared' => 'Not cleared',
    'on_hold'     => 'On hold',
    'all'         => 'All',
];

$finTone = static fn($r) => ($r['financial_status'] ?? '') === 'cleared' ? 'success'
    : ((($r['financial_status'] ?? '') === 'not_cleared') ? 'danger' : 'warning');
?>

<?= edu_page_header([
    'eyebrow'     => 'Accounting',
    'title'       => 'Financial clearance',
    'description' => 'A student is cleared only at a ₱0 balance.',
    'icon'        => 'bi-shield-check',
    'actions'     => edu_badge('Cleared only at ₱0 balance', 'info', 'bi-info-circle'),
]) ?>

<!-- Status filters -->
<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($filters as $k => $l): ?>
        <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= e(url('/accounting/clearances?status=' . $k)) ?>">
            <?= e($l) ?>
            <span class="badge text-bg-<?= $status === $k ? 'light' : 'secondary' ?> ms-1"><?= (int) ($counts[$k] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="edu-card mb-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> <?= e($filters[$status] ?? 'All') ?></h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state(
                'No clearances',
                'Nothing matches this filter right now.',
                ['icon' => 'bi-shield-check', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead><tr><th>#</th><th>Student</th><th>Balance</th><th>Financial</th><th class="text-end">Update</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int) $r['id'] ?></td>
                            <td>
                                <strong><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></strong>
                                <div class="text-muted"><?= e($r['student_no'] ?? '') ?> · <?= e($r['semester']) ?> <?= e($r['school_year']) ?></div>
                            </td>
                            <td class="fw-semibold"><?= money($r['balance'] ?? 0) ?></td>
                            <td>
                                <?= edu_badge(str_replace('_', ' ', (string) ($r['financial_status'] ?? 'pending')), $finTone($r)) ?>
                                <div class="small text-muted">Acad: <?= e($r['status']) ?></div>
                            </td>
                            <td class="text-end">
                                <form method="post" action="<?= e(url('/accounting/clearances/' . $r['id'] . '/status')) ?>"
                                      class="d-flex gap-1 justify-content-end clearance-form">
                                    <?= csrf_field() ?>
                                    <input name="remarks" class="form-control form-control-sm" placeholder="Remarks (required if Not cleared/On hold)" style="max-width:140px" aria-label="Remarks">
                                    <select name="financial_status" class="form-select form-select-sm" style="max-width:130px" aria-label="Financial status">
                                        <?php foreach (['pending', 'for_review', 'cleared', 'not_cleared', 'on_hold'] as $s): ?>
                                            <option value="<?= $s ?>" <?= ($r['financial_status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-sm btn-primary flex-none">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- History -->
<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-clock-history"></i> Recent financial clearance history</h2>
        <?= edu_badge((string) count($history), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($history === []): ?>
            <?= edu_empty_state('No history yet', 'Status changes will be recorded here.', ['icon' => 'bi-clock-history', 'compact' => true]) ?>
        <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($history as $h): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <span>
                        #<?= (int) $h['clearance_id'] ?>
                        <?= e($h['from_status'] ?? '—') ?> → <strong><?= e($h['to_status']) ?></strong>
                        by <?= e($h['actor'] ?? 'system') ?><?= $h['remarks'] ? ' — ' . e($h['remarks']) : '' ?>
                    </span>
                    <span class="small text-muted flex-none"><?= e(date('M j, g:ia', strtotime($h['created_at']))) ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
/* Remarks (min 5 chars) are required for Not cleared / On hold, then confirm. */
document.querySelectorAll('.clearance-form').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
        var s = f.querySelector('select[name="financial_status"]').value;
        var r = f.querySelector('input[name="remarks"]').value.trim();
        if ((s === 'not_cleared' || s === 'on_hold') && r.length < 5) {
            ev.preventDefault();
            alert('A reason is required when marking Not cleared / On hold (min 5 characters).');
            f.querySelector('input[name="remarks"]').focus();
            return;
        }
        if (!confirm('Update financial clearance?')) {
            ev.preventDefault();
        }
    });
});
</script>
