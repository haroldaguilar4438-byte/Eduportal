<?php
/**
 * Student document centre (spec §14).
 *
 * Non-destructive: $rows, $types, $academicYears, $status, $counts,
 * $termOptions, $termNoun, $isParent, $linkedChildren and $actingChild are all
 * used exactly as before. The request form keeps every field name, its CSRF
 * token and the parent child_id passthrough; the status filter pills, the View
 * link and the Print link (released only) are unchanged.
 *
 * What changed: requests are shown as document cards grouped by category
 * (Academic / Enrollment / Financial / Identification / Certificate), each with
 * an icon, status, date and actions — which is what a document centre is.
 *
 * @var array $rows
 * @var array $types
 * @var array $academicYears
 * @var string $status
 * @var array $counts
 */
$rows = $rows ?? [];
$types = $types ?? [];
$academicYears = $academicYears ?? [];
$status = (string) ($status ?? 'all');
$counts = $counts ?? [];
$termOptions = $termOptions ?? ['1st', '2nd', 'Summer'];
$termNoun = (string) ($termNoun ?? 'Semester');
$isParent = $isParent ?? false;
$linkedChildren = $linkedChildren ?? [];
$actingChild = $actingChild ?? null;

/* Map a document type onto one of the five categories in the spec. */
$docCategory = static function (string $t): array {
    $t = strtolower($t);
    return match (true) {
        str_contains($t, 'grade'), str_contains($t, 'report'), str_contains($t, 'transcript'), str_contains($t, 'honor')
            => ['Academic', 'bi-journal-check'],
        str_contains($t, 'enroll'), str_contains($t, 'form'), str_contains($t, 'certif')
            => ['Enrollment', 'bi-file-earmark-text'],
        str_contains($t, 'receipt'), str_contains($t, 'paid'), str_contains($t, 'tuition'), str_contains($t, 'account')
            => ['Financial', 'bi-receipt'],
        str_contains($t, 'id'), str_contains($t, 'photo'), str_contains($t, 'ident')
            => ['Identification', 'bi-person-vcard'],
        default => ['Certificates', 'bi-award'],
    };
};

$docStatusMeta = static function (string $s): array {
    return match ($s) {
        'released'  => ['badge' => 'success', 'icon' => 'bi-patch-check-fill'],
        'ready'     => ['badge' => 'info',    'icon' => 'bi-file-earmark-check'],
        'rejected'  => ['badge' => 'danger',  'icon' => 'bi-x-circle-fill'],
        'cancelled' => ['badge' => 'neutral', 'icon' => 'bi-slash-circle'],
        'processing'=> ['badge' => 'info',    'icon' => 'bi-arrow-repeat'],
        default     => ['badge' => 'warning', 'icon' => 'bi-hourglass-split'],
    };
};

/* Group into categories, preserving the controller's row order. */
$grouped = [];
foreach ($rows as $r) {
    [$cat, $catIcon] = $docCategory((string) ($r['doc_type'] ?? ''));
    $grouped[$cat][] = ['row' => $r, 'icon' => $catIcon];
}
?> 

<?= edu_page_header([
    'eyebrow'     => 'Document centre',
    'title'       => 'Academic documents',
    'description' => 'Request stamped documents and download them once the Registrar releases them.',
    'actions'     => '<a href="' . e(url('/student/grades')) . '" class="btn btn-light btn-sm"><i class="bi bi-percent me-1"></i> My grades</a>',
]) ?>

<?= edu_callout(
    'Released grades appear on your <a href="' . e(url('/student/grades')) . '">Grades</a> page. A <strong>Grade Slip</strong> is a separate stamped document — request it below and it will appear here under <strong>Released</strong> once the Registrar issues it.',
    ['tone' => 'info', 'icon' => 'bi-info-circle-fill', 'title' => 'Grades versus grade slips']
) ?>

<!-- Status filter -->
<?= edu_tabs([
    ['key' => 'all',       'label' => 'All',          'url' => url('/student/documents?status=all'),       'count' => $counts['all']       ?? 0],
    ['key' => 'pending',   'label' => 'Pending',      'url' => url('/student/documents?status=pending'),   'count' => $counts['pending']   ?? 0],
    ['key' => 'released',  'label' => 'Released',     'url' => url('/student/documents?status=released'),  'count' => $counts['released']  ?? 0],
    ['key' => 'rejected',  'label' => 'Rejected',     'url' => url('/student/documents?status=rejected'),  'count' => $counts['rejected']  ?? 0],
], $status) ?>

<?php if ($isParent && !empty($linkedChildren)): ?>
  <?= edu_section_head('Managing', 'Switch between your linked children', '', 'bi-people') ?>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($linkedChildren as $lc):
        $lcActive = $actingChild && (int) $lc['id'] === (int) $actingChild['id'];
        $lcName = trim(implode(' ', array_filter([trim((string) ($lc['first_name'] ?? '')), trim((string) ($lc['middle_name'] ?? '')), trim((string) ($lc['last_name'] ?? '')), trim((string) ($lc['suffix'] ?? ''))])));
        if ($lcName === '') $lcName = 'Student #' . $lc['id']; ?>
      <a href="<?= e(url('/student/documents?child_id=' . (int) $lc['id'])) ?>"
         class="btn btn-sm <?= $lcActive ? 'btn-primary' : 'btn-outline-secondary' ?>"
         <?= $lcActive ? 'aria-current="true"' : '' ?>><?= e($lcName) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="row g-4">
  <!-- Request form -->
  <div class="col-12 col-xl-4">
    <div class="edu-card edu-card--primary">
      <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-plus-square"></i> New request</h2>
        <?php if ($actingChild): ?>
          <?= edu_badge(e($actingChild['name']), 'primary', 'bi-person') ?>
        <?php endif; ?>
      </div>
      <div class="edu-card__body">
        <form method="post" action="<?= e(url('/student/documents')) ?>">
          <?= csrf_field() ?>
          <?php if ($actingChild): ?><input type="hidden" name="child_id" value="<?= (int) $actingChild['id'] ?>"><?php endif; ?>

          <div class="mb-3">
            <label class="form-label" for="docType">Document type <span class="req">*</span></label>
            <select name="doc_type" id="docType" class="form-select" required>
              <option value="">— Select —</option>
              <?php foreach ($types as $v => $l): ?>
                <option value="<?= e($v) ?>"><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="docAy">School year</label>
              <select name="academic_year_id" id="docAy" class="form-select">
                <option value="0">Current</option>
                <?php foreach ($academicYears as $ay): ?>
                  <option value="<?= (int) $ay['id'] ?>"><?= e($ay['school_year']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="docSem"><?= e($termNoun) ?></label>
              <select name="semester" id="docSem" class="form-select">
                <option value="">All</option>
                <?php foreach ($termOptions as $to): ?><option><?= e($to) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="docPurpose">Purpose</label>
            <input name="purpose" id="docPurpose" class="form-control" maxlength="255" placeholder="e.g. Scholarship application">
            <div class="form-text">Helps the Registrar process your request faster.</div>
          </div>

          <button class="btn btn-primary w-100"><i class="bi bi-send me-1"></i> Submit request</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Document cards -->
  <div class="col-12 col-xl-8">
    <?= edu_section_head('My requests &amp; history', count($rows) . ' document' . (count($rows) === 1 ? '' : 's') . ' on record', '', 'bi-folder2-open') ?>

    <?php if ($rows === []): ?>
      <?= edu_empty_state(
            'No document requests yet',
            'Submit your first request on the left. Once the Registrar releases it you can view and print it here.',
            ['icon' => 'bi-folder2-open']
        ) ?>
    <?php else: ?>
      <?php foreach ($grouped as $category => $items): ?>
        <?= edu_section_head($category, count($items) . ' item' . (count($items) === 1 ? '' : 's'), '', $items[0]['icon']) ?>
        <div class="d-flex flex-column gap-2 mb-4">
          <?php foreach ($items as $item):
              $r      = $item['row'];
              $st     = (string) ($r['status'] ?? '');
              $meta   = $docStatusMeta($st);
              $typeLbl = $types[$r['doc_type']] ?? str_replace('_', ' ', (string) ($r['doc_type'] ?? ''));
              $docPer = trim((string) ($r['period_code'] ?? ''));
              if ($docPer === '') $docPer = trim((string) ($r['semester'] ?? ''));
              if (in_array($docPer, ['1st', '2nd'], true)) $docPer .= ' Semester';
              $iconTone = $st === 'released' ? 'success' : ($st === 'rejected' ? 'danger' : ($st === 'ready' ? 'info' : 'warning')); ?>
            <article class="edu-card-doc">
              <span class="edu-card-doc__icon edu-card-doc__icon--<?= e($iconTone) ?>" aria-hidden="true">
                <i class="bi <?= e($item['icon']) ?>"></i>
              </span>
              <div class="edu-card-doc__body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                  <span class="edu-card-doc__name"><?= e($typeLbl) ?></span>
                  <?= edu_badge(edu_status_label($st), $meta['badge'], $meta['icon']) ?>
                </div>
                <div class="edu-card-doc__meta">
                  <?= e($r['doc_no'] ?? '') ?>
                  &middot; <?= e($r['school_year'] ?? 'current') ?><?= $docPer !== '' ? ' ' . e($docPer) : '' ?>
                  &middot; requested <?= e(edu_date($r['created_at'] ?? null)) ?>
                  <?php if (!empty($r['purpose'])): ?>
                    <br>Purpose: <?= e($r['purpose']) ?>
                  <?php endif; ?>
                </div>
                <?php if ($st === 'rejected' && !empty($r['remarks'])): ?>
                  <div class="mt-1 small" style="color:var(--danger-text)">
                    <i class="bi bi-x-circle-fill me-1"></i><?= e($r['remarks']) ?>
                  </div>
                <?php endif; ?>
                <div class="edu-card-doc__actions">
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/student/documents/' . (int) $r['id'])) ?>">
                    <i class="bi bi-eye me-1"></i> View
                  </a>
                  <?php if ($st === 'released'): ?>
                    <a class="btn btn-sm btn-success" href="<?= e(url('/documents/' . (int) $r['id'] . '/print')) ?>" target="_blank" rel="noopener">
                      <i class="bi bi-printer me-1"></i> Print
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
