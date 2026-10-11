<?php
/**
 * Admin: clearance list (spec §30).
 *
 * Non-destructive: the five filter pills keep their hrefs and counts, the
 * semester normalisation (1st/2nd -> "N Semester", other codes verbatim, empty
 * -> em dash), the issued/pending/other status tone map, the QR-token prefix
 * plus public Verify link, the created_at column and the print View link are
 * all unchanged. Read-only; only presentation moved to the shared components.
 *
 * @var array  $rows
 * @var string $status
 * @var array  $counts
 */
$rows   = $rows ?? [];
$status = $status ?? 'pending';
$counts = $counts ?? [];

$filters   = ['pending' => 'Pending', 'signed' => 'Signed', 'issued' => 'Issued', 'used' => 'Used', 'all' => 'All'];
$toneFor   = static function (string $st): string {
    return match ($st) {
        'issued'  => 'success',
        'pending' => 'warning',
        default   => 'info',
    };
};
$periodLabel = static function ($row): string {
    $v = trim((string) ($row['semester'] ?? ''));
    if (in_array($v, ['1st', '2nd'], true)) {
        return $v . ' Semester';
    }
    return $v !== '' ? $v : '—';
};
?>

<?= edu_page_header([
    'eyebrow'     => 'Admin',
    'title'       => 'Clearances',
    'description' => (int) ($counts['pending'] ?? 0) . ' pending · ' . (int) ($counts['issued'] ?? 0) . ' issued',
    'icon'        => 'bi-shield-check',
    'actions'     => edu_badge((int) ($counts['all'] ?? 0) . ' total', 'neutral', 'bi-list-ul'),
]) ?>

<!-- Status filters -->
<div class="d-flex gap-1 flex-wrap mb-3">
    <?php foreach ($filters as $k => $l): ?>
        <a href="<?= e(url('/admin/clearances?status=' . $k)) ?>"
           class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-light' ?>">
            <?= e($l) ?>
            <span class="badge text-bg-<?= $status === $k ? 'light' : 'secondary' ?> ms-1"><?= (int) ($counts[$k] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="edu-card">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> <?= e($filters[$status] ?? 'All') ?></h2>
        <?= edu_badge((string) count($rows), 'neutral') ?>
    </div>
    <div class="edu-card__body p-0">
        <?php if ($rows === []): ?>
            <?= edu_empty_state(
                'No clearances in this filter',
                'Clearances appear here as students request them.',
                ['icon' => 'bi-shield-check', 'compact' => true]
            ) ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">ID</th><th scope="col">Student</th><th scope="col">Period</th>
                            <th scope="col">Year</th><th scope="col">Status</th><th scope="col">QR</th>
                            <th scope="col">Requested</th><th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>#<?= e($r['id']) ?></td>
                            <td>
                                <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong>
                                <div class="small text-muted"><?= e($r['student_no']) ?></div>
                            </td>
                            <td><?= e($periodLabel($r)) ?></td>
                            <td><?= e($r['school_year']) ?></td>
                            <td><?= edu_badge((string) $r['status'], $toneFor((string) $r['status'])) ?></td>
                            <td class="small">
                                <code><?= e(substr($r['qr_token'] ?? '', 0, 8)) ?>…</code>
                                <a href="<?= e(url('/verify-clearance/' . ($r['qr_token'] ?? ''))) ?>" target="_blank" rel="noopener">Verify</a>
                            </td>
                            <td class="small text-muted"><?= e($r['created_at']) ?></td>
                            <td>
                                <a href="<?= e(url('/clearances/' . $r['id'] . '/print')) ?>" class="btn btn-sm btn-light border">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
