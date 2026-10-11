<?php
/**
 * Parent: enrollment history with filters (spec §16).
 *
 * Non-destructive: the GET filter form keeps its action, all four field names
 * (child, ay, level, status), the same option values and selected-state logic,
 * the Filter submit and the Reset link. The table keeps its six columns, the
 * child-name fallback, the status tone match() map, and the $rows /
 * $children / $years / $levels / $fChild / $fAy / $fLevel / $fStatus contract.
 * Only presentation changed.
 *
 * @var array  $rows
 * @var array  $children
 * @var array  $years
 * @var array  $levels
 * @var int    $fChild
 * @var int    $fAy
 * @var int    $fLevel
 * @var string $fStatus
 */
$rows     = $rows ?? [];
$children = $children ?? [];
$years    = $years ?? [];
$levels   = $levels ?? [];
$fChild  = (int) ($fChild ?? 0);
$fAy     = (int) ($fAy ?? 0);
$fLevel  = (int) ($fLevel ?? 0);
$fStatus = (string) ($fStatus ?? '');

$childName = static function (array $c): string {
    $n = trim(trim((string) ($c['first_name'] ?? '')) . ' ' . trim((string) ($c['last_name'] ?? '')));
    return $n !== '' ? $n : ('Child #' . (int) ($c['id'] ?? 0));
};
$toneFor = static fn(string $s): string => match ($s) {
    'approved'            => 'success',
    'awaiting_payment'    => 'warning',
    'accounting_approved' => 'info',
    'rejected'            => 'danger',
    'action_required'     => 'warning',
    default               => 'secondary',
};
$statusOptions = [
    'pending'             => 'Pending',
    'accounting_approved' => 'Accounting approved',
    'awaiting_payment'    => 'Awaiting payment',
    'action_required'     => 'Action required',
    'approved'            => 'Approved',
    'rejected'            => 'Rejected',
];
?>

<?= edu_page_header([
    'eyebrow'     => 'Parent portal',
    'title'       => 'Enrollment history',
    'description' => count($children) . ' child(ren) linked · ' . count($rows) . ' application(s) shown',
    'icon'        => 'bi-collection',
    'actions'     => '<a href="' . e(url('/dashboard')) . '" class="btn btn-light btn-sm">Parent portal</a>'
        . '<a href="' . e(url('/enroll')) . '" class="btn btn-primary btn-sm">'
        . '<i class="bi bi-plus-lg"></i> Apply for a child</a>',
]) ?>

<!-- Filters -->
<form method="get" action="<?= e(url('/parent/applications')) ?>" class="edu-card mb-3">
    <div class="edu-card__body">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label" for="f_child">Child</label>
                <select name="child" id="f_child" class="form-select form-select-sm">
                    <option value="0">All children</option>
                    <?php foreach ($children as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $fChild === (int) $c['id'] ? 'selected' : '' ?>><?= e($childName($c)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="f_ay">School year</label>
                <select name="ay" id="f_ay" class="form-select form-select-sm">
                    <option value="0">All years</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int) $y['id'] ?>" <?= $fAy === (int) $y['id'] ? 'selected' : '' ?>><?= e($y['school_year']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="f_level">Level</label>
                <select name="level" id="f_level" class="form-select form-select-sm">
                    <option value="0">All levels</option>
                    <?php foreach ($levels as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= $fLevel === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="f_status">Status</label>
                <select name="status" id="f_status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <?php foreach ($statusOptions as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $fStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-fill">Filter</button>
                <a href="<?= e(url('/parent/applications')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> Applications</h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state(
                'No applications match',
                'Try widening the filters above, or file a new application.',
                [
                    'icon'    => 'bi-collection',
                    'compact' => true,
                    'actions' => '<a href="' . e(url('/enroll')) . '" class="btn btn-sm btn-primary">'
                        . '<i class="bi bi-plus-lg me-1"></i> Apply for a child</a>',
                ]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Application</th><th>Child</th><th>Level</th>
                            <th>School year</th><th>Status</th><th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $cn = trim(trim((string) ($r['child_first'] ?? '')) . ' ' . trim((string) ($r['child_last'] ?? '')));
                        if ($cn === '') {
                            $cn = '—';
                        }
                    ?>
                        <tr>
                            <td class="fw-semibold small" style="font-family:var(--font-mono)"><?= e($r['application_no']) ?></td>
                            <td class="small"><strong><?= e($cn) ?></strong></td>
                            <td class="small"><?= e($r['level_name'] ?? '') ?></td>
                            <td class="small text-muted"><?= e($r['school_year'] ?? '') ?></td>
                            <td><?= edu_badge(str_replace('_', ' ', (string) $r['status']), $toneFor((string) $r['status'])) ?></td>
                            <td class="small text-muted"><?= e(isset($r['created_at']) ? date('M j, Y', strtotime($r['created_at'])) : '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
