<?php
/**
 * ACCOUNTING — finance workspace.
 * Distinct focus: money in, money out and money owed. The headline reports
 * the outstanding figure first because that is the number finance owns.
 */
/** @var array $stats @var float $outstanding @var float $monthPaid @var array $recentPayments @var array $enrollQueue */
$outstandingFloat = (float) ($outstanding ?? 0);
$accHeadline = $outstandingFloat > 0
    ? money($outstandingFloat) . ' is still outstanding across student accounts'
    : 'All student accounts are settled';
?>
<?= edu_page_header([
    'eyebrow'     => 'Finance & collections',
    'title'       => 'Accounting dashboard',
    'description' => $accHeadline . '. ' . e(date('l, F j, Y')),
    'actions'     => '<a class="btn btn-light btn-sm" href="' . e(url('/accounting/students')) . '"><i class="bi bi-wallet2 me-1"></i> Student accounts</a>'
                   . '<a class="btn btn-primary btn-sm" href="' . e(url('/accounting/payments')) . '"><i class="bi bi-credit-card me-1"></i> Review payments</a>',
]) ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Outstanding balances', 'value' => '<span style="font-size:1.35rem">' . money($outstandingFloat) . '</span>', 'icon' => 'bi-cash-stack', 'tone' => $outstandingFloat > 0 ? 'danger' : 'success', 'meta' => '<span><span data-live-count="pending_payments">' . e(number_format((float)($stats['pending_payments']??0))) . '</span> payments pending</span>']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Collected this month', 'value' => '<span style="font-size:1.35rem">' . money($monthPaid ?? 0) . '</span>', 'icon' => 'bi-graph-up-arrow', 'tone' => 'success', 'meta' => '<span><span data-live-count="pending_enroll">' . e(number_format((float)($stats['pending_enroll']??0))) . '</span> enrollments pending</span>']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Pending fin. clearance', 'value' => '<span data-live-count="pending_clearance">' . e(number_format((float)($stats['pending_clearance']??0))) . '</span>', 'icon' => 'bi-check2-square', 'tone' => 'warning', 'href' => url('/accounting/clearances')]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Cleared', 'value' => e(number_format((float)($stats['cleared']??0))), 'icon' => 'bi-patch-check-fill', 'tone' => 'info', 'href' => url('/accounting/clearances?status=cleared')]) ?></div>
</div>
<div class="row g-3">
<div class="col-12 col-xl-6"><div class="card p-3 h-100"><div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Enrollment transactions (finance)</h2><a class="small" href="<?= e(url('/accounting/enrollments')) ?>">All &rarr;</a></div>
<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>App</th><th>Applicant</th><th>Status</th></tr></thead><tbody>
<?php if(($enrollQueue??[])===[]): ?><tr><td colspan="3" class="text-center text-muted py-3">Queue clear.</td></tr><?php endif; ?>
<?php foreach(($enrollQueue??[]) as $e): ?><tr><td class="fw-semibold"><?= e($e['application_no']) ?></td><td><?= e($e['applicant']??'') ?></td><td><span class="badge text-bg-secondary"><?= e(str_replace('_',' ',$e['status'])) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
<div class="col-12 col-xl-6"><div class="card p-3 h-100"><div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Recent payments</h2><a class="small" href="<?= e(url('/accounting/payments')) ?>">All &rarr;</a></div>
<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Ref</th><th>Amount</th><th>Status</th></tr></thead><tbody>
<?php if(($recentPayments??[])===[]): ?><tr><td colspan="3" class="text-center text-muted py-3">No transactions.</td></tr><?php endif; ?>
<?php foreach(($recentPayments??[]) as $p): ?><tr><td class="fw-semibold"><?= e($p['transaction_ref']) ?></td><td><?= money($p['amount']) ?></td><td><span class="badge text-bg-<?= $p['status']==='paid'?'success':'warning' ?>"><?= e($p['status']) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
</div>
