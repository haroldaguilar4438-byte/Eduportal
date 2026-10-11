<?php
declare(strict_types=1);

/**
 * PayRex webhook receiver - PUBLIC endpoint (no session, no CSRF).
 * Datamex College of Saint Adeline - EduPortal
 *
 * REGISTER THIS URL IN PAYREX DASHBOARD:
 *   Developers -> Webhooks -> Add endpoint
 *     URL:    https://YOUR-DOMAIN/api/payrex/webhook.php
 *     Events: payment_intent.succeeded + payment_intent.amount_capturable
 *     (also available: billing_statement.paid for billing, ignore others)
 *
 * Security model:
 *   1. HMAC signature check on the raw body (PayRex-Signature: t=...,v1=...)
 *   2. Event-id dedupe via UNIQUE KEY (provider, event_id) -> replays are no-ops
 *   3. State transitions guarded inside a DB transaction with SELECT ... FOR UPDATE
 *   4. Every event logged to payment_webhook_logs regardless of outcome
 */

require __DIR__ . '/../../../app/Core/bootstrap.php';

use App\Core\Database;
use Lib\PayRexClient;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    json_response(['error' => 'Empty payload.'], 400);
}

/* ---- 1. verify signature -------------------------------------------------- */
$secret      = PAYREX_WEBHOOK_SECRET;
$sigHeader   = $_SERVER['HTTP_PAYREX_SIGNATURE'] ?? '';
$signatureOk = $secret !== ''
    && PayRexClient::verifyWebhookSignature($rawBody, $sigHeader, $secret);

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    $event = [];
}
$eventId   = isset($event['id']) ? (string) $event['id'] : '';
$eventType = isset($event['type']) ? (string) $event['type'] : '';

/* ---- 2. reject bad signatures BEFORE storing anything -----------------------
   (Never persist attacker-controlled bodies: forged payloads must not reach
   the log table. Only an event id is logged, never the raw body.) -------- */
if (!$signatureOk) {
    storage_log('webhook rejected: invalid signature' . ($eventId !== '' ? ' event=' . $eventId : ''));
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature.']);
    exit;
}

/* ---- 3. log + dedupe (signature already verified) ------------------------- */
try {
    $stmt  = Database::run(
        'INSERT INTO payment_webhook_logs (provider, event_id, event_type, payload_json, signature_valid)
         VALUES (?, ?, ?, ?, ?)',
        ['payrex', $eventId !== '' ? $eventId : null, $eventType !== '' ? $eventType : null, $rawBody, 1]
    );
    $isNew = $stmt->rowCount() > 0;
    $logId = Database::lastInsertId();
} catch (\Throwable $e) {
    storage_log('webhook log insert failed: ' . $e->getMessage());
    json_response(['error' => 'Storage failure.'], 500);
}

if (!$isNew) {
    // Duplicate delivery (PayRex retry or replay) - acknowledge idempotently.
    json_response(['received' => true, 'duplicate' => true]);
}

/* ---- 4. map event -> internal state (your PayRex choices) --------------- */
$statusMap = [
    'payment_intent.succeeded'           => 'processing',
    'payment_intent.amount_capturable'   => 'processing',
    'payment_intent.failed'              => 'failed',
    'payment_intent.canceled'            => 'cancelled',
    'payment_intent.awaiting_next_action' => 'processing',
    'billing_statement.paid'             => 'processing',
];

$object = $event['data']['object'] ?? [];
$intentId = '';
if (is_array($object) && ($object['object'] ?? '') === 'payment_intent') {
    $intentId = (string) ($object['id'] ?? '');
}

if ($intentId === '' || !isset($statusMap[$eventType])) {
    Database::run(
        'UPDATE payment_webhook_logs SET processed = 1, processing_error = ? WHERE id = ?',
        ['unhandled event type: ' . $eventType, $logId]
    );
    json_response(['received' => true, 'handled' => false]);
}

$newStatus = $statusMap[$eventType];
$now       = date('Y-m-d H:i:s');

/* ---- 5. apply the transition atomically ------------------------------------ */
try {
    $result = Database::transaction(static function () use ($intentId, $newStatus, $now, $eventType, $eventId): array {

        $payment = Database::one(
            'SELECT * FROM payments WHERE payrex_payment_intent_id = ? LIMIT 1 FOR UPDATE',
            [$intentId]
        );
        if ($payment === null) {
            return ['handled' => false, 'reason' => 'unknown payment intent'];
        }
        if ($payment['status'] === 'paid') {
            return ['handled' => false, 'reason' => 'already paid'];
        }

        if ($newStatus === 'processing') {
            Database::run("UPDATE payments SET status='processing', payrex_last_event_id=? WHERE id=?", [$eventId, (int)$payment['id']]);
            $admins = Database::all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' LIMIT 5");
            foreach($admins as $adm){ try{ Database::insert('notifications',['user_id'=>(int)$adm['id'],'title'=>'PayRex payment awaiting approval','message'=>'Payment '.$payment['transaction_ref'].' succeeded via PayRex — please verify and approve to send receipt.','type'=>'payment','link_url'=>'/admin/payments']); } catch(Throwable $e){} }
            if(!empty($payment['enrollment_application_id'])){ try{ Database::run("UPDATE enrollment_applications SET status='awaiting_payment' WHERE id=?", [(int)$payment['enrollment_application_id']]); } catch(Throwable $e){} }
            return ['handled'=>true,'became_paid'=>false,'awaiting_approval'=>true,'payment'=>$payment];
        }
        if ($newStatus === 'paid') {
            Database::run(
                "UPDATE payments SET status = 'paid', paid_at = ?, payrex_last_event_id = ? WHERE id = ?",
                [$now, $eventId, (int) $payment['id']]
            );
            $receiptNo = 'RC-' . date('Y') . '-' . str_pad((string) $payment['id'], 6, '0', STR_PAD_LEFT);
            Database::insert('receipts', ['payment_id' => (int) $payment['id'],'receipt_no' => $receiptNo]);
            try {
                if (class_exists(\App\Core\Tuition::class)) {
                    \App\Core\Tuition::applyPaidPayment((int) $payment['id'], 0);
                }
            } catch (\Throwable $e) {
                storage_log('webhook plan allocation failed: ' . $e->getMessage());
            }
            return ['handled' => true, 'became_paid' => true, 'payment' => $payment, 'receipt_no' => $receiptNo];
        }
        Database::run('UPDATE payments SET status = ?, failure_reason = ?, payrex_last_event_id = ? WHERE id = ?', [$newStatus, ucfirst(str_replace('_', ' ', $eventType)), $eventId, (int) $payment['id']]);
        return ['handled' => true, 'became_paid' => false];
    });

    Database::run('UPDATE payment_webhook_logs SET processed = 1 WHERE id = ?', [$logId]);
} catch (\Throwable $e) {
    storage_log('webhook processing failed: ' . $e->getMessage());
    Database::run(
        'UPDATE payment_webhook_logs SET processing_error = ? WHERE id = ?',
        [mb_substr($e->getMessage(), 0, 490), $logId]
    );
    http_response_code(500);                       // let PayRex retry
    echo json_encode(['error' => 'Processing failed; will retry.']);
    exit;
}

/* ---- 6. post-processing outside the lock: receipt email + notifications ---- */
if (!empty($result['became_paid'])) {
    try {
        $payment = $result['payment'];

        $payer = Database::one(
            'SELECT u.* FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1',
            [(int) $payment['student_id']]
        );
        if ($payer === null && !empty($payment['enrollment_application_id'])) {
            // Parent-filed children have no login: email the filer instead.
            $payer = Database::one(
                'SELECT u.* FROM enrollment_applications ea JOIN users u ON u.id = ea.user_id WHERE ea.id = ? LIMIT 1',
                [(int) $payment['enrollment_application_id']]
            );
        }

        $methodLabel = ucfirst((string) $payment['payment_method']);

        if ($payer !== null) {
            send_payment_receipt_email($payer, [
                'receipt_no'     => $result['receipt_no'],
                'transaction_ref' => $payment['transaction_ref'],
                'amount'         => (float) $payment['amount'],
                'method_label'   => $methodLabel,
                'paid_at'        => $now,
                'student_name'   => trim(($payer['first_name'] ?? '') . ' ' . ($payer['last_name'] ?? '')),
            ]);
            Database::run(
                'UPDATE receipts SET emailed_to = ?, emailed_at = NOW()
                  WHERE payment_id = ? AND emailed_at IS NULL',
                [$payer['email'], (int) $payment['id']]
            );

            Database::insert('notifications', [
                'user_id' => (int) $payer['id'],
                'title'   => 'Payment confirmed',
                'message' => 'Payment ' . $payment['transaction_ref'] . ' ('
                    . money_plain($payment['amount']) . ') was received.',
                'type'    => 'payment',
                'link_url' => '/my-enrollments',
            ]);

            // Notify linked guardians/parents too.
            $guardianIds = Database::all(
                'SELECT u.id FROM guardian_students gs
                 JOIN guardians g ON g.id = gs.guardian_id
                 JOIN users u ON u.id = g.user_id
                 WHERE gs.student_id = ? AND u.is_email_verified = 1',
                [(int) $payment['student_id']]
            );
            foreach ($guardianIds as $g) {
                Database::insert('notifications', [
                    'user_id' => (int) $g['id'],
                    'title'   => 'Payment confirmed',
                    'message' => 'Payment ' . $payment['transaction_ref'] . ' ('
                        . money_plain($payment['amount']) . ') was received.',
                    'type'    => 'payment',
                    'link_url' => '/my-enrollments',
                ]);
            }
        }

        Database::insert('audit_logs', [
            'actor_user_id' => null,
            'action'        => 'payment.webhook_paid',
            'entity_type'   => 'payments',
            'entity_id'     => (int) $payment['id'],
            'new_values_json' => json_encode(['receipt_no' => $result['receipt_no']]),
            'ip_address'    => client_ip(),
        ]);

        if (!empty($payment['enrollment_application_id'])) {
            try {
                Database::run("UPDATE enrollment_applications SET status='approved', reviewed_at=NOW() WHERE id=? AND status='awaiting_payment'", [(int)$payment['enrollment_application_id']]);
                $app = Database::one("SELECT * FROM enrollment_applications WHERE id=? LIMIT 1", [(int)$payment['enrollment_application_id']]);
                if ($app && $app['student_id']) Database::run("UPDATE students SET status='enrolled' WHERE id=?", [(int)$app['student_id']]);
                if ($app && $payer) send_enrollment_status_email($payer, $app['application_no'], 'Approved - Enrolled', 'Payment confirmed via PayRex. You are now fully enrolled.');
            } catch (Throwable $e) { storage_log('enroll auto-approve after pay failed: '.$e->getMessage()); }
        }
    } catch (\Throwable $e) {
        // Payment itself is already recorded; auxiliary steps failing must not
        // fail the webhook (a retry would hit the "already paid" guard).
        storage_log('post-payment side effects failed: ' . $e->getMessage());
    }
}

json_response(['received' => true, 'handled' => (bool) ($result['handled'] ?? false)]);
