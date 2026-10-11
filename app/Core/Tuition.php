<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Tuition payment plans (Full vs Monthly) — single source of truth.
 * Datamex College of Saint Adeline - EduPortal
 *
 * Money rules (all enforced here, never in the browser):
 *  - Total tuition, downpayment, months come from the ACTIVE tuition_plans
 *    row (admin-configured). Code fallbacks are 10000/3000/7, never 1500.
 *  - Full: one charge for the total. Monthly: downpayment first (required),
 *    then N installments; the LAST installment absorbs rounding so the sum
 *    is exactly the total. Confirmed payments can never exceed the total
 *    (allocation is capped; overpayments are logged, not applied).
 *  - Only `paid` payments count. pending/processing/failed/cancelled never do.
 *  - Historical rows are never rewritten; snapshots live on the plan row.
 */
final class Tuition
{
    public const FALLBACK_TOTAL = 10000.00;
    public const FALLBACK_DOWN = 3000.00;
    public const FALLBACK_MONTHS = 7;

    private static ?bool $hasTables = null;

    public static function hasTables(): bool
    {
        if (self::$hasTables !== null) return self::$hasTables;
        try {
            self::$hasTables = Database::value("SHOW TABLES LIKE 'tuition_plans'") ? true : false;
        } catch (Throwable $e) {
            self::$hasTables = false;
        }
        return self::$hasTables;
    }

    /** Active admin-configured plan (or safe defaults when unavailable). */
    public static function activePlan(): array
    {
        $def = [
            'id' => 0, 'name' => 'Default tuition',
            'total_tuition' => self::FALLBACK_TOTAL,
            'downpayment_amount' => self::FALLBACK_DOWN,
            'installment_months' => self::FALLBACK_MONTHS,
            'monthly_amount' => null,
        ];
        if (!self::hasTables()) return $def;
        try {
            $row = Database::one("SELECT * FROM tuition_plans WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
            if ($row === null) {
                $row = Database::one("SELECT * FROM tuition_plans ORDER BY id DESC LIMIT 1");
            }
            if ($row === null) return $def;
            return [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? 'Tuition'),
                'total_tuition' => max(1.0, (float) $row['total_tuition']),
                'downpayment_amount' => max(0.0, (float) $row['downpayment_amount']),
                'installment_months' => max(1, (int) $row['installment_months']),
                'monthly_amount' => $row['monthly_amount'] !== null ? max(0.0, (float) $row['monthly_amount']) : null,
            ];
        } catch (Throwable $e) {
            return $def;
        }
    }

    public static function total(): float
    {
        return (float) self::activePlan()['total_tuition'];
    }

    /**
     * Effective amount due for display and payment creation.
     * A stored value wins — EXCEPT the legacy 1500.00 column default,
     * which predates tuition plans and must never surface (or be charged)
     * once a plan exists. Never throws; falls back to the plan total.
     */
    public static function effectiveDue($stored): float
    {
        try {
            $v = round((float) ($stored ?? 0), 2);
            if ($v > 0 && abs($v - 1500.00) >= 0.005) return $v;
            return round(self::total(), 2);
        } catch (Throwable $e) {
            return self::FALLBACK_TOTAL;
        }
    }

    /** Monthly amount: explicit config, else remaining/months rounded. */
    public static function monthlyFor(float $total, float $down, int $months, ?float $configured): float
    {
        $remaining = round($total - $down, 2);
        if ($configured !== null && $configured > 0) return round($configured, 2);
        if ($months <= 0) return $remaining;
        return round($remaining / $months, 2);
    }

    /** Full installment schedule: equal months, LAST absorbs rounding. */
    public static function buildSchedule(float $total, float $down, int $months, ?float $configuredMonthly, string $baseDate): array
    {
        $remaining = round($total - $down, 2);
        $monthly = self::monthlyFor($total, $down, $months, $configuredMonthly);
        $out = [];
        $base = strtotime($baseDate) ?: time();
        $accum = 0.0;
        for ($n = 1; $n <= $months; $n++) {
            $amt = ($n === $months) ? round($remaining - $accum, 2) : $monthly;
            if ($amt < 0) $amt = 0.0;
            $accum = round($accum + $amt, 2);
            $out[] = ['n' => $n, 'amount' => $amt, 'due_date' => date('Y-m-d', strtotime("+{$n} months", $base))];
        }
        return $out;
    }
    /**
     * Sync a still-unpaid application's amount_due to its plan total.
     * Safe by construction: only runs while status is awaiting_payment AND
     * no payment was ever submitted (processing/paid). In-flight and
     * historical amounts are never rewritten.
     */
    public static function syncAmountDue(int $appId): void
    {
        try {
            $app = Database::one("SELECT id, status, amount_due FROM enrollment_applications WHERE id = ? LIMIT 1", [$appId]);
            if ($app === null || ($app['status'] ?? '') !== 'awaiting_payment') return;
            $epp = self::ensurePlan($appId);
            if ($epp === null) return;
            $total = round((float) $epp['total_tuition'], 2);
            if (abs((float) ($app['amount_due'] ?? 0) - $total) < 0.005) return;
            $busy = (int) Database::value(
                "SELECT COUNT(*) FROM payments WHERE enrollment_application_id = ? AND status IN ('processing','paid')",
                [$appId]
            );
            if ($busy > 0) return;
            Database::run(
                "UPDATE enrollment_applications SET amount_due = ? WHERE id = ? AND status = 'awaiting_payment'",
                [$total, $appId]
            );
        } catch (Throwable $e) {
        }
    }

    /** Plan row for an application (snapshot), creating it from the active plan. */
    public static function ensurePlan(int $appId): ?array
    {
        if (!self::hasTables() || $appId <= 0) return null;
        try {
            $epp = Database::one("SELECT * FROM enrollment_payment_plans WHERE application_id = ? LIMIT 1", [$appId]);
            if ($epp !== null) return $epp;
            $plan = self::activePlan();
            Database::insert('enrollment_payment_plans', [
                'application_id' => $appId,
                'plan_id' => $plan['id'] > 0 ? $plan['id'] : null,
                'total_tuition' => $plan['total_tuition'],
                'downpayment_amount' => min($plan['downpayment_amount'], $plan['total_tuition']),
                'installment_months' => $plan['installment_months'],
                'monthly_amount' => $plan['monthly_amount'],
            ]);
            return Database::one("SELECT * FROM enrollment_payment_plans WHERE application_id = ? LIMIT 1", [$appId]);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function getPlan(int $appId): ?array
    {
        if (!self::hasTables()) return null;
        try {
            return Database::one("SELECT * FROM enrollment_payment_plans WHERE application_id = ? LIMIT 1", [$appId]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Lock in the plan choice. Allowed only before any payment was submitted
     * (no processing/paid rows for this application).
     */
    public static function choosePlan(int $appId, string $type): array
    {
        if (!in_array($type, ['full', 'monthly'], true)) {
            return ['ok' => false, 'message' => 'Please select a payment plan.'];
        }
        $epp = self::ensurePlan($appId);
        if ($epp === null) return ['ok' => false, 'message' => 'Payment plans are unavailable right now.'];
        try {
            $busy = (int) Database::value(
                "SELECT COUNT(*) FROM payments WHERE enrollment_application_id = ? AND status IN ('processing','paid')",
                [$appId]
            );
            if ($busy > 0) {
                return ['ok' => false, 'message' => 'The payment plan is locked — a payment is already in progress.'];
            }
            Database::run(
                "UPDATE enrollment_payment_plans SET plan_type = ? WHERE id = ? AND (plan_type IS NULL OR plan_type = ?)",
                [$type, (int) $epp['id'], $type]
            );
            // Allow switching while nothing paid: update even if different.
            Database::run("UPDATE enrollment_payment_plans SET plan_type = ? WHERE id = ?", [$type, (int) $epp['id']]);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save the payment plan.'];
        }
    }

    /**
     * Clear the plan choice (back button). Allowed only before any payment
     * was submitted — same lock as choosing.
     */
    public static function clearPlan(int $appId): array
    {
        $epp = self::getPlan($appId);
        if ($epp === null) return ['ok' => false, 'message' => 'No payment plan to change.'];
        try {
            $busy = (int) Database::value(
                "SELECT COUNT(*) FROM payments WHERE enrollment_application_id = ? AND status IN ('processing','paid')",
                [$appId]
            );
            if ($busy > 0) {
                return ['ok' => false, 'message' => 'The plan is locked — a payment is already in progress.'];
            }
            Database::run("UPDATE enrollment_payment_plans SET plan_type = NULL WHERE id = ?", [(int) $epp['id']]);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not change the payment plan.'];
        }
    }

    /**
     * What must be charged RIGHT NOW (server-side truth for pay() + intents).
     * Returns null when nothing is due (fully paid).
     * @return ?array{kind:string,amount:float,installment_id:?int,label:string}
     */
    public static function currentDue(int $appId): ?array
    {
        $epp = self::getPlan($appId);
        if ($epp === null) return null;
        $type = (string) ($epp['plan_type'] ?? '');
        $total = (float) $epp['total_tuition'];
        if ($type === '') return null; // plan not chosen yet
        // A processing early-settlement covers everything — no parallel due.
        if (self::hasOpenSettlement($appId)) return null;
        if ($type === 'full') {
            if (self::paidTotal($appId) >= $total - 0.009) return null;
            // A submitted-but-unverified full payment blocks a second one.
            if (self::hasOpenPayment($appId, 'full')) return null;
            return ['kind' => 'full', 'amount' => $total, 'installment_id' => null, 'label' => 'Full Tuition'];
        }
        // monthly
        $downStatus = (string) $epp['downpayment_status'];
        $down = min((float) $epp['downpayment_amount'], $total);
        if ($downStatus !== 'paid') {
            if (self::hasOpenPayment($appId, 'downpayment')) return null;
            return ['kind' => 'downpayment', 'amount' => $down, 'installment_id' => null, 'label' => 'Downpayment'];
        }
        $next = self::nextInstallment((int) $epp['id']);
        if ($next === null) return null; // schedule complete
        if (self::hasOpenPayment($appId, 'installment', (int) $next['id'])) return null;
        return [
            'kind' => 'installment', 'amount' => (float) $next['amount'],
            'installment_id' => (int) $next['id'],
            'label' => 'Monthly Installment #' . (int) $next['n'],
        ];
    }

    /** A submitted-but-unverified early settlement blocks any parallel due. */
    public static function hasOpenSettlement(int $appId): bool
    {
        try {
            return (bool) Database::one(
                "SELECT id FROM payments WHERE enrollment_application_id = ? AND charge_kind = 'settlement' AND status IN ('pending','processing') LIMIT 1",
                [$appId]
            );
        } catch (Throwable $e) {
            return true; // fail closed on DB trouble
        }
    }

    /** Any submitted-but-unverified payment for the application. */
    public static function hasOpenPaymentForApp(int $appId): bool
    {
        try {
            return (bool) Database::one(
                "SELECT id FROM payments WHERE enrollment_application_id = ? AND status IN ('pending','processing') LIMIT 1",
                [$appId]
            );
        } catch (Throwable $e) {
            return true; // fail closed on DB trouble
        }
    }

    /**
     * Server-computed remaining balance (total minus confirmed payments).
     * Uses the plan snapshot when one exists, else amount_due minus paid.
     */
    public static function remainingFor(int $appId, ?array $app = null): float
    {
        try {
            $sum = self::planSummary($appId);
            if (!empty($sum['has_plan'])) {
                return max(0.0, round((float) $sum['total'] - (float) $sum['paid'], 2));
            }
            $due0 = $app !== null ? (float) ($app['amount_due'] ?? 0) : 0.0;
            return max(0.0, round($due0 - self::paidTotal($appId), 2));
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    private static function hasOpenPayment(int $appId, string $kind, ?int $installmentId = null): bool
    {
        try {
            if ($installmentId !== null) {
                return (bool) Database::one(
                    "SELECT id FROM payments WHERE plan_installment_id = ? AND status IN ('processing','paid') LIMIT 1",
                    [$installmentId]
                );
            }
            return (bool) Database::one(
                "SELECT id FROM payments WHERE enrollment_application_id = ? AND charge_kind = ? AND status IN ('processing','paid') LIMIT 1",
                [$appId, $kind]
            );
        } catch (Throwable $e) {
            return true; // fail closed on DB trouble
        }
    }

    /** Sum of CONFIRMED (paid) payments for the application. */
    public static function paidTotal(int $appId): float
    {
        try {
            return (float) Database::value(
                "SELECT COALESCE(SUM(amount),0) FROM payments WHERE enrollment_application_id = ? AND status = 'paid'",
                [$appId]
            );
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    public static function nextInstallment(int $eppId): ?array
    {
        try {
            return Database::one(
                "SELECT * FROM plan_installments WHERE epp_id = ? AND status IN ('due','upcoming','failed')
                  ORDER BY n ASC LIMIT 1",
                [$eppId]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function installments(int $eppId): array
    {
        try {
            return Database::all("SELECT * FROM plan_installments WHERE epp_id = ? ORDER BY n ASC", [$eppId]);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Generate the monthly schedule on downpayment confirmation.
     * Idempotent: existing rows are kept, never duplicated.
     */
    public static function activateSchedule(int $eppId, string $baseDate): array
    {
        try {
            $epp = Database::one("SELECT * FROM enrollment_payment_plans WHERE id = ? LIMIT 1", [$eppId]);
            if ($epp === null) return [];
            $existing = (int) Database::value("SELECT COUNT(*) FROM plan_installments WHERE epp_id = ?", [$eppId]);
            if ($existing > 0) {
                Database::run("UPDATE enrollment_payment_plans SET activated_at = COALESCE(activated_at, NOW()) WHERE id = ?", [$eppId]);
                return self::installments($eppId);
            }
            $rows = self::buildSchedule(
                (float) $epp['total_tuition'], (float) $epp['downpayment_amount'],
                (int) $epp['installment_months'],
                $epp['monthly_amount'] !== null ? (float) $epp['monthly_amount'] : null,
                $baseDate
            );
            foreach ($rows as $i => $r) {
                Database::insert('plan_installments', [
                    'epp_id' => $eppId, 'n' => $r['n'], 'amount' => $r['amount'],
                    'due_date' => $r['due_date'], 'status' => $i === 0 ? 'due' : 'upcoming',
                ]);
            }
            Database::run("UPDATE enrollment_payment_plans SET activated_at = NOW() WHERE id = ?", [$eppId]);
            return self::installments($eppId);
        } catch (Throwable $e) {
            storage_log('activateSchedule failed epp=' . $eppId . ': ' . $e->getMessage());
            return [];
        }
    }

    /** Tuition charge row for the ledger (one per application), created once. */
    public static function ensureTuitionCharge(int $studentId, int $ayId, string $appNo, float $total, int $actorId): int
    {
        try {
            $desc = 'Tuition ' . $appNo;
            $found = Database::one(
                "SELECT id FROM student_charges WHERE student_id = ? AND academic_year_id = ? AND description = ? LIMIT 1",
                [$studentId, $ayId, $desc]
            );
            if ($found !== null) return (int) $found['id'];
            return Database::insert('student_charges', [
                'student_id' => $studentId, 'academic_year_id' => $ayId,
                'description' => $desc, 'amount' => $total,
                'charge_date' => date('Y-m-d'), 'posted_by' => $actorId > 0 ? $actorId : null,
            ]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function allocatedTotal(int $chargeId): float
    {
        try {
            return (float) Database::value(
                "SELECT COALESCE(SUM(pa.amount_applied),0) FROM payment_allocations pa
                  JOIN payments p ON p.id = pa.payment_id WHERE pa.charge_id = ? AND p.status = 'paid'",
                [$chargeId]
            );
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Apply a CONFIRMED (paid) payment: allocate to the tuition charge
     * (capped — overpayments never apply), flip downpayment/installment
     * states, generate/advance the schedule. Idempotent: an already
     * allocated payment is a no-op. Returns summary for notifications.
     */
    public static function applyPaidPayment(int $paymentId, int $actorId = 0): array
    {
        $out = ['allocated' => 0.0, 'completed' => false, 'activated' => false, 'kind' => null];
        try {
            $pay = Database::one("SELECT * FROM payments WHERE id = ? LIMIT 1 FOR UPDATE", [$paymentId]);
        } catch (Throwable $e) {
            try {
                $pay = Database::one("SELECT * FROM payments WHERE id = ? LIMIT 1", [$paymentId]);
            } catch (Throwable $e2) {
                return $out;
            }
        }
        if ($pay === null || ($pay['status'] ?? '') !== 'paid') return $out;
        $appId = (int) ($pay['enrollment_application_id'] ?? 0);
        if ($appId <= 0) return $out;
        $kind = (string) ($pay['charge_kind'] ?? '');
        $out['kind'] = $kind !== '' ? $kind : null;

        try {
            Database::transaction(function () use ($pay, $appId, $paymentId, $actorId, &$out, $kind) {
                // Idempotency: already allocated → nothing to do.
                $already = (int) Database::value(
                    "SELECT COUNT(*) FROM payment_allocations WHERE payment_id = ?",
                    [$paymentId]
                );
                if ($already > 0) return;

                $app = Database::one("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1", [$appId]);
                if ($app === null) return;
                $epp = self::getPlan($appId);
                $total = $epp !== null ? (float) $epp['total_tuition'] : (float) ($app['amount_due'] ?? self::total());
                $sid = (int) ($pay['student_id'] ?? $app['student_id'] ?? 0);
                $ayId = (int) ($app['academic_year_id'] ?? 0);

                // Allocate against the tuition charge (capped at the total).
                if ($sid > 0 && $ayId > 0) {
                    $chargeId = self::ensureTuitionCharge($sid, $ayId, (string) $app['application_no'], $total, $actorId);
                    if ($chargeId > 0) {
                        $room = round($total - self::allocatedTotal($chargeId), 2);
                        $give = round(min((float) $pay['amount'], max(0.0, $room)), 2);
                        if ($give > 0) {
                            Database::insert('payment_allocations', [
                                'payment_id' => $paymentId, 'charge_id' => $chargeId, 'amount_applied' => $give,
                            ]);
                            $out['allocated'] = $give;
                        } elseif ((float) $pay['amount'] > 0) {
                            storage_log("plan overpayment ignored app=$appId pay=$paymentId amount=" . $pay['amount']);
                        }
                    }
                }

                // Flip plan state.
                if ($epp !== null) {
                    $eppId = (int) $epp['id'];
                    if ($kind === 'downpayment') {
                        Database::run(
                            "UPDATE enrollment_payment_plans SET downpayment_status = 'paid' WHERE id = ?",
                            [$eppId]
                        );
                        if ((string) ($epp['plan_type'] ?? '') === 'monthly') {
                            self::activateSchedule($eppId, date('Y-m-d H:i:s'));
                            $out['activated'] = true;
                        }
                    } elseif ($kind === 'installment' && !empty($pay['plan_installment_id'])) {
                        Database::run(
                            "UPDATE plan_installments SET status = 'paid', paid_at = NOW() WHERE id = ? AND status != 'paid'",
                            [(int) $pay['plan_installment_id']]
                        );
                        // Promote the next upcoming row to due.
                        $next = self::nextInstallment($eppId);
                        if ($next !== null && ($next['status'] ?? '') === 'upcoming') {
                            Database::run("UPDATE plan_installments SET status = 'due' WHERE id = ?", [(int) $next['id']]);
                        }
                    }
                }

                // Fully paid? (confirmed total covers tuition)
                if (self::paidTotal($appId) >= $total - 0.009) {
                    $out['completed'] = true;
                    // An early settlement covering the total closes out the
                    // remaining schedule rows, so next-due, summary and the
                    // installment table stay consistent. Idempotent:
                    // already-paid rows match zero rows (no-op).
                    if ($epp !== null && (string) ($epp['plan_type'] ?? '') === 'monthly') {
                        Database::run(
                            "UPDATE plan_installments SET status = 'paid', paid_at = COALESCE(paid_at, NOW()) WHERE epp_id = ? AND status != 'paid'",
                            [$eppId]
                        );
                    }
                    Database::insert('audit_logs', [
                        'actor_user_id' => $actorId > 0 ? $actorId : null, 'action' => 'plan.completed',
                        'entity_type' => 'enrollment_applications', 'entity_id' => $appId,
                        'ip_address' => client_ip(),
                    ]);
                }
            });
        } catch (Throwable $e) {
            storage_log('applyPaidPayment failed pay=' . $paymentId . ': ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Everything the summary screens need — always from the database.
     * Status vocabulary: Unpaid, Downpayment Required/Pending/Paid,
     * Monthly Active, Partially Paid, Fully Paid, Overdue (+ Failed/Cancelled
     * surfaced per row, never counted as paid).
     */
    public static function planSummary(int $appId): array
    {
        $out = [
            'has_plan' => false, 'plan_type' => null, 'total' => 0.0,
            'paid' => 0.0, 'remaining' => 0.0, 'status' => 'Unpaid',
            'downpayment' => 0.0, 'downpayment_status' => null,
            'installments' => [], 'next' => null, 'overdue_amount' => 0.0,
        ];
        $epp = self::getPlan($appId);
        if ($epp === null) return $out;
        $out['has_plan'] = true;
        $type = (string) ($epp['plan_type'] ?? '');
        $out['plan_type'] = $type !== '' ? $type : null;
        $total = (float) $epp['total_tuition'];
        $out['total'] = $total;
        $paid = self::paidTotal($appId);
        $out['paid'] = $paid;
        $out['remaining'] = max(0.0, round($total - $paid, 2));
        $today = date('Y-m-d');

        if ($type === '' || $type === null) {
            $out['status'] = $paid > 0 ? 'Partially Paid' : 'Unpaid';
            return $out;
        }
        if ($type === 'full') {
            $out['status'] = $paid >= $total - 0.009 ? 'Fully Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid');
            return $out;
        }
        // monthly
        $out['downpayment'] = (float) $epp['downpayment_amount'];
        $out['downpayment_status'] = (string) $epp['downpayment_status'];
        $rows = self::installments((int) $epp['id']);
        $overdue = 0.0;
        foreach ($rows as &$r) {
            $r['display_status'] = (string) $r['status'];
            if (in_array($r['status'], ['due', 'upcoming'], true) && $r['due_date'] < $today) {
                $r['display_status'] = 'overdue';
                $overdue = round($overdue + (float) $r['amount'], 2);
            }
        }
        unset($r);
        $out['installments'] = $rows;
        $out['overdue_amount'] = $overdue;
        foreach ($rows as $r) {
            if (in_array($r['status'], ['due', 'upcoming', 'failed'], true)) {
                $out['next'] = $r;
                break;
            }
        }
        if ($paid >= $total - 0.009) {
            $out['status'] = 'Fully Paid';
        } elseif ($overdue > 0) {
            $out['status'] = 'Overdue';
        } elseif ((string) $epp['downpayment_status'] !== 'paid') {
            $out['status'] = ((string) $epp['downpayment_status'] === 'pending') ? 'Downpayment Pending' : 'Downpayment Required';
        } elseif ($epp['activated_at'] === null) {
            $out['status'] = 'Downpayment Paid';
        } elseif ($paid > 0) {
            $out['status'] = 'Partially Paid';
            if (!empty($rows)) $out['status'] = 'Monthly Active';
        } else {
            $out['status'] = 'Monthly Active';
        }
        return $out;
    }
}
