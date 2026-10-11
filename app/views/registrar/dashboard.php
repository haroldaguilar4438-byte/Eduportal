<?php
/**
 * REGISTRAR — academic records workspace.
 * Distinct focus: every tile is an academic approval queue, and the
 * headline reports the total queue depth awaiting a registrar decision.
 */
/** @var array $stats @var int $pendingGrades @var array $recentApproved @var array $permitQueue @var array $recentDocs */
$queueDepth = (float) ($stats['pending_sheets'] ?? 0)
            + (float) ($stats['pending_permits'] ?? 0)
            + (float) ($stats['pending_docs'] ?? 0)
            + (float) ($stats['pending_clearance_review'] ?? 0);
$regHeadline = $queueDepth > 0
    ? e(number_format($queueDepth)) . ' record' . ($queueDepth == 1 ? '' : 's') . ' awaiting your review'
    : 'All academic queues are clear';
?>
<?= edu_page_header([
    'eyebrow'     => 'Academic records',
    'title'       => 'Registrar dashboard',
    'description' => $regHeadline . '. ' . e(date('l, F j, Y')),
    'actions'     => '<a class="btn btn-light btn-sm" href="' . e(url('/registrar/students')) . '"><i class="bi bi-people me-1"></i> Student records</a>'
                   . '<a class="btn btn-primary btn-sm" href="' . e(url('/registrar/enrollments')) . '"><i class="bi bi-inbox me-1"></i> Enrollment queue</a>',
]) ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Enrolled students', 'value' => e(number_format((float)($stats['total_students']??0))), 'icon' => 'bi-people-fill', 'tone' => 'primary', 'href' => url('/registrar/students')]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Grade sheets awaiting review', 'value' => '<span data-live-count="pending_sheets">' . e(number_format((float)($stats['pending_sheets']??0))) . '</span>', 'icon' => 'bi-journal-check', 'tone' => 'warning', 'meta' => '<span>' . e(number_format((float)($stats['returned_sheets']??0))) . ' returned · <span data-live-count="grades_awaiting">' . e(number_format((float)($stats['grades_awaiting']??0))) . '</span> task grades</span>', 'href' => url('/registrar/sheets')]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Permits awaiting registrar', 'value' => '<span data-live-count="pending_permits">' . e(number_format((float)($stats['pending_permits']??0))) . '</span>', 'icon' => 'bi-patch-check-fill', 'tone' => 'info', 'href' => url('/registrar/permits')]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Pending document requests', 'value' => '<span data-live-count="pending_docs">' . e(number_format((float)($stats['pending_docs']??0))) . '</span>', 'icon' => 'bi-file-earmark-text', 'tone' => 'accent', 'href' => url('/registrar/documents')]) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Clearances awaiting review', 'value' => '<span data-live-count="pending_clearance_review">' . e(number_format((float)($stats['pending_clearance_review']??0))) . '</span>', 'icon' => 'bi-check2-square', 'tone' => 'success', 'meta' => '<span>' . e(number_format((float)($stats['ready_next']??0))) . ' ready for next enrollment</span>', 'href' => url('/registrar/re-enrollment')]) ?></div>
</div>
<div class="row g-3">
    <div class="col-12 col-xl-6"><div class="card p-3 h-100">
        <div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Permits awaiting registrar (Stage 2)</h2><a class="small" href="<?= e(url('/registrar/permits')) ?>">All &rarr;</a></div>
        <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Permit</th><th>Student</th><th>Period</th></tr></thead><tbody>
        <?php if(($permitQueue??[])===[]): ?><tr><td colspan="3" class="text-center text-muted py-3">Queue clear.</td></tr><?php endif; ?>
        <?php foreach(($permitQueue??[]) as $p): ?><tr><td class="fw-semibold"><?= e($p['permit_no']) ?></td><td><?= e(trim(($p['first_name']??'').' '.($p['last_name']??''))) ?><div class="small text-muted"><?= e($p['student_no']??'') ?></div></td><td><span class="badge text-bg-info"><?= e($p['exam_period']) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div></div>
    <div class="col-12 col-xl-6"><div class="card p-3 h-100">
        <div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Recently approved grades</h2><a class="small" href="<?= e(url('/registrar/grades?review=approved')) ?>">All &rarr;</a></div>
        <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Student</th><th>Subject</th><th>Score</th></tr></thead><tbody>
        <?php if(($recentApproved??[])===[]): ?><tr><td colspan="3" class="text-center text-muted py-3">No approvals yet.</td></tr><?php endif; ?>
        <?php foreach(($recentApproved??[]) as $g): ?><tr><td><?= e($g['student_name']??'') ?></td><td><?= e($g['subject_code']??'') ?> <span class="text-muted small"><?= e($g['title']??'') ?></span></td><td><span class="badge text-bg-success"><?= e((string)($g['score']??'—')) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div></div>
</div>
<div class="card p-3 mt-3"><div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Recently issued documents</h2><a class="small" href="<?= e(url('/registrar/documents')) ?>">All &rarr;</a></div>
<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Doc No.</th><th>Student</th><th>Type</th><th>Status</th></tr></thead><tbody>
<?php if(($recentDocs??[])===[]): ?><tr><td colspan="4" class="text-center text-muted py-3">No documents yet.</td></tr><?php endif; ?>
<?php foreach(($recentDocs??[]) as $d): ?><tr><td class="fw-semibold"><?= e($d['doc_no']) ?></td><td><?= e($d['student_name']??'') ?></td><td><?= e($d['doc_type']) ?></td><td><span class="badge text-bg-secondary"><?= e($d['status']) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div></div>
