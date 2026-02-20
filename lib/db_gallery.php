<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function sectionsAll(): array
{
    return db()->query('SELECT s.*, (SELECT COUNT(*) FROM photos p WHERE p.section_id=s.id) AS photos_count FROM sections s ORDER BY s.sort_order, s.name')->fetchAll();
}

function sectionById(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM sections WHERE id=:id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

function sectionCreate(string $name, int $sort): void
{
    $st = db()->prepare('INSERT INTO sections(name, sort_order) VALUES (:name,:sort)');
    $st->execute(['name' => $name, 'sort' => $sort]);
}

function photosBySection(int $sectionId): array
{
    $sql = 'SELECT p.*, bf.file_path AS before_path, af.file_path AS after_path
            FROM photos p
            LEFT JOIN photo_files bf ON bf.photo_id=p.id AND bf.kind="before"
            LEFT JOIN photo_files af ON af.photo_id=p.id AND af.kind="after"
            WHERE p.section_id=:sid
            ORDER BY p.sort_order, p.id DESC';
    $st = db()->prepare($sql);
    $st->execute(['sid' => $sectionId]);
    return $st->fetchAll();
}

function photoCreate(int $sectionId, string $codeName, ?string $description, int $sortOrder): int
{
    $st = db()->prepare('INSERT INTO photos(section_id, code_name, description, sort_order) VALUES (:sid,:code,:descr,:sort)');
    $st->execute([
        'sid' => $sectionId,
        'code' => $codeName,
        'descr' => $description,
        'sort' => $sortOrder,
    ]);
    return (int)db()->lastInsertId();
}

function photoFileUpsert(int $photoId, string $kind, string $path, string $mime, int $size): void
{
    $sql = 'INSERT INTO photo_files(photo_id, kind, file_path, mime_type, size_bytes)
            VALUES (:pid,:kind,:path,:mime,:size)
            ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), mime_type=VALUES(mime_type), size_bytes=VALUES(size_bytes), updated_at=CURRENT_TIMESTAMP';
    $st = db()->prepare($sql);
    $st->execute([
        'pid' => $photoId,
        'kind' => $kind,
        'path' => $path,
        'mime' => $mime,
        'size' => $size,
    ]);
}
