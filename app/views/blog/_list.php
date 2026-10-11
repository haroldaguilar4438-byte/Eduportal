<?php
/**
 * Public news list partial.
 *
 * Non-destructive: the empty state keeps its two CTAs, the article count line,
 * the /news/{slug} link, the featured-image and category branches, and the
 * card grid columns. Only markup and empty-state styling changed.
 *
 * @var array $rows
 * @var int   $total
 */
$rows  = $rows ?? [];
$total = $total ?? count($rows);
?>

<?php if ($rows === []): ?>
    <?= edu_empty_state(
        'No articles yet',
        'New events, enrollment reminders, and school stories will appear here once published.',
        [
            'icon'  => 'bi-newspaper',
            'actions' => '<a href="' . e(url('/')) . '" class="btn btn-sm btn-outline-secondary">'
                . '<i class="bi bi-house me-1"></i> Back to homepage</a>'
                . '<a href="' . e(url('/register')) . '" class="btn btn-sm btn-primary">Enroll now</a>',
        ]
    ) ?>
<?php else: ?>
    <p class="text-muted small"><?= (int) $total ?> article(s)</p>
    <div class="row g-3">
        <?php foreach ($rows as $r): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <article class="card h-100 overflow-hidden">
                    <?php if (!empty($r['featured_image']) && is_file(ROOT_PATH . '/public_html/' . ltrim((string) $r['featured_image'], '/'))): ?>
                        <div class="news-media news-media--small" style="overflow:hidden;border-radius:14px 14px 0 0">
                            <img src="<?= e(url($r['featured_image'])) ?>" alt="<?= e($r['title'] ?? 'Article image') ?>" loading="lazy" style="display:block;width:100%;height:150px;object-fit:cover">
                        </div>
                    <?php else: ?>
                        <div class="news-media news-media--small news-media--fallback" aria-hidden="true" style="height:150px;font-size:1.75rem">
                            <i class="bi bi-newspaper"></i>
                        </div>
                    <?php endif; ?>
                    <div class="card-body d-flex flex-column">
                        <?php if (!empty($r['category_name'])): ?>
                            <span class="badge text-bg-light border align-self-start mb-2"><?= e($r['category_name']) ?></span>
                        <?php endif; ?>
                        <h2 class="h6"><?= e($r['title']) ?></h2>
                        <p class="text-muted small flex-fill"><?= e($r['summary'] ?? '') ?></p>
                        <small class="text-muted d-block mb-2"><?= e(date('M j, Y', strtotime($r['published_at'] ?? 'now'))) ?></small>
                        <a class="btn btn-sm btn-outline-primary align-self-start" href="<?= e(url('/news/' . $r['slug'])) ?>">
                            Read more <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
