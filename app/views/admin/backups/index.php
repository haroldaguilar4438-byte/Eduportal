<?php
/**
 * Admin: account backups (spec §32).
 *
 * Non-destructive: both forms keep their routes (/admin/backups/{id}/restore,
 * /admin/backups/{id}/delete), CSRF tokens and their full confirm() messages.
 * The summary_json decode, the row total, the five-key slice with the "…"
 * overflow, the display_name fallback, the remove/reset badge colours and the
 * sensitivity warning are all preserved verbatim — this page exposes password
 * hashes and encrypted MFA secrets, so the warning stays prominent.
 *
 * @var array $rows
 */
$rows = $rows ?? [];

$totalRows = 0;
foreach ($rows as $b) {
    $sum = json_decode((string) ($b['summary_json'] ?? '{}'), true) ?: [];
    $totalRows += array_sum(array_map('intval', $sum));
}
?>

<?= edu_breadcrumb([
    ['label' => 'Accounts', 'url' => url('/admin/users')],
    ['label' => 'Backups'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Accounts',
    'title'       => 'Account backups',
    'description' => count($rows) . ' snapshot(s) · ' . $totalRows . ' stored row(s)',
    'icon'        => 'bi-archive',
    'actions'     => '<a href="' . e(url('/admin/users')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-arrow-left me-1"></i> All accounts</a>',
]) ?>

<?= edu_callout(
    'A snapshot is captured <strong>automatically</strong> before every admin <strong>Reset to default</strong> '
    . '(access state: password, MFA, lockout) and every <strong>Remove</strong> (access + enrollments, '
    . 'payments, grades, permits, clearances, documents &mdash; uploaded files are stashed, not deleted). '
    . 'Restoring never touches other accounts; rows that already exist or lost their parent are reported as '
    . 'skipped.',
    ['tone' => 'info', 'icon' => 'bi-info-circle', 'title' => null]
) ?>

<?= edu_callout(
    'Snapshots contain password hashes and encrypted MFA secrets &mdash; treat this page as sensitive.',
    ['tone' => 'warning', 'icon' => 'bi-shield-exclamation', 'title' => null, 'compact' => true]
) ?>

<div class="edu-card mt-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> Snapshots</h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state(
                'No backups yet',
                'They appear here automatically after a reset or remove.',
                ['icon' => 'bi-archive', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th><th>Account</th><th>Trigger</th><th>Contents</th>
                            <th>By</th><th>When</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $b):
                        $sum   = json_decode((string) ($b['summary_json'] ?? '{}'), true) ?: [];
                        $total = array_sum(array_map('intval', $sum));
                        $nonZero = array_filter($sum, static fn($n) => (int) $n > 0);
                        $isRemove = ($b['action'] ?? '') === 'remove';
                    ?>
                        <tr>
                            <td class="text-muted small"><?= (int) $b['id'] ?></td>
                            <td>
                                <div class="fw-semibold"><?= e($b['display_name'] ?: ('User #' . (int) $b['user_id'])) ?></div>
                                <div class="small text-muted">
                                    <?= e($b['email']) ?> &middot; <?= e($b['role_name']) ?> &middot; user #<?= (int) $b['user_id'] ?>
                                </div>
                            </td>
                            <td><?= edu_badge(ucfirst((string) ($b['action'] ?? '')), $isRemove ? 'danger' : 'warning', $isRemove ? 'bi-trash' : 'bi-arrow-counterclockwise') ?></td>
                            <td class="small">
                                <?= $total ?> row(s)
                                <div class="text-muted">
                                    <?= e(implode(', ', array_slice(array_keys($nonZero), 0, 5))) ?><?= count($nonZero) > 5 ? ', …' : '' ?>
                                </div>
                            </td>
                            <td class="small"><?= e($b['admin_name'] ?? ('#' . (int) ($b['created_by'] ?? 0))) ?></td>
                            <td class="small text-muted">
                                <?= e(isset($b['created_at']) ? date('M j, Y g:ia', strtotime($b['created_at'])) : '') ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <form method="post" action="<?= e(url('/admin/backups/' . (int) $b['id'] . '/restore')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-success"
                                            onclick="return confirm('Restore Backup #<?= (int) $b['id'] ?> (<?= e($b['display_name']) ?>)? Existing live rows are kept; missing rows come back. Nothing else is touched.')">
                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                    </button>
                                </form>
                                <form method="post" action="<?= e(url('/admin/backups/' . (int) $b['id'] . '/delete')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Permanently DELETE Backup #<?= (int) $b['id'] ?> including stashed files? This cannot be undone.')">
                                        <i class="bi bi-trash"></i>
                                    </button>
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
