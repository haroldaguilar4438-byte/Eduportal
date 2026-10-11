<?php
/**
 * Online Teachers Evaluation - student side (admin-gated).
 * @var array $eligible @var array $mine
 * @var array|null $openPeriod @var array|null $closedPeriod
 */
$openPeriod = $openPeriod ?? null;
$closedPeriod = $closedPeriod ?? null;
$window = $openPeriod ?? $closedPeriod;
function eval_window_label(?array $w): string {
    if (!$w) return '';
    return 'AY ' . ($w['school_year'] ?? '') . ' ' . ($w['term'] ?? '') . ' Term (' . ($w['period'] ?? '') . ' Period)';
}
?>
<div class="small text-muted mb-1"><a href="<?= e(url('/dashboard')) ?>">Students</a> / Online Teachers Evaluation</div>
<h1 class="h4 mb-1">Online Teachers Evaluation</h1>

<?php if ($openPeriod !== null): ?>
  <div class="alert alert-success py-2 small">Online Teachers Evaluation for <?= e(eval_window_label($openPeriod)) ?> is now open.</div>
  <p class="text-muted small mb-3">Evaluate only the teachers of classes you are registered in. One evaluation per class.</p>
  <div class="row g-3">
    <div class="col-12 col-xl-7">
      <div class="card p-3"><h2 class="h6">Eligible classes</h2>
        <?php if ($eligible===[]): ?><p class="text-muted small mb-0">No registered classes found.</p>
        <?php else: foreach ($eligible as $e): ?>
          <div class="border rounded p-2 mb-2">
            <div class="d-flex justify-content-between align-items-center gap-2">
              <div><strong><?= e($e['subject_code']) ?></strong> <span class="small text-muted">— <?= e($e['subject_title']) ?></span><div class="small text-muted"><?= e(trim($e['instructor']??'')!==''?$e['instructor']:'TBA') ?></div></div>
              <?php if ((int)$e['evaluated']>0): ?><span class="badge text-bg-success">Evaluated</span><?php endif; ?>
            </div>
            <?php if ((int)$e['evaluated']===0): ?>
            <form method="post" action="<?= e(url('/student/evaluations')) ?>" class="row g-2 mt-1">
              <?= csrf_field() ?>
              <input type="hidden" name="class_offering_id" value="<?= (int)$e['offering_id'] ?>">
              <div class="col-4 col-md-3"><select name="rating" class="form-select form-select-sm" required><option value="">Rating</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> ★</option><?php endfor; ?></select></div>
              <div class="col-8 col-md-7"><input name="comments" class="form-control form-control-sm" placeholder="Comments (optional)" maxlength="1000"></div>
              <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Submit</button></div>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="col-12 col-xl-5">
      <div class="card p-3"><h2 class="h6">My evaluations</h2>
        <?php if ($mine===[]): ?><p class="text-muted small mb-0">No evaluations submitted yet.</p>
        <?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($mine as $m): ?><li class="list-group-item px-0"><strong><?= e($m['subject_code']) ?></strong> — <?= (int)$m['rating'] ?> ★<div class="text-muted"><?= e($m['comments']??'') ?></div></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="alert py-2 small text-white" style="background:#e23c1e;border-color:#e23c1e" role="alert">
    Online Teachers Evaluation for <?= e(eval_window_label($window) !== '' ? eval_window_label($window) : 'the current term') ?> is already closed.
  </div>
  <button type="button" class="btn btn-sm btn-primary" onclick="history.length > 1 ? history.back() : window.location.href='<?= e(url('/dashboard')) ?>'">&laquo; Back</button>
  <?php if ($mine !== []): ?>
  <div class="card p-3 mt-3"><h2 class="h6">My evaluations</h2>
    <ul class="list-group list-group-flush small"><?php foreach ($mine as $m): ?><li class="list-group-item px-0"><strong><?= e($m['subject_code']) ?></strong> — <?= (int)$m['rating'] ?> ★<div class="text-muted"><?= e($m['comments']??'') ?></div></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
<?php endif; ?>
