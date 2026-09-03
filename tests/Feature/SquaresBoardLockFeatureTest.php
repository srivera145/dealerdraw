<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Board;
use Keel\App\Services\BoardLockService;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * Self-check: are row/col digits absent from every JSON payload before lock?
 */
class SquaresBoardLockFeatureTest extends TestCase
{
    use SquaresFixtures;

    public function testDigitsAreAbsentFromPublicPayloadsUntilTheBoardLocks(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id'], ['public_slug' => 'lock-test']);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);

        $beforeLock = $this->getJson('/p/lock-test/board');
        self::assertSame(200, $beforeLock->status);
        self::assertNull($beforeLock->json()['digits']);
        self::assertFalse($beforeLock->json()['board']['locked']);

        // Not just null in the payload - nothing is stored on the row either.
        $stored = Board::find((int) $board['id']);
        self::assertNull($stored['row_digits']);
        self::assertNull($stored['col_digits']);

        $page = $this->get('/p/lock-test');
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('row_digits', $page->body);
        self::assertStringContainsString('Numbers are hidden until kickoff.', $page->body);
        // Axis headers are present but carry no digit yet.
        self::assertStringContainsString('data-col-digit="0"></th>', $page->body);
        self::assertStringContainsString('data-row-digit="0"></th>', $page->body);

        $digits = BoardLockService::lock((int) $board['id']);
        self::assertIsArray($digits);

        $afterLock = $this->getJson('/p/lock-test/board');
        self::assertSame(200, $afterLock->status);
        self::assertTrue($afterLock->json()['board']['locked']);
        self::assertSame($digits['row'], $afterLock->json()['digits']['row']);
        self::assertSame($digits['col'], $afterLock->json()['digits']['col']);
    }

    public function testLockAssignsEachDigitOnceAndIsNotRepeatable(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);

        $digits = BoardLockService::lock((int) $board['id']);

        self::assertIsArray($digits);
        // Sorting back to 0-9 proves every digit appears exactly once.
        self::assertSame(range(0, 9), $this->sorted($digits['row']));
        self::assertSame(range(0, 9), $this->sorted($digits['col']));

        $locked = Board::find((int) $board['id']);
        self::assertSame('locked', (string) $locked['status']);
        self::assertNotNull($locked['locked_at']);

        // A second lock must not reshuffle digits under claims that are already public.
        self::assertNull(BoardLockService::lock((int) $board['id']));

        $afterSecondAttempt = Board::find((int) $board['id']);
        self::assertSame($locked['row_digits'], $afterSecondAttempt['row_digits']);
        self::assertSame($locked['col_digits'], $afterSecondAttempt['col_digits']);
    }

    public function testAdminLockRouteDrawsDigitsAndRefusesASecondLock(): void
    {
        $this->enableMultiTenancy();

        $dealer = $this->createOrganization('Dealer A');
        $this->actingAs(['organization_id' => (int) $dealer['id'], 'role' => 'owner']);

        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);

        $first = $this->post('/admin/boards/' . (int) $board['id'] . '/lock', ['_csrf' => $this->csrfToken()]);
        self::assertSame(302, $first->status);
        self::assertStringContainsString('notice=', (string) $first->header('Location'));

        $second = $this->post('/admin/boards/' . (int) $board['id'] . '/lock', ['_csrf' => $this->csrfToken()]);
        self::assertSame(302, $second->status);
        self::assertStringContainsString('error=', (string) $second->header('Location'));

        $digits = Board::digits(Board::find((int) $board['id']));
        self::assertIsArray($digits);
        self::assertSame(range(0, 9), $this->sorted($digits['row']));
    }

    public function testKickoffSweepLocksOpenBoardsWhoseGameHasStarted(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);

        $startedGame = $this->createGame(['kickoff_at' => date('Y-m-d H:i:s', time() - 60)]);
        $futureGame = $this->createGame(['kickoff_at' => date('Y-m-d H:i:s', time() + 7200)]);

        $startedBoard = $this->createBoard((int) $campaign['id'], (int) $startedGame['id']);
        $futureBoard = $this->createBoard((int) $campaign['id'], (int) $futureGame['id']);

        self::assertSame(1, \Keel\App\Services\ScoreSyncService::lockBoardsAtKickoff());

        self::assertNotNull(Board::find((int) $startedBoard['id'])['locked_at']);
        self::assertNull(Board::find((int) $futureBoard['id'])['locked_at']);
    }

    /**
     * @param int[] $digits
     * @return int[]
     */
    private function sorted(array $digits): array
    {
        sort($digits);

        return $digits;
    }
}
