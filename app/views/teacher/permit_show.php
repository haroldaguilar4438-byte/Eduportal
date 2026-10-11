<?php
/** @var array $permit @var array $history */
$permit = $permit ?? [];
$history = $history ?? [];
$full = trim(($permit['first_name'] ?? '') . ' ' . ($permit['last_name'] ?? ''));
$status = (string) ($permit['status'] ?? '');
$statusBadge = match ($status) {
    'fully_approved' => 'bg-success',
    'approved_registrar' => 'bg-primary',
    'approved_accounting' => 'bg-info text-dark',
    'rejected' => 'bg-danger',
    default => 'bg-warning text-dark',
};
$stageIdx = match ($status) {
    'pending_accounting' => 0,
    'approved_accounting' => 1,
    'approved_registrar' => 2,
    'fully_approved' => 3,
    default => 0,
};
$stages = ['Accounting', 'Registrar', 'Teacher'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h5 mb-0"><i class="bi bi-patch-check"></i> <?= e($permit['permit_no'] ?? 'Permit') ?> <span class="badge <?= $statusBadge ?>"><?= e(str_replace('_', ' ', $status)) ?></span></h1>
    <a href="<?= e(url('/teacher/permits')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to queue</a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card p-4 h-100">
            <h2 class="h6"><i class="bi bi-person-badge"></i> Student</h2>
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="d-inline-flex align-items-center justify-content-center rounded-3 text-white fw-bold" style="width:48px;height:48px;background:#1e3a5f"><?= e(strtoupper(substr($permit['first_name'] ?? 'S', 0, 1) . substr($permit['last_name'] ?? '', 0, 1))) ?></span>
                <div>
                    <div class="fw-bold"><?= e($full ?: '—') ?></div>
                    <div class="small text-muted"><?= e($permit['student_no'] ?? '') ?> · <?= e($permit['email'] ?? '') ?></div>
                </div>
            </div>
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Level</dt><dd class="col-7"><?= e($permit['level_name'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Section</dt><dd class="col-7"><?= e($permit['section_name'] ?? '—') ?></dd>
                <?php if (!empty($permit['program_name'])): ?><dt class="col-5 text-muted">Program</dt><dd class="col-7"><?= e($permit['program_code'] ?? '') ?> <?= e($permit['program_name']) ?></dd><?php endif; ?>
                <dt class="col-5 text-muted">School Year</dt><dd class="col-7"><?= e($permit['school_year'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Exam Period</dt><dd class="col-7"><span class="badge text-bg-dark"><?= e($permit['exam_period'] ?? '') ?></span></dd>
            </dl>
        </div>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card p-4 h-100">
            <h2 class="h6"><i class="bi bi-diagram-3"></i> Approval progress</h2>
            <ol class="list-group list-group-numbered mb-3">
                <?php foreach ($stages as $i => $s): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <?= e($s) ?>
                        <?php if ($i < $stageIdx || $status === 'fully_approved'): ?><span class="badge bg-success"><i class="bi bi-check-lg"></i> Done</span>
                        <?php elseif ($i === $stageIdx && $status !== 'rejected'): ?><span class="badge bg-primary">Current</span>
                        <?php elseif ($status === 'rejected'): ?><span class="badge bg-secondary">—</span>
                        <?php else: ?><span class="badge bg-light text-dark border">Waiting</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
            <h2 class="h6"><i class="bi bi-receipt"></i> Payment proof</h2>
            <?php if ((int) ($permit['payment_validated'] ?? 0)): ?>
                <div class="alert alert-success small mb-2"><i class="bi bi-check-circle"></i> <strong>Payment validated</strong> — ref <code><?= e($permit['payment_ref'] ?? '—') ?></code><?php if (!empty($permit['receipt_no'])): ?> · receipt <code><?= e($permit['receipt_no']) ?></code><?php endif; ?><?php if (isset($permit['balance_at_request'])): ?> · balance at request <?= money_plain($permit['balance_at_request']) ?><?php endif; ?></div>
            <?php else: ?>
                <div class="alert alert-danger small mb-2"><i class="bi bi-x-circle"></i> Payment NOT validated.</div>
            <?php endif; ?>
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Requested</dt><dd class="col-7"><?= e(!empty($permit['requested_at']) ? date('M j, Y g:ia', strtotime($permit['requested_at'])) : '—') ?></dd>
                <?php if (!empty($permit['rejected_reason'])): ?><dt class="col-5 text-muted">Rejected reason</dt><dd class="col-7 text-danger"><?= e($permit['rejected_reason']) ?></dd><?php endif; ?>
            </dl>
        </div>
    </div>
</div>

<div class="card p-4 mt-3">
    <h2 class="h6"><i class="bi bi-clock-history"></i> Approval history</h2>
    <?php if ($history === []): ?>
        <div class="alert alert-light border small mb-0">No stage records yet.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Stage</th><th>Status</th><th>Approver</th><th>Remarks</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr><td class="small fw-semibold"><?= e(ucfirst($h['stage'] ?? '')) ?></td><td><span class="badge <?= ($h['status'] ?? '') === 'approved' ? 'bg-success' : 'bg-secondary' ?>"><?= e($h['status'] ?? '') ?></span></td><td class="small"><?= e($h['approver'] ?? '—') ?></td><td class="small text-muted"><?= e($h['remarks'] ?? '—') ?></td><td class="small text-muted"><?= e($h['created_at'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php if ($status === 'approved_registrar'): ?>
<div class="card p-4 mt-3 border-success" style="border-top:4px solid #16a34a">
    <h2 class="h6"><i class="bi bi-patch-check-fill text-success"></i> Teacher verification (Stage 3/3)</h2>
    <p class="small text-muted">Verify this permit to mark it <span class="badge bg-success">Fully Approved</span>. The student can then print the official card.</p>
    <form method="post" action="<?= e(url('/teacher/permits/' . (int) ($permit['id'] ?? 0) . '/verify')) ?>" class="d-flex gap-2 flex-wrap">
        <?= csrf_field() ?>
        <input name="remarks" class="form-control" maxlength="255" placeholder="Remarks (optional)" aria-label="Verification remarks">
        <button type="submit" class="btn btn-success" onclick="return confirm('Mark <?= e($permit['permit_no'] ?? '') ?> as Fully Approved?')"><i class="bi bi-patch-check-fill"></i> Verify &amp; approve</button>
    </form>
</div>
<?php elseif ($status === 'fully_approved'): ?>
<div class="alert alert-success mt-3 mb-0"><i class="bi bi-check-circle"></i> Fully approved — QR stamp generated. Students print from <em>My Permits → Print</em>.</div>
<?php endif; ?>
