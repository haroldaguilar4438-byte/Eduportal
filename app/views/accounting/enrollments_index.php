<?php
/**
 * Accounting: enrollment transactions (spec §32).
 *
 * Non-destructive: the five filter pills, the Documents link, the parent-filed
 * detection and the doc-waiver label map with its tooltip are preserved. The
 * tuition-plan cell logic is kept verbatim — monthly vs full tone, paid/total,
 * downpayment status, next-due date, remaining balance, and the "no plan" dash.
 * Both finance forms keep their routes (/accounting/enrollments/{id}/verify,
 * /mark-paid), CSRF tokens and confirm() guards, as does the print form link.
 *
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 */
$rows   = $rows ?? [];
$status = $status ?? 'pending';
$counts = $counts ?? [];

$filters = [
    'pending'             => 'Pending',
    'accounting_approved' => 'Verified → Registrar',
    'awaiting_payment'    => 'Awaiting payment',
    'approved'            => 'Approved',
    'all'                 => 'All',
];

$wvLabels = ['eccd_checklist' => 'ECCD', 'form138_report_card' => 'Report Card'];
?>

<?= edu_page_header([
    'eyebrow'     => 'Accounting',
    'title'       => 'Enrollment transactions',
    'description' => 'Verify finance, then hand off to the Registrar.',
    'icon'        => 'bi-journal-text',
    'actions'     => '<a href="' . e(url('/accounting/documents')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-folder2-open me-1"></i> Documents</a>'
        . edu_badge('Verify finance → Registrar', 'neutral', 'bi-arrow-right'),
]) ?>

<!-- Status filters -->
<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($filters as $k => $l): ?>
        <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= e(url('/accounting/enrollments?status=' . $k)) ?>">
            <?= e($l) ?>
            <span class="badge text-bg-<?= $status === $k ? 'light' : 'secondary' ?> ms-1"><?= (int) ($counts[$k] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> <?= e($filters[$status] ?? 'All') ?></h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state(
                'No transactions',
                'Nothing matches this filter right now.',
                ['icon' => 'bi-journal-x', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>App no.</th><th>Applicant</th><th>Plan</th><th>Balance</th>
                            <th>Status</th><th>Form</th><th class="text-end">Finance action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $kid = trim(trim((string) ($r['child_first'] ?? '')) . ' ' . trim((string) ($r['child_last'] ?? '')));
                        $isParentFiled = $kid !== ''
                            && mb_strtolower($kid) !== mb_strtolower(trim((string) ($r['applicant'] ?? '')));

                        $waivers = [];
                        foreach (array_filter(array_map('trim', explode(',', (string) ($r['doc_waivers'] ?? '')))) as $w) {
                            $waivers[] = $wvLabels[$w] ?? $w;
                        }
                    ?>
                        <tr>
                            <td class="fw-semibold">
                                <?= e($r['application_no']) ?>
                                <div class="text-muted"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></div>
                            </td>
                            <td>
                                <?php if ($isParentFiled): ?>
                                    <i class="bi bi-person"></i> <strong><?= e($kid) ?></strong>
                                    <?php if (!empty($r['child_no'])): ?>
                                        <span class="text-muted small"><?= e($r['child_no']) ?></span>
                                    <?php endif; ?>
                                    <div class="text-muted small">
                                        Filed by parent: <?= e($r['applicant'] ?? '') ?> · <?= e($r['level_name'] ?? '') ?>
                                    </div>
                                <?php else: ?>
                                    <?= e($r['applicant'] ?? '') ?>
                                    <div class="text-muted"><?= e($r['applicant_email'] ?? '') ?> · <?= e($r['level_name'] ?? '') ?></div>
                                <?php endif; ?>

                                <?php if ($waivers !== []): ?>
                                    <div class="mt-1">
                                        <span class="badge text-bg-light border"
                                              title="Parent declared these papers do not exist (no prior preschool/Kindergarten)">
                                            waived: <?= e(implode(', ', $waivers)) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php $pl = $r['plan'] ?? null; ?>
                                <?php if ($pl === null || empty($pl['plan_type'])): ?>
                                    <span class="text-muted">—</span>
                                <?php else:
                                    $pt     = (string) $pl['plan_type'];
                                    $ptotal = (float) $pl['total_tuition'];
                                    $ppaid  = (float) ($pl['paid_total'] ?? 0);
                                    $prem   = max(0, $ptotal - $ppaid);
                                ?>
                                    <span class="badge <?= $pt === 'monthly' ? 'bg-info text-dark' : 'bg-success' ?>">
                                        <?= $pt === 'monthly' ? 'Monthly' : 'Full' ?>
                                    </span>
                                    <div><?= money($ppaid) ?> / <?= money($ptotal) ?></div>
                                    <?php if ($pt === 'monthly'): ?>
                                        <div class="text-muted">
                                            Down: <?= e($pl['downpayment_status']) ?><?= !empty($pl['next_due']) ? ' · next ' . e(date('M j', strtotime($pl['next_due']))) : '' ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-muted">Left <?= money($prem) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $r['outstanding'] === null ? '<span class="text-muted">—</span>' : money($r['outstanding']) ?></td>
                            <td><?= edu_status_badge((string) $r['status']) ?></td>
                            <td>
                                <a href="<?= e(url('/enroll/' . (int) $r['id'] . '/form')) ?>" target="_blank"
                                   class="btn btn-sm btn-light border" aria-label="Print form">
                                    <i class="bi bi-printer"></i>
                                </a>
                            </td>
                            <td class="text-end">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <form method="post" action="<?= e(url('/accounting/enrollments/' . $r['id'] . '/verify')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-primary" onclick="return confirm('Verify financial requirements?')">Verify finance</button>
                                    </form>
                                <?php elseif ($r['status'] === 'awaiting_payment'): ?>
                                    <form method="post" action="<?= e(url('/accounting/enrollments/' . $r['id'] . '/mark-paid')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-success" onclick="return confirm('Confirm payment received and enroll student?')">Confirm paid</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
