<?php
/**
 * Admin: class-offering roster (spec §28).
 *
 * Non-destructive: the remove-student form keeps its route
 * (/admin/class-offerings/{id}/remove-student), CSRF token, hidden student_id
 * and confirm() guard. The DRAFT badge when no teacher is assigned, the
 * teacher name + employee number, the section / school-year / invite code line
 * and the registration columns are all unchanged.
 *
 * @var array $offering
 * @var array $regs
 */
$offering = $offering ?? [];
$regs     = $regs ?? [];
$hasTeacher = !empty($offering['teacher_id']);
?>

<?= edu_breadcrumb([
    ['label' => 'Subjects', 'url' => url('/admin/class-offerings')],
    ['label' => (string) ($offering['subject_code'] ?? '')],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Subject',
    'title'       => (string) ($offering['subject_code'] ?? '') . ' — ' . ($offering['subject_title'] ?? ''),
    'description' => ($hasTeacher
            ? trim(($offering['teacher_name'] ?? '') . ' (' . ($offering['employee_no'] ?? '') . ')')
            : 'No teacher assigned yet')
        . ' · ' . ($offering['section_name'] ?? '—') . ' · ' . ($offering['school_year'] ?? ''),
    'icon'        => 'bi-easel2',
    'actions'     => ($hasTeacher ? '' : edu_badge('DRAFT — no teacher assigned', 'warning', 'bi-exclamation-triangle') . ' ')
        . '<span class="edu-badge edu-badge--accent" style="font-family:var(--font-mono)" title="Invite code">'
        . e($offering['invite_code'] ?? '') . '</span>',
]) ?>

<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-people"></i> Joined students</h2>
        <?= edu_badge((string) count($regs), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($regs === []): ?>
            <?= edu_empty_state(
                'No students joined yet',
                'Share the invite code above so students can join this subject.',
                ['icon' => 'bi-people', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Student</th><th>Email</th><th>Joined</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($regs as $r): ?>
                        <tr>
                            <td>
                                <strong><?= e($r['name']) ?></strong>
                                <div class="small text-muted"><?= e($r['student_no'] ?? '') ?></div>
                            </td>
                            <td class="small"><?= e($r['email'] ?? '') ?></td>
                            <td class="small text-muted"><?= e($r['registered_at'] ?? '') ?></td>
                            <td class="text-end">
                                <form method="post" action="<?= e(url('/admin/class-offerings/' . (int) $offering['id'] . '/remove-student')) ?>"
                                      class="d-inline" onsubmit="return confirm('Remove this student?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="student_id" value="<?= (int) ($r['student_id'] ?? 0) ?>">
                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
