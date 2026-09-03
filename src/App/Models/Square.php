<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class Square
{
    public const GRID_SIZE = 10;

    /**
     * Boards are born with all 100 cells so a claim is an UPDATE on an existing
     * row - that plus uniq_squares_cell is what stops two people taking one square.
     */
    public static function createGrid(int $boardId): void
    {
        $rows = [];
        $bindings = [];

        for ($rowIndex = 0; $rowIndex < self::GRID_SIZE; $rowIndex++) {
            for ($colIndex = 0; $colIndex < self::GRID_SIZE; $colIndex++) {
                $rows[] = '(?, ?, ?)';
                $bindings[] = $boardId;
                $bindings[] = $rowIndex;
                $bindings[] = $colIndex;
            }
        }

        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO squares (board_id, row_index, col_index) VALUES ' . implode(', ', $rows)
        );
        $statement->execute($bindings);
    }

    public static function forBoard(int $boardId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.id, s.row_index, s.col_index, s.claim_id, c.first_name, c.last_name
             FROM squares s
             LEFT JOIN claims c ON c.id = s.claim_id
             WHERE s.board_id = ?
             ORDER BY s.row_index ASC, s.col_index ASC'
        );
        $statement->execute([$boardId]);

        return $statement->fetchAll();
    }

    public static function findByCell(int $boardId, int $rowIndex, int $colIndex): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM squares WHERE board_id = ? AND row_index = ? AND col_index = ? LIMIT 1'
        );
        $statement->execute([$boardId, $rowIndex, $colIndex]);

        return $statement->fetch() ?: null;
    }

    /**
     * Conditional update: returns false when someone else took the square first.
     */
    public static function assign(int $boardId, int $rowIndex, int $colIndex, int $claimId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE squares SET claim_id = :claim_id
             WHERE board_id = :board_id AND row_index = :row_index AND col_index = :col_index AND claim_id IS NULL'
        );
        $statement->execute([
            'claim_id' => $claimId,
            'board_id' => $boardId,
            'row_index' => $rowIndex,
            'col_index' => $colIndex,
        ]);

        return $statement->rowCount() > 0;
    }

    public static function availableCount(int $boardId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM squares WHERE board_id = ? AND claim_id IS NULL'
        );
        $statement->execute([$boardId]);

        return (int) $statement->fetchColumn();
    }
}
