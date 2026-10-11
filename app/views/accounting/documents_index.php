<?php
/**
 * Accounting: enrollment documents (spec §32).
 *
 * Non-destructive: the two tabs keep their hrefs and counts, the five status
 * pills preserve the tab + query carryover ($qs), the search form keeps its
 * hidden tab/status fields and `q` name. The $badge tone map, the $typeLabel
 * fallback map (Form 137/138, Birth Cert, Good Moral, ID Photo, Valid ID,
 * Prev. School Record), the period normalisation, the review_reason display,
 * and both Verify forms (uploads + permanent) with their routes, CSRF tokens
 * and confirm() guards are preserved. Both per-row resubmission modals keep
 * their unique ids (up{id} / rs{id}), routes, `reason` field with
 * required/minlength=5 and their example placeholders.
 *
 * @var string $tab
 * @var array  $uploads
 * @var array  $uploadCounts
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 * @var string $q
 * @var array  $types
 */
$tab = $tab ?? 'uploads';
$uploads = $uploads ?? [];
$uploadCounts = $uploadCounts ?? [];
$rows = $rows ?? [];
$status = $status ?? 'submitted';
$counts = $counts ?? [];
$q = $q ?? '';
$types = $types ?? [];

$badge = static function (?string $st): string {
    return match ((string) $st) {
        'verified'           => 'success',
        'submitted'          => 'warning',
        'needs_resubmission' => 'info',
        'rejected'           => 'danger',
        default              => 'secondary',
    };
};
$typeLabel = static function (string $t) use ($types): string {
    if (isset($types[$t])) {
        return (string) $types[$t];
    }
    return match ($t) {
        'form137'               => 'Form 137',
        'form138_report_card'   => 'Form 138',
        'birth_certificate'     => 'Birth Cert',
        'good_moral'            => 'Good Moral',
        'id_photo'              => 'ID Photo',
        'valid_id'              => 'Valid ID',
        'previous_school_record'=> 'Prev. School Record',
        default                 => str_replace('_', ' ', $t),
    };
};
$qs = $q !== '' ? '&q=' . urlencode($q) : '';

$statusPills = [
    'submitted'          => 'Needs review',
    'verified'           => 'Verified',
    'needs_resubmission' => 'Resubmission asked',
    'rejected'           => 'Rejected',
    'all'                => 'All',
];
?>

<?= edu_page_header([
    'eyebrow'     => 'Accounting',
    'title'       => 'Enrollment documents',
    'description' => 'Verify uploaded files; verified files are imported to the permanent file and reused every semester.',
    'icon'        => 'bi-folder2-open',
    'actions'     => '<a href="' . e(url('/accounting/enrollments')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-arrow-left me-1"></i> Enrollment queue</a>',
]) ?>

<?= edu_callout(
    '<strong>Uploaded files</strong> are what students attached to each application — verify them here. '
    . 'Verified files are imported to the <strong>Permanent file</strong> and reused automatically every '
    . 'semester. Request a new version only when necessary — a reason is required and the student is notified.',
    ['tone' => 'info', 'icon' => 'bi-info-circle', 'title' => null]
) ?>

<?php if (empty($storeReady)): ?>
    <?= edu_callout(
        'The permanent-file store is not installed yet — the Uploaded files tab still works, but verifying '
        . 'cannot import to permanent reuse. Ask the admin to import <code>database/patch_enrollment_form_docs.sql</code>.',
        ['tone' => 'warning', 'icon' => 'bi-exclamation-triangle', 'title' => null]
    ) ?>
<?php endif; ?>

<!-- Tabs -->
<?= edu_tabs([
    ['key' => 'uploads', 'label' => 'Uploaded files', 'url' => url('/accounting/documents?tab=uploads'),
     'icon' => 'bi-cloud-upload', 'count' => (int) ($uploadCounts['all'] ?? 0)],
    ['key' => 'permanent', 'label' => 'Permanent file', 'url' => url('/accounting/documents?tab=permanent'),
     'icon' => 'bi-folder-check', 'count' => (int) ($counts['all'] ?? 0)],
], $tab) ?>

<!-- Status pills -->
<?php $cnt = $tab === 'uploads' ? $uploadCounts : $counts; ?>
<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($statusPills as $k => $l): ?>
        <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>"
           href="<?= e(url('/accounting/documents?tab=' . $tab . '&status=' . $k . $qs)) ?>">
            <?= e($l) ?>
            <span class="badge text-bg-<?= $status === $k ? 'light' : 'secondary' ?> ms-1"><?= (int) ($cnt[$k] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Search -->
<form method="get" action="<?= e(url('/accounting/documents')) ?>" class="d-flex gap-2 mb-3" role="search" aria-label="Search documents">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Search name, email, student no., application no…" style="max-width:340px" aria-label="Search">
    <button class="btn btn-sm btn-outline-primary flex-none">Search</button>
</form>

<?php if ($tab === 'uploads'): ?>
    <div class="edu-card">
        <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-cloud-upload"></i> Uploaded files</h2>
            <?= edu_badge((string) count($uploads), 'neutral') ?>
        </div>
        <div class="edu-card__body p-0">
            <?php if ($uploads === []): ?>
                <?= edu_empty_state('Nothing here', 'No uploads match this filter.', ['icon' => 'bi-cloud-upload', 'compact' => true]) ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Student / application</th><th scope="col">Document</th>
                                <th scope="col">Status</th><th scope="col">File</th><th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($uploads as $r):
                            $st = (string) ($r['status'] ?? 'submitted');
                            if ($st === '') {
                                $st = 'submitted';
                            }
                            $accPer = trim($r['app_period'] ?? '');
                            if ($accPer === '') {
                                $accPer = trim($r['app_semester'] ?? '');
                            }
                            if (in_array($accPer, ['1st', '2nd'], true)) {
                                $accPer .= ' Semester';
                            }
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e(trim((string) ($r['applicant'] ?? '')) !== '' ? $r['applicant'] : '—') ?></div>
                                    <div class="small text-muted" style="font-family:var(--font-mono)">
                                        <?= e($r['student_no'] ?? $r['returning_student_no'] ?? '') ?> · <?= e($r['email'] ?? '') ?>
                                    </div>
                                    <div class="small">
                                        <span class="badge text-bg-light border"><?= e($r['application_no'] ?? '') ?></span>
                                        <span class="text-muted">
                                            <?= e($r['school_year'] ?? '') ?> <?= e($accPer) ?> · <?= e(str_replace('_', ' ', $r['app_status'] ?? '')) ?>
                                        </span>
                                    </div>
                                </td>
                                <td><?= e($typeLabel((string) $r['doc_type'])) ?></td>
                                <td>
                                    <?= edu_badge($st, $badge($st)) ?>
                                    <?php if (!empty($r['review_reason'])): ?>
                                        <div class="small text-warning mt-1"><?= e($r['review_reason']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <a href="<?= e(url('/uploads/' . $r['stored_path'])) ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <div class="text-muted">
                                        <?= e($r['original_filename']) ?> · <?= e(number_format((float) $r['file_size_bytes'] / 1024, 1)) ?> KB
                                    </div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if (in_array($st, ['submitted', 'needs_resubmission', 'rejected'], true)): ?>
                                        <form method="post" action="<?= e(url('/accounting/uploads/' . (int) $r['id'] . '/verify')) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-success" onclick="return confirm('Mark this upload VERIFIED? It will be imported to the permanent file.')">
                                                <i class="bi bi-check-lg"></i> Verify
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#up<?= (int) $r['id'] ?>">Request new</button>
                                    <div class="modal fade" id="up<?= (int) $r['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog"><div class="modal-content">
                                            <form method="post" action="<?= e(url('/accounting/uploads/' . (int) $r['id'] . '/request-resubmission')) ?>">
                                                <?= csrf_field() ?>
                                                <div class="modal-header">
                                                    <h3 class="h6 mb-0">Request new <?= e($typeLabel((string) $r['doc_type'])) ?></h3>
                                                </div>
                                                <div class="modal-body text-start">
                                                    <label class="small fw-semibold">Reason (required — student will see this)</label>
                                                    <textarea name="reason" class="form-control" rows="3" required minlength="5" placeholder="e.g. Photo is blurry — please upload a clear 2x2 ID photo."></textarea>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button class="btn btn-sm btn-warning">Send request</button>
                                                </div>
                                            </form>
                                        </div></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <div class="edu-card">
        <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-folder-check"></i> Permanent file</h2>
            <?= edu_badge((string) count($rows), 'neutral') ?>
        </div>
        <div class="edu-card__body p-0">
            <?php if ($rows === []): ?>
                <?= edu_empty_state(
                    'Nothing here',
                    'Verify uploads in the Uploaded files tab to build the permanent file.',
                    ['icon' => 'bi-folder-check', 'compact' => true]
                ) ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Student</th><th scope="col">Document</th><th scope="col">Version</th>
                                <th scope="col">Status</th><th scope="col">File</th><th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></div>
                                    <div class="small text-muted" style="font-family:var(--font-mono)">
                                        <?= e($r['student_no'] ?? '') ?> · <?= e($r['email'] ?? '') ?>
                                    </div>
                                </td>
                                <td><?= e($typeLabel((string) $r['doc_type'])) ?></td>
                                <td>
                                    <span class="badge text-bg-light border">v<?= (int) $r['version'] ?></span>
                                    <div class="small text-muted"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></div>
                                </td>
                                <td>
                                    <?= edu_badge((string) $r['status'], $badge((string) $r['status'])) ?>
                                    <?php if (!empty($r['review_reason'])): ?>
                                        <div class="small text-warning mt-1"><?= e($r['review_reason']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <a href="<?= e(url('/uploads/' . $r['stored_path'])) ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <div class="text-muted"><?= e($r['original_filename']) ?></div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if (in_array($r['status'], ['submitted', 'needs_resubmission', 'rejected'], true)): ?>
                                        <form method="post" action="<?= e(url('/accounting/documents/' . (int) $r['id'] . '/verify')) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-success" onclick="return confirm('Mark this version VERIFIED? It will be reused automatically.')">
                                                <i class="bi bi-check-lg"></i> Verify
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#rs<?= (int) $r['id'] ?>">Request new</button>
                                    <div class="modal fade" id="rs<?= (int) $r['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog"><div class="modal-content">
                                            <form method="post" action="<?= e(url('/accounting/documents/' . (int) $r['id'] . '/request-resubmission')) ?>">
                                                <?= csrf_field() ?>
                                                <div class="modal-header">
                                                    <h3 class="h6 mb-0">Request updated <?= e($typeLabel((string) $r['doc_type'])) ?> (v<?= (int) $r['version'] ?>)</h3>
                                                </div>
                                                <div class="modal-body text-start">
                                                    <label class="small fw-semibold">Reason (required — student will see this)</label>
                                                    <textarea name="reason" class="form-control" rows="3" required minlength="5" placeholder="e.g. ID expired — please upload a valid ID issued within the last year."></textarea>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button class="btn btn-sm btn-warning">Send request</button>
                                                </div>
                                            </form>
                                        </div></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
