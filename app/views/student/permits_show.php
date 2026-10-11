<?php /** @var array $permit @var array $approvals */ $isParent=$isParent??false; $childId=(int)($childId??0); ?>
<a href="<?= e(url('/student/permits')) ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> Back</a>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><?= e($permit['permit_no']) ?> — <?= e($permit['exam_period']) ?></h1>
    <?php $badge=match($permit['status']){'fully_approved'=>'success','rejected'=>'danger','approved_registrar'=>'warning','approved_accounting'=>'info', default=>'secondary'}; ?>
    <span class="badge text-bg-<?= $badge ?> fs-6"><?= e(str_replace('_',' ', $permit['status'])) ?></span>
</div>
<?php if ($permit['status']==='rejected'): ?>
<div class="alert alert-danger"><strong><i class="bi bi-x-circle"></i> Status: Rejected</strong><br><strong>Reason:</strong> <?= e($permit['rejected_reason'] ?? 'No reason recorded — contact the office.') ?>
<form method="post" action="<?= e(url('/student/permits/'.$permit['id'].'/request-again')) ?>" class="mt-2"><?= csrf_field() ?><?php if ($isParent && $childId > 0): ?><input type="hidden" name="child_id" value="<?= $childId ?>"><?php endif; ?><button type="submit" class="btn btn-warning text-dark btn-sm" onclick="return confirm('Resubmit this permit for review? The rejection stays in history below.')"><i class="bi bi-arrow-repeat"></i> Request Again — correct &amp; resubmit</button></form>
</div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card p-3 h-100">
            <h2 class="h6">Permit details</h2>
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Permit No.</dt><dd class="col-7 fw-semibold"><?= e($permit['permit_no']) ?></dd>
                <dt class="col-5 text-muted">Period</dt><dd class="col-7"><span class="badge text-bg-dark"><?= e($permit['exam_period']) ?></span></dd>
                <dt class="col-5 text-muted">School Year</dt><dd class="col-7"><?= e($permit['school_year'] ?? '') ?></dd>
                <dt class="col-5 text-muted">Campus</dt><dd class="col-7"><?= e($permit['campus_name'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Financial</dt><dd class="col-7">
                    <?php $finBadge=match($permit['financial_status']??'unpaid'){ 'fully_paid'=>'success','installment_paid'=>'info', default=>'warning'}; ?>
                    <span class="badge text-bg-<?= $finBadge ?>"><?= e($permit['financial_status']) ?></span>
                    <?php if ((int)($permit['payment_validated']??0)): ?><span class="badge text-bg-success">Payment Validated</span><?php endif; ?>
                    <div class="small text-muted"><?= e($permit['payment_ref']??$permit['receipt_no']??'—') ?> · Bal <?= money($permit['balance_at_request']??0) ?></div>
                </dd>
                <dt class="col-5 text-muted">Requested</dt><dd class="col-7"><?= e(date('M j, Y g:ia', strtotime($permit['requested_at']))) ?></dd>
                <?php if ($permit['status']==='fully_approved'): ?>
                    <dt class="col-5 text-muted">QR Token</dt><dd class="col-7 small font-monospace"><?= e($permit['qr_token']) ?></dd>
                <?php endif; ?>
            </dl>
            <?php if ($permit['status']==='fully_approved'): ?>
                <div class="text-center mt-3">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode(url('/verify-permit/'.$permit['qr_token'])) ?>" alt="QR" class="border rounded p-2 bg-white">
                    <div class="small text-muted mt-1">Scan to verify at gate</div>
                    <a href="<?= e(url('/student/permits/'.$permit['id'].'/print')) ?>" target="_blank" class="btn btn-success btn-sm mt-2 w-100"><i class="bi bi-printer"></i> View / Print official permit card</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card p-3 h-100">
            <h2 class="h6">Pipeline & Approvals</h2>
            <div class="d-flex gap-2 mb-3">
                <span class="badge <?= in_array($permit['status'],['approved_accounting','approved_registrar','fully_approved'],true) ? 'bg-success' : 'bg-light text-dark border' ?>">Accounting <?= in_array($permit['status'],['approved_accounting','approved_registrar','fully_approved'],true)?'✓':'·' ?></span>
                <span class="badge <?= in_array($permit['status'],['approved_registrar','fully_approved'],true) ? 'bg-success' : 'bg-light text-dark border' ?>">Registrar <?= in_array($permit['status'],['approved_registrar','fully_approved'],true)?'✓':'·' ?></span>
                <span class="badge <?= $permit['status']==='fully_approved' ? 'bg-success' : 'bg-light text-dark border' ?>">Teacher <?= $permit['status']==='fully_approved'?'✓':'·' ?></span>
            </div>
            <?php if ($approvals===[]): ?>
                <p class="text-muted small">No approvals yet. Pipeline starts at Accounting.</p>
            <?php else: ?>
                <p class="small text-muted">Full history — rejections and resubmissions are preserved here.</p>
                <ul class="list-group list-group-flush">
                    <?php foreach ($approvals as $ap): ?>
                        <li class="list-group-item px-0">
                            <div class="fw-semibold"><span class="badge text-bg-light border"><?= e(ucfirst($ap['stage'])) ?></span> <?= $ap['status']==='approved' ? '<span class="badge bg-success">Approved</span>' : '<span class="badge bg-danger">Rejected</span>' ?></div>
                            <div class="small text-muted"><?= e(trim(($ap['first_name']??'').' '.($ap['last_name']??'')) ?: 'System') ?> · <?= e(date('M j, Y g:ia', strtotime($ap['acted_at']))) ?></div>
                            <?php if (!empty($ap['remarks'])): ?><div class="small"><?= e($ap['remarks']) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
