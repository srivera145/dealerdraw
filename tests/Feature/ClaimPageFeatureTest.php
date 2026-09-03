<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Square;
use Keel\Core\Database;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * The public claim page itself: branding, the pre/post-lock split, and what
 * happens when two visitors reach for the same square.
 */
class ClaimPageFeatureTest extends TestCase
{
    use SquaresFixtures;

    public function testTwoVisitorsOnTheSameSquareLeaveExactlyOneWinner(): void
    {
        $context = $this->openBoard();
        $boardId = $context['boardId'];

        // The guard itself: a second assign against a filled square matches no rows.
        $firstClaimId = $this->claimSquare($boardId, 2, 2, ['email' => 'early@example.test']);
        self::assertFalse(Square::assign($boardId, 2, 2, $firstClaimId));

        // And through the route, with two visitors racing for square 4-4.
        $visitorOne = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
            'email' => 'one@example.test',
            'phone' => '5551110000',
            'squares' => ['4-4'],
        ]));

        $visitorTwo = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
            'email' => 'two@example.test',
            'phone' => '5552220000',
            'squares' => ['4-4'],
        ]));

        self::assertStringContainsString('notice=', (string) $visitorOne->header('Location'));
        self::assertStringContainsString('error=', (string) $visitorTwo->header('Location'));

        // One row, one owner, and the loser created no claim at all.
        self::assertSame(1, $this->countRows('squares', 'board_id = ? AND row_index = 4 AND col_index = 4 AND claim_id IS NOT NULL', [$boardId]));
        self::assertSame(0, $this->countRows('claims', 'board_id = ? AND email = ?', [$boardId, 'two@example.test']));

        $owner = Database::connection()->prepare(
            'SELECT c.email FROM squares s INNER JOIN claims c ON c.id = s.claim_id
             WHERE s.board_id = ? AND s.row_index = 4 AND s.col_index = 4'
        );
        $owner->execute([$boardId]);
        self::assertSame('one@example.test', (string) $owner->fetchColumn());
    }

    public function testConflictResponseNamesTheFailedSquaresAndKeepsTheRest(): void
    {
        $context = $this->openBoard();
        $boardId = $context['boardId'];

        // Two of the four squares go while the visitor is deciding.
        $this->claimSquare($boardId, 1, 1, ['email' => 'gone1@example.test']);
        $this->claimSquare($boardId, 3, 3, ['email' => 'gone2@example.test']);

        $response = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
            'squares' => ['0-0', '1-1', '2-2', '3-3'],
        ]), ['Accept' => 'application/json']);

        self::assertSame(409, $response->status);

        $payload = $response->json();
        self::assertFalse($payload['ok']);
        self::assertSame(['1-1', '3-3'], $payload['failed']);
        self::assertStringContainsString('2 of your squares', (string) $payload['error']);

        // Nothing was written: the visitor resubmits 0-0 and 2-2 themselves.
        self::assertSame(0, $this->countRows('claims', 'board_id = ? AND email = ?', [$boardId, 'alex@example.test']));
        self::assertSame(2, $this->countRows('squares', 'board_id = ? AND claim_id IS NOT NULL', [$boardId]));

        $retry = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
            'squares' => ['0-0', '2-2'],
        ]), ['Accept' => 'application/json']);

        self::assertSame(201, $retry->status);
        self::assertTrue($retry->json()['ok']);
        self::assertSame(['0-0', '2-2'], $retry->json()['cells']);
        self::assertSame(4, $this->countRows('squares', 'board_id = ? AND claim_id IS NOT NULL', [$boardId]));
    }

    public function testConflictWithoutJavaScriptBouncesBackWithTheTakenAndKeptSquares(): void
    {
        $context = $this->openBoard();
        $this->claimSquare($context['boardId'], 1, 1, ['email' => 'gone@example.test']);

        $response = $this->post('/p/' . $context['slug'] . '/claim', $this->claimPayload([
            'squares' => ['0-0', '1-1'],
        ]));

        self::assertSame(302, $response->status);

        $location = (string) $response->header('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        self::assertSame('1-1', $query['taken']);
        self::assertSame('0-0', $query['sel']);

        // Following the bounce re-renders the page with 0-0 still ticked and
        // 1-1 flagged, so the visitor can resubmit in one tap.
        $page = $this->get($location);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Still selected: 0-0.', $page->body);
        self::assertStringContainsString('square square--selected" data-cell="0-0"', $page->body);
        self::assertStringContainsString('square--conflict" data-cell="1-1"', $page->body);
    }

    public function testUnbrandedCampaignRendersCleanly(): void
    {
        $context = $this->openBoard([], ['brand_primary_color' => null, 'brand_logo_path' => null]);

        $page = $this->get('/p/' . $context['slug']);

        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('class="claim-logo"', $page->body);
        // Falls back to the neutral default and keeps a readable button colour.
        self::assertStringContainsString('--dealer-brand: #111827', $page->body);
        self::assertStringContainsString('--dealer-brand-contrast: #ffffff', $page->body);
        // The colour still anchors the header where a logo would have gone.
        self::assertStringContainsString('claim-header claim-header--plain', $page->body);
        self::assertStringContainsString('No purchase necessary. Free to enter.', $page->body);
        self::assertSame(100, substr_count($page->body, 'name="squares[]"'));
    }

    public function testBrandedCampaignInjectsLogoAndColourAsCustomProperties(): void
    {
        $context = $this->openBoard([], [
            'brand_primary_color' => '#0f766e',
            'brand_logo_path' => 'brand/9/logo.png',
        ]);

        $page = $this->get('/p/' . $context['slug']);

        self::assertStringContainsString('--dealer-brand: #0f766e', $page->body);
        self::assertStringContainsString('--dealer-brand-contrast: #ffffff', $page->body);
        self::assertStringContainsString('src="/uploads/brand/9/logo.png"', $page->body);
    }

    public function testPaleBrandColourGetsDarkInkSoButtonsStayReadable(): void
    {
        $context = $this->openBoard([], ['brand_primary_color' => '#fde047']);

        $page = $this->get('/p/' . $context['slug']);

        self::assertStringContainsString('--dealer-brand: #fde047', $page->body);
        self::assertStringContainsString('--dealer-brand-contrast: #111827', $page->body);
    }

    public function testLockedBoardShowsDigitsOnAxesAndReplacesTheFormWithStatus(): void
    {
        $context = $this->openBoard();
        $boardId = $context['boardId'];

        $openPage = $this->get('/p/' . $context['slug']);
        self::assertStringContainsString('Tap your squares', $openPage->body);
        self::assertStringContainsString('id="first_name"', $openPage->body);
        self::assertStringContainsString('name="consent_sms"', $openPage->body);
        self::assertStringNotContainsString('Board status', $openPage->body);

        $this->lockBoardWithDigits($boardId, [3, 1, 4, 1, 5, 9, 2, 6, 8, 7], [2, 7, 1, 8, 2, 8, 1, 8, 2, 8]);
        $this->createPrize($boardId, 'q1', ['label' => 'Free Oil Change', 'terms_text' => 'Most vehicles.']);

        $lockedPage = $this->get('/p/' . $context['slug']);

        self::assertStringContainsString('Board status', $lockedPage->body);
        self::assertStringContainsString('Numbers are drawn.', $lockedPage->body);
        // The contact form is gone, not merely hidden.
        self::assertStringNotContainsString('id="first_name"', $lockedPage->body);
        self::assertStringNotContainsString('name="consent_sms"', $lockedPage->body);
        // Axes now carry the drawn digits.
        self::assertStringContainsString('data-row-digit="0">3</th>', $lockedPage->body);
        self::assertStringContainsString('data-col-digit="1">7</th>', $lockedPage->body);
        // Every checkbox is disabled once entries close.
        self::assertSame(100, substr_count($lockedPage->body, 'disabled'));
    }

    public function testPrizesRenderUnderTheGridWithValueAndTerms(): void
    {
        $context = $this->openBoard();

        $this->createPrize($context['boardId'], 'q1', [
            'label' => 'Free Oil Change',
            'retail_value' => '79.95',
            'terms_text' => 'Conventional oil and filter. Most vehicles.',
            'expires_days' => 90,
        ]);

        $page = $this->get('/p/' . $context['slug']);

        self::assertStringContainsString('Free Oil Change', $page->body);
        self::assertStringContainsString('Retail value $79.95', $page->body);
        self::assertStringContainsString('Conventional oil and filter. Most vehicles.', $page->body);
        self::assertStringContainsString('Redeem within 90 days', $page->body);

        // Under the grid, per the layout brief.
        self::assertGreaterThan(
            strpos($page->body, 'data-board'),
            strpos($page->body, 'What you can win')
        );
    }

    public function testPageLinksTheStandaloneBoardAssets(): void
    {
        $context = $this->openBoard();

        $page = $this->get('/p/' . $context['slug']);

        self::assertMatchesRegularExpression('#href="/assets/css/board\.css(\?v=\d+)?"#', $page->body);
        self::assertMatchesRegularExpression('#src="/assets/js/board\.js(\?v=\d+)?" defer#', $page->body);
    }

    /**
     * @return array{slug: string, boardId: int}
     */
    private function openBoard(array $boardOverrides = [], array $campaignOverrides = []): array
    {
        $dealer = $this->createOrganization('Dealer A');
        $slug = 'page-test-' . bin2hex(random_bytes(3));

        $campaign = $this->createCampaign((int) $dealer['id'], array_merge([
            'public_slug' => $slug,
            'status' => 'active',
        ], $campaignOverrides));

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
