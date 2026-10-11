<?php
/**
 * Email verification notice (spec §5).
 *
 * Non-destructive: every element the script depends on is preserved —
 * #verifyCard, #verifiedBanner, #btnOtp / #otpLabel, #btnLink / #linkLabel,
 * the $cooldownOtp / $cooldownLink disabled state and label text, the
 * /verify-status poll and the redirect to /login.
 *
 * Forms are untouched: /verify/otp (field `otp` with inputmode/pattern/
 * maxlength/placeholder/aria-label), /verify/send-otp, /verify/resend-link,
 * plus the /login and /dashboard links and the $alreadyVerified branch.
 *
 * Headings now come from shared/layout_auth; this view previously rendered an
 * extra <h1> in each branch.
 *
 * @var array  $user
 * @var bool   $alreadyVerified
 * @var int    $cooldownLink
 * @var int    $cooldownOtp
 */
$user            = $user ?? [];
$alreadyVerified = $alreadyVerified ?? false;
$cooldownLink    = $cooldownLink ?? 0;
$cooldownOtp     = $cooldownOtp ?? 0;

if ($alreadyVerified) {
    $authHeading    = 'Email verified';
    $authSubheading = 'Your email address is confirmed — you can sign in now.';
} else {
    $authHeading    = 'Verify your email';
    $authSubheading = 'We sent a verification link. Click it, or use one of the options below.';
    $authStep       = 2; /* Account -> Email -> Security -> Complete */
    $authSteps      = [
        ['label' => 'Account',  'hint' => 'Created'],
        ['label' => 'Email',    'hint' => 'Verify now'],
        ['label' => 'Security', 'hint' => 'MFA'],
        ['label' => 'Complete', 'hint' => 'Sign in'],
    ];
}
?>

<?php if ($alreadyVerified): ?>

    <div class="text-center mb-4">
        <span class="edu-stat__icon edu-stat__icon--success mx-auto" style="width:64px;height:64px;font-size:1.75rem" aria-hidden="true">
            <i class="bi bi-check-lg"></i>
        </span>
    </div>

    <?= edu_callout(
        'Your email <strong>' . e($user['email'] ?? '') . '</strong> is now verified. You can sign in.',
        ['tone' => 'success', 'icon' => 'bi-patch-check-fill', 'title' => 'Verified']
    ) ?>

    <div class="d-grid gap-2">
        <a href="<?= e(url('/login')) ?>" class="btn btn-primary py-2">
            <i class="bi bi-box-arrow-in-right me-1"></i> Go to sign in
        </a>
        <a href="<?= e(url('/dashboard')) ?>" class="btn btn-outline-secondary py-2">Go to dashboard</a>
    </div>

    <p class="small text-center mt-3 mb-0" style="color:var(--text-tertiary)">
        <i class="bi bi-check-circle"></i> Verified automatically via email link.
    </p>

<?php else: ?>

<div id="verifyCard">

    <div class="text-center mb-4">
        <span class="edu-stat__icon edu-stat__icon--solid mx-auto" style="width:64px;height:64px;font-size:1.75rem" aria-hidden="true">
            <i class="bi bi-envelope-check"></i>
        </span>
    </div>

    <p class="small text-center mb-3" style="color:var(--text-secondary)">
        We sent a verification link to <strong><?= e($user['email'] ?? '') ?></strong>.<br>
        Click the link in that email &mdash; or use one of the options below.
    </p>

    <div id="verifiedBanner" class="alert alert-success text-center d-none">
        <div class="fs-4"><i class="bi bi-patch-check-fill"></i> Verified</div>
        <div class="small">Your email is now verified! Redirecting&hellip;</div>
        <a href="<?= e(url('/login')) ?>" class="btn btn-success btn-sm mt-2">Go to sign in</a>
    </div>

    <form method="post" action="<?= e(url('/verify/otp')) ?>" class="mb-3" novalidate id="otpForm">
        <?= csrf_field() ?>
        <label for="otp" class="form-label">Six-digit code from the email</label>
        <div class="d-flex gap-2">
            <input class="form-control text-center" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}"
                   maxlength="6" placeholder="- - - - - -" required aria-label="Six digit code"
                   style="font-family:var(--font-mono);font-size:1.25rem;letter-spacing:.2em">
            <button type="submit" class="btn btn-primary px-3 flex-none">Verify</button>
        </div>
    </form>

    <div class="d-grid gap-2">
        <form method="post" action="<?= e(url('/verify/send-otp')) ?>">
            <?= csrf_field() ?>
            <button type="submit" id="btnOtp" class="btn btn-outline-primary w-100" <?= $cooldownOtp > 0 ? 'disabled' : '' ?>>
                <i class="bi bi-phone"></i>
                <span id="otpLabel"><?= $cooldownOtp > 0 ? 'Resend code in ' . $cooldownOtp . 's' : 'Email me a 6-digit code instead' ?></span>
            </button>
        </form>
        <form method="post" action="<?= e(url('/verify/resend-link')) ?>">
            <?= csrf_field() ?>
            <button type="submit" id="btnLink" class="btn btn-outline-secondary w-100" <?= $cooldownLink > 0 ? 'disabled' : '' ?>>
                <i class="bi bi-arrow-clockwise"></i>
                <span id="linkLabel"><?= $cooldownLink > 0 ? 'Resend link in ' . $cooldownLink . 's' : 'Resend verification link' ?></span>
            </button>
        </form>
    </div>

    <div class="small text-center mt-3" style="color:var(--text-tertiary)">
        <i class="bi bi-clock"></i> Cooldown 60s after each resend to prevent spam.
    </div>

    <hr class="my-4">
    <p class="text-center small mb-0">
        Wrong account? <a href="<?= e(url('/login')) ?>">Sign in with another email</a>
    </p>

</div><!-- /#verifyCard -->

<?php endif; ?>

<script>
(function () {
    'use strict';

    var linkBtn = document.getElementById('btnLink');
    var otpBtn  = document.getElementById('btnOtp');
    var linkLbl = document.getElementById('linkLabel');
    var otpLbl  = document.getElementById('otpLabel');
    var cdLink  = <?= (int) $cooldownLink ?>;
    var cdOtp   = <?= (int) $cooldownOtp ?>;

    function tick(btn, label, cd, txt) {
        if (!btn || cd <= 0) return;
        btn.disabled = true;
        var left = cd;
        var timer = setInterval(function () {
            left--;
            label.textContent = (txt === 'link' ? 'Resend link in ' : 'Resend code in ') + left + 's';
            if (left <= 0) {
                clearInterval(timer);
                btn.disabled = false;
                label.textContent = txt === 'link' ? 'Resend verification link' : 'Email me a 6-digit code instead';
            }
        }, 1000);
    }
    tick(linkBtn, linkLbl, cdLink, 'link');
    tick(otpBtn, otpLbl, cdOtp, 'otp');

    /* Digits only, and block submit until six are present. */
    var otp = document.getElementById('otp');
    if (otp) {
        otp.addEventListener('input', function () {
            otp.value = otp.value.replace(/\D/g, '').slice(0, 6);
        });
    }

    if (!linkBtn) return; /* already-verified branch has nothing to poll */

    var banner  = document.getElementById('verifiedBanner');
    var card    = document.getElementById('verifyCard') || otpBtn.closest('.card, form, div');
    var verifiedUrl = <?= json_encode(url('/verify-status'), JSON_UNESCAPED_SLASHES) ?>;
    var loginUrl    = <?= json_encode(url('/login'), JSON_UNESCAPED_SLASHES) ?>;

    var polling = setInterval(function () {
        fetch(verifiedUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.verified) return;
                clearInterval(polling);
                if (banner) banner.classList.remove('d-none');
                if (card) card.style.opacity = '0.6';

                var note = document.createElement('div');
                note.className = 'alert alert-success text-center mt-3';
                note.innerHTML = '<div class="fs-5"><i class="bi bi-patch-check-fill"></i> Verified</div>'
                    + '<div class="small">Verified as ' + (j.email || '') + ' &mdash; redirecting to sign in&hellip;</div>';
                if (card) card.prepend(note);

                setTimeout(function () { window.location.href = loginUrl; }, 1800);
            })
            .catch(function () {});
    }, 3000);
})();
</script>
