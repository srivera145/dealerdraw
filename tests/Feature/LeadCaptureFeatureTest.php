<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\Core\Database;
use Keel\Core\Session;
use Tests\TestCase;

/**
 * The demo request form, and the guarantee that the sample board writes nothing.
 */
class LeadCaptureFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['LEAD_NOTIFICATION_EMAIL'] = 'sales@dealerdraw.test';
        $_SERVER['LEAD_NOTIFICATION_EMAIL'] = 'sales@dealerdraw.test';
    }

    public function testValidSubmissionStoresTheLeadAndEmailsTheAdmin(): void
    {
        $response = $this->submit();

        self::assertSame(302, $response->status);
        self::assertSame('/?submitted=1#request-demo', $response->header('Location'));

        $lead = Database::connection()->query('SELECT * FROM leads ORDER BY id DESC LIMIT 1')->fetch();

        self::assertIsArray($lead);
        self::assertSame('Rivera Motors', (string) $lead['dealer_name']);
        self::assertSame('Santos Rivera', (string) $lead['contact_name']);
        self::assertSame('santos@riveramotors.test', (string) $lead['email']);
        self::assertSame(3, (int) $lead['rooftop_count']);
        self::assertSame('landing_page', (string) $lead['source']);

        $mail = $this->latestMailLog();
        self::assertStringContainsString('sales@dealerdraw.test', $mail);
        self::assertStringContainsString('New demo request: Rivera Motors', $mail);
        self::assertStringContainsString('santos@riveramotors.test', $mail);

        // The success banner shows on the page it lands back on.
        self::assertStringContainsString('We will call or text within one business day', $this->get('/?submitted=1')->body);
    }

    public function testHoneypotSubmissionIsDiscardedButLooksSuccessful(): void
    {
        $response = $this->submit(['company_website' => 'http://spam.example']);

        // A bot gets the same answer a person does and learns nothing.
        self::assertSame(302, $response->status);
        self::assertSame('/?submitted=1#request-demo', $response->header('Location'));
        self::assertSame(0, $this->countLeads());
    }

    public function testSubmissionFasterThanAHumanIsDiscarded(): void
    {
        // Form "rendered" this instant, then posted immediately.
        Session::put('lead_form_rendered_at', time());

        $response = $this->post('/request-demo', $this->payload(['form_started_at' => time()]));

        self::assertSame(302, $response->status);
        self::assertSame(0, $this->countLeads());
    }

    public function testSubmissionWithoutEverRenderingTheFormIsDiscarded(): void
    {
        Session::forget('lead_form_rendered_at');

        $response = $this->post('/request-demo', $this->payload(['form_started_at' => 0]));

        self::assertSame(302, $response->status);
        self::assertSame(0, $this->countLeads());
    }

    public function testValidationErrorsComeBackOnTheFormWithTheInputPreserved(): void
    {
        $response = $this->submit([
            'dealer_name' => '',
            'email' => 'not-an-email',
            'phone' => '123',
            'rooftop_count' => 'seven',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/#request-demo', $response->header('Location'));
        self::assertSame(0, $this->countLeads());

        $page = $this->get('/');

        self::assertStringContainsString('Tell us the dealership name.', $page->body);
        self::assertStringContainsString('Enter a valid work email address.', $page->body);
        self::assertStringContainsString('Enter a phone number we can reach you on.', $page->body);
        self::assertStringContainsString('Rooftops should be a whole number.', $page->body);
        self::assertStringContainsString('aria-invalid="true"', $page->body);

        // What they typed is still in the fields.
        self::assertStringContainsString('value="not-an-email"', $page->body);
        self::assertStringContainsString('value="Santos Rivera"', $page->body);

        // And the errors clear once shown.
        self::assertStringNotContainsString('Tell us the dealership name.', $this->get('/')->body);
    }

    public function testRooftopCountIsOptional(): void
    {
        $this->submit(['rooftop_count' => '']);

        $lead = Database::connection()->query('SELECT * FROM leads ORDER BY id DESC LIMIT 1')->fetch();

        self::assertIsArray($lead);
        self::assertNull($lead['rooftop_count']);
    }

    public function testADoubleTapDoesNotCreateTwoLeads(): void
    {
        $this->submit();
        self::assertSame(1, $this->countLeads());

        $this->submit();
        self::assertSame(1, $this->countLeads(), 'a repeat inside a few minutes is a double-tap, not a lead');
    }

    public function testRepeatedSubmissionsFromOneAddressAreRateLimited(): void
    {
        for ($attempt = 0; $attempt < 7; $attempt++) {
            $this->submit([
                'email' => 'buyer' . $attempt . '@example.test',
                'dealer_name' => 'Dealer ' . $attempt,
            ]);
        }

        // Five get through per hour; the rest are refused.
        self::assertSame(5, $this->countLeads());
        self::assertStringContainsString('Too many requests', $this->get('/')->body);
    }

    public function testCsrfIsRequired(): void
    {
        Session::put('lead_form_rendered_at', time() - 30);

        $payload = $this->payload();
        unset($payload['_csrf']);

        $response = $this->post('/request-demo', $payload);

        self::assertSame(419, $response->status);
        self::assertSame(0, $this->countLeads());
    }

    /* ---- the demo board writes nothing ------------------------------- */

    public function testDemoPageRendersSeededClaimsAndIsIndexable(): void
    {
        $response = $this->get('/demo');

        self::assertSame(200, $response->status);
        self::assertSame(100, substr_count($response->body, 'name="squares[]"'));
        self::assertStringContainsString('Marcus T.', $response->body);
        self::assertStringContainsString('Dana L.', $response->body);
        self::assertStringContainsString('No purchase necessary. Free to enter.', $response->body);
        self::assertStringContainsString('nothing you do here is saved', $response->body);
        // Indexable on purpose: it is a real product page, and it is in the sitemap.
        self::assertStringContainsString('content="index,follow', $response->body);

        // Full names are never shown, on the demo or anywhere else.
        self::assertStringNotContainsString('Marcus Thompson', $response->body);
    }

    public function testNoPathFromTheDemoPageCreatesADatabaseRow(): void
    {
        $before = $this->gameDataCounts();

        // Every interaction the page offers.
        $this->get('/demo');
        $this->get('/demo?submitted=1');

        $page = $this->get('/demo')->body;

        // The only form on the page is a GET, so there is nothing to post to.
        preg_match_all('#<form[^>]*>#', $page, $forms);
        self::assertNotEmpty($forms[0]);

        foreach ($forms[0] as $form) {
            self::assertStringContainsString('method="GET"', $form);
            self::assertStringNotContainsString('method="POST"', $form);
        }

        self::assertStringNotContainsString('/p/', $page, 'the demo must not link at a real claim page');

        // Even posting the demo grid straight at the real claim endpoint finds
        // nothing to write to, because no campaign backs it.
        $forged = $this->post('/p/demo/claim', [
            '_csrf' => $this->csrfToken(),
            'first_name' => 'Bot',
            'last_name' => 'Tester',
            'email' => 'bot@example.test',
            'phone' => '5550001234',
            'consent_email' => '1',
            'squares' => ['0-0'],
        ]);

        self::assertSame(404, $forged->status);

        self::assertSame($before, $this->gameDataCounts(), 'the demo created game data');
        self::assertSame(0, $this->countLeads(), 'the demo created a lead');
    }

    public function testDemoSubmitShowsTheDemoMessageWithoutJavaScript(): void
    {
        $response = $this->get('/demo?submitted=1');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('This is a demo, so nothing was saved', $response->body);
        self::assertStringNotContainsString('hidden role="status"', $response->body);
    }

    /* ---- helpers ------------------------------------------------------ */

    /**
     * @return array<string, int>
     */
    private function gameDataCounts(): array
    {
        $counts = [];

        foreach (['campaigns', 'boards', 'squares', 'claims', 'wins', 'games', 'organizations'] as $table) {
            $counts[$table] = (int) Database::connection()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }

        return $counts;
    }

    private function countLeads(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            '_csrf' => $this->csrfToken(),
            'dealer_name' => 'Rivera Motors',
            'contact_name' => 'Santos Rivera',
            'email' => 'santos@riveramotors.test',
            'phone' => '(555) 010-2233',
            'rooftop_count' => '3',
            'message' => 'We run three stores and want this before the season.',
            'company_website' => '',
            'form_started_at' => time() - 30,
        ], $overrides);
    }

    private function submit(array $overrides = []): \Tests\Support\TestResponse
    {
        // Stand in for the visitor having loaded the page half a minute ago.
        Session::put('lead_form_rendered_at', time() - 30);

        return $this->post('/request-demo', $this->payload($overrides));
    }
}
