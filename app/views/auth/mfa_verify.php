<?php
/**
 * MFA challenge (spec §5).
 *
 * Non-destructive: the form keeps its action (/mfa/verify), CSRF token, the
 * `code` field (accepts a 6-digit TOTP *or* an XXXX-XXXX recovery code), the
 * `remember_device` checkbox and the cancel form, with all original input
 * attributes intact (autocomplete, inputmode, maxlength, placeholder, autofocus).
 *
 * Heading and stepper are supplied to shared/layout_auth, which is the only
 * shell this view is ever rendered into (SecurityController::showMfaVerify).
 *
 * @var string $email
 */
$email = $email ?? '';

$authHeading    = 'Two-factor verification';
$authSubheading = 'Your account is protected with MFA. Enter the code to finish signing in.';
$authStep       = 3; /* Account -> Email -> Security -> Complete */
$authSteps      = [
    ['label' => 'Account',  'hint' => 'Confirmed'],
    ['label' => 'Email',    'hint' => 'Verified'],
    ['label' => 'Security', 'hint' => 'Enter code'],
    ['label' => 'Complete', 'hint' => 'Signed in'],
];
?>

<div class="text-center mb-4">
    <span class="edu-stat__icon edu-stat__icon--solid mx-auto" style="width:56px;height:56px;font-size:1.5rem" aria-hidden="true">
        <i class="bi bi-shield-lock"></i>
    </span>
</div>

<?php if ($email !== ''): ?>
    <?= edu_callout(
        'Signing in as <strong>' . e($email) . '</strong>',
        ['tone' => 'info', 'icon' => 'bi-person-check', 'title' => null]
    ) ?>
<?php endif; ?>

<form method="post" action="<?= e(url('/mfa/verify')) ?>" autocomplete="off" id="mfaVerifyForm">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="mfa_code" class="form-label">Authenticator code or recovery code <span class="req">*</span></label>
        <input type="text" id="mfa_code" name="code" required autofocus autocomplete="one-time-code"
               inputmode="text" maxlength="11" placeholder="123456 or XXXX-XXXX"
               style="text-align:center;letter-spacing:.3em;font-family:var(--font-mono);font-size:1.1rem"
               class="form-control">
        <div class="form-hint text-center">6 digits from your authenticator app &mdash; or one of your recovery codes.</div>
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2 mb-3">
        <i class="bi bi-check-lg me-1"></i> Verify &amp; sign in
    </button>

    <div class="form-check mb-1">
        <input class="form-check-input" type="checkbox" name="remember_device" value="1" id="remember_device">
        <label class="form-check-label" for="remember_device">
            <strong>Remember this device for 30 days</strong><br>
            <span class="small" style="color:var(--text-tertiary)">Skip the code on this trusted browser. Password still required every login.</span>
        </label>
    </div>
</form>

<form method="post" action="<?= e(url('/login/cancel')) ?>" class="mt-3">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-secondary w-100">Cancel sign-in</button>
</form>

<script>
(function () {
    'use strict';

    /*
     * Accept both formats: 6 digits, or XXXX-XXXX (uppercased, single dash at
     * position 4). We deliberately do not strip other characters here — the
     * server owns validation, and silently mangling a code would be worse
     * than letting it fail with its own message.
     */
    var input = document.getElementById('mfa_code');
    var form = document.getElementById('mfaVerifyForm');
    if (!input || !form) return;

    input.addEventListener('input', function () {
        var v = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
        if (/^\d*$/.test(v)) {
            v = v.slice(0, 6);
        } else if (v.length > 8) {
            v = v.slice(0, 4) + '-' + v.slice(4, 8);
        }
        input.value = v;
    });

    form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Verifying&hellip;';
        }
    });
})();
</script>
