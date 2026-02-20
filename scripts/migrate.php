#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../lib/db.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(191) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($files, SORT_NATURAL);

$check = $pdo->prepare('SELECT 1 FROM migrations WHERE name = :name');
$mark = $pdo->prepare('INSERT INTO migrations(name) VALUES (:name)');

foreach ($files as $file) {
    $name = basename($file);
    $check->execute(['name' => $name]);
    if ($check->fetchColumn()) {
        echo "skip {$name}" . PHP_EOL;
        continue;
    }

    echo "apply {$name}" . PHP_EOL;
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$file}");
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $mark->execute(['name' => $name]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

echo "done" . PHP_EOL;
