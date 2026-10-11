<?php /** @var string $heading @var string $updated @var string $bodyHtml @var array $school @var bool $custom */
$allowed = '<h2><p><ul><ol><li><strong><em><a><br>';

/* Heading is rendered by shared/layout_auth; this view also emitted its own
   <h1>, so every legal page shipped with two <h1> elements. */
$authHeading    = $heading ?? 'Policy';
$authSubheading = ($school['name'] ?? '') . ' · Updated ' . ($updated ?? '');
?>
<div class="p-4 p-md-5">
  <div class="small legal-body">
    <?= strip_tags($bodyHtml ?? '', $allowed) ?>
  </div>
  <hr>
  <p class="small text-muted mb-2">Questions about this page: <?= e($school['contact'] ?? '') ?></p>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= e(url('/register')) ?>" class="btn btn-sm btn-primary">Back to registration</a>
    <a href="<?= e(url('/')) ?>" class="btn btn-sm btn-outline-secondary">Home</a>
  </div>
</div>
<style>.legal-body h2{font-size:1rem;margin:1rem 0 .35rem;} .legal-body p,.legal-body li{color:#334155;} .legal-body ul,.legal-body ol{padding-left:1.25rem;}</style>
