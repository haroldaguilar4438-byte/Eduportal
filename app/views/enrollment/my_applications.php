<?php /** @var array $applications */ $permanentStudentNo = $permanentStudentNo ?? null; $reusedDocs = $reusedDocs ?? []; $childNumbers = $childNumbers ?? []; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-collection"></i> My Enrollment Applications</h1>
    <div class="d-flex gap-2">
        <a href="<?= e(url('/student/enrollment-docs')) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-folder-check"></i> My Documents</a>
        <a href="<?= e(url('/enroll')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New Application</a>
    </div>
</div>
<?php if (!empty($childNumbers)): ?>
<div class="alert alert-primary d-flex align-items-center gap-2 py-2 small flex-wrap"><i class="bi bi-person-badge fs-5"></i><div>Permanent Student Numbers — same number every term, read-only: <?php foreach ($childNumbers as $cn): ?><span class="badge text-bg-light border me-1"><?= e($cn['name']) ?>: <strong class="font-monospace"><?= e($cn['no']) ?></strong></span><?php endforeach; ?></div></div>
<?php elseif (!empty($permanentStudentNo)): ?>
<div class="alert alert-primary d-flex align-items-center gap-2 py-2 small"><i class="bi bi-person-badge fs-5"></i><div>Your permanent Student Number: <strong class="font-monospace" style="letter-spacing:.06em"><?= e($permanentStudentNo) ?></strong> — same number every semester, read-only. It appears automatically on all enrollment forms.</div></div>
<?php endif; ?>

<?php if ($applications === []): ?>
    <div class="card p-5 text-center">
        <div style="font-size:2.2rem;color:#94a3b8;"><i class="bi bi-inbox"></i></div>
        <h2 class="h6 mt-2">No applications yet</h2>
        <p class="text-muted small">Start your enrollment for SY 2026-2027. It takes about 5 minutes.</p>
        <a href="<?= e(url('/enroll')) ?>" class="btn btn-primary">Apply Now</a>
    </div>
<?php else: ?>
    <div class="card p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Application No.</th><th>Level</th><th>Period</th><th>Program</th><th>Type</th><th>Student No.</th><th>Campus</th><th>School Year</th><th>Status</th><th>Form</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($applications as $a):
                    $badge = match($a['status']) {
                        'approved' => 'success',
                        'awaiting_payment' => 'warning',
                        'accounting_approved' => 'info',
                        'rejected' => 'danger',
                        'action_required' => 'warning',
                        default => 'secondary',
                    };
                    $statusLabel = match($a['status']) {
                        'action_required' => 'Action Required',
                        'awaiting_payment' => 'Awaiting Payment',
                        'accounting_approved' => 'Accounting Approved',
                        default => ucfirst(str_replace('_',' ', $a['status'])),
                    };
                    $typeLabel = match($a['new_or_returning'] ?? 'new') {
                        'returning' => 'Returning',
                        'transferee' => 'Transferee',
                        default => 'New',
                    };
                ?>
                    <tr>
                        <td class="fw-semibold"><?= e($a['application_no']) ?>
                            <?php $kidName = trim(trim((string) ($a['child_first'] ?? '')) . ' ' . trim((string) ($a['child_last'] ?? ''))); ?>
                            <?php if ($kidName !== ''): ?><div><span class="badge bg-primary bg-opacity-10 text-primary border"><i class="bi bi-person"></i> <?= e($kidName) ?></span></div><?php endif; ?>
                        </td>
                        <td><?= e($a['level_name']) ?> <span class="text-muted small">(<?= e($a['level_code']) ?>)</span></td>
                        <?php $appPeriod = trim($a['period_code'] ?? ''); if ($appPeriod === '') $appPeriod = trim($a['semester'] ?? ''); if (in_array($appPeriod, ['1st','2nd'], true)) $appPeriod .= ' Semester'; ?><td><?= $appPeriod !== '' ? '<span class="badge text-bg-light border">'.e($appPeriod).'</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $a['program_name'] ? e($a['program_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td><span class="badge text-bg-light border"><?= e($typeLabel) ?></span></td>
                        <td><?= !empty($a['returning_student_no']) ? '<span class="fw-semibold">' . e($a['returning_student_no']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?php if (!empty($a['campus_name'])): ?><span class="badge text-bg-primary"><i class="bi bi-geo-alt"></i> <?= e($a['campus_name']) ?></span><div class="small text-muted"><?= e($a['campus_address']) ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                        <td><?= e($a['school_year']) ?></td>
                        <td>
                            <span class="badge text-bg-<?= $badge ?>"><?= e($statusLabel) ?></span>
                            <?php $ps = $a['plan_summary'] ?? null; ?>
                            <?php if (is_array($ps) && !empty($ps['has_plan']) && !empty($ps['plan_type'])): ?>
                                <div class="mt-1"><span class="badge <?= $ps['plan_type'] === 'monthly' ? 'bg-info text-dark' : 'bg-success' ?>"><?= $ps['plan_type'] === 'monthly' ? 'Monthly' : 'Full' ?></span>
                                <span class="small text-muted"><?= money($ps['paid']) ?> / <?= money($ps['total']) ?></span></div>
                                <div class="small <?= ($ps['remaining'] ?? 0) > 0 ? 'text-danger fw-semibold' : 'text-success' ?>">Left <?= money($ps['remaining'] ?? 0) ?><?= $ps['status'] !== '' ? ' · ' . e($ps['status']) : '' ?></div>
                                <?php if (!empty($ps['next']) && ($ps['remaining'] ?? 0) > 0): ?><div class="small text-muted">Next <?= money($ps['next']['amount']) ?> due <?= e(date('M j, Y', strtotime($ps['next']['due_date']))) ?></div><?php endif; ?>
                            <?php endif; ?>
                            <?php
                            $steps = ['Submitted', 'Documents', 'Accounting', 'Registrar', 'Payment', 'Confirmed', 'Enrolled'];
                            $stage = match ($a['status']) {
                                'pending' => 1, 'action_required' => 1, 'rejected' => -1,
                                'accounting_approved' => 3, 'awaiting_payment' => 4, 'approved' => 6, default => 0,
                            };
                            $paidHint = in_array(($a['pay_status'] ?? ''), ['paid', 'processing'], true);
                            if ($stage === 4 && $paidHint) $stage = 5;
                            ?>
                            <?php if ($stage >= 0): ?>
                            <div class="mt-2" style="min-width:180px">
                                <div class="d-flex align-items-center" aria-label="Application progress">
                                <?php foreach ($steps as $i => $s): $n = $i + 1; $done = $n <= $stage; $cur = $n === $stage + 1; ?>
                                    <div class="rounded-circle <?= $done ? 'bg-success' : ($cur ? 'bg-warning' : 'bg-light border') ?>" title="<?= e($s) ?>" style="width:12px;height:12px;<?= $done || $cur ? '' : 'opacity:.7' ?>"></div>
                                    <?php if ($n < count($steps)): ?><div class="flex-fill <?= $n < $stage ? 'bg-success' : 'bg-light border-top' ?>" style="height:2px"></div><?php endif; ?>
                                <?php endforeach; ?>
                                </div>
                                <div class="small text-muted mt-1"><?= e($stage >= count($steps) ? 'Enrolled' : ('Next: ' . $steps[min($stage, count($steps) - 1)])) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($a['status']==='approved' && ($a['clr_status']??'')==='issued' && ($a['clr_financial']??'')==='cleared' && ($a['clr_registrar']??'pending')==='approved'): ?><div class="mt-1"><span class="badge bg-success"><i class="bi bi-check-circle"></i> Period completed</span></div><?php endif; ?>
                            <?php if (!empty($a['remarks']) && in_array($a['status'], ['rejected','action_required'], true)): ?><div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> <?= e($a['remarks']) ?></div><?php endif; ?>
                            <?php if ($a['status']==='awaiting_payment'): ?>
                                <?php if (($a['pay_status'] ?? '')==='processing'): ?>
                                    <div class="mt-1"><span class="badge bg-info"><i class="bi bi-hourglass-split"></i> Payment submitted — awaiting admin approval</span></div>
                                    <div class="small text-muted">Ref <?= e($a['transaction_ref'] ?? '') ?> · Admin will verify & send receipt.</div>
                                <?php elseif (($a['pay_status'] ?? '')==='paid'): ?>
                                    <div class="mt-1"><span class="badge bg-success"><i class="bi bi-check-circle"></i> Paid — receipt sent</span></div>
                                <?php elseif (($a['pay_status'] ?? '')==='failed'): ?>
                                    <div class="mt-1"><span class="badge bg-danger"><i class="bi bi-x-circle"></i> Payment rejected — see remarks</span></div>
                                <?php else: ?>
                                    <?php
                                    // Button must show the real amount: stored amount_due,
                                    // else the plan-aware tuition total — never the old default.
                                    $btnAmount = isset($a['amount_due']) && (float) $a['amount_due'] > 0
                                        ? (float) $a['amount_due']
                                        : (float) (($a['plan_summary']['total'] ?? 0) > 0 ? $a['plan_summary']['total'] : \App\Core\Tuition::total());
                                    ?>
                                    <div class="mt-1"><a href="<?= e(url('/enroll/' . $a['id'] . '/pay')) ?>" class="btn btn-sm btn-success"><i class="bi bi-credit-card"></i> Pay Now <?= money($btnAmount) ?> via PayRex</a></div>
                                <?php endif; ?>
                                <?php if (!empty($a['pay_status'])): ?><div class="small text-muted">Pay status: <?= e($a['pay_status']) ?><?php if (!empty($a['transaction_ref'])): ?> · <?= e($a['transaction_ref']) ?><?php endif; ?></div><?php endif; ?>
                            <?php elseif (($a['status'] ?? '') === 'approved' && (float) (($a['plan_summary']['remaining'] ?? 0)) > 0.009): ?>
                                <div class="mt-1"><a href="<?= e(url('/enroll/' . (int)$a['id'] . '/pay')) ?>" class="btn btn-sm btn-success"><i class="bi bi-credit-card"></i> Pay balance <?= money((float) $a['plan_summary']['remaining']) ?> via PayRex</a></div>
                                <div class="small text-muted">Next due on schedule — or settle the full balance early.</div>
                            <?php elseif (($a['pay_status'] ?? '')==='failed'): ?>
                                <div class="mt-1"><span class="badge bg-danger"><i class="bi bi-x-circle"></i> Payment failed</span></div>
                                <div class="small text-muted">Pay status: failed<?php if (!empty($a['transaction_ref'])): ?> · <?= e($a['transaction_ref']) ?><?php endif; ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php if (($a['status'] ?? '') === 'approved'): ?><a href="<?= e(url('/enroll/' . (int)$a['id'] . '/form')) ?>" target="_blank" class="btn btn-sm btn-success"><i class="bi bi-printer"></i> Form</a><?php else: ?><span class="text-muted small">After approval</span><?php endif; ?></td>
                        <td class="text-muted small"><?= e(date('M j, Y', strtotime($a['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> Status updates appear here after the registrar reviews your documents.</div>
<?php endif; ?>