<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\NotificationController;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Admin-controlled Online Teachers Evaluation.
 * Admin opens/closes evaluation windows per AY + term + period.
 * Students may only submit while a window is open.
 */
final class EvaluationController extends Controller
{
    private static function ensureSchema(): void
    {
        try {
            Database::run(
                "CREATE TABLE IF NOT EXISTS `evaluation_periods` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `academic_year_id` INT UNSIGNED NOT NULL,
                  `term` ENUM('1st','2nd','Summer') NOT NULL DEFAULT '1st',
                  `period` ENUM('Prelim','Midterm','Prefinal','Finals') NOT NULL DEFAULT 'Midterm',
                  `status` ENUM('open','closed') NOT NULL DEFAULT 'closed',
                  `opens_at` DATETIME DEFAULT NULL,
                  `closes_at` DATETIME DEFAULT NULL,
                  `created_by` BIGINT UNSIGNED DEFAULT NULL,
                  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_eval_period` (`academic_year_id`,`term`,`period`),
                  KEY `idx_eval_period_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            storage_log('eval periods ensure failed: ' . $e->getMessage());
        }
        try {
            $has = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_evaluations' AND COLUMN_NAME = 'evaluation_period_id'"
            );
            if ($has === 0) {
                Database::run("ALTER TABLE `teacher_evaluations` ADD COLUMN `evaluation_period_id` INT UNSIGNED DEFAULT NULL AFTER `academic_year_id`");
            }
        } catch (Throwable $e) {
        }
    }

    public static function currentOpenPeriod(): ?array
    {
        self::ensureSchema();
        try {
            $row = Database::one(
                "SELECT ep.*, ay.school_year FROM evaluation_periods ep
                   JOIN academic_years ay ON ay.id = ep.academic_year_id
                  WHERE ep.status = 'open' ORDER BY ep.updated_at DESC LIMIT 1"
            );
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function latestPeriod(): ?array
    {
        self::ensureSchema();
        try {
            $row = Database::one(
                "SELECT ep.*, ay.school_year FROM evaluation_periods ep
                   JOIN academic_years ay ON ay.id = ep.academic_year_id
                  ORDER BY ay.school_year DESC, ep.updated_at DESC LIMIT 1"
            );
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        self::ensureSchema();
        $periods = [];
        try {
            $periods = Database::all(
                "SELECT ep.*, ay.school_year,
                        (SELECT COUNT(*) FROM teacher_evaluations te WHERE te.evaluation_period_id = ep.id) AS eval_count,
                        (SELECT ROUND(AVG(te.rating),2) FROM teacher_evaluations te WHERE te.evaluation_period_id = ep.id) AS avg_rating
                   FROM evaluation_periods ep
                   JOIN academic_years ay ON ay.id = ep.academic_year_id
                  ORDER BY ep.status DESC, ep.updated_at DESC LIMIT 50"
            );
        } catch (Throwable $e) {
            $periods = [];
        }
        $ays = [];
        try {
            $ays = Database::all("SELECT id, school_year, is_current FROM academic_years ORDER BY school_year DESC LIMIT 20");
        } catch (Throwable $e) {
        }
        $results = [];
        $selectedId = (int) ($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
        if ($selectedId > 0) {
            try {
                $hasPeriodCol = (int) Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_evaluations' AND COLUMN_NAME = 'evaluation_period_id'"
                );
                if ($hasPeriodCol > 0) {
                    $results = Database::all(
                        "SELECT TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS teacher,
                                sc.subject_code, sc.subject_title,
                                COUNT(te.id) AS eval_count, ROUND(AVG(te.rating),2) AS avg_rating
                           FROM teacher_evaluations te
                           JOIN teachers t ON t.id = te.teacher_id
                           LEFT JOIN users tu ON tu.id = t.user_id
                           LEFT JOIN subject_classrooms sc ON sc.id = te.class_offering_id
                          WHERE te.evaluation_period_id = ?
                          GROUP BY te.teacher_id, te.class_offering_id ORDER BY avg_rating DESC",
                        [$selectedId]
                    );
                } else {
                    $results = Database::all(
                        "SELECT TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS teacher,
                                sc.subject_code, sc.subject_title,
                                COUNT(te.id) AS eval_count, ROUND(AVG(te.rating),2) AS avg_rating
                           FROM teacher_evaluations te
                           JOIN teachers t ON t.id = te.teacher_id
                           LEFT JOIN users tu ON tu.id = t.user_id
                           LEFT JOIN subject_classrooms sc ON sc.id = te.class_offering_id
                          GROUP BY te.teacher_id, te.class_offering_id ORDER BY avg_rating DESC LIMIT 100"
                    );
                }
            } catch (Throwable $e) {
                $results = [];
            }
        }
        $this->view('admin/evaluations/index', [
            'title' => 'Online Teachers Evaluation',
            'periods' => $periods,
            'academicYears' => $ays,
            'results' => $results,
            'selectedId' => $selectedId,
        ]);
    }

    public function store(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        self::ensureSchema();
        $ayId = (int) ($_POST['academic_year_id'] ?? 0);
        $term = trim((string) ($_POST['term'] ?? '1st'));
        $period = trim((string) ($_POST['period'] ?? 'Midterm'));
        if (!in_array($term, ['1st', '2nd', 'Summer'], true)) $term = '1st';
        if (!in_array($period, ['Prelim', 'Midterm', 'Prefinal', 'Finals'], true)) $period = 'Midterm';
        if ($ayId <= 0) {
            try {
                $cur = Database::one("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
                $ayId = $cur ? (int) $cur['id'] : 0;
            } catch (Throwable $e) {
            }
        }
        if ($ayId <= 0) {
            set_flash('error', 'Select an academic year.');
            redirect('/admin/evaluations');
        }
        try {
            Database::run("UPDATE evaluation_periods SET status = 'closed' WHERE status = 'open'");
            Database::run(
                "INSERT INTO evaluation_periods (academic_year_id, term, period, status, opens_at, created_by)
                 VALUES (?, ?, ?, 'open', NOW(), ?)
                 ON DUPLICATE KEY UPDATE status = 'open', opens_at = NOW(), closes_at = NULL, updated_at = NOW()",
                [$ayId, $term, $period, (int) Auth::id()]
            );
            NotificationController::pushToStudents('Online Teachers Evaluation is OPEN', 'Rate your teachers for this term — open your evaluation page before it closes.', 'grade', '/student/evaluations');
            set_flash('success', 'Online Teachers Evaluation is now OPEN for students.');
        } catch (Throwable $e) {
            storage_log('eval open failed: ' . $e->getMessage());
            set_flash('error', 'Could not open evaluation.');
        }
        redirect('/admin/evaluations');
    }

    public function open(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        self::ensureSchema();
        $id = (int) ($params['id'] ?? 0);
        try {
            Database::run("UPDATE evaluation_periods SET status = 'closed' WHERE status = 'open'");
            Database::run("UPDATE evaluation_periods SET status = 'open', opens_at = NOW(), closes_at = NULL WHERE id = ?", [$id]);
            NotificationController::pushToStudents('Online Teachers Evaluation is OPEN', 'Rate your teachers for this term — open your evaluation page before it closes.', 'grade', '/student/evaluations');
            set_flash('success', 'Evaluation window opened.');
        } catch (Throwable $e) {
            set_flash('error', 'Could not open window.');
        }
        redirect('/admin/evaluations');
    }

    public function close(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        self::ensureSchema();
        $id = (int) ($params['id'] ?? 0);
        try {
            Database::run("UPDATE evaluation_periods SET status = 'closed', closes_at = NOW() WHERE id = ?", [$id]);
            NotificationController::pushToStudents('Online Teachers Evaluation is CLOSED', 'The evaluation window has closed. Thank you to everyone who participated.', 'warning', '/student/evaluations');
            set_flash('success', 'Evaluation window closed. Students now see the closed notice.');
        } catch (Throwable $e) {
            set_flash('error', 'Could not close window.');
        }
        redirect('/admin/evaluations');
    }
}
