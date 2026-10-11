<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Student Academic Documents: request, track, view reason, request again,
 * access released documents. Students can NEVER edit official data —
 * documents render from finalized records at print time (DocumentController).
 */
final class StudentDocumentsController extends Controller
{
    public const TYPES = [
        'grade_slip' => 'Grade Slip',
        'registration_form' => 'Registration Form',
        'grade_equivalency' => 'Grade Equivalency',
        'transcript' => 'Transcript of Records',
        'enrollment_cert' => 'Certificate of Enrollment',
        'grades_cert' => 'Certificate of Grades',
    ];

    /**
     * Acting student: self for students; owned linked child (?child_id=
     * ownership-checked, else first child) for parents.
     * @return array{row:array|null,via_parent:bool,child_id:int,child_name:string,tampered:bool}
     */
    private function actingStudent(): array
    {
        $out = ['row' => null, 'via_parent' => false, 'child_id' => 0, 'child_name' => '', 'tampered' => false];
        if (Auth::is('parent')) {
            if (!class_exists(\App\Core\GuardianLinks::class)) return $out;
            $kid = (int) ($_REQUEST['child_id'] ?? 0);
            if ($kid > 0 && !\App\Core\GuardianLinks::ownedChild((int) Auth::id(), $kid)) {
                $out['tampered'] = true;
                return $out;
            }
            $row = \App\Core\GuardianLinks::ownedChild((int) Auth::id(), $kid);
            if ($row) {
                $out['row'] = $row;
                $out['via_parent'] = true;
                $out['child_id'] = (int) $row['id'];
                $out['child_name'] = \App\Core\GuardianLinks::displayName($row);
            }
            return $out;
        }
        $row = Database::one('SELECT * FROM students WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
        if ($row) $out['row'] = $row;
        return $out;
    }

    private function myStudent(): ?array
    {
        $a = $this->actingStudent();
        return $a['row'];
    }

    /**
     * Schema-drift guard: live Hostinger installs were provisioned from an
     * older schema where academic_documents lacked academic_year_id / semester /
     * period_code (or the table itself). Detect per-column so SELECT/INSERT
     * statements degrade gracefully instead of throwing "Unknown column" and
     * surfacing the generic "We'll be right back" screen.
     */
    private static array $docColCache = [];

    private static function hasDocColumn(string $col): bool
    {
        if (array_key_exists($col, self::$docColCache)) return self::$docColCache[$col];
        try {
            $v = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_documents' AND COLUMN_NAME = ?",
                [$col]
            );
            self::$docColCache[$col] = ((int) $v) > 0;
        } catch (Throwable $e) {
            // Fail open: assume the column exists so local/dev behaviour is unchanged.
            self::$docColCache[$col] = true;
        }
        return self::$docColCache[$col];
    }

    private static function hasDocTable(): bool
    {
        try {
            $v = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_documents'"
            );
            return ((int) $v) > 0;
        } catch (Throwable $e) {
            return true;
        }
    }

    public function index(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $s = $acting['row'];
        if ($s === null) {
            set_flash('error', Auth::is('parent') ? 'No linked child found. The registrar links your guardian profile after enrollment approval.' : 'Student record not found.');
            redirect('/dashboard');
        }
        $status = trim((string) ($_GET['status'] ?? 'all'));
        if (!in_array($status, ['pending', 'approved', 'ready', 'released', 'rejected', 'cancelled', 'all'], true)) $status = 'all';
        $where = $status === 'all' ? '' : 'AND ad.status = ?';
        $args = [(int) $s['id']];
        if ($status !== 'all') $args[] = $status;
        $hasAyCol = self::hasDocColumn('academic_year_id');
        if ($hasAyCol) {
            $rows = Database::all(
                "SELECT ad.*, ay.school_year FROM academic_documents ad
                  LEFT JOIN academic_years ay ON ay.id = ad.academic_year_id
                 WHERE ad.student_id = ? $where ORDER BY ad.created_at DESC LIMIT 100",
                $args
            );
        } else {
            // Older live schema without academic_year_id: skip the join entirely.
            $rows = Database::all(
                "SELECT ad.*, NULL AS school_year FROM academic_documents ad
                 WHERE ad.student_id = ? $where ORDER BY ad.created_at DESC LIMIT 100",
                $args
            );
        }
        $counts = ['all' => 0, 'pending' => 0, 'released' => 0, 'rejected' => 0];
        try {
            foreach (Database::all("SELECT status, COUNT(*) AS c FROM academic_documents WHERE student_id = ? GROUP BY status", [(int) $s['id']]) as $c) {
                $counts['all'] += (int) $c['c'];
                if (isset($counts[$c['status']])) $counts[$c['status']] = (int) $c['c'];
            }
        } catch (Throwable $e) {
            storage_log('student documents counts failed s=' . (int) $s['id'] . ': ' . $e->getMessage());
        }
        try {
            $ays = Database::all("SELECT id, school_year FROM academic_years ORDER BY school_year DESC LIMIT 10");
        } catch (Throwable $e) {
            storage_log('student documents academic-years failed: ' . $e->getMessage());
            $ays = [];
        }
        $termOptions = ['1st', '2nd', 'Summer'];
        $termNoun = 'Semester';
        try {
            if (class_exists(\App\Core\AcademicPeriod::class)) {
                $sBand = \App\Core\AcademicPeriod::bandForLevel((int) ($s['grade_level_id'] ?? 0));
                $termOptions = \App\Core\AcademicPeriod::periodCodes($sBand);
                $termNoun = \App\Core\AcademicPeriod::noun($sBand);
            }
        } catch (Throwable $e) {
        }
        $linkedChildren = [];
        if (Auth::is('parent') && class_exists(\App\Core\GuardianLinks::class)) {
            $linkedChildren = \App\Core\GuardianLinks::linkedChildren((int) Auth::id());
        }
        $this->view('student/documents_index', [
            'title' => 'Academic Documents', 'rows' => $rows, 'types' => self::TYPES,
            'academicYears' => $ays, 'student' => $s, 'status' => $status, 'counts' => $counts,
            'termOptions' => $termOptions, 'termNoun' => $termNoun,
            'isParent' => Auth::is('parent'), 'linkedChildren' => $linkedChildren,
            'actingChild' => $acting['via_parent'] ? ['id' => $acting['child_id'], 'name' => $acting['child_name']] : null,
        ]);
    }

    public function store(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $s = $acting['row'];
        if ($s === null) {
            set_flash('error', 'Student record not found.');
            redirect('/student/documents');
        }
        $type = trim((string) ($_POST['doc_type'] ?? ''));
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        $ayId = (int) ($_POST['academic_year_id'] ?? 0);
        $postedPeriod = trim((string) ($_POST['semester'] ?? ''));
        if (!array_key_exists($type, self::TYPES)) {
            set_flash('error', 'Select a valid document type.');
            redirect('/student/documents');
        }
        // Band-aware term: basic-ed quarters/terms go to period_code,
        // SHS/College semesters stay in the legacy column (validated config-side).
        $docBand = \App\Core\AcademicPeriod::bandForLevel((int) ($s['grade_level_id'] ?? 0));
        $semester = '';
        $docPeriod = null;
        $hasDocPeriod = false;
        try {
            $hasDocPeriod = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_documents' AND COLUMN_NAME = 'period_code'"
            );
        } catch (Throwable $e) {
        }
        if ($docBand === \App\Core\AcademicPeriod::BAND_BASIC && $hasDocPeriod) {
            if ($postedPeriod === '' || !\App\Core\AcademicPeriod::isValid($docBand, $postedPeriod)) {
                set_flash('error', 'Select a valid ' . \App\Core\AcademicPeriod::noun($docBand) . ' for this document.');
                redirect('/student/documents');
            }
            $docPeriod = $postedPeriod;
        } else {
            if (!in_array($postedPeriod, array_merge([''], \App\Controllers\RegistrarController::periodFilterOptions()), true)) $postedPeriod = '';
            $semester = $postedPeriod;
        }
        // Grade-bearing documents require finalized grades first (never drafts)
        if (in_array($type, ['grade_slip', 'grade_equivalency', 'grades_cert', 'transcript'], true)) {
            $official = \App\Controllers\DocumentController::officialGrades(
                (int) $s['id'], $ayId > 0 ? $ayId : null, ($semester !== '' ? $semester : ($docPeriod ?? null))
            );
            if ($official['rows'] === []) {
                set_flash('warning', 'No finalized grades yet for that term — request once your teachers submit and the Registrar finalizes. Registration-type documents remain available.');
                redirect('/student/documents');
            }
        }
        // Prevent duplicate ACTIVE requests for the same type+term.
        // Tolerant of older live schemas missing academic_year_id/semester.
        $hasAyCol = self::hasDocColumn('academic_year_id');
        $hasSemCol = self::hasDocColumn('semester');
        $dup = null;
        try {
            if ($hasAyCol && $hasSemCol) {
                $dup = Database::one(
                    "SELECT id FROM academic_documents WHERE student_id = ? AND doc_type = ?
                       AND COALESCE(academic_year_id,0) = COALESCE(NULLIF(?,0), academic_year_id, 0)
                       AND COALESCE(semester,'') = ? AND status IN ('pending','approved','ready') LIMIT 1",
                    [(int) $s['id'], $type, $ayId, $semester]
                );
            } else {
                $dup = Database::one(
                    "SELECT id FROM academic_documents WHERE student_id = ? AND doc_type = ?
                       AND status IN ('pending','approved','ready') LIMIT 1",
                    [(int) $s['id'], $type]
                );
            }
        } catch (Throwable $e) {
            storage_log('student document dup-check failed: ' . $e->getMessage());
            $dup = null;
        }
        if ($dup === null && $docPeriod !== null && $hasDocPeriod) {
            try {
                $dup = Database::one(
                    "SELECT id FROM academic_documents WHERE student_id = ? AND doc_type = ?
                       AND COALESCE(academic_year_id,0) = COALESCE(NULLIF(?,0), academic_year_id, 0)
                       AND COALESCE(period_code,'') = ? AND status IN ('pending','approved','ready') LIMIT 1",
                    [(int) $s['id'], $type, $ayId, $docPeriod]
                );
            } catch (Throwable $e) {
                storage_log('student document dup-check (period) failed: ' . $e->getMessage());
            }
        }
        if ($dup !== null) {
            set_flash('warning', 'You already have an active request for this document and term — track it below instead of duplicating.');
            redirect('/student/documents/' . (int) $dup['id']);
        }
        try {
            $docNo = 'DOC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $docRow = [
                'doc_no' => $docNo, 'student_id' => (int) $s['id'], 'doc_type' => $type,
                'purpose' => $purpose !== '' ? mb_substr($purpose, 0, 255) : null, 'status' => 'pending',
                'requested_by' => (int) Auth::id(),
                'remarks' => null,
            ];
            // Only bind columns that exist on this install (older live DBs lack them).
            if (self::hasDocColumn('academic_year_id')) $docRow['academic_year_id'] = $ayId > 0 ? $ayId : null;
            if (self::hasDocColumn('semester')) $docRow['semester'] = $semester !== '' ? $semester : null;
            if ($hasDocPeriod) $docRow['period_code'] = $docPeriod;
            $docId = Database::insert('academic_documents', $docRow);
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'document.requested',
                'entity_type' => 'academic_documents', 'entity_id' => $docId, 'ip_address' => client_ip(),
            ]);
            NotificationController::pushToRegistrars(
                'Document request: ' . self::TYPES[$type] . ' (' . $docNo . ')',
                'Student #' . $s['id'] . ' requested ' . self::TYPES[$type] . ' — review in Registrar Documents.',
                'info', '/registrar/documents'
            );
            set_flash('success', 'Request ' . $docNo . ' submitted (' . self::TYPES[$type] . '). Track its status below.');
        } catch (Throwable $e) {
            storage_log('student document request failed: ' . $e->getMessage());
            set_flash('error', 'Could not submit request: ' . $e->getMessage());
        }
        redirect('/student/documents');
    }

    public function show(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $s = $acting['row'];
        $id = (int) ($params['id'] ?? 0);
        $doc = self::hasDocColumn('academic_year_id')
            ? Database::one(
                "SELECT ad.*, ay.school_year FROM academic_documents ad
                  LEFT JOIN academic_years ay ON ay.id = ad.academic_year_id
                 WHERE ad.id = ? AND ad.student_id = ? LIMIT 1",
                [$id, (int) ($s['id'] ?? 0)]
            )
            : Database::one(
                "SELECT ad.*, NULL AS school_year FROM academic_documents ad
                 WHERE ad.id = ? AND ad.student_id = ? LIMIT 1",
                [$id, (int) ($s['id'] ?? 0)]
            );
        if ($doc === null) {
            http_response_code(404);
            exit('Request not found');
        }
        $history = Database::all(
            "SELECT * FROM academic_documents WHERE student_id = ? AND doc_type = ? AND id <> ?
              ORDER BY created_at DESC LIMIT 10",
            [(int) $s['id'], $doc['doc_type'], $id]
        );
        $this->view('student/documents_show', [
            'title' => 'Request ' . $doc['doc_no'], 'doc' => $doc,
            'history' => $history, 'types' => self::TYPES,
            'isParent' => Auth::is('parent'), 'childId' => $acting['via_parent'] ? (int) $acting['child_id'] : 0,
        ]);
    }

    public function requestAgain(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $s = $acting['row'];
        $id = (int) ($params['id'] ?? 0);
        $doc = Database::one(
            "SELECT * FROM academic_documents WHERE id = ? AND student_id = ? LIMIT 1",
            [$id, (int) ($s['id'] ?? 0)]
        );
        if ($doc === null) {
            set_flash('error', 'Request not found.');
            redirect('/student/documents');
        }
        if (!in_array($doc['status'], ['rejected', 'cancelled'], true)) {
            set_flash('info', 'Only rejected or cancelled requests can be requested again.');
            redirect('/student/documents/' . $id);
        }
        $purpose = trim((string) ($_POST['purpose'] ?? $doc['purpose'] ?? ''));
        try {
            $docNo = 'DOC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $reRow = [
                'doc_no' => $docNo, 'student_id' => (int) $s['id'], 'doc_type' => $doc['doc_type'],
                'purpose' => $purpose !== '' ? mb_substr($purpose, 0, 255) : null, 'status' => 'pending',
                'requested_by' => (int) Auth::id(),
                'remarks' => null,
            ];
            if (array_key_exists('academic_year_id', $doc) && self::hasDocColumn('academic_year_id')) $reRow['academic_year_id'] = $doc['academic_year_id'];
            if (array_key_exists('semester', $doc) && self::hasDocColumn('semester')) $reRow['semester'] = $doc['semester'];
            if (array_key_exists('period_code', $doc)) $reRow['period_code'] = $doc['period_code'];
            $newId = Database::insert('academic_documents', $reRow);
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'document.resubmitted',
                'entity_type' => 'academic_documents', 'entity_id' => $newId, 'ip_address' => client_ip(),
            ]);
            NotificationController::pushToRegistrars(
                'Document resubmitted: ' . $docNo,
                'Student corrected and resubmitted ' . (self::TYPES[$doc['doc_type']] ?? $doc['doc_type']) . ' (was ' . $doc['doc_no'] . ').',
                'info', '/registrar/documents'
            );
            set_flash('success', 'Resubmitted as ' . $docNo . ' — the original rejection stays in history below.');
            redirect('/student/documents/' . $newId);
        } catch (Throwable $e) {
            storage_log('document request-again failed ' . $id . ': ' . $e->getMessage());
            set_flash('error', 'Could not resubmit: ' . $e->getMessage());
            redirect('/student/documents/' . $id);
        }
    }

    public function cancel(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $s = $acting['row'];
        $id = (int) ($params['id'] ?? 0);
        $doc = Database::one(
            "SELECT * FROM academic_documents WHERE id = ? AND student_id = ? LIMIT 1",
            [$id, (int) ($s['id'] ?? 0)]
        );
        if ($doc === null || !in_array($doc['status'], ['pending'], true)) {
            set_flash('error', 'Only pending requests can be cancelled.');
            redirect('/student/documents');
        }
        Database::run("UPDATE academic_documents SET status = 'cancelled' WHERE id = ?", [$id]);
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'document.cancelled',
            'entity_type' => 'academic_documents', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        set_flash('success', 'Request ' . $doc['doc_no'] . ' cancelled.');
        redirect('/student/documents');
    }
}
