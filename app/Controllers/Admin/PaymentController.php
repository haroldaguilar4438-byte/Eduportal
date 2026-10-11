<?php
declare(strict_types=1);
namespace App\Controllers\Admin;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;
final class PaymentController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin', 'accounting');
        $status = $_GET['status'] ?? 'processing';
        $allowed = ['pending','processing','paid','failed','cancelled','expired','all'];
        if (!in_array($status,$allowed,true)) $status='processing';
        if ($status === 'failed') {
            $where = "WHERE p.status IN ('failed','cancelled','expired')";
            $arg = [];
        } else {
            $where = $status==='all' ? '' : 'WHERE p.status=?';
            $arg = $status==='all' ? [] : [$status];
        }
        $rows = Database::all("SELECT p.*, u.email, u.first_name, u.last_name, s.student_no, ea.application_no FROM payments p LEFT JOIN students s ON s.id=p.student_id LEFT JOIN users u ON u.id=s.user_id OR u.id=p.initiated_by LEFT JOIN enrollment_applications ea ON ea.id=p.enrollment_application_id $where ORDER BY p.created_at DESC LIMIT 100", $arg);
        $counts=[];
        foreach(['pending','processing','paid','failed','cancelled','expired'] as $s) $counts[$s]=(int)Database::value("SELECT COUNT(*) FROM payments WHERE status=?",[$s]);
        $counts['failed'] = $counts['failed'] + $counts['cancelled'] + $counts['expired'];
        $counts['all']=(int)Database::value("SELECT COUNT(*) FROM payments");
        $this->view('admin/payments/index',['title'=>'Payments — PayRex','rows'=>$rows,'status'=>$status,'counts'=>$counts]);
    }

    public function approve(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'accounting');
        $id=(int)($params['id']??0);
        $pay=Database::one("SELECT * FROM payments WHERE id=? LIMIT 1",[$id]);
        if(!$pay || !in_array($pay['status'],['pending','processing'],true)){ set_flash('error','Payment not awaiting approval.'); redirect('/admin/payments'); }
        try{
            Database::transaction(function() use ($pay,$id){
                Database::run("UPDATE payments SET status='paid', paid_at=NOW() WHERE id=?",[$id]);
                $receiptNo='RC-'.date('Y').'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
                try{ Database::insert('receipts',['payment_id'=>$id,'receipt_no'=>$receiptNo]); } catch(Throwable $e){}
                if(!empty($pay['enrollment_application_id'])){
                    Database::run("UPDATE enrollment_applications SET status='approved', reviewed_at=NOW(), reviewed_by=? WHERE id=? AND status='awaiting_payment'", [(int)Auth::id(), (int)$pay['enrollment_application_id']]);
                    $app=Database::one("SELECT student_id FROM enrollment_applications WHERE id=? LIMIT 1", [(int)$pay['enrollment_application_id']]);
                    if($app && $app['student_id']) Database::run("UPDATE students SET status='enrolled' WHERE id=?",[(int)$app['student_id']]);
                }
            });
            // Tuition-plan allocation: downpayment/installment states, schedule
            // activation, ledger allocation, completion audit (idempotent).
            $planResult = ['allocated' => 0.0, 'completed' => false, 'activated' => false, 'kind' => null];
            try {
                if (class_exists(\App\Core\Tuition::class)) {
                    $planResult = \App\Core\Tuition::applyPaidPayment($id, (int)Auth::id());
                }
            } catch(Throwable $e){}
            // send receipt email
            $payFresh=Database::one("SELECT * FROM payments WHERE id=? LIMIT 1",[$id]);
            $receipt=Database::one("SELECT * FROM receipts WHERE payment_id=? LIMIT 1",[$id]);
            $payer=null;
            if($payFresh['student_id']) $payer=Database::one("SELECT u.* FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=? LIMIT 1",[(int)$payFresh['student_id']]);
            if(!$payer && $payFresh['initiated_by']) $payer=Database::one("SELECT * FROM users WHERE id=? LIMIT 1",[(int)$payFresh['initiated_by']]);
            if(!$payer && $payFresh['enrollment_application_id']) $payer=Database::one("SELECT u.* FROM enrollment_applications ea JOIN users u ON u.id=ea.user_id WHERE ea.id=? LIMIT 1",[(int)$payFresh['enrollment_application_id']]);
            if($payer && $receipt){
                try{
                    $kindLabel = !empty($planResult['kind']) ? ucfirst((string) $planResult['kind']) : 'Full Tuition';
                    $remAfter = null;
                    try {
                        if (!empty($payFresh['enrollment_application_id']) && class_exists(\App\Core\Tuition::class)) {
                            $sum = \App\Core\Tuition::planSummary((int) $payFresh['enrollment_application_id']);
                            if (!empty($sum['has_plan'])) $remAfter = (float) $sum['remaining'];
                        }
                    } catch(Throwable $e){}
                    send_payment_receipt_email($payer, ['receipt_no'=>$receipt['receipt_no'],'transaction_ref'=>$payFresh['transaction_ref'],'amount'=>(float)$payFresh['amount'],'method_label'=>ucfirst($payFresh['payment_method']),'paid_at'=>date('Y-m-d H:i:s'),'student_name'=>trim(($payer['first_name']??'').' '.($payer['last_name']??'')),'payment_type'=>$kindLabel,'remaining_balance'=>$remAfter]);
                    Database::run("UPDATE receipts SET emailed_to=?, emailed_at=NOW() WHERE id=?",[$payer['email'], (int)$receipt['id']]);
                } catch(Throwable $e){}
                try{
                    $extraNote = '';
                    if (!empty($planResult['activated'])) $extraNote = ' Monthly installment plan is now active.';
                    elseif (!empty($planResult['completed'])) $extraNote = ' Tuition fully paid.';
                    Database::insert('notifications',['user_id'=>(int)$payer['id'],'title'=>'Payment approved — receipt sent','message'=>'Your payment '.$payFresh['transaction_ref'].' ('.money_plain($payFresh['amount']).') was approved. Receipt '.$receipt['receipt_no'].' emailed.'.$extraNote,'type'=>'payment','link_url'=>'/my-enrollments']);
                } catch(Throwable $e){}
            }
            Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'payment.admin_approved','entity_type'=>'payments','entity_id'=>$id,'ip_address'=>client_ip()]);
            // Parent-filed enrollment paid here: link filer to child (idempotent).
            try {
                if (!empty($pay['enrollment_application_id']) && class_exists(\App\Core\GuardianLinks::class)) {
                    \App\Core\GuardianLinks::ensureForApplication((int) $pay['enrollment_application_id'], (int)Auth::id());
                }
            } catch (Throwable $e) {}
            set_flash('success','Payment '.$pay['transaction_ref'].' approved — receipt '.($receipt['receipt_no'] ?? '').' sent.');
        }catch(Throwable $e){ storage_log('admin pay approve failed '.$id.': '.$e->getMessage()); set_flash('error','Failed: '.$e->getMessage()); }
        redirect('/admin/payments');
    }

    public function reject(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'accounting');
        $id=(int)($params['id']??0);
        $pay=Database::one("SELECT * FROM payments WHERE id=? LIMIT 1",[$id]);
        if(!$pay || !in_array($pay['status'],['pending','processing'],true)){ set_flash('error','Payment not awaiting approval.'); redirect('/admin/payments'); }
        $reason=trim((string)($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if(mb_strlen($reason) < 5){ set_flash('error','A rejection reason (min 5 characters) is required.'); redirect('/admin/payments'); }
        Database::run("UPDATE payments SET status='failed', failure_reason=? WHERE id=?",[mb_substr($reason, 0, 255), $id]);
        if($pay['enrollment_application_id']){
            Database::run("UPDATE enrollment_applications SET status='rejected', remarks=? WHERE id=?",['Payment rejected: '.($reason ?: 'admin review').' — please re-apply or contact registrar.', (int)$pay['enrollment_application_id']]);
            try {
                $clr = Database::one("SELECT clearance_id FROM enrollment_applications WHERE id=? LIMIT 1", [(int)$pay['enrollment_application_id']]);
                if ($clr && !empty($clr['clearance_id'])) {
                    Database::run("UPDATE clearances SET status='issued' WHERE id=? AND status='used'", [(int)$clr['clearance_id']]);
                    Database::insert('audit_logs', ['actor_user_id'=>(int)Auth::id(),'action'=>'clearance.restored','entity_type'=>'clearances','entity_id'=>(int)$clr['clearance_id'],'ip_address'=>client_ip()]);
                }
            } catch (Throwable $e) {
            }
            try{
                $appUser = Database::one("SELECT user_id, application_no FROM enrollment_applications WHERE id=? LIMIT 1", [(int)$pay['enrollment_application_id']]);
                if($appUser){
                    Database::insert('notifications',['user_id'=>(int)$appUser['user_id'],'title'=>'Payment rejected','message'=>'Your payment '.$pay['transaction_ref'].' was rejected'.($reason ? ': '.$reason : '').'. Application '.$appUser['application_no'].' is now marked Rejected — see remarks.','type'=>'danger','link_url'=>'/my-enrollments']);
                    $u = Database::one("SELECT * FROM users WHERE id=? LIMIT 1", [(int)$appUser['user_id']]);
                    if($u) send_enrollment_status_email($u, $appUser['application_no'], 'Rejected — Payment not verified', $reason ?: 'Payment rejected by admin — verify PayRex and retry.');
                }
            } catch(Throwable $e){}
        }
        set_flash('success','Payment rejected — student sees Rejected.');
        redirect('/admin/payments');
    }

    public function resendReceipt(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'accounting');
        $id=(int)($params['id']??0);
        $pay=Database::one("SELECT * FROM payments WHERE id=? LIMIT 1",[$id]);
        if(!$pay || $pay['status']!=='paid'){ set_flash('error','Only paid payments have receipts.'); redirect('/admin/payments'); }
        $receipt=Database::one("SELECT * FROM receipts WHERE payment_id=? LIMIT 1",[$id]);
        if(!$receipt){ set_flash('error','No receipt found.'); redirect('/admin/payments'); }
        $payer=null;
        if($pay['student_id']) $payer=Database::one("SELECT u.* FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=? LIMIT 1",[(int)$pay['student_id']]);
        if(!$payer && $pay['initiated_by']) $payer=Database::one("SELECT * FROM users WHERE id=? LIMIT 1",[(int)$pay['initiated_by']]);
        if($payer){
            try{
                send_payment_receipt_email($payer, ['receipt_no'=>$receipt['receipt_no'],'transaction_ref'=>$pay['transaction_ref'],'amount'=>(float)$pay['amount'],'method_label'=>ucfirst($pay['payment_method']),'paid_at'=>$pay['paid_at'] ?? date('Y-m-d H:i:s'),'student_name'=>trim(($payer['first_name']??'').' '.($payer['last_name']??''))]);
                Database::run("UPDATE receipts SET emailed_to=?, emailed_at=NOW() WHERE id=?",[$payer['email'], (int)$receipt['id']]);
                set_flash('success','Receipt '.$receipt['receipt_no'].' resent to '.$payer['email']);
            } catch(Throwable $e){ set_flash('error','Send failed: '.$e->getMessage()); }
        } else { set_flash('error','Payer not found.'); }
        redirect('/admin/payments');
    }
}
