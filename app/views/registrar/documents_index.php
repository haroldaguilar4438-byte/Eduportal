<?php /** @var array $rows @var string $status @var array $counts @var string $typeFilter */ $typeFilter = $typeFilter ?? ''; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><h1 class="h4 mb-0">Academic Documents</h1><span class="badge text-bg-light border"><?= number_format($counts['all']??count($rows)) ?> records</span></div>
<ul class="nav nav-pills mb-3 gap-1 small flex-wrap"><?php foreach(['pending'=>'Pending','approved'=>'Approved','ready'=>'Ready','released'=>'Released','rejected'=>'Rejected','all'=>'All'] as $k=>$l): ?><li class="nav-item"><a class="nav-link<?= $status===$k?' active':'' ?>" href="<?= e(url('/registrar/documents?status='.$k.($typeFilter!==''?'&type='.urlencode($typeFilter):''))) ?>"><?= $l ?> <span class="badge text-bg-light ms-1"><?= $counts[$k]??0 ?></span></a></li><?php endforeach; ?></ul>
<form method="get" class="card p-2 mb-3"><div class="row g-2"><input type="hidden" name="status" value="<?= e($status) ?>">
<div class="col-md-6"><select name="type" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All document types</option><?php foreach(['transcript'=>'Transcript','enrollment_cert'=>'Cert. of Enrollment','grades_cert'=>'Cert. of Grades','grade_slip'=>'Grade Slip','registration_form'=>'Registration Form','grade_equivalency'=>'Grade Equivalency','history'=>'History','other'=>'Other'] as $v=>$l): ?><option value="<?= $v ?>" <?= $typeFilter===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
</div></form>
<div class="row g-3"><div class="col-12 col-xl-4"><div class="card p-3"><h2 class="h6">Issue directly</h2>
<form method="post" action="<?= e(url('/registrar/documents/issue')) ?>"><?= csrf_field() ?>
<label class="small">Student ID (from Records)</label><input name="student_id" class="form-control mb-2" required placeholder="e.g. 12">
<label class="small">Type</label><select name="doc_type" class="form-select mb-2"><option value="transcript">Transcript of Records</option><option value="enrollment_cert">Certificate of Enrollment</option><option value="grades_cert">Certificate of Grades</option><option value="grade_slip">Grade Slip (finalized grades)</option><option value="registration_form">Registration Form</option><option value="grade_equivalency">Grade Equivalency</option><option value="history">Academic History</option><option value="other">Other certification</option></select>
<label class="small">Purpose</label><input name="purpose" class="form-control mb-2" placeholder="e.g. Board exam">
<button class="btn btn-primary w-100">Issue</button></form>
<div class="small text-muted mt-2">Student online requests arrive here as <span class="badge text-bg-warning">pending</span> — open them to approve, ready, and release.</div>
</div></div>
<div class="col-12 col-xl-8"><div class="card p-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
<thead><tr><th>Doc No.</th><th>Student</th><th>Type</th><th>Status</th><th class="text-end">Open</th></tr></thead><tbody>
<?php if($rows===[]): ?><tr><td colspan="5" class="text-center text-muted py-4">No documents.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr>
<td class="fw-semibold"><?= e($r['doc_no']) ?><div class="text-muted"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></div></td>
<td><?= e(trim(($r['first_name']??'').' '.($r['last_name']??''))) ?><div class="text-muted"><?= e($r['student_no']??'') ?></div></td>
<td><?= e(str_replace('_',' ',$r['doc_type'])) ?></td>
<td><span class="badge text-bg-<?= match($r['status']){'released'=>'success','rejected'=>'danger','cancelled'=>'secondary','ready'=>'info',default=>'warning'} ?>"><?= e($r['status']) ?></span><?php if($r['status']==='rejected' && !empty($r['remarks'])): ?><div class="small text-danger mt-1"><?= e($r['remarks']) ?></div><?php endif; ?></td>
<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/registrar/documents/'.$r['id'])) ?>">Open</a></td>
</tr><?php endforeach; ?>
</tbody></table></div></div></div></div>
