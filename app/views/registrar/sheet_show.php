<?php
/**
 * Registrar: grade-sheet review (spec §22).
 *
 * Non-destructive: every computation is preserved verbatim — the status tone
 * match() map, the INC count ($incN, reused by the finalized-state reopen
 * card), the graded/missing counter, the engine mismatch loop (entered vs
 * computed ±0.01, INC without engine INC flag), the category summary and the
 * per-cell rendering. All five action forms keep their route
 * (/registrar/sheets/{id}/action), CSRF token, hidden `action` values
 * (under_review / approved / returned / finalized / reopen) and their
 * confirm() guards, remarks constraints and the under_review disabled state.
 * The History log, the Units/Submitted/Finalized lines and both explainer
 * notes are unchanged.
 *
 * @var array $sheet
 * @var array $rows
 * @var array $logs
 * @var int   $missing
 * @var array $calc
 */
$sheet   = $sheet ?? [];
$rows    = $rows ?? [];
$logs    = $logs ?? [];
$missing = (int) ($missing ?? 0);
$calc    = $calc ?? [];

$statusTone = match ($sheet['status']) {
    'submitted'    => 'warning',
    'under_review' => 'info',
    'returned'     => 'danger',
    'approved'     => 'primary',
    'finalized'    => 'success',
    default        => 'secondary',
};

/* INC count — also drives the finalized-state reopen card. */
$incN = 0;
foreach ($rows as $rr) {
    if ($rr['grade'] === null && trim((string) ($rr['remarks'] ?? '')) === 'INC') {
        $incN++;
    }
}
$gradedCount = count($rows) - $missing;

$weights = $calc['weights'] ?? [];
$mismatch = 0;
foreach ($rows as $r) {
    $cellBox = $calc['students'][(int) $r['student_id']] ?? null;
    $cellPoint = is_array($cellBox) ? ($cellBox['point'] ?? null) : null;
    $rowInc = $r['grade'] === null && trim((string) ($r['remarks'] ?? '')) === 'INC';
    if ($cellPoint !== null && $r['grade'] !== null && abs((float) $cellPoint - (float) $r['grade']) > 0.01) {
        $mismatch++;
    }
    if ($rowInc && ($cellBox === null || empty($cellBox['inc']))) {
        $mismatch++;
    }
}
?>

<?= edu_breadcrumb([
    ['label' => 'Grade sheets', 'url' => url('/registrar/sheets?status=' . $sheet['status'])],
    ['label' => (string) ($sheet['subject_code'] ?? '') . ' ' . ($sheet['period_code'] ?? '')],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Grade review',
    'title'       => 'Review — ' . ($sheet['subject_code'] ?? '') . ' ' . ($sheet['period_code'] ?? ''),
    'description' => ($sheet['subject_title'] ?? '') . ' · ' . ($sheet['section_name'] ?? '') . ' · '
        . ($sheet['school_year'] ?? '') . ' ' . ($sheet['semester'] ?? '') . ' · ' . ($sheet['units'] ?? '') . ' units',
    'icon'        => 'bi-clipboard-check',
    'actions'     => edu_badge(str_replace('_', ' ', (string) $sheet['status']), $statusTone, 'bi-flag'),
]) ?>

<!-- Sheet meta -->
<div class="edu-card mb-3">
    <div class="edu-card__body">
        <div class="row g-2 small align-items-center">
            <div class="col-md-6">
                <strong><?= e($sheet['subject_title']) ?></strong>
                <div class="text-muted">
                    <?= e($sheet['section_name'] ?? '') ?> · <?= e($sheet['school_year']) ?>
                    <?= e($sheet['semester'] ?? '') ?> · <?= e($sheet['units']) ?> units
                </div>
                <div class="text-muted">
                    Teacher: <strong style="color:var(--text-primary)"><?= e($sheet['teacher_name']) ?></strong>
                    · Submitted: <?= e($sheet['submitted_at'] ?? '—') ?>
                </div>
            </div>
            <div class="col-md-6 text-md-end">
                <div>
                    <?= $gradedCount ?>/<?= count($rows) ?> graded
                    <?php if ($missing > 0): ?>
                        <?= edu_badge($missing . ' missing', 'danger', 'bi-exclamation-circle') ?>
                    <?php endif; ?>
                    <?php if ($incN > 0): ?>
                        <?= edu_badge($incN . ' INC', 'neutral', 'bi-pause-circle') ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($sheet['status'] === 'returned' && !empty($sheet['review_remarks'])): ?>
    <?= edu_callout(
        '<strong>Last return reason:</strong> ' . e($sheet['review_remarks']),
        ['tone' => 'warning', 'icon' => 'bi-arrow-return-left', 'title' => null]
    ) ?>
<?php endif; ?>

<!-- Engine verification -->
<div class="edu-card mb-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-calculator"></i> Engine verification <span class="small fw-normal text-muted">(read-only)</span></h2>
        <?= edu_badge('backend recomputed', 'neutral', 'bi-cpu') ?>
    </div>
    <div class="edu-card__body">
        <?php if ($weights === []): ?>
            <?= edu_callout('No active weights configured.', ['tone' => 'warning', 'icon' => 'bi-exclamation-triangle', 'title' => null, 'compact' => true]) ?>
        <?php else: ?>
            <div class="small text-muted mb-2">
                Weights:
                <?php
                $wp = [];
                foreach ($weights as $w) {
                    $wp[] = e($w['name']) . ' ' . e(rtrim(rtrim(number_format($w['weight'], 2), '.'), '0')) . '%';
                }
                echo implode(' · ', $wp);
                ?>
                · missing minor work = 0 (deducts) · missing major work (exams, projects) = INC ·
                mismatches vs entered:
                <strong class="<?= $mismatch > 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $mismatch ?></strong>
            </div>

            <?php $catSummary = $calc['catSummary'] ?? []; ?>
            <?php if ($catSummary !== []): ?>
                <div class="row g-2 mb-2">
                <?php foreach ($weights as $cid => $w):
                    $cs = $catSummary[$cid] ?? ['assessments' => 0, 'avg_pct' => null]; ?>
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="p-2 rounded-3 text-center small" style="background:var(--surface-muted);border:1px solid var(--border)">
                            <div class="fw-semibold"><?= e(strtoupper($w['name'])) ?></div>
                            <div class="text-muted">Assessments: <?= (int) $cs['assessments'] ?></div>
                            <div class="fw-bold"><?= $cs['avg_pct'] === null ? '—' : e(number_format((float) $cs['avg_pct'], 1)) . '%' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <?php foreach ($weights as $w): ?>
                                <th class="text-center"><?= e($w['name']) ?> <span class="text-muted fw-normal">raw</span></th>
                            <?php endforeach; ?>
                            <th>Entered final</th><th>Computed (locked)</th><th>Weighted %</th><th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $cb    = $calc['students'][(int) $r['student_id']] ?? null;
                        $cp    = is_array($cb) ? ($cb['point'] ?? null) : null;
                        $rowInc = $r['grade'] === null && trim((string) ($r['remarks'] ?? '')) === 'INC';
                        $engInc = is_array($cb) && !empty($cb['inc']);
                        $bad   = ($cp !== null && $r['grade'] !== null && abs((float) $cp - (float) $r['grade']) > 0.01)
                            || ($rowInc && !$engInc);
                    ?>
                        <tr class="<?= $bad ? 'table-warning' : '' ?>">
                            <td>
                                <?= e($r['student_name']) ?>
                                <?php if ($rowInc): ?>
                                    <?= edu_badge('INC', 'neutral') ?>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($weights as $cid => $w):
                                $cell = $cb['cats'][$cid] ?? null; ?>
                                <td class="text-center">
                                    <?php if ($cell === null || !isset($cell['pct']) || $cell['pct'] === null): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <?= e(number_format((float) $cell['pct'], 1)) ?>%
                                        <div class="text-muted" style="font-size:.65rem">
                                            <?= e((string) $cell['earned']) ?>/<?= e((string) $cell['possible']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td>
                                <?= $rowInc
                                    ? edu_badge('INC', 'neutral')
                                    : ($r['grade'] === null
                                        ? edu_badge('missing', 'danger', 'bi-exclamation-circle')
                                        : edu_badge((string) $r['grade'], 'primary')) ?>
                            </td>
                            <td>
                                <?= $engInc
                                    ? edu_badge('INC', 'neutral')
                                    : ($cp === null
                                        ? '<span class="text-muted">—</span>'
                                        : edu_badge(number_format($cp, 2), 'info')) ?>
                            </td>
                            <td class="small">
                                <?= $engInc
                                    ? 'missing major: ' . e(implode('; ', array_slice($cb['inc_missing'] ?? [], 0, 2)))
                                    : ($cb === null || $cb['score'] === null
                                        ? '—'
                                        : e(number_format($cb['score'], 2)) . '% · ' . $cb['missing'] . ' missing') ?>
                            </td>
                            <td><?= edu_badge((string) ($r['source'] ?? 'manual'), ($r['source'] ?? 'manual') === 'auto' ? 'info' : 'neutral') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="form-hint mt-2">
            Raw assessment columns (score / total → %) show how each computed grade was produced. Verify: no score
            exceeds its total, totals are 1–100, percentages match raw ÷ total. Teacher grades are engine-locked —
            any entered grade diverging from Computed is a mismatch.
        </div>
        <div class="form-hint">
            You review — you don't recalculate. Return with reason if entered grades diverge from computed values.
        </div>
    </div>
</div>

<!-- Entered grades -->
<div class="edu-card mb-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-table"></i> Entered grades</h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state('No rows', 'This sheet has no grade rows.', ['icon' => 'bi-table', 'compact' => true]) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead><tr><th>Student no.</th><th>Name</th><th>Grade</th><th>Remarks</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $rInc = $r['grade'] === null && trim((string) ($r['remarks'] ?? '')) === 'INC'; ?>
                        <tr class="<?= ($r['grade'] === null && !$rInc) ? 'table-warning' : '' ?>">
                            <td class="fw-semibold"><?= e($r['student_no'] ?? '—') ?></td>
                            <td><?= e($r['student_name']) ?></td>
                            <td>
                                <?= $rInc
                                    ? edu_badge('INC', 'neutral')
                                    : ($r['grade'] === null
                                        ? edu_badge('missing', 'danger', 'bi-exclamation-circle')
                                        : edu_badge((string) $r['grade'], 'primary')) ?>
                            </td>
                            <td class="text-muted"><?= e($r['remarks'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Actions by state -->
<?php if (in_array($sheet['status'], ['submitted', 'under_review'], true)): ?>
    <div class="edu-card mb-3">
        <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-check2-square"></i> Registrar actions</h2>
        </div>
        <div class="edu-card__body">
            <div class="row g-2">
                <div class="col-md-6">
                    <form method="post" action="<?= e(url('/registrar/sheets/' . $sheet['id'] . '/action')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="under_review">
                        <button class="btn btn-info w-100" <?= $sheet['status'] === 'under_review' ? 'disabled' : '' ?>>Mark under review</button>
                    </form>
                </div>
                <div class="col-md-6">
                    <form method="post" action="<?= e(url('/registrar/sheets/' . $sheet['id'] . '/action')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approved">
                        <button class="btn btn-primary w-100" onclick="return confirm('Approve these grades?')">Approve</button>
                    </form>
                </div>
                <div class="col-12">
                    <form method="post" action="<?= e(url('/registrar/sheets/' . $sheet['id'] . '/action')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="returned">
                        <label class="form-label small" for="sheet_remarks_<?= (int) $sheet['id'] ?>">Return for correction</label>
                        <div class="input-group">
                            <textarea name="remarks" id="sheet_remarks_<?= (int) $sheet['id'] ?>" class="form-control" rows="2" required minlength="5" maxlength="500" placeholder="Correction reason (required to return)..."></textarea>
                            <button class="btn btn-outline-danger flex-none">Return for correction</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="form-hint mt-2">Returning notifies the teacher with your reason. Approving moves the sheet toward finalization.</div>
        </div>
    </div>

<?php elseif ($sheet['status'] === 'approved'): ?>
    <div class="edu-card mb-3">
        <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-lock"></i> Finalize into official records</h2>
        </div>
        <div class="edu-card__body">
            <form method="post" action="<?= e(url('/registrar/sheets/' . $sheet['id'] . '/action')) ?>"
                  onsubmit="return confirm('Finalize? Grades lock and become official records for documents.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="finalized">
                <button class="btn btn-success w-100"><i class="bi bi-lock"></i> Finalize — lock as official record</button>
            </form>
            <div class="form-hint mt-2">
                Finalized grades feed Grade Slips and official documents. INC rows finalize as INCOMPLETE on slips.
                They cannot be edited afterward (reopen below for INC summer completion).
            </div>
        </div>
    </div>

<?php elseif ($sheet['status'] === 'finalized'): ?>
    <?= edu_callout(
        '<i class="bi bi-lock"></i> Finalized ' . e($sheet['finalized_at'] ?? '')
        . ' — official academic record. Used by Grade Slip generation.',
        ['tone' => 'success', 'icon' => null, 'title' => null]
    ) ?>

    <?php if ($incN > 0): ?>
        <div class="edu-card mb-3">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-arrow-counterclockwise"></i> Reopen for INC completion (summer)</h2>
            </div>
            <div class="edu-card__body">
                <form method="post" action="<?= e(url('/registrar/sheets/' . $sheet['id'] . '/action')) ?>"
                      onsubmit="return confirm('Reopen this finalized sheet so the teacher can grade completed INC work?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reopen">
                    <label class="form-label small" for="sheet_reopen_<?= (int) $sheet['id'] ?>">Completion context</label>
                    <div class="input-group">
                        <textarea name="remarks" id="sheet_reopen_<?= (int) $sheet['id'] ?>" class="form-control" rows="2" required minlength="5" maxlength="500" placeholder="Completion context, e.g. Summer 2026 completion + OR no. ..."></textarea>
                        <button class="btn btn-outline-warning flex-none">Reopen <?= (int) $incN ?> INC</button>
                    </div>
                </form>
                <div class="form-hint mt-2">
                    Only sheets with INC grades can be reopened. The teacher grades the completed work and resubmits
                    through the normal pipeline.
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- History -->
<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-clock-history"></i> History</h2>
        <?= edu_badge((string) count($logs), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($logs === []): ?>
            <?= edu_empty_state('No history', 'Status changes will be recorded here.', ['icon' => 'bi-clock-history', 'compact' => true]) ?>
        <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($logs as $l): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <span>
                        <strong><?= e($l['action']) ?></strong>
                        <?= e($l['from_status'] ?? '—') ?> → <strong><?= e($l['to_status']) ?></strong>
                        by <?= e($l['actor'] ?? 'system') ?><?= $l['remarks'] ? ' — ' . e($l['remarks']) : '' ?>
                    </span>
                    <span class="small text-muted flex-none"><?= e(date('M j, g:ia', strtotime($l['created_at']))) ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
