<?php

namespace Keel\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function resetConnection(): void
    {
        self::$instance = null;
    }

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '3306');
            $name = Env::get('DB_DATABASE');
            $user = Env::get('DB_USERNAME');
            $pass = Env::get('DB_PASSWORD');
            $charset = Env::get('DB_CHARSET', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                // Align the server clock with PHP's. Datetimes written by the app
                // are compared against NOW() in scheduling queries, so the two
                // must agree; a numeric offset works without the MySQL tz tables.
                $offset = (new \DateTimeImmutable('now'))->format('P');
                self::$instance->exec("SET time_zone = '{$offset}'");
            } catch (PDOException $e) {
                error_log('[Keel] DB connection failed: ' . $e->getMessage());
                throw new PDOException('Database connection failed.');
            }
        }

        return self::$instance;
    }
}
