<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Console\Commands\SyncGamesCommand;
use Keel\App\Models\Game;
use Keel\App\Services\ScoreSyncService;
use Tests\Support\FakeScoreProvider;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

class SyncGamesCommandFeatureTest extends TestCase
{
    use SquaresFixtures;

    private FakeScoreProvider $provider;

    /** @var string[] */
    private array $stdout = [];

    /** @var string[] */
    private array $stderr = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stdout = [];
        $this->stderr = [];
        $this->provider = new FakeScoreProvider();
        ScoreSyncService::setProvider($this->provider);
    }

    protected function tearDown(): void
    {
        ScoreSyncService::setProvider(null);

        parent::tearDown();
    }

    public function testImportsAWeekAndUpsertsOnExternalId(): void
    {
        $this->provider->withSchedule([
            $this->scheduleGame('401700001', 'Chiefs', 'Ravens', '2026-09-06 20:20:00'),
            $this->scheduleGame('401700002', 'Cowboys', 'Eagles', '2026-09-07 16:25:00'),
        ]);

        self::assertSame(0, $this->runCommand(['--league=nfl', '--week=1', '--season=2026']));

        self::assertSame(['league' => 'nfl', 'season' => 2026, 'week' => 1, 'type' => 2], $this->provider->scheduleCalls[0]);
        self::assertSame(2, $this->countRows('games'));
        self::assertStringContainsString('2 new, 0 updated', implode("\n", $this->stdout));

        // Re-running with a corrected kickoff updates in place instead of duplicating.
        $this->provider->withSchedule([
            $this->scheduleGame('401700001', 'Kansas City Chiefs', 'Baltimore Ravens', '2026-09-06 20:15:00'),
            $this->scheduleGame('401700002', 'Cowboys', 'Eagles', '2026-09-07 16:25:00'),
        ]);

        self::assertSame(0, $this->runCommand(['--league=nfl', '--week=1', '--season=2026']));

        self::assertSame(2, $this->countRows('games'), 'upsert, not insert');

        $updated = Game::findByExternalId('nfl', '401700001');
        self::assertSame('Kansas City Chiefs', (string) $updated['home_team']);
        self::assertSame('2026-09-06 20:15:00', (string) $updated['kickoff_at']);
    }

    public function testReimportNeverOverwritesScoresOrAManualOverride(): void
    {
        $this->provider->withSchedule([$this->scheduleGame('401700003', 'Chiefs', 'Ravens', '2026-09-06 20:20:00')]);
        $this->runCommand(['--league=nfl', '--week=1', '--season=2026']);

        $game = Game::findByExternalId('nfl', '401700003');

        // The game is played and an advisor takes manual control of the score.
        Game::applyManualScores((int) $game['id'], [
            'q1_home_score' => 7,
            'q1_away_score' => 3,
            'q2_home_score' => 14,
            'q2_away_score' => 10,
            'q3_home_score' => null,
            'q3_away_score' => null,
            'q4_home_score' => null,
            'q4_away_score' => null,
            'final_home_score' => 24,
            'final_away_score' => 17,
        ], 'final');

        // Someone re-runs the week import afterwards.
        $this->runCommand(['--league=nfl', '--week=1', '--season=2026']);

        $after = Game::findByExternalId('nfl', '401700003');
        self::assertSame('manual', (string) $after['scores_source']);
        self::assertSame('final', (string) $after['status']);
        self::assertSame(24, (int) $after['final_home_score']);
        self::assertSame(17, (int) $after['final_away_score']);
    }

    public function testLeagueAndWeekAreValidatedBeforeAnyRequest(): void
    {
        self::assertSame(1, $this->runCommand(['--week=1']));
        self::assertSame(1, $this->runCommand(['--league=nba', '--week=1']));
        self::assertSame(1, $this->runCommand(['--league=nfl']));
        self::assertSame(1, $this->runCommand(['--league=nfl', '--week=99']));
        self::assertSame(1, $this->runCommand(['--league=nfl', '--week=1', '--type=7']));

        self::assertSame([], $this->provider->scheduleCalls);
        self::assertSame(0, $this->countRows('games'));
        self::assertStringContainsString('Usage: php scripts/sync-games.php', implode("\n", $this->stderr));
    }

    public function testFeedFailureExitsNonZeroWithoutWritingAnything(): void
    {
        $this->provider->failing('schedule endpoint unavailable');

        self::assertSame(1, $this->runCommand(['--league=ncaaf', '--week=5', '--season=2026']));
        self::assertSame(0, $this->countRows('games'));
        self::assertStringContainsString('schedule endpoint unavailable', implode("\n", $this->stderr));
    }

    public function testEmptyWeekIsReportedAsSuccessNotFailure(): void
    {
        $this->provider->withSchedule([]);

        self::assertSame(0, $this->runCommand(['--league=nfl', '--week=22', '--season=2026']));
        self::assertStringContainsString('No games returned', implode("\n", $this->stdout));
    }

    /**
     * @param string[] $arguments
     */
    private function runCommand(array $arguments): int
    {
        $command = new SyncGamesCommand(
            function (string $line): void {
                $this->stdout[] = $line;
            },
            function (string $line): void {
                $this->stderr[] = $line;
            }
        );

        return $command->handle(array_merge(['scripts/sync-games.php'], $arguments));
    }

    private function scheduleGame(string $externalId, string $home, string $away, string $kickoff): array
    {
        return [
            'external_id' => $externalId,
            'league' => 'nfl',
            'home_team' => $home,
            'away_team' => $away,
            'kickoff_at' => $kickoff,
            'status' => 'scheduled',
            'current_period' => 0,
            'completed' => false,
            'period_points' => [],
            'total' => null,
        ];
    }
}
