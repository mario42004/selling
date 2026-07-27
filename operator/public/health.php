<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    $host = getenv('MARIADB_HOST') ?: 'mariadb';
    $port = getenv('MARIADB_PORT') ?: '3306';
    $database = getenv('MARIADB_DATABASE') ?: '';
    $user = getenv('MARIADB_USER') ?: '';
    $password = getenv('MARIADB_PASSWORD') ?: '';

    if ($database === '' || $user === '' || $password === '') {
        throw new RuntimeException('Database configuration is incomplete');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->query('SELECT 1');
    echo "ok\n";
} catch (Throwable) {
    http_response_code(503);
    echo "unavailable\n";
}
