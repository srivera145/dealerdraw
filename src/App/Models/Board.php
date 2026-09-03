<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class Board
{
    public const STATUSES = ['open', 'locked', 'scoring', 'complete'];
    public const DEFAULT_CLAIM_LIMIT = 5;

    public static function create(array $attributes): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO boards (campaign_id, game_id, status, claim_limit)
             VALUES (:campaign_id, :game_id, :status, :claim_limit)'
        );
        $statement->execute($attributes);

        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM boards WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    /**
     * The only lookup admin controllers may use: the join to campaigns is what
     * keeps one dealer out of another dealer's board.
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT b.*, c.tenant_id, c.name AS campaign_name, c.public_slug,
                    g.league, g.home_team, g.away_team, g.kickoff_at, g.status AS game_status,
                    g.scores_source, g.last_synced_at,
                    g.q1_home_score, g.q1_away_score, g.q2_home_score, g.q2_away_score,
                    g.q3_home_score, g.q3_away_score, g.q4_home_score, g.q4_away_score,
                    g.final_home_score, g.final_away_score
             FROM boards b
             INNER JOIN campaigns c ON c.id = b.campaign_id
             INNER JOIN games g ON g.id = b.game_id
             WHERE b.id = ? AND c.tenant_id = ?
             LIMIT 1'
        );
        $statement->execute([$id, $tenantId]);

        return $statement->fetch() ?: null;
    }

    public static function forCampaign(int $campaignId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT b.*, g.league, g.home_team, g.away_team, g.kickoff_at, g.status AS game_status,
                    (SELECT COUNT(*) FROM squares s WHERE s.board_id = b.id AND s.claim_id IS NOT NULL) AS claimed_count
             FROM boards b
             INNER JOIN games g ON g.id = b.game_id
             WHERE b.campaign_id = ?
             ORDER BY g.kickoff_at ASC, b.id ASC'
        );
        $statement->execute([$campaignId]);

        return $statement->fetchAll();
    }

    /**
     * The board a public claim page shows: the soonest board that is still open,
     * otherwise the most recent board on the campaign.
     */
    public static function publicBoardForCampaign(int $campaignId, ?int $boardId = null): ?array
    {
        $sql = 'SELECT b.*, g.league, g.home_team, g.away_team, g.kickoff_at, g.status AS game_status,
                       g.q1_home_score, g.q1_away_score, g.q2_home_score, g.q2_away_score,
                       g.q3_home_score, g.q3_away_score, g.q4_home_score, g.q4_away_score,
                       g.final_home_score, g.final_away_score
                FROM boards b
                INNER JOIN games g ON g.id = b.game_id
                WHERE b.campaign_id = :campaign_id';

        $bindings = ['campaign_id' => $campaignId];

        if ($boardId !== null) {
            $sql .= ' AND b.id = :board_id';
            $bindings['board_id'] = $boardId;
        }

        $sql .= " ORDER BY (b.status = 'open') DESC, g.kickoff_at ASC, b.id ASC LIMIT 1";

        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetch() ?: null;
    }

    public static function updateSettings(int $id, int $claimLimit, string $status): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE boards SET claim_limit = :claim_limit, status = :status WHERE id = :id'
        );
        $statement->execute(['claim_limit' => $claimLimit, 'status' => $status, 'id' => $id]);
    }

    public static function updateStatus(int $id, string $status): void
    {
        $statement = Database::connection()->prepare('UPDATE boards SET status = ? WHERE id = ?');
        $statement->execute([$status, $id]);
    }

    /**
     * Only an open board locks, and only once - the WHERE clause makes a double
     * lock a no-op instead of a reshuffle.
     */
    public static function applyLock(int $id, array $rowDigits, array $colDigits): bool
    {
        $statement = Database::connection()->prepare(
            "UPDATE boards
             SET row_digits = :row_digits, col_digits = :col_digits, status = 'locked', locked_at = NOW()
             WHERE id = :id AND status = 'open' AND locked_at IS NULL"
        );
        $statement->execute([
            'row_digits' => json_encode(array_values($rowDigits)),
            'col_digits' => json_encode(array_values($colDigits)),
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    public static function isLocked(array $board): bool
    {
        return !empty($board['locked_at']);
    }

    /**
     * @return array{row: int[], col: int[]}|null Null until the board is locked.
     */
    public static function digits(array $board): ?array
    {
        if (!self::isLocked($board)) {
            return null;
        }

        $rowDigits = json_decode((string) ($board['row_digits'] ?? ''), true);
        $colDigits = json_decode((string) ($board['col_digits'] ?? ''), true);

        if (!is_array($rowDigits) || !is_array($colDigits)) {
            return null;
        }

        return [
            'row' => array_map('intval', array_values($rowDigits)),
            'col' => array_map('intval', array_values($colDigits)),
        ];
    }
}
