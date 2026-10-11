<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Permanent reusable student documents (EXTENSION — never replaces
 * enrollment_documents, academic_documents, or any working workflow).
 *
 * Model:
 *   students (permanent student_no)
 *     -> student_documents (one row per student+doc_type+version, history kept)
 *     -> enrollment_applications (one row per AY/semester, history kept)
 *          -> enrollment_documents (per-application snapshot/link)
 *
 * Rules:
 *   - Verified rows are reused automatically; never ask again.
 *   - New upload required only when missing OR status = needs_resubmission.
 *   - Resubmission creates a NEW version; old versions stay in history.
 *   - Status changes happen only via accounting/admin/registrar workflows.
 */
final class StudentDocs
{
    /** Canonical enrollment doc vocabulary (maps legacy upload fields). */
    public const TYPES = [
        'birth_certificate'       => 'PSA Birth Certificate',
        'valid_id'                => 'Valid ID',
        'good_moral'              => 'Certificate of Good Moral',
        'form137'                 => 'Form 137 (Permanent Record)',
        'form138_report_card'     => 'Form 138 / Report Card',
        'id_photo'                => '2x2 ID Photo',
        'eccd_checklist'          => 'ECCD Checklist / Preschool Record',
    ];

    /** Docs every applicant must have on file (verified or submitted). */
    public const REQUIRED = ['birth_certificate', 'good_moral', 'id_photo'];

    /** Legacy enrollment form field -> canonical doc_type. */
    public const FIELD_MAP = [
        'birth_certificate' => 'birth_certificate',
        'good_moral'        => 'good_moral',
        'id_photo'          => 'id_photo',
        'form137'           => 'form137',
        'form138'           => 'form138_report_card',
        'valid_id'          => 'valid_id',
        'prev_record'       => 'previous_school_record',
        'eccd_checklist'    => 'eccd_checklist',
    ];

    /**
     * DepEd-style required documents per applying level + applicant type.
     * $level: ['code'=>..., 'category'=>...] from grade_levels (or null).
     * $applicant: 'new' | 'returning' | 'transferee'.
     * Unknown levels fall back to the legacy REQUIRED trio (unchanged).
     *
     *   Kinder (new)      : PSA birth cert, ECCD checklist, 2x2 photo.
     *                       (No Form 137, no report card, no good moral —
     *                       none of those exist yet at this age.)
     *   Grade 1 (new)     : PSA birth cert, Kindergarten Report Card (SF9),
     *                       ECCD checklist, 2x2 photo.
     *   Grades 2-6 (new)  : PSA birth cert, report card (SF9), 2x2 photo.
     *   Grade 7 (new)     : Grade 6 report card (SF9), PSA birth cert,
     *                       good moral (esp. from private schools), 2x2 photo.
     *   Grades 8-10 (new) : PSA birth cert, report card (SF9), good moral,
     *                       2x2 photo.
     *   Returning pupils  : report card (SF9) + 2x2 photo only.
     *   Transferees       : report card (SF9) + Form 137 + PSA + good moral
     *                       + 2x2 photo.
     */
    public static function requiredForLevel(?array $level, string $applicant = 'new'): array
    {
        $code = strtoupper(trim((string) ($level['code'] ?? '')));
        $cat = trim((string) ($level['category'] ?? ''));
        $applicant = in_array($applicant, ['returning', 'transferee'], true) ? $applicant : 'new';
        if ($code === '' && $cat === '') {
            return self::REQUIRED;
        }
        $isKinder = $code === 'K' || $cat === 'kindergarten';
        $isG1 = $code === 'G1';
        $isElem = $cat === 'elementary' && !$isG1;
        $isG7 = $code === 'G7';
        $isJhs = $cat === 'junior_high' && !$isG7;

        if ($applicant === 'transferee' && ($isKinder || $cat === 'kindergarten' || $cat === 'elementary' || $cat === 'junior_high')) {
            return ['form138_report_card', 'form137', 'birth_certificate', 'good_moral', 'id_photo'];
        }
        if ($isKinder) {
            return ['birth_certificate', 'eccd_checklist', 'id_photo'];
        }
        if ($applicant === 'returning') {
            if ($isG1 || $isElem || $isG7 || $isJhs) {
                return ['form138_report_card', 'id_photo'];
            }
            return self::REQUIRED;
        }
        if ($isG1) {
            return ['birth_certificate', 'form138_report_card', 'eccd_checklist', 'id_photo'];
        }
        if ($isElem) {
            return ['birth_certificate', 'form138_report_card', 'id_photo'];
        }
        if ($isG7 || $isJhs) {
            return ['birth_certificate', 'form138_report_card', 'good_moral', 'id_photo'];
        }
        return self::REQUIRED;
    }

    /** Short guidance line shown under a doc input for a level (or ''). */
    public static function hintFor(string $type, ?array $level): string
    {
        $code = strtoupper(trim((string) ($level['code'] ?? '')));
        return match ($type) {
            'birth_certificate' => 'PSA/NSO photocopy (civil registrar or barangay certification accepted temporarily).',
            'eccd_checklist' => 'ECCD checklist or daycare/preschool completion record (if your child attended one).',
            'form138_report_card' => $code === 'G1'
                ? 'Kindergarten Report Card (SF9) — original copy.'
                : ($code === 'G7'
                    ? 'Grade 6 Report Card / SF9 (Elementary) — original copy.'
                    : 'Report Card / SF9 from the last school year — original copy.'),
            'form137' => 'Permanent academic record (SF10) — if available or requested by the registrar.',
            'good_moral' => 'From the previous school (often requested when coming from a private school).',
            'id_photo' => 'Recent 2x2 colored photo, white background.',
            default => '',
        };
    }

    /** Kindergarten age guidance (DepEd cut-off rule), shown, not hard-blocked. */
    public static function kinderAgeNote(): string
    {
        return 'Children should generally be 5 years old by the DepEd cut-off date for the school year. A child turning 5 late in the year may be asked for proof of preschool/daycare completion (ECCD).';
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    public static function hasTable(): bool
    {
        try {
            return Database::value("SHOW TABLES LIKE 'student_documents'") !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Latest row per doc_type for a student (version history preserved). */
    public static function latestByType(int $studentId): array
    {
        if (!self::hasTable() || $studentId <= 0) return [];
        try {
            $rows = Database::all(
                "SELECT * FROM student_documents WHERE student_id = ? ORDER BY doc_type ASC, version DESC",
                [$studentId]
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!isset($out[$r['doc_type']])) $out[$r['doc_type']] = $r;
        }
        return $out;
    }

    /** Full version history for one doc_type (newest first). */
    public static function history(int $studentId, string $docType): array
    {
        if (!self::hasTable()) return [];
        try {
            return Database::all(
                "SELECT sd.*, CONCAT(u.first_name,' ',u.last_name) AS reviewer
                   FROM student_documents sd LEFT JOIN users u ON u.id = sd.reviewed_by
                  WHERE sd.student_id = ? AND sd.doc_type = ? ORDER BY sd.version DESC",
                [$studentId, $docType]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * ONE SOURCE OF TRUTH for student document status.
     *
     * Root-cause fix: enrollment uploads live in `enrollment_documents`
     * (one row per application), while the permanent reusable file lives in
     * `student_documents` (one row per student+type+version). Pages that read
     * only one table disagree with pages that read the other. Every
     * student-facing status display and every "what must be uploaded"
     * decision must go through self::unified() / self::neededForEnrollment().
     *
     * Effective status per type (first match wins):
     *   permanent verified            -> verified (reused; uploads ignored)
     *   permanent needs_resubmission  -> needs_resubmission (reason shown)
     *   any application upload        -> that upload's status (submitted…)
     *   permanent submitted           -> submitted (pending review)
     *   permanent rejected            -> rejected
     *   otherwise                     -> missing
     *
     * @return array<string,array{label:string,status:string,row:?array,permanent:?array,upload:?array,reason:?string,version:?int,history:array<int,array{kind:string,version:?int,status:string,file:?string,name:?string,date:?string,reason:?string,app:?string}>}>
     */
    public static function unified(int $studentId, int $userId = 0, int $childId = 0, bool $newChildFiling = false): array
    {
        $perm = $studentId > 0 ? self::latestByType($studentId) : [];
        $ups = ($userId > 0 || $studentId > 0) ? self::applicationUploads($userId, $studentId, $childId, $newChildFiling) : [];
        $out = [];
        foreach (self::TYPES as $t => $label) {
            $p = $perm[$t] ?? null;
            $u = $ups[$t] ?? null;
            $pStatus = $p['status'] ?? null;
            if ($p !== null && $pStatus === 'verified') {
                $status = 'verified';
                $row = $p;
            } elseif ($p !== null && $pStatus === 'needs_resubmission') {
                $status = 'needs_resubmission';
                $row = $p;
            } elseif ($u !== null) {
                $status = (string) ($u['status'] ?? '');
                if ($status === '' || $status === null) $status = 'submitted';
                $row = $u;
            } elseif ($p !== null && $pStatus === 'submitted') {
                $status = 'submitted';
                $row = $p;
            } elseif ($p !== null && $pStatus === 'rejected') {
                $status = 'rejected';
                $row = $p;
            } else {
                $status = 'missing';
                $row = null;
            }
            $history = [];
            if ($p !== null && $studentId > 0) {
                foreach (self::history($studentId, $t) as $h) {
                    $history[] = [
                        'kind' => 'permanent', 'version' => isset($h['version']) ? (int) $h['version'] : null,
                        'status' => (string) ($h['status'] ?? ''), 'file' => $h['stored_path'] ?? null,
                        'name' => $h['original_filename'] ?? null, 'date' => $h['created_at'] ?? null,
                        'reason' => $h['review_reason'] ?? null, 'app' => null,
                    ];
                }
            }
            if ($u !== null) {
                // Newest upload per type only (older per-application rows are
                // superseded; the application itself stays in history).
                $history[] = [
                    'kind' => 'upload', 'version' => null,
                    'status' => (string) ($u['status'] ?? 'submitted') !== '' ? (string) ($u['status'] ?? 'submitted') : 'submitted',
                    'file' => $u['stored_path'] ?? null, 'name' => $u['original_filename'] ?? null,
                    'date' => $u['uploaded_at'] ?? null, 'reason' => $u['review_reason'] ?? null,
                    'app' => $u['application_no'] ?? null,
                ];
            }
            usort($history, static function ($a, $b) {
                return strtotime((string) ($b['date'] ?? '')) <=> strtotime((string) ($a['date'] ?? ''));
            });
            $out[$t] = [
                'label' => $label, 'status' => $status, 'row' => $row,
                'permanent' => $p, 'upload' => $u,
                'reason' => $row['review_reason'] ?? null,
                'version' => isset($row['version']) ? (int) $row['version'] : null,
                'history' => $history,
            ];
        }
        return $out;
    }

    /**
     * Latest application upload per doc_type across ALL of a user's
     * applications (matched by user_id, or student_id once linked).
     * This is what the enrollment page sees — the Documents page must
     * recognize these same rows instead of reporting "missing".
     *
     * Sibling safety for parent accounts:
     *   $childId > 0      → only rows attributed to THAT linked child
     *                        (tagged filings or their student id).
     *   $newChildFiling   → parent filing for a NEW (unlinked) child:
     *                        nothing is on file, except that account's own
     *                        dead filings (rejected / action required) being
     *                        corrected — a sibling's pending/approved papers
     *                        never leak across.
     */
    public static function applicationUploads(int $userId, int $studentId = 0, int $childId = 0, bool $newChildFiling = false): array
    {
        try {
            if ($childId > 0) {
                $sid = $studentId > 0 ? $studentId : $childId;
                try {
                    $rows = Database::all(
                        "SELECT ed.*, ea.application_no, ea.status AS app_status
                           FROM enrollment_documents ed
                           JOIN enrollment_applications ea ON ea.id = ed.application_id
                          WHERE (ea.applicant_child_id = ? OR ea.student_id = ?)
                          ORDER BY ed.uploaded_at DESC, ed.id DESC",
                        [$childId, $sid]
                    );
                } catch (Throwable $e) {
                    // Column may predate the applicant_child_id patch.
                    $rows = Database::all(
                        "SELECT ed.*, ea.application_no, ea.status AS app_status
                           FROM enrollment_documents ed
                           JOIN enrollment_applications ea ON ea.id = ed.application_id
                          WHERE ea.student_id = ?
                          ORDER BY ed.uploaded_at DESC, ed.id DESC",
                        [$sid]
                    );
                }
            } elseif ($newChildFiling) {
                try {
                    $rows = Database::all(
                        "SELECT ed.*, ea.application_no, ea.status AS app_status
                           FROM enrollment_documents ed
                           JOIN enrollment_applications ea ON ea.id = ed.application_id
                          WHERE ea.user_id = ? AND ea.student_id IS NULL AND ea.applicant_child_id IS NULL
                            AND ea.status IN ('rejected','action_required')
                          ORDER BY ed.uploaded_at DESC, ed.id DESC",
                        [$userId]
                    );
                } catch (Throwable $e) {
                    // Column may predate the applicant_child_id patch.
                    $rows = Database::all(
                        "SELECT ed.*, ea.application_no, ea.status AS app_status
                           FROM enrollment_documents ed
                           JOIN enrollment_applications ea ON ea.id = ed.application_id
                          WHERE ea.user_id = ? AND ea.student_id IS NULL
                            AND ea.status IN ('rejected','action_required')
                          ORDER BY ed.uploaded_at DESC, ed.id DESC",
                        [$userId]
                    );
                }
            } else {
                $rows = Database::all(
                    "SELECT ed.*, ea.application_no, ea.status AS app_status
                       FROM enrollment_documents ed
                       JOIN enrollment_applications ea ON ea.id = ed.application_id
                      WHERE (ea.user_id = ? OR (? > 0 AND ea.student_id = ?))
                      ORDER BY ed.uploaded_at DESC, ed.id DESC",
                    [$userId, $studentId, $studentId]
                );
            }
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $t = (string) ($r['doc_type'] ?? '');
            if ($t === 'form138') $t = 'form138_report_card'; // legacy field name
            if ($t === '' || isset($out[$t])) continue;
            if (!array_key_exists($t, self::TYPES)) continue;
            $r['doc_type'] = $t;
            $out[$t] = $r;
        }
        return $out;
    }

    /**
     * What does this student still need to upload for a new enrollment?
     * Unified: a valid row in EITHER table satisfies the requirement.
     * @return array<string,string> doc_type => reason (missing|resubmission_requested)
     */
    public static function neededForEnrollment(int $studentId, int $userId = 0, int $childId = 0, bool $newChildFiling = false, ?array $levelCtx = null): array
    {
        if ($studentId <= 0 && $userId <= 0) {
            return array_fill_keys(self::REQUIRED, 'missing');
        }
        $required = $levelCtx !== null
            ? self::requiredForLevel($levelCtx['level'] ?? null, (string) ($levelCtx['applicant'] ?? 'new'))
            : self::REQUIRED;
        $needed = [];
        foreach (self::unified($studentId, $userId, $childId, $newChildFiling) as $t => $info) {
            $st = $info['status'];
            if ($st === 'needs_resubmission') {
                $needed[$t] = 'resubmission_requested';
            } elseif ($st === 'missing' || $st === 'rejected') {
                if (in_array($t, $required, true)) {
                    $needed[$t] = 'missing';
                }
            }
        }
        return $needed;
    }

    /** Next version number for a student+type (1 when first). */
    public static function nextVersion(int $studentId, string $docType): int
    {
        try {
            $v = Database::value(
                "SELECT COALESCE(MAX(version),0)+1 FROM student_documents WHERE student_id = ? AND doc_type = ?",
                [$studentId, $docType]
            );
            return max(1, (int) $v);
        } catch (Throwable $e) {
            return 1;
        }
    }

    /**
     * Store a new version (student upload). Previous versions are kept.
     * A needs_resubmission row goes back to submitted (pending accounting review).
     */
    public static function storeVersion(
        int $studentId, string $docType, string $original, string $storedPath,
        string $mime, int $bytes, ?int $applicationId = null
    ): int {
        return Database::insert('student_documents', [
            'student_id'        => $studentId,
            'doc_type'          => $docType,
            'version'           => self::nextVersion($studentId, $docType),
            'original_filename' => mb_substr($original, 0, 191),
            'stored_path'       => $storedPath,
            'mime_type'         => $mime,
            'file_size_bytes'   => $bytes,
            'status'            => 'submitted',
            'review_reason'     => null,
            'application_id'    => $applicationId,
        ]);
    }

    /**
     * Backfill permanent rows from a just-submitted application's uploads,
     * so the NEXT semester can reuse them without re-upload.
     */
    public static function backfillFromApplication(int $applicationId, int $studentId): int
    {
        if (!self::hasTable() || $applicationId <= 0 || $studentId <= 0) return 0;
        $n = 0;
        try {
            $docs = Database::all(
                "SELECT * FROM enrollment_documents WHERE application_id = ?",
                [$applicationId]
            );
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($docs as $d) {
            $type = (string) ($d['doc_type'] ?? '');
            if ($type === 'form138_report_card') { /* canonical already */ }
            if (!array_key_exists($type, self::TYPES)) continue;
            try {
                $exists = Database::one(
                    "SELECT id FROM student_documents WHERE student_id = ? AND doc_type = ? AND stored_path = ? LIMIT 1",
                    [$studentId, $type, (string) $d['stored_path']]
                );
                if ($exists !== null) {
                    if (empty($d['student_document_id'])) {
                        Database::run(
                            "UPDATE enrollment_documents SET student_document_id = ? WHERE id = ?",
                            [(int) $exists['id'], (int) $d['id']]
                        );
                    }
                    continue;
                }
                $sid = Database::insert('student_documents', [
                    'student_id'        => $studentId,
                    'doc_type'          => $type,
                    'version'           => self::nextVersion($studentId, $type),
                    'original_filename' => (string) $d['original_filename'],
                    'stored_path'       => (string) $d['stored_path'],
                    'mime_type'         => (string) $d['mime_type'],
                    'file_size_bytes'   => (int) $d['file_size_bytes'],
                    'status'            => (($d['status'] ?? '') === 'verified' || (int)($d['is_verified'] ?? 0) === 1) ? 'verified' : 'submitted',
                    'reviewed_by'       => $d['verified_by'] ?? null,
                    'reviewed_at'       => $d['verified_at'] ?? null,
                    'application_id'    => $applicationId,
                ]);
                Database::run(
                    "UPDATE enrollment_documents SET student_document_id = ? WHERE id = ?",
                    [$sid, (int) $d['id']]
                );
                $n++;
            } catch (Throwable $e) {
                continue;
            }
        }
        return $n;
    }

    /** Link already-verified permanent docs into a new application (no re-upload). */
    public static function linkVerifiedIntoApplication(int $applicationId, int $studentId): int
    {
        if (!self::hasTable() || $applicationId <= 0 || $studentId <= 0) return 0;
        $n = 0;
        foreach (self::latestByType($studentId) as $type => $row) {
            if (!in_array($row['status'] ?? '', ['verified', 'submitted'], true)) continue;
            try {
                $exists = Database::one(
                    "SELECT id FROM enrollment_documents WHERE application_id = ? AND doc_type = ? LIMIT 1",
                    [$applicationId, $type]
                );
                if ($exists !== null) continue;
                Database::insert('enrollment_documents', [
                    'application_id'      => $applicationId,
                    'doc_type'            => $type,
                    'original_filename'   => (string) $row['original_filename'],
                    'stored_path'         => (string) $row['stored_path'],
                    'mime_type'           => (string) $row['mime_type'],
                    'file_size_bytes'     => (int) $row['file_size_bytes'],
                    'is_verified'         => (($row['status'] ?? '') === 'verified') ? 1 : 0,
                    'status'              => (($row['status'] ?? '') === 'verified') ? 'verified' : 'submitted',
                    'verified_by'         => $row['reviewed_by'] ?? null,
                    'verified_at'         => $row['reviewed_at'] ?? null,
                    'student_document_id' => (int) $row['id'],
                ]);
                $n++;
            } catch (Throwable $e) {
                continue;
            }
        }
        return $n;
    }
}
