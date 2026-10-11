<!doctype html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../shared/public_head.php'; ?>
</head>
<body style="background:var(--background);">

<?php $navActive = 'news'; include __DIR__ . '/../shared/public_nav.php'; ?>

<!-- Hero — same maroon language as homepage -->
<section class="hero">
  <div class="container position-relative py-5" style="z-index:1;">
    <span class="badge rounded-pill text-bg-light mb-3" style="color:#7C0F28;"><i class="bi bi-newspaper"></i> Official school updates</span>
    <h1 class="display-5 mb-2">School News</h1>
    <p class="lead mb-3">Events, enrollment reminders, and stories from Datamex College of Saint Adeline.</p>
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= e(url('/')) ?>" class="btn btn-light px-4" style="color:#7C0F28;font-weight:700;"><i class="bi bi-house"></i> Homepage</a>
      <a href="#latest" class="btn btn-outline-light px-4">Browse articles</a>
    </div>
  </div>
</section>

<main id="latest" class="container py-4" style="max-width:1080px;">
  <?php if (!empty($featured)): ?>
  <a href="<?= e(url('/news/' . $featured['slug'])) ?>" class="card mb-4 text-decoration-none overflow-hidden">
    <?php if (!empty($featured['featured_image']) && is_file(ROOT_PATH . '/public_html/' . ltrim((string) $featured['featured_image'], '/'))): ?>
      <div class="news-media news-media--featured" style="overflow:hidden;border-radius:14px 14px 0 0">
        <img src="<?= e(url($featured['featured_image'])) ?>" alt="<?= e($featured['title'] ?? 'Featured article image') ?>" loading="lazy" style="display:block;width:100%;height:200px;object-fit:cover">
      </div>
    <?php endif; ?>
    <div class="card-body">
      <span class="badge text-bg-danger mb-2">Featured</span>
      <?php if (!empty($featured['category_name'])): ?><span class="badge text-bg-light border"><?= e($featured['category_name']) ?></span><?php endif; ?>
      <h2 class="h4 mt-2"><?= e($featured['title']) ?></h2>
      <p class="text-muted"><?= e($featured['summary'] ?? '') ?></p>
      <small class="text-muted"><?= e(date('M j, Y', strtotime($featured['published_at'] ?? 'now'))) ?><?php if (!empty($featured['author_name']) && trim($featured['author_name']) !== ''): ?> &middot; <?= e(trim($featured['author_name'])) ?><?php endif; ?></small>
    </div>
  </a>
  <?php endif; ?>

  <form id="blogFilter" method="get" action="<?= e(url('/news')) ?>" class="card card-body mb-3" role="search" aria-label="Search news">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label" for="q">Search</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input class="form-control" id="q" name="q" value="<?= e($q ?? '') ?>" placeholder="Keyword…" maxlength="100" autocomplete="off">
        </div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="category">Category</label>
        <select class="form-select" id="category" name="category">
          <option value="">All categories</option>
          <?php foreach (($cats ?? []) as $c): ?>
            <option value="<?= e($c['slug']) ?>" <?= (($category ?? '') === $c['slug']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-primary w-100" type="submit" data-loading="Searching…"><i class="bi bi-search"></i> Search</button>
      </div>
    </div>
  </form>

  <div id="blogList" aria-live="polite">
    <?php include __DIR__ . '/_list.php'; ?>
  </div>

  <?php if (($pages ?? 1) > 1): ?>
  <nav aria-label="News pages" class="mt-3">
    <ul class="pagination">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <li class="page-item <?= $i === ($page ?? 1) ? 'active' : '' ?>">
          <a class="page-link" href="<?= e(url('/news?' . http_build_query(['q' => $q ?? '', 'category' => $category ?? '', 'page' => $i]))) ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../shared/public_footer.php'; ?>
</body>
</html>
