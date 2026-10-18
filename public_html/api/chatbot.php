<?php
declare(strict_types=1);
/**
 * EduPortal chat API (single JSON endpoint).
 *
 * GET  ?action=history  -> this user's recent chat turns
 * GET  ?action=suggest  -> role-appropriate starter suggestions
 * POST                  -> send a message, get a reply
 * POST {reset:true}     -> clear this user's chat history
 *
 * Security:
 *   - History is always scoped to the signed-in user_id; guests get nothing
 *     stored and nothing readable.
 *   - Authenticated POSTs require the session CSRF token.
 *   - The AI provider key stays server-side (app/config/credentials.php).
 *   - Provider error text is logged, never returned to the browser.
 */
define('APP_ROOT', dirname(__DIR__, 2) . '/app');
require APP_ROOT . '/Core/bootstrap.php';

use App\Core\Auth;
use App\Core\ChatbotKnowledge;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\GeminiClient;

Auth::boot();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action  = (string) ($_GET['action'] ?? '');

/* ------------------------------ read-only actions ------------------------- */

if ($method === 'GET') {
    if ($action === 'suggest') {
        echo json_encode([
            'suggestions' => ChatbotKnowledge::suggestions(Auth::check() ? Auth::role() : null),
            'role'        => Auth::check() ? Auth::role() : 'guest',
        ]);
        exit;
    }
    if ($action === 'history') {
        if (!Auth::check()) {
            echo json_encode(['history' => [], 'guest' => true]);
            exit;
        }
        $rows = [];
        try {
            $rows = Database::all(
                'SELECT sender, message FROM chat_history WHERE user_id = ? ORDER BY id DESC LIMIT 40',
                [(int) Auth::id()]
            );
            $rows = array_reverse($rows);
        } catch (Throwable $e) {
            storage_log('chat history read failed: ' . $e->getMessage());
        }
        $history = array_map(static fn($r) => [
            'role' => $r['sender'] === 'user' ? 'user' : 'model',
            'text' => (string) $r['message'],
        ], $rows);
        echo json_encode([
            'history'     => $history,
            'guest'       => false,
            'role'        => Auth::role(),
            'suggestions' => ChatbotKnowledge::suggestions(Auth::role()),
        ]);
        exit;
    }
    http_response_code(405);
    echo json_encode(['error' => 'Unsupported action']);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST']);
    exit;
}

/* --------------------------------- POST ---------------------------------- */

$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) $data = [];

$isGuest = !Auth::check();
if (!$isGuest) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Csrf::validate(is_string($token) ? $token : null)) {
        http_response_code(419);
        echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
        exit;
    }
}
$uid = $isGuest ? null : (int) Auth::id();

/* Start a new conversation: delete only this user's rows. */
if (!empty($data['reset'])) {
    if ($isGuest) {
        echo json_encode(['ok' => true, 'guest' => true]);
        exit;
    }
    try {
        Database::run('DELETE FROM chat_history WHERE user_id = ?', [$uid]);
    } catch (Throwable $e) {
        storage_log('chat history reset failed: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true, 'guest' => false, 'suggestions' => ChatbotKnowledge::suggestions(Auth::role())]);
    exit;
}

$msg = trim((string) ($data['message'] ?? ''));
if ($msg === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Please type a message first.']);
    exit;
}
if (mb_strlen($msg) > 2000) $msg = mb_substr($msg, 0, 2000);

/* Never let credentials reach chat_history, the AI provider, or the logs. */
$msg = ChatbotKnowledge::redact($msg);

/* Per-IP throttle: 1 message / 2s. */
$ip  = client_ip();
$key = 'chat_' . md5($ip);
if (isset($_SESSION[$key]) && time() - (int) $_SESSION[$key] < 2) {
    http_response_code(429);
    echo json_encode(['error' => 'One at a time please - give me a second.']);
    exit;
}
$_SESSION[$key] = time();

/* History: server is authoritative for signed-in users. Guests send theirs. */
$history = [];
if ($isGuest) {
    $sent = is_array($data['history'] ?? null) ? $data['history'] : [];
    foreach (array_slice($sent, -12) as $h) {
        if (is_array($h) && isset($h['role'], $h['text'])) {
            $history[] = ['role' => $h['role'] === 'user' ? 'user' : 'model', 'text' => (string) $h['text']];
        }
    }
} else {
    try {
        $rows = Database::all(
            'SELECT sender, message FROM chat_history WHERE user_id = ? ORDER BY id DESC LIMIT 12',
            [$uid]
        );
        foreach (array_reverse($rows) as $r) {
            $history[] = ['role' => $r['sender'] === 'user' ? 'user' : 'model', 'text' => (string) $r['message']];
        }
    } catch (Throwable $e) {
        storage_log('chat context read failed: ' . $e->getMessage());
    }
}

$lastTopic = '';
foreach (array_reverse($history) as $h) {
    if ($h['role'] === 'user') { $lastTopic = ChatbotKnowledge::topicOf($h['text']); break; }
}

/* Persist the user's turn before replying. */
if (!$isGuest) {
    try {
        Database::insert('chat_history', ['user_id' => $uid, 'sender' => 'user', 'message' => $msg]);
    } catch (Throwable $e) {
        storage_log('chat save user failed: ' . $e->getMessage());
    }
}

$reply   = '';
$mode    = 'gemini';
$context = null;
if (!$isGuest) {
    try {
        $context = ChatbotKnowledge::accountSnapshot();
    } catch (Throwable $e) {
        $context = null;
    }
}

try {
    $client = new GeminiClient();
    $reply  = $client->chat($msg, $history, $context);
} catch (Throwable $e) {
    storage_log('chatbot gemini failed: ' . $e->getMessage());
    $reply = ChatbotKnowledge::fallback($msg, $lastTopic);
    $mode  = 'fallback';
}

if (!$isGuest) {
    try {
        Database::insert('chat_history', ['user_id' => $uid, 'sender' => 'ai', 'message' => $reply]);
    } catch (Throwable $e) {
        storage_log('chat save ai failed: ' . $e->getMessage());
    }
}

echo json_encode([
    'reply'       => $reply,
    'mode'        => $mode,
    'guest'       => $isGuest,
    'suggestions' => $isGuest ? null : ChatbotKnowledge::suggestions(Auth::role()),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);