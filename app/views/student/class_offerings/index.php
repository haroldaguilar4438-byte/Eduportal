<?php
/** @var array $rows @var int $total @var int $page @var int $perPage @var array $perPageOptions @var int $totalPages @var array $filters @var array $academicYears @var array $programs @var array $gradeLevels @var array $subjects @var array $semesters @var array $departments @var bool $needsMigration @var bool $isStudent */
$f = $filters ?? [];
$acadCtx = $acadCtx ?? null;
$locked = !empty($locked);
$blockReason = (string)($blockReason ?? '');
$studentLocked = $locked && !empty($isStudent);
$browseAll = !empty($browseAll) && (!empty($isStudent) || !empty($isParent));
// Parent acting on behalf of a linked child: same locked UI + join buttons,
// everything resolved to the child server-side (never trusted from the form).
$parentActing = !empty($isParent) && !empty($actingChild);
$canJoin = !empty($isStudent) || $parentActing;
$lockedView = $studentLocked || ($locked && $parentActing);
$childQ = $parentActing ? '?child_id=' . (int)$actingChild['id'] : '';
$childQamp = $parentActing ? '&child_id=' . (int)$actingChild['id'] : '';
$actingName = $parentActing ? (string)($actingChild['name'] ?? '') : '';
function keep_q(array $f, array $over = []): string {
    $q = array_merge(['ay_id'=>$f['ay_id']??'','semester'=>$f['semester']??'','period'=>$f['period']??'','program_id'=>$f['program_id']??'','department'=>$f['department']??'','grade_level_id'=>$f['grade_level_id']??'','subject_id'=>$f['subject_id']??'','search'=>$f['search']??'','availability'=>$f['availability']??'','view'=>$f['view']??'','child_id'=>$f['child_id']??'','per_page'=>$GLOBALS['perPage']??25], $over);
    return http_build_query(array_filter($q, static fn($v)=>$v!==''&&$v!==null));
}
$browsePeriods = $browsePeriods ?? ['1st','2nd','Summer'];
$tabBase = url('/student/class-offerings');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-0"><i class="bi bi-easel2"></i> Class Offerings</h1>
    <p class="text-muted small mb-0">Official Admin-created subjects — active & available only. Join with the student invite code from your teacher.</p>
  </div>
  <a href="<?= e(url('/student/my-classes')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-collection"></i> My Classes</a>
</div>

<?php if (!empty($needsMigration)): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Class registration is not set up yet. Ask the administrator to import <code>database/upgrade_student_portal.sql</code>.</div>
<?php endif; ?>

<?php if ($lockedView): ?>
<ul class="nav nav-pills mb-3 gap-1 small">
  <li class="nav-item"><a class="nav-link<?= !$browseAll ? ' active' : '' ?>" href="<?= e($tabBase . $childQ) ?>"><i class="bi bi-mortarboard"></i> <?= $parentActing ? 'Eligible Subjects' : 'My Eligible Subjects' ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $browseAll ? ' active' : '' ?>" href="<?= e($tabBase . '?view=all' . ($parentActing ? '&child_id=' . (int)$actingChild['id'] : '')) ?>"><i class="bi bi-eye"></i> Browse All (view-only)</a></li>
</ul>
<?php if ($parentActing && !empty($linkedChildren)): ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3 small">
  <span class="text-muted">Managing:</span>
  <?php foreach ($linkedChildren as $lc): $lcActive = (int)$lc['id'] === (int)$actingChild['id']; $lcParts = [trim((string)($lc['first_name'] ?? '')), trim((string)($lc['middle_name'] ?? '')), trim((string)($lc['last_name'] ?? '')), trim((string)($lc['suffix'] ?? ''))]; $lcName = trim(implode(' ', array_filter($lcParts))); if ($lcName === '') $lcName = 'Student #' . $lc['id']; ?>
  <a href="<?= e($tabBase . '?' . keep_q($f, ['child_id' => (int)$lc['id'], 'page' => 1])) ?>" class="btn btn-sm <?= $lcActive ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($lcName) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$ctxPeriodLabel = $acadCtx['period_label'] ?? '';
if ($ctxPeriodLabel === '' && $acadCtx !== null) $ctxPeriodLabel = trim(($acadCtx['semester'] ?? '') !== '' ? ($acadCtx['semester'] . ' Semester') : '—');
$ctxNoun = $acadCtx['noun'] ?? 'Semester';
$ctxLevelLabel = ($acadCtx['band'] ?? 'college') === 'college' ? ($acadCtx['short_year'] ?? '') : ($acadCtx['level_name'] ?? '');
$rowPeriod = static function (array $r): string {
    $p = trim((string)($r['period_code'] ?? ''));
    if ($p !== '') {
        if (in_array($p, ['1st','2nd'], true)) return $p . ' Semester';
        return $p;
    }
    $s = trim((string)($r['semester'] ?? ''));
    if (in_array($s, ['1st','2nd'], true)) return $s . ' Semester';
    return $s !== '' ? $s : '—';
};
?>
<?php if ($lockedView && $acadCtx !== null && ($acadCtx['has_active_enrollment'] ?? false)): ?>
<div class="card p-3 mb-3 border-success" style="border-left:4px solid #198754 !important">
  <h2 class="h6 mb-2"><i class="bi bi-mortarboard-fill text-success"></i> My Current Academic Period</h2>
  <div class="d-flex flex-wrap gap-2 small">
    <span class="badge text-bg-light border">School Year: <strong><?= e($acadCtx['ay_label'] ?? '—') ?></strong></span>
    <span class="badge text-bg-light border"><?= e($ctxNoun) ?>: <strong><?= e($ctxPeriodLabel) ?></strong></span>
    <span class="badge text-bg-light border"><?= e(($acadCtx['band'] ?? 'college') === 'college' ? 'Year Level' : 'Grade Level') ?>: <strong><?= e($ctxLevelLabel) ?></strong></span>
    <?php if (!empty($acadCtx['program_code'])): ?><span class="badge text-bg-light border">Program: <strong><?= e($acadCtx['program_code']) ?></strong></span><?php endif; ?>
    <span class="badge text-bg-success">Enrollment: Approved</span>
  </div>
  <p class="small text-muted mb-0 mt-2"><?= $browseAll ? 'Browsing all subjects (view-only) — only the ones matching ' . ($parentActing ? e($actingName) . '\'s' : 'your') . ' enrollment above can be joined.' : 'Showing only subjects for ' . ($parentActing ? e($actingName) . '\'s' : 'your') . ' current enrollment. Level, ' . e(strtolower($ctxNoun)) . ', school year and program are set automatically — search within the eligible offerings below.' ?></p>
</div>
<?php if ($browseAll): ?>
<div class="alert alert-info small"><i class="bi bi-eye"></i> <strong>Browse mode (view-only):</strong> other programs, levels, and terms are shown for viewing only — the Join button appears solely on subjects <?= $parentActing ? e($actingName) . ' is' : 'you are' ?> eligible for.</div>
<?php endif; ?>
<?php elseif ($lockedView && $blockReason !== ''): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <?= e($blockReason) ?> <a href="<?= e(url('/my-enrollments')) ?>" class="alert-link">Check My Enrollments</a>.</div>
<?php endif; ?>

<?php if ($lockedView): ?>
<div class="card p-3 mb-3">
  <form method="get" action="<?= e(url('/student/class-offerings')) ?>" class="row g-2">
    <?php if ($browseAll): ?><input type="hidden" name="view" value="all"><?php endif; ?>
    <?php if ($parentActing): ?><input type="hidden" name="child_id" value="<?= (int)$actingChild['id'] ?>"><?php endif; ?>
    <?php if ($browseAll): ?>
    <div class="col-6 col-md-3">
      <label class="form-label small fw-semibold" for="f_ay">Academic Year</label>
      <select id="f_ay" name="ay_id" class="form-select form-select-sm">
        <option value="">-- All --</option>
        <?php foreach (($academicYears??[]) as $ay): ?>
          <option value="<?= (int)$ay['id'] ?>" <?= ((int)($f['ay_id']??0)===(int)$ay['id'])?'selected':'' ?>><?= e($ay['school_year']) ?><?= !empty($ay['is_current'])?' (Current)':'' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small fw-semibold" for="f_per">Academic Period</label>
      <select id="f_per" name="period" class="form-select form-select-sm">
        <option value="">-- All --</option>
        <?php foreach ($browsePeriods as $s): ?>
          <option value="<?= e($s) ?>" <?= (($f['period']??'')===$s)?'selected':'' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-3">
      <label class="form-label small fw-semibold" for="f_av">Availability</label>
      <select id="f_av" name="availability" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="available" <?= (($f['availability']??'')==='available')?'selected':'' ?>>Available only</option>
        <option value="full" <?= (($f['availability']??'')==='full')?'selected':'' ?>>Full only</option>
      </select>
    </div>
    <div class="col-12 <?= $browseAll ? 'col-md-3' : 'col-md-9' ?>">
      <label class="form-label small fw-semibold" for="f_q"><?= $browseAll ? 'Search all subjects' : 'Search within my eligible subjects' ?></label>
      <div class="input-group input-group-sm">
        <input id="f_q" name="search" class="form-control" placeholder="Subject, code, teacher, section, schedule…" value="<?= e($f['search']??'') ?>" maxlength="80">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Go</button>
        <a class="btn btn-outline-secondary" href="<?= e($browseAll ? url('/student/class-offerings?view=all' . $childQamp) : url('/student/class-offerings' . $childQ)) ?>">Reset</a>
      </div>
    </div>
  </form>
</div>
<?php else: ?>
<div class="card p-3 mb-3">
  <form method="get" action="<?= e(url('/student/class-offerings')) ?>" class="row g-2">
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label small fw-semibold" for="f_ay">Academic Year</label>
      <select id="f_ay" name="ay_id" class="form-select form-select-sm">
        <option value="">-- All --</option>
        <?php foreach (($academicYears??[]) as $ay): ?>
          <option value="<?= (int)$ay['id'] ?>" <?= ((int)($f['ay_id']??0)===(int)$ay['id'])?'selected':'' ?>><?= e($ay['school_year']) ?><?= !empty($ay['is_current'])?' (Current)':'' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label small fw-semibold" for="f_sem">Semester</label>
      <select id="f_sem" name="semester" class="form-select form-select-sm">
        <option value="">-- All --</option>
        <?php foreach (($semesters??[]) as $s): ?>
          <option value="<?= e($s) ?>" <?= (($f['semester']??'')===$s)?'selected':'' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label small fw-semibold" for="f_prog">Program</label>
      <select id="f_prog" name="program_id" class="form-select form-select-sm">
        <option value="">-- All --</option>
        <?php foreach (($programs??[]) as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= ((int)($f['program_id']??0)===(int)$p['id'])?'selected':'' ?>><?= e($p['code']) ?> — <?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label small fw-semibold" for="f_dept">Department</label>
      <select id="f_dept" name="department" class="form-select form-select-sm">
        <option value="">-- All --</option>
        <?php foreach (($departments??[]) as $d): $dv = $d['department'] ?? ''; ?>
          <option value="<?= e($dv) ?>" <?= (($f['department']??'')===$dv)?'selected':'' ?>><?= e($dv) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label small fw-semibold" for="f_lvl">Year Level</label>
      <select id="f_lvl" name="grade_level_id" class="form-select form-select-sm">
        <option value="">-- All --</option>
        <?php foreach (($gradeLevels??[]) as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= ((int)($f['grade_level_id']??0)===(int)$g['id'])?'selected':'' ?>><?= e($g['code']) ?> — <?= e($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label small fw-semibold" for="f_sub">Subject</label>
      <select id="f_sub" name="subject_id" class="form-select form-select-sm">
        <option value="">-- All --</option>
        <?php foreach (($subjects??[]) as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ((int)($f['subject_id']??0)===(int)$s['id'])?'selected':'' ?>><?= e($s['code']) ?> — <?= e(mb_substr($s['title']??'',0,30)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label small fw-semibold" for="f_av">Availability</label>
      <select id="f_av" name="availability" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="available" <?= (($f['availability']??'')==='available')?'selected':'' ?>>Available only</option>
        <option value="full" <?= (($f['availability']??'')==='full')?'selected':'' ?>>Full only</option>
      </select>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
      <label class="form-label small fw-semibold" for="f_q">Search</label>
      <div class="input-group input-group-sm">
        <input id="f_q" name="search" class="form-control" placeholder="Code, title, teacher, section…" value="<?= e($f['search']??'') ?>" maxlength="80">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Go</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/student/class-offerings')) ?>">Reset</a>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <span class="small text-muted"><?= (int)$total ?> result(s) · Page <?= (int)$page ?> of <?= max(1,(int)$totalPages) ?></span>
  <form method="get" class="d-flex align-items-center gap-1 small">
    <?php foreach (['ay_id','semester','period','program_id','department','grade_level_id','subject_id','search','availability','view','child_id'] as $k): ?>
      <?php if (($f[$k]??'')!==''): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$f[$k]) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <label class="text-muted" for="pp">Show</label>
    <select id="pp" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach (($perPageOptions??[10,25,50,100]) as $o): ?>
        <option value="<?= (int)$o ?>" <?= ((int)$perPage===(int)$o)?'selected':'' ?>><?= (int)$o ?></option>
      <?php endforeach; ?>
    </select>
    <span class="text-muted">entries</span>
  </form>
</div>

<?php if ($rows === []): ?>
  <div class="card p-5 text-center">
    <div style="font-size:2rem;color:#94a3b8"><i class="bi bi-inbox"></i></div>
    <h2 class="h6 mt-2">No classes currently available</h2>
    <p class="text-muted small mb-0">Try a different term, clear the search, or check back later.</p>
  </div>
<?php else: ?>
  <!-- Desktop table -->
  <div class="card p-0 d-none d-lg-block overflow-hidden">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
          <th>Subject</th><th>Section</th><th>Instructor</th><th>Schedule</th>
          <th class="text-center">Capacity</th><th class="text-center">Enrolled</th><th class="text-center">Available</th><th>Status</th><th class="text-end">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $avail = max(0,(int)$r['max_students']-(int)$r['enrolled_count']);
          $mine = $r['my_status'] ?? null;
          $st = $r['co_status'] ?? 'open';
          $estate = $r['elig_state'] ?? '';
          $ereason = $r['elig_reason'] ?? '';
          if ($mine) { $badge='success'; $label='Joined'; }
          elseif ($estate==='full') { $badge='danger'; $label='Class Full'; }
          elseif ($estate==='closed') { $badge='secondary'; $label='Closed'; }
          elseif ($estate==='conflict') { $badge='warning'; $label='Schedule Conflict'; }
          elseif (in_array($estate, ['wrong_level','wrong_program','wrong_period'], true)) { $badge='secondary'; $label='Not Eligible'; }
          elseif (in_array($estate, ['wrong_sem','wrong_ay','no_period'], true)) { $badge='secondary'; $label='Not Available'; }
          elseif ($st!=='open') { $badge='secondary'; $label=ucfirst($st); }
          elseif ($avail<=0) { $badge='danger'; $label='Full'; }
          elseif ($avail<=5) { $badge='warning'; $label='Almost full'; }
          else { $badge='success'; $label='Available'; }
        ?>
          <tr>
            <td>
              <a href="<?= e(url('/student/class-offerings/'.(int)$r['id'] . $childQ)) ?>" class="fw-semibold"><?= e($r['subject_code']) ?></a>
              <div class="small text-muted"><?= e($r['subject_title']) ?> · <?= e(number_format((float)$r['units'],1)) ?> unit(s)</div>
              <div class="small text-muted"><?= e($r['school_year']) ?> · <?= e($rowPeriod($r)) ?><?= !empty($r['level_name'] ?? '') ? ' · ' . e(trim($r['level_name'])) : '' ?><?= !empty($r['program_code'] ?? '') ? ' · ' . e($r['program_code']) : '' ?></div>
              <?php if ($ereason !== '' && !$mine && $estate !== ''): ?><div class="small text-muted" title="<?= e($ereason) ?>"><?= e(mb_substr($ereason, 0, 90)) ?><?= strlen($ereason) > 90 ? '…' : '' ?></div><?php endif; ?>
            </td>
            <td><span class="badge text-bg-light border"><?= e($r['section_name']) ?></span></td>
            <td class="small"><?= $r['instructor']!==''?e(trim($r['instructor'])):'<span class="text-muted">TBA</span>' ?><?= !empty($r['department'])?'<div class="text-muted">'.e($r['department']).'</div>':'' ?></td>
            <td class="small"><?= e($r['schedule_note']?:'—') ?><?= !empty($r['room'])?'<div class="text-muted">'.e($r['room']).'</div>':'' ?></td>
            <td class="text-center"><?= (int)$r['max_students'] ?></td>
            <td class="text-center"><?= (int)$r['enrolled_count'] ?></td>
            <td class="text-center fw-semibold"><?= $avail ?></td>
            <td><span class="badge text-bg-<?= $badge ?>" <?= $ereason!==''?('title="'.e($ereason).'"'):'' ?>><?= e($label) ?></span></td>
            <td class="text-end">
              <?php if ($canJoin && $mine): ?>
                <span class="badge text-bg-success mb-1">Joined</span><br><a href="<?= e(url('/student/my-classes' . ($parentActing ? '?child_id=' . (int)$actingChild['id'] : ''))) ?>" class="btn btn-sm btn-outline-success">View</a>
              <?php elseif ($canJoin && ($estate==='' || $estate==='eligible') && $st==='open' && $avail>0): ?>
                <a href="<?= e(url('/student/class-offerings/'.(int)$r['id'] . $childQ)) ?>" class="btn btn-sm btn-primary" title="<?= $parentActing ? 'View details and enroll ' . e($actingName) : 'View details and join with invite code' ?>"><?= $parentActing ? 'Enroll ' . e($actingName) : 'Join Subject' ?></a><br>
                <form method="post" action="<?= e(url('/student/classrooms/request')) ?>" class="d-inline" onsubmit="return confirm('Request to join <?= e($r['subject_code']) ?>? No code needed — the teacher will verify.')"><?= csrf_field() ?><input type="hidden" name="classroom_id" value="<?= (int)$r['id'] ?>"><?php if ($parentActing): ?><input type="hidden" name="child_id" value="<?= (int)$actingChild['id'] ?>"><?php endif; ?><button type="submit" class="btn btn-sm btn-outline-primary mt-1" title="No invite code needed — the teacher verifies before you join">Request to Join</button></form>
              <?php elseif ($canJoin && $estate!==''): ?>
                <span class="btn btn-sm btn-outline-secondary disabled" title="<?= e($ereason) ?>"><?= e($r['elig_label'] ?? $label) ?></span><br><a href="<?= e(url('/student/class-offerings/'.(int)$r['id'] . $childQ)) ?>" class="btn btn-sm btn-link">Why?</a>
              <?php elseif (!empty($isStudent) && $st==='open' && $avail>0): ?>
                <a href="<?= e(url('/dashboard')) ?>" class="btn btn-sm btn-primary" title="Join with student invite code">Join with code</a>
                <a href="<?= e(url('/student/class-offerings/'.(int)$r['id'])) ?>" class="btn btn-sm btn-outline-secondary">Details</a>
              <?php else: ?>
                <a href="<?= e(url('/student/class-offerings/'.(int)$r['id'] . $childQ)) ?>" class="btn btn-sm btn-outline-secondary">Details</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <!-- Mobile cards -->
  <div class="d-lg-none">
    <?php foreach ($rows as $r):
      $avail = max(0,(int)$r['max_students']-(int)$r['enrolled_count']);
      $mine = $r['my_status'] ?? null;
      $st = $r['co_status'] ?? 'open';
      $estate = $r['elig_state'] ?? '';
      if ($mine) { $badge='success'; $label='Joined'; }
      elseif ($estate==='full') { $badge='danger'; $label='Class Full'; }
      elseif ($estate==='closed') { $badge='secondary'; $label='Closed'; }
      elseif ($estate==='conflict') { $badge='warning'; $label='Schedule Conflict'; }
      elseif (in_array($estate, ['wrong_level','wrong_program','wrong_period'], true)) { $badge='secondary'; $label='Not Eligible'; }
      elseif (in_array($estate, ['wrong_sem','wrong_ay','no_period'], true)) { $badge='secondary'; $label='Not Available'; }
      elseif ($st!=='open') { $badge='secondary'; $label=ucfirst($st); }
      elseif ($avail<=0) { $badge='danger'; $label='Full'; }
      elseif ($avail<=5) { $badge='warning'; $label='Almost full'; }
      else { $badge='success'; $label='Available'; }
    ?>
    <div class="card p-3 mb-2">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
          <a href="<?= e(url('/student/class-offerings/'.(int)$r['id'] . $childQ)) ?>" class="fw-semibold"><?= e($r['subject_code']) ?></a>
          <div class="small text-muted"><?= e($r['subject_title']) ?></div>
          <div class="small text-muted"><?= e($r['section_name']) ?> · <?= e($r['schedule_note']?:'No schedule') ?></div>
          <div class="small text-muted"><?= $r['instructor']!==''?e(trim($r['instructor'])):'Instructor TBA' ?> · <?= e($r['room']?:'') ?></div>
        </div>
        <span class="badge text-bg-<?= $badge ?>"><?= e($label) ?></span>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-2">
        <span class="small text-muted">Enrolled <?= (int)$r['enrolled_count'] ?>/<?= (int)$r['max_students'] ?> · <strong><?= $avail ?></strong> left</span>
        <?php $mReason = $r['elig_reason'] ?? ''; ?>
        <?php if ($canJoin && $mine): ?>
          <a href="<?= e(url('/student/my-classes' . ($parentActing ? '?child_id=' . (int)$actingChild['id'] : ''))) ?>" class="btn btn-sm btn-outline-success">View</a>
        <?php elseif ($canJoin && ($estate==='' || $estate==='eligible') && $st==='open' && $avail>0): ?>
          <div class="d-flex gap-1 justify-content-end flex-wrap">
          <a href="<?= e(url('/student/class-offerings/'.(int)$r['id'] . $childQ)) ?>" class="btn btn-sm btn-primary"><?= $parentActing ? 'Enroll' : 'Join Subject' ?></a>
          <form method="post" action="<?= e(url('/student/classrooms/request')) ?>" class="d-inline" onsubmit="return confirm('Request to join <?= e($r['subject_code']) ?>? No code needed.')"><?= csrf_field() ?><input type="hidden" name="classroom_id" value="<?= (int)$r['id'] ?>"><?php if ($parentActing): ?><input type="hidden" name="child_id" value="<?= (int)$actingChild['id'] ?>"><?php endif; ?><button type="submit" class="btn btn-sm btn-outline-primary" title="No invite code needed">Request</button></form>
          </div>
        <?php elseif ($canJoin && $estate!==''): ?>
          <span class="btn btn-sm btn-outline-secondary disabled" title="<?= e($mReason) ?>"><?= e($r['elig_label'] ?? $label) ?></span>
        <?php else: ?>
          <a href="<?= e(url('/student/class-offerings/'.(int)$r['id'] . $childQ)) ?>" class="btn btn-sm btn-outline-secondary">Details</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ((int)$totalPages > 1): ?>
  <nav class="mt-3" aria-label="Offerings pages">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
      <?php $base = url('/student/class-offerings').'?'.keep_q($f); ?>
      <li class="page-item <?= ((int)$page<=1)?'disabled':'' ?>"><a class="page-link" href="<?= e($base.'&page='.max(1,(int)$page-1)) ?>">Previous</a></li>
      <?php for ($i=max(1,(int)$page-2); $i<=min((int)$totalPages,(int)$page+2); $i++): ?>
        <li class="page-item <?= ($i===(int)$page)?'active':'' ?>"><a class="page-link" href="<?= e($base.'&page='.$i) ?>"><?= $i ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= ((int)$page>=(int)$totalPages)?'disabled':'' ?>"><a class="page-link" href="<?= e($base.'&page='.min((int)$totalPages,(int)$page+1)) ?>">Next</a></li>
    </ul>
  </nav>
  <?php endif; ?>
<?php endif; ?>

<script>
(function(){
  document.querySelectorAll('.reg-form').forEach(function(f){
    f.addEventListener('submit', function(ev){
      var btn = f.querySelector('button[type=submit]');
      var msg = btn ? (btn.getAttribute('data-confirm')||'Confirm registration?') : 'Confirm registration?';
      if(!confirm(msg)){ ev.preventDefault(); return; }
      if(btn){ btn.disabled = true; btn.innerHTML = 'Registering…'; }
    });
  });
})();
</script>
