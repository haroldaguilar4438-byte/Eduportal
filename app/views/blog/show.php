<!doctype html>
<html lang="en">
<head>
  <?php
    $metaDescription = !empty($post['seo_description']) ? $post['seo_description'] : (!empty($post['summary']) ? $post['summary'] : 'Datamex College of Saint Adeline news.');
    include __DIR__ . '/../shared/public_head.php';
  ?>
</head>
<body>

<?php $navActive = 'news'; include __DIR__ . '/../shared/public_nav.php'; ?>

<?php
    /* Date line reused by the hero and the article meta. */
    $newsDate = static function (array $p): string {
        return date('F j, Y', strtotime($p['published_at'] ?? $p['created_at'] ?? 'now'));
    };
    $postTitle = (string) ($post['title'] ?? ($title ?? 'School News'));
?>

<section class="hero">
  <div class="container position-relative py-4" style="z-index:1;">
    <a href="<?= e(url('/news')) ?>" class="btn btn-sm btn-light mb-3" style="color:#7C0F28;font-weight:700;">
      <i class="bi bi-arrow-left"></i> All news
    </a>
    <?php /*
      The article title is rendered once, below, inside <main>. It used to be
      repeated here, which produced two <h1> elements on every article page.
      This hero keeps only the category + date line.
    */ ?>
    <?php if (!empty($post)): ?>
      <h1 class="visually-hidden"><?= e($postTitle) ?></h1>
      <p class="lead mb-0" style="font-size:1rem;">
        <?php if (!empty($post['category_name'])): ?><?= e($post['category_name']) ?> &middot; <?php endif; ?>
        <?= e($newsDate((array) $post)) ?>
      </p>
    <?php else: ?>
      <h1 class="h2 mb-1"><?= e($postTitle) ?></h1>
    <?php endif; ?>
  </div>
</section>

<main class="container py-4" style="max-width:780px;">
  <?php if (!empty($preview)): ?>
    <div class="alert alert-warning"><i class="bi bi-eye"></i> <strong>Preview</strong> — this article is not public in this state.</div>
  <?php endif; ?>

  <?php if (empty($post)): ?>
    <div class="card card-body text-center py-5">
      <h2 class="h4">Article not found</h2>
      <p class="text-muted">It may have been archived or the link is incorrect.</p>
      <div class="d-flex gap-2 justify-content-center">
        <a class="btn btn-primary" href="<?= e(url('/news')) ?>">Browse news</a>
        <a class="btn btn-outline-secondary" href="<?= e(url('/')) ?>">Homepage</a>
      </div>
    </div>
  <?php else: ?>
    <article class="card overflow-hidden">
      <?php if (!empty($post['featured_image']) && is_file(ROOT_PATH . '/public_html/' . ltrim((string) $post['featured_image'], '/'))): ?>
        <img src="<?= e(url($post['featured_image'])) ?>" alt="<?= e($post['title']) ?>" loading="lazy" class="blog-featured-img">
      <?php endif; ?>

      <div class="card-body p-4">
        <?php if (!empty($post['category_name'])): ?>
          <span class="badge text-bg-light border"><?= e($post['category_name']) ?></span>
        <?php endif; ?>

        <h2 class="h3 mt-2"><?= e($postTitle) ?></h2>

        <p class="text-muted small">
          <?php if (!empty($post['author_name']) && trim($post['author_name']) !== ''): ?>
            By <?= e(trim($post['author_name'])) ?> &middot;
          <?php endif; ?>
          Published <?= e($newsDate((array) $post)) ?>
          <?php if (!empty($post['updated_at']) && $post['updated_at'] !== ($post['created_at'] ?? '')): ?>
            &middot; Updated <?= e(date('M j, Y', strtotime($post['updated_at']))) ?>
          <?php endif; ?>
        </p>

        <?php if (!empty($post['summary'])): ?>
          <p class="lead fs-6 text-muted"><?= e($post['summary']) ?></p>
        <?php endif; ?>

        <hr>

        <div class="blog-content">
          <?= $post['content'] /* pre-sanitized allowlist HTML on write */ ?>
        </div>

        <?php if (!empty($post['tags'])): ?>
          <p class="mt-3"><i class="bi bi-tags"></i> <small class="text-muted"><?= e($post['tags']) ?></small></p>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-3 flex-wrap">
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/news')) ?>"><i class="bi bi-newspaper"></i> More news</a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/')) ?>"><i class="bi bi-house"></i> Homepage</a>
        </div>
      </div>
    </article>

    <?php if (!empty($related)): ?>
      <h2 class="h5 mt-4">Related articles</h2>
      <div class="row g-3">
        <?php foreach ($related as $r): ?>
          <div class="col-12 col-md-4">
            <a class="card program-card p-3 h-100 text-decoration-none" href="<?= e(url('/news/' . $r['slug'])) ?>">
              <h3 class="h6"><?= e($r['title']) ?></h3>
              <small class="text-muted"><?= e(date('M j, Y', strtotime($r['published_at'] ?? 'now'))) ?></small>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../shared/public_footer.php'; ?>
</body>
</html>
