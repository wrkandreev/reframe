<?php

declare(strict_types=1);

function appConfig(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $path = __DIR__ . '/../config.php';
    if (!is_file($path)) {
        throw new RuntimeException('config.php not found. Copy config.php.example');
    }

    $cfg = require $path;
    if (!is_array($cfg)) {
        throw new RuntimeException('Invalid config.php format');
    }

    return $cfg;
}

function appSecrets(): array
{
    static $secrets = null;
    if ($secrets !== null) {
        return $secrets;
    }

    $path = __DIR__ . '/../secrets.php';
    if (!is_file($path)) {
        throw new RuntimeException('secrets.php not found. Copy secrets.php.example');
    }

    $secrets = require $path;
    if (!is_array($secrets)) {
        throw new RuntimeException('Invalid secrets.php format');
    }

    return $secrets;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = appConfig()['db'] ?? [];
    $host = $db['host'] ?? '127.0.0.1';
    $port = (int)($db['port'] ?? 3306);
    $name = $db['name'] ?? '';
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, (string)$user, (string)$pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
