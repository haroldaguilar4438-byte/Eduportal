<?php
/**
 * Registrar: enrollment records (academic view) (spec §24).
 *
 * Non-destructive: the status filter pills keep their hrefs, the academic
 * approval form keeps its route (/registrar/enrollments/{id}/approve), CSRF
 * token and confirm() guard, and the print form link keeps its target. The
 * parent-filed detection, the doc-waiver label map ($wvLbl: ECCD / Report
 * Card) and its explanatory title attribute, and the period-label
 * normalisation are all preserved verbatim. Registrar still cannot touch
 * payments, and that constraint is restated.
 *
 * @var array  $rows
 * @var string $status
 */
$rows = $rows ?? [];

$filters = [
    'pending'            => 'Pending',
    'accounting_approved' => 'Accounting ✓',
    'awaiting_payment'    => 'Awaiting payment',
    'approved'            => 'Approved',
    'all'                 => 'All',
];

$wvLabels = ['eccd_checklist' => 'ECCD', 'form138_report_card' => 'Report Card'];

$pendingCount = 0;
foreach ($rows as $r) {
    if (($r['status'] ?? '') === 'accounting_approved') {
        $pendingCount++;
    }
}
?>

<?= edu_page_header([
    'eyebrow'     => 'Registrar',
    'title'       => 'Enrollment records',
    'description' => $pendingCount > 0
        ? $pendingCount . ' application(s) waiting on academic approval.'
        : 'Read-only academic view of enrollment applications.',
    'icon'        => 'bi-journal-text',
    'actions'     => edu_badge('Read + academic approval only', 'neutral', 'bi-eye'),
]) ?>

<!-- Status filters -->
<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($filters as $k => $l): ?>
        <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>" href="<?= e(url('/registrar/enrollments?status=' . $k)) ?>"><?= $l ?></a>
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
                'No applications',
                'Nothing matches this filter right now.',
                ['icon' => 'bi-journal-x', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small align-middle">
                    <thead>
                        <tr>
                            <th>App no.</th><th>Applicant</th><th>Level</th><th>Period</th>
                            <th>Status</th><th>Form</th><th class="text-end">Academic action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $kid = trim(trim((string) ($r['child_first'] ?? '')) . ' ' . trim((string) ($r['child_last'] ?? '')));
                        $isParentFiled = $kid !== ''
                            && mb_strtolower($kid) !== mb_strtolower(trim((string) ($r['applicant'] ?? '')));

                        $enPer = trim($r['period_code'] ?? '');
                        if ($enPer === '') {
                            $enPer = trim($r['semester'] ?? '');
                        }
                        if (in_array($enPer, ['1st', '2nd'], true)) {
                            $enPer .= ' Semester';
                        }

                        $waivers = [];
                        foreach (array_filter(array_map('trim', explode(',', (string) ($r['doc_waivers'] ?? '')))) as $w) {
                            $waivers[] = $wvLabels[$w] ?? $w;
                        }
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['application_no']) ?></td>
                            <td>
                                <?php if ($isParentFiled): ?>
                                    <strong><?= e($kid) ?></strong>
                                    <?php if (!empty($r['child_no'])): ?>
                                        <span class="text-muted small"><?= e($r['child_no']) ?></span>
                                    <?php endif; ?>
                                    <div class="text-muted small">Filed by parent: <?= e($r['applicant'] ?? '') ?></div>
                                <?php else: ?>
                                    <?= e($r['applicant'] ?? '') ?>
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
                            <td><?= e($r['level_name']) ?></td>
                            <td class="small"><?= e($r['school_year'] ?? '') ?> <?= e($enPer) ?></td>
                            <td><?= edu_status_badge((string) $r['status']) ?></td>
                            <td>
                                <a href="<?= e(url('/enroll/' . (int) $r['id'] . '/form')) ?>" target="_blank"
                                   class="btn btn-sm btn-light border" aria-label="Print form">
                                    <i class="bi bi-printer"></i>
                                </a>
                            </td>
                            <td class="text-end">
                                <?php if ($r['status'] === 'accounting_approved'): ?>
                                    <form method="post" action="<?= e(url('/registrar/enrollments/' . $r['id'] . '/approve')) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-primary" onclick="return confirm('Academically approve and send payment request?')">Approve academics</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted"><?= $r['status'] === 'pending' ? 'Awaiting Accounting' : '—' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="p-3 small text-muted" style="border-top:1px solid var(--border)">
            <i class="bi bi-diagram-3"></i> Flow: Student &rarr; Accounting verification &rarr; Registrar academic
            approval &rarr; Payment &rarr; Enrolled. Registrar cannot touch payments.
        </div>
    </div>
</div>
