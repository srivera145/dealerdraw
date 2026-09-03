<?php

namespace Keel\App\Models;

use Keel\Core\Database;
use PDO;

/**
 * Games hold league schedule and score data. They are shared across tenants;
 * a dealer never addresses a game directly, only through one of its own boards.
 */
class Game
{
    public const LEAGUES = ['nfl', 'ncaaf'];
    public const STATUSES = ['scheduled', 'in_progress', 'final'];

    /** Period => [home column, away column]. q4 is stored but never pays out; `final` does. */
    public const PERIOD_COLUMNS = [
        'q1' => ['q1_home_score', 'q1_away_score'],
        'q2' => ['q2_home_score', 'q2_away_score'],
        'q3' => ['q3_home_score', 'q3_away_score'],
        'q4' => ['q4_home_score', 'q4_away_score'],
        'final' => ['final_home_score', 'final_away_score'],
    ];

    public static function create(array $attributes): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO games (league, external_id, home_team, away_team, kickoff_at, status, scores_source)
             VALUES (:league, :external_id, :home_team, :away_team, :kickoff_at, :status, :scores_source)'
        );
        $statement->execute($attributes);

        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM games WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    public static function findByExternalId(string $league, string $externalId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM games WHERE league = ? AND external_id = ? LIMIT 1'
        );
        $statement->execute([$league, $externalId]);

        return $statement->fetch() ?: null;
    }

    public static function upcoming(int $limit = 100): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM games
             WHERE status <> :final_status OR kickoff_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY kickoff_at ASC, id ASC
             LIMIT :row_limit'
        );
        $statement->bindValue(':final_status', 'final');
        $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** A game window is considered over this long after kickoff even if the feed never said final. */
    public const MAX_GAME_WINDOW_HOURS = 8;

    /**
     * Games the sync job should ask the feed about: kickoff is close or passed,
     * the game is not final yet, and nobody has taken manual control of it. The
     * trailing window stops a feed that never posts a final from polling forever.
     */
    public static function pendingFeedSync(int $preKickoffMinutes = 15): array
    {
        $statement = Database::connection()->prepare(
            "SELECT * FROM games
             WHERE scores_source = 'feed'
               AND status <> 'final'
               AND kickoff_at <= DATE_ADD(NOW(), INTERVAL :pre_kickoff_minutes MINUTE)
               AND kickoff_at >= DATE_SUB(NOW(), INTERVAL " . self::MAX_GAME_WINDOW_HOURS . " HOUR)
             ORDER BY kickoff_at ASC, id ASC"
        );
        $statement->bindValue(':pre_kickoff_minutes', $preKickoffMinutes, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public static function hasActiveFeedGames(int $preKickoffMinutes = 15): bool
    {
        return self::pendingFeedSync($preKickoffMinutes) !== [];
    }

    /**
     * Feed writes are refused once an advisor has overridden the score by hand.
     * The WHERE clause carries that rule so a stale in-flight job cannot undo it.
     */
    public static function applyFeedScores(int $id, array $scores, string $status): bool
    {
        $assignments = [];
        $bindings = ['id' => $id, 'status' => $status];

        foreach (self::PERIOD_COLUMNS as [$homeColumn, $awayColumn]) {
            foreach ([$homeColumn, $awayColumn] as $column) {
                if (!array_key_exists($column, $scores)) {
                    continue;
                }

                $assignments[] = "{$column} = :{$column}";
                $bindings[$column] = $scores[$column] === null ? null : (int) $scores[$column];
            }
        }

        $assignments[] = 'status = :status';
        $assignments[] = 'last_synced_at = NOW()';

        $statement = Database::connection()->prepare(
            'UPDATE games SET ' . implode(', ', $assignments) . " WHERE id = :id AND scores_source = 'feed'"
        );
        $statement->execute($bindings);

        return $statement->rowCount() > 0;
    }

    /**
     * A manual override is permanent for that game: scores_source flips to manual
     * and every applyFeedScores() call afterwards matches zero rows.
     */
    public static function applyManualScores(int $id, array $scores, string $status): void
    {
        $assignments = [];
        $bindings = ['id' => $id, 'status' => $status];

        foreach (self::PERIOD_COLUMNS as [$homeColumn, $awayColumn]) {
            foreach ([$homeColumn, $awayColumn] as $column) {
                $assignments[] = "{$column} = :{$column}";
                $bindings[$column] = ($scores[$column] ?? null) === null || $scores[$column] === ''
                    ? null
                    : (int) $scores[$column];
            }
        }

        $assignments[] = 'status = :status';
        $assignments[] = "scores_source = 'manual'";
        $assignments[] = 'last_synced_at = NOW()';

        $statement = Database::connection()->prepare(
            'UPDATE games SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($bindings);
    }

    /**
     * @return array{0: int, 1: int}|null Home and away score for the period, or null if not posted yet.
     */
    public static function periodScore(array $game, string $period): ?array
    {
        if (!isset(self::PERIOD_COLUMNS[$period])) {
            return null;
        }

        [$homeColumn, $awayColumn] = self::PERIOD_COLUMNS[$period];

        if ($game[$homeColumn] === null || $game[$awayColumn] === null) {
            return null;
        }

        return [(int) $game[$homeColumn], (int) $game[$awayColumn]];
    }
}
