<?php
/**
 * Admin — teacher invite code generation (spec §26).
 *
 * Non-destructive: this view is rendered with only $title from the controller,
 * and that is still all it uses. The form keeps its action, CSRF token and both
 * field names (email_hint, department) with their original help text and
 * placeholders, and the security explanation is preserved.
 *
 * Invite history, revocation and status live on Admin → Teachers (the controller
 * does not pass them here), so this page links there rather than inventing a
 * second, unbacked list.
 *
 * @var string $title
 */
?>

<?= edu_page_header([
    'eyebrow'     => 'Faculty onboarding',
    'title'       => 'Generate a teacher invite code',
    'description' => 'Teacher accounts are invite-only. Each code is single-use, expires in 7 days, and is shown in plain text exactly once.',
    'actions'     => '<a href="' . e(url('/admin/teachers')) . '" class="btn btn-primary btn-sm"><i class="bi bi-person-badge me-1"></i> Invites &amp; teachers</a>',
    'icon'        => 'bi-key',
]) ?>

<div class="row g-4">
    <!-- Generation form -->
    <div class="col-12 col-lg-7">
        <div class="edu-card edu-card--accent">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-key"></i> New invite</h2>
                <?= edu_badge('7-day · single use', 'accent', 'bi-clock') ?>
            </div>
            <div class="edu-card__body">
                <form method="post" action="<?= e(url('/admin/teachers/invite')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="email_hint" class="form-label">Restrict to email <span class="small" style="color:var(--text-tertiary)">(optional)</span></label>
                        <div class="edu-input">
                            <i class="bi bi-envelope" aria-hidden="true"></i>
                            <input type="email" class="form-control" id="email_hint" name="email_hint" placeholder="teacher@datamex.edu.ph">
                        </div>
                        <div class="form-text">If set, the invite can only be redeemed with that exact email. Leave blank for any teacher email.</div>
                    </div>

                    <div class="mb-3">
                        <label for="department" class="form-label">Department hint <span class="small" style="color:var(--text-tertiary)">(optional)</span></label>
                        <div class="edu-input">
                            <i class="bi bi-building" aria-hidden="true"></i>
                            <input class="form-control" id="department" name="department" maxlength="100" placeholder="e.g. SHS, College, IT">
                        </div>
                        <div class="form-text">Prefills department on teacher registration; the teacher can override it.</div>
                    </div>

                    <div class="mb-3"><?= edu_callout(
                        '<strong>Security:</strong> plain code format <code>TCH-XXXX-XXXX</code> (32-symbol alphabet, <code>random_int</code> per character, 36-bit entropy), bcrypt-hashed and never logged. After redemption the row is marked <code>is_used=1</code> and the hash remains for audit.',
                        ['tone' => 'accent', 'icon' => 'bi-shield-lock', 'title' => 'How the code is protected']
                    ) ?></div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-key me-1"></i> Generate invite (7-day, single-use)
                    </button>
                </form>
            </div>
        </div>

        <!-- Where history lives -->
        <div class="mt-3"><?= edu_callout(
            'Need an account now? Use <strong>Create Teacher Directly</strong> on the teachers page to skip the code. All issued codes &mdash; active, used and expired &mdash; are listed there, where they can also be revoked.',
            ['tone' => 'info', 'icon' => 'bi-info-circle-fill', 'title' => 'Invite history &amp; revocation']
        ) ?></div>
    </div>

    <!-- How it works -->
    <div class="col-12 col-lg-5">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-lightbulb"></i> How it works</h2>
            </div>
            <div class="edu-card__body">
                <?= edu_stepper([
                    ['label' => 'Generate &amp; copy', 'hint' => 'Shown once', 'state' => 'current'],
                    ['label' => 'Share securely',   'hint' => 'Email or print'],
                    ['label' => 'Redeem',           'hint' => 'Teacher registers'],
                    ['label' => 'Tracked',          'hint' => 'Auto-expires'],
                ], true) ?>

                <hr style="border-color:var(--border)">

                <ul class="mb-0 ps-3 small" style="line-height:1.9;color:var(--text-secondary)">
                    <li>Each invite is a one-time code <code>TCH-XXXX-XXXX</code> valid for <strong>7 days</strong>. We store it hashed, so even the database cannot reveal it.</li>
                    <li>After a teacher uses it once it automatically expires and cannot be reused.</li>
                    <li>If you set an email, only that exact address can redeem the code &mdash; ideal for assigning a code to one specific teacher.</li>
                </ul>

                <hr style="border-color:var(--border)">

                <div class="small fw-semibold mb-2"><i class="bi bi-hand-index me-1"></i> Three steps</div>
                <ol class="ps-3 mb-0 small" style="line-height:1.9;color:var(--text-secondary)">
                    <li><strong>Generate &amp; copy</strong> &mdash; click <em>Generate invite</em> and copy the code shown once, e.g. <code>TCH-AB12-CD34</code>. Save or print it.</li>
                    <li><strong>Share securely</strong> &mdash; send it to the teacher by email or printed slip. They open <code>/register-teacher</code> (or your link with <code>?code=TCH-&hellip;</code>) and create their account.</li>
                    <li><strong>Track</strong> &mdash; check the teachers page for <em>Active</em>, <em>Used</em> or expired codes, and revoke any that went to the wrong person.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
