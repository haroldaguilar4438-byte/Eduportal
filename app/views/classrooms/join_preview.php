<?php
/**
 * Join subject confirmation (spec §10).
 *
 * Non-destructive: the POST keeps its action (/student/classrooms/join), the
 * CSRF token, and both hidden inputs (invite_code, child_id when acting for a
 * child). All three eligibility branches (not eligible / already joined /
 * confirm) and every link target are preserved, as are $acadCtx, $elig,
 * $viaParent, $childId and $childName.
 *
 * @var array  $offering
 * @var string $code
 */
$acadCtx  = $acadCtx ?? null;
$elig     = $elig ?? null;
$estate   = $elig['state'] ?? '';
$ereason  = $elig['reason'] ?? '';
$viaParent = !empty($viaParent);
$childId  = (int) ($childId ?? 0);
$childName = (string) ($childName ?? '');

/* Join flow: validate code -> review subject -> confirm. */
$joinSteps = [
    ['label' => 'Code',     'hint' => 'Validated', 'state' => 'complete'],
    ['label' => 'Review',   'hint' => 'This page', 'state' => 'current'],
    ['label' => 'Confirm',  'hint' => 'Enroll',    'state' => ''],
    ['label' => 'Subjects', 'hint' => 'My classes','state' => ''],
];
?>

<?= edu_breadcrumb([['label' => 'Dashboard', 'url' => url('/dashboard')], ['label' => 'Join subject']]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Class registration',
    'title'       => 'Join subject',
    'description' => 'Validate code, review the subject, then confirm. The code works for this subject only.',
    'icon'        => 'bi-easel2',
]) ?>

<div class="mx-auto" style="max-width:640px">

    <div class="edu-card mb-3">
        <div class="edu-card__body">
            <?= edu_stepper($joinSteps) ?>
        </div>
    </div>

    <?php if ($viaParent && $childName !== ''): ?>
        <?= edu_callout(
            'You are enrolling <strong>' . e($childName) . '</strong> (parent acting on behalf). '
            . 'Eligibility below is checked against your child\'s enrollment.',
            ['tone' => 'info', 'icon' => 'bi-people', 'title' => null]
        ) ?>
    <?php endif; ?>

    <?php if ($acadCtx !== null && ($acadCtx['is_college'] ?? false)): ?>
        <div class="d-flex align-items-center gap-2 flex-wrap px-3 py-2 rounded-3 mb-3 small" style="background:var(--surface-muted);border:1px solid var(--border)">
            <span class="text-muted">MY CURRENT ENROLLMENT:</span>
            <strong><?= e($acadCtx['short_year'] ?? '') ?></strong>
            <span>&middot;</span>
            <span><?= e(($acadCtx['semester'] ?? '') . ' Semester') ?></span>
            <span>&middot;</span>
            <span><?= e($acadCtx['ay_label'] ?? '') ?></span>
            <?php if (!empty($acadCtx['program_code'])): ?>
                <span>&middot;</span><span><?= e($acadCtx['program_code']) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Subject card -->
    <div class="edu-card mb-3">
        <div class="edu-card__head">
            <?= edu_badge((string) ($offering['subject_code'] ?? ''), 'primary', 'bi-book') ?>
            <span class="edu-badge edu-badge--accent" style="font-family:var(--font-mono)">
                <?= e($offering['invite_code'] ?? $code) ?>
            </span>
        </div>
        <div class="edu-card__body">
            <h2 class="h5 mb-1"><?= e($offering['subject_title'] ?? '') ?></h2>
            <?php
            $meta = [];
            if (!empty($offering['teacher_name'] ?? ''))   { $meta[] = 'Teacher: ' . e($offering['teacher_name']); }
            $sec = 'Section: ' . e($offering['section_name'] ?? '—');
            $sy  = (string) ($offering['school_year'] ?? '');
            $sem = (string) ($offering['semester'] ?? '');
            $meta[] = $sec . ($sy !== '' ? ' · ' . e($sy) : '') . ($sem !== '' ? ' · ' . e($sem) : '');
            if (!empty($offering['schedule'] ?? '')) { $meta[] = 'Schedule: ' . e($offering['schedule']); }
            if (!empty($offering['room'] ?? ''))     { $meta[] = 'Room: ' . e($offering['room']); }
            ?>
            <div class="small text-muted"><?= implode('<br>', $meta) ?></div>
            <?php if (!empty($offering['description'] ?? '')): ?>
                <p class="mb-0 mt-2 small"><?= e($offering['description']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($elig !== null && $estate !== '' && $estate !== 'eligible' && $estate !== 'joined'): ?>
        <?= edu_callout(
            '<strong>Not eligible.</strong> ' . e($ereason !== '' ? $ereason : 'This subject does not match your current enrollment.'),
            ['tone' => 'danger', 'icon' => 'bi-slash-circle', 'title' => null]
        ) ?>
        <div class="d-flex gap-2">
            <a href="<?= e(url('/dashboard')) ?>" class="btn btn-outline-secondary flex-grow-1">Back</a>
            <a href="<?= e(url('/student/class-offerings')) ?>" class="btn btn-primary flex-grow-1">View my eligible subjects</a>
        </div>

    <?php elseif ($elig !== null && $estate === 'joined'): ?>
        <?= edu_callout('You already joined this subject.', ['tone' => 'success', 'icon' => 'bi-check-circle', 'title' => null]) ?>
        <div class="d-flex gap-2">
            <a href="<?= e(url('/dashboard')) ?>" class="btn btn-outline-secondary flex-grow-1">Back</a>
            <a href="<?= e(url('/student/my-classes')) ?>" class="btn btn-success flex-grow-1">Go to my subjects</a>
        </div>

    <?php else: ?>
        <?php if ($elig !== null && $estate === 'eligible'): ?>
            <?= edu_callout('Eligible for this subject.', ['tone' => 'success', 'icon' => 'bi-patch-check', 'title' => null, 'compact' => true]) ?>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/student/classrooms/join')) ?>" class="d-flex gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="invite_code" value="<?= e($offering['invite_code'] ?? $code) ?>">
            <?php if ($viaParent && $childId > 0): ?>
                <input type="hidden" name="child_id" value="<?= $childId ?>">
            <?php endif; ?>
            <a href="<?= e(url('/dashboard')) ?>" class="btn btn-outline-secondary flex-grow-1">Cancel</a>
            <button type="submit" class="btn btn-primary flex-grow-1">
                <i class="bi bi-check-lg"></i>
                <?= $viaParent ? 'Confirm — enroll ' . e($childName) : 'Confirm — enroll me' ?>
            </button>
        </form>
    <?php endif; ?>
</div>
