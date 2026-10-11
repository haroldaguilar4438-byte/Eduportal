<?php /** @var array $rows @var string $q @var int $level @var string $statusFilter @var array $levels @var int $page @var int $per @var int $total */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0">Student Academic Records</h1>
    <span class="badge text-bg-light border"><?= number_format($total) ?> found</span>
</div>
<form method="get" class="card p-3 mb-3"><div class="row g-2">
    <div class="col-md-5"><input name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search name, email, student no."></div>
    <div class="col-md-3"><select name="level" class="form-select"><option value="0">All levels</option><?php foreach(($levels??[]) as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $level===(int)$l['id']?'selected':'' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="status" class="form-select"><option value="">All status</option><?php foreach(['enrolled','applicant','on_hold','graduated','transferred','dropped'] as $s): ?><option <?= $statusFilter===$s?'selected':'' ?> value="<?= $s ?>"><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Search</button></div>
</div></form>
<div class="card p-3"><div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
<thead><tr><th>Student No.</th><th>Name</th><th>Level / Section</th><th>Status</th><th class="text-end">Open</th></tr></thead><tbody>
<?php if($rows===[]): ?><tr><td colspan="5" class="text-center text-muted py-4">No students found.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr>
<td class="fw-semibold"><?= e($r['student_no']??'—') ?></td>
<td><?= e(trim(($r['first_name']??'').' '.($r['last_name']??'')) ?: '—') ?><?php if(empty($r['has_account'])): ?> <span class="badge text-bg-light border" style="font-size:.6rem" title="Enrolled by a parent — no portal login yet">no portal account</span><?php endif; ?><div class="text-muted"><?= e($r['email']??'') ?></div></td>
<td><?= e($r['level_name']??'—') ?><div class="text-muted"><?= e($r['section_name']??'') ?></div></td>
<td><span class="badge text-bg-<?= $r['status']==='enrolled'?'success':'secondary' ?>"><?= e($r['status']) ?></span></td>
<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/registrar/students/'.$r['id'])) ?>">Record</a></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<div class="d-flex justify-content-between mt-2 small text-muted"><span>Page <?= (int)$page ?> · <?= (int)$per ?> per page</span><span><?php if($page>1): ?><a href="?q=<?= e(urlencode($q)) ?>&level=<?= (int)$level ?>&status=<?= e(urlencode($statusFilter)) ?>&page=<?= (int)$page-1 ?>">← Prev</a><?php endif; ?> <?php if($total>$page*$per): ?><a href="?q=<?= e(urlencode($q)) ?>&level=<?= (int)$level ?>&status=<?= e(urlencode($statusFilter)) ?>&page=<?= (int)$page+1 ?>">Next →</a><?php endif; ?></span></div></div>
