<?php /** @var array $rows @var string $status @var array $counts */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><h1 class="h4 mb-0">Academic Permits — Registrar</h1><span class="badge text-bg-light border">Stage 2: Accounting ✓ → Registrar → Teacher</span></div>
<ul class="nav nav-pills mb-3 flex-wrap gap-1 small">
<?php foreach(['approved_accounting'=>'Awaiting registrar','approved_registrar'=>'→ Teacher','fully_approved'=>'Fully approved','rejected'=>'Rejected','all'=>'All'] as $k=>$l): ?><li class="nav-item"><a class="nav-link<?= $status===$k?' active':'' ?>" href="<?= e(url('/registrar/permits?status='.$k)) ?>"><?= $l ?> <span class="badge text-bg-light ms-1"><?= $counts[$k]??0 ?></span></a></li><?php endforeach; ?>
</ul>
<div class="card p-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
<thead><tr><th>Permit</th><th>Student</th><th>Payment</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php if($rows===[]): ?><tr><td colspan="5" class="text-center text-muted py-4">No permits.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr>
<td class="fw-semibold"><?= e($r['permit_no']) ?><div class="text-muted"><?= e($r['exam_period']) ?> · <?= e($r['school_year']) ?></div></td>
<td><?= e(trim(($r['first_name']??'').' '.($r['last_name']??''))) ?><div class="text-muted small"><?= e($r['student_no']??'') ?></div></td>
<td><?php if((int)($r['payment_validated']??0)): ?><span class="badge text-bg-success"><?= e($r['financial_status']) ?></span><div class="small text-muted"><?= e($r['payment_ref']??'') ?></div><?php else: ?><span class="badge text-bg-danger">Not validated</span><?php endif; ?></td>
<td><span class="badge text-bg-<?= $r['status']==='fully_approved'?'success':($r['status']==='rejected'?'danger':'warning') ?>"><?= e(str_replace('_',' ',$r['status'])) ?></span><?php if($r['status']==='rejected' && !empty($r['rejected_reason'])): ?><div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> <?= e($r['rejected_reason']) ?></div><?php endif; ?></td>
<td class="text-end"><?php if($r['status']==='approved_accounting'): ?>
<form method="post" action="<?= e(url('/registrar/permits/'.$r['id'].'/approve')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-warning text-dark" onclick="return confirm('Approve by Registrar?')">Approve</button></form>
<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal" data-form-action="<?= e(url('/registrar/permits/'.$r['id'].'/reject')) ?>" data-record="<?= e($r['permit_no'].' — '.trim(($r['first_name']??'').' '.($r['last_name']??''))) ?>" data-title="Reject Permit">Reject</button>
<?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../shared/partials/reject_modal.php'; ?>
