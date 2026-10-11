<?php
declare(strict_types=1);
namespace App\Controllers\Admin;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;
/**
 * Tuition payment-plan configuration (admin only).
 * One active plan at a time: total tuition, required downpayment,
 * installment months, optional fixed monthly amount (else auto).
 */
final class TuitionPlanController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        $plans = [];
        try {
            $plans = Database::all("SELECT * FROM tuition_plans ORDER BY is_active DESC, id DESC LIMIT 20");
        } catch (Throwable $e) {
            set_flash('error', 'Tuition plan tables are missing — import database/patch_tuition_plans.sql first.');
        }
        $stats = ['full' => 0, 'monthly' => 0, 'collected' => 0.0, 'receivable' => 0.0];
        try {
            $stats['full'] = (int) Database::value("SELECT COUNT(*) FROM enrollment_payment_plans WHERE plan_type = 'full'");
            $stats['monthly'] = (int) Database::value("SELECT COUNT(*) FROM enrollment_payment_plans WHERE plan_type = 'monthly'");
            $stats['collected'] = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid' AND charge_kind IS NOT NULL");
            $stats['receivable'] = (float) Database::value(
                "SELECT COALESCE(SUM(epp.total_tuition),0) - COALESCE((SELECT SUM(p.amount) FROM payments p JOIN enrollment_payment_plans e2 ON e2.application_id = p.enrollment_application_id WHERE p.status = 'paid'),0)
                   FROM enrollment_payment_plans epp WHERE epp.plan_type IS NOT NULL"
            );
            if ($stats['receivable'] < 0) $stats['receivable'] = 0.0;
        } catch (Throwable $e) {
        }
        $this->view('admin/tuition_plans/index', ['title' => 'Tuition Payment Plans', 'plans' => $plans, 'stats' => $stats]);
    }

    public function store(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $name = trim((string) ($_POST['name'] ?? 'Default tuition'));
        $total = round((float) ($_POST['total_tuition'] ?? 0), 2);
        $down = round((float) ($_POST['downpayment_amount'] ?? 0), 2);
        $months = (int) ($_POST['installment_months'] ?? 0);
        $monthlyRaw = trim((string) ($_POST['monthly_amount'] ?? ''));
        $monthly = $monthlyRaw === '' ? null : round((float) $monthlyRaw, 2);
        $errors = [];
        if ($name === '') $errors[] = 'Plan name is required.';
        if ($total <= 0) $errors[] = 'Total tuition must be greater than zero.';
        if ($down <= 0) $errors[] = 'Required downpayment must be greater than zero.';
        if ($down >= $total) $errors[] = 'Downpayment must be less than the total tuition.';
        if ($months < 1 || $months > 24) $errors[] = 'Installments must be between 1 and 24 months.';
        if ($monthly !== null) {
            if ($monthly <= 0) $errors[] = 'Monthly amount must be positive (or blank for automatic).';
            elseif ($monthly > ($total - $down)) $errors[] = 'Monthly amount cannot exceed the remaining balance.';
            elseif ($monthly * ($months - 1) >= ($total - $down)) $errors[] = 'Monthly amount is too high — the final installment would be zero or negative. Lower it or add months.';
        }
        if ($errors !== []) {
            foreach ($errors as $e) set_flash('error', $e);
            redirect('/admin/tuition-plans');
        }
        try {
            Database::transaction(function () use ($name, $total, $down, $months, $monthly) {
                Database::run("UPDATE tuition_plans SET is_active = 0 WHERE is_active = 1");
                Database::insert('tuition_plans', [
                    'name' => mb_substr($name, 0, 80), 'total_tuition' => $total,
                    'downpayment_amount' => $down, 'installment_months' => $months,
                    'monthly_amount' => $monthly, 'is_active' => 1,
                ]);
            });
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'tuition.plan_saved', 'entity_type' => 'tuition_plans', 'entity_id' => 0, 'new_values_json' => json_encode(['total' => $total, 'down' => $down, 'months' => $months, 'monthly' => $monthly]), 'ip_address' => client_ip()]);
            set_flash('success', 'Tuition plan saved and activated: ' . money_plain($total) . ' total, ' . money_plain($down) . ' down, ' . $months . ' months. New checkouts use it immediately; in-progress plans keep their snapshot.');
        } catch (Throwable $e) {
            storage_log('tuition plan save failed: ' . $e->getMessage());
            set_flash('error', 'Could not save the plan.');
        }
        redirect('/admin/tuition-plans');
    }

    public function activate(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        try {
            $exists = Database::one("SELECT id FROM tuition_plans WHERE id = ? LIMIT 1", [$id]);
            if ($exists === null) {
                set_flash('error', 'Plan not found.');
                redirect('/admin/tuition-plans');
            }
            Database::transaction(function () use ($id) {
                Database::run("UPDATE tuition_plans SET is_active = 0 WHERE is_active = 1");
                Database::run("UPDATE tuition_plans SET is_active = 1 WHERE id = ?", [$id]);
            });
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'tuition.plan_activated', 'entity_type' => 'tuition_plans', 'entity_id' => $id, 'ip_address' => client_ip()]);
            set_flash('success', 'Plan activated for new checkouts. In-progress plans keep their snapshot.');
        } catch (Throwable $e) {
            set_flash('error', 'Activation failed.');
        }
        redirect('/admin/tuition-plans');
    }
}
