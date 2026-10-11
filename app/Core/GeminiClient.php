<?php
declare(strict_types=1);
namespace App\Core;
use Throwable;
final class GeminiClient
{
    private string $apiKey;
    private string $model;
    private string $base = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(?string $apiKey=null, ?string $model=null)
    {
        $this->apiKey = $apiKey ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
        $this->model = $model ?? (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash');
        if ($this->apiKey==='') throw new \RuntimeException('Gemini API key not configured');
    }

    public function chat(string $userMessage, array $history=[]): string
    {
        $system = defined('SYSTEM_CONTEXT') ? SYSTEM_CONTEXT : "You are a helpful assistant for a School Management System. You help students, teachers, and admins with questions about grades, schedules, enrollment, and school policies. Keep answers short, friendly, and professional. If you don't know something specific, suggest contacting the school admin.";
        $contents=[];
        if(!empty($history)){
            $contents[]=['role'=>'user','parts'=>[['text'=>$system]]];
            $contents[]=['role'=>'model','parts'=>[['text'=>'Understood. I will help as EduPortal assistant.']]];
            foreach($history as $h){
                if(!isset($h['role'],$h['text'])) continue;
                $role = $h['role']==='user'?'user':'model';
                $contents[]=['role'=>$role,'parts'=>[['text'=>mb_substr((string)$h['text'],0,2000)]]];
            }
            $contents[]=['role'=>'user','parts'=>[['text'=>mb_substr($userMessage,0,2000)]]];
        } else {
            $contents[]=['role'=>'user','parts'=>[['text'=>$system . "\n\nUser: " . mb_substr($userMessage,0,2000)]]];
        }

        $payload = json_encode(['contents'=>$contents,'generationConfig'=>['temperature'=>0.7,'maxOutputTokens'=>512]]);
        $url = defined('GEMINI_API_URL') ? GEMINI_API_URL : $this->base.'/models/'.rawurlencode($this->model).':generateContent?key='.urlencode($this->apiKey);

        $ch=curl_init($url);
        $ca=null; foreach(['C:/xampp/php/extras/ssl/cacert.pem','C:/xampp/apache/bin/curl-ca-bundle.crt'] as $c) if(is_file($c)){$ca=$c; break;}
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_SSL_VERIFYPEER=>$ca?true:false,
            CURLOPT_SSL_VERIFYHOST=>$ca?2:0,
            CURLOPT_CAINFO=>$ca?:null,
        ]);
        $body=curl_exec($ch);
        if($body===false) throw new \RuntimeException('Gemini network error: '.curl_error($ch));
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json=json_decode($body,true);
        if($status>=400){
            $msg=$json['error']['message'] ?? 'Gemini error HTTP '.$status;
            throw new \RuntimeException($msg);
        }
        $text=$json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if(trim($text)==='') $text="Sorry, I couldn't generate a response. Please try rephrasing.";
        return trim($text);
    }

    public static function fallback(string $msg): string
    {
        $m=strtolower(trim($msg));
        if(preg_match('/\b(verify|verification|verified|otp|email.*verify)\b/i',$m)) return "Hey! 😊 For Verification:\n\n- After you register, you go to /verify-pending\n- We sent you a link that lasts 24 hours, or you can get a 6-digit code that lasts 10 minutes\n- Just click the link or type the code, the page checks every 3 seconds and will show Verified then go to Sign in\n- Buttons have 60 seconds wait before you can resend";
        if(str_contains($m,'enroll')) return "Enroll time! 🎓\n\n- Go to /enroll and fill your info and upload your files\n- Click Submit and check /my-enrollments\n- Steps: pending -> Accounting check -> Registrar check -> Awaiting Payment -> Pay with PayRex at /enroll/id/pay -> waiting for admin -> Admin approves in /admin/payments -> Approved and Enrolled! Then My Subjects will open.";
        if(str_contains($m,'pay')||str_contains($m,'payrex')||str_contains($m,'payment')) return "Payment with PayRex:\n\n- After Accounting and Registrar approve, you will see Pay Now in My Applications with your exact amount (GCash, Maya, Card, QRPh - follows the school tuition plan)\n- After you pay, status is processing and waits for admin\n- Admin goes to Payments and clicks Approve and Send Receipt, then you are paid and get your receipt by email.";
        if(str_contains($m,'permit')) return "Exam Permits:\n\n- You need to be fully enrolled first\n- Then request in order: Prelim, then Midterm, then Prefinal, then Finals\n- Each one needs Accounting, then Registrar, then Teacher to approve\n- You can request them on your Dashboard or at /student/permits";
        if(str_contains($m,'subject')||str_contains($m,'classroom')||str_contains($m,'invite')||str_contains($m,'join')) return "My Subjects:\n\n- Only after you are fully enrolled\n- Admin creates the official subject and assigns your teacher, you get a code like CS7K9P2\n- You paste it at Dashboard -> My Subjects -> Validate and preview, then Confirm join\n- Then your tasks will show up";
        if(str_contains($m,'grade')||str_contains($m,'schedule')) return "For grades and schedules, just check your Dashboard after you are enrolled and joined your subjects. Everything is there! If you need more details, ask your teacher.";
        $offTopic = preg_match('/\b(basketball|football|game|joke|love|personal|politics|music|movie|sport|math|weather|hello|hi|hey)\b/i',$m) && !preg_match('/\b(enroll|pay|permit|subject|verify|grade|schedule|account|profile|password|payment|enrollment)\b/i',$m);
        if($offTopic || mb_strlen($m) < 3 || in_array($m,['verification','verify','hi','hello','hey','help'],true)){
            if(preg_match('/\b(verify|verification)\b/i',$m)) return "Hey! 😊 For Verification:\n\n- After you register, you go to /verify-pending\n- We sent you a link that lasts 24 hours, or you can get a 6-digit code that lasts 10 minutes\n- Just click the link or type the code, the page checks every 3 seconds and will show Verified then go to Sign in\n- Buttons have 60 seconds wait before you can resend";
            return "Haha that's outside what I can do 😅\n\nI'm best at helping with Enrollment, PayRex, Permits, Subjects and Verification.\n\nFor other things, please talk to the admin at datamex82@gmail.com or visit the Registrar.\n\nWhat can I help you with about the portal today?";
        }
        return "Hey there! 😊 I'm EduPortal Buddy for Datamex College!\n\nI can help you with:\n- Enrollment\n- PayRex payments\n- Exam Permits\n- Subjects\n- Verification and Accounts\n\nJust tell me what you need help with! For anything not about the portal, please talk to the admin at datamex82@gmail.com 😊";
    }
}
