<?php
 /** @var array $levels @var array $programs @var array $campuses @var array|null $currentAy @var bool $hasPending @var string[] $errors @var array $old @var array|null $clearance */
$campuses = $campuses ?? [];
$nextMode = $nextMode ?? null;
$isParentFlow = $isParentFlow ?? false;
$periodChoices = $periodChoices ?? [];
$basicPeriods = $periodChoices['basic'] ?? [];
$basicNoun = $periodChoices['basic_noun'] ?? 'Quarter';
$shsPeriods = $periodChoices['shs'] ?? [['code'=>'1st','name'=>'1st Semester'],['code'=>'2nd','name'=>'2nd Semester']];
$nextPeriodLabel = $nextMode['period_label'] ?? ((($nextMode['semester'] ?? '') !== '') ? (($nextMode['semester']) . ' Semester') : '');
?>
<a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> Back</a>
<?php if ($hasPending): ?>
    <?php $pendingApp = $pendingApp ?? null; $pStatus = $pendingApp['status'] ?? 'pending'; $pLabel = $pStatus==='awaiting_payment'?'Awaiting Payment':($pStatus==='action_required'?'Action Required':ucfirst(str_replace('_',' ',$pStatus))); ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-hourglass-split fs-5"></i>
        <div>You already have an ongoing application <strong><?= e($pendingApp['application_no'] ?? '') ?></strong> — status: <span class="badge text-bg-warning"><?= e($pLabel) ?></span>. Submitting another one is blocked until it is completed.
        <a href="<?= e(url('/my-enrollments')) ?>" class="alert-link">View my applications</a>
        <?php if ($pStatus==='awaiting_payment'): ?> · <a href="<?= e(url('/enroll/'.($pendingApp['id']??0).'/pay')) ?>" class="alert-link">Pay Now via PayRex</a><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?= edu_page_header([
    'eyebrow'     => 'Admissions',
    'title'       => !empty($nextMode) ? 'Re-enrollment application' : 'Enrollment application',
    'description' => 'Complete the sections below in order. Your progress is saved as you submit, and staff review each stage before the next.',
    'icon'        => 'bi-journal-plus',
    'actions'     => '<a href="' . e(url('/my-enrollments')) . '" class="btn btn-light btn-sm"><i class="bi bi-collection me-1"></i> My applications</a>'
                   . ($currentAy ? '<span class="edu-badge edu-badge--primary"><i class="bi bi-calendar3"></i> ' . e($currentAy['school_year']) . '</span>' : ''),
]) ?>

<!-- Enrollment wizard: where this application sits in the 7-step journey -->
<div class="edu-card mb-4">
  <div class="edu-card__head">
    <h2 class="edu-card__title"><i class="bi bi-signpost-split"></i> Enrolment journey</h2>
    <?php if (!empty($nextMode)): ?>
      <?= edu_badge('Re-enrollment · ' . e(($nextMode['level_name'] ?? '') . ' · ' . $nextPeriodLabel), 'accent', 'bi-arrow-repeat') ?>
    <?php else: ?>
      <?= edu_badge('Step 1 of 7', 'primary', 'bi-flag') ?>
    <?php endif; ?>
  </div>
  <div class="edu-card__body">
    <?= edu_stepper([
        ['label' => 'Eligibility',   'hint' => 'Checked first',  'state' => 'complete'],
        ['label' => 'Learner info', 'hint' => 'This form',      'state' => 'current'],
        ['label' => 'Subjects',     'hint' => 'Class offerings'],
        ['label' => 'Documents',    'hint' => 'Upload here'],
        ['label' => 'Review',       'hint' => 'Check & submit'],
        ['label' => 'Payment',      'hint' => 'PayRex'],
        ['label' => 'Confirmed',    'hint' => 'Enrolled'],
    ]) ?>

    <p class="small mb-0 mt-3" style="color:var(--text-tertiary)">
      <i class="bi bi-info-circle me-1"></i>
      Steps 1&ndash;2 happen on this page. After you submit, Accounting then the Registrar
      review your application before a payment link and your student number are issued.
    </p>
  </div>
</div>

<?php if (!empty($nextMode)): ?><div class="alert alert-info small"><i class="bi bi-arrow-repeat"></i> Re-enrolling for <strong><?= e(($nextMode['level_name'] ?? '') . ' — ' . $nextPeriodLabel . ', ' . ($nextMode['ay_label'] ?? '')) ?></strong> (same account and student number). Level, academic period, and school year are fixed by your academic progression.</div><?php endif; ?>
<?php if (!empty($enrollChildren) && empty($nextMode)): ?>
<div class="alert alert-light border small">
    <i class="bi bi-people"></i> <strong>Already have children linked?</strong> This form files a <strong>new child</strong>. To move a linked child forward, use their card instead:
    <span class="d-inline-flex flex-wrap gap-1 mt-1">
        <?php foreach ($enrollChildren as $ec): $ecid = (int) ($ec['id'] ?? 0); $ecn = trim(trim((string) ($ec['first_name'] ?? '')) . ' ' . trim((string) ($ec['last_name'] ?? ''))); if ($ecn === '') $ecn = 'Child #' . $ecid; ?>
            <a href="<?= e(url('/enrollment/eligibility?child=' . $ecid)) ?>" class="badge text-bg-light border text-decoration-none"><?= e($ecn) ?><?= !empty($ec['level_name']) ? ' · ' . e($ec['level_name']) : '' ?> →</a>
        <?php endforeach; ?>
    </span>
</div>
<?php endif; ?>
<?php if (!empty($enrollChild)): ?><div class="alert alert-primary small"><i class="bi bi-people"></i> Filing for <strong><?= e(trim(($enrollChild['first_name'] ?? '') . ' ' . ($enrollChild['last_name'] ?? '')) ?: ('Child #' . (int) ($enrollChild['id'] ?? 0))) ?></strong><?= !empty($enrollChild['student_no']) ? ' (' . e($enrollChild['student_no']) . ')' : '' ?> — Kindergarten to Junior High.</div><?php endif; ?>
<?php if (!empty($isParentFlow)): ?><div class="alert alert-info small"><i class="bi bi-people"></i> Parent account — enrollment here covers <strong>Kindergarten through Junior High</strong> levels only.</div><?php elseif (empty($nextMode)): ?><div class="alert alert-info small"><i class="bi bi-info-circle"></i> Enrollment covers <strong>Kindergarten through Senior High</strong> levels.</div><?php endif; ?>
<?php if (!empty($clearance)): ?><?php $clrSemRaw = trim($clearance['semester'] ?? ''); $clrSemLabel = in_array($clrSemRaw, ['1st','2nd'], true) ? ($clrSemRaw . ' Semester') : ($clrSemRaw !== '' ? $clrSemRaw : '—'); ?><div class="alert alert-success small"><i class="bi bi-patch-check-fill"></i> Using digital clearance <strong>#<?= e($clearance['id']) ?> — <?= e($clrSemLabel) ?> (<?= e($clearance['status']) ?>)</strong> — issued <?= e($clearance['issued_at'] ?? $clearance['created_at']) ?>. It will be marked <code>used</code> after submission.</div><?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <strong>Please fix the following:</strong>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($hasPending): ?>
    <div class="card p-5 text-center">
        <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-100 text-amber-700 d-flex align-items-center justify-content-center fs-4 mb-3"><i class="bi bi-hourglass-split"></i></div>
        <h2 class="h6">Application pending — <?= e($pLabel ?? 'Pending') ?></h2>
        <p class="small text-muted mb-3">Your application <strong><?= e($pendingApp['application_no'] ?? '') ?></strong> is currently <span class="badge text-bg-warning"><?= e($pLabel ?? 'pending') ?></span>. You cannot submit a new one until this is resolved.</p>
        <?php if (($pendingApp['status'] ?? '')==='awaiting_payment'): ?>
            <a href="<?= e(url('/enroll/'.($pendingApp['id']??0).'/pay')) ?>" class="btn btn-success mb-2"><i class="bi bi-credit-card"></i> Pay Now <?= money(class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::effectiveDue($pendingApp['amount_due'] ?? null) : (float)($pendingApp['amount_due'] ?? 0)) ?> via PayRex</a>
            <div class="small text-muted">Accounting & Registrar approved — pay to become fully enrolled.</div>
        <?php elseif (($pendingApp['status'] ?? '')==='action_required'): ?>
            <div class="alert alert-warning small text-start d-inline-block">Registrar needs action: <?= e($pendingApp['remarks'] ?? 'Please check My Applications for details.') ?></div>
        <?php endif; ?>
        <div class="mt-3"><a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-outline-primary">View my applications</a></div>
    </div>
<?php else: ?>
<form method="post" action="<?= e(url('/enroll')) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <?php if (!empty($enrollChild)): ?><input type="hidden" name="child_id" value="<?= (int) ($enrollChild['id'] ?? 0) ?>"><?php endif; ?>

    <?php if (!empty($isParentFlow) && empty($nextMode) && empty($enrollChild)): ?>
    <!-- Child Information (new child — becomes their own student record) -->
    <div class="card p-4 mb-3 border-primary">
        <h2 class="h6 mb-1"><span class="badge text-bg-primary">0</span> Child Information</h2>
        <p class="small text-muted mb-3">Who is this application for? A separate student record is created for this child — your own profile is never overwritten. Duplicate filings for an already-linked child are blocked automatically.</p>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label for="child_first_name" class="form-label fw-semibold">Child's first name <span class="text-danger">*</span></label>
                <input class="form-control" id="child_first_name" name="child_first_name" required maxlength="100" autocomplete="off" value="<?= e($old['child_first_name'] ?? '') ?>" placeholder="e.g. Maria">
            </div>
            <div class="col-12 col-md-4">
                <label for="child_middle_name" class="form-label">Middle name</label>
                <input class="form-control" id="child_middle_name" name="child_middle_name" maxlength="100" autocomplete="off" value="<?= e($old['child_middle_name'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-4">
                <label for="child_last_name" class="form-label fw-semibold">Child's last name <span class="text-danger">*</span></label>
                <input class="form-control" id="child_last_name" name="child_last_name" required maxlength="100" autocomplete="off" value="<?= e($old['child_last_name'] ?? '') ?>" placeholder="e.g. Dela Cruz">
            </div>
            <div class="col-6 col-md-3">
                <label for="child_suffix" class="form-label">Suffix</label>
                <input class="form-control" id="child_suffix" name="child_suffix" maxlength="20" value="<?= e($old['child_suffix'] ?? '') ?>" placeholder="Jr., III">
            </div>
            <div class="col-6 col-md-3">
                <label for="child_dob" class="form-label fw-semibold">Date of birth <span class="text-danger">*</span></label>
                <input class="form-control" id="child_dob" type="date" name="child_dob" required max="<?= date('Y-m-d') ?>" value="<?= e($old['child_dob'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label for="child_gender" class="form-label">Sex</label>
                <select class="form-select" id="child_gender" name="child_gender">
                    <option value="">Select</option>
                    <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $gv => $gl): ?>
                        <option value="<?= $gv ?>" <?= ($old['child_gender'] ?? '') === $gv ? 'selected' : '' ?>><?= $gl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="child_birthplace" class="form-label">Birthplace</label>
                <input class="form-control" id="child_birthplace" name="child_birthplace" maxlength="150" value="<?= e($old['child_birthplace'] ?? '') ?>" placeholder="City / Province">
            </div>
            <div class="col-12 col-md-6">
                <label for="child_nationality" class="form-label">Nationality</label>
                <input class="form-control" id="child_nationality" name="child_nationality" maxlength="60" value="<?= e($old['child_nationality'] ?? 'Filipino') ?>">
            </div>
            <div class="col-12 col-md-6">
                <label for="child_address" class="form-label">Home address</label>
                <input class="form-control" id="child_address" name="child_address" maxlength="150" value="<?= e($old['child_address'] ?? '') ?>" placeholder="House / Street / Barangay">
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php $parentInfo = $parentInfo ?? null; ?>
    <?php if (!empty($parentInfo)): ?>
    <div class="alert alert-light border small">
        <i class="bi bi-person-check"></i> Filing as <strong><?= e(trim(trim((string) ($parentInfo['first_name'] ?? '')) . ' ' . trim((string) ($parentInfo['last_name'] ?? '')))) ?></strong>
        (<?= e($parentInfo['email'] ?? '') ?><?= !empty($parentInfo['phone']) ? ' · ' . e($parentInfo['phone']) : '' ?>) — taken from your account, no need to retype it.
    </div>
    <?php endif; ?>

    <!-- Academic Information -->
    <div class="card p-4 mb-3">
        <h2 class="h6 mb-3"><span class="badge text-bg-primary">1</span> Academic Information</h2>
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="grade_level_id" class="form-label fw-semibold">Applying for <span class="text-danger">*</span></label>
                <?php if (!empty($nextMode)): ?>
                    <input type="hidden" name="grade_level_id" value="<?= (int)($nextMode['level_id'] ?? 0) ?>">
                    <input class="form-control" value="<?= e(($nextMode['level_name'] ?? '') . ' — ' . $nextPeriodLabel . ' (' . ($nextMode['ay_label'] ?? '') . ')') ?>" disabled>
                    <div class="form-text">Fixed by your progression — verified again on submit.</div>
                <?php else: ?>
                <select class="form-select" id="grade_level_id" name="grade_level_id" required>
                    <option value="">Select level</option>
                    <?php
                    $group = null;
                    foreach ($levels as $lv):
                        if ($group !== $lv['category']) {
                            if ($group !== null) echo '</optgroup>';
                            $label = match($lv['category']) { 'kindergarten'=>'Kindergarten','elementary'=>'Elementary','junior_high'=>'Junior High','senior_high'=>'Senior High','college'=>'College', default => $lv['category'] };
                            echo '<optgroup label="' . e($label) . '">';
                            $group = $lv['category'];
                        }
                        $sel = (int)($old['grade_level_id'] ?? 0) === (int)$lv['id'] ? ' selected' : '';
                        echo '<option value="' . e((string)$lv['id']) . '" data-category="' . e($lv['category']) . '"' . $sel . '>' . e($lv['name']) . ' (' . e($lv['code']) . ')</option>';
                    endforeach;
                    if ($group !== null) echo '</optgroup>';
                    ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-6" id="programWrap" style="display:none;">
                <label for="program_id" class="form-label fw-semibold">College Program <span class="text-danger">*</span></label>
                <select class="form-select" id="program_id" name="program_id">
                    <option value="">Select program</option>
                    <?php foreach ($programs as $pg):
                        $sel = (int)($old['program_id'] ?? 0) === (int)$pg['id'] ? ' selected' : '';
                    ?>
                        <option value="<?= e((string)$pg['id']) ?>"<?= $sel ?>><?= e($pg['code']) ?> - <?= e($pg['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6" id="periodWrap" style="display:none;">
                <label for="period_code" class="form-label fw-semibold"><span id="periodNounLabel"><?= e($basicNoun) ?></span> <span class="text-danger">*</span></label>
                <select class="form-select" id="period_code" name="period_code">
                    <option value="">Select <?= e(strtolower($basicNoun)) ?></option>
                    <?php foreach ($basicPeriods as $bp):
                        $sel = trim($old['period_code'] ?? '') === (string)$bp['code'] ? ' selected' : '';
                    ?>
                        <option value="<?= e($bp['code']) ?>"<?= $sel ?>><?= e($bp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Basic Education (K–Grade 10) uses <?= e($basicNoun) ?>s, not semesters.</div>
            </div>
            <div class="col-12 col-md-6" id="semWrap" style="display:none;">
                <label for="semester" class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                <select class="form-select" id="semester" name="semester">
                    <option value="">Select semester</option>
                    <?php foreach ($shsPeriods as $sp):
                        $sel = trim($old['semester'] ?? '1st') === (string)$sp['code'] ? ' selected' : '';
                    ?>
                        <option value="<?= e($sp['code']) ?>"<?= $sel ?>><?= e($sp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Senior High School uses 1st / 2nd Semester.</div>
            </div>
            <div class="col-12 col-md-6">
                <label for="preferred_campus_id" class="form-label fw-semibold"><i class="bi bi-geo-alt text-primary"></i> Preferred Campus / Location <span class="text-danger">*</span></label>
                <select class="form-select" id="preferred_campus_id" name="preferred_campus_id" required>
                    <option value="">Select campus nearest you</option>
                    <?php foreach ($campuses as $cp):
                        $sel = (int)($old['preferred_campus_id'] ?? 0) === (int)$cp['id'] ? ' selected' : '';
                        $label = e($cp['name']) . ' — ' . e($cp['address']);
                    ?>
                        <option value="<?= e((string)$cp['id']) ?>"<?= $sel ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Choose the school location closest to you. This helps assign you to the nearest site for enrollment & classes.</div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Applicant type <span class="text-danger">*</span></label>
                <?php if (!empty($nextMode)): ?>
                    <input type="hidden" name="new_or_returning" value="returning">
                    <div class="d-flex gap-3">
                        <?php foreach (['new'=>'New','returning'=>'Returning','transferee'=>'Transferee'] as $val=>$label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" <?= $val === 'returning' ? 'checked' : '' ?> disabled>
                            <label class="form-check-label<?= $val === 'returning' ? ' fw-bold' : ' text-muted' ?>"><?= $label ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text"><i class="bi bi-lock-fill"></i> Locked to Returning — re-enrollment uses your existing account.</div>
                <?php else: ?>
                <div class="d-flex gap-3">
                    <?php foreach (['new'=>'New','returning'=>'Returning','transferee'=>'Transferee'] as $val=>$label):
                        $chk = ($old['new_or_returning'] ?? 'new') === $val ? ' checked' : '';
                    ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="new_or_returning" id="type_<?= $val ?>" value="<?= $val ?>"<?= $chk ?>>
                        <label class="form-check-label" for="type_<?= $val ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($nextMode)): ?>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Student Number (permanent — read-only)</label>
                <input class="form-control font-monospace fw-bold" value="<?= e($nextMode['student_no'] ?? '') ?>" disabled readonly>
                <div class="form-text"><i class="bi bi-lock-fill"></i> Loaded from your account automatically. Contact the Registrar if correction is needed.</div>
            </div>
            <?php else: ?>
            <div class="col-12 col-md-6" id="returningStudentNoWrap" style="display:none;">
                <label for="returning_student_no" class="form-label fw-semibold">Student Number <span class="text-danger">*</span></label>
                <input class="form-control" id="returning_student_no" name="returning_student_no" placeholder="e.g. 2023022" pattern="\d{7,10}" maxlength="10" inputmode="numeric" value="<?= e($old['returning_student_no'] ?? '') ?>">
                <div class="form-text">Enter your official school Student Number (7-10 digits, e.g. 2023022). Required for returning students only.</div>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-6">
                <label for="last_grade_completed" class="form-label">Last grade completed</label>
                <input class="form-control" id="last_grade_completed" name="last_grade_completed" placeholder="e.g. Grade 10" value="<?= e($old['last_grade_completed'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label for="previous_school" class="form-label fw-semibold">Previous school <span class="text-danger">*</span></label>
                <input class="form-control" id="previous_school" name="previous_school" required placeholder="Name of previous school" value="<?= e($old['previous_school'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- Documents (reuse: verified docs on file are NOT asked again) -->
    <?php
    $reusedDocs = $reusedDocs ?? []; $neededDocs = $neededDocs ?? [];
    ?>
    <?php if ($reusedDocs !== []): ?>
    <div class="alert alert-success small"><i class="bi bi-check-circle-fill"></i> <strong>Documents on file are reused automatically</strong> — you only upload what is missing or Accounting explicitly requested. <a href="<?= e(url('/student/enrollment-docs')) ?>" class="alert-link">Manage my documents</a></div>
    <div class="card p-3 mb-3"><h2 class="h6 mb-2"><i class="bi bi-folder-check"></i> Already on file</h2>
      <div class="row g-2">
      <?php foreach ($reusedDocs as $t => $d): $st = (string)($d['status'] ?? ''); ?>
        <div class="col-12 col-md-4"><div class="border rounded-2 p-2 small">
          <div class="fw-semibold"><?= e(\App\Core\StudentDocs::label($t)) ?></div>
          <div><span class="badge text-bg-<?= $st === 'verified' ? 'success' : ($st === 'needs_resubmission' ? 'warning' : 'secondary') ?>"><?= e($st) ?></span> <span class="text-muted">v<?= (int)($d['version'] ?? 1) ?></span></div>
          <?php if ($st === 'needs_resubmission' && !empty($d['review_reason'])): ?><div class="text-warning mt-1"><i class="bi bi-exclamation-triangle"></i> <?= e($d['review_reason']) ?> — please re-upload below.</div><?php endif; ?>
        </div></div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card p-4 mb-3">
        <h2 class="h6 mb-1"><span class="badge text-bg-primary">2</span> Required Documents</h2>
        <p class="small text-muted">Accepted: PDF, JPG, PNG, WebP — max 10 MB each. Only missing / requested items are required.</p>
        <?php
        $docRules = $docRules ?? [];
        $docLabels = [];
        foreach (['birth_certificate','good_moral','id_photo','form137','form138_report_card','valid_id','eccd_checklist'] as $dt) {
            $docLabels[$dt] = \App\Core\StudentDocs::label($dt);
        }
        $kinderNote = \App\Core\StudentDocs::kinderAgeNote();
        ?>
        <div id="docChecklist" class="alert alert-info small">
            <i class="bi bi-info-circle"></i> <span id="docChecklistText">Select the <strong>Applying for</strong> level above to see exactly which documents are needed.</span>
            <ul id="docChecklistItems" class="mb-0 mt-2 ps-3"></ul>
        </div>
        <div id="kinderAgeNote" class="alert alert-warning small" hidden><i class="bi bi-exclamation-triangle"></i> <strong>Age note (Kindergarten):</strong> <?= e($kinderNote) ?></div>
        <div id="docWaivers" class="alert alert-light border small">
            <div class="fw-semibold mb-1"><i class="bi bi-patch-check"></i> No previous school papers? Declare it here instead of being blocked:</div>
            <div class="form-check" id="waiveEccdWrap" hidden>
                <input class="form-check-input" type="checkbox" id="waive_eccd" name="waive_eccd" value="1" <?= !empty($old['waive_eccd']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="waive_eccd">My child <strong>did not attend</strong> preschool/daycare — no ECCD record exists.</label>
            </div>
            <div class="form-check" id="waiveForm138Wrap" hidden>
                <input class="form-check-input" type="checkbox" id="waive_form138" name="waive_form138" value="1" <?= !empty($old['waive_form138']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="waive_form138">My child <strong>did not finish Kindergarten</strong> — no report card exists. (Grade 1 new applicants only.)</label>
            </div>
            <div class="form-text">Ticked papers are skipped and recorded as your declaration for the reviewers. PSA Birth Certificate and 2×2 photo are always required.</div>
        </div>
        <div class="row g-3" id="docInputs">
            <div class="col-12 col-md-6" data-docwrap="form137">
                <label for="form137" class="form-label"><span data-doclabel>Form 137 (Permanent Record)</span> <span data-docstar></span></label>
                <input class="form-control" type="file" id="form137" name="form137" accept=".pdf,.jpg,.jpeg,.png,.webp" data-docinput="form137">
                <div class="form-text" data-dochint></div>
                <div data-docbadge></div>
            </div>
            <div class="col-12 col-md-6" data-docwrap="form138_report_card">
                <label for="form138" class="form-label"><span data-doclabel>Form 138 / Report Card</span> <span data-docstar></span></label>
                <input class="form-control" type="file" id="form138" name="form138" accept=".pdf,.jpg,.jpeg,.png,.webp" data-docinput="form138_report_card">
                <div class="form-text" data-dochint></div>
                <div data-docbadge></div>
            </div>
            <div class="col-12 col-md-6" data-docwrap="birth_certificate">
                <label for="birth_certificate" class="form-label fw-semibold"><span data-doclabel>PSA Birth Certificate</span> <span data-docstar></span></label>
                <input class="form-control" type="file" id="birth_certificate" name="birth_certificate" accept=".pdf,.jpg,.jpeg,.png,.webp" data-docinput="birth_certificate">
                <div class="form-text" data-dochint></div>
                <div data-docbadge></div>
            </div>
            <div class="col-12 col-md-6" data-docwrap="good_moral">
                <label for="good_moral" class="form-label fw-semibold"><span data-doclabel>Certificate of Good Moral</span> <span data-docstar></span></label>
                <input class="form-control" type="file" id="good_moral" name="good_moral" accept=".pdf,.jpg,.jpeg,.png,.webp" data-docinput="good_moral">
                <div class="form-text" data-dochint></div>
                <div data-docbadge></div>
            </div>
            <div class="col-12 col-md-6" data-docwrap="id_photo">
                <label for="id_photo" class="form-label fw-semibold"><span data-doclabel>2x2 ID Photo</span> <span data-docstar></span></label>
                <input class="form-control" type="file" id="id_photo" name="id_photo" accept=".jpg,.jpeg,.png,.webp" data-docinput="id_photo">
                <div class="form-text" data-dochint></div>
                <div data-docbadge></div>
            </div>
            <div class="col-12 col-md-6" data-docwrap="valid_id">
                <label for="valid_id" class="form-label"><span data-doclabel>Valid ID</span> <span data-docstar></span></label>
                <input class="form-control" type="file" id="valid_id" name="valid_id" accept=".pdf,.jpg,.jpeg,.png,.webp" data-docinput="valid_id">
                <div class="form-text" data-dochint></div>
                <div data-docbadge></div>
            </div>
            <div class="col-12 col-md-6" data-docwrap="eccd_checklist">
                <label for="eccd_checklist" class="form-label"><span data-doclabel>ECCD Checklist / Preschool Record</span> <span data-docstar></span></label>
                <input class="form-control" type="file" id="eccd_checklist" name="eccd_checklist" accept=".pdf,.jpg,.jpeg,.png,.webp" data-docinput="eccd_checklist">
                <div class="form-text" data-dochint></div>
                <div data-docbadge></div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var rules = <?= json_encode($docRules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var labels = <?= json_encode($docLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var reused = <?= json_encode(array_map(static function ($d) { return (string) ($d['status'] ?? ''); }, $reusedDocs), JSON_UNESCAPED_SLASHES) ?>;
        var grade = document.getElementById('grade_level_id');
        var checklist = document.getElementById('docChecklistText');
        var checklistItems = document.getElementById('docChecklistItems');
        var kinderNote = document.getElementById('kinderAgeNote');
        function applicantType(){
            <?php if (!empty($nextMode)): ?>return 'returning';<?php endif; ?>
            var c = document.querySelector('input[name="new_or_returning"]:checked');
            return c ? c.value : 'new';
        }
        function currentRule(){
            var type = applicantType();
            if (grade && grade.value && rules[grade.value]) {
                var r = rules[grade.value];
                var req = r[type] || r['new'] || [];
                return { req: req, hints: r.hints || {}, code: r.code || '', name: r.name || '' };
            }
            return null;
        }
        function onFile(t){ return reused[t] === 'verified' || reused[t] === 'submitted'; }
        function refreshDocs(){
            var rule = currentRule();
            var type = applicantType();
            var waiveEccd = document.getElementById('waive_eccd');
            var waiveEccdWrap = document.getElementById('waiveEccdWrap');
            var waive138 = document.getElementById('waive_form138');
            var waive138Wrap = document.getElementById('waiveForm138Wrap');
            function waiverApplies(t){
                if (!rule) return false;
                if (t === 'eccd_checklist') {
                    return rule.req.indexOf(t) !== -1 && (rule.code === 'K' || (rule.code === 'G1' && type === 'new'));
                }
                if (t === 'form138_report_card') {
                    return rule.req.indexOf(t) !== -1 && rule.code === 'G1' && type === 'new';
                }
                return false;
            }
            function isWaived(t){
                if (t === 'eccd_checklist' && waiveEccd && waiveEccd.checked && waiverApplies(t)) return true;
                if (t === 'form138_report_card' && waive138 && waive138.checked && waiverApplies(t)) return true;
                return false;
            }
            if (!rule) {
                checklist.innerHTML = 'Select the <strong>Applying for</strong> level above to see exactly which documents are needed.';
                checklistItems.innerHTML = '';
                if (kinderNote) kinderNote.hidden = true;
            } else {
                checklist.innerHTML = 'For <strong>' + rule.name + '</strong> (' + type + '): you need <strong>' + rule.req.length + '</strong> document(s).';
                var html = '';
                rule.req.forEach(function(t){
                    var mark = onFile(t) ? ' <span class="badge text-bg-success">On file — reused</span>' : (isWaived(t) ? ' <span class="badge text-bg-secondary">Waived — declared</span>' : ' <span class="badge text-bg-warning text-dark">Required</span>');
                    var hint = rule.hints[t] ? ' <span class="text-muted">— ' + rule.hints[t] + '</span>' : '';
                    html += '<li>' + (labels[t] || t) + mark + hint + '</li>';
                });
                checklistItems.innerHTML = html;
                if (kinderNote) kinderNote.hidden = !(rule.code === 'K');
            }
            if (waiveEccdWrap) {
                var showEccd = waiverApplies('eccd_checklist');
                waiveEccdWrap.hidden = !showEccd;
                if (!showEccd && waiveEccd) waiveEccd.checked = false;
            }
            if (waive138Wrap) {
                var show138 = waiverApplies('form138_report_card');
                waive138Wrap.hidden = !show138;
                if (!show138 && waive138) waive138.checked = false;
            }
            document.querySelectorAll('[data-docwrap]').forEach(function(wrap){
                var t = wrap.getAttribute('data-docwrap');
                var input = wrap.querySelector('[data-docinput]');
                var star = wrap.querySelector('[data-docstar]');
                var hint = wrap.querySelector('[data-dochint]');
                var needed = !!rule && rule.req.indexOf(t) !== -1 && !onFile(t) && !isWaived(t);
                var visible = !rule || (rule.req.indexOf(t) !== -1) || (reused[t] && reused[t] !== 'missing');
                wrap.style.display = visible ? '' : 'none';
                if (input) input.required = needed;
                if (star) star.innerHTML = needed ? '<span class="text-danger">*</span>' : (onFile(t) ? '<span class="badge text-bg-success">On file — reused</span>' : '');
                if (hint) hint.textContent = (rule && rule.hints[t]) || '';
                var label = wrap.querySelector('[data-doclabel]');
                if (label) {
                    if (t === 'form138_report_card' && rule && rule.code === 'G1') label.textContent = 'Kindergarten Report Card (SF9)';
                    else if (t === 'form138_report_card' && rule && rule.code === 'G7') label.textContent = 'Grade 6 Report Card (SF9)';
                    else label.textContent = labels[t] || t;
                }
            });
        }
        if (grade) grade.addEventListener('change', refreshDocs);
        document.querySelectorAll('input[name="new_or_returning"]').forEach(function(r){ r.addEventListener('change', refreshDocs); });
        var wE = document.getElementById('waive_eccd');
        var wF = document.getElementById('waive_form138');
        if (wE) wE.addEventListener('change', refreshDocs);
        if (wF) wF.addEventListener('change', refreshDocs);
        refreshDocs();
    })();
    </script>

    <div class="d-flex justify-content-between align-items-center">
        <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-outline-secondary">View my applications</a>
        <button type="submit" class="btn btn-primary btn-lg px-4"><i class="bi bi-send"></i> Submit Application</button>
    </div>
</form>

<script>
(function(){
  var grade = document.getElementById("grade_level_id");
  var progWrap = document.getElementById("programWrap");
  var prog = document.getElementById("program_id");
  var studentNoWrap = document.getElementById("returningStudentNoWrap");
  var studentNoInput = document.getElementById("returning_student_no");

  function toggleProgram(){
    if(!grade) return;
    var opt = grade.options[grade.selectedIndex];
    var cat = opt ? opt.getAttribute("data-category") : "";
    if(cat === "college"){ progWrap.style.display = ""; prog.required = true; }
    else { progWrap.style.display = "none"; prog.required = false; prog.value = ""; }
  }

  var periodWrap = document.getElementById("periodWrap");
  var periodSel = document.getElementById("period_code");
  var semWrap = document.getElementById("semWrap");
  var semSel = document.getElementById("semester");
  function basicCat(cat){ return cat === "kindergarten" || cat === "elementary" || cat === "junior_high"; }
  function togglePeriod(){
    if(!grade) return;
    var opt = grade.options[grade.selectedIndex];
    var cat = opt ? opt.getAttribute("data-category") : "";
    var isBasic = basicCat(cat);
    var isShs = cat === "senior_high";
    if(periodWrap) periodWrap.style.display = isBasic ? "" : "none";
    if(periodSel){ periodSel.required = isBasic; if(!isBasic) periodSel.value = ""; }
    if(semWrap) semWrap.style.display = isShs ? "" : "none";
    if(semSel){ semSel.required = isShs; if(!isShs) semSel.value = ""; }
  }

  function toggleReturning(){
    // Re-enrollment mode: type is locked to Returning (no radios, no input) — nothing to toggle.
    if(!studentNoWrap || !studentNoInput) return;
    var checked = document.querySelector('input[name="new_or_returning"]:checked');
    var val = checked ? checked.value : "new";
    if(val === "returning"){
      studentNoWrap.style.display = "";
      studentNoInput.required = true;
    } else {
      studentNoWrap.style.display = "none";
      studentNoInput.required = false;
      studentNoInput.value = "";
    }
  }

  if(grade){ grade.addEventListener("change", toggleProgram); grade.addEventListener("change", togglePeriod); toggleProgram(); togglePeriod(); }
  document.querySelectorAll('input[name="new_or_returning"]').forEach(function(r){
    r.addEventListener("change", toggleReturning);
  });
  toggleReturning();

  // Numeric-only filter for student number (7-10 digits, e.g. 2023022)
  if(studentNoInput){
    studentNoInput.addEventListener("input", function(){
      this.value = this.value.replace(/\D/g, "").slice(0,10);
    });
  }
})();
</script>
<?php endif; ?>