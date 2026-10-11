<?php
/**
 * Admin — user management (spec §25).
 *
 * Non-destructive: $users, $stats, $byRole, $q, $roleFilter and $statusFilter are
 * used exactly as before, the GET filter form keeps its field names (q, role,
 * status) and target, and EVERY action form keeps its action URL, CSRF token,
 * visibility condition and confirmation prompt — including the self-protection
 * rule (you cannot act on yourself).
 *
 * Changes: a proper data table with sticky headers and phone-card fallback, and
 * a right-side detail drawer so previewing an account no longer means leaving
 * the list. The drawer is populated from data already present in each row, so
 * no new endpoint or query is introduced.
 *
 * @var array  $users
 * @var array  $stats
 * @var array  $byRole
 * @var string $q
 * @var string $roleFilter
 * @var string $statusFilter
 */
$users = $users ?? [];
$stats = $stats ?? [];
$byRole = $byRole ?? [];
$selfId = (int) \App\Core\Auth::id();
?>

<?= edu_page_header([
    'eyebrow'     => 'Directory',
    'title'       => 'All accounts',
    'description' => 'Live data from the users table joined to roles. Select any row to preview it without leaving the list.',
    'actions'     => '<a href="' . e(url('/admin/backups')) . '" class="btn btn-light btn-sm"><i class="bi bi-archive me-1"></i> Backups</a>'
                   . '<a href="' . e(url('/admin/teachers')) . '" class="btn btn-primary btn-sm"><i class="bi bi-person-badge me-1"></i> Teachers</a>',
]) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Total accounts', 'value' => (string) (int) ($stats['total'] ?? count($users)), 'icon' => 'bi-people-fill', 'tone' => 'primary']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Pending verification', 'value' => (string) (int) ($stats['pending'] ?? 0), 'icon' => 'bi-hourglass-split', 'tone' => 'warning', 'meta' => '<span>Email not yet proven</span>']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Verified', 'value' => (string) (int) ($stats['verified'] ?? 0), 'icon' => 'bi-patch-check-fill', 'tone' => 'success']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Active', 'value' => (string) (int) ($stats['active'] ?? 0), 'icon' => 'bi-check2-circle', 'tone' => 'info']) ?></div>
</div>

<?php if ($byRole): ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($byRole as $br): ?>
      <?= edu_badge(e($br['name']) . ': ' . (int) $br['c'], 'neutral', 'bi-person') ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Filters -->
<form method="get" action="<?= e(url('/admin/users')) ?>" class="edu-filterbar" aria-label="Filter accounts">
    <div class="edu-filterbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <label class="visually-hidden" for="userSearch">Search accounts</label>
        <input name="q" id="userSearch" value="<?= e($q) ?>" class="form-control" placeholder="Search name or email&hellip;">
    </div>
    <div class="edu-filterbar__group">
        <label class="visually-hidden" for="userRole">Role</label>
        <select name="role" id="userRole" class="form-select">
            <option value="">All roles</option>
            <?php foreach (['admin', 'teacher', 'student', 'parent'] as $r): ?>
                <option value="<?= e($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="userStatus">Status</label>
        <select name="status" id="userStatus" class="form-select">
            <option value="">All status</option>
            <?php foreach (['active', 'pending_verification', 'on_hold', 'banned', 'suspended', 'locked', 'archived'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filter</button>
        <a href="<?= e(url('/admin/users')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
    </div>
</form>

<?php if ($users === []): ?>
  <?= edu_empty_state(
        'No accounts match your filter',
        'Try a different search term, role or status.',
        ['icon' => 'bi-person-x', 'actions' => '<a href="' . e(url('/admin/users')) . '" class="btn btn-primary btn-sm">Clear filters</a>']
    ) ?>
<?php else: ?>

  <div class="edu-card">
    <div class="edu-card__head">
      <h2 class="edu-card__title"><i class="bi bi-table"></i> Accounts database table</h2>
      <span class="small" style="color:var(--text-tertiary)">Showing <?= count($users) ?> of 200 latest</span>
    </div>
    <div class="edu-card__body">
      <div class="edu-table-wrap" style="box-shadow:none;border:0">
        <div class="edu-table-scroll">
          <table class="edu-table edu-table--cards" id="userTable">
            <thead>
              <tr>
                <th style="width:52px">#</th>
                <th>Name</th>
                <th>Email / Phone</th>
                <th>Role</th>
                <th>Status</th>
                <th>Verified</th>
                <th>MFA</th>
                <th>Last login</th>
                <th>Created</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $full   = trim(($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? '') . ' ' . ($u['last_name'] ?? '') . ' ' . ($u['suffix'] ?? ''));
                $isSelf = (int) $u['id'] === $selfId;
                $locked = !empty($u['locked_until']) && strtotime((string) $u['locked_until']) > time();
                $failed = (int) ($u['failed_login_attempts'] ?? 0);
                $mfaOn  = !empty($u['mfa_enabled']);
                $verified = (int) $u['is_email_verified'] === 1;
                $lastLogin = !empty($u['last_login_at']) ? date('M j, Y g:ia', strtotime((string) $u['last_login_at'])) : 'never';
                $created = date('M j, Y', strtotime((string) $u['created_at'])); ?>
              <tr<?= $isSelf ? ' class="is-selected"' : '' ?>
                  data-user-row
                  data-id="<?= (int) $u['id'] ?>"
                  data-name="<?= e($full ?: '—') ?>"
                  data-email="<?= e($u['email']) ?>"
                  data-phone="<?= e($u['phone'] ?: '—') ?>"
                  data-role="<?= e($u['role_name']) ?>"
                  data-status="<?= e($u['status']) ?>"
                  data-verified="<?= $verified ? 'Yes — ' . ($u['verified_at'] ? date('M j, Y', strtotime((string) $u['verified_at'])) : 'verified') : 'Pending' ?>"
                  data-mfa="<?= $mfaOn ? 'On' : 'Off' ?>"
                  data-last="<?= e($lastLogin) ?>"
                  data-created="<?= e($created) ?>"
                  data-self="<?= $isSelf ? '1' : '0' ?>">

                <td data-label="#" class="small" style="color:var(--text-tertiary)"><?= (int) $u['id'] ?></td>

                <td data-label="Name">
                  <span class="fw-semibold"><?= e($full ?: '—') ?></span>
                  <?php if ($isSelf): ?>
                    <?= edu_badge('You', 'solid') ?>
                  <?php endif; ?>
                </td>

                <td data-label="Email / Phone">
                  <div class="small"><?= e($u['email']) ?></div>
                  <div class="small" style="color:var(--text-tertiary)"><?= e($u['phone'] ?: '—') ?></div>
                </td>

                <td data-label="Role"><?= edu_badge(e($u['role_name']), 'primary', 'bi-person-badge') ?></td>
                <td data-label="Status"><?= edu_status_badge((string) $u['status']) ?></td>

                <td data-label="Verified">
                  <?php if ($verified): ?>
                    <?= edu_badge('Yes', 'success', 'bi-check-circle-fill') ?>
                    <div class="small" style="color:var(--text-tertiary)"><?= e($u['verified_at'] ? date('M j, Y', strtotime((string) $u['verified_at'])) : '') ?></div>
                  <?php else: ?>
                    <?= edu_badge('Pending', 'warning', 'bi-hourglass-split') ?>
                  <?php endif; ?>
                </td>

                <td data-label="MFA">
                  <?= $mfaOn
                      ? edu_badge('On', 'success', 'bi-shield-check')
                      : edu_badge('Off', 'neutral', 'bi-shield') ?>
                </td>

                <td data-label="Last login" class="small" style="color:var(--text-tertiary)">
                  <?= e($lastLogin) ?>
                  <?php if ($locked): ?>
                    <div><?= edu_badge('Locked', 'danger', 'bi-lock-fill') ?></div>
                  <?php elseif ($failed > 0): ?>
                    <div>Failed: <?= $failed ?></div>
                  <?php endif; ?>
                </td>

                <td data-label="Created" class="small" style="color:var(--text-tertiary)"><?= e($created) ?></td>

                <td data-label="Actions" class="edu-table__actions-cell">
                  <?php if ($isSelf): ?>
                    <span class="small" style="color:var(--text-tertiary)">&mdash;</span>
                  <?php else: ?>
                    <div class="edu-table__actions">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-user-preview
                              aria-label="Preview <?= e($full ?: 'account') ?>">
                        <i class="bi bi-eye"></i>
                      </button>
                      <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Manage</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= e(url('/admin/users/' . $u['id'] . '/security')) ?>"><i class="bi bi-shield-lock me-2"></i> Security (MFA / devices / sessions)</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (!in_array($u['status'], ['on_hold', 'suspended'], true)): ?>
                                <li><form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/hold')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="dropdown-item" onclick="return confirm('Put <?= e($full) ?> On Hold? They will be blocked from signing in.')"><i class="bi bi-pause-circle me-2"></i> On Hold</button></form></li>
                            <?php endif; ?>
                            <?php if ($u['status'] !== 'banned' && $u['status'] !== 'locked'): ?>
                                <li><form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/ban')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger" onclick="return confirm('Ban <?= e($full) ?>? They will be locked out permanently until restored.')"><i class="bi bi-slash-circle me-2"></i> Ban</button></form></li>
                            <?php endif; ?>
                            <?php if ($verified === 1): ?>
                                <li><form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/revoke')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="dropdown-item" onclick="return confirm('Revoke verification for <?= e($full) ?>? They must re-verify email.')"><i class="bi bi-shield-x me-2"></i> Revoke (force re-verify)</button></form></li>
                            <?php endif; ?>
                            <?php if (in_array($u['status'], ['banned', 'locked', 'on_hold', 'suspended', 'archived', 'pending_verification'], true)): ?>
                                <li><form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/restore')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="dropdown-item text-success" onclick="return confirm('Restore <?= e($full) ?> to Active?')"><i class="bi bi-arrow-counterclockwise me-2"></i> Restore to Active</button></form></li>
                            <?php endif; ?>
                            <?php if ($verified === 0): ?>
                                <li><form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/resend-verification')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="dropdown-item"><i class="bi bi-envelope me-2"></i> Resend verification email</button></form></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/reset')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="dropdown-item text-warning" onclick="return confirm('RESET <?= e($full) ?> to default? This unlocks the account, signs out all devices, turns OFF MFA, and sets a new temporary password. Student records, grades, enrollments, and payments are NOT touched.')"><i class="bi bi-arrow-counterclockwise me-2"></i> Reset to default</button></form></li>
                            <li><form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/factory-reset')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger" onclick="return confirm('FACTORY RESET <?= e($full) ?> (<?= e($u['email']) ?>)? This wipes ALL their enrollments, payments, grades, permits, clearances, documents, subjects, and sessions — the login stays with a new temporary password and must re-verify email. A backup is captured first. Linked children’s own records survive. Continue?')"><i class="bi bi-stars me-2"></i> Reset to fresh account</button></form></li>
                            <li><form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/remove')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger" onclick="return confirm('PERMANENTLY REMOVE <?= e($full) ?> (<?= e($u['email']) ?>)? A safety backup is captured first and files are stashed (not deleted) — restore from Backups if this is a mistake. Their account, student/teacher records and memberships will be removed.')"><i class="bi bi-trash me-2"></i> Remove (delete)</button></form></li>
                        </ul>
                      </div>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?= edu_callout(
      '<strong>On Hold</strong> = suspended (temporary block) &middot; <strong>Ban</strong> = locked/banned (permanent block) &middot; <strong>Revoke</strong> = forces re-verification &middot; <strong>Reset to default</strong> = unlock + sign out everywhere + MFA off + new temporary password (records untouched) &middot; <strong>Reset to fresh account</strong> = wipes all their data but keeps the login (backup captured first; linked children&rsquo;s records survive) &middot; <strong>Remove</strong> = hard delete all related data &middot; <strong>Restore</strong> = back to Active. You cannot act on yourself; the last active admin is protected.',
      ['tone' => 'info', 'icon' => 'bi-info-circle-fill', 'title' => 'What each action does']
  ) ?>
<?php endif; ?>

<!-- ================= Right-side detail drawer ================= -->
<div id="userDrawerBackdrop" class="edu-drawer-backdrop"></div>
<aside id="userDrawer" class="edu-drawer" role="dialog" aria-modal="true" aria-labelledby="userDrawerName" aria-hidden="true">
    <div class="edu-drawer__head">
        <h2 id="userDrawerName" class="edu-card__title mb-0"><i class="bi bi-person-circle"></i> Account</h2>
        <button type="button" id="userDrawerClose" class="edu-topbar-icon" aria-label="Close preview">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <div class="edu-drawer__body">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="edu-avatar edu-avatar--lg edu-avatar--fallback" id="userDrawerInitials" aria-hidden="true">?</span>
            <div class="min-w-0">
                <div class="fw-bold" id="userDrawerFull" style="color:var(--primary-900)">&mdash;</div>
                <div class="small" style="color:var(--text-tertiary)" id="userDrawerEmail">&mdash;</div>
            </div>
        </div>
        <div id="userDrawerBadges" class="d-flex flex-wrap gap-2 mb-3"></div>
        <?= edu_dl([
            'User ID'    => '<span id="userDrawerId">&mdash;</span>',
            'Phone'      => '<span id="userDrawerPhone">&mdash;</span>',
            'Email verified' => '<span id="userDrawerVerified">&mdash;</span>',
            'MFA'        => '<span id="userDrawerMfa">&mdash;</span>',
            'Last login' => '<span id="userDrawerLast">&mdash;</span>',
            'Created'    => '<span id="userDrawerCreated">&mdash;</span>',
        ]) ?>
        <div class="mt-3" id="userDrawerSelfNote"></div>
    </div>
    <div class="edu-drawer__foot">
        <a id="userDrawerSecurity" href="#" class="btn btn-primary btn-sm flex-fill" data-base="<?= e(url('/admin/users/')) ?>">
            <i class="bi bi-shield-lock me-1"></i> Open security
        </a>
        <button type="button" id="userDrawerClose2" class="btn btn-outline-secondary btn-sm">Close</button>
    </div>
</aside>

<script>
(function () {
  'use strict';
  var drawer = document.getElementById('userDrawer');
  var backdrop = document.getElementById('userDrawerBackdrop');
  if (!drawer) return;

  var lastFocus = null;

  function set(id, text) { var el = document.getElementById(id); if (el) el.textContent = text || '—'; }

  function badge(text, tone, icon) {
    return '<span class="edu-badge edu-badge--' + tone + '"><i class="bi bi-' + icon + '" aria-hidden="true"></i><span>'
      + text.replace(/[<>&]/g, '') + '</span></span>';
  }

  function statusTone(s) {
    return ({
      active: 'success', pending_verification: 'warning', on_hold: 'neutral',
      banned: 'danger', suspended: 'neutral', locked: 'danger', archived: 'neutral'
    })[s] || 'neutral';
  }

  function open(row) {
    var d = row.dataset;
    set('userDrawerFull', d.name);
    set('userDrawerEmail', d.email);
    set('userDrawerId', d.id);
    set('userDrawerPhone', d.phone);
    set('userDrawerVerified', d.verified);
    set('userDrawerMfa', d.mfa);
    set('userDrawerLast', d.last);
    set('userDrawerCreated', d.created);

    var init = (d.name || '?').trim().split(/\s+/).map(function (p) { return p.charAt(0); })
      .slice(0, 2).join('').toUpperCase() || '?';
    set('userDrawerInitials', init);

    var badges = document.getElementById('userDrawerBadges');
    if (badges) {
      badges.innerHTML =
        badge(d.role, 'primary', 'person-badge')
        + badge(d.status.replace(/_/g, ' '), statusTone(d.status), 'circle-fill')
        + (d.verified.indexOf('Pending') === 0 ? badge('Email pending', 'warning', 'hourglass-split') : badge('Email verified', 'success', 'patch-check-fill'))
        + (d.mfa === 'On' ? badge('MFA on', 'success', 'shield-check') : badge('MFA off', 'neutral', 'shield'))
        + (d.self === '1' ? badge('This is you', 'solid', 'person-fill') : '');
    }

    var selfNote = document.getElementById('userDrawerSelfNote');
    if (selfNote) {
      selfNote.innerHTML = d.self === '1'
        ? '<div class="edu-callout edu-callout--warning"><i class="bi bi-exclamation-triangle-fill edu-callout__icon" aria-hidden="true"></i><div>This is your own account. Management actions are disabled to prevent locking yourself out.</div></div>'
        : '';
    }

    var sec = document.getElementById('userDrawerSecurity');
    if (sec && sec.dataset.base) {
      sec.setAttribute('href', sec.dataset.base + encodeURIComponent(d.id) + '/security');
    }

    lastFocus = document.activeElement;
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    if (backdrop) backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    var closeBtn = document.getElementById('userDrawerClose');
    if (closeBtn) closeBtn.focus();
  }

  function close() {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (backdrop) backdrop.classList.remove('is-open');
    document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  document.querySelectorAll('[data-user-preview]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = btn.closest('[data-user-row]');
      if (row) open(row);
    });
  });

  /* Clicking the row itself (but not a link, button or form) previews too. */
  document.querySelectorAll('[data-user-row]').forEach(function (row) {
    row.addEventListener('click', function (ev) {
      if (ev.target.closest('a, button, form, input, select, textarea, .dropdown-menu')) return;
      open(row);
    });
  });

  var c1 = document.getElementById('userDrawerClose');
  var c2 = document.getElementById('userDrawerClose2');
  if (c1) c1.addEventListener('click', close);
  if (c2) c2.addEventListener('click', close);
  if (backdrop) backdrop.addEventListener('click', close);
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && drawer.classList.contains('is-open')) close();
  });
})();
</script>
