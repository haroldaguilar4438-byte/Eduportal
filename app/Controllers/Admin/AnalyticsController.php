<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use Throwable;

/**
 * School Analytics (admin-only, READ-ONLY).
 *
 * One central builder over REAL tables — no sample data, no writes.
 * Used for the initial server render (DashboardController) and for the
 * lightweight refresh endpoint below (dashboard "Refresh Analytics" +
 * EduLive notification hook). Aggregate COUNT/GROUP BY only; no PII lists.
 *
 * Filters (all optional, validated):
 *   ay_id      academic_years.id (default: current AY)
 *   period     semester code ('1st','2nd','Summer') or basic-ed period_code
 *   band       basic | shs | college (grade_levels.category groups)
 *   program_id programs.id
 *
 * Scope labels: period-scoped cards say so; trailing-6-month trend and
 * all-time operational snapshots are labeled as such. A failed query yields
 * null (rendered "unavailable") — never a fake zero.
 */
final class AnalyticsController extends Controller
{
    public const OPEN_ENROLL = ['pending', 'accounting_approved', 'awaiting_payment', 'action_required'];

    /** @return array{filters:array,options:array,data:array<string,mixed>} */
    public static function build(array $raw = []): array
    {
        $f = self::filters($raw);
        $d = [];
        $d['overview'] = self::overview($f);
        $d['enrollment'] = self::enrollment($f);
        $d['registration'] = self::registration();
        $d['users'] = self::users();
        $d['programs'] = self::programs($f);
        $d['levels'] = self::levels($f);
        $d['clearance'] = self::clearance($f);
        $d['documents'] = self::documents();
        $d['payments'] = self::payments();
        $d['grades'] = self::grades($f);
        $d['classes'] = self::classes();
        $d['activity'] = self::activity();
        $d['attention'] = self::attention();
        return ['filters' => $f, 'options' => self::options(), 'data' => $d];
    }

    /** JSON refresh endpoint (admin only). Same shape as build(). */
    public function data(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->json(self::build($_GET));
    }

    /* ------------------------------ filters ------------------------------ */

    /** @return array{ay_id:int,ay_label:string,period:string,band:string,program_id:int} */
    public static function filters(array $raw): array
    {
        $ayId = max(0, (int) ($raw['ay_id'] ?? 0));
        if ($ayId <= 0) {
            try {
                $cur = Database::one("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1")
                    ?? Database::one("SELECT id FROM academic_years ORDER BY id DESC LIMIT 1");
                $ayId = $cur ? (int) $cur['id'] : 0;
            } catch (Throwable $e) {
                $ayId = 0;
            }
        }
        $ayLabel = '';
        if ($ayId > 0) {
            try {
                $r = Database::one("SELECT school_year FROM academic_years WHERE id = ? LIMIT 1", [$ayId]);
                $ayLabel = $r ? (string) $r['school_year'] : '';
                if ($ayLabel === '') $ayId = 0;
            } catch (Throwable $e) {
                $ayId = 0;
            }
        }
        $period = substr(trim((string) ($raw['period'] ?? '')), 0, 20);
        $band = strtolower(trim((string) ($raw['band'] ?? '')));
        if (!in_array($band, ['', 'basic', 'shs', 'college'], true)) $band = '';
        return [
            'ay_id' => $ayId, 'ay_label' => $ayLabel, 'period' => $period,
            'band' => $band, 'program_id' => max(0, (int) ($raw['program_id'] ?? 0)),
        ];
    }

    public static function options(): array
    {
        $ays = self::rows("SELECT id, school_year, is_current FROM academic_years ORDER BY school_year DESC LIMIT 20");
        $programs = self::rows("SELECT id, code, name FROM programs WHERE is_active = 1 ORDER BY name LIMIT 100");
        $periods = [];
        try {
            if (class_exists(\App\Core\AcademicPeriod::class)) {
                foreach (['basic', 'shs', 'college'] as $b) {
                    foreach (\App\Core\AcademicPeriod::periodsFor($b) as $p) $periods[$p['code']] = $p['name'];
                }
            }
        } catch (Throwable $e) {
        }
        if ($periods === []) $periods = ['1st' => '1st Semester', '2nd' => '2nd Semester', 'Summer' => 'Summer'];
        return ['years' => $ays, 'programs' => $programs, 'periods' => $periods];
    }

    /**
     * Shared enrollment-application scope (AY + period + band + program).
     * @return array{0:string,1:array} [where fragment (with leading AND), bind]
     */
    private static function eaScope(array $f, string $alias = 'ea'): array
    {
        $w = [];
        $b = [];
        if ($f['ay_id'] > 0) {
            $w[] = "{$alias}.academic_year_id = ?";
            $b[] = $f['ay_id'];
        }
        if ($f['period'] !== '') {
            $w[] = "({$alias}.semester = ? OR {$alias}.period_code = ?)";
            $b[] = $f['period'];
            $b[] = $f['period'];
        }
        if ($f['program_id'] > 0) {
            $w[] = "{$alias}.program_id = ?";
            $b[] = $f['program_id'];
        }
        if ($f['band'] !== '') {
            $cats = $f['band'] === 'basic'
                ? ['kindergarten', 'elementary', 'junior_high']
                : ($f['band'] === 'shs' ? ['senior_high'] : ['college']);
            $w[] = "gl.category IN (" . implode(',', array_fill(0, count($cats), '?')) . ")";
            foreach ($cats as $c) $b[] = $c;
        }
        $needsGl = $f['band'] !== '';
        return [$needsGl, $w === [] ? '' : ' AND ' . implode(' AND ', $w), $b];
    }

    /* ------------------------------ sections ----------------------------- */

    private static function overview(array $f): array
    {
        [$needsGl, $scope, $bind] = self::eaScope($f);
        $join = $needsGl ? " JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id" : "";
        $open = "'" . implode("','", self::OPEN_ENROLL) . "'";
        return [
            'total_students' => self::c("SELECT COUNT(*) FROM students"),
            'enrolled' => self::enrolledStudents($f, "ea.status = 'approved'"),
            'enrolling' => self::enrollingStudents($f),
            'registered' => self::c(
                "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name IN ('student','parent') AND u.is_email_verified = 1"
            ),
            'transferred' => self::c("SELECT COUNT(*) FROM students WHERE status = 'transferred'"),
            'transferee_admissions' => self::c(
                "SELECT COUNT(*) FROM enrollment_applications ea{$join} WHERE ea.new_or_returning = 'transferee' AND ea.status = 'approved'{$scope}", $bind
            ),
            'active_users' => self::c("SELECT COUNT(*) FROM users WHERE status = 'active' AND is_active = 1"),
            'inactive_users' => self::c("SELECT COUNT(*) FROM users WHERE NOT (status = 'active' AND is_active = 1)"),
            'orphan_apps' => self::c("SELECT COUNT(*) FROM enrollment_applications WHERE student_id IS NULL AND user_id IS NULL"),
        ];
    }

    /**
     * DISTINCT students behind approved applications in scope, resolved via
     * student_id or the applicant's user account. One student with several
     * historical approvals in the period counts once; applications linked to
     * no account are excluded here and surfaced as orphan_apps instead.
     */
    private static function enrolledStudents(array $f, string $statusCond): int|string|null
    {
        [$needsGl, $scope, $bind] = self::eaScope($f);
        $join = $needsGl ? " JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id" : "";
        $v = self::c(
            "SELECT COUNT(DISTINCT s.id) FROM enrollment_applications ea{$join}
              LEFT JOIN students s ON s.id = ea.student_id OR s.user_id = ea.user_id
              WHERE {$statusCond} AND s.id IS NOT NULL{$scope}", $bind
        );
        return $v === null ? null : (int) $v;
    }

    private static function enrollingStudents(array $f): int|string|null
    {
        $open = "'" . implode("','", self::OPEN_ENROLL) . "'";
        return self::enrolledStudents($f, "ea.status IN ({$open})");
    }

    private static function enrollment(array $f): array
    {
        [$needsGl, $scope, $bind] = self::eaScope($f);
        $join = $needsGl ? " JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id" : "";
        $byStatus = self::rows(
            "SELECT ea.status, COUNT(*) c FROM enrollment_applications ea{$join} WHERE 1=1{$scope} GROUP BY ea.status ORDER BY c DESC", $bind
        );
        $open = "'" . implode("','", self::OPEN_ENROLL) . "'";
        $trend = self::rows(
            "SELECT DATE_FORMAT(ea.created_at,'%Y-%m') m, COUNT(*) c FROM enrollment_applications ea"
            . ($needsGl ? " JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id" : "")
            . " WHERE ea.created_at >= DATE_FORMAT(NOW() - INTERVAL 5 MONTH, '%Y-%m-01')"
            . $scope . " GROUP BY m ORDER BY m ASC", $bind
        );
        return [
            'by_status' => $byStatus,
            'open' => self::c("SELECT COUNT(*) FROM enrollment_applications ea{$join} WHERE ea.status IN ({$open}){$scope}", $bind),
            'approved' => self::c("SELECT COUNT(*) FROM enrollment_applications ea{$join} WHERE ea.status = 'approved'{$scope}", $bind),
            'rejected' => self::c("SELECT COUNT(*) FROM enrollment_applications ea{$join} WHERE ea.status = 'rejected'{$scope}", $bind),
            'trend' => self::fillMonths($trend),
        ];
    }

    private static function registration(): array
    {
        return [
            'by_role' => self::rows(
                "SELECT r.name role, COUNT(u.id) c FROM roles r LEFT JOIN users u ON u.role_id = r.id GROUP BY r.id, r.name ORDER BY r.id"
            ),
            'verified' => self::c("SELECT COUNT(*) FROM users WHERE is_email_verified = 1"),
            'pending_verify' => self::c("SELECT COUNT(*) FROM users WHERE is_email_verified = 0"),
            'active' => self::c("SELECT COUNT(*) FROM users WHERE status = 'active' AND is_active = 1"),
            'inactive' => self::c("SELECT COUNT(*) FROM users WHERE NOT (status = 'active' AND is_active = 1)"),
            'new_30d' => self::c("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY"),
        ];
    }

    private static function users(): array
    {
        return [
            'total' => self::c("SELECT COUNT(*) FROM users"),
            'logins_today' => self::c("SELECT COUNT(*) FROM users WHERE last_login_at >= CURDATE()"),
            'active_7d' => self::c("SELECT COUNT(*) FROM users WHERE last_login_at >= NOW() - INTERVAL 7 DAY"),
            'active_30d' => self::c("SELECT COUNT(*) FROM users WHERE last_login_at >= NOW() - INTERVAL 30 DAY"),
            'never_logged' => self::c("SELECT COUNT(*) FROM users WHERE last_login_at IS NULL AND status = 'active'"),
        ];
    }

    private static function programs(array $f): array
    {
        $cats = null;
        if ($f['band'] === 'basic') $cats = ['kindergarten', 'elementary', 'junior_high'];
        elseif ($f['band'] === 'shs') $cats = ['senior_high'];
        elseif ($f['band'] === 'college') $cats = ['college'];
        return self::rows(
            "SELECT p.code, p.name, COUNT(DISTINCT CASE WHEN ea.status = 'approved' AND s.id IS NOT NULL THEN s.id END) enrolled,
                    COUNT(CASE WHEN ea.status IN ('" . implode("','", self::OPEN_ENROLL) . "') THEN 1 END) enrolling
               FROM programs p LEFT JOIN enrollment_applications ea ON ea.program_id = p.id"
            . ($f['ay_id'] > 0 ? " AND ea.academic_year_id = " . (int) $f['ay_id'] : "")
            . ($f['period'] !== '' ? " AND (ea.semester = " . self::q($f['period']) . " OR ea.period_code = " . self::q($f['period']) . ")" : "")
            . ($cats !== null ? " LEFT JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id" : "")
            . " LEFT JOIN students s ON s.id = ea.student_id OR s.user_id = ea.user_id"
            . " WHERE p.is_active = 1"
            . ($cats !== null ? " AND (ea.id IS NULL OR gl.category IN ('" . implode("','", $cats) . "'))" : "")
            . " GROUP BY p.id, p.code, p.name ORDER BY enrolled DESC, p.name ASC LIMIT 20"
        );
    }

    private static function levels(array $f): array
    {
        [$needsGl, $scope, $bind] = self::eaScope($f);
        $rows = self::rows(
            "SELECT gl.code, gl.name, gl.category, COUNT(DISTINCT CASE WHEN ea.status = 'approved' AND st.id IS NOT NULL THEN st.id END) enrolled
               FROM grade_levels gl LEFT JOIN enrollment_applications ea ON ea.grade_level_applied_id = gl.id"
            . ($f['ay_id'] > 0 ? " AND ea.academic_year_id = " . (int) $f['ay_id'] : "")
            . ($f['period'] !== '' ? " AND (ea.semester = " . self::q($f['period']) . " OR ea.period_code = " . self::q($f['period']) . ")" : "")
            . ($f['program_id'] > 0 ? " AND ea.program_id = " . (int) $f['program_id'] : "")
            . " LEFT JOIN students st ON st.id = ea.student_id OR st.user_id = ea.user_id"
            . " GROUP BY gl.id, gl.code, gl.name, gl.category ORDER BY gl.sort_order ASC LIMIT 40"
        );
        $bands = ['basic' => 0, 'shs' => 0, 'college' => 0];
        try {
            foreach ($rows as $r) {
                $b = class_exists(\App\Core\AcademicPeriod::class)
                    ? \App\Core\AcademicPeriod::bandForCategory($r['category'] ?? null) : 'college';
                if (isset($bands[$b])) $bands[$b] += (int) ($r['enrolled'] ?? 0);
            }
        } catch (Throwable $e) {
        }
        if ($f['band'] !== '') {
            $want = $f['band'] === 'basic'
                ? ['kindergarten', 'elementary', 'junior_high']
                : ($f['band'] === 'shs' ? ['senior_high'] : ['college']);
            $rows = array_values(array_filter($rows, static fn($r) => in_array($r['category'] ?? '', $want, true)));
        }
        return ['rows' => $rows, 'bands' => $bands];
    }

    private static function clearance(array $f): array
    {
        $ay = $f['ay_id'] > 0 ? " AND academic_year_id = " . (int) $f['ay_id'] : "";
        $per = $f['period'] !== '' ? " AND semester = " . self::q($f['period']) : "";
        return [
            'pending' => self::c("SELECT COUNT(*) FROM clearances WHERE status = 'pending'{$ay}{$per}"),
            'signed' => self::c("SELECT COUNT(*) FROM clearances WHERE status = 'signed'{$ay}{$per}"),
            'issued' => self::c("SELECT COUNT(*) FROM clearances WHERE status = 'issued'{$ay}{$per}"),
            'used' => self::c("SELECT COUNT(*) FROM clearances WHERE status = 'used'{$ay}{$per}"),
            'teachers_pending' => self::c("SELECT COUNT(DISTINCT clearance_id) FROM clearance_items WHERE is_signed = 0"),
            'accounting_pending' => self::c("SELECT COUNT(*) FROM clearances WHERE financial_status IN ('pending','for_review'){$ay}{$per}"),
            'registrar_pending' => self::c("SELECT COUNT(*) FROM clearances WHERE status = 'issued' AND COALESCE(registrar_status,'pending') = 'pending'{$ay}{$per}"),
        ];
    }

    private static function documents(): array
    {
        return [
            'academic' => self::rows("SELECT status, COUNT(*) c FROM academic_documents GROUP BY status ORDER BY c DESC"),
            'pending_review' => self::c("SELECT COUNT(*) FROM academic_documents WHERE status = 'pending'"),
            'needs_resub' => self::c("SELECT COUNT(*) FROM student_documents WHERE status = 'needs_resubmission'"),
            'verified_files' => self::c("SELECT COUNT(*) FROM student_documents WHERE status = 'verified'"),
        ];
    }

    private static function payments(): array
    {
        return [
            'by_status' => self::rows("SELECT status, COUNT(*) c, COALESCE(SUM(amount),0) s FROM payments GROUP BY status ORDER BY c DESC"),
            'month_paid' => self::c("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"),
            'outstanding' => self::c(
                "SELECT COALESCE((SELECT SUM(amount) FROM student_charges),0) - COALESCE((SELECT SUM(pa.amount_applied) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE p.status = 'paid'),0)"
            ),
        ];
    }

    private static function grades(array $f): array
    {
        $ay = $f['ay_id'] > 0 ? " AND academic_year_id = " . (int) $f['ay_id'] : "";
        return [
            'by_status' => self::rows("SELECT status, COUNT(*) c FROM grade_sheets WHERE 1=1{$ay} GROUP BY status"),
            'pending_review' => self::c("SELECT COUNT(*) FROM grade_sheets WHERE status IN ('submitted','under_review'){$ay}"),
        ];
    }

    private static function classes(): array
    {
        $full = 0;
        $empty = 0;
        try {
            $rows = Database::all(
                "SELECT sc.id, sc.max_students, (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = sc.id) members
                   FROM subject_classrooms sc WHERE sc.is_active = 1 LIMIT 500"
            );
            foreach ($rows as $r) {
                $m = (int) ($r['members'] ?? 0);
                if ($m <= 0) $empty++;
                if ($m >= max(1, (int) ($r['max_students'] ?? 0))) $full++;
            }
        } catch (Throwable $e) {
            return ['active' => null, 'unassigned' => null, 'full' => null, 'empty' => null];
        }
        return [
            'active' => self::c("SELECT COUNT(*) FROM subject_classrooms WHERE is_active = 1"),
            'unassigned' => self::c("SELECT COUNT(*) FROM subject_classrooms WHERE is_active = 1 AND teacher_id IS NULL"),
            'full' => $full,
            'empty' => $empty,
        ];
    }

    private static function activity(): array
    {
        $rows = self::rows(
            "SELECT a.action, a.entity_type, a.created_at, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) actor, r.name role
               FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id LEFT JOIN roles r ON r.id = u.role_id
              ORDER BY a.id DESC LIMIT 10"
        );
        return ['recent' => $rows];
    }

    private static function attention(): array
    {
        $items = [];
        $push = function (?int $n, string $label, string $link) use (&$items) {
            if ($n !== null && $n > 0) $items[] = ['count' => $n, 'label' => $label, 'link' => $link];
        };
        $push(self::c("SELECT COUNT(*) FROM enrollment_applications WHERE status = 'pending'"), 'Pending enrollment applications', '/admin/enrollments');
        $push(self::c("SELECT COUNT(*) FROM enrollment_applications WHERE status = 'action_required'"), 'Applications needing applicant action', '/admin/enrollments');
        $push(self::c("SELECT COUNT(*) FROM payments WHERE status IN ('pending','processing')"), 'Payments awaiting confirmation', '/admin/payments');
        $push(self::c("SELECT COUNT(*) FROM payments WHERE status = 'failed'"), 'Failed payments', '/admin/payments');
        $push(self::c("SELECT COUNT(*) FROM clearances WHERE status = 'pending'"), 'Clearances awaiting signatures', '/admin/clearances');
        $push(self::c("SELECT COUNT(*) FROM academic_documents WHERE status = 'pending'"), 'Document requests awaiting review', '/registrar/documents');
        $push(self::c("SELECT COUNT(*) FROM grade_sheets WHERE status IN ('submitted','under_review')"), 'Grade sheets awaiting review', '/registrar/sheets');
        $push(self::c("SELECT COUNT(*) FROM subject_classrooms WHERE is_active = 1 AND teacher_id IS NULL"), 'Active subjects with no teacher', '/admin/class-offerings');
        $push(self::c("SELECT COUNT(*) FROM student_documents WHERE status = 'needs_resubmission'"), 'Documents needing resubmission', '/accounting/documents');
        $push(self::c("SELECT COUNT(*) FROM enrollment_applications WHERE student_id IS NULL AND user_id IS NULL"), 'Applications with no linked account (data cleanup)', '/admin/enrollments');
        return $items;
    }

    /* ------------------------------- helpers ----------------------------- */

    /** @return int|null */
    private static function c(string $sql, array $params = [])
    {
        try {
            $v = Database::value($sql, $params);
            return $v === null ? 0 : (int) $v;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function rows(string $sql, array $params = []): array
    {
        try {
            return Database::all($sql, $params);
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function q(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }

    /** Zero-fill trailing 6 month buckets (YYYY-MM) from sparse trend rows. */
    private static function fillMonths(array $rows): array
    {
        $have = [];
        foreach ($rows as $r) {
            if (!empty($r['m'])) $have[(string) $r['m']] = (int) ($r['c'] ?? 0);
        }
        $out = [];
        $d = new \DateTime('first day of this month');
        $d->modify('-5 months');
        for ($i = 0; $i < 6; $i++) {
            $k = $d->format('Y-m');
            $out[] = ['m' => $k, 'label' => $d->format('M Y'), 'c' => $have[$k] ?? 0];
            $d->modify('+1 month');
        }
        return $out;
    }
}
