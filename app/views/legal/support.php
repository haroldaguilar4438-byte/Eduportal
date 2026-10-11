<?php /** @var array $school @var array $campuses */

/* Heading is rendered by shared/layout_auth; this view also emitted its own
   <h1>, so the page shipped with two <h1> elements. */
$authHeading    = 'Support & Contact';
$authSubheading = ($school['name'] ?? '') . ' — we reply on school days.';
?>
<div class="p-4 p-md-5">
  <ul class="list-group list-group-flush small mb-3">
    <li class="list-group-item px-0"><i class="bi bi-envelope"></i> General &amp; billing questions: <strong><?= e($school['support'] ?? '') ?></strong></li>
    <li class="list-group-item px-0"><i class="bi bi-mortarboard"></i> Academic records: <strong><?= e($school['registrar'] ?? '') ?></strong></li>
    <?php if (!empty($school['address'])): ?><li class="list-group-item px-0"><i class="bi bi-geo-alt"></i> <?= e($school['address']) ?></li><?php endif; ?>
  </ul>
  <?php if (!empty($campuses)): ?>
  <h2 class="h6">Campuses</h2>
  <ul class="small text-muted mb-3">
    <?php foreach ($campuses as $c): ?><li><?= e($c['name']) ?> <span class="text-secondary">(<?= e($c['code']) ?>)</span></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= e(url('/privacy')) ?>" class="btn btn-sm btn-outline-secondary">Privacy Policy</a>
    <a href="<?= e(url('/terms')) ?>" class="btn btn-sm btn-outline-secondary">Terms</a>
    <a href="<?= e(url('/')) ?>" class="btn btn-sm btn-outline-secondary">Home</a>
  </div>
</div>
