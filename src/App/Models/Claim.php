<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class Claim
{
    public static function create(array $attributes): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO claims (board_id, first_name, last_name, email, phone, consent_sms, consent_email, ip)
             VALUES (:board_id, :first_name, :last_name, :email, :phone, :consent_sms, :consent_email, :ip)'
        );
        $statement->execute($attributes);

        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM claims WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    /**
     * The claim limit is counted in squares held, matching either contact detail,
     * so the same person cannot reset their allowance by re-submitting the form.
     */
    public static function squaresHeldByPerson(int $boardId, string $email, string $phone): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM squares s
             INNER JOIN claims c ON c.id = s.claim_id
             WHERE s.board_id = ? AND c.board_id = ? AND (c.email = ? OR c.phone = ?)'
        );
        $statement->execute([$boardId, $boardId, $email, $phone]);

        return (int) $statement->fetchColumn();
    }

    public static function forBoard(int $boardId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM squares s WHERE s.claim_id = c.id) AS square_count,
                    (SELECT GROUP_CONCAT(CONCAT(s.row_index, "-", s.col_index) ORDER BY s.row_index, s.col_index SEPARATOR " ")
                     FROM squares s WHERE s.claim_id = c.id) AS square_cells
             FROM claims c
             WHERE c.board_id = ?
             ORDER BY c.created_at DESC, c.id DESC'
        );
        $statement->execute([$boardId]);

        return $statement->fetchAll();
    }
}
