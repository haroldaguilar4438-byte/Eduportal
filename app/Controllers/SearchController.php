<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use Throwable;

/**
 * Global topbar search suggestions (spec: portal-wide search).
 *
 * Read-only GET endpoint. Every source is role-scoped so a suggestion can
 * never leak a record the signed-in user is not allowed to open:
 *   - staff (admin/registrar/accounting) search across their own queues
 *   - teachers search only their own classrooms, tasks and students
 *   - students search only their own permits, applications and documents
 *   - parents search only their linked children and those children's records
 *   - announcements are limited to published items addressed to everyone,
 *     so role-targeted notices never surface to the wrong role
 *
 * Each source fails open (try/catch) so a missing table degrades to fewer
 * suggestions instead of a 500. Output is capped (5 per source, 12 total)
 * and the frontend renders labels with textContent only.
 */
final class SearchController extends Controller
{
    private const PER_SOURCE = 5;
    private const TOTAL      = 12;

    public function suggest(array $params = []): void
    {
        if (!Auth::check()) {
            json_response(['ok' => false, 'items' => []], 401);
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
            json_response(['ok' => true, 'items' => []]);
        }

        $role = strtolower((string) Auth::role());
        $uid  = (int) Auth::id();
        $like = '%' . self::escapeLike($q) . '%';

        $items = match ($role) {
            'admin'      => $this->forAdmin($like),
            'registrar'  => $this->forRegistrar($like),
            'accounting' => $this->forAccounting($like),
            'teacher'    => $this->forTeacher($uid, $like),
            'student'    => $this->forStudent($uid, $like),
            'parent'     => $this->forParent($uid, $like),
            default      => [],
        };

        json_response(['ok' => true, 'items' => array_slice($items, 0, self::TOTAL)]);
    }

    /* ============================== staff ============================== */

    /** @return list<array{group:string,title:string,sub:string,url:string,icon:string}> */
    private function forAdmin(string $like): array
    {
        return array_merge(
            $this->students($like, '/registrar/students/'),
            $this->permits($like, null, '/admin/permits?status=all'),
            $this->payments($like, null, '/admin/payments?status=all'),
            $this->enrollments($like, null, '/admin/enrollments/'),
            $this->announcements($like),
        );
    }

    /** @return list<array{group:string,title:string,sub:string,url:string,icon:string}> */
    private function forRegistrar(string $like): array
    {
        return array_merge(
            $this->students($like, '/registrar/students/'),
            $this->permits($like, null, '/registrar/permits?status=all'),
            $this->enrollments($like, null, null),
            $this->announcements($like),
        );
    }

    /** @return list<array{group:string,title:string,sub:string,url:string,icon:string}> */
    private function forAccounting(string $like): array
    {
        return array_merge(
            $this->students($like, '/accounting/students/'),
            $this->payments($like, null, '/accounting/payments?status=all'),
            $this->permits($like, null, '/accounting/permits?status=all'),
            $this->enrollments($like, null, '/accounting/enrollments?status=all'),
        );
    }

    /* ========================= teachers / learners ========================= */

    /** @return list<array{group:string,title:string,sub:string,url:string,icon:string}> */
    private function forTeacher(int $uid, string $like): array
    {
        $out = [];
        try {
            $teacher = Database::one('SELECT id FROM teachers WHERE user_id = ? LIMIT 1', [$uid]);
            if ($teacher === null) {
                return $this->announcements($like);
            }
            $tid = (int) $teacher['id'];

            // Own classrooms.
            $rooms = Database::all(
                "SELECT id, subject_code, subject_title FROM subject_classrooms
                  WHERE teacher_id = ? AND (subject_code LIKE ? ESCAPE '\\\\' OR subject_title LIKE ? ESCAPE '\\\\')
                  ORDER BY subject_code LIMIT " . self::PER_SOURCE,
                [$tid, $like, $like]
            );
            foreach ($rooms as $r) {
                $out[] = [
                    'group' => 'Classrooms',
                    'title' => trim((string) ($r['subject_code'] ?? '') . ' ' . ($r['subject_title'] ?? '')),
                    'sub'   => 'My classroom',
                    'url'   => url('/classrooms/' . (int) $r['id']),
                    'icon'  => 'bi-easel2',
                ];
            }

            // Own tasks.
            $tasks = Database::all(
                "SELECT ct.id, ct.title, sc.subject_code FROM classroom_tasks ct
                   JOIN subject_classrooms sc ON sc.id = ct.classroom_id
                  WHERE sc.teacher_id = ? AND ct.title LIKE ? ESCAPE '\\\\'
                  ORDER BY ct.id DESC LIMIT " . self::PER_SOURCE,
                [$tid, $like]
            );
            foreach ($tasks as $t) {
                $out[] = [
                    'group' => 'Tasks',
                    'title' => (string) ($t['title'] ?? 'Task'),
                    'sub'   => (string) ($t['subject_code'] ?? ''),
                    'url'   => url('/classroom-tasks/' . (int) $t['id']),
                    'icon'  => 'bi-list-task',
                ];
            }

            // Students in own classrooms only.
            $kids = Database::all(
                "SELECT DISTINCT s.id, s.first_name, s.last_name, s.student_no FROM students s
                   JOIN classroom_members cm ON cm.student_id = s.id
                   JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                  WHERE sc.teacher_id = ?
                    AND (s.first_name LIKE ? ESCAPE '\\\\' OR s.last_name LIKE ? ESCAPE '\\\\'
                      OR s.student_no LIKE ? ESCAPE '\\\\' OR s.email LIKE ? ESCAPE '\\\\')
                  ORDER BY s.last_name, s.first_name LIMIT " . self::PER_SOURCE,
                [$tid, $like, $like, $like, $like]
            );
            foreach ($kids as $s) {
                $out[] = [
                    'group' => 'Students',
                    'title' => trim(trim((string) ($s['first_name'] ?? '')) . ' ' . trim((string) ($s['last_name'] ?? ''))),
                    'sub'   => (string) ($s['student_no'] ?? ''),
                    'url'   => url('/teacher/grades'),
                    'icon'  => 'bi-people',
                ];
            }
        } catch (Throwable $e) {
            // Fail open: teacher still gets announcements below.
        }
        return array_merge($out, $this->announcements($like));
    }

    /** @return list<array{group:string,title:string,sub:string,url:string,icon:string}> */
    private function forStudent(int $uid, string $like): array
    {
        $out = [];
        try {
            $me = Database::one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$uid]);
            if ($me === null) {
                return $this->announcements($like);
            }
            $sid = (int) $me['id'];

            // Own permits.
            $permits = Database::all(
                "SELECT id, permit_no, exam_period, status FROM exam_permits
                  WHERE student_id = ? AND (permit_no LIKE ? ESCAPE '\\\\' OR exam_period LIKE ? ESCAPE '\\\\')
                  ORDER BY requested_at DESC LIMIT " . self::PER_SOURCE,
                [$sid, $like, $like]
            );
            foreach ($permits as $p) {
                $out[] = [
                    'group' => 'My permits',
                    'title' => (string) ($p['permit_no'] ?? 'Permit'),
                    'sub'   => trim((string) ($p['exam_period'] ?? '') . ' · ' . str_replace('_', ' ', (string) ($p['status'] ?? ''))),
                    'url'   => url('/student/permits'),
                    'icon'  => 'bi-patch-check',
                ];
            }

            // Own applications.
            $apps = Database::all(
                "SELECT id, application_no, status FROM enrollment_applications
                  WHERE (user_id = ? OR student_id = ?)
                    AND (application_no LIKE ? ESCAPE '\\\\' OR status LIKE ? ESCAPE '\\\\')
                  ORDER BY created_at DESC LIMIT " . self::PER_SOURCE,
                [$uid, $sid, $like, $like]
            );
            foreach ($apps as $a) {
                $out[] = [
                    'group' => 'My applications',
                    'title' => (string) ($a['application_no'] ?? 'Application'),
                    'sub'   => str_replace('_', ' ', (string) ($a['status'] ?? '')),
                    'url'   => url('/my-enrollments'),
                    'icon'  => 'bi-collection',
                ];
            }

            // Own documents.
            $docs = Database::all(
                "SELECT id, doc_no, doc_type, status FROM academic_documents
                  WHERE student_id = ? AND (doc_no LIKE ? ESCAPE '\\\\' OR doc_type LIKE ? ESCAPE '\\\\')
                  ORDER BY created_at DESC LIMIT " . self::PER_SOURCE,
                [$sid, $like, $like]
            );
            foreach ($docs as $d) {
                $out[] = [
                    'group' => 'My documents',
                    'title' => (string) ($d['doc_no'] ?? 'Document'),
                    'sub'   => str_replace('_', ' ', (string) ($d['doc_type'] ?? '')),
                    'url'   => url('/student/documents'),
                    'icon'  => 'bi-file-earmark-text',
                ];
            }
        } catch (Throwable $e) {
            // Fail open.
        }
        return array_merge($out, $this->announcements($like));
    }

    /** @return list<array{group:string,title:string,sub:string,url:string,icon:string}> */
    private function forParent(int $uid, string $like): array
    {
        $out = [];
        try {
            $guardian = Database::one('SELECT id FROM guardians WHERE user_id = ? LIMIT 1', [$uid]);
            if ($guardian === null) {
                return $this->announcements($like);
            }
            $kids = Database::all(
                "SELECT s.id, s.first_name, s.last_name, s.student_no FROM students s
                   JOIN guardian_students gs ON gs.student_id = s.id
                  WHERE gs.guardian_id = ?
                    AND (s.first_name LIKE ? ESCAPE '\\\\' OR s.last_name LIKE ? ESCAPE '\\\\'
                      OR s.student_no LIKE ? ESCAPE '\\\\')
                  ORDER BY s.last_name, s.first_name LIMIT " . self::PER_SOURCE,
                [(int) $guardian['id'], $like, $like, $like]
            );
            $kidIds = [];
            foreach ($kids as $k) {
                $kidIds[] = (int) $k['id'];
                $out[] = [
                    'group' => 'My children',
                    'title' => trim(trim((string) ($k['first_name'] ?? '')) . ' ' . trim((string) ($k['last_name'] ?? ''))),
                    'sub'   => (string) ($k['student_no'] ?? ''),
                    'url'   => url('/parent/children/' . (int) $k['id']),
                    'icon'  => 'bi-people',
                ];
            }

            // Children's applications.
            if ($kidIds !== []) {
                $placeholders = implode(',', array_fill(0, count($kidIds), '?'));
                $apps = Database::all(
                    "SELECT id, application_no, status, student_id FROM enrollment_applications
                      WHERE (student_id IN ($placeholders) OR user_id = ?)
                        AND (application_no LIKE ? ESCAPE '\\\\' OR status LIKE ? ESCAPE '\\\\')
                      ORDER BY created_at DESC LIMIT " . self::PER_SOURCE,
                    array_merge($kidIds, [$uid, $like, $like])
                );
                foreach ($apps as $a) {
                    $out[] = [
                        'group' => 'Applications',
                        'title' => (string) ($a['application_no'] ?? 'Application'),
                        'sub'   => str_replace('_', ' ', (string) ($a['status'] ?? '')),
                        'url'   => url('/parent/applications?child=' . (int) ($a['student_id'] ?? 0)),
                        'icon'  => 'bi-collection',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Fail open.
        }
        return array_merge($out, $this->announcements($like));
    }

    /* ============================ shared sources ============================ */

    /**
     * @param int|null $studentId scope to one student when set
     * @return list<array{group:string,title:string,sub:string,url:string,icon:string}>
     */
    private function students(string $like, string $detailPrefix, ?int $studentId = null): array
    {
        $out = [];
        try {
            // The OR group is closed BEFORE any AND scope, or scoping silently
            // breaks (AND binds tighter than OR).
            $where = "(first_name LIKE ? ESCAPE '\\\\' OR last_name LIKE ? ESCAPE '\\\\'"
                . " OR student_no LIKE ? ESCAPE '\\\\' OR email LIKE ? ESCAPE '\\\\')";
            $args = [$like, $like, $like, $like];
            if ($studentId !== null) {
                $where .= ' AND id = ?';
                $args[] = $studentId;
            }
            $sql = 'SELECT id, first_name, last_name, student_no, email FROM students'
                . ' WHERE ' . $where
                . ' ORDER BY last_name, first_name LIMIT ' . self::PER_SOURCE;
            foreach (Database::all($sql, $args) as $s) {
                $out[] = [
                    'group' => 'Students',
                    'title' => trim(trim((string) ($s['first_name'] ?? '')) . ' ' . trim((string) ($s['last_name'] ?? ''))),
                    'sub'   => trim((string) ($s['student_no'] ?? '') . ' · ' . ($s['email'] ?? ''), ' ·'),
                    'url'   => url($detailPrefix . (int) $s['id']),
                    'icon'  => 'bi-people',
                ];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * @param int[]|null $studentIds scope to these students when set
     * @return list<array{group:string,title:string,sub:string,url:string,icon:string}>
     */
    private function permits(string $like, ?array $studentIds, string $queueUrl): array
    {
        $out = [];
        try {
            $where = "(permit_no LIKE ? ESCAPE '\\\\' OR exam_period LIKE ? ESCAPE '\\\\')";
            $args = [$like, $like];
            if ($studentIds !== null) {
                if ($studentIds === []) {
                    return [];
                }
                $where .= ' AND student_id IN (' . implode(',', array_fill(0, count($studentIds), '?')) . ')';
                $args = array_merge($args, $studentIds);
            }
            $sql = 'SELECT id, permit_no, exam_period, status FROM exam_permits'
                . ' WHERE ' . $where
                . ' ORDER BY requested_at DESC LIMIT ' . self::PER_SOURCE;
            foreach (Database::all($sql, $args) as $p) {
                $out[] = [
                    'group' => 'Permits',
                    'title' => (string) ($p['permit_no'] ?? 'Permit'),
                    'sub'   => trim((string) ($p['exam_period'] ?? '') . ' · ' . str_replace('_', ' ', (string) ($p['status'] ?? ''))),
                    'url'   => url($queueUrl),
                    'icon'  => 'bi-patch-check',
                ];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * @param int[]|null $studentIds scope to these students when set
     * @return list<array{group:string,title:string,sub:string,url:string,icon:string}>
     */
    private function payments(string $like, ?array $studentIds, string $queueUrl): array
    {
        $out = [];
        try {
            $where = "(transaction_ref LIKE ? ESCAPE '\\\\')";
            $args = [$like];
            if ($studentIds !== null) {
                if ($studentIds === []) {
                    return [];
                }
                $where .= ' AND student_id IN (' . implode(',', array_fill(0, count($studentIds), '?')) . ')';
                $args = array_merge($args, $studentIds);
            }
            $sql = 'SELECT id, transaction_ref, amount, status FROM payments'
                . ' WHERE ' . $where
                . ' ORDER BY created_at DESC LIMIT ' . self::PER_SOURCE;
            foreach (Database::all($sql, $args) as $p) {
                $out[] = [
                    'group' => 'Payments',
                    'title' => (string) ($p['transaction_ref'] ?? 'Payment'),
                    'sub'   => trim(money($p['amount'] ?? 0) . ' · ' . ($p['status'] ?? '')),
                    'url'   => url($queueUrl),
                    'icon'  => 'bi-credit-card',
                ];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * @param int|null $detailId link to a detail page when applications are
     *   unscoped; scoped queues link to their list instead
     * @return list<array{group:string,title:string,sub:string,url:string,icon:string}>
     */
    private function enrollments(string $like, ?array $studentIds, ?string $detailPrefix): array
    {
        $out = [];
        try {
            $where = "(application_no LIKE ? ESCAPE '\\\\' OR status LIKE ? ESCAPE '\\\\')";
            $args = [$like, $like];
            if ($studentIds !== null) {
                if ($studentIds === []) {
                    return [];
                }
                $where .= ' AND student_id IN (' . implode(',', array_fill(0, count($studentIds), '?')) . ')';
                $args = array_merge($args, $studentIds);
            }
            $sql = 'SELECT id, application_no, status FROM enrollment_applications'
                . ' WHERE ' . $where
                . ' ORDER BY created_at DESC LIMIT ' . self::PER_SOURCE;
            foreach (Database::all($sql, $args) as $a) {
                $out[] = [
                    'group' => 'Enrollments',
                    'title' => (string) ($a['application_no'] ?? 'Application'),
                    'sub'   => str_replace('_', ' ', (string) ($a['status'] ?? '')),
                    'url'   => $detailPrefix !== null
                        ? url($detailPrefix . (int) $a['id'])
                        : url('/registrar/enrollments?status=all'),
                    'icon'  => 'bi-journal-text',
                ];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Published announcements addressed to everyone only, so role-targeted
     * notices never surface to the wrong role through suggestions.
     *
     * @return list<array{group:string,title:string,sub:string,url:string,icon:string}>
     */
    private function announcements(string $like): array
    {
        $out = [];
        try {
            $rows = Database::all(
                "SELECT id, title FROM announcements
                  WHERE status = 'published' AND audience IN ('everyone', 'all')
                    AND title LIKE ? ESCAPE '\\\\'
                  ORDER BY created_at DESC LIMIT " . self::PER_SOURCE,
                [$like]
            );
            foreach ($rows as $r) {
                $out[] = [
                    'group' => 'Announcements',
                    'title' => (string) ($r['title'] ?? 'Announcement'),
                    'sub'   => 'School notice',
                    'url'   => url('/announcements/' . (int) $r['id']),
                    'icon'  => 'bi-megaphone',
                ];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Escape user input for LIKE: backslash first, then the two wildcards.
     * Every query above pairs this with an explicit ESCAPE clause.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
