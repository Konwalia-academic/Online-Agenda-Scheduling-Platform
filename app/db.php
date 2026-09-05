<?php
/*
 * Database access: a small lazy PDO singleton.
 * MySQL 5.7+ / MariaDB with utf8mb4.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $ex) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'Database connection failed: ' . $ex->getMessage() . "\n");
                exit(1);
            }
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Database connection failed. Check app/config.php and that MySQL is running.');
        }
    }
    return $pdo;
}

/** Installer helper: return a PDO bound to a server without a selected database. */
function db_server(): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
