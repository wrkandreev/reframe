#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/thumbs.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$st = $pdo->query('SELECT file_path FROM photo_files ORDER BY id');
$paths = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
if (!is_array($paths)) {
    $paths = [];
}

$total = 0;
$ok = 0;
$missing = 0;

foreach ($paths as $path) {
    if (!is_string($path) || $path === '') {
        continue;
    }

    $total++;
    $thumb = ensureThumbForSource(dirname(__DIR__), $path);
    if ($thumb !== null) {
        $ok++;
        continue;
    }
    $missing++;
}

echo "checked: {$total}" . PHP_EOL;
echo "generated_or_fresh: {$ok}" . PHP_EOL;
echo "missing_or_failed: {$missing}" . PHP_EOL;
