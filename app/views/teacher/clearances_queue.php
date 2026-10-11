<?php /** @var array $clearances */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-pen"></i> Clearance Signing Queue <span class="badge bg-warning text-dark"><?= count($clearances) ?> pending</span></h4>
  <span class="badge bg-light border">Professor signs after Final completed</span>
</div>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> You see clearances where your subject is assigned. Signing one item; when all subjects signed, clearance becomes <strong>Issued</strong> with QR PDF for student.</div>
<?php if ($clearances===[]): ?><div class="card p-5 text-center text-muted">No pending clearances for your subjects.</div><?php else: ?>
<div class="card p-3"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th scope="col">ID</th><th scope="col">Student</th><th scope="col">My Subject(s)</th><th scope="col">Period</th><th scope="col">Year</th><th scope="col">Status</th><th scope="col">Requested</th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($clearances as $r): ?>
<tr>
  <td>#<?= e($r['id']) ?></td>
  <td><?= e($r['first_name'].' '.$r['last_name']) ?> <div class="small text-muted"><?= e($r['student_no']) ?></div></td>
  <td class="small"><?php if (!empty($r['my_subjects'])): foreach ($r['my_subjects'] as $sc): ?><span class="badge text-bg-light border"><?= e($sc !== '' ? $sc : 'Subject') ?></span> <?php endforeach; ?><?php else: ?><span class="text-muted">Any subject</span><?php endif; ?></td>
  <?php $tq = trim($r['semester'] ?? ''); $tqLabel = in_array($tq, ['1st','2nd'], true) ? ($tq . ' Semester') : ($tq !== '' ? $tq : '—'); ?><td><span class="badge text-bg-light border"><?= e($tqLabel) ?></span></td>
  <td><?= e($r['school_year']) ?></td>
  <td><span class="badge text-bg-warning">pending</span><?php if (!empty($r['teacher_remarks'])): ?><div class="small text-muted mt-1">Returned: <?= e($r['teacher_remarks']) ?></div><?php endif; ?></td>
  <td class="small text-muted"><?= e($r['requested_at'] ?? $r['created_at']) ?></td>
  <td><div class="d-flex gap-1 flex-wrap">
  <form method="post" action="<?= e(url('/teacher/clearances/'.$r['id'].'/sign')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-pen"></i> Sign</button></form>
  <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#returnModal<?= (int)$r['id'] ?>">Return</button>
  </div>
  <div class="modal fade" id="returnModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true" aria-labelledby="returnTitle<?= (int)$r['id'] ?>"><div class="modal-dialog"><div class="modal-content">
  <form method="post" action="<?= e(url('/teacher/clearances/'.$r['id'].'/return')) ?>"><?= csrf_field() ?>
  <div class="modal-header"><h5 class="modal-title" id="returnTitle<?= (int)$r['id'] ?>">Return clearance #<?= e($r['id']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><label class="small fw-semibold">Reason (required, shown to student) <span class="text-danger">*</span></label>
  <textarea name="reason" class="form-control" rows="3" required minlength="5" maxlength="500" placeholder="What must the student fix?"></textarea></div>
  <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Return to Student</button></div>
      </form></div></div></div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
