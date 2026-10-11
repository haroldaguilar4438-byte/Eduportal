<?php
/** @var array $teachers @var array $invites */
$teachers = $teachers ?? [];
$invites  = $invites ?? [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-person-badge"></i> Teacher Accounts <span class="badge text-bg-light border"><?= count($teachers) ?></span></h1>
    <div class="d-flex gap-2">
        <a href="<?= e(url('/admin/teachers/invite')) ?>" class="btn btn-warning"><i class="bi bi-key"></i> Generate Invite Code</a>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTeacherModal"><i class="bi bi-person-plus"></i> Create Teacher Directly</button>
    </div>
</div>

<div class="alert alert-info py-2 small">
    <i class="bi bi-shield-lock"></i> <strong>Secure invite-only:</strong> Teacher self-registration requires a single-use code <code>TCH-XXXX-XXXX</code> (bcrypt-hashed, 7-day expiry, email-restrictable, audit-logged). Non-teachers who try <code>/register?role=teacher</code> are blocked. Admin can also bypass invite via “Create Directly”.
</div>

<div class="card bg-light border small p-3 mb-3" style="background:var(--surface-sunken)">
    <div class="fw-semibold mb-1"><i class="bi bi-lightbulb text-warning"></i> Tips — How to use this page</div>
    <ul class="mb-0 ps-3">
        <li>Click <strong>Generate Invite Code</strong>, copy the flash code once, and share it securely with the teacher.</li>
        <li>Click any invite row to see its full details — email, department, expiry, and attempts.</li>
        <li>Need an account right away? Use <strong>Create Teacher Directly</strong> to make it without a code.</li>
    </ul>
</div>

<!-- Teachers table -->
<div class="card p-3 mb-4">
    <h2 class="h6"><i class="bi bi-people"></i> Registered teachers</h2>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Employee No.</th><th>Name</th><th>Email</th><th>Dept / Position</th><th>Verified</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
            <?php if ($teachers===[]): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No teachers yet. Generate an invite or create directly.</td></tr>
            <?php endif; ?>
            <?php foreach ($teachers as $t): ?>
                <tr>
                    <td class="font-monospace fw-semibold"><?= e($t['employee_no']) ?></td>
                    <td><?= e(trim(($t['first_name']??'').' '.($t['last_name']??''))) ?></td>
                    <td><?= e($t['email']) ?></td>
                    <td class="small"><?= e($t['department'] ?? '—') ?><?= $t['position'] ? ' · '.e($t['position']) : '' ?><div class="small text-muted"><?= e($t['employment_status'] ?? '') ?></div></td>
                    <td><?php if ((int)$t['is_email_verified']): ?><span class="badge bg-success">Verified</span><?php else: ?><span class="badge bg-warning text-dark">Pending</span><?php endif; ?></td>
                    <td><span class="badge bg-light border text-dark"><?= e($t['status']) ?></span></td>
                    <td class="small text-muted"><?= e(date('M j, Y', strtotime($t['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Invites table -->
<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-key"></i> Recent invite codes (hashed, single-use)</h2>
        <span class="badge bg-primary bg-opacity-10 text-primary border"><i class="bi bi-hand-index"></i> Click row to view details</span>
    </div>
    <p class="small text-muted">Plain codes are shown <strong>once</strong> at generation (flash) and never stored. Only the hash + preview (last 4) remain. Share the flash code securely (email/print). Revoke deletes unused invites. <strong>Click any row to see full information.</strong></p>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Preview</th><th>Email hint</th><th>Dept</th><th>Creator</th><th>Expires</th><th>Used</th><th>Attempts</th><th></th></tr></thead>
            <tbody>
            <?php if ($invites===[]): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No invites generated yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($invites as $inv): 
                $isExpired = strtotime($inv['expires_at']) < time();
                $isUsed = (int)$inv['is_used'] === 1;
                $statusLabel = $isUsed ? 'Used' : ($isExpired ? 'Expired' : 'Active');
                $statusClass = $isUsed ? 'bg-secondary' : ($isExpired ? 'bg-danger' : 'bg-success');
            ?>
                <tr class="invite-row" role="button" tabindex="0" style="cursor:pointer" 
                    data-id="<?= e((string)$inv['id']) ?>"
                    data-preview="<?= e($inv['code_preview']) ?>"
                    data-email="<?= e($inv['email_hint'] ?? '—') ?>"
                    data-email-raw="<?= e($inv['email_hint'] ?? '') ?>"
                    data-dept="<?= e($inv['department'] ?? '—') ?>"
                    data-creator="<?= e($inv['creator_name'] ?? '—') ?>"
                    data-created="<?= e(date('M j, Y g:ia', strtotime($inv['created_at']))) ?>"
                    data-expires="<?= e(date('M j, Y g:ia', strtotime($inv['expires_at']))) ?>"
                    data-expires-raw="<?= e($inv['expires_at']) ?>"
                    data-used="<?= $isUsed ? e(date('M j, Y g:ia', strtotime($inv['used_at'])) ) : '' ?>"
                    data-used-raw="<?= e($inv['is_used'] ? '1' : '0') ?>"
                    data-is-expired="<?= $isExpired ? '1' : '0' ?>"
                    data-status="<?= e($statusLabel) ?>"
                    data-status-class="<?= e($statusClass) ?>"
                    data-attempts="<?= (int)$inv['attempts'] ?>/5"
                    data-attempts-raw="<?= (int)$inv['attempts'] ?>"
                    data-hash-exists="1"
                    title="Click to view invite details">
                    <td class="font-monospace"><span class="text-primary text-decoration-underline">…<?= e($inv['code_preview']) ?></span> <i class="bi bi-eye small text-muted"></i></td>
                    <td class="small"><?= e($inv['email_hint'] ?? '—') ?></td>
                    <td class="small"><?= e($inv['department'] ?? '—') ?></td>
                    <td class="small"><?= e($inv['creator_name'] ?? '') ?></td>
                    <td class="small <?= $isExpired ? 'text-danger':'' ?>"><?= e(date('M j, Y g:ia', strtotime($inv['expires_at']))) ?><?php if ($isExpired): ?> <span class="badge bg-danger">Expired</span><?php endif; ?></td>
                    <td><?php if ($isUsed): ?><span class="badge bg-secondary">Used <?= e(date('M j', strtotime($inv['used_at']))) ?></span><?php else: ?><span class="badge bg-success">Active</span><?php endif; ?></td>
                    <td><span class="badge bg-light border text-dark"><?= (int)$inv['attempts'] ?>/5</span></td>
                    <td onclick="event.stopPropagation()">
                        <?php if (!$isUsed): ?>
                            <form method="post" action="<?= e(url('/admin/teachers/invite/'.$inv['id'].'/revoke')) ?>" class="d-inline" onsubmit="return confirm('Revoke invite …<?= e($inv['code_preview']) ?>?')">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation()">Revoke</button>
                            </form>
                        <?php else: ?>
                            <span class="small text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Invite Detail Modal (click row to view information) -->
<div class="modal fade" id="inviteDetailModal" tabindex="-1" aria-labelledby="inviteDetailLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="inviteDetailLabel"><i class="bi bi-key"></i> Invite Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex align-items-center gap-2 mb-3 p-2 bg-light rounded-3 border">
            <span class="font-monospace fs-5">…<span id="detailPreview"></span></span>
            <span id="detailStatusBadge"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="navigator.clipboard.writeText(document.getElementById('detailPreview').textContent); this.textContent='Copied!'; setTimeout(()=>this.innerHTML='<i class=&quot;bi bi-clipboard&quot;></i> Copy preview',1500)"><i class="bi bi-clipboard"></i> Copy preview</button>
        </div>
        <dl class="row small mb-0">
            <dt class="col-4 text-muted">Invite ID</dt><dd class="col-8" id="detailId">—</dd>
            <dt class="col-4 text-muted">Code preview</dt><dd class="col-8 font-monospace" id="detailPreview2">—</dd>
            <dt class="col-4 text-muted">Email hint</dt><dd class="col-8" id="detailEmail">—</dd>
            <dt class="col-4 text-muted">Department</dt><dd class="col-8" id="detailDept">—</dd>
            <dt class="col-4 text-muted">Created by</dt><dd class="col-8" id="detailCreator">—</dd>
            <dt class="col-4 text-muted">Created at</dt><dd class="col-8" id="detailCreated">—</dd>
            <dt class="col-4 text-muted">Expires at</dt><dd class="col-8" id="detailExpires">—</dd>
            <dt class="col-4 text-muted">Used at</dt><dd class="col-8" id="detailUsed">—</dd>
            <dt class="col-4 text-muted">Attempts</dt><dd class="col-8"><span class="badge bg-light border text-dark" id="detailAttempts">—</span> <span class="text-muted">/5 before lock</span></dd>
            <dt class="col-4 text-muted">Hash</dt><dd class="col-8"><span class="badge bg-success bg-opacity-10 text-success border"><i class="bi bi-shield-lock"></i> bcrypt hashed (not plain)</span> <span class="small text-muted">Plain code never stored — encrypted copy available for secure reveal</span></dd>
        </dl>

        <!-- Secure Reveal (encrypted full code, password-protected, audit-logged) -->
        <div id="revealSection" class="mt-3 p-3 border rounded-3 bg-warning bg-opacity-10">
            <div class="d-flex justify-content-between align-items-center">
                <strong class="small"><i class="bi bi-shield-lock"></i> Secure full code</strong>
                <span id="revealStatus" class="badge bg-light border text-dark">Encrypted & audit-logged</span>
            </div>
            <div id="revealHidden" class="mt-2">
                <p class="small text-muted mb-2">Full code is <strong>AES-256-CBC encrypted</strong>. Only an admin who verifies their password can reveal it. Every reveal is logged. Auto-hides after 30s.</p>
                <div class="input-group input-group-sm">
                    <input type="password" id="revealPassword" class="form-control" placeholder="Enter your admin password to reveal">
                    <button type="button" class="btn btn-warning" id="revealBtn"><i class="bi bi-eye"></i> Reveal securely</button>
                </div>
                <div id="revealError" class="small text-danger mt-1 d-none"></div>
            </div>
            <div id="revealShown" class="mt-2 d-none">
                <div class="p-3 rounded-3 bg-success bg-opacity-10 border border-success py-2 mb-2" id="revealCodeBox">
                    <div class="small text-muted">Full invite code — copy quickly (auto-hides in <span id="revealTimer">30</span>s):</div>
                    <div class="font-monospace fs-5 fw-bold text-success" id="revealCode">TCH-XXXX-XXXX</div>
                    <button type="button" class="btn btn-sm btn-outline-success mt-1" id="copyFullBtn"><i class="bi bi-clipboard"></i> Copy full code</button>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="hideRevealBtn">Hide again</button>
            </div>
        </div>

        <div class="alert alert-warning py-2 small mt-3 mb-0">
            <i class="bi bi-info-circle"></i> Share the <strong>flash code</strong> shown once at generation (<code>TCH-XXXX-XXXX</code>) via secure channel. If you missed it, use <strong>Reveal securely</strong> above (requires password, logged) — full code works only while <span class="badge bg-success">Active</span>.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <a href="<?= e(url('/admin/teachers/invite')) ?>" class="btn btn-warning"><i class="bi bi-key"></i> Generate new invite</a>
      </div>
    </div>
  </div>
</div>

<script>
(function initInviteModal(){
    if(typeof bootstrap === 'undefined'){ setTimeout(initInviteModal, 100); return; }
    var modalEl = document.getElementById('inviteDetailModal');
    if(!modalEl) return;
    var bsModal = new bootstrap.Modal(modalEl);
    function openInviteDetail(row){
        document.getElementById('detailPreview').textContent = '…' + (row.dataset.preview || '');
        document.getElementById('detailPreview2').textContent = 'TCH-XXXX-' + (row.dataset.preview || '');
        document.getElementById('detailId').textContent = '#' + (row.dataset.id || '—');
        document.getElementById('detailEmail').textContent = row.dataset.email || '—';
        document.getElementById('detailDept').textContent = row.dataset.dept || '—';
        document.getElementById('detailCreator').textContent = row.dataset.creator || '—';
        document.getElementById('detailCreated').textContent = row.dataset.created || '—';
        var expiresEl = document.getElementById('detailExpires');
        expiresEl.textContent = row.dataset.expires || '—';
        if(row.dataset.isExpired === '1'){
            expiresEl.innerHTML = row.dataset.expires + ' <span class="badge bg-danger ms-1">Expired</span>';
        }
        var usedEl = document.getElementById('detailUsed');
        if(row.dataset.usedRaw === '1' && row.dataset.used){
            usedEl.textContent = row.dataset.used;
            usedEl.className = '';
        } else if(row.dataset.usedRaw === '1'){
            usedEl.textContent = 'Used (no timestamp)';
        } else {
            usedEl.innerHTML = '<span class="badge bg-success">Active — not yet used</span>';
        }
        document.getElementById('detailAttempts').textContent = row.dataset.attempts || '0/5';
        var statusBadge = document.getElementById('detailStatusBadge');
        statusBadge.className = 'badge ' + row.dataset.statusClass;
        statusBadge.textContent = row.dataset.status || '—';
        // setup reveal state for this invite
        currentInviteId = row.dataset.id;
        resetReveal();
        var isUsed = row.dataset.usedRaw==='1';
        var isExpired = row.dataset.isExpired==='1';
        var revealSection = document.getElementById('revealSection');
        var hasEnc = true; // assume yes, server will tell if not
        if(isUsed || isExpired){
            revealSection.style.opacity='0.5';
            document.getElementById('revealBtn').disabled=true;
            document.getElementById('revealBtn').innerHTML='<i class="bi bi-x-circle"></i> Not available';
            document.getElementById('revealPassword').disabled=true;
            document.getElementById('revealStatus').textContent = isUsed ? 'Used — cannot reveal' : 'Expired — cannot reveal';
            document.getElementById('revealStatus').className='badge bg-secondary';
        } else {
            revealSection.style.opacity='1';
            document.getElementById('revealBtn').disabled=false;
            document.getElementById('revealBtn').innerHTML='<i class="bi bi-eye"></i> Reveal securely';
            document.getElementById('revealPassword').disabled=false;
            document.getElementById('revealStatus').textContent='Encrypted & audit-logged';
            document.getElementById('revealStatus').className='badge bg-light border text-dark';
        }
        bsModal.show();
    }
    // Use event delegation so rows work without refresh and after any DOM update
    document.addEventListener('click', function(e){
        var row = e.target.closest('.invite-row');
        if(row){ openInviteDetail(row); }
    });
    document.addEventListener('keydown', function(e){
        if(e.key==='Enter' || e.key===' '){
            var row = e.target.closest('.invite-row');
            if(row){ e.preventDefault(); openInviteDetail(row); }
        }
    });

    // Secure reveal logic
    var currentInviteId = null;
    var revealTimerInt = null;
    function resetReveal(){
        var hidden = document.getElementById('revealHidden');
        var shown = document.getElementById('revealShown');
        var errEl = document.getElementById('revealError');
        if(hidden) hidden.classList.remove('d-none');
        if(shown) shown.classList.add('d-none');
        if(errEl) errEl.classList.add('d-none');
        var pwd = document.getElementById('revealPassword');
        if(pwd) pwd.value='';
        var codeEl = document.getElementById('revealCode');
        if(codeEl) codeEl.textContent='TCH-XXXX-XXXX';
        if(revealTimerInt){ clearInterval(revealTimerInt); revealTimerInt=null; }
        var timerEl = document.getElementById('revealTimer');
        if(timerEl) timerEl.textContent='30';
    }
    var revealBtn = document.getElementById('revealBtn');
    if(revealBtn){
        revealBtn.addEventListener('click', function(){
            var pwdEl = document.getElementById('revealPassword');
            var errEl = document.getElementById('revealError');
            var pwd = pwdEl ? pwdEl.value : '';
            if(!pwd){ if(errEl){ errEl.textContent='Please enter your admin password.'; errEl.classList.remove('d-none'); } return; }
            var btn = this; btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm"></span> Verifying...';
            if(errEl) errEl.classList.add('d-none');
            var csrfEl = document.querySelector('input[name="_csrf"]');
            var csrf = csrfEl ? csrfEl.value : '';
            fetch('<?= e(url('/admin/teachers/invite/')) ?>' + encodeURIComponent(currentInviteId) + '/reveal', {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                credentials:'same-origin',
                body: '_csrf=' + encodeURIComponent(csrf) + '&password=' + encodeURIComponent(pwd)
            }).then(function(r){ return r.json().then(function(j){ return {ok:r.ok, status:r.status, j:j}; }).catch(function(){ return r.text().then(function(t){ return {ok:false, j:{error:'Server error ('+r.status+'): '+t.substring(0,200)}}; }); }); }).then(function(res){
                btn.disabled=false; btn.innerHTML='<i class="bi bi-eye"></i> Reveal securely';
                if(!res.ok || !res.j.ok){ if(errEl){ errEl.textContent = res.j.error || 'Reveal failed'; errEl.classList.remove('d-none'); } return; }
                var codeEl = document.getElementById('revealCode');
                if(codeEl) codeEl.textContent = res.j.code;
                var hidden = document.getElementById('revealHidden');
                var shown = document.getElementById('revealShown');
                if(hidden) hidden.classList.add('d-none');
                if(shown) shown.classList.remove('d-none');
                var statusEl = document.getElementById('revealStatus');
                if(statusEl){ statusEl.textContent='Revealed — audit logged'; statusEl.className='badge bg-success'; }
                var left = 30;
                var timerEl = document.getElementById('revealTimer');
                if(timerEl) timerEl.textContent = left;
                revealTimerInt = setInterval(function(){
                    left--; if(timerEl) timerEl.textContent = left;
                    if(left<=0){ clearInterval(revealTimerInt); resetReveal(); }
                },1000);
            }).catch(function(e){
                btn.disabled=false; btn.innerHTML='<i class="bi bi-eye"></i> Reveal securely';
                if(errEl){ errEl.textContent='Network error: ' + (e.message || 'failed'); errEl.classList.remove('d-none'); console.error('Reveal fetch failed', e); }
            });
        });
    }
    var hideBtn = document.getElementById('hideRevealBtn');
    if(hideBtn) hideBtn.addEventListener('click', resetReveal);
    var copyBtn = document.getElementById('copyFullBtn');
    if(copyBtn) copyBtn.addEventListener('click', function(){
        var code = document.getElementById('revealCode').textContent;
        navigator.clipboard.writeText(code);
        this.innerHTML='<i class="bi bi-check"></i> Copied!';
        var self=this; setTimeout(function(){ self.innerHTML='<i class="bi bi-clipboard"></i> Copy full code'; },1500);
    });
    modalEl.addEventListener('hidden.bs.modal', resetReveal);
})();
</script>

<!-- Direct creation modal -->
<div class="modal fade" id="createTeacherModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog">
<form method="post" action="<?= e(url('/admin/teachers/create')) ?>" class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus"></i> Create teacher directly (bypass invite)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <?= csrf_field() ?>
        <div class="alert alert-secondary py-2 small">Creates verified teacher immediately. Use a strong temporary password and share securely. Employee No. auto-generated <code>T-YYYY-XXXX</code>.</div>
        <div class="row g-2">
            <div class="col-6"><label class="form-label">First name *</label><input name="first_name" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Last name *</label><input name="last_name" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Email *</label><input name="email" type="email" class="form-control" required placeholder="teacher@datamex.edu.ph"></div>
            <div class="col-12"><label class="form-label">Temporary password *</label><input name="password" type="password" class="form-control" required minlength="10" placeholder="10+ chars, U/lower + number"></div>
            <div class="col-6"><label class="form-label">Department</label><input name="department" class="form-control" placeholder="SHS / College"></div>
            <div class="col-6"><label class="form-label">Position</label><input name="position" class="form-control" placeholder="Instructor"></div>
            <div class="col-12"><label class="form-label">Phone</label><input name="phone" class="form-control"></div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Create teacher</button>
    </div>
</form>
</div>
</div>
