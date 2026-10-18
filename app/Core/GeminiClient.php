<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Gemini transport for the EduPortal assistant.
 *
 * Upgraded from the previous version, which:
 *   - stuffed the system prompt INSIDE the first user message (so the model
 *     treated instructions as something the user said),
 *   - disabled TLS certificate verification whenever no bundled CA file was
 *     found (true on Hostinger, since the hardcoded XAMPP paths don't exist),
 *   - had no server-side conversation memory, so context depended entirely on
 *     whatever the browser sent.
 *
 * Now: proper systemInstruction, verified TLS via the platform CA bundle,
 * server-authoritative history, bounded token budget, and clear errors.
 */
final class GeminiClient
{
    private string $apiKey;
    private string $model;
    private string $base = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
        $this->model  = $model  ?? (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.0-flash');
        if ($this->apiKey === '') {
            throw new \RuntimeException('Gemini API key not configured');
        }
    }

    /**
     * @param array<int,array{role:string,text:string}> $history oldest -> newest
     */
    public function chat(string $userMessage, array $history = [], ?string $accountContext = null): string
    {
        $contents = [];

        // Real multi-turn history (excluding a trailing duplicate of the
        // current message, which we append ourselves).
        $turns = [];
        foreach ($history as $h) {
            if (!is_array($h) || !isset($h['role'], $h['text'])) continue;
            $text = trim((string) $h['text']);
            if ($text === '') continue;
            $turns[] = ['role' => ($h['role'] === 'user' ? 'user' : 'model'), 'parts' => [['text' => mb_substr($text, 0, 1500)]]];
        }
        // Gemini requires the conversation to start with a user turn.
        if (isset($turns[0]) && $turns[0]['role'] === 'model') array_shift($turns);

        $message = mb_substr(trim($userMessage), 0, 2000);
        $turns[] = ['role' => 'user', 'parts' => [['text' => $message]]];
        $contents = $turns;

        $system = ChatbotKnowledge::systemPrompt();
        if ($accountContext !== null && trim($accountContext) !== '') {
            $system .= "\n\nLIVE STATUS OF THE SIGNED-IN USER (authoritative, read-only).\n"
                     . "Use it to answer questions about what is actually on their account. "
                     . "Never reveal another person's data. If their question is about a detail that "
                     . "is not in this snapshot, tell them where to check instead of guessing.\n"
                     . $accountContext;
        }

        $payload = json_encode([
            'contents' => $contents,
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'generationConfig' => [
                'temperature'        => 0.65,
                'topP'               => 0.92,
                'maxOutputTokens'    => 700,
                'candidateCount'     => 1,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) throw new \RuntimeException('Could not encode request');
        $url = $this->base . '/models/' . rawurlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);

        $body = $this->post($url, $payload);
        $json = json_decode($body, true);
        if (!is_array($json)) throw new \RuntimeException('Gemini returned an unreadable response');

        if (isset($json['error'])) {
            $msg = (string) ($json['error']['message'] ?? 'Gemini error');
            throw new \RuntimeException($msg);
        }
        if (($json['promptFeedback']['blockReason'] ?? '') !== '') {
            throw new \RuntimeException('Blocked by safety filter');
        }

        $text = '';
        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (isset($p['text'])) $text .= (string) $p['text'];
        }
        $finish = (string) ($json['candidates'][0]['finishReason'] ?? '');
        if (trim($text) === '') {
            if ($finish === 'MAX_TOKENS') {
                return trim($text) !== ''
                    ? trim($text)
                    : "That answer got cut off - could you ask me about one thing at a time?";
            }
            throw new \RuntimeException('Gemini returned an empty response');
        }
        return trim($text);
    }

    /** POST with verified TLS. Uses the platform CA bundle when available. */
    private function post(string $url, string $payload): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL is not available on this server');
        }
        $ca = self::caBundle();
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT         => 25,
            CURLOPT_CONNECTTIMEOUT  => 8,
            CURLOPT_FOLLOWLOCATION  => false,
        ];
        if ($ca !== null) {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
            $opts[CURLOPT_CAINFO] = $ca;
        } elseif (defined('OPENSSL_CA_FILE') && is_file(OPENSSL_CA_FILE)) {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
            $opts[CURLOPT_CAINFO] = OPENSSL_CA_FILE;
        }
        // No CA bundle anywhere: fail closed rather than silently accept any
        // certificate. The caller falls back to the offline assistant.
        else {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Gemini network error: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 429)  throw new \RuntimeException('Gemini rate limit reached');
        if ($status === 400 || $status === 403) throw new \RuntimeException('Gemini rejected the request (check the API key and model name)');
        if ($status >= 400) {
            $j = json_decode((string) $body, true);
            throw new \RuntimeException('Gemini error HTTP ' . $status . ': ' . (string) ($j['error']['message'] ?? 'unknown'));
        }
        return (string) $body;
    }

    /** Best available CA bundle for this host. */
    private static function caBundle(): ?string
    {
        $candidates = [];
        if (defined('OPENSSL_CA_FILE')) $candidates[] = OPENSSL_CA_FILE;
        foreach ([
            '/etc/ssl/certs/ca-certificates.crt',              // Hostinger/Debian
            '/etc/pki/tls/certs/ca-bundle.crt',                // RHEL family
            '/etc/ssl/ca-bundle.pem',                          // SUSE
            '/usr/local/openssl/cert.pem',                     // Homebrew/macOS
            '/etc/ssl/cert.pem',
            'C:/xampp/php/extras/ssl/cacert.pem',              // local XAMPP
            'C:/xampp/apache/bin/curl-ca-bundle.crt',
        ] as $c) $candidates[] = $c;
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '' && is_file($c)) return $c;
        }
        return null;
    }

    /**
     * Back-compat wrapper used by the offline path.
     */
    public static function fallback(string $msg, string $topic = ''): string
    {
        return ChatbotKnowledge::fallback($msg, $topic);
    }
}