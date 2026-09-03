<?php

namespace Keel\App\Models;

use Keel\Core\Database;
use PDOException;

class Win
{
    /** No 0/O/1/I - codes get read aloud over a service counter. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const CODE_LENGTH = 8;

    /**
     * Relies on uniq_wins_board_period: a second attempt for the same period
     * inserts nothing and returns the row that already exists.
     *
     * @return array{win: array, created: bool}|null
     */
    public static function award(array $attributes): ?array
    {
        $existing = self::findForBoardPeriod((int) $attributes['board_id'], (string) $attributes['scoring_period']);

        if ($existing !== null) {
            return ['win' => $existing, 'created' => false];
        }

        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO wins (board_id, prize_id, square_id, claim_id, scoring_period, redemption_code)
             VALUES (:board_id, :prize_id, :square_id, :claim_id, :scoring_period, :redemption_code)'
        );

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $statement->execute([
                    'board_id' => $attributes['board_id'],
                    'prize_id' => $attributes['prize_id'],
                    'square_id' => $attributes['square_id'],
                    'claim_id' => $attributes['claim_id'],
                    'scoring_period' => $attributes['scoring_period'],
                    'redemption_code' => self::generateCode(),
                ]);
            } catch (PDOException $exception) {
                // Duplicate redemption code: draw another one and retry.
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }

                continue;
            }

            if ($statement->rowCount() === 0) {
                // uniq_wins_board_period rejected it: another worker got there first.
                $existing = self::findForBoardPeriod((int) $attributes['board_id'], (string) $attributes['scoring_period']);

                return $existing === null ? null : ['win' => $existing, 'created' => false];
            }

            $win = self::find((int) Database::connection()->lastInsertId());

            return $win === null ? null : ['win' => $win, 'created' => true];
        }

        return null;
    }

    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM wins WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    public static function findForBoardPeriod(int $boardId, string $scoringPeriod): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM wins WHERE board_id = ? AND scoring_period = ? LIMIT 1'
        );
        $statement->execute([$boardId, $scoringPeriod]);

        return $statement->fetch() ?: null;
    }

    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT w.*, c.tenant_id
             FROM wins w
             INNER JOIN boards b ON b.id = w.board_id
             INNER JOIN campaigns c ON c.id = b.campaign_id
             WHERE w.id = ? AND c.tenant_id = ?
             LIMIT 1'
        );
        $statement->execute([$id, $tenantId]);

        return $statement->fetch() ?: null;
    }

    public static function forTenant(int $tenantId, ?int $boardId = null): array
    {
        $sql = 'SELECT w.*, p.label AS prize_label, p.retail_value, p.expires_days,
                       cl.first_name, cl.last_name, cl.email, cl.phone,
                       s.row_index, s.col_index,
                       c.name AS campaign_name,
                       g.home_team, g.away_team, g.kickoff_at,
                       u.email AS redeemed_by_email
                FROM wins w
                INNER JOIN boards b ON b.id = w.board_id
                INNER JOIN campaigns c ON c.id = b.campaign_id
                INNER JOIN games g ON g.id = b.game_id
                INNER JOIN prizes p ON p.id = w.prize_id
                INNER JOIN claims cl ON cl.id = w.claim_id
                INNER JOIN squares s ON s.id = w.square_id
                LEFT JOIN users u ON u.id = w.redeemed_by_user_id
                WHERE c.tenant_id = :tenant_id';

        $bindings = ['tenant_id' => $tenantId];

        if ($boardId !== null) {
            $sql .= ' AND w.board_id = :board_id';
            $bindings['board_id'] = $boardId;
        }

        $sql .= ' ORDER BY w.created_at DESC, w.id DESC';

        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    public static function notificationPayload(int $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT w.id, w.redemption_code, w.scoring_period, w.notified_at,
                    p.label AS prize_label, p.retail_value, p.terms_text, p.expires_days,
                    cl.first_name, cl.last_name, cl.email, cl.phone, cl.consent_sms, cl.consent_email,
                    s.row_index, s.col_index,
                    c.name AS campaign_name, c.public_slug, c.terms_text AS campaign_terms,
                    g.home_team, g.away_team
             FROM wins w
             INNER JOIN boards b ON b.id = w.board_id
             INNER JOIN campaigns c ON c.id = b.campaign_id
             INNER JOIN games g ON g.id = b.game_id
             INNER JOIN prizes p ON p.id = w.prize_id
             INNER JOIN claims cl ON cl.id = w.claim_id
             INNER JOIN squares s ON s.id = w.square_id
             WHERE w.id = ? LIMIT 1'
        );
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    /**
     * Scoped by tenant in the WHERE clause so a forged win id cannot be redeemed
     * from another dealer's admin.
     */
    public static function markRedeemed(int $id, int $tenantId, int $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE wins w
             INNER JOIN boards b ON b.id = w.board_id
             INNER JOIN campaigns c ON c.id = b.campaign_id
             SET w.redeemed_at = NOW(), w.redeemed_by_user_id = :user_id
             WHERE w.id = :id AND c.tenant_id = :tenant_id AND w.redeemed_at IS NULL'
        );
        $statement->execute(['user_id' => $userId, 'id' => $id, 'tenant_id' => $tenantId]);

        return $statement->rowCount() > 0;
    }

    public static function markNotified(int $id): void
    {
        $statement = Database::connection()->prepare('UPDATE wins SET notified_at = NOW() WHERE id = ?');
        $statement->execute([$id]);
    }

    public static function generateCode(): string
    {
        $alphabetLength = strlen(self::CODE_ALPHABET);
        $code = '';

        for ($index = 0; $index < self::CODE_LENGTH; $index++) {
            $code .= self::CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }
}
