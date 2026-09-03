<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class Prize
{
    /** q4 is tracked as a score but the game pays out on the final, not the fourth quarter. */
    public const SCORING_PERIODS = ['q1', 'q2', 'q3', 'final'];

    public const PERIOD_LABELS = [
        'q1' => 'End of 1st Quarter',
        'q2' => 'Halftime',
        'q3' => 'End of 3rd Quarter',
        'final' => 'Final Score',
    ];

    /**
     * One prize per period per board, so saving is an upsert on uniq_prizes_board_period.
     */
    public static function save(int $boardId, string $scoringPeriod, array $attributes): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO prizes (board_id, scoring_period, label, retail_value, terms_text, expires_days)
             VALUES (:board_id, :scoring_period, :label, :retail_value, :terms_text, :expires_days)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                retail_value = VALUES(retail_value),
                terms_text = VALUES(terms_text),
                expires_days = VALUES(expires_days)'
        );
        $statement->execute([
            'board_id' => $boardId,
            'scoring_period' => $scoringPeriod,
            'label' => $attributes['label'],
            'retail_value' => $attributes['retail_value'],
            'terms_text' => $attributes['terms_text'],
            'expires_days' => $attributes['expires_days'],
        ]);

        $existing = self::findForBoardPeriod($boardId, $scoringPeriod);

        return (int) ($existing['id'] ?? 0);
    }

    public static function findForBoardPeriod(int $boardId, string $scoringPeriod): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM prizes WHERE board_id = ? AND scoring_period = ? LIMIT 1'
        );
        $statement->execute([$boardId, $scoringPeriod]);

        return $statement->fetch() ?: null;
    }

    public static function forBoard(int $boardId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM prizes WHERE board_id = ?');
        $statement->execute([$boardId]);

        $prizes = [];

        foreach ($statement->fetchAll() as $prize) {
            $prizes[(string) $prize['scoring_period']] = $prize;
        }

        return $prizes;
    }

    /**
     * A prize that has already been awarded stays put - the redemption code a
     * customer is holding has to keep resolving to something.
     */
    public static function delete(int $boardId, string $scoringPeriod): bool
    {
        $awarded = Database::connection()->prepare(
            'SELECT 1 FROM wins WHERE board_id = ? AND scoring_period = ? LIMIT 1'
        );
        $awarded->execute([$boardId, $scoringPeriod]);

        if ($awarded->fetchColumn()) {
            return false;
        }

        $statement = Database::connection()->prepare(
            'DELETE FROM prizes WHERE board_id = ? AND scoring_period = ?'
        );
        $statement->execute([$boardId, $scoringPeriod]);

        return true;
    }
}
