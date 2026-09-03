<?php

namespace Keel\App\Controllers\Public;

use Keel\App\Content\MarketingContent;
use Keel\App\Models\Lead;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\ErrorHandler;
use Keel\Core\Mailer;
use Keel\Core\RateLimiter;
use Keel\Core\Request;
use Keel\Core\Session;

/**
 * The dealerdraw.com marketing site: one page, one conversion action, plus a
 * sample board a visitor can tap through without an account.
 */
class SiteController extends Controller
{
    /** A human needs at least this long to fill the form in. */
    private const MIN_FORM_SECONDS = 3;

    private const MAX_SUBMISSIONS_PER_IP = 5;
    private const SUBMISSION_DECAY_MINUTES = 60;

    private const SESSION_FORM_RENDERED = 'lead_form_rendered_at';

    public function home(Request $request): void
    {
        Session::put(self::SESSION_FORM_RENDERED, time());

        $this->view('public.home', [
            'formStartedAt' => time(),
            'submitted' => $request->input('submitted') === '1',
            'errors' => $this->flashedErrors(),
            'old' => $this->flashedInput(),
        ]);
    }

    /**
     * The sample board. Reads nothing and writes nothing - the grid below is
     * generated in memory, so no path from this page reaches the database.
     */
    public function demo(Request $request): void
    {
        $this->view('public.demo', [
            'board' => self::sampleBoard(),
            'submitted' => $request->input('submitted') === '1',
        ]);
    }

    public function faq(Request $request): void
    {
        $this->view('public.faq', ['faqs' => MarketingContent::faqs()]);
    }

    public function guides(Request $request): void
    {
        $this->view('public.guides.index', ['guides' => MarketingContent::guides()]);
    }

    public function guide(Request $request, string $slug): void
    {
        $guide = MarketingContent::findGuide($slug);

        if ($guide === null) {
            ErrorHandler::render(404);

            return;
        }

        $this->view('public.guides._layout', [
            'guide' => $guide,
            'contentFile' => $guide['slug'],
        ]);
    }

    public function leadSubmit(Request $request): void
    {
        $ip = $this->clientIp();

        // Honeypot: a field no human sees, so anything in it is a bot. Answered
        // with the same success redirect a person gets, so the bot learns nothing.
        if (trim((string) $request->input('company_website', '')) !== '') {
            error_log('[DealerDraw] Lead form honeypot tripped from ' . $ip);

            $this->redirect('/?submitted=1#request-demo');
        }

        if (!$this->passedTimingCheck($request)) {
            error_log('[DealerDraw] Lead form submitted too fast from ' . $ip);

            $this->redirect('/?submitted=1#request-demo');
        }

        if (!RateLimiter::attempt('lead|' . $ip, self::MAX_SUBMISSIONS_PER_IP, self::SUBMISSION_DECAY_MINUTES)) {
            $this->failValidation(['form' => 'Too many requests from this connection. Please try again later.'], $request);
        }

        $fields = [
            'dealer_name' => trim((string) $request->input('dealer_name', '')),
            'contact_name' => trim((string) $request->input('contact_name', '')),
            'email' => strtolower(trim((string) $request->input('email', ''))),
            'phone' => trim((string) $request->input('phone', '')),
            'rooftop_count' => trim((string) $request->input('rooftop_count', '')),
            'message' => trim((string) $request->input('message', '')),
        ];

        $errors = $this->validate($fields);

        if ($errors !== []) {
            $this->failValidation($errors, $request);
        }

        // A repeat submission inside a few minutes is a double-tap, not a lead.
        if (Lead::recentlySubmitted($fields['email'])) {
            $this->redirect('/?submitted=1#request-demo');
        }

        $leadId = Lead::create([
            'dealer_name' => $fields['dealer_name'],
            'contact_name' => $fields['contact_name'],
            'email' => $fields['email'],
            'phone' => $fields['phone'],
            'rooftop_count' => $fields['rooftop_count'] === '' ? null : (int) $fields['rooftop_count'],
            'message' => $fields['message'] === '' ? null : $fields['message'],
            'source' => 'landing_page',
            'ip' => $ip,
        ]);

        $this->notifyAdmin($leadId, $fields);

        Session::forget(self::SESSION_FORM_RENDERED);
        Session::forget('lead_errors');
        Session::forget('lead_old');

        $this->redirect('/?submitted=1#request-demo');
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private function validate(array $fields): array
    {
        $errors = [];

        if ($fields['dealer_name'] === '') {
            $errors['dealer_name'] = 'Tell us the dealership name.';
        }

        if ($fields['contact_name'] === '') {
            $errors['contact_name'] = 'Tell us who we are speaking with.';
        }

        if (filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid work email address.';
        }

        if (strlen((string) preg_replace('/\D+/', '', $fields['phone'])) < 10) {
            $errors['phone'] = 'Enter a phone number we can reach you on.';
        }

        if ($fields['rooftop_count'] !== '' && (!ctype_digit($fields['rooftop_count']) || (int) $fields['rooftop_count'] < 1)) {
            $errors['rooftop_count'] = 'Rooftops should be a whole number.';
        }

        if (strlen($fields['message']) > 2000) {
            $errors['message'] = 'Please keep the message under 2000 characters.';
        }

        return $errors;
    }

    /**
     * The form carries a rendered-at timestamp and the session holds its own
     * copy, so neither a replayed form nor a scripted post gets through on
     * timing alone.
     */
    private function passedTimingCheck(Request $request): bool
    {
        $sessionStartedAt = (int) Session::get(self::SESSION_FORM_RENDERED, 0);
        $postedStartedAt = (int) $request->input('form_started_at', 0);
        $startedAt = max($sessionStartedAt, $postedStartedAt);

        if ($startedAt <= 0) {
            // The form was never rendered in this session.
            return false;
        }

        return (time() - $startedAt) >= self::MIN_FORM_SECONDS;
    }

    /**
     * @param array<string, string> $fields
     */
    private function notifyAdmin(int $leadId, array $fields): void
    {
        $recipient = trim((string) Env::get('LEAD_NOTIFICATION_EMAIL', ''));

        if ($recipient === '') {
            $recipient = trim((string) Env::get('MAIL_FROM_ADDRESS', ''));
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[DealerDraw] Lead ' . $leadId . ' stored but no valid notification address is configured.');

            return;
        }

        $rows = [
            'Dealership' => $fields['dealer_name'],
            'Contact' => $fields['contact_name'],
            'Email' => $fields['email'],
            'Phone' => $fields['phone'],
            'Rooftops' => $fields['rooftop_count'] === '' ? 'not given' : $fields['rooftop_count'],
            'Message' => $fields['message'] === '' ? 'none' : $fields['message'],
        ];

        $body = '<div style="font-family: Arial, sans-serif; font-size: 15px; color: #111827;">'
            . '<h2 style="margin:0 0 12px;">New demo request</h2><table cellpadding="4">';

        foreach ($rows as $label => $value) {
            $body .= '<tr><td style="color:#6b7280;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td><strong>' . nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')) . '</strong></td></tr>';
        }

        $body .= '</table><p style="color:#9ca3af;font-size:12px;">Lead #' . $leadId . ' from the DealerDraw landing page.</p></div>';

        if (!Mailer::send($recipient, 'DealerDraw', 'New demo request: ' . $fields['dealer_name'], $body)) {
            // The lead is already stored, so a failed email is not lost business.
            error_log('[DealerDraw] Could not email the notification for lead ' . $leadId . '.');
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function failValidation(array $errors, Request $request): never
    {
        Session::put('lead_errors', $errors);
        Session::put('lead_old', [
            'dealer_name' => (string) $request->input('dealer_name', ''),
            'contact_name' => (string) $request->input('contact_name', ''),
            'email' => (string) $request->input('email', ''),
            'phone' => (string) $request->input('phone', ''),
            'rooftop_count' => (string) $request->input('rooftop_count', ''),
            'message' => (string) $request->input('message', ''),
        ]);

        $this->redirect('/#request-demo');
    }

    /**
     * @return array<string, string>
     */
    private function flashedErrors(): array
    {
        $errors = Session::get('lead_errors', []);
        Session::forget('lead_errors');

        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string, string>
     */
    private function flashedInput(): array
    {
        $old = Session::get('lead_old', []);
        Session::forget('lead_old');

        return is_array($old) ? $old : [];
    }

    private function clientIp(): string
    {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
    }

    /**
     * A fixed, fictional board for the demo page. Built in memory on every
     * request - there is no query behind it and nothing to write to.
     *
     * @return array{home_team: string, away_team: string, claim_limit: int, squares: array<int, array<int, string>>, taken: int}
     */
    public static function sampleBoard(): array
    {
        $names = [
            'Marcus T.', 'Dana L.', 'Priya S.', 'Jordan B.', 'Ellis W.',
            'Renee C.', 'Tomas G.', 'Aisha K.', 'Bobby R.', 'Sam O.',
            'Kelly N.', 'Devon P.', 'Nina F.', 'Curtis A.', 'Rosa M.',
            'Wes H.', 'Ivy D.', 'Hector V.', 'Joan E.', 'Trey J.',
        ];

        // A fixed pattern, not random: the sample board looks the same to every
        // visitor and to the screenshot that ends up in the social card.
        $claimed = [
            '0-2', '0-7', '1-0', '1-4', '1-9', '2-3', '2-6', '3-1', '3-5', '3-8',
            '4-0', '4-4', '4-7', '5-2', '5-9', '6-1', '6-6', '7-3', '7-8', '8-5',
            '8-0', '9-2', '9-6', '2-9', '5-5', '6-3', '0-4', '7-1', '9-9', '3-3',
            '1-6', '4-2', '8-8', '2-1', '6-9',
        ];

        $squares = [];
        $index = 0;

        for ($row = 0; $row < 10; $row++) {
            for ($col = 0; $col < 10; $col++) {
                $key = $row . '-' . $col;
                $squares[$row][$col] = in_array($key, $claimed, true)
                    ? $names[$index++ % count($names)]
                    : '';
            }
        }

        return [
            'home_team' => 'Kansas City',
            'away_team' => 'Buffalo',
            'claim_limit' => 5,
            'squares' => $squares,
            'taken' => count($claimed),
        ];
    }
}
