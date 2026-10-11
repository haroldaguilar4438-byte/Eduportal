<?php
/**
 * Student academic record (spec §21).
 *
 * Non-destructive: the student-number correction form keeps its action
 * (/admin/students/{id}/student-no), CSRF token, both field names
 * (student_no, reason), the pattern/maxlength/minlength attributes and the
 * confirm() guard. The $rgPer period label helper, the has_account badge, the
 * score >= 75 pass/fail tone, the print link for approved applications and all
 * $student / $enrolled / $grades / $gwa / $enrollHist / $docs contract are
 * preserved verbatim.
 *
 * @var array $student
 * @var array $enrolled
 * @var array $grades
 * @var float|null $gwa
 * @var array $enrollHist
 * @var array $docs
 */
$rgPer = static function ($row): string {
    $v = trim($row['period_code'] ?? '');
    if ($v === '') {
        $v = trim($row['semester'] ?? '');
    }
    if (in_array($v, ['1st', '2nd'], true)) {
        return $v . ' Semester';
    }
    return $v !== '' ? $v : '—';
};

$fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?: '—';
$avgCount = count(array_filter($grades, static fn($g) => $g['score'] !== null));
?>

<?= edu_breadcrumb([
    ['label' => 'Students', 'url' => url('/registrar/students')],
    ['label' => $student['student_no'] ?? ('#' . $student['id'])],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Academic record',
    'title'       => $fullName,
    'description' => ($student['level_name'] ?? '—') . ' · ' . ($student['section_name'] ?? '—')
        . ($student['program_code'] ?? '' !== '' ? ' · ' . $student['program_code'] : ''),
    'icon'        => 'bi-person-vcard',
    'actions'     => edu_status_badge((string) ($student['status'] ?? ''), 'lg'),
]) ?>

<!-- Identity + permanent student number -->
<div class="edu-card mb-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-person-badge"></i> Identity</h2>
        <?php if (empty($student['has_account'])): ?>
            <?= edu_badge('No portal account', 'neutral', 'bi-info-circle', 'Enrolled by a parent — no portal login yet') ?>
        <?php endif; ?>
    </div>
    <div class="edu-card__body">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-6">
                <div class="fw-semibold"><?= e($fullName) ?></div>
                <div class="small text-muted"><?= e($student['email'] ?? '') ?> &middot; <?= e($student['phone'] ?? '') ?></div>
                <div class="mt-2 d-inline-flex align-items-center gap-2 px-2 py-1 rounded-3" style="background:var(--surface-muted);border:1px solid var(--border)">
                    <span class="small text-muted">STUDENT NO. (permanent)</span>
                    <strong style="font-family:var(--font-mono)"><?= e($student['student_no'] ?? '—') ?></strong>
                </div>
            </div>
            <div class="col-12 col-md-6 text-md-end">
                <?php if ($gwa !== null): ?>
                    <div class="mb-2">Average:
                        <span class="edu-badge edu-badge--primary" style="font-size:1rem"><?= e((string) $gwa) ?></span>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/admin/students/' . (int) $student['id'] . '/student-no')) ?>"
                      class="d-flex gap-1 justify-content-md-end flex-wrap">
                    <?= csrf_field() ?>
                    <input name="student_no" class="form-control form-control-sm" style="max-width:150px;font-family:var(--font-mono)"
                           placeholder="Correct no." pattern="\d{7,10}" maxlength="10" required
                           aria-label="Corrected student number">
                    <input name="reason" class="form-control form-control-sm" style="max-width:220px"
                           placeholder="Reason (logged, min 5)" required minlength="5" aria-label="Reason">
                    <button class="btn btn-sm btn-outline-warning flex-none"
                            onclick="return confirm('Correct the permanent Student Number? This is audit-logged.')">Correct</button>
                </form>
                <div class="small text-muted">Authorized correction only — every change is logged.</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Enrolled subjects -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-journal-bookmark"></i> Enrolled subjects</h2>
                <?= edu_badge((string) count($enrolled), 'neutral') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($enrolled === []): ?>
                    <?= edu_empty_state('No subjects', 'This student has no enrolled subjects.', ['icon' => 'bi-journal-x', 'compact' => true]) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Code</th><th>Title</th><th>Term</th></tr></thead>
                            <tbody>
                            <?php foreach ($enrolled as $e): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($e['subject_code']) ?></td>
                                    <td><?= e($e['subject_title']) ?>
                                        <div class="small text-muted"><?= e(trim(($e['t_first'] ?? '') . ' ' . ($e['t_last'] ?? ''))) ?></div>
                                    </td>
                                    <td class="small"><?= e($e['school_year']) ?> <?= e($rgPer($e)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Grades -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-graph-up"></i> Grades by subject</h2>
                <?= edu_badge($avgCount . ' graded', 'neutral') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($grades === []): ?>
                    <?= edu_empty_state('No grades yet', 'Grades appear once teachers submit them.', ['icon' => 'bi-graph-up', 'compact' => true]) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Subject</th><th>Score</th><th>Review</th></tr></thead>
                            <tbody>
                            <?php foreach ($grades as $g):
                                $passed = $g['score'] !== null && $g['score'] >= 75;
                            ?>
                                <tr>
                                    <td><?= e($g['subject_code']) ?> <span class="text-muted small"><?= e($g['title']) ?></span>
                                        <div class="small text-muted"><?= e($g['school_year']) ?> · <?= e($g['teacher'] ?? '') ?></div>
                                    </td>
                                    <td><span class="badge text-bg-<?= $passed ? 'success' : 'warning' ?>"><?= e($g['score'] === null ? '—' : (string) $g['score']) ?></span></td>
                                    <td><span class="badge text-bg-secondary"><?= e($g['review_status'] ?? '') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Enrollment history -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-clock-history"></i> Enrollment history</h2>
                <?= edu_badge('Forms preserved per term', 'neutral', 'bi-file-earmark') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($enrollHist === []): ?>
                    <?= edu_empty_state('No history', 'No enrollment applications on record.', ['icon' => 'bi-clock-history', 'compact' => true]) ?>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($enrollHist as $h): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span><?= e($h['application_no']) ?> · <?= e($h['level_name']) ?>
                                <span class="text-muted">(<?= e($h['school_year']) ?> <?= e($rgPer($h)) ?>)</span>
                            </span>
                            <span class="flex-none">
                                <?= edu_status_badge((string) ($h['status'] ?? '')) ?>
                                <?php if (($h['status'] ?? '') === 'approved' && !empty($h['id'])): ?>
                                    <a class="btn btn-sm btn-light border ms-1" target="_blank" href="<?= e(url('/enroll/' . (int) $h['id'] . '/form')) ?>">
                                        <i class="bi bi-printer"></i> Form
                                    </a>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Documents -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-folder-check"></i> Released documents</h2>
                <?= edu_badge((string) count($docs), 'neutral') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($docs === []): ?>
                    <?= edu_empty_state('No documents', 'Nothing has been released for this student.', ['icon' => 'bi-folder-x', 'compact' => true]) ?>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($docs as $d): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?= e($d['doc_no']) ?> · <?= e($d['doc_type']) ?></span>
                            <?= edu_status_badge((string) $d['status']) ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
