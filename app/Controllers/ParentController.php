<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use Throwable;

/**
 * Parent portal: child details + enrollment history.
 * Every child id is ownership-checked against guardian_students server-side
 * (IDOR-safe); forged ids bounce with a clear message, never swap silently.
 */
final class ParentController extends Controller
{
    /** Owned child or bounce (shared by all actions below). */
    private function childOrFail(int $childId): array
    {
        Auth::requireRole('parent');
        if (!class_exists(\App\Core\GuardianLinks::class)) {
            set_flash('error', 'Unable to load this child\u2019s information.');
            redirect('/dashboard');
        }
        try {
            $child = \App\Core\GuardianLinks::ownedChild((int) Auth::id(), $childId);
        } catch (Throwable $e) {
            $child = null;
        }
        if ($child === null) {
            set_flash('error', 'That child is not linked to your account.');
            redirect('/dashboard');
        }
        return $child;
    }

    /** Child details: profile, enrollment, finance, documents, clearance, permits. */
    public function show(array $params = []): void
    {
        $child = $this->childOrFail((int) ($params['id'] ?? 0));
        $sid = (int) $child['id'];

        $profile = null;
        try {
            $profile = Database::one(
                "SELECT s.*, gl.name AS level_name, gl.code AS level_code, sec.name AS section_name,
                        p.code AS program_code, p.name AS program_name, ay.school_year AS ay_label,
                        c.name AS campus_name
                   FROM students s
                   LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
                   LEFT JOIN sections sec ON sec.id = s.section_id
                   LEFT JOIN programs p ON p.id = s.program_id
                   LEFT JOIN enrollment_applications ea ON ea.id = (
                       SELECT ea2.id FROM enrollment_applications ea2
                        WHERE (ea2.student_id = s.id OR ea2.applicant_child_id = s.id)
                          AND ea2.status = 'approved' ORDER BY ea2.created_at DESC LIMIT 1)
                   LEFT JOIN academic_years ay ON ay.id = ea.academic_year_id
                   LEFT JOIN campuses c ON c.id = ea.preferred_campus_id
                  WHERE s.id = ? LIMIT 1",
                [$sid]
            );
        } catch (Throwable $e) {
        }
        if ($profile === null) {
            try {
                $profile = Database::one(
                    "SELECT s.*, gl.name AS level_name, sec.name AS section_name FROM students s
                      LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
                      LEFT JOIN sections sec ON sec.id = s.section_id
                     WHERE s.id = ? LIMIT 1",
                    [$sid]
                );
            } catch (Throwable $e) {
            }
        }

        $current = null;
        try {
            if (class_exists(\App\Core\EnrollmentCycle::class)) {
                $current = \App\Core\EnrollmentCycle::currentPeriod($sid);
            }
        } catch (Throwable $e) {
        }

        $applications = [];
        try {
            $applications = Database::all(
                "SELECT ea.*, gl.name AS level_name, ay.school_year FROM enrollment_applications ea
                  LEFT JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                  LEFT JOIN academic_years ay ON ay.id = ea.academic_year_id
                 WHERE ea.student_id = ? OR ea.applicant_child_id = ?
                 ORDER BY ea.created_at DESC LIMIT 20",
                [$sid, $sid]
            );
        } catch (Throwable $e) {
            try {
                $applications = Database::all(
                    "SELECT ea.*, gl.name AS level_name, ay.school_year FROM enrollment_applications ea
                      LEFT JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                      LEFT JOIN academic_years ay ON ay.id = ea.academic_year_id
                     WHERE ea.student_id = ? ORDER BY ea.created_at DESC LIMIT 20",
                    [$sid]
                );
            } catch (Throwable $e2) {
            }
        }

        $payments = [];
        try {
            $payments = Database::all(
                "SELECT p.transaction_ref, p.amount, p.status, p.payment_method, p.paid_at, p.created_at,
                        r.receipt_no, ea.application_no
                   FROM payments p
                   LEFT JOIN receipts r ON r.payment_id = p.id
                   LEFT JOIN enrollment_applications ea ON ea.id = p.enrollment_application_id
                  WHERE p.student_id = ?
                  ORDER BY p.created_at DESC LIMIT 20",
                [$sid]
            );
        } catch (Throwable $e) {
        }

        $balance = 0.0;
        try {
            $bal = Database::value(
                "SELECT COALESCE((SELECT SUM(amount) FROM student_charges WHERE student_id = ?),0)
                        - COALESCE((SELECT SUM(pa.amount_applied) FROM payment_allocations pa
                                     JOIN payments p ON p.id = pa.payment_id WHERE p.status = 'paid'
                                       AND pa.charge_id IN (SELECT id FROM student_charges WHERE student_id = ?)),0)",
                [$sid, $sid]
            );
            $balance = (float) max(0, (float) $bal);
        } catch (Throwable $e) {
        }

        $documents = [];
        try {
            if (class_exists(\App\Core\StudentDocs::class)) {
                $documents = \App\Core\StudentDocs::unified($sid, 0);
            }
        } catch (Throwable $e) {
        }

        $clearance = null;
        try {
            $clearance = Database::one(
                "SELECT * FROM clearances WHERE student_id = ? ORDER BY created_at DESC LIMIT 1",
                [$sid]
            );
        } catch (Throwable $e) {
        }

        $permits = [];
        try {
            $permits = Database::all(
                "SELECT exam_period, status, permit_no, requested_at FROM exam_permits WHERE student_id = ? ORDER BY requested_at DESC LIMIT 12",
                [$sid]
            );
        } catch (Throwable $e) {
        }

        $grades = [];
        try {
            $grades = Database::all(
                "SELECT g.final_grade, g.is_released, sc.subject_code, sc.subject_title FROM grades g
                  LEFT JOIN subject_classrooms sc ON sc.id = g.classroom_id
                 WHERE g.student_id = ? AND g.is_released = 1 ORDER BY g.id DESC LIMIT 30",
                [$sid]
            );
        } catch (Throwable $e) {
        }

        $subjects = [];
        try {
            $subjects = Database::all(
                "SELECT sc.subject_code, sc.subject_title, sc.section_name FROM classroom_members cm
                  JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                 WHERE cm.student_id = ? ORDER BY sc.subject_code LIMIT 30",
                [$sid]
            );
        } catch (Throwable $e) {
        }

        $this->view('parent/child', [
            'title' => 'Child details',
            'child' => $child,
            'profile' => $profile ?? $child,
            'current' => $current,
            'applications' => $applications,
            'payments' => $payments,
            'balance' => $balance,
            'documents' => $documents,
            'clearance' => $clearance,
            'permits' => $permits,
            'grades' => $grades,
            'subjects' => $subjects,
        ]);
    }

    /** Filtered enrollment history across all linked children. */
    public function applications(array $params = []): void
    {
        Auth::requireRole('parent');
        $uid = (int) Auth::id();
        $children = [];
        try {
            if (class_exists(\App\Core\GuardianLinks::class)) {
                $children = \App\Core\GuardianLinks::linkedChildren($uid);
            }
        } catch (Throwable $e) {
        }
        $fChild = (int) ($_GET['child'] ?? 0);
        $fAy = (int) ($_GET['ay'] ?? 0);
        $fLevel = (int) ($_GET['level'] ?? 0);
        $fStatus = trim((string) ($_GET['status'] ?? ''));
        if ($fChild > 0) {
            $ok = false;
            foreach ($children as $c) {
                if ((int) ($c['id'] ?? 0) === $fChild) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                set_flash('error', 'That child is not linked to your account.');
                redirect('/parent/applications');
            }
        }
        $allowedStatus = ['pending', 'accounting_approved', 'awaiting_payment', 'action_required', 'approved', 'rejected'];
        if ($fStatus !== '' && !in_array($fStatus, $allowedStatus, true)) $fStatus = '';

        $where = ['ea.user_id = ?'];
        $bind = [$uid];
        if ($fChild > 0) {
            $where[] = '(ea.student_id = ? OR ea.applicant_child_id = ?)';
            $bind[] = $fChild;
            $bind[] = $fChild;
        }
        if ($fAy > 0) {
            $where[] = 'ea.academic_year_id = ?';
            $bind[] = $fAy;
        }
        if ($fLevel > 0) {
            $where[] = 'ea.grade_level_applied_id = ?';
            $bind[] = $fLevel;
        }
        if ($fStatus !== '') {
            $where[] = 'ea.status = ?';
            $bind[] = $fStatus;
        }
        $rows = [];
        try {
            $rows = Database::all(
                "SELECT ea.*, gl.name AS level_name, ay.school_year,
                        COALESCE(ku.first_name, ks.first_name) AS child_first,
                        COALESCE(ku.last_name, ks.last_name) AS child_last
                   FROM enrollment_applications ea
                   LEFT JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                   LEFT JOIN academic_years ay ON ay.id = ea.academic_year_id
                   LEFT JOIN students ks ON (ks.id = ea.student_id OR ks.id = ea.applicant_child_id)
                   LEFT JOIN users ku ON ku.id = ks.user_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY ea.created_at DESC LIMIT 100",
                $bind
            );
        } catch (Throwable $e) {
            try {
                // Pre-migration fallback (no applicant_child_id).
                $where2 = ['ea.user_id = ?'];
                $bind2 = [$uid];
                if ($fAy > 0) {
                    $where2[] = 'ea.academic_year_id = ?';
                    $bind2[] = $fAy;
                }
                if ($fLevel > 0) {
                    $where2[] = 'ea.grade_level_applied_id = ?';
                    $bind2[] = $fLevel;
                }
                if ($fStatus !== '') {
                    $where2[] = 'ea.status = ?';
                    $bind2[] = $fStatus;
                }
                if ($fChild > 0) {
                    $where2[] = 'ea.student_id = ?';
                    $bind2[] = $fChild;
                }
                $rows = Database::all(
                    "SELECT ea.*, gl.name AS level_name, ay.school_year FROM enrollment_applications ea
                      LEFT JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                      LEFT JOIN academic_years ay ON ay.id = ea.academic_year_id
                     WHERE " . implode(' AND ', $where2) . "
                     ORDER BY ea.created_at DESC LIMIT 100",
                    $bind2
                );
            } catch (Throwable $e2) {
            }
        }
        $years = [];
        $levels = [];
        try {
            $years = Database::all("SELECT id, school_year FROM academic_years ORDER BY id DESC LIMIT 10");
            $levels = Database::all("SELECT id, name FROM grade_levels ORDER BY sort_order LIMIT 30");
        } catch (Throwable $e) {
        }
        $this->view('parent/applications', [
            'title' => 'Enrollment history',
            'rows' => $rows,
            'children' => $children,
            'years' => $years,
            'levels' => $levels,
            'fChild' => $fChild,
            'fAy' => $fAy,
            'fLevel' => $fLevel,
            'fStatus' => $fStatus,
        ]);
    }
}
