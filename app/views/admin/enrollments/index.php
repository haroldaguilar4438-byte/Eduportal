<?php
/**
 * Admin — enrollment pipeline (spec §27).
 *
 * Non-destructive: $rows, $status, $counts, $campusFilter and $campuses are used
 * exactly as before. The status pills, the campus GET form (with its hidden
 * `status` field and auto-submit select) and both row links — the detail page
 * and the printable enrollment form — are unchanged.
 *
 * What changed: the flat table became a pipeline view. A stage rail at the top
 * shows the whole queue at a glance, and each row shows which legs it has
 * cleared so an administrator can pick the next action without opening it.
 *
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 * @var int|null $campusFilter
 * @var array  $campuses
 */
$campusFilter = $campusFilter ?? null;
$campuses = $campuses ?? [];
$rows = $rows ?? [];
$status = (string) ($status ?? 'pending');
$counts = $counts ?? [];

/* The seven pipeline stages in the order an application travels them. */
$STAGES = [
    'pending'            => ['Submitted',        'bi-file-earmark-text', 'info'],
    'accounting_approved'=> ['Accounting',       'bi-calculator',        'success'],
    'awaiting_payment'   => ['Payment',          'bi-credit-card',       'warning'],
    'approved'           => ['Approved',         'bi-patch-check-fill',  'success'],
    'rejected'           => ['Rejected',         'bi-x-circle-fill',     'danger'],
    'action_required'    => ['Action required',  'bi-exclamation-triangle-fill', 'warning'],
];

/** Which legs has this application already cleared? */
$pipelineLegs = static function (string $s): array {
    switch ($s) {
        case 'accounting_approved': return ['Accounting' => true, 'Payment' => false, 'Registrar' => false];
        case 'awaiting_payment':    return ['Accounting' => true, 'Payment' => true, 'Registrar' => false];
        case 'approved':            return ['Accounting' => true, 'Payment' => true, 'Registrar' => true];
        case 'rejected':            return ['Accounting' => false, 'Payment' => false, 'Registrar' => false];
        case 'action_required':     return ['Accounting' => false, 'Payment' => false, 'Registrar' => false];
        default:                    return ['Accounting' => false, 'Payment' => false, 'Registrar' => false];
    }
};
?>

<?= edu_page_header([
    'eyebrow'     => 'Admissions',
    'title'       => 'Enrollment queue',
    'description' => 'Every application moves through accounting, payment and the registrar. Open one to act without losing your place in the queue.',
    'actions'     => '<a href="' . e(url('/registrar/enrollments')) . '" class="btn btn-light btn-sm"><i class="bi bi-mortarboard me-1"></i> Registrar queue</a>'
                   . '<a href="' . e(url('/accounting/enrollments')) . '" class="btn btn-light btn-sm"><i class="bi bi-calculator me-1"></i> Accounting queue</a>',
]) ?>

<!-- Pipeline rail -->
<div class="edu-card mb-4">
    <div class="edu-card__body">
        <div class="edu-track">
            <?php $si = 0; foreach ($STAGES as $key => [$label, $icon, $tone]):
                $cnt   = (int) ($counts[$key] ?? 0);
                $isCur = ($status === $key);
                $state = $isCur ? 'is-active' : ($cnt > 0 ? 'is-done' : ''); ?>
              <a class="edu-track__node <?= $state ?>" href="<?= e(url('/admin/enrollments?status=' . $key . ($campusFilter ? '&campus=' . (int) $campusFilter : ''))) ?>"
                 <?= $isCur ? 'aria-current="page"' : '' ?>>
                <div class="edu-track__dot"><i class="bi bi-<?= $isCur ? 'dot' : $icon ?>"></i></div>
                <div class="edu-track__label"><?= e($label) ?></div>
                <div class="edu-track__meta"><?= $cnt ?> app<?= $cnt === 1 ? '' : 's' ?></div>
              </a>
              <?php if ($si < count($STAGES) - 1): ?>
                <div class="edu-track__bar<?= $cnt > 0 ? ' is-done' : '' ?>" aria-hidden="true"></div>
              <?php endif; ?>
              <?php $si++; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Status tabs -->
<?= edu_tabs([
    ['key' => 'pending',             'label' => 'Pending',          'url' => url('/admin/enrollments?status=pending' . ($campusFilter ? '&campus=' . (int) $campusFilter : '')),             'count' => $counts['pending'] ?? 0],
    ['key' => 'accounting_approved', 'label' => 'Acct. approved',   'url' => url('/admin/enrollments?status=accounting_approved' . ($campusFilter ? '&campus=' . (int) $campusFilter : '')), 'count' => $counts['accounting_approved'] ?? 0],
    ['key' => 'awaiting_payment',    'label' => 'Awaiting payment', 'url' => url('/admin/enrollments?status=awaiting_payment' . ($campusFilter ? '&campus=' . (int) $campusFilter : '')),    'count' => $counts['awaiting_payment'] ?? 0],
    ['key' => 'approved',            'label' => 'Approved',         'url' => url('/admin/enrollments?status=approved' . ($campusFilter ? '&campus=' . (int) $campusFilter : '')),            'count' => $counts['approved'] ?? 0],
    ['key' => 'action_required',     'label' => 'Action required',  'url' => url('/admin/enrollments?status=action_required' . ($campusFilter ? '&campus=' . (int) $campusFilter : '')),     'count' => $counts['action_required'] ?? 0],
    ['key' => 'rejected',            'label' => 'Rejected',         'url' => url('/admin/enrollments?status=rejected' . ($campusFilter ? '&campus=' . (int) $campusFilter : '')),            'count' => $counts['rejected'] ?? 0],
    ['key' => 'all',                 'label' => 'All',              'url' => url('/admin/enrollments?status=all' . ($campusFilter ? '&campus=' . (int) $campusFilter : '')),                 'count' => $counts['all'] ?? 0],
], $status) ?>

<!-- Campus filter -->
<form method="get" class="edu-filterbar" aria-label="Filter by campus">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label class="form-label mb-0 flex-none" for="campusFilter"><i class="bi bi-geo-alt me-1"></i> Campus</label>
    <select name="campus" id="campusFilter" class="form-select" onchange="this.form.submit()">
        <option value="">All campuses</option>
        <?php foreach ($campuses as $cp): ?>
            <option value="<?= e((string) $cp['id']) ?>" <?= $campusFilter === (int) $cp['id'] ? ' selected' : '' ?>>
                <?= e($cp['name']) ?> — <?= e($cp['address']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <div class="ms-auto d-flex align-items-center gap-2">
        <?= edu_badge(number_format($counts[$status] ?? 0) . ' shown', 'neutral', 'bi-inbox') ?>
        <?php if ($campusFilter): ?>
            <a href="<?= e(url('/admin/enrollments?status=' . $status)) ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($rows === []): ?>
  <?= edu_empty_state(
        'No applications in this filter',
        'Nothing is waiting at this stage' . ($campusFilter ? ' for the selected campus' : '') . '. Try another stage, or clear the campus filter.',
        ['icon' => 'bi-inbox', 'actions' => '<a href="' . e(url('/admin/enrollments?status=all')) . '" class="btn btn-primary btn-sm">View all applications</a>']
    ) ?>
<?php else: ?>

  <div class="edu-table-wrap">
    <div class="edu-table-scroll">
      <table class="edu-table edu-table--cards">
        <thead>
          <tr>
            <th>App no.</th>
            <th>Applicant</th>
            <th>Level</th>
            <th>Type / Student no.</th>
            <th>Campus</th>
            <th>Year</th>
            <th>Pipeline</th>
            <th>Filed</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $st       = (string) $r['status'];
            $legs     = $pipelineLegs($st);
            $typeLbl  = match ($r['new_or_returning'] ?? 'new') { 'returning' => 'Returning', 'transferee' => 'Transferee', default => 'New' };
            $kid      = trim(trim((string) ($r['child_first'] ?? '')) . ' ' . trim((string) ($r['child_last'] ?? '')));
            $parentFiled = $kid !== '' && mb_strtolower($kid) !== mb_strtolower(trim((string) ($r['applicant'] ?? ''))); ?>
          <tr>
            <td data-label="App no." class="fw-semibold"><?= e($r['application_no']) ?></td>

            <td data-label="Applicant">
              <?php if ($parentFiled): ?>
                <span class="fw-semibold"><?= e($kid) ?></span>
                <div class="small" style="color:var(--text-tertiary)">Filed by parent: <?= e($r['applicant'] ?? '—') ?></div>
              <?php else: ?>
                <?= e($r['applicant'] ?? '—') ?>
                <div class="small" style="color:var(--text-tertiary)"><?= e($r['applicant_email'] ?? '') ?></div>
              <?php endif; ?>
            </td>

            <td data-label="Level">
              <?= e($r['level_name']) ?> <span class="small" style="color:var(--text-tertiary)">(<?= e($r['level_code']) ?>)</span>
              <?php if (!empty($r['program_name'])): ?>
                <div class="small" style="color:var(--text-tertiary)"><?= e($r['program_name']) ?></div>
              <?php endif; ?>
            </td>

            <td data-label="Type">
              <?= edu_badge($typeLbl, 'neutral') ?>
              <?php if (!empty($r['returning_student_no'])): ?>
                <div class="small fw-semibold mt-1" style="color:var(--primary-800)">
                  <i class="bi bi-person-badge me-1"></i><?= e($r['returning_student_no']) ?>
                </div>
              <?php endif; ?>
            </td>

            <td data-label="Campus">
              <?php if (!empty($r['campus_name'])): ?>
                <?= edu_badge(e($r['campus_name']), 'primary', 'bi-geo-alt') ?>
                <div class="small" style="color:var(--text-tertiary)"><?= e($r['campus_address']) ?></div>
              <?php else: ?>
                <span class="small" style="color:var(--text-tertiary)">&mdash;</span>
              <?php endif; ?>
            </td>

            <td data-label="Year"><?= e($r['school_year']) ?></td>

            <td data-label="Pipeline">
              <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
                <?php foreach ($legs as $legName => $cleared): ?>
                  <?= edu_badge(($cleared ? '✓ ' : '⏳ ') . $legName, $cleared ? 'success' : 'neutral', $cleared ? 'bi-check-lg' : 'bi-hourglass') ?>
                <?php endforeach; ?>
              </div>
              <?= edu_status_badge($st) ?>
            </td>

            <td data-label="Filed" class="small" style="color:var(--text-tertiary)"><?= e(edu_date($r['created_at'] ?? null)) ?></td>

            <td data-label="Actions" class="edu-table__actions-cell">
              <div class="edu-table__actions">
                <a href="<?= e(url('/admin/enrollments/' . (int) $r['id'])) ?>" class="btn btn-sm btn-outline-primary">View</a>
                <a href="<?= e(url('/enroll/' . (int) $r['id'] . '/form')) ?>" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary" title="Enrollment form" aria-label="Print enrollment form for <?= e($r['application_no']) ?>">
                  <i class="bi bi-printer"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
