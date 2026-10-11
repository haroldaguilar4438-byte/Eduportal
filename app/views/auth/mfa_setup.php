<?php
/**
 * MFA setup (spec §5).
 *
 * Non-destructive: $secret, $otpauth and $isLoginFlow are used exactly as
 * before. The form keeps its action, CSRF token, field name (`code`) and every
 * input attribute (autocomplete, inputmode, pattern, maxlength, placeholder).
 * The QR library and its fallback messaging are preserved exactly, as is the
 * cancel form and the back-to-security link.
 *
 * The heading moved into the layout, so this renders only the body. The
 * verification stepper marks Security as the current step.
 *
 * @var string $secret
 * @var string $otpauth
 * @var bool   $isLoginFlow
 */
$secret = $secret ?? '';
$otpauth = $otpauth ?? '';
$isLoginFlow = $isLoginFlow ?? false;
$authHeading = 'Set up your authenticator';
$authSubheading = 'Scan the code, then enter a 6-digit code from your app to activate two-factor protection.';

/*
 * Two different shells render this view (SecurityController::showMfaSetup):
 *   - $isLoginFlow === true  -> shared/layout_auth (heading + stepper come
 *                               from the layout, driven by these variables)
 *   - $isLoginFlow === false -> shared/layout, which ignores them, so the
 *                               view supplies its own page header below.
 * Setting $authStep/$authSteps is therefore only meaningful in the first case.
 */
if ($isLoginFlow) {
    $authStep = 3; /* Account -> Email -> Security -> Complete */
    $authSteps = [
        ['label' => 'Account',  'hint' => 'Signed in'],
        ['label' => 'Email',    'hint' => 'Verified'],
        ['label' => 'Security', 'hint' => 'Set up now'],
        ['label' => 'Complete', 'hint' => 'Protected'],
    ];
}
?>

<?php if (!$isLoginFlow): ?>
    <?= edu_page_header([
        'eyebrow'     => 'Security settings',
        'title'       => 'Set up your authenticator',
        'description' => 'Scan the code below, then enter a 6-digit code from your app to activate two-factor protection.',
        'icon'        => 'bi-qr-code',
        'actions'     => '<a href="' . e(url('/security')) . '" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to security</a>',
    ]) ?>
<?php endif; ?>

<div class="text-center mb-4">
    <span class="edu-stat__icon edu-stat__icon--solid mx-auto" style="width:56px;height:56px;font-size:1.5rem" aria-hidden="true">
        <i class="bi bi-qr-code"></i>
    </span>
</div>

<!-- Instructions -->
<ol class="mb-4 ps-3 small" style="line-height:2;color:var(--text-secondary)">
    <li>Open your authenticator app and add a new account.</li>
    <li>Scan the QR code below &mdash; or enter the setup key manually.</li>
    <li>Enter the 6-digit code to activate protection.</li>
</ol>

<!-- QR -->
<div class="d-flex justify-content-center mb-4">
    <div class="p-3 bg-white border rounded-4" style="box-shadow:var(--shadow)">
        <div id="mfaQr" style="width:180px;height:180px;display:grid;place-items:center;font-size:.75rem;color:var(--text-tertiary)">Loading QR&hellip;</div>
    </div>
</div>

<!-- Manual key -->
<div class="edu-callout mb-4" style="align-items:flex-start">
    <i class="bi bi-key edu-callout__icon" aria-hidden="true"></i>
    <div class="w-100">
        <div style="font-size:.65rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.35rem">Manual setup key</div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <code id="mfaSecret" class="flex-grow-1 text-truncate" style="font-family:var(--font-mono);font-size:.85rem;word-break:break-all;user-select:all"><?= e($secret) ?></code>
            <button type="button" class="btn btn-sm btn-light flex-none"
                    onclick="navigator.clipboard.writeText(document.getElementById('mfaSecret').textContent.trim()); this.textContent='Copied'; setTimeout(()=>this.textContent='Copy',1500)">Copy</button>
        </div>
    </div>
</div>

<!-- Verify -->
<form method="post" action="<?= e(url('/mfa/setup')) ?>" autocomplete="off" id="mfaSetupForm">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="setup_code" class="form-label">6-digit code from the app <span class="req">*</span></label>
        <input type="text" id="setup_code" name="code" required autofocus autocomplete="one-time-code"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456"
               style="text-align:center;letter-spacing:.3em;font-family:var(--font-mono);font-size:1.15rem"
               class="form-control">
    </div>
    <button type="submit" class="btn btn-primary w-100 py-2">
        <i class="bi bi-shield-check me-1"></i> Verify &amp; enable MFA
    </button>
</form>

<p class="small text-center mt-3 mb-0" style="color:var(--text-tertiary)">
    MFA activates only after a correct code. Recovery codes are issued next &mdash; save them securely.
</p>

<?php if ($isLoginFlow): ?>
    <form method="post" action="<?= e(url('/login/cancel')) ?>" class="mt-3">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary w-100">Cancel sign-in</button>
    </form>
<?php else: ?>
    <a href="<?= e(url('/security')) ?>" class="d-block text-center small fw-medium text-decoration-none mt-3">
        <i class="bi bi-arrow-left me-1"></i>Back to security settings
    </a>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    'use strict';

    /* ---- QR code (unchanged behaviour, theme-neutral markup) ---- */
    var el = document.getElementById('mfaQr');
    var uri = <?= json_encode($otpauth, JSON_UNESCAPED_SLASHES) ?>;
    try {
        if (window.QRCode && uri) {
            el.textContent = '';
            new QRCode(el, { text: uri, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
        } else if (uri) {
            el.innerHTML = '<span style="padding:0 .5rem;text-align:center">QR unavailable offline &mdash; use the manual setup key below.</span>';
        }
    } catch (e) {
        el.textContent = 'Use the manual setup key below.';
    }

    /* ---- Digits only, and block submit until six are entered ---- */
    var form = document.getElementById('mfaSetupForm');
    var input = document.getElementById('setup_code');
    if (!form || !input) return;

    var submit = form.querySelector('button[type="submit"]');

    input.addEventListener('input', function () {
        input.value = input.value.replace(/\D/g, '').slice(0, 6);
    });

    if (submit) {
        submit.disabled = true;
        input.addEventListener('input', function () {
            submit.disabled = input.value.length !== 6;
        });
        input.dispatchEvent(new Event('input'));
    }

    form.addEventListener('submit', function () {
        if (submit) {
            submit.disabled = true;
            submit.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Verifying&hellip;';
        }
    });
})();
</script>
