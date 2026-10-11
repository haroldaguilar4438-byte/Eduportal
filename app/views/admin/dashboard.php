<?php
/**
 * Admin dashboard.
 * @var array $stats @var float $monthPaid @var int $pendingPayments
 * @var array $recentEnrollments @var array $recentPayments @var array $announcements
 * @var array $analytics School analytics payload (filters/options/data)
 */
$analytics = $analytics ?? ['filters' => [], 'options' => [], 'data' => []];
$af = $analytics['filters'] ?? [];
$ao = $analytics['options'] ?? [];
$ad = $analytics['data'] ?? [];
$av = static function ($v): string {
    return $v === null ? '<span class="badge text-bg-warning">unavailable</span>' : e(number_format((float) $v));
};
$scopeLbl = trim(($af['ay_label'] ?? '') . ' ' . ($af['period'] ?? ''));
if ($scopeLbl === '') $scopeLbl = 'All periods';
?>
<?php
/**
 * ADMIN — system control centre.
 * Distinct from every other role: leads with operational pressure (queues
 * needing a decision) rather than academics or finance.
 */
$pendingOps = (float) ($stats['pending_enrollments'] ?? 0)
            + (float) ($pendingPayments ?? 0);
$adminHeadline = $pendingOps > 0
    ? e(number_format($pendingOps)) . ' item' . ($pendingOps == 1 ? '' : 's') . ' waiting on a decision'
    : 'No operational queues are waiting on you';
?>
<?= edu_page_header([
    'eyebrow'     => 'System control centre',
    'title'       => 'Admin dashboard',
    'description' => $adminHeadline . '. ' . e($scopeLbl) . ' · ' . e(date('l, F j, Y')),
    'actions'     => '<a class="btn btn-light btn-sm" href="' . e(url('/admin/content')) . '"><i class="bi bi-layout-text-window me-1"></i> Content</a>'
                   . '<a class="btn btn-light btn-sm" href="' . e(url('/admin/blog/create')) . '"><i class="bi bi-plus-lg me-1"></i> Post</a>'
                   . '<a class="btn btn-accent btn-sm" href="' . e(url('/admin/announcements/create')) . '"><i class="bi bi-megaphone me-1"></i> Announce</a>',
]) ?>

<?php include __DIR__ . '/../shared/partials/announcement_banner.php'; ?>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted">Pending enrollments</div>
                    <h2 data-live-count="pending_enrollments"><?= e(number_format((float) $stats['pending_enrollments'])) ?></h2>
                </div>
                <span class="stat-icon bg-warning-subtle text-warning-emphasis"><i class="bi bi-hourglass-split"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted">Enrolled students</div>
                    <h2><?= e(number_format((float) $stats['students_enrolled'])) ?></h2>
                </div>
                <span class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted">Collected this month</div>
                    <h2 style="font-size:1.25rem"><?= money($monthPaid) ?></h2>
                </div>
                <span class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-stack"></i></span>
            </div>
            <div class="text-muted small mt-1">
                <span data-live-count="pending_payments"><?= e(number_format($pendingPayments)) ?></span> payment(s) awaiting confirmation
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted">At-risk students</div>
                    <h2><?= e(number_format((float) $stats['open_risk_flags'])) ?></h2>
                </div>
                <span class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-triangle"></i></span>
            </div>
            <div class="text-muted small mt-1">Open moderate/high flags</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Recent enrollment applications -->
    <div class="col-12 col-xl-7">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0"><i class="bi bi-journal-plus"></i> Latest enrollment applications</h2>
                <a href="<?= e(url('/admin/enrollments')) ?>" class="small">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr><th>App No.</th><th>Applicant</th><th>Level</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($recentEnrollments === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No applications yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentEnrollments as $r):
                        $badge = match ($r['status']) {
                            'approved'        => 'success',
                            'rejected'        => 'danger',
                            'action_required' => 'warning',
                            default           => 'secondary',
                        }; ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['application_no']) ?></td>
                            <td><?= e($r['applicant'] ?? '(no account)') ?></td>
                            <td><?= e($r['level_name']) ?></td>
                            <td><span class="badge text-bg-<?= $badge ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Announcements -->
    <div class="col-12 col-xl-5">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0"><i class="bi bi-megaphone"></i> Announcements</h2>
                <a href="<?= e(url('/admin/announcements')) ?>" class="small">Manage</a>
            </div>
            <?php if ($announcements === []): ?>
                <p class="text-muted small mb-0 py-4 text-center">Nothing posted yet.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($announcements as $a): ?>
                        <li class="list-group-item px-0">
                            <div class="fw-semibold"><?= e($a['title']) ?></div>
                            <div class="small text-muted"><?= e($a['excerpt']) ?>&hellip;</div>
                            <div class="small text-secondary"><i class="bi bi-clock"></i> <?= e(date('M j, Y', strtotime($a['publish_at']))) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent payments -->
<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-receipt"></i> Recent payment transactions</h2>
        <a href="<?= e(url('/admin/payments')) ?>" class="small">Payment reports</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Reference</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php if ($recentPayments === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No transactions yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentPayments as $p):
                $badge = match ($p['status']) {
                    'paid'      => 'success',
                    'failed'    => 'danger',
                    'refunded'  => 'info',
                    'expired',
                    'cancelled' => 'secondary',
                    default     => 'warning',   // pending / processing
                }; ?>
                <tr>
                    <td class="fw-semibold"><?= e($p['transaction_ref']) ?></td>
                    <td><?= money($p['amount']) ?></td>
                    <td><?= e(ucfirst($p['payment_method'])) ?><?= $p['channel'] === 'counter' ? ' <span class="badge text-bg-light">counter</span>' : '' ?></td>
                    <td><span class="badge text-bg-<?= $badge ?>"><?= e($p['status']) ?></span></td>
                    <td class="text-muted small"><?= e(date('M j, Y g:ia', strtotime($p['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== School Analytics (read-only, real data) ==================== -->
<?php $ov = $ad['overview'] ?? []; $en = $ad['enrollment'] ?? []; $rg = $ad['registration'] ?? []; $us = $ad['users'] ?? []; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3 gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-graph-up-arrow"></i> School Analytics <span class="badge text-bg-light border fw-normal"><?= e($scopeLbl) ?></span></h2>
    <div class="d-flex gap-2">
        <span id="analyticsError" class="badge text-bg-danger" hidden>Analytics could not be loaded. Please try again.</span>
        <button type="button" id="refreshAnalytics" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Refresh Analytics</button>
    </div>
</div>
<p class="text-muted small mt-n2 mb-3">Read-only aggregates from live records. “Selected period” cards follow the filters; trend covers the trailing 6 months; operational snapshots are all-time.</p>

<!-- Filters -->
<div class="card p-3 mb-3">
    <form method="get" action="<?= e(url('/dashboard')) ?>" class="row g-2 align-items-end" id="analyticsFilters">
        <div class="col-6 col-md-3"><label for="an_ay" class="form-label small fw-semibold">School Year</label><select id="an_ay" name="ay_id" class="form-select form-select-sm"><option value="">All years</option><?php foreach (($ao['years'] ?? []) as $y): ?><option value="<?= (int) $y['id'] ?>" <?= ((int) ($af['ay_id'] ?? 0) === (int) $y['id']) ? 'selected' : '' ?>><?= e($y['school_year']) ?><?= !empty($y['is_current']) ? ' (current)' : '' ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label for="an_period" class="form-label small fw-semibold">Period</label><select id="an_period" name="period" class="form-select form-select-sm"><option value="">All periods</option><?php foreach (($ao['periods'] ?? []) as $code => $name): ?><option value="<?= e($code) ?>" <?= (($af['period'] ?? '') === (string) $code) ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label for="an_band" class="form-label small fw-semibold">Education Level</label><select id="an_band" name="band" class="form-select form-select-sm"><option value="">All levels</option><?php foreach (['basic' => 'Basic Education', 'shs' => 'Senior High', 'college' => 'College'] as $b => $bl): ?><option value="<?= $b ?>" <?= (($af['band'] ?? '') === $b) ? 'selected' : '' ?>><?= $bl ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label for="an_prog" class="form-label small fw-semibold">Program</label><select id="an_prog" name="program_id" class="form-select form-select-sm"><option value="">All programs</option><?php foreach (($ao['programs'] ?? []) as $p): ?><option value="<?= (int) $p['id'] ?>" <?= ((int) ($af['program_id'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>><?= e(($p['code'] ? $p['code'] . ' — ' : '') . $p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill">Apply</button><a href="<?= e(url('/dashboard')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a></div>
    </form>
</div>

<!-- System overview cards -->
<div class="row g-3 mb-4" id="analyticsCards">
    <?php $cards = [
        ['total_students', 'Total Students', 'Student records in the system (all time).', 'bi-people', 'primary'],
        ['enrolled', 'Currently Enrolled', 'Distinct students with an approved enrollment in the selected period.', 'bi-patch-check', 'success'],
        ['enrolling', 'Currently Enrolling', 'Applications in progress (pending / accounting-approved / awaiting payment / action-required) in the selected period.', 'bi-hourglass-split', 'warning'],
        ['registered', 'Registered', 'Verified student + parent accounts (distinct from enrolled).', 'bi-person-check', 'info'],
        ['transferred', 'Transferred', 'Student records with transferred status (all time).', 'bi-arrow-left-right', 'secondary'],
        ['active_users', 'Active Users', 'Accounts marked active (not “currently online”).', 'bi-circle-fill', 'success'],
        ['inactive_users', 'Inactive Users', 'Accounts not active (pending, suspended, archived…).', 'bi-circle', 'dark'],
    ]; ?>
    <?php foreach ($cards as $c): ?>
    <div class="col-6 col-lg-3">
        <div class="card stat-card p-3 h-100" title="<?= e($c[2]) ?>">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted"><?= e($c[1]) ?></div>
                    <h2 data-analytic="overview.<?= e($c[0]) ?>"><?= $av($ov[$c[0]] ?? null) ?></h2>
                </div>
                <span class="stat-icon bg-<?= e($c[4]) ?>-subtle text-<?= e($c[4]) ?>"><i class="bi <?= e($c[3]) ?>"></i></span>
            </div>
            <div class="text-muted small mt-1"><?= e($c[2]) ?></div>
            <?php if ($c[0] === 'transferred'): ?><div class="small mt-1">Admitted as transferee (selected period): <strong data-analytic="overview.transferee_admissions"><?= $av($ov['transferee_admissions'] ?? null) ?></strong></div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-6">
        <div class="card p-3 h-100">
            <h3 class="h6 mb-1"><i class="bi bi-activity"></i> Enrollment Trend <span class="fw-normal text-muted">— trailing 6 months</span></h3>
            <?php $trend = $en['trend'] ?? []; $trendSum = 0; foreach ($trend as $t) $trendSum += (int) ($t['c'] ?? 0); ?>
            <?php if ($trendSum <= 0): ?><p class="text-muted small mb-0 py-4 text-center">No data available for the selected period.</p>
            <?php else: ?><canvas id="anTrend" height="180" role="img" aria-label="Enrollment applications per month, trailing 6 months"></canvas><ul class="small text-muted mt-2 mb-0" id="anTrendList"><?php foreach ($trend as $t): ?><li><?= e($t['label']) ?>: <strong><?= e(number_format((int) $t['c'])) ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card p-3 h-100">
            <h3 class="h6 mb-1"><i class="bi bi-pie-chart"></i> Enrollment Status <span class="fw-normal text-muted">— selected period</span></h3>
            <?php $bst = $en['by_status'] ?? []; ?>
            <?php if ($bst === []): ?><p class="text-muted small mb-0 py-4 text-center">No data available for the selected period.</p>
            <?php else: ?><canvas id="anStatus" height="180" role="img" aria-label="Enrollment applications by status"></canvas><ul class="small text-muted mt-2 mb-0"><?php foreach ($bst as $s): ?><li><?= e(str_replace('_', ' ', $s['status'])) ?>: <strong><?= e(number_format((int) $s['c'])) ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card p-3 h-100">
            <h3 class="h6 mb-1"><i class="bi bi-people"></i> User Accounts by Role <span class="fw-normal text-muted">— all time</span></h3>
            <?php $broles = $rg['by_role'] ?? []; ?>
            <?php if ($broles === []): ?><p class="text-muted small mb-0 py-4 text-center">No data available.</p>
            <?php else: ?><canvas id="anRoles" height="200" role="img" aria-label="User accounts by role"></canvas><ul class="small text-muted mt-2 mb-0"><?php foreach ($broles as $r): ?><li><?= e(ucfirst($r['role'])) ?>: <strong><?= e(number_format((int) $r['c'])) ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card p-3 h-100">
            <h3 class="h6 mb-1"><i class="bi bi-mortarboard"></i> Enrollment by Program <span class="fw-normal text-muted">— selected period</span></h3>
            <?php $progs = $ad['programs'] ?? []; $progSum = 0; foreach ($progs as $p) $progSum += (int) ($p['enrolled'] ?? 0); ?>
            <?php if ($progSum <= 0): ?><p class="text-muted small mb-0 py-4 text-center">No data available for the selected period.</p>
            <?php else: ?><canvas id="anPrograms" height="200" role="img" aria-label="Enrolled students by program"></canvas><ul class="small text-muted mt-2 mb-0"><?php foreach ($progs as $p): ?><li><?= e($p['code'] ?: $p['name']) ?>: <strong><?= e(number_format((int) $p['enrolled'])) ?></strong> enrolled<?= ((int) ($p['enrolling'] ?? 0) > 0) ? ' · ' . e(number_format((int) $p['enrolling'])) . ' enrolling' : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card p-3 h-100">
            <h3 class="h6 mb-1"><i class="bi bi-bar-chart-steps"></i> Enrollment by Level <span class="fw-normal text-muted">— selected period</span></h3>
            <?php $lvls = ($ad['levels'] ?? [])['rows'] ?? []; $lvlSum = 0; foreach ($lvls as $l) $lvlSum += (int) ($l['enrolled'] ?? 0); $bands = ($ad['levels'] ?? [])['bands'] ?? []; ?>
            <?php if ($lvlSum <= 0): ?><p class="text-muted small mb-0 py-4 text-center">No data available for the selected period.</p>
            <?php else: ?><canvas id="anLevels" height="200" role="img" aria-label="Enrolled students by year level"></canvas>
            <ul class="small text-muted mt-2 mb-0"><li>Basic Education: <strong><?= e(number_format((int) ($bands['basic'] ?? 0))) ?></strong></li><li>Senior High: <strong><?= e(number_format((int) ($bands['shs'] ?? 0))) ?></strong></li><li>College: <strong><?= e(number_format((int) ($bands['college'] ?? 0))) ?></strong></li></ul><?php endif; ?>
        </div>
    </div>
</div>

<!-- Operations + activity -->
<?php $cl = $ad['clearance'] ?? []; $dc = $ad['documents'] ?? []; $py = $ad['payments'] ?? []; $gr = $ad['grades'] ?? []; $kl = $ad['classes'] ?? []; ?>
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-4">
        <div class="card p-3 h-100">
            <h3 class="h6 mb-2"><i class="bi bi-patch-check"></i> Clearance <span class="fw-normal text-muted">— selected period</span></h3>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between px-0">Pending signatures <strong data-analytic="clearance.pending"><?= $av($cl['pending'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Issued <strong><?= $av($cl['issued'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Teacher leg pending <strong><?= $av($cl['teachers_pending'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Accounting leg pending <strong><?= $av($cl['accounting_pending'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Registrar leg pending <strong><?= $av($cl['registrar_pending'] ?? null) ?></strong></li>
            </ul>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card p-3 h-100">
            <h3 class="h6 mb-2"><i class="bi bi-folder-check"></i> Documents &amp; Grades <span class="fw-normal text-muted">— all time</span></h3>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between px-0">Document requests pending <strong><?= $av($dc['pending_review'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Verified files on record <strong><?= $av($dc['verified_files'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Resubmissions requested <strong><?= $av($dc['needs_resub'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Grade sheets awaiting review <strong><?= $av($gr['pending_review'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Active subjects / unassigned <strong><?= $av($kl['active'] ?? null) ?> / <?= $av($kl['unassigned'] ?? null) ?></strong></li>
            </ul>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card p-3 h-100">
            <h3 class="h6 mb-2"><i class="bi bi-cash-coin"></i> Payments &amp; Activity <span class="fw-normal text-muted">— all time</span></h3>
            <ul class="list-group list-group-flush small">
<?php $pendPay = 0; $pendPayFound = false; foreach (($py['by_status'] ?? []) as $ps) { if (in_array($ps['status'], ['pending', 'processing'], true)) { $pendPay += (int) $ps['c']; $pendPayFound = true; } } ?>
                <li class="list-group-item d-flex justify-content-between px-0">Payments awaiting confirmation <strong data-analytic="payments.pending"><?= $pendPayFound ? e(number_format($pendPay)) : $av(null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Collected this month <strong><?= ($py['month_paid'] ?? null) === null ? $av(null) : '&#8369;' . e(number_format((float) $py['month_paid'], 2)) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Logins today / 7d / 30d <strong><?= $av($us['logins_today'] ?? null) ?> / <?= $av($us['active_7d'] ?? null) ?> / <?= $av($us['active_30d'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">Verified / pending verification <strong><?= $av($rg['verified'] ?? null) ?> / <?= $av($rg['pending_verify'] ?? null) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0">New accounts (30d) <strong><?= $av($rg['new_30d'] ?? null) ?></strong></li>
            </ul>
        </div>
    </div>
</div>

<div class="row g-3 mb-2">
    <div class="col-12 col-xl-6">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h6 mb-0"><i class="bi bi-exclamation-circle"></i> Needs Attention</h3></div>
            <?php $att = $ad['attention'] ?? []; ?>
            <?php if ($att === []): ?><p class="text-muted small mb-0 py-3 text-center">Nothing needs attention right now.</p>
            <?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($att as $a): ?><li class="list-group-item px-0 d-flex justify-content-between align-items-center"><a href="<?= e(url($a['link'])) ?>"><?= e($a['label']) ?></a><span class="badge text-bg-warning"><?= e(number_format((int) $a['count'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h6 mb-0"><i class="bi bi-clock-history"></i> Recent System Activity</h3></div>
            <?php $recent = ($ad['activity'] ?? [])['recent'] ?? []; ?>
            <?php if ($recent === []): ?><p class="text-muted small mb-0 py-3 text-center">No recorded activity yet.</p>
            <?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($recent as $ev): ?><li class="list-group-item px-0"><span class="badge text-bg-light border"><?= e(str_replace(['.', '_'], ' ', $ev['action'])) ?></span> <span class="text-muted"><?= trim((string) ($ev['actor'] ?? '')) !== '' ? 'by ' . e(trim($ev['actor'])) . ' (' . e($ev['role'] ?? 'system') . ')' : 'by system' ?> · <?= e(time_ago($ev['created_at'] ?? '')) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var payload = <?= json_encode($ad, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var charts = {};
    function num(v){ var n = Number(v); return isNaN(n) ? 0 : n; }
    function fmt(v){ return Number(v).toLocaleString('en-US'); }
    function setText(key, v){
        document.querySelectorAll('[data-analytic="' + key + '"]').forEach(function(el){
            el.textContent = (v === null || v === undefined) ? 'unavailable' : fmt(v);
        });
    }
    function draw(){
        if (typeof Chart === 'undefined') return;
        Object.values(charts).forEach(function(c){ try { c.destroy(); } catch(e){} });
        charts = {};
        var d = payload || {};
        var en = d.enrollment || {};
        var t = document.getElementById('anTrend');
        if (t && (en.trend || []).length) {
            charts.trend = new Chart(t, { type: 'line',
                data: { labels: en.trend.map(function(r){ return r.label; }), datasets: [{ label: 'Applications', data: en.trend.map(function(r){ return num(r.c); }), borderColor: '#1E3A8A', backgroundColor: 'rgba(30,58,138,.12)', fill: true, tension: .3 }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
        }
        var s = document.getElementById('anStatus');
        if (s && (en.by_status || []).length) {
            charts.status = new Chart(s, { type: 'doughnut',
                data: { labels: en.by_status.map(function(r){ return String(r.status).replace(/_/g, ' '); }), datasets: [{ data: en.by_status.map(function(r){ return num(r.c); }), backgroundColor: ['#16a34a', '#f59e0b', '#3b82f6', '#ef4444', '#94a3b8', '#8b5cf6'] }] },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } } });
        }
        var roles = ((d.registration || {}).by_role || []);
        var rl = document.getElementById('anRoles');
        if (rl && roles.length) {
            charts.roles = new Chart(rl, { type: 'doughnut',
                data: { labels: roles.map(function(r){ return r.role; }), datasets: [{ data: roles.map(function(r){ return num(r.c); }), backgroundColor: ['#1E3A8A', '#0ea5e9', '#22c55e', '#f59e0b', '#8b5cf6', '#64748b'] }] },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } } });
        }
        var pr = document.getElementById('anPrograms');
        if (pr && (d.programs || []).length) {
            charts.programs = new Chart(pr, { type: 'bar',
                data: { labels: d.programs.map(function(r){ return r.code || r.name; }), datasets: [{ label: 'Enrolled', data: d.programs.map(function(r){ return num(r.enrolled); }), backgroundColor: '#1E3A8A', borderRadius: 6 }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
        }
        var lv = document.getElementById('anLevels');
        var rows = ((d.levels || {}).rows || []);
        if (lv && rows.length) {
            charts.levels = new Chart(lv, { type: 'bar',
                data: { labels: rows.map(function(r){ return r.code; }), datasets: [{ label: 'Enrolled', data: rows.map(function(r){ return num(r.enrolled); }), backgroundColor: '#7C0F28', borderRadius: 6 }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
        }
    }
    function applyPayload(p){
        payload = p;
        var ov = p.overview || {};
        ['total_students','enrolled','enrolling','registered','transferred','transferee_admissions','active_users','inactive_users'].forEach(function(k){ setText('overview.' + k, ov[k]); });
        var cl = p.clearance || {};
        setText('clearance.pending', cl.pending);
        var pend = 0, found = false;
        ((p.payments || {}).by_status || []).forEach(function(r){ if (r.status === 'pending' || r.status === 'processing') { pend += num(r.c); found = true; } });
        setText('payments.pending', found ? pend : null);
        draw();
    }
    var errBadge = document.getElementById('analyticsError');
    var btn = document.getElementById('refreshAnalytics');
    var busy = false;
    function currentParams(){
        var f = document.getElementById('analyticsFilters');
        return f ? new URLSearchParams(new FormData(f)).toString() : '';
    }
    function refresh(showErr){
        if (busy) return;
        busy = true;
        if (btn) { btn.disabled = true; }
        fetch('<?= e(url('/admin/analytics/data')) ?>?' + currentParams(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
            .then(function(j){ if (errBadge) errBadge.hidden = true; applyPayload(j.data || {}); })
            .catch(function(){ if (showErr !== false && errBadge) errBadge.hidden = false; })
            .finally(function(){ busy = false; if (btn) { btn.disabled = false; } });
    }
    if (btn) btn.addEventListener('click', function(){ refresh(true); });
    draw();
    // Live: reuse the existing EduLive signal (no second poller). New
    // notifications about enroll/payments/etc. refresh cards + charts.
    var last = 0;
    function maybeRefresh(){
        var now = Date.now();
        if (now - last < 30000) return;
        last = now;
        refresh(false);
    }
    if (window.EduLive && EduLive.onNotification) {
        EduLive.onNotification(function(n){
            var t = ((n && n.title) || '') + ' ' + ((n && n.message) || '');
            if (/enroll|payment|clearance|permit|grade|document|verif|regist|user/i.test(t)) maybeRefresh();
        });
    }
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) maybeRefresh(); });
})();
</script>
