<?php
/**
 * Registrar: re-enrollment cycle (spec §25).
 *
 * Non-destructive: this page is read-only navigation — both queues keep their
 * exact columns (done_noun / done_label / school_year, and next_noun /
 * next_label / next_level), the Clearance Review link, and the explanatory copy
 * describing the College-by-semester vs Kinder-to-Senior-High-by-quarter rule.
 * Only structure and empty states changed.
 *
 * @var array $awaiting
 * @var array $eligible
 */
$awaiting = $awaiting ?? [];
$eligible = $eligible ?? [];
?>

<?= edu_breadcrumb([
    ['label' => 'Re-enrollment', 'url' => url('/registrar/re-enrollment')],
    ['label' => 'Cycle'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Registrar',
    'title'       => 'Re-enrollment cycle',
    'description' => count($awaiting) . ' awaiting academic review · ' . count($eligible) . ' ready to advance',
    'icon'        => 'bi-arrow-repeat',
    'actions'     => '<a href="' . e(url('/registrar/clearances')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-clipboard-check me-1"></i> Clearance review</a>',
]) ?>

<div class="row g-3">
    <!-- Awaiting review -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-hourglass-split"></i> Awaiting academic review</h2>
                <?= edu_badge((string) count($awaiting), count($awaiting) > 0 ? 'warning' : 'neutral') ?>
            </div>
            <div class="edu-card__body">
                <p class="small text-muted">
                    Teacher-issued, registrar undecided. Approve or return with a reason in Clearance Review.
                </p>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($awaiting === []): ?>
                    <?= edu_empty_state('Queue clear', 'Nothing is waiting on the registrar.', ['icon' => 'bi-check2-all', 'compact' => true]) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 small align-middle">
                            <thead><tr><th>#</th><th>Student</th><th>Completed</th></tr></thead>
                            <tbody>
                            <?php foreach ($awaiting as $r): ?>
                                <tr>
                                    <td><?= (int) $r['id'] ?></td>
                                    <td>
                                        <strong><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></strong>
                                        <div class="text-muted"><?= e($r['student_no'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <?= e($r['done_noun'] ?? 'Period') ?>:
                                        <strong><?= e($r['done_label'] ?? '—') ?></strong>
                                        <div class="text-muted"><?= e($r['school_year']) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Eligible -->
    <div class="col-12 col-xl-6">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-arrow-right-circle"></i> Ready for next enrollment</h2>
                <?= edu_badge((string) count($eligible), count($eligible) > 0 ? 'success' : 'neutral') ?>
            </div>
            <div class="edu-card__body">
                <p class="small text-muted">
                    All three legs complete, no open or next application. They see
                    &ldquo;Enroll for Next Period&rdquo;. College advances by Semester; Kinder to Senior High by
                    Quarter/Term &mdash; detected from each student&rsquo;s enrollment.
                </p>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($eligible === []): ?>
                    <?= edu_empty_state('Nobody waiting', 'No student has cleared all three legs yet.', ['icon' => 'bi-people', 'compact' => true]) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 small align-middle">
                            <thead><tr><th>Student</th><th>Completed</th><th>Next</th></tr></thead>
                            <tbody>
                            <?php foreach ($eligible as $r): ?>
                                <tr>
                                    <td>
                                        <strong><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></strong>
                                        <div class="text-muted"><?= e($r['student_no'] ?? '') ?> &middot; <?= e($r['level_name'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <?= e($r['done_noun'] ?? 'Period') ?>: <?= e($r['done_label'] ?? '—') ?>
                                        <div class="text-muted"><?= e($r['school_year']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($r['next_label'])): ?>
                                            <?= edu_badge('Next ' . e($r['next_noun']) . ': ' . e($r['next_label']), 'success', 'bi-arrow-right') ?>
                                            <?php if (!empty($r['next_level'])): ?>
                                                <div class="text-muted"><?= e($r['next_level']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
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
    </div>
</div>
