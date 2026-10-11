<?php
$old = $old ?? [];
$errors = $errors ?? [];

/* Heading is rendered by shared/layout_auth; the view previously emitted its
   own <h1> as well, leaving two on the page. */
$authHeading    = 'Teacher registration';
$authSubheading = 'Invite only. Codes are single-use, bcrypt-hashed, and expire in 7 days.';
?>
    <a href="<?= e(url('/register')) ?>" class="d-inline-flex align-items-center gap-1 small fw-medium text-decoration-none mb-3" style="color:var(--text-secondary)">
        <i class="bi bi-arrow-left"></i> Back to student registration
    </a>

    <?= edu_callout(
        'Non-teachers cannot self-register as teacher. Ask your <strong>admin</strong> to generate an invite at <code>Admin &rarr; Teachers &rarr; Generate Invite</code> (format <code>TCH-XXXX-XXXX</code>). Admin can also create teachers directly.',
        ['tone' => 'warning', 'icon' => 'bi-info-circle', 'title' => null]
    ) ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger py-2">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle"></i> Please fix the following</div>
            <ul class="mb-0 ps-3 small"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/register-teacher')) ?>" novalidate id="teacherForm" class="space-y-4">
        <?= csrf_field() ?>

        <div class="rounded-xl border-2 border-yellow-300 bg-yellow-50/70 p-4">
            <label for="invite_code" class="block text-xs font-bold text-yellow-900 mb-1.5 flex items-center gap-1.5"><i class="bi bi-key"></i> Teacher invite code <span class="text-red-500">*</span></label>
            <input id="invite_code" name="invite_code" required placeholder="TCH-AB12-CD34" maxlength="13" style="letter-spacing:.08em;text-transform:uppercase"
                value="<?= e($old['invite_code'] ?? '') ?>"
                class="w-full px-3 py-2.5 bg-white border border-yellow-300 rounded-xl text-sm font-mono font-semibold text-[#173B6C] placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-yellow-400/30 focus:border-yellow-500 transition">
            <p class="text-[11px] text-yellow-800/80 mt-1.5">Format <span class="font-mono bg-white border border-yellow-200 px-1 rounded">TCH-XXXX-XXXX</span> (A-Z, 2-9 excluding 0/O/1/I). Paste from admin email. Single-use — consumed on success.</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="first_name" class="block text-xs font-semibold text-slate-700 mb-1">First name <span class="text-red-500">*</span></label>
                <input id="first_name" name="first_name" required value="<?= e($old['first_name'] ?? '') ?>" placeholder="Juan"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
            </div>
            <div>
                <label for="last_name" class="block text-xs font-semibold text-slate-700 mb-1">Last name <span class="text-red-500">*</span></label>
                <input id="last_name" name="last_name" required value="<?= e($old['last_name'] ?? '') ?>" placeholder="Dela Cruz"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
            </div>
            <div>
                <label for="middle_name" class="block text-xs font-semibold text-slate-700 mb-1">Middle name</label>
                <input id="middle_name" name="middle_name" value="<?= e($old['middle_name'] ?? '') ?>" placeholder="Optional"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
            </div>
            <div>
                <label for="suffix" class="block text-xs font-semibold text-slate-700 mb-1">Suffix</label>
                <input id="suffix" name="suffix" placeholder="Jr, III..." value="<?= e($old['suffix'] ?? '') ?>"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="department" class="block text-xs font-semibold text-slate-700 mb-1">Department</label>
                <input id="department" name="department" placeholder="e.g., SHS, College, IT" value="<?= e($old['department'] ?? '') ?>"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
            </div>
            <div>
                <label for="position" class="block text-xs font-semibold text-slate-700 mb-1">Position</label>
                <input id="position" name="position" placeholder="e.g., Instructor" value="<?= e($old['position'] ?? '') ?>"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
            </div>
        </div>

        <div>
            <label for="email" class="block text-xs font-semibold text-slate-700 mb-1">Work email <span class="text-red-500">*</span></label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"><i class="bi bi-envelope"></i></span>
                <input type="email" id="email" name="email" required value="<?= e($old['email'] ?? '') ?>" placeholder="teacher@datamex.edu.ph"
                    pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}"
                    class="w-full pl-9 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
                <span id="emailIconT" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"><i class="bi bi-check-circle-fill text-emerald-500"></i></span>
            </div>
            <p id="emailErrorT" class="hidden text-[11px] text-red-600 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i> <span></span></p>
            <p class="text-[11px] text-slate-500 mt-1">If invite was restricted to a specific email, you must use that exact email. Wrong format blocks registration.</p>
        </div>

        <div>
            <label for="phone" class="block text-xs font-semibold text-slate-700 mb-1">Mobile <span class="text-red-500">*</span> <span class="text-[11px] font-normal text-slate-500">(11 digits)</span></label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"><i class="bi bi-phone"></i></span>
                <input type="tel" id="phone" name="phone" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" minlength="11" required placeholder="09XXXXXXXXX" value="<?= e($old['phone'] ?? '') ?>"
                    class="w-full pl-9 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
                <span id="phoneIconT" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"><i class="bi bi-check-circle-fill text-emerald-500"></i></span>
            </div>
            <p id="phoneErrorT" class="hidden text-[11px] text-red-600 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i> <span></span></p>
            <p class="text-[11px] text-slate-500 mt-1"><span id="phoneCountT">0</span>/11 digits · Must start with <strong>09</strong> — only numbers.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label for="password" class="block text-xs font-semibold text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password" placeholder="••••••••••"
                        class="w-full pl-3 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
                    <button type="button" data-toggle-password="#password" class="absolute right-2 top-1/2 -translate-y-1/2 w-7 h-7 grid place-items-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"><i class="bi bi-eye text-sm"></i></button>
                </div>
            </div>
            <div>
                <label for="password_confirm" class="block text-xs font-semibold text-slate-700 mb-1">Confirm password <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password" placeholder="••••••••••"
                        class="w-full pl-3 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400/20 focus:border-yellow-500 focus:bg-white transition">
                    <button type="button" data-toggle-password="#password_confirm" class="absolute right-2 top-1/2 -translate-y-1/2 w-7 h-7 grid place-items-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"><i class="bi bi-eye text-sm"></i></button>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 space-y-2.5">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-700">Password strength</span>
                <span id="pm-label" class="text-xs font-bold px-2 py-0.5 rounded-full bg-slate-200 text-slate-600">—</span>
            </div>
            <div class="h-2 bg-slate-200 rounded-full overflow-hidden flex">
                <div id="pm-bar" class="h-full rounded-full transition-all duration-300" style="width:0%"></div>
            </div>
            <ul class="grid grid-cols-2 gap-1.5 text-[11px]">
                <li id="pm-len" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> 10+ characters</li>
                <li id="pm-upper" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Uppercase</li>
                <li id="pm-lower" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Lowercase</li>
                <li id="pm-num" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Number</li>
                <li id="pm-spec" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Special char (bonus)</li>
                <li id="pm-match" class="flex items-center gap-1.5 text-slate-500"><i class="bi bi-circle text-[10px]"></i> Passwords match</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-3.5">
            <div id="termsWrap" class="flex gap-3 select-none opacity-60 cursor-pointer">
                <input type="checkbox" id="terms2" name="terms" value="1" class="mt-0.5 w-4 h-4 rounded border-slate-300 text-yellow-600 focus:ring-yellow-500 disabled:cursor-not-allowed shrink-0" disabled required>
                <label for="terms2" class="text-xs leading-relaxed text-slate-700 cursor-pointer">
                    I confirm I am authorized teaching staff and agree to the
                    <button type="button" id="openPrivacy" class="font-semibold text-yellow-700 underline decoration-2 underline-offset-2 hover:text-yellow-800">Data Privacy Notice &amp; Teacher Terms</button>.
                    <span id="privacyBadge" class="ml-1 inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 border border-yellow-200"><i class="bi bi-exclamation-circle"></i> Required — click to review</span>
                </label>
            </div>
            <p id="termsHint" class="text-[11px] text-yellow-600 mt-2 flex items-center gap-1"><i class="bi bi-lock"></i> You must open and acknowledge the Data Privacy Notice before creating a teacher account.</p>
        </div>

        <button type="submit" id="submitBtn" disabled class="w-full py-2.5 rounded-xl bg-yellow-500 hover:bg-yellow-600 text-white text-sm font-bold shadow-sm flex items-center justify-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed transition">
            <i class="bi bi-person-badge"></i> Create teacher account
        </button>
    </form>

    <div class="flex flex-wrap gap-2 justify-between items-center mt-6 text-xs">
        <a href="<?= e(url('/login')) ?>" class="font-medium text-slate-600 hover:text-[#173B6C]">Already have an account? Sign in</a>
        <span class="inline-flex items-center gap-1 text-[11px] text-slate-500"><i class="bi bi-shield-check"></i> Invite codes: bcrypt-hashed, single-use, 7-day expiry.</span>
    </div>

<div class="modal fade" id="privacyModal" tabindex="-1" aria-hidden="true" aria-labelledby="privacyModalTitleT">
  <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
    <div class="modal-content rounded-2xl overflow-hidden border-0 shadow-xl">
      <div class="modal-header border-b bg-yellow-50 px-5 py-4">
        <div>
          <h2 id="privacyModalTitleT" class="font-display font-bold text-base text-[#173B6C] flex items-center gap-2"><i class="bi bi-shield-check text-yellow-600"></i> Data Privacy Notice — Teacher Portal</h2>
          <p class="text-xs text-slate-500 mt-0.5">Datamex College of Saint Adeline — EduPortal &middot; <?= e(date('F Y')) ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div id="privacyBody" class="modal-body px-5 py-4 space-y-4 text-[13px] leading-relaxed text-slate-700" style="max-height:60vh; overflow-y:auto">
        <div class="rounded-xl bg-yellow-50 border border-yellow-200 px-3.5 py-3 text-xs text-yellow-900 flex gap-2"><i class="bi bi-info-circle mt-0.5"></i><span><strong>Scroll to the bottom</strong> to enable “I Understand” — required under Data Privacy Act of 2012 (RA 10173). Teacher data is subject to additional professional record-keeping.</span></div>
        <h3 class="font-semibold text-[#173B6C] text-sm">1. What we collect (teachers)</h3>
        <p>Name, work email, phone, department/position, employee number (auto-generated), invite code verification trail, credentials (bcrypt-hashed), and audit logs (IP, timestamps). Class assignments and permit actions are logged separately.</p>
        <h3 class="font-semibold text-[#173B6C] text-sm">2. Purpose</h3>
        <ul class="list-disc ps-5 space-y-1"><li>Verify your authorization via invite code (single-use, hashed, 7-day expiry, email-restricted when applicable).</li><li>Create and secure your teacher account, including email verification and recovery.</li><li>Enable classroom, grading, and permit review duties.</li><li>Maintain accountable audit trails required for academic governance.</li></ul>
        <h3 class="font-semibold text-[#173B6C] text-sm">3. Invite code security</h3>
        <p>Invite codes are generated by admins, shown once, and stored only as bcrypt hashes. Each code is single-use, expires in 7 days, locks after 5 failed attempts, and is audit-logged on use. Admins can also create teacher accounts directly without an invite.</p>
        <h3 class="font-semibold text-[#173B6C] text-sm">4. Sharing &amp; retention</h3>
        <p>Access limited to authorized admins/HR on a need-to-know basis. We do not sell data. Records retained while employed and per school retention policy (typically 5 years post-separation) unless law requires longer.</p>
        <h3 class="font-semibold text-[#173B6C] text-sm">5. Your rights &amp; obligations</h3>
        <ul class="list-disc ps-5 space-y-1"><li>You may access/correct your data via HR/registrar; deletion subject to employment/academic record obligations.</li><li>You must keep your invite code and password confidential; never share codes publicly.</li><li>Keep your work email secure — restricted invites will reject mismatched emails.</li><li>Misuse may result in suspension per faculty handbook and Data Privacy Act.</li></ul>
        <h3 class="font-semibold text-[#173B6C] text-sm">6. Teacher Terms</h3>
        <ul class="list-disc ps-5 space-y-1"><li>Confirm you are authorized teaching staff of Datamex College of Saint Adeline.</li><li>Use the portal only for legitimate educational duties.</li><li>Protect student data you access; do not disclose outside authorized channels.</li></ul>
        <p class="text-xs text-slate-500 border-t pt-3">By clicking “I Understand &amp; Agree” you give informed consent and certify you are authorized staff. Questions: <span class="font-mono bg-slate-100 px-1 rounded">datamex82@gmail.com</span> / HR Office.</p>
        <div id="scrollSentinel" class="h-1"></div>
      </div>
      <div class="modal-footer border-t bg-slate-50 px-5 py-3 flex justify-between items-center">
        <button type="button" class="text-xs font-medium text-slate-500 hover:text-slate-700" data-bs-dismiss="modal">Close without agreeing</button>
        <button type="button" id="privacyAgreeBtn" disabled class="px-5 py-2 rounded-xl bg-slate-300 text-white text-sm font-semibold cursor-not-allowed transition">Scroll to bottom to enable</button>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('invite_code')?.addEventListener('input', e => e.target.value = e.target.value.toUpperCase());
(function(){
  var pwd=document.getElementById('password'), confirm=document.getElementById('password_confirm'), bar=document.getElementById('pm-bar'), label=document.getElementById('pm-label');
  function setCheck(id, ok){
    var el=document.getElementById(id);
    el.className= ok?'flex items-center gap-1.5 text-emerald-600 font-medium':'flex items-center gap-1.5 text-slate-500';
    el.querySelector('i').className= ok?'bi bi-check-circle-fill text-[11px]':'bi bi-circle text-[10px]';
  }
  function score(v){
    var s=0;
    if(v.length>=10) s++; if(v.length>=12) s++; if(/[a-z]/.test(v)) s++; if(/[A-Z]/.test(v)) s++; if(/[0-9]/.test(v)) s++; if(/[^A-Za-z0-9]/.test(v)) s++;
    if(v.length>=10 && /[a-z]/.test(v) && /[A-Z]/.test(v) && /[0-9]/.test(v)) s++;
    return Math.min(s,6);
  }
  function update(){
    var v=pwd.value||'', c=confirm.value||'', sc=score(v);
    var cfg=[{w:0,bg:'#e2e8f0',tx:'—',b:'bg-slate-200',t:'text-slate-600'},{w:20,bg:'#ef4444',tx:'Very Weak',b:'bg-red-100',t:'text-red-700'},{w:35,bg:'#f97316',tx:'Weak',b:'bg-orange-100',t:'text-orange-700'},{w:55,bg:'#eab308',tx:'Fair',b:'bg-yellow-100',t:'text-yellow-700'},{w:75,bg:'#3b82f6',tx:'Good',b:'bg-blue-100',t:'text-blue-700'},{w:90,bg:'#22c55e',tx:'Strong',b:'bg-emerald-100',t:'text-emerald-700'},{w:100,bg:'#059669',tx:'Excellent',b:'bg-emerald-100',t:'text-emerald-700'}];
    var idx=v? (sc<=1?1: sc===2?2: sc===3?3: sc===4?4: sc===5?5:6):0;
    var cur=cfg[idx];
    bar.style.width=(v?cur.w:0)+'%'; bar.style.background=cur.bg;
    label.textContent=v?cur.tx:'—'; label.className='text-xs font-bold px-2.5 py-0.5 rounded-full '+cur.b+' '+cur.t;
    setCheck('pm-len', v.length>=10); setCheck('pm-upper', /[A-Z]/.test(v)); setCheck('pm-lower', /[a-z]/.test(v)); setCheck('pm-num', /[0-9]/.test(v)); setCheck('pm-spec', /[^A-Za-z0-9]/.test(v)); setCheck('pm-match', v&&c&&v===c);
    if(v&&c&&v!==c) confirm.setCustomValidity('Passwords do not match'); else confirm.setCustomValidity('');
  }
  pwd.addEventListener('input', update); confirm.addEventListener('input', update); update();
  document.querySelectorAll('[data-toggle-password]').forEach(function(b){
    b.addEventListener('click', function(){
      var inp=document.querySelector(b.getAttribute('data-toggle-password')), ic=b.querySelector('i');
      var isPwd=inp.type==='password'; inp.type=isPwd?'text':'password'; ic.className=isPwd?'bi bi-eye-slash text-sm':'bi bi-eye text-sm';
    });
  });
  var emailInput=document.getElementById('email'), phoneInput=document.getElementById('phone');
  var emailErr=document.getElementById('emailErrorT'), phoneErr=document.getElementById('phoneErrorT');
  var emailIcon=document.getElementById('emailIconT'), phoneIcon=document.getElementById('phoneIconT');
  var phoneCount=document.getElementById('phoneCountT');
  function validateEmail(show){
    var v=emailInput.value.trim();
    var re=/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/i;
    var ok=re.test(v) && !v.includes('..') && v.indexOf('@')>0 && v.lastIndexOf('.')>v.indexOf('@')+1;
    if(v===''){ emailInput.setCustomValidity('Work email is required'); if(show){emailErr.classList.remove('hidden'); emailErr.querySelector('span').textContent='Work email is required';} emailIcon.classList.add('hidden'); return false; }
    if(!ok){ emailInput.setCustomValidity('Enter a valid email'); if(show){emailErr.classList.remove('hidden'); emailErr.querySelector('span').textContent='Wrong email format — use name@domain.com';} emailIcon.classList.add('hidden'); emailInput.classList.add('border-red-400'); return false; }
    emailInput.setCustomValidity(''); emailErr.classList.add('hidden'); emailIcon.classList.remove('hidden'); emailInput.classList.remove('border-red-400'); emailInput.classList.add('border-emerald-400'); return true;
  }
  function validatePhone(show){
    var v=phoneInput.value.replace(/\D/g,'').slice(0,11);
    phoneInput.value=v;
    if(phoneCount) phoneCount.textContent=v.length;
    if(v===''){ phoneInput.setCustomValidity('Mobile is required (11 digits)'); if(show){phoneErr.classList.remove('hidden'); phoneErr.querySelector('span').textContent='Mobile is required — 11 digits starting with 09';} phoneIcon.classList.add('hidden'); return false; }
    if(!/^09\d{9}$/.test(v)){ phoneInput.setCustomValidity('Must be 11 digits starting with 09'); if(show){phoneErr.classList.remove('hidden'); phoneErr.querySelector('span').textContent=v.length!==11 ? 'Must be exactly 11 digits ('+v.length+'/11)' : 'Must start with 09';} phoneIcon.classList.add('hidden'); phoneInput.classList.add('border-red-400'); return false; }
    phoneInput.setCustomValidity(''); phoneErr.classList.add('hidden'); phoneIcon.classList.remove('hidden'); phoneInput.classList.remove('border-red-400'); phoneInput.classList.add('border-emerald-400'); return true;
  }
  emailInput.addEventListener('input', function(){ validateEmail(false); });
  emailInput.addEventListener('blur', function(){ validateEmail(true); });
  phoneInput.addEventListener('input', function(){ validatePhone(false); });
  phoneInput.addEventListener('blur', function(){ validatePhone(true); });
  phoneInput.addEventListener('keydown', function(e){ if(!/[0-9]/.test(e.key) && !['Backspace','Delete','Tab','ArrowLeft','ArrowRight'].includes(e.key) && !e.ctrlKey) e.preventDefault(); });
  function initPrivacy(){
    if(typeof bootstrap === 'undefined'){ setTimeout(initPrivacy, 120); return; }
    var privacyViewed=false, terms=document.getElementById('terms2'), termsWrap=document.getElementById('termsWrap'), badge=document.getElementById('privacyBadge'), hint=document.getElementById('termsHint'), submitBtn=document.getElementById('submitBtn'), openBtn=document.getElementById('openPrivacy'), agreeBtn=document.getElementById('privacyAgreeBtn'), body=document.getElementById('privacyBody'), modalEl=document.getElementById('privacyModal'), bsModal=new bootstrap.Modal(modalEl);
    function refresh(){
      var emailOk = typeof validateEmail==='function' ? validateEmail(false) : true;
      var phoneOk = typeof validatePhone==='function' ? validatePhone(false) : true;
      var ok=terms.checked && privacyViewed && emailOk && phoneOk;
      submitBtn.disabled=!ok;
      if(privacyViewed){
        terms.disabled=false; termsWrap.classList.remove('opacity-60'); termsWrap.classList.add('opacity-100');
        badge.className='ml-1 inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200';
        badge.innerHTML='<i class="bi bi-check-circle-fill"></i> Reviewed — you may now agree';
        hint.innerHTML='<span class="text-emerald-600 flex items-center gap-1"><i class="bi bi-check2"></i> Privacy notice reviewed. Check the box to enable.</span>';
        if(terms.checked){ badge.className='ml-1 inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-emerald-600 text-white border border-emerald-600'; badge.innerHTML='<i class="bi bi-shield-check"></i> Consent given'; }
      }
    }
    function openModal(){ bsModal.show(); }
    openBtn.addEventListener('click', function(e){ e.stopPropagation(); openModal(); });
    termsWrap.addEventListener('click', function(e){
      if(!privacyViewed){ if(e.target !== openBtn){ e.preventDefault(); openModal(); } }
    });
    terms.addEventListener('click', function(e){ if(!privacyViewed){ e.preventDefault(); openModal(); } });
    terms.addEventListener('change', refresh);
    emailInput.addEventListener('input', refresh); phoneInput.addEventListener('input', refresh);
    body.addEventListener('scroll', function(){
      if(body.scrollTop + body.clientHeight >= body.scrollHeight - 8){
        agreeBtn.disabled=false; agreeBtn.classList.remove('bg-slate-300','cursor-not-allowed'); agreeBtn.classList.add('bg-yellow-500','hover:bg-yellow-600','cursor-pointer'); agreeBtn.textContent='I Understand & Agree';
      }
    });
    modalEl.addEventListener('shown.bs.modal', function(){ if(body.scrollHeight <= body.clientHeight+4){ agreeBtn.disabled=false; agreeBtn.classList.remove('bg-slate-300','cursor-not-allowed'); agreeBtn.classList.add('bg-yellow-500','hover:bg-yellow-600'); agreeBtn.textContent='I Understand & Agree'; } });
    agreeBtn.addEventListener('click', function(){ privacyViewed=true; bsModal.hide(); setTimeout(function(){ refresh(); terms.focus(); },150); });
    modalEl.addEventListener('hidden.bs.modal', refresh);
    document.getElementById('teacherForm').addEventListener('submit', function(e){ var eOk=validateEmail(true); var pOk=validatePhone(true); if(!eOk || !pOk){ e.preventDefault(); if(!eOk) emailInput.focus(); else phoneInput.focus(); return; } if(!privacyViewed || !terms.checked){ e.preventDefault(); openModal(); }});
    refresh();
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', initPrivacy);
  else initPrivacy();
})();
</script>
