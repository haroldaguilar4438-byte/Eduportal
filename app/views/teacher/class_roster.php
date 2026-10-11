<?php
/**
 * Class roster (spec §18).
 *
 * Non-destructive: $offering and $students are used exactly as before; every
 * column (student no, name, email, registered date, status) is preserved.
 * This is a read-only roster, so no per-student actions are invented.
 *
 * @var array $offering
 * @var array $students
 */
$offering = $offering ?? [];
$students = $students ?? [];

$active = 0;
foreach ($students as $s) {
    if (in_array((string) ($s['status'] ?? ''), ['registered', 'active', 'enrolled'], true)) {
        $active++;
    }
}
?>

<?= edu_page_header([
    'eyebrow'     => 'Class management',
    'title'       => 'Class roster — ' . e($offering['code'] ?? ''),
    'description' => e($offering['title'] ?? '') . ' · ' . count($students)
        . ' registered student' . (count($students) === 1 ? '' : 's'),
    'actions'     => '<a href="' . e(url('/dashboard')) . '" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to dashboard</a>',
]) ?>

<?php if ($students === []): ?>
  <?= edu_empty_state(
        'No students registered yet',
        'Students appear here once they join this class with your invite code or a teacher approval.',
        ['icon' => 'bi-people']
    ) ?>
<?php else: ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-4"><?= edu_stat_card(['label' => 'Students', 'value' => (string) count($students), 'icon' => 'bi-people-fill', 'tone' => 'primary']) ?></div>
    <div class="col-6 col-lg-4"><?= edu_stat_card(['label' => 'Active', 'value' => (string) $active, 'icon' => 'bi-person-check-fill', 'tone' => 'success']) ?></div>
    <div class="col-12 col-lg-4"><?= edu_stat_card([
        'label' => 'Section',
        'value' => '<span style="font-size:1.25rem">' . e($offering['section_name'] ?? $offering['code'] ?? '—') . '</span>',
        'icon'  => 'bi-door-open', 'tone' => 'info',
        'meta'  => !empty($offering['schedule']) ? '<span>' . e($offering['schedule']) . '</span>' : '',
    ]) ?></div>
  </div>

  <div class="edu-table-wrap">
    <div class="edu-table-scroll">
      <table class="edu-table edu-table--cards">
        <thead>
          <tr>
            <th>Student no.</th>
            <th>Name</th>
            <th>Email</th>
            <th>Registered</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td data-label="Student no." class="fw-semibold"><?= e($s['student_no'] ?? '—') ?></td>
            <td data-label="Name"><?= e($s['student_name'] ?? '') ?></td>
            <td data-label="Email" class="small" style="color:var(--text-tertiary)"><?= e($s['email'] ?? '') ?></td>
            <td data-label="Registered" class="small" style="color:var(--text-tertiary)"><?= e(edu_date($s['registered_at'] ?? null)) ?></td>
            <td data-label="Status"><?= edu_status_badge((string) ($s['status'] ?? 'registered')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
