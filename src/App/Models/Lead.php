<?php

namespace Keel\App\Models;

use Keel\Core\Database;
use PDO;

class Lead
{
    public static function create(array $attributes): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO leads (dealer_name, contact_name, email, phone, rooftop_count, message, source, ip)
             VALUES (:dealer_name, :contact_name, :email, :phone, :rooftop_count, :message, :source, :ip)'
        );

        $rooftopCount = $attributes['rooftop_count'] ?? null;

        $statement->bindValue(':dealer_name', $attributes['dealer_name']);
        $statement->bindValue(':contact_name', $attributes['contact_name']);
        $statement->bindValue(':email', $attributes['email']);
        $statement->bindValue(':phone', $attributes['phone']);
        $statement->bindValue(':rooftop_count', $rooftopCount, $rooftopCount === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':message', $attributes['message'] ?? null);
        $statement->bindValue(':source', $attributes['source'] ?? 'landing_page');
        $statement->bindValue(':ip', $attributes['ip'] ?? null);
        $statement->execute();

        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM leads WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    public static function recent(int $limit = 50): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM leads ORDER BY created_at DESC, id DESC LIMIT :row_limit'
        );
        $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * The same person tapping submit twice. Matched on email alone, not on IP:
     * a dealer group behind one connection can easily have two managers ask for
     * a demo in the same afternoon, and losing the second is worse than storing
     * it. Abuse from one address is the rate limiter's job, not this one's.
     */
    public static function recentlySubmitted(string $email, int $withinMinutes = 5): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM leads
             WHERE email = :email
               AND created_at >= DATE_SUB(NOW(), INTERVAL :within_minutes MINUTE)
             LIMIT 1'
        );
        $statement->bindValue(':email', $email);
        $statement->bindValue(':within_minutes', $withinMinutes, PDO::PARAM_INT);
        $statement->execute();

        return (bool) $statement->fetchColumn();
    }
}
