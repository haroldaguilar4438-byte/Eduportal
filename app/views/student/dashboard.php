<?php
/**
 * Student dashboard - Enhanced with Exam Permit Widget (NON-DESTRUCTIVE EXTENSION)
 * Preserved: $student, $avgGrade, $balance, $dueAssignments, $announcements
 * Added: $examPermits, $currentAy, $permitBalance, $permitMap (period => permit row)
 * All new vars default gracefully if controller not yet updated.
 * @var array|null $student @var float|null $avgGrade @var float $balance
 * @var array $dueAssignments @var array $announcements
 * @var array|null $examPermits
 * @var array|null $currentAy
 */
$firstName = \App\Core\Auth::user()['first_name'] ?? 'there';
$examPermits = $examPermits ?? [];
$periods = ['Prelim','Midterm','Prefinal','Finals'];
$permitMap = [];
foreach ($examPermits as $ep) {
    $k = $ep['exam_period'] ?? '';
    if (in_array($k, $periods, true)) $permitMap[$k] = $ep;
}
function permit_checklist(array $p): string {
    $status = $p['status'] ?? 'pending_accounting';
    $stages = [
        'accounting' => in_array($status, ['approved_accounting','approved_registrar','fully_approved'], true),
        'registrar'  => in_array($status, ['approved_registrar','fully_approved'], true),
        'teacher'    => $status === 'fully_approved',
    ];
    $html = '<div class="d-flex gap-1 flex-wrap small">';
    foreach (['accounting'=>'Accounting','registrar'=>'Registrar','teacher'=>'Teacher'] as $k=>$label) {
        $ok = $stages[$k];
        $cls = $ok ? 'bg-success text-white' : 'bg-light text-dark border';
        $icon = $ok ? '✓' : '·';
        $html .= '<span class="badge '.$cls.'">'.$icon.' '.$label.'</span>';
    }
    $html .= '</div>';
    return $html;
}
function permit_fin_label(array $p): array {
    $fin = $p['financial_status'] ?? 'unpaid';
    $badge = match($fin) { 'fully_paid'=>'success','installment_paid'=>'info', default=>'warning' };
    $label = match($fin) { 'fully_paid'=>'Fully Paid','installment_paid'=>'Installment Paid', default=>'Payment Pending' };
    return [$badge,$label];
}
$isEnrolledStudent = $student !== null && ($student['status'] ?? '') === 'enrolled';
$permitMinSubjects = (int)($permitMinSubjects ?? 8);
$joinedTermCount = (int)($joinedTermCount ?? 0);
$subjectsReady = (bool)($subjectsReady ?? ($joinedTermCount >= $permitMinSubjects));
$subjectsShort = max(0, $permitMinSubjects - $joinedTermCount);
?>
<?php
/**
 * Greeting header. Time-of-day aware, and states the one thing the student
 * most needs to know next (balance due, tasks due, or permit status).
 */
$greeting = (int) date('G') < 12 ? 'Good morning' : ((int) date('G') < 18 ? 'Good afternoon' : 'Good evening');
$balanceFloat = (float) $balance;
$taskCount = count($dueAssignments);

$nextAction = 'Review your classes and stay on top of your deadlines.';
if ($balanceFloat > 0.0) {
    $nextAction = 'Settle your outstanding balance to keep permits and documents moving.';
} elseif ($taskCount > 0) {
    $nextAction = 'You have ' . $taskCount . ' task' . ($taskCount === 1 ? '' : 's') . ' due — check the deadline list below.';
} elseif ($student !== null && ($student['status'] ?? '') === 'enrolled') {
    $nextAction = 'You are all caught up. Nothing is due right now.';
}
?>
<?= edu_page_header([
    'eyebrow'     => 'Student workspace',
    'title'       => $greeting . ', ' . e($firstName) . '!',
    'description' => e($nextAction),
    'actions'     => '<a href="' . e(url('/student/schedule')) . '" class="btn btn-light btn-sm"><i class="bi bi-calendar-week me-1"></i> My schedule</a>'
                   . '<a href="' . e(url('/student/grades')) . '" class="btn btn-primary btn-sm"><i class="bi bi-percent me-1"></i> View grades</a>',
]) ?>

<!-- NEXT ENROLLMENT CYCLE (additive: same account, new period, history preserved) -->
<?php $cycle = $cycle ?? null; ?>
<?php if (!empty($cycle) && ($cycle['state'] ?? '') !== 'no_record' && ($cycle['state'] ?? '') !== 'first_enrollment'): ?>
<div class="card p-3 mb-4 border-success">
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h2 class="h6 mb-0"><i class="bi bi-arrow-repeat text-success"></i> Next Enrollment</h2>
    <?php if (($cycle['state'] ?? '') === 'eligible'): ?><span class="badge text-bg-success">ELIGIBLE</span>
    <?php elseif (($cycle['state'] ?? '') === 'graduating'): ?><span class="badge text-bg-primary">PROGRAM COMPLETE</span>
    <?php elseif (($cycle['state'] ?? '') === 'already_enrolled'): ?><span class="badge text-bg-info">IN PROGRESS</span>
    <?php else: ?><span class="badge text-bg-warning text-dark">NOT YET ELIGIBLE</span><?php endif; ?>
  </div>
  <div class="small">
    <?php if (!empty($cycle['current'])): ?><div class="text-muted">Current: <strong><?= e(($cycle['current']['level_name'] ?? '') . ' – ' . ($cycle['current']['period_label'] ?? (($cycle['current']['semester'] ?? '') . ' Semester')) . ' (' . ($cycle['current']['ay_label'] ?? '') . ')') ?></strong></div><?php endif; ?>
    <?php if (!empty($cycle['next']) && empty($cycle['next']['graduating'])): ?><div class="text-muted">Next: <strong><?= e(($cycle['next']['level_name'] ?? '') . ' – ' . ($cycle['next']['period_label'] ?? (($cycle['next']['semester'] ?? '') . ' Semester')) . ' (' . ($cycle['next']['ay_label'] ?? 'to be opened') . ')') ?></strong></div><?php endif; ?>
    <?php if (($cycle['state'] ?? '') === 'not_eligible' && !empty($cycle['reasons'])): ?><div class="text-muted mt-1">Pending: <?= e(implode(' | ', array_slice($cycle['reasons'], 0, 3))) ?></div><?php endif; ?>
  </div>
  <div class="mt-2">
    <?php if (($cycle['state'] ?? '') === 'eligible'): ?><a href="<?= e(url('/enroll')) ?>" class="btn btn-sm btn-success"><i class="bi bi-send"></i> Enroll for Next Period</a>
    <?php elseif (($cycle['state'] ?? '') === 'already_enrolled'): ?><a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-sm btn-info">Track Application</a>
    <?php endif; ?>
    <a href="<?= e(url('/enrollment/eligibility')) ?>" class="btn btn-sm btn-outline-secondary">Details</a>
  </div>
</div>
<?php endif; ?>

<?php $acadCtx = $acadCtx ?? null; $eligibleCount = (int)($eligibleCount ?? 0); ?>
<?php
$dashBandDb = $acadCtx['band'] ?? 'college';
$dashNounDb = $acadCtx['noun'] ?? 'Semester';
$dashPeriodDb = $acadCtx['period_label'] ?? '';
$dashLevelDb = $dashBandDb === 'college' ? ($acadCtx['short_year'] ?? '') : ($acadCtx['level_name'] ?? '');
$dashBandNameDb = $dashBandDb === 'basic' ? 'Basic Education' : ($dashBandDb === 'shs' ? 'Senior High School' : 'College');
?>
<?php if (!empty($acadCtx) && ($acadCtx['has_active_enrollment'] ?? false)): ?>
<div class="card p-3 mb-4 border-primary" style="border-left:4px solid #0d6efd !important">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h2 class="h6 mb-0"><i class="bi bi-mortarboard-fill text-primary"></i> Current Enrollment</h2>
    <span class="badge text-bg-success">Approved</span>
  </div>
  <div class="d-flex flex-wrap gap-2 small mb-2">
    <span class="badge text-bg-light border">School Year: <strong><?= e($acadCtx['ay_label'] ?? '—') ?></strong></span>
    <span class="badge text-bg-light border"><?= e($dashNounDb) ?>: <strong><?= e($dashPeriodDb) ?></strong></span>
    <span class="badge text-bg-light border"><?= e($dashBandDb === 'college' ? 'Year Level' : 'Grade Level') ?>: <strong><?= e($dashLevelDb) ?></strong></span>
    <?php if (!empty($acadCtx['program_code'])): ?><span class="badge text-bg-light border">Program: <strong><?= e($acadCtx['program_code']) ?></strong></span><?php endif; ?>
    <span class="badge text-bg-light border">Eligible Subjects: <strong><?= $eligibleCount ?></strong></span>
  </div>
  <div>
    <a href="<?= e(url('/student/class-offerings')) ?>" class="btn btn-sm btn-primary"><i class="bi bi-easel2"></i> View My Eligible Subjects</a>
    <a href="<?= e(url('/student/my-classes')) ?>" class="btn btn-sm btn-outline-secondary">My Subjects</a>
  </div>
</div>
<?php elseif (!empty($acadCtx) && !($acadCtx['has_active_enrollment'] ?? false)): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> You must have an active/approved <?= e($dashBandNameDb) ?> enrollment before joining subjects. <a href="<?= e(url('/my-enrollments')) ?>" class="alert-link">Check My Enrollments</a>.</div>
<?php endif; ?>

<!-- STUDENT SERVICES HUB (existing design system cards; all routes enforce Auth + ownership) -->
<div class="card p-3 mb-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h6 mb-0"><i class="bi bi-grid-3x3-gap text-primary"></i> Student Services</h2>
    <span class="small text-muted">One hub for all your portal needs</span>
  </div>
  <div class="row g-2">
    <?php $hub = [
      ['/profile','bi-person','Profile','View your student information'],
      ['/student/class-offerings','bi-easel2','Class Offerings','Browse & register classes'],
      ['/student/my-classes','bi-journal-check','My Classes','View / drop registered'],
      ['/enroll','bi-file-earmark-plus','Enrollment','Apply & track application'],
      ['/student/ledger','bi-wallet2','Ledger','Charges & payments'],
      ['/student/schedule','bi-calendar3','Schedule','Your class timetable'],
      ['/student/grades','bi-percent','Grades','Released grades & GWA'],
      ['/student/flowchart','bi-diagram-3','Flowchart','Curriculum progress'],
      ['/student/clearances','bi-patch-check-fill','Clearance','Status & signing'],
      ['/student/documents','bi-folder2-open','Documents','Request official copies'],
      ['/student/enrollment-docs','bi-folder-check','My Files','Upload once, reuse always'],
      ['/student/permits','bi-patch-check','Exam Permits','Request & print'],
    ]; foreach ($hub as [$href,$icon,$t,$d]): ?>
    <div class="col-6 col-md-4 col-xl-3">
      <a href="<?= e(url($href)) ?>" class="border rounded-3 p-2 d-flex gap-2 align-items-start text-decoration-none h-100" style="color:inherit">
        <span class="rounded-2 bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:34px;height:34px;flex-shrink:0"><i class="bi <?= e($icon) ?>"></i></span>
        <span><span class="d-block fw-semibold small"><?= e($t) ?></span><span class="d-block text-muted" style="font-size:.72rem"><?= e($d) ?></span></span>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card bg-light border small p-3 mb-4" style="background:var(--surface-sunken)">
    <div class="fw-semibold mb-1"><i class="bi bi-lightbulb text-warning"></i> Tips — How to use this page</div>
    <ul class="mb-0 ps-3">
        <li><strong>Exam Permits:</strong> Request in order — <code>Prelim</code> → <code>Midterm</code> → <code>Prefinal</code> → <code>Finals</code>. The next one unlocks after the previous is <span class="badge bg-success">Fully Approved</span>.</li>
        <li><strong>Subjects first:</strong> Join at least <strong><?= $permitMinSubjects ?> subjects</strong> for the term (currently <?= $joinedTermCount ?>) before the Prelim permit unlocks.</li>
        <li><strong>My Subjects:</strong> Got a 6-character code from your teacher? Paste it in <strong>Join a Subject</strong> to join instantly.</li>
        <li><strong>Tasks:</strong> Click any row in <strong>Subject Tasks</strong> to open it, attach your file, and submit. You can update until graded.</li>
    </ul>
</div>

<?php if ($student === null): ?>
    <div class="alert alert-info">
        Your portal account is not linked to a student record yet. Once your enrollment application
        is approved by the registrar, your profile, grades and tasks will appear here automatically.
    </div>
<?php endif; ?>

<?php if ($isEnrolledStudent && !$subjectsReady): ?>
<div class="card p-3 mb-4 border-warning" style="border-left:4px solid #f59e0b !important; background: linear-gradient(135deg,var(--warning-bg) 0%,var(--surface) 100%);">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
        <h2 class="h6 mb-0"><i class="bi bi-easel2 text-warning"></i> Join your subjects first</h2>
        <span class="badge text-bg-warning text-dark"><?= $joinedTermCount ?> / <?= $permitMinSubjects ?> joined</span>
    </div>
    <p class="small text-muted mb-2">Freshly enrolled? You need at least <strong><?= $permitMinSubjects ?> subjects</strong> for this term before you can request a Prelim exam permit (<?= $subjectsShort ?> more to go).</p>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(url('/student/class-offerings')) ?>" class="btn btn-sm btn-warning text-dark"><i class="bi bi-search"></i> Browse Class Offerings</a>
        <span class="small text-muted align-self-center">or paste an invite code in <strong>My Subjects</strong> below</span>
    </div>
</div>
<?php endif; ?>

<!-- EXAM PERMIT WIDGET -->
<div class="card p-3 mb-4 border-0 shadow-soft overflow-hidden" style="border-left:4px solid #0F172A !important">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-patch-check-fill text-success"></i> Exam Permit <span class="text-muted fw-normal">— 4 periods · 3-tier approval</span></h2>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge text-bg-light border"><i class="bi bi-shield-check"></i> <?= $currentAy['school_year'] ?? date('Y').'-'.(date('Y')+1) ?></span>
            <?php if ($student && isset($student['student_no'])): ?>
                <span class="small text-muted"><?= e($student['student_no']) ?></span>
            <?php endif; ?>
            <a href="<?= e(url('/student/permits')) ?>" class="btn btn-sm btn-outline-primary">Manage permits</a>
        </div>
    </div>
    <?php if (!$isEnrolledStudent): ?>
        <div class="alert alert-warning mb-3 small"><i class="bi bi-exclamation-triangle"></i> Enrollment required before requesting exam permits. Complete enrollment for the active term first.</div>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($periods as $period):
            $p = $permitMap[$period] ?? null;
            $has = $p !== null;
            $status = $p['status'] ?? 'not_requested';
            $isFully = $status === 'fully_approved';
            $isRejected = $status === 'rejected';
            $paymentValidated = $has && (int)($p['payment_validated'] ?? 0) === 1;
            [$finBadge,$finLabel] = $has ? permit_fin_label($p) : ['secondary','—'];
            $permitNo = $p['permit_no'] ?? '';
            $qrToken = $p['qr_token'] ?? ($permitNo ?: $period);
            $qrUrl = $has && $isFully ? 'https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=' . urlencode(url('/verify-permit/' . ($qrToken))) : '';
            $seqIdx = array_search($period, $periods, true);
            $prevPeriod = $seqIdx > 0 ? $periods[$seqIdx - 1] : null;
            $prevPermit = $prevPeriod ? ($permitMap[$prevPeriod] ?? null) : null;
            $prevFully = $prevPermit && ($prevPermit['status'] ?? '') === 'fully_approved';
            $isLockedSequential = !$has && $seqIdx > 0 && !$prevFully;
        ?>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100 border <?= $isLockedSequential ? 'border-secondary' : ($isFully ? 'border-success' : ($isRejected ? 'border-danger' : 'border-light')) ?>" style="<?= $isFully ? 'background: linear-gradient(135deg,var(--success-bg) 0%,var(--surface) 100%);' : ($isLockedSequential ? 'background:#f8fafc; opacity:0.92; filter:grayscale(15%);' : '') ?>">
                <div class="card-body p-3 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge text-bg-dark"><?= e($period) ?></span>
                        <?php if (!$has): ?>
                            <?php if ($isLockedSequential): ?>
                                <span class="badge text-bg-secondary"><i class="bi bi-lock"></i> Locked</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Not requested</span>
                            <?php endif; ?>
                        <?php elseif ($isFully): ?>
                            <span class="badge text-bg-success"><i class="bi bi-patch-check-fill"></i> FULLY APPROVED</span>
                        <?php elseif ($isRejected): ?>
                            <span class="badge text-bg-danger">REJECTED</span>
                        <?php elseif ($status === 'approved_registrar'): ?>
                            <span class="badge text-bg-warning text-dark">Awaiting Teacher</span>
                        <?php elseif ($status === 'approved_accounting'): ?>
                            <span class="badge text-bg-info">Awaiting Registrar</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning">Pending Accounting</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($has): ?>
                        <div class="small text-muted mb-1">Permit No.</div>
                        <div class="fw-semibold small"><?= e($permitNo) ?></div>
                        <div class="d-flex gap-2 align-items-center mt-2 mb-2 flex-wrap">
                            <?php if ($paymentValidated): ?>
                                <span class="badge text-bg-<?= $finBadge ?>"><i class="bi bi-check-circle"></i> <?= e($finLabel) ?></span>
                                <span class="small text-muted"><i class="bi bi-receipt"></i> <?= e($p['payment_ref'] ?? $p['receipt_no'] ?? '—') ?></span>
                                <span class="badge text-bg-success border" title="Payment Validated">Payment Validated</span>
                            <?php else: ?>
                                <span class="badge text-bg-<?= $finBadge ?>"><?= e($finLabel) ?></span>
                                <span class="small text-muted">Balance: <?= money($p['balance_at_request'] ?? $balance ?? 0) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="mb-2"><?= permit_checklist($p) ?></div>
                        <?php if (!empty($p['rejected_reason']) && $isRejected): ?>
                            <div class="alert alert-danger py-1 px-2 small mb-2"><?= e($p['rejected_reason']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($isLockedSequential): ?>
                            <div class="small text-warning mb-2"><i class="bi bi-lock"></i> Locked — Complete <strong><?= e($prevPeriod) ?></strong> (Fully Approved) first to unlock <?= e($period) ?>.</div>
                        <?php else: ?>
                            <div class="small text-muted mb-2">No permit requested for <?= e($period) ?> yet. Payment must be validated first (₱0 remaining or installment paid).</div>
                        <?php endif; ?>
                        <div class="d-flex gap-1 mb-2">
                            <span class="badge <?= $isLockedSequential ? 'bg-secondary text-white' : 'bg-light text-dark border' ?>">Accounting ·</span>
                            <span class="badge <?= $isLockedSequential ? 'bg-secondary text-white' : 'bg-light text-dark border' ?>">Registrar ·</span>
                            <span class="badge <?= $isLockedSequential ? 'bg-secondary text-white' : 'bg-light text-dark border' ?>">Teacher ·</span>
                        </div>
                    <?php endif; ?>
                    <div class="mt-auto pt-2 border-top">
                        <?php if ($isFully): ?>
                            <div class="text-center mb-2">
                                <?php if ($qrUrl): ?>
                                    <img src="<?= e($qrUrl) ?>" alt="QR verification" class="img-fluid border rounded-2 p-1 bg-white" style="width:110px;height:110px;object-fit:contain">
                                    <div class="small text-muted mt-1" style="font-size:.7rem">Scan to verify · <?= e(substr($qrToken,0,8)) ?>…</div>
                                <?php endif; ?>
                                <div class="mt-2 d-flex justify-content-center">
                                    <span class="badge text-bg-success" style="letter-spacing:.08em"><i class="bi bi-star-fill"></i> OFFICIAL STAMP VERIFIED</span>
                                </div>
                            </div>
                            <div class="d-grid gap-1">
                                <a href="<?= e(url('/student/permits/' . ($p['id'] ?? 0) . '/print')) ?>" target="_blank" class="btn btn-sm btn-success"><i class="bi bi-printer"></i> View / Print official permit card</a>
                                <a href="<?= e(url('/student/permits/' . ($p['id'] ?? 0))) ?>" class="btn btn-sm btn-outline-secondary">Details</a>
                            </div>
                        <?php elseif ($has): ?>
                            <div class="d-grid gap-1">
                                <a href="<?= e(url('/student/permits/' . ($p['id'] ?? 0))) ?>" class="btn btn-sm btn-outline-primary">Track status</a>
                                <?php if ($isRejected): ?>
                                <form method="post" action="<?= e(url('/student/permits/' . ($p['id'] ?? 0) . '/request-again')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-warning text-dark w-100" onclick="return confirm('Resubmit this permit for review?')"><i class="bi bi-arrow-repeat"></i> Request Again</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php if (!$isEnrolledStudent): ?>
                                <button type="button" class="btn btn-sm btn-secondary w-100" disabled>Enrollment required</button>
                            <?php elseif ($isLockedSequential): ?>
                                <button type="button" class="btn btn-sm btn-secondary w-100" disabled style="pointer-events:none; opacity:0.6"><i class="bi bi-lock"></i> Complete <?= e($prevPeriod) ?> first</button>
                                <div class="small text-warning text-center mt-1" style="font-size:.7rem"><i class="bi bi-exclamation-circle"></i> Sequential: <?= e($prevPeriod) ?> must be Fully Approved</div>
                            <?php elseif ($period === 'Prelim' && !$subjectsReady): ?>
                                <a href="<?= e(url('/student/class-offerings')) ?>" class="btn btn-sm btn-warning text-dark w-100"><i class="bi bi-easel2"></i> Join subjects (<?= $joinedTermCount ?>/<?= $permitMinSubjects ?>)</a>
                                <div class="small text-warning text-center mt-1" style="font-size:.7rem"><i class="bi bi-exclamation-circle"></i> Prelim unlocks after <?= $subjectsShort ?> more subject(s)</div>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('/student/permits/request')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="exam_period" value="<?= e($period) ?>">
                                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-send"></i> Request <?= e($period) ?> permit</button>
                                </form>
                                <div class="small text-muted text-center mt-1" style="font-size:.7rem">Payment will be validated (PayRex/receipt)</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="small text-muted mt-3 border-top pt-2"><i class="bi bi-info-circle"></i> Pipeline: <span class="badge bg-light text-dark border">Pending Accounting</span> → <span class="badge bg-info">Approved by Accounting</span> → <span class="badge bg-warning text-dark">Approved by Registrar</span> → <span class="badge bg-success">Fully Approved</span> (Teacher). &nbsp; Payment prerequisite: <span class="fw-semibold">₱0 fully paid OR installment paid</span> with PayRex/receipt ref shown as <span class="badge bg-success border">Payment Validated</span>.</div>
</div>

<?php if (!$isEnrolledStudent): ?>
<div class="card p-5 mb-4 text-center border-warning" style="background: linear-gradient(135deg,var(--warning-bg) 0%,var(--surface) 100%);">
    <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-500 text-white d-flex align-items-center justify-content-center fs-4 mb-3"><i class="bi bi-lock-fill"></i></div>
    <h2 class="h5">My Subjects & Everything Else — Locked</h2>
    <p class="small text-muted mb-1">These open only after you are <strong>fully enrolled</strong> (Accounting & Registrar approved + PayRex payment).</p>
    <p class="small text-muted mb-3">Includes: My Subjects (invite code), Exam Permit actions, Grades, Balance, Tasks & Announcements.</p>
    <div class="d-flex justify-content-center gap-2 flex-wrap">
        <a href="<?= e(url('/enroll')) ?>" class="btn btn-primary"><i class="bi bi-file-earmark-plus"></i> Complete Enrollment</a>
        <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-outline-primary">Track Application / Pay</a>
    </div>
</div>
<?php else: ?>
<?php $joinedClassrooms = $joinedClassrooms ?? []; ?>
<div class="card p-3 mb-4 border-0 shadow-sm" style="border-top: 4px solid #1E3A8A">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-easel2 text-primary"></i> My Subjects <span class="fw-normal text-muted">— joined via invite code</span></h2>
        <span class="badge text-bg-light border"><i class="bi bi-collection"></i> <span data-live-count="my_subjects"><?= count($joinedClassrooms) ?></span> subject(s)</span>
    </div>
    <form method="get" action="<?= e(url('/student/classrooms/preview')) ?>" class="border rounded-3 p-3 mb-3 invite-code-box">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-8">
                <label for="invite_code_join" class="form-label small fw-semibold mb-1">Have a student invite code? Paste it here</label>
                <input id="invite_code_join" name="code" class="form-control font-monospace" placeholder="e.g., CS7K9P2" required maxlength="10" style="letter-spacing:.1em; text-transform:uppercase">
                <div class="form-text">Official code from Admin/teacher for one specific subject. You will preview the subject before confirming.</div>
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Validate & preview</button>
            </div>
        </div>
    </form>
    <?php if ($joinedClassrooms === []): ?>
        <div class="text-center py-3">
            <div class="fs-4 text-muted"><i class="bi bi-journal-plus"></i></div>
            <p class="small text-muted mb-0">Not yet joined any subject. Enter the invite code your teacher shared (e.g., <code>X7K9M2</code>).</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($joinedClassrooms as $jc): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card h-100 border shadow-sm">
                        <div class="card-body p-3 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <span class="badge bg-dark"><?= e($jc['subject_code']) ?></span>
                                <span class="badge bg-success bg-opacity-10 text-success border">Joined <i class="bi bi-check-lg"></i></span>
                            </div>
                            <h3 class="h6 mb-1"><?= e($jc['subject_title']) ?></h3>
                            <div class="small text-muted mb-1">Teacher: <?= e(trim(($jc['teacher_first']??'').' '.($jc['teacher_last']??''))) ?> (<?= e($jc['employee_no'] ?? '') ?>)</div>
                            <?php if (!empty($jc['section_name'])): ?><div class="small text-muted mb-1">Section: <?= e($jc['section_name']) ?> · <?= e($jc['school_year']) ?></div><?php endif; ?>
                            <div class="small text-muted mb-2">Invite: <code><?= e($jc['invite_code']) ?></code> · <?= (int)($jc['member_count'] ?? 0) ?> classmates</div>
                            <?php if (!empty($jc['description'])): ?><div class="small text-muted mb-2"><?= e($jc['description']) ?></div><?php endif; ?>
                            <div class="mt-auto d-flex gap-1">
                                <a href="<?= e(url('/classrooms/'.$jc['id'])) ?>" class="btn btn-sm btn-outline-primary flex-grow-1"><i class="bi bi-eye"></i> View subject</a>
                                <form method="post" action="<?= e(url('/student/classrooms/'.(int)$jc['id'].'/leave')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Leave <?= e($jc['subject_code']) ?>?')">Leave</button>
                                </form>
                            </div>
                            <div class="small text-muted mt-1">Joined <?= e(date('M j, Y', strtotime($jc['joined_at']))) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>document.getElementById('invite_code_join')?.addEventListener('input', e => e.target.value = e.target.value.toUpperCase());</script>

<?php $classroomTasks = $classroomTasks ?? []; ?>
<?php
$approvedPermitPeriods = $approvedPermitPeriods ?? [];
$permitOkMap = [];
foreach ($approvedPermitPeriods as $ap) { $permitOkMap[(int)($ap['academic_year_id'] ?? 0) . '|' . trim((string)($ap['exam_period'] ?? ''))] = true; }
$examPeriodsFeed = ['Prelim', 'Midterm', 'Prefinal', 'Finals'];
?>
<?php if ($joinedClassrooms !== []): ?>
<div class="card p-3 mb-4 border-0 shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-journal-text text-primary"></i> Subject Tasks <span class="badge bg-primary bg-opacity-10 text-primary border"><?= count($classroomTasks) ?> due</span></h2>
        <span class="small text-muted">From your joined subjects</span>
    </div>
    <?php if ($classroomTasks === []): ?>
        <p class="text-muted small text-center py-3 mb-0">No subject tasks yet. Your teachers will post activities here — check your joined subjects above.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Task</th><th>Subject</th><th>Due</th><th>Your Upload</th><th>Raw Score</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php foreach ($classroomTasks as $ct):
                    $days = $ct['due_at'] ? (int) floor((strtotime($ct['due_at']) - time()) / 86400) : null;
                    $overdue = $days !== null && $days < 0;
                    $hasSub = !empty($ct['submission_id']);
                    $subStatus = $ct['submission_status'] ?? null;
                    $ctPeriod = trim((string)($ct['period_code'] ?? ''));
                    $ctIsExam = in_array($ctPeriod, $examPeriodsFeed, true);
                    $ctPermitOk = !$ctIsExam || isset($permitOkMap[(int)($ct['academic_year_id'] ?? 0) . '|' . $ctPeriod]);
                ?>
                    <tr class="classroom-task-row" role="button" tabindex="0" style="cursor:pointer" onclick="window.location='<?= e(url('/classroom-tasks/'.$ct['id'])) ?>'" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='<?= e(url('/classroom-tasks/'.$ct['id'])) ?>';}" title="Click to open — upload your work" aria-label="Open task: <?= e($ct['title']) ?>">
                        <td>
                            <div class="fw-semibold text-primary"><i class="bi bi-journal-text"></i> <?= e($ct['title']) ?>
                                <?php if ($ctIsExam): ?>
                                    <?php if ($ctPermitOk): ?><span class="badge bg-success ms-1" style="font-size:.6rem"><i class="bi bi-patch-check"></i> <?= e($ctPeriod) ?> permit OK</span>
                                    <?php else: ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem"><i class="bi bi-lock"></i> <?= e($ctPeriod) ?> permit needed</span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ct['instructions'])): ?><div class="small text-muted"><?= e(substr($ct['instructions'],0,80)) ?><?= strlen($ct['instructions'])>80 ? '…' : '' ?></div><?php endif; ?>
                        </td>
                        <td><span class="badge text-bg-light border"><?= e($ct['subject_code']) ?></span><div class="small text-muted"><?= e($ct['subject_title']) ?></div></td>
                        <td>
                            <?php if ($ct['due_at']): ?>
                                <span class="badge <?= $overdue ? 'text-bg-danger' : ($days !== null && $days <= 2 ? 'text-bg-warning' : 'text-bg-secondary') ?>"><?= e(date('M j', strtotime($ct['due_at']))) ?></span>
                                <span class="small text-muted ms-1"><?= $overdue ? 'overdue' : ($days === 0 ? 'today' : ($days > 0 ? 'in '.$days.' day(s)' : '')) ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-light border">No due date</span>
                            <?php endif; ?>
                        </td>
                        <td class="small" onclick="event.stopPropagation()">
                            <?php if ($hasSub): ?>
                                <span class="badge <?= $subStatus==='late' ? 'bg-warning text-dark' : ($subStatus==='graded' ? 'bg-success' : ($subStatus==='returned' ? 'bg-danger' : 'bg-success')) ?>"><i class="bi bi-check-circle"></i> <?= e(ucfirst($subStatus ?? 'submitted')) ?></span>
                                <?php if (!empty($ct['original_filename'])): ?>
                                    <div class="mt-1"><a href="<?= e(url('/uploads/'.$ct['file_path'])) ?>" target="_blank" class="fw-semibold small" onclick="event.stopPropagation()"><i class="bi bi-file-earmark"></i> <?= e($ct['original_filename']) ?></a></div>
                                <?php elseif (!empty($ct['submission_id'])): ?>
                                    <div class="small text-muted">Note submitted</div>
                                <?php endif; ?>
                                <div class="small text-muted" style="font-size:.65rem"><?= !empty($ct['submission_at']) ? e(date('M j g:ia', strtotime($ct['submission_at']))) : '' ?></div>
                            <?php else: ?>
                                <span class="badge bg-light border text-dark">Not yet</span>
                                <div class="small text-muted" style="font-size:.65rem">Click to upload</div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php $ctMax = max(0.01, (float)($ct['max_score'] ?? 100)); if ($subStatus === 'graded' && isset($ct['score']) && $ct['score'] !== null && $ct['score'] !== ''): ?><div class="fw-semibold"><?= e(\App\Core\GradeEngine::fmtRaw((float)$ct['score'], $ctMax)) ?></div><div class="text-muted"><?= e(\App\Core\GradeEngine::fmtPct(\App\Core\GradeEngine::pct((float)$ct['score'], $ctMax))) ?></div><div class="text-muted" style="font-size:.6rem">raw, not final grade</div><?php elseif ($hasSub): ?><span class="text-muted">—</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                        <td class="text-end"><a href="<?= e(url('/classroom-tasks/'.$ct['id'])) ?>" class="btn btn-sm <?= $hasSub ? 'btn-success' : 'btn-outline-primary' ?>" onclick="event.stopPropagation()"><i class="bi bi-<?= $hasSub ? 'eye' : 'box-arrow-up' ?>"></i> <?= $hasSub ? 'View' : 'Open & Upload' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <?= edu_stat_card([
            'label' => 'General average',
            'value' => $avgGrade === null ? '&mdash;' : e(number_format($avgGrade, 2)),
            'icon'  => 'bi-graph-up-arrow',
            'tone'  => 'info',
            'meta'  => '<span>Released final grades only</span>',
            'href'  => url('/student/grades'),
        ]) ?>
    </div>
    <div class="col-6 col-lg-4">
        <?= edu_stat_card([
            'label' => 'Outstanding balance',
            'value' => '<span style="font-size:1.35rem">' . money($balance) . '</span>',
            'icon'  => 'bi-wallet2',
            'tone'  => $balanceFloat > 0.0 ? 'warning' : 'success',
            'meta'  => ($balanceFloat === 0.0 && $student
                ? '<span class="edu-badge edu-badge--success"><i class="bi bi-check-circle-fill"></i> Fully paid</span>'
                : ($student ? '<span class="edu-badge edu-badge--info"><i class="bi bi-clock-fill"></i> Installment / balance due</span>' : '<span>Log in to see your ledger</span>'))
                . ($student
                    ? '<span class="d-flex gap-2 mt-2"><a href="' . e((int) ($payAppId ?? 0) > 0 ? url('/enroll/' . (int) $payAppId . '/pay') : url('/my-enrollments')) . '" class="btn btn-sm btn-success flex-grow-1"><i class="bi bi-cash-coin me-1"></i> Pay now</a>'
                      . '<a href="' . e(url('/student/permits')) . '" class="btn btn-sm btn-outline-primary">Permits</a></span>'
                    : ''),
        ]) ?>
    </div>
    <div class="col-12 col-lg-4">
        <?= edu_stat_card([
            'label' => 'Tasks due soon',
            'value' => '<span data-live-count="tasks_due">' . $taskCount . '</span>',
            'icon'  => 'bi-list-task',
            'tone'  => $taskCount > 0 ? 'warning' : 'success',
            'meta'  => '<span>' . ($taskCount > 0 ? 'See deadlines below' : 'Nothing outstanding') . '</span>',
            'href'  => url('/student/my-classes'),
        ]) ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-list-task"></i> Upcoming deadlines</h2>
                <?php if ($student !== null && $dueAssignments !== []): ?>
                    <span class="edu-badge edu-badge--warning"><?= count($dueAssignments) ?> due</span>
                <?php endif; ?>
            </div>
            <div class="edu-card__body">
            <?php if ($student === null || $dueAssignments === []): ?>
                <?= edu_empty_state('Nothing due right now', 'When a teacher posts a deadline it will appear here with its due date.', ['icon' => 'bi-calendar2-check', 'compact' => true]) ?>
            <?php else: ?>
                <div class="edu-table-wrap">
                    <div class="edu-table-scroll">
                        <table class="edu-table edu-table--cards">
                            <thead><tr><th>Task</th><th>Subject</th><th>Due</th></tr></thead>
                            <tbody>
                            <?php foreach ($dueAssignments as $d):
                                $days = (int) floor((strtotime($d['due_at']) - time()) / 86400); ?>
                                <tr>
                                    <td data-label="Task" class="fw-semibold"><?= e($d['title']) ?></td>
                                    <td data-label="Subject"><span class="edu-badge edu-badge--neutral"><?= e($d['subject_code']) ?></span></td>
                                    <td data-label="Due">
                                        <?= edu_badge(date('M j', strtotime($d['due_at'])), $days <= 2 ? 'danger' : 'neutral') ?>
                                        <span class="small ms-1" style="color:var(--text-tertiary)">
                                            <?= $days <= 0 ? 'today' : 'in ' . $days . ' day(s)' ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-megaphone"></i> Announcements</h2>
                <a href="<?= e(url('/announcements')) ?>" class="small text-decoration-none fw-semibold">View all</a>
            </div>
            <div class="edu-card__body">
            <?php if ($announcements === []): ?>
                <?= edu_empty_state("You're all caught up", 'No announcements have been posted for you yet.', ['icon' => 'bi-check2-circle', 'compact' => true]) ?>
            <?php else: ?>
                <div class="edu-feed">
                    <?php foreach ($announcements as $a): ?>
                        <div class="edu-feed__item">
                            <span class="edu-feed__icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
                            <div class="edu-feed__body">
                                <div class="edu-feed__title"><?= e($a['title']) ?></div>
                                <div class="small" style="color:var(--text-tertiary)"><?= e($a['excerpt']) ?>&hellip;</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
