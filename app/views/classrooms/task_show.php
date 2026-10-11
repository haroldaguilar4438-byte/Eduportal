<?php
/** @var array $task @var array|null $mySubmission @var array $submissions @var bool $isTeacher */ $isParent=$isParent??false; $isOwner=$isOwner??false;
$task = $task ?? [];
$mySubmission = $mySubmission ?? null;
$submissions = $submissions ?? [];
$isTeacher = $isTeacher ?? false;
$permitLocked = $permitLocked ?? false;
$lockPeriod = $lockPeriod ?? null;
$dueTs = !empty($task['due_at']) ? strtotime($task['due_at']) : null;
$now = time();
$isOverdue = $dueTs && $now > $dueTs;
$isDueSoon = $dueTs && !$isOverdue && ($dueTs - $now) <= 2*86400;
$hasSubmitted = $mySubmission !== null;
?>
<?= edu_breadcrumb([['label' => 'Dashboard', 'url' => url('/dashboard')], ['label' => 'Activity']]) ?>

<?php
$taskTitle = (string) ($task['title'] ?? 'Activity');
$subjectBits = trim(($task['subject_code'] ?? '') . ' ' . ($task['subject_title'] ?? ''));
$ownerActions = '';
if ($isOwner) {
    $ownerActions = '<a href="' . e(url('/teacher/classroom-tasks/' . (int) ($task['id'] ?? 0) . '/edit')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-pencil me-1"></i> Edit</a>'
        . '<form method="post" action="' . e(url('/teacher/classroom-tasks/' . (int) ($task['id'] ?? 0) . '/delete')) . '" class="d-inline"'
        . ' onsubmit="return confirm(\'Delete this activity? All submissions and files will be removed. This cannot be undone.\')">'
        . csrf_field()
        . '<button class="btn btn-outline-danger btn-sm ms-1"><i class="bi bi-trash"></i> Delete</button></form>';
}
$statusBadges = '';
if ($isOverdue) {
    $statusBadges .= ' ' . edu_badge('Archived — past due', 'neutral', 'bi-archive');
} elseif ($isDueSoon) {
    $statusBadges .= ' ' . edu_badge('Due soon', 'warning', 'bi-clock');
}
?>

<?= edu_page_header([
    'eyebrow'     => $subjectBits,
    'title'       => $taskTitle,
    'description' => 'Teacher: ' . trim(($task['teacher_first'] ?? '') . ' ' . ($task['teacher_last'] ?? ''))
        . ' · Posted ' . date('M j, Y g:ia', strtotime($task['created_at'] ?? 'now')),
    'icon'        => 'bi-journal-text',
    'actions'     => $statusBadges . $ownerActions,
]) ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="edu-card mb-3">
            <div class="edu-card__head">
                <?= edu_badge((string) ($task['subject_code'] ?? ''), 'primary', 'bi-book') ?>
                <?php if (!empty($task['invite_code'])): ?>
                    <span class="edu-badge edu-badge--accent" style="font-family:var(--font-mono)"><?= e($task['invite_code']) ?></span>
                <?php endif; ?>
            </div>
            <div class="edu-card__body">
            <?php if ($permitLocked && !$isTeacher): ?>
                <div class="alert alert-warning border-warning">
                    <div class="fw-bold"><i class="bi bi-lock-fill"></i> <?= e($lockPeriod ?? 'Exam') ?> permit required</div>
                    <div class="small">This is a <strong><?= e($lockPeriod ?? '') ?> exam</strong>. You need a <strong>fully approved <?= e($lockPeriod ?? '') ?> permit</strong> to view the questions and submit answers.</div>
                    <?php if (!$isParent): ?><a href="<?= e(url('/student/permits')) ?>" class="btn btn-sm btn-warning mt-2"><i class="bi bi-patch-check"></i> Request my <?= e($lockPeriod ?? '') ?> permit</a>
                    <?php else: ?><div class="small text-muted mt-1">Permits are requested on the student account.</div><?php endif; ?>
                </div>
            <?php else: ?>
            <?php if (!empty($task['file_path'])): ?>
                <div class="d-flex align-items-center gap-2 p-3 bg-primary bg-opacity-10 border border-primary rounded-3 mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-2 bg-primary text-white" style="width:40px;height:40px"><i class="bi bi-file-earmark-text"></i></span>
                    <div class="flex-1 min-w-0">
                        <div class="fw-semibold small text-truncate"><?= e($task['original_filename'] ?? 'Attached file') ?></div>
                        <div class="small text-muted">From your teacher · <?= isset($task['file_size_bytes']) ? number_format($task['file_size_bytes']/1024,1).' KB' : '' ?></div>
                    </div>
                    <a href="<?= e(url('/classroom-tasks/'.(int)($task['id'] ?? 0).'/file')) ?>" target="_blank" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-download"></i> View / Download</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($task['instructions'])): ?>
                <div class="p-3 bg-light border rounded-3 small mb-3"><?= nl2br(e($task['instructions'])) ?></div>
            <?php endif; ?>
            <?php endif; ?>

            <?php $taskMax = $taskMax ?? max(0.01, (float)($task['max_score'] ?? 100)); $catLabel = $task['category_name'] ?? ($task['category_code'] ?? 'Activities'); $taskPassing = $taskPassing ?? (isset($task['passing_score']) && $task['passing_score'] !== null && $task['passing_score'] !== '' ? (float)$task['passing_score'] : null); ?>
            <dl class="row small mb-0">
                <dt class="col-4 text-muted">Category</dt><dd class="col-8"><span class="badge bg-light border text-dark"><?= e($catLabel) ?></span><?php if (!empty($task['period_code'])): ?> <span class="badge bg-info bg-opacity-10 text-info border"><?= e($task['period_code']) ?> period</span><?php endif; ?></dd>
                <dt class="col-4 text-muted">Total Items / Max</dt><dd class="col-8"><span class="badge bg-primary"><?= e(rtrim(rtrim(number_format($taskMax, 2, '.', ''), '0'), '.')) ?> items</span><?php if ($taskPassing !== null): ?> <span class="badge bg-warning bg-opacity-10 text-warning border">Passing: <?= e(rtrim(rtrim(number_format($taskPassing, 2, '.', ''), '0'), '.')) ?></span><?php else: ?> <span class="text-muted">— no passing set</span><?php endif; ?> <span class="text-muted">— raw scores are entered against this total, not as a final grade</span></dd>
                <dt class="col-4 text-muted">Due</dt>
                <dd class="col-8">
                    <?php if (empty($task['due_at'])): ?><span class="badge text-bg-light border">No deadline</span>
                    <?php elseif ($isOverdue): ?><span class="badge text-bg-danger">Overdue — <?= e(date('M j, Y g:ia', $dueTs)) ?></span><div class="small text-danger">Late submissions will be marked “late”.</div>
                    <?php elseif ($isDueSoon): ?><span class="badge text-bg-warning">Due <?= e(date('M j, Y g:ia', $dueTs)) ?> · in <?= max(1, (int)ceil(($dueTs-$now)/86400)) ?> day(s)</span>
                    <?php else: ?><span class="badge text-bg-secondary">Due <?= e(date('M j, Y g:ia', $dueTs)) ?></span>
                    <?php endif; ?>
                </dd>
                <dt class="col-4 text-muted">Subject</dt><dd class="col-8"><?= e($task['subject_code'] ?? '') ?> — <?= e($task['subject_title'] ?? '') ?></dd>
            </dl>
            </div><!-- /.edu-card__body -->
        </div><!-- /.edu-card -->

        <?php if (!$isTeacher && !$permitLocked): ?>
        <!-- Student Upload Card (clickable task → upload) -->
        <?php $myStatus = $hasSubmitted ? ($mySubmission['status'] ?? '') : ''; ?>
        <?php $isGraded = $hasSubmitted && $myStatus === 'graded'; ?>
        <?php $isReturned = $hasSubmitted && $myStatus === 'returned'; ?>
        <?php /* Own file link only when the binary is on disk (see teacher rows below). */
              $myFileOk = !empty($mySubmission['file_path']) && is_file(ROOT_PATH . '/public_html/uploads/' . $mySubmission['file_path']); ?>
        <?php $subTone = $isGraded ? 'success' : ($isReturned ? 'danger' : 'primary'); ?>
        <div class="edu-card mb-3" style="border-top:4px solid var(--<?= $subTone === 'success' ? 'success' : ($subTone === 'danger' ? 'danger' : 'primary-700') ?>)">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-box-arrow-up"></i> Your submission</h2>
                <?php if ($isGraded): ?>
                    <?= edu_badge('GRADED — locked', 'success', 'bi-patch-check-fill') ?>
                <?php elseif ($isReturned): ?>
                    <?= edu_badge('REJECTED — revise & resubmit', 'danger', 'bi-x-circle-fill') ?>
                <?php endif; ?>
            </div>
            <div class="edu-card__body">

            <?php if ($hasSubmitted): ?>
                <?php if ($isGraded): ?>
                    <div class="alert alert-success py-2 small border-success">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-check-circle-fill"></i> <strong>GRADED</strong> — <?= e(date('M j, Y g:ia', strtotime($mySubmission['submitted_at']))) ?></span>
                            <?php if ($mySubmission['score'] !== null && $mySubmission['score'] !== ''): ?><span class="badge bg-success">Raw Score: <?= e(\App\Core\GradeEngine::fmtRaw((float)$mySubmission['score'], $taskMax)) ?> · <?= e(\App\Core\GradeEngine::fmtPct(\App\Core\GradeEngine::pct((float)$mySubmission['score'], $taskMax))) ?></span><?php $mpf = \App\Core\GradeEngine::passFail((float)$mySubmission['score'], $taskPassing); if ($mpf === true): ?> <span class="badge bg-success-subtle text-success border">PASSED</span><?php elseif ($mpf === false): ?> <span class="badge bg-danger-subtle text-danger border">NOT PASSED</span><?php endif; ?><?php endif; ?>
                        </div>
                        <?php if (!empty($mySubmission['original_filename']) && $myFileOk): ?>
                            <div class="mt-1">File: <a href="<?= e(url('/uploads/'.$mySubmission['file_path'])) ?>" target="_blank" class="fw-semibold"><?= e($mySubmission['original_filename']) ?></a> <span class="text-muted">(<?= number_format(($mySubmission['file_size_bytes']??0)/1024,1) ?> KB)</span></div>
                        <?php endif; ?>
                        <?php if (!empty($mySubmission['note'])): ?><div class="mt-1">Note: <?= e($mySubmission['note']) ?></div><?php endif; ?>
                        <?php if (!empty($mySubmission['feedback'])): ?><div class="mt-2 p-2 border rounded small" style="background:var(--surface-sunken)"><strong>Teacher feedback:</strong> <?= e($mySubmission['feedback']) ?></div><?php endif; ?>
                        <div class="alert alert-light border small py-2 mt-2 mb-0"><i class="bi bi-lock"></i> Your work has been <strong>GRADED</strong> and is now locked — you can no longer upload a new file. Contact your teacher if you need a resubmission.</div>
                    </div>
                <?php elseif ($isReturned): ?>
                    <div class="alert alert-danger py-2 small border-danger">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-x-circle-fill"></i> <strong>REJECTED</strong> — <?= e(date('M j, Y g:ia', strtotime($mySubmission['submitted_at']))) ?> — please revise below</span>
                            <?php if (isset($mySubmission['score']) && $mySubmission['score'] !== null && $mySubmission['score'] !== ''): ?><span class="badge bg-danger">Raw Score: <?= e(\App\Core\GradeEngine::fmtRaw((float)$mySubmission['score'], $taskMax)) ?> · <?= e(\App\Core\GradeEngine::fmtPct(\App\Core\GradeEngine::pct((float)$mySubmission['score'], $taskMax))) ?></span><?php $mpf2 = \App\Core\GradeEngine::passFail((float)$mySubmission['score'], $taskPassing); if ($mpf2 === true): ?> <span class="badge bg-success-subtle text-success border">PASSED</span><?php elseif ($mpf2 === false): ?> <span class="badge bg-danger-subtle text-danger border">NOT PASSED</span><?php endif; ?><?php endif; ?>
                        </div>
                        <?php if (!empty($mySubmission['original_filename']) && $myFileOk): ?>
                            <div class="mt-1">File: <a href="<?= e(url('/uploads/'.$mySubmission['file_path'])) ?>" target="_blank" class="fw-semibold"><?= e($mySubmission['original_filename']) ?></a> <span class="text-muted">(<?= number_format(($mySubmission['file_size_bytes']??0)/1024,1) ?> KB · <?= e($mySubmission['mime_type'] ?? '') ?>)</span></div>
                        <?php endif; ?>
                        <?php if (!empty($mySubmission['note'])): ?><div class="mt-1">Note: <?= e($mySubmission['note']) ?></div><?php endif; ?>
                        <?php if (!empty($mySubmission['feedback'])): ?><div class="mt-2 p-2 border rounded small" style="background:var(--surface-sunken)"><strong>Teacher feedback (required fix):</strong> <?= e($mySubmission['feedback']) ?></div><?php endif; ?>
                        <div class="small mt-1">Revise your file/note below and click <strong>Resubmit</strong> — it will go back to Submitted/Late for grading.</div>
                    </div>
                <?php else: ?>
                    <div class="alert <?= $mySubmission['status']==='late' ? 'alert-warning' : 'alert-success' ?> py-2 small">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-check-circle"></i> <strong><?= e(ucfirst($mySubmission['status'])) ?></strong> — <?= e(date('M j, Y g:ia', strtotime($mySubmission['submitted_at']))) ?></span>
                            <?php if ($mySubmission['score'] !== null && $mySubmission['score'] !== ''): ?><span class="badge bg-primary">Raw Score: <?= e(\App\Core\GradeEngine::fmtRaw((float)$mySubmission['score'], $taskMax)) ?> · <?= e(\App\Core\GradeEngine::fmtPct(\App\Core\GradeEngine::pct((float)$mySubmission['score'], $taskMax))) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($mySubmission['original_filename']) && $myFileOk): ?>
                            <div class="mt-1">File: <a href="<?= e(url('/uploads/'.$mySubmission['file_path'])) ?>" target="_blank" class="fw-semibold"><?= e($mySubmission['original_filename']) ?></a> <span class="text-muted">(<?= number_format(($mySubmission['file_size_bytes']??0)/1024,1) ?> KB · <?= e($mySubmission['mime_type'] ?? '') ?>)</span></div>
                        <?php endif; ?>
                        <?php if (!empty($mySubmission['note'])): ?><div class="mt-1">Note: <?= e($mySubmission['note']) ?></div><?php endif; ?>
                        <?php if (!empty($mySubmission['feedback'])): ?><div class="mt-2 p-2 border rounded small" style="background:var(--surface-sunken)"><strong>Teacher feedback:</strong> <?= e($mySubmission['feedback']) ?></div><?php endif; ?>
                        <div class="small text-muted mt-1">You can still update below — resubmitting replaces your file/note (marked late if past due).</div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> <?= $isParent ? 'No submission yet — your child submits this on their student account.' : 'You haven\'t submitted yet. Click <strong>Upload</strong> below — the row you clicked brought you here.' ?></div>
            <?php endif; ?>

            <?php if ($isParent): ?>
            <div class="alert alert-info py-2 small mb-0"><i class="bi bi-info-circle"></i> You are viewing as a parent — uploads are submitted on the <strong>student</strong> account. Status, files, scores, and teacher feedback above are read-only here.</div>
            <?php else: ?>
            <form method="post" action="<?= e(url('/classroom-tasks/'.(int)($task['id'] ?? 0).'/submit')) ?>" enctype="multipart/form-data" class="border rounded-3 p-3 <?= $isGraded ? 'bg-light opacity-75' : 'bg-light' ?>" <?= $isGraded ? 'onsubmit="return false;"' : '' ?>>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="submission_file" class="form-label fw-semibold">File <span class="text-muted fw-normal">(PDF, JPG, PNG, WebP, DOC/DOCX, TXT, ZIP — max 10 MB)</span></label>
                    <input type="file" class="form-control" id="submission_file" name="submission_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt,.zip" <?= $isGraded ? 'disabled' : '' ?>>
                    <?php if ($isGraded): ?><div class="form-text text-danger"><i class="bi bi-lock"></i> Locked — already graded.</div><?php else: ?><div class="form-text">You can also submit a note without a file.</div><?php endif; ?>
                </div>
                <div class="mb-3">
                    <label for="note" class="form-label fw-semibold">Note / comment (optional)</label>
                    <textarea class="form-control" id="note" name="note" rows="3" placeholder="<?= $isGraded ? 'Locked after grading' : 'Add a comment for your teacher...' ?>" <?= $isGraded ? 'disabled' : '' ?>><?= e($mySubmission['note'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn <?= $isGraded ? 'btn-secondary' : ($isReturned ? 'btn-danger' : 'btn-primary') ?> w-100" <?= $isGraded ? 'disabled' : '' ?>><i class="bi bi-<?= $isGraded ? 'lock' : 'cloud-upload' ?>"></i> <?= $isGraded ? 'Already graded — cannot update' : ($isReturned ? 'Resubmit after REJECTED' : ($hasSubmitted ? 'Update submission' : 'Submit task')) ?></button>
                <?php if (!$isGraded && $isOverdue): ?><div class="small text-warning text-center mt-2"><i class="bi bi-exclamation-triangle"></i> Past due — submission will be marked <strong>late</strong> but still accepted.</div><?php endif; ?>
                <?php if ($isGraded): ?><div class="small text-muted text-center mt-2"><i class="bi bi-info-circle"></i> If you need to resubmit, ask your teacher to <strong>Return</strong> your work first.</div><?php endif; ?>
            </form>
            <?php endif; ?>

            <div class="small text-muted mt-2"><i class="bi bi-shield-check"></i> Submission is tied to your account and this exact task (<?= e($task['subject_code']) ?>). Only you and the teacher can see it.</div>
            </div><!-- /.edu-card__body -->
        </div><!-- /.edu-card -->
        <?php endif; ?>
    </div>

    <div class="col-12 col-lg-5">
        <?php if ($isTeacher): ?>
            <!-- Teacher view: all submissions for this task -->
            <div class="edu-card h-100">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-people"></i> Submissions</h2>
                    <?= edu_badge((string) count($submissions), 'primary', 'bi-people') ?>
                </div>
                <div class="edu-card__body">
                <p class="small text-muted mb-2"><i class="bi bi-hand-index"></i> Pending: click to grade · Final: view-only</p>
                <?php if ($submissions === []): ?>
                    <?= edu_empty_state(
                        'No submissions yet',
                        'Share the subject invite so students join and click the task to upload.',
                        ['icon' => 'bi-inbox', 'compact' => true]
                    ) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>Student</th><th>Status</th><th>Raw Score</th><th>File</th><th>When</th></tr></thead>
                            <tbody>
                            <?php foreach ($submissions as $s): ?>
                                <?php $isFinal = in_array($s['status'] ?? '', ['graded','returned'], true); ?>
                                <?php $finalLabel = ($s['status'] ?? '') === 'graded' ? 'GRADED' : (($s['status'] ?? '') === 'returned' ? 'REJECTED' : $s['status']); ?>
                                <?php /* Link only files actually on disk: a DB row without its
                                       binary would otherwise 404 (rewrite sends it to the
                                       router). Missing files fall through to "— no file". */
                                      $rowFileOk = !empty($s['file_path']) && is_file(ROOT_PATH . '/public_html/uploads/' . $s['file_path']); ?>
                                <tr class="<?= $isFinal ? 'submission-view-row table-light' : 'submission-row' ?>" role="button" tabindex="0" style="cursor:pointer"
                                    data-id="<?= e((string)$s['id']) ?>"
                                    data-student="<?= e(trim(($s['first_name']??'').' '.($s['last_name']??''))) ?>"
                                    data-student-no="<?= e($s['student_no'] ?? '') ?>"
                                    data-email="<?= e($s['email'] ?? '') ?>"
                                    data-status="<?= e($s['status']) ?>"
                                    data-file="<?= e($rowFileOk ? $s['file_path'] : '') ?>"
                                    data-filename="<?= e($s['original_filename'] ?? '') ?>"
                                    data-note="<?= e($s['note'] ?? '') ?>"
                                    data-score="<?= e((string)($s['score'] ?? '')) ?>"
                                    data-feedback="<?= e($s['feedback'] ?? '') ?>"
                                    title="<?= $isFinal ? 'Finalized — click to view (locked)' : 'Click to grade — approve/reject + score + comment' ?>">
                                    <td><div class="fw-semibold"><?= e(trim(($s['first_name']??'').' '.($s['last_name']??''))) ?></div><div class="small text-muted"><?= e($s['student_no'] ?? '') ?> · <?= e($s['email'] ?? '') ?></div></td>
                                    <td><span class="badge <?= $s['status']==='late'?'text-bg-warning':($s['status']==='graded'?'text-bg-success':($s['status']==='returned'?'text-bg-danger':'text-bg-info')) ?>"><?= e($finalLabel) ?></span><?php if (!empty($s['feedback'])): ?><div class="small text-muted" style="max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= e($s['feedback']) ?></div><?php endif; ?></td>
                                    <td class="small"><?php if ($s['score'] !== null && $s['score'] !== ''): ?><div class="fw-semibold text-primary"><?= e(\App\Core\GradeEngine::fmtRaw((float)$s['score'], $taskMax)) ?></div><div class="text-muted"><?= e(\App\Core\GradeEngine::fmtPct(\App\Core\GradeEngine::pct((float)$s['score'], $taskMax))) ?><?php $pf = \App\Core\GradeEngine::passFail((float)$s['score'], $taskPassing); if ($pf === true): ?> <span class="badge bg-success-subtle text-success border" style="font-size:.6rem">PASSED</span><?php elseif ($pf === false): ?> <span class="badge bg-danger-subtle text-danger border" style="font-size:.6rem">NOT PASSED</span><?php endif; ?></div><?php elseif (in_array($s['status'] ?? '', ['submitted','late'], true)): ?><span class="badge text-bg-warning">Not yet scored</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                    <td class="small" onclick="event.stopPropagation()"><?php if ($rowFileOk): ?><a href="<?= e(url('/uploads/'.$s['file_path'])) ?>" target="_blank" class="fw-semibold"><?= e($s['original_filename'] ?? 'Download') ?></a><div class="small text-muted"><?= e($s['mime_type'] ?? '') ?> · <?= isset($s['file_size_bytes']) ? number_format($s['file_size_bytes']/1024,1).' KB' : '' ?></div><?php else: ?><span class="text-muted">— no file</span><?php endif; ?><?php if (!empty($s['note'])): ?><div class="small text-dark bg-light border rounded px-1 mt-1"><?= e(substr($s['note'],0,40)) ?><?= strlen($s['note'])>40?'…':'' ?></div><?php endif; ?></td>
                                    <td class="small text-muted"><?= e(date('M j g:ia', strtotime($s['submitted_at']))) ?><div class="mt-1"><?php if ($isFinal): ?><span class="badge bg-secondary"><i class="bi bi-eye"></i> View</span><?php else: ?><span class="badge bg-light border text-primary"><i class="bi bi-pencil-square"></i> Grade</span><?php endif; ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> Click a <strong>pending</strong> row to <strong>Approve / Reject</strong> with raw score + comment. <strong>GRADED / REJECTED</strong> rows are locked — click to view only.</div>
                <?php endif; ?>
                </div><!-- /.edu-card__body -->
            </div><!-- /.edu-card submissions -->
            <?php $assessSummary = $assessSummary ?? null; ?>
            <?php if ($assessSummary !== null): ?>
            <div class="edu-card mt-3" style="border-left:4px solid var(--info)">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-bar-chart"></i> Assessment summary</h2>
                    <span class="small text-muted">from actual records</span>
                </div>
                <div class="edu-card__body">
                <div class="row g-2 small text-center">
                    <div class="col-4"><div class="p-2 bg-light border rounded-3"><div class="text-muted">Total Items</div><div class="fw-bold"><?= e(rtrim(rtrim(number_format($taskMax, 2, '.', ''), '0'), '.')) ?></div><?php if ($taskPassing !== null): ?><div class="text-muted">Passing: <?= e(rtrim(rtrim(number_format($taskPassing, 2, '.', ''), '0'), '.')) ?></div><?php endif; ?></div></div>
                    <div class="col-4"><div class="p-2 bg-light border rounded-3"><div class="text-muted">Graded</div><div class="fw-bold"><?= (int)$assessSummary['graded'] ?> / <?= (int)$assessSummary['members'] ?></div><?php if (($assessSummary['passed'] ?? null) !== null): ?><div class="text-success">Passed: <?= (int)$assessSummary['passed'] ?></div><?php endif; ?></div></div>
                    <div class="col-4"><div class="p-2 bg-light border rounded-3"><div class="text-muted">Needs Review</div><div class="fw-bold"><?= (int)($assessSummary['needs_review'] ?? $assessSummary['pending']) ?></div></div></div>
                    <div class="col-4"><div class="p-2 bg-light border rounded-3"><div class="text-muted">Highest</div><div class="fw-bold"><?= $assessSummary['highest'] === null ? '—' : e(\App\Core\GradeEngine::fmtRaw((float)$assessSummary['highest'], $taskMax)) ?></div></div></div>
                    <div class="col-4"><div class="p-2 bg-light border rounded-3"><div class="text-muted">Lowest</div><div class="fw-bold"><?= $assessSummary['lowest'] === null ? '—' : e(\App\Core\GradeEngine::fmtRaw((float)$assessSummary['lowest'], $taskMax)) ?></div></div></div>
                    <div class="col-4"><div class="p-2 bg-light border rounded-3"><div class="text-muted">Average</div><div class="fw-bold"><?= $assessSummary['average'] === null ? '—' : e(\App\Core\GradeEngine::fmtRaw(round((float)$assessSummary['average'], 2), $taskMax)) ?></div><div class="text-muted"><?= $assessSummary['average_pct'] === null ? '' : e(number_format(round((float)$assessSummary['average_pct'], 2), 2)) . '%' ?></div></div></div>
                </div>
                <div class="small text-muted mt-2">Raw assessment results only — the subject computed grade is calculated separately from all weighted categories.</div>
                </div><!-- /.edu-card__body -->
            </div><!-- /.edu-card assessment -->
            <?php endif; ?>
            <!-- Grade / Approve / Reject Modal (clickable submission) -->
            <script>
            /* Global grade-modal closer. Same standalone pattern as the view
               modal below: inline onclick handlers call this directly, so
               Cancel/× work even if Bootstrap or the main script block
               failed. Submit buttons (Approve/Reject) are untouched — they
               POST the form and navigate away. */
            function eduGradeModalClose() {
              var m = document.getElementById('gradeSubmissionModal');
              if (m) {
                m.classList.remove('show');
                m.style.display = 'none';
                m.setAttribute('aria-hidden', 'true');
              }
              var bds = document.querySelectorAll('.modal-backdrop');
              for (var i = 0; i < bds.length; i++) { bds[i].remove(); }
              if (!document.querySelector('.modal.show')) {
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
              }
            }
            </script>
            <div class="modal fade" id="gradeSubmissionModal" tabindex="-1" aria-labelledby="gradeModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <form method="post" id="gradeSubmissionForm" class="modal-content">
                  <?= csrf_field() ?>
                  <div class="modal-header">
                    <h5 class="modal-title" id="gradeModalLabel"><i class="bi bi-pencil-square"></i> Record raw score <span class="badge bg-light border text-dark fw-normal">not final grade</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="eduGradeModalClose()"></button>
                  </div>
                  <div class="modal-body">
                    <div class="p-2 bg-light border rounded-3 mb-3 small">
                        <div><strong>Student:</strong> <span id="gradeStudentName"></span> <span class="text-muted" id="gradeStudentNo"></span></div>
                        <div><strong>File:</strong> <span id="gradeFileName" class="font-monospace"></span> <a id="gradeFileLink" href="#" target="_blank" class="ms-1" style="display:none"><i class="bi bi-box-arrow-up-right"></i> View</a></div>
                        <div><strong>Status:</strong> <span id="gradeStatusBadge"></span> <span class="text-muted" id="gradeNotePreview"></span></div>
                    </div>
                    <div class="alert alert-info py-2 small mb-3">Total Items / Max: <strong id="gradeTaskMax"><?= e(rtrim(rtrim(number_format($taskMax, 2, '.', ''), '0'), '.')) ?></strong><?php if ($taskPassing !== null): ?> · Passing: <strong><?= e(rtrim(rtrim(number_format($taskPassing, 2, '.', ''), '0'), '.')) ?></strong><?php endif; ?> · Grading period: <strong><?= e($task['period_code'] ?? 'untagged') ?></strong> · This is a <strong>raw score</strong>, not the final grade.</div>
                    <div class="mb-3">
                        <label for="gradeScore" class="form-label fw-semibold">Raw Score (0–<?= e(rtrim(rtrim(number_format($taskMax, 2, '.', ''), '0'), '.')) ?>) <span class="text-danger fw-normal">— required to Approve</span></label>
                        <input type="number" class="form-control" id="gradeScore" name="score" min="0" max="<?= e((string)$taskMax) ?>" step="0.01" placeholder="e.g., 45" data-task-max="<?= e((string)$taskMax) ?>">
                        <div class="form-text">Leave empty only when Rejecting (work goes back for revision).</div>
                        <div class="p-2 bg-light border rounded-3 small mt-2">Result: <strong id="gradePreviewRaw">—</strong> · Percentage: <strong id="gradePreviewPct">—</strong> <span id="gradePreviewPass"></span></div>
                    </div>
                    <div class="mb-3">
                        <label for="gradeFeedback" class="form-label fw-semibold">Comment / feedback <span class="text-muted fw-normal">— visible to student (required to Reject)</span></label>
                        <textarea class="form-control" id="gradeFeedback" name="feedback" rows="3" placeholder="Great work! See comments on page 2..."></textarea>
                    </div>
                    <div class="alert alert-light border small py-2">Choose: <strong>Approve</strong> marks <code>GRADED</code> and saves the score, <strong>Reject</strong> marks <code>REJECTED</code> for revision (comment required). Finalized items become locked (view-only).</div>
                  </div>
                  <div class="modal-footer d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="eduGradeModalClose()">Cancel</button>
                    <button type="submit" name="action" value="reject" class="btn btn-outline-danger"><i class="bi bi-x-circle"></i> Reject &amp; comment</button>
                    <button type="submit" name="action" value="approve" class="btn btn-success"><i class="bi bi-check-circle"></i> Approve &amp; grade</button>
                  </div>
                </form>
              </div>
            </div>
            <!-- View-only Modal for GRADED / REJECTED (locked) -->
            <script>
            /* Global view-modal closer. Deliberately standalone (no Bootstrap,
               no shared state, no DOMContentLoaded dependency): inline
               onclick handlers above call this directly, so the dialog closes
               even if every other script on the page failed. Mirrors the
               proven manual release sequence. */
            function eduViewModalClose() {
              var m = document.getElementById('viewSubmissionModal');
              if (m) {
                m.classList.remove('show');
                m.style.display = 'none';
                m.setAttribute('aria-hidden', 'true');
              }
              var bds = document.querySelectorAll('.modal-backdrop');
              for (var i = 0; i < bds.length; i++) { bds[i].remove(); }
              if (!document.querySelector('.modal.show')) {
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
              }
            }
            </script>
            <div class="modal fade" id="viewSubmissionModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true" style="z-index:1065">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="viewModalLabel"><i class="bi bi-eye"></i> View submission <span class="badge bg-secondary ms-1">Locked</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="eduViewModalClose()"></button>
                  </div>
                  <div class="modal-body">
                    <div class="p-2 bg-light border rounded-3 mb-3 small">
                        <div><strong>Student:</strong> <span id="viewStudentName"></span> <span class="text-muted" id="viewStudentNo"></span></div>
                        <div><strong>File:</strong> <span id="viewFileName" class="font-monospace"></span> <a id="viewFileLink" href="#" target="_blank" class="ms-1" style="display:none"><i class="bi bi-box-arrow-up-right"></i> View</a></div>
                        <div><strong>Status:</strong> <span id="viewStatusBadge"></span></div>
                    </div>
                    <div class="mb-2"><label class="form-label fw-semibold mb-1">Raw Score (not final grade)</label><div id="viewScore" class="form-control bg-light"></div><div id="viewPct" class="small text-muted mt-1"></div></div>
                    <div class="mb-2"><label class="form-label fw-semibold mb-1">Comment / feedback</label><div id="viewFeedback" class="form-control bg-light" style="min-height:70px;white-space:pre-wrap"></div></div>
                    <div class="mb-0"><label class="form-label fw-semibold mb-1">Student note</label><div id="viewNote" class="form-control bg-light" style="white-space:pre-wrap"></div></div>
                    <div class="alert alert-secondary small py-2 mt-3 mb-0"><i class="bi bi-lock"></i> This submission is <span id="viewFinalLabel" class="fw-bold"></span> and locked — view-only, cannot approve / reject / edit.</div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="eduViewModalClose()">Close</button>
                  </div>
                </div>
              </div>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function(){
              const modalEl = document.getElementById('gradeSubmissionModal');
              const viewEl = document.getElementById('viewSubmissionModal');
              if(!modalEl || !viewEl) return;
              /* Stacking-trap insurance for BOTH dialogs: keep them as
                 direct <body> children so no positioned/filtered ancestor
                 can ever paint a body-level backdrop over their buttons
                 (which swallows clicks and wedges the page). Element
                 references stay valid across the move; Bootstrap positions
                 body-level modals natively. */
              [modalEl, viewEl].forEach(function(el){
                if (el.parentElement !== document.body) document.body.appendChild(el);
              });
              // Single modal driver. When Bootstrap is present it does EVERYTHING
              // natively (dismiss buttons, backdrop click, Escape) — this script
              // only calls show()/hide() and never touches classes, inline
              // styles or backdrops, so two drivers cannot fight and wedge the
              // page behind a stuck overlay. The manual fallback below runs
              // ONLY when Bootstrap is absent.
              let bsModal = null, bsView = null;
              const hasBs = (typeof bootstrap !== 'undefined' && bootstrap && bootstrap.Modal);
              try {
                if (hasBs) {
                  bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                  bsView = bootstrap.Modal.getOrCreateInstance(viewEl);
                }
              } catch (e) { bsModal = null; bsView = null; }
              const driven = (bsModal !== null && bsView !== null);
              const form = document.getElementById('gradeSubmissionForm');
              const uploadsBase = '<?= e(url('/uploads')) ?>/';
              // Track which action button was pressed (robust: works for Enter-key
              // submits and browsers that don't focus buttons on click).
              let submitAction = '';
              form.querySelectorAll('button[type="submit"][name="action"]').forEach(function(btn){
                btn.addEventListener('click', function(){ submitAction = btn.value; });
              });
              // Manual fallback state: at most ONE backdrop, tracked in a variable
              // (never re-queried from the DOM), created only when Bootstrap is
              // absent — so a manual overlay and a Bootstrap overlay can never
              // stack and trap the page.
              let manualBd = null;
              const clearManual = () => {
                if (manualBd) { manualBd.remove(); manualBd = null; }
                if (!document.querySelector('.modal.show')) {
                  document.body.classList.remove('modal-open');
                  document.body.style.removeProperty('overflow');
                  document.body.style.removeProperty('padding-right');
                }
              };
              const showModal = (el, inst) => {
                if (driven && inst) { inst.show(); return; }
                if (el.classList.contains('show')) return;
                el.classList.add('show');
                el.style.display = 'block';
                el.removeAttribute('aria-hidden');
                document.body.classList.add('modal-open');
                if (!manualBd) {
                  manualBd = document.createElement('div');
                  manualBd.className = 'modal-backdrop fade show';
                  manualBd.addEventListener('click', function(){ closeModal(el, null); });
                  document.body.appendChild(manualBd);
                }
              };
              /* Dedicated view-modal overlay. The view (locked) modal never
                 touches the Bootstrap engine or the shared manualBd above:
                 it owns its backdrop element, its dismiss wiring and its
                 Escape handling, so no cross-modal or cross-driver state can
                 ever wedge it. Grade-modal logic below is untouched. */
              let viewBd = null;
              const viewUnlock = () => {
                if (!document.querySelector('.modal.show')) {
                  document.body.classList.remove('modal-open');
                  document.body.style.removeProperty('overflow');
                  document.body.style.removeProperty('padding-right');
                }
              };
              const viewOverlayClose = () => {
                viewEl.classList.remove('show');
                viewEl.style.display = 'none';
                viewEl.setAttribute('aria-hidden', 'true');
                if (viewBd) { viewBd.remove(); viewBd = null; }
                viewUnlock();
              };
              const viewOverlayOpen = () => {
                if (viewEl.classList.contains('show')) return;
                /* Reparent to <body>: any positioned/filtered/transformed
                   ancestor traps the dialog in a weaker stacking context,
                   letting a body-level backdrop paint over it (and swallow
                   its Close clicks) no matter what z-index the dialog has.
                   As a direct body child, Bootstrap's own z-index order
                   applies and the dialog always wins. */
                if (viewEl.parentElement !== document.body) {
                  document.body.appendChild(viewEl);
                }
                viewEl.classList.add('show');
                viewEl.style.display = 'block';
                viewEl.removeAttribute('aria-hidden');
                document.body.classList.add('modal-open');
                if (!viewBd) {
                  viewBd = document.createElement('div');
                  viewBd.className = 'modal-backdrop fade show';
                  viewBd.addEventListener('click', viewOverlayClose);
                  document.body.appendChild(viewBd);
                }
              };
              /* Self-healing close: ask Bootstrap to hide, then verify. If the
                 overlay is still stuck ~300ms later (swallowed event, wedged
                 focus trap, half-loaded bundle), strip it manually so the
                 page can NEVER trap the user behind a dead modal. */
              const sweepStuck = (el) => {
                if (!el.classList.contains('show')) return;
                el.classList.remove('show');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                document.querySelectorAll('.modal-backdrop').forEach(function(bd){ bd.remove(); });
                manualBd = null;
                if (!document.querySelector('.modal.show')) {
                  document.body.classList.remove('modal-open');
                  document.body.style.removeProperty('overflow');
                  document.body.style.removeProperty('padding-right');
                }
              };
              const closeModal = (el, inst) => {
                if (driven && inst) {
                  try { inst.hide(); } catch (e) { /* fall through to sweep */ }
                  setTimeout(function(){ sweepStuck(el); }, 300);
                  return;
                }
                el.classList.remove('show');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                clearManual();
              };
              const hideTop = () => {
                if (viewEl.classList.contains('show')) viewOverlayClose();
                else if (modalEl.classList.contains('show')) closeModal(modalEl, bsModal);
                else clearManual();
              };
              if (driven) {
                // Safety net only: drop manual leftovers after Bootstrap hides.
                modalEl.addEventListener('hidden.bs.modal', clearManual);
                viewEl.addEventListener('hidden.bs.modal', clearManual);
              } else {
                // No Bootstrap: drop any stale manual state on load. (Escape
                // and dismiss buttons are wired below for both modes.)
                clearManual();
              }
              /* Dismiss controls are wired in BOTH modes: when Bootstrap is
                 healthy its native handler runs and our force-close is a
                 harmless second hide; when Bootstrap is wedged, ours is the
                 one that actually frees the page. Escape is likewise always
                 ours now (Bootstrap double-hide is a safe no-op). */
              modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function(b){
                b.addEventListener('click', function(){ closeModal(modalEl, bsModal); });
              });
              viewEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function(b){
                b.addEventListener('click', function(){ viewOverlayClose(); });
              });
              document.addEventListener('keydown', function(ev){
                if (ev.key === 'Escape' || ev.key === 'Esc') hideTop();
              });
              document.querySelectorAll('.submission-row').forEach(row => {
                const open = () => {
                  if (modalEl.classList.contains('show')) return;
                  submitAction = '';
                  setSubmitting(false);
                  document.getElementById('gradeStudentName').textContent = row.dataset.student || '';
                  document.getElementById('gradeStudentNo').textContent = row.dataset.studentNo ? '('+row.dataset.studentNo+')' : '';
                  document.getElementById('gradeFileName').textContent = row.dataset.filename || '— no file';
                  const link = document.getElementById('gradeFileLink');
                  if(row.dataset.file){ link.href = uploadsBase + row.dataset.file; link.style.display=''; } else { link.style.display='none'; }
                  document.getElementById('gradeStatusBadge').innerHTML = '<span class="badge bg-info">'+row.dataset.status+'</span>';
                  document.getElementById('gradeNotePreview').textContent = row.dataset.note ? ' — '+row.dataset.note.substring(0,40) : '';
                  document.getElementById('gradeScore').value = row.dataset.score || '';
                  document.getElementById('gradeFeedback').value = row.dataset.feedback || '';
                  form.action = '<?= e(url('/classroom-submissions')) ?>/' + row.dataset.id + '/grade';
                  if (typeof refreshPreview === 'function') setTimeout(refreshPreview, 50);
                  showModal(modalEl, bsModal);
                };
                row.addEventListener('click', open);
                row.addEventListener('keydown', e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); open(); } });
              });
              const scoreInp = document.getElementById('gradeScore');
              const taskMaxVal = parseFloat(scoreInp.dataset.taskMax || '100');
              const taskPassVal = <?= $taskPassing !== null ? json_encode($taskPassing) : 'null' ?>;
              const prevRaw = document.getElementById('gradePreviewRaw');
              const prevPct = document.getElementById('gradePreviewPct');
              const prevPass = document.getElementById('gradePreviewPass');
              const refreshPreview = () => {
                const v = parseFloat(scoreInp.value);
                if (scoreInp.value === '' || isNaN(v)) { prevRaw.textContent = '—'; prevPct.textContent = '—'; prevPass.textContent = ''; return; }
                prevRaw.textContent = v + ' / ' + taskMaxVal;
                const pct = taskMaxVal > 0 ? (v / taskMaxVal) * 100 : 0;
                prevPct.textContent = pct.toFixed(2) + '%';
                if (v > taskMaxVal) {
                  prevPass.innerHTML = '<span class="badge bg-danger">Score cannot be higher than the total number of items.</span>';
                } else if (taskPassVal === null) {
                  prevPass.textContent = '';
                } else if (v >= taskPassVal) {
                  prevPass.innerHTML = '<span class="badge bg-success-subtle text-success border">PASSED (≥ ' + taskPassVal + ')</span>';
                } else {
                  prevPass.innerHTML = '<span class="badge bg-danger-subtle text-danger border">NOT PASSED (need ' + taskPassVal + ')</span>';
                }
              };
              scoreInp.addEventListener('input', refreshPreview);
              const submitBtns = Array.from(form.querySelectorAll('button[type="submit"]'));
              const setSubmitting = (on) => {
                submitBtns.forEach(function(b){
                  b.disabled = on;
                  if (on) {
                    if (!b.dataset.orig) b.dataset.orig = b.innerHTML;
                    b.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving…';
                  } else if (b.dataset.orig) {
                    b.innerHTML = b.dataset.orig; delete b.dataset.orig;
                  }
                });
              };
              form.addEventListener('submit', function(ev){
                var action = submitAction || '';
                var fb = document.getElementById('gradeFeedback').value.trim();
                if (action === 'reject' && fb.length < 5) {
                  ev.preventDefault();
                  setSubmitting(false);
                  alert('Rejecting requires feedback for the student (min 5 characters).');
                  document.getElementById('gradeFeedback').focus();
                  return;
                }
                if (action === 'approve') {
                  var sc = document.getElementById('gradeScore').value.trim();
                  if (sc === '' || isNaN(sc) || Number(sc) < 0 || Number(sc) > taskMaxVal) {
                    ev.preventDefault();
                    setSubmitting(false);
                    alert('Approving requires a raw score between 0 and ' + taskMaxVal + '. Score cannot be higher than the total number of items.');
                    document.getElementById('gradeScore').focus();
                    return;
                  }
                }
                // Validation passed (or Enter-key submit with server-side fallback):
                // lock buttons + show progress so slow responses never look frozen.
                setSubmitting(true);
              });
              document.querySelectorAll('.submission-view-row').forEach(row => {                const openView = () => {
                  if (viewEl.classList.contains('show')) return;
                  const isGraded = row.dataset.status === 'graded';
                  const label = isGraded ? 'GRADED' : 'REJECTED';
                  document.getElementById('viewStudentName').textContent = row.dataset.student || '';
                  document.getElementById('viewStudentNo').textContent = row.dataset.studentNo ? '('+row.dataset.studentNo+')' : '';
                  document.getElementById('viewFileName').textContent = row.dataset.filename || '— no file';
                  const link = document.getElementById('viewFileLink');
                  if(row.dataset.file){ link.href = uploadsBase + row.dataset.file; link.style.display=''; } else { link.style.display='none'; }
                  document.getElementById('viewStatusBadge').innerHTML = '<span class="badge '+(isGraded?'bg-success':'bg-danger')+'">'+label+'</span>';
                  const vm = parseFloat('<?= e((string)$taskMax) ?>') || 100;
                  const vp = <?= $taskPassing !== null ? json_encode($taskPassing) : 'null' ?>;
                  document.getElementById('viewScore').textContent = row.dataset.score ? row.dataset.score+' / ' + vm : '—';
                  let vtxt = row.dataset.score ? (((parseFloat(row.dataset.score)/vm)*100).toFixed(2) + '%') : '';
                  if (row.dataset.score && vp !== null) vtxt += parseFloat(row.dataset.score) >= vp ? ' · PASSED' : ' · NOT PASSED (need ' + vp + ')';
                  document.getElementById('viewPct').textContent = vtxt;
                  document.getElementById('viewFeedback').textContent = row.dataset.feedback || '—';
                  document.getElementById('viewNote').textContent = row.dataset.note || '—';
                  document.getElementById('viewFinalLabel').textContent = label;
                  viewOverlayOpen();
                };
                row.addEventListener('click', openView);
                row.addEventListener('keydown', e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); openView(); } });
              });
            });
            </script>
        <?php else: ?>
            <div class="edu-card h-100">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-info-circle"></i> How to submit</h2>
                </div>
                <div class="edu-card__body">
                    <ol class="small text-muted ps-3">
                        <li>Click the <strong>Chapter 1</strong> row in <strong>Subject Tasks</strong> (you did).</li>
                        <li>Attach your file (PDF/image/doc) or type a note.</li>
                        <li>Click <strong>Submit task</strong>. You can resubmit before grading — latest replaces previous.</li>
                        <li>Teacher sees it in <strong>Activity History</strong> and can grade.</li>
                    </ol>
                    <?= edu_callout(
                        '<strong>Tips — how to use this page.</strong>'
                        . '<ul class="mb-0 ps-3 mt-1">'
                        . '<li>You got here by clicking a task row — this is your personal hand-in page for that task.</li>'
                        . '<li>Upload a file or type a note, then hit <strong>Submit</strong>. You can update anytime before your teacher grades it.</li>'
                        . '<li>After submitting, check the badge at the top — GRADED or REJECTED confirms the outcome.</li>'
                        . '</ul>',
                        ['tone' => 'info', 'icon' => 'bi-lightbulb', 'title' => null]
                    ) ?>
                    <a href="<?= e(url('/classrooms/'.(int)($task['classroom_id'] ?? 0))) ?>" class="btn btn-sm btn-outline-primary w-100 mt-3"><i class="bi bi-easel"></i> View subject details</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
