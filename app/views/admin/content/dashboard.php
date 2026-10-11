<?php
/**
 * Content Management home (spec §34).
 *
 * Non-destructive: the four KPI cards keep their values and their
 * pre-filtered "Manage" links (blog published/draft/scheduled, announcements),
 * both create links, both "All →" links, and the two recent lists keep their
 * columns (post title + status; announcement title + audience). Counts come
 * from the controller's real DB queries — nothing is invented here.
 *
 * @var array $blogCounts
 * @var array $annCounts
 * @var array $recentPosts
 * @var array $recentAnn
 */
$blogCounts  = $blogCounts ?? [];
$annCounts   = $annCounts ?? [];
$recentPosts = $recentPosts ?? [];
$recentAnn   = $recentAnn ?? [];

$kpis = [
    ['label' => 'Published posts',  'value' => $blogCounts['published'] ?? 0, 'url' => url('/admin/blog?status=published'),  'tone' => 'success', 'icon' => 'bi-newspaper'],
    ['label' => 'Drafts',           'value' => $blogCounts['draft'] ?? 0,     'url' => url('/admin/blog?status=draft'),      'tone' => 'neutral', 'icon' => 'bi-file-earmark'],
    ['label' => 'Scheduled',        'value' => $blogCounts['scheduled'] ?? 0, 'url' => url('/admin/blog?status=scheduled'),  'tone' => 'info',    'icon' => 'bi-clock'],
    ['label' => 'Active announcements', 'value' => $annCounts['active'] ?? 0, 'url' => url('/admin/announcements'),          'tone' => 'accent',  'icon' => 'bi-megaphone'],
];
?>

<?= edu_page_header([
    'eyebrow'     => 'Content',
    'title'       => 'Content management',
    'description' => 'School news posts and announcements, with live counts from the database.',
    'icon'        => 'bi-grid-1x2',
    'actions'     => '<a href="' . e(url('/admin/blog/create')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-plus-lg me-1"></i> Create post</a>'
        . '<a href="' . e(url('/admin/announcements/create')) . '" class="btn btn-primary btn-sm">'
        . '<i class="bi bi-megaphone me-1"></i> Create announcement</a>',
]) ?>

<!-- KPIs -->
<div class="row g-3 mb-3">
    <?php foreach ($kpis as $k): ?>
        <div class="col-6 col-lg-3">
            <a href="<?= e($k['url']) ?>" class="d-block h-100 edu-card edu-card--hover p-3 text-decoration-none">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="edu-stat__icon edu-stat__icon--<?= e($k['tone']) ?> flex-none" style="width:34px;height:34px;font-size:.95rem" aria-hidden="true">
                        <i class="bi <?= e($k['icon']) ?>"></i>
                    </span>
                    <span class="small" style="color:var(--text-tertiary)"><?= e($k['label']) ?></span>
                </div>
                <div class="fw-bold" style="font-size:1.6rem;line-height:1.1"><?= (int) $k['value'] ?></div>
                <div class="small" style="color:var(--primary-700)">Manage <i class="bi bi-arrow-right"></i></div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <!-- Recent posts -->
    <div class="col-12 col-lg-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-newspaper"></i> Recent posts</h2>
                <a class="small text-decoration-none" href="<?= e(url('/admin/blog')) ?>">All &rarr;</a>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($recentPosts === []): ?>
                    <?= edu_empty_state('No posts yet', 'Published and draft articles will appear here.', ['icon' => 'bi-newspaper', 'compact' => true]) ?>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($recentPosts as $p): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="text-truncate"><?= e($p['title']) ?></span>
                            <?= edu_status_badge((string) $p['status']) ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent announcements -->
    <div class="col-12 col-lg-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-megaphone"></i> Recent announcements</h2>
                <a class="small text-decoration-none" href="<?= e(url('/admin/announcements')) ?>">All &rarr;</a>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($recentAnn === []): ?>
                    <?= edu_empty_state('Nothing posted yet', 'Announcements will appear here once published.', ['icon' => 'bi-megaphone', 'compact' => true]) ?>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($recentAnn as $a): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="text-truncate"><?= e($a['title']) ?></span>
                            <?= edu_badge((string) $a['audience'], 'neutral', 'bi-people') ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
