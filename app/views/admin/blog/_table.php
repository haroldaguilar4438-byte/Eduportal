<?php
/**
 * Admin: blog table partial (spec §34).
 *
 * Non-destructive: the status tone map, the /news/{slug} display, the Edit and
 * Preview links (Preview keeps target=_blank rel=noopener), the delete form with
 * its action/CSRF/data-confirm, the draft-or-archived-only delete condition,
 * and the pagination block — which still preserves q/status/category on every
 * page link — are all unchanged. Added: an Archive button for published /
 * scheduled posts (posts to the archive action, same CSRF/confirm pattern),
 * which unpublishes them so the Delete button then appears. Only markup is
 * restyled otherwise.
 *
 * @var array $rows
 */
$rows  = $rows ?? [];
$pages = (int) ($pages ?? 1);
$pageNo = (int) ($page ?? 1);

$toneFor = static function (string $status): string {
    return match ($status) {
        'published' => 'success',
        'draft'     => 'secondary',
        'scheduled' => 'info',
        default     => 'warning',
    };
};
?>

<?php if ($rows === []): ?>
    <?= edu_empty_state(
        'No articles found',
        'Create a post, or adjust the filters above.',
        ['icon' => 'bi-newspaper', 'compact' => true]
    ) ?>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Published</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <strong><?= e($r['title']) ?></strong>
                        <div class="small text-muted" style="font-family:var(--font-mono)">/news/<?= e($r['slug']) ?></div>
                    </td>
                    <td><?= e($r['category_name'] ?? '—') ?></td>
                    <td><?= edu_badge((string) $r['status'], $toneFor((string) $r['status'])) ?></td>
                    <td class="small text-muted"><?= e($r['published_at'] ?? '—') ?></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/blog/' . (int) $r['id'] . '/edit')) ?>">Edit</a>
                        <a class="btn btn-sm btn-outline-info" href="<?= e(url('/admin/blog/' . (int) $r['id'] . '/preview')) ?>" target="_blank" rel="noopener">Preview</a>
                        <?php if (in_array($r['status'], ['draft', 'archived'], true)): ?>
                            <form method="post" action="<?= e(url('/admin/blog/' . (int) $r['id'] . '/delete')) ?>" class="d-inline"
                                  data-confirm="Delete this article? This cannot be undone.">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('/admin/blog/' . (int) $r['id'] . '/archive')) ?>" class="d-inline"
                                  data-confirm="Archive this article? It will be removed from public view, and can then be deleted permanently if needed.">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-warning">Archive</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($pages > 1): ?>
    <nav aria-label="Blog pages" class="mt-3">
        <ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <li class="page-item <?= $i === $pageNo ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(url('/admin/blog?' . http_build_query([
                        'q'        => $_GET['q'] ?? '',
                        'status'   => $_GET['status'] ?? '',
                        'category' => $_GET['category'] ?? '',
                        'page'     => $i,
                    ]))) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
