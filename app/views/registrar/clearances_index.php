<?php /** @var array $rows */ ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h1 class="h4 mb-0">Clearance Review (Academic)</h1><a href="<?= e(url('/registrar/re-enrollment')) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Re-enrollment Report</a></div>
<div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> Verify academic requirements per clearance. Overall completion needs all three legs: professors <strong>issued</strong> + accounting <strong>cleared</strong> + registrar <strong>approved</strong>.</div>
<div class="card p-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
<thead><tr><th scope="col">#</th><th scope="col">Student</th><th scope="col">Sem</th><th scope="col">Academic</th><th scope="col">Financial</th><th scope="col">Registrar</th><th scope="col" class="text-end">Review</th></tr></thead><tbody>
<?php if($rows===[]): ?><tr><td colspan="7" class="text-center text-muted py-4">None.</td></tr><?php endif; ?>
<?php foreach($rows as $r): $rs=$r['registrar_status']??'pending'; $rcSem = trim($r['semester'] ?? ''); $rcLabel = in_array($rcSem, ['1st','2nd'], true) ? ($rcSem . ' Semester') : ($rcSem !== '' ? $rcSem : '—'); ?><tr>
<td><?= (int)$r['id'] ?></td><td><?= e(trim(($r['first_name']??'').' '.($r['last_name']??''))) ?><div class="text-muted"><?= e($r['student_no']??'') ?> · <?= e($r['school_year']??'') ?></div></td>
<td><?= e($rcLabel) ?></td><td><span class="badge text-bg-secondary"><?= e($r['status']) ?></span></td>
<td><span class="badge text-bg-<?= ($r['financial_status']??'')==='cleared'?'success':'warning' ?>"><?= e($r['financial_status']??'pending') ?></span></td>
<td><span class="badge text-bg-<?= $rs==='approved'?'success':($rs==='returned'?'danger':'warning') ?>"><?= e($rs) ?></span><?php if(!empty($r['registrar_remarks'])): ?><div class="text-muted" style="font-size:.7rem"><?= e($r['registrar_remarks']) ?></div><?php endif; ?><?php if(!empty($r['eligibility_override'])): ?><div><span class="badge text-bg-primary">override</span></div><?php endif; ?></td>
<td class="text-end">
<?php if($rs!=='approved'): ?>
<form method="post" action="<?= e(url('/registrar/clearances/'.$r['id'].'/review')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="approved"><button class="btn btn-sm btn-success" onclick="return confirm('Approve academic requirements?')">Approve</button></form>
<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#regRej<?= (int)$r['id'] ?>">Return</button>
<div class="modal fade" id="regRej<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true" aria-labelledby="regRejTitle<?= (int)$r['id'] ?>"><div class="modal-dialog"><div class="modal-content">
<form method="post" action="<?= e(url('/registrar/clearances/'.$r['id'].'/review')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="returned">
<div class="modal-header"><h5 class="modal-title" id="regRejTitle<?= (int)$r['id'] ?>">Return clearance #<?= (int)$r['id'] ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><label class="small fw-semibold">Reason (required) <span class="text-danger">*</span></label><textarea name="reason" class="form-control" rows="3" required minlength="5" maxlength="500"></textarea></div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Return</button></div>
</form></div></div></div>
<?php else: ?><span class="text-muted">—</span><?php endif; ?>
<?php if(empty($r['eligibility_override'])): ?>
<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ovr<?= (int)$r['id'] ?>" title="Force eligible (logged)">Override</button>
<div class="modal fade" id="ovr<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true" aria-labelledby="ovrTitle<?= (int)$r['id'] ?>"><div class="modal-dialog"><div class="modal-content">
<form method="post" action="<?= e(url('/registrar/clearances/'.$r['id'].'/override')) ?>"><?= csrf_field() ?>
<div class="modal-header"><h5 class="modal-title" id="ovrTitle<?= (int)$r['id'] ?>">Override eligibility #<?= (int)$r['id'] ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><div class="alert alert-warning small">Bypasses academic legs. Logged with your name - holds and duplicates still apply.</div><label class="small fw-semibold">Reason (required) <span class="text-danger">*</span></label><textarea name="reason" class="form-control" rows="3" required minlength="5" maxlength="500"></textarea></div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Apply Override</button></div>
</form></div></div></div>
<?php endif; ?>
</td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
