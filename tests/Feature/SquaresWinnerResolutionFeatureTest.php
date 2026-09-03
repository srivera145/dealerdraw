<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Jobs\NotifyWinnerJob;
use Keel\App\Models\Board;
use Keel\App\Models\Game;
use Keel\App\Models\Win;
use Keel\App\Services\ScoreSyncService;
use Keel\App\Services\WinnerService;
use Keel\Core\Database;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * Self-checks: does 24-17 resolve to row digit 4 / col digit 7 with the same
 * orientation the grid is drawn in, does re-running create zero new rows, and
 * does a manual override survive the next feed sync?
 */
class SquaresWinnerResolutionFeatureTest extends TestCase
{
    use SquaresFixtures;

    protected function tearDown(): void
    {
        ScoreSyncService::setFetcher(null);

        parent::tearDown();
    }

    public function testFinalOf24To17ResolvesToRowDigitFourAndColumnDigitSeven(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id'], ['public_slug' => 'final-test']);
        $game = $this->createGame(['home_team' => 'Home Team', 'away_team' => 'Away Team']);
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        // Row digit 4 sits at row index 2; column digit 7 sits at column index 5.
        $rowDigits = [9, 0, 4, 1, 2, 3, 5, 6, 7, 8];
        $colDigits = [1, 2, 3, 0, 9, 7, 4, 5, 6, 8];
        $this->lockBoardWithDigits($boardId, $rowDigits, $colDigits);

        $winningClaimId = $this->claimSquare($boardId, 2, 5, ['first_name' => 'Dana', 'last_name' => 'Lopez']);
        $decoyClaimId = $this->claimSquare($boardId, 5, 2, ['first_name' => 'Sam', 'last_name' => 'Ortiz']);

        $this->createPrize($boardId, 'final', ['label' => 'Free Full Detail']);

        // Home 24, away 17.
        $this->setGameScores((int) $game['id'], [
            'final_home_score' => 24,
            'final_away_score' => 17,
        ], 'final');

        Board::updateStatus($boardId, 'locked');

        $results = WinnerService::resolveBoard($boardId);

        self::assertSame('awarded', $results['final']['status']);

        $win = Win::findForBoardPeriod($boardId, 'final');
        self::assertIsArray($win);
        self::assertSame($winningClaimId, (int) $win['claim_id']);
        self::assertNotSame($decoyClaimId, (int) $win['claim_id']);
        self::assertSame(8, strlen((string) $win['redemption_code']));

        // The grid the customer sees puts the same claim at the same intersection.
        $payload = $this->getJson('/p/final-test/board')->json();
        self::assertSame(4, $payload['digits']['row'][2]);
        self::assertSame(7, $payload['digits']['col'][5]);
        self::assertSame(2, $payload['winners']['final']['row']);
        self::assertSame(5, $payload['winners']['final']['col']);
        self::assertSame('Dana L.', $payload['winners']['final']['name']);

        // Winning fires exactly one notification job.
        $jobs = Database::connection()->query("SELECT payload FROM jobs WHERE job_class = " . Database::connection()->quote(NotifyWinnerJob::class))->fetchAll();
        self::assertCount(1, $jobs);
        self::assertSame((int) $win['id'], (int) json_decode((string) $jobs[0]['payload'], true)['win_id']);

        self::assertSame('complete', (string) Board::find($boardId)['status']);
    }

    public function testRerunningScoringOnACompletedBoardCreatesNoNewRows(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');

        foreach (['q1', 'q2', 'q3', 'final'] as $period) {
            $this->createPrize($boardId, $period, ['label' => 'Prize ' . $period]);
        }

        // Every quarter lands on a claimed square with digits in natural order.
        $this->claimSquare($boardId, 7, 3, ['first_name' => 'Q1']);
        $this->claimSquare($boardId, 4, 7, ['first_name' => 'Final']);

        $this->setGameScores((int) $game['id'], [
            'q1_home_score' => 7,
            'q1_away_score' => 3,
            'q2_home_score' => 14,
            'q2_away_score' => 10,
            'q3_home_score' => 17,
            'q3_away_score' => 10,
            'q4_home_score' => 24,
            'q4_away_score' => 17,
            'final_home_score' => 24,
            'final_away_score' => 17,
        ], 'final');

        $firstPass = WinnerService::resolveBoard($boardId);
        self::assertSame('awarded', $firstPass['q1']['status']);
        self::assertSame('awarded', $firstPass['final']['status']);

        $winsAfterFirstPass = $this->countRows('wins', 'board_id = ?', [$boardId]);
        $codes = Database::connection()->query('SELECT redemption_code FROM wins ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $jobsAfterFirstPass = $this->countRows('jobs');

        for ($run = 0; $run < 3; $run++) {
            $rerun = WinnerService::resolveBoard($boardId);

            self::assertSame('already_awarded', $rerun['q1']['status']);
            self::assertSame('already_awarded', $rerun['final']['status']);
        }

        self::assertSame($winsAfterFirstPass, $this->countRows('wins', 'board_id = ?', [$boardId]));
        self::assertSame($codes, Database::connection()->query('SELECT redemption_code FROM wins ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame($jobsAfterFirstPass, $this->countRows('jobs'));
    }

    public function testUnclaimedWinningSquareAwardsNothingAndStaysReRunnable(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');
        $this->createPrize($boardId, 'final');

        $this->setGameScores((int) $game['id'], [
            'final_home_score' => 24,
            'final_away_score' => 17,
        ], 'final');

        $results = WinnerService::resolveBoard($boardId);

        self::assertSame('unclaimed', $results['final']['status']);
        self::assertSame(0, $this->countRows('wins', 'board_id = ?', [$boardId]));
    }

    public function testFinalDoesNotPayOutWhileTheGameIsStillInProgress(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');
        $this->createPrize($boardId, 'final');
        $this->claimSquare($boardId, 4, 7);

        $this->setGameScores((int) $game['id'], [
            'final_home_score' => 24,
            'final_away_score' => 17,
        ], 'in_progress');

        $results = WinnerService::resolveBoard($boardId);

        self::assertSame('period_not_final', $results['final']['status']);
        self::assertSame(0, $this->countRows('wins', 'board_id = ?', [$boardId]));
        self::assertSame('scoring', (string) Board::find($boardId)['status']);
    }

    public function testManualOverrideSurvivesTheNextFeedSync(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame([
            'external_id' => 'nfl-2026-w1-001',
            'kickoff_at' => date('Y-m-d H:i:s', time() - 1800),
            'status' => 'in_progress',
        ]);
        $this->createBoard((int) $campaign['id'], (int) $game['id']);

        // The feed says 21-14 and would keep saying so.
        ScoreSyncService::setFetcher(static fn (array $row): array => [
            'status' => 'in_progress',
            'periods' => [
                'q1' => ['home' => 7, 'away' => 0],
                'q2' => ['home' => 21, 'away' => 14],
            ],
        ]);

        ScoreSyncService::syncActiveGames();

        $afterFeed = Game::find((int) $game['id']);
        self::assertSame(21, (int) $afterFeed['q2_home_score']);
        self::assertSame('feed', (string) $afterFeed['scores_source']);

        // An advisor corrects the halftime score by hand.
        Game::applyManualScores((int) $game['id'], [
            'q1_home_score' => 7,
            'q1_away_score' => 0,
            'q2_home_score' => 20,
            'q2_away_score' => 13,
            'q3_home_score' => null,
            'q3_away_score' => null,
            'q4_home_score' => null,
            'q4_away_score' => null,
            'final_home_score' => null,
            'final_away_score' => null,
        ], 'in_progress');

        $summary = ScoreSyncService::syncActiveGames();

        $afterOverride = Game::find((int) $game['id']);
        self::assertSame('manual', (string) $afterOverride['scores_source']);
        self::assertSame(20, (int) $afterOverride['q2_home_score']);
        self::assertSame(13, (int) $afterOverride['q2_away_score']);
        self::assertSame(0, $summary['checked'], 'a manual game is never handed to the feed again');

        // Even a direct feed write against that game id is refused.
        self::assertFalse(Game::applyFeedScores((int) $game['id'], ['q2_home_score' => 21, 'q2_away_score' => 14], 'in_progress'));
        self::assertSame(20, (int) Game::find((int) $game['id'])['q2_home_score']);
    }

    public function testSyncJobPollsOnACadenceWhileAGameWindowIsOpenThenStops(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame([
            'external_id' => 'nfl-cadence-1',
            'kickoff_at' => date('Y-m-d H:i:s', time() - 600),
            'status' => 'in_progress',
        ]);
        $this->createBoard((int) $campaign['id'], (int) $game['id']);

        ScoreSyncService::setFetcher(static fn (array $row): array => [
            'status' => 'in_progress',
            'periods' => ['q1' => ['home' => 7, 'away' => 3]],
        ]);

        // Cron queues the first run; a second cron tick while one is pending adds nothing.
        self::assertTrue(\Keel\App\Jobs\SyncScoresJob::ensureScheduled());
        self::assertFalse(\Keel\App\Jobs\SyncScoresJob::ensureScheduled());
        self::assertSame(1, $this->countRows('jobs', 'job_class = ?', [\Keel\App\Jobs\SyncScoresJob::class]));

        Database::connection()->exec('DELETE FROM jobs');

        // While the game is live the job re-queues itself one cadence later.
        (new \Keel\App\Jobs\SyncScoresJob())->handle([]);

        $queued = Database::connection()->query(
            'SELECT available_at, created_at FROM jobs ORDER BY id DESC LIMIT 1'
        )->fetch();

        self::assertIsArray($queued);
        self::assertGreaterThanOrEqual(
            55,
            strtotime((string) $queued['available_at']) - strtotime((string) $queued['created_at'])
        );
        self::assertSame(7, (int) Game::find((int) $game['id'])['q1_home_score']);

        Database::connection()->exec('DELETE FROM jobs');

        // Once the game is final the chain ends instead of polling a dead feed.
        ScoreSyncService::setFetcher(static fn (array $row): array => [
            'status' => 'final',
            'periods' => ['q1' => ['home' => 7, 'away' => 3]],
            'final' => ['home' => 24, 'away' => 17],
        ]);

        (new \Keel\App\Jobs\SyncScoresJob())->handle([]);

        self::assertSame('final', (string) Game::find((int) $game['id'])['status']);
        self::assertSame(0, $this->countRows('jobs', 'job_class = ?', [\Keel\App\Jobs\SyncScoresJob::class]));
    }

    public function testManualOverrideThroughAdminResolvesWinnersImmediately(): void
    {
        $this->enableMultiTenancy();

        $dealer = $this->createOrganization('Dealer A');
        $this->actingAs(['organization_id' => (int) $dealer['id'], 'role' => 'owner']);

        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame(['kickoff_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');
        $this->createPrize($boardId, 'final', ['label' => 'Free Oil Change']);
        $claimId = $this->claimSquare($boardId, 4, 7, ['first_name' => 'Robin', 'last_name' => 'Vega']);

        $response = $this->post('/admin/boards/' . $boardId . '/scores', [
            '_csrf' => $this->csrfToken(),
            'final_home_score' => 24,
            'final_away_score' => 17,
            'game_status' => 'final',
        ]);

        self::assertSame(302, $response->status);

        $win = Win::findForBoardPeriod($boardId, 'final');
        self::assertIsArray($win);
        self::assertSame($claimId, (int) $win['claim_id']);
        self::assertSame('manual', (string) Game::find((int) $game['id'])['scores_source']);
    }
}
