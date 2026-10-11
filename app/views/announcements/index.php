<?php
/**
 * Announcement inbox — role-filtered server-side (spec §16).
 *
 * Non-destructive: the filter form keeps its id (annFilter), action and all
 * three field names (q, type, history) with the same values, the Filter submit
 * keeps data-loading, the pagination preserves q/type/history on every page
 * link, and the content.js script tag stays. Only presentation changed.
 *
 * @var string $q
 * @var string $type
 * @var mixed  $history
 * @var int    $unread
 */
?>

<?= edu_page_header([
    'eyebrow'     => 'Inbox',
    'title'       => 'Announcements',
    'description' => 'School updates addressed to your role.',
    'icon'        => 'bi-megaphone',
    'actions'     => (!empty($unread)
            ? edu_badge((int) $unread . ' unread', 'danger', 'bi-envelope-exclamation')
            : '')
        . '<a href="' . e(url('/dashboard')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-speedometer2 me-1"></i> Dashboard</a>',
]) ?>

<!-- Filters -->
<form id="annFilter" method="get" action="<?= e(url('/announcements')) ?>" class="edu-card mb-3" role="search" aria-label="Filter announcements">
    <div class="edu-card__body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label" for="q">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input class="form-control" id="q" name="q" value="<?= e($q ?? '') ?>" maxlength="100" placeholder="Keyword…">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All types</option>
                    <?php foreach (($types ?? []) as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= (($type ?? '') === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="history">History</label>
                <select class="form-select" id="history" name="history">
                    <option value="">Active only</option>
                    <option value="1" <?= !empty($history) ? 'selected' : '' ?>>Include expired</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button class="btn btn-primary w-100" type="submit" data-loading="Filtering…"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </div>
    </div>
</form>

<div id="annList" aria-live="polite">
    <?php include __DIR__ . '/_list.php'; ?>
</div>

<?php if (($pages ?? 1) > 1): ?>
    <nav aria-label="Announcement pages" class="mt-3">
        <ul class="pagination mb-0">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <li class="page-item <?= $i === ($page ?? 1) ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(url('/announcements?' . http_build_query([
                        'q'       => $q ?? '',
                        'type'    => $type ?? '',
                        'history' => !empty($history) ? '1' : '',
                        'page'    => $i,
                    ]))) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<script src="<?= e(asset('js/content.js')) ?>"></script>
