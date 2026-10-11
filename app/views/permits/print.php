<?php
/** @var array $permit @var array $approvals */
// This view is rendered with layout = null (standalone printable card)
$studentName = trim(($permit['first_name']??'').' '.($permit['last_name']??''));
$permitNo = $permit['permit_no'] ?? '';
$period = $permit['exam_period'] ?? '';
$schoolYear = $permit['school_year'] ?? '';
$level = $permit['level_name'] ?? '';
$section = $permit['section_name'] ?? '';
$paymentRef = $permit['payment_ref'] ?? $permit['receipt_no'] ?? '—';
$isFully = ($permit['status'] ?? '') === 'fully_approved';
$qrToken = $permit['qr_token'] ?? '';
$qrUrl = $qrToken ? 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode(url('/verify-permit/'.$qrToken)) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Exam Permit <?= e($permitNo) ?> - Datamex College of Saint Adeline</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
@media print { .no-print { display:none !important } body { background:#fff !important } .card { box-shadow:none !important; border:1px solid #e2e8f0 !important } }
.permit-card { max-width:640px; margin:32px auto; border:2px solid #0F172A; border-radius:16px; overflow:hidden }
.permit-header { background:#0F172A; color:#fff; padding:18px 20px; display:flex; justify-content:space-between; align-items:center }
.permit-body { padding:20px }
.stamp { border:2px dashed #059669; color:#059669; border-radius:999px; padding:6px 14px; font-weight:800; letter-spacing:.08em; font-size:.8rem }
</style>
</head>
<body class="bg-light">
<div class="permit-card bg-white shadow">
    <div class="permit-header">
        <div>
            <div class="fw-bold" style="letter-spacing:.04em">DATAMEX COLLEGE OF SAINT ADELINE</div>
            <div class="small opacity-75">EduPortal — Official Exam Permit</div>
        </div>
        <div class="text-end">
            <div class="small opacity-75">Permit</div>
            <div class="fw-bold"><?= e($permitNo) ?></div>
        </div>
    </div>
    <div class="permit-body">
        <div class="row g-3">
            <div class="col-8">
                <h5 class="mb-1"><?= e($studentName) ?></h5>
                <div class="small text-muted"><?= e($permit['student_no'] ?? '') ?> · <?= e($level) ?><?= $section ? ' — '.e($section) : '' ?> · <?= e($schoolYear) ?></div>
                <div class="mt-3">
                    <span class="badge bg-dark fs-6"><?= e($period) ?> Examination</span>
                    <?php if ($isFully): ?><span class="stamp ms-2">FULLY APPROVED ✓</span><?php else: ?><span class="badge bg-warning text-dark">NOT YET FULLY APPROVED — <?= e(str_replace('_',' ', strtoupper($permit['status']??''))) ?></span><?php endif; ?>
                </div>
                <dl class="row small mt-3 mb-0">
                    <dt class="col-5 text-muted">Payment Status</dt>
                    <dd class="col-7"><span class="badge bg-<?= $permit['financial_status']==='fully_paid'?'success':($permit['financial_status']==='installment_paid'?'info':'warning') ?>"><?= e($permit['financial_status']) ?></span> <?php if ((int)($permit['payment_validated']??0)): ?><span class="badge bg-success">Payment Validated</span><?php endif; ?><div class="font-monospace small"><?= e($paymentRef) ?></div></dd>
                    <dt class="col-5 text-muted">Balance at request</dt><dd class="col-7"><?= money($permit['balance_at_request']??0) ?></dd>
                    <dt class="col-5 text-muted">Pipeline</dt><dd class="col-7 small">
                        <span class="badge <?= in_array($permit['status'],['approved_accounting','approved_registrar','fully_approved'],true)?'bg-success':'bg-light text-dark border' ?>">Accounting <?= in_array($permit['status'],['approved_accounting','approved_registrar','fully_approved'],true)?'✓':'·' ?></span>
                        <span class="badge <?= in_array($permit['status'],['approved_registrar','fully_approved'],true)?'bg-success':'bg-light text-dark border' ?>">Registrar ✓</span>
                        <span class="badge <?= $isFully?'bg-success':'bg-light text-dark border' ?>">Teacher <?= $isFully?'✓':'·' ?></span>
                    </dd>
                </dl>
            </div>
            <div class="col-4 text-center">
                <?php if ($qrUrl): ?>
                    <img src="<?= e($qrUrl) ?>" alt="QR verification" class="img-fluid border rounded bg-white p-1" style="width:140px;height:140px">
                    <div class="small font-monospace text-muted mt-1" style="font-size:.65rem;word-break:break-all"><?= e($qrToken) ?></div>
                <?php endif; ?>
                <div class="small text-muted mt-2">Scan to verify at <br><?= e(url('/verify-permit/'.$qrToken)) ?></div>
            </div>
        </div>
        <hr>
        <div class="d-flex justify-content-between align-items-center small">
            <div class="text-muted"><i class="bi bi-shield-check"></i> Valid only when <span class="fw-bold text-success">Fully Approved</span> and stamped. Present at examination gate.</div>
            <div><?= e(date('M j, Y', strtotime($permit['requested_at']))) ?></div>
        </div>
        <div class="mt-3 d-flex gap-2 no-print">
            <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
            <a href="<?= e(url('/dashboard')) ?>" class="btn btn-outline-secondary">Back to portal</a>
            <?php if (!$isFully): ?><span class="ms-auto small text-warning">Not yet valid for entry</span><?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
