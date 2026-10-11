<?php
/**
 * Security settings — MFA, recovery codes, trusted devices, sessions, activity.
 * @var bool $mfaEnabled @var string|null $mfaSince @var int $recoveryLeft
 * @var array $devices @var array $sessions @var array $activity
 */
$mfaEnabled = $mfaEnabled ?? false;
$mfaSince = $mfaSince ?? null;
$recoveryLeft = (int) ($recoveryLeft ?? 0);
$devices = $devices ?? [];
$sessions = $sessions ?? [];
$activity = $activity ?? [];
$actionLabel = static function (string $a): string {
    return match (true) {
        str_starts_with($a, 'mfa.') => 'MFA',
        str_starts_with($a, 'device.') => 'Device',
        str_starts_with($a, 'session.') => 'Session',
        str_starts_with($a, 'login') => 'Login',
        str_starts_with($a, 'password.') => 'Password',
        $a === 'logout' => 'Logout',
        default => 'Security',
    };
};
?>
<?= edu_page_header([
    'eyebrow'     => 'Account protection',
    'title'       => 'Security settings',
    'description' => 'Two-factor authentication, trusted devices, active sessions and recent security activity.',
    'actions'     => '<a href="' . e(url('/profile')) . '" class="btn btn-light btn-sm"><i class="bi bi-person me-1"></i> Profile</a>'
                   . '<a href="' . e(url('/dashboard')) . '" class="btn btn-primary btn-sm"><i class="bi bi-grid-1x2 me-1"></i> Dashboard</a>',
]) ?>

<?php
/* Security posture summary — derived from the same values the cards below use. */
$deviceCount = count($devices ?? []);
$sessionCount = count($sessions ?? []);
$posture = $mfaEnabled ? 'Strong' : 'Needs attention';
$postureTone = $mfaEnabled ? 'success' : 'warning';
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Security posture', 'value' => '<span style="font-size:1.2rem">' . e($posture) . '</span>',
        'icon' => $mfaEnabled ? 'bi-shield-check' : 'bi-shield-exclamation', 'tone' => $postureTone,
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Two-factor auth', 'value' => $mfaEnabled ? 'On' : 'Off',
        'icon' => 'bi-phone', 'tone' => $mfaEnabled ? 'success' : 'warning',
        'meta'  => '<span>' . $recoveryLeft . ' recovery code' . ($recoveryLeft === 1 ? '' : 's') . ' left</span>',
        'href'  => url('/mfa/setup'),
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Trusted devices', 'value' => (string) $deviceCount,
        'icon' => 'bi-laptop', 'tone' => 'info',
    ]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card([
        'label' => 'Active sessions', 'value' => (string) $sessionCount,
        'icon' => 'bi-window-stack', 'tone' => 'primary',
    ]) ?></div>
</div>

<?php if (!$mfaEnabled): ?>
  <?= edu_callout(
        'Your account is not protected by two-factor authentication. Enable MFA to require a one-time code at every sign-in.',
        ['tone' => 'warning', 'icon' => 'bi-shield-exclamation', 'title' => 'We strongly recommend MFA']
    ) ?>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12">
    <!-- MFA status -->
    <div class="edu-card <?= $mfaEnabled ? 'edu-card--primary' : '' ?> mb-4">
      <div class="edu-card__body">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="edu-stat__icon <?= $mfaEnabled ? 'edu-stat__icon--success' : 'edu-stat__icon--warning' ?> flex-none" style="width:48px;height:48px;font-size:1.3rem" aria-hidden="true">
              <i class="bi bi-phone"></i>
            </span>
            <div class="flex-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-bold" style="color:var(--primary-900)">Authenticator app (MFA)</span>
                    <?php if ($mfaEnabled): ?>
                        <?= edu_badge('Enabled', 'success', 'bi-check-lg') ?>
                    <?php else: ?>
                        <?= edu_badge('Not enabled', 'warning', 'bi-exclamation-triangle-fill') ?>
                    <?php endif; ?>
                </div>
                <div class="small mt-1" style="color:var(--text-tertiary)">
                    <?php if ($mfaEnabled): ?>
                        Active<?= $mfaSince ? ' since ' . e(date('M j, Y', strtotime((string) $mfaSince))) : '' ?>. Your account is protected with MFA.
                        <?= $recoveryLeft ?> recovery code(s) left.
                    <?php else: ?>
                        Not protected yet. Enable MFA to require a code at every sign-in.
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if (!$mfaEnabled): ?>
                    <a href="<?= e(url('/mfa/setup')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-qr-code me-1"></i> Set up MFA</a>
                <?php else: ?>
                    <a href="<?= e(url('/mfa/setup')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Change MFA</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($mfaEnabled): ?>
        <hr>
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <h2 class="h6 small fw-bold"><i class="bi bi-key"></i> Recovery codes (<?= $recoveryLeft ?> left)</h2>
                <p class="small text-muted">Regenerating invalidates the old set immediately. Requires your password.</p>
                <form method="post" action="<?= e(url('/security/mfa/codes')) ?>" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="password" name="password" class="form-control form-control-sm" placeholder="Current password" required autocomplete="current-password" aria-label="Current password">
                    <button type="submit" class="btn btn-outline-warning btn-sm text-nowrap"><i class="bi bi-arrow-clockwise"></i> New codes</button>
                </form>
            </div>
            <div class="col-12 col-md-6">
                <h2 class="h6 small fw-bold text-danger"><i class="bi bi-shield-x"></i> Disable MFA</h2>
                <p class="small text-muted">Your account becomes password-only. Requires your password and sends an alert email.</p>
                <form method="post" action="<?= e(url('/security/mfa/disable')) ?>" class="d-flex gap-2" onsubmit="return confirm('Disable MFA? Your account will be less protected.');">
                    <?= csrf_field() ?>
                    <input type="password" name="password" class="form-control form-control-sm" placeholder="Current password" required autocomplete="current-password" aria-label="Current password">
                    <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">Disable</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /edu-card__body -->
    </div>

    <!-- Trusted devices -->
    <div class="card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div><h2 class="h6 mb-0"><i class="bi bi-laptop"></i> Trusted devices</h2>
            <p class="small text-muted mb-0">Devices that skip email approval. Revoke any you don't recognize.</p></div>
            <form method="post" action="<?= e(url('/security/devices/remember')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-clock-history"></i> Remember this device (skip MFA 30 days)</button></form>
        </div>
        <hr>
        <?php if ($devices === []): ?>
            <div class="alert alert-light border small mb-0">No trusted devices yet — your next verified sign-in will add this browser.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Device</th><th>Last used</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            <?php foreach ($devices as $d): ?>
                <?php $rev = !empty($d['revoked']); $exp = !empty($d['expired']); $exempt = !empty($d['mfa_exempt']); ?>
                <tr>
                    <td><i class="bi bi-<?= $rev ? 'x-circle text-danger' : 'check-circle text-success' ?>"></i> <strong><?= e($d['device_name'] ?? 'Device') ?></strong><br><span class="small text-muted"><?= e($d['ip_address'] ?? '') ?></span>
                        <?php if ($exempt): ?><br><span class="badge bg-info text-dark"><i class="bi bi-clock-history"></i> Skips MFA until <?= e(date('M j, Y', strtotime($d['mfa_exempt_until']))) ?></span><?php endif; ?></td>
                    <td class="small"><?= !empty($d['last_used_at']) ? e(date('M j, Y g:i A', strtotime($d['last_used_at']))) : '—' ?></td>
                    <td><?php if ($rev): ?><span class="badge bg-secondary">Revoked</span><?php elseif ($exp): ?><span class="badge bg-warning text-dark">Expired</span><?php else: ?><span class="badge bg-success">Trusted</span><?php endif; ?></td>
                    <td class="text-end"><?php if (!$rev && !$exp): ?>
                        <?php if ($exempt): ?>
                            <form method="post" action="<?= e(url('/security/devices/' . (int) $d['id'] . '/forget')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-secondary">Ask code again</button></form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/security/devices/' . (int) $d['id'] . '/revoke')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button></form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- Active sessions -->
    <div class="card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div><h2 class="h6 mb-0"><i class="bi bi-hdd-network"></i> Active sessions</h2>
            <p class="small text-muted mb-0">Where you are signed in right now.</p></div>
            <form method="post" action="<?= e(url('/security/sessions/revoke-others')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-right"></i> Log out of all other devices</button></form>
        </div>
        <hr>
        <?php if ($sessions === []): ?>
            <div class="alert alert-light border small mb-0">Only this session is active.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Session</th><th>Last activity</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr>
                    <td><?= !empty($s['is_current']) ? '<span class="badge bg-primary">This device</span>' : '<span class="badge bg-light text-dark border">Other</span>' ?><br>
                        <span class="small text-muted"><?= e(mb_substr((string) ($s['user_agent'] ?? ''), 0, 60)) ?> · <?= e($s['ip_address'] ?? '') ?></span></td>
                    <td class="small"><?= !empty($s['last_activity']) ? e(date('M j, Y g:i A', strtotime($s['last_activity']))) : '—' ?></td>
                    <td class="text-end"><?php if (empty($s['is_current'])): ?>
                        <form method="post" action="<?= e(url('/security/sessions/' . (int) $s['id'] . '/revoke')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button></form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- Recent activity -->
    <div class="card p-4 mb-4">
        <h2 class="h6"><i class="bi bi-clock-history"></i> Recent security activity</h2>
        <?php if ($activity === []): ?>
            <div class="alert alert-light border small mb-0">No security events yet.</div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($activity as $a): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span><span class="badge bg-light text-dark border me-1"><?= e($actionLabel((string) ($a['action'] ?? ''))) ?></span> <span class="small"><?= e((string) ($a['action'] ?? '')) ?></span><br>
                    <span class="small text-muted"><?= e($a['ip_address'] ?? '') ?></span></span>
                    <span class="small text-muted text-nowrap"><?= e(isset($a['created_at']) ? date('M j, g:i A', strtotime($a['created_at'])) : '') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    </div><!-- /col-12 -->
</div><!-- /row -->

