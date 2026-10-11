<?php
/**
 * Admin: blog / news list (spec §34).
 *
 * Non-destructive: the CSV link keeps its q/status/category query params, the
 * filter form keeps its id (adminBlogFilter), action and all three field names,
 * the count cards iterate $counts exactly as before, the _table.php include and
 * content.js script are unchanged.
 *
 * @var array  $counts
 * @var string $q
 * @var string $status
 * @var string $category
 */
?>

<?= edu_breadcrumb([
    ['label' => 'Content', 'url' => url('/admin/content')],
    ['label' => 'Blog / news'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Content',
    'title'       => 'Blog / news',
    'description' => 'Posts published here appear on the public /news page.',
    'icon'        => 'bi-newspaper',
    'actions'     => '<a href="' . e(url('/admin/blog?export=csv&q=' . urlencode($q ?? '') . '&status=' . urlencode($status ?? '') . '&category=' . urlencode($category ?? ''))) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-download me-1"></i> CSV</a>'
        . '<a href="' . e(url('/admin/blog/create')) . '" class="btn btn-primary btn-sm">'
        . '<i class="bi bi-plus-lg"></i> Create post</a>',
]) ?>

<!-- Counts -->
<div class="row g-2 mb-3">
    <?php foreach (($counts ?? []) as $k => $v): ?>
        <div class="col-6 col-md-3">
            <div class="edu-card text-center" style="padding:.5rem">
                <div class="small text-muted text-uppercase" style="font-size:.62rem;letter-spacing:.06em"><?= e($k) ?></div>
                <div class="fw-bold" style="font-size:1.25rem;line-height:1.2"><?= (int) $v ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<form id="adminBlogFilter" method="get" action="<?= e(url('/admin/blog')) ?>" class="edu-card mb-3">
    <div class="edu-card__body">
        <div class="row g-2">
            <div class="col-12 col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="<?= e($q ?? '') ?>" maxlength="100" placeholder="Title, slug, tag…">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['draft', 'scheduled', 'published', 'archived'] as $s): ?>
                        <option value="<?= $s ?>" <?= (($status ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All</option>
                    <?php foreach (($cats ?? []) as $c): ?>
                        <option value="<?= e($c['slug']) ?>" <?= (($category ?? '') === $c['slug']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100" data-loading="Filtering…"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </div>
    </div>
</form>

<div id="adminBlogTable">
    <?php include __DIR__ . '/_table.php'; ?>
</div>

<script src="<?= e(asset('js/content.js')) ?>"></script>
