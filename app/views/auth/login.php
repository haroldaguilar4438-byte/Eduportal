<?php
/**
 * Login (spec §5).
 *
 * Non-destructive: the form posts to the same /login action with the same
 * field names (email, password, remember) and the same CSRF token. Password
 * visibility, "keep me signed in" and the forgot-password link are all kept.
 * The heading is moved into the layout (edu-auth-panel) via $authHeading, so
 * this file only renders the form itself.
 *
 * @var string|null $flash
 */
$authHeading    = 'Welcome back';
$authSubheading = 'Sign in with your school email address to continue to EduPortal.';
$authBackUrl    = url('/');
?>
<div class="auth-form-wrap">

  <form method="post" action="<?= e(url('/login')) ?>" novalidate class="d-flex flex-column gap-3" id="eduLoginForm">
    <?= csrf_field() ?>

    <div>
      <label for="email" class="form-label">Email address <span class="req">*</span></label>
      <div class="edu-input">
        <i class="bi bi-envelope" aria-hidden="true"></i>
        <input type="email" id="email" name="email" class="form-control" required autofocus
               autocomplete="email" placeholder="you@example.com"
               aria-describedby="emailHelp">
      </div>
      <div class="form-text" id="emailHelp">Use the email address registered with the school.</div>
    </div>

    <div>
      <div class="d-flex align-items-center justify-content-between mb-1">
        <label for="password" class="form-label mb-0">Password <span class="req">*</span></label>
        <a href="<?= e(url('/forgot-password')) ?>" class="small fw-semibold text-decoration-none">Forgot?</a>
      </div>
      <div class="edu-input">
        <i class="bi bi-lock" aria-hidden="true"></i>
        <input type="password" id="password" name="password" class="form-control" required
               autocomplete="current-password" placeholder="Enter your password"
               style="padding-right:2.75rem" aria-describedby="passwordHelp">
        <button type="button" class="edu-input__toggle" data-toggle-password="#password"
                aria-label="Show password" title="Show password">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
      </div>
      <div class="form-text" id="passwordHelp">
        <i class="bi bi-shield-lock me-1"></i>Your password is sent over an encrypted connection.
      </div>
    </div>

    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
      <label class="form-check-label small" for="remember">Keep me signed in on this device</label>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100" id="eduLoginBtn">
      <i class="bi bi-box-arrow-in-right me-1"></i> Sign in
    </button>
  </form>

  <div class="d-flex align-items-center gap-3 my-4" aria-hidden="true">
    <span class="flex-grow-1 border-top" style="border-color:var(--border)!important"></span>
    <span class="small" style="color:var(--text-tertiary)">New here?</span>
    <span class="flex-grow-1 border-top" style="border-color:var(--border)!important"></span>
  </div>

  <a href="<?= e(url('/register')) ?>" class="btn btn-outline-primary w-100">
    <i class="bi bi-person-plus me-1"></i> Create an account
  </a>

  <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
    <?= edu_badge('Secure portal', 'success', 'bi-shield-check') ?>
    <?= edu_badge('Datamex College of Saint Adeline', 'neutral') ?>
  </div>

  <p class="text-center small mt-3 mb-0" style="color:var(--text-tertiary)">
    Staff and faculty sign in with the same form.
    Teachers without an account can <a href="<?= e(url('/register-teacher')) ?>" class="text-decoration-none fw-semibold">register with an invite code</a>.
  </p>
</div>

<script>
(function () {
  'use strict';

  /* ---- Password visibility ---- */
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.querySelector(btn.getAttribute('data-toggle-password'));
      if (!input) return;
      var wasPwd = input.type === 'password';
      input.type = wasPwd ? 'text' : 'password';
      var icon = btn.querySelector('i');
      if (icon) {
        icon.classList.toggle('bi-eye', !wasPwd);
        icon.classList.toggle('bi-eye-slash', wasPwd);
      }
      btn.setAttribute('aria-label', wasPwd ? 'Hide password' : 'Show password');
      btn.setAttribute('title', wasPwd ? 'Hide password' : 'Show password');
      input.focus();
    });
  });

  /* ---- Inline validation (client-side only; the server remains authoritative) ---- */
  var form = document.getElementById('eduLoginForm');
  if (!form) return;
  var email = form.querySelector('#email');
  var pass = form.querySelector('#password');

  function mark(input, ok) {
    if (!input) return;
    input.classList.toggle('is-invalid', !ok);
    input.setAttribute('aria-invalid', ok ? 'false' : 'true');
  }

  function validate() {
    var eOk = email.checkValidity();
    var pOk = pass.value.length > 0;
    mark(email, eOk);
    mark(pass, pOk);
    return eOk && pOk;
  }

  var submitBtn = form.querySelector('#eduLoginBtn');

  /* Silent check for the button state — unlike validate() this never paints
     error styling, so typing the first character doesn't flash red. */
  function refreshButton() {
    if (!submitBtn || submitBtn.dataset.busy === '1') return;
    var ready = email.checkValidity() && pass.value.length > 0;
    submitBtn.disabled = !ready;
  }

  [email, pass].forEach(function (el) {
    if (!el) return;
    el.addEventListener('blur', function () { if (el.value !== '') mark(el, el.checkValidity()); });
    el.addEventListener('input', function () {
      if (el.classList.contains('is-invalid')) mark(el, el.checkValidity());
      refreshButton();
    });
    el.addEventListener('change', refreshButton);
  });

  /* Initial state + delayed pass for browser autofill / password managers,
     which often fill fields without firing input events. */
  refreshButton();
  setTimeout(refreshButton, 500);

  /* ---- Loading state: disable the button so a double-submit cannot happen ---- */
  form.addEventListener('submit', function (ev) {
    if (!validate()) {
      ev.preventDefault();
      return;
    }
    var btn = form.querySelector('button[type="submit"]');
    if (btn) {
      btn.disabled = true;
      btn.dataset.busy = '1';
      btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Signing in&hellip;';
    }
  });

  /* ---- Enter submits from either field ---- */
  form.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.tagName === 'INPUT') {
      ev.preventDefault();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
  });
})();
</script>
