<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use Throwable;

/**
 * Public homepage (marketing landing for guests).
 * Logged-in visitors are sent straight to their dashboard.
 * Now includes LIVE analytics: totals + K-4 breakdown.
 */
final class HomeController extends Controller
{
    public function index(array $params = []): void
    {
        $user = Auth::user();
        $role = Auth::role();

        // Live analytics — all queries are defensive (return 0/[] on error)
        $stats = [
            'total_users'    => self::scalar("SELECT COUNT(*) FROM users WHERE is_active=1"),
            'total_students' => self::scalar("SELECT COUNT(*) FROM students WHERE status='enrolled'"),
            'total_teachers' => self::scalar("SELECT COUNT(*) FROM teachers"),
            'total_parents'  => self::scalar("SELECT COUNT(*) FROM guardians"),
            'enrolled'       => self::scalar("SELECT COUNT(*) FROM students WHERE status='enrolled'"),
            'pending_enroll' => self::scalar("SELECT COUNT(*) FROM enrollment_applications WHERE status='pending'"),
        ];

        // K-4 breakdown: Kindergarten, Grade 1-4, plus College 1-4 for completeness
        // Uses grade_levels sort_order to keep natural order
        $k4 = [];
        try {
            $k4 = Database::all(
                "SELECT gl.code, gl.name, gl.category, COUNT(s.id) AS cnt
                   FROM grade_levels gl
                   LEFT JOIN students s ON s.grade_level_id = gl.id AND s.status='enrolled'
                  WHERE gl.code IN ('K','G1','G2','G3','G4','C1','C2','C3','C4')
                  GROUP BY gl.id, gl.code, gl.name, gl.category
                  ORDER BY gl.sort_order"
            );
        } catch (Throwable $e) { $k4 = []; }

        // Full K-College breakdown for chart
        $byLevel = [];
        try {
            $byLevel = Database::all(
                "SELECT gl.code, gl.name, COUNT(s.id) AS cnt
                   FROM grade_levels gl
                   LEFT JOIN students s ON s.grade_level_id = gl.id AND s.status='enrolled'
                  GROUP BY gl.id ORDER BY gl.sort_order"
            );
        } catch (Throwable $e) { $byLevel = []; }

        // Real school info: single-sourced from system_settings + DB so the
        // page can never show two conflicting addresses/phones again.
        $school = [
            'name' => 'Datamex College of Saint Adeline',
            'address' => 'Dasmarinas, Cavite, Philippines',
            'contact' => 'datamex82@gmail.com',
            'support' => 'support@datamex.edu.ph',
        ];
        try {
            foreach (['school_name', 'school_address', 'school_contact', 'support_email'] as $k) {
                $v = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", [$k]);
                if ($v !== null && trim((string) $v) !== '') {
                    if ($k === 'school_name') $school['name'] = trim((string) $v);
                    elseif ($k === 'school_address') $school['address'] = trim((string) $v);
                    elseif ($k === 'school_contact') $school['contact'] = trim((string) $v);
                    elseif ($k === 'support_email') $school['support'] = trim((string) $v);
                }
            }
        } catch (Throwable $e) {
        }
        $currentAy = null;
        $campuses = [];
        try {
            $currentAy = Database::one("SELECT school_year FROM academic_years WHERE is_current = 1 LIMIT 1");
        } catch (Throwable $e) {
        }
        try {
            $campuses = Database::all("SELECT code, name FROM campuses WHERE is_active = 1 ORDER BY name LIMIT 20");
        } catch (Throwable $e) {
        }

        // Latest public news (fail-open — homepage never breaks without blog tables)
        $latestNews = [];
        try {
            if (\App\Models\Blog::tableReady()) {
                $latestNews = \App\Models\Blog::publicList('', '', 1, 3)['rows'] ?? [];
            }
        } catch (Throwable $e) {
            $latestNews = [];
        }

        $this->view('home/index', [
            'title' => $school['name'],
            'stats' => $stats,
            'k4'    => $k4,
            'byLevel' => $byLevel,
            'user' => $user,
            'role' => $role,
            'school' => $school,
            'currentAy' => $currentAy ? (string) $currentAy['school_year'] : '',
            'campuses' => $campuses,
            'latestNews' => $latestNews,
        ], null);
    }

    private static function scalar(string $sql, array $params = []): int
    {
        try { return (int) Database::value($sql, $params); } catch (Throwable $e) { return 0; }
    }
}
