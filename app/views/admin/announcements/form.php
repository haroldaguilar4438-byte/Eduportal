<?php
/**
 * Admin: announcement create / edit (spec §34).
 *
 * Non-destructive: the form keeps its conditional action (POST /admin/announcements
 * when creating, POST /admin/announcements/{id} when editing), multipart
 * encoding, data-disable-on-submit, CSRF token, and every field name — title,
 * content, audience, type, status, publish_at, expires_at, campus_id,
 * is_pinned, attachment, remove_attachment — with the same defaults
 * (everyone / general / published), the same maxlength, accept list and
 * datetime-local formatting. The "Save draft" button's inline status override
 * and both data-loading labels are preserved, as is the image-preview branch
 * and the content.js script.
 *
 * @var array|null $row
 * @var array      $errors
 */
$row    = $row ?? null;
$errors = $errors ?? [];
$isEdit = $row && !empty($row['id']);
$action = $isEdit
    ? url('/admin/announcements/' . (int) $row['id'])
    : url('/admin/announcements');
?>

<?= edu_breadcrumb([
    ['label' => 'Announcements', 'url' => url('/admin/announcements')],
    ['label' => $isEdit ? 'Edit' : 'Create'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Content',
    'title'       => $isEdit ? 'Edit announcement' : 'New announcement',
    'description' => $isEdit
        ? 'Changes are recorded on the announcement timeline.'
        : 'Set the audience and schedule before publishing.',
    'icon'        => $isEdit ? 'bi-pencil-square' : 'bi-megaphone',
]) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle"></i> Please fix the following</div>
        <ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= e($action) ?>" data-disable-on-submit>
    <?= csrf_field() ?>

    <div class="row g-3">
        <!-- Message -->
        <div class="col-12">
            <div class="edu-card">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-file-text"></i> Message</h2>
                </div>
                <div class="edu-card__body">
                    <div class="mb-3">
                        <label class="form-label" for="title">Title <span class="req">*</span></label>
                        <input class="form-control" id="title" name="title" required maxlength="150" value="<?= e($row['title'] ?? '') ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="content">Message <span class="req">*</span></label>
                        <textarea class="form-control" id="content" name="content" rows="6" required><?= e($row['content'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Audience & lifecycle -->
        <div class="col-12 col-lg-6">
            <div class="edu-card h-100">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-people"></i> Audience &amp; type</h2>
                </div>
                <div class="edu-card__body">
                    <div class="mb-3">
                        <label class="form-label" for="audience">Audience <span class="req">*</span></label>
                        <select class="form-select" id="audience" name="audience">
                            <?php foreach (($audiences ?? []) as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= (($row['audience'] ?? 'everyone') === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="type">Type <span class="req">*</span></label>
                        <select class="form-select" id="type" name="type">
                            <?php foreach (($types ?? []) as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= (($row['type'] ?? 'general') === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule -->
        <div class="col-12 col-lg-6">
            <div class="edu-card h-100">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-calendar-event"></i> Schedule</h2>
                </div>
                <div class="edu-card__body">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label" for="status">Status <span class="req">*</span></label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach (['draft', 'scheduled', 'published', 'archived'] as $s): ?>
                                    <option value="<?= $s ?>" <?= (($row['status'] ?? 'published') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="publish_at">Publish at</label>
                            <input class="form-control" type="datetime-local" id="publish_at" name="publish_at"
                                   value="<?= e(!empty($row['publish_at']) ? date('Y-m-d\TH:i', strtotime($row['publish_at'])) : '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="expires_at">Expires at</label>
                            <input class="form-control" type="datetime-local" id="expires_at" name="expires_at"
                                   value="<?= e(!empty($row['expires_at']) ? date('Y-m-d\TH:i', strtotime($row['expires_at'])) : '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Placement & attachment -->
        <div class="col-12">
            <div class="edu-card">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-geo-alt"></i> Placement &amp; attachment</h2>
                </div>
                <div class="edu-card__body">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="campus_id">Campus</label>
                            <select class="form-select" id="campus_id" name="campus_id">
                                <option value="0">All campuses</option>
                                <?php foreach (($campuses ?? []) as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= ((int) ($row['campus_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_pinned" value="1" id="is_pinned" <?= !empty($row['is_pinned']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_pinned">Pinned to top</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="attachment">Attachment <span class="text-muted fw-normal">(PDF/image/doc, max 5 MB)</span></label>
                            <input class="form-control" type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt,.zip">
                            <?php if (!empty($row['attachment_path'])): ?>
                                <?php
                                    $curRel   = ltrim((string) $row['attachment_path'], '/');
                                    $curExt   = strtolower(pathinfo($curRel, PATHINFO_EXTENSION));
                                    $curIsImg = in_array($curExt, ['jpg', 'jpeg', 'png', 'webp'], true) && !str_contains($curRel, '..');
                                ?>
                                <div class="mt-2 small">
                                    <?php if ($curIsImg): ?>
                                        <a href="<?= e(url($curRel)) ?>" target="_blank" rel="noopener" title="Open full size">
                                            <img src="<?= e(url($curRel)) ?>" alt="Current attachment preview" loading="lazy"
                                                 style="max-width:220px;height:auto;max-height:140px;object-fit:contain;background:#f3edef;border:1px solid #eadfe3;border-radius:10px;display:block;margin-bottom:.35rem;">
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= e(url($curRel)) ?>" target="_blank" rel="noopener">Current file</a>
                                    <label class="ms-2">
                                        <input type="checkbox" name="remove_attachment" value="1"> Remove
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <button class="btn btn-outline-secondary" onclick="document.getElementById('status').value='draft'" data-loading="Saving…">Save draft</button>
        <button class="btn btn-primary ms-auto" data-loading="Publishing…"><i class="bi bi-check-lg"></i> Save</button>
    </div>
</form>

<script src="<?= e(asset('js/content.js')) ?>"></script>
