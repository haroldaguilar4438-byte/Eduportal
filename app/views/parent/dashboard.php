<?php
/**
 * Parent portal — real actions per child, plus account overview.
 * @var array $children @var array $announcements @var array $stats @var array|null $parentInfo
 */
$children = $children ?? [];
$stats = $stats ?? ['pending' => 0, 'action' => 0, 'unpaid' => 0, 'enrolled' => 0];
$parentInfo = $parentInfo ?? null;
$pName = $parentInfo !== null ? trim(trim((string) ($parentInfo['first_name'] ?? '')) . ' ' . trim((string) ($parentInfo['last_name'] ?? ''))) : '';
$openStates = ['pending', 'accounting_approved', 'awaiting_payment', 'action_required'];
?>
<?php
/**
 * PARENT — family workspace.
 * Distinct focus: one card per child, so the header reports how many
 * children are linked and how many need the parent's attention.
 */
$childCount = count($children);
$attention  = (int) ($stats['action'] ?? 0) + (int) ($stats['unpaid'] ?? 0);
$parentHeadline = $childCount === 0
    ? 'No children are linked to this account yet'
    : ($attention > 0
        ? $attention . ' item' . ($attention === 1 ? '' : 's') . ' need your attention across ' . $childCount . ' child' . ($childCount === 1 ? '' : 'ren')
        : 'Everything for your ' . $childCount . ' child' . ($childCount === 1 ? '' : 'ren') . ' is up to date');
?>
<?= edu_page_header([
    'eyebrow'     => 'Family portal',
    'title'       => 'Welcome, ' . ($pName !== '' ? e(explode(' ', $pName)[0]) : 'Parent'),
    'description' => e($parentHeadline) . '.',
    'actions'     => '<a class="btn btn-light btn-sm" href="' . e(url('/parent/applications')) . '"><i class="bi bi-file-earmark-text me-1"></i> Applications</a>'
                   . '<a class="btn btn-primary btn-sm" href="' . e(url('/notifications')) . '"><i class="bi bi-bell me-1"></i> Notifications</a>',
]) ?>
<?php if ($pName !== ''): ?>
<p class="small mb-4" style="color:var(--text-tertiary)">
    Signed in as <strong style="color:var(--text-secondary)"><?= e($pName) ?></strong>
    <?= e($parentInfo['email'] ?? '') ?><?= !empty($parentInfo['phone']) ? ' · ' . e($parentInfo['phone']) : '' ?>
    &middot; <?= edu_badge($childCount . ' linked child' . ($childCount === 1 ? '' : 'ren'), 'primary', 'bi-people') ?>
</p>
<?php endif; ?>

<?php $hasPendingApp = $hasPendingApp ?? false; $hasApprovedUnlinked = $hasApprovedUnlinked ?? false; ?>
<?php if ($hasPendingApp && $children === []): ?>
<div class="alert alert-warning">
    <i class="bi bi-hourglass-split"></i> Your child's enrollment application is still being processed
    (Accounting → Registrar → payment). Nothing is linked yet because there is no approved student record —
    track it under <a href="<?= e(url('/my-enrollments')) ?>" class="alert-link">Track applications</a>.
</div>
<?php elseif ($hasApprovedUnlinked): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> An approved enrollment is not linked to your children list yet.
    Reload this page (linking is automatic) or contact the registrar.
</div>
<?php endif; ?>

<!-- Overview -->
<div class="row g-2 mb-4">
    <div class="col-6 col-lg-3"><div class="card p-3 text-center h-100"><div class="small text-muted">Linked children</div><div class="h4 mb-0"><?= count($children) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center h-100"><div class="small text-muted">Enrolled</div><div class="h4 mb-0 text-success"><?= (int) ($stats['enrolled'] ?? 0) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center border-warning h-100"><div class="small text-muted">Need action / unpaid</div><div class="h4 mb-0 text-warning"><?= (int) ($stats['action'] ?? 0) + (int) ($stats['unpaid'] ?? 0) ?></div><a href="<?= e(url('/my-enrollments')) ?>" class="small">Review</a></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center h-100"><div class="small text-muted">In process</div><div class="h4 mb-0"><?= (int) ($stats['pending'] ?? 0) ?></div><a href="<?= e(url('/parent/applications')) ?>" class="small">History</a></div></div>
</div>

<?php $pendingFilings = $pendingFilings ?? []; ?>
<?php if ($pendingFilings !== []): ?>
<div class="card p-3 mb-4 border-warning">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-hourglass-split text-warning"></i> New filings — awaiting approval &amp; linkage</h2>
        <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-sm btn-outline-secondary">Track applications</a>
    </div>
    <p class="small text-muted mb-2">Filed and received — waiting for Accounting → Registrar → payment. Your child gets their own card above automatically once approved and paid.</p>
    <ul class="list-group list-group-flush">
        <?php foreach ($pendingFilings as $pf): ?>
        <li class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><strong><?= e($pf['application_no'] ?? '') ?></strong> · <?= e($pf['child_name'] ?? '—') ?><?= !empty($pf['level_name']) ? ' · ' . e($pf['level_name']) : '' ?>
            <span class="badge bg-light text-dark border ms-1"><?= e(str_replace('_', ' ', $pf['status'] ?? '')) ?></span></span>
            <span class="small text-muted"><?= e(time_ago($pf['created_at'] ?? '')) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($children === []): ?>
<div class="alert alert-info">
    No children are linked to your account yet. File your first application below — once approved and paid, your child appears here automatically.
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($children as $c):
        $cid = (int) ($c['id'] ?? 0);
        $cName = trim(trim(($c['first_name'] ?? '')) . ' ' . trim(($c['last_name'] ?? '')));
        if ($cName === '') $cName = 'Child #' . $cid;
        $statusBadge = match ($c['status']) {
            'enrolled'   => 'success',
            'applicant'  => 'info',
            'on_hold'    => 'warning',
            'graduated'  => 'primary',
            default      => 'secondary',
        };
        $isEnrolled = ($c['status'] ?? '') === 'enrolled';
        $app = $c['latest_app'] ?? null;
        $appOpen = $app !== null && in_array(($app['status'] ?? ''), $openStates, true);
        $needsAction = $app !== null && ($app['status'] ?? '') === 'action_required';
    ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card p-3 h-100 <?= $needsAction ? 'border-warning' : '' ?>">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-person"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="fw-semibold text-truncate"><?= e($cName) ?></div>
                    <div class="small text-muted"><?= e($c['student_no'] ?? 'No ID yet') ?></div>
                </div>
                <span class="badge text-bg-<?= $statusBadge ?>"><?= e(str_replace('_', ' ', $c['status'] ?? '')) ?></span>
            </div>
            <ul class="list-unstyled small mt-3 mb-0">
                <li><i class="bi bi-mortarboard"></i> <?= e($c['level_name'] ?? 'Level N/A') ?>
                    &middot; <?= e($c['section_name'] ?? 'No section') ?></li>
                <?php if (!empty($c['period_label'])): ?><li class="text-muted"><i class="bi bi-calendar3"></i> <?= e($c['period_label']) ?></li><?php endif; ?>
                <li class="mt-1">
                    <span class="badge text-bg-light border"><i class="bi bi-journal-check"></i> <?= (int) ($c['subject_count'] ?? 0) ?> subject(s)</span>
                    <?php if ((float) ($c['balance'] ?? 0) > 0): ?><span class="badge text-bg-danger">Balance <?= money($c['balance']) ?></span><?php endif; ?>
                </li>
                <?php if ($app !== null): ?>
                <li class="mt-1"><i class="bi bi-file-text"></i> <?= e($app['application_no']) ?>
                    <span class="badge <?= ($app['status'] ?? '') === 'approved' ? 'bg-success' : ((($app['status'] ?? '') === 'action_required' || ($app['status'] ?? '') === 'rejected') ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>"><?= e(str_replace('_', ' ', $app['status'])) ?></span></li>
                <?php endif; ?>
            </ul>
            <hr class="my-3">
            <div class="d-flex gap-2">
                <a href="<?= e(url('/parent/children/' . $cid)) ?>" class="btn btn-sm btn-primary w-100"><i class="bi bi-person-vcard"></i> View Child</a>
            </div>
            <div class="d-flex gap-2 mt-2">
                <a href="<?= e(url('/student/grades?child_id=' . $cid)) ?>" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-graph-up"></i> Grades</a>
                <a href="<?= e(url('/student/ledger?child_id=' . $cid)) ?>" class="btn btn-sm btn-outline-success w-100"><i class="bi bi-cash-coin"></i> Fees</a>
            </div>
            <div class="d-flex gap-2 mt-2">
                <a href="<?= e(url('/student/schedule?child_id=' . $cid)) ?>" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-calendar3"></i> Schedule</a>
                <a href="<?= e(url('/student/clearances?child_id=' . $cid)) ?>" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-patch-check-fill"></i> Clearance</a>
            </div>
            <div class="d-flex gap-2 mt-2">
                <a href="<?= e(url('/student/class-offerings?child_id=' . $cid)) ?>" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-easel2"></i> Subjects</a>
                <a href="<?= e(url('/student/my-classes?child_id=' . $cid)) ?>" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-collection"></i> My Subjects</a>
            </div>
            <div class="d-flex gap-2 mt-2">
                <a href="<?= e(url('/student/enrollment-docs?child_id=' . $cid)) ?>" class="btn btn-sm btn-outline-success w-100"><i class="bi bi-folder-check"></i> Documents</a>
                <a href="<?= e(url('/enrollment/eligibility?child=' . $cid)) ?>" class="btn btn-sm btn-outline-warning w-100"><i class="bi bi-arrow-repeat"></i> Next Period</a>
            </div>
            <div class="d-flex gap-2 mt-2">
                <?php if ($appOpen): ?>
                    <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-sm btn-info w-100"><i class="bi bi-hourglass-split"></i> Track Application</a>
                <?php else: ?>
                    <a href="<?= e(url('/enroll?child=' . $cid)) ?>" class="btn btn-sm btn-success w-100"><i class="bi bi-send"></i> <?= $isEnrolled ? 'Re-enroll' : 'Enroll' ?></a>
                <?php endif; ?>
            </div>
            <?php if ($isEnrolled): ?>
            <form method="get" action="<?= e(url('/student/classrooms/preview')) ?>" class="mt-2 border rounded-3 p-2 bg-light">
                <label class="form-label small fw-semibold mb-1" for="code_<?= $cid ?>">Join a subject for <?= e($cName) ?></label>
                <div class="input-group input-group-sm">
                    <input id="code_<?= $cid ?>" name="code" class="form-control font-monospace" placeholder="Invite code" required maxlength="12" style="text-transform:uppercase">
                    <input type="hidden" name="child_id" value="<?= $cid ?>">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Join</button>
                </div>
                <div class="form-text">Paste the teacher's invite code — you'll preview before confirming.</div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card p-3 mb-4 border-primary">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
        <h2 class="h6 mb-0"><i class="bi bi-journal-plus text-primary"></i> Enroll a child</h2>
        <span class="badge text-bg-light border">Kindergarten – Junior High</span>
    </div>
    <p class="small text-muted mb-2">Parent accounts enroll <strong>Kindergarten through Junior High</strong> levels — one application per child, each with separate documents, payments, and records. Once approved and paid, your child is linked above automatically.</p>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(url('/enroll')) ?>" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Start enrollment</a>
        <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-sm btn-outline-secondary">Track applications</a>
        <a href="<?= e(url('/parent/applications')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clock-history"></i> Enrollment history</a>
    </div>
</div>

<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-megaphone"></i> Announcements for parents</h2>
        <a href="<?= e(url('/notifications')) ?>" class="btn btn-sm btn-outline-secondary">All notifications</a>
    </div>
    <?php if ($announcements === []): ?>
        <p class="text-muted small text-center py-4 mb-0">No announcements yet.</p>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($announcements as $a): ?>
                <li class="list-group-item px-0">
                    <div class="fw-semibold"><?= e($a['title']) ?></div>
                    <div class="small text-muted"><?= e($a['excerpt']) ?>&hellip;
                        <span class="text-secondary">&middot; <?= e(date('M j, Y', strtotime($a['publish_at']))) ?></span></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
