<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function sectionsAll(): array
{
    $sql = 'SELECT s.*, (SELECT COUNT(*) FROM photos p WHERE p.section_id=s.id) AS photos_count
            FROM sections s
            ORDER BY s.sort_order, s.name';
    return db()->query($sql)->fetchAll();
}

function sectionById(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM sections WHERE id=:id');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: null;
}

function sectionCreate(string $name, int $sort): void
{
    $st = db()->prepare('INSERT INTO sections(name, sort_order) VALUES (:name,:sort)');
    $st->execute(['name' => $name, 'sort' => $sort]);
}

function photosBySection(int $sectionId): array
{
    $sql = 'SELECT p.*, 
                   bf.id AS before_file_id, bf.file_path AS before_path,
                   af.id AS after_file_id, af.file_path AS after_path
            FROM photos p
            LEFT JOIN photo_files bf ON bf.photo_id=p.id AND bf.kind="before"
            LEFT JOIN photo_files af ON af.photo_id=p.id AND af.kind="after"
            WHERE p.section_id=:sid
            ORDER BY p.sort_order, p.id DESC';
    $st = db()->prepare($sql);
    $st->execute(['sid' => $sectionId]);
    return $st->fetchAll();
}

function photoById(int $photoId): ?array
{
    $sql = 'SELECT p.*, 
                   bf.id AS before_file_id, bf.file_path AS before_path,
                   af.id AS after_file_id, af.file_path AS after_path
            FROM photos p
            LEFT JOIN photo_files bf ON bf.photo_id=p.id AND bf.kind="before"
            LEFT JOIN photo_files af ON af.photo_id=p.id AND af.kind="after"
            WHERE p.id=:id';
    $st = db()->prepare($sql);
    $st->execute(['id' => $photoId]);
    return $st->fetch() ?: null;
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

function photoFileById(int $fileId): ?array
{
    $st = db()->prepare('SELECT * FROM photo_files WHERE id=:id');
    $st->execute(['id' => $fileId]);
    return $st->fetch() ?: null;
}

function commenterByToken(string $token): ?array
{
    $hash = hash('sha256', $token);
    $st = db()->prepare('SELECT * FROM comment_users WHERE token_hash=:h AND is_active=1');
    $st->execute(['h' => $hash]);
    return $st->fetch() ?: null;
}

function commenterCreate(string $displayName): array
{
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $token);
    $st = db()->prepare('INSERT INTO comment_users(display_name, token_hash, is_active) VALUES (:n,:h,1)');
    $st->execute(['n' => $displayName, 'h' => $hash]);

    return [
        'id' => (int)db()->lastInsertId(),
        'display_name' => $displayName,
        'token' => $token,
    ];
}

function commentersAll(): array
{
    return db()->query('SELECT * FROM comment_users ORDER BY id DESC')->fetchAll();
}

function commenterDelete(int $id): void
{
    $st = db()->prepare('DELETE FROM comment_users WHERE id=:id');
    $st->execute(['id' => $id]);
}

function commentsByPhoto(int $photoId): array
{
    $sql = 'SELECT c.*, u.display_name
            FROM photo_comments c
            LEFT JOIN comment_users u ON u.id=c.user_id
            WHERE c.photo_id=:pid
            ORDER BY c.created_at DESC, c.id DESC';
    $st = db()->prepare($sql);
    $st->execute(['pid' => $photoId]);
    return $st->fetchAll();
}

function commentAdd(int $photoId, int $userId, string $text): void
{
    $st = db()->prepare('INSERT INTO photo_comments(photo_id, user_id, comment_text) VALUES (:p,:u,:t)');
    $st->execute(['p' => $photoId, 'u' => $userId, 't' => $text]);
}

function commentDelete(int $id): void
{
    $st = db()->prepare('DELETE FROM photo_comments WHERE id=:id');
    $st->execute(['id' => $id]);
}

function commentsLatest(int $limit = 100): array
{
    $sql = 'SELECT c.id, c.photo_id, c.comment_text, c.created_at, p.code_name, u.display_name
            FROM photo_comments c
            JOIN photos p ON p.id=c.photo_id
            LEFT JOIN comment_users u ON u.id=c.user_id
            ORDER BY c.id DESC
            LIMIT ' . (int)$limit;
    return db()->query($sql)->fetchAll();
}
