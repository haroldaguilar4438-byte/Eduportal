<?php /** @var array $rows @var array $weights @var float $weightSum @var array $conversions @var array $categories */ ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0"><i class="bi bi-list-ol"></i> Grading Configuration</h1><a href="<?= e(url('/registrar/sheets')) ?>" class="btn btn-sm btn-light border">← Sheets</a></div>
<div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> Point-grade bands (1.00 best – 5.00), category weights, and percent→point conversion. Teachers read these — they cannot edit. Engine renormalizes weights over categories with actual data; missing scores are never zero.</div>
<div class="row g-3"><div class="col-12 col-xl-4"><div class="card p-3"><h2 class="h6">Add / edit band</h2>
<form method="post" action="<?= e(url('/registrar/scales')) ?>" id="scaleForm"><?= csrf_field() ?><input type="hidden" name="id" id="scaleId" value="0">
<div class="row g-2"><div class="col-6"><label class="small fw-semibold">Min</label><input name="min_grade" id="scaleMin" class="form-control" required inputmode="decimal" placeholder="1.00"></div>
<div class="col-6"><label class="small fw-semibold">Max</label><input name="max_grade" id="scaleMax" class="form-control" required inputmode="decimal" placeholder="1.25"></div></div>
<label class="small fw-semibold mt-2">Equivalent label</label><input name="equivalent" id="scaleEq" class="form-control mb-2" required maxlength="20" placeholder="Excellent">
<label class="small fw-semibold">Remarks</label><input name="remarks" id="scaleRem" class="form-control mb-2" maxlength="60" value="PASSED">
<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_passing" id="scalePass" checked><label class="form-check-label small" for="scalePass">Counts as passing</label></div>
<button class="btn btn-primary w-100">Save band</button></form></div></div>
<div class="col-12 col-xl-8"><div class="card p-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
<thead><tr><th>Range</th><th>Equivalent</th><th>Remarks</th><th>Passing</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php if($rows===[]): ?><tr><td colspan="5" class="text-center text-muted py-4">No bands — add the school scale.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr>
<td class="fw-semibold"><?= e(number_format((float)$r['min_grade'],2)) ?> – <?= e(number_format((float)$r['max_grade'],2)) ?></td>
<td><?= e($r['equivalent']) ?></td><td><?= e($r['remarks']) ?></td>
<td><?= (int)$r['is_passing']?'<span class="badge text-bg-success">Yes</span>':'<span class="badge text-bg-danger">No</span>' ?></td>
<td class="text-end"><button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('scaleId').value='<?= (int)$r['id'] ?>';document.getElementById('scaleMin').value='<?= e((string)$r['min_grade']) ?>';document.getElementById('scaleMax').value='<?= e((string)$r['max_grade']) ?>';document.getElementById('scaleEq').value='<?= e(addslashes($r['equivalent'])) ?>';document.getElementById('scaleRem').value='<?= e(addslashes($r['remarks'])) ?>';document.getElementById('scalePass').checked=<?= (int)$r['is_passing']?'true':'false' ?>;window.scrollTo(0,0)">Edit</button>
<form method="post" action="<?= e(url('/registrar/scales/'.$r['id'].'/delete')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this band?')">Delete</button></form></td>
</tr><?php endforeach; ?>
</tbody></table></div></div></div></div>
<div class="row g-3 mt-1"><div class="col-12 col-xl-6"><div class="card p-3"><h2 class="h6"><i class="bi bi-pie-chart"></i> Category weights (global default)</h2>
<form method="post" action="<?= e(url('/registrar/weights')) ?>"><?= csrf_field() ?>
<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>Category</th><th style="width:120px">Weight %</th></tr></thead><tbody>
<?php foreach($weights as $w): ?><tr><td><?= e($w['name']) ?> <span class="text-muted small"><?= e($w['code']) ?></span></td>
<td><input name="weight[<?= (int)$w['category_id'] ?>]" value="<?= e((string)$w['weight_pct']) ?>" class="form-control form-control-sm" inputmode="decimal" required></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="d-flex justify-content-between align-items-center"><span class="small">Total: <strong class="<?= abs($weightSum-100.0)>0.01?'text-danger':'text-success' ?>"><?= e(number_format((float)$weightSum,2)) ?>%</strong> (should be 100%)</span><button class="btn btn-primary btn-sm">Save weights</button></div></form>
<div class="small text-muted mt-1">Teachers may override per subject later; untagged activities default to Activities.</div></div></div>
<div class="col-12 col-xl-6"><div class="card p-3"><h2 class="h6"><i class="bi bi-arrow-left-right"></i> Percent → point conversion</h2>
<form method="post" action="<?= e(url('/registrar/conversions')) ?>" class="row g-2 mb-2"><?= csrf_field() ?><input type="hidden" name="id" id="convId" value="0">
<div class="col-3"><input name="min_pct" id="convMin" class="form-control form-control-sm" placeholder="Min %" required inputmode="decimal"></div>
<div class="col-3"><input name="max_pct" id="convMax" class="form-control form-control-sm" placeholder="Max %" required inputmode="decimal"></div>
<div class="col-3"><input name="point" id="convPoint" class="form-control form-control-sm" placeholder="Point" required inputmode="decimal"></div>
<div class="col-3"><button class="btn btn-primary btn-sm w-100">Save</button></div></form>
<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Range %</th><th>Point</th><th class="text-end"></th></tr></thead><tbody>
<?php if(($conversions??[])===[]): ?><tr><td colspan="3" class="text-center text-muted py-3">No bands.</td></tr><?php endif; ?>
<?php foreach(($conversions??[]) as $c): ?><tr>
<td><?= e(number_format((float)$c['min_pct'],2)) ?> – <?= e(number_format((float)$c['max_pct'],2)) ?></td><td><span class="badge text-bg-primary"><?= e(number_format((float)$c['point'],2)) ?></span></td>
<td class="text-end"><button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('convId').value='<?= (int)$c['id'] ?>';document.getElementById('convMin').value='<?= e((string)$c['min_pct']) ?>';document.getElementById('convMax').value='<?= e((string)$c['max_pct']) ?>';document.getElementById('convPoint').value='<?= e((string)$c['point']) ?>';window.scrollTo(0,0)">Edit</button>
<form method="post" action="<?= e(url('/registrar/conversions/'.$c['id'].'/delete')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')">Delete</button></form></td>
</tr><?php endforeach; ?>
</tbody></table></div></div></div></div>
