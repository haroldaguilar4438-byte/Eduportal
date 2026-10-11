<?php
/**
 * Teacher dashboard - Enhanced Operational Interface (NON-DESTRUCTIVE EXTENSION)
 * Preserved: $classes, $toGrade, $announcements from DashboardController::teacherDashboard()
 * Added: $teacherStats, $assignedClasses (alias of $classes), $permitQueue, $recentAssignments, financial context
 * All new vars default gracefully if controller not yet updated.
 * @var array $classes
 * @var array $toGrade
 * @var array $announcements
 * @var array|null $teacherStats  keys: totalClasses, enrolledStudents, pendingGrades, pendingPermits
 * @var array|null $permitQueue
 * @var array|null $assignmentManager  optional
 */
$teacherStats = $teacherStats ?? [
    'totalClasses'     => count($classes ?? []),
    'enrolledStudents' => $enrolledStudents ?? null, // fallback computed below
    'pendingGrades'    => $pendingGrades ?? null,
    'pendingPermits'   => $pendingPermits ?? null,
];
// Backward compat: if controller hasn't supplied dedicated counts, derive from existing data
if (!isset($teacherStats['totalClasses'])) $teacherStats['totalClasses'] = count($classes ?? []);
$permitQueue = $permitQueue ?? ($pendingPermitsList ?? []);
$assignedClasses = $classes ?? [];
?>
<?php
/**
 * TEACHER — teaching workspace.
 * Distinct from the student dashboard on purpose: the headline is about
 * teaching load (classes to teach, work to grade, permits to verify) and
 * the primary action is "post an assignment", not "view my grades".
 */
$teachClasses  = (int) ($teacherStats['totalClasses'] ?? count($classes ?? []));
$toGradeCount  = (int) ($teacherStats['pendingGrades'] ?? count($toGrade ?? []));
$permitCount   = (int) ($teacherStats['pendingPermits'] ?? count($permitQueue ?? []));
$teachWorkload = $toGradeCount + $permitCount;
$teachHeadline = $teachWorkload > 0
    ? $teachWorkload . ' item' . ($teachWorkload === 1 ? '' : 's') . ' need your input across ' . $teachClasses . ' class' . ($teachClasses === 1 ? '' : 'es')
    : 'You have ' . $teachClasses . ' class' . ($teachClasses === 1 ? '' : 'es') . ' and no pending submissions';
$firstName = \App\Core\Auth::user()['first_name'] ?? '';
?>
<?= edu_page_header([
    'eyebrow'     => 'Faculty workspace',
    'title'       => ($firstName !== '' ? 'Welcome back, ' . e($firstName) : 'Teacher dashboard'),
    'description' => e($teachHeadline) . '.',
    'actions'     => '<a href="' . e(url('/teacher/grades')) . '" class="btn btn-light btn-sm"><i class="bi bi-journal-check me-1"></i> Grade sheets</a>'
                   . '<a href="' . e(url('/teacher/permits')) . '" class="btn btn-light btn-sm"><i class="bi bi-patch-check me-1"></i> Permit queue</a>'
                   . '<a href="' . e(url('/teacher/assignments/create')) . '" class="btn btn-accent btn-sm"><i class="bi bi-plus-lg me-1"></i> Post an assignment</a>',
]) ?>

<div class="card bg-light border small p-3 mb-4" style="background:var(--surface-sunken)">
    <div class="fw-semibold mb-1"><i class="bi bi-lightbulb text-warning"></i> Tips — How to use this page</div>
    <ul class="mb-0 ps-3">
        <li><strong>Assigned Subjects:</strong> Admin creates and assigns subjects — they appear here automatically. No need to create your own.</li>
        <li><strong>Activities:</strong> Post to an assigned subject and joined students see it instantly in <strong>Dashboard → Subject Tasks</strong>.</li>
        <li><strong>History & Permits:</strong> Review what you’ve posted in <strong>Activity History</strong> and verify exams in <strong>Permit Queue</strong>.</li>
    </ul>
</div>

<!-- ── Key Stat Cards (4) ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100 border-0 shadow-soft">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.06em">Assigned classes</div>
                    <h2 class="mb-0"><?= e(number_format((float)($teacherStats['totalClasses'] ?? 0))) ?></h2>
                    <div class="text-muted small">This term</div>
                </div>
                <span class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-journals"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100 border-0 shadow-soft">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.06em">Enrolled students</div>
                    <h2 class="mb-0"><?= e(number_format((float)($teacherStats['enrolledStudents'] ?? 0))) ?></h2>
                    <div class="text-muted small">In your sections</div>
                </div>
                <span class="stat-icon bg-success-subtle text-success"><i class="bi bi-people"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100 border-0 shadow-soft">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.06em">Pending grades</div>
                    <h2 class="mb-0" data-live-count="pending_grades"><?= e(number_format((float)($teacherStats['pendingGrades'] ?? 0))) ?></h2>
                    <div class="text-muted small">Entries to encode</div>
                </div>
                <span class="stat-icon bg-warning-subtle text-warning-emphasis"><i class="bi bi-pencil-square"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100 border-0 shadow-soft">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.06em">Clearance to sign</div>
                    <h2 class="mb-0" data-live-count="pending_clearances"><?= e(number_format((float)($teacherStats['pendingClearances'] ?? 0))) ?></h2>
                    <div class="text-muted small"><a href="<?= e(url('/teacher/clearances')) ?>">Open signing queue</a></div>
                </div>
                <span class="stat-icon bg-success-subtle text-success"><i class="bi bi-pen"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100 border-0 shadow-soft">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.06em">Permit checks</div>
                    <h2 class="mb-0" data-live-count="pending_permits"><?= e(number_format((float)($teacherStats['pendingPermits'] ?? count($permitQueue)))) ?></h2>
                    <div class="text-muted small">Awaiting verification</div>
                </div>
                <span class="stat-icon bg-info-subtle text-info"><i class="bi bi-patch-check"></i></span>
            </div>
            <a href="#permitQueue" class="stretched-link"></a>
        </div>
    </div>
</div>

<!-- ── Assigned Classes Table (with quick actions) ── -->
<div class="card p-3 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-journals"></i> My classes this term</h2>
        <span class="badge text-bg-light border"><?= count($assignedClasses) ?> classes</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Code</th><th>Subject</th><th>Section</th><th>Schedule</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if ($assignedClasses === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No class load assigned yet. Contact the registrar.</td></tr>
            <?php endif; ?>
            <?php foreach ($assignedClasses as $c): ?>
                <tr>
                    <td class="fw-semibold"><?= e($c['code']) ?></td>
                    <td><?= e($c['title']) ?></td>
                    <td><span class="badge text-bg-light border"><?= e($c['level_name'] ?? '') ?></span> <?= e($c['section_name']) ?></td>
                    <td class="text-muted small"><?= e($c['schedule_note'] ?? 'TBA') ?><?php if (!empty($c['room'])): ?><span class="text-muted"> · <?= e($c['room']) ?></span><?php endif; ?></td>
                    <td class="text-end teacher-row-actions">
                        <div class="btn-group btn-group-sm">
                            <a href="<?= e(url('/teacher/grades')) ?>" class="btn btn-outline-primary" title="Input grades"><i class="bi bi-pencil-square"></i> Grades</a>
                            <a href="<?= e(url('/classrooms/' . ($c['id'] ?? 0))) ?>" class="btn btn-outline-secondary" title="View roster"><i class="bi bi-people"></i> Roster</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
</div>

<!-- ── Admin-assigned Subjects (official, view-only invite) ── -->
<?php $subjectClassrooms = $subjectClassrooms ?? []; ?>
<div class="card p-3 mb-4 border-0 shadow-sm" style="border-top: 4px solid #059669">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-easel2 text-success"></i> My Assigned Subjects <span class="fw-normal text-muted">— Admin-managed</span></h2>
        <span class="badge text-bg-light border"><i class="bi bi-people"></i> <?= count($subjectClassrooms) ?> subject(s)</span>
    </div>

    <div class="alert alert-info small py-2"><i class="bi bi-info-circle"></i> Subjects are created and assigned by Admin. They appear here automatically — share the student invite code so students can join.</div>

    <?php if ($subjectClassrooms === []): ?>
        <div class="text-center py-3 text-muted small">No subjects assigned yet. Contact the admin — assigned subjects will appear here with their student invite codes.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($subjectClassrooms as $sc): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card h-100 border shadow-sm">
                        <div class="card-body p-3 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-primary"><?= e($sc['subject_code']) ?></span>
                                <span class="badge bg-light border text-dark"><i class="bi bi-people"></i> <?= (int)($sc['member_count'] ?? 0) ?>/<?= (int)($sc['max_students'] ?? 40) ?></span>
                            </div>
                            <?php if ((int)($sc['pending_requests'] ?? 0) > 0): ?>
                            <div class="mb-2"><a href="<?= e(url('/classrooms/' . (int)$sc['id'])) ?>" class="badge text-bg-warning text-decoration-none"><i class="bi bi-hourglass-split"></i> <?= (int)$sc['pending_requests'] ?> join request(s) — verify</a></div>
                            <?php endif; ?>
                            <h3 class="h6 mb-1"><?= e($sc['subject_title']) ?></h3>
                            <?php if (!empty($sc['section_name'])): ?><div class="small text-muted mb-1">Section: <?= e($sc['section_name']) ?> · <?= e($sc['school_year']) ?></div><?php endif; ?>
                            <?php if (!empty($sc['description'])): ?><div class="small text-muted mb-2"><?= e($sc['description']) ?></div><?php endif; ?>

                            <div class="p-2 bg-warning bg-opacity-10 border border-warning rounded-3 text-center mb-2">
                                <div class="small fw-semibold text-warning-emphasis" style="letter-spacing:.06em">INVITE CODE</div>
                                <div class="fs-4 font-monospace fw-bold" style="letter-spacing:.12em"><?= e($sc['invite_code']) ?></div>
                                <button type="button" class="btn btn-sm btn-outline-warning mt-1 copy-invite-btn" data-code="<?= e($sc['invite_code']) ?>"><i class="bi bi-clipboard"></i> Copy</button>
                            </div>

                            <div class="mt-auto d-flex gap-1 flex-wrap">
                                <a href="<?= e(url('/classrooms/'.$sc['id'])) ?>" class="btn btn-sm btn-outline-primary flex-grow-1"><i class="bi bi-eye"></i> View roster & students</a>
                            </div>
                            <div class="small text-muted mt-1">Assigned by Admin · <?= e(date('M j, Y', strtotime($sc['created_at']))) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-2"><i class="bi bi-share"></i> Share the code via announcement or chat. Students join in one click.</div>
    <?php endif; ?>

    <?php if ($subjectClassrooms !== []): ?>
    <hr class="my-3">
    <form method="post" action="<?= e(url('/teacher/classroom-tasks')) ?>" enctype="multipart/form-data" class="border rounded-3 p-3 bg-success bg-opacity-10">
        <?= csrf_field() ?>
        <div class="fw-semibold small mb-2"><i class="bi bi-journal-plus text-success"></i> Post activity to a subject <span class="badge bg-success ms-1">For joined students</span></div>
        <div class="row g-2">
            <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Select subject <span class="text-danger">*</span></label>
                <select name="classroom_id" class="form-select form-select-sm" required>
                    <option value="">Select your subject (invite code)</option>
                    <?php foreach ($subjectClassrooms as $sc3): ?>
                        <option value="<?= e((string)$sc3['id']) ?>"><?= e($sc3['subject_code']) ?> — <?= e($sc3['subject_title']) ?> (<?= e($sc3['invite_code']) ?>) · <?= (int)($sc3['member_count'] ?? 0) ?> students</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <input type="text" name="title" class="form-control form-control-sm" placeholder="Activity title (e.g., Chapter 1 Exercise)" required>
            </div>
            <div class="col-12">
                <textarea name="instructions" rows="2" class="form-control form-control-sm" placeholder="Instructions for students"></textarea>
            </div>
            <div class="col-12">
                <label class="form-label small fw-semibold mb-1" for="post_task_file">Attach file <span class="text-muted fw-normal">(exam paper / worksheet — PDF, JPG, PNG, WebP, DOC/DOCX, TXT, ZIP, max 10 MB, optional)</span></label>
                <input type="file" id="post_task_file" name="task_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt,.zip">
            </div>
            <div class="col-6 col-md-3">
                <select name="category_id" class="form-select form-select-sm" title="Assessment category (weights apply)">
                    <option value="">Category: Activities</option>
                    <?php foreach (($assessCategories ?? []) as $ac): ?><option value="<?= (int)$ac['id'] ?>"><?= e($ac['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="period" class="form-select form-select-sm" title="Grading period this counts toward">
                    <option value="">Period: untagged</option>
                    <?php foreach (($gradePeriods ?? []) as $gp): ?><option value="<?= e($gp['code']) ?>"><?= e($gp['code']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1" for="post_max_score">Total Items / Max (1–100) <span class="text-danger">*</span></label>
                <input type="number" id="post_max_score" name="max_score" class="form-control form-control-sm max-score-input" value="100" min="1" max="100" step="1" title="Total number of items for item-based assessments (Exams, Quizzes, Activities, Assignments): whole number 1–100" placeholder="e.g., 50" required oninput="if(this.value!==''&&(parseFloat(this.value)<1||parseFloat(this.value)>100))this.setCustomValidity('Total items must be between 1 and 100.');else this.setCustomValidity('');" oninvalid="this.setCustomValidity('Total items must be between 1 and 100 (whole number for Exams/Quizzes/Activities).')">
                <div class="form-text" style="font-size:.65rem">E.g. 50 items.</div>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1" for="post_passing_score">Passing Score <span class="text-muted fw-normal">(optional)</span></label>
                <input type="number" id="post_passing_score" name="passing_score" class="form-control form-control-sm" min="1" max="100" step="0.01" title="Teacher-decided passing raw score — must not exceed Total Items. Leave empty for no pass/fail." placeholder="e.g., 30">
                <div class="form-text" style="font-size:.65rem">You decide (e.g. 30/50). Auto-marks PASSED.</div>
            </div>
            <div class="col-12 col-sm-6">
                        <input type="datetime-local" name="due_at" class="form-control form-control-sm validated-due" min="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>" oninvalid="this.setCustomValidity('Please choose a future due date within <?= date('Y') ?>–<?= date('Y')+5 ?>.')" oninput="this.setCustomValidity('')">
            </div>
            <div class="col-12 col-sm-6 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-send"></i> Post to subject</button>
            </div>
        </div>
        <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> Activity will appear to joined students in <strong>My Subjects → Tasks</strong> and triggers notifications. Date is validated (future only). Tag a grading period (<strong>Prelim / Midterm / Prefinal / Finals</strong>) to make it an exam — students then need a fully approved permit to open it.</div>
    </form>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('.copy-invite-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    navigator.clipboard.writeText(btn.dataset.code);
    const old = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
    setTimeout(() => btn.innerHTML = old, 1500);
  });
});
</script>
<script>
// ── Date Validation: prevent invalid/past due dates across all activity forms ──
document.addEventListener('DOMContentLoaded', function(){
  const nowPlus5 = new Date(Date.now() + 5*60*1000);
  const minStr = nowPlus5.toISOString().slice(0,16); // YYYY-MM-DDTHH:MM
  document.querySelectorAll('.validated-due').forEach(inp => {
    // Set dynamic min (server rendered min is fallback for initial load)
    if (!inp.min || inp.min < minStr) inp.min = minStr;
    // Live feedback
    inp.addEventListener('change', function(){
      const ts = Date.parse(this.value);
      const curY = new Date().getFullYear();
      if (this.value && (isNaN(ts) || ts <= Date.now() + 300000)){
        this.setCustomValidity('Please choose a future due date — at least 5 minutes from now.');
      } else if (this.value) {
        const y = new Date(ts).getFullYear();
        if (y < curY || y > curY + 5) this.setCustomValidity('Year must be ' + curY + '–' + (curY+5) + '.');
        else this.setCustomValidity('');
      } else {
        this.setCustomValidity('');
      }
    });
  });
  // Block submit if invalid past date slips through
  document.querySelectorAll('form[action*="/teacher/assignments"], form[action*="/teacher/classroom-tasks"]').forEach(form => {
    form.addEventListener('submit', function(e){
      const inp = form.querySelector('.validated-due');
      if (inp && inp.value){
        const ts = Date.parse(inp.value);
        const curY = new Date().getFullYear();
        if (isNaN(ts) || ts <= Date.now() + 300000 || new Date(ts).getFullYear() < curY || new Date(ts).getFullYear() > curY+5){
          e.preventDefault();
          inp.reportValidity();
          // Also show flash-like alert inline
          let msg = inp.validationMessage || 'Invalid or past due date.';
          inp.focus();
          // Create temporary bootstrap alert
          let al = document.createElement('div');
          al.className = 'alert alert-danger py-2 small mt-2';
          al.innerHTML = '<i class="bi bi-exclamation-triangle"></i> ' + msg + ' — please pick a future date.';
          form.appendChild(al);
          setTimeout(()=>al.remove(), 4000);
        }
      }
    });
  });
});
</script>

<!-- ── Activity History (when teacher created activities) ── -->
<?php $activityHistory = $activityHistory ?? []; ?>
<?php $archN = 0; foreach ($activityHistory as $ahx) { if (\App\Controllers\ClassroomController::taskArchived(is_array($ahx) ? $ahx : [])) $archN++; } $actN = count($activityHistory) - $archN; ?>
<div class="card p-3 mb-4 border-0 shadow-sm" style="border-left:4px solid #6366f1">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-clock-history text-primary"></i> Activity History <span class="fw-normal text-muted">— when you posted</span></h2>
        <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="Filter activities">
                <button type="button" class="btn btn-outline-primary active" data-ah-filter="active">Active (<?= $actN ?>)</button>
                <button type="button" class="btn btn-outline-secondary" data-ah-filter="archived">Archived (<?= $archN ?>)</button>
            </div>
            <span class="badge text-bg-light border"><i class="bi bi-journal-text"></i> <?= count($activityHistory) ?> activit<?= count($activityHistory)===1?'y':'ies' ?></span>
        </div>
    </div>
    <?php if ($activityHistory === []): ?>
        <div class="text-center py-4">
            <div class="fs-4 text-muted"><i class="bi bi-inbox"></i></div>
            <p class="small text-muted mb-0">No activities posted yet. Use <strong>My Subjects → Post activity to a subject</strong> above (e.g., <code>chapter 1 exercise</code>) — it will appear here with timestamp and below in each joined student's <strong>My Subjects → Tasks</strong> feed (only valid future deadlines shown).</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Activity</th><th>Subject</th><th>Invite</th><th>Due</th><th>Students</th><th>Uploads</th><th>Manage</th><th>Created</th></tr></thead>
                <tbody>
                <?php foreach ($activityHistory as $ah): 
                    $dueValid = !empty($ah['due_at']) && strtotime($ah['due_at']) !== false && strtotime($ah['due_at']) > time() && (int)date('Y',strtotime($ah['due_at'])) >= (int)date('Y') && (int)date('Y',strtotime($ah['due_at'])) <= (int)date('Y')+5;
                    $duePast = !empty($ah['due_at']) && strtotime($ah['due_at']) !== false && strtotime($ah['due_at']) <= time();
                    $isArch = $duePast;
                    $subCount = (int)($ah['submission_count'] ?? 0);
                    $fileCount = (int)($ah['file_count'] ?? 0);
                ?>
                    <tr data-archived="<?= $isArch ? '1' : '0' ?>" <?= $isArch ? 'class="table-light"' : '' ?> style="cursor:pointer" role="button" tabindex="0" onclick="window.location='<?= e(url('/classroom-tasks/'.$ah['id'])) ?>'" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='<?= e(url('/classroom-tasks/'.$ah['id'])) ?>';}" title="Click to view uploads">
                        <td>
                            <div class="fw-semibold text-primary"><i class="bi bi-journal-text"></i> <?= e($ah['title']) ?></div>
                            <?php if (!empty($ah['instructions'])): ?><div class="small text-muted"><?= e(substr($ah['instructions'],0,60)) ?><?= strlen($ah['instructions'])>60?'…':'' ?></div><?php endif; ?>
                            <?php if ($isArch): ?><div><span class="badge text-bg-secondary" style="font-size:.6rem">Archived — past due</span></div><?php endif; ?>
                        </td>
                        <td><span class="badge bg-primary"><?= e($ah['subject_code']) ?></span><div class="small text-muted"><?= e($ah['subject_title']) ?><?= !empty($ah['section_name']) ? ' · '.e($ah['section_name']) : '' ?></div></td>
                        <td class="font-monospace small"><code><?= e($ah['invite_code'] ?? '') ?></code></td>
                        <td class="small">
                            <?php if (empty($ah['due_at'])): ?><span class="badge text-bg-light border">No deadline</span>
                            <?php elseif ($dueValid): ?><span class="badge text-bg-success"><?= e(date('M j, Y g:ia', strtotime($ah['due_at']))) ?></span>
                            <?php elseif ($duePast): ?><span class="badge text-bg-secondary">Archived</span><div class="small text-muted" style="font-size:.65rem"><?= e(date('M j, Y g:ia', strtotime($ah['due_at']))) ?></div>
                            <?php else: ?><span class="badge text-bg-warning">Invalid year</span><?php endif; ?>
                        </td>
                        <td class="small"><span class="badge bg-light border text-dark"><i class="bi bi-people"></i> <?= (int)($ah['member_count'] ?? 0) ?></span></td>
                        <td class="small text-center">
                            <span class="badge <?= $subCount>0 ? 'bg-success' : 'bg-light border text-dark' ?>"><i class="bi bi-cloud-arrow-up"></i> <?= $subCount ?> <?= $subCount===1?'file':'files' ?></span>
                            <?php if ($fileCount>0): ?><div class="small text-success"><i class="bi bi-file-earmark"></i> <?= $fileCount ?> with file</div><?php endif; ?>
                            <a href="<?= e(url('/classroom-tasks/'.$ah['id'])) ?>" class="btn btn-sm btn-outline-primary mt-1 py-0" style="font-size:.65rem" onclick="event.stopPropagation()"><i class="bi bi-eye"></i> View</a>
                        </td>
                        <td class="small text-center" onclick="event.stopPropagation()">
                            <div class="d-flex gap-1 justify-content-center">
                                <a href="<?= e(url('/teacher/classroom-tasks/'.$ah['id'].'/edit')) ?>" class="btn btn-sm btn-outline-secondary py-0" style="font-size:.65rem" title="Edit activity" aria-label="Edit <?= e($ah['title']) ?>"><i class="bi bi-pencil"></i></a>
                                <form method="post" action="<?= e(url('/teacher/classroom-tasks/'.$ah['id'].'/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete <?= e(str_replace(['\\','\''], '', $ah['title'])) ?>? <?= $subCount ?> submission(s) and their files will be removed. This cannot be undone.')"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger py-0" style="font-size:.65rem" title="Delete activity" aria-label="Delete <?= e($ah['title']) ?>"><i class="bi bi-trash"></i></button></form>
                            </div>
                        </td>
                        <td class="small text-muted"><?= e(date('M j, Y g:ia', strtotime($ah['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="ahEmpty" class="text-center text-muted small py-3" hidden>No activities in this tab.</div>
        <script>
        (function(){
            // Scoped to this card and runs after its table exists in the DOM.
            var card = document.getElementById('ahEmpty').closest('.card');
            var btns = card.querySelectorAll('[data-ah-filter]');
            var rows = card.querySelectorAll('tr[data-archived]');
            var empty = document.getElementById('ahEmpty');
            function apply(f){
                var shown = 0;
                rows.forEach(function(r){
                    var arch = r.getAttribute('data-archived') === '1';
                    var show = (f === 'active' && !arch) || (f === 'archived' && arch);
                    r.style.display = show ? '' : 'none';
                    if (show) shown++;
                });
                if (empty) empty.hidden = shown > 0;
                btns.forEach(function(b){
                    var on = b.getAttribute('data-ah-filter') === f;
                    b.classList.toggle('active', on);
                    b.classList.toggle('btn-outline-primary', on);
                    b.classList.toggle('btn-outline-secondary', !on);
                });
            }
            btns.forEach(function(b){ b.addEventListener('click', function(){ apply(b.getAttribute('data-ah-filter')); }); });
            apply('active');
        })();
        </script>
        <div class="card bg-light border small p-3 mt-3" style="background:var(--surface-sunken)">
            <div class="fw-semibold mb-1"><i class="bi bi-lightbulb text-warning"></i> Tips — How to use this page</div>
            <ul class="mb-0 ps-3">
                <li>This table is your personal log — every activity you post stays here with its time. Use the pencil/trash icons to <strong>edit or delete</strong> an activity.</li>
                <li>Past-due activities move to the <strong>Archived</strong> tab automatically (students can still submit late). Clearing the deadline keeps an activity active forever.</li>
                <li>Students check <strong>Dashboard → Subject Tasks</strong> to see your newest work, sorted by what's due soon.</li>
                <li>Want more joiners? Copy the invite code from <strong>My Subjects</strong> above and share it.</li>
            </ul>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0"><i class="bi bi-megaphone"></i> School announcements</h2>
                <a href="<?= e(url('/teacher/announcements/create')) ?>" class="btn btn-sm btn-outline-primary">Post</a>
            </div>
            <?php if ($announcements === []): ?>
                <p class="text-muted small mb-0 py-3 text-center">No announcements right now.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($announcements as $a): ?>
                        <li class="list-group-item px-0">
                            <div class="fw-semibold"><?= e($a['title']) ?></div>
                            <div class="small text-muted"><?= e($a['excerpt']) ?>&hellip;</div>
                            <div class="small text-secondary"><i class="bi bi-clock"></i> <?= e(date('M j, Y', strtotime($a['publish_at'] ?? 'now'))) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <hr class="my-3">
            <form method="post" action="<?= e(url('/teacher/announcements')) ?>">
                <?= csrf_field() ?>
                <label class="form-label small fw-semibold">Post subject announcement</label>
                <input type="text" name="title" class="form-control form-control-sm mb-2" placeholder="Announcement title" required>
                <textarea name="content" rows="3" class="form-control form-control-sm mb-2" placeholder="Content for your classes" required></textarea>
                <div class="d-flex gap-2">
                    <select name="audience" class="form-select form-select-sm" style="max-width:160px">
                        <option value="students">Students</option>
                        <option value="everyone">Everyone</option>
                        <option value="class">My sections</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-send"></i> Publish</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Exam Permit Inspection Queue (Stage 3: Teacher Verification) ── -->
<div class="card p-3" id="permitQueue">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-patch-check"></i> Exam Permit Inspection Queue <span class="badge text-bg-warning ms-2">Teacher verification</span></h2>
        <div class="d-flex gap-2">
            <span class="badge text-bg-light border"><i class="bi bi-info-circle"></i> Only registrar-approved permits appear here</span>
            <a href="<?= e(url('/teacher/permits')) ?>" class="btn btn-sm btn-outline-primary">View all</a>
        </div>
    </div>
    <?php if (empty($permitQueue)): ?>
        <div class="text-center py-4">
            <div class="fs-4 text-muted"><i class="bi bi-shield-check"></i></div>
            <p class="small text-muted mb-0">No permits pending your verification. Queue shows students in your sections whose permits are <span class="fw-semibold">Approved by Registrar</span> and awaiting teacher stamp.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Permit No.</th><th>Student</th><th>Period</th><th>Payment</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php foreach ($permitQueue as $p): 
                    $paymentValidated = (int)($p['payment_validated'] ?? 0) === 1;
                    $financial = $p['financial_status'] ?? 'unpaid';
                    $finBadge = match($financial) { 'fully_paid'=>'success','installment_paid'=>'info', default=>'warning' };
                    $finLabel = match($financial) { 'fully_paid'=>'Fully Paid','installment_paid'=>'Installment Paid', default=>'Unpaid' };
                ?>
                    <tr>
                        <td class="fw-semibold"><?= e($p['permit_no'] ?? '—') ?><div class="small text-muted"><?= e($p['exam_period'] ?? '') ?> · <?= e($p['school_year'] ?? $p['academic_year'] ?? '') ?></div></td>
                        <td>
                            <div class="fw-semibold"><?= e(trim(($p['first_name']??'') . ' ' . ($p['last_name']??'')) ?: ($p['student_name'] ?? 'Student')) ?></div>
                            <div class="small text-muted"><?= e($p['student_no'] ?? '') ?><?php if (!empty($p['section_name'])): ?> · <?= e($p['section_name']) ?><?php endif; ?></div>
                        </td>
                        <td><span class="badge text-bg-light border"><?= e($p['exam_period'] ?? '') ?></span></td>
                        <td>
                            <?php if ($paymentValidated): ?>
                                <span class="badge text-bg-<?= $finBadge ?>"><i class="bi bi-check-circle"></i> <?= e($finLabel) ?></span>
                                <div class="small text-muted mt-1"><i class="bi bi-receipt"></i> <?= e($p['payment_ref'] ?? $p['receipt_no'] ?? '—') ?></div>
                            <?php else: ?>
                                <span class="badge text-bg-danger">Not validated</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-warning">Approved by Registrar</span>
                            <div class="small text-muted"><?= e(date('M j, Y', strtotime($p['requested_at'] ?? 'now'))) ?></div>
                        </td>
                        <td class="text-end">
                            <form method="post" action="<?= e(url('/teacher/permits/' . ($p['id'] ?? 0) . '/verify')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Verify this exam permit as Fully Approved?')"><i class="bi bi-patch-check-fill"></i> Verify</button>
                            </form>
                            <a href="<?= e(url('/teacher/permits/' . ($p['id'] ?? 0))) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="small text-muted mt-2"><i class="bi bi-shield-check"></i> Verifying marks permit <span class="fw-semibold">Fully Approved</span> (Stage 3/3) and generates QR stamp for student.</div>
    <?php endif; ?>
</div>
