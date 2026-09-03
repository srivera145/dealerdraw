<?php

namespace Keel\App\Console\Commands;

use Keel\App\Models\Game;
use Keel\App\Services\ScoreSyncService;
use Keel\App\Services\Providers\ScoreFeedException;

/**
 * Imports a week of schedule from the configured provider.
 *
 *   php scripts/sync-games.php --league=nfl --week=1
 *   php scripts/sync-games.php --league=ncaaf --week=5 --season=2026
 *
 * Upserts on (league, external_id) and touches only schedule fields, so running
 * it again after games have been played cannot overwrite scores or undo a
 * manual override.
 */
class SyncGamesCommand
{
    /** ESPN season types: 1 preseason, 2 regular, 3 postseason. */
    private const SEASON_TYPES = [1, 2, 3];

    /** @var callable(string): void */
    private $output;

    /** @var callable(string): void */
    private $errorOutput;

    public function __construct(?callable $output = null, ?callable $errorOutput = null)
    {
        $this->output = $output ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };

        $this->errorOutput = $errorOutput ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /**
     * @param array<int, string> $arguments Raw argv, script name included.
     * @return int Process exit code.
     */
    public function handle(array $arguments): int
    {
        $options = $this->parse($arguments);

        $league = strtolower(trim((string) ($options['league'] ?? '')));

        if (!in_array($league, Game::LEAGUES, true)) {
            return $this->fail('--league is required and must be one of: ' . implode(', ', Game::LEAGUES));
        }

        if (!isset($options['week']) || !is_numeric($options['week'])) {
            return $this->fail('--week is required and must be a number.');
        }

        $week = (int) $options['week'];

        if ($week < 1 || $week > 25) {
            return $this->fail('--week must be between 1 and 25.');
        }

        $season = isset($options['season']) && is_numeric($options['season'])
            ? (int) $options['season']
            : $this->defaultSeason();

        $seasonType = isset($options['type']) && is_numeric($options['type'])
            ? (int) $options['type']
            : 2;

        if (!in_array($seasonType, self::SEASON_TYPES, true)) {
            return $this->fail('--type must be 1 (preseason), 2 (regular) or 3 (postseason).');
        }

        try {
            $games = ScoreSyncService::provider()->scheduleForWeek($league, $season, $week, $seasonType);
        } catch (ScoreFeedException $exception) {
            return $this->fail('Schedule import failed: ' . $exception->getMessage());
        }

        if ($games === []) {
            ($this->output)("No games returned for {$league} season {$season} week {$week}.");

            return 0;
        }

        $inserted = 0;
        $updated = 0;

        foreach ($games as $game) {
            if (Game::upsertSchedule($game) === 'inserted') {
                $inserted++;
                continue;
            }

            $updated++;
        }

        ($this->output)(sprintf(
            'Imported %s season %d week %d: %d new, %d updated (%d total).',
            $league,
            $season,
            $week,
            $inserted,
            $updated,
            count($games)
        ));

        return 0;
    }

    /**
     * A football season is named for the year it starts, so anything before
     * about March belongs to the previous year's season.
     */
    private function defaultSeason(): int
    {
        $now = new \DateTimeImmutable('now');

        return (int) $now->format('n') < 3
            ? (int) $now->format('Y') - 1
            : (int) $now->format('Y');
    }

    /**
     * @param array<int, string> $arguments
     * @return array<string, string>
     */
    private function parse(array $arguments): array
    {
        $options = [];

        foreach (array_slice($arguments, 1) as $argument) {
            if (preg_match('/^--([a-z][a-z0-9_-]*)(?:=(.*))?$/i', (string) $argument, $matches) !== 1) {
                continue;
            }

            $options[strtolower($matches[1])] = $matches[2] ?? '1';
        }

        return $options;
    }

    private function fail(string $message): int
    {
        ($this->errorOutput)($message);
        ($this->errorOutput)('Usage: php scripts/sync-games.php --league=nfl|ncaaf --week=N [--season=YYYY] [--type=1|2|3]');

        return 1;
    }
}
