<?php
$old = $old ?? [];
$errors = $errors ?? [];
$captchaQ = $captchaQ ?? null;

/* Heading is rendered by shared/layout_auth; the view previously emitted its
   own <h1> as well, leaving two on the page. */
$authHeading    = 'Create your account';
$authSubheading = 'Students and parents or guardians can self-register. Teacher accounts are invite-only.';
?>
    <a href="<?= e(url('/')) ?>" class="d-inline-flex align-items-center gap-1 small fw-medium text-decoration-none mb-3" style="color:var(--text-secondary)">
        <i class="bi bi-arrow-left"></i> Back to home
    </a>

    <?= edu_callout(
        'Are you a teacher? <a href="' . e(url('/register-teacher')) . '" class="fw-semibold">Register as Teacher (Invite Code <code>TCH-XXXX-XXXX</code>)</a> &mdash; ask admin',
        ['tone' => 'warning', 'icon' => 'bi-shield-lock', 'title' => null]
    ) ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger py-2">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle"></i> Please fix the following</div>
            <ul class="mb-0 ps-3 small"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/register')) ?>" novalidate id="registerForm" class="space-y-4">
        <?= csrf_field() ?>

        <div class="grid grid-cols-2 gap-2.5">
            <label class="relative flex cursor-pointer rounded-xl border-2 p-3.5 transition <?= ($old['role'] ?? 'student') === 'student' ? 'border-[#173B6C] bg-slate-50' : 'border-slate-200 bg-white hover:border-slate-300' ?>">
                <input type="radio" name="role" value="student" id="roleStudent" class="sr-only" <?= ($old['role'] ?? 'student') === 'student' ? 'checked' : '' ?>>
                <div class="w-full">
                    <div class="flex items-center gap-1.5 text-sm font-semibold text-[#173B6C]"><i class="bi bi-backpack2"></i> Student</div>
                    <div class="text-[11px] text-slate-500">I am enrolling / enrolled</div>
                </div>
                <i class="bi bi-check-circle-fill absolute top-2.5 right-2.5 text-[#173B6C] <?= ($old['role'] ?? 'student') === 'student' ? '' : 'hidden' ?>"></i>
            </label>
            <label class="relative flex cursor-pointer rounded-xl border-2 p-3.5 transition <?= ($old['role'] ?? '') === 'parent' ? 'border-[#173B6C] bg-slate-50' : 'border-slate-200 bg-white hover:border-slate-300' ?>">
                <input type="radio" name="role" value="parent" id="roleParent" class="sr-only" <?= ($old['role'] ?? '') === 'parent' ? 'checked' : '' ?>>
                <div class="w-full">
                    <div class="flex items-center gap-1.5 text-sm font-semibold text-[#173B6C]"><i class="bi bi-people"></i> Parent</div>
                    <div class="text-[11px] text-slate-500">I monitor my child</div>
                </div>
                <i class="bi bi-check-circle-fill absolute top-2.5 right-2.5 text-[#173B6C] <?= ($old['role'] ?? '') === 'parent' ? '' : 'hidden' ?>"></i>
            </label>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="first_name" class="block text-xs font-semibold text-slate-700 mb-1">First name <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input id="first_name" name="first_name" maxlength="100" required value="<?= e($old['first_name'] ?? '') ?>" placeholder="Juan"
                        class="w-full px-3 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#173B6C]/10 focus:border-[#173B6C] focus:bg-white transition">
                    <span id="firstNameIcon" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"><i class="bi bi-check-circle-fill text-emerald-500"></i></span>
                </div>
                <p id="firstNameError" class="hidden text-[11px] text-red-600 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i> <span></span></p>
            </div>
            <div>
                <label for="last_name" class="block text-xs font-semibold text-slate-700 mb-1">Last name <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input id="last_name" name="last_name" maxlength="100" required value="<?= e($old['last_name'] ?? '') ?>" placeholder="Dela Cruz"
                        class="w-full px-3 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#173B6C]/10 focus:border-[#173B6C] focus:bg-white transition">
                    <span id="lastNameIcon" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"><i class="bi bi-check-circle-fill text-emerald-500"></i></span>
                </div>
                <p id="lastNameError" class="hidden text-[11px] text-red-600 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i> <span></span></p>
            </div>
            <div>
                <label for="middle_name" class="block text-xs font-semibold text-slate-700 mb-1">Middle name</label>
                <input id="middle_name" name="middle_name" maxlength="100" value="<?= e($old['middle_name'] ?? '') ?>" placeholder="Optional"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#173B6C]/10 focus:border-[#173B6C] focus:bg-white transition">
                <p id="middleNameError" class="hidden text-[11px] text-red-600 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i> <span></span></p>
            </div>
            <div>
                <label for="suffix" class="block text-xs font-semibold text-slate-700 mb-1">Suffix</label>
                <input id="suffix" name="suffix" maxlength="20" placeholder="Jr, III..." value="<?= e($old['suffix'] ?? '') ?>"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#173B6C]/10 focus:border-[#173B6C] focus:bg-white transition">
                <p id="suffixError" class="hidden text-[11px] text-red-600 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i> <span></span></p>
            </div>
        </div>

        <div>
            <label for="email" class="block text-xs font-semibold text-slate-700 mb-1">Email address <span class="text-red-500">*</span></label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"><i class="bi bi-envelope"></i></span>
                <input type="email" id="email" name="email" required maxlength="190" value="<?= e($old['email'] ?? '') ?>" placeholder="you@example.com"
                    pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}"
                    class="w-full pl-9 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#173B6C]/10 focus:border-[#173B6C] focus:bg-white transition peer">
                <span id="emailIcon" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"><i class="bi bi-check-circle-fill text-emerald-500"></i></span>
            </div>
            <p id="emailError" class="hidden text-[11px] text-red-600 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i> <span></span></p>
            <p class="text-[11px] text-slate-500 mt-1">Must be a valid email (e.g., name@domain.com). Wrong format will be blocked.</p>
        </div>

        <div>
            <label for="phone" class="block text-xs font-semibold text-slate-700 mb-1">Mobile number <span class="text-red-500">*</span> <span class="text-[11px] font-normal text-slate-500">(11 digits)</span></label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"><i class="bi bi-phone"></i></span>
                <input type="tel" id="phone" name="phone" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" minlength="11" required placeholder="09XXXXXXXXX" value="<?= e($old['phone'] ?? '') ?>"
                    class="w-full pl-9 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#173B6C]/10 focus:border-[#173B6C] focus:bg-white transition">
                <span id="phoneIcon" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"><i class="bi bi-check-circle-fill text-emerald-500"></i></span>
            </div>
            <p id="phoneError" class="hidden text-[11px] text-red-600 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i> <span></span></p>
            <p class="text-[11px] text-slate-500 mt-1"><span id="phoneCount">0</span>/11 digits · Must start with <strong>09</strong> · Only numbers allowed — wrong input blocks registration.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label for="password" class="block text-xs font-semibold text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password" placeholder="••••••••••"
                        class="w-full pl-3 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#173B6C]/10 focus:border-[#173B6C] focus:bg-white transition">
                    <button type="button" data-toggle-password="#password" class="absolute right-2 top-1/2 -translate-y-1/2 w-7 h-7 grid place-items-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"><i class="bi bi-eye text-sm"></i></button>
                </div>
            </div>
            <div>
                <label for="password_confirm" class="block text-xs font-semibold text-slate-700 mb-1">Confirm password <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="10" autocomplete="new-password" placeholder="••••••••••"
                        class="w-full pl-3 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#173B6C]/10 focus:border-[#173B6C] focus:bg-white transition">
                    <button type="button" data-toggle-password="#password_confirm" class="absolute right-2 top-1/2 -translate-y-1/2 w-7 h-7 grid place-items-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"><i class="bi bi-eye text-sm"></i></button>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 space-y-2.5">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-700">Password strength</span>
                <span id="pm-label" class="text-xs font-bold px-2 py-0.5 rounded-full bg-slate-200 text-slate-600">—</span>
            </div>
            <div class="h-2 bg-slate-200 rounded-full overflow-hidden flex gap-1 p-0.5">
                <div id="pm-bar" class="h-full rounded-full transition-all duration-300" style="width:0%"></div>
            </div>
            <ul class="grid grid-cols-2 gap-1.5 text-[11px]">
                <li id="pm-len" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> 10+ characters</li>
                <li id="pm-upper" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Uppercase letter</li>
                <li id="pm-lower" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Lowercase letter</li>
                <li id="pm-num" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> A number</li>
                <li id="pm-spec" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Special char <span class="text-slate-400">(bonus)</span></li>
                <li id="pm-match" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Passwords match</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-3.5">
            <div id="termsWrap" class="flex gap-3 select-none opacity-60 cursor-pointer">
                <input type="checkbox" id="terms" name="terms" value="1" class="mt-0.5 w-4 h-4 rounded border-slate-300 text-[#173B6C] focus:ring-[#173B6C] disabled:cursor-not-allowed shrink-0" disabled required>
                <label for="terms" class="text-xs leading-relaxed text-slate-700 cursor-pointer">
                    I have read and agree to the
                    <button type="button" id="openPrivacy" class="font-semibold text-[#173B6C] underline decoration-2 underline-offset-2 hover:text-[#173B6C]">Data Privacy Notice &amp; Portal Terms</button>.
                    <span class="text-slate-500">Full text:</span>
                    <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener" class="font-semibold text-[#173B6C] underline">Privacy Policy</a> &middot;
                    <a href="<?= e(url('/terms')) ?>" target="_blank" rel="noopener" class="font-semibold text-[#173B6C] underline">Terms</a>.
                    <span id="privacyBadge" class="ml-1 inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 border border-yellow-200"><i class="bi bi-exclamation-circle"></i> Required — click to review</span>
                </label>
            </div>
            <p id="termsHint" class="text-[11px] text-yellow-600 mt-2 flex items-center gap-1"><i class="bi bi-lock"></i> You must open and acknowledge the Data Privacy Notice before you can create an account.</p>
        </div>

        <?php if ($captchaQ !== null): ?>
        <div class="rounded-xl border border-yellow-200 bg-yellow-50 p-3.5">
            <label for="reg_captcha" class="block text-xs font-semibold text-yellow-900 mb-1"><i class="bi bi-robot"></i> Quick verification <span class="text-red-500">*</span></label>
            <p class="text-[11px] text-yellow-700 mb-2">We noticed several sign-up attempts from your network. Please answer to continue: <strong><?= e($captchaQ) ?></strong></p>
            <input id="reg_captcha" name="captcha" inputmode="numeric" autocomplete="off" placeholder="Your answer"
                class="w-full px-3 py-2.5 bg-white border border-yellow-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500/20 focus:border-yellow-500 transition">
        </div>
        <?php endif; ?>

        <!-- Honeypot: invisible to humans, bots fill it in (server rejects). -->
        <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
            <label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
        </div>

        <button type="submit" id="submitBtn" disabled class="w-full py-2.5 rounded-xl bg-[#173B6C] text-white text-sm font-semibold shadow-sm flex items-center justify-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-[#1D4A80] transition">
            <i class="bi bi-person-plus"></i> Create account
        </button>
    </form>

    <p class="text-center text-xs text-slate-500 mt-5">Already registered? <a href="<?= e(url('/login')) ?>" class="font-semibold text-[#173B6C] hover:underline">Sign in</a></p>

<div class="modal fade" id="privacyModal" tabindex="-1" aria-hidden="true" aria-labelledby="privacyModalTitle">
  <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
    <div class="modal-content rounded-2xl overflow-hidden border-0 shadow-xl">
      <div class="modal-header border-b border-slate-200 bg-slate-50 px-5 py-4">
        <div>
          <h2 id="privacyModalTitle" class="font-display font-bold text-base text-[#173B6C] flex items-center gap-2"><i class="bi bi-shield-check text-emerald-600"></i> Data Privacy Notice &amp; Portal Terms</h2>
          <p class="text-xs text-slate-500 mt-0.5">Datamex College of Saint Adeline — EduPortal &middot; Last updated <?= e(date('F Y')) ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div id="privacyBody" class="modal-body px-5 py-4 space-y-4 text-[13px] leading-relaxed text-slate-700" style="max-height:60vh; overflow-y:auto">
        <div class="rounded-xl bg-blue-50 border border-blue-200 px-3.5 py-3 text-xs text-blue-900 flex gap-2"><i class="bi bi-info-circle mt-0.5"></i><span><strong>Please scroll to the bottom</strong> to enable the “I Understand” button — this confirms you have reviewed the notice. Your consent is required under the Data Privacy Act of 2012 (RA 10173).</span></div>
        <h3 class="font-semibold text-[#173B6C] text-sm">1. What we collect</h3>
        <p>We collect your name, email, phone, role (student/parent), and account credentials you provide during registration. Additional academic records are collected only through official enrollment flows.</p>
        <h3 class="font-semibold text-[#173B6C] text-sm">2. How we use it</h3>
        <ul class="list-disc ps-5 space-y-1"><li>To create and secure your EduPortal account (authentication, password recovery, email verification).</li><li>To process enrollments, permits, and classroom access.</li><li>To communicate official school announcements and verification codes via email.</li><li>To maintain audit logs for security (IP, timestamps) as required for account protection.</li></ul>
        <h3 class="font-semibold text-[#173B6C] text-sm">3. Legal basis &amp; retention</h3>
        <p>Processing is based on your consent and the school’s legitimate educational interest. Data is retained while your account is active and up to 5 years after graduation/withdrawal unless law requires longer. You may request deletion subject to academic record-keeping obligations.</p>
        <h3 class="font-semibold text-[#173B6C] text-sm">4. Sharing &amp; protection</h3>
        <p>We do not sell your data. Access is limited to authorized school personnel (registrar, teachers, admins) on a need-to-know basis. Passwords are bcrypt-hashed; invite codes are single-use &amp; hashed. We use HTTPS, CSRF protection, rate limiting, and audit logging.</p>
        <h3 class="font-semibold text-[#173B6C] text-sm">5. Your rights</h3>
        <ul class="list-disc ps-5 space-y-1"><li>Right to access, correct, or request deletion of your personal data (contact registrar).</li><li>Right to withdraw consent (may limit portal access).</li><li>Right to lodge a complaint with the National Privacy Commission.</li></ul>
        <h3 class="font-semibold text-[#173B6C] text-sm">6. Portal Terms</h3>
        <ul class="list-disc ps-5 space-y-1"><li>Provide accurate information; one account per person.</li><li>Keep your password confidential; you are responsible for activity under your account.</li><li>Do not misuse the portal, attempt to bypass access controls, or share invite codes publicly.</li><li>Violation may result in account suspension per student handbook.</li></ul>
        <p class="text-xs text-slate-500 border-t pt-3 mt-2">By clicking “I Understand &amp; Agree” you give free, informed consent to the above and agree to comply with portal terms. For questions: <span class="font-mono bg-slate-100 px-1 rounded">datamex82@gmail.com</span></p>
        <div id="scrollSentinel" class="h-1"></div>
      </div>
      <div class="modal-footer border-t border-slate-200 bg-slate-50 px-5 py-3 flex justify-between items-center">
        <button type="button" class="text-xs font-medium text-slate-500 hover:text-slate-700" data-bs-dismiss="modal">Close without agreeing</button>
        <button type="button" id="privacyAgreeBtn" disabled class="px-5 py-2 rounded-xl bg-slate-300 text-white text-sm font-semibold cursor-not-allowed transition">Scroll to bottom to enable</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var pwd = document.getElementById('password');
  var confirm = document.getElementById('password_confirm');
  var bar = document.getElementById('pm-bar');
  var label = document.getElementById('pm-label');
  function setCheck(id, ok){
    var el = document.getElementById(id);
    if(!el) return;
    el.className = ok ? 'flex items-center gap-1.5 text-emerald-600 font-medium' : 'flex items-center gap-1.5 text-slate-500';
    el.querySelector('i').className = ok ? 'bi bi-check-circle-fill text-[11px]' : 'bi bi-circle text-[10px]';
  }
  function scorePassword(v){
    var s=0;
    if(v.length>=10) s++;
    if(v.length>=12) s++;
    if(/[a-z]/.test(v)) s++;
    if(/[A-Z]/.test(v)) s++;
    if(/[0-9]/.test(v)) s++;
    if(/[^A-Za-z0-9]/.test(v)) s++;
    if(v.length>=10 && /[a-z]/.test(v) && /[A-Z]/.test(v) && /[0-9]/.test(v)) s++;
    return Math.min(s,6);
  }
  function updateMeter(){
    var v = pwd.value || '';
    var c = confirm.value || '';
    var sc = scorePassword(v);
    var cfg = [
      {w:0, bg:'#e2e8f0', tx:'—', bgL:'bg-slate-200', txC:'text-slate-600'},
      {w:20, bg:'#ef4444', tx:'Very Weak', bgL:'bg-red-100', txC:'text-red-700'},
      {w:35, bg:'#f97316', tx:'Weak', bgL:'bg-orange-100', txC:'text-orange-700'},
      {w:55, bg:'#eab308', tx:'Fair', bgL:'bg-yellow-100', txC:'text-yellow-700'},
      {w:75, bg:'#3b82f6', tx:'Good', bgL:'bg-blue-100', txC:'text-blue-700'},
      {w:90, bg:'#22c55e', tx:'Strong', bgL:'bg-emerald-100', txC:'text-emerald-700'},
      {w:100, bg:'#059669', tx:'Excellent', bgL:'bg-emerald-100', txC:'text-emerald-700'},
    ];
    var idx = v ? (sc<=1?1: sc===2?2: sc===3?3: sc===4?4: sc===5?5:6) : 0;
    var cur = cfg[idx];
    bar.style.width = (v?cur.w:0)+'%';
    bar.style.background = cur.bg;
    label.textContent = v ? cur.tx : '—';
    label.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full '+cur.bgL+' '+cur.txC;
    setCheck('pm-len', v.length>=10);
    setCheck('pm-upper', /[A-Z]/.test(v));
    setCheck('pm-lower', /[a-z]/.test(v));
    setCheck('pm-num', /[0-9]/.test(v));
    setCheck('pm-spec', /[^A-Za-z0-9]/.test(v));
    setCheck('pm-match', v && c && v===c);
    if(v && c && v!==c) confirm.setCustomValidity('Passwords do not match');
    else confirm.setCustomValidity('');
  }
  pwd.addEventListener('input', updateMeter);
  confirm.addEventListener('input', updateMeter);
  updateMeter();

  document.querySelectorAll('[data-toggle-password]').forEach(function(b){
    b.addEventListener('click', function(){
      var inp = document.querySelector(b.getAttribute('data-toggle-password'));
      var ic = b.querySelector('i');
      var isPwd = inp.type==='password';
      inp.type = isPwd?'text':'password';
      ic.className = isPwd?'bi bi-eye-slash text-sm':'bi bi-eye text-sm';
    });
  });

  document.querySelectorAll('input[name="role"]').forEach(function(r){
    r.addEventListener('change', function(){
      document.querySelectorAll('input[name="role"]').forEach(function(x){
        var card = x.closest('label');
        var check = card.querySelector('.bi-check-circle-fill');
        if(x.checked){ card.classList.add('border-[#173B6C]','bg-slate-50'); card.classList.remove('border-slate-200','bg-white'); check.classList.remove('hidden'); }
        else { card.classList.remove('border-[#173B6C]','bg-slate-50'); card.classList.add('border-slate-200','bg-white'); check.classList.add('hidden'); }
      });
    });
  });

  var emailInput = document.getElementById('email');
  var phoneInput = document.getElementById('phone');
  var emailErr = document.getElementById('emailError');
  var phoneErr = document.getElementById('phoneError');
  var emailIcon = document.getElementById('emailIcon');
  var phoneIcon = document.getElementById('phoneIcon');
  var phoneCount = document.getElementById('phoneCount');
  function validateEmail(show){
    var v = emailInput.value.trim();
    var re = /^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/i;
    var ok = re.test(v) && !v.includes('..') && v.indexOf('@')>0 && v.lastIndexOf('.') > v.indexOf('@')+1;
    if(v===''){ emailInput.setCustomValidity('Email is required'); if(show){emailErr.classList.remove('hidden'); emailErr.querySelector('span').textContent='Email is required';} emailIcon.classList.add('hidden'); emailInput.classList.remove('border-emerald-400'); emailInput.classList.add('border-slate-200'); return false; }
    if(!ok){ emailInput.setCustomValidity('Enter a valid email like name@domain.com'); if(show){emailErr.classList.remove('hidden'); emailErr.querySelector('span').textContent='Wrong email format — use name@domain.com';} emailIcon.classList.add('hidden'); emailInput.classList.add('border-red-400'); emailInput.classList.remove('border-emerald-400'); return false; }
    emailInput.setCustomValidity(''); emailErr.classList.add('hidden'); emailIcon.classList.remove('hidden'); emailInput.classList.remove('border-red-400'); emailInput.classList.add('border-emerald-400'); return true;
  }
  function validatePhone(show){
    var v = phoneInput.value.replace(/\D/g,'').slice(0,11);
    phoneInput.value = v;
    if(phoneCount) phoneCount.textContent = v.length;
    if(phoneCount) phoneCount.className = v.length===11 ? 'text-emerald-600 font-semibold' : 'text-slate-500';
    if(v===''){ phoneInput.setCustomValidity('Mobile number is required (11 digits)'); if(show){phoneErr.classList.remove('hidden'); phoneErr.querySelector('span').textContent='Mobile is required — 11 digits starting with 09';} phoneIcon.classList.add('hidden'); phoneInput.classList.remove('border-emerald-400'); return false; }
    if(!/^09\d{9}$/.test(v)){ phoneInput.setCustomValidity('Must be 11 digits starting with 09'); if(show){phoneErr.classList.remove('hidden'); phoneErr.querySelector('span').textContent=v.length!==11 ? 'Must be exactly 11 digits ('+v.length+'/11)' : 'Must start with 09 and be 11 digits';} phoneIcon.classList.add('hidden'); phoneInput.classList.add('border-red-400'); return false; }
    phoneInput.setCustomValidity(''); phoneErr.classList.add('hidden'); phoneIcon.classList.remove('hidden'); phoneInput.classList.remove('border-red-400'); phoneInput.classList.add('border-emerald-400'); return true;
  }
  emailInput.addEventListener('input', function(){ validateEmail(false); });
  emailInput.addEventListener('blur', function(){ validateEmail(true); });
  phoneInput.addEventListener('input', function(){ validatePhone(false); });
  phoneInput.addEventListener('blur', function(){ validatePhone(true); });
  phoneInput.addEventListener('keydown', function(e){ if(!/[0-9]/.test(e.key) && !['Backspace','Delete','Tab','ArrowLeft','ArrowRight'].includes(e.key) && !e.ctrlKey) e.preventDefault(); });

  // Personal-name quality hints (mirror the server rules; server stays authoritative).
  var firstInput = document.getElementById('first_name');
  var lastInput = document.getElementById('last_name');
  var middleInput = document.getElementById('middle_name');
  var suffixInput = document.getElementById('suffix');
  function nameError(input, msg){
    var map = {first_name:['firstNameError','firstNameIcon'], last_name:['lastNameError','lastNameIcon'], middle_name:['middleNameError',null], suffix:['suffixError',null]};
    var ids = map[input.id] || [null,null];
    var errEl = ids[0] ? document.getElementById(ids[0]) : null;
    var iconEl = ids[1] ? document.getElementById(ids[1]) : null;
    if(msg){
      input.setCustomValidity(msg);
      if(errEl){ errEl.classList.remove('hidden'); errEl.querySelector('span').textContent = msg; }
      if(iconEl) iconEl.classList.add('hidden');
      input.classList.add('border-red-400'); input.classList.remove('border-emerald-400');
      return false;
    }
    input.setCustomValidity('');
    if(errEl) errEl.classList.add('hidden');
    if(input.value.trim() !== '' && iconEl){ iconEl.classList.remove('hidden'); input.classList.remove('border-red-400'); input.classList.add('border-emerald-400'); }
    else { if(iconEl) iconEl.classList.add('hidden'); input.classList.remove('border-red-400','border-emerald-400'); }
    return true;
  }
  // Pure check (no UI side effects) for the submit gate; UI version below.
  function nameOk(input, required){
    var v = input.value.trim();
    if(v === '') return !required;
    if(v.length < 2) return false;
    if((v.match(/\p{L}/gu) || []).length < 2) return false;
    if(/\d/.test(v)) return false;
    if(!/^[\p{L} .'\-’‘]+$/u.test(v)) return false;
    return true;
  }
  function checkName(input, label, required){
    var v = input.value.trim();
    if(v === '') return nameError(input, required ? (label + ' is required') : '');
    if(v.length < 2) return nameError(input, label + ': minimum 2 characters required');
    var letters = (v.match(/\p{L}/gu) || []).length;
    if(letters < 2) return nameError(input, label + ': must contain at least 2 letters');
    if(/\d/.test(v)) return nameError(input, label + ': must not contain numbers');
    if(!/^[\p{L} .'\-’‘]+$/u.test(v)) return nameError(input, label + ': invalid characters');
    return nameError(input, '');
  }
  function checkSuffix(){
    var v = suffixInput.value.trim();
    if(v === '') return nameError(suffixInput, '');
    if(/\d/.test(v)) return nameError(suffixInput, 'Suffix: must not contain numbers');
    if(!/^[\p{L}. ]+$/u.test(v)) return nameError(suffixInput, 'Suffix: invalid characters');
    return nameError(suffixInput, '');
  }
  [[firstInput,'First name',true],[lastInput,'Last name',true]].forEach(function(cfg){
    cfg[0].addEventListener('input', function(){ checkName(cfg[0], cfg[1], cfg[2]); });
    cfg[0].addEventListener('blur', function(){ checkName(cfg[0], cfg[1], cfg[2]); });
  });
  middleInput.addEventListener('input', function(){ checkName(middleInput, 'Middle name', false); });
  middleInput.addEventListener('blur', function(){ checkName(middleInput, 'Middle name', false); });
  suffixInput.addEventListener('input', checkSuffix);
  suffixInput.addEventListener('blur', checkSuffix);

  function initPrivacy(){
    if(typeof bootstrap === 'undefined'){ setTimeout(initPrivacy, 120); return; }
    var privacyViewed = false;
    var terms = document.getElementById('terms');
    var termsWrap = document.getElementById('termsWrap');
    var privacyBadge = document.getElementById('privacyBadge');
    var termsHint = document.getElementById('termsHint');
    var submitBtn = document.getElementById('submitBtn');
    var openBtn = document.getElementById('openPrivacy');
    var agreeBtn = document.getElementById('privacyAgreeBtn');
    var body = document.getElementById('privacyBody');
    var modalEl = document.getElementById('privacyModal');
    var bsModal = new bootstrap.Modal(modalEl);

    function refreshGate(){
      var emailOk = typeof validateEmail === 'function' ? validateEmail(false) : true;
      var phoneOk = typeof validatePhone === 'function' ? validatePhone(false) : true;
      var firstOk = (typeof nameOk === 'function' && typeof firstInput !== 'undefined') ? nameOk(firstInput, true) : true;
      var lastOk = (typeof nameOk === 'function' && typeof lastInput !== 'undefined') ? nameOk(lastInput, true) : true;
      var capInput = document.getElementById('reg_captcha');
      var capOk = !capInput || capInput.value.trim() !== '';
      var checked = terms.checked && privacyViewed && emailOk && phoneOk && firstOk && lastOk && capOk;
      submitBtn.disabled = !checked;
      if(privacyViewed){
        terms.disabled = false;
        termsWrap.classList.remove('opacity-60');
        termsWrap.classList.add('opacity-100');
        privacyBadge.className = 'ml-1 inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200';
        privacyBadge.innerHTML = '<i class="bi bi-check-circle-fill"></i> Reviewed — you may now agree';
        termsHint.innerHTML = '<span class="text-emerald-600 flex items-center gap-1"><i class="bi bi-check2"></i> Privacy notice reviewed. Check the box to enable registration.</span>';
        if(terms.checked){
          privacyBadge.className = 'ml-1 inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-emerald-600 text-white border border-emerald-600';
          privacyBadge.innerHTML = '<i class="bi bi-shield-check"></i> Consent given';
        }
      }
    }

    function openModal(){ bsModal.show(); }

    openBtn.addEventListener('click', function(e){ e.stopPropagation(); openModal(); });
    termsWrap.addEventListener('click', function(e){
      if(!privacyViewed){
        if(e.target !== openBtn){
          e.preventDefault();
          openModal();
        }
      }
    });
    terms.addEventListener('click', function(e){
      if(!privacyViewed){ e.preventDefault(); openModal(); }
    });
    terms.addEventListener('change', refreshGate);
    if(typeof emailInput !== 'undefined'){ emailInput.addEventListener('input', refreshGate); phoneInput.addEventListener('input', refreshGate); }
    if(typeof firstInput !== 'undefined'){ firstInput.addEventListener('input', refreshGate); lastInput.addEventListener('input', refreshGate); }
    var capField = document.getElementById('reg_captcha');
    if(capField) capField.addEventListener('input', refreshGate);

    body.addEventListener('scroll', function(){
      if(body.scrollTop + body.clientHeight >= body.scrollHeight - 8){
        agreeBtn.disabled = false;
        agreeBtn.classList.remove('bg-slate-300','cursor-not-allowed');
        agreeBtn.classList.add('bg-[#173B6C]','hover:bg-[#1D4A80]','cursor-pointer');
        agreeBtn.textContent = 'I Understand & Agree';
      }
    });
    modalEl.addEventListener('shown.bs.modal', function(){
      if(body.scrollHeight <= body.clientHeight + 4){
        agreeBtn.disabled = false;
        agreeBtn.classList.remove('bg-slate-300','cursor-not-allowed');
        agreeBtn.classList.add('bg-[#173B6C]','hover:bg-[#1D4A80]');
        agreeBtn.textContent = 'I Understand & Agree';
      }
    });
    agreeBtn.addEventListener('click', function(){
      privacyViewed = true;
      bsModal.hide();
      setTimeout(function(){ refreshGate(); terms.focus(); }, 150);
    });
    modalEl.addEventListener('hidden.bs.modal', refreshGate);

    document.getElementById('registerForm').addEventListener('submit', function(e){
      var eOk = validateEmail(true);
      var pOk = validatePhone(true);
      if(!eOk || !pOk){ e.preventDefault(); if(!eOk) emailInput.focus(); else phoneInput.focus(); return; }
      var fOk = checkName(firstInput, 'First name', true);
      var lOk = checkName(lastInput, 'Last name', true);
      var mOk = checkName(middleInput, 'Middle name', false);
      var sOk = checkSuffix();
      if(!fOk || !lOk || !mOk || !sOk){ e.preventDefault(); if(!fOk) firstInput.focus(); else if(!lOk) lastInput.focus(); return; }
      if(!privacyViewed || !terms.checked){
        e.preventDefault();
        openModal();
        termsHint.classList.add('animate-pulse');
        setTimeout(function(){ termsHint.classList.remove('animate-pulse'); }, 800);
        return;
      }
      // Prevent duplicate accounts from double-clicks / repeated submits.
      var btn = document.getElementById('submitBtn');
      if(btn.dataset.submitting === '1'){ e.preventDefault(); return; }
      btn.dataset.submitting = '1';
      btn.disabled = true;
      btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Creating account…';
    });
    refreshGate();
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initPrivacy);
  else initPrivacy();
})();
</script>
