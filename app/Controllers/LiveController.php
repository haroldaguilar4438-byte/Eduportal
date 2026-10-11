<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use Throwable;

/**
 * Live sync API (adaptive-polling transport for EduLive).
 *
 * Why polling, not SSE/WebSockets: the production target is shared PHP
 * hosting (Hostinger-style Apache + PHP) with no long-lived-process or
 * socket support. A single lightweight endpoint polled adaptively
 * (active tab ~15s, hidden tab backed off, instant resync on
 * visibility/focus/online) gives the no-refresh experience without new
 * infrastructure. No separate socket system was introduced on purpose.
 *
 * Security: every payload is computed for the CALLER only, reusing the same
 * queries/filters as normal page loads. Students see only their own rows,
 * teachers only their assigned classes. No cross-user data ever leaves here.
 *
 *   GET /api/live/digest  -> {unread, counts:{...}, server_time}
 */
final class LiveController extends Controller
{
    public function digest(array $params = []): void
    {
        if (!Auth::check()) json_response(['unread' => 0, 'counts' => [], 'server_time' => time()]);
        $uid = (int) Auth::id();
        $role = (string) (Auth::role() ?? '');
        $counts = self::countersFor($uid, $role);
        // Real-time announcement badge (same server-side filter as page loads)
        try {
            $counts['announcements_unread'] = \App\Models\Announcement::unreadCount($role, $uid);
        } catch (\Throwable $e) {
            $counts['announcements_unread'] = 0;
        }
        json_response([
            'unread' => NotificationController::unreadCountFor($uid),
            'counts' => $counts,
            'server_time' => time(),
        ]);
    }

    /**
     * Role-aware counters. Each mirrors the corresponding dashboard query so
     * live badges and page loads can never disagree (§47). Pure function of
     * ($userId, $role) — unit-testable. All fail-open.
     *
     * @return array<string,int>
     */
    public static function countersFor(int $userId, string $role): array
    {
        $out = [];
        if ($userId <= 0) return $out;
        try {
            switch ($role) {
                case 'admin':
                    $out['pending_enrollments'] = self::scalar(
                        "SELECT COUNT(*) FROM enrollment_applications WHERE status = 'pending'"
                    );
                    $out['pending_payments'] = self::scalar(
                        "SELECT COUNT(*) FROM payments WHERE status IN ('pending','processing')"
                    );
                    break;

                case 'registrar':
                    $out['pending_permits'] = self::scalar(
                        "SELECT COUNT(*) FROM exam_permits WHERE status = 'approved_accounting'"
                    );
                    $out['grades_awaiting'] = self::scalar(
                        "SELECT COUNT(*) FROM classroom_task_submissions WHERE status = 'graded' AND review_status = 'submitted'"
                    );
                    $out['pending_docs'] = self::scalar(
                        "SELECT COUNT(*) FROM academic_documents WHERE status = 'pending'"
                    );
                    $out['pending_sheets'] = self::scalar(
                        "SELECT COUNT(*) FROM grade_sheets WHERE status IN ('submitted','under_review')"
                    );
                    $out['pending_clearance_review'] = self::scalar(
                        "SELECT COUNT(*) FROM clearances WHERE status = 'issued' AND COALESCE(registrar_status,'pending') = 'pending'"
                    );
                    break;

                case 'accounting':
                    $out['pending_clearance'] = self::scalar(
                        "SELECT COUNT(*) FROM clearances WHERE financial_status IN ('pending','for_review')"
                    );
                    $out['pending_payments'] = self::scalar(
                        "SELECT COUNT(*) FROM payments WHERE status IN ('pending','processing')"
                    );
                    $out['pending_enroll'] = self::scalar(
                        "SELECT COUNT(*) FROM enrollment_applications WHERE status = 'pending'"
                    );
                    break;

                case 'teacher':
                    $t = Database::one('SELECT id FROM teachers WHERE user_id = ? LIMIT 1', [$userId]);
                    $tid = $t ? (int) $t['id'] : 0;
                    if ($tid > 0) {
                        $out['pending_grades'] = self::scalar(
                            "SELECT COUNT(*) FROM classroom_task_submissions cts
                              JOIN classroom_tasks ct ON ct.id = cts.task_id
                              JOIN subject_classrooms sc ON sc.id = ct.classroom_id
                             WHERE sc.teacher_id = ? AND cts.status IN ('submitted','late')",
                            [$tid]
                        );
                        $out['pending_clearances'] = self::scalar(
                            "SELECT COUNT(DISTINCT ci.clearance_id) FROM clearance_items ci
                              JOIN clearances c ON c.id = ci.clearance_id
                             WHERE ci.teacher_id = ? AND ci.is_signed = 0 AND c.status = 'pending'",
                            [$tid]
                        );
                        try {
                            $hasJR = Database::value("SHOW TABLES LIKE 'class_join_requests'");
                            $out['pending_join_requests'] = $hasJR ? self::scalar(
                                "SELECT COUNT(*) FROM class_join_requests jr
                                  JOIN subject_classrooms sc ON sc.id = jr.classroom_id
                                 WHERE sc.teacher_id = ? AND jr.status = 'pending'",
                                [$tid]
                            ) : 0;
                        } catch (Throwable $e) {
                            $out['pending_join_requests'] = 0;
                        }
                    } else {
                        $out['pending_grades'] = 0;
                        $out['pending_clearances'] = 0;
                        $out['pending_join_requests'] = 0;
                    }
                    $out['pending_permits'] = self::scalar(
                        "SELECT COUNT(*) FROM exam_permits WHERE status = 'approved_registrar'"
                    );
                    break;

                case 'student':
                    $s = Database::one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$userId]);
                    $sid = $s ? (int) $s['id'] : 0;
                    if ($sid > 0) {
                        $out['my_subjects'] = self::scalar(
                            "SELECT COUNT(*) FROM classroom_members WHERE student_id = ?",
                            [$sid]
                        );
                        $out['tasks_due'] = self::scalar(
                            "SELECT COUNT(*) FROM classroom_tasks ct
                              JOIN subject_classrooms sc ON sc.id = ct.classroom_id
                              JOIN classroom_members cm ON cm.classroom_id = sc.id
                             WHERE cm.student_id = ? AND ct.due_at IS NOT NULL AND ct.due_at > NOW()",
                            [$sid]
                        );
                    } else {
                        $out['my_subjects'] = 0;
                        $out['tasks_due'] = 0;
                    }
                    break;

                case 'parent':
                    $out['children'] = self::scalar(
                        "SELECT COUNT(*) FROM guardians g
                          JOIN guardian_students gs ON gs.guardian_id = g.id
                         WHERE g.user_id = ?",
                        [$userId]
                    );
                    break;

                default:
                    break;
            }
        } catch (Throwable $e) {
        }
        foreach ($out as $k => $v) $out[$k] = max(0, (int) $v);
        return $out;
    }

    private static function scalar(string $sql, array $params = []): int
    {
        try {
            return (int) (Database::value($sql, $params) ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
