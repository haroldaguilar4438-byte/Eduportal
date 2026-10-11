<?php
/**
 * Admin: announcement list (spec §34).
 *
 * Non-destructive: every view/filter link keeps its query string (the $tabUrl
 * closure still carries view/q/type/audience/status), the GET filter form keeps
 * its action, all four filter names plus the hidden `view` field, the CSV and
 * Create links, the six count cards, the expiring-soon alert, the _table.php
 * include and the content.js script tag are all unchanged.
 *
 * The GET form and the view pills both submit the same query params, so the
 * pills are rendered as real buttons and the form as a filter bar — behaviour
 * is identical.
 *
 * @var array $counts
 */
$c = $counts ?? [];
$cards = [
    'active'        => 'Active',
    'scheduled'     => 'Scheduled',
    'expiring_soon' => 'Expiring soon',
    'expired'       => 'Expired',
    'draft'         => 'Draft',
    'history'       => 'Total history',
];

$curView = $view ?? 'all';
$tabUrl = static function (string $v) use ($q, $type, $audience, $status): string {
    $p = array_filter(
        ['view' => $v, 'q' => $q ?? '', 'type' => $type ?? '', 'audience' => $audience ?? '', 'status' => $status ?? ''],
        static fn($x) => $x !== ''
    );
    return url('/admin/announcements' . ($p === [] ? '' : '?' . http_build_query($p)));
};
?>

<?= edu_breadcrumb([
    ['label' => 'Content', 'url' => url('/admin/content')],
    ['label' => 'Announcements'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Content',
    'title'       => 'Announcements',
    'description' => (int) ($c['active'] ?? 0) . ' active · ' . (int) ($c['scheduled'] ?? 0) . ' scheduled · '
        . (int) ($c['draft'] ?? 0) . ' draft',
    'icon'        => 'bi-megaphone',
    'actions'     => '<a href="' . e(url('/admin/announcements?export=csv')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-download me-1"></i> CSV</a>'
        . '<a href="' . e(url('/admin/announcements/create')) . '" class="btn btn-primary btn-sm">'
        . '<i class="bi bi-plus-lg"></i> Create</a>',
]) ?>

<!-- Counts -->
<div class="row g-2 mb-3">
    <?php foreach ($cards as $k => $label): ?>
        <div class="col-6 col-md-2">
            <div class="edu-card text-center" style="padding:.5rem">
                <div class="small text-muted text-uppercase" style="font-size:.62rem;letter-spacing:.06em"><?= e($label) ?></div>
                <div class="fw-bold" style="font-size:1.25rem;line-height:1.2"><?= (int) ($c[$k] ?? 0) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- View pills -->
<div class="d-flex gap-1 flex-wrap mb-3" role="group" aria-label="Announcement views">
    <?php foreach (['all' => 'All', 'active' => 'Active', 'scheduled' => 'Scheduled', 'history' => 'History'] as $v => $label): ?>
        <a class="btn btn-sm <?= $curView === $v ? 'btn-primary' : 'btn-light' ?>" href="<?= e($tabUrl($v)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (($curView === 'active' || $curView === 'all') && ($expiringSoon ?? []) !== []): ?>
    <?= edu_callout(
        '<strong>Expiring soon:</strong> '
        . implode(' ', array_map(
            static fn($e) => '<span class="edu-badge edu-badge--warning">' . e($e['title']) . ' &middot; expires '
                . e(date('M j, g:i A', strtotime($e['expires_at']))) . '</span>',
            (array) $expiringSoon
        )),
        ['tone' => 'warning', 'icon' => 'bi-alarm', 'title' => null, 'compact' => true]
    ) ?>
<?php endif; ?>

<!-- Filters -->
<form method="get" action="<?= e(url('/admin/announcements')) ?>" class="edu-card mb-3">
    <div class="edu-card__body">
        <div class="row g-2">
            <div class="col-12 col-md-3">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="<?= e($q ?? '') ?>" maxlength="100">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type"><option value="">All</option>
                    <?php foreach (($types ?? []) as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= (($type ?? '') === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="audience">Audience</label>
                <select class="form-select" id="audience" name="audience"><option value="">All</option>
                    <?php foreach (($audiences ?? []) as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= (($audience ?? '') === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status"><option value="">All</option>
                    <?php foreach (['draft', 'scheduled', 'published', 'archived', 'expired'] as $s): ?>
                        <option value="<?= $s ?>" <?= (($status ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="view" value="<?= e($view ?? 'all') ?>">
            <div class="col-6 col-md-1 d-flex align-items-end">
                <button class="btn btn-primary w-100" data-loading="Filtering…">Go</button>
            </div>
        </div>
    </div>
</form>

<div id="adminAnnTable"><?php include __DIR__ . '/_table.php'; ?></div>

<script src="<?= e(asset('js/content.js')) ?>"></script>
