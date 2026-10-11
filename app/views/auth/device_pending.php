<?php
/** @var string $maskedEmail @var string $deviceName @var int $cooldown */
$maskedEmail = $maskedEmail ?? 'your email';
$deviceName = $deviceName ?? '';
$cooldown = (int) ($cooldown ?? 0);
?>
<?php
$authHeading = 'Check your email';
$authSubheading = 'This device is not recognized, so we need to confirm it is really you before signing in.';
$authStep = 2; /* Account -> Email -> Security -> Complete */

/* Optional per-step hints; layout_auth renders the stepper itself. */
$authSteps = [
    ['label' => 'Account',  'hint' => 'Signed in'],
    ['label' => 'Email',    'hint' => 'Approve now'],
    ['label' => 'Security', 'hint' => 'Enter code'],
    ['label' => 'Complete', 'hint' => 'Signed in'],
];
?>
<div class="text-center mb-4">
    <span class="edu-stat__icon edu-stat__icon--warning mx-auto" style="width:56px;height:56px;font-size:1.5rem" aria-hidden="true">
        <i class="bi bi-laptop"></i>
    </span>
</div>

<?= edu_callout(
    '<p class="mb-1">We sent a verification email to <strong>' . e($maskedEmail) . '</strong>.</p>'
    . ($deviceName !== '' ? '<p class="mb-1">Device: <strong>' . e($deviceName) . '</strong></p>' : '')
    . '<p class="mb-0">Click <strong>Approve This Device</strong> in that email (expires in 15 minutes, works once). Approved on your phone? This page moves forward on its own &mdash; or tap Continue below.</p>',
    ['tone' => 'warning', 'icon' => 'bi-envelope-check', 'title' => 'Check your inbox']
) ?>

<div id="deviceGoneBanner" class="d-none mb-3">
    <?= edu_callout(
        'This sign-in was finished or expired on another device.',
        ['tone' => 'info', 'icon' => 'bi-info-circle-fill', 'title' => 'Sign-in no longer pending']
    ) ?>
    <a href="<?= e(url('/login')) ?>" class="btn btn-primary btn-sm w-100">Sign in again</a>
</div>

<a href="<?= e(url('/login/device')) ?>" class="btn btn-primary w-100 py-2 mb-3 text-center">
    <span id="deviceContinueLabel">I&rsquo;ve approved it &mdash; Continue</span>
</a>

<form method="post" action="<?= e(url('/login/device/resend')) ?>" class="mb-3">
    <?= csrf_field() ?>
    <button type="submit" id="btnDeviceResend" <?= $cooldown > 0 ? 'disabled' : '' ?>
            class="btn btn-outline-secondary w-100 py-2<?= $cooldown > 0 ? ' disabled' : '' ?>">
        <i class="bi bi-arrow-clockwise"></i>
        <span id="deviceResendLabel"><?= $cooldown > 0 ? 'Resend in ' . $cooldown . 's' : 'Resend approval email' ?></span>
    </button>
</form>

<form method="post" action="<?= e(url('/login/cancel')) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-light w-100 py-2">Cancel sign-in</button>
</form>

<p class="small text-center mt-4 mb-0" style="color:var(--text-tertiary)">
    Didn&rsquo;t try to sign in? Ignore the email &mdash; no access was granted.
</p>

<script>
(function(){
    var btn = document.getElementById('btnDeviceResend');
    var label = document.getElementById('deviceResendLabel');
    var left = <?= (int) $cooldown ?>;
    if (btn && left > 0) {
        var t = setInterval(function(){
            left--;
            label.textContent = 'Resend in ' + left + 's';
            if (left <= 0) { clearInterval(t); btn.disabled = false; btn.classList.remove('disabled'); label.textContent = 'Resend approval email'; }
        }, 1000);
    }

    // Auto-advance: if the user approved on another device (e.g. phone),
    // this page moves to the next step without a manual refresh.
    var statusUrl = <?= json_encode(url('/login/device/status'), JSON_UNESCAPED_SLASHES) ?>;
    var verifyUrl = <?= json_encode(url('/mfa/verify'), JSON_UNESCAPED_SLASHES) ?>;
    var setupUrl = <?= json_encode(url('/mfa/setup'), JSON_UNESCAPED_SLASHES) ?>;
    var banner = document.getElementById('deviceGoneBanner');
    var contLabel = document.getElementById('deviceContinueLabel');
    var poll = setInterval(function(){
        fetch(statusUrl, { credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(j){
            if (!j) return;
            if (j.ok && j.stage === 'mfa') { clearInterval(poll); if (contLabel) contLabel.textContent = 'Approved! Continuing…'; window.location.href = verifyUrl; }
            else if (j.ok && j.stage === 'setup') { clearInterval(poll); if (contLabel) contLabel.textContent = 'Approved! Continuing…'; window.location.href = setupUrl; }
            else if (!j.ok && j.gone) { clearInterval(poll); if (banner) banner.classList.remove('d-none'); }
        }).catch(function(){});
    }, 3000);
})();
</script>

<script>
(function(){
    var btn = document.getElementById('btnDeviceResend');
    var label = document.getElementById('deviceResendLabel');
    var left = <?= (int) $cooldown ?>;
    if (btn && left > 0) {
        var t = setInterval(function(){
            left--;
            label.textContent = 'Resend in ' + left + 's';
            if (left <= 0) { clearInterval(t); btn.disabled = false; label.textContent = 'Resend approval email'; }
        }, 1000);
    }

    // Auto-advance: if the user approved on another device (e.g. phone),
    // this page moves to the next step without a manual refresh.
    var statusUrl = <?= json_encode(url('/login/device/status'), JSON_UNESCAPED_SLASHES) ?>;
    var verifyUrl = <?= json_encode(url('/mfa/verify'), JSON_UNESCAPED_SLASHES) ?>;
    var setupUrl = <?= json_encode(url('/mfa/setup'), JSON_UNESCAPED_SLASHES) ?>;
    var banner = document.getElementById('deviceGoneBanner');
    var contLabel = document.getElementById('deviceContinueLabel');
    var poll = setInterval(function(){
        fetch(statusUrl, { credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(j){
            if (!j) return;
            if (j.ok && j.stage === 'mfa') { clearInterval(poll); if (contLabel) contLabel.textContent = 'Approved! Continuing…'; window.location.href = verifyUrl; }
            else if (j.ok && j.stage === 'setup') { clearInterval(poll); if (contLabel) contLabel.textContent = 'Approved! Continuing…'; window.location.href = setupUrl; }
            else if (!j.ok && j.gone) { clearInterval(poll); if (banner) banner.classList.remove('hidden'); }
        }).catch(function(){});
    }, 3000);
})();
</script>
