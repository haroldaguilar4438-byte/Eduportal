<?php
/**
 * Edit activity (spec §9).
 *
 * Non-destructive: the form keeps its action
 * (/teacher/classroom-tasks/{id}/update), multipart encoding, CSRF token and
 * every field name — title, instructions, task_file, remove_file, category_id,
 * max_score, period, passing_score, due_at — with all original attributes
 * (maxlength, accept, min/max/step, the max_score oninput/oninvalid
 * validators, the datetime-local format). The $locked / $isArchived logic and
 * the hidden inputs that substitute for disabled selects are preserved
 * verbatim; those three fields are read-only once submissions exist.
 *
 * @var array  $task
 * @var array  $cats
 * @var array  $periods
 * @var int    $subCount
 * @var bool   $isArchived
 */
$task       = $task ?? [];
$cats       = $cats ?? [];
$periods    = $periods ?? [];
$subCount   = (int) ($subCount ?? 0);
$isArchived = (bool) ($isArchived ?? false);

$dueVal = '';
if (!empty($task['due_at']) && strtotime((string) $task['due_at']) !== false) {
    $dueVal = date('Y-m-d\TH:i', strtotime((string) $task['due_at']));
}
$locked = $subCount > 0;

$curPeriodCode = '';
foreach ($periods as $p) {
    if ((int) ($task['period_id'] ?? 0) === (int) $p['id']) {
        $curPeriodCode = (string) $p['code'];
        break;
    }
}
?>

<?= edu_breadcrumb([['label' => 'Activity', 'url' => url('/classroom-tasks/' . (int) ($task['id'] ?? 0))], ['label' => 'Edit']]) ?>

<?= edu_page_header([
    'eyebrow'     => ($task['subject_code'] ?? '') . ' · ' . ($task['subject_title'] ?? ''),
    'title'       => 'Edit activity',
    'description' => $locked
        ? 'Grading fields are locked while submissions exist. Title, instructions and deadline stay editable.'
        : 'Update the activity details students will see.',
    'icon'        => 'bi-pencil-square',
    'actions'     => $isArchived
        ? edu_badge('Archived (past due)', 'neutral', 'bi-archive')
        : edu_badge('Active', 'success', 'bi-check-circle'),
]) ?>

<div class="mx-auto" style="max-width:760px">

    <?php if ($isArchived): ?>
        <?= edu_callout(
            'This activity is <strong>archived</strong> (deadline passed). You can still edit it — giving it a '
            . 'future deadline moves it back to active. Student submissions stay intact.',
            ['tone' => 'neutral', 'icon' => 'bi-archive', 'title' => null]
        ) ?>
    <?php endif; ?>

    <?php if ($locked): ?>
        <?= edu_callout(
            'Grading fields (category, max score, grading period) are <strong>locked</strong> — '
            . $subCount . ' submission(s) already reference this activity.',
            ['tone' => 'warning', 'icon' => 'bi-lock', 'title' => null]
        ) ?>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/teacher/classroom-tasks/' . (int) ($task['id'] ?? 0) . '/update')) ?>"
          enctype="multipart/form-data">
        <?= csrf_field() ?>

        <!-- Details -->
        <div class="edu-card mb-3">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-card-text"></i> Details</h2>
            </div>
            <div class="edu-card__body">
                <div class="mb-3">
                    <label for="task_title" class="form-label">Title <span class="req">*</span></label>
                    <input id="task_title" name="title" class="form-control" required maxlength="191" value="<?= e($task['title'] ?? '') ?>">
                </div>
                <div class="mb-0">
                    <label for="task_instructions" class="form-label">Instructions</label>
                    <textarea id="task_instructions" name="instructions" class="form-control" rows="4"><?= e($task['instructions'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Attachment -->
        <div class="edu-card mb-3">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-paperclip"></i> Attached file</h2>
            </div>
            <div class="edu-card__body">
                <label for="task_file" class="form-label">Attachment</label>
                <?php if (!empty($task['file_path'])): ?>
                    <div class="d-flex align-items-center gap-2 p-2 rounded-3 mb-2" style="background:var(--surface-muted);border:1px solid var(--border)">
                        <i class="bi bi-file-earmark-text text-primary"></i>
                        <span class="small fw-semibold text-truncate"><?= e($task['original_filename'] ?? 'Attached file') ?></span>
                        <a href="<?= e(url('/classroom-tasks/' . (int) ($task['id'] ?? 0) . '/file')) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 flex-none">View</a>
                        <label class="small text-danger ms-auto mb-0 flex-none">
                            <input type="checkbox" name="remove_file" value="1" class="form-check-input me-1">Remove file
                        </label>
                    </div>
                <?php endif; ?>
                <input id="task_file" type="file" name="task_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt,.zip">
                <div class="form-hint">PDF, JPG, PNG, WebP, DOC/DOCX, TXT, ZIP — max 10 MB. Uploading a new file replaces the current one. Students download it from the activity page (permit-checked for exams).</div>
            </div>
        </div>

        <!-- Grading -->
        <div class="edu-card mb-3">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-clipboard-check"></i> Grading</h2>
                <?php if ($locked): ?>
                    <?= edu_badge('Locked', 'warning', 'bi-lock', 'Submissions reference this activity') ?>
                <?php endif; ?>
            </div>
            <div class="edu-card__body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="task_category" class="form-label">Category</label>
                        <select id="task_category" name="category_id" class="form-select" <?= $locked ? 'disabled' : '' ?>>
                            <option value="">Activities (default)</option>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= ((int) ($task['category_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($locked): ?>
                            <input type="hidden" name="category_id" value="<?= (int) ($task['category_id'] ?? 0) ?>">
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="task_max" class="form-label">Total items / max score (1&ndash;100)</label>
                        <input id="task_max" name="max_score" type="number" class="form-control" inputmode="decimal" min="1" max="100" step="1" required
                               value="<?= e((string) ($task['max_score'] ?? '100')) ?>" <?= $locked ? 'readonly' : '' ?>
                               oninput="if(this.value!==''&&(parseFloat(this.value)<1||parseFloat(this.value)>100))this.setCustomValidity('Total items must be between 1 and 100.');else this.setCustomValidity('');"
                               oninvalid="this.setCustomValidity('Total items must be between 1 and 100.')">
                        <div class="form-hint">Item-based (exams, quizzes, activities): whole number. Raw scores validate against this total.</div>
                    </div>

                    <div class="col-md-6">
                        <label for="task_period" class="form-label">Grading period</label>
                        <select id="task_period" name="period" class="form-select" <?= $locked ? 'disabled' : '' ?>>
                            <option value="">Every period (legacy)</option>
                            <?php foreach ($periods as $p): ?>
                                <option value="<?= e($p['code']) ?>" <?= ((int) ($task['period_id'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>><?= e($p['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($locked): ?>
                            <input type="hidden" name="period" value="<?= e($curPeriodCode) ?>">
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="task_passing" class="form-label">Passing score <span class="text-muted fw-normal">(optional)</span></label>
                        <input id="task_passing" name="passing_score" type="number" class="form-control" inputmode="decimal" min="1" max="100" step="0.01"
                               value="<?= e(isset($task['passing_score']) && $task['passing_score'] !== null ? (string) $task['passing_score'] : '') ?>"
                               placeholder="e.g., 30">
                        <div class="form-hint">You decide (e.g. 30/50). Empty = no pass/fail. Stays editable.</div>
                    </div>

                    <div class="col-12">
                        <label for="task_due" class="form-label">Deadline <span class="text-muted fw-normal">(empty = no deadline, never archives)</span></label>
                        <input id="task_due" type="datetime-local" name="due_at" class="form-control" value="<?= e($dueVal) ?>">
                        <div class="form-hint">Must be at least 5 minutes in the future.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save changes</button>
            <a href="<?= e(url('/classroom-tasks/' . (int) ($task['id'] ?? 0))) ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
