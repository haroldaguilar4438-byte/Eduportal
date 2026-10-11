<?php /** @var array $rows @var array $counts @var array $filters */ $f=$filters??[]; $periodOptions=$periodOptions??['1st','2nd','Summer']; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0">Grade Verification</h1>
    <span class="small text-muted">Teacher grading is preserved — Registrar reviews, approves, or returns.</span>
</div>
<ul class="nav nav-pills mb-3 flex-wrap gap-1 small">
<?php foreach(['submitted'=>'Awaiting review','reviewed'=>'Reviewed','approved'=>'Approved','returned'=>'Returned'] as $k=>$l): ?><li class="nav-item"><a class="nav-link<?= ($f['review']??'submitted')===$k?' active':'' ?>" href="<?= e(url('/registrar/grades?review='.$k.'&teacher='.urlencode($f['teacher']??'').'&subject='.urlencode($f['subject']??'').'&semester='.urlencode($f['semester']??''))) ?>"><?= $l ?> <span class="badge text-bg-light ms-1"><?= $counts[$k]??0 ?></span></a></li><?php endforeach; ?>
</ul>
<form method="get" class="card p-3 mb-3"><div class="row g-2">
<input type="hidden" name="review" value="<?= e($f['review']??'submitted') ?>">
<div class="col-md-4"><input name="teacher" value="<?= e($f['teacher']??'') ?>" class="form-control" placeholder="Teacher name"></div>
<div class="col-md-4"><input name="subject" value="<?= e($f['subject']??'') ?>" class="form-control" placeholder="Subject code/title"></div>
<div class="col-md-2"><select name="semester" class="form-select"><option value="">All periods</option><?php foreach($periodOptions as $s): ?><option <?= ($f['semester']??'')===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
</div></form>
<div class="card p-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
<thead><tr><th scope="col">Student</th><th scope="col">Subject / Task</th><th scope="col">Score</th><th scope="col">Teacher</th><th scope="col">Status</th><th scope="col" class="text-end">Review</th></tr></thead><tbody>
<?php if($rows===[]): ?><tr><td colspan="6" class="text-center text-muted py-4">Queue clear.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr>
<td><?= e($r['student_name']) ?><div class="text-muted"><?= e($r['student_no']) ?></div></td>
<td><span class="fw-semibold"><?= e($r['subject_code']) ?></span> <span class="text-muted"><?= e($r['title']) ?></span><div class="text-muted"><?= e($r['school_year']) ?> <?= e($r['semester']??'') ?> · <?= e($r['section_name']??'') ?></div></td>
<td><span class="badge text-bg-<?= $r['score']!==null&&$r['score']>=75?'success':'warning' ?>"><?= e($r['score']===null?'—':(string)$r['score']) ?></span></td>
<td class="small"><?= e($r['teacher_name']) ?></td>
<td><span class="badge text-bg-secondary"><?= e($r['status']) ?></span> <span class="badge text-bg-info"><?= e($r['review_status']) ?></span></td>
<td class="text-end">
<form method="post" action="<?= e(url('/registrar/grades/'.$r['id'].'/review')) ?>" class="d-flex gap-1 justify-content-end grade-review-form">
<?= csrf_field() ?>
<input name="remarks" class="form-control form-control-sm" placeholder="Remarks (required to Return)" style="max-width:140px">
<select name="action" class="form-select form-select-sm" style="max-width:120px"><option value="reviewed">Reviewed</option><option value="approved">Approved</option><option value="returned">Returned</option></select>
<button class="btn btn-sm btn-primary">Apply</button>
</form></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<div class="small text-muted mt-2">Approved grades are locked — teachers cannot re-grade. Returning a grade requires a correction reason (min 5 characters), logged in grade history + audit.</div></div>
<script>
document.querySelectorAll('.grade-review-form').forEach(function(f){
  f.addEventListener('submit', function(ev){
    var a=f.querySelector('select[name="action"]').value, r=f.querySelector('input[name="remarks"]').value.trim();
    if(a==='returned' && r.length<5){ ev.preventDefault(); alert('Returning a grade requires a correction reason (min 5 characters).'); f.querySelector('input[name="remarks"]').focus(); return; }
    if(!confirm('Apply review status?')) ev.preventDefault();
  });
});
</script>
