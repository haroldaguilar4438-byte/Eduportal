<?php
declare(strict_types=1);

namespace Lib;

use RuntimeException;
use Throwable;

/**
 * PayRex REST client (cURL, no SDK dependency).
 * Docs: https://docs.payrexhq.com  - API base: https://api.payrexhq.com/v1
 *
 * AUTH
 *   HTTP Basic auth where the USERNAME is your SECRET key and the password is
 *   empty:  curl -u sk_test_xxx: https://api.payrexhq.com/v1/payment_intents
 *
 * MONEY
 *   All amounts are INTEGERS in centavos (PHP 1500.50 => 150050).
 *
 * TYPICAL SERVER FLOW (Module 6)
 *   1. Create a PaymentIntent for the assessed balance:
 *        $pi = (new PayRexClient())->createPaymentIntent(
 *            150050, ['gcash','maya','card','qrph'],
 *            'Tuition SY 2026-2027', ['payment_ref' => 'PAY-260826-AB12CD']);
 *   2. Store $pi['id'] on the payments row (payrex_payment_intent_id) and hand
 *      $pi['client_token'] + PAYREX_PUBLIC_KEY to the checkout view so the
 *      PayRex browser SDK / drop-in can collect GCash/Maya/card/QRPh details.
 *   3. PayRex calls public_html/api/payrex/webhook.php; that script verifies
 *      the signature with verifyWebhookSignature() below and finalizes status.
 */
class PayRexException extends RuntimeException
{
    public int $httpStatus = 0;
    /** @var array<string,mixed> raw error body from the API */
    public array $details = [];
}

final class PayRexClient
{
    private string $secretKey;
    private string $baseUrl;

    public function __construct(?string $secretKey = null, ?string $baseUrl = null)
    {
        $this->secretKey = $secretKey ?? PAYREX_SECRET_KEY;
        $this->baseUrl   = rtrim($baseUrl ?? PAYREX_API_BASE, '/');

        if ($this->secretKey === '') {
            throw new PayRexException('PayRex secret key is not configured (credentials.php).');
        }
    }

    /* =====================================================================
     *  Low-level request
     * ===================================================================== */

    /**
     * Perform an API call.
     *
     * @param string               $method GET | POST | DELETE
     * @param string               $path   e.g. '/payment_intents'
     * @param array<string, mixed> $form   form fields (POST) or query params (GET)
     * @return array<string, mixed> decoded response
     */
    public function request(string $method, string $path, array $form = []): array
    {
        $method  = strtoupper($method);
        $url     = $this->baseUrl . $path;

        $ch = curl_init();
        if ($ch === false) {
            throw new PayRexException('Unable to initialize cURL.');
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: EduPortal/1.0 (PayRex integration)',
        ];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        } elseif ($form !== []) {                       // GET/DELETE with query params
            $url .= '?' . http_build_query($form);
        }

        $caBundle = null;
        foreach (['C:/xampp/php/extras/ssl/cacert.pem','C:/xampp/apache/bin/curl-ca-bundle.crt','C:/xampp/php/cacert.pem'] as $ca) { if (is_file($ca)) { $caBundle = $ca; break; } }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERPWD        => $this->secretKey . ':',
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $caBundle ? true : false,
            CURLOPT_SSL_VERIFYHOST => $caBundle ? 2 : 0,
            CURLOPT_CAINFO         => $caBundle ?: null,
        ]);

        try {
            $body = curl_exec($ch);
            if ($body === false) {
                throw new PayRexException('Network error talking to PayRex: ' . curl_error($ch));
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new PayRexException('Malformed JSON from PayRex (HTTP ' . $status . ').');
        }

        if ($status >= 400) {
            $e = new PayRexException(
                (string) ($decoded['message'] ?? $decoded['error'] ?? ('PayRex HTTP ' . $status))
            );
            $e->httpStatus = $status;
            $e->details    = $decoded;
            throw $e;
        }

        return $decoded;
    }

    /* =====================================================================
     *  PaymentIntents
     * ===================================================================== */

    /**
     * @param int    $amountCentavos e.g. 150050 = PHP 1,500.50
     * @param string[] $paymentMethods subset of: gcash, maya, card, qrph, grab_pay, billease
     */
    public function createPaymentIntent(
        int $amountCentavos,
        array $paymentMethods,
        string $description = '',
        array $metadata = []
    ): array {
        $form = array_filter([
            'amount'          => $amountCentavos,
            'currency'        => 'PHP',
            'payment_methods' => $paymentMethods,
            'description'     => $description,
        ], static fn($v) => $v !== null);
        foreach ($metadata as $k=>$v) $form["metadata[$k]"] = $v;
        return $this->request('POST', '/payment_intents', $form);
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        return $this->request('GET', '/payment_intents/' . rawurlencode($paymentIntentId));
    }

    /* =====================================================================
     *  Refunds
     * ===================================================================== */

    public function createRefund(int $amountCentavos, string $paymentId, string $reason = ''): array
    {
        return $this->request('POST', '/refunds', array_filter([
            'amount'    => $amountCentavos,
            'payment_id' => $paymentId,
            'notes'     => $reason,
        ], static fn($v) => $v !== null && $v !== ''));
    }

    /* =====================================================================
     *  Webhook signature verification
     * ===================================================================== */

    /**
     * Verify the `PayRex-Signature` header of an incoming webhook.
     * Header format: "t=<unix-ts>,v1=<hex-hmac>" (Stripe-style scheme).
     * Signature = HMAC-SHA256( "<t>.<raw-payload>", webhook_secret ).
     *
     * @param int $tolerance reject events older than this many seconds (replay guard)
     */
    public static function verifyWebhookSignature(
        string $rawPayload,
        string $signatureHeader,
        string $webhookSecret,
        int $tolerance = 300
    ): bool {
        if ($webhookSecret === '' || $signatureHeader === '' || $rawPayload === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (preg_split('/[,\s]+/', $signatureHeader) ?: [] as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$key, $value] = $parts;
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;                                   // replayed / stale event
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $webhookSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, strtolower($sig))) {
                return true;
            }
        }
        return false;
    }
}
