<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use Throwable;

/**
 * Centralized official-document generation.
 * Every document renders ONLY from authoritative DB sources:
 *   grades     <- finalized grade_sheets (never drafts, never task scores)
 *   enrollment <- classroom_members + students + programs + grade_levels
 *   permits    <- existing exam_permits workflow (print stays in ExamPermitController)
 * Students may print ONLY their own released documents; registrar/admin preview any.
 */
final class DocumentController extends Controller
{
    /* ================= shared official-data helpers ================= */

    public static function schoolInfo(): array
    {
        $name = 'Datamex College of Saint Adeline';
        $addr = '8167 Datamex Compd. Dr. A. Santos Avenue Sucat Parañaque City · 70897516';
        try {
            $n = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name' LIMIT 1");
            $a = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = 'school_address' LIMIT 1");
            if ($n) $name = (string) $n;
            if ($a) $addr = (string) $a;
        } catch (Throwable $e) {
        }
        return ['name' => $name, 'address' => $addr];
    }

    public static function studentProfile(int $studentId): ?array
    {
        return Database::one(
            "SELECT s.*, u.first_name, u.last_name, u.middle_name, u.email,
                    gl.name AS level_name, p.code AS program_code, p.name AS program_name,
                    sec.name AS section_name
               FROM students s
               LEFT JOIN users u ON u.id = s.user_id
               LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
               LEFT JOIN programs p ON p.id = s.program_id
               LEFT JOIN sections sec ON sec.id = s.section_id
              WHERE s.id = ? LIMIT 1",
            [$studentId]
        );
    }

    /**
     * Official finalized grades: latest finalized period per subject.
     * INC rows (grade NULL + remarks 'INC', i.e. missing major work) are
     * included and flagged — they print as INCOMPLETE and are excluded
     * from GWA/units until completed and re-finalized.
     * @return array{rows:array,totalUnits:float,gwa:?float,failed:int,incomplete:int}
     */
    public static function officialGrades(int $studentId, ?int $ayId = null, ?string $semester = null): array
    {
        $where = "gs.status = 'finalized' AND r.student_id = ?";
        $bind = [$studentId];
        if ($ayId) {
            $where .= " AND gs.academic_year_id = ?";
            $bind[] = $ayId;
        }
        if ($semester) {
            $where .= " AND gs.semester = ?";
            $bind[] = $semester;
        }
        $hasScPeriodDoc = false;
        try {
            $hasScPeriodDoc = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'period_code'"
            );
        } catch (Throwable $e) {
        }
        $effSel = $hasScPeriodDoc ? 'COALESCE(sc.period_code, sc.semester) AS eff_period,' : 'sc.semester AS eff_period,';
        $all = Database::all(
            "SELECT r.grade, r.remarks AS row_remarks, sc.subject_code, sc.subject_title, sc.units, {$effSel} ay.school_year,
                    gp.sort_order AS period_order, gp.code AS period_code
               FROM grade_sheet_rows r
               JOIN grade_sheets gs ON gs.id = r.sheet_id
               JOIN subject_classrooms sc ON sc.id = gs.classroom_id
               JOIN academic_years ay ON ay.id = gs.academic_year_id
               JOIN grade_periods gp ON gp.id = gs.period_id
              WHERE $where AND (r.grade IS NOT NULL OR r.remarks = 'INC')
              ORDER BY ay.school_year DESC, sc.subject_code ASC, gp.sort_order DESC",
            $bind
        );
        // Latest finalized period wins per subject (+AY+effective period).
        // Adds a display label without reinterpreting stored history.
        $bySubject = [];
        foreach ($all as $g) {
            $key = $g['school_year'] . '|' . ($g['eff_period'] ?? '') . '|' . $g['subject_code'];
            if (!isset($bySubject[$key])) $bySubject[$key] = $g;
        }
        foreach ($bySubject as &$bg) {
            $ep = trim((string) ($bg['eff_period'] ?? ''));
            $bg['period_label'] = in_array($ep, ['1st', '2nd'], true) ? ($ep . ' Semester') : ($ep !== '' ? $ep : '—');
        }
        unset($bg);
        $rows = array_values($bySubject);
        $units = 0.0;
        $sum = 0.0;
        $graded = 0;
        $failed = 0;
        $incomplete = 0;
        $scales = Database::all("SELECT * FROM grade_scales ORDER BY sort_order ASC");
        foreach ($rows as &$r) {
            $r['inc'] = $r['grade'] === null && trim((string) ($r['row_remarks'] ?? '')) === 'INC';
            if (!empty($r['inc'])) {
                $incomplete++;
                $r['equivalent'] = 'Incomplete';
                $r['scale_remarks'] = 'INCOMPLETE';
                $r['passing'] = 0;
                continue;
            }
            $units += (float) $r['units'];
            $sum += (float) $r['grade'];
            $graded++;
            $band = self::bandFor((float) $r['grade'], $scales);
            $r['equivalent'] = $band['equivalent'] ?? '';
            $r['scale_remarks'] = $band['remarks'] ?? '';
            $r['passing'] = (int) ($band['is_passing'] ?? ((float) $r['grade'] <= 3.00 ? 1 : 0));
            if (!$r['passing']) $failed++;
        }
        unset($r);
        return ['rows' => $rows, 'totalUnits' => $units, 'gwa' => $graded > 0 ? round($sum / $graded, 2) : null, 'failed' => $failed, 'incomplete' => $incomplete];
    }

    public static function bandFor(float $grade, ?array $scales = null): ?array
    {
        $scales = $scales ?? Database::all("SELECT * FROM grade_scales ORDER BY sort_order ASC");
        foreach ($scales as $b) {
            if ($grade >= (float) $b['min_grade'] && $grade <= (float) $b['max_grade']) return $b;
        }
        return null;
    }

    public static function enrollments(int $studentId, ?int $ayId = null, ?string $semester = null): array
    {
        $where = "cm.student_id = ?";
        $bind = [$studentId];
        if ($ayId) {
            $where .= " AND sc.academic_year_id = ?";
            $bind[] = $ayId;
        }
        $hasScPeriodDoc = false;
        try {
            $hasScPeriodDoc = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'period_code'"
            );
        } catch (Throwable $e) {
        }
        if ($semester) {
            // Effective-period match (basic-ed period_code, else semester).
            $where .= $hasScPeriodDoc ? " AND COALESCE(sc.period_code, sc.semester) = ?" : " AND sc.semester = ?";
            $bind[] = $semester;
        }
        $effSel = $hasScPeriodDoc ? 'COALESCE(sc.period_code, sc.semester) AS eff_period,' : 'sc.semester AS eff_period,';
        $rows = Database::all(
            "SELECT sc.subject_code, sc.subject_title, sc.units, sc.section_name, sc.schedule AS schedule_note,
                    sc.room, sc.semester, {$effSel} ay.school_year,
                    TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS instructor
               FROM classroom_members cm
               JOIN subject_classrooms sc ON sc.id = cm.classroom_id
               JOIN academic_years ay ON ay.id = sc.academic_year_id
               LEFT JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
              WHERE $where ORDER BY sc.subject_code ASC LIMIT 100",
            $bind
        );
        foreach ($rows as &$er) {
            $ep = trim((string) ($er['eff_period'] ?? ''));
            $er['period_label'] = in_array($ep, ['1st', '2nd'], true) ? ($ep . ' Semester') : ($ep !== '' ? $ep : '—');
        }
        unset($er);
        return $rows;
    }

    /**
     * College Enrollment Form dataset — every value from the database.
     * Returns null when the application does not exist.
     */
    public static function enrollmentFormData(int $applicationId): ?array
    {
        $app = Database::one(
            "SELECT ea.*, gl.name AS level_name, gl.code AS level_code, gl.category AS level_category,
                    p.code AS program_code, p.name AS program_name,
                    ay.school_year, ay.id AS ay_id,
                    c.name AS campus_name, c.address AS campus_address,
                    u.first_name, u.middle_name, u.last_name, u.suffix, u.email, u.phone, u.avatar_path,
                    u.created_at AS account_created,
                    s.id AS sid, s.student_no, s.gender, s.date_of_birth, s.civil_status, s.nationality,
                    s.address_line1, s.address_city, s.address_province, s.address_zip,
                    s.grade_level_id, s.program_id, s.section_id, s.status AS student_status,
                    sec.name AS section_name
               FROM enrollment_applications ea
               JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
               LEFT JOIN programs p ON p.id = ea.program_id
               JOIN academic_years ay ON ay.id = ea.academic_year_id
               LEFT JOIN campuses c ON c.id = ea.preferred_campus_id
               LEFT JOIN users u ON u.id = ea.user_id
               LEFT JOIN students s ON s.id = ea.student_id
               LEFT JOIN sections sec ON sec.id = s.section_id
              WHERE ea.id = ? LIMIT 1",
            [$applicationId]
        );
        if ($app === null) return null;
        $sid = (int) ($app['sid'] ?? 0);
        $school = self::schoolInfo();
        try {
            $contact = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = 'school_contact' LIMIT 1");
            if ($contact) $school['contact'] = (string) $contact;
        } catch (Throwable $e) {}
        try {
            $rule = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = 'enrollment_form_scholarship_rules' LIMIT 1");
            $note = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = 'enrollment_form_notes' LIMIT 1");
            $misc = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = 'enrollment_form_misc_note' LIMIT 1");
            if ($rule) $school['scholarship_rules'] = (string) $rule;
            if ($note) $school['form_notes'] = (string) $note;
            if ($misc) $school['misc_note'] = (string) $misc;
        } catch (Throwable $e) {}
        // Guardian (primary first).
        $guardian = null;
        if ($sid > 0) {
            try {
                $guardian = Database::one(
                    "SELECT g.first_name, g.middle_name, g.last_name, g.contact_no, gs.relationship, gs.is_primary
                       FROM guardian_students gs JOIN guardians g ON g.id = gs.guardian_id
                      WHERE gs.student_id = ? ORDER BY gs.is_primary DESC LIMIT 1",
                    [$sid]
                );
            } catch (Throwable $e) {}
        }
        // Subjects for this AY/period (classroom joins = official source).
        // Band-correct period: basic-ed period_code, else legacy semester.
        $subjects = [];
        $totalUnits = 0.0;
        if ($sid > 0) {
            try {
                $appPeriod = trim((string) ($app['period_code'] ?? ''));
                if ($appPeriod === '') $appPeriod = trim((string) ($app['semester'] ?? ''));
                $subjects = self::enrollments($sid, (int) $app['ay_id'], $appPeriod !== '' ? $appPeriod : null);
                foreach ($subjects as $s) $totalUnits += (float) $s['units'];
            } catch (Throwable $e) {}
        }
        // Payment schedule + misc fees from the Accounting module (never invented).
        $charges = [];
        $payments = [];
        $balance = 0.0;
        if ($sid > 0) {
            try {
                $charges = Database::all(
                    "SELECT sc.*, ft.name AS fee_name FROM student_charges sc
                       LEFT JOIN fee_structures fs ON fs.id = sc.fee_structure_id
                       LEFT JOIN fee_types ft ON ft.id = fs.fee_type_id
                      WHERE sc.student_id = ? ORDER BY sc.charge_date ASC, sc.id ASC LIMIT 100",
                    [$sid]
                );
                $payments = Database::all(
                    "SELECT p.*, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id
                      WHERE p.student_id = ? OR p.enrollment_application_id = ?
                      ORDER BY p.created_at ASC LIMIT 50",
                    [$sid, $applicationId]
                );
                $balance = (float) (Database::value(
                    "SELECT COALESCE((SELECT SUM(amount) FROM student_charges WHERE student_id = ?),0)
                            - COALESCE((SELECT SUM(pa.amount_applied) FROM payment_allocations pa
                                         JOIN payments p ON p.id = pa.payment_id WHERE p.status = 'paid'
                                          AND pa.charge_id IN (SELECT id FROM student_charges WHERE student_id = ?)),0)",
                    [$sid, $sid]
                ) ?? 0);
            } catch (Throwable $e) {}
        }
        // Five-row schedule: prefer explicit charge descriptions matching
        // down/installment wording; otherwise list first charges in order.
        $schedule = self::paymentSchedule($charges, (float) ($app['amount_due'] ?? 0));
        // Student type: system-determined (new vs returning/old).
        $isReturning = (($app['new_or_returning'] ?? 'new') === 'returning')
            || (int) Database::value(
                "SELECT COUNT(*) FROM enrollment_applications WHERE (student_id = ? OR user_id = ?)
                   AND status = 'approved' AND id < ?",
                [$sid, (int) $app['user_id'], $applicationId]
            ) > 0;
        // Year-level checkbox: C1..C4 for college, else level name.
        $yearLevel = self::yearLevelBox($app);
        return [
            'app' => $app, 'school' => $school, 'guardian' => $guardian,
            'subjects' => $subjects, 'totalUnits' => $totalUnits,
            'charges' => $charges, 'payments' => $payments,
            'balance' => max(0, $balance), 'schedule' => $schedule,
            'isReturning' => $isReturning, 'yearLevel' => $yearLevel,
        ];
    }

    /** Map level/period to the form's period boxes (college checkboxes preserved). */
    private static function yearLevelBox(array $app): array
    {
        $code = strtoupper((string) ($app['level_code'] ?? ''));
        $map = ['C1' => '1st Year', 'C2' => '2nd Year', 'C3' => '3rd Year', 'C4' => '4th Year'];
        $year = $map[$code] ?? '';
        if ($year === '') {
            $name = strtolower((string) ($app['level_name'] ?? ''));
            foreach (['1st year' => '1st Year', '2nd year' => '2nd Year', '3rd year' => '3rd Year', '4th year' => '4th Year',
                      'first year' => '1st Year', 'second year' => '2nd Year', 'third year' => '3rd Year', 'fourth year' => '4th Year'] as $k => $v) {
                if (str_contains($name, $k)) { $year = $v; break; }
            }
        }
        // Band-correct period label for the printed form (quarters/terms for
        // basic-ed; semesters preserved for SHS/College).
        $per = trim((string) ($app['period_code'] ?? ''));
        if ($per === '') $per = trim((string) ($app['semester'] ?? ''));
        $cat = (string) ($app['level_category'] ?? '');
        if (in_array($cat, ['kindergarten', 'elementary', 'junior_high'], true)) {
            $known = ['Q1' => 'Quarter 1', 'Q2' => 'Quarter 2', 'Q3' => 'Quarter 3', 'Q4' => 'Quarter 4', 'T1' => 'Term 1', 'T2' => 'Term 2', 'T3' => 'Term 3'];
            $perLabel = $known[$per] ?? ($per !== '' ? $per : '—');
        } else {
            $perLabel = in_array($per, ['1st', '2nd'], true) ? ($per . ' Semester') : ($per !== '' ? $per : '—');
        }
        return ['year' => $year, 'semester' => (string) ($app['semester'] ?? ''), 'period' => $per, 'period_label' => $perLabel];
    }

    /**
     * Build the 5-row payment schedule strictly from Accounting records.
     * Each row: ['label','due','amount','source']. Amounts are never invented:
     * unmatched rows show null (rendered as TBD / per-assessment).
     */
    private static function paymentSchedule(array $charges, float $amountDue): array
    {
        $labels = ['Down Payment', 'First Installment', 'Second Installment', 'Third Installment', 'Final/Last Installment'];
        $rows = [];
        foreach ($labels as $i => $label) $rows[] = ['label' => $label, 'due' => null, 'amount' => null, 'source' => null];
        if ($charges === []) {
            // No itemized charges yet — surface the enrollment amount due as the
            // total reference only (first row), never split/invent the rest.
            if ($amountDue > 0) {
                $rows[0]['amount'] = $amountDue;
                $rows[0]['source'] = 'Enrollment assessment (total — see Accounting for breakdown)';
            }
            return $rows;
        }
        // Match charges whose description/fee name mentions the row intent.
        $needles = [
            ['down'],
            ['first', '1st'],
            ['second', '2nd'],
            ['third', '3rd'],
            ['final', 'last', 'fourth', '4th', 'fifth', '5th'],
        ];
        $used = [];
        foreach ($labels as $i => $label) {
            foreach ($charges as $ci => $c) {
                if (isset($used[$ci])) continue;
                $hay = strtolower((string) ($c['description'] ?? '') . ' ' . (string) ($c['fee_name'] ?? ''));
                foreach ($needles[$i] as $n) {
                    if (str_contains($hay, $n)) {
                        $rows[$i]['amount'] = (float) $c['amount'];
                        $rows[$i]['due'] = $c['charge_date'] ?? null;
                        $rows[$i]['source'] = trim((string) ($c['description'] ?? '') !== '' ? (string) $c['description'] : (string) ($c['fee_name'] ?? ''));
                        $used[$ci] = true;
                        break 2;
                    }
                }
            }
        }
        // Fill remaining rows in charge order (no invention — real charges only).
        $ci = 0;
        foreach ($rows as $i => $r) {
            if ($r['amount'] !== null) continue;
            while (isset($charges[$ci]) && isset($used[$ci])) $ci++;
            if (!isset($charges[$ci])) break;
            $c = $charges[$ci];
            $rows[$i]['amount'] = (float) $c['amount'];
            $rows[$i]['due'] = $c['charge_date'] ?? null;
            $rows[$i]['source'] = trim((string) ($c['description'] ?? '') !== '' ? (string) $c['description'] : (string) ($c['fee_name'] ?? ''));
            $used[$ci] = true;
            $ci++;
        }
        return $rows;
    }

    /* ================= print endpoint (one per document row) ================= */

    public function print(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $doc = Database::one("SELECT * FROM academic_documents WHERE id = ? LIMIT 1", [$id]);
        if ($doc === null) {
            http_response_code(404);
            exit('Document not found');
        }
        $role = Auth::role();
        // Students: own released documents only. Parents: a linked child's
        // released documents only (ownership-checked). Registrar/admin: preview anything.
        if ($role === 'student') {
            Auth::requireRole('student');
            $me = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
            if ($me === null || (int) $me['id'] !== (int) $doc['student_id'] || !in_array($doc['status'], ['released'], true)) {
                http_response_code(403);
                exit('Not available — released documents only.');
            }
        } elseif ($role === 'parent') {
            Auth::requireRole('parent');
            $ok = false;
            if (class_exists(\App\Core\GuardianLinks::class)) {
                $g = Database::one("SELECT id FROM guardians WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
                if ($g) {
                    $ok = (bool) Database::one(
                        "SELECT 1 FROM guardian_students WHERE guardian_id = ? AND student_id = ? LIMIT 1",
                        [(int) $g['id'], (int) $doc['student_id']]
                    );
                }
            }
            if (!$ok || !in_array($doc['status'], ['released'], true)) {
                http_response_code(403);
                exit('Not available — released documents only.');
            }
        } else {
            Auth::requireRole('registrar', 'admin');
        }
        $student = self::studentProfile((int) $doc['student_id']);
        if ($student === null) {
            http_response_code(404);
            exit('Student not found');
        }
        $school = self::schoolInfo();
        $ayId = $doc['academic_year_id'] ? (int) $doc['academic_year_id'] : null;
        // Band-correct effective period for the document (quarters/terms for
        // basic-ed, semesters for SHS/College); history never reinterpreted.
        $sem = trim((string) ($doc['period_code'] ?? ''));
        if ($sem === '') $sem = trim((string) ($doc['semester'] ?? ''));
        if ($sem === '') $sem = null;
        $semLabel = '—';
        if ($sem !== null) {
            $semLabel = in_array($sem, ['1st', '2nd'], true) ? ($sem . ' Semester') : $sem;
        }
        $base = [
            'title' => 'Document ' . $doc['doc_no'], 'doc' => $doc, 'student' => $student,
            'school' => $school, 'registrar' => Auth::user(),
            'docPeriod' => $sem, 'docPeriodLabel' => $semLabel,
        ];
        switch ($doc['doc_type']) {
            case 'grade_slip':
                $data = self::officialGrades((int) $doc['student_id'], $ayId, $sem);
                if ($data['rows'] === []) {
                    set_flash('warning', 'No finalized grades yet for this term — document would be empty.');
                    redirect($role === 'student' ? '/student/documents/' . $id : '/registrar/documents/' . $id);
                }
                $this->view('documents/print_grade_slip', $base + [
                    'title' => 'Grade Slip', 'grades' => $data['rows'],
                    'totalUnits' => $data['totalUnits'], 'gwa' => $data['gwa'], 'failed' => $data['failed'],
                ], null);
                return;
            case 'registration_form':
                $regs = self::enrollments((int) $doc['student_id'], $ayId);
                $units = 0.0;
                foreach ($regs as $r) $units += (float) $r['units'];
                $this->view('documents/print_reg_form', $base + [
                    'title' => 'Registration Form', 'regs' => $regs, 'totalUnits' => $units,
                ], null);
                return;
            case 'grade_equivalency':
                $data = self::officialGrades((int) $doc['student_id'], $ayId, $sem);
                if ($data['rows'] === []) {
                    set_flash('warning', 'No finalized grades yet — document would be empty.');
                    redirect($role === 'student' ? '/student/documents/' . $id : '/registrar/documents/' . $id);
                }
                $this->view('documents/print_equivalency', $base + [
                    'title' => 'Grade Equivalency', 'grades' => $data['rows'],
                    'scales' => Database::all("SELECT * FROM grade_scales ORDER BY sort_order ASC"),
                ], null);
                return;
            case 'transcript':
                $data = self::officialGrades((int) $doc['student_id'], null, null);
                $this->view('documents/print_transcript', $base + [
                    'title' => 'Transcript of Records', 'grades' => $data['rows'],
                    'gwa' => $data['gwa'],
                ], null);
                return;
            default: // enrollment_cert, grades_cert, history, other
                $data = self::officialGrades((int) $doc['student_id'], $ayId, $sem);
                $regs = self::enrollments((int) $doc['student_id'], $ayId);
                $this->view('documents/print_cert', $base + [
                    'title' => ucwords(str_replace('_', ' ', $doc['doc_type'])),
                    'grades' => $data['rows'], 'gwa' => $data['gwa'], 'regs' => $regs,
                ], null);
                return;
        }
    }

    /**
     * Official College Enrollment Form for one enrollment_applications row.
     * One form per academic period — previous forms stay in history.
     * Students: own APPROVED enrollments only (never premature drafts).
     * Registrar/Accounting/Admin: any enrollment (review + signatures).
     */
    public function printEnrollmentForm(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $data = self::enrollmentFormData($id);
        if ($data === null) {
            http_response_code(404);
            exit('Enrollment not found');
        }
        $role = Auth::role();
        $app = $data['app'];
        if ($role === 'student' || $role === 'parent') {
            Auth::requireRole('student', 'parent');
            if ((int) ($app['user_id'] ?? 0) !== (int) Auth::id()) {
                http_response_code(403);
                exit('Not your enrollment record.');
            }
            if (($app['status'] ?? '') !== 'approved') {
                set_flash('warning', 'The official Enrollment Form is available after Accounting & Registrar approval. Current status: ' . str_replace('_', ' ', (string) ($app['status'] ?? '')) . '.');
                redirect('/my-enrollments');
            }
        } else {
            Auth::requireRole('registrar', 'accounting', 'admin');
        }
        $this->view('documents/print_enrollment_form', $data + [
            'title' => 'Enrollment Form — ' . ($app['application_no'] ?? ('#' . $id)),
        ], null);
    }
}
