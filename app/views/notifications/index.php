<?php
/**
 * Notification centre (spec §33).
 *
 * Non-destructive: $rows, $filter and $unread are used exactly as before. The
 * All / Unread filters, the "Mark all read" form and the per-row "Mark read"
 * form keep their actions, CSRF tokens and hidden `redirect` fields. The
 * EduLive live-injection script is preserved so new notifications still appear
 * without a reload.
 *
 * The spec asks for five categories (All, Academic, Enrollment, Payment, Permit,
 * System). The backend only stores a free `type`, so categories are derived by
 * mapping that type onto a category — no new rows, no server change.
 *
 * @var array  $rows
 * @var string $filter
 * @var int    $unread
 */
$rows   = $rows ?? [];
$filter = (string) ($filter ?? 'all');
$unread = (int) ($unread ?? 0);

/** Map the stored notification type onto one of the spec's categories. */
$categoryOf = static function (string $type): array {
    $t = strtolower(trim($type));
    return match (true) {
        in_array($t, ['grade', 'task', 'classroom', 'submission'], true)
            => ['Academic', 'bi-journal-check', 'info'],
        in_array($t, ['enroll', 'enrollment', 'document', 'announcement'], true)
            => ['Enrollment', 'bi-file-earmark-text', 'primary'],
        in_array($t, ['payment', 'finance', 'payrex', 'receipt'], true)
            => ['Payment', 'bi-credit-card', 'accent'],
        in_array($t, ['permit', 'clearance'], true)
            => ['Permit', 'bi-patch-check', 'success'],
        default => ['System', 'bi-info-circle', 'neutral'],
    };
};

/** Per-category counts, so the filter bar can show real numbers. */
$catCounts = [];
foreach ($rows as $n) {
    [$cat] = $categoryOf((string) ($n['type'] ?? 'system'));
    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
}

/** Client-side category filter, layered on top of the server filter. */
$activeCategory = 'all';
?>

<?= edu_page_header([
    'eyebrow'     => 'Activity',
    'title'       => 'Notifications',
    'description' => 'Exam permits, payments, activities, grades, clearances and announcements.',
    'actions'     => '<form method="post" action="' . e(url('/notifications/read-all')) . '" class="m-0">'
                   . csrf_field()
                   . '<input type="hidden" name="redirect" value="/notifications">'
                   . '<button class="btn btn-light btn-sm"' . ($unread > 0 ? '' : ' disabled title="Nothing unread"') . '>'
                   . '<i class="bi bi-check2-all me-1"></i> Mark all read</button></form>',
]) ?>

<?php if ($unread > 0): ?>
  <div class="mb-3"><?= edu_badge($unread . ' unread', 'primary', 'bi-bell-fill') ?></div>
<?php endif; ?>

<!-- Server-side filter (all / unread) preserved exactly as before -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="<?= e(url('/notifications?filter=all')) ?>"
     class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
  <a href="<?= e(url('/notifications?filter=unread')) ?>"
     class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline-secondary' ?>">Unread</a>
</div>

<?php if ($rows === []): ?>
  <div id="notifEmpty">
    <?= edu_empty_state(
        $filter === 'unread' ? "You're all caught up" : 'No notifications yet',
        $filter === 'unread'
            ? 'You have no unread notifications. Anything new will appear here.'
            : 'Permit, payment, grade, clearance and announcement updates will appear here.',
        ['icon' => 'bi-bell-slash']
    ) ?>
  </div>
  <ul class="list-group list-group-flush" id="notifList" style="display:none"></ul>
<?php else: ?>

  <!-- Client-side category bar -->
  <nav class="edu-tabs" id="notifCategories" aria-label="Notification categories">
    <button type="button" class="edu-tab is-active" data-cat="all">
      <i class="bi bi-collection"></i><span>All</span><span class="edu-tab__count"><?= count($rows) ?></span>
    </button>
    <?php foreach (['Academic', 'Enrollment', 'Payment', 'Permit', 'System'] as $cat):
        $tabIcons = [
            'Academic'  => 'bi-journal-check',
            'Enrollment'=> 'bi-file-earmark-text',
            'Payment'   => 'bi-credit-card',
            'Permit'    => 'bi-patch-check',
            'System'    => 'bi-info-circle',
        ]; ?>
      <button type="button" class="edu-tab" data-cat="<?= e($cat) ?>">
        <i class="bi <?= e($tabIcons[$cat]) ?>" aria-hidden="true"></i>
        <span><?= e($cat) ?></span>
        <span class="edu-tab__count"><?= (int) ($catCounts[$cat] ?? 0) ?></span>
      </button>
    <?php endforeach; ?>
  </nav>

  <div class="edu-card" id="notifListCard">
    <div class="edu-card__body">
      <div class="d-flex flex-column" id="notifList">
        <?php foreach ($rows as $n):
            $isRead = !empty($n['is_read']);
            [$cat, $catIcon, $catTone] = $categoryOf((string) ($n['type'] ?? 'system')); ?>
          <div class="edu-feed__item" data-notif-id="<?= (int) $n['id'] ?>" data-cat="<?= e($cat) ?>" style="<?= $isRead ? '' : 'background:var(--primary-50);border-radius:var(--radius);padding-inline:.6rem' ?>">
            <span class="edu-stat__icon edu-stat__icon--<?= e($catTone) ?> flex-none" aria-hidden="true"
                  style="width:38px;height:38px;font-size:1rem">
              <i class="bi <?= e($catIcon) ?>"></i>
            </span>
            <div class="edu-feed__body">
              <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                <span class="edu-feed__title mb-0"><?= e($n['title'] ?? '') ?></span>
                <?= edu_badge($cat, $catTone) ?>
                <?php if (!$isRead): ?>
                  <?= edu_badge('Unread', 'primary', 'bi-circle-fill') ?>
                <?php endif; ?>
              </div>
              <?php if (!empty($n['message'])): ?>
                <div class="small" style="color:var(--text-secondary)"><?= e($n['message']) ?></div>
              <?php endif; ?>
              <div class="edu-feed__meta">
                <?= e(time_ago($n['created_at'] ?? null)) ?>
                &middot; <?= e(edu_datetime($n['created_at'] ?? null)) ?>
              </div>
            </div>
            <div class="d-flex gap-1 flex-none flex-wrap">
              <?php if (!empty($n['link_url'])): ?>
                <a href="<?= e($n['link_url']) ?>" class="btn btn-sm btn-outline-primary"
                   aria-label="Open notification: <?= e($n['title'] ?? '') ?>">Open</a>
              <?php endif; ?>
              <?php if (!$isRead): ?>
                <form method="post" action="<?= e(url('/notifications/' . (int) $n['id'] . '/read')) ?>" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="redirect" value="/notifications<?= $filter === 'unread' ? '?filter=unread' : '' ?>">
                  <button class="btn btn-sm btn-outline-secondary">Mark read</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div id="notifCatEmpty" class="d-none">
        <?= edu_empty_state('Nothing in this category', 'Try another category or the All tab.', ['icon' => 'bi-funnel', 'compact' => true]) ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
(function () {
  'use strict';

  /* ---- Category filter (client-side, layered on the server filter) ---- */
  var bar = document.getElementById('notifCategories');
  var list = document.getElementById('notifList');
  var catEmpty = document.getElementById('notifCatEmpty');
  var barEmpty = document.getElementById('notifEmpty');

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function applyCategory(cat) {
    if (!list) return;
    var shown = 0;
    list.querySelectorAll('[data-cat]').forEach(function (row) {
      var match = (cat === 'all') || (row.getAttribute('data-cat') === cat);
      row.classList.toggle('d-none', !match);
      if (match) shown++;
    });
    if (catEmpty) catEmpty.classList.toggle('d-none', shown !== 0);
    if (bar) {
      bar.querySelectorAll('.edu-tab').forEach(function (t) {
        t.classList.toggle('is-active', t.getAttribute('data-cat') === cat);
      });
    }
  }

  if (bar && list) {
    bar.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.edu-tab');
      if (!btn) return;
      applyCategory(btn.getAttribute('data-cat'));
    });
  }

  /* ---- Live notifications (EduLive) ---- */
  function boot() {
    if (!list) return;
    var seen = {};
    list.querySelectorAll('[data-notif-id]').forEach(function (el) { seen[el.getAttribute('data-notif-id')] = 1; });

    EduLive.onNotification(function (items) {
      var added = 0;
      items.slice().reverse().forEach(function (n) {
        var key = String(n.id);
        if (seen[key]) return;
        seen[key] = 1;
        added++;

        var el = document.createElement('div');
        el.className = 'edu-feed__item';
        el.setAttribute('data-notif-id', n.id);
        el.setAttribute('data-cat', 'System');
        el.style.background = 'var(--primary-50);border-radius:var(--radius);padding-inline:.6rem';
        el.innerHTML =
          '<span class="edu-stat__icon edu-stat__icon--neutral flex-none" aria-hidden="true" style="width:38px;height:38px;font-size:1rem"><i class="bi bi-info-circle"></i></span>'
          + '<div class="edu-feed__body">'
          + '<div class="d-flex align-items-center gap-2 flex-wrap mb-1">'
          + '<span class="edu-feed__title mb-0">' + esc(n.title) + '</span>'
          + eduBadgeJs('System')
          + eduBadgeJs('Unread', 'primary')
          + '</div>'
          + (n.message ? '<div class="small" style="color:var(--text-secondary)">' + esc(n.message) + '</div>' : '')
          + '<div class="edu-feed__meta">just now</div></div>'
          + '<div class="d-flex gap-1 flex-none">'
          + '<a href="' + esc(n.link || '/notifications') + '" class="btn btn-sm btn-outline-primary">Open</a>'
          + '</div>';
        list.insertBefore(el, list.firstChild);
      });

      if (added > 0) {
        if (barEmpty) barEmpty.remove();
        if (catEmpty) catEmpty.classList.add('d-none');
        list.classList.remove('d-none');
        EduLive.syncNow();
      }
    });
  }

  function eduBadgeJs(label, tone) {
    return '<span class="edu-badge edu-badge--' + (tone || 'neutral') + '"><span>' + esc(label) + '</span></span>';
  }

  if (window.EduLive) { boot(); return; }
  var tries = 0;
  var iv = setInterval(function () {
    if (window.EduLive) { clearInterval(iv); boot(); }
    else if (++tries > 100) { clearInterval(iv); }
  }, 100);
})();
</script>
