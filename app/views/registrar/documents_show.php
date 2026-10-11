<?php
/**
 * Registrar: document request detail (spec §23).
 *
 * Non-destructive: all three lifecycle stages keep their POST route
 * (/registrar/documents/{id}/release), CSRF token and hidden `action` value
 * (approved / ready / released), with the same confirm() guards. The Reject
 * buttons keep every data-* attribute the shared reject modal reads, the
 * status tone match() map, the period-label normalisation, the Preview/Print
 * link, the Queue link and the reject_modal include are unchanged. The remarks
 * callout still switches to danger styling on rejection.
 *
 * @var array $doc
 * @var array $history
 */
$doc     = $doc ?? [];
$history = $history ?? [];

$statusTone = match ($doc['status']) {
    'released'  => 'success',
    'rejected'  => 'danger',
    'cancelled' => 'secondary',
    'ready'     => 'info',
    default     => 'warning',
};

$periodLabel = trim($doc['period_code'] ?? '');
if ($periodLabel === '') {
    $periodLabel = trim($doc['semester'] ?? '');
}
if (in_array($periodLabel, ['1st', '2nd'], true)) {
    $periodLabel .= ' Semester';
}

$detailRows = [
    ['label' => 'Student', 'value' => '<strong>' . e(trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? '')))
        . '</strong> (' . e($doc['student_no'] ?? '') . ')'],
    ['label' => 'Type', 'value' => e(str_replace('_', ' ', (string) $doc['doc_type']))],
    ['label' => 'Purpose', 'value' => e($doc['purpose'] ?? '—')],
    ['label' => 'Term', 'value' => e($doc['school_year'] ?? 'current') . ' ' . e($periodLabel)],
    ['label' => 'Level / program', 'value' => e($doc['level_name'] ?? '—') . ' ' . e($doc['program_code'] ?? '')],
    ['label' => 'Status', 'value' => edu_status_badge((string) $doc['status'])],
    ['label' => 'Requested', 'value' => edu_datetime((string) $doc['created_at'])],
];
if (!empty($doc['released_at'])) {
    $detailRows[] = ['label' => 'Released', 'value' => e((string) $doc['released_at'])];
}
?>

<?= edu_breadcrumb([
    ['label' => 'Documents', 'url' => url('/registrar/documents?status=' . $doc['status'])],
    ['label' => (string) $doc['doc_no']],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Document request',
    'title'       => (string) $doc['doc_no'],
    'description' => str_replace('_', ' ', (string) $doc['doc_type']) . ' · '
        . ($doc['school_year'] ?? 'current'),
    'icon'        => 'bi-file-earmark-text',
    'actions'     => '<a href="' . e(url('/registrar/documents?status=' . $doc['status'])) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-arrow-left me-1"></i> Queue</a>'
        . '<a href="' . e(url('/documents/' . $doc['id'] . '/print')) . '" target="_blank" class="btn btn-light btn-sm">'
        . '<i class="bi bi-eye me-1"></i> Preview / print</a>',
]) ?>

<?php if (!empty($doc['remarks'])): ?>
    <?= edu_callout(
        '<strong>' . ($doc['status'] === 'rejected' ? 'Rejection reason: ' : 'Registrar note: ') . '</strong>'
        . e($doc['remarks']),
        [
            'tone'  => $doc['status'] === 'rejected' ? 'danger' : 'info',
            'icon'  => $doc['status'] === 'rejected' ? 'bi-x-circle' : 'bi-chat-left-text',
            'title' => null,
        ]
    ) ?>
<?php endif; ?>

<div class="row g-3">
    <!-- Details -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-card-list"></i> Request details</h2>
                <?= edu_badge((string) $doc['status'], $statusTone) ?>
            </div>
            <div class="edu-card__body">
                <?= edu_dl($detailRows) ?>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-check2-square"></i> Registrar actions</h2>
            </div>
            <div class="edu-card__body">
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($doc['status'] === 'pending'): ?>
                        <form method="post" action="<?= e(url('/registrar/documents/' . $doc['id'] . '/release')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approved">
                            <button class="btn btn-primary btn-sm" onclick="return confirm('Approve this request?')">Approve</button>
                        </form>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                data-form-action="<?= e(url('/registrar/documents/' . $doc['id'] . '/release')) ?>"
                                data-record="<?= e($doc['doc_no']) ?>" data-title="Reject Request">Reject</button>

                    <?php elseif ($doc['status'] === 'approved'): ?>
                        <form method="post" action="<?= e(url('/registrar/documents/' . $doc['id'] . '/release')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="ready">
                            <button class="btn btn-info btn-sm">Mark ready for release</button>
                        </form>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                data-form-action="<?= e(url('/registrar/documents/' . $doc['id'] . '/release')) ?>"
                                data-record="<?= e($doc['doc_no']) ?>" data-title="Reject Request">Reject</button>

                    <?php elseif ($doc['status'] === 'ready'): ?>
                        <form method="post" action="<?= e(url('/registrar/documents/' . $doc['id'] . '/release')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="released">
                            <button class="btn btn-success btn-sm" onclick="return confirm('Release to student?')">
                                <i class="bi bi-check-lg"></i> Release to student
                            </button>
                        </form>

                    <?php else: ?>
                        <span class="small text-muted">No further actions (<?= e($doc['status']) ?>).</span>
                    <?php endif; ?>
                </div>

                <?= edu_callout(
                    'Approve &rarr; Ready &rarr; Release. The student is notified at each step. '
                    . 'Preview always renders live official data.',
                    ['tone' => 'info', 'icon' => 'bi-info-circle', 'title' => null, 'compact' => true]
                ) ?>
            </div>
        </div>
    </div>
</div>

<?php if ($history !== []): ?>
    <div class="edu-card mt-3">
        <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-clock-history"></i> Student request history</h2>
            <?= edu_badge((string) count($history), 'neutral') ?>
        </div>
        <div class="edu-card__body p-0">
            <ul class="list-group list-group-flush">
            <?php foreach ($history as $h): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><?= e($h['doc_no']) ?> &middot; <?= e(str_replace('_', ' ', $h['doc_type'])) ?></span>
                    <?= edu_status_badge((string) $h['status']) ?>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../shared/partials/reject_modal.php'; ?>
