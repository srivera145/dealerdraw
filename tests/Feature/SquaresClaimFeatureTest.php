<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Square;
use Keel\Core\Database;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * Self-check: does the claim page render and function with JS disabled well
 * enough to submit? Every request below is a plain form post - no JSON, no
 * fetch, nothing the grid script provides.
 */
class SquaresClaimFeatureTest extends TestCase
{
    use SquaresFixtures;

    public function testClaimPageRendersTheDisclosureAndAPostableGridWithoutJavaScript(): void
    {
        $context = $this->openBoard();

        $response = $this->get('/p/' . $context['slug']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('No purchase necessary. Free to enter.', $response->body);

        // The disclosure sits above the grid, not below it.
        self::assertLessThan(
            strpos($response->body, 'data-board'),
            strpos($response->body, 'No purchase necessary. Free to enter.')
        );

        // All 100 cells are real form controls rendered by PHP.
        self::assertSame(100, substr_count($response->body, 'name="squares[]"'));
        self::assertStringContainsString('action="/p/' . $context['slug'] . '/claim"', $response->body);
        self::assertStringContainsString('name="_csrf"', $response->body);
    }

    public function testFormPostClaimsSquaresAndRecordsConsent(): void
    {
        $context = $this->openBoard();
        $boardId = $context['boardId'];

        $response = $this->post('/p/' . $context['slug'] . '/claim', [
            '_csrf' => $this->csrfToken(),
            'board' => $boardId,
            'first_name' => 'Jordan',
            'last_name' => 'Blake',
            'email' => 'Jordan.Blake@Example.test',
            'phone' => '(555) 010-2233',
            'consent_email' => '1',
            'consent_sms' => '1',
            'squares' => ['0-0', '3-4'],
        ]);

        self::assertSame(302, $response->status);
        self::assertStringContainsString('notice=', (string) $response->header('Location'));

        $claim = Database::connection()->query('SELECT * FROM claims ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('jordan.blake@example.test', (string) $claim['email']);
        self::assertSame('5550102233', (string) $claim['phone']);
        self::assertSame(1, (int) $claim['consent_sms']);
        self::assertSame(1, (int) $claim['consent_email']);

        self::assertSame(2, $this->countRows('squares', 'board_id = ? AND claim_id = ?', [$boardId, (int) $claim['id']]));
        self::assertSame(98, Square::availableCount($boardId));

        // The public grid reports the cells as taken, with a privacy-trimmed name.
        $payload = $this->getJson('/p/' . $context['slug'] . '/board')->json();
        $taken = array_values(array_filter($payload['squares'], static fn (array $square): bool => $square['taken']));
        self::assertCount(2, $taken);
        self::assertSame('Jordan B.', $taken[0]['name']);
    }

    public function testClaimLimitIsEnforcedPerEmailOrPhoneAcrossSubmissions(): void
    {
        $context = $this->openBoard(['claim_limit' => 3]);

        $first = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload(['squares' => ['0-0', '0-1']]));
        self::assertSame(302, $first->status);
        self::assertStringContainsString('notice=', (string) $first->header('Location'));

        // Same email, different phone: still the same person for limit purposes.
        $second = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
            'phone' => '5559998888',
            'squares' => ['1-0', '1-1'],
        ]));

        self::assertSame(302, $second->status);
        self::assertStringContainsString('You can claim 1 more', urldecode((string) $second->header('Location')));
        self::assertSame(2, $this->countRows('squares', 'board_id = ? AND claim_id IS NOT NULL', [$context['boardId']]));

        $third = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload(['squares' => ['1-0']]));
        self::assertStringContainsString('notice=', (string) $third->header('Location'));
        self::assertSame(3, $this->countRows('squares', 'board_id = ? AND claim_id IS NOT NULL', [$context['boardId']]));

        $fourth = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload(['squares' => ['2-0']]));
        self::assertStringContainsString('error=', (string) $fourth->header('Location'));
        self::assertSame(3, $this->countRows('squares', 'board_id = ? AND claim_id IS NOT NULL', [$context['boardId']]));
    }

    public function testAlreadyTakenSquareIsRejectedAndLeavesNoPartialClaim(): void
    {
        $context = $this->openBoard();
        $this->claimSquare($context['boardId'], 5, 5, ['email' => 'first@example.test']);

        $claimsBefore = $this->countRows('claims', 'board_id = ?', [$context['boardId']]);

        $response = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
            'email' => 'second@example.test',
            'squares' => ['6-6', '5-5'],
        ]));

        self::assertSame(302, $response->status);
        self::assertStringContainsString('error=', (string) $response->header('Location'));

        // The whole submission rolls back: no new claim row, and 6-6 stays open.
        self::assertSame($claimsBefore, $this->countRows('claims', 'board_id = ?', [$context['boardId']]));
        self::assertSame(1, $this->countRows('squares', 'board_id = ? AND claim_id IS NOT NULL', [$context['boardId']]));
    }

    public function testClaimIsRejectedWithoutAContactConsentOrSquares(): void
    {
        $context = $this->openBoard();

        $noConsent = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
            'consent_email' => '',
            'consent_sms' => '',
        ]));
        self::assertStringContainsString('error=', (string) $noConsent->header('Location'));

        $noSquares = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload(['squares' => []]));
        self::assertStringContainsString('error=', (string) $noSquares->header('Location'));

        self::assertSame(0, $this->countRows('claims', 'board_id = ?', [$context['boardId']]));
    }

    public function testClaimsAreClosedOnceTheBoardIsLocked(): void
    {
        $context = $this->openBoard();
        $this->lockBoardWithDigits($context['boardId'], range(0, 9), range(0, 9));

        $response = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload());

        self::assertSame(302, $response->status);
        self::assertStringContainsString('closed', urldecode((string) $response->header('Location')));
        self::assertSame(0, $this->countRows('claims', 'board_id = ?', [$context['boardId']]));

        $page = $this->get('/p/' . $context['slug']);
        self::assertStringContainsString('Entries are closed and the numbers have been drawn.', $page->body);
        self::assertStringContainsString('No purchase necessary. Free to enter.', $page->body);
    }

    public function testClaimPostsAreRateLimitedByIp(): void
    {
        $context = $this->openBoard(['claim_limit' => 100]);

        $accepted = 0;
        $throttled = 0;

        for ($attempt = 0; $attempt < 14; $attempt++) {
            $response = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
                'email' => 'rate' . $attempt . '@example.test',
                'phone' => '55510000' . str_pad((string) $attempt, 2, '0', STR_PAD_LEFT),
                'squares' => [intdiv($attempt, 10) . '-' . ($attempt % 10)],
            ]));

            $location = urldecode((string) $response->header('Location'));

            if (str_contains($location, 'Too many claim attempts')) {
                $throttled++;
                continue;
            }

            $accepted++;
        }

        self::assertSame(10, $accepted, 'the eleventh claim from one IP is refused');
        self::assertSame(4, $throttled);
        self::assertSame(10, $this->countRows('claims', 'board_id = ?', [$context['boardId']]));
    }

    /**
     * @return array{slug: string, boardId: int}
     */
    private function openBoard(array $boardOverrides = []): array
    {
        $dealer = $this->createOrganization('Dealer A');
        $slug = 'claim-test-' . bin2hex(random_bytes(3));
        $campaign = $this->createCampaign((int) $dealer['id'], ['public_slug' => $slug, 'status' => 'active']);
        $game = $this->createGame(['kickoff_at' => date('Y-m-d H:i:s', time() + 86400)]);
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id'], $boardOverrides);

        return ['slug' => $slug, 'boardId' => (int) $board['id']];
    }

    private function claimPayload(array $overrides = []): array
    {
        return array_merge([
            '_csrf' => $this->csrfToken(),
            'first_name' => 'Alex',
            'last_name' => 'Nunez',
            'email' => 'alex@example.test',
            'phone' => '5550001111',
            'consent_email' => '1',
            'squares' => ['9-9'],
        ], $overrides);
    }
}
