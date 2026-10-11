<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Enrollment (Module 2) - student/parent self-service.
 * Handles K-to-College applications with document uploads.
 */
final class EnrollmentController extends Controller
{
    /* Show the application form */
    public function showForm(array $params = []): void
    {
        Auth::requireRole('student', 'parent');

        // Enrollment-cycle guard: first-time applicants use the plain form;
        // enrolled students may only continue when eligible for the NEXT period.
        // Parents act per linked child (?child=Child 1, Child 2, ...).
        $clearanceForNext = null;
        $nextMode = null;
        $enrollChild = null;
        $enrollChildren = [];
        if (Auth::is('parent') && class_exists(\App\Core\GuardianLinks::class)) {
            try {
                $enrollChildren = \App\Core\GuardianLinks::linkedChildren((int) Auth::id());
            } catch (Throwable $e) {
                $enrollChildren = [];
            }
        }
        try {
            $uidGuard = (int) Auth::id();
            if (Auth::is('parent')) {
                $childCtx = $this->enrollChildContext();
                if (!empty($childCtx['tampered'])) {
                    set_flash('error', 'That child is not linked to your guardian account.');
                    redirect('/dashboard');
                }
                // Explicit ?child=X = act for THAT child (re-enroll flow when
                // enrolled, tagged first-time filing otherwise). Plain /enroll
                // always means "enroll another (new) child" — Child 1's status
                // must never hijack it.
                $explicitChild = isset($_GET['child']) && (int) $_GET['child'] > 0;
                $enrollChild = ($explicitChild && $childCtx['child'] !== null) ? $childCtx['child'] : null;
                $stuGuard = $enrollChild;
            } else {
                $stuGuard = Database::one("SELECT * FROM students WHERE user_id = ? LIMIT 1", [$uidGuard]);
            }
            $isEnrolledGuard = $stuGuard !== null && ($stuGuard['status'] ?? '') === 'enrolled';
            if (!$isEnrolledGuard) {
                $curAyGuard = Database::one("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
                if ($curAyGuard && isset($curAyGuard['id'])) {
                    $appr = (int) Database::value("SELECT COUNT(*) FROM enrollment_applications WHERE user_id = ? AND status = 'approved' AND academic_year_id = ?", [$uidGuard, (int)$curAyGuard['id']]);
                    if ($appr > 0) $isEnrolledGuard = true;
                }
            }
            if ($isEnrolledGuard && $stuGuard) {
                $check = \App\Core\EnrollmentCycle::checklist((int) $stuGuard['id']);
                $state = $check['state'] ?? 'not_eligible';
                if ($state === 'eligible' && $check['next'] !== null) {
                    $nxTmp = $check['next'];
                    $nxBand = (string) ($nxTmp['band'] ?? \App\Core\AcademicPeriod::bandForLevel((int) $nxTmp['level_id']));
                    $nxPeriod = (string) ($nxTmp['period'] ?? $nxTmp['semester']);
                    $nextMode = [
                        'level_id' => (int) $nxTmp['level_id'],
                        'level_name' => (string) $nxTmp['level_name'],
                        'semester' => (string) $nxTmp['semester'],
                        'band' => $nxBand,
                        'period' => $nxPeriod,
                        'period_label' => (string) ($nxTmp['period_label'] ?? \App\Core\AcademicPeriod::label($nxBand, $nxPeriod)),
                        'ay_id' => (int) $nxTmp['ay_id'],
                        'ay_label' => (string) $nxTmp['ay_label'],
                        'student_no' => (string) ($stuGuard['student_no'] ?? ''),
                        'program_id' => $stuGuard['program_id'] !== null ? (int) $stuGuard['program_id'] : null,
                    ];
                    $clearanceForNext = $check['clearance'];
                } elseif ($state === 'graduating') {
                    set_flash('info', 'You have completed the final academic period of your program — please see the Registrar about graduation.');
                    redirect('/dashboard');
                } elseif ($state === 'already_enrolled') {
                    set_flash('info', implode(' ', $check['reasons']));
                    redirect('/my-enrollments');
                } else {
                    set_flash('warning', Auth::is('parent') ? 'Not ready yet — see the next step on the page below.' : 'Not yet eligible for next enrollment. ' . implode(' ', array_slice($check['reasons'], 0, 3)));
                    redirect('/enrollment/eligibility' . ($enrollChild !== null ? '?child=' . (int) $enrollChild['id'] : ''));
                }
            }
        } catch (Throwable $e) { /* fail open */ }

        $levels = Database::all("SELECT id, code, name, category FROM grade_levels ORDER BY sort_order");
        $programs = Database::all("SELECT id, code, name FROM programs WHERE is_active = 1 ORDER BY name");
        $campuses = Database::all("SELECT id, code, name, address, city, province FROM campuses WHERE is_active = 1 ORDER BY name");
        // School scope: Kindergarten through Senior High for new enrollments.
        // Parents enroll young children: Kindergarten through Junior High only.
        // (Existing college records keep working via progression re-enrollment.)
        $isParentFlow = Auth::is('parent');
        $allowedCats = $isParentFlow
            ? ['kindergarten', 'elementary', 'junior_high']
            : ['kindergarten', 'elementary', 'junior_high', 'senior_high'];
        $levels = array_values(array_filter($levels, static fn($lv) => in_array($lv['category'] ?? '', $allowedCats, true)));
        $currentAy = Database::one("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
        if ($currentAy === null) {
            $currentAy = Database::one("SELECT * FROM academic_years ORDER BY id DESC LIMIT 1");
        }

        // Prevent duplicate pending / awaiting payment (per child for parents,
        // so Child 2 can be filed while Child 1's application is processing).
        $openGuard = $this->openAppsFor((int) Auth::id(), $enrollChild !== null ? (int) $enrollChild['id'] : 0);
        $hasPending = count($openGuard) > 0;
        $pendingApp = $hasPending ? $openGuard[0] : null;

        // Reusable-doc status, same source of truth as the Documents page
        // (StudentDocs::unified). Scoped per child for parents so a sibling's
        // papers never show as "on file" on another child's form. Level rules
        // apply once the level is known (re-enroll mode); otherwise markers
        // stay neutral until "Applying for" is chosen (JS refines them).
        $reusedDocs = [];
        $neededDocs = [];
        $docRules = [];
        try {
            if (class_exists(\App\Core\StudentDocs::class)) {
                $uidDocs = (int) Auth::id();
                $docChildId = ($enrollChild !== null) ? (int) $enrollChild['id'] : 0;
                $docNewFiling = Auth::is('parent') && $enrollChild === null;
                if ($enrollChild !== null) {
                    $sidDocs = (int) $enrollChild['id'];
                } else {
                    $stuForDocs = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [$uidDocs]);
                    $sidDocs = $stuForDocs ? (int) $stuForDocs['id'] : 0;
                }
                $formLevelCtx = null;
                if ($nextMode !== null) {
                    $nl = Database::one("SELECT code, category FROM grade_levels WHERE id = ? LIMIT 1", [(int) $nextMode['level_id']]);
                    if ($nl) {
                        $formLevelCtx = ['level' => $nl, 'applicant' => 'returning'];
                        $neededDocs = \App\Core\StudentDocs::neededForEnrollment($sidDocs, $uidDocs, $docChildId, $docNewFiling, $formLevelCtx);
                    }
                }
                foreach (\App\Core\StudentDocs::unified($sidDocs, $uidDocs, $docChildId, $docNewFiling) as $t => $info) {
                    if (($info['status'] ?? 'missing') === 'missing') continue;
                    $reusedDocs[$t] = [
                        'status' => (string) $info['status'],
                        'version' => $info['version'] ?? 1,
                        'review_reason' => $info['reason'],
                    ];
                }
                // Per-level checklist for the "Applying for" picker (JS).
                $docRules = $this->docRulesFor($levels);
            }
        } catch (Throwable $e) { $reusedDocs = []; $neededDocs = []; $docRules = []; }

        $periodChoices = [];
        try {
            if (class_exists(\App\Core\AcademicPeriod::class)) {
                $periodChoices = [
                    'basic' => \App\Core\AcademicPeriod::periodsFor(\App\Core\AcademicPeriod::BAND_BASIC),
                    'basic_noun' => \App\Core\AcademicPeriod::noun(\App\Core\AcademicPeriod::BAND_BASIC),
                    'shs' => \App\Core\AcademicPeriod::periodsFor(\App\Core\AcademicPeriod::BAND_SHS),
                ];
            }
        } catch (Throwable $e) {
        }
        $this->view('enrollment/apply', [
            'title'      => $nextMode ? 'Re-enrollment — ' . $nextMode['level_name'] . ' ' . ($nextMode['period_label'] ?? ($nextMode['semester'] . ' Semester')) : 'Enrollment Application',
            'levels'     => $levels,
            'programs'   => $programs,
            'campuses'   => $campuses,
            'currentAy'  => $currentAy,
            'hasPending' => (int) $hasPending > 0,
            'pendingApp' => $pendingApp,
            'clearance'  => $clearanceForNext,
            'nextMode'   => $nextMode,
            'periodChoices' => $periodChoices,
            'errors'     => [],
            'enrollChild' => $enrollChild,
            'enrollChildren' => $enrollChildren,
            'docRules'   => $docRules,
            'parentInfo' => $isParentFlow ? Database::one("SELECT first_name, middle_name, last_name, email, phone FROM users WHERE id = ? LIMIT 1", [(int) Auth::id()]) : null,
            'old'        => $nextMode ? ['grade_level_id' => $nextMode['level_id'], 'program_id' => $nextMode['program_id'], 'new_or_returning' => 'returning', 'returning_student_no' => $nextMode['student_no']] : [],
            'reusedDocs' => $reusedDocs,
            'neededDocs' => $neededDocs,
            'isParentFlow' => $isParentFlow,
        ]);
    }

    /* Handle form submission */
    public function submit(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');

        $userId = (int) Auth::id();

        // Parent child context (?child=Child 1, Child 2...). The period,
        // level, and clearance are still recomputed server-side below —
        // the browser value only selects WHICH linked child this filing is for.
        // An untagged filing (no ?child= / child_id, and the form only sends
        // child_id when acting for a linked child) is ALWAYS a new child —
        // it must never inherit the first linked child's identity, or the
        // re-enrollment guard below hijacks it into that child's checklist.
        $fileChild = null;
        if (Auth::is('parent')) {
            $childCtx = $this->enrollChildContext();
            if (!empty($childCtx['tampered'])) {
                set_flash('error', 'That child is not linked to your guardian account.');
                redirect('/dashboard');
            }
            $explicitChild = (isset($_GET['child']) && (int) $_GET['child'] > 0)
                || (isset($_POST['child_id']) && (int) $_POST['child_id'] > 0);
            $fileChild = ($explicitChild && $childCtx['child'] !== null) ? $childCtx['child'] : null;
        }
        $fileChildId = $fileChild !== null ? (int) $fileChild['id'] : 0;

        // Check duplicate pending / awaiting payment (per child for parents)
        $openApps = $this->openAppsFor($userId, $fileChildId);
        if (count($openApps) > 0) {
            $who = $fileChild !== null ? ' for ' . \App\Core\GuardianLinks::displayName($fileChild) : '';
            set_flash('error', 'You already have an ongoing application' . $who . ' (pending / awaiting payment). Please complete it first.');
            redirect('/my-enrollments');
        }

        // Guard: enrolled students may only submit a NEXT-period re-enrollment
        // when the cycle engine says they are eligible (server-side enforced).
        $nextCtx = null;
        try {
            $stuS = $fileChild;
            // A parent's NEW (untagged) filing is for another child: the
            // student row sitting on this account (if any) is a LINKED CHILD,
            // never "self". Resolving it here hijacked every new filing into
            // that child's re-enrollment guard (bounce to eligibility).
            $parentNewFiling = Auth::is('parent') && $fileChild === null;
            if ($stuS === null && !$parentNewFiling) {
                $stuS = Database::one("SELECT * FROM students WHERE user_id = ? LIMIT 1", [$userId]);
            }
            $isEnrolledSubmit = $stuS !== null && ($stuS['status'] ?? '') === 'enrolled';
            if (!$isEnrolledSubmit && !$parentNewFiling) {
                $curAyS = Database::one("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
                if ($curAyS && isset($curAyS['id'])) {
                    $apprS = (int) Database::value("SELECT COUNT(*) FROM enrollment_applications WHERE user_id = ? AND status = 'approved' AND academic_year_id = ?", [$userId, (int)$curAyS['id']]);
                    if ($apprS > 0) { $isEnrolledSubmit = true; $stuS = $stuS ?? Database::one("SELECT * FROM students WHERE user_id = ? LIMIT 1", [$userId]); }
                }
            }
            if ($isEnrolledSubmit && $stuS) {
                $check = \App\Core\EnrollmentCycle::checklist((int) $stuS['id']);
                if (($check['state'] ?? '') !== 'eligible' || $check['next'] === null) {
                    set_flash('warning', Auth::is('parent') ? 'Not ready yet — see the next step on the page below.' : 'Not yet eligible for next enrollment. ' . implode(' ', array_slice($check['reasons'] ?? ['See eligibility.'], 0, 3)));
                    redirect('/enrollment/eligibility');
                }
                $nx = $check['next'];
                $ayId = !empty($nx['ay_id']) ? (int) $nx['ay_id'] : \App\Core\EnrollmentCycle::ensureNextAy((string) $nx['ay_label']);
                if (!$ayId) {
                    set_flash('error', 'Next school year is not open yet — please contact the Registrar.');
                    redirect('/enrollment/eligibility');
                }
                $nxBand = (string) ($nx['band'] ?? \App\Core\AcademicPeriod::bandForLevel((int) $nx['level_id']));
                $nxPeriod = (string) ($nx['period'] ?? $nx['semester']);
                $nextCtx = [
                    'level_id' => (int) $nx['level_id'], 'semester' => (string) $nx['semester'],
                    'band' => $nxBand, 'period' => $nxPeriod,
                    'ay_id' => $ayId, 'clearance' => $check['clearance'],
                    'current' => $check['current'] ?? null,
                ];
            } elseif ($isEnrolledSubmit) {
                set_flash('warning', 'You are already enrolled for the active term.');
                redirect('/dashboard');
            }
        } catch (Throwable $e) { /* fail open for unexpected errors */ }

        $gradeId   = (int) ($_POST['grade_level_id'] ?? 0);
        $programId = ($_POST['program_id'] ?? '') !== '' ? (int) $_POST['program_id'] : null;
        // Re-enrollment: academic period comes from the eligibility engine,
        // never from browser input.
        $forceSemester = null;
        $forcePeriod = null;
        $forceBand = null;
        $forceAyId = 0;
        $forceClearanceId = null;
        if ($nextCtx !== null) {
            $gradeId = (int) $nextCtx['level_id'];
            $forceBand = (string) ($nextCtx['band'] ?? '');
            $forcePeriod = (string) ($nextCtx['period'] ?? '');
            // SHS/College write the legacy semester column; Basic-ed writes period_code.
            $forceSemester = $forceBand === \App\Core\AcademicPeriod::BAND_BASIC ? null : (string) $nextCtx['semester'];
            if ($forceBand !== \App\Core\AcademicPeriod::BAND_BASIC) $forcePeriod = null;
            $forceAyId = (int) $nextCtx['ay_id'];
            $forceClearanceId = isset($nextCtx['clearance']['id']) ? (int) $nextCtx['clearance']['id'] : null;
        }
        $newReturn = (string) ($_POST['new_or_returning'] ?? 'new');
        // PERMANENT STUDENT NUMBER: students can never set/change it.
        // Re-enrollment always uses the number already on their student record
        // (for parents: the selected child's number).
        $ownStudentNo = '';
        try {
            if ($fileChild !== null) {
                $ownStudentNo = trim((string) ($fileChild['student_no'] ?? ''));
            } elseif (!(Auth::is('parent'))) {
                $ownRow = Database::one("SELECT student_no FROM students WHERE user_id = ? LIMIT 1", [$userId]);
                $ownStudentNo = trim((string) ($ownRow['student_no'] ?? ''));
            }
            // Parent new filing: no self number exists on this account.
        } catch (Throwable $e) { $ownStudentNo = ''; }
        if ($nextCtx !== null) {
            $newReturn = 'returning';
            $returningStudentNo = $ownStudentNo;
        } else {
            $returningStudentNo = trim((string) ($_POST['returning_student_no'] ?? ''));
            // A portal account already linked to a student number may only
            // ever re-use its own number — never claim someone else's.
            if ($ownStudentNo !== '' && $returningStudentNo !== '' && $returningStudentNo !== $ownStudentNo) {
                $returningStudentNo = $ownStudentNo;
            }
        }
        $campusId  = ($_POST['preferred_campus_id'] ?? '') !== '' ? (int) $_POST['preferred_campus_id'] : null;
        $prevSchool = trim((string) ($_POST['previous_school'] ?? ''));
        $lastGrade  = trim((string) ($_POST['last_grade_completed'] ?? ''));

        // Child identity (parent new-child filings): the child is a separate
        // person — capture their details here, validated server-side.
        $childInfo = null;
        $isParentNewChild = Auth::is('parent') && $fileChild === null;
        if ($isParentNewChild) {
            $childInfo = [
                'first_name' => trim((string) ($_POST['child_first_name'] ?? '')),
                'middle_name' => trim((string) ($_POST['child_middle_name'] ?? '')),
                'last_name' => trim((string) ($_POST['child_last_name'] ?? '')),
                'suffix' => trim((string) ($_POST['child_suffix'] ?? '')),
                'dob' => trim((string) ($_POST['child_dob'] ?? '')),
                'gender' => trim((string) ($_POST['child_gender'] ?? '')),
                'birthplace' => trim((string) ($_POST['child_birthplace'] ?? '')),
                'nationality' => trim((string) ($_POST['child_nationality'] ?? 'Filipino')),
                'address' => trim((string) ($_POST['child_address'] ?? '')),
            ];
        }

        $errors = [];
        if ($gradeId === 0) $errors[] = 'Please select the grade/year level you are applying for.';
        if (!in_array($newReturn, ['new','returning','transferee'], true)) $errors[] = 'Invalid applicant type.';
        if ($isParentNewChild && $childInfo !== null) {
            if (mb_strlen($childInfo['first_name']) < 2 || !preg_match('/^[\p{L} .\'-]+$/u', $childInfo['first_name'])) {
                $errors[] = 'Enter the child\u2019s real first name (letters only, at least 2 characters).';
            }
            if (mb_strlen($childInfo['last_name']) < 2 || !preg_match('/^[\p{L} .\'-]+$/u', $childInfo['last_name'])) {
                $errors[] = 'Enter the child\u2019s real last name (letters only, at least 2 characters).';
            }
            if ($childInfo['dob'] === '' || ($dobTs = strtotime($childInfo['dob'])) === false) {
                $errors[] = 'Enter the child\u2019s date of birth.';
            } elseif ($dobTs > time() || $dobTs < strtotime('2000-01-01')) {
                $errors[] = 'The child\u2019s date of birth looks invalid.';
            } else {
                $childInfo['dob'] = date('Y-m-d', $dobTs);
            }
            if ($childInfo['gender'] !== '' && !in_array($childInfo['gender'], ['male', 'female', 'other'], true)) {
                $errors[] = 'Select a valid option for the child\u2019s sex.';
            }
            // Duplicate-child guard: same parent + same name + same birthdate
            // is almost certainly the same kid — point at them, never clone.
            if ($errors === []) {
                try {
                    $dupChild = null;
                    if (class_exists(\App\Core\GuardianLinks::class)) {
                        foreach (\App\Core\GuardianLinks::linkedChildren($userId) as $lc) {
                            $dobMatch = ($childInfo['dob'] !== '' && !empty($lc['date_of_birth']))
                                ? (substr((string) $lc['date_of_birth'], 0, 10) === $childInfo['dob']) : true;
                            if (mb_strtolower(trim((string) ($lc['first_name'] ?? ''))) === mb_strtolower($childInfo['first_name'])
                                && mb_strtolower(trim((string) ($lc['last_name'] ?? ''))) === mb_strtolower($childInfo['last_name'])
                                && $dobMatch) {
                                $dupChild = $lc;
                                break;
                            }
                        }
                    }
                    if ($dupChild !== null) {
                        $errors[] = 'This looks like ' . \App\Core\GuardianLinks::displayName($dupChild) . ', who is already linked to your account. Open their card on your dashboard instead of filing again — records are never merged automatically. If this is truly a different child, contact the registrar.';
                    }
                } catch (Throwable $e) {
                }
            }
        }
        if ($newReturn === 'returning') {
            if ($nextCtx !== null) {
                if ($returningStudentNo === '' || !preg_match('/^\d{7,10}$/', $returningStudentNo)) {
                    $errors[] = 'Your permanent Student Number is missing — please contact the Registrar before re-enrolling.';
                }
            } elseif ($returningStudentNo === '') {
                $errors[] = 'Student Number is required for returning students (e.g. 2023022).';
            } elseif (!preg_match('/^\d{7,10}$/', $returningStudentNo)) {
                $errors[] = 'Student Number must be 7-10 digits (e.g. 2023022).';
            }
        } else {
            $returningStudentNo = '';
        }
        // Preferred campus validation (required for all applicants)
        $campusRow = $campusId ? Database::one("SELECT id FROM campuses WHERE id = ? AND is_active = 1", [$campusId]) : null;
        if ($campusId === null) {
            $errors[] = 'Please choose your preferred school location / campus.';
        } elseif ($campusRow === null) {
            $errors[] = 'Selected campus is invalid. Please choose from the list.';
        }
        if ($prevSchool === '') $errors[] = 'Previous school is required.';

        $level = $gradeId ? Database::one("SELECT * FROM grade_levels WHERE id = ?", [$gradeId]) : null;
        if ($level === null) {
            $errors[] = 'Selected grade level does not exist.';
        } elseif ($nextCtx === null && ($level['category'] ?? '') === 'college') {
            // Backend mirror: no NEW college enrollments (never trust the dropdown).
            // Progression re-enrollment of existing college students still allowed.
            $errors[] = 'College enrollment is closed — this portal now covers Kindergarten to Senior High only.';
        } elseif (Auth::is('parent') && !in_array($level['category'] ?? '', ['kindergarten', 'elementary', 'junior_high'], true)) {
            // Backend mirror of the parent K-to-JHS restriction (never trust the dropdown).
            $errors[] = 'Parent accounts may only enroll Kindergarten to Junior High levels.';
        }

        // College requires program
        if ($level && $level['category'] === 'college') {
            if ($programId === null) $errors[] = 'Please select a college program.';
            elseif (Database::one("SELECT id FROM programs WHERE id = ?", [$programId]) === null) $errors[] = 'Selected program does not exist.';
        } else {
            $programId = null;
        }

        // New applications: academic period comes from the applicant's band
        // (validated server-side against configuration — never trusted blindly).
        // Re-enrollments already fixed $forceSemester/$forcePeriod from the engine.
        $newPeriod = $forcePeriod;
        $newSemester = $forceSemester;
        $newBand = $forceBand;
        if ($nextCtx === null && $level !== null) {
            $newBand = \App\Core\AcademicPeriod::bandForCategory($level['category'] ?? null);
            if ($newBand === \App\Core\AcademicPeriod::BAND_BASIC) {
                $posted = trim((string) ($_POST['period_code'] ?? ''));
                if ($posted === '' || !\App\Core\AcademicPeriod::isValid($newBand, $posted)) {
                    $errors[] = 'Please select the ' . \App\Core\AcademicPeriod::noun($newBand) . ' you are enrolling for (e.g. ' . \App\Core\AcademicPeriod::firstPeriod($newBand) . ').';
                } else {
                    $newPeriod = $posted;
                    $newSemester = null;
                }
            } elseif ($newBand === \App\Core\AcademicPeriod::BAND_SHS) {
                $posted = trim((string) ($_POST['semester'] ?? ''));
                if (!\App\Core\AcademicPeriod::isValid($newBand, $posted)) {
                    $errors[] = 'Please select the semester you are enrolling for (1st or 2nd Semester).';
                } else {
                    $newSemester = $posted;
                    $newPeriod = null;
                }
            } else {
                $newSemester = null;
                $newPeriod = null;
            }
        }

        $currentAy = $forceAyId > 0
            ? Database::one("SELECT id FROM academic_years WHERE id = ? LIMIT 1", [$forceAyId])
            : Database::one("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
        if ($currentAy === null) $errors[] = 'No active academic year configured. Contact registrar.';

        // Parents filing WITHOUT a child tag: the posted target may actually
        // be a LINKED child's current or next period (their student row sits
        // on this account). Route those into the child's own flow instead of
        // misfiling a duplicate under a new application.
        if (Auth::is('parent') && $fileChild === null && $currentAy !== null && $gradeId > 0 && $errors === []) {
            try {
                $postedPeriod = ($newBand === \App\Core\AcademicPeriod::BAND_BASIC) ? (string) $newPeriod : (string) $newSemester;
                if ($postedPeriod !== '' && class_exists(\App\Core\GuardianLinks::class) && class_exists(\App\Core\EnrollmentCycle::class)) {
                    foreach (\App\Core\GuardianLinks::linkedChildren($userId) as $lc) {
                        $lcId = (int) ($lc['id'] ?? 0);
                        if ($lcId <= 0) continue;
                        // Same grade + period is NOT proof of same child —
                        // siblings are often enrolled at the same level.
                        // Only treat this as the linked child when the posted
                        // identity matches (name, and DOB when both known).
                        if ($childInfo !== null) {
                            $pF = mb_strtolower(trim((string) ($childInfo['first_name'] ?? '')));
                            $pL = mb_strtolower(trim((string) ($childInfo['last_name'] ?? '')));
                            $lF = mb_strtolower(trim((string) ($lc['first_name'] ?? '')));
                            $lL = mb_strtolower(trim((string) ($lc['last_name'] ?? '')));
                            $pD = substr((string) ($childInfo['dob'] ?? ''), 0, 10);
                            $lD = substr((string) ($lc['date_of_birth'] ?? ''), 0, 10);
                            if (($pF !== '' && $pL !== '' && ($pF !== $lF || $pL !== $lL))
                                || ($pD !== '' && $lD !== '' && $pD !== $lD)) {
                                continue; // different kid — file normally
                            }
                        }
                        $lcName = \App\Core\GuardianLinks::displayName($lc);
                        $cur = \App\Core\EnrollmentCycle::currentPeriod($lcId);
                        if ($cur !== null) {
                            $curPeriod = (string) ($cur['period'] ?? $cur['semester']);
                            if ((int) $cur['level_id'] === $gradeId && $curPeriod === $postedPeriod
                                && (int) $cur['ay_id'] === (int) $currentAy['id']) {
                                set_flash('warning', 'This looks like ' . $lcName . ', who is already covered for this grade and period. Their checklist was opened instead — no duplicate was filed.');
                                redirect('/enrollment/eligibility?child=' . $lcId);
                            }
                            $next = \App\Core\EnrollmentCycle::nextPeriod((int) $cur['level_id'], $curPeriod !== '' ? $curPeriod : (string) $cur['semester'], (int) $cur['ay_id']);
                            if (empty($next['graduating'])) {
                                $np = (string) ($next['period'] ?? $next['semester']);
                                $ayOk = empty($next['ay_id']) || (int) $next['ay_id'] === (int) $currentAy['id'];
                                if ((int) $next['level_id'] === $gradeId && $np === $postedPeriod && $ayOk) {
                                    set_flash('info', 'This matches ' . $lcName . '\'s next period — continued in their re-enrollment flow so nothing is filed twice.');
                                    redirect('/enroll?child=' . $lcId);
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
            }
        }

        // Parents filing a NEW (untagged) application must not double-cover a
        // linked child that already holds an open or approved application for
        // this exact grade + period — point at that child's flow instead.
        // Tagged rows name their child; untagged legacy rows stay anonymous.
        if (Auth::is('parent') && $fileChild === null && $currentAy !== null && $gradeId > 0 && $errors === []) {
            try {
                $hasChildCol = false;
                try {
                    $hasChildCol = (bool) Database::value(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'applicant_child_id'"
                    );
                } catch (Throwable $e) {
                }
                $periodFrag = ($newBand === \App\Core\AcademicPeriod::BAND_BASIC && $hasEaPeriodSubmit)
                    ? "AND COALESCE(period_code,'') = COALESCE(?,'')"
                    : "AND COALESCE(semester,'') = COALESCE(?,'')";
                $periodVal = ($newBand === \App\Core\AcademicPeriod::BAND_BASIC && $hasEaPeriodSubmit) ? $newPeriod : $newSemester;
                $baseWhere = "user_id = ? AND academic_year_id = ? AND grade_level_applied_id = ? {$periodFrag}
                                AND status IN ('pending','accounting_approved','awaiting_payment','action_required','approved')";
                $baseBind = [$userId, (int) $currentAy['id'], $gradeId, $periodVal];
                $linked = class_exists(\App\Core\GuardianLinks::class)
                    ? \App\Core\GuardianLinks::linkedChildren($userId) : [];
                $linkedIds = [];
                foreach ($linked as $lc) $linkedIds[] = (int) ($lc['id'] ?? 0);
                $hitChild = null;
                $hitRow = null;
                // Rows tagged to a linked child: name that child.
                foreach ($linked as $lc) {
                    if (!$hasChildCol) break;
                    $hitRow = Database::one(
                        "SELECT application_no, status FROM enrollment_applications
                          WHERE {$baseWhere} AND applicant_child_id = ? LIMIT 1",
                        array_merge($baseBind, [(int) $lc['id']])
                    );
                    if ($hitRow !== null) {
                        $hitChild = $lc;
                        break;
                    }
                }
                if ($hitRow === null) {
                    // Any other same-target row of this account (untagged, or
                    // tagged to a not-yet-linked child): block anonymously.
                    // A tagged-but-unlinked first filing must NOT be invisible
                    // here, or the same child gets filed twice.
                    // Exception: a NEW filing naming a DIFFERENT child
                    // (siblings share grade + period) must not be blocked by
                    // another kid's row — compare child identity first.
                    if ($hasChildCol && $linkedIds !== []) {
                        $in = implode(',', $linkedIds);
                        $hitRow = Database::one(
                            "SELECT application_no, status, applicant_child_id, student_id FROM enrollment_applications
                              WHERE {$baseWhere} AND (applicant_child_id IS NULL OR applicant_child_id NOT IN ({$in})) LIMIT 1",
                            $baseBind
                        );
                    } else {
                        $nullWhere = $hasChildCol
                            ? "WHERE {$baseWhere} AND applicant_child_id IS NULL LIMIT 1"
                            : "WHERE {$baseWhere} LIMIT 1";
                        $hitRow = Database::one(
                            "SELECT application_no, status, applicant_child_id, student_id FROM enrollment_applications {$nullWhere}",
                            $baseBind
                        );
                    }
                    if ($hitRow !== null && $childInfo !== null && Auth::is('parent') && $fileChild === null) {
                        $otherId = (int) ($hitRow['applicant_child_id'] ?? $hitRow['student_id'] ?? 0);
                        $other = $otherId > 0 ? Database::one("SELECT first_name, last_name, date_of_birth FROM students WHERE id = ? LIMIT 1", [$otherId]) : null;
                        if (is_array($other)) {
                            $pF = mb_strtolower(trim((string) ($childInfo['first_name'] ?? '')));
                            $pL = mb_strtolower(trim((string) ($childInfo['last_name'] ?? '')));
                            $oF = mb_strtolower(trim((string) ($other['first_name'] ?? '')));
                            $oL = mb_strtolower(trim((string) ($other['last_name'] ?? '')));
                            $pD = substr((string) ($childInfo['dob'] ?? ''), 0, 10);
                            $oD = substr((string) ($other['date_of_birth'] ?? ''), 0, 10);
                            if (($pF !== '' && $pL !== '' && ($pF !== $oF || $pL !== $oL))
                                || ($pD !== '' && $oD !== '' && $pD !== $oD)) {
                                $hitRow = null; // different kid (sibling) — allow filing
                            }
                        }
                        // Unresolvable legacy row: keep blocking (can't tell).
                    }
                }
                if ($hitRow !== null) {
                    if ($hitChild !== null) {
                        $who = \App\Core\GuardianLinks::displayName($hitChild);
                        $errors[] = $who . ' already has application ' . ($hitRow['application_no'] ?? '') . ' (' . str_replace('_', ' ', (string) ($hitRow['status'] ?? '')) . ') covering this grade and period. To move them forward, use their Next Period button instead of filing again.';
                    } else {
                        $errors[] = 'Your account already has application ' . ($hitRow['application_no'] ?? '') . ' (' . str_replace('_', ' ', (string) ($hitRow['status'] ?? '')) . ') covering this grade and period. Track it under My Applications, or contact the registrar if it belongs to a different child.';
                    }
                }
            } catch (Throwable $e) {
            }
        }

        // Document reuse (same source of truth as the Documents page):
        // only missing / rejected / resubmission-requested docs need upload.
        // A valid row in EITHER the permanent file or any past application
        // satisfies the requirement — never re-ask for accepted uploads.
        // WHAT is required depends on the applying level (DepEd-style rules:
        // Kinder needs PSA + ECCD + photo — never Form 137 or report cards).
        $levelCtx = ['level' => $level, 'applicant' => $newReturn];
        $allRequired = ['birth_certificate' => 'PSA Birth Certificate', 'good_moral' => 'Certificate of Good Moral', 'id_photo' => 'ID Photo (2x2)'];
        try {
            if (class_exists(\App\Core\StudentDocs::class) && $level !== null) {
                $allRequired = [];
                foreach (\App\Core\StudentDocs::requiredForLevel($level, $newReturn) as $t) {
                    $allRequired[$t] = \App\Core\StudentDocs::label($t);
                }
            }
        } catch (Throwable $e) {
        }
        $requiredDocs = $allRequired;
        try {
            if (class_exists(\App\Core\StudentDocs::class)) {
                $fileCid = $fileChild !== null ? (int) $fileChild['id'] : 0;
                $fileNew = Auth::is('parent') && $fileChild === null;
                $rs = $fileChild !== null ? $fileChild : Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [$userId]);
                $needed = \App\Core\StudentDocs::neededForEnrollment($rs ? (int) $rs['id'] : 0, $userId, $fileCid, $fileNew, $levelCtx);
                $requiredDocs = [];
                foreach ($allRequired as $key => $label) {
                    if (isset($needed[$key])) $requiredDocs[$key] = $label;
                }
            }
        } catch (Throwable $e) { $requiredDocs = $allRequired; }
        // Prior-schooling waivers (parent declarations): a child who never
        // attended preschool/Kindergarten cannot produce those papers.
        // Strictly scoped — ECCD for Kinder/G1-new, report card for G1-new
        // only. PSA + photo are never waivable. Server is authoritative.
        $waivedDocs = [];
        $lvlCode = strtoupper(trim((string) ($level['code'] ?? '')));
        $lvlCat = trim((string) ($level['category'] ?? ''));
        $isG1New = ($lvlCode === 'G1' && $newReturn === 'new');
        $isKinderNew = (($lvlCode === 'K' || $lvlCat === 'kindergarten') && $newReturn === 'new');
        if (!empty($_POST['waive_eccd']) && ($isKinderNew || $isG1New) && isset($requiredDocs['eccd_checklist'])) {
            unset($requiredDocs['eccd_checklist']);
            $waivedDocs[] = 'eccd_checklist';
        }
        if (!empty($_POST['waive_form138']) && $isG1New && isset($requiredDocs['form138_report_card'])) {
            unset($requiredDocs['form138_report_card']);
            $waivedDocs[] = 'form138_report_card';
        }
        // Canonical doc type => actual <input name> on the form (they differ
        // for the report card: input name="form138").
        $fieldFor = ['form138_report_card' => 'form138', 'previous_school_record' => 'prev_record'];
        foreach ($requiredDocs as $key => $label) {
            $field = $fieldFor[$key] ?? $key;
            if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $errors[] = $label . ' is required.';
            }
        }

        if ($errors !== []) {
            $levels = Database::all("SELECT id, code, name, category FROM grade_levels ORDER BY sort_order");
            $errCats = Auth::is('parent')
                ? ['kindergarten', 'elementary', 'junior_high']
                : ['kindergarten', 'elementary', 'junior_high', 'senior_high'];
            $levels = array_values(array_filter($levels, static fn($lv) => in_array($lv['category'] ?? '', $errCats, true)));
            $programs = Database::all("SELECT id, code, name FROM programs WHERE is_active = 1 ORDER BY name");
            $campuses = Database::all("SELECT id, code, name, address, city, province FROM campuses WHERE is_active = 1 ORDER BY name");
            $currentAyRow = Database::one("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
            // Same unified source so the re-rendered form agrees with Documents.
            $errReused = [];
            $errNeeded = [];
            try {
                if (class_exists(\App\Core\StudentDocs::class)) {
                    $errCid = $fileChild !== null ? (int) $fileChild['id'] : 0;
                    $errNew = Auth::is('parent') && $fileChild === null;
                    $errStu = $fileChild !== null ? $fileChild : Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [$userId]);
                    $errSid = $errStu ? (int) $errStu['id'] : 0;
                    $errNeeded = \App\Core\StudentDocs::neededForEnrollment($errSid, $userId, $errCid, $errNew, $levelCtx);
                    foreach (\App\Core\StudentDocs::unified($errSid, $userId, $errCid, $errNew) as $t => $info) {
                        if (($info['status'] ?? 'missing') === 'missing') continue;
                        $errReused[$t] = ['status' => (string) $info['status'], 'version' => $info['version'] ?? 1, 'review_reason' => $info['reason']];
                    }
                }
            } catch (Throwable $e) { $errReused = []; $errNeeded = []; }
            $errPeriodChoices = [];
            $errDocRules = [];
            try {
                if (class_exists(\App\Core\AcademicPeriod::class)) {
                    $errPeriodChoices = [
                        'basic' => \App\Core\AcademicPeriod::periodsFor(\App\Core\AcademicPeriod::BAND_BASIC),
                        'basic_noun' => \App\Core\AcademicPeriod::noun(\App\Core\AcademicPeriod::BAND_BASIC),
                        'shs' => \App\Core\AcademicPeriod::periodsFor(\App\Core\AcademicPeriod::BAND_SHS),
                    ];
                }
                if (class_exists(\App\Core\StudentDocs::class)) {
                    $errDocRules = $this->docRulesFor($levels);
                }
            } catch (Throwable $e) {
            }
            $this->view('enrollment/apply', [
                'title' => 'Enrollment Application',
                'levels' => $levels,
                'programs' => $programs,
                'campuses' => $campuses,
                'currentAy' => $currentAyRow,
                'hasPending' => false,
                'errors' => $errors,
                'old' => ['grade_level_id' => $gradeId, 'program_id' => $programId, 'new_or_returning' => $newReturn, 'returning_student_no' => $returningStudentNo, 'preferred_campus_id' => $campusId, 'previous_school' => $prevSchool, 'last_grade_completed' => $lastGrade, 'period_code' => $newPeriod, 'semester' => $newSemester,
                    'child_first_name' => trim((string) ($_POST['child_first_name'] ?? '')), 'child_middle_name' => trim((string) ($_POST['child_middle_name'] ?? '')),
                    'child_last_name' => trim((string) ($_POST['child_last_name'] ?? '')), 'child_suffix' => trim((string) ($_POST['child_suffix'] ?? '')),
                    'child_dob' => trim((string) ($_POST['child_dob'] ?? '')), 'child_gender' => trim((string) ($_POST['child_gender'] ?? '')),
                    'child_birthplace' => trim((string) ($_POST['child_birthplace'] ?? '')), 'child_nationality' => trim((string) ($_POST['child_nationality'] ?? '')),
                    'child_address' => trim((string) ($_POST['child_address'] ?? '')),
                    'waive_eccd' => !empty($_POST['waive_eccd']), 'waive_form138' => !empty($_POST['waive_form138'])],
                'reusedDocs' => $errReused,
                'neededDocs' => $errNeeded,
                'isParentFlow' => Auth::is('parent'),
                'periodChoices' => $errPeriodChoices,
                'enrollChild' => $fileChild,
                'enrollChildren' => (Auth::is('parent') && class_exists(\App\Core\GuardianLinks::class)) ? (function () use ($userId) {
                    try {
                        return \App\Core\GuardianLinks::linkedChildren($userId);
                    } catch (Throwable $e) {
                        return [];
                    }
                })() : [],
                'docRules' => $errDocRules,
                'parentInfo' => Auth::is('parent') ? Database::one("SELECT first_name, middle_name, last_name, email, phone FROM users WHERE id = ? LIMIT 1", [$userId]) : null,
            ]);
            return;
        }

        // Generate application number
        $appNo = 'EN-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        // New child, own record: create the applicant student row NOW
        // (user_id NULL — the child has no login) so the application, its
        // documents, and later approval all point at the right kid.
        // Filing FOR a linked child reuses their row (filling blanks only).
        $filingStudentId = null;
        if ($fileChild !== null) {
            $filingStudentId = (int) $fileChild['id'];
        } elseif ($isParentNewChild && $childInfo !== null) {
            try {
                $idCols = \App\Core\GuardianLinks::hasNameCols()
                    ? ['first_name' => $childInfo['first_name'], 'middle_name' => $childInfo['middle_name'] !== '' ? $childInfo['middle_name'] : null,
                       'last_name' => $childInfo['last_name'], 'suffix' => $childInfo['suffix'] !== '' ? $childInfo['suffix'] : null,
                       'birthplace' => $childInfo['birthplace'] !== '' ? $childInfo['birthplace'] : null]
                    : [];
                $filingStudentId = Database::insert('students', array_merge([
                    'user_id' => null,
                    'grade_level_id' => $gradeId,
                    'date_of_birth' => $childInfo['dob'] !== '' ? $childInfo['dob'] : null,
                    'gender' => $childInfo['gender'] !== '' ? $childInfo['gender'] : null,
                    'nationality' => $childInfo['nationality'] !== '' ? $childInfo['nationality'] : 'Filipino',
                    'address_line1' => $childInfo['address'] !== '' ? mb_substr($childInfo['address'], 0, 150) : null,
                    'status' => 'applicant',
                    'admission_date' => date('Y-m-d'),
                ], $idCols));
                Database::insert('audit_logs', [
                    'actor_user_id' => $userId, 'action' => 'student.created_by_parent',
                    'entity_type' => 'students', 'entity_id' => $filingStudentId, 'ip_address' => client_ip(),
                ]);
            } catch (Throwable $e) {
                storage_log('parent child-row create failed: ' . $e->getMessage());
                set_flash('error', 'Could not create the child record. Please try again.');
                redirect('/enroll');
            }
        }

        $clearanceId = $forceClearanceId;
        if ($clearanceId === null) {
            try {
                $stuTmp = $fileChild !== null ? $fileChild : Database::one("SELECT id FROM students WHERE user_id=? LIMIT 1", [$userId]);
                if ($stuTmp) {
                    // A clearance authorizes the NEXT enrollment only when it was
                    // issued for the just-completed CURRENT period — never reuse
                    // a clearance from another quarter/semester (§17).
                    $curPeriodForClr = null;
                    if ($nextCtx !== null && isset($nextCtx['current'])) {
                        $cc = $nextCtx['current'];
                        $curPeriodForClr = (string) (($cc['period'] ?? '') !== '' ? $cc['period'] : ($cc['semester'] ?? ''));
                    }
                    $clrAvail = null;
                    if ($curPeriodForClr !== null && $curPeriodForClr !== '') {
                        $clrAvail = Database::one(
                            "SELECT id FROM clearances WHERE student_id=? AND status='issued' AND semester=? ORDER BY issued_at DESC LIMIT 1",
                            [(int)$stuTmp['id'], $curPeriodForClr]
                        );
                    }
                    if ($clrAvail === null) {
                        $clrAvail = Database::one("SELECT id FROM clearances WHERE student_id=? AND status='issued' ORDER BY issued_at DESC LIMIT 1", [(int)$stuTmp['id']]);
                    }
                    if ($clrAvail) $clearanceId = (int)$clrAvail['id'];
                }
            } catch(Throwable $e){}
        }
        $hasEaPeriodSubmit = false;
        try {
            $hasEaPeriodSubmit = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'period_code'"
            );
        } catch (Throwable $e) {
        }
        try {
            $appId = Database::transaction(function () use ($userId, $appNo, $currentAy, $gradeId, $programId, $newReturn, $returningStudentNo, $campusId, $prevSchool, $lastGrade, $clearanceId, $newSemester, $newPeriod, $newBand, $hasEaPeriodSubmit, $fileChildId, $filingStudentId, $waivedDocs) {
                // Race-safe duplicate guard for the exact target period.
                // Tagged filings are separated per child. A NEW filing owns
                // its just-created student row ($filingStudentId), so only
                // rows tagged to THAT child — or anonymous legacy rows no one
                // can attribute — may block it. Another kid's row (e.g. an
                // enrolled sibling's approved application) must not block.
                // Column may predate the patch → fall back to legacy checks.
                $childScope = '';
                $childBind = [];
                try {
                    $hasChildCol = (bool) Database::value(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'applicant_child_id'"
                    );
                    if ($hasChildCol && $fileChildId > 0) {
                        $childScope = ' AND (applicant_child_id IS NULL OR applicant_child_id = ?)';
                        $childBind = [$fileChildId];
                    } elseif ($hasChildCol && $filingStudentId !== null && (int) $filingStudentId > 0) {
                        $childScope = ' AND (applicant_child_id IS NULL OR applicant_child_id = ?)';
                        $childBind = [(int) $filingStudentId];
                    }
                } catch (Throwable $e) {
                }
                if ($newBand === \App\Core\AcademicPeriod::BAND_BASIC && $hasEaPeriodSubmit) {
                    $dupCheck = Database::one(
                        "SELECT id FROM enrollment_applications WHERE user_id = ? AND academic_year_id = ?
                           AND COALESCE(period_code,'') = COALESCE(?,'')
                           AND status IN ('pending','accounting_approved','awaiting_payment','action_required','approved')" . $childScope . " LIMIT 1",
                        array_merge([$userId, (int) $currentAy['id'], $newPeriod], $childBind)
                    );
                } else {
                    $dupCheck = Database::one(
                        "SELECT id FROM enrollment_applications WHERE user_id = ? AND academic_year_id = ?
                           AND COALESCE(semester,'') = COALESCE(?,'')
                           AND status IN ('pending','accounting_approved','awaiting_payment','action_required','approved')" . $childScope . " LIMIT 1",
                        array_merge([$userId, (int) $currentAy['id'], $newSemester], $childBind)
                    );
                }
                if ($dupCheck !== null) {
                    throw new \RuntimeException('An enrollment record already exists for that school year and period.');
                }
                $row = [
                    'application_no'         => $appNo,
                    'user_id'                => $userId,
                    'academic_year_id'       => (int) $currentAy['id'],
                    'semester'               => $newSemester,
                    'grade_level_applied_id' => $gradeId,
                    'program_id'             => $programId,
                    'new_or_returning'       => $newReturn,
                    'returning_student_no'   => $returningStudentNo !== '' ? $returningStudentNo : null,
                    'preferred_campus_id'    => $campusId,
                    'clearance_id'           => $clearanceId,
                    'previous_school'        => $prevSchool,
                    'last_grade_completed'   => $lastGrade !== '' ? $lastGrade : null,
                    'status'                 => 'pending',
                ];
                if ($hasEaPeriodSubmit) $row['period_code'] = $newPeriod;
                // Parent-declared prior-schooling waivers (visible to reviewers).
                try {
                    $hasWaiverCol = (bool) Database::value(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'doc_waivers'"
                    );
                    if ($hasWaiverCol && $waivedDocs !== []) $row['doc_waivers'] = implode(',', $waivedDocs);
                } catch (Throwable $e) {
                }
                // The application belongs to the child's own student row from
                // day one (parent new-child filings create it just above).
                if ($filingStudentId !== null && $filingStudentId > 0) {
                    $row['student_id'] = $filingStudentId;
                }
                try {
                    $hasChildColIns = (bool) Database::value(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'applicant_child_id'"
                    );
                    if ($hasChildColIns && $fileChildId > 0) $row['applicant_child_id'] = $fileChildId;
                    elseif ($hasChildColIns && $filingStudentId !== null && $filingStudentId > 0) $row['applicant_child_id'] = $filingStudentId;
                } catch (Throwable $e) {
                }
                $id = Database::insert('enrollment_applications', $row);
                if ($clearanceId) Database::run("UPDATE clearances SET status='used' WHERE id=? AND status='issued'", [$clearanceId]);
                return $id;
            });
        } catch (Throwable $e) {
            storage_log('enrollment insert failed: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'already exists')) {
                set_flash('warning', $e->getMessage());
                redirect('/my-enrollments');
            }
            set_flash('error', 'Could not save your application. Please try again.');
            redirect('/enroll');
        }

        // Handle uploads (only missing / resubmission-requested types need files;
        // verified permanent docs are linked automatically afterwards).
        $docTypes = [
            'form137' => 'form137',
            'form138' => 'form138_report_card',
            'birth_certificate' => 'birth_certificate',
            'good_moral' => 'good_moral',
            'id_photo' => 'id_photo',
            'valid_id' => 'valid_id',
            'prev_record' => 'previous_school_record',
            'eccd_checklist' => 'eccd_checklist',
        ];
        $uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'documents';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

        $uploaded = 0;
        foreach ($docTypes as $field => $docType) {
            if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) continue;
            $file = $_FILES[$field];
            if ($file['size'] > UPLOAD_MAX_BYTES) continue;
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? 'application/octet-stream');
            if ($finfo) finfo_close($finfo);
            if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
                // Allow images even if finfo says image/jpeg etc - already covered
                if (!str_starts_with($mime, 'image/')) continue;
            }
            $ext = \App\Core\Uploads::safeExt((string) $file['name'], (string) $mime);
            if ($ext === null) continue; // unapproved extension (e.g. html/svg/js) — never lands on disk
            $stored = \App\Core\Uploads::storedName('app_' . $appId . '_' . $docType, $ext);
            $dest = $uploadDir . DIRECTORY_SEPARATOR . $stored;
            if (!is_uploaded_file($file['tmp_name'])) continue;
            if (!move_uploaded_file($file['tmp_name'], $dest)) continue;
            try {
                Database::insert('enrollment_documents', [
                    'application_id'    => $appId,
                    'doc_type'          => $docType,
                    'original_filename' => $file['name'],
                    'stored_path'       => 'documents/' . $stored,
                    'mime_type'         => $mime,
                    'file_size_bytes'   => (int) $file['size'],
                ]);
                $uploaded++;
            } catch (Throwable $e) {
                storage_log('doc insert failed: ' . $e->getMessage());
            }
        }

        if ($uploaded === 0) {
            // At least warn, but keep application
            storage_log('enrollment ' . $appId . ' had no documents saved');
        }

        // Permanent reuse linkage: link verified docs + backfill new uploads.
        // For parent filings the papers belong to the selected child —
        // never to a sibling or the parent's own (nonexistent) row.
        try {
            $linkStudentId = $fileChildId > 0 ? $fileChildId : 0;
            if ($linkStudentId <= 0 && $filingStudentId !== null && $filingStudentId > 0) {
                $linkStudentId = $filingStudentId;
            }
            if ($linkStudentId <= 0 && !(Auth::is('parent'))) {
                $ls = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [$userId]);
                if ($ls) $linkStudentId = (int) $ls['id'];
            }
            if ($linkStudentId <= 0) {
                $linked = Database::one("SELECT student_id FROM enrollment_applications WHERE id = ? LIMIT 1", [$appId]);
                if ($linked && !empty($linked['student_id'])) $linkStudentId = (int) $linked['student_id'];
            }
            if ($linkStudentId > 0 && class_exists(\App\Core\StudentDocs::class)) {
                \App\Core\StudentDocs::backfillFromApplication($appId, $linkStudentId);
                $reused = \App\Core\StudentDocs::linkVerifiedIntoApplication($appId, $linkStudentId);
                if ($reused > 0) storage_log('enrollment ' . $appId . ' reused ' . $reused . ' verified document(s)');
            }
        } catch (Throwable $e) { storage_log('enrollment doc reuse failed ' . $appId . ': ' . $e->getMessage()); }

        // Notify admin via audit log
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => $userId,
                'action' => 'enrollment.submitted',
                'entity_type' => 'enrollment_applications',
                'entity_id' => $appId,
                'ip_address' => client_ip(),
            ]);
        } catch (Throwable $e) {}
        // Record parent-declared waivers (durable + reviewer-visible).
        if ($waivedDocs !== []) {
            try {
                Database::insert('audit_logs', [
                    'actor_user_id' => $userId,
                    'action' => 'enrollment.docs_waived',
                    'entity_type' => 'enrollment_applications',
                    'entity_id' => $appId,
                    'new_values_json' => json_encode(['waived' => $waivedDocs, 'reason' => 'parent declared no prior preschool/Kindergarten record']),
                    'ip_address' => client_ip(),
                ]);
            } catch (Throwable $e) {}
        }
        // Notify the Stage-1 owner (shows in their bell on every page)
        try {
            \App\Controllers\NotificationController::pushToAccounting(
                'New enrollment application: ' . $appNo,
                'A student submitted enrollment ' . $appNo . '. Review in Enrollments queue.',
                'info', '/accounting/enrollments'
            );
        } catch (Throwable $e) {}
        // Acknowledge the filer (parents get no linked child card until
        // approval, so this bell is their receipt that the filing exists).
        try {
            $filerKid = '';
            if ($isParentNewChild && $childInfo !== null) {
                $filerKid = trim(trim((string) ($childInfo['first_name'] ?? '')) . ' ' . trim((string) ($childInfo['last_name'] ?? '')));
            } elseif ($fileChild !== null && class_exists(\App\Core\GuardianLinks::class)) {
                $filerKid = \App\Core\GuardianLinks::displayName($fileChild);
            }
            \App\Controllers\NotificationController::push(
                $userId,
                'Application ' . $appNo . ' submitted' . ($filerKid !== '' ? ' for ' . $filerKid : ''),
                'Your enrollment filing was received and is now with Accounting. Track it any time under My Applications.'
                . ($filerKid !== '' && $isParentNewChild ? ' ' . $filerKid . ' will appear on your dashboard automatically once approved and paid.' : ''),
                'success', '/my-enrollments'
            );
        } catch (Throwable $e) {}

        set_flash('success', 'Application ' . $appNo . ' submitted! Track its status below.' . ($waivedDocs !== [] ? ' Filed without: ' . implode(', ', array_map(static fn($w) => $w === 'eccd_checklist' ? 'ECCD (declared no preschool)' : 'Report Card (declared no Kinder)', $waivedDocs)) . '.' : ''));
        redirect('/my-enrollments');
    }

    /**
     * Per-level document checklist for the "Applying for" picker (JS).
     * @return array<int,array{code:string,category:string,name:string,new:string[],returning:string[],transferee:string[],hints:array<string,string>}>
     */
    private function docRulesFor(array $levels): array
    {
        $out = [];
        try {
            if (!class_exists(\App\Core\StudentDocs::class)) return $out;
            foreach ($levels as $lv) {
                $req = [
                    'new' => \App\Core\StudentDocs::requiredForLevel($lv, 'new'),
                    'returning' => \App\Core\StudentDocs::requiredForLevel($lv, 'returning'),
                    'transferee' => \App\Core\StudentDocs::requiredForLevel($lv, 'transferee'),
                ];
                $hints = [];
                foreach (array_unique(array_merge($req['new'], $req['returning'], $req['transferee'])) as $t) {
                    $hints[$t] = \App\Core\StudentDocs::hintFor($t, $lv);
                }
                $out[(int) $lv['id']] = [
                    'code' => (string) ($lv['code'] ?? ''),
                    'category' => (string) ($lv['category'] ?? ''),
                    'name' => (string) ($lv['name'] ?? ''),
                    'new' => $req['new'],
                    'returning' => $req['returning'],
                    'transferee' => $req['transferee'],
                    'hints' => $hints,
                ];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Parent child context for enrollment flows (Child 1, Child 2, ...).
     * ?child= / POST child_id is ownership-checked against guardian_students;
     * a forged id yields tampered=true (caller bounces, never swaps silently).
     * No ?child= → first linked child. Non-parents get an empty context.
     * @return array{child:?array,tampered:bool,children:array}
     */
    private function enrollChildContext(): array
    {
        $empty = ['child' => null, 'tampered' => false, 'children' => []];
        if (!Auth::is('parent') || !class_exists(\App\Core\GuardianLinks::class)) {
            return $empty;
        }
        $uid = (int) Auth::id();
        try {
            $children = \App\Core\GuardianLinks::linkedChildren($uid);
        } catch (Throwable $e) {
            $children = [];
        }
        $asked = (int) ($_GET['child'] ?? $_POST['child_id'] ?? 0);
        if ($asked > 0) {
            try {
                $owned = \App\Core\GuardianLinks::ownedChild($uid, $asked);
            } catch (Throwable $e) {
                $owned = null;
            }
            if ($owned !== null) {
                return ['child' => $owned, 'tampered' => false, 'children' => $children];
            }
            return ['child' => null, 'tampered' => true, 'children' => $children];
        }
        if ($children === []) {
            return $empty;
        }
        try {
            $first = \App\Core\GuardianLinks::ownedChild($uid, 0);
        } catch (Throwable $e) {
            $first = null;
        }
        return ['child' => $first, 'tampered' => false, 'children' => $children];
    }

    /** Open applications blocking a new filing (per-child for parents). */
    private function openAppsFor(int $userId, int $childId): array
    {
        try {
            return Database::all(
                "SELECT * FROM enrollment_applications
                  WHERE user_id = ? AND status IN ('pending','accounting_approved','awaiting_payment','action_required')
                    AND (applicant_child_id IS NULL OR applicant_child_id = ?)
                  ORDER BY created_at DESC",
                [$userId, $childId]
            );
        } catch (Throwable $e) {
            // Column may predate the applicant_child_id patch: legacy behavior.
            try {
                return Database::all(
                    "SELECT * FROM enrollment_applications
                      WHERE user_id = ? AND status IN ('pending','accounting_approved','awaiting_payment','action_required')
                      ORDER BY created_at DESC",
                    [$userId]
                );
            } catch (Throwable $e2) {
                return [];
            }
        }
    }

    /* Next-semester eligibility (enrollment cycle) */
    public function eligibility(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $childCtx = $this->enrollChildContext();
        if (!empty($childCtx['tampered'])) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $student = null;
        if (Auth::is('parent')) {
            // Each linked child (Child 1, Child 2, ...) has its own checklist.
            // No linked children yet → first-enrollment guidance.
            $student = $childCtx['child'];
        } else {
            $student = Database::one("SELECT * FROM students WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
        }
        if ($student === null) {
            $this->view('enrollment/eligibility', [
                'title' => 'Next Enrollment Eligibility', 'state' => 'no_record',
                'check' => ['state' => 'no_record', 'reasons' => ['No student record found.'], 'items' => []],
                'children' => $childCtx['children'], 'activeChild' => null,
            ]);
            return;
        }
        $hasApproved = (int) Database::value(
            "SELECT COUNT(*) FROM enrollment_applications WHERE student_id = ? AND status = 'approved'",
            [(int) $student['id']]
        );
        if ($hasApproved === 0 && ($student['status'] ?? '') !== 'enrolled') {
            $this->view('enrollment/eligibility', [
                'title' => 'Next Enrollment Eligibility', 'state' => 'first_enrollment',
                'check' => ['state' => 'first_enrollment', 'reasons' => [], 'items' => []],
                'children' => $childCtx['children'], 'activeChild' => $childCtx['child'],
            ]);
            return;
        }
        $check = \App\Core\EnrollmentCycle::checklist((int) $student['id']);
        $next = $check['next'] ?? null;
        $offerLink = null;
        if ($next !== null && empty($next['graduating']) && !empty($next['ay_id'])) {
            $offerLink = '/student/class-offerings';
        }
        // Parent-simple guide: precomputed here (never in the view) so the
        // page cannot render half-empty. Diagnostic-logged for support.
        // TEMP-DIAG: proves exactly which view file the live app reads.
        $vf = APP_ROOT . '/views/enrollment/eligibility.php';
        storage_log('eligibility viewfile=' . $vf . ' exists=' . (is_file($vf) ? '1' : '0') . ' mtime=' . (@filemtime($vf) ?: 0) . ' size=' . (@filesize($vf) ?: 0) . ' md5=' . (@md5_file($vf) ?: 'none'));
        $parentGuide = null;
        if (Auth::is('parent')) {
            $parentGuide = self::parentGuide($check, $childCtx['child']);
            storage_log('eligibility parent guide state=' . ($check['state'] ?? '?') . ' step=' . ($parentGuide['step'] !== '' ? $parentGuide['step'] : 'none'));
        }
        $this->view('enrollment/eligibility', [
            'title' => 'Next Enrollment Eligibility', 'state' => $check['state'] ?? 'not_eligible',
            'check' => $check, 'offerLink' => $offerLink,
            'children' => $childCtx['children'], 'activeChild' => $childCtx['child'],
            'parentGuide' => $parentGuide,
        ]);
    }

    /**
     * One-screen parent guide for Next Enrollment Eligibility: status, next
     * period, and the single next step. Pure data — the view only echoes.
     * @return array{name:string,nextLabel:string,step:string,link:string,label:string,enrollUrl:string}
     */
    private static function parentGuide(array $check, $activeChild): array
    {
        $name = '';
        $cid = 0;
        if (is_array($activeChild)) {
            $cid = (int) ($activeChild['id'] ?? 0);
            $n = trim(trim((string) ($activeChild['first_name'] ?? '')) . ' ' . trim((string) ($activeChild['last_name'] ?? '')));
            $name = $n !== '' ? $n : ($cid > 0 ? 'Child #' . $cid : '');
        }
        $nextLabel = '';
        $next = $check['next'] ?? null;
        if (is_array($next) && empty($next['graduating'])) {
            $band = (string) ($next['band'] ?? 'college');
            $noun = 'Semester';
            if ($band === 'basic') {
                $pc = (string) ($next['period'] ?? '');
                $noun = (strpos($pc, 'T') === 0 && $pc !== '') ? 'Term' : 'Quarter';
            }
            $per = '';
            if (!empty($next['period_label'])) {
                $per = (string) $next['period_label'];
            } else {
                $s = trim((string) ($next['semester'] ?? ''));
                $per = in_array($s, ['1st', '2nd'], true) ? $s . ' Semester' : $s;
            }
            if ($per === '') $per = '—';
            $parts = [trim((string) ($next['level_name'] ?? '')), trim($noun . ' ' . $per), trim((string) ($next['ay_label'] ?? ''))];
            $nextLabel = implode(' · ', array_filter($parts));
        }
        $map = [
            'grades' => ['Waiting for the teacher to finalize grades.', '/student/grades', 'Check Grades'],
            'permits' => ['Please finish the Finals exam permit first.', '/student/clearances', 'Go to Clearances'],
            'teachers' => ['Clearance is still being signed — follow it under Clearances.', '/student/clearances', 'Go to Clearances'],
            'accounting' => ['Clearance payment review is still ongoing — follow it under Clearances.', '/student/clearances', 'Go to Clearances'],
            'registrar' => ['Registrar review is still ongoing — follow it under Clearances.', '/student/clearances', 'Go to Clearances'],
            'account' => ['There is an account issue — please contact the registrar.', '', ''],
        ];
        $step = '';
        $link = '';
        $label = '';
        $items = (isset($check['items']) && is_array($check['items'])) ? $check['items'] : [];
        foreach ($map as $k => $v) {
            if (isset($items[$k]) && is_array($items[$k]) && empty($items[$k]['ok'])) {
                $step = $v[0];
                $link = $v[1];
                $label = $v[2];
                break;
            }
        }
        return [
            'name' => $name, 'nextLabel' => $nextLabel, 'step' => $step,
            'link' => $link, 'label' => $label,
            'enrollUrl' => '/enroll' . ($cid > 0 ? '?child=' . $cid : ''),
        ];
    }

    /* List my applications */
    public function myApplications(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $hasEaPeriod = false;
        try {
            $hasEaPeriod = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'period_code'"
            );
        } catch (Throwable $e) {
        }
        // Clearance join is period-aware: basic-ed matches period_code,
        // SHS/College match the legacy semester (clearances carry the
        // effective period for every band).
        $clrOn = 'cl.student_id = ea.student_id AND cl.academic_year_id = ea.academic_year_id AND cl.semester <=> ea.semester';
        if ($hasEaPeriod) {
            $clrOn = 'cl.student_id = ea.student_id AND cl.academic_year_id = ea.academic_year_id AND (cl.semester <=> ea.semester OR (ea.period_code IS NOT NULL AND cl.semester <=> ea.period_code))';
        }
        try {
            $rows = Database::all(
                "SELECT ea.*, gl.name AS level_name, gl.code AS level_code, gl.category AS level_category, p.name AS program_name, ay.school_year,
                        c.name AS campus_name, c.address AS campus_address, pay.status AS pay_status, pay.payrex_payment_intent_id, pay.transaction_ref,
                        cl.status AS clr_status, cl.financial_status AS clr_financial, COALESCE(cl.registrar_status,'pending') AS clr_registrar,
                        COALESCE(ku.first_name, ks.first_name) AS child_first, COALESCE(ku.last_name, ks.last_name) AS child_last, ks.student_no AS child_no
                   FROM enrollment_applications ea
                     JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                     LEFT JOIN programs p ON p.id = ea.program_id
                     JOIN academic_years ay ON ay.id = ea.academic_year_id
                     LEFT JOIN campuses c ON c.id = ea.preferred_campus_id
                     LEFT JOIN payments pay ON pay.id = ea.payment_id
                     LEFT JOIN clearances cl ON {$clrOn}
                     LEFT JOIN students ks ON ks.id = ea.applicant_child_id
                     LEFT JOIN users ku ON ku.id = ks.user_id
                   WHERE ea.user_id = ?
                   ORDER BY ea.created_at DESC",
                [(int) Auth::id()]
            );
        } catch (Throwable $e) {
            // Column may predate the applicant_child_id patch.
            $rows = Database::all(
                "SELECT ea.*, gl.name AS level_name, gl.code AS level_code, gl.category AS level_category, p.name AS program_name, ay.school_year,
                        c.name AS campus_name, c.address AS campus_address, pay.status AS pay_status, pay.payrex_payment_intent_id, pay.transaction_ref,
                        cl.status AS clr_status, cl.financial_status AS clr_financial, COALESCE(cl.registrar_status,'pending') AS clr_registrar
                   FROM enrollment_applications ea
                     JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                     LEFT JOIN programs p ON p.id = ea.program_id
                     JOIN academic_years ay ON ay.id = ea.academic_year_id
                     LEFT JOIN campuses c ON c.id = ea.preferred_campus_id
                     LEFT JOIN payments pay ON pay.id = ea.payment_id
                     LEFT JOIN clearances cl ON {$clrOn}
                   WHERE ea.user_id = ?
                   ORDER BY ea.created_at DESC",
                [(int) Auth::id()]
            );
        }
        $studentNo = null;
        $childNumbers = [];
        $reusedDocs = [];
        try {
            if (Auth::is('parent') && class_exists(\App\Core\GuardianLinks::class)) {
                // Parents have no personal number — show each linked child's.
                foreach (\App\Core\GuardianLinks::linkedChildren((int) Auth::id()) as $lc) {
                    if (!empty($lc['student_no'])) {
                        $childNumbers[] = [
                            'name' => \App\Core\GuardianLinks::displayName($lc),
                            'no' => (string) $lc['student_no'],
                        ];
                    }
                }
            } else {
                $st = Database::one("SELECT id, student_no FROM students WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
                if ($st) {
                    $studentNo = $st['student_no'] ?? null;
                    if (class_exists(\App\Core\StudentDocs::class)) {
                        $reusedDocs = \App\Core\StudentDocs::latestByType((int) $st['id']);
                    }
                }
            }
        } catch (Throwable $e) {}
        // Payment-plan snapshot per application (fail-open, few rows).
        // Also syncs stale amount_due to the plan total while unpaid.
        try {
            if (class_exists(\App\Core\Tuition::class) && \App\Core\Tuition::hasTables()) {
                foreach ($rows as &$ar) {
                    $ar['plan_summary'] = \App\Core\Tuition::planSummary((int) $ar['id']);
                    if (($ar['status'] ?? '') === 'awaiting_payment') {
                        \App\Core\Tuition::syncAmountDue((int) $ar['id']);
                        if (abs((float) ($ar['amount_due'] ?? 0) - (float) ($ar['plan_summary']['total'] ?? 0)) > 0.005
                            && ($ar['plan_summary']['has_plan'] ?? false)) {
                            $ar['amount_due'] = $ar['plan_summary']['total'];
                        }
                    }
                }
                unset($ar);
            }
        } catch (Throwable $e) {}
        $this->view('enrollment/my_applications', [
            'title' => 'My Enrollments',
            'applications' => $rows,
            'permanentStudentNo' => $studentNo,
            'childNumbers' => $childNumbers,
            'reusedDocs' => $reusedDocs,
        ]);
    }

    /**
     * Whether the application may currently receive an online payment:
     * awaiting_payment (initial flow), or approved with a server-computed
     * remaining balance (installments / early settlement). All other
     * statuses, and fully-settled accounts, stay out.
     */
    private function payableStatus(array $app): bool
    {
        $st = (string) ($app['status'] ?? '');
        if ($st === 'awaiting_payment') return true;
        if ($st !== 'approved') return false;
        try {
            if (!class_exists(\App\Core\Tuition::class)) {
                return (float) ($app['amount_due'] ?? 0) > 0.009;
            }
            return \App\Core\Tuition::remainingFor((int) $app['id'], $app) > 0.009;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function checkout(array $params = []): void
    {
        Auth::requireRole('student','parent');
        $id = (int)($params['id'] ?? 0);
        $app = Database::one("SELECT ea.*, gl.name AS level_name, p.name AS program_name, pay.status AS pay_status, pay.payrex_payment_intent_id, pay.payrex_client_secret, pay.transaction_ref, pay.amount FROM enrollment_applications ea JOIN grade_levels gl ON gl.id=ea.grade_level_applied_id LEFT JOIN programs p ON p.id=ea.program_id LEFT JOIN payments pay ON pay.id=ea.payment_id WHERE ea.id=? AND ea.user_id=? LIMIT 1", [$id, (int)Auth::id()]);
        if (!$app || !$this->payableStatus($app)) { set_flash('error','Not awaiting payment.'); redirect('/my-enrollments'); }
        $isAwaiting = ($app['status'] ?? '') === 'awaiting_payment';

        // Payment plan (Full vs Monthly). Legacy installs without the plan
        // tables fall back to the single-amount flow. Approved accounts never
        // create or re-choose a plan here — they pay against the existing one.
        if ($isAwaiting) {
            $plan = class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::ensurePlan($id) : null;
            if ($plan !== null) {
                \App\Core\Tuition::syncAmountDue($id);
            }
        } else {
            $plan = class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::getPlan($id) : null;
            if ($plan === null || ($plan['plan_type'] ?? null) === null) {
                set_flash('error', 'No payment plan on file for this application.');
                redirect('/my-enrollments');
            }
        }
        $summary = $plan !== null ? \App\Core\Tuition::planSummary($id) : null;
        $activePlan = class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::activePlan() : null;
        $due = $plan !== null && class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::currentDue($id) : null;

        // Early settlement: monthly accounts past the downpayment may clear
        // the entire remaining balance anytime instead of waiting for each
        // due date. Fully server-computed here; the view only offers it as
        // a choice next to the scheduled due.
        $settle = null;
        try {
            if ($plan !== null && ($plan['plan_type'] ?? null) === 'monthly'
                && ($plan['downpayment_status'] ?? '') === 'paid'
                && class_exists(\App\Core\Tuition::class)
            ) {
                $remaining = \App\Core\Tuition::remainingFor($id, $app);
                if ($due !== null && ($due['kind'] ?? '') === 'installment'
                    && $remaining > (float) $due['amount'] + 0.009
                    && !\App\Core\Tuition::hasOpenPaymentForApp($id)
                ) {
                    $settle = ['amount' => $remaining, 'label' => 'Full Remaining Balance'];
                }
            }
        } catch (Throwable $e) {
            $settle = null;
        }

        if (($app['pay_status'] ?? 'pending') !== 'paid' && ($app['pay_status'] ?? '') !== 'processing'
            && $plan !== null && ($plan['plan_type'] ?? null) === null) {
            // Step 1: choose HOW to pay (no amounts move yet).
            $this->view('enrollment/checkout', [
                'title' => 'Choose Payment Plan', 'app' => $app, 'step' => 'plan',
                'plan' => $plan, 'summary' => $summary, 'activePlan' => $activePlan,
                'clientSecret' => null, 'intentId' => null, 'due' => null,
            ]);
            return;
        }

        // Step 2: charge exactly what is due NOW (server-computed).
        if ($plan !== null && $due === null && ($summary['remaining'] ?? 1) <= 0.009) {
            set_flash('success', 'Tuition already fully paid. Nothing due.');
            redirect('/my-enrollments');
        }
        // Plan chosen but nothing actionable (a payment is being verified):
        // bounce instead of showing the plan screen again.
        if ($due === null && $settle === null && $plan !== null && ($plan['plan_type'] ?? null) !== null) {
            set_flash('info', 'Payment already submitted — awaiting admin approval.');
            redirect('/my-enrollments');
        }
        $chargeAmount = $due !== null ? (float) $due['amount'] : (float) ($app['amount_due'] ?? ($activePlan['total_tuition'] ?? 10000));
        if ($chargeAmount <= 0) {
            set_flash('success', 'Nothing due right now.');
            redirect('/my-enrollments');
        }

        $clientSecret = $app['payrex_client_secret'] ?? null;
        $intentId = $app['payrex_payment_intent_id'] ?? null;

        if (!$intentId || !$clientSecret) {
            $amountCentavos = (int)round($chargeAmount * 100);
            try {
                $client = new \Lib\PayRexClient();
                $pi = $client->createPaymentIntent($amountCentavos, ['qrph'], 'Enrollment '.$app['application_no'], ['application_no'=>$app['application_no'],'enrollment_id'=>(string)$id]);
                $intentId = $pi['id'] ?? $pi['data']['id'] ?? null;
                $clientSecret = $pi['client_secret'] ?? $pi['clientSecret'] ?? $pi['client_secret'] ?? null;
                if (!$clientSecret) {
                    if ($intentId) {
                        $retrieved = $client->retrievePaymentIntent($intentId);
                        $clientSecret = $retrieved['client_secret'] ?? $retrieved['clientSecret'] ?? null;
                    }
                }
                if ($intentId) {
                    if ($app['pay_status'] !== null && !empty($app['payment_id'])) {
                        Database::run("UPDATE payments SET payrex_payment_intent_id=?, payrex_client_secret=? WHERE id=?", [$intentId, $clientSecret, (int)$app['payment_id']]);
                    }
                    $app['payrex_payment_intent_id'] = $intentId;
                    $app['payrex_client_secret'] = $clientSecret;
                }
            } catch (Throwable $e) { storage_log('checkout PayRex create failed '.$id.': '.$e->getMessage()); }
        }

        $this->view('enrollment/checkout', [
            'title' => 'Pay Enrollment Fee', 'app' => $app, 'step' => 'pay',
            'plan' => $plan, 'summary' => $summary, 'activePlan' => $activePlan,
            'clientSecret' => $clientSecret, 'intentId' => $intentId, 'due' => $due,
            'settle' => $settle,
        ]);
    }

    /** Save the Full/Monthly choice (before any money moves). */
    public function choosePlan(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student','parent');
        $id = (int)($params['id'] ?? 0);
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id=? AND user_id=? LIMIT 1", [$id, (int)Auth::id()]);
        if (!$app || $app['status'] !== 'awaiting_payment') { set_flash('error','Not awaiting payment.'); redirect('/my-enrollments'); }
        if (!class_exists(\App\Core\Tuition::class)) { set_flash('error','Payment plans are unavailable.'); redirect('/enroll/'.$id.'/pay'); }
        $type = trim((string) ($_POST['plan_type'] ?? ''));
        $result = \App\Core\Tuition::choosePlan($id, $type);
        if (empty($result['ok'])) {
            set_flash('error', $result['message'] ?? 'Please select a payment plan.');
            redirect('/enroll/'.$id.'/pay');
        }
        $label = $type === 'full' ? 'Full Tuition' : 'Monthly Payment';
        set_flash('success', $label . ' selected.' . ($type === 'monthly' ? ' The required downpayment comes first.' : ''));
        redirect('/enroll/'.$id.'/pay');
    }

    /** Back to plan choice (before any payment was submitted). */
    public function clearPlan(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student','parent');
        $id = (int)($params['id'] ?? 0);
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id=? AND user_id=? LIMIT 1", [$id, (int)Auth::id()]);
        if (!$app || $app['status'] !== 'awaiting_payment') { set_flash('error','Not awaiting payment.'); redirect('/my-enrollments'); }
        if (!class_exists(\App\Core\Tuition::class)) { set_flash('error','Payment plans are unavailable.'); redirect('/enroll/'.$id.'/pay'); }
        $result = \App\Core\Tuition::clearPlan($id);
        if (empty($result['ok'])) {
            set_flash('error', $result['message'] ?? 'Could not change the payment plan.');
        } else {
            set_flash('info', 'Payment plan cleared — choose again.');
        }
        redirect('/enroll/'.$id.'/pay');
    }

    public function pay(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student','parent');
        $id = (int)($params['id'] ?? 0);
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id=? AND user_id=? LIMIT 1", [$id, (int)Auth::id()]);
        if (!$app || !$this->payableStatus($app)) { set_flash('error','Not awaiting payment.'); redirect('/my-enrollments'); }
        if (class_exists(\App\Core\Tuition::class)) {
            \App\Core\Tuition::syncAmountDue($id);
        }
        // Checkout offers GCash / Maya / QR Ph only.
        $allowed = ['gcash','maya','qrph'];
        $method = strtolower(trim((string)($_POST['payment_method'] ?? 'gcash')));
        if (!in_array($method, $allowed, true)) { set_flash('error','Please select GCash, Maya, or QR Ph.'); redirect('/enroll/'.$id.'/pay'); }

        // THE amount due comes from the server, never the browser.
        // pay_scope=settle: clear the whole remaining balance at once
        // (monthly, past downpayment). Re-validated here — the browser
        // choice only selects which server-computed figure to charge.
        $due = class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::currentDue($id) : null;
        $settleAmount = null;
        if ((($_POST['pay_scope'] ?? 'due') === 'settle') && class_exists(\App\Core\Tuition::class)) {
            $planRow = \App\Core\Tuition::getPlan($id);
            $remaining = \App\Core\Tuition::remainingFor($id, $app);
            $dueAmt = $due !== null ? (float) $due['amount'] : 0.0;
            $ok = $planRow !== null && ($planRow['plan_type'] ?? null) === 'monthly'
                && ($planRow['downpayment_status'] ?? '') === 'paid'
                && $remaining > 0.009 && $remaining > $dueAmt + 0.009
                && !\App\Core\Tuition::hasOpenPaymentForApp($id);
            if ($ok) {
                $settleAmount = round($remaining, 2);
            } else {
                set_flash('error', 'Early settlement is not available right now.');
                redirect('/enroll/'.$id.'/pay');
            }
        }
        if ($settleAmount !== null) {
            $amount = $settleAmount;
            $kind = 'settlement';
            $installmentId = null;
            $dueLabel = 'Full Remaining Balance';
        } elseif ($due === null) {
            // Legacy (no plan tables) or fully paid.
            $paidAlready = false;
            try {
                $paidAlready = (float) \App\Core\Tuition::paidTotal($id) >= (float) ($app['amount_due'] ?? 0) - 0.009 && (float) ($app['amount_due'] ?? 0) > 0;
            } catch (Throwable $e) {
            }
            if ($paidAlready) { set_flash('success','Tuition already fully paid. Nothing due.'); redirect('/my-enrollments'); }
            if (!class_exists(\App\Core\Tuition::class) || \App\Core\Tuition::getPlan($id) === null) {
                set_flash('error','Please choose a payment plan first.');
                redirect('/enroll/'.$id.'/pay');
            }
            set_flash('info','Payment already submitted — awaiting admin approval.');
            redirect('/my-enrollments');
        }
        if ($settleAmount === null) {
            $amount = round((float) $due['amount'], 2);
            if ($amount <= 0) { set_flash('success','Nothing due right now.'); redirect('/my-enrollments'); }
            $kind = (string) $due['kind'];
            $installmentId = $due['installment_id'] ?? null;
        }
        $dueLabel = $settleAmount !== null ? 'Full Remaining Balance' : (string) ($due['label'] ?? 'Payment');

        $existingPay = $app['payment_id'] ? Database::one("SELECT status FROM payments WHERE id=? LIMIT 1", [(int)$app['payment_id']]) : null;
        if (in_array($kind, ['full','downpayment'], true) && $existingPay && in_array($existingPay['status'], ['processing','paid'], true)) {
            set_flash('info','Payment already submitted — awaiting admin approval.');
            redirect('/my-enrollments');
        }

        try {
            $intentId = null;
            $amountCentavos = (int)round($amount * 100);
            if (defined('PAYREX_SECRET_KEY') && PAYREX_SECRET_KEY !== '') {
                try {
                    $client = new \Lib\PayRexClient();
                    $pi = $client->createPaymentIntent($amountCentavos, [$method], 'Enrollment '.$app['application_no'].' — '.$dueLabel, ['application_no'=>$app['application_no'],'user_id'=>(string)Auth::id(),'charge_kind'=>$kind]);
                    $intentId = $pi['id'] ?? $pi['data']['id'] ?? null;
                } catch (Throwable $e) { storage_log('payrex intent pay() failed '.$id.': '.$e->getMessage()); }
            }

            $prefix = $kind === 'downpayment' ? 'DWN-' : ($kind === 'installment' ? 'INS-' : ($kind === 'settlement' ? 'STL-' : 'ENR-'));
            Database::transaction(function() use ($app, $id, $method, $intentId, $amount, $kind, $installmentId, $prefix) {
                if (in_array($kind, ['full','downpayment'], true) && $app['payment_id']) {
                    Database::run("UPDATE payments SET payment_method=?, amount=?, charge_kind=?, status='processing', payrex_payment_intent_id=COALESCE(?, payrex_payment_intent_id), initiated_by=? WHERE id=? AND status NOT IN ('processing','paid')", [$method, $amount, $kind, $intentId, (int)Auth::id(), (int)$app['payment_id']]);
                    $pid = (int) $app['payment_id'];
                } else {
                    $pid = Database::insert('payments', ['transaction_ref'=>$prefix.$app['application_no'].'-'.strtoupper(bin2hex(random_bytes(2))),'student_id'=>$app['student_id'],'enrollment_application_id'=>$id,'amount'=>$amount,'payment_method'=>$method,'channel'=>'online','status'=>'processing','charge_kind'=>$kind,'plan_installment_id'=>$installmentId,'payrex_payment_intent_id'=>$intentId,'initiated_by'=>(int)Auth::id(),'ip_address'=>client_ip()]);
                    if (in_array($kind, ['full','downpayment'], true) && empty($app['payment_id'])) {
                        Database::run("UPDATE enrollment_applications SET payment_id=? WHERE id=?", [$pid, $id]);
                    }
                }
                if ($kind === 'downpayment') {
                    Database::run("UPDATE enrollment_payment_plans SET downpayment_status='pending', downpayment_payment_id=? WHERE application_id=?", [$pid ?? (int)$app['payment_id'], $id]);
                } elseif ($kind === 'installment' && $installmentId !== null) {
                    Database::run("UPDATE plan_installments SET status='pending', payment_id=? WHERE id=? AND status != 'paid'", [$pid ?? null, (int)$installmentId]);
                    // Link the payment row to its installment (unique guard).
                    if (!empty($pid)) {
                        try { Database::run("UPDATE payments SET plan_installment_id=? WHERE id=?", [(int)$installmentId, $pid]); } catch (Throwable $e) {}
                    }
                }
                Database::insert('audit_logs', ['actor_user_id'=>(int)Auth::id(),'action'=>'enrollment.payment_submitted','entity_type'=>'enrollment_applications','entity_id'=>$id,'new_values_json'=>json_encode(['kind'=>$kind,'amount'=>$amount]),'ip_address'=>client_ip()]);
            });

            $methodLabel = match($method){'gcash'=>'GCash','maya'=>'Maya','qrph'=>'QR Ph', default=>strtoupper($method)};
            $kidName = class_exists(\App\Core\GuardianLinks::class) ? \App\Core\GuardianLinks::applicantChildName($app) : null;
            $kidPrefix = $kidName !== null ? $kidName . ': ' : '';
            Database::insert('notifications', ['user_id'=>(int)Auth::id(),'title'=>$dueLabel.' submitted — awaiting approval','message'=>$kidPrefix.'Your '.$methodLabel.' payment for '.$app['application_no'].' ('.money_plain($amount).') is now awaiting admin verification. You will receive a receipt once approved.','type'=>'payment','link_url'=>'/my-enrollments']);

            $admins = Database::all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' AND u.status='active' LIMIT 10");
            foreach ($admins as $adm) {
                try { Database::insert('notifications', ['user_id'=>(int)$adm['id'],'title'=>'Payment awaiting approval','message'=>'Application '.$app['application_no'].' ('.$dueLabel.' '.money_plain($amount).') paid via '.$methodLabel.' — please verify PayRex/bank and approve to send receipt.','type'=>'payment','link_url'=>'/admin/payments']); } catch (Throwable $e) {}
            }

            set_flash('success',$methodLabel.' '.$dueLabel.' submitted ('.money_plain($amount).')! Status: Awaiting admin approval. Admin will verify PayRex is paid before approving.');
        } catch (Throwable $e) { storage_log('enroll pay failed '.$id.': '.$e->getMessage()); set_flash('error','Payment failed: '.$e->getMessage()); }
        redirect('/my-enrollments');
    }

    public function paymentStatus(array $params=[]): void
    {
        Auth::requireRole('student','parent');
        $id=(int)($params['id']??0);
        $app=Database::one("SELECT status, payment_id FROM enrollment_applications WHERE id=? AND user_id=? LIMIT 1",[$id,(int)Auth::id()]);
        if(!$app) json_response(['error'=>'Not found'],404);
        $pay=null;
        if($app['payment_id']) $pay=Database::one("SELECT status, transaction_ref FROM payments WHERE id=? LIMIT 1",[(int)$app['payment_id']]);
        $out=['enrollment_status'=>$app['status'],'pay_status'=>$pay['status']??'pending','pay_ref'=>$pay['transaction_ref']??null];
        try {
            if (class_exists(\App\Core\Tuition::class)) {
                $due=\App\Core\Tuition::currentDue($id);
                $out['due']=$due;
                $out['plan']=\App\Core\Tuition::planSummary($id);
            }
        } catch (Throwable $e) {}
        json_response($out);
    }
}