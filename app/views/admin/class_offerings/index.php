<?php /** @var array $rows @var array $teachers @var array $ays @var array $filters @var bool $hasStatus */
$rows = $rows ?? []; $teachers = $teachers ?? []; $ays = $ays ?? []; $filters = $filters ?? [];
$subjects = $subjects ?? []; $gradeLevels = $gradeLevels ?? []; $programs = $programs ?? [];
$periodOptions = $periodOptions ?? [];
$hasAcadCols = !empty($hasAcadCols);
$hasPeriodCol = !empty($hasPeriodCol);
$curAy = null; foreach ($ays as $a) if (!empty($a['is_current'])) { $curAy = $a; break; }
$rowPeriodLabel = static function (array $r): string {
    $p = trim((string)($r['period_code'] ?? ''));
    if ($p !== '') {
        $known = ['Q1'=>'Quarter 1','Q2'=>'Quarter 2','Q3'=>'Quarter 3','Q4'=>'Quarter 4','T1'=>'Term 1','T2'=>'Term 2','T3'=>'Term 3'];
        if (isset($known[$p])) return $known[$p];
        if (in_array($p, ['1st','2nd'], true)) return $p . ' Semester';
        return $p;
    }
    $s = trim((string)($r['semester'] ?? ''));
    if (in_array($s, ['1st','2nd'], true)) return $s . ' Semester';
    return $s !== '' ? $s : '—';
};
$rowBand = static function (array $r): string {
    $cat = $r['level_category'] ?? '';
    if (in_array($cat, ['kindergarten','elementary','junior_high'], true)) return 'basic';
    if ($cat === 'senior_high') return 'shs';
    return 'college';
};
?>
<?= edu_page_header([
    'eyebrow'     => 'Course catalogue',
    'title'       => 'Subjects & class offerings',
    'description' => 'Admin creates a subject, assigns a teacher, and it appears in Offerings and the teacher dashboard. Students join via the invite code.',
    'actions'     => '<a href="' . e(url('/student/class-offerings')) . '" class="btn btn-light btn-sm"><i class="bi bi-eye me-1"></i> Student view</a>'
                   . '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createSubjectModal"><i class="bi bi-plus-lg me-1"></i> Create subject</button>',
    'icon'        => 'bi-easel2',
]) ?>
<form method="get" action="<?= e(url('/admin/class-offerings')) ?>" class="card p-2 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-4"><label class="form-label small mb-1">Search</label><input name="q" value="<?= e($filters['q'] ?? '') ?>" class="form-control form-control-sm" placeholder="Code, title, section, invite"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach (['open','closed','archived','active','inactive'] as $s): ?><option value="<?= e($s) ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Teacher</label><select name="teacher_id" class="form-select form-select-sm"><option value="0">All teachers</option><?php foreach ($teachers as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($filters['teacher_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?> (<?= e($t['employee_no']) ?>)</option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-2 d-flex gap-1"><button class="btn btn-sm btn-primary flex-grow-1">Filter</button><a href="<?= e(url('/admin/class-offerings')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a></div>
  </div>
</form>
<div class="card p-0 overflow-hidden"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead class="table-light"><tr><th>Subject</th><th>Section / Term</th><th>Teacher</th><th>Schedule / Room</th><th class="text-center">Enrolled</th><th>Invite code</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php if ($rows === []): ?><tr><td colspan="8" class="text-center text-muted p-4">No subjects yet. Click Create Subject.</td></tr>
  <?php else: foreach ($rows as $r): ?>
  <tr>
    <td><strong><?= e($r['subject_code']) ?></strong><div class="small text-muted"><?= e($r['subject_title']) ?></div><div class="small text-muted"><?= e($r['school_year']) ?> · <?= e($rowPeriodLabel($r)) ?></div><?php if ($hasAcadCols): ?><div class="small"><?php if (!empty($r['level_name'] ?? '')): ?><span class="badge text-bg-info"><?= e($r['level_code'] ?? '') ?> · <?= e($r['level_name']) ?></span><?php else: ?><span class="badge text-bg-warning text-dark" title="Set the year level so students can join by enrollment">No year level</span><?php endif; ?><?php if (!empty($r['program_code'] ?? '')): ?> <span class="badge text-bg-light border"><?= e($r['program_code']) ?></span><?php endif; ?></div><?php endif; ?></td>
    <td><span class="badge text-bg-light border"><?= e($r['section_name'] ?? '—') ?></span></td>
    <td class="small"><?php if (empty($r['teacher_id'])): ?><div><span class="badge text-bg-warning text-dark">DRAFT — no teacher</span></div><div class="text-muted small">Assign to publish to students</div><?php else: ?><div class="fw-semibold"><?= e(trim($r['teacher_name'] ?? '') !== '' ? $r['teacher_name'] : 'TBA') ?></div><div class="text-muted"><?= e($r['employee_no'] ?? '') ?></div><?php endif; ?></td>
    <td class="small text-muted"><?= e($r['schedule'] ?? 'TBA') ?><div><?= e($r['room'] ?? '') ?></div></td>
    <td style="min-width:150px">
      <?php
        $capEnrolled = (int) $r['enrolled'];
        $capMax      = max(1, (int) $r['max_students']);
        $capPct      = edu_percent($capEnrolled, $capMax, 0);
        $capTone     = edu_percent_tone($capPct);
        $capTone     = $capEnrolled === 0 ? 'neutral' : $capTone;
      ?>
      <div class="edu-meter">
        <span class="edu-meter__track">
          <span class="edu-meter__fill" style="width:<?= (float) $capPct ?>%;background:var(--<?= $capTone === 'neutral' ? 'border-strong' : $capTone ?>, var(--<?= $capTone === 'neutral' ? 'border-strong' : $capTone ?>))"></span>
        </span>
        <span class="edu-meter__label"><?= $capEnrolled ?>/<?= $capMax ?></span>
      </div>
      <div class="small" style="color:var(--text-tertiary)">
        <?php if ($capPct >= 100): ?>Full — no slots left
        <?php elseif ($capPct >= 85): ?>Almost full
        <?php elseif ($capEnrolled === 0): ?>No students yet
        <?php else: ?><?= $capMax - $capEnrolled ?> slot<?= ($capMax - $capEnrolled) === 1 ? '' : 's' ?> left
        <?php endif; ?>
      </div>
    </td>
    <td><code class="font-monospace fw-bold"><?= e($r['invite_code']) ?></code></td>
    <td><?php $st = $r['status'] ?? (($r['is_active'] ?? 1) ? 'open' : 'closed'); ?><span class="badge text-bg-<?= $st === 'open' ? 'success' : ($st === 'archived' ? 'dark' : 'secondary') ?>"><?= e(strtoupper($st)) ?></span></td>
    <td class="text-end"><div class="btn-group btn-group-sm">
      <a href="<?= e(url('/admin/class-offerings/' . (int)$r['id'])) ?>" class="btn btn-outline-primary" title="Students"><i class="bi bi-people"></i></a>
      <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#edit<?= (int)$r['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
      <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#assign<?= (int)$r['id'] ?>" title="Assign teacher"><i class="bi bi-person-check"></i></button>
      <form method="post" action="<?= e(url('/admin/class-offerings/' . (int)$r['id'] . '/regenerate')) ?>" class="d-inline" onsubmit="return confirm('Regenerate student invite code? Old code stops working.')"><?= csrf_field() ?><button class="btn btn-outline-warning" title="New code"><i class="bi bi-arrow-clockwise"></i></button></form>
      <form method="post" action="<?= e(url('/admin/class-offerings/' . (int)$r['id'] . '/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete <?= e($r['subject_code']) ?>? Students, tasks and uploads are removed.')"><?= csrf_field() ?><button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
    </div></td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table></div></div>

<?php foreach ($rows as $r): ?>
<div class="modal fade" id="edit<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true" aria-labelledby="editTitle<?= (int)$r['id'] ?>"><div class="modal-dialog"><form method="post" action="<?= e(url('/admin/class-offerings/' . (int)$r['id'] . '/update')) ?>" class="modal-content"><?= csrf_field() ?>
<div class="modal-header"><h5 class="modal-title" id="editTitle<?= (int)$r['id'] ?>">Edit <?= e($r['subject_code']) ?></h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body row g-2">
<div class="col-4"><label class="form-label small">Code</label><input name="subject_code" value="<?= e($r['subject_code']) ?>" class="form-control form-control-sm"></div>
<div class="col-8"><label class="form-label small">Title</label><input name="subject_title" value="<?= e($r['subject_title']) ?>" class="form-control form-control-sm"></div>
<div class="col-12"><label class="form-label small">Description</label><input name="description" value="<?= e($r['description'] ?? '') ?>" class="form-control form-control-sm"></div>
<?php $rowIsBasic = in_array($r['level_category'] ?? '', ['kindergarten','elementary','junior_high'], true); ?>
<div class="col-6"><label class="form-label small">Section</label><input name="section_name" value="<?= e($r['section_name'] ?? '') ?>" class="form-control form-control-sm" placeholder="<?= $rowIsBasic ? 'e.g. Grade 1-A' : 'e.g. BSIT-1A' ?>"></div>
<?php if (!$rowIsBasic): ?>
<div class="col-6"><label class="form-label small">Semester (SHS/College)</label><select name="semester" class="form-select form-select-sm"><?php foreach (['', '1st', '2nd', 'Summer'] as $s): ?><option value="<?= e($s) ?>" <?= ($r['semester'] ?? '') === $s ? 'selected' : '' ?>><?= e($s === '' ? '—' : $s) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<?php if ($hasPeriodCol && $rowIsBasic): ?>
<div class="col-6"><label class="form-label small"><?= e($periodOptions['basic_noun'] ?? 'Quarter') ?> (required)</label><select name="period_code" class="form-select form-select-sm"><option value="">— Select —</option><?php foreach (($periodOptions['basic'] ?? []) as $p): ?><option value="<?= e($p['code']) ?>" <?= trim($r['period_code'] ?? '') === $p['code'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><div class="form-text">Basic Education runs on <?= e($periodOptions['basic_noun'] ?? 'Quarter') ?>s — no semesters, no programs.</div></div>
<?php endif; ?>
<?php if ($hasAcadCols): ?>
<div class="col-6"><label class="form-label small">Year Level (eligibility)</label><select name="grade_level_id" class="form-select form-select-sm"><option value="0">— Not set —</option><?php foreach ($gradeLevels as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)($r['grade_level_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['code']) ?> — <?= e($g['name']) ?></option><?php endforeach; ?></select></div>
<?php if (!$rowIsBasic): ?>
<div class="col-6"><label class="form-label small">Program (empty = all)</label><select name="program_id" class="form-select form-select-sm"><option value="0">All programs</option><?php foreach ($programs as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($r['program_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code']) ?> — <?= e(mb_substr($p['name'] ?? '', 0, 28)) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<?php endif; ?>
<div class="col-6"><label class="form-label small">Schedule</label><input name="schedule" value="<?= e($r['schedule'] ?? '') ?>" class="form-control form-control-sm" placeholder="MWF 08:00-09:30"></div>
<div class="col-6"><label class="form-label small">Room</label><input name="room" value="<?= e($r['room'] ?? '') ?>" class="form-control form-control-sm"></div>
<div class="col-6"><label class="form-label small">Max</label><input name="max_students" type="number" value="<?= (int)$r['max_students'] ?>" class="form-control form-control-sm" min="1" max="500"></div>
<div class="col-6"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm" form="statusForm<?= (int)$r['id'] ?>"><option>use status buttons below</option></select><div class="d-flex gap-1 mt-1">
<?php foreach (['open' => 'success', 'closed' => 'secondary', 'archived' => 'dark'] as $s => $c): ?>
<button type="submit" form="statusForm<?= (int)$r['id'] ?>" name="status" value="<?= e($s) ?>" class="btn btn-sm btn-outline-<?= e($c) ?>" formaction="<?= e(url('/admin/class-offerings/' . (int)$r['id'] . '/status')) ?>" onclick="return confirm('Mark <?= e($r['subject_code']) ?> as <?= e($s) ?>?')"><?= e(ucfirst($s)) ?></button>
<?php endforeach; ?></div></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
<form id="statusForm<?= (int)$r['id'] ?>" method="post" action="<?= e(url('/admin/class-offerings/' . (int)$r['id'] . '/status')) ?>"><?= csrf_field() ?></form>
<form id="unassignForm<?= (int)$r['id'] ?>" method="post" action="<?= e(url('/admin/class-offerings/' . (int)$r['id'] . '/remove-teacher')) ?>"><?= csrf_field() ?></form>
<div class="modal fade" id="assign<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true" aria-labelledby="assignTitle<?= (int)$r['id'] ?>"><div class="modal-dialog"><form method="post" action="<?= e(url('/admin/class-offerings/' . (int)$r['id'] . '/assign')) ?>" class="modal-content"><?= csrf_field() ?>
<div class="modal-header"><h5 class="modal-title" id="assignTitle<?= (int)$r['id'] ?>">Assign teacher — <?= e($r['subject_code']) ?></h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><p class="small text-muted">Current: <strong><?= empty($r['teacher_id']) ? 'No teacher (draft)' : e($r['teacher_name'] ?? '') ?></strong>. Only registered, active teachers are listed.</p>
<select name="teacher_id" class="form-select" required><?php foreach ($teachers as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === (int)$r['teacher_id'] ? 'selected' : '' ?>><?= e($t['name']) ?> — <?= e($t['employee_no']) ?> (<?= e($t['department'] ?? '') ?>)</option><?php endforeach; ?></select></div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><?php if (!empty($r['teacher_id'])): ?><button type="submit" class="btn btn-outline-warning" form="unassignForm<?= (int)$r['id'] ?>" onclick="return confirm('Unassign the teacher? <?= e($r['subject_code']) ?> becomes a draft, hidden from students.')">Unassign</button><?php endif; ?><button class="btn btn-primary">Assign</button></div>
</form></div></div>
<?php endforeach; ?>

<div class="modal fade" id="createSubjectModal" tabindex="-1" aria-hidden="true" aria-labelledby="createSubjectTitle"><div class="modal-dialog modal-lg"><form method="post" action="<?= e(url('/admin/class-offerings')) ?>" class="modal-content"><?= csrf_field() ?>
<div class="modal-header"><h5 class="modal-title" id="createSubjectTitle"><i class="bi bi-plus-lg"></i> Create official subject</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body row g-2">
<div class="col-4"><label class="form-label small">Subject Code *</label><input name="subject_code" id="codeCreate" class="form-control" placeholder="CS101" required maxlength="30"></div>
<div class="col-8"><label class="form-label small">Subject Name *</label><input name="subject_title" id="titleCreate" class="form-control" placeholder="Introduction to Programming" required maxlength="150"></div>
<div class="col-12"><label class="form-label small">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
<div class="col-6"><label class="form-label small">School Year *</label><select name="academic_year_id" class="form-select" required><?php foreach ($ays as $a): ?><option value="<?= (int)$a['id'] ?>" <?= !empty($a['is_current']) ? 'selected' : '' ?>><?= e($a['school_year']) ?></option><?php endforeach; ?></select></div>
<div class="col-6" id="semWrapCreate"><label class="form-label small" id="semLabelCreate">Semester (SHS/College)</label><select name="semester" id="semCreate" class="form-select"><option value="">—</option><option>1st</option><option>2nd</option><option>Summer</option></select></div>
<?php if ($hasPeriodCol): ?>
<div class="col-6" id="periodWrapCreate" style="display:none" data-noun="<?= e($periodOptions['basic_noun'] ?? 'Quarter') ?>"><label class="form-label small" id="periodLabelCreate">Quarter *</label><select name="period_code" id="periodCreate" class="form-select"><option value="">— Select —</option><?php foreach (($periodOptions['basic'] ?? []) as $p): ?><option value="<?= e($p['code']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select><div class="form-text" id="periodHintCreate">Basic Education uses <?= e($periodOptions['basic_noun'] ?? 'Quarter') ?>s, not semesters.</div></div>
<?php endif; ?>
<?php if ($hasAcadCols): ?>
<div class="col-6" id="canonWrapCreate"><label class="form-label small">Curriculum Subject (auto-fills year)</label><select name="subject_id" id="canonCreate" class="form-select"><option value="0">— Custom / pick by code —</option><?php foreach ($subjects as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['code']) ?> — <?= e(mb_substr($s['title'] ?? '', 0, 32)) ?></option><?php endforeach; ?></select></div>
<div class="col-3"><label class="form-label small">Year Level</label><select name="grade_level_id" id="levelCreate" class="form-select"><option value="0" data-cat="" data-code="">— Auto —</option><?php foreach ($gradeLevels as $g): ?><option value="<?= (int)$g['id'] ?>" data-cat="<?= e($g['category'] ?? '') ?>" data-code="<?= e($g['code'] ?? '') ?>"><?= e($g['code']) ?> — <?= e($g['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-3" id="progWrapCreate"><label class="form-label small">Program</label><select name="program_id" id="progCreate" class="form-select"><option value="0">All programs</option><?php foreach ($programs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['code']) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<div class="col-4"><label class="form-label small">Section / Class</label><input name="section_name" id="sectionCreate" class="form-control" placeholder="BSIT-1A"></div>
<div class="col-4"><label class="form-label small">Schedule</label><input name="schedule" class="form-control" placeholder="MWF 08:00-09:30"></div>
<div class="col-4"><label class="form-label small">Room</label><input name="room" class="form-control" placeholder="Room 201"></div>
<div class="col-6"><label class="form-label small">Assign teacher <span class="text-muted fw-normal">(optional — can assign later)</span></label><select name="teacher_id" class="form-select"><option value="0">— No teacher yet (draft) —</option><?php foreach ($teachers as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?> — <?= e($t['employee_no']) ?></option><?php endforeach; ?></select></div>
<div class="col-3"><label class="form-label small">Max</label><input name="max_students" type="number" value="40" min="1" max="500" class="form-control"></div>
<div class="col-3"><label class="form-label small">Status</label><select name="status" class="form-select"><option value="open">Open</option><option value="closed">Closed</option><option value="archived">Archived</option></select></div>
<div class="col-12"><div class="alert alert-info small mb-0">Student invite code is auto-generated (e.g., CS7K9P2), unique per subject, shown after creation. Subjects without a teacher stay hidden from students until you assign one.</div></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary">Create & publish</button></div>
</form></div></div>
<script>
(function(){
  var lvl = document.getElementById('levelCreate');
  var semWrap = document.getElementById('semWrapCreate');
  var sem = document.getElementById('semCreate');
  var pWrap = document.getElementById('periodWrapCreate');
  var per = document.getElementById('periodCreate');
  var perLabel = document.getElementById('periodLabelCreate');
  var canonWrap = document.getElementById('canonWrapCreate');
  var canon = document.getElementById('canonCreate');
  var progWrap = document.getElementById('progWrapCreate');
  var prog = document.getElementById('progCreate');
  var code = document.getElementById('codeCreate');
  var title = document.getElementById('titleCreate');
  var section = document.getElementById('sectionCreate');
  function basicCat(cat){ return cat === 'kindergarten' || cat === 'elementary' || cat === 'junior_high'; }
  function swap(){
    if(!lvl) return;
    var opt = lvl.options[lvl.selectedIndex];
    var cat = opt ? opt.getAttribute('data-cat') : '';
    var lvCode = opt ? opt.getAttribute('data-code') : '';
    var isBasic = basicCat(cat);
    // Basic Ed (K–G10): traditional four-quarter flow — show ONLY the
    // essentials. Semester, Program, and Curriculum-link are hidden (and
    // cleared so nothing stale is submitted).
    if(pWrap) pWrap.style.display = isBasic ? '' : 'none';
    if(per){ per.required = isBasic; if(!isBasic) per.value = ''; }
    if(perLabel && pWrap) perLabel.textContent = (pWrap.getAttribute('data-noun') || 'Quarter') + ' *';
    if(semWrap) semWrap.style.display = isBasic ? 'none' : '';
    if(sem && isBasic) sem.value = '';
    if(canonWrap) canonWrap.style.display = isBasic ? 'none' : '';
    if(canon && isBasic) canon.value = '0';
    if(progWrap) progWrap.style.display = isBasic ? 'none' : '';
    if(prog && isBasic) prog.value = '0';
    if(code) code.placeholder = isBasic ? (cat === 'kindergarten' ? 'e.g. KINDER-Math' : 'e.g. MATH-101') : 'CS101';
    if(title) title.placeholder = isBasic ? (cat === 'kindergarten' ? 'e.g. Early Math Play' : 'e.g. Mathematics') : 'Introduction to Programming';
    if(section) section.placeholder = isBasic ? (cat === 'kindergarten' ? 'e.g. Kinder-A' : 'e.g. Grade 1-A') : 'BSIT-1A';
  }
  if(lvl){ lvl.addEventListener('change', swap); swap(); }
})();
</script>
