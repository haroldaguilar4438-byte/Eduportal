<?php
/**
 * Admin: per-user security overview (spec §32).
 *
 * Non-destructive: both revoke forms keep their routes
 * (/admin/users/{id}/devices/revoke, /admin/users/{id}/sessions/revoke), CSRF
 * tokens and confirm() guards. The MFA ON/OFF logic, the revoked/trusted and
 * MFA-skip badges, and the $target / $mfa / $devices / $sessions / $events
 * contract are unchanged. The reassurance copy is kept verbatim.
 *
 * @var array      $target
 * @var array|null $mfa
 * @var array      $devices
 * @var array      $sessions
 * @var array      $events
 */
$target   = $target ?? [];
$mfa      = $mfa ?? null;
$devices  = $devices ?? [];
$sessions = $sessions ?? [];
$events   = $events ?? [];
$full     = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));
$mfaOn    = !empty($mfa['is_enabled']);
$activeDevices = 0;
foreach ($devices as $d) {
    if (empty($d['revoked_at'])) { $activeDevices++; }
}
$activeSessions = 0;
foreach ($sessions as $s) {
    if (empty($s['revoked_at'])) { $activeSessions++; }
}
?>

<?= edu_breadcrumb([
    ['label' => 'Accounts', 'url' => url('/admin/users')],
    ['label' => $full ?: ($target['email'] ?? '')],
    ['label' => 'Security'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Account security',
    'title'       => $full ?: ($target['email'] ?? 'Security'),
    'description' => 'Admins can view status and revoke access — never secrets, codes, or tokens.',
    'icon'        => 'bi-shield-lock',
    'actions'     => edu_badge($mfaOn ? 'MFA ON' : 'MFA OFF', $mfaOn ? 'success' : 'neutral', $mfaOn ? 'bi-shield-check' : 'bi-shield-exclamation'),
]) ?>

<div class="row g-3">
    <!-- Identity -->
    <div class="col-12 col-md-4">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-person"></i> Account</h2>
                <?= edu_status_badge((string) ($target['status'] ?? '')) ?>
            </div>
            <div class="edu-card__body">
                <div class="fw-semibold"><?= e($full) ?></div>
                <div class="small text-muted"><?= e($target['email'] ?? '') ?></div>
                <div class="mt-2"><?= edu_badge((string) ($target['role_name'] ?? ''), 'primary', 'bi-person-badge') ?></div>
            </div>
        </div>
    </div>

    <!-- Trusted devices -->
    <div class="col-12 col-md-4">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-laptop"></i> Trusted devices</h2>
                <?= edu_badge((string) $activeDevices . ' active', 'neutral') ?>
            </div>
            <div class="edu-card__body">
                <?php if ($devices === []): ?>
                    <?= edu_empty_state('None', 'No trusted devices for this account.', ['icon' => 'bi-laptop', 'compact' => true]) ?>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($devices as $d): ?>
                        <li class="list-group-item px-0 small">
                            <strong><?= e($d['device_name']) ?></strong> · <?= e($d['ip_address'] ?? '') ?><br>
                            <span class="text-muted">
                                Last used <?= e($d['last_used_at'] ?? '—') ?> ·
                                <?= !empty($d['revoked_at'])
                                    ? edu_badge('revoked', 'neutral')
                                    : edu_badge('trusted', 'success') ?>
                                <?php if (!empty($d['mfa_exempt_until']) && strtotime((string) $d['mfa_exempt_until']) > time()): ?>
                                    <?= edu_badge('MFA skip', 'info', 'bi-lightning') ?>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form method="post" action="<?= e(url('/admin/users/' . (int) $target['id'] . '/devices/revoke')) ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revoke ALL trusted devices for this account?')">
                        <i class="bi bi-x-circle"></i> Revoke all devices
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Sessions -->
    <div class="col-12 col-md-4">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-hdd-network"></i> Active sessions</h2>
                <?= edu_badge((string) $activeSessions . ' active', 'neutral') ?>
            </div>
            <div class="edu-card__body">
                <?php if ($sessions === []): ?>
                    <?= edu_empty_state('None recorded', 'No sessions stored for this account.', ['icon' => 'bi-hdd-network', 'compact' => true]) ?>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($sessions as $s): ?>
                        <li class="list-group-item px-0 small">
                            <?= e(mb_substr((string) ($s['user_agent'] ?? ''), 0, 60)) ?> · <?= e($s['ip_address'] ?? '') ?><br>
                            <span class="text-muted">
                                Last activity <?= e($s['last_activity'] ?? '') ?> ·
                                <?= !empty($s['revoked_at'])
                                    ? edu_badge('revoked', 'neutral')
                                    : edu_badge('active', 'success') ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form method="post" action="<?= e(url('/admin/users/' . (int) $target['id'] . '/sessions/revoke')) ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revoke ALL sessions for this account? They will be signed out.')">
                        <i class="bi bi-box-arrow-right"></i> Revoke all sessions
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?= edu_callout(
    'All actions here are audit-logged. You cannot secretly sign in as this user.',
    ['tone' => 'info', 'icon' => 'bi-info-circle', 'title' => null, 'compact' => true]
) ?>

<!-- Security events -->
<div class="edu-card mt-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-clock-history"></i> Recent security events</h2>
        <?= edu_badge((string) count($events), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($events === []): ?>
            <?= edu_empty_state('No events', 'Nothing has been recorded for this account.', ['icon' => 'bi-clock-history', 'compact' => true]) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Event</th><th>IP</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($events as $e): ?>
                        <tr>
                            <td class="small"><?= e($e['action']) ?></td>
                            <td class="small text-muted"><?= e($e['ip_address'] ?? '') ?></td>
                            <td class="small text-muted"><?= e($e['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
