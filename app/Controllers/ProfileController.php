<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;
final class ProfileController extends Controller
{
    public function show(array $params=[]): void
    {
        Auth::requireRole('admin','teacher','student','parent','registrar','accounting');
        $user = Auth::user();
        $uid = (int)$user['id'];
        $role = $user['role_name'] ?? '';
        $student = null; $teacher = null; $parentStudents = [];
        if ($role === 'student') {
            $student = Database::one("SELECT s.student_no, s.status AS student_status, s.admission_date, gl.name AS level_name, gl.code AS level_code, p.name AS program_name FROM students s LEFT JOIN grade_levels gl ON gl.id=s.grade_level_id LEFT JOIN programs p ON p.id=s.program_id WHERE s.user_id=? LIMIT 1", [$uid]);
        } elseif ($role === 'teacher') {
            $teacher = Database::one("SELECT employee_no, department, position FROM teachers WHERE user_id=? LIMIT 1", [$uid]);
        } elseif ($role === 'parent') {
            $parentStudents = Database::all("SELECT s.student_no, gl.name AS level_name, CONCAT(u.first_name,' ',u.last_name) AS child_name FROM guardian_students gs JOIN students s ON s.id=gs.student_id JOIN users u ON u.id=s.user_id JOIN grade_levels gl ON gl.id=s.grade_level_id WHERE gs.guardian_id=(SELECT id FROM guardians WHERE user_id=? LIMIT 1)", [$uid]);
        }
        $this->view('profile/show',['title'=>'My Profile','user'=>$user,'student'=>$student,'teacher'=>$teacher,'parentStudents'=>$parentStudents]);
    }

    public function update(array $params=[]): void
    {
        Csrf::guard();
        Auth::requireRole('admin','teacher','student','parent','registrar','accounting');
        $user = Auth::user();
        $uid = (int)$user['id'];

        $first = trim((string)($_POST['first_name'] ?? ''));
        $middle = trim((string)($_POST['middle_name'] ?? ''));
        $last = trim((string)($_POST['last_name'] ?? ''));
        $suffix = trim((string)($_POST['suffix'] ?? ''));
        $phone = preg_replace('/\D/','', (string)($_POST['phone'] ?? ''));

        $errors=[];
        if($first==='' || $last==='') $errors[]='First and last name required.';
        if($phone!=='' && !preg_match('/^09\d{9}$/',$phone)) $errors[]='Mobile must be 11 digits starting with 09.';

        // avatar upload
        $avatarPath = $user['avatar_path'] ?? null;
        if(!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error']===UPLOAD_ERR_OK){
            $f=$_FILES['avatar'];
            if($f['size']> 2*1024*1024) $errors[]='Profile picture must be under 2MB.';
            else {
                $finfo=finfo_open(FILEINFO_MIME_TYPE);
                $mime=$finfo?finfo_file($finfo,$f['tmp_name']):'';
                if($finfo) finfo_close($finfo);
                if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)) $errors[]='Avatar must be JPG, PNG or WebP.';
                else {
                    $ext=match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp', default=>'jpg'};
                    $dir=UPLOAD_DIR.'/avatars';
                    if(!is_dir($dir)) @mkdir($dir,0775,true);
                    $name='avatar_'.$uid.'_'.bin2hex(random_bytes(8)).'.'.$ext;
                    $dest=$dir.'/'.$name;
                    if(move_uploaded_file($f['tmp_name'],$dest)){
                        $avatarPath='uploads/avatars/'.$name;
                        // delete old
                        if(!empty($user['avatar_path']) && is_file(ROOT_PATH.'/public_html/'.$user['avatar_path'])) @unlink(ROOT_PATH.'/public_html/'.$user['avatar_path']);
                    } else $errors[]='Failed to save avatar.';
                }
            }
        }

        if($errors){
            foreach($errors as $e) set_flash('error',$e);
            redirect('/profile');
        }

        try{
            Database::run("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, phone=?, avatar_path=? WHERE id=?", [$first, $middle ?: null, $last, $suffix ?: null, $phone ?: null, $avatarPath, $uid]);
            set_flash('success','Profile updated.');
        } catch(Throwable $e){ storage_log('profile update failed: '.$e->getMessage()); set_flash('error','Update failed: '.$e->getMessage()); }
        redirect('/profile');
    }

    public function updatePassword(array $params=[]): void
    {
        Csrf::guard();
        Auth::requireRole('admin','teacher','student','parent','registrar','accounting');
        $uid=(int)Auth::id();
        $current=(string)($_POST['current_password'] ?? '');
        $new=(string)($_POST['new_password'] ?? '');
        $confirm=(string)($_POST['confirm_password'] ?? '');

        $user=Database::one("SELECT password_hash FROM users WHERE id=? LIMIT 1",[$uid]);
        if(!$user || !password_verify($current, $user['password_hash'])){ set_flash('error','Current password is incorrect.'); redirect('/profile'); }

        if($new!==$confirm){ set_flash('error','New password confirmation does not match.'); redirect('/profile'); }
        $weak=Auth::assertPasswordStrength($new);
        if($weak){ set_flash('error','New password needs: '.implode(', ',$weak).'.'); redirect('/profile'); }

        Database::run("UPDATE users SET password_hash=? WHERE id=?",[password_hash($new,PASSWORD_DEFAULT),$uid]);
        // Password change invalidates other sessions (current one stays).
        try {
            \App\Core\Mfa::revokeOtherSessions($uid);
            \App\Core\Mfa::clearAllMfaExemptions($uid);
            \App\Core\Mfa::event($uid, 'password.changed');
            $u = Database::one("SELECT * FROM users WHERE id=? LIMIT 1", [$uid]);
            if ($u !== null) send_password_changed_email($u);
        } catch(Throwable $e){ storage_log('password change notify failed: '.$e->getMessage()); }
        set_flash('success','Password changed successfully. Other devices were logged out.');
        redirect('/profile');
    }
}
