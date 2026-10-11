<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\AcademicPeriod;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Admin-managed academic calendar (centralized period configuration).
 *
 * Bands (from existing grade_levels.category — no duplicate level defs):
 *   basic   = Kindergarten–Grade 10 → Quarter or Trimester/Term (switchable)
 *   shs     = Grade 11–12           → 1st / 2nd Semester
 *   college = 1st–4th Year          → 1st / 2nd / Summer (existing workflow)
 *
 * Deactivation/deletion is blocked while a period is referenced by
 * enrollments, offerings, clearances, or grade sheets (history preserved).
 */
final class AcademicCalendarController extends Controller
{
    private function tableReady(): bool
    {
        try {
            return (bool) Database::value("SHOW TABLES LIKE 'academic_periods'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Reference counts so Admin sees what is safe to change. */
    private function usageFor(string $band, string $code): array
    {
        $use = ['enrollments' => 0, 'offerings' => 0, 'clearances' => 0, 'sheets' => 0];
        try {
            if ($band === AcademicPeriod::BAND_BASIC) {
                $use['enrollments'] = (int) Database::value(
                    "SELECT COUNT(*) FROM enrollment_applications WHERE period_code = ?", [$code]
                );
                try {
                    $use['offerings'] = (int) Database::value(
                        "SELECT COUNT(*) FROM subject_classrooms WHERE period_code = ?", [$code]
                    );
                } catch (Throwable $e) {
                }
            } else {
                $use['enrollments'] = (int) Database::value(
                    "SELECT COUNT(*) FROM enrollment_applications WHERE semester = ? AND period_code IS NULL", [$code]
                );
                $use['enrollments'] += (int) Database::value(
                    "SELECT COUNT(*) FROM enrollment_applications ea JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id WHERE ea.semester = ? AND gl.category IN ('senior_high','college') AND ea.period_code IS NOT NULL", [$code]
                );
                try {
                    $use['offerings'] = (int) Database::value(
                        "SELECT COUNT(*) FROM subject_classrooms WHERE semester = ? AND (period_code IS NULL OR period_code = '')", [$code]
                    );
                } catch (Throwable $e) {
                }
            }
            $use['clearances'] = (int) Database::value(
                "SELECT COUNT(*) FROM clearances WHERE semester = ?", [$code]
            );
            $use['sheets'] = (int) Database::value(
                "SELECT COUNT(*) FROM grade_sheets WHERE semester = ?", [$code]
            );
        } catch (Throwable $e) {
        }
        return $use;
    }

    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        if (!$this->tableReady()) {
            set_flash('error', 'Academic calendar is not installed — import database/patch_academic_periods.sql first.');
            redirect('/dashboard');
        }
        $bands = [AcademicPeriod::BAND_BASIC, AcademicPeriod::BAND_SHS, AcademicPeriod::BAND_COLLEGE];
        $data = [];
        foreach ($bands as $band) {
            $rows = AcademicPeriod::allPeriods($band);
            foreach ($rows as &$r) {
                $r['usage'] = $this->usageFor($band, (string) $r['code']);
                $r['used'] = array_sum($r['usage']) > 0;
            }
            unset($r);
            $data[$band] = [
                'label' => AcademicPeriod::bandLabel($band),
                'noun' => AcademicPeriod::noun($band),
                'periods' => $rows,
            ];
        }
        $this->view('admin/academic_calendar/index', [
            'title' => 'Academic Calendar',
            'bands' => $data,
            'basicStructure' => AcademicPeriod::basicStructure(),
        ]);
    }

    public function setStructure(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        if (!$this->tableReady()) {
            set_flash('error', 'Academic calendar is not installed.');
            redirect('/admin/academic-calendar');
        }
        $to = trim((string) ($_POST['structure'] ?? ''));
        if (!in_array($to, ['quarter', 'trimester'], true)) {
            set_flash('error', 'Invalid structure.');
            redirect('/admin/academic-calendar');
        }
        try {
            Database::transaction(static function () use ($to) {
                Database::run(
                    "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'basic_structure'",
                    [$to]
                );
                if ((int) Database::value("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'basic_structure'") === 0) {
                    Database::insert('system_settings', [
                        'setting_key' => 'basic_structure', 'setting_value' => $to, 'setting_group' => 'academic',
                    ]);
                }
                // Flip the SEEDED sets only (Q* vs T*); custom Admin rows are untouched.
                if ($to === 'trimester') {
                    Database::run("UPDATE academic_periods SET is_active = 0 WHERE band = 'basic' AND code LIKE 'Q%'");
                    Database::run("UPDATE academic_periods SET is_active = 1 WHERE band = 'basic' AND code LIKE 'T%'");
                } else {
                    Database::run("UPDATE academic_periods SET is_active = 1 WHERE band = 'basic' AND code LIKE 'Q%'");
                    Database::run("UPDATE academic_periods SET is_active = 0 WHERE band = 'basic' AND code LIKE 'T%'");
                }
            });
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'admin.calendar_structure',
                'entity_type' => 'academic_periods', 'entity_id' => 0,
                'new_values_json' => json_encode(['basic_structure' => $to]), 'ip_address' => client_ip(),
            ]);
            $noun = $to === 'trimester' ? 'Terms (T1–T3)' : 'Quarters (Q1–Q4)';
            set_flash('success', 'Basic Education calendar switched to ' . $noun . '. In-use periods were left untouched.');
        } catch (Throwable $e) {
            storage_log('calendar structure failed: ' . $e->getMessage());
            set_flash('error', 'Could not switch structure.');
        }
        redirect('/admin/academic-calendar');
    }

    public function toggle(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        $row = $this->tableReady() ? Database::one("SELECT * FROM academic_periods WHERE id = ? LIMIT 1", [$id]) : null;
        if (!$row) {
            set_flash('error', 'Period not found.');
            redirect('/admin/academic-calendar');
        }
        $to = (int) ($row['is_active'] ?? 0) === 1 ? 0 : 1;
        if ($to === 0) {
            $use = $this->usageFor((string) $row['band'], (string) $row['code']);
            if (array_sum($use) > 0) {
                set_flash('error', $row['code'] . ' is referenced by existing records (enrollments ' . $use['enrollments'] . ', offerings ' . $use['offerings'] . ', clearances ' . $use['clearances'] . ', sheets ' . $use['sheets'] . ') and cannot be deactivated — history is preserved.');
                redirect('/admin/academic-calendar');
            }
        }
        Database::run("UPDATE academic_periods SET is_active = ? WHERE id = ?", [$to, $id]);
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'admin.calendar_toggle',
            'entity_type' => 'academic_periods', 'entity_id' => $id,
            'new_values_json' => json_encode(['code' => $row['code'], 'is_active' => $to]), 'ip_address' => client_ip(),
        ]);
        set_flash('success', $row['code'] . ' ' . ($to === 1 ? 'activated.' : 'deactivated.'));
        redirect('/admin/academic-calendar');
    }

    public function store(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        if (!$this->tableReady()) {
            set_flash('error', 'Academic calendar is not installed.');
            redirect('/admin/academic-calendar');
        }
        $band = trim((string) ($_POST['band'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!in_array($band, [AcademicPeriod::BAND_BASIC, AcademicPeriod::BAND_SHS, AcademicPeriod::BAND_COLLEGE], true)) {
            set_flash('error', 'Select a valid education band.');
            redirect('/admin/academic-calendar');
        }
        if (!preg_match('/^[A-Z0-9]{1,20}$/', $code)) {
            set_flash('error', 'Period code must be 1–20 letters/digits (e.g. Q1, T1, 1st).');
            redirect('/admin/academic-calendar');
        }
        if ($name === '' || mb_strlen($name) > 40) {
            set_flash('error', 'Give the period a display name (max 40 chars, e.g. Quarter 1).');
            redirect('/admin/academic-calendar');
        }
        try {
            $maxSort = (int) Database::value("SELECT COALESCE(MAX(sort_order),0) FROM academic_periods WHERE band = ?", [$band]);
            Database::insert('academic_periods', [
                'band' => $band, 'code' => $code, 'name' => mb_substr($name, 0, 40),
                'sort_order' => $maxSort + 10, 'is_active' => 1,
            ]);
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'admin.calendar_added',
                'entity_type' => 'academic_periods', 'entity_id' => 0,
                'new_values_json' => json_encode(['band' => $band, 'code' => $code, 'name' => $name]), 'ip_address' => client_ip(),
            ]);
            set_flash('success', $code . ' added to ' . AcademicPeriod::bandLabel($band) . ' and activated.');
        } catch (Throwable $e) {
            if ($e instanceof \PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
                set_flash('error', $code . ' already exists for this band.');
            } else {
                storage_log('calendar add failed: ' . $e->getMessage());
                set_flash('error', 'Could not add period.');
            }
        }
        redirect('/admin/academic-calendar');
    }

    public function destroy(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        $row = $this->tableReady() ? Database::one("SELECT * FROM academic_periods WHERE id = ? LIMIT 1", [$id]) : null;
        if (!$row) {
            set_flash('error', 'Period not found.');
            redirect('/admin/academic-calendar');
        }
        $use = $this->usageFor((string) $row['band'], (string) $row['code']);
        if (array_sum($use) > 0) {
            set_flash('error', $row['code'] . ' is referenced by existing records and cannot be deleted — history is preserved. Deactivate it instead once unused.');
            redirect('/admin/academic-calendar');
        }
        Database::run("DELETE FROM academic_periods WHERE id = ?", [$id]);
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'admin.calendar_deleted',
            'entity_type' => 'academic_periods', 'entity_id' => $id,
            'old_values_json' => json_encode(['band' => $row['band'], 'code' => $row['code']]), 'ip_address' => client_ip(),
        ]);
        set_flash('success', $row['code'] . ' removed.');
        redirect('/admin/academic-calendar');
    }
}
