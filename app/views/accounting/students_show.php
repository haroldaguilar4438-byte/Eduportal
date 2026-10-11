<?php
/**
 * Accounting: student ledger (spec §32).
 *
 * Non-destructive: the outstanding balance still uses money(), the
 * cleared/has-balance threshold ($balance <= 0.01) and tone are unchanged, the
 * charges table keeps description/fee/charge-date/amount, the payments table
 * keeps ref/receipt/date/amount/paid-vs-warning status, and the Back link plus
 * the $student / $charges / $payments / $allocations / $balance contract are
 * preserved. Read-only; only presentation changed.
 *
 * @var array $student
 * @var array $charges
 * @var array $payments
 * @var array $allocations
 * @var float $balance
 */
$student  = $student ?? [];
$charges  = $charges ?? [];
$payments = $payments ?? [];
$balance  = (float) ($balance ?? 0);
$cleared  = $balance <= 0.01;
$fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
?>

<?= edu_breadcrumb([
    ['label' => 'Accounts', 'url' => url('/accounting/students')],
    ['label' => $student['student_no'] ?? ('#' . $student['id'])],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Financial account',
    'title'       => 'Account — ' . ($student['student_no'] ?? ('#' . $student['id'])),
    'description' => $fullName . ' · ' . ($student['level_name'] ?? '') . ' ' . ($student['section_name'] ?? ''),
    'icon'        => 'bi-journal-text',
    'actions'     => edu_badge($cleared ? 'Cleared balance' : 'Has balance', $cleared ? 'success' : 'warning', $cleared ? 'bi-check-circle' : 'bi-exclamation-circle'),
]) ?>

<!-- Identity + balance -->
<div class="edu-card mb-3">
    <div class="edu-card__body">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="fw-semibold"><?= e($fullName) ?></div>
                <div class="small text-muted">
                    <?= e($student['email'] ?? '') ?> · <?= e($student['level_name'] ?? '') ?> <?= e($student['section_name'] ?? '') ?>
                </div>
            </div>
            <div class="col-md-6 text-md-end">
                <span class="text-muted small">Outstanding</span>
                <div class="fw-bold" style="font-size:1.5rem;line-height:1.2"><?= money($balance) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Charges -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-receipt"></i> Assessments / charges</h2>
                <?= edu_badge((string) count($charges), 'neutral') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($charges === []): ?>
                    <?= edu_empty_state('No charges', 'Nothing has been assessed to this account.', ['icon' => 'bi-receipt', 'compact' => true]) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Description</th><th class="text-end">Amount</th></tr></thead>
                            <tbody>
                            <?php foreach ($charges as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($c['description']) ?></strong>
                                        <div class="small text-muted"><?= e($c['fee_name'] ?? '') ?> · <?= e($c['charge_date']) ?></div>
                                    </td>
                                    <td class="text-end fw-semibold"><?= money($c['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payments -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-credit-card"></i> Payments</h2>
                <?= edu_badge((string) count($payments), 'neutral') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($payments === []): ?>
                    <?= edu_empty_state('No payments', 'No payments recorded for this account.', ['icon' => 'bi-credit-card', 'compact' => true]) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Reference</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold" style="font-family:var(--font-mono);font-size:.82rem"><?= e($p['transaction_ref']) ?></span>
                                        <div class="small text-muted">
                                            <?= e($p['receipt_no'] ?? '') ?> <?= e(date('M j, Y', strtotime($p['created_at']))) ?>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold"><?= money($p['amount']) ?></td>
                                    <td><?= edu_status_badge((string) $p['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
