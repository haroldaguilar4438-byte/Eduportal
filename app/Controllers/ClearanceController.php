<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

final class ClearanceController extends Controller
{
    /* ================= Student: list & request ================= */
    public function studentIndex(array $params = []): void
    {
        Auth::requireRole('student','parent');
        $uid = (int)Auth::id();
        $student = Database::one("SELECT id FROM students WHERE user_id=? LIMIT 1", [$uid]);
        $sid = $student ? (int)$student['id'] : 0;
        // Parents view a linked child's clearances (ownership-checked ?child_id, else first child).
        if ($sid <= 0 && Auth::is('parent')) {
            $g = Database::one('SELECT id FROM guardians WHERE user_id = ? LIMIT 1', [$uid]);
            if ($g) {
                $kid = (int) ($_GET['child_id'] ?? 0);
                if ($kid > 0) {
                    $owned = Database::one(
                        'SELECT s.id FROM guardian_students gs JOIN students s ON s.id = gs.student_id WHERE gs.guardian_id = ? AND s.id = ? LIMIT 1',
                        [(int) $g['id'], $kid]
                    );
                    if ($owned) $sid = (int) $owned['id'];
                }
                if ($sid <= 0) {
                    $first = Database::one(
                        'SELECT s.id FROM guardian_students gs JOIN students s ON s.id = gs.student_id WHERE gs.guardian_id = ? ORDER BY s.id LIMIT 1',
                        [(int) $g['id']]
                    );
                    if ($first) $sid = (int) $first['id'];
                }
            }
        }
        // Parents view the acting owned child (?child_id= validated, else first).
        $actingChildId = 0;
        if (Auth::is('parent')) {
            if (class_exists(\App\Core\GuardianLinks::class)) {
                $kid = (int) ($_GET['child_id'] ?? 0);
                if ($kid > 0 && !\App\Core\GuardianLinks::ownedChild($uid, $kid)) {
                    set_flash('error', 'That child is not linked to your guardian account.');
                    redirect('/dashboard');
                }
                $child = \App\Core\GuardianLinks::ownedChild($uid, $kid);
                if ($child) {
                    $sid = (int) $child['id'];
                    $actingChildId = $sid;
                } else {
                    $sid = 0;
                }
            } else {
                $sid = 0;
            }
        }
        $rows = $sid ? Database::all("SELECT c.*, ay.school_year FROM clearances c JOIN academic_years ay ON ay.id=c.academic_year_id WHERE c.student_id=? ORDER BY c.created_at DESC", [$sid]) : [];
        $canRequest = false;
        $reason = '';
        $currentPeriodLabel = '';
        if ($sid) {
            $check = $this->canRequestClearance($sid);
            $canRequest = $check['ok'];
            $reason = $check['reason'] ?? '';
            try {
                if (class_exists(\App\Core\CollegeEligibility::class)) {
                    $cctx = \App\Core\CollegeEligibility::currentContext($sid);
                    if ($cctx && ($cctx['has_active_enrollment'] ?? false)) {
                        $currentPeriodLabel = ($cctx['period_label'] ?? '') . ' · ' . ($cctx['ay_label'] ?? '');
                    }
                }
            } catch (Throwable $e) {
            }
        }
        $linkedChildren = [];
        if (Auth::is('parent') && class_exists(\App\Core\GuardianLinks::class)) {
            $linkedChildren = \App\Core\GuardianLinks::linkedChildren($uid);
        }
        $this->view('student/clearances_index', ['title'=>'My Clearances','clearances'=>$rows,'canRequest'=>$canRequest,'reason'=>$reason,'currentPeriodLabel'=>$currentPeriodLabel,'isParent'=>Auth::is('parent'),'linkedChildren'=>$linkedChildren,'actingChildId'=>$actingChildId]);
    }

    public function request(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        $uid = (int)Auth::id();
        // Parents file ON BEHALF of an owned linked child (?child_id=
        // ownership-checked, else first child); tampering is rejected.
        $viaParent = false;
        if (Auth::is('parent')) {
            if (!class_exists(\App\Core\GuardianLinks::class)) {
                set_flash('error', 'No linked child found.');
                redirect('/student/clearances');
            }
            $kid = (int) ($_REQUEST['child_id'] ?? 0);
            if ($kid > 0 && !\App\Core\GuardianLinks::ownedChild($uid, $kid)) {
                set_flash('error', 'That child is not linked to your guardian account.');
                redirect('/dashboard');
            }
            $child = \App\Core\GuardianLinks::ownedChild($uid, $kid);
            if ($child === null) {
                set_flash('error', 'No linked child found. The registrar links your guardian profile after enrollment approval.');
                redirect('/student/clearances');
            }
            $student = $child;
            $viaParent = true;
        } else {
            $student = Database::one("SELECT * FROM students WHERE user_id=? LIMIT 1", [$uid]);
        }
        if (!$student || ($student['status'] ?? '') !== 'enrolled') { set_flash('warning', $viaParent ? 'Your child must be enrolled to request clearance.' : 'You must be enrolled to request clearance.'); redirect('/student/clearances'); }
        $sid = (int)$student['id'];
        // Academic period comes from the student's CURRENT enrollment —
        // never from POST (basic-ed: Quarter/Term; SHS/College: semester).
        $ctx = class_exists(\App\Core\CollegeEligibility::class)
            ? \App\Core\CollegeEligibility::currentContext($sid) : null;
        if ($ctx === null || !$ctx['has_active_enrollment'] || trim((string)($ctx['period'] ?? '')) === '') {
            set_flash('warning', 'You need an active enrollment with a determined academic period before requesting clearance.');
            redirect('/student/clearances');
        }
        $band = (string) ($ctx['band'] ?? \App\Core\AcademicPeriod::BAND_COLLEGE);
        $semester = trim((string) $ctx['period']);
        $ayId = (int) ($ctx['ay_id'] ?? 0);
        if (!$ayId) {
            $curAy = Database::one("SELECT id FROM academic_years WHERE is_current=1 LIMIT 1");
            $ayId = $curAy ? (int)$curAy['id'] : 0;
        }
        if (!$ayId) { set_flash('error','No active academic year.'); redirect('/student/clearances'); }
        $periodLabel = \App\Core\AcademicPeriod::label($band, $semester);
        $check = $this->canRequestClearance($sid, $semester, $ayId);
        if (!$check['ok']) { set_flash('warning', $check['reason'] ?? 'Not eligible for clearance.'); redirect('/student/clearances'); }
        $exists = Database::one("SELECT id, status FROM clearances WHERE student_id=? AND academic_year_id=? AND semester=? LIMIT 1", [$sid,$ayId,$semester]);
        if ($exists) { set_flash('info','Clearance already '.$exists['status'].' for '.$periodLabel.'.'); redirect('/student/clearances'); }
        $qr = bin2hex(random_bytes(32));
        try {
            $cid = Database::transaction(function () use ($sid, $ayId, $semester, $qr, $uid, $student) {
                $id = Database::insert('clearances', ['student_id'=>$sid,'academic_year_id'=>$ayId,'semester'=>$semester,'status'=>'pending','qr_token'=>$qr,'requested_at'=>date('Y-m-d H:i:s')]);
                // Per-subject signing items — one per enrolled subject so ALL of the
                // student's professors must sign (invite-code subjects + legacy).
                self::buildSigningItems($id, $sid, $student['section_id'] ?? null);
                Database::insert('audit_logs',['actor_user_id'=>$uid,'action'=>'clearance.requested','entity_type'=>'clearances','entity_id'=>$id,'ip_address'=>client_ip()]);
                return $id;
            });
            // Notify the ASSIGNED teachers directly; broadcast only when the
            // clearance has no subject items (any professor may sign).
            try {
                $assignees = Database::all(
                    "SELECT DISTINCT t.user_id FROM clearance_items ci JOIN teachers t ON t.id = ci.teacher_id WHERE ci.clearance_id = ? AND t.user_id IS NOT NULL",
                    [$cid]
                );
                if ($assignees === []) {
                    NotificationController::pushToTeachers('New clearance request (' . $periodLabel . ')', 'Student #' . $sid . ' requested ' . $periodLabel . ' clearance — review the signing queue.', 'info', '/teacher/clearances');
                } else {
                    foreach ($assignees as $a) {
                        NotificationController::push((int) $a['user_id'], 'Clearance needs your signature (' . $periodLabel . ')', 'Student #' . $sid . ' requested ' . $periodLabel . ' clearance for your subject — sign or return it in your queue.', 'warning', '/teacher/clearances');
                    }
                }
            } catch (Throwable $e) {
                NotificationController::pushToTeachers('New clearance request (' . $periodLabel . ')', 'Student #' . $sid . ' requested ' . $periodLabel . ' clearance — review the signing queue.', 'info', '/teacher/clearances');
            }
            set_flash('success',"Clearance requested for $periodLabel — awaiting professor signature.");
        } catch(Throwable $e){ storage_log('clearance request failed: '.$e->getMessage()); set_flash('error','Could not request clearance right now. Please try again or contact the registrar.'); }
        redirect('/student/clearances');
    }

    /**
     * Build per-subject signing items for a clearance (idempotent).
     * One item per enrolled subject so ALL of the student's professors sign.
     * Covers invite-code subject_classrooms (primary) + legacy class_offerings.
     */
    private static function buildSigningItems(int $clearanceId, int $studentId, mixed $sectionId): void
    {
        $added = [];
        foreach (Database::all("SELECT subject_classroom_id AS sid FROM clearance_items WHERE clearance_id = ?", [$clearanceId]) as $ex) {
            if (!empty($ex['sid'])) $added['s' . (int) $ex['sid']] = true;
        }
        foreach (Database::all("SELECT class_offering_id AS oid FROM clearance_items WHERE clearance_id = ? AND class_offering_id IS NOT NULL", [$clearanceId]) as $ex) {
            if (!empty($ex['oid'])) $added['o' . (int) $ex['oid']] = true;
        }
        try {
            $joined = Database::all(
                "SELECT sc.id, sc.teacher_id FROM classroom_members cm
                   JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                  WHERE cm.student_id = ? AND sc.teacher_id IS NOT NULL",
                [$studentId]
            );
            foreach ($joined as $j) {
                $jid = (int) ($j['id'] ?? 0);
                $tid = (int) ($j['teacher_id'] ?? 0);
                if ($jid <= 0 || $tid <= 0 || isset($added['s' . $jid])) continue;
                $added['s' . $jid] = true;
                try {
                    Database::insert('clearance_items', ['clearance_id' => $clearanceId, 'class_offering_id' => null, 'subject_classroom_id' => $jid, 'teacher_id' => $tid, 'is_signed' => 0]);
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
            storage_log('clearance subject items failed: ' . $e->getMessage());
        }
        try {
            $offerings = [];
            if (!empty($sectionId)) {
                $offerings = Database::all("SELECT co.id, co.teacher_id FROM class_offerings co WHERE co.section_id=? AND co.teacher_id IS NOT NULL LIMIT 20", [$sectionId]);
            }
            foreach ($offerings as $off) {
                $oid = (int) ($off['id'] ?? 0);
                $tid = (int) ($off['teacher_id'] ?? 0);
                if ($oid <= 0 || $tid <= 0 || isset($added['o' . $oid])) continue;
                $added['o' . $oid] = true;
                try {
                    Database::insert('clearance_items', ['clearance_id' => $clearanceId, 'class_offering_id' => $oid, 'subject_classroom_id' => null, 'teacher_id' => $tid, 'is_signed' => 0]);
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
            storage_log('clearance offering items failed: ' . $e->getMessage());
        }
    }

    /**
     * Band-correct display label for a clearance row ("Quarter 1", "1st
     * Semester"). Band resolves from the clearance student's level; raw
     * stored value is shown as-is when unknown (history never reinterpreted).
     */
    public static function periodLabelFor(array $clearance): string
    {
        $code = trim((string) ($clearance['semester'] ?? ''));
        if ($code === '') return '—';
        try {
            if (class_exists(\App\Core\AcademicPeriod::class) && !empty($clearance['student_id'])) {
                $st = Database::one(
                    "SELECT s.grade_level_id, gl.category FROM students s LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id WHERE s.id = ? LIMIT 1",
                    [(int) $clearance['student_id']]
                );
                if ($st) {
                    $band = \App\Core\AcademicPeriod::bandForCategory($st['category'] ?? null);
                    return \App\Core\AcademicPeriod::label($band, $code);
                }
            }
        } catch (Throwable $e) {
        }
        if (in_array($code, ['1st', '2nd'], true)) return $code . ' Semester';
        return $code;
    }

    private function canRequestClearance(int $studentId, string $semester='1st', int $ayId=0): array    {
        if (!$ayId) {
            $curAy = Database::one("SELECT id FROM academic_years WHERE is_current=1 LIMIT 1");
            $ayId = $curAy ? (int)$curAy['id'] : 0;
            if (!$ayId) return ['ok'=>false,'reason'=>'No active academic year'];
        }
        // Must have Finals permit fully_approved
        $final = Database::one("SELECT status FROM exam_permits WHERE student_id=? AND academic_year_id=? AND exam_period='Finals' LIMIT 1", [$studentId,$ayId]);
        if (!$final || $final['status'] !== 'fully_approved') return ['ok'=>false,'reason'=>'Complete Final exam permit (Fully Approved by Teacher) before requesting clearance. Sequential: Prelim → Midterm → Prefinal → Finals.'];
        // Must have at least one Final grade released?
        // Check grades released for finals? optional
        return ['ok'=>true,'reason'=>''];
    }

    /* ================= Teacher: queue & sign ================= */
    public function teacherQueue(array $params = []): void
    {
        Auth::requireRole('teacher');
        $teacher = Database::one("SELECT id FROM teachers WHERE user_id=? LIMIT 1", [(int)Auth::id()]);
        $tid = $teacher ? (int)$teacher['id'] : 0;
        $rows = Database::all("SELECT c.*, s.student_no, u.first_name, u.last_name, ay.school_year FROM clearances c JOIN students s ON s.id=c.student_id JOIN users u ON u.id=s.user_id JOIN academic_years ay ON ay.id=c.academic_year_id WHERE c.status='pending' ORDER BY c.requested_at ASC LIMIT 50");
        // filter to only those where teacher has an item (if items exist) —
        // single query, not one per row.
        if ($tid) {
            $mineIds = [];
            try {
                foreach (Database::all("SELECT DISTINCT clearance_id FROM clearance_items WHERE teacher_id = ?", [$tid]) as $ir) {
                    $mineIds[(int) $ir['clearance_id']] = true;
                }
            } catch (Throwable $e) {
            }
            $filtered = [];
            foreach ($rows as $r) {
                if (isset($mineIds[(int) $r['id']])) $filtered[] = $r;
            }
            if ($filtered) $rows=$filtered;
            // Attach this teacher's subjects per clearance (one query).
            try {
                $ids = array_map(static fn($r) => (int) $r['id'], $rows);
                $subjMap = [];
                if ($ids !== []) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $subRows = Database::all(
                        "SELECT ci.clearance_id, COALESCE(sc.subject_code, s.code, '') AS code
                           FROM clearance_items ci
                           LEFT JOIN subject_classrooms sc ON sc.id = ci.subject_classroom_id
                           LEFT JOIN class_offerings co ON co.id = ci.class_offering_id
                           LEFT JOIN subjects s ON s.id = co.subject_id
                          WHERE ci.teacher_id = ? AND ci.clearance_id IN ($ph)",
                        array_merge([$tid], $ids)
                    );
                    foreach ($subRows as $sr) $subjMap[(int) $sr['clearance_id']][] = (string) $sr['code'];
                }
                foreach ($rows as &$r) $r['my_subjects'] = $subjMap[(int) $r['id']] ?? [];
                unset($r);
            } catch (Throwable $e) {
            }
        }
        $this->view('teacher/clearances_queue', ['title'=>'Clearance Signing Queue','clearances'=>$rows]);
    }

    public function teacherSign(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        $cid = (int)($params['id'] ?? 0);
        $clearance = Database::one("SELECT * FROM clearances WHERE id=? LIMIT 1", [$cid]);
        if (!$clearance || $clearance['status'] !== 'pending') { set_flash('error','Clearance not pending.'); redirect('/teacher/clearances'); }
        $teacher = Database::one("SELECT id FROM teachers WHERE user_id=? LIMIT 1", [(int)Auth::id()]);
        $tid = $teacher ? (int)$teacher['id'] : 0;
        // Authorization: when the clearance names specific professors, only
        // they may sign. Item-less clearances keep the any-professor fallback.
        try {
            $itemCount = (int) Database::value("SELECT COUNT(*) FROM clearance_items WHERE clearance_id = ?", [$cid]);
            if ($itemCount > 0 && $tid > 0) {
                $mine = (int) Database::value("SELECT COUNT(*) FROM clearance_items WHERE clearance_id = ? AND teacher_id = ?", [$cid, $tid]);
                if ($mine === 0) {
                    set_flash('error', 'This clearance is assigned to other professors — you are not authorized to sign it.');
                    redirect('/teacher/clearances');
                }
            }
        } catch (Throwable $e) {
        }
        try {
            Database::transaction(function() use ($cid,$tid,$clearance) {
                // Heal clearances created before per-subject items existed:
                // materialize items from current enrollments before signing.
                if ((int)Database::value("SELECT COUNT(*) FROM clearance_items WHERE clearance_id=?", [$cid]) === 0) {
                    $stu = Database::one("SELECT id, section_id FROM students WHERE id=? LIMIT 1", [(int)$clearance['student_id']]);
                    if ($stu) self::buildSigningItems($cid, (int)$stu['id'], $stu['section_id'] ?? null);
                }
                // mark items for this teacher as signed
                if ($tid) Database::run("UPDATE clearance_items SET is_signed=1, signed_at=NOW() WHERE clearance_id=? AND teacher_id=?", [$cid,$tid]);
                else Database::run("UPDATE clearance_items SET is_signed=1, signed_at=NOW() WHERE clearance_id=?", [$cid]);
                // check if all items signed → issue
                $pending = (int)Database::value("SELECT COUNT(*) FROM clearance_items WHERE clearance_id=? AND is_signed=0", [$cid]);
                if ($pending===0) {
                    Database::run("UPDATE clearances SET status='issued', signed_at=NOW(), issued_at=NOW() WHERE id=?", [$cid]);
                    $clr = Database::one("SELECT student_id FROM clearances WHERE id=? LIMIT 1", [$cid]);
                    if ($clr) {
                        $stuUser = Database::one("SELECT user_id FROM students WHERE id=? LIMIT 1", [(int)$clr['student_id']]);
                        if ($stuUser && $stuUser['user_id']) {
                            Database::insert('notifications',['user_id'=>(int)$stuUser['user_id'],'title'=>'Clearance Issued','message'=>'Your '.self::periodLabelFor($clearance).' clearance is issued — download PDF and use it to enroll for the next period.','type'=>'success','link_url'=>'/student/clearances']);
                        }
                        // Next leg is Accounting financial review — notify them.
                        NotificationController::pushToAccounting(
                            'Clearance issued — needs financial review #' . $cid,
                            self::periodLabelFor($clearance) . ' clearance fully signed by professors. Review in Fin. Clearance queue.',
                            'info', '/accounting/clearances'
                        );
                    }
                } else {
                    Database::run("UPDATE clearances SET status='signed', signed_at=NOW() WHERE id=?", [$cid]);
                    $clr2 = Database::one("SELECT student_id FROM clearances WHERE id=? LIMIT 1", [$cid]);
                    if ($clr2) {
                        $stuUser2 = Database::one("SELECT user_id FROM students WHERE id=? LIMIT 1", [(int)$clr2['student_id']]);
                        if ($stuUser2 && !empty($stuUser2['user_id'])) {
                            NotificationController::push((int)$stuUser2['user_id'], 'Professor signed your clearance', 'One professor signed your ' . self::periodLabelFor($clearance) . ' clearance — awaiting other subjects.', 'success', '/student/clearances');
                        }
                    }
                }
            });
            // Recorded in audit_logs (NOT permit_approvals: that table is only
            // for exam_permits — clearance ids would pollute permit history).
            Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'clearance.signed','entity_type'=>'clearances','entity_id'=>$cid,'ip_address'=>client_ip()]);
            \App\Core\EnrollmentCycle::maybeComplete($cid);
            set_flash('success','Clearance signed. '.((int)Database::value("SELECT COUNT(*) FROM clearance_items WHERE clearance_id=? AND is_signed=0",[$cid])===0 ? 'Issued — student can now enroll for the next period.' : 'Awaiting other subjects.'));
        } catch(Throwable $e){ storage_log('clearance sign failed: '.$e->getMessage()); set_flash('error','Sign failed: '.$e->getMessage()); }
        redirect('/teacher/clearances');
    }

    public function teacherReturn(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        $cid = (int)($params['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if (mb_strlen($reason) < 5) {
            set_flash('error', 'Returning a clearance requires a reason (min 5 characters) so the student knows what to fix.');
            redirect('/teacher/clearances');
        }
        $clearance = Database::one("SELECT * FROM clearances WHERE id=? LIMIT 1", [$cid]);
        if (!$clearance || !in_array($clearance['status'], ['pending', 'signed'], true)) {
            set_flash('error', 'Clearance cannot be returned in its current state.');
            redirect('/teacher/clearances');
        }
        $teacher = Database::one("SELECT id FROM teachers WHERE user_id=? LIMIT 1", [(int)Auth::id()]);
        $tid = $teacher ? (int)$teacher['id'] : 0;
        try {
            $itemCount = (int) Database::value("SELECT COUNT(*) FROM clearance_items WHERE clearance_id = ?", [$cid]);
            if ($itemCount > 0 && $tid > 0) {
                $mine = (int) Database::value("SELECT COUNT(*) FROM clearance_items WHERE clearance_id = ? AND teacher_id = ?", [$cid, $tid]);
                if ($mine === 0) {
                    set_flash('error', 'This clearance is assigned to other professors.');
                    redirect('/teacher/clearances');
                }
            }
        } catch (Throwable $e) {
        }
        try {
            Database::run("UPDATE clearances SET status = 'pending', teacher_remarks = ? WHERE id = ?", [mb_substr($reason, 0, 500), $cid]);
            Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(),'action' => 'clearance.teacher_returned','entity_type' => 'clearances','entity_id' => $cid,'ip_address' => client_ip()]);
            try {
                $stuUser = Database::one("SELECT user_id FROM students WHERE id=? LIMIT 1", [(int)$clearance['student_id']]);
                if ($stuUser && !empty($stuUser['user_id'])) {
                    NotificationController::push((int)$stuUser['user_id'], 'Clearance returned by professor', 'Your ' . self::periodLabelFor($clearance) . ' clearance was returned: ' . mb_substr($reason, 0, 200), 'warning', '/student/clearances');
                }
            } catch (Throwable $e) {
            }
            set_flash('success', 'Clearance returned with reason — the student has been notified.');
        } catch (Throwable $e) {
            storage_log('clearance return failed: ' . $e->getMessage());
            set_flash('error', 'Return failed: ' . $e->getMessage());
        }
        redirect('/teacher/clearances');
    }

    /* ============ Registrar academic review + eligibility override ============ */

    public function registrarReview(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('registrar', 'admin');
        $cid = (int)($params['id'] ?? 0);
        $action = trim((string)($_POST['action'] ?? 'approved'));
        if (!in_array($action, ['approved', 'returned'], true)) $action = 'approved';
        $remarks = trim((string)($_POST['remarks'] ?? $_POST['reason'] ?? ''));
        if ($action === 'returned' && mb_strlen($remarks) < 5) {
            set_flash('error', 'Returning a clearance requires a reason (min 5 characters).');
            redirect('/registrar/clearances');
        }
        $clearance = Database::one("SELECT * FROM clearances WHERE id=? LIMIT 1", [$cid]);
        if ($clearance === null) {
            set_flash('error', 'Clearance not found.');
            redirect('/registrar/clearances');
        }
        try {
            $status = $action === 'approved' ? 'approved' : 'returned';
            Database::run(
                "UPDATE clearances SET registrar_status = ?, registrar_reviewed_by = ?, registrar_reviewed_at = NOW(), registrar_remarks = ? WHERE id = ?",
                [$status, (int)Auth::id(), $remarks !== '' ? mb_substr($remarks, 0, 500) : null, $cid]
            );
            Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(),'action' => 'clearance.registrar_' . $status,'entity_type' => 'clearances','entity_id' => $cid,'ip_address' => client_ip()]);
            try {
                $stuUser = Database::one("SELECT user_id FROM students WHERE id=? LIMIT 1", [(int)$clearance['student_id']]);
                if ($stuUser && !empty($stuUser['user_id'])) {
                    NotificationController::push(
                        (int)$stuUser['user_id'],
                        'Registrar ' . ($status === 'approved' ? 'approved' : 'returned') . ' your clearance',
                        $status === 'approved' ? 'Academic requirements verified for ' . self::periodLabelFor($clearance) . '.' : ('Reason: ' . mb_substr($remarks, 0, 200)),
                        $status === 'approved' ? 'success' : 'warning', '/student/clearances'
                    );
                }
            } catch (Throwable $e) {
            }
            \App\Core\EnrollmentCycle::maybeComplete($cid);
            set_flash('success', 'Clearance #' . $cid . ' registrar review: ' . $status . '.');
        } catch (Throwable $e) {
            storage_log('clearance registrar review failed: ' . $e->getMessage());
            set_flash('error', 'Review failed: ' . $e->getMessage());
        }
        redirect('/registrar/clearances');
    }

    public function override(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('registrar', 'admin');
        $cid = (int)($params['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if (mb_strlen($reason) < 5) {
            set_flash('error', 'An override requires a recorded reason (min 5 characters).');
            redirect('/registrar/clearances');
        }
        $clearance = Database::one("SELECT * FROM clearances WHERE id=? LIMIT 1", [$cid]);
        if ($clearance === null) {
            set_flash('error', 'Clearance not found.');
            redirect('/registrar/clearances');
        }
        Database::run(
            "UPDATE clearances SET eligibility_override = 1, override_reason = ?, override_by = ?, override_at = NOW() WHERE id = ?",
            [mb_substr($reason, 0, 500), (int)Auth::id(), $cid]
        );
        Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(),'action' => 'clearance.eligibility_override','entity_type' => 'clearances','entity_id' => $cid,'ip_address' => client_ip()]);
        try {
            $stuUser = Database::one("SELECT user_id FROM students WHERE id=? LIMIT 1", [(int)$clearance['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                NotificationController::push((int)$stuUser['user_id'], 'You are eligible for next enrollment', 'The Registrar marked your clearance #' . $cid . ' eligible by override. Open Enrollment to start your next period.', 'success', '/enrollment/eligibility');
            }
        } catch (Throwable $e) {
        }
        \App\Core\EnrollmentCycle::maybeComplete($cid);
        set_flash('success', 'Clearance #' . $cid . ' marked eligible by override (logged with reason).');
        redirect('/registrar/clearances');
    }

    /* ================= Student: download / print ================= */
    public function print(array $params = []): void
    {
        Auth::requireRole('student','parent','teacher','admin');
        $id = (int)($params['id'] ?? 0);
        $clr = Database::one("SELECT c.*, s.student_no, u.first_name, u.last_name, u.email, ay.school_year FROM clearances c JOIN students s ON s.id=c.student_id JOIN users u ON u.id=s.user_id JOIN academic_years ay ON ay.id=c.academic_year_id WHERE c.id=? LIMIT 1", [$id]);
        if (!$clr) { http_response_code(404); exit('Clearance not found'); }
        $role = Auth::role();
        if ($role==='student') {
            $stu = Database::one("SELECT id FROM students WHERE user_id=? LIMIT 1", [(int)Auth::id()]);
            if (!$stu || (int)$stu['id'] !== (int)$clr['student_id']) { http_response_code(403); exit('Forbidden'); }
        } elseif ($role==='parent') {
            // Parents print only a linked child's clearance (mirror DocumentController::print).
            $ok = false;
            if (class_exists(\App\Core\GuardianLinks::class)) {
                $g = Database::one("SELECT id FROM guardians WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
                if ($g) {
                    $ok = (bool) Database::one(
                        "SELECT 1 FROM guardian_students WHERE guardian_id = ? AND student_id = ? LIMIT 1",
                        [(int) $g['id'], (int) $clr['student_id']]
                    );
                }
            }
            if (!$ok) { http_response_code(403); exit('Forbidden'); }
        } elseif ($role==='teacher') {
            // Teachers print only clearances naming them (any of its items).
            $t = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
            $mine = $t ? Database::one("SELECT id FROM clearance_items WHERE clearance_id = ? AND teacher_id = ? LIMIT 1", [$id, (int) $t['id']]) : null;
            if ($mine === null) { http_response_code(403); exit('Forbidden'); }
        }
        $items = Database::all("SELECT ci.*, t.employee_no, u.first_name AS t_first, u.last_name AS t_last, s.code AS subj_code, s.title AS subj_title FROM clearance_items ci LEFT JOIN teachers t ON t.id=ci.teacher_id LEFT JOIN users u ON u.id=t.user_id LEFT JOIN class_offerings co ON co.id=ci.class_offering_id LEFT JOIN subjects s ON s.id=co.subject_id WHERE ci.clearance_id=? AND ci.class_offering_id IS NOT NULL ORDER BY ci.id ASC", [$id]);
        $subjectItems = [];
        try {
            $subjectItems = Database::all(
                "SELECT ci.*, t.employee_no, u.first_name AS t_first, u.last_name AS t_last, sc.subject_code AS subj_code, sc.subject_title AS subj_title, sc.section_name
                   FROM clearance_items ci
                   LEFT JOIN teachers t ON t.id=ci.teacher_id
                   LEFT JOIN users u ON u.id=t.user_id
                   LEFT JOIN subject_classrooms sc ON sc.id=ci.subject_classroom_id
                  WHERE ci.clearance_id=? AND ci.subject_classroom_id IS NOT NULL ORDER BY ci.id ASC",
                [$id]
            );
        } catch (Throwable $e) { $subjectItems = []; }
        $items = array_merge($items, $subjectItems);
        $this->view('clearances/print', ['title'=>'Clearance '.$clr['id'],'clearance'=>$clr,'items'=>$items], null);
    }

    public function verifyQr(array $params = []): void
    {
        $token = trim((string)($params['token'] ?? ''));
        if ($token==='') { http_response_code(404); exit('Invalid QR'); }
        $clr = Database::one("SELECT c.*, s.student_no, u.first_name, u.last_name, ay.school_year FROM clearances c JOIN students s ON s.id=c.student_id JOIN users u ON u.id=s.user_id JOIN academic_years ay ON ay.id=c.academic_year_id WHERE c.qr_token=? LIMIT 1", [$token]);
        if (!$clr) { http_response_code(404); exit('Clearance not found'); }
        header('Content-Type: text/html; charset=utf-8');
        $ok = in_array($clr['status'], ['issued','used'], true);
        $color = $ok ? '#166534' : '#92400e';
        $badge = strtoupper($clr['status']);
        $badgeCls = $ok ? 'bg-success' : 'bg-warning text-dark';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify Clearance</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="card mx-auto" style="max-width:560px"><div class="card-body text-center p-4">';
        echo '<div class="mb-2"><span class="badge '.$badgeCls.'">'.$badge.'</span></div><h4>Clearance #'.$clr['id'].'</h4>';
        echo '<p class="text-muted">'.$clr['first_name'].' '.$clr['last_name'].' ('.$clr['student_no'].')<br>'.e(self::periodLabelFor($clr)).' · '.$clr['school_year'].'</p>';
        echo '<p style="color:'.$color.';font-weight:600">'.($ok?'✓ Authentic — issued, eligible to enroll for the next period.':'⧗ Not yet issued. Status: '.$badge).'</p>';
        echo '<a href="'.e(url('/')).'" class="btn btn-primary">Back to Portal</a></div></div></div></body></html>';
    }

    /* ================= Admin: queue ================= */
    public function adminQueue(array $params = []): void
    {
        Auth::requireRole('admin', 'registrar', 'accounting');
        $status = $_GET['status'] ?? 'pending';
        $allowed=['pending','signed','issued','used','all'];
        if(!in_array($status,$allowed,true)) $status='pending';
        $where=$status==='all'?'':'WHERE c.status=?';
        $args=$status==='all'?[]:[$status];
        $rows=Database::all("SELECT c.*, s.student_no, u.first_name, u.last_name, ay.school_year FROM clearances c JOIN students s ON s.id=c.student_id JOIN users u ON u.id=s.user_id JOIN academic_years ay ON ay.id=c.academic_year_id $where ORDER BY c.created_at DESC LIMIT 100",$args);
        $counts=[]; foreach(['pending','signed','issued','used'] as $s) $counts[$s]=(int)Database::value("SELECT COUNT(*) FROM clearances WHERE status=?",[$s]); $counts['all']=(int)Database::value("SELECT COUNT(*) FROM clearances");
        $this->view('admin/clearances_index', ['title'=>'Clearances','rows'=>$rows,'status'=>$status,'counts'=>$counts]);
    }
}
