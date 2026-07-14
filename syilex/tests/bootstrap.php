<?php

/**
 * PHPUnit bootstrap — runs before the Laravel application boots.
 *
 * 1. Clears stale config cache so phpunit.xml env vars (e.g. DB_DATABASE) apply.
 * 2. Ensures the MySQL test database exists (avoids MigrateCommand confirm() prompts).
 */

$configCache = dirname(__DIR__).'/bootstrap/cache/config.php';

if (is_file($configCache)) {
    unlink($configCache);
}

$connection = $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: 'mysql';

if ($connection === 'mysql') {
    $database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'posip_db_test';
    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
    $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
    $user = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root';
    $pass = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port}",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        fwrite(STDERR, "Could not create test database `{$database}`: {$e->getMessage()}\n");
        exit(1);
    }
}

require dirname(__DIR__).'/vendor/autoload.php';
