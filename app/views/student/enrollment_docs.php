<?php
/** @var array|null $student @var int $studentId @var array $unified @var array $needed @var array $types */
$student = $student ?? null; $unified = $unified ?? []; $needed = $needed ?? []; $types = $types ?? [];
$children = $children ?? []; $activeChildId = (int) ($activeChildId ?? 0);
$childName = static function (array $c): string {
    $n = trim(implode(' ', array_filter([trim((string) ($c['first_name'] ?? '')), trim((string) ($c['middle_name'] ?? '')), trim((string) ($c['last_name'] ?? '')), trim((string) ($c['suffix'] ?? ''))])));
    return $n !== '' ? $n : ('Child #' . (int) ($c['id'] ?? 0));
};
$badge = static function (string $st): string {
    return match($st) { 'verified' => 'success', 'submitted' => 'info', 'needs_resubmission' => 'warning', 'rejected' => 'danger', default => 'secondary' };
};
$required = $required ?? ['birth_certificate','good_moral','id_photo'];
$level = $level ?? null;
/* Level-aware display: required docs first, then anything already on file
   (history/corrections stay visible). Fully-missing, non-required docs —
   e.g. ECCD checklist for college — are never listed as "missing". */
$displayTypes = [];
foreach ($types as $t => $label) {
    if (in_array($t, $required, true)) { $displayTypes[$t] = $label; continue; }
    $info = $unified[$t] ?? null;
    if ($info === null) continue;
    if ((string) ($info['status'] ?? 'missing') !== 'missing' || !empty($info['history'])) $displayTypes[$t] = $label;
}
if ($displayTypes === []) $displayTypes = $types;
$levelNote = '';
if (is_array($level) && (trim((string) ($level['code'] ?? '')) !== '' || trim((string) ($level['category'] ?? '')) !== '')) {
    $levelNote = ' Showing requirements for <strong>' . e(trim((string) ($level['code'] ?? '')) !== '' ? (string) $level['code'] : (string) ($level['category'] ?? '')) . '</strong> — only documents relevant to this level are listed.';
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-folder-check"></i> My Enrollment Documents</h1>
    <div class="d-flex gap-2 align-items-center">
        <?php if (!empty($student['student_no'])): ?><span class="badge text-bg-primary font-monospace"><?= e($student['student_no']) ?></span><?php endif; ?>
        <a href="<?= e(url('/my-enrollments')) ?>" class="btn btn-sm btn-outline-secondary">My Enrollments</a>
    </div>
</div>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> This page reads the <strong>same records as your enrollment</strong> — files you attached to any application appear here with their real status. Documents you submit <strong>once</strong> are kept on file and <strong>reused automatically</strong> every semester. Upload again only when a row below says <span class="badge text-bg-warning">needs_resubmission</span> (Accounting will state the reason) or when a required item is still missing.<?= $levelNote ?></div>

<?php if (count($children) > 1): ?>
<div class="card p-3 mb-3">
    <div class="small fw-semibold mb-2"><i class="bi bi-people"></i> Whose documents are these?</div>
    <div class="d-flex gap-2 flex-wrap">
        <?php foreach ($children as $ch): $cid = (int) ($ch['id'] ?? 0); ?>
            <a href="<?= e(url('/student/enrollment-docs?child_id=' . $cid)) ?>" class="btn btn-sm <?= $cid === $activeChildId ? 'btn-primary' : 'btn-outline-primary' ?>"><?= e($childName($ch)) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card p-3 mb-3">
    <h2 class="h6 mb-2">Current status</h2>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Document</th><th>Status</th><th>Version</th><th>File</th><th>Review note</th></tr></thead>
        <tbody>
        <?php foreach ($displayTypes as $t => $label): $info = $unified[$t] ?? ['status' => 'missing', 'row' => null, 'reason' => null, 'version' => null, 'history' => []]; $st = (string)$info['status']; $row = $info['row']; ?>
            <tr>
                <td class="fw-semibold"><?= e($label) ?><?php if (in_array($t, $required, true)): ?> <span class="text-danger">*</span><?php endif; ?></td>
                <td>
                    <span class="badge text-bg-<?= $badge($st) ?>"><?= e($st) ?></span>
                    <?php if (isset($needed[$t])): ?><div class="small text-warning-emphasis fw-semibold mt-1"><?= $needed[$t] === 'resubmission_requested' ? 'Accounting requested a new version' : 'Upload required' ?></div>
                    <?php elseif ($st === 'submitted'): ?><div class="small text-muted mt-1">Received — pending review</div>
                    <?php elseif ($st === 'verified'): ?><div class="small text-success mt-1">Accepted — reused automatically</div><?php endif; ?>
                </td>
                <td class="small"><?= $info['version'] !== null ? 'v' . (int)$info['version'] : ($row !== null ? 'via application' : '<span class="text-muted">—</span>') ?><?php if ($row !== null && !empty($row['created_at'] ?? $row['uploaded_at'] ?? null)): ?><div class="text-muted"><?= e(date('M j, Y', strtotime($row['created_at'] ?? $row['uploaded_at']))) ?></div><?php endif; ?></td>
                <td class="small"><?php if ($row !== null && !empty($row['stored_path'])): ?><a href="<?= e(url('/uploads/' . $row['stored_path'])) ?>" target="_blank"><i class="bi bi-eye"></i> View</a> <span class="text-muted"><?= e($row['original_filename'] ?? '') ?></span><?php if (!empty($info['upload']) && ($info['permanent'] ?? null) !== null && $st === 'verified'): ?><div class="text-muted">latest accepted version</div><?php endif; ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td class="small"><?php if (!empty($info['reason'])): ?><span class="text-warning-emphasis fw-semibold"><?= e($info['reason']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="card p-3">
            <h2 class="h6"><i class="bi bi-cloud-upload"></i> Upload (only when needed)</h2>
            <form method="post" action="<?= e(url('/student/enrollment-docs')) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <?php if ($activeChildId > 0): ?><input type="hidden" name="child_id" value="<?= $activeChildId ?>"><?php endif; ?>
                <label class="small fw-semibold">Document type</label>
                <select name="doc_type" class="form-select mb-2" required>
                    <option value="">— Select —</option>
                    <?php foreach ($displayTypes as $t => $label): $st = (string)(($unified[$t] ?? [])['status'] ?? 'missing');
                        $ok = in_array($st, ['missing','needs_resubmission','rejected'], true);
                        if ($st === 'missing' && !in_array($t, $required, true)) continue;
                    ?>
                        <option value="<?= e($t) ?>" <?= $ok ? '' : 'disabled' ?>><?= e($label) ?> — <?= e($st) ?><?= $ok ? '' : ' (on file — reused)' ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="small fw-semibold">File (PDF/JPG/PNG/WebP, max 10 MB)</label>
                <input type="file" name="document" class="form-control mb-2" required accept=".pdf,.jpg,.jpeg,.png,.webp">
                <button class="btn btn-primary w-100"><i class="bi bi-upload"></i> Upload new version</button>
                <div class="form-text mt-2">Accepted documents stay untouched — a new upload creates a new version for Accounting review; history is preserved.</div>
            </form>
        </div>
    </div>
    <div class="col-12 col-xl-8">
        <div class="card p-3">
            <h2 class="h6">Version history (never overwritten)</h2>
            <?php $anyHist = false; foreach ($displayTypes as $t => $label): $h = ($unified[$t] ?? [])['history'] ?? []; if ($h === []) continue; $anyHist = true; ?>
                <h3 class="h6 mt-3 mb-1"><?= e($label) ?></h3>
                <div class="table-responsive"><table class="table table-sm align-middle mb-2">
                    <thead><tr><th>Source</th><th>Status</th><th>File</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($h as $v): ?>
                        <tr>
                            <td class="small"><?php if (($v['kind'] ?? '') === 'permanent'): ?><span class="badge text-bg-light border">v<?= (int)($v['version'] ?? 1) ?> · file</span><?php else: ?><span class="badge text-bg-light border">application <?= e($v['app'] ?? '') ?></span><?php endif; ?><?php if (!empty($v['reason'])): ?><div class="small text-warning"><?= e($v['reason']) ?></div><?php endif; ?></td>
                            <td><span class="badge text-bg-<?= $badge((string)($v['status'] ?? '')) ?>"><?= e($v['status'] ?? '') ?></span></td>
                            <td class="small"><?php if (!empty($v['file'])): ?><a href="<?= e(url('/uploads/' . $v['file'])) ?>" target="_blank">View</a> <span class="text-muted"><?= e($v['name'] ?? '') ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                            <td class="small text-muted"><?= !empty($v['date']) ? e(date('M j, Y', strtotime((string)$v['date']))) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endforeach; ?>
            <?php if (!$anyHist): ?><p class="text-muted small mb-0">No uploads yet — files you attach during enrollment will appear here.</p><?php endif; ?>
        </div>
    </div>
</div>
