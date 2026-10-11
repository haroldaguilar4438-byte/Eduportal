<?php
/** @var array $classroom @var array $members */
$classroom = $classroom ?? [];
$members = $members ?? [];
$isTeacherOwner = false;
try {
    $uid = \App\Core\Auth::id();
    $t = \App\Core\Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [$uid]);
    if ($t && (int)$t['id'] === (int)($classroom['teacher_id'] ?? 0)) $isTeacherOwner = true;
} catch (Throwable $e) {}
$role = \App\Core\Auth::role();
?>
<a href="<?= e(url('/dashboard')) ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> Back to dashboard</a>

<?php
$memberCount  = count($members);
$memberMax    = max(1, (int) ($classroom['max_students'] ?? 40));
$memberPct    = edu_percent($memberCount, $memberMax, 0);
$memberTone   = $memberCount === 0 ? 'neutral' : edu_percent_tone($memberPct);
$pendingJoins = count($joinRequests);
$taskCount    = count($tasks ?? []);
?>
<?= edu_page_header([
    'eyebrow'     => 'Classroom',
    'title'       => (string) ($classroom['subject_title'] ?? 'Classroom'),
    'description' => trim(
        (($classroom['section_name'] ?? '') !== '' ? 'Section ' . $classroom['section_name'] . ' · ' : '')
        . 'Teacher: ' . trim(($classroom['first_name'] ?? '') . ' ' . ($classroom['last_name'] ?? ''))
        . ' (' . ($classroom['employee_no'] ?? '') . ')'
        . ' · Created ' . date('M j, Y', strtotime($classroom['created_at'] ?? 'now'))
    ),
    'icon'        => 'bi-journal-bookmark-fill',
]) ?>

<!-- Section tabs -->
<?= edu_tabs([
    ['key' => 'activities', 'label' => 'Overview & activities', 'url' => current_path(), 'count' => $taskCount],
    ['key' => 'join',       'label' => 'Join requests',       'url' => current_path() . '#join-requests', 'count' => $pendingJoins],
    ['key' => 'members',    'label' => 'Members',             'url' => current_path() . '#members', 'count' => $memberCount],
], 'activities') ?>

<!-- Classroom header -->
<div class="edu-card mb-3">
    <div class="edu-card__body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div class="min-w-0">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <?= edu_badge((string) ($classroom['subject_code'] ?? ''), 'primary', 'bi-book') ?>
                    <?= edu_badge((string) ($classroom['school_year'] ?? ''), 'neutral', 'bi-calendar3') ?>
                    <?php if (!empty($classroom['section_name'])): ?>
                        <?= edu_badge('Section ' . $classroom['section_name'], 'accent', 'bi-door-open') ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($classroom['description'])): ?>
                    <p class="small mb-0" style="color:var(--text-secondary)"><?= e($classroom['description']) ?></p>
                <?php endif; ?>
            </div>

            <!-- Capacity -->
            <div class="flex-none" style="min-width:190px">
                <div class="d-flex justify-content-between align-items-baseline mb-1">
                    <span class="small fw-semibold" style="color:var(--text-tertiary)">Members</span>
                    <span class="small fw-bold" style="color:var(--primary-900)"><?= $memberCount ?>/<?= $memberMax ?></span>
                </div>
                <?= edu_progress((float) $memberPct, [
                    'tone'  => $memberTone === 'neutral' ? '' : $memberTone,
                    'size'  => 'sm',
                ]) ?>
                <div class="small mt-1" style="color:var(--text-tertiary)">
                    <?php if ($memberPct >= 100): ?>Class is full
                    <?php elseif ($memberCount === 0): ?>No members yet
                    <?php else: ?><?= $memberMax - $memberCount ?> slot<?= ($memberMax - $memberCount) === 1 ? '' : 's' ?> left
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Invite code -->
        <div class="mt-3 pt-3 border-top" style="border-color:var(--border)!important">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-7">
                    <div class="small fw-semibold mb-1" style="color:var(--text-tertiary);letter-spacing:.06em">
                        <?= ($isTeacherOwner || $role === 'admin') ? 'Invite code' : 'Share this code with classmates' ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <code class="fs-5 fw-bold" style="letter-spacing:.12em;color:var(--primary-800)"><?= e($classroom['invite_code'] ?? '') ?></code>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                onclick="navigator.clipboard.writeText('<?= e($classroom['invite_code'] ?? '') ?>'); this.textContent='Copied!'; setTimeout(()=>{this.innerHTML='<i class=&quot;bi bi-clipboard&quot;></i> Copy';},1500)">
                            <i class="bi bi-clipboard" aria-hidden="true"></i> Copy
                        </button>
                    </div>
                </div>
                <?php if ($isTeacherOwner || $role === 'admin'): ?>
                <div class="col-12 col-md-5">
                    <div class="d-flex gap-2 justify-content-md-end">
                        <form method="post" action="<?= e(url('/teacher/classrooms/' . (int) ($classroom['id'] ?? 0) . '/regenerate')) ?>" class="flex-grow-1">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100" onclick="return confirm('Regenerate code? Old code will stop working.')">
                                <i class="bi bi-arrow-clockwise me-1"></i> New code
                            </button>
                        </form>
                        <form method="post" action="<?= e(url('/teacher/classrooms/' . (int) ($classroom['id'] ?? 0) . '/delete')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete classroom" onclick="return confirm('Delete subject and remove all members?')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Activities for this subject (visible to joined students + teacher history) -->
<?php $tasks = $tasks ?? []; ?>
<div class="card p-3 mb-3 border-0 shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-journal-text text-primary"></i> Activities <span class="badge bg-light border text-dark"><?= count($tasks) ?></span></h2>
        <span class="small text-muted">Posted by teacher → visible to joined students</span>
    </div>
    <?php if ($tasks === []): ?>
        <div class="text-center py-4">
            <div class="fs-4 text-muted"><i class="bi bi-inbox"></i></div>
            <p class="small text-muted mb-0">No activities yet. Teacher posts via <strong>My Subjects → Post activity</strong> (title + due date validated: future, year <?= date('Y') ?>–<?= date('Y')+5 ?>).</p>
        </div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($tasks as $t): 
                $dueTs = !empty($t['due_at']) ? strtotime($t['due_at']) : null;
                $dueValid = $dueTs && $dueTs > time() && (int)date('Y',$dueTs) >= (int)date('Y') && (int)date('Y',$dueTs) <= (int)date('Y')+5;
                $duePast = $dueTs && $dueTs <= time();
                $hasMySub = !empty($t['my_submission_id']);
                $subCount = (int)($t['submission_count'] ?? 0);
                $fileCount = (int)($t['file_count'] ?? 0);
            ?>
                <div class="list-group-item px-0" role="button" tabindex="0" style="cursor:pointer" onclick="window.location='<?= e(url('/classroom-tasks/'.$t['id'])) ?>'" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='<?= e(url('/classroom-tasks/'.$t['id'])) ?>';}" title="Click to open — upload/view submission" aria-label="Open task: <?= e($t['title']) ?>">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="flex-grow-1">
                            <div class="fw-semibold text-primary"><i class="bi bi-journal-text"></i> <?= e($t['title']) ?></div>
                            <?php if (!empty($t['instructions'])): ?><div class="small text-muted"><?= e($t['instructions']) ?></div><?php endif; ?>
                            <div class="small text-muted"><i class="bi bi-person"></i> <?= e(trim(($t['first_name']??'').' '.($t['last_name']??'')) ?: 'Teacher') ?> · <i class="bi bi-clock"></i> Posted <?= e(date('M j, Y g:ia', strtotime($t['created_at']))) ?></div>
                            <?php if ($hasMySub): ?>
                                <?php $myLbl = ($t['my_status'] ?? '') === 'graded' ? 'GRADED' : ((($t['my_status'] ?? '') === 'returned') ? 'REJECTED' : ($t['my_status'] ?? 'submitted')); ?>
                                <div class="mt-1"><span class="badge <?= ($t['my_status'] ?? '')==='graded' ? 'bg-success' : ((($t['my_status'] ?? '')==='returned') ? 'bg-danger' : 'bg-success') ?>"><i class="bi bi-<?= ($t['my_status'] ?? '')==='returned' ? 'x-circle-fill' : 'check-circle' ?>"></i> <?= ($t['my_status'] ?? '')==='graded' ? 'GRADED' : ((($t['my_status'] ?? '')==='returned') ? 'REJECTED — revise' : 'You submitted — '.e($myLbl)) ?></span>
                                <?php if (!empty($t['my_file_path'])): ?><a href="<?= e(url('/uploads/'.$t['my_file_path'])) ?>" target="_blank" class="small ms-1" onclick="event.stopPropagation()"><i class="bi bi-file-earmark"></i> <?= e($t['my_original_filename'] ?? 'View file') ?></a><?php endif; ?></div>
                            <?php elseif (!$isTeacherOwner && $role !== 'admin'): ?>
                                <div class="small text-muted mt-1"><i class="bi bi-box-arrow-up"></i> Click to upload your work</div>
                            <?php endif; ?>
                            <?php if ($isTeacherOwner || $role==='admin'): ?>
                                <div class="small mt-1"><span class="badge <?= $subCount>0 ? 'bg-success' : 'bg-light border text-dark' ?>"><i class="bi bi-cloud-arrow-up"></i> <?= $subCount ?> submission<?= $subCount===1?'':'s' ?></span><?php if ($fileCount>0): ?><span class="badge bg-primary bg-opacity-10 text-primary border ms-1"><i class="bi bi-file-earmark"></i> <?= $fileCount ?> file(s)</span><?php endif; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="text-end ms-2" style="min-width:110px">
                            <?php if (empty($t['due_at'])): ?><span class="badge text-bg-light border">No deadline</span>
                            <?php elseif ($dueValid): ?><span class="badge text-bg-success">Due <?= e(date('M j', strtotime($t['due_at']))) ?></span>
                            <?php elseif ($duePast): ?><span class="badge text-bg-secondary">Archived</span><div class="small text-muted" style="font-size:.65rem">past due — late upload OK</div>
                            <?php else: ?><span class="badge text-bg-warning">Invalid</span><?php endif; ?>
                            <div><a href="<?= e(url('/classroom-tasks/'.$t['id'])) ?>" class="btn btn-sm btn-outline-primary mt-1 py-0" style="font-size:.65rem" onclick="event.stopPropagation()" aria-label="Open task: <?= e($t['title']) ?>"><i class="bi bi-eye" aria-hidden="true"></i> Open</a></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card bg-light border small p-3 mt-3" style="background:var(--surface-sunken)">
            <div class="fw-semibold mb-1"><i class="bi bi-lightbulb text-warning"></i> Tips — How to use this page</div>
            <ul class="mb-0 ps-3">
                <li><strong>Teachers:</strong> Share the invite code at the top so students can join this subject.</li>
                <li><strong>Students:</strong> After joining, new activities appear automatically in <strong>Dashboard → Subject Tasks</strong>.</li>
                <li>This page keeps the full list for this subject — newest first. Use it to review past work.</li>
            </ul>
        </div>
    <?php endif; ?>
</div>

<?php $joinRequests = $joinRequests ?? []; ?>
<?php if ($isTeacherOwner || $role === 'admin'): ?>
<div class="card p-3 mb-3 border-warning">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-hourglass-split text-warning"></i> Join Requests <span class="badge <?= count($joinRequests) > 0 ? 'text-bg-warning' : 'text-bg-light border' ?>"><?= count($joinRequests) ?> pending</span></h2>
        <span class="small text-muted">No code needed — verify before they join</span>
    </div>
    <?php if ($joinRequests === []): ?>
        <p class="small text-muted mb-0">No pending requests. Students can request from Class Offerings, or join instantly with the invite code above.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Student</th><th>Filed by</th><th>Requested</th><th class="text-end">Verify</th></tr></thead>
            <tbody>
            <?php foreach ($joinRequests as $jr):
                $jrName = trim(trim($jr['first_name'] ?? '') . ' ' . trim($jr['last_name'] ?? ''));
                if ($jrName === '') $jrName = 'Student #' . $jr['student_id'];
                $reqName = trim(trim($jr['req_first'] ?? '') . ' ' . trim($jr['req_last'] ?? ''));
            ?>
                <tr>
                    <td><span class="fw-semibold"><?= e($jrName) ?></span><div class="small text-muted"><?= e($jr['student_no'] ?? '') ?></div></td>
                    <td class="small text-muted"><?= $reqName !== '' ? e($reqName) : '—' ?></td>
                    <td class="small text-muted"><?= e(date('M j, Y g:ia', strtotime($jr['requested_at'] ?? 'now'))) ?></td>
                    <td class="text-end">
                        <form method="post" action="<?= e(url('/teacher/join-requests/' . (int)$jr['id'] . '/approve')) ?>" class="d-inline" onsubmit="return confirm('Enroll <?= e($jrName) ?> in <?= e($classroom['subject_code'] ?? '') ?>? Eligibility and capacity are re-checked.')"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Approve</button></form>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#declineModal<?= (int)$jr['id'] ?>">Decline</button>
                        <div class="modal fade" id="declineModal<?= (int)$jr['id'] ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
                        <form method="post" action="<?= e(url('/teacher/join-requests/' . (int)$jr['id'] . '/decline')) ?>"><?= csrf_field() ?>
                        <div class="modal-header"><h5 class="modal-title">Decline — <?= e($jrName) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <div class="modal-body"><label class="small fw-semibold">Reason (optional, shown to student)</label><textarea name="reason" class="form-control" rows="3" maxlength="500" placeholder="e.g. Wrong section — please request BSIT-1B instead."></textarea></div>
                        <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Decline request</button></div>
                        </form></div></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-people"></i> Members <span class="badge bg-light border text-dark"><?= count($members) ?></span></h2>
        <?php if ($isTeacherOwner): ?><span class="small text-muted">Google Classroom-style roster</span><?php endif; ?>
    </div>
    <?php if ($members === []): ?>
        <div class="text-center py-4">
            <div class="fs-4 text-muted"><i class="bi bi-person-plus"></i></div>
            <p class="small text-muted mb-0">No students have joined yet. Share the invite code <code><?= e($classroom['invite_code'] ?? '') ?></code> — students paste it at <strong>Student Dashboard → Join a Subject</strong>.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>#</th><th>Student</th><th>Email</th><th>Joined</th></tr></thead>
                <tbody>
                <?php foreach ($members as $idx => $m): ?>
                    <tr>
                        <td class="text-muted small"><?= $idx + 1 ?></td>
                        <td><span class="fw-semibold"><?= e(trim(($m['first_name']??'').' '.($m['last_name']??''))) ?></span><div class="small text-muted"><?= e($m['student_no'] ?? '') ?></div></td>
                        <td class="small"><?= e($m['email'] ?? '') ?></td>
                        <td class="small text-muted"><?= e(date('M j, Y g:ia', strtotime($m['joined_at'] ?? 'now'))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
