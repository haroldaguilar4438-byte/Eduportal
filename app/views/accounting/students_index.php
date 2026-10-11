<?php
/**
 * Accounting: student financial accounts (spec §32).
 *
 * Non-destructive: the GET search form keeps its `q` field name and submit
 * behaviour, the balance column still uses money(), the status badge and the
 * per-row Ledger link (/accounting/students/{id}) are unchanged, as is the
 * $rows / $q / $page / $per / $total contract.
 *
 * @var array  $rows
 * @var string $q
 * @var int    $page
 * @var int    $per
 * @var int    $total
 */
$rows  = $rows ?? [];
$q     = $q ?? '';
$total = (int) ($total ?? count($rows));
?>

<?= edu_page_header([
    'eyebrow'     => 'Accounting',
    'title'       => 'Student financial accounts',
    'description' => $q !== ''
        ? 'Search results for "' . $q . '".'
        : 'Search a student to open their ledger.',
    'icon'        => 'bi-wallet2',
    'actions'     => edu_badge(number_format($total) . ' found', 'neutral', 'bi-search'),
]) ?>

<!-- Search -->
<form method="get" action="<?= e(url('/accounting/students')) ?>" class="edu-card mb-3" role="search" aria-label="Search student accounts">
    <div class="edu-card__body">
        <div class="row g-2">
            <div class="col-md-10">
                <label class="visually-hidden" for="acct_q">Search</label>
                <input name="q" id="acct_q" value="<?= e($q) ?>" class="form-control" placeholder="Search name, email, student no." autocomplete="off">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100"><i class="bi bi-search"></i> Search</button>
            </div>
        </div>
    </div>
</form>

<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> Accounts</h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state(
                'No students',
                $q !== '' ? 'No accounts match your search.' : 'No student accounts on record.',
                ['icon' => 'bi-people', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead>
                        <tr><th>Student no.</th><th>Name</th><th>Balance</th><th>Status</th><th class="text-end">Account</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['student_no'] ?? '—') ?></td>
                            <td>
                                <strong><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></strong>
                                <div class="text-muted"><?= e($r['email'] ?? '') ?></div>
                            </td>
                            <td class="fw-semibold"><?= money($r['balance'] ?? 0) ?></td>
                            <td><?= edu_status_badge((string) ($r['status'] ?? '')) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light border" href="<?= e(url('/accounting/students/' . $r['id'])) ?>">Ledger</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
