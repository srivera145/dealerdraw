<?php

namespace Keel\App\Models;

use Keel\Core\Database;
use PDO;
use PDOException;

class Win
{
    /**
     * Uppercase alphanumeric minus 0, O, 1, I and L. Codes get read aloud and
     * copied off a phone screen at a service counter, so the pairs that get
     * misread are simply not in the alphabet.
     */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const CODE_LENGTH = 8;
    private const CODE_ATTEMPTS = 8;

    /**
     * Relies on uniq_wins_board_period: a second attempt for the same period
     * inserts nothing and returns the row that already exists.
     *
     * claim_id may be null - a winning square nobody claimed still produces a
     * win row so the dealer can see it and decide what to do with the prize.
     *
     * @return array{win: array, created: bool}|null
     */
    public static function award(array $attributes): ?array
    {
        $boardId = (int) $attributes['board_id'];
        $period = (string) $attributes['scoring_period'];

        $existing = self::findForBoardPeriod($boardId, $period);

        if ($existing !== null) {
            return ['win' => $existing, 'created' => false];
        }

        $claimId = $attributes['claim_id'] === null ? null : (int) $attributes['claim_id'];

        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO wins (board_id, prize_id, square_id, claim_id, scoring_period, redemption_code)
             VALUES (:board_id, :prize_id, :square_id, :claim_id, :scoring_period, :redemption_code)'
        );

        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            try {
                $statement->bindValue(':board_id', $boardId, PDO::PARAM_INT);
                $statement->bindValue(':prize_id', (int) $attributes['prize_id'], PDO::PARAM_INT);
                $statement->bindValue(':square_id', (int) $attributes['square_id'], PDO::PARAM_INT);
                $statement->bindValue(':claim_id', $claimId, $claimId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $statement->bindValue(':scoring_period', $period);
                $statement->bindValue(':redemption_code', self::generateCode());
                $statement->execute();
            } catch (PDOException $exception) {
                // Duplicate redemption code: draw another one and retry.
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }

                continue;
            }

            if ($statement->rowCount() === 0) {
                // uniq_wins_board_period rejected it: another worker got there
                // first, or the drawn code collided. Tell the two apart.
                $existing = self::findForBoardPeriod($boardId, $period);

                if ($existing !== null) {
                    return ['win' => $existing, 'created' => false];
                }

                continue;
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

    public static function findByMessageUuid(string $messageUuid): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM wins WHERE sms_message_uuid = ? LIMIT 1'
        );
        $statement->execute([$messageUuid]);

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
        // LEFT JOIN on claims: unclaimed winning squares are wins too.
        $sql = 'SELECT w.*, p.label AS prize_label, p.retail_value, p.expires_days,
                       cl.first_name, cl.last_name, cl.email, cl.phone, cl.consent_sms, cl.consent_email,
                       s.row_index, s.col_index,
                       c.name AS campaign_name,
                       g.home_team, g.away_team, g.kickoff_at,
                       u.email AS redeemed_by_email
                FROM wins w
                INNER JOIN boards b ON b.id = w.board_id
                INNER JOIN campaigns c ON c.id = b.campaign_id
                INNER JOIN games g ON g.id = b.game_id
                INNER JOIN prizes p ON p.id = w.prize_id
                INNER JOIN squares s ON s.id = w.square_id
                LEFT JOIN claims cl ON cl.id = w.claim_id
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

    /**
     * Everything NotifyWinnerJob needs, including the tenant the opt-out list is
     * keyed on and the dealership name that goes in the message body.
     */
    public static function notificationPayload(int $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT w.id, w.redemption_code, w.scoring_period, w.notified_at, w.created_at,
                    p.label AS prize_label, p.retail_value, p.terms_text, p.expires_days,
                    cl.id AS claim_id, cl.first_name, cl.last_name, cl.email, cl.phone,
                    cl.consent_sms, cl.consent_email,
                    s.row_index, s.col_index,
                    c.id AS campaign_id, c.tenant_id, c.name AS campaign_name,
                    c.public_slug, c.terms_text AS campaign_terms,
                    o.name AS dealer_name,
                    g.home_team, g.away_team
             FROM wins w
             INNER JOIN boards b ON b.id = w.board_id
             INNER JOIN campaigns c ON c.id = b.campaign_id
             INNER JOIN organizations o ON o.id = c.tenant_id
             INNER JOIN games g ON g.id = b.game_id
             INNER JOIN prizes p ON p.id = w.prize_id
             INNER JOIN squares s ON s.id = w.square_id
             LEFT JOIN claims cl ON cl.id = w.claim_id
             WHERE w.id = ? LIMIT 1'
        );
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    /**
     * Atomically takes ownership of the notification. Returns false when
     * notified_at was already set, which is what makes a re-run a no-op rather
     * than a second text message.
     */
    public static function claimNotification(int $id): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE wins SET notified_at = NOW() WHERE id = ? AND notified_at IS NULL'
        );
        $statement->execute([$id]);

        return $statement->rowCount() > 0;
    }

    /** Hands the notification back when every channel failed, so a retry can run. */
    public static function releaseNotification(int $id, string $error): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE wins SET notified_at = NULL, notify_error = ? WHERE id = ?'
        );
        $statement->execute([substr($error, 0, 255), $id]);
    }

    public static function recordDelivery(int $id, array $attributes): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE wins
             SET sms_message_uuid = :sms_message_uuid,
                 sms_status = :sms_status,
                 sms_status_at = :sms_status_at,
                 email_status = :email_status,
                 notify_error = :notify_error
             WHERE id = :id'
        );
        $statement->execute([
            'sms_message_uuid' => $attributes['sms_message_uuid'] ?? null,
            'sms_status' => $attributes['sms_status'] ?? null,
            'sms_status_at' => ($attributes['sms_status'] ?? null) === null ? null : date('Y-m-d H:i:s'),
            'email_status' => $attributes['email_status'] ?? null,
            'notify_error' => isset($attributes['notify_error']) ? substr((string) $attributes['notify_error'], 0, 255) : null,
            'id' => $id,
        ]);
    }

    /** Applied from the Plivo status callback. */
    public static function recordSmsStatus(string $messageUuid, string $status): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE wins SET sms_status = ?, sms_status_at = NOW() WHERE sms_message_uuid = ?'
        );
        $statement->execute([substr($status, 0, 32), $messageUuid]);

        return $statement->rowCount() > 0;
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

    /** The date a code stops being honoured, from the prize's expiry window. */
    public static function expiresAt(array $win): string
    {
        $awardedAt = strtotime((string) ($win['created_at'] ?? 'now')) ?: time();
        $days = max(1, (int) ($win['expires_days'] ?? 30));

        return date('Y-m-d', $awardedAt + ($days * 86400));
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
