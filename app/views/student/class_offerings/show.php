<?php /** @var array $offering @var array $slots @var bool $isStudent */
$o = $offering; $avail = max(0,(int)$o['max_students']-(int)$o['enrolled_count']);
$mine = $o['my_status'] ?? null;
$acadCtx = $acadCtx ?? null;
$elig = $elig ?? null;
$estate = $elig['state'] ?? '';
$ereason = $elig['reason'] ?? '';
$elabel = $elig['label'] ?? '';
$ctxBand = $acadCtx['band'] ?? 'college';
$ctxNoun = $acadCtx['noun'] ?? 'Semester';
$ctxPeriodLabel = $acadCtx['period_label'] ?? '';
$ctxLevelLabel = $ctxBand === 'college' ? ($acadCtx['short_year'] ?? '') : ($acadCtx['level_name'] ?? '');
$offPeriod = trim((string)($o['period_code'] ?? ''));
if ($offPeriod === '') $offPeriod = trim((string)($o['semester'] ?? ''));
if (in_array($offPeriod, ['1st','2nd'], true)) $offPeriodLabel = $offPeriod . ' Semester';
elseif ($offPeriod !== '') $offPeriodLabel = $offPeriod;
else $offPeriodLabel = '—';
$actingChildSh = $actingChild ?? null;
$parentActingSh = !empty($isParent) && !empty($actingChildSh);
$backQ = $parentActingSh ? '?child_id=' . (int)$actingChildSh['id'] : '';
?>
<a href="<?= e(url('/student/class-offerings' . $backQ)) ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> Back to offerings</a>
<?php $viewerIsParentSh = class_exists(\App\Core\Auth::class) && \App\Core\Auth::is('parent'); ?>
<?php if ($parentActingSh): ?>
<div class="alert alert-info small py-2"><i class="bi bi-people"></i> Managing <strong><?= e($actingChildSh['name']) ?></strong> — eligibility below is checked against your child's enrollment. Joins you confirm are audit-logged under your parent account.</div>
<?php endif; ?>
<?php if ($acadCtx !== null && ($acadCtx['has_active_enrollment'] ?? false)): ?>
<div class="card p-3 mb-3 border-success" style="border-left:4px solid #198754 !important">
  <div class="small text-muted">MY CURRENT ACADEMIC PERIOD</div>
  <div class="d-flex flex-wrap gap-2 small mt-1">
    <span class="badge text-bg-light border">School Year: <strong><?= e($acadCtx['ay_label'] ?? '—') ?></strong></span>
    <span class="badge text-bg-light border"><?= e($ctxNoun) ?>: <strong><?= e($ctxPeriodLabel) ?></strong></span>
    <span class="badge text-bg-light border"><?= e($ctxBand === 'college' ? 'Year Level' : 'Grade Level') ?>: <strong><?= e($ctxLevelLabel) ?></strong></span>
    <?php if (!empty($acadCtx['program_code'])): ?><span class="badge text-bg-light border">Program: <strong><?= e($acadCtx['program_code']) ?></strong></span><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card p-4">
      <span class="badge text-bg-dark mb-2"><?= e($o['subject_code']) ?></span>
      <h1 class="h4 mb-1"><?= e($o['subject_title']) ?></h1>
      <p class="text-muted small">Section <?= e($o['section_name']) ?> · <?= e($o['level_name']??'') ?> · <?= e($o['school_year']) ?> · <?= e($offPeriodLabel) ?><?= !empty($o['program_code'] ?? '') ? ' · ' . e($o['program_code']) : '' ?></p>
      <?php if (!empty($o['subject_desc'])): ?><p><?= e($o['subject_desc']) ?></p><?php endif; ?>
      <dl class="row small mb-0">
        <dt class="col-4">Subject Code</dt><dd class="col-8"><?= e($o['subject_code']) ?></dd>
        <dt class="col-4">Subject Name</dt><dd class="col-8"><?= e($o['subject_title']) ?></dd>
        <dt class="col-4">Units</dt><dd class="col-8"><?= e(number_format((float)$o['units'],1)) ?></dd>
        <dt class="col-4">Instructor</dt><dd class="col-8"><?= trim($o['instructor']??'')!==''?e($o['instructor']):'TBA' ?><?= !empty($o['department'])?' ('.e($o['department']).')':'' ?></dd>
        <dt class="col-4">Section</dt><dd class="col-8"><?= e($o['section_name'] ?? '—') ?></dd>
        <dt class="col-4">School Year</dt><dd class="col-8"><?= e($o['school_year']) ?></dd>
        <dt class="col-4"><?= e($ctxNoun) ?></dt><dd class="col-8"><?= e($offPeriodLabel) ?></dd>
        <dt class="col-4"><?= e($ctxBand === 'college' ? 'Year Level' : 'Grade Level') ?></dt><dd class="col-8"><?= e(trim($o['level_name'] ?? '') !== '' ? $o['level_name'] : '—') ?></dd>
        <?php if (!empty($o['program_code'] ?? '') || !empty($o['program_name'] ?? '')): ?>
        <dt class="col-4">Program</dt><dd class="col-8"><?= e(trim(($o['program_code'] ?? '') . ' ' . ($o['program_name'] ?? ''))) ?></dd>
        <?php endif; ?>
        <dt class="col-4">Schedule</dt><dd class="col-8"><?= e($o['schedule_note']?:'To be announced') ?></dd>
        <dt class="col-4">Room</dt><dd class="col-8"><?= e($o['room']?:'—') ?></dd>
        <dt class="col-4">Class status</dt><dd class="col-8"><span class="badge text-bg-<?= ($o['status']??$o['co_status']??'open')==='open'?'success':'secondary' ?>"><?= e(ucfirst($o['status']??$o['co_status']??'open')) ?></span></dd>
        <dt class="col-4">Available slots</dt><dd class="col-8"><?= $avail ?> slot(s) left · <?= (int)$o['enrolled_count'] ?> / <?= (int)$o['max_students'] ?> enrolled</dd>
      </dl>
      <?php if ($slots !== []): ?>
        <h2 class="h6 mt-3">Weekly meetings</h2>
        <ul class="list-group list-group-flush small">
          <?php foreach ($slots as $s): ?>
            <li class="list-group-item px-0"><?= e($s['day']) ?> · <?= e($s['start']) ?> – <?= e($s['end']) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card p-4 text-center">
      <h2 class="h6">Availability</h2>
      <div class="display-6 fw-bold"><?= $avail ?></div>
      <p class="small text-muted mb-2">slot(s) left · <?= (int)$o['enrolled_count'] ?> / <?= (int)$o['max_students'] ?> enrolled</p>
      <?php $showEligSh = (!empty($isStudent) || $viewerIsParentSh) && $elig !== null; ?>
      <?php if ($showEligSh): ?>
        <?php if ($mine || $estate === 'joined'): ?>
          <span class="badge text-bg-success mb-2">Joined</span>
          <a href="<?= e(url('/student/my-classes' . $backQ)) ?>" class="btn btn-outline-success w-100">Go to My Subjects</a>
        <?php elseif ($estate === 'eligible' && !empty($isStudent)): ?>
          <div class="alert alert-success small py-2">ELIGIBLE FOR THIS SUBJECT</div>
          <a href="<?= e(url('/dashboard')) ?>" class="btn btn-primary w-100"><i class="bi bi-key"></i> Join Subject</a>
          <p class="small text-muted mt-2 mb-2">Go to Dashboard → Validate &amp; preview → Confirm join for <?= e($o['subject_code']) ?>.</p>
          <form method="post" action="<?= e(url('/student/classrooms/request')) ?>" onsubmit="return confirm('Request to join <?= e($o['subject_code']) ?>? No code needed — the teacher will verify.')"><?= csrf_field() ?><input type="hidden" name="classroom_id" value="<?= (int)$o['id'] ?>"><button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-send"></i> Request to Join (no code)</button></form>
        <?php elseif ($estate === 'eligible' && $parentActingSh): ?>
          <div class="alert alert-success small py-2">ELIGIBLE — <?= e($actingChildSh['name']) ?> can join</div>
          <form method="post" action="<?= e(url('/student/classrooms/request')) ?>" class="mb-2" onsubmit="return confirm('Request for <?= e($actingChildSh['name']) ?> to join <?= e($o['subject_code']) ?>? No code needed — the teacher will verify.')"><?= csrf_field() ?><input type="hidden" name="classroom_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="child_id" value="<?= (int)$actingChildSh['id'] ?>"><button type="submit" class="btn btn-success w-100"><i class="bi bi-send"></i> Request to Join <?= e($actingChildSh['name']) ?> (no code)</button></form>
          <form method="post" action="<?= e(url('/student/classrooms/join')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="child_id" value="<?= (int)$actingChildSh['id'] ?>">
            <label class="form-label small fw-semibold" for="join_code">Or join now with student invite code</label>
            <div class="input-group input-group-sm mb-2">
              <input id="join_code" name="invite_code" class="form-control font-monospace" placeholder="e.g. X7K9M2" required maxlength="12" style="text-transform:uppercase">
              <button class="btn btn-primary" type="submit" onclick="return confirm('Enroll <?= e($actingChildSh['name']) ?> in <?= e($o['subject_code']) ?>?')"><i class="bi bi-check-lg"></i> Enroll</button>
            </div>
            <p class="small text-muted mb-0">Get the code from the teacher. Eligibility is re-checked on submit.</p>
          </form>
        <?php else: ?>
          <div class="alert alert-secondary small py-2 mb-2">NOT ELIGIBLE — <?= e($elabel !== '' ? $elabel : 'Not available') ?></div>
          <?php if ($ereason !== ''): ?><p class="small text-muted">Reason: <?= e($ereason) ?></p><?php endif; ?>
          <span class="badge text-bg-<?= $avail<=0?'danger':'secondary' ?>"><?= e($elabel !== '' ? $elabel : ($avail<=0?'Full':'Not available')) ?></span>
        <?php endif; ?>
      <?php elseif ($mine): ?>
        <span class="badge text-bg-success mb-2">Joined</span>
        <a href="<?= e(url('/dashboard')) ?>" class="btn btn-outline-success w-100">Go to My Subjects</a>
      <?php elseif (!empty($isStudent) && ($o['status']??$o['co_status']??'open')==='open' && $avail>0): ?>
        <a href="<?= e(url('/dashboard')) ?>" class="btn btn-primary w-100"><i class="bi bi-key"></i> Join with student invite code</a>
        <p class="small text-muted mt-2 mb-0">Go to Dashboard → Validate & preview → Confirm join for <?= e($o['subject_code']) ?>.</p>
      <?php else: ?>
        <span class="badge text-bg-<?= $avail<=0?'danger':'secondary' ?>"><?= $avail<=0?'Full':'Not available' ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
document.getElementById('regForm')?.addEventListener('submit', function(ev){
  if(!confirm('Register for <?= addslashes($o['subject_code']) ?> — <?= addslashes($o['section_name']) ?>?')){ ev.preventDefault(); return; }
  var b = document.getElementById('regBtn'); if(b){ b.disabled = true; b.textContent = 'Registering…'; }
});
</script>
