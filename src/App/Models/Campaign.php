<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * Every read here takes a tenant id. There is deliberately no findById() without
 * one so a controller cannot accidentally hand a dealer another dealer's campaign.
 */
class Campaign
{
    public const STATUSES = ['draft', 'active', 'paused', 'complete'];

    public static function create(array $attributes): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO campaigns (tenant_id, campaign_type_id, name, status, starts_at, ends_at,
                                    brand_primary_color, brand_logo_path, public_slug, terms_text)
             VALUES (:tenant_id, :campaign_type_id, :name, :status, :starts_at, :ends_at,
                     :brand_primary_color, :brand_logo_path, :public_slug, :terms_text)'
        );
        $statement->execute($attributes);

        return (int) Database::connection()->lastInsertId();
    }

    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM campaigns WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $statement->execute([$id, $tenantId]);

        return $statement->fetch() ?: null;
    }

    public static function findByPublicSlug(string $slug): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT c.*, t.slug AS campaign_type_slug
             FROM campaigns c
             INNER JOIN campaign_types t ON t.id = c.campaign_type_id
             WHERE c.public_slug = ? LIMIT 1'
        );
        $statement->execute([$slug]);

        return $statement->fetch() ?: null;
    }

    public static function forTenant(int $tenantId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT c.*, t.name AS campaign_type_name,
                    (SELECT COUNT(*) FROM boards b WHERE b.campaign_id = c.id) AS board_count
             FROM campaigns c
             INNER JOIN campaign_types t ON t.id = c.campaign_type_id
             WHERE c.tenant_id = ?
             ORDER BY c.created_at DESC, c.id DESC'
        );
        $statement->execute([$tenantId]);

        return $statement->fetchAll();
    }

    public static function update(int $id, int $tenantId, array $attributes): void
    {
        $attributes['id'] = $id;
        $attributes['tenant_id'] = $tenantId;

        $statement = Database::connection()->prepare(
            'UPDATE campaigns
             SET name = :name,
                 status = :status,
                 starts_at = :starts_at,
                 ends_at = :ends_at,
                 brand_primary_color = :brand_primary_color,
                 brand_logo_path = :brand_logo_path,
                 terms_text = :terms_text
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute($attributes);
    }

    public static function slugExists(string $slug): bool
    {
        $statement = Database::connection()->prepare('SELECT 1 FROM campaigns WHERE public_slug = ? LIMIT 1');
        $statement->execute([$slug]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * Slugs are public URLs, so they get a random suffix rather than a guessable counter.
     */
    public static function generateSlug(string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        $base = $base !== '' ? substr($base, 0, 40) : 'game';

        do {
            $slug = $base . '-' . bin2hex(random_bytes(3));
        } while (self::slugExists($slug));

        return $slug;
    }
}
