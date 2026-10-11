<?php
/**
 * Reset password (spec §5).
 *
 * Non-destructive: the missing-token branch, the POST action (/reset-password),
 * the hidden `token` field, both `password` / `password_confirm` fields with
 * their minlength=10 and autocomplete attributes, and the server-rendered
 * $errors list are all preserved exactly. The requirements checklist and
 * strength meter are client-side hints only — the server still decides.
 *
 * The heading now comes from shared/layout_auth; this view previously rendered
 * a second <h1>, giving the page two.
 *
 * @var string   $token
 * @var string[] $errors
 */
$token  = $token ?? '';
$errors = $errors ?? [];

$authHeading    = $token !== '' ? 'Choose a new password' : 'Reset link required';
$authSubheading = 'Passwords must be at least 10 characters with upper case, lower case and a number.';
?>

<div class="text-center mb-4">
    <span class="edu-stat__icon edu-stat__icon--solid mx-auto" style="width:56px;height:56px;font-size:1.5rem" aria-hidden="true">
        <i class="bi bi-shield-lock"></i>
    </span>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger py-2">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($token === ''): ?>
    <?= edu_callout(
        'Missing reset token. Please use the link from your email.',
        ['tone' => 'warning', 'icon' => 'bi-exclamation-triangle-fill', 'title' => null]
    ) ?>
    <a href="<?= e(url('/forgot-password')) ?>" class="btn btn-primary w-100">Request a new link</a>
<?php else: ?>
    <form method="post" action="<?= e(url('/reset-password')) ?>" novalidate id="resetForm">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <!-- Client-side requirements checklist (advisory only) -->
        <ul class="list-unstyled small mb-3" id="pwRules">
            <li class="mb-1" data-rule="len"><i class="bi bi-circle me-1"></i>At least 10 characters</li>
            <li class="mb-1" data-rule="case"><i class="bi bi-circle me-1"></i>Upper and lower case</li>
            <li class="mb-1" data-rule="num"><i class="bi bi-circle me-1"></i>At least one number</li>
            <li class="mb-1" data-rule="match"><i class="bi bi-circle me-1"></i>Both passwords match</li>
        </ul>

        <div class="mb-3">
            <label for="password" class="form-label">New password <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" class="form-control" id="password" name="password"
                       required minlength="10" autofocus autocomplete="new-password">
                <button class="btn btn-outline-secondary" type="button" data-toggle="password" data-target="#password" aria-label="Show password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            <div class="progress mt-2" style="height:5px" aria-hidden="true">
                <div class="progress-bar" id="pwMeter" style="width:0"></div>
            </div>
        </div>

        <div class="mb-3">
            <label for="password_confirm" class="form-label">Confirm new password <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                       required minlength="10" autocomplete="new-password">
                <button class="btn btn-outline-secondary" type="button" data-toggle="password" data-target="#password_confirm" aria-label="Show password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2">
            <i class="bi bi-shield-lock me-1"></i> Update password
        </button>
    </form>
<?php endif; ?>

<hr class="my-4">
<p class="text-center small mb-0"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>

<script>
(function () {
    'use strict';

    var form  = document.getElementById('resetForm');
    var pw    = document.getElementById('password');
    var pw2   = document.getElementById('password_confirm');
    var meter = document.getElementById('pwMeter');
    if (!form || !pw || !pw2) return;

    /* Show/hide toggles, wired by data-target so the markup stays declarative. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-toggle="password"]'), function (btn) {
        btn.addEventListener('click', function () {
            var field = document.querySelector(btn.getAttribute('data-target'));
            if (!field) return;
            var show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    function rule(ok) {
        return '<i class="bi ' + (ok ? 'bi-check-circle-fill text-success' : 'bi-circle') + ' me-1"></i>';
    }

    function score(v) {
        var s = 0;
        if (v.length >= 10) s++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
        if (/\d/.test(v)) s++;
        if (/[^\w]/.test(v)) s++;
        return s;
    }

    function update() {
        var v = pw.value;
        var checks = {
            len:   v.length >= 10,
            'case': /[a-z]/.test(v) && /[A-Z]/.test(v),
            num:   /\d/.test(v),
            match: v.length > 0 && v === pw2.value
        };
        Object.keys(checks).forEach(function (k) {
            var li = document.querySelector('#pwRules [data-rule="' + k + '"]');
            if (li) {
                li.innerHTML = rule(checks[k]) + li.textContent.trim();
                li.classList.toggle('fw-semibold', checks[k]);
                li.style.color = checks[k] ? 'var(--text-primary)' : 'var(--text-tertiary)';
            }
        });

        if (meter) {
            var s = score(v);
            meter.style.width = (s / 4 * 100) + '%';
            meter.className = 'progress-bar ' + (s <= 1 ? 'bg-danger' : s === 2 ? 'bg-warning' : s === 3 ? 'bg-info' : 'bg-success');
        }
    }

    pw.addEventListener('input', update);
    pw2.addEventListener('input', update);
    update();
})();
</script>
