<?php
/**
 * Parent: child detail (spec §16).
 *
 * Non-destructive: the ownership-checked page keeps every link with its
 * child_id query param (grades, schedule, my-classes, ledger, applications,
 * clearances, enrollment-docs, eligibility, enroll), the enrolled-vs-other
 * action buttons, the avatar initials, the status tone match() map, the
 * balance red/green colouring via money(), the paid-vs-other payment tone,
 * the per-status document badge map with the "skip missing rows" rule, and
 * the $child / $profile / $current / $applications / $payments / $balance /
 * $documents / $clearance / $permits / $grades / $subjects contract.
 *
 * @var array $child
 * @var array $profile
 * @var array $current
 * @var array $applications
 * @var array $payments
 * @var float $balance
 * @var array $documents
 * @var array $clearance
 * @var array $permits
 * @var array $grades
 * @var array $subjects
 */
$child        = $child ?? [];
$profile      = $profile ?? $child;
$current      = $current ?? null;
$applications = $applications ?? [];
$payments     = $payments ?? [];
$balance      = (float) ($balance ?? 0);
$documents    = $documents ?? [];
$clearance    = $clearance ?? null;
$permits      = $permits ?? [];
$grades       = $grades ?? [];
$subjects     = $subjects ?? [];

$name = trim(trim(($child['first_name'] ?? '')) . ' ' . trim(($child['middle_name'] ?? ''))
    . ' ' . trim(($child['last_name'] ?? '')) . ' ' . trim(($child['suffix'] ?? '')));
if ($name === '') {
    $name = 'Child #' . (int) ($child['id'] ?? 0);
}
$statusTone = match ($child['status'] ?? '') {
    'enrolled'  => 'success',
    'applicant' => 'info',
    'on_hold'   => 'warning',
    'graduated' => 'primary',
    default     => 'secondary',
};
$initials = strtoupper(mb_substr($child['first_name'] ?? 'S', 0, 1) . mb_substr($child['last_name'] ?? '', 0, 1));
$childId  = (int) ($child['id'] ?? 0);
$profileRows = [
    ['label' => 'Level', 'value' => e($profile['level_name'] ?? '—')],
    ['label' => 'School year', 'value' => e($profile['ay_label'] ?? ($current['ay_label'] ?? '—'))],
    ['label' => 'Current period', 'value' => e($current['period_label'] ?? '—')],
    ['label' => 'Campus', 'value' => e($profile['campus_name'] ?? '—')],
    ['label' => 'Outstanding balance', 'value' => '<span class="fw-bold ' . ($balance > 0 ? 'text-danger' : 'text-success') . '">' . money($balance) . '</span>'],
];
?>

<?= edu_breadcrumb([
    ['label' => 'Parent portal', 'url' => url('/dashboard')],
    ['label' => $name],
]) ?>

<!-- Identity -->
<div class="edu-card mb-3">
    <div class="edu-card__body">
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 text-white fw-bold flex-none"
                  style="width:56px;height:56px;background:var(--primary-900);font-size:1.25rem" aria-hidden="true">
                <?= e($initials) ?>
            </span>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1 class="h4 mb-0"><?= e($name) ?></h1>
                    <?= edu_badge(str_replace('_', ' ', (string) ($child['status'] ?? '')), $statusTone) ?>
                </div>
                <div class="small text-muted">
                    <?php if (!empty($child['student_no'])): ?>
                        Student No. <strong style="font-family:var(--font-mono)"><?= e($child['student_no']) ?></strong> ·
                    <?php endif; ?>
                    <?= e($profile['level_name'] ?? '') ?><?= !empty($profile['section_name']) ? ' · ' . e($profile['section_name']) : '' ?><?= !empty($profile['program_name']) ? ' · ' . e($profile['program_name']) : '' ?>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if (($child['status'] ?? '') === 'enrolled'): ?>
                    <a href="<?= e(url('/enrollment/eligibility?child=' . $childId)) ?>" class="btn btn-sm btn-warning">
                        <i class="bi bi-arrow-repeat"></i> Next period
                    </a>
                <?php endif; ?>
                <a href="<?= e(url('/enroll?child=' . $childId)) ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-send"></i> Enroll
                </a>
            </div>
        </div>
        <hr>
        <?= edu_dl($profileRows) ?>
    </div>
</div>

<div class="row g-3">
    <!-- Academic -->
    <div class="col-12 col-lg-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-mortarboard"></i> Academic</h2>
            </div>
            <div class="edu-card__body">
                <div class="small fw-semibold mb-1">Subjects (<?= count($subjects) ?>)</div>
                <?php if ($subjects === []): ?>
                    <p class="small text-muted">No subjects joined yet.</p>
                <?php else: ?>
                    <ul class="small mb-2 ps-3">
                    <?php foreach (array_slice($subjects, 0, 8) as $s): ?>
                        <li><?= e($s['subject_code'] ?? '') ?> — <?= e($s['subject_title'] ?? '') ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="small fw-semibold mb-1">Released grades (<?= count($grades) ?>)</div>
                <?php if ($grades === []): ?>
                    <p class="small text-muted mb-2">No released grades yet.</p>
                <?php else: ?>
                    <ul class="small mb-2 ps-3">
                    <?php foreach (array_slice($grades, 0, 8) as $g): ?>
                        <li><?= e($g['subject_code'] ?? 'Subject') ?>: <strong><?= e($g['final_grade']) ?></strong></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= e(url('/student/grades?child_id=' . $childId)) ?>" class="btn btn-sm btn-outline-primary">Grades</a>
                    <a href="<?= e(url('/student/schedule?child_id=' . $childId)) ?>" class="btn btn-sm btn-outline-secondary">Schedule</a>
                    <a href="<?= e(url('/student/my-classes?child_id=' . $childId)) ?>" class="btn btn-sm btn-outline-secondary">Subjects</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial -->
    <div class="col-12 col-lg-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-wallet2"></i> Financial</h2>
                <?= edu_badge(money($balance), $balance > 0 ? 'warning' : 'success', 'bi-cash-stack') ?>
            </div>
            <div class="edu-card__body">
                <?php if ($payments === []): ?>
                    <?= edu_empty_state('No payments yet', 'Payments for this child will appear here.', ['icon' => 'bi-wallet2', 'compact' => true]) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-2 align-middle">
                            <thead><tr><th>Reference</th><th>Amount</th><th>Status</th><th>Receipt</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($payments, 0, 6) as $p): ?>
                                <tr>
                                    <td class="small" style="font-family:var(--font-mono)"><?= e($p['transaction_ref']) ?></td>
                                    <td class="small fw-semibold"><?= money($p['amount']) ?></td>
                                    <td><?= edu_badge((string) ($p['status'] ?? ''), ($p['status'] ?? '') === 'paid' ? 'success' : 'warning') ?></td>
                                    <td class="small text-muted"><?= e($p['receipt_no'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <a href="<?= e(url('/student/ledger?child_id=' . $childId)) ?>" class="btn btn-sm btn-outline-success">Fees &amp; balance</a>
            </div>
        </div>
    </div>

    <!-- Enrollment -->
    <div class="col-12 col-lg-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-collection"></i> Enrollment</h2>
                <?= edu_badge((string) count($applications), 'neutral') ?>
            </div>
            <div class="edu-card__body">
                <?php if ($applications === []): ?>
                    <?= edu_empty_state('No applications yet', 'File the first application for this child.', ['icon' => 'bi-collection', 'compact' => true]) ?>
                <?php else: ?>
                    <ul class="list-group list-group-flush small mb-2">
                    <?php foreach (array_slice($applications, 0, 5) as $a): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                            <span>
                                <strong><?= e($a['application_no']) ?></strong> · <?= e($a['level_name'] ?? '') ?> · <?= e($a['school_year'] ?? '') ?>
                            </span>
                            <?= edu_status_badge((string) $a['status']) ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a href="<?= e(url('/parent/applications?child=' . $childId)) ?>" class="btn btn-sm btn-outline-primary">Full history</a>
            </div>
        </div>
    </div>

    <!-- Clearance & permits -->
    <div class="col-12 col-lg-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-patch-check-fill"></i> Clearance &amp; permits</h2>
            </div>
            <div class="edu-card__body">
                <div class="small mb-1">
                    Clearance:
                    <?php if ($clearance === null): ?>
                        <span class="text-muted">none requested</span>
                    <?php else: ?>
                        <?= edu_status_badge((string) $clearance['status']) ?>
                        <span class="text-muted">
                            financial: <?= e($clearance['financial_status'] ?? '') ?> · registrar: <?= e($clearance['registrar_status'] ?? '') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="small mb-2">
                    Permits:
                    <?php if ($permits === []): ?>
                        <span class="text-muted">none</span>
                    <?php else: ?>
                        <?php foreach ($permits as $pm): ?>
                            <?= edu_badge(e($pm['exam_period']) . ': ' . e(str_replace('_', ' ', $pm['status'])), 'neutral', 'bi-ticket') ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= e(url('/student/clearances?child_id=' . $childId)) ?>" class="btn btn-sm btn-outline-secondary">Clearance</a>
                    <a href="<?= e(url('/student/enrollment-docs?child_id=' . $childId)) ?>" class="btn btn-sm btn-outline-success">Documents</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Documents -->
<div class="edu-card mt-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-folder-check"></i> Documents on file</h2>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($documents === []): ?>
            <?= edu_empty_state('No documents yet', 'Verified documents for this child will appear here.', ['icon' => 'bi-folder-x', 'compact' => true]) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Document</th><th>Status</th><th>File</th></tr></thead>
                    <tbody>
                    <?php foreach ($documents as $t => $info):
                        $st = (string) ($info['status'] ?? 'missing');
                        if ($st === 'missing') {
                            continue;
                        }
                        $row = $info['row'] ?? null;
                        $docTone = $st === 'verified' ? 'success' : ($st === 'rejected' ? 'danger' : 'info');
                    ?>
                        <tr>
                            <td class="small fw-semibold"><?= e($info['label'] ?? $t) ?></td>
                            <td><?= edu_badge($st, $docTone) ?></td>
                            <td class="small">
                                <?php if ($row !== null && !empty($row['stored_path'])): ?>
                                    <a href="<?= e(url('/uploads/' . $row['stored_path'])) ?>" target="_blank" rel="noopener">View</a>
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
