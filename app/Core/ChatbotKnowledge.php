<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * EduPortal chatbot support layer (READ-ONLY).
 *
 * Responsibilities:
 *   1. systemPrompt()  - conversation style rules + VERIFIED portal knowledge
 *   2. accountSnapshot() - the signed-in user's OWN live status (read-only,
 *      role-scoped, aggregate-only; never other people's records, never
 *      passwords/tokens/keys)
 *   3. suggestions()   - role-appropriate starter chips
 *   4. fallback()      - deterministic offline answer that still follows the
 *      conversation so the assistant degrades gracefully, never silently
 *
 * GROUND RULES baked into the prompt (do not remove):
 *   - Answer only from this knowledge + the user's own snapshot.
 *   - Never invent a page, status, workflow, or a completed action.
 *   - Never claim to have submitted/paid/graded/issued anything.
 *   - Admits when it doesn't know, then points to the right office.
 *   - Never asks for a password, OTP, MFA/recovery code, or card details.
 */
final class ChatbotKnowledge
{
    /** Roles the portal actually implements (see roles table + Auth::role()). */
    public const ROLES = ['admin', 'registrar', 'accounting', 'teacher', 'student', 'parent'];

    /* ================================================================
     * 1. VERIFIED PORTAL KNOWLEDGE
     *    Every path below is checked against app/routes/web.php.
     * ================================================================ */

    public static function portalKnowledge(): string
    {
        return <<<'TXT'
VERIFIED PORTAL KNOWLEDGE (use only this; every path below exists)

ROLES: Administrator, Registrar, Accounting, Teacher, Student, Parent/Guardian.
Each role has its OWN dashboard and its OWN pages. Never tell a user to open a
page that belongs to another role, and never promise them a page that exists
but is hidden from their role.

STUDENT / PARENT PAGES
- /dashboard - role dashboard with quick actions
- /notifications, /announcements, /news - notices and school news
- /enroll - new enrollment application
- /my-enrollments - your enrollment applications and their status
- /student/enrollment-docs - permanent documents (PSA, Valid ID, Good Moral,
  Form 137, Form 138, 2x2 photo, and ECCD checklist for Kinder/Grade 1 only)
- /enrollment/eligibility - eligibility for the next period
- /student/class-offerings - official subjects you may join (eligible + browse all)
- /student/my-classes - your joined subjects; drop a subject here
- /student/schedule - weekly schedule
- /student/grades - released grades
- /student/ledger - your billing ledger and charges
- /student/permits - exam permit requests
- /student/clearances - clearance requests (academic and financial)
- /student/documents - request stamped documents (grade slip, transcript,
  certificate of enrollment, certificate of grades, registration form,
  grade equivalency)
- /profile - your profile, /security - password, MFA, devices, active sessions

TEACHER PAGES
- /teacher/grades - your grade sheets (draft -> submitted -> under review ->
  returned -> approved -> finalized)
- /teacher/permits - permit verification queue
- /teacher/clearances - clearance queue (sign / return per subject)
- /teacher/class-offerings/{id}/roster - class roster
- /classrooms/{id} - classroom, tasks, submissions

REGISTRAR PAGES
- /registrar/enrollments - enrollment queue (approve / reject)
- /registrar/students - student records
- /registrar/grades - grade verification and review
- /registrar/sheets - grade sheets
- /registrar/documents - document requests (approve -> ready -> release, or reject with a reason)
- /registrar/permits - permit queue
- /registrar/clearances - clearance review and override
- /registrar/scales - grade scale
- /registrar/re-enrollment - re-enrollment

ACCOUNTING PAGES
- /accounting/enrollments - verify applications
- /accounting/payments - payment approval and receipts
- /accounting/documents - verify uploaded documents
- /accounting/students - student accounts and balances
- /accounting/clearances - financial clearance
- /accounting/permits - permit queue

ADMIN PAGES
- /admin/enrollments, /admin/payments, /admin/permits, /admin/clearances
- /admin/class-offerings (create subjects, assign teacher, invite code)
- /admin/teachers, /admin/staff, /admin/users (all accounts), /admin/backups
- /admin/tuition-plans, /admin/academic-calendar, /admin/evaluations
- /admin/content, /admin/blog, /admin/announcements

VERIFIED STATUS MEANINGS
ENROLLMENT (My Applications)
- pending -> waiting for Accounting to check
- accounting_approved -> Accounting cleared it, now waiting for payment
- awaiting_payment -> approved but tuition is not paid yet; pay to continue
- action_required -> needs a correction or document; see the note
- approved -> fully approved; you are enrolled
- rejected -> declined; a parent can file again for a new period
DOCUMENTS (/student/documents)
- pending -> the Registrar has not looked at it yet
- approved -> accepted, being prepared
- ready -> printed and ready to be released
- released -> you can open and print it
- rejected -> refused, with a reason; you can request again
- cancelled -> you cancelled it
EXAM PERMITS (must go in order: Prelim, Midterm, Prefinal, Finals)
- pending_accounting -> waiting for Accounting
- approved_accounting -> Accounting cleared it, now with the Registrar
- approved_registrar -> Registrar cleared it, now with your teacher
- fully_approved -> fully approved and printable
- rejected -> refused, with a reason
A permit also needs the account to be paid in the ledger.
ACADEMIC CLEARANCE
- pending -> waiting on your teachers to sign
- signed -> all teachers signed, waiting on the Registrar
- issued -> issued, now with Accounting for the financial side
FINANCIAL CLEARANCE
- pending, for_review, cleared, not_cleared, on_hold
GRADES (grade sheets)
- draft -> teacher still editing
- submitted -> sent for review
- under_review -> Registrar is reviewing
- returned -> sent back to the teacher for correction
- approved -> approved; finalized -> locked as the official record
Only FINALIZED grades appear as official grades, and only RELEASED ones print.
CLASSES
You can join an official subject only while fully enrolled, using the invite
code your teacher gives you (for example X7K9M2) at /student/class-offerings.
DOCUMENT UPLOADS (/student/enrollment-docs)
- verified -> on file and reused automatically next period
- submitted -> received, waiting for Accounting to check
- needs_resubmission -> Accounting asked for a new version; the reason is shown
  next to that document
- rejected -> refused
ACCOUNT / LOGIN
- /security - change password, set up MFA (authenticator app or SMS), manage
  trusted devices and revoke active sessions
- Forgot password: /forgot-password sends a link valid 1 hour
- Email verification link is valid 24 hours; the 6-digit code is valid 10 minutes
- Accounts lock for 15 minutes after 5 failed sign-in attempts
TXT;
    }

    /** Conversation style rules. This is what makes it sound human. */
    public static function styleRules(): string
    {
        return <<<'TXT'
HOW TO TALK
- You are "EduPortal Buddy", the portal's AI assistant. Be warm, patient and
  quick. Talk like a helpful office clerk who has all day for the user.
- Write the way people text: contractions are good ("you're", "can't", "I'll").
  Short sentences. Plain words. No jargon unless the user used it first.
- Lead with the ANSWER in the first sentence. Add detail after, only if useful.
- Match the length to the question. "How do I pay?" gets 2-4 sentences, not an
  essay. A vague question gets a short clarifying question, not a wall of text.
- Use simple bullets or short numbered steps ONLY when they genuinely help
  (multi-step procedures). Never dump long lists for a yes/no question.
- If the request is ambiguous, answer what you can, then ask ONE short
  follow-up question ("Are you asking about this school year or the next one?").
- Handle typos, all-lowercase, slang and half-finished sentences gracefully.
  Never scold a user for grammar.
- Show brief empathy when something breaks: "That error is annoying - let's
  fix it." Keep it to one short line. No melodrama.
- NEVER open with "Hello! How can I help?" on every message. No repeated
  greetings, no repeating yourself, no restating the question back at them.
- Do not add disclaimers, apologies, or "As an AI language model" noise.
- If you're unsure, SAY you're unsure and point to the office that can answer.
  Guessing about a real school record is much worse than saying "check here".
- Be transparent: you are an AI assistant. If asked directly whether you're a
  human or whether you contacted staff, answer honestly.
- NEVER invent: a page, a button, a status, a policy, or an action you took.
- NEVER claim you submitted, paid, graded, approved, or issued anything. You
  explain and guide; the user performs every action in the portal.
- NEVER ask for or accept a password, OTP, MFA/recovery code, or card details.
  If a user pastes one, tell them to delete it and rotate it in /security.
- Keep replies short enough to read on a phone screen.
TXT;
    }

    public static function systemPrompt(): string
    {
        return self::styleRules() . "\n\n" . self::portalKnowledge();
    }

    /* ================================================================
     * 2. ACCOUNT SNAPSHOT (read-only, role-scoped, aggregate)
     * ================================================================ */

    /**
     * The signed-in user's OWN live state. Safe to include in the prompt.
     * Never returns another person's name, id, email, or records.
     */
    public static function accountSnapshot(): string
    {
        $uid = (int) (Auth::id() ?? 0);
        if ($uid <= 0) {
            return 'The visitor is NOT signed in. Give general guidance only and '
                 . 'point them to /login to see their own records.';
        }
        $role = (string) (Auth::role() ?? '');
        $lines = ["SIGNED-IN ROLE: {$role} (user #{$uid})."];
        if (!in_array($role, self::ROLES, true)) {
            $lines[] = 'Unknown role - give general guidance only.';
            return implode("\n", $lines);
        }

        $q = static function (string $sql, array $args = []) {
            try { return Database::value($sql, $args); } catch (Throwable $e) { return null; }
        };

        if ($role === 'student' || $role === 'parent') {
            $sid = self::studentIdFor($uid, $role);
            if ($sid <= 0) {
                $lines[] = 'No student record is linked to this account yet.';
            } else {
                $lines[] = "Student record #{$sid} (the user's own).";
                $st = $q('SELECT status FROM students WHERE id = ?', [$sid]);
                if ($st !== null) $lines[] = "Student status in the portal: {$st}.";

                $apps = [];
                try {
                    $apps = Database::all(
                        'SELECT status, COUNT(*) c FROM enrollment_applications
                          WHERE (user_id = ? OR student_id = ?) GROUP BY status',
                        [$uid, $sid]
                    );
                } catch (Throwable $e) {}
                if ($apps !== []) {
                    $sum = [];
                    foreach ($apps as $a) $sum[] = $a['status'] . '=' . (int) $a['c'];
                    $lines[] = 'Their enrollment applications (status x count): ' . implode(', ', $sum) . '.';
                } else {
                    $lines[] = 'They have no enrollment application on file.';
                }

                $p = $q(
                    "SELECT COALESCE(SUM(amount),0) FROM payments WHERE student_id = ? AND status = 'paid'",
                    [$sid]
                );
                if ($p !== null) $lines[] = 'Paid amount on record: PHP ' . number_format((float) $p, 2) . '.';

                $due = $q(
                    "SELECT COALESCE(SUM(amount),0) FROM payments WHERE student_id = ? AND status <> 'paid'",
                    [$sid]
                );
                if ($due !== null && (float) $due > 0) {
                    $lines[] = 'Outstanding (unpaid) amount: PHP ' . number_format((float) $due, 2) . '.';
                }

                foreach (self::countsFor($sid, 'exam_permits', ['pending_accounting', 'approved_accounting', 'approved_registrar', 'fully_approved', 'rejected']) as $k => $v) {
                    if ($v > 0) $lines[] = "Exam permits with status {$k}: {$v}.";
                }
                foreach (self::countsFor($sid, 'clearances', ['pending', 'signed', 'issued', 'used']) as $k => $v) {
                    if ($v > 0) $lines[] = "Academic clearances with status {$k}: {$v}.";
                }
                foreach (self::countsFor($sid, 'academic_documents', ['pending', 'approved', 'ready', 'released', 'rejected']) as $k => $v) {
                    if ($v > 0) $lines[] = "Document requests with status {$k}: {$v}.";
                }
                $fin = self::countsFor($sid, 'enrollment_documents', ['submitted', 'verified', 'needs_resubmission', 'rejected']);
                if (($fin['needs_resubmission'] ?? 0) > 0) {
                    $lines[] = "Documents needing a NEW upload: {$fin['needs_resubmission']}.";
                }
                $n = $q('SELECT COUNT(*) FROM classroom_members WHERE student_id = ?', [$sid]);
                if ($n !== null) $lines[] = "Subjects currently joined: {$n}.";
            }
            if ($role === 'parent') {
                $kids = $q(
                    'SELECT COUNT(*) FROM guardian_students gs JOIN guardians g ON g.id = gs.guardian_id
                      WHERE g.user_id = ?',
                    [$uid]
                );
                if ($kids !== null) {
                    $lines[] = "Linked children: {$kids}. They can only see and switch to children linked to their own account.";
                }
            }
        }

        if ($role === 'teacher') {
            try {
                $rows = Database::all(
                    'SELECT sc.subject_code, sc.subject_title
                       FROM subject_classrooms sc JOIN teachers t ON t.id = sc.teacher_id
                      WHERE t.user_id = ? ORDER BY sc.subject_code LIMIT 12',
                    [$uid]
                );
                if ($rows !== []) {
                    $subj = array_map(static fn($r) => $r['subject_code'] . ' (' . $r['subject_title'] . ')', $rows);
                    $lines[] = 'Subjects assigned to them: ' . implode('; ', $subj) . '.';
                } else {
                    $lines[] = 'No subjects are assigned to them right now.';
                }
                $sheets = self::countsByStatus(
                    'SELECT gs.status, COUNT(*) c FROM grade_sheets gs
                       JOIN subject_classrooms sc ON sc.id = gs.classroom_id
                       JOIN teachers t ON t.id = sc.teacher_id
                      WHERE t.user_id = ? GROUP BY gs.status',
                    [$uid]
                );
                if ($sheets !== []) {
                    $sum = [];
                    foreach ($sheets as $k => $v) $sum[] = $k . '=' . $v;
                    $lines[] = 'Their grade sheets (status x count): ' . implode(', ', $sum) . '.';
                }
            } catch (Throwable $e) {}
        }

        if ($role === 'registrar' || $role === 'accounting' || $role === 'admin') {
            $tables = [
                'pending enrollment applications' => ['enrollment_applications', ['pending']],
                'document requests awaiting review' => ['academic_documents', ['pending']],
                'pending grade sheets' => ['grade_sheets', ['submitted', 'under_review']],
                'pending exam permits' => ['exam_permits', ['pending_accounting', 'approved_accounting', 'approved_registrar']],
            ];
            foreach ($tables as $label => [$tbl, $sts]) {
                $c = $q(
                    'SELECT COUNT(*) FROM `' . $tbl . '` WHERE status IN (' .
                    implode(',', array_fill(0, count($sts), '?')) . ')',
                    $sts
                );
                if ($c !== null && (int) $c > 0) $lines[] = "In their queue - {$label}: {$c}.";
            }
        }

        return implode("\n", $lines);
    }

    /** Student id for a signed-in student, or for a parent's acting child. */
    private static function studentIdFor(int $uid, string $role): int
    {
        if ($role === 'student') {
            try {
                $s = Database::one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$uid]);
                if ($s) return (int) $s['id'];
            } catch (Throwable $e) {}
            return 0;
        }
        if (class_exists(GuardianLinks::class)) {
            try {
                $kid = GuardianLinks::ownedChild($uid, 0);
                if ($kid) return (int) $kid['id'];
            } catch (Throwable $e) {}
        }
        return 0;
    }

    private static function countsFor(int $sid, string $table, array $statuses): array
    {
        $out = [];
        try {
            foreach (Database::all(
                "SELECT status, COUNT(*) c FROM `{$table}` WHERE student_id = ? GROUP BY status",
                [$sid]
            ) as $r) {
                $out[(string) $r['status']] = (int) $r['c'];
            }
        } catch (Throwable $e) {}
        return array_intersect_key($out, array_flip($statuses));
    }

    private static function countsByStatus(string $sql, array $args): array
    {
        $out = [];
        try {
            foreach (Database::all($sql, $args) as $r) $out[(string) $r['status']] = (int) $r['c'];
        } catch (Throwable $e) {}
        return $out;
    }

    /* ================================================================
     * 3. SUGGESTIONS (role-appropriate chips)
     * ================================================================ */

    public static function suggestions(?string $role): array
    {
        $common = ['What can I do in this portal?'];
        $byRole = [
            'student' => [
                'Where can I see my grades?',
                'How do I check my enrollment status?',
                'How do I request a document?',
                'How do I pay?',
                'How do I get an exam permit?',
            ],
            'parent' => [
                'How do I check my child\'s documents?',
                'What does my enrollment status mean?',
                'How do I check my payment?',
                'How do I enroll my child?',
            ],
            'teacher' => [
                'How do I submit my grade sheet?',
                'Where do I sign clearances?',
                'How do I post an assignment?',
            ],
            'registrar' => [
                'How do I release a document?',
                'How do I review grade sheets?',
                'How do I approve an exam permit?',
            ],
            'accounting' => [
                'How do I verify a payment?',
                'How do I check a document?',
                'How do I approve financial clearance?',
            ],
            'admin' => [
                'How do I create a subject?',
                'How do I manage accounts?',
                'How do I check backups?',
            ],
        ];
        $role = $role !== null && in_array($role, self::ROLES, true) ? $role : null;
        return $role !== null ? array_slice(array_merge($byRole[$role], $common), 0, 5) : array_slice($common, 0, 3);
    }

    /* ================================================================
     * 4. OFFLINE FALLBACK (conversational, context-aware)
     * ================================================================ */

    /**
     * Used only when the AI provider is unreachable. Still tracks the last
     * topic so a short follow-up ("monthly") gets a relevant answer.
     */
    public static function fallback(string $msg, string $topic = ''): string
    {
        $m = ' ' . strtolower(trim($msg)) . ' ';
        $pick = function (array $keys) use ($m): bool {
            foreach ($keys as $k) if (str_contains($m, $k)) return true;
            return false;
        };

        // Short follow-ups reuse the current topic.
        if (preg_match('/^\s*(and |also |what about |how about |ok|okay|then |monthly|quarterly|full|in full|now|next)\b/i', trim($msg))
            || in_array(trim(strtolower($msg)), ['monthly', 'full', 'in full', 'yes', 'no', 'ok', 'okay', 'thanks', 'thank you'], true)) {
            if ($topic === 'payment') {
                return "Got it. You can pick that option from the payment section of your enrollment application - the exact amount and methods (GCash, Maya, card, QR Ph) show up there before you confirm. I can't see the payment screen right now, so if it looks different from that, tell me what you see.";
            }
            if ($topic === 'enrollment') {
                return "Okay - head to My Applications and open the one you mean. The status badge there tells you exactly which office still needs to act. If it says action_required, that note will say what to fix.";
            }
            if ($topic === 'documents') {
                return "Sure - the details are on the request page itself. If it says needs_resubmission, Accounting left a reason right there; fix that one item and upload again.";
            }
            return "Sure - what would you like to know about that?";
        }

        // Policy questions the portal does not store: never invent an answer.
        // Checked BEFORE topic routing so "tuition refund policy" is not
        // answered with generic payment steps.
        if ($pick(['refund', 'policy', 'scholarship', 'discount', 'penalty', 'late fee', 'deadline', 'cut-off', 'cutoff', 'withdraw'])) {
            return "That one's school policy, and I don't want to guess at it - policies change each year. The Registrar or Accounting office can confirm it for you, and you'll also see the real figures in your own ledger. If you tell me which document you're trying to settle, I can point you to the right office.";
        }

        if ($pick(['payment', 'pay ', 'pay?', 'payrex', 'tuition', 'bill', 'ledger', 'owe', 'balance', 'monthly'])) {
            return "Payment runs through My Applications. Once Accounting and the Registrar approve your enrollment, a Pay option appears there with your exact amount - you can pay in full or follow the active tuition plan. GCash, Maya, card, and QR Ph are supported. I can't see live payment data right now, so check the amount shown there and tell me what you see if something looks off.";
        }
        if ($pick(['grade', 'mark', 'score', 'gwa', 'grade slip', 'university'])) {
            return "Your grades live under My Classes and Grades on your dashboard. A grade only counts as official once the teacher finalizes it and the Registrar approves it - before that it's still a draft. If the page is empty, your teacher probably hasn't finalized that period yet.";
        }
        if ($pick(['enroll', 'apply', 'application', 'admission', 'register my child'])) {
            return "You start an enrollment from your dashboard - open Enroll, fill in the details, and attach the documents the form asks for. Submitting puts it in front of Accounting first, then the Registrar, then payment. After that you can follow it any time under My Applications.";
        }
        if ($pick(['document', 'psa', 'form 137', 'form 138', 'id photo', 'good moral', 'birth cert', 'eccd', 'transcript', 'registr form', 'certificate'])) {
            return "You have two places for documents. Permanent ones like PSA, Valid ID, Good Moral, Form 137, Form 138 and your 2x2 photo go under Enrollment Documents - upload once and they're reused. Stamped papers like transcripts and grade slips you request under Academic Documents, and the Registrar releases them for you to print.";
        }
        if ($pick(['permit', 'exam'])) {
            return "Exam permits unlock once you're fully paid and enrolled. You request them in order - Prelim, then Midterm, then Prefinal, then Finals - and each one is checked by Accounting, then the Registrar, then your teacher. Track them under Exam Permits.";
        }
        if ($pick(['clearance', 'clear'])) {
            return "Clearance has two halves. Your teachers sign their subjects first, the Registrar reviews it, and then Accounting clears the financial side. You can request and track both from Clearances on your dashboard.";
        }
        if ($pick(['class', 'subject', 'join', 'invite', 'code', 'schedule', 'task', 'assignment', 'submit'])) {
            return "You join subjects once you're fully enrolled. Open Class Offerings, find the subject, and enter the invite code your teacher gave you. After that, My Classes shows your schedule, assignments, and submissions.";
        }
        if ($pick(['password', 'login', 'log in', 'sign in', 'locked', 'account', 'profile', 'mfa', 'security', 'otp', 'verify', 'verification', 'reset'])) {
            return "Account help lives under Security - that's where you change your password, set up two-factor authentication, and see your devices and active sessions. If you can't sign in, use Forgot Password; the reset link is valid for one hour. Accounts also unlock on their own 15 minutes after a few failed attempts.";
        }
        if ($pick(['hello', 'hi ', 'hey', 'good morning', 'good afternoon', 'kumusta'])) {
            return "Hi! I'm the EduPortal AI assistant. I can walk you through enrollment, documents, payments, grades, permits and clearances. What do you need?";
        }
        if (preg_match('/\b(basketball|football|joke|love|weather|games?|movie|music|political)\b/i', $m)) {
            return "That's outside what I can help with - I'm all about the portal. For anything else, the school office is the right place. What can I help you with in EduPortal?";
        }
        if (mb_strlen(trim($msg)) < 3) {
            return "I'm here - what would you like to know about the portal?";
        }
        return "I'm not sure about that one, and I don't want to guess. Try asking about enrollment, documents, payments, grades, permits, or clearances - or check with the Registrar's office for anything I've missed.";
    }

    /**
     * Strip secrets a user may have pasted into the chat box, so they never
     * reach chat_history, the AI provider, or the logs. Replaced, not dropped,
     * so the user can see that something was removed.
     */
    public static function redact(string $m): string
    {
        $patterns = [
            '/\b(?:password|passcode|pin)\b\s*(?:is|=|:)\s*\S+/i'                => 'password [removed]',
            '/\b(?:otp|verification code|auth code|2fa code)\s*(?:is|[:=])?\s*\b\d{4,8}\b/i' => 'one-time code [removed]',
            '/\b(?:recovery|backup)\s+codes?\b\s*[:=].*/i'                        => 'recovery codes [removed]',
            '/\b(?:sk|pk|whsk)_[A-Za-z0-9_]{10,}\b/'                             => 'api key [removed]',
            '/\b\d{4}[- ]?\d{4}[- ]?\d{4}[- ]?\d{4}\b/'                          => 'card number [removed]',
            '/\b(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{10,}\b/'                    => 'access token [removed]',
            '/\bAIza[0-9A-Za-z\-_]{20,}\b/'                                      => 'api key [removed]',
        ];
        $out = $m;
        foreach ($patterns as $p => $repl) {
            $out = preg_replace($p, $repl, $out) ?? $out;
        }
        return trim($out) === ''
            ? 'I removed that - please never share passwords, codes, or card details here.'
            : $out;
    }

    /** Cheap topic label so the fallback can resolve short follow-ups. */
    public static function topicOf(string $msg): string
    {
        $m = ' ' . strtolower(trim($msg)) . ' ';
        $map = [
            'payment'  => ['payment', 'pay ', 'payrex', 'tuition', 'bill', 'ledger', 'monthly'],
            'enrollment' => ['enroll', 'apply', 'application', 'admission'],
            'documents' => ['document', 'psa', 'form 137', 'form 138', 'id photo', 'good moral', 'eccd', 'transcript'],
            'grades'   => ['grade', 'mark', 'score', 'gwa', 'grade slip'],
            'permits'  => ['permit', 'exam'],
            'clearance' => ['clearance'],
            'classes'  => ['class', 'subject', 'join', 'invite', 'schedule', 'task', 'assignment'],
            'account'  => ['password', 'login', 'sign in', 'locked', 'profile', 'mfa', 'security', 'verify'],
        ];
        foreach ($map as $topic => $keys) {
            foreach ($keys as $k) if (str_contains($m, $k)) return $topic;
        }
        return '';
    }
}