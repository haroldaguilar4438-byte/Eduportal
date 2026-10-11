<?php /** @var array $bands @var string $basicStructure */
$bands = $bands ?? [];
$basicStructure = $basicStructure ?? 'quarter';
$bandMeta = [
    'basic' => ['title' => 'Basic Education — Kindergarten to Grade 10', 'hint' => 'Quarter-based by default; switch to Terms (trimesters) for S.Y. 2026–2027 and beyond. Students enroll, join, clear, and are graded per ' . ($basicStructure === 'trimester' ? 'Term' : 'Quarter') . '.'],
    'shs' => ['title' => 'Senior High School — Grade 11 to Grade 12', 'hint' => 'Semester-based: Grade 11 (1st → 2nd) → Grade 12 (1st → 2nd). Never quarter-based.'],
    'college' => ['title' => 'College — 1st Year to 4th Year', 'hint' => 'Existing semester workflow (1st / 2nd / Summer). Untouched by Basic-Ed configuration.'],
];
?>
<?= edu_page_header([
    'eyebrow'     => 'Academic structure',
    'title'       => 'Academic calendar',
    'description' => 'One centralized calendar per education band. Enrollment, offerings, joining, grading, clearance and documents all read from here.',
    'actions'     => '<a href="' . e(url('/admin/class-offerings')) . '" class="btn btn-light btn-sm"><i class="bi bi-easel2 me-1"></i> Class offerings</a>',
    'icon'        => 'bi-calendar3',
]) ?>

<!-- Basic Education structure switcher -->
<div class="edu-card mb-4">
  <div class="edu-card__head">
    <h2 class="edu-card__title"><i class="bi bi-mortarboard"></i> Basic Education structure</h2>
    <?= $basicStructure === 'trimester' ? edu_badge('Terms — T1, T2, T3', 'info', 'bi-calendar-range') : edu_badge('Quarters — Q1, Q2, Q3, Q4', 'success', 'bi-calendar3') ?>
  </div>
  <div class="edu-card__body">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <span class="small" style="color:var(--text-tertiary)">Currently:</span>
      <form method="post" action="<?= e(url('/admin/academic-calendar/structure')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="structure" value="quarter"><button class="btn btn-sm <?= $basicStructure === 'quarter' ? 'btn-success' : 'btn-outline-success' ?>" <?= $basicStructure === 'quarter' ? 'disabled' : '' ?> onclick="return confirm('Switch Basic Education to Quarters (Q1–Q4)?')"><i class="bi bi-check-lg me-1"></i>Quarters</button></form>
      <form method="post" action="<?= e(url('/admin/academic-calendar/structure')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="structure" value="trimester"><button class="btn btn-sm <?= $basicStructure === 'trimester' ? 'btn-info' : 'btn-outline-info' ?>" <?= $basicStructure === 'trimester' ? 'disabled' : '' ?> onclick="return confirm('Switch Basic Education to Terms (T1–T3)? In-use quarters stay active until their records complete.')"><i class="bi bi-check-lg me-1"></i>Terms</button></form>
    </div>
    <p class="small mb-0" style="color:var(--text-tertiary)">
      Switching flips the seeded Quarter/Term sets. Custom periods you add below are never flipped.
      Periods referenced by records cannot be deactivated or deleted.
    </p>
  </div>
</div>

<?php foreach (['basic', 'shs', 'college'] as $band):
    $b = $bands[$band] ?? null;
    if (!$b) continue;
    $periodList = $b['periods'] ?? [];
    $activeCount = 0;
    $usedCount = 0;
    foreach ($periodList as $p) {
      if (!empty($p['is_active'])) $activeCount++;
      if (!empty($p['used'])) $usedCount++;
    } ?>
  <div class="edu-card mb-3">
    <div class="edu-card__head">
      <div class="min-w-0">
        <h2 class="edu-card__title mb-1"><?= e($bandMeta[$band]['title']) ?></h2>
        <div class="small" style="color:var(--text-tertiary)"><?= e($bandMeta[$band]['hint']) ?></div>
      </div>
      <div class="d-flex gap-1 flex-wrap flex-none">
        <?= edu_badge($activeCount . ' active', 'success', 'bi-check-circle-fill') ?>
        <?= edu_badge($usedCount . ' in use', $usedCount > 0 ? 'warning' : 'neutral', 'bi-link-45deg') ?>
      </div>
    </div>
    <div class="edu-card__body">
      <?php if ($periodList === []): ?>
        <?= edu_empty_state('No periods configured', 'Add a custom period for this band below.', ['icon' => 'bi-calendar-x', 'compact' => true]) ?>
      <?php else: ?>
        <div class="edu-table-wrap" style="box-shadow:none;border:0">
          <div class="edu-table-scroll">
            <table class="edu-table edu-table--compact edu-table--cards">
              <thead><tr><th>Code</th><th>Display name</th><th>Status</th><th>Records</th><th class="text-end">Actions</th></tr></thead>
              <tbody>
              <?php foreach ($periodList as $p):
                  $used = !empty($p['used']);
                  $isOn = !empty($p['is_active']);
                  $usageSum = array_sum((array) ($p['usage'] ?? [])); ?>
                <tr>
                  <td data-label="Code"><code class="fw-bold"><?= e($p['code']) ?></code></td>
                  <td data-label="Display name"><?= e($p['name']) ?></td>
                  <td data-label="Status"><?= $isOn ? edu_badge('Active', 'success', 'bi-check-circle-fill') : edu_badge('Inactive', 'neutral', 'bi-dash-circle') ?></td>
                  <td data-label="Records">
                    <?php if ($used): ?>
                      <?= edu_badge('In use (' . $usageSum . ')', 'info', 'bi-link-45deg') ?>
                      <div class="small mt-1" style="color:var(--text-tertiary)">
                        Enrollments <?= (int) $p['usage']['enrollments'] ?> &middot; Offerings <?= (int) $p['usage']['offerings'] ?>
                        &middot; Clearances <?= (int) $p['usage']['clearances'] ?> &middot; Sheets <?= (int) $p['usage']['sheets'] ?>
                      </div>
                    <?php else: ?>
                      <?= edu_badge('Unused', 'neutral') ?>
                    <?php endif; ?>
                  </td>
                  <td data-label="Actions" class="edu-table__actions-cell">
                    <div class="edu-table__actions">
                      <form method="post" action="<?= e(url('/admin/academic-calendar/' . (int) $p['id'] . '/toggle')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm <?= $isOn ? 'btn-outline-secondary' : 'btn-outline-success' ?>" <?= ($isOn && $used) ? 'disabled title="In use — cannot deactivate"' : '' ?>><?= $isOn ? 'Deactivate' : 'Activate' ?></button></form>
                      <form method="post" action="<?= e(url('/admin/academic-calendar/' . (int) $p['id'] . '/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete <?= e($p['code']) ?>? Only unused periods can be removed.')"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" aria-label="Delete <?= e($p['code']) ?>" <?= $used ? 'disabled title="In use — cannot delete"' : '' ?>><i class="bi bi-trash"></i></button></form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<!-- Add custom period -->
<div class="edu-card mb-3">
  <div class="edu-card__head">
    <h2 class="edu-card__title"><i class="bi bi-plus-lg"></i> Add custom period</h2>
    <?= edu_badge('Institution-approved structures', 'neutral') ?>
  </div>
  <div class="edu-card__body">
    <form method="post" action="<?= e(url('/admin/academic-calendar')) ?>" class="row g-2" novalidate>
      <?= csrf_field() ?>
      <div class="col-12 col-md-3">
        <label class="form-label" for="calBand">Band <span class="req">*</span></label>
        <select name="band" id="calBand" class="form-select" required>
          <option value="basic">Basic Education (K–G10)</option>
          <option value="shs">Senior High (G11–G12)</option>
          <option value="college">College</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="calCode">Code <span class="req">*</span></label>
        <input name="code" id="calCode" class="form-control" placeholder="e.g. Q1, T1" required maxlength="20" style="text-transform:uppercase">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="calName">Display name <span class="req">*</span></label>
        <input name="name" id="calName" class="form-control" placeholder="e.g. Quarter 1" required maxlength="40">
      </div>
      <div class="col-12 col-md-2 d-flex align-items-end">
        <button class="btn btn-sm btn-primary w-100">Add &amp; activate</button>
      </div>
    </form>
  </div>
</div>
