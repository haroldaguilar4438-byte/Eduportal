<?php /** @var array $byLevel @var array $done @var array $enrolledCodes */ ?>
<h1 class="h4 mb-1"><i class="bi bi-diagram-3"></i> My Curriculum Flowchart</h1>
<p class="text-muted small mb-3">Only subjects you joined with your teacher's invite code are shown. Green = completed, blue = currently registered.</p>
<?php if ($byLevel===[]): ?>
  <div class="card p-5 text-center"><h2 class="h6">No registered subjects yet</h2><p class="text-muted small mb-0">Join with the student invite code from your teacher. Other courses are hidden.</p></div>
<?php else: ?>
  <?php foreach ($byLevel as $g): ?>
  <div class="card p-3 mb-3">
    <h2 class="h6 mb-2"><?= e($g['level']['code']) ?> — <?= e($g['level']['name']) ?></h2>
    <div class="row g-2">
      <?php foreach ($g['subjects'] as $s): $isDone = !empty($done[(int)$s['id']]); $isEn = !empty($enrolledCodes[(int)$s['id']]); ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="border rounded p-2 h-100 <?= $isDone?'border-success bg-success bg-opacity-10':($isEn?'border-primary bg-primary bg-opacity-10':'') ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <strong class="small"><?= e($s['code']) ?></strong>
            <?php if ($isDone): ?><span class="badge text-bg-success">Done</span><?php elseif ($isEn): ?><span class="badge text-bg-primary">Enrolled</span><?php else: ?><span class="badge text-bg-light border">Pending</span><?php endif; ?>
          </div>
          <div class="small"><?= e($s['title']) ?></div>
          <?php $flPer = trim($s['period_code'] ?? ''); if ($flPer === '') $flPer = trim($s['semester'] ?? ''); if (in_array($flPer, ['1st','2nd'], true)) $flPer .= ' Semester'; ?><div class="small text-muted"><?= e(number_format((float)$s['units'],1)) ?> unit(s)<?= $flPer !== '' ? ' · '.e($flPer) : '' ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
