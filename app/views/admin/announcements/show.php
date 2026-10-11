<?php
/**
 * Admin: announcement detail + lifecycle timeline (spec §34).
 *
 * Non-destructive: the Edit link keeps its route, the $badge match() map, the
 * $fmt date helper, the stored-status explainer, the raw $row['content'] output
 * (unchanged, so stored HTML renders exactly as before), every Details row, the
 * ANNOUNCEMENT_ prefix stripping in the timeline, the new_values_json excerpt
 * and the $row / $timeline / $computed contract are all preserved. This page is
 * read-only, as before.
 *
 * @var array       $row
 * @var array       $timeline
 * @var string      $computed
 */
$row      = $row ?? [];
$timeline = $timeline ?? [];
$computed = $computed ?? 'active';

$badge = match ($computed) {
    'active'    => 'success',
    'scheduled' => 'info',
    'expired'   => 'warning',
    'archived'  => 'dark',
    'draft'     => 'secondary',
    default     => 'secondary',
};

$fmt = static function (?string $dt): string {
    if ($dt === null || trim($dt) === '') {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts === false ? e($dt) : e(date('F j, Y g:i A', $ts));
};

$author = trim((string) ($row['author_name'] ?? '')) !== ''
    ? (string) $row['author_name']
    : ('#' . (int) ($row['posted_by'] ?? 0));

$detailRows = [
    ['label' => 'Category', 'value' => e($row['type'] ?? 'general')],
    ['label' => 'Audience', 'value' => e($row['audience'] ?? '')],
    ['label' => 'Created by', 'value' => e($author)],
    ['label' => 'Created at', 'value' => $fmt($row['created_at'] ?? null)],
    ['label' => 'Starts', 'value' => $fmt($row['publish_at'] ?? null)],
    ['label' => 'Expires', 'value' => $fmt($row['expires_at'] ?? null)],
    ['label' => 'Last updated', 'value' => $fmt($row['updated_at'] ?? null)],
];
?>

<?= edu_breadcrumb([
    ['label' => 'Announcements', 'url' => url('/admin/announcements')],
    ['label' => (string) ($row['title'] ?? 'Detail')],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Content',
    'title'       => (string) ($row['title'] ?? 'Announcement'),
    'description' => 'Stored status: ' . e($row['status'] ?? 'published') . ' — visibility is decided by dates, not by this label alone.',
    'icon'        => 'bi-megaphone',
    'actions'     => edu_badge(ucfirst($computed), $badge, 'bi-broadcast')
        . (!empty($row['is_pinned']) ? ' ' . edu_badge('Pinned', 'accent', 'bi-pin-angle') : '')
        . '<a href="' . e(url('/admin/announcements/' . (int) ($row['id'] ?? 0) . '/edit')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-pencil me-1"></i> Edit</a>',
]) ?>

<div class="row g-3">
    <!-- Content -->
    <div class="col-12 col-lg-7">
        <div class="edu-card">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-file-text"></i> Content</h2>
            </div>
            <div class="edu-card__body">
                <?= $row['content'] ?? '' ?>
            </div>
        </div>
    </div>

    <!-- Details + timeline -->
    <div class="col-12 col-lg-5">
        <div class="edu-card mb-3">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-info-circle"></i> Details</h2>
            </div>
            <div class="edu-card__body">
                <?= edu_dl($detailRows) ?>
            </div>
        </div>

        <div class="edu-card">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-clock-history"></i> Timeline</h2>
                <?= edu_badge((string) count($timeline), 'neutral') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($timeline === []): ?>
                    <?= edu_empty_state(
                        'No audit events yet',
                        'New publishes, schedules, edits, and archives will appear here.',
                        ['icon' => 'bi-clock-history', 'compact' => true]
                    ) ?>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($timeline as $t): ?>
                        <li class="list-group-item px-0">
                            <strong><?= e(str_replace('ANNOUNCEMENT_', '', (string) ($t['action'] ?? ''))) ?></strong><br>
                            <span class="small text-muted">
                                <?= $fmt($t['created_at'] ?? null) ?>
                                <?php if (!empty($t['new_values_json'])): ?>
                                    &middot; <?= e(mb_substr((string) $t['new_values_json'], 0, 160)) ?>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
