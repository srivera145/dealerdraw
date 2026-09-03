<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Models\Game;
use Keel\App\Services\Providers\EspnScoreProvider;
use Keel\App\Services\Providers\ScoreFeedException;
use Keel\App\Services\Providers\ScoreProvider;
use Keel\Core\Database;

/**
 * Keeps live games current from whatever ScoreProvider is configured.
 *
 * Three rules shape everything here:
 *
 *  - One request per league-day, not per game. Games are grouped before any
 *    HTTP happens, so a 40-game college Saturday costs a single call.
 *  - A period is written only once it has closed - the feed moved on to the
 *    next period, or the game ended. An in-progress quarter is never stored.
 *  - Manual games are invisible to this service. Game::pendingFeedSync() will
 *    not select them and Game::applyFeedScores() will not write them, so a
 *    stale in-flight job cannot undo an advisor's correction either.
 */
class ScoreSyncService
{
    /** Backoff after 1, 2, 3, 4+ consecutive failures. */
    private const BACKOFF_SECONDS = [60, 120, 300, 600];

    private static ?ScoreProvider $provider = null;

    /** Swaps the feed - a paid vendor, or a fake in tests. */
    public static function setProvider(?ScoreProvider $provider): void
    {
        self::$provider = $provider;
    }

    public static function provider(): ScoreProvider
    {
        return self::$provider ??= new EspnScoreProvider();
    }

    /**
     * @return array{locked: int, requests: int, checked: int, updated: int, periods_closed: int, resolved: int, failed: int}
     */
    public static function syncActiveGames(int $preKickoffMinutes = 15): array
    {
        $summary = [
            'locked' => self::lockBoardsAtKickoff(),
            'requests' => 0,
            'checked' => 0,
            'updated' => 0,
            'periods_closed' => 0,
            'resolved' => 0,
            'failed' => 0,
        ];

        $provider = self::provider();

        foreach (self::batches(Game::pendingFeedSync($preKickoffMinutes)) as $batch) {
            $summary['requests']++;
            $summary['checked'] += count($batch['games']);

            try {
                $scoreboard = $provider->scoreboardForDate($batch['league'], $batch['date']);
            } catch (ScoreFeedException $exception) {
                // The whole league-day failed, so every game in it takes the hit.
                self::failBatch($batch['games'], $exception->getMessage());
                $summary['failed'] += count($batch['games']);

                continue;
            }

            foreach ($batch['games'] as $game) {
                $feedGame = $scoreboard[(string) $game['external_id']] ?? null;

                if ($feedGame === null) {
                    // Usually a wrong external_id. Counted so it raises the alert
                    // instead of failing silently for the whole game window.
                    self::failBatch([$game], 'Game was not on the ' . $batch['league'] . ' scoreboard for that date.');
                    $summary['failed']++;

                    continue;
                }

                $result = self::applyGame($game, $feedGame);

                $summary['updated'] += $result['updated'] ? 1 : 0;
                $summary['periods_closed'] += count($result['closed']);
                $summary['resolved'] += $result['resolved'];
            }
        }

        return $summary;
    }

    /**
     * Groups the poll set into one entry per league and per calendar day, using
     * the feed's own timezone so a late kickoff lands on the scoreboard day the
     * vendor filed it under rather than the server's.
     *
     * @param array<int, array> $games
     * @return array<string, array{league: string, date: DateTimeImmutable, games: array<int, array>}>
     */
    public static function batches(array $games): array
    {
        $feedZone = new DateTimeZone(EspnScoreProvider::FEED_TIMEZONE);
        $localZone = new DateTimeZone(date_default_timezone_get());
        $batches = [];

        foreach ($games as $game) {
            $kickoff = new DateTimeImmutable((string) $game['kickoff_at'], $localZone);
            $feedDate = $kickoff->setTimezone($feedZone);
            $key = $game['league'] . '|' . $feedDate->format('Y-m-d');

            if (!isset($batches[$key])) {
                $batches[$key] = [
                    'league' => (string) $game['league'],
                    'date' => $feedDate,
                    'games' => [],
                ];
            }

            $batches[$key]['games'][] = $game;
        }

        return $batches;
    }

    /**
     * Converts a provider's per-period points into the cumulative end-of-period
     * scores the board pays out on, and reports which of those periods are
     * closed and therefore safe to store.
     *
     * Only a contiguous run from q1 is accumulated. If the feed skips a period,
     * every later cumulative total would be wrong, so they are dropped rather
     * than written low.
     *
     * @return array{scores: array<string, int>, closed: string[]}
     */
    public static function cumulativeScores(array $feedGame): array
    {
        $completed = (bool) ($feedGame['completed'] ?? false);
        $currentPeriod = max(0, (int) ($feedGame['current_period'] ?? 0));
        $points = is_array($feedGame['period_points'] ?? null) ? $feedGame['period_points'] : [];

        // A period closes when the feed has moved past it, or the game is over.
        $closedThrough = $completed ? 4 : min(4, max(0, $currentPeriod - 1));

        $scores = [];
        $closed = [];
        $runningHome = 0;
        $runningAway = 0;

        for ($period = 1; $period <= $closedThrough; $period++) {
            $key = 'q' . $period;
            $periodPoints = is_array($points[$key] ?? null) ? $points[$key] : null;

            if ($periodPoints === null
                || !isset($periodPoints['home'], $periodPoints['away'])
                || !is_numeric($periodPoints['home'])
                || !is_numeric($periodPoints['away'])) {
                // Gap in the run: stop rather than guess the periods after it.
                break;
            }

            $runningHome += (int) $periodPoints['home'];
            $runningAway += (int) $periodPoints['away'];

            $scores[$key . '_home_score'] = $runningHome;
            $scores[$key . '_away_score'] = $runningAway;
            $closed[] = $key;
        }

        // The final comes from the vendor's own total so overtime is included -
        // it is not the sum of four quarters.
        $total = is_array($feedGame['total'] ?? null) ? $feedGame['total'] : null;

        if ($completed
            && $total !== null
            && isset($total['home'], $total['away'])
            && is_numeric($total['home'])
            && is_numeric($total['away'])) {
            $scores['final_home_score'] = (int) $total['home'];
            $scores['final_away_score'] = (int) $total['away'];
            $closed[] = 'final';
        }

        return ['scores' => $scores, 'closed' => $closed];
    }

    /**
     * Locks every open board whose game has kicked off. Digits are assigned here
     * and nowhere else, which is why they cannot exist before kickoff.
     *
     * @return int Boards locked on this pass.
     */
    public static function lockBoardsAtKickoff(): int
    {
        $statement = Database::connection()->query(
            "SELECT b.id
             FROM boards b
             INNER JOIN games g ON g.id = b.game_id
             WHERE b.status = 'open' AND b.locked_at IS NULL AND g.kickoff_at <= NOW()"
        );

        $locked = 0;

        foreach ($statement->fetchAll() as $board) {
            if (BoardLockService::lock((int) $board['id']) !== null) {
                $locked++;
            }
        }

        return $locked;
    }

    /**
     * Runs winner resolution for every locked board attached to a game. Called
     * when a period closes and after a manual override; idempotent either way.
     *
     * @return array<int, array<string, array{status: string}>>
     */
    public static function resolveBoardsForGame(int $gameId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT id FROM boards WHERE game_id = ? AND status IN ('locked', 'scoring')"
        );
        $statement->execute([$gameId]);

        $results = [];

        foreach ($statement->fetchAll() as $board) {
            $results[(int) $board['id']] = WinnerService::resolveBoard((int) $board['id']);
        }

        return $results;
    }

    /**
     * @return array{updated: bool, closed: string[], resolved: int}
     */
    private static function applyGame(array $game, array $feedGame): array
    {
        $status = (string) ($feedGame['status'] ?? '');

        if (!in_array($status, Game::STATUSES, true)) {
            self::failBatch([$game], 'Feed reported an unrecognised status.');

            return ['updated' => false, 'closed' => [], 'resolved' => 0];
        }

        ['scores' => $scores, 'closed' => $closed] = self::cumulativeScores($feedGame);

        // Only periods that were not already stored count as newly closed.
        $newlyClosed = array_values(array_filter(
            $closed,
            static function (string $period) use ($game, $scores): bool {
                [$homeColumn, $awayColumn] = Game::PERIOD_COLUMNS[$period];

                return $game[$homeColumn] === null
                    || $game[$awayColumn] === null
                    || (int) $game[$homeColumn] !== $scores[$homeColumn]
                    || (int) $game[$awayColumn] !== $scores[$awayColumn];
            }
        ));

        if (!Game::applyFeedScores((int) $game['id'], $scores, $status)) {
            // A manual override landed between the poll set being read and now.
            return ['updated' => false, 'closed' => [], 'resolved' => 0];
        }

        $resolved = 0;

        if ($newlyClosed !== []) {
            $resolved = count(self::resolveBoardsForGame((int) $game['id']));
        }

        return ['updated' => true, 'closed' => $newlyClosed, 'resolved' => $resolved];
    }

    /**
     * @param array<int, array> $games
     */
    private static function failBatch(array $games, string $message): void
    {
        $ids = array_map(static fn (array $game): int => (int) $game['id'], $games);
        $failureCount = 0;

        foreach ($games as $game) {
            $failureCount = max($failureCount, (int) ($game['sync_failure_count'] ?? 0) + 1);
        }

        Game::recordSyncFailure($ids, $message, self::backoffSeconds($failureCount));

        error_log(sprintf(
            '[DealerDraw] Score sync failure (%d game(s), attempt %d): %s',
            count($ids),
            $failureCount,
            $message
        ));
    }

    private static function backoffSeconds(int $failureCount): int
    {
        $index = min(max(1, $failureCount), count(self::BACKOFF_SECONDS)) - 1;

        return self::BACKOFF_SECONDS[$index];
    }
}
