<?php
/**
 * Admin: blog create / edit (spec §34).
 *
 * Non-destructive: the conditional action, multipart encoding,
 * data-disable-on-submit, CSRF token and every field name are unchanged —
 * title, slug, category_id, summary, content, featured_image, remove_image,
 * tags, status, published_at, seo_title, seo_description — including the
 * maxlength/pattern attributes, the data-slug-source / data-slug-target hooks
 * that content.js reads for auto-slugging, the draft default, the
 * status_btn="draft" submit button, the edit-only Preview link and both
 * data-loading labels.
 *
 * @var array|null $post
 * @var array      $errors
 */
$post   = $post ?? null;
$errors = $errors ?? [];
$isEdit = $post && !empty($post['id']);
$action = $isEdit ? url('/admin/blog/' . (int) $post['id']) : url('/admin/blog');
?>

<?= edu_breadcrumb([
    ['label' => 'Blog / news', 'url' => url('/admin/blog')],
    ['label' => $isEdit ? 'Edit' : 'Create'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Content',
    'title'       => $isEdit ? 'Edit article' : 'New article',
    'description' => $isEdit
        ? 'Slug, status and SEO fields update immediately.'
        : 'The slug becomes the public URL: /news/{slug}.',
    'icon'        => $isEdit ? 'bi-pencil-square' : 'bi-newspaper',
]) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle"></i> Please fix the following</div>
        <ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= e($action) ?>" data-disable-on-submit>
    <?= csrf_field() ?>

    <div class="row g-3">
        <!-- Title & slug -->
        <div class="col-12">
            <div class="edu-card">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-type"></i> Title &amp; URL</h2>
                </div>
                <div class="edu-card__body">
                    <div class="mb-3">
                        <label class="form-label" for="title">Title <span class="req">*</span></label>
                        <input class="form-control" id="title" name="title" required maxlength="200" value="<?= e($post['title'] ?? '') ?>" data-slug-source>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="slug">Slug <span class="text-muted fw-normal">(auto if blank)</span></label>
                        <input class="form-control" id="slug" name="slug" maxlength="220" value="<?= e($post['slug'] ?? '') ?>" pattern="[a-z0-9\-]*" data-slug-target>
                    </div>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="col-12">
            <div class="edu-card">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-file-text"></i> Content</h2>
                </div>
                <div class="edu-card__body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="category_id">Category</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="0">&mdash; None &mdash;</option>
                                <?php foreach (($cats ?? []) as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= ((int) ($post['category_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tags">Tags <span class="text-muted fw-normal">(comma-separated)</span></label>
                            <input class="form-control" id="tags" name="tags" maxlength="255" value="<?= e($post['tags'] ?? '') ?>" placeholder="enrollment, holiday">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="summary">Summary</label>
                            <textarea class="form-control" id="summary" name="summary" rows="2" maxlength="500" placeholder="Short summary shown on cards…"><?= e($post['summary'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="content">Content <span class="req">*</span> <span class="text-muted fw-normal">(safe formatting only — scripts are stripped)</span></label>
                            <textarea class="form-control" id="content" name="content" rows="12" required><?= e($post['content'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Media -->
        <div class="col-12 col-lg-6">
            <div class="edu-card h-100">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-image"></i> Featured image</h2>
                </div>
                <div class="edu-card__body">
                    <label class="form-label" for="featured_image">Image <span class="text-muted fw-normal">(JPG/PNG/WebP, max 2 MB)</span></label>
                    <input class="form-control" type="file" id="featured_image" name="featured_image" accept=".jpg,.jpeg,.png,.webp">
                    <?php if (!empty($post['featured_image'])): ?>
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <img src="<?= e(url($post['featured_image'])) ?>" alt="Current featured image"
                                 style="height:56px;border-radius:8px;border:1px solid #e2e8f0;">
                            <label class="form-check small">
                                <input class="form-check-input" type="checkbox" name="remove_image" value="1"> Remove
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Publishing & SEO -->
        <div class="col-12 col-lg-6">
            <div class="edu-card h-100">
                <div class="edu-card__head">
                    <h2 class="edu-card__title"><i class="bi bi-megaphone"></i> Publishing &amp; SEO</h2>
                </div>
                <div class="edu-card__body">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="status">Status <span class="req">*</span></label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach (['draft', 'scheduled', 'published', 'archived'] as $s): ?>
                                    <option value="<?= $s ?>" <?= (($post['status'] ?? 'draft') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="published_at">Publish at</label>
                            <input class="form-control" type="datetime-local" id="published_at" name="published_at"
                                   value="<?= e(isset($post['published_at']) && $post['published_at'] ? date('Y-m-d\TH:i', strtotime($post['published_at'])) : '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="seo_title">SEO title</label>
                            <input class="form-control" id="seo_title" name="seo_title" maxlength="200" value="<?= e($post['seo_title'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="seo_description">SEO description</label>
                            <input class="form-control" id="seo_description" name="seo_description" maxlength="300" value="<?= e($post['seo_description'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3 flex-wrap">
        <button class="btn btn-outline-secondary" type="submit" name="status_btn" value="draft" onclick="document.getElementById('status').value='draft'" data-loading="Saving…">Save draft</button>
        <?php if (!empty($post['id'])): ?>
            <a class="btn btn-outline-info" href="<?= e(url('/admin/blog/' . (int) $post['id'] . '/preview')) ?>" target="_blank" rel="noopener">
                <i class="bi bi-eye"></i> Preview
            </a>
        <?php endif; ?>
        <button class="btn btn-primary ms-auto" data-loading="Publishing…"><i class="bi bi-check-lg"></i> Save</button>
    </div>
</form>

<script src="<?= e(asset('js/content.js')) ?>"></script>
