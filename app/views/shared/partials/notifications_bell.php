<?php
/**
 * Global notification bell — rendered on EVERY page (desktop topbar + mobile topbar).
 * Expects $notifUnread (int) and $notifRecent (array) from layout; defaults safe for guests.
 * NOTE: button uses .notif-toggle (NOT .theme-toggle) so the dark-mode script
 * never rewrites the bell icon into a sun/moon.
 */
$notifUnread = $notifUnread ?? 0;
$notifRecent = $notifRecent ?? [];
static $_notifBellN = 0;
$_notifBellN++;
$bid = 'notifBellBtn' . $_notifBellN;
$typeIcon = static function (string $t): string {
    return match ($t) {
        'success' => 'bi-check-circle-fill text-success',
        'warning' => 'bi-exclamation-triangle-fill text-warning',
        'danger' => 'bi-x-circle-fill text-danger',
        'payment' => 'bi-credit-card-fill text-primary',
        'task' => 'bi-journal-text text-primary',
        'grade' => 'bi-percent text-success',
        default => 'bi-info-circle-fill text-secondary',
    };
};
?>
<div class="dropdown notif-bell-wrap">
  <button type="button" class="notif-toggle position-relative" id="<?= $bid ?>" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Notifications" aria-label="Notifications">
    <i class="bi bi-bell"></i>
    <span class="notifBadge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= ((int)$notifUnread > 0) ? '' : 'd-none' ?>" style="font-size:.6rem"><?= (int)$notifUnread > 99 ? '99+' : (int)$notifUnread ?></span>
  </button>
  <div class="dropdown-menu dropdown-menu-end shadow p-0" aria-labelledby="<?= $bid ?>" style="width:340px;max-width:92vw;max-height:70vh;overflow:hidden">
    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
      <strong class="small">Notifications</strong>
      <div class="d-flex gap-2 align-items-center">
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>" class="m-0">
          <?= \App\Core\Csrf::field() ?>
          <input type="hidden" name="redirect" value="<?= e(current_path()) ?>">
          <button type="submit" class="btn btn-link btn-sm text-decoration-none p-0">Mark all read</button>
        </form>
        <a href="<?= e(url('/notifications')) ?>" class="btn btn-link btn-sm text-decoration-none p-0">View all</a>
      </div>
    </div>
    <div class="overflow-auto" style="max-height:52vh" aria-live="polite" aria-label="Latest notifications">
      <?php if ($notifRecent === []): ?>
        <p class="text-muted small text-center py-4 mb-0">No notifications yet.</p>
      <?php else: ?>
        <?php foreach ($notifRecent as $n): ?>
          <a href="<?= e($n['link_url'] ?: '/notifications') ?>" data-notif-id="<?= (int)$n['id'] ?>" class="dropdown-item d-flex gap-2 py-2 px-3 border-bottom <?= empty($n['is_read']) ? 'notif-unread' : '' ?>">
            <i class="bi <?= $typeIcon((string)($n['type'] ?? 'info')) ?> mt-1"></i>
            <span class="flex-grow-1">
              <span class="d-block small fw-semibold"><?= e($n['title']) ?></span>
              <?php if (!empty($n['message'])): ?><span class="d-block small text-muted" style="white-space:normal"><?= e(mb_substr($n['message'], 0, 110)) ?><?= strlen($n['message']) > 110 ? '…' : '' ?></span><?php endif; ?>
              <span class="d-block text-secondary" style="font-size:.7rem"><?= e(time_ago($n['created_at'])) ?></span>
            </span>
            <?php if (empty($n['is_read'])): ?><span class="badge-pulse mt-2" style="flex-shrink:0"></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function(){
  if (window._notifPollStarted) return;
  window._notifPollStarted = true;
  function setBadges(n){
    document.querySelectorAll('.notifBadge').forEach(function(b){
      b.textContent = n > 99 ? '99+' : n;
      b.classList.toggle('d-none', n <= 0);
    });
  }
  function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  var ICONS = {success:'bi-check-circle-fill text-success',warning:'bi-exclamation-triangle-fill text-warning',danger:'bi-x-circle-fill text-danger',payment:'bi-credit-card-fill text-primary',task:'bi-journal-text text-primary',grade:'bi-percent text-success'};
  // Live path: centralized EduLive manager (badge + dropdown, deduplicated).
  // NOTE: this partial renders before edulive.js loads (end of body), so wait briefly.
  function bindLive() {
    EduLive.onUnread(setBadges);
    EduLive.onNotification(function(items){
      document.querySelectorAll('.notif-bell-wrap').forEach(function(wrap){
        var list = wrap.querySelector('.overflow-auto');
        if (!list) return;
        var empty = list.querySelector('p.text-muted');
        if (empty) empty.remove();
        items.slice().reverse().forEach(function(n){
          if (list.querySelector('[data-notif-id="' + n.id + '"]')) return;
          var a = document.createElement('a');
          a.className = 'dropdown-item d-flex gap-2 py-2 px-3 border-bottom notif-unread';
          a.href = n.link || '/notifications';
          a.setAttribute('data-notif-id', n.id);
          var msg = n.message ? '<span class="d-block small text-muted" style="white-space:normal">' + esc(n.message.length > 110 ? n.message.substring(0,110) + '…' : n.message) + '</span>' : '';
          a.innerHTML = '<i class="bi ' + (ICONS[n.type] || 'bi-info-circle-fill text-secondary') + ' mt-1"></i>'
            + '<span class="flex-grow-1"><span class="d-block small fw-semibold">' + esc(n.title) + '</span>' + msg + '</span>'
            + '<span class="badge-pulse mt-2" style="flex-shrink:0"></span>';
          list.insertBefore(a, list.firstChild);
          while (list.querySelectorAll('[data-notif-id]').length > 8) list.removeChild(list.lastChild);
        });
      });
    });
  }
  function bindLegacy() {
    // Fallback when EduLive is unavailable: legacy badge-only polling.
    var feedUrl = <?= json_encode(url('/notifications/feed')) ?>;
    function tick(){
      fetch(feedUrl, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){ if(d) setBadges(parseInt(d.unread || 0, 10)); })
        .catch(function(){});
    }
    setInterval(tick, 60000);
  }
  if (window.EduLive) { bindLive(); return; }
  var tries = 0;
  var iv = setInterval(function(){
    if (window.EduLive) { clearInterval(iv); bindLive(); }
    else if (++tries > 100) { clearInterval(iv); bindLegacy(); }
  }, 100);
})();
</script>
