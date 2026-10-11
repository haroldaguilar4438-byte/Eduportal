<?php
/**
 * Shared Footer Partial — non-destructive: no DB/session logic.
 */
?>
<footer class="app-footer mt-auto">
  <div class="px-4 lg:px-6 py-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
      <div class="edu-foot-text">
        &copy; <?= date('Y') ?> <span class="fw-semibold">Datamex College of Saint Adeline</span>
        &middot; EduPortal
        <span class="d-none d-sm-inline">&middot; Secure school information system</span>
      </div>
      <div class="d-flex align-items-center flex-wrap gap-3 edu-foot-text">
        <a href="<?= e(url('/privacy')) ?>" class="edu-foot-link">Privacy</a>
        <a href="<?= e(url('/terms')) ?>" class="edu-foot-link">Terms</a>
        <a href="<?= e(url('/support')) ?>" class="edu-foot-link">Support</a>
        <span class="d-none d-sm-inline-flex align-items-center gap-2 px-2.5 py-1 rounded-pill edu-status-pill">
          <span class="edu-status-pill__dot" aria-hidden="true"></span> System online
        </span>
      </div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/responsive.js')) ?>" defer></script>
<script src="<?= e(asset('js/mobile.js')) ?>" defer></script>
