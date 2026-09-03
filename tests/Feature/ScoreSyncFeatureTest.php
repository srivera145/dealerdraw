<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Board;
use Keel\App\Models\Game;
use Keel\App\Models\Win;
use Keel\App\Services\ScoreSyncService;
use Tests\Support\FakeScoreProvider;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * Score sync: request batching, period-close semantics, feed failure handling,
 * and the manual-override guarantee.
 */
class ScoreSyncFeatureTest extends TestCase
{
    use SquaresFixtures;

    private FakeScoreProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeScoreProvider();
        ScoreSyncService::setProvider($this->provider);
    }

    protected function tearDown(): void
    {
        ScoreSyncService::setProvider(null);

        parent::tearDown();
    }

    public function testFortyGameSaturdayCostsOneRequestNotForty(): void
    {
        $kickoff = $this->kickoffMinutesAgo(30);
        $scoreboard = [];
        $tracked = [];

        for ($index = 0; $index < 40; $index++) {
            $externalId = 'ncaaf-' . $index;
            $tracked[] = $this->createGame([
                'league' => 'ncaaf',
                'external_id' => $externalId,
                'kickoff_at' => $kickoff,
                'status' => 'in_progress',
            ]);

            $scoreboard[$externalId] = FakeScoreProvider::game(
                $externalId,
                ['q1' => ['home' => 7, 'away' => 3]],
                2
            );
        }

        $this->provider->withScoreboard('ncaaf', $this->feedDate($kickoff), $scoreboard);

        $summary = ScoreSyncService::syncActiveGames();

        self::assertSame(40, $summary['checked']);
        self::assertSame(1, $summary['requests'], 'one league-day is one request');
        self::assertSame(1, $this->provider->scoreboardCallCount());
        self::assertSame(40, $summary['updated']);

        // Every tracked game actually got the score from that single response.
        foreach ($tracked as $game) {
            $stored = Game::find((int) $game['id']);
            self::assertSame(7, (int) $stored['q1_home_score']);
            self::assertSame(3, (int) $stored['q1_away_score']);
        }
    }

    public function testSeparateLeaguesAndDatesEachCostOneRequest(): void
    {
        $saturday = $this->kickoffMinutesAgo(30);
        $sunday = date('Y-m-d H:i:s', strtotime($saturday) + 86400);

        $this->createGame(['league' => 'ncaaf', 'external_id' => 'c1', 'kickoff_at' => $saturday, 'status' => 'in_progress']);
        $this->createGame(['league' => 'ncaaf', 'external_id' => 'c2', 'kickoff_at' => $saturday, 'status' => 'in_progress']);
        $this->createGame(['league' => 'nfl', 'external_id' => 'n1', 'kickoff_at' => $saturday, 'status' => 'in_progress']);

        $summary = ScoreSyncService::syncActiveGames();

        // Two leagues on one date: two requests for three games.
        self::assertSame(3, $summary['checked']);
        self::assertSame(2, $summary['requests']);

        self::assertNotSame($sunday, $saturday);
    }

    public function testNumericVendorIdsStillMapToTheirGame(): void
    {
        // ESPN ids are numeric strings, so PHP turns them into int array keys
        // on the scoreboard. The lookup has to survive that.
        $kickoff = $this->kickoffMinutesAgo(30);
        $game = $this->createGame(['external_id' => '401671001', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);

        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), [
            '401671001' => FakeScoreProvider::game('401671001', ['q1' => ['home' => 7, 'away' => 3]], 2),
        ]);

        $summary = ScoreSyncService::syncActiveGames();

        self::assertSame(0, $summary['failed']);
        self::assertSame(1, $summary['updated']);
        self::assertSame(7, (int) Game::find((int) $game['id'])['q1_home_score']);
    }

    public function testFinalGamesAreNeverPolled(): void
    {
        $kickoff = $this->kickoffMinutesAgo(200);

        $this->createGame(['external_id' => 'done', 'kickoff_at' => $kickoff, 'status' => 'final']);

        $summary = ScoreSyncService::syncActiveGames();

        self::assertSame(0, $summary['checked']);
        self::assertSame(0, $summary['requests']);
        self::assertSame(0, $this->provider->scoreboardCallCount());
    }

    public function testScheduledGameIsOnlyPolledInsideThePreKickoffWindow(): void
    {
        $this->createGame([
            'external_id' => 'later',
            'kickoff_at' => date('Y-m-d H:i:s', time() + 3600),
            'status' => 'scheduled',
        ]);

        self::assertSame(0, ScoreSyncService::syncActiveGames()['checked']);

        $this->createGame([
            'external_id' => 'soon',
            'kickoff_at' => date('Y-m-d H:i:s', time() + 300),
            'status' => 'scheduled',
        ]);

        self::assertSame(1, ScoreSyncService::syncActiveGames()['checked']);
    }

    public function testOnlyClosedPeriodsAreStored(): void
    {
        $kickoff = $this->kickoffMinutesAgo(40);
        $game = $this->createGame(['external_id' => 'live', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);

        // Second quarter under way: Q1 has closed, Q2 has not.
        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), [
            'live' => FakeScoreProvider::game('live', [
                'q1' => ['home' => 7, 'away' => 3],
                'q2' => ['home' => 3, 'away' => 0],
            ], 2),
        ]);

        ScoreSyncService::syncActiveGames();

        $stored = Game::find((int) $game['id']);
        self::assertSame(7, (int) $stored['q1_home_score']);
        self::assertSame(3, (int) $stored['q1_away_score']);
        self::assertNull($stored['q2_home_score'], 'an in-progress quarter is not a closed period');
        self::assertNull($stored['final_home_score']);
        self::assertSame('in_progress', (string) $stored['status']);
    }

    public function testPeriodScoresAreStoredCumulativelyAndTheFinalIncludesOvertime(): void
    {
        $kickoff = $this->kickoffMinutesAgo(200);
        $game = $this->createGame(['external_id' => 'ot', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);

        // Per-quarter points 7/3, 7/7, 0/7, 7/4, then a 3-point overtime for home.
        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), [
            'ot' => FakeScoreProvider::game('ot', [
                'q1' => ['home' => 7, 'away' => 3],
                'q2' => ['home' => 7, 'away' => 7],
                'q3' => ['home' => 0, 'away' => 7],
                'q4' => ['home' => 7, 'away' => 4],
            ], 5, true, ['home' => 24, 'away' => 21]),
        ]);

        ScoreSyncService::syncActiveGames();

        $stored = Game::find((int) $game['id']);

        // Running totals, which is what the board pays out on.
        self::assertSame([7, 3], [(int) $stored['q1_home_score'], (int) $stored['q1_away_score']]);
        self::assertSame([14, 10], [(int) $stored['q2_home_score'], (int) $stored['q2_away_score']]);
        self::assertSame([14, 17], [(int) $stored['q3_home_score'], (int) $stored['q3_away_score']]);
        self::assertSame([21, 21], [(int) $stored['q4_home_score'], (int) $stored['q4_away_score']]);

        // The final is the vendor total, not the sum of four quarters.
        self::assertSame([24, 21], [(int) $stored['final_home_score'], (int) $stored['final_away_score']]);
        self::assertSame('final', (string) $stored['status']);
    }

    public function testWinnerServiceReadsTheSameCumulativeNumbersThatSyncWrote(): void
    {
        $kickoff = $this->kickoffMinutesAgo(200);
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame(['external_id' => 'pay', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');
        $this->createPrize($boardId, 'q1', ['label' => 'Free Oil Change']);
        $this->createPrize($boardId, 'final', ['label' => 'Free Full Detail']);

        // Cumulative q1 is 7-3, so the q1 winner sits at row 7 / column 3.
        $q1ClaimId = $this->claimSquare($boardId, 7, 3, ['first_name' => 'Q1']);
        // Cumulative final is 24-17, so the final winner sits at row 4 / column 7.
        $finalClaimId = $this->claimSquare($boardId, 4, 7, ['first_name' => 'Final']);

        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), [
            'pay' => FakeScoreProvider::game('pay', [
                'q1' => ['home' => 7, 'away' => 3],
                'q2' => ['home' => 7, 'away' => 7],
                'q3' => ['home' => 3, 'away' => 0],
                'q4' => ['home' => 7, 'away' => 7],
            ], 4, true, ['home' => 24, 'away' => 17]),
        ]);

        ScoreSyncService::syncActiveGames();

        self::assertSame($q1ClaimId, (int) Win::findForBoardPeriod($boardId, 'q1')['claim_id']);
        self::assertSame($finalClaimId, (int) Win::findForBoardPeriod($boardId, 'final')['claim_id']);
    }

    public function testWinnerResolutionRunsOnlyWhenAPeriodActuallyCloses(): void
    {
        $kickoff = $this->kickoffMinutesAgo(40);
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame(['external_id' => 'close', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');
        $this->createPrize($boardId, 'q1');
        $this->claimSquare($boardId, 7, 3);

        $payload = ['close' => FakeScoreProvider::game('close', ['q1' => ['home' => 7, 'away' => 3]], 2)];
        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), $payload);

        $first = ScoreSyncService::syncActiveGames();
        self::assertSame(1, $first['periods_closed']);
        self::assertSame(1, $first['resolved']);
        self::assertNotNull(Win::findForBoardPeriod($boardId, 'q1'));

        // Same payload again: nothing new closed, so no resolution pass runs.
        $second = ScoreSyncService::syncActiveGames();
        self::assertSame(0, $second['periods_closed']);
        self::assertSame(0, $second['resolved']);
        self::assertSame(1, $this->countRows('wins', 'board_id = ?', [$boardId]));
    }

    public function testManualOverrideIsSkippedEntirelyAndSurvivesTheNextCycle(): void
    {
        $kickoff = $this->kickoffMinutesAgo(40);
        $game = $this->createGame(['external_id' => 'manual', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);

        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), [
            'manual' => FakeScoreProvider::game('manual', [
                'q1' => ['home' => 7, 'away' => 3],
                'q2' => ['home' => 14, 'away' => 11],
            ], 3),
        ]);

        ScoreSyncService::syncActiveGames();
        self::assertSame(21, (int) Game::find((int) $game['id'])['q2_home_score']);

        // An advisor corrects halftime by hand.
        Game::applyManualScores((int) $game['id'], [
            'q1_home_score' => 7,
            'q1_away_score' => 3,
            'q2_home_score' => 20,
            'q2_away_score' => 13,
            'q3_home_score' => null,
            'q3_away_score' => null,
            'q4_home_score' => null,
            'q4_away_score' => null,
            'final_home_score' => null,
            'final_away_score' => null,
        ], 'in_progress');

        $callsBefore = $this->provider->scoreboardCallCount();
        $summary = ScoreSyncService::syncActiveGames();

        self::assertSame(0, $summary['checked'], 'a manual game is not even in the poll set');
        self::assertSame($callsBefore, $this->provider->scoreboardCallCount(), 'and costs no request');

        $stored = Game::find((int) $game['id']);
        self::assertSame('manual', (string) $stored['scores_source']);
        self::assertSame(20, (int) $stored['q2_home_score']);
        self::assertSame(13, (int) $stored['q2_away_score']);

        // Even a direct write against that game id is refused.
        self::assertFalse(Game::applyFeedScores((int) $game['id'], ['q2_home_score' => 21], 'in_progress'));
        self::assertSame(20, (int) Game::find((int) $game['id'])['q2_home_score']);
    }

    public function testMalformedAndPartialPayloadsWriteNothing(): void
    {
        $kickoff = $this->kickoffMinutesAgo(60);
        $game = $this->createGame(['external_id' => 'partial', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);

        // Fourth quarter under way, but the feed lost Q2. Q3 and Q4 cumulative
        // totals would be wrong without it, so only Q1 is trusted.
        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), [
            'partial' => FakeScoreProvider::game('partial', [
                'q1' => ['home' => 7, 'away' => 3],
                'q3' => ['home' => 7, 'away' => 0],
            ], 4),
        ]);

        ScoreSyncService::syncActiveGames();

        $stored = Game::find((int) $game['id']);
        self::assertSame(7, (int) $stored['q1_home_score']);
        self::assertNull($stored['q2_home_score']);
        self::assertNull($stored['q3_home_score'], 'a gap stops the run rather than writing a wrong total');
        self::assertNull($stored['q4_home_score']);
    }

    public function testNonNumericPeriodValuesAreTreatedAsUnknownNotZero(): void
    {
        $kickoff = $this->kickoffMinutesAgo(60);
        $game = $this->createGame(['external_id' => 'junk', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);

        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), [
            'junk' => FakeScoreProvider::game('junk', [
                'q1' => ['home' => '', 'away' => null],
                'q2' => ['home' => 10, 'away' => 7],
            ], 3),
        ]);

        ScoreSyncService::syncActiveGames();

        $stored = Game::find((int) $game['id']);
        self::assertNull($stored['q1_home_score']);
        self::assertNull($stored['q2_home_score']);
    }

    public function testAWholeDayFailureBacksOffAndRaisesTheAlertOnTheThirdStrike(): void
    {
        $kickoff = $this->kickoffMinutesAgo(30);
        $game = $this->createGame(['external_id' => 'down', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);
        $gameId = (int) $game['id'];

        $this->provider->failing('scoreboard timed out');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Clear the backoff gate so the test can drive three cycles quickly.
            $this->clearRetryGate($gameId);

            $summary = ScoreSyncService::syncActiveGames();
            self::assertSame(1, $summary['failed']);

            $stored = Game::find($gameId);
            self::assertSame($attempt, (int) $stored['sync_failure_count']);
            self::assertSame('scoreboard timed out', (string) $stored['last_sync_error']);
            self::assertNotNull($stored['sync_retry_after']);

            // Alert on the third consecutive failure, not before.
            self::assertSame($attempt >= 3 ? 1 : 0, (int) $stored['sync_alert']);
        }

        // Backoff keeps the game out of the poll set until it expires.
        self::assertSame(0, ScoreSyncService::syncActiveGames()['checked']);

        // A good read clears the streak and the alert.
        $this->clearRetryGate($gameId);
        $this->provider->recover()->withScoreboard('nfl', $this->feedDate($kickoff), [
            'down' => FakeScoreProvider::game('down', ['q1' => ['home' => 7, 'away' => 0]], 2),
        ]);

        ScoreSyncService::syncActiveGames();

        $recovered = Game::find($gameId);
        self::assertSame(0, (int) $recovered['sync_failure_count']);
        self::assertSame(0, (int) $recovered['sync_alert']);
        self::assertNull($recovered['sync_retry_after']);
        self::assertSame(7, (int) $recovered['q1_home_score']);
    }

    public function testAGameMissingFromTheScoreboardCountsAsAFailure(): void
    {
        $kickoff = $this->kickoffMinutesAgo(30);
        $game = $this->createGame(['external_id' => 'typo-id', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);

        // The day's scoreboard came back fine, but this id is not in it.
        $this->provider->withScoreboard('nfl', $this->feedDate($kickoff), [
            'some-other-game' => FakeScoreProvider::game('some-other-game', [], 1),
        ]);

        $summary = ScoreSyncService::syncActiveGames();

        self::assertSame(1, $summary['failed']);
        self::assertSame(0, $summary['updated']);

        $stored = Game::find((int) $game['id']);
        self::assertSame(1, (int) $stored['sync_failure_count']);
        self::assertStringContainsString('not on the nfl scoreboard', (string) $stored['last_sync_error']);
    }

    public function testTheJobKeepsPollingWhileAGameIsInBackoff(): void
    {
        $kickoff = $this->kickoffMinutesAgo(30);
        $game = $this->createGame(['external_id' => 'backoff', 'kickoff_at' => $kickoff, 'status' => 'in_progress']);

        $this->provider->failing();
        ScoreSyncService::syncActiveGames();

        self::assertNotNull(Game::find((int) $game['id'])['sync_retry_after']);
        // Nothing to poll right now, but the window is still open, so the chain
        // must stay alive to retry once the backoff expires.
        self::assertSame([], Game::pendingFeedSync());
        self::assertTrue(Game::hasActiveFeedGames());
    }

    private function clearRetryGate(int $gameId): void
    {
        \Keel\Core\Database::connection()
            ->prepare('UPDATE games SET sync_retry_after = NULL WHERE id = ?')
            ->execute([$gameId]);
    }

    private function kickoffMinutesAgo(int $minutes): string
    {
        return date('Y-m-d H:i:s', time() - ($minutes * 60));
    }

    /** The scoreboard day a kickoff belongs to, in the feed's timezone. */
    private function feedDate(string $kickoffAt): string
    {
        return (new \DateTimeImmutable($kickoffAt))
            ->setTimezone(new \DateTimeZone(\Keel\App\Services\Providers\EspnScoreProvider::FEED_TIMEZONE))
            ->format('Y-m-d');
    }
}
