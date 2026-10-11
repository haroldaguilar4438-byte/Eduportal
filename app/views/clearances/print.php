<?php /** @var array $clearance */ /** @var array $items */
$ok = in_array($clearance['status'], ['issued','used'], true);
$clrCode = trim($clearance['semester'] ?? '');
$clrLabel = in_array($clrCode, ['1st','2nd'], true) ? ($clrCode . ' Semester') : ($clrCode !== '' ? $clrCode : '—');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Clearance #<?= e($clearance['id']) ?> — Datamex</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>@media print{ .no-print{display:none} } body{background:#f3f5f9} .paper{max-width:720px;margin:24px auto;background:#fff;border:1px solid #e2e6ee;border-radius:12px;overflow:hidden}</style>
</head>
<body>
<div class="text-center p-3 no-print"><button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print / Save PDF</button> <a href="<?= e(url('/verify-clearance/'.$clearance['qr_token'])) ?>" target="_blank" class="btn btn-outline-secondary">Verify QR</a></div>
<div class="paper">
  <div style="background:#0F172A;color:#fff;padding:18px 24px;display:flex;justify-content:space-between;align-items:center">
    <div><div style="font-weight:800;font-size:18px"><i class="bi bi-mortarboard"></i> Datamex College of Saint Adeline</div><div style="opacity:.8;font-size:12px">Digital Clearance — <?= e($clrLabel) ?> · <?= e($clearance['school_year']) ?></div></div>
    <span class="badge <?= $ok?'bg-success':'bg-warning text-dark' ?>" style="font-size:12px"><?= e(strtoupper($clearance['status'])) ?></span>
  </div>
  <div style="padding:24px">
    <div class="d-flex justify-content-between">
      <div><div class="small text-muted">Student</div><div class="fw-bold"><?= e($clearance['first_name'].' '.$clearance['last_name']) ?> <span class="text-muted">(<?= e($clearance['student_no']) ?>)</span></div><div class="small text-muted"><?= e($clearance['email']) ?></div></div>
      <div class="text-end"><div class="small text-muted">Clearance #<?= e($clearance['id']) ?></div><div class="small">Academic Period: <strong><?= e($clrLabel) ?></strong></div><div class="small">Year: <?= e($clearance['school_year']) ?></div></div>
    </div>
    <hr>
    <div class="small text-muted mb-1">Professor Signatures (all subjects must be signed)</div>
    <table class="table table-sm">
      <thead><tr><th>Subject</th><th>Professor</th><th>Signed</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e(($it['subj_code']??'SUB').' — '.($it['subj_title']??'Subject')) ?><div class="small text-muted"><?= !empty($it['class_offering_id']) ? 'Offering #'.e($it['class_offering_id']) : 'Subject class #'.e($it['subject_classroom_id'] ?? '') ?><?= !empty($it['section_name']) ? ' · '.e($it['section_name']) : '' ?></div></td><td><?= e(($it['t_first']??'').' '.($it['t_last']??'')) ?> <div class="small text-muted"><?= e($it['employee_no']??'') ?></div></td><td><?php if($it['is_signed']): ?><span class="badge bg-success"><i class="bi bi-check-lg"></i> Signed <?= e($it['signed_at']??'') ?></span><?php else: ?><span class="badge bg-warning text-dark">Pending</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if(empty($items)): ?><tr><td colspan="3" class="text-center text-muted">No subjects assigned — any professor may sign to issue.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="mt-3 p-3" style="background:<?= $ok?'#DCFCE7':'#FEF3C7' ?>;border:1px solid <?= $ok?'#BBF7D0':'#FDE68A' ?>;border-radius:8px">
      <div class="fw-bold" style="color:<?= $ok?'#166534':'#92400e' ?>"><?= $ok?'✓ Clearance Issued — eligible to enroll for the next period':'⧗ Pending — awaiting professor signatures' ?></div>
      <div class="small text-muted">Digital copy — present QR at Accounting/Registrar or enroll online. The system auto-validates clearance when enrolling for the next period.</div>
    </div>
    <div class="text-center mt-3">
      <div style="display:inline-block;padding:12px;border:1px solid #e2e6ee;border-radius:12px;background:#fff">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode(url('/verify-clearance/'.$clearance['qr_token'])) ?>" alt="QR" style="width:180px;height:180px">
        <div class="small text-muted mt-1">Verify: <?= e(url('/verify-clearance/'.$clearance['qr_token'])) ?></div>
      </div>
    </div>
    <div class="small text-muted text-center mt-3">Issued: <?= e($clearance['issued_at'] ?? $clearance['created_at']) ?> · QR Token: <code><?= e(substr($clearance['qr_token'],0,16)) ?>…</code></div>
  </div>
  <div style="background:#f8fafc;border-top:1px solid #e2e6ee;padding:12px 24px;font-size:11px;color:#64748b;text-align:center">Digital clearance — valid only if status is ISSUED/USED and QR verifies on portal. Datamex College of Saint Adeline · EduPortal</div>
</div>
</body>
</html>
