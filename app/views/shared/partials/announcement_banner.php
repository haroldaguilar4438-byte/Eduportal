<?php
/**
 * Announcement banner partial — include at top of role dashboards.
 * Expects $annBannerRows (array) or loads fail-open via Models\Announcement.
 * Only shows announcements the viewer is allowed to receive (server-side).
 */
$annBannerRows = $annBannerRows ?? null;
if ($annBannerRows === null) {
    try {
        $role = \App\Core\Auth::role() ?? '';
        $uid = (int) (\App\Core\Auth::id() ?? 0);
        $annBannerRows = \App\Models\Announcement::activeFor($role, $uid, 3);
    } catch (Throwable $e) {
        $annBannerRows = [];
    }
}
if (!empty($annBannerRows)):
  $top = $annBannerRows[0];
  $unreadN = 0;
  foreach ($annBannerRows as $r) { if (((int) ($r['is_read'] ?? 1)) === 0) $unreadN++; }
?>
<div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3" role="alert" aria-live="polite">
  <div>
    <strong><i class="bi bi-megaphone"></i> Important notice:</strong>
    <?= e($top['title']) ?>
    <?php if ($unreadN > 1): ?><span class="badge text-bg-danger ms-1"><?= (int) $unreadN ?> unread</span><?php endif; ?>
    <div class="small text-muted"><?= e($top['excerpt'] ?? '') ?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-warning" href="<?= e(url('/announcements/' . (int) $top['id'])) ?>">View announcement</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/announcements')) ?>">All</a>
  </div>
</div>
<?php endif; ?>
