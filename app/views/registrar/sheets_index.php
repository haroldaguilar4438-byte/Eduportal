<?php /** @var array $rows @var array $counts @var array $filters @var array $periods */ $f=$filters??[]; $periodOptions=$periodOptions??['1st','2nd','Summer']; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><h1 class="h4 mb-0"><i class="bi bi-journal-check"></i> Grade Sheets</h1><a href="<?= e(url('/registrar/scales')) ?>" class="btn btn-sm btn-light border"><i class="bi bi-list-ol"></i> Grade Scale</a></div>
<ul class="nav nav-pills mb-3 gap-1 small flex-wrap">
<?php foreach(['submitted'=>'Submitted','under_review'=>'Under Review','returned'=>'Returned','approved'=>'Approved','finalized'=>'Finalized','draft'=>'Drafts','all'=>'All'] as $k=>$l): ?><li class="nav-item"><a class="nav-link<?= ($f['status']??'submitted')===$k?' active':'' ?>" href="<?= e(url('/registrar/sheets?status='.$k.'&teacher='.urlencode($f['teacher']??'').'&subject='.urlencode($f['subject']??'').'&period='.urlencode($f['period']??'').'&semester='.urlencode($f['semester']??''))) ?>"><?= $l ?> <span class="badge text-bg-light ms-1"><?= $counts[$k]??0 ?></span></a></li><?php endforeach; ?>
</ul>
<form method="get" class="card p-3 mb-3"><div class="row g-2">
<input type="hidden" name="status" value="<?= e($f['status']??'submitted') ?>">
<div class="col-md-3"><input name="teacher" value="<?= e($f['teacher']??'') ?>" class="form-control" placeholder="Teacher name"></div>
<div class="col-md-3"><input name="subject" value="<?= e($f['subject']??'') ?>" class="form-control" placeholder="Code / title / section"></div>
<div class="col-md-2"><select name="period" class="form-select"><option value="">All periods</option><?php foreach($periods as $p): ?><option value="<?= e($p['code']) ?>" <?= ($f['period']??'')===$p['code']?'selected':'' ?>><?= e($p['code']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><select name="semester" class="form-select"><option value="">All periods</option><?php foreach($periodOptions as $s): ?><option <?= ($f['semester']??'')===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
</div></form>
<div class="card p-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
<thead><tr><th scope="col">Subject</th><th scope="col">Period</th><th scope="col">Teacher</th><th scope="col">Graded</th><th scope="col">Status</th><th scope="col" class="text-end">Open</th></tr></thead><tbody>
<?php if($rows===[]): ?><tr><td colspan="6" class="text-center text-muted py-4">Queue clear.</td></tr><?php endif; ?>
<?php foreach($rows as $r): $done=(int)$r['total_rows']-(int)$r['missing']; ?><tr>
<td><span class="fw-semibold"><?= e($r['subject_code']) ?></span> <?= e($r['subject_title']) ?><div class="text-muted"><?= e($r['section_name']??'') ?> · <?= e($r['school_year']) ?> <?= e($r['class_semester']??'') ?></div></td>
<td><span class="badge text-bg-dark"><?= e($r['period_code']) ?></span></td>
<td class="small"><?= e($r['teacher_name']) ?></td>
<td class="small"><?= $done ?>/<?= (int)$r['total_rows'] ?><?= (int)$r['missing']>0?' <span class="badge text-bg-danger">'.$r['missing'].' missing</span>':'' ?></td>
<td><span class="badge text-bg-<?= match($r['status']){'submitted'=>'warning','under_review'=>'info','returned'=>'danger','approved'=>'primary','finalized'=>'success',default=>'secondary'} ?>"><?= e(str_replace('_',' ',$r['status'])) ?></span></td>
<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/registrar/sheets/'.$r['id'])) ?>">Review</a></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
