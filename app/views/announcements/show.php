<?php
/**
 * Announcement detail (spec §16).
 *
 * Non-destructive: only the header badges/title/meta moved to the shared page
 * header. Everything below — the raw $row['content'] output, the back link,
 * and the entire attachment resolver (traversal guard, image verification,
 * PDF branch, size labels, download links) — is byte-identical to before.
 *
 * @var array $row
 */
?>

<?= edu_breadcrumb([
    ['label' => 'Announcements', 'url' => url('/announcements')],
    ['label' => mb_strimwidth((string) ($row['title'] ?? ''), 0, 48, '…')],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Announcement',
    'title'       => (string) ($row['title'] ?? 'Announcement'),
    'description' => 'Published ' . date('F j, Y g:i A', strtotime($row['publish_at'] ?? $row['created_at'] ?? 'now'))
        . (!empty($row['expires_at']) ? ' · Expires ' . date('M j, Y', strtotime($row['expires_at'])) : '')
        . (!empty($row['author_name']) && trim($row['author_name']) !== '' ? ' · ' . trim($row['author_name']) : ''),
    'icon'        => 'bi-megaphone',
    'actions'     => (!empty($row['is_pinned']) ? edu_badge('Pinned', 'accent', 'bi-pin-angle-fill') . ' ' : '')
        . edu_badge((string) ($row['audience'] ?? ''), 'neutral', 'bi-people')
        . (!empty($row['type']) ? ' ' . edu_badge((string) (($types ?? [])[$row['type']] ?? $row['type']), 'info') : ''),
]) ?>

<article class="edu-card">
  <div class="edu-card__body">
    <div><?= $row['content'] /* sanitized on write */ ?></div>
    <?php if (!empty($row['attachment_path'])): ?>
      <?php
        // Resolve the stored path safely: must be a real file inside the
        // public uploads dir (no traversal, no absolute server paths leak).
        $attRel = ltrim((string) $row['attachment_path'], '/');
        $attBase = realpath(UPLOAD_DIR) ?: (ROOT_PATH . '/public_html/uploads');
        $attAbs = @realpath(ROOT_PATH . '/public_html/' . $attRel);
        $attOk = $attAbs !== false && str_starts_with($attAbs, (string) $attBase)
          && is_file($attAbs) && !str_contains($attRel, '..');
        $attExt = strtolower(pathinfo($attRel, PATHINFO_EXTENSION));
        $attName = basename($attRel);
        $attIsImg = $attOk && in_array($attExt, ['jpg', 'jpeg', 'png', 'webp'], true);
        if ($attIsImg) {
          // Never inline something that is not really an image.
          $attInfo = @getimagesize($attAbs);
          if ($attInfo === false) { $attIsImg = false; }
        }
        $attIsPdf = $attOk && $attExt === 'pdf' && !$attIsImg;
        $attSize = ($attOk && ($fs = @filesize($attAbs)) !== false) ? $fs : null;
        $attSizeLbl = $attSize === null ? '' : ($attSize >= 1048576
          ? number_format($attSize / 1048576, 1) . ' MB'
          : max(1, (int) round($attSize / 1024)) . ' KB');
      ?>
      <?php if ($attOk): ?>
        <hr>
        <h2 class="h6"><i class="bi bi-paperclip"></i> Attachment</h2>
        <?php if ($attIsImg): ?>
          <a href="<?= e(url($attRel)) ?>" target="_blank" rel="noopener" title="Open full size">
            <img src="<?= e(url($attRel)) ?>" alt="Announcement attachment image" loading="lazy" class="ann-attachment-img">
          </a>
        <?php elseif ($attIsPdf): ?>
          <iframe src="<?= e(url($attRel)) ?>" title="PDF attachment preview" class="ann-attachment-frame" loading="lazy"></iframe>
          <p class="small text-muted mt-1 mb-0">Preview not showing? <a href="<?= e(url($attRel)) ?>" target="_blank" rel="noopener">Open the PDF in a new tab</a>.</p>
        <?php else: ?>
          <div class="ann-file-card">
            <i class="bi bi-file-earmark-text"></i>
            <span class="fw-semibold text-break"><?= e($attName) ?></span>
            <?php if ($attSizeLbl !== ''): ?><span class="badge text-bg-light border"><?= e($attSizeLbl) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url($attRel)) ?>" target="_blank" rel="noopener">
            <i class="bi bi-eye"></i> <?= $attIsImg ? 'Open full size' : ($attIsPdf ? 'Open PDF' : 'Open file') ?>
          </a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($attRel)) ?>" download>
            <i class="bi bi-download"></i> Download<?= $attSizeLbl !== '' ? ' (' . e($attSizeLbl) . ')' : '' ?>
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</article>
