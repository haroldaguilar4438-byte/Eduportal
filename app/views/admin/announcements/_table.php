<?php
/**
 * Admin: announcement table partial (spec §34).
 *
 * Non-destructive: the computed status logic (draft / archived / scheduled /
 * expired / active, plus the 3-day "expiring soon" flag) is preserved exactly,
 * as are the View and Edit links, the Delete form with its action, CSRF token
 * and data-confirm text. Expired and archived rows stay visible — history is
 * preserved. Only the badge markup and empty state are restyled.
 *
 * @var array $rows
 */
$rows = $rows ?? [];
?>

<?php if ($rows === []): ?>
    <?= edu_empty_state(
        'No announcements found',
        'Create one, or adjust the filters above.',
        ['icon' => 'bi-megaphone', 'compact' => true]
    ) ?>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Title</th><th>Audience</th><th>Type</th><th>Status</th>
                    <th>Publish</th><th>Expires</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $now = time();
                $st  = (string) ($r['status'] ?? 'published');
                if ($st === 'draft') {
                    $badge = 'secondary'; $label = 'Draft';
                } elseif ($st === 'archived') {
                    $badge = 'dark'; $label = 'Archived';
                } elseif (!empty($r['publish_at']) && strtotime((string) $r['publish_at']) > $now) {
                    $badge = 'info'; $label = 'Scheduled';
                } elseif (!empty($r['expires_at']) && strtotime((string) $r['expires_at']) <= $now) {
                    $badge = 'warning'; $label = 'Expired';
                } else {
                    $badge = 'success'; $label = 'Active';
                }
                $soon = $label === 'Active'
                    && !empty($r['expires_at'])
                    && strtotime((string) $r['expires_at']) <= $now + 3 * 86400;
            ?>
                <tr>
                    <td>
                        <strong>
                            <?php if (!empty($r['is_pinned'])): ?>
                                <i class="bi bi-pin-angle-fill text-warning" title="Pinned"></i>
                            <?php endif; ?>
                            <?= e($r['title']) ?>
                        </strong>
                        <?php if ($soon): ?>
                            <?= edu_badge('expiring soon', 'warning', 'bi-alarm') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= edu_badge((string) $r['audience'], 'neutral', 'bi-people') ?></td>
                    <td class="small"><?= e($r['type'] ?? 'general') ?></td>
                    <td><?= edu_badge($label, $badge, $label === 'Active' ? 'bi-check-circle' : null) ?></td>
                    <td class="small text-muted"><?= e($r['publish_at'] ?? '') ?></td>
                    <td class="small text-muted"><?= e($r['expires_at'] ?? '—') ?></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-light border" href="<?= e(url('/admin/announcements/' . (int) $r['id'])) ?>">View</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/announcements/' . (int) $r['id'] . '/edit')) ?>">Edit</a>
                        <form method="post" action="<?= e(url('/admin/announcements/' . (int) $r['id'] . '/delete')) ?>" class="d-inline"
                              data-confirm="Delete this announcement? History is preserved for active items — only drafts/expired can be deleted.">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
