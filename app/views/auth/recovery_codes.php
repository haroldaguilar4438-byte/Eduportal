<?php
/**
 * Show-once MFA recovery codes (spec §5).
 *
 * Non-destructive: $codes and the Auth::check() branch for the "Done" link are
 * used exactly as before. The button ids (btnCopyCodes / btnDlCodes), the
 * clipboard + .txt download behaviour and the file name are unchanged.
 *
 * This view renders in either shell (SecurityController::showRecoveryCodes):
 *   - signed in          -> shared/layout      (page header rendered below)
 *   - mid sign-in flow   -> shared/layout_auth (heading/stepper from layout)
 *
 * @var array $codes
 */
$codes  = array_values($codes ?? []);
$authed = \App\Core\Auth::check();

if (!$authed) {
    $authHeading    = 'Save your recovery codes';
    $authSubheading = 'Each code works once if you lose your authenticator.';
    $authStep       = 4; /* Account -> Email -> Security -> Complete */
    $authSteps      = [
        ['label' => 'Account',  'hint' => 'Confirmed'],
        ['label' => 'Email',    'hint' => 'Verified'],
        ['label' => 'Security', 'hint' => 'Protected'],
        ['label' => 'Complete', 'hint' => 'Save codes'],
    ];
}
?>

<?php if ($authed): ?>
    <?= edu_page_header([
        'eyebrow'     => 'Security settings',
        'title'       => 'Save your recovery codes',
        'description' => 'Each code works once if you lose your authenticator. They are shown only on this page.',
        'icon'        => 'bi-key',
        'actions'     => '<a href="' . e(url('/security')) . '" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to security</a>',
    ]) ?>
<?php else: ?>
    <div class="text-center mb-4">
        <span class="edu-stat__icon edu-stat__icon--success mx-auto" style="width:56px;height:56px;font-size:1.5rem" aria-hidden="true">
            <i class="bi bi-key"></i>
        </span>
    </div>
<?php endif; ?>

<?= edu_callout(
    '<strong>Shown only once.</strong> Store them in a password manager or on paper. Anyone with a code can sign in as you.',
    ['tone' => 'danger', 'icon' => 'bi-exclamation-triangle-fill', 'title' => null]
) ?>

<!-- Code grid -->
<div class="row g-2 mb-4" role="list">
    <?php foreach ($codes as $c): ?>
        <div class="col-6 col-sm-4" role="listitem">
            <div class="text-center px-2 py-2 rounded-3 user-select-all"
                 style="font-family:var(--font-mono);font-size:.85rem;background:var(--surface-muted);border:1px solid var(--border)">
                <?= e($c) ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Actions -->
<div class="d-flex gap-2 mb-4">
    <button type="button" id="btnCopyCodes" class="btn btn-outline-secondary flex-fill py-2">
        <i class="bi bi-clipboard me-1"></i> Copy all
    </button>
    <button type="button" id="btnDlCodes" class="btn btn-outline-secondary flex-fill py-2">
        <i class="bi bi-download me-1"></i> Download .txt
    </button>
</div>

<a href="<?= e(url($authed ? '/security' : '/dashboard')) ?>" class="btn btn-primary w-100 py-2">
    Done &mdash; continue
</a>

<script>
(function () {
    'use strict';

    var codes = <?= json_encode($codes, JSON_UNESCAPED_SLASHES) ?>;
    var text = codes.join("\n");

    var copy = document.getElementById('btnCopyCodes');
    if (copy) {
        copy.addEventListener('click', function () {
            var btn = this;
            navigator.clipboard.writeText(text).then(function () {
                btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
                setTimeout(function () { btn.innerHTML = '<i class="bi bi-clipboard me-1"></i> Copy all'; }, 1500);
            });
        });
    }

    var dl = document.getElementById('btnDlCodes');
    if (dl) {
        dl.addEventListener('click', function () {
            var blob = new Blob(["EduPortal MFA recovery codes - keep secret, each works once:\n\n" + text + "\n"], { type: "text/plain" });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'eduportal-recovery-codes.txt';
            a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
        });
    }
})();
</script>
