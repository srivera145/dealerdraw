<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Keel\App\Services\Providers\ScoreFeedException;
use Keel\App\Services\Providers\ScoreProvider;

/**
 * A provider that answers from canned data and counts its calls, so tests can
 * assert on request volume as well as on what got stored.
 */
class FakeScoreProvider implements ScoreProvider
{
    /** @var array<int, array{league: string, date: string}> */
    public array $scoreboardCalls = [];

    /** @var array<int, array{league: string, season: int, week: int, type: int}> */
    public array $scheduleCalls = [];

    /** @var array<string, array<string, array>> Keyed by "league|Y-m-d". */
    private array $scoreboards = [];

    /** @var array<int, array> */
    private array $schedule = [];

    private ?string $failWith = null;

    public function name(): string
    {
        return 'fake';
    }

    /**
     * @param array<string, array> $games Keyed by external_id.
     */
    public function withScoreboard(string $league, string $date, array $games): self
    {
        $this->scoreboards[$league . '|' . $date] = $games;

        return $this;
    }

    /**
     * @param array<int, array> $games
     */
    public function withSchedule(array $games): self
    {
        $this->schedule = $games;

        return $this;
    }

    public function failing(string $message = 'feed down'): self
    {
        $this->failWith = $message;

        return $this;
    }

    public function recover(): self
    {
        $this->failWith = null;

        return $this;
    }

    public function scoreboardCallCount(): int
    {
        return count($this->scoreboardCalls);
    }

    public function scoreboardForDate(string $league, DateTimeImmutable $date): array
    {
        $this->scoreboardCalls[] = ['league' => $league, 'date' => $date->format('Y-m-d')];

        if ($this->failWith !== null) {
            throw new ScoreFeedException($this->failWith);
        }

        return $this->scoreboards[$league . '|' . $date->format('Y-m-d')] ?? [];
    }

    public function scheduleForWeek(string $league, int $season, int $week, int $seasonType = 2): array
    {
        $this->scheduleCalls[] = ['league' => $league, 'season' => $season, 'week' => $week, 'type' => $seasonType];

        if ($this->failWith !== null) {
            throw new ScoreFeedException($this->failWith);
        }

        return $this->schedule;
    }

    /**
     * Builds one normalised game in the provider contract's shape.
     *
     * @param array<string, array{home: int, away: int}> $periodPoints Per-period points, not totals.
     */
    public static function game(
        string $externalId,
        array $periodPoints = [],
        int $currentPeriod = 0,
        bool $completed = false,
        ?array $total = null,
        array $overrides = []
    ): array {
        $status = $completed ? 'final' : ($currentPeriod > 0 ? 'in_progress' : 'scheduled');

        return array_merge([
            'external_id' => $externalId,
            'league' => 'nfl',
            'home_team' => 'Home Team',
            'away_team' => 'Away Team',
            'kickoff_at' => date('Y-m-d H:i:s'),
            'status' => $status,
            'current_period' => $currentPeriod,
            'completed' => $completed,
            'period_points' => $periodPoints,
            'total' => $total,
        ], $overrides);
    }
}
