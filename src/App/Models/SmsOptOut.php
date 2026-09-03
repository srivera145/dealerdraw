<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * Tenant-wide SMS suppression.
 *
 * A STOP arrives against one message on one board, but it suppresses the number
 * for the whole dealership - the unique key is (tenant_id, phone_e164), and
 * every send checks it before dialling out.
 */
class SmsOptOut
{
    public const STOP_KEYWORDS = ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit', 'stop all'];
    public const START_KEYWORDS = ['start', 'unstop', 'yes', 'subscribe'];

    public static function add(int $tenantId, string $phoneE164, string $keyword = '', string $source = 'inbound_stop'): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO sms_opt_outs (tenant_id, phone_e164, source, keyword)
             VALUES (:tenant_id, :phone_e164, :source, :keyword)
             ON DUPLICATE KEY UPDATE keyword = VALUES(keyword), source = VALUES(source)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'phone_e164' => $phoneE164,
            'source' => $source,
            'keyword' => $keyword === '' ? null : substr($keyword, 0, 20),
        ]);
    }

    public static function remove(int $tenantId, string $phoneE164): void
    {
        $statement = Database::connection()->prepare(
            'DELETE FROM sms_opt_outs WHERE tenant_id = ? AND phone_e164 = ?'
        );
        $statement->execute([$tenantId, $phoneE164]);
    }

    public static function isOptedOut(int $tenantId, string $phoneE164): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM sms_opt_outs WHERE tenant_id = ? AND phone_e164 = ? LIMIT 1'
        );
        $statement->execute([$tenantId, $phoneE164]);

        return (bool) $statement->fetchColumn();
    }

    public static function forTenant(int $tenantId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM sms_opt_outs WHERE tenant_id = ? ORDER BY created_at DESC, id DESC'
        );
        $statement->execute([$tenantId]);

        return $statement->fetchAll();
    }

    /**
     * Tenants that have ever texted this number, so an inbound STOP can be
     * applied to the right dealership even though the reply carries no board id.
     *
     * @return int[]
     */
    public static function tenantsForPhone(string $phoneE164): array
    {
        // Claims store the number as the visitor typed it, so the match is made
        // on digits rather than on formatting.
        $digits = preg_replace('/\D+/', '', $phoneE164) ?? '';
        $national = strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1) : $digits;

        $statement = Database::connection()->prepare(
            "SELECT DISTINCT c.tenant_id
             FROM claims cl
             INNER JOIN boards b ON b.id = cl.board_id
             INNER JOIN campaigns c ON c.id = b.campaign_id
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(cl.phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', '')
                   IN (:digits, :national)"
        );
        $statement->execute(['digits' => $digits, 'national' => $national]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Turns consent off on every claim that tenant holds for this number, so the
     * dealer's own exports and screens reflect the opt-out too.
     */
    public static function revokeClaimConsent(int $tenantId, string $phoneE164): int
    {
        $digits = preg_replace('/\D+/', '', $phoneE164) ?? '';
        $national = strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1) : $digits;

        $statement = Database::connection()->prepare(
            "UPDATE claims cl
             INNER JOIN boards b ON b.id = cl.board_id
             INNER JOIN campaigns c ON c.id = b.campaign_id
             SET cl.consent_sms = 0
             WHERE c.tenant_id = :tenant_id
               AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(cl.phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', '')
                   IN (:digits, :national)"
        );
        $statement->execute(['tenant_id' => $tenantId, 'digits' => $digits, 'national' => $national]);

        return $statement->rowCount();
    }
}
