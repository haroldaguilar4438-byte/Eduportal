<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__,2).'/app');
require APP_ROOT.'/Core/bootstrap.php';
use App\Core\GeminiClient;
\App\Core\Auth::boot(); // idle-timeout + revoked-session + step-up guards

header('Content-Type: application/json; charset=utf-8');
// Same-origin only: no CORS wildcard (was Access-Control-Allow-Origin: *).
// Secrets come from app/config/credentials.php via bootstrap — never hardcoded here.

if(($_SERVER['REQUEST_METHOD']??'GET')==='GET' && ($_GET['action']??'')==='history'){
    if(!\App\Core\Auth::check()){ echo json_encode(['history'=>[],'guest'=>true]); exit; }
    $uid=(int)\App\Core\Auth::id();
    $rows=\App\Core\Database::all("SELECT sender, message, created_at FROM chat_history WHERE user_id=? ORDER BY id ASC LIMIT 100",[$uid]);
    echo json_encode(['history'=>$rows,'guest'=>false]);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){ http_response_code(405); echo json_encode(['error'=>'Use POST']); exit; }

$isGuest = !\App\Core\Auth::check();
// Authenticated posts require CSRF (session-riding protection). Guests keep
// the session throttle below (no per-user state to protect for them).
if (!$isGuest) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!\App\Core\Csrf::validate(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(419);
        echo json_encode(['error' => 'Security token mismatch. Refresh the page and try again.']);
        exit;
    }
}
$uid = $isGuest ? null : (int)\App\Core\Auth::id();

$raw=file_get_contents('php://input');
$data=json_decode($raw,true);
$msg=trim((string)($data['message'] ?? ''));
$history=$data['history'] ?? [];
if($msg===''){ http_response_code(400); echo json_encode(['error'=>'Message required']); exit; }
if(mb_strlen($msg)>2000) $msg=mb_substr($msg,0,2000);

// simple rate limit per IP per 2 sec
$ip=client_ip();
$key='chat_'.md5($ip);
if(isset($_SESSION[$key]) && time() - (int)$_SESSION[$key] < 2){ http_response_code(429); echo json_encode(['error'=>'Too fast, please wait 2s']); exit; }
$_SESSION[$key]=time();

// save user message only if logged in (guests: no DB)
if(!$isGuest){
    try{ \App\Core\Database::insert('chat_history',['user_id'=>$uid,'sender'=>'user','message'=>$msg]); } catch(Throwable $e){ storage_log('chat save user failed: '.$e->getMessage()); }
}

try{
    $client=new GeminiClient();
    $reply=$client->chat($msg, is_array($history)?$history:[]);
    if(!$isGuest){ try{ \App\Core\Database::insert('chat_history',['user_id'=>$uid,'sender'=>'ai','message'=>$reply]); } catch(Throwable $e){ storage_log('chat save ai failed: '.$e->getMessage()); } }
    echo json_encode(['reply'=>$reply,'mode'=>'gemini','guest'=>$isGuest]);
} catch(Throwable $e){
    storage_log('chatbot gemini failed: '.$e->getMessage());
    $fallback=GeminiClient::fallback($msg);
    if(!$isGuest){ try{ \App\Core\Database::insert('chat_history',['user_id'=>$uid,'sender'=>'ai','message'=>$fallback]); } catch(Throwable $e2){} }
    echo json_encode(['reply'=>$fallback,'mode'=>'fallback','note'=>$e->getMessage(),'guest'=>$isGuest]);
}
