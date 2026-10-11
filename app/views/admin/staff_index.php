<?php
/**
 * Admin: Registrar & Accounting staff accounts (spec §33).
 *
 * Non-destructive: the create form keeps its action (/admin/staff/create), CSRF
 * token, all five field names (role, first_name, last_name, email, password),
 * the minlength=10 constraint, and every JS hook — #staffPw, #staffPwToggle,
 * #staffPwGen, #staffPwShow — so password reveal and generation keep working.
 * The role/status badge logic, the verified/unverified badges, the role
 * options, the All Accounts links and the $staff contract are unchanged.
 *
 * @var array $staff
 */
$staff = $staff ?? [];

$regCount = 0;
$acctCount = 0;
$unverified = 0;
foreach ($staff as $s) {
    if (($s['role_name'] ?? '') === 'registrar') { $regCount++; }
    if (($s['role_name'] ?? '') === 'accounting') { $acctCount++; }
    if (empty($s['is_email_verified'])) { $unverified++; }
}
?>

<?= edu_breadcrumb([
    ['label' => 'Accounts', 'url' => url('/admin/users')],
    ['label' => 'Staff'],
]) ?>

<?= edu_page_header([
    'eyebrow'     => 'Administration',
    'title'       => 'Registrar & Accounting accounts',
    'description' => $regCount . ' registrar · ' . $acctCount . ' accounting'
        . ($unverified > 0 ? ' · ' . $unverified . ' awaiting email verification' : ''),
    'icon'        => 'bi-person-gear',
    'actions'     => '<a href="' . e(url('/admin/users')) . '" class="btn btn-light btn-sm">'
        . '<i class="bi bi-people me-1"></i> All accounts</a>',
]) ?>

<?= edu_callout(
    '<strong>Staff-only.</strong> Registrar handles grades, permits, and academic documents. Accounting '
    . 'handles enrollment finance, payments, and financial clearance. Neither can do the other\'s '
    . 'restricted actions. Accounts are admin-created, verified, and active on creation.',
    ['tone' => 'info', 'icon' => 'bi-shield-lock', 'title' => null]
) ?>

<div class="row g-3">
    <!-- Create -->
    <div class="col-12 col-xl-4">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-person-plus"></i> Create staff account</h2>
            </div>
            <div class="edu-card__body">
                <form method="post" action="<?= e(url('/admin/staff/create')) ?>" autocomplete="off" id="staffCreateForm">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="staff_role">Role</label>
                        <select name="role" id="staff_role" class="form-select">
                            <option value="registrar">Registrar — academic records</option>
                            <option value="accounting">Accounting — finance</option>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="staff_fn">First name</label>
                            <input name="first_name" id="staff_fn" class="form-control" required autocomplete="off">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="staff_ln">Last name</label>
                            <input name="last_name" id="staff_ln" class="form-control" required autocomplete="off">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="staff_email">Email</label>
                        <input name="email" id="staff_email" type="email" class="form-control" required autocomplete="off" placeholder="name@datamex.edu.ph">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="staffPw">Temporary password <span class="text-muted fw-normal">(10+ chars, upper/lower/number)</span></label>
                        <div class="input-group">
                            <input id="staffPw" name="password" type="password" class="form-control" required minlength="10" autocomplete="new-password" placeholder="TempPass1234">
                            <button type="button" class="btn btn-outline-secondary" id="staffPwToggle" title="Show password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            <button type="button" class="btn btn-outline-secondary" id="staffPwGen" title="Generate strong password" aria-label="Generate strong password"><i class="bi bi-dice-5"></i></button>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="staffPwShow"
                               onchange="var i=document.getElementById('staffPw');i.type=this.checked?'text':'password';document.querySelector('#staffPwToggle i').className=this.checked?'bi bi-eye-slash':'bi bi-eye';">
                        <label class="form-check-label small" for="staffPwShow">Show password while typing</label>
                    </div>
                    <button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> Create (send verification email)</button>
                </form>
                <div class="form-hint mt-2">Staff sign in at <code>/login</code> after verifying their email — unverified accounts cannot sign in. They can change the temporary password in Profile.</div>
            </div>
        </div>
    </div>

    <!-- List -->
    <div class="col-12 col-xl-8">
        <div class="edu-card h-100">
            <div class="edu-card__head">
                <h2 class="edu-card__title"><i class="bi bi-people"></i> Staff list</h2>
                <?= edu_badge((string) count($staff), 'neutral') ?>
            </div>
            <div class="edu-card__body p-0">
                <?php if ($staff === []): ?>
                    <?= edu_empty_state(
                        'No staff accounts yet',
                        'Create the first registrar or accounting account.',
                        ['icon' => 'bi-person-plus', 'compact' => true]
                    ) ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Status</th><th>Created</th></tr></thead>
                            <tbody>
                            <?php foreach ($staff as $s): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($s['email']) ?></td>
                                    <td><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></td>
                                    <td><?= edu_badge((string) $s['role_name'], $s['role_name'] === 'registrar' ? 'primary' : 'info', 'bi-person-badge') ?></td>
                                    <td>
                                        <?= edu_status_badge((string) $s['status']) ?>
                                        <?= (int) $s['is_email_verified']
                                            ? edu_badge('verified', 'success', 'bi-patch-check')
                                            : edu_badge('unverified', 'warning', 'bi-envelope-exclamation') ?>
                                    </td>
                                    <td class="small text-muted"><?= e(date('M j, Y', strtotime($s['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="p-3 small text-muted" style="border-top:1px solid var(--border)">
                    Manage holds and bans in <a href="<?= e(url('/admin/users')) ?>">All Accounts</a>.
                    Staff cannot access each other's restricted modules.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var toggle = document.getElementById('staffPwToggle');
    var input  = document.getElementById('staffPw');
    var gen    = document.getElementById('staffPwGen');
    var show   = document.getElementById('staffPwShow');

    function reveal(isText) {
        if (input)  { input.type = isText ? 'text' : 'password'; }
        if (toggle) { toggle.querySelector('i').className = isText ? 'bi bi-eye-slash' : 'bi bi-eye'; }
        if (show)   { show.checked = isText; }
    }

    if (toggle && input) {
        toggle.addEventListener('click', function () {
            reveal(input.type === 'password');
        });
    }

    if (gen && input) {
        gen.addEventListener('click', function () {
            /* Same character pools as before: no 0/O/1/I, to avoid ambiguity. */
            var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
            var lower = 'abcdefghijkmnopqrstuvwxyz';
            var digit = '23456789';
            var pw = upper[Math.floor(Math.random() * upper.length)]
                  + lower[Math.floor(Math.random() * lower.length)]
                  + digit[Math.floor(Math.random() * digit.length)];
            var all = upper + lower + digit + upper + lower + '23456789';
            for (var k = 0; k < 9; k++) {
                pw += all[Math.floor(Math.random() * all.length)];
            }
            reveal(true);
            input.value = pw;
        });
    }
})();
</script>
