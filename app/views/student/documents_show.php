<?php /** @var array $doc @var array $history @var array $types */ $isParent=$isParent??false; $childId=(int)($childId??0); ?>
<a href="<?= e(url('/student/documents')) ?>" class="btn btn-sm btn-outline-secondary mb-3">← Requests</a>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
<h1 class="h5 mb-0"><?= e($doc['doc_no']) ?> — <?= e($types[$doc['doc_type']] ?? $doc['doc_type']) ?></h1>
<span class="badge text-bg-<?= match($doc['status']){'released'=>'success','rejected'=>'danger','cancelled'=>'secondary','ready'=>'info',default=>'warning'} ?> fs-6"><?= e($doc['status']) ?></span>
</div>
<?php if($doc['status']==='rejected'): ?>
<div class="alert alert-danger"><strong>Status: Rejected</strong><br><strong>Reason:</strong> <?= e($doc['remarks'] ?? 'No reason recorded — contact the Registrar.') ?>
<form method="post" action="<?= e(url('/student/documents/'.$doc['id'].'/request-again')) ?>" class="mt-2"><?= csrf_field() ?><?php if ($isParent && $childId > 0): ?><input type="hidden" name="child_id" value="<?= $childId ?>"><?php endif; ?><input name="purpose" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Purpose (optional — correct info if needed)" value="<?= e($doc['purpose']??'') ?>"><button class="btn btn-warning text-dark btn-sm" onclick="return confirm('Resubmit this request?')"><i class="bi bi-arrow-repeat"></i> Request Again</button></form>
</div>
<?php endif; ?>
<div class="row g-3"><div class="col-12 col-lg-6"><div class="card p-3 h-100"><h2 class="h6">Request details</h2>
<dl class="row small mb-0">
<dt class="col-5 text-muted">Type</dt><dd class="col-7"><?= e($types[$doc['doc_type']] ?? $doc['doc_type']) ?></dd>
<dt class="col-5 text-muted">Purpose</dt><dd class="col-7"><?= e($doc['purpose']??'—') ?></dd>
<?php $docPerSh = trim($doc['period_code'] ?? ''); if ($docPerSh === '') $docPerSh = trim($doc['semester'] ?? ''); if (in_array($docPerSh, ['1st','2nd'], true)) $docPerSh .= ' Semester'; ?><dt class="col-5 text-muted">Term</dt><dd class="col-7"><?= e($doc['school_year']??'current') ?> <?= e($docPerSh) ?></dd>
<dt class="col-5 text-muted">Submitted</dt><dd class="col-7"><?= e(date('M j, Y g:ia', strtotime($doc['created_at']))) ?></dd>
<?php if(!empty($doc['released_at'])): ?><dt class="col-5 text-muted">Released</dt><dd class="col-7"><?= e($doc['released_at']) ?></dd><?php endif; ?>
</dl>
<div class="mt-3 d-flex gap-2 flex-wrap">
<?php if($doc['status']==='released'): ?><a href="<?= e(url('/documents/'.$doc['id'].'/print')) ?>" target="_blank" class="btn btn-success btn-sm"><i class="bi bi-printer"></i> View / Print document</a><?php endif; ?>
<?php if($doc['status']==='pending'): ?><form method="post" action="<?= e(url('/student/documents/'.$doc['id'].'/cancel')) ?>"><?= csrf_field() ?><?php if ($isParent && $childId > 0): ?><input type="hidden" name="child_id" value="<?= $childId ?>"><?php endif; ?><button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Cancel this request?')">Cancel request</button></form><?php endif; ?>
</div></div></div>
<div class="col-12 col-lg-6"><div class="card p-3 h-100"><h2 class="h6">History</h2>
<?php
$docStatus = (string) ($doc['status'] ?? '');
$statusTone = static fn(string $s): string => match ($s) {
    'released' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary',
    'ready' => 'info', 'approved' => 'primary', default => 'warning',
};
$flow = ['pending', 'approved', 'ready', 'released'];
$flowIdx = array_search($docStatus, $flow, true);
?>
<ul class="list-group list-group-flush small">
<li class="list-group-item d-flex justify-content-between"><span><strong>Submitted</strong> <?= e(date('M j, Y', strtotime($doc['created_at']))) ?></span><span class="badge text-bg-secondary">submitted</span></li>
<?php if ($flowIdx !== false && $flowIdx >= 1): ?>
<li class="list-group-item d-flex justify-content-between"><span><strong>Approved</strong><?= !empty($doc['updated_at']) && $docStatus !== 'pending' ? ' ' . e(date('M j, Y', strtotime($doc['updated_at']))) : '' ?></span><span class="badge text-bg-primary">approved</span></li>
<?php endif; ?>
<?php if ($flowIdx !== false && $flowIdx >= 2): ?>
<li class="list-group-item d-flex justify-content-between"><span><strong>Ready</strong></span><span class="badge text-bg-info">ready</span></li>
<?php endif; ?>
<?php if ($docStatus === 'released'): ?>
<li class="list-group-item d-flex justify-content-between"><span><strong>Released</strong><?= !empty($doc['released_at']) ? ' ' . e(date('M j, Y', strtotime($doc['released_at']))) : '' ?></span><span class="badge text-bg-success">released</span></li>
<?php elseif ($docStatus === 'rejected'): ?>
<li class="list-group-item d-flex justify-content-between"><span><strong>Rejected</strong><?= !empty($doc['updated_at']) ? ' ' . e(date('M j, Y', strtotime($doc['updated_at']))) : '' ?></span><span class="badge text-bg-danger">rejected</span></li>
<?php elseif ($docStatus === 'cancelled'): ?>
<li class="list-group-item d-flex justify-content-between"><span><strong>Cancelled</strong></span><span class="badge text-bg-secondary">cancelled</span></li>
<?php endif; ?>
</ul>
<h3 class="h6 mt-3">Earlier requests of this type</h3>
<ul class="list-group list-group-flush small">
<?php foreach($history as $h): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($h['doc_no']) ?> · <?= e($types[$h['doc_type']] ?? $h['doc_type']) ?></span><span class="badge text-bg-<?= $statusTone((string) ($h['status'] ?? '')) ?>"><?= e($h['status']) ?></span></li><?php endforeach; ?>
<?php if($history===[]): ?><li class="list-group-item text-muted">No earlier requests of this type.</li><?php endif; ?>
</ul>
<div class="small text-muted mt-2">Original rejected requests are never deleted — resubmissions create a new record linked by type.</div>
</div></div></div>
