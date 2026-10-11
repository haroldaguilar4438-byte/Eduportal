<?php
/**
 * Enrollment application detail (spec §27).
 *
 * Non-destructive: every route, CSRF token and field name is preserved —
 *   /admin/enrollments/{id}/approve        (no fields)
 *   /admin/enrollments/{id}/action-required (field: remarks)
 *   /admin/enrollments/{id}/reject         (field: remarks)
 *   /admin/enrollments/{id}/mark-paid       (no fields, awaiting_payment only)
 *   /enroll/{id}/form                       (print, target=_blank)
 * as well as the $app / $docs contract, the two money() computations and the
 * class_exists(Tuition) guards. The status label/tone match() maps and the
 * parent-filed-vs-self-filed branch are kept verbatim.
 *
 * @var array $app
 * @var array $docs
 */

$badge = match ($app['status']) {
    'approved'           => 'success',
    'awaiting_payment'   => 'warning',
    'accounting_approved'=> 'info',
    'rejected'           => 'danger',
    'action_required'    => 'warning',
    default              => 'secondary',
};
$label = match ($app['status']) {
    'action_required'     => 'Action Required',
    'awaiting_payment'    => 'Awaiting Payment',
    'accounting_approved' => 'Accounting Approved',
    default               => ucfirst(str_replace('_', ' ', $app['status'])),
};
$amountDue = money(class_exists(\App\Core\Tuition::class)
    ? \App\Core\Tuition::effectiveDue($app['amount_due'] ?? null)
    : (float) ($app['amount_due'] ?? 0));

/* Where this application sits in the Accounting -> Registrar -> PayRex flow. */
$stages = [
    ['label' => 'Submitted',  'state' => 'complete'],
    ['label' => 'Accounting', 'state' => $app['status'] === 'pending' ? 'current' : 'complete'],
    ['label' => 'Registrar',  'state' => in_array($app['status'], ['accounting_approved', 'pending'], true) ? 'current' : 'complete'],
    ['label' => 'Payment',    'state' => $app['status'] === 'awaiting_payment' ? 'current' : ($app['status'] === 'approved' ? 'complete' : '')],
    ['label' => 'Enrolled',   'state' => $app['status'] === 'approved' ? 'complete' : ''],
];

$kid   = trim(trim((string) ($app['child_first'] ?? '')) . ' ' . trim((string) ($app['child_last'] ?? '')));
$filer = trim(trim((string) ($app['first_name'] ?? '')) . ' ' . trim((string) ($app['last_name'] ?? '')));
$isParentFiled = $kid !== '' && mb_strtolower($kid) !== mb_strtolower($filer);
?>

<?= edu_breadcrumb([
    ['label' => 'Enrollments', 'url' => url('/admin/enrollments')],
    ['label' => $app['application_no']],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Application detail',
    'title'       => 'Application ' . $app['application_no'],
    'description' => $isParentFiled
        ? 'Filed by a parent or guardian for ' . $kid . '.'
        : 'Self-filed application for ' . $filer . '.',
    'icon'        => 'bi-journal-check',
    'actions'     => '<a href="' . e(url('/enroll/' . (int) $app['id'] . '/form')) . '" target="_blank" class="btn btn-light btn-sm">'
                   . '<i class="bi bi-printer me-1"></i> Enrollment form</a>'
                   . edu_status_badge((string) $app['status'], 'lg'),
]) ?>

<!-- Stage pipeline -->
<div class="edu-card mb-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-signpost-split"></i> Progress</h2>
        <span class="edu-badge edu-badge--<?= e($badge) ?>"><?= e($label) ?></span>
    </div>
    <div class="edu-card__body">
        <?= edu_stepper($stages) ?>
    </div>
</div>

<?php if (!empty($app['remarks'])): ?>
    <?= edu_callout(
        nl2br(e($app['remarks'])),
        ['tone' => 'warning', 'icon' => 'bi-chat-left-text', 'title' => 'Remarks']
    ) ?>
<?php endif; ?>

<?php if ($app['status'] === 'awaiting_payment'):
    $pay = !empty($app['payment_id'])
        ? \App\Core\Database::one("SELECT * FROM payments WHERE id=? LIMIT 1", [(int) $app['payment_id']])
        : null;
?>
    <?= edu_callout(
        '<strong>Awaiting payment.</strong> Accounting &amp; Registrar approved. The student must pay '
        . $amountDue . ' via PayRex.'
        . ($pay ? ' Ref <code>' . e($pay['transaction_ref']) . '</code> &middot; status '
            . edu_status_badge((string) $pay['status']) : ''),
        ['tone' => 'warning', 'icon' => 'bi-credit-card', 'title' => null]
    ) ?>
    <?php if ($pay && $pay['status'] !== 'paid'): ?>
        <form method="post" action="<?= e(url('/admin/enrollments/' . $app['id'] . '/mark-paid')) ?>" class="mb-3">
            <?= csrf_field() ?>
            <button class="btn btn-success btn-sm" onclick="return confirm('Mark as paid manually?')">
                <i class="bi bi-check-circle me-1"></i> Mark paid (manual)
            </button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-3">
    <!-- Applicant -->
    <div class="col-12 col-lg-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-person"></i> Applicant</h2>
            </div>
            <div class="edu-card__body">
                <?php
                $rows = [];
                if ($isParentFiled) {
                    $rows[] = ['label' => 'Child', 'value' => '<strong>' . e($kid) . '</strong>'
                        . (!empty($app['child_no']) ? ' <span class="text-muted">' . e($app['child_no']) . '</span>' : '')];
                    $rows[] = ['label' => 'Filed by (parent)', 'value' => e($filer)];
                } else {
                    $rows[] = ['label' => 'Name', 'value' => '<strong>' . e($filer) . '</strong>'];
                }
                $rows[] = ['label' => 'Email',    'value' => e($app['email'] ?? '—')];
                $rows[] = ['label' => 'Phone',    'value' => e($app['phone'] ?? '—')];
                $rows[] = ['label' => 'Applied',  'value' => edu_datetime((string) $app['created_at'])];
                ?>
                <?= edu_dl($rows) ?>
            </div>
        </div>
    </div>

    <!-- Academic -->
    <div class="col-12 col-lg-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-mortarboard"></i> Academic</h2>
            </div>
            <div class="edu-card__body">
                <?php
                $rows = [];
                $rows[] = ['label' => 'Level', 'value' => e($app['level_name']) . ' <span class="text-muted">(' . e($app['level_code']) . ')</span>'];
                if ($app['program_name']) {
                    $rows[] = ['label' => 'Program', 'value' => e($app['program_name'])];
                }
                $rows[] = ['label' => 'School year', 'value' => e($app['school_year'])];
                $rows[] = ['label' => 'Type', 'value' => edu_badge(ucfirst((string) $app['new_or_returning']), 'neutral', 'bi-arrow-left-right')];
                if (!empty($app['returning_student_no'])) {
                    $rows[] = ['label' => 'Student no.', 'value' => '<span class="fw-semibold">' . e($app['returning_student_no']) . '</span> <span class="text-muted small">(returning)</span>'];
                }
                if (!empty($app['campus_name'])) {
                    $campus = '<span class="fw-semibold">' . e($app['campus_name']) . '</span>';
                    $addr = e($app['campus_address']);
                    if (!empty($app['campus_city']))     { $addr .= ', ' . e($app['campus_city']); }
                    if (!empty($app['campus_province'])) { $addr .= ', ' . e($app['campus_province']); }
                    $rows[] = ['label' => 'Campus', 'value' => $campus . '<div class="small text-muted">' . $addr . '</div>'];
                } else {
                    $rows[] = ['label' => 'Campus', 'value' => '<span class="text-muted">Not selected</span>'];
                }
                $rows[] = ['label' => 'Previous school', 'value' => e($app['previous_school'])];
                $rows[] = ['label' => 'Last grade', 'value' => e($app['last_grade_completed'] ?? '—')];
                ?>
                <?= edu_dl($rows) ?>
            </div>
        </div>
    </div>

    <!-- Documents -->
    <div class="col-12">
        <div class="edu-card">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-paperclip"></i> Documents</h2>
                <?= edu_badge((string) count($docs), 'neutral') ?>
            </div>
            <div class="edu-card__body">
                <?php if ($docs === []): ?>
                    <?= edu_empty_state(
                        'No documents uploaded',
                        'This application was submitted without supporting documents.',
                        ['icon' => 'bi-file-earmark-x', 'variant' => 'warning', 'compact' => true]
                    ) ?>
                <?php else: ?>
                    <div class="row g-2">
                        <?php foreach ($docs as $d):
                            $typeLabel = match ($d['doc_type']) {
                                'form137'             => 'Form 137',
                                'form138_report_card' => 'Form 138',
                                'birth_certificate'   => 'Birth Cert',
                                'good_moral'          => 'Good Moral',
                                'id_photo'            => 'ID Photo',
                                default               => e($d['doc_type']),
                            };
                            $fileUrl = url('/uploads/' . $d['stored_path']);
                        ?>
                            <div class="col-12 col-md-6">
                                <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background:var(--surface-muted);border:1px solid var(--border)">
                                    <i class="bi bi-file-earmark-text fs-5 text-muted"></i>
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-semibold small"><?= $typeLabel ?></div>
                                        <div class="small text-muted text-truncate"><?= e($d['original_filename']) ?></div>
                                    </div>
                                    <span class="badge text-bg-light border flex-none"><?= number_format($d['file_size_bytes'] / 1024, 1) ?> KB</span>
                                    <a href="<?= e($fileUrl) ?>" target="_blank" class="btn btn-sm btn-light flex-none" aria-label="View <?= $typeLabel ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Actions -->
<?php if ($app['status'] === 'pending' || $app['status'] === 'action_required'): ?>
    <div class="edu-card mt-3">
        <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-check2-square"></i> Decision</h2>
        </div>
        <div class="edu-card__body">
            <?= edu_callout(
                '<strong>Flow:</strong> Accounting &rarr; Registrar &rarr; PayRex. Approving here means both '
                . 'offices approved and instantly sends a PayRex payment link (' . $amountDue . ') to the '
                . 'student. They must pay to become fully enrolled.',
                ['tone' => 'info', 'icon' => 'bi-info-circle', 'title' => null, 'compact' => true]
            ) ?>

            <div class="row g-3 mt-1">
                <div class="col-12 col-md-4">
                    <form method="post" action="<?= e(url('/admin/enrollments/' . $app['id'] . '/approve')) ?>" class="h-100">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-success w-100"
                                onclick="return confirm('Approve (Accounting & Registrar) and send PayRex payment link? Student must pay to be enrolled.')">
                            <i class="bi bi-credit-card"></i> Approve &amp; send PayRex link
                        </button>
                        <div class="small text-muted text-center mt-1">Sets status &rarr; awaiting_payment</div>
                    </form>
                </div>
                <div class="col-12 col-md-4">
                    <form method="post" action="<?= e(url('/admin/enrollments/' . $app['id'] . '/action-required')) ?>" class="h-100">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="ar_remarks_<?= (int) $app['id'] ?>">What is missing?</label>
                        <div class="input-group">
                            <input type="text" name="remarks" id="ar_remarks_<?= (int) $app['id'] ?>" class="form-control" placeholder="What is missing?" required>
                            <button type="submit" class="btn btn-warning flex-none">Flag</button>
                        </div>
                    </form>
                </div>
                <div class="col-12 col-md-4">
                    <form method="post" action="<?= e(url('/admin/enrollments/' . $app['id'] . '/reject')) ?>" class="h-100">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="rj_remarks_<?= (int) $app['id'] ?>">Reason for rejection</label>
                        <div class="input-group">
                            <input type="text" name="remarks" id="rj_remarks_<?= (int) $app['id'] ?>" class="form-control" placeholder="Reason for rejection" required>
                            <button type="submit" class="btn btn-danger flex-none">Reject</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <?= edu_callout(
        'This application is <strong>' . e($label) . '</strong> and can no longer be changed from here.',
        ['tone' => 'info', 'icon' => 'bi-lock', 'title' => null]
    ) ?>
<?php endif; ?>
