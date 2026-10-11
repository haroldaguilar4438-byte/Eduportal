<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use Throwable;

/**
 * Public legal & support pages (Privacy, Terms, Support).
 *
 * Content describes what the application ACTUALLY does (verified against the
 * codebase) and makes no compliance/certification claims. Body text is
 * overridable via system_settings (privacy_policy, terms_text) so authorized
 * personnel can maintain official wording without code changes; contact
 * details come from existing settings + the campuses table (never invented).
 */
final class LegalController extends Controller
{
    private static function setting(string $key): string
    {
        try {
            $v = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", [$key]);
            if ($v !== null && trim((string) $v) !== '') return trim((string) $v);
        } catch (Throwable $e) {
        }
        return '';
    }

    /** @return array{name:string,address:string,contact:string,support:string,registrar:string} */
    private static function school(): array
    {
        $out = [
            'name' => 'Datamex College of Saint Adeline',
            'address' => 'Dasmarinas, Cavite, Philippines',
            'contact' => 'datamex82@gmail.com',
            'support' => 'support@datamex.edu.ph',
            'registrar' => 'registrar@datamex.edu.ph',
        ];
        foreach (['school_name' => 'name', 'school_address' => 'address', 'school_contact' => 'contact', 'support_email' => 'support', 'registrar_email' => 'registrar'] as $k => $f) {
            $v = self::setting($k);
            if ($v !== '') $out[$f] = $v;
        }
        return $out;
    }

    private static function defaultPrivacy(array $school): string
    {
        $lines = [
            'What we collect|We collect only what enrollment, grading, and school operations need: account details (name, email, phone), enrollment information, school documents you upload (birth certificate, report cards, ID photo), grades and attendance-linked records, payment references, and clearance/permit records. Teacher accounts additionally store employment details and invite verification trails.',
            'How we use it|Your information is used to process enrollment, assign classes, record grades, issue permits/clearances/documents, and communicate about your application. We do not sell personal data.',
            'Payments|Online payments go through PayRex (GCash, Maya, QR Ph). Card and bank details are handled by the payment provider; the portal stores only transaction references, amounts, and statuses. For billing questions, contact ' . $school['support'] . '.',
            'AI chatbot|The help chatbot sends your typed question to Google Gemini to generate an answer. Chat history is saved only for signed-in accounts; guest chats are not stored.',
            'QR verification|Official permits and clearances carry QR codes rendered through an external image service to verify authenticity.',
            'Cookies and local data|We use an essential session cookie to keep you signed in (30-minute idle timeout), an optional 30-day "keep me signed in" cookie only if you tick the box, and browser-local settings (theme, live-update cursor). There is no advertising tracking on this portal.',
            'Children|Kindergarten to Junior High enrollment is filed by a parent/guardian account. Parents see only their own linked children\'s records.',
            'Your rights|You may request access or correction of your records through the Registrar. Account or record concerns: ' . $school['contact'] . '.',
            'Retention|Academic and financial records are kept while enrolled and per school retention policy afterwards. Ask the Registrar for the current schedule.',
        ];
        $html = '';
        foreach ($lines as $i => $sec) {
            [$h, $b] = explode('|', $sec, 2);
            $html .= '<h2>' . ($i + 1) . '. ' . $h . '</h2><p>' . $b . '</p>';
        }
        return $html;
    }

    private static function defaultTerms(array $school): string
    {
        $lines = [
            'Who may use the portal|Students, parents/guardians (Kindergarten–Junior High enrollment only), teachers (invite-only registration), registrar, accounting, and admin staff, each with role-limited access.',
            'Your account|Keep your password and teacher invite codes confidential. You are responsible for activity under your account. Sessions end after 30 minutes idle.',
            'Fair use|Use the portal only for legitimate school transactions. Do not attempt to access other users\' records, submit false documents, or disrupt the service. Misuse may lead to suspension.',
            'Academic integrity|Classwork submissions must be the student\'s own work. Parents may monitor progress and manage enrollments/joins for young children but must not submit work as the student.',
            'Enrollment and payments|Enrollment follows accounting verification, registrar approval, and PayRex payment. Amounts due come from official assessments, never from browser input. For billing questions, contact ' . $school['support'] . '.',
            'Official records|Grades, clearances, permits, and documents shown here are system-generated from finalized records. Disputes go to the Registrar.',
        ];
        $html = '';
        foreach ($lines as $i => $sec) {
            [$h, $b] = explode('|', $sec, 2);
            $html .= '<h2>' . ($i + 1) . '. ' . $h . '</h2><p>' . $b . '</p>';
        }
        return $html;
    }

    public function privacy(array $params = []): void
    {
        $school = self::school();
        $body = self::setting('privacy_policy');
        $this->view('legal/page', [
            'title' => 'Privacy Policy',
            'heading' => 'Privacy Policy',
            'updated' => date('F Y'),
            'bodyHtml' => $body !== '' ? $body : self::defaultPrivacy($school),
            'school' => $school,
            'custom' => $body !== '',
        ], 'shared/layout_auth');
    }

    public function terms(array $params = []): void
    {
        $school = self::school();
        $body = self::setting('terms_text');
        $this->view('legal/page', [
            'title' => 'Terms & Conditions',
            'heading' => 'Portal Terms & Conditions',
            'updated' => date('F Y'),
            'bodyHtml' => $body !== '' ? $body : self::defaultTerms($school),
            'school' => $school,
            'custom' => $body !== '',
        ], 'shared/layout_auth');
    }

    public function support(array $params = []): void
    {
        $school = self::school();
        $campuses = [];
        try {
            $campuses = Database::all("SELECT code, name FROM campuses WHERE is_active = 1 ORDER BY name LIMIT 20");
        } catch (Throwable $e) {
        }
        $this->view('legal/support', [
            'title' => 'Support & Contact',
            'school' => $school,
            'campuses' => $campuses,
        ], 'shared/layout_auth');
    }
}
