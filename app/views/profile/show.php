<?php
/** @var array $user @var array|null $student @var array|null $teacher @var array $parentStudents */
$user = $user ?? [];
$student = $student ?? null;
$teacher = $teacher ?? null;
$parentStudents = $parentStudents ?? [];
$full = trim(($user['first_name']??'').' '.($user['middle_name']??'').' '.($user['last_name']??'').' '.($user['suffix']??''));
$initials = strtoupper(substr($user['first_name']??'',0,1).substr($user['last_name']??'',0,1));
$role = $user['role_name'] ?? $user['role'] ?? 'user';
$avatar = $user['avatar_path'] ?? null;
?>
<?= edu_page_header([
    'eyebrow'     => 'My account',
    'title'       => $full !== '' ? $full : 'My profile',
    'description' => 'Manage your personal details, academic identity and account security.',
    'actions'     => '<a href="' . e(url('/security')) . '" class="btn btn-light btn-sm"><i class="bi bi-shield-lock me-1"></i> Security</a>'
                   . '<a href="' . e(url('/dashboard')) . '" class="btn btn-primary btn-sm"><i class="bi bi-grid-1x2 me-1"></i> Dashboard</a>',
]) ?>

<div class="row g-4">
    <!-- Identity header -->
    <div class="col-12">
        <div class="edu-card edu-card--primary">
            <div class="edu-card__body">
                <div class="d-flex flex-column flex-md-row gap-4 align-items-md-center">
                    <div class="flex-none text-center">
                        <?php if (!empty($avatar) && is_file(ROOT_PATH . '/public_html/' . ltrim((string) $avatar, '/'))): ?>
                            <img src="<?= e(url($avatar)) ?>"
                                 alt="Profile photo of <?= e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?>"
                                 class="edu-avatar edu-avatar--xl mx-auto">
                        <?php else: ?>
                            <span class="edu-avatar edu-avatar--xl edu-avatar--fallback mx-auto" aria-hidden="true" style="font-size:1.75rem"><?= e($initials ?: 'U') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <h2 class="edu-section-head__title mb-0" style="font-size:1.25rem"><?= e($full ?: 'User') ?></h2>
                            <?= edu_badge(ucfirst($role), 'primary', 'bi-person-badge') ?>
                            <?= edu_status_badge((string) ($user['status'] ?? 'active')) ?>
                            <?= (int) ($user['is_email_verified'] ?? 0) === 1
                                ? edu_badge('Email verified', 'success', 'bi-patch-check-fill')
                                : edu_badge('Email pending', 'warning', 'bi-exclamation-triangle-fill') ?>
                        </div>

                        <div class="small mb-2" style="color:var(--text-tertiary)">
                            <i class="bi bi-envelope me-1"></i><?= e($user['email'] ?? '') ?>
                            <span class="mx-2">&middot;</span>
                            <i class="bi bi-telephone me-1"></i><?= e($user['phone'] ?? '—') ?>
                            <span class="mx-2">&middot;</span>
                            <i class="bi bi-calendar me-1"></i>Joined <?= e(isset($user['created_at']) ? date('M j, Y', strtotime((string) $user['created_at'])) : '—') ?>
                        </div>

                        <?php if ($student && !empty($student['student_no'])): ?>
                          <?= edu_callout(
                              '<div class="d-flex flex-wrap align-items-center gap-3">
                                 <div>
                                   <div style="font-size:.65rem;letter-spacing:.06em;text-transform:uppercase;opacity:.8">Student number</div>
                                   <div class="fw-bold" style="font-family:var(--font-mono);font-size:1.05rem;letter-spacing:.08em">' . e($student['student_no']) . '</div>
                                 </div>
                                 <div>
                                   <div>' . e($student['level_name'] ?? '') . ' ' . e($student['level_code'] ?? '')
                                     . (!empty($student['program_name']) ? ' &middot; ' . e($student['program_name']) : '') . '</div>
                                   <div class="mt-1">' . edu_status_badge((string) ($student['student_status'] ?? '')) . '</div>
                                 </div>
                                 <button type="button" class="btn btn-sm btn-light ms-auto" onclick="navigator.clipboard.writeText(\'' . e($student['student_no']) . '\'); this.textContent=\'Copied!\'; setTimeout(()=>this.textContent=\'Copy\',1200)">Copy</button>
                               </div>',
                              ['tone' => 'accent', 'icon' => 'bi-mortarboard', 'title' => 'Academic identity']
                          ) ?>
                        <?php elseif ($teacher && !empty($teacher['employee_no'])): ?>
                          <?= edu_callout(
                              '<div class="d-flex flex-wrap align-items-center gap-3">
                                 <div>
                                   <div style="font-size:.65rem;letter-spacing:.06em;text-transform:uppercase;opacity:.8">Employee number</div>
                                   <div class="fw-bold" style="font-family:var(--font-mono);font-size:1.05rem">' . e($teacher['employee_no']) . '</div>
                                 </div>
                                 <div>' . e($teacher['department'] ?? '')
                                   . (!empty($teacher['position']) ? ' &middot; ' . e($teacher['position']) : '') . '</div>
                               </div>',
                              ['tone' => 'info', 'icon' => 'bi-person-badge', 'title' => 'Faculty identity']
                          ) ?>
                        <?php elseif ($role === 'parent' && $parentStudents): ?>
                          <div class="mb-2">
                            <div class="small fw-semibold mb-2"><i class="bi bi-people me-1"></i> Linked student<?= count($parentStudents) === 1 ? '' : 's' ?></div>
                            <div class="d-flex flex-wrap gap-2">
                              <?php foreach ($parentStudents as $ps): ?>
                                <span class="edu-badge edu-badge--neutral">
                                  <strong><?= e($ps['student_no']) ?></strong> &middot; <?= e($ps['child_name']) ?> &middot; <?= e($ps['level_name']) ?>
                                </span>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        <?php elseif ($role === 'student'): ?>
                          <?= edu_callout('No student number yet &mdash; complete enrolment and PayRex payment to be assigned one (College starts at 2000000).', ['tone' => 'warning', 'icon' => 'bi-info-circle-fill', 'title' => 'Student number pending']) ?>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a href="<?= e(url('/dashboard')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-grid-1x2 me-1"></i> Go to Dashboard</a>
                            <a href="<?= e(url('/security')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-shield-check me-1"></i> Security Settings</a>
                            <form method="post" action="<?= e(url('/logout')) ?>" class="d-inline"><?= \App\Core\Csrf::field() ?><button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right me-1"></i> Logout</button></form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-6">
            <div class="card p-4 h-100">
                <h2 class="h6"><i class="bi bi-person-gear"></i> Edit Profile</h2>
                <p class="small text-muted">Update your name, phone and profile picture.</p>
                <form method="post" action="<?= e(url('/profile/update')) ?>" enctype="multipart/form-data" class="space-y-3">
                    <?= csrf_field() ?>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label small" for="pf_first">First name *</label><input id="pf_first" name="first_name" value="<?= e($user['first_name'] ?? '') ?>" class="form-control" required autocomplete="given-name"></div>
                        <div class="col-6"><label class="form-label small" for="pf_last">Last name *</label><input id="pf_last" name="last_name" value="<?= e($user['last_name'] ?? '') ?>" class="form-control" required autocomplete="family-name"></div>
                        <div class="col-6"><label class="form-label small" for="pf_mid">Middle name</label><input id="pf_mid" name="middle_name" value="<?= e($user['middle_name'] ?? '') ?>" class="form-control" autocomplete="additional-name"></div>
                        <div class="col-6"><label class="form-label small" for="pf_suffix">Suffix</label><input id="pf_suffix" name="suffix" value="<?= e($user['suffix'] ?? '') ?>" class="form-control" placeholder="Jr."></div>
                        <div class="col-12"><label class="form-label small" for="pf_phone">Phone (11 digits)</label><input id="pf_phone" name="phone" value="<?= e($user['phone'] ?? '') ?>" class="form-control" pattern="09[0-9]{9}" maxlength="11" placeholder="09XXXXXXXXX" inputmode="tel" autocomplete="tel"></div>
                        <div class="col-12"><label class="form-label small" for="pf_avatar">Profile picture (JPG/PNG/WebP, max 2MB)</label><input id="pf_avatar" type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp" class="form-control"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-3"><i class="bi bi-check-lg"></i> Save changes</button>
                </form>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card p-4 h-100">
                <h2 class="h6"><i class="bi bi-shield-lock"></i> Change Password</h2>
                <p class="small text-muted">10+ chars, upper + lower + number. You must know current password.</p>
                <form method="post" action="<?= e(url('/profile/password')) ?>" class="space-y-3">
                    <?= csrf_field() ?>
                    <div class="mb-2"><label class="form-label small" for="pf_cur">Current password *</label><input id="pf_cur" type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
                    <div class="mb-2"><label class="form-label small" for="pf_new">New password *</label><input id="pf_new" type="password" name="new_password" class="form-control" required minlength="10" autocomplete="new-password"></div>
                    <div class="mb-3"><label class="form-label small" for="pf_confirm">Confirm new password *</label><input id="pf_confirm" type="password" name="confirm_password" class="form-control" required minlength="10" autocomplete="new-password"></div>
                    <button type="submit" class="btn btn-warning w-100"><i class="bi bi-key"></i> Update password</button>
                </form>
            </div>
        </div>
</div><!-- /row: closes the Edit Profile / Change Password row -->

<div class="edu-card mt-4">
    <div class="edu-card__body">
        <div class="small" style="color:var(--text-tertiary)">
            <i class="bi bi-info-circle me-1"></i>Your role <strong style="color:var(--text-secondary)"><?= e($role) ?></strong>
            determines access. Contact admin to change role. Date joined is fixed.
        </div>
    </div>
</div>

