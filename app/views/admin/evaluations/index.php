<?php
/**
 * Admin: Online Teachers Evaluation control (spec §33).
 *
 * Non-destructive: the open-window form keeps its action (/admin/evaluations),
 * CSRF token and all four field names (academic_year_id, term, period), the
 * same options and the current-AY default. Every per-window form keeps its
 * /close or /open route and CSRF token, the Results link keeps its
 * period_id query, and the $periods / $academicYears / $results / $selectedId
 * contract is unchanged.
 *
 * @var array      $periods
 * @var array      $academicYears
 * @var array      $results
 * @var int        $selectedId
 */
$openWindow = null;
foreach ($periods as $p) {
    if ($p['status'] === 'open') {
        $openWindow = $p;
        break;
    }
}
?>

<?= edu_breadcrumb([
    ['label' => 'Dashboard', 'url' => url('/dashboard')],
    ['label' => 'Admin'],
    ['label' => 'Evaluations'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Admin',
    'title'       => 'Online teachers evaluation',
    'description' => 'Open or close the evaluation window. Students can only evaluate while a window is open.',
    'icon'        => 'bi-star',
    'actions'     => $openWindow !== null
        ? edu_badge('Window open', 'success', 'bi-unlock')
        : edu_badge('No open window', 'neutral', 'bi-lock'),
]) ?>

<div class="row g-3">
    <!-- Open a window -->
    <div class="col-12 col-xl-4">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-door-open"></i> Open evaluation</h2>
            </div>
            <div class="edu-card__body">
                <form method="post" action="<?= e(url('/admin/evaluations')) ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-12">
                        <label class="form-label" for="eval_ay">Academic year</label>
                        <select name="academic_year_id" id="eval_ay" class="form-select" required>
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?= (int) $ay['id'] ?>" <?= !empty($ay['is_current']) ? 'selected' : '' ?>>
                                    <?= e($ay['school_year']) ?><?= !empty($ay['is_current']) ? ' (current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="eval_term">Term</label>
                        <select name="term" id="eval_term" class="form-select">
                            <option value="1st">1st Term</option>
                            <option value="2nd">2nd Term</option>
                            <option value="Summer">Summer</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="eval_period">Period</label>
                        <select name="period" id="eval_period" class="form-select">
                            <option value="Prelim">Prelim Period</option>
                            <option value="Midterm" selected>Midterm Period</option>
                            <option value="Prefinal">Prefinal Period</option>
                            <option value="Finals">Finals Period</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100"><i class="bi bi-door-open"></i> Open for students</button>
                        <div class="form-hint">Opening closes any other open window automatically.</div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Windows -->
    <div class="col-12 col-xl-8">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-clock-history"></i> Evaluation windows</h2>
                <?= edu_badge((string) count($periods), 'neutral') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($periods === []): ?>
                    <?= edu_empty_state(
                        'No windows yet',
                        'Open one — students will see the closed notice until then.',
                        ['icon' => 'bi-calendar-x', 'compact' => true]
                    ) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>Window</th><th>Status</th><th>Responses</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($periods as $p): ?>
                                <tr>
                                    <td class="fw-semibold">
                                        AY <?= e($p['school_year']) ?> <?= e($p['term']) ?> Term
                                        <span class="text-muted fw-normal">(<?= e($p['period']) ?> Period)</span>
                                    </td>
                                    <td><?= $p['status'] === 'open'
                                        ? edu_badge('OPEN', 'success', 'bi-unlock')
                                        : edu_badge('Closed', 'danger', 'bi-lock') ?></td>
                                    <td class="small">
                                        <?= (int) ($p['eval_count'] ?? 0) ?> ·
                                        <?= $p['avg_rating'] !== null ? e((string) $p['avg_rating']) . ' ★' : '—' ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($p['status'] === 'open'): ?>
                                            <form method="post" action="<?= e(url('/admin/evaluations/' . (int) $p['id'] . '/close')) ?>" class="d-inline">
                                                <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Close</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="<?= e(url('/admin/evaluations/' . (int) $p['id'] . '/open')) ?>" class="d-inline">
                                                <?= csrf_field() ?><button class="btn btn-sm btn-outline-success">Re-open</button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="<?= e(url('/admin/evaluations?period_id=' . (int) $p['id'])) ?>" class="btn btn-sm btn-outline-secondary">Results</a>
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

<!-- Results -->
<div class="edu-card mt-3">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-bar-chart"></i> Results by teacher</h2>
        <span class="small text-muted">Anonymous averages — students never see names attached to comments here.</span>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($results === []): ?>
            <?= edu_empty_state(
                'No responses for this window yet',
                'Results appear once students submit their evaluation.',
                ['icon' => 'bi-bar-chart', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr><th>Teacher</th><th>Class</th><th>Responses</th><th>Avg rating</th></tr></thead>
                    <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['teacher'] !== '' ? $r['teacher'] : 'TBA') ?></td>
                            <td>
                                <span class="badge text-bg-light border"><?= e($r['subject_code'] ?? '') ?></span>
                                <span class="small text-muted"><?= e($r['subject_title'] ?? '') ?></span>
                            </td>
                            <td><?= (int) $r['eval_count'] ?></td>
                            <td><span class="edu-badge edu-badge--accent"><?= e((string) $r['avg_rating']) ?> ★</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
