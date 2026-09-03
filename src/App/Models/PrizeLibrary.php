<?php

namespace Keel\App\Models;

use Keel\Core\Database;
use PDO;

class PrizeLibrary
{
    /**
     * A dealer sees the platform defaults (tenant_id NULL) plus their own saved offers.
     */
    public static function forTenant(int $tenantId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM prize_library
             WHERE tenant_id IS NULL OR tenant_id = ?
             ORDER BY tenant_id IS NULL DESC, label ASC'
        );
        $statement->execute([$tenantId]);

        return $statement->fetchAll();
    }

    public static function create(int $tenantId, array $attributes): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO prize_library (tenant_id, label, retail_value, terms_text, expires_days)
             VALUES (:tenant_id, :label, :retail_value, :terms_text, :expires_days)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'label' => $attributes['label'],
            'retail_value' => $attributes['retail_value'],
            'terms_text' => $attributes['terms_text'],
            'expires_days' => $attributes['expires_days'],
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** Global rows are readable by every tenant but deletable by none of them. */
    public static function deleteForTenant(int $id, int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'DELETE FROM prize_library WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() > 0;
    }
}
