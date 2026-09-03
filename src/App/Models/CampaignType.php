<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class CampaignType
{
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM campaign_types WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM campaign_types WHERE slug = ? LIMIT 1');
        $statement->execute([$slug]);

        return $statement->fetch() ?: null;
    }

    public static function active(): array
    {
        $statement = Database::connection()->query('SELECT * FROM campaign_types WHERE active = 1 ORDER BY name ASC');

        return $statement->fetchAll();
    }
}
