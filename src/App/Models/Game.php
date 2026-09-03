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

    /** Consecutive failed reads before a game is flagged for an admin. */
    public const ALERT_AFTER_FAILURES = 3;

    /**
     * The poll set, exactly: games already in progress, plus scheduled games
     * whose kickoff is inside the pre-kickoff window. Final games are never
     * selected, and neither is anything on manual scoring.
     *
     * Two extra gates keep a broken feed from being polled pointlessly: a game
     * in failure backoff waits until sync_retry_after passes, and a game whose
     * feed never posts a final ages out of the window entirely.
     */
    public static function pendingFeedSync(int $preKickoffMinutes = 15): array
    {
        $statement = Database::connection()->prepare(
            "SELECT * FROM games
             WHERE scores_source = 'feed'
               AND (
                    status = 'in_progress'
                    OR (status = 'scheduled' AND kickoff_at <= DATE_ADD(NOW(), INTERVAL :pre_kickoff_minutes MINUTE))
               )
               AND kickoff_at >= DATE_SUB(NOW(), INTERVAL " . self::MAX_GAME_WINDOW_HOURS . " HOUR)
               AND (sync_retry_after IS NULL OR sync_retry_after <= NOW())
             ORDER BY kickoff_at ASC, id ASC"
        );
        $statement->bindValue(':pre_kickoff_minutes', $preKickoffMinutes, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Whether any game window is still open, so the poll chain knows to keep
     * going. Deliberately ignores sync_retry_after: a game in failure backoff is
     * still live, and the chain has to be running to retry it.
     */
    public static function hasActiveFeedGames(int $preKickoffMinutes = 15): bool
    {
        $statement = Database::connection()->prepare(
            "SELECT 1 FROM games
             WHERE scores_source = 'feed'
               AND (
                    status = 'in_progress'
                    OR (status = 'scheduled' AND kickoff_at <= DATE_ADD(NOW(), INTERVAL :pre_kickoff_minutes MINUTE))
               )
               AND kickoff_at >= DATE_SUB(NOW(), INTERVAL " . self::MAX_GAME_WINDOW_HOURS . " HOUR)
             LIMIT 1"
        );
        $statement->bindValue(':pre_kickoff_minutes', $preKickoffMinutes, PDO::PARAM_INT);
        $statement->execute();

        return (bool) $statement->fetchColumn();
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
        // A successful read clears the failure streak and the admin alert with it.
        $assignments[] = 'sync_failure_count = 0';
        $assignments[] = 'sync_alert = 0';
        $assignments[] = 'sync_retry_after = NULL';

        $statement = Database::connection()->prepare(
            'UPDATE games SET ' . implode(', ', $assignments) . " WHERE id = :id AND scores_source = 'feed'"
        );
        $statement->execute($bindings);

        if ($statement->rowCount() > 0) {
            return true;
        }

        // rowCount() is 0 both for "no such feed game" and for "matched but every
        // value was already identical", so confirm against the row itself.
        $check = Database::connection()->prepare('SELECT scores_source FROM games WHERE id = ? LIMIT 1');
        $check->execute([$id]);

        return $check->fetchColumn() === 'feed';
    }

    /**
     * Records a failed read against each game in a batch. The third consecutive
     * failure raises the admin alert, and every failure pushes the game out of
     * the poll set until the backoff expires.
     *
     * @param int[] $ids
     */
    public static function recordSyncFailure(array $ids, string $message, int $backoffSeconds): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $statement = Database::connection()->prepare(
            // MySQL applies SET assignments left to right, so the sync_alert
            // expression below reads the count AFTER the increment above it.
            // Comparing it directly is what makes the alert fire on the third
            // consecutive failure rather than the second.
            'UPDATE games
             SET sync_failure_count = sync_failure_count + 1,
                 sync_alert = IF(sync_failure_count >= ' . self::ALERT_AFTER_FAILURES . ', 1, sync_alert),
                 sync_retry_after = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 last_sync_error = ?,
                 last_sync_error_at = NOW()
             WHERE id IN (' . $placeholders . ") AND scores_source = 'feed'"
        );

        $statement->execute(array_merge([max(0, $backoffSeconds), substr($message, 0, 255)], $ids));
    }

    /** Games an admin should look at because the feed stopped answering for them. */
    public static function withSyncAlert(): array
    {
        $statement = Database::connection()->query(
            'SELECT * FROM games WHERE sync_alert = 1 ORDER BY kickoff_at DESC, id DESC'
        );

        return $statement->fetchAll();
    }

    /**
     * Schedule import. Matches on the (league, external_id) unique key and
     * touches only schedule fields - never scores, status, or scores_source, so
     * re-importing a week cannot undo a played game or a manual override.
     *
     * @return string 'inserted' or 'updated'
     */
    public static function upsertSchedule(array $game): string
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO games (league, external_id, home_team, away_team, kickoff_at, status, scores_source)
             VALUES (:league, :external_id, :home_team, :away_team, :kickoff_at, :status, :scores_source)
             ON DUPLICATE KEY UPDATE
                home_team = VALUES(home_team),
                away_team = VALUES(away_team),
                kickoff_at = VALUES(kickoff_at)'
        );
        $statement->execute([
            'league' => $game['league'],
            'external_id' => $game['external_id'],
            'home_team' => $game['home_team'],
            'away_team' => $game['away_team'],
            'kickoff_at' => $game['kickoff_at'],
            'status' => $game['status'] ?? 'scheduled',
            'scores_source' => 'feed',
        ]);

        // MySQL reports 1 affected row for an insert and 2 for an update that
        // changed something; an unchanged duplicate reports 0.
        return $statement->rowCount() === 1 ? 'inserted' : 'updated';
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
        // The feed is out of the picture now, so its health flags go with it.
        $assignments[] = 'sync_failure_count = 0';
        $assignments[] = 'sync_alert = 0';
        $assignments[] = 'sync_retry_after = NULL';

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
