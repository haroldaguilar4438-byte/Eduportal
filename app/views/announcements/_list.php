<?php
/**
 * Announcement inbox list partial.
 *
 * Non-destructive: the unread computation (is_read cast to int, 0 = unread),
 * the pinned flag, the time_ago() date helper, the excerpt, the audience and
 * non-general type badges, the expired and unread badges, and the per-row
 * detail link are all preserved. Only the empty state uses the shared
 * component now.
 *
 * @var array $rows
 * @var int   $total
 * @var int   $page
 * @var int   $pages
 */
$rows  = $rows ?? [];
$total = $total ?? count($rows);
$page  = $page ?? 1;
$pages = $pages ?? 1;
?>

<?php if ($rows === []): ?>
    <?= edu_empty_state(
        'No announcements right now',
        'When the school office posts an update for your role it will appear here.',
        ['icon' => 'bi-megaphone', 'compact' => true]
    ) ?>
<?php else: ?>
    <p class="text-muted small"><?= (int) $total ?> announcement(s)</p>
    <div class="list-group">
        <?php foreach ($rows as $r):
            $isUnread = ((int) ($r['is_read'] ?? 1)) === 0;
            $pinned   = !empty($r['is_pinned']);
        ?>
            <a href="<?= e(url('/announcements/' . (int) $r['id'])) ?>"
               class="list-group-item list-group-item-action <?= $isUnread ? 'list-group-item-warning' : '' ?>">
                <div class="d-flex justify-content-between gap-2">
                    <strong>
                        <?php if ($pinned): ?>
                            <i class="bi bi-pin-angle-fill text-warning" title="Pinned"></i>
                        <?php endif; ?>
                        <?= e($r['title']) ?>
                    </strong>
                    <small class="text-muted text-nowrap"><?= e(time_ago($r['publish_at'] ?? $r['created_at'] ?? '')) ?></small>
                </div>
                <div class="small text-muted"><?= e($r['excerpt'] ?? '') ?></div>
                <div class="mt-1 d-flex gap-1 flex-wrap">
                    <span class="badge text-bg-light border"><?= e($r['audience'] ?? '') ?></span>
                    <?php if (!empty($r['type']) && $r['type'] !== 'general'): ?>
                        <span class="badge text-bg-info"><?= e($r['type']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($r['is_history'])): ?>
                        <span class="badge text-bg-secondary">expired</span>
                    <?php endif; ?>
                    <?php if ($isUnread): ?>
                        <span class="badge text-bg-danger">unread</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
