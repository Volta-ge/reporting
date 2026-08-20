<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /**
     * @param array{host: string, port: int, database: string, username: string, password: string} $config
     */
    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        try {
            return new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Could not connect to the database. Check config.php and that this server can reach '
                . $config['host'] . ':' . $config['port'] . '.',
                previous: $e,
            );
        }
    }
}
