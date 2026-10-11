<?php
/**
 * Shared public footer — contact block + admissions checklist + legal row.
 * Expects $school (array) and $campuses (array), both optional / fail-open.
 *
 * @var array|null $school
 * @var array|null $campuses
 */
$school   = $school ?? ['name' => 'Datamex College of Saint Adeline', 'address' => 'Dasmarinas, Cavite, Philippines', 'contact' => 'datamex82@gmail.com'];
$campuses = $campuses ?? [];

$contactRows = [];
if (!empty($school['address'])) {
    $contactRows[] = ['bi-geo-alt',    'Address',   $school['name'] . ', ' . $school['address']];
}
if (!empty($school['contact'])) {
    $contactRows[] = ['bi-envelope',   'Email',     $school['contact']];
}
if (!empty($campuses)) {
    $contactRows[] = ['bi-building',   'Campuses',  implode(' · ', array_map(static fn ($c) => (string) ($c['name'] ?? ''), $campuses))];
}
?>
<section id="contact" class="section-pad border-top" style="background:var(--surface)">
  <div class="container">
    <div class="section-head">
      <span class="section-eyebrow"><i class="bi bi-geo-alt"></i> Get in touch</span>
      <h2 class="section-title">Visit the campus or talk to admissions</h2>
      <p class="section-lede">Our office is open Monday to Friday. Walk-ins are welcome for queries, document submission and guided online enrollment.</p>
    </div>

    <div class="row g-4">
      <div class="col-12 col-lg-5">
        <div class="edu-card h-100">
          <div class="edu-card__head">
            <h3 class="edu-card__title"><i class="bi bi-info-circle"></i> School details</h3>
          </div>
          <div class="edu-card__body">
            <?php if ($contactRows === []): ?>
              <?= edu_empty_state('Contact details coming soon', 'The school office has not published contact details yet.', ['icon' => 'bi-telephone-x', 'compact' => true]) ?>
            <?php else: ?>
              <div class="edu-dl">
                <?php foreach ($contactRows as [$icon, $label, $value]): ?>
                  <div class="edu-dl__row">
                    <dt><i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?></dt>
                    <dd><?= e($value) ?></dd>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <a href="<?= e(url('/register')) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil-square me-1"></i> Start an application
              </a>
              <a href="<?= e(url('/support')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-headset me-1"></i> Get support
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-7">
        <div class="edu-card edu-card--accent h-100">
          <div class="edu-card__head">
            <h3 class="edu-card__title"><i class="bi bi-list-check"></i> Admissions checklist</h3>
            <span class="edu-badge edu-badge--accent">Prepare ahead</span>
          </div>
          <div class="edu-card__body">
            <ul class="mb-0 ps-3" style="line-height:2">
              <li>Scanned <strong>Form 137 / Form 138</strong> (Report Card)</li>
              <li><strong>PSA Birth Certificate</strong> and Good Moral Certificate</li>
              <li><strong>2&times;2 ID photo</strong> on a white background</li>
              <li>Pay the reservation fee online (GCash &middot; Maya &middot; Card &middot; QR&nbsp;Ph)</li>
            </ul>
            <div class="edu-callout edu-callout--accent mt-3" role="note">
              <i class="bi bi-lightbulb edu-callout__icon" aria-hidden="true"></i>
              <div>Everything above can be uploaded as a photo or PDF once your account is created.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<footer class="py-4 border-top" style="background:var(--surface-muted)">
  <div class="container">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 small" style="color:var(--text-tertiary)">
      <div>
        &copy; <?= date('Y') ?> <strong style="color:var(--text-secondary)"><?= e($school['name']) ?></strong> &middot; EduPortal
      </div>
      <nav class="d-flex flex-wrap gap-3" aria-label="Footer">
        <a class="text-decoration-none" style="color:var(--text-tertiary)" href="<?= e(url('/news')) ?>">News</a>
        <a class="text-decoration-none" style="color:var(--text-tertiary)" href="<?= e(url('/login')) ?>">Portal sign in</a>
        <a class="text-decoration-none" style="color:var(--text-tertiary)" href="<?= e(url('/privacy')) ?>">Privacy</a>
        <a class="text-decoration-none" style="color:var(--text-tertiary)" href="<?= e(url('/terms')) ?>">Terms</a>
        <a class="text-decoration-none" style="color:var(--text-tertiary)" href="<?= e(url('/support')) ?>">Support</a>
      </nav>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/chatbot.js')) ?>"></script>
<script src="<?= e(asset('js/content.js')) ?>"></script>
<script src="<?= e(asset('js/design-system.js')) ?>" defer></script>
<script src="<?= e(asset('js/responsive.js')) ?>" defer></script>
