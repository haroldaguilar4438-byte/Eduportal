<?php
/**
 * Forgot password (spec §5).
 *
 * Non-destructive: form action (/forgot-password), CSRF token, the `email`
 * field with type/autocomplete/placeholder/autofocus and the submit button are
 * all unchanged.
 *
 * The heading lives in shared/layout_auth via $authHeading/$authSubheading —
 * previously this view also rendered its own <h1>, which produced two <h1>
 * elements on the page.
 */
$authHeading    = 'Forgot password';
$authSubheading = 'Enter the email linked to your account and we will send a reset link.';
?>

<div class="text-center mb-4">
    <span class="edu-stat__icon edu-stat__icon--solid mx-auto" style="width:56px;height:56px;font-size:1.5rem" aria-hidden="true">
        <i class="bi bi-envelope-paper"></i>
    </span>
</div>

<form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="email" class="form-label">Email address <span class="req">*</span></label>
        <input type="email" class="form-control" id="email" name="email"
               required autofocus autocomplete="email" placeholder="you@example.com">
    </div>
    <button type="submit" class="btn btn-primary w-100 py-2">
        <i class="bi bi-send me-1"></i> Send reset link
    </button>
</form>

<?= edu_callout(
    'For your privacy we send the same confirmation whether or not an account exists for that address.',
    ['tone' => 'info', 'icon' => 'bi-shield-check', 'title' => null]
) ?>

<hr class="my-4">
<p class="text-center small mb-0">
    Remembered it? <a href="<?= e(url('/login')) ?>">Back to sign in</a>
</p>
