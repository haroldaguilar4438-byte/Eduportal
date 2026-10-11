<?php
/**
 * Student ledger — financial dashboard (spec §16).
 *
 * Non-destructive: $student, $charges, $payments, $allocations and $balance are
 * used exactly as before. The two side-by-side tables became a single running
 * statement (date, description, reference, debit, credit, balance) built by
 * folding the charges and payments the controller already provides, so the
 * running balance is real arithmetic rather than an invented number.
 *
 * @var array $student
 * @var array $charges
 * @var array $payments
 * @var array $allocations
 * @var float $balance
 */
$charges     = $charges ?? [];
$payments    = $payments ?? [];
$allocations = $allocations ?? [];
$balance     = (float) ($balance ?? 0);

$totalCharged  = 0.0;
foreach ($charges as $c) {
    $totalCharged += (float) ($c['amount'] ?? 0);
}
$totalPaid = 0.0;
$paidCount = 0;
foreach ($payments as $p) {
    if ((string) ($p['status'] ?? '') === 'paid') {
        $totalPaid += (float) ($p['amount'] ?? 0);
        $paidCount++;
    }
}

/* Build one chronological statement. */
$entries = [];
foreach ($charges as $c) {
    $entries[] = [
        'ts'    => strtotime((string) ($c['charge_date'] ?? $c['created_at'] ?? '')) ?: 0,
        'seq'   => 0, /* charges list before payments on the same day */
        'date'  => (string) ($c['charge_date'] ?? $c['created_at'] ?? ''),
        'desc'  => (string) ($c['description'] ?? 'Charge'),
        'sub'   => (string) ($c['fee_name'] ?? ''),
        'ref'   => '',
        'debit' => (float) ($c['amount'] ?? 0),
        'credit'=> 0.0,
        'status'=> null,
    ];
}
foreach ($payments as $p) {
    $entries[] = [
        'ts'    => strtotime((string) ($p['created_at'] ?? '')) ?: 0,
        'seq'   => 1,
        'date'  => (string) ($p['created_at'] ?? ''),
        'desc'  => 'Payment received',
        'sub'   => trim((string) ($p['payment_method'] ?? '')),
        'ref'   => (string) ($p['transaction_ref'] ?? ''),
        'debit' => 0.0,
        'credit'=> (float) ($p['amount'] ?? 0),
        'status'=> (string) ($p['status'] ?? ''),
    ];
}
/* Chronological; charges before credits on the same date so a same-day
   payment is never shown as though it predates the charge it settles. */
usort($entries, static fn ($a, $b) => ($a['ts'] <=> $b['ts']) ?: ($a['seq'] <=> $b['seq']));

/* Walk the statement to get the running balance. */
$running = 0.0;
foreach ($entries as &$e) {
    $running += $e['debit'] - $e['credit'];
    $e['balance'] = $running;
}
unset($e);

/* Settlement progress. */
$settlePct = $totalCharged > 0 ? edu_percent($totalPaid, $totalCharged, 1) : 0.0;

/* Pay-now destination: straight to the payable filing's checkout when one
   exists (awaiting payment, or approved with balance left for installments
   or early settlement), otherwise to My Enrollments (works for student and
   parent and shows the per-application Pay action). The old
   /student/payments target is student-only and merely bounced back here. */
$payAppId = (int) ($payAppId ?? 0);
$payUrl = $payAppId > 0 ? url('/enroll/' . $payAppId . '/pay') : url('/my-enrollments');
?>

<?= edu_page_header([
    'eyebrow'     => 'Account statement',
    'title'       => 'My ledger',
    'description' => 'Every charge and payment recorded against your student account.',
    'actions'     => '<a href="' . e(url('/student/permits')) . '" class="btn btn-light btn-sm"><i class="bi bi-patch-check me-1"></i> Exam permits</a>'
                   . '<a href="' . e($payUrl) . '" class="btn btn-primary btn-sm"><i class="bi bi-cash-coin me-1"></i> Pay now</a>',
]) ?>

<div class="row g-3 mb-4">
  <div class="col-12 col-md-4">
    <?= edu_stat_card([
        'label' => 'Outstanding balance',
        'value' => '<span style="font-size:1.35rem">' . money($balance) . '</span>',
        'icon'  => 'bi-wallet2',
        'tone'  => $balance > 0 ? 'danger' : 'success',
        'meta'  => $balance > 0
            ? '<a href="' . e($payUrl) . '" class="small fw-semibold text-decoration-none">Settle this balance &rarr;</a>'
            : '<span class="edu-badge edu-badge--success"><i class="bi bi-check-circle-fill"></i> Fully settled</span>',
    ]) ?>
  </div>
  <div class="col-6 col-md-4">
    <?= edu_stat_card([
        'label' => 'Total charged',
        'value' => '<span style="font-size:1.35rem">' . money($totalCharged) . '</span>',
        'icon'  => 'bi-receipt', 'tone' => 'primary',
        'meta'  => '<span>' . count($charges) . ' charge' . (count($charges) === 1 ? '' : 's') . '</span>',
    ]) ?>
  </div>
  <div class="col-6 col-md-4">
    <?= edu_stat_card([
        'label' => 'Total paid',
        'value' => '<span style="font-size:1.35rem">' . money($totalPaid) . '</span>',
        'icon'  => 'bi-cash-coin', 'tone' => 'success',
        'meta'  => '<span>' . $paidCount . ' successful payment' . ($paidCount === 1 ? '' : 's') . '</span>',
    ]) ?>
  </div>
</div>

<?php if ($totalCharged > 0): ?>
  <div class="edu-card mb-4">
    <div class="edu-card__body">
      <?= edu_progress((float) $settlePct, [
          'label' => 'Settlement progress',
          'tone'  => $settlePct >= 100 ? 'success' : ($settlePct >= 50 ? 'warning' : 'danger'),
          'size'  => 'lg',
      ]) ?>
      <p class="small mb-0 mt-2" style="color:var(--text-tertiary)">
        <?= money($totalPaid) ?> paid of <?= money($totalCharged) ?> charged
        (<?= count($payments) - $paidCount ?> payment<?= (count($payments) - $paidCount) === 1 ? '' : 's' ?> not yet successful).
      </p>
    </div>
  </div>
<?php endif; ?>

<?php if ($entries === []): ?>
  <?= edu_empty_state(
        'No account activity yet',
        'Charges and payments appear here as soon as the accounting office posts them against your student record.',
        ['icon' => 'bi-receipt', 'actions' => '<a href="' . e(url('/my-enrollments')) . '" class="btn btn-light btn-sm">My enrollments</a>']
    ) ?>
<?php else: ?>
  <?= edu_section_head('Account statement', count($entries) . ' entr' . (count($entries) === 1 ? 'y' : 'ies') . ' · running balance', '', 'bi-list-ol') ?>

  <div class="edu-table-wrap mb-3">
    <div class="edu-table-scroll">
      <table class="edu-table edu-table--cards">
        <thead>
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Reference</th>
            <th class="edu-table__num">Debit</th>
            <th class="edu-table__num">Credit</th>
            <th class="edu-table__num">Balance</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
          <tr>
            <td data-label="Date" class="small" style="white-space:nowrap"><?= e(edu_date($e['date'])) ?></td>
            <td data-label="Description">
              <span class="fw-semibold"><?= e($e['desc']) ?></span>
              <?php if ($e['sub'] !== ''): ?>
                <div class="small" style="color:var(--text-tertiary)"><?= e($e['sub']) ?></div>
              <?php endif; ?>
            </td>
            <td data-label="Reference" class="small" style="color:var(--text-tertiary)">
              <?= $e['ref'] !== '' ? e($e['ref']) : '&mdash;' ?>
            </td>
            <td data-label="Debit" class="edu-table__num">
              <?php if ($e['debit'] > 0): ?>
                <span style="color:var(--danger-text);font-weight:600"><?= money($e['debit']) ?></span>
              <?php else: ?><span style="color:var(--border-strong)">&mdash;</span><?php endif; ?>
            </td>
            <td data-label="Credit" class="edu-table__num">
              <?php if ($e['credit'] > 0): ?>
                <span style="color:var(--success-text);font-weight:600"><?= money($e['credit']) ?></span>
              <?php else: ?><span style="color:var(--border-strong)">&mdash;</span><?php endif; ?>
            </td>
            <td data-label="Balance" class="edu-table__num fw-semibold"><?= money($e['balance']) ?></td>
            <td data-label="Status">
              <?php if ($e['status'] === null): ?>
                <span style="color:var(--border-strong)">&mdash;</span>
              <?php else: ?>
                <?= edu_status_badge($e['status']) ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3">Total charged <?= money($totalCharged) ?></td>
            <td class="edu-table__num"><?= money($totalCharged) ?></td>
            <td class="edu-table__num"><?= money($totalPaid) ?></td>
            <td class="edu-table__num"><?= money($balance) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?= edu_callout(
        'This statement is generated from official accounting records. For an official receipt or assessment, contact the accounting office.',
        ['tone' => 'info', 'icon' => 'bi-receipt-cutoff', 'title' => 'About this statement']
    ) ?>
<?php endif; ?>
