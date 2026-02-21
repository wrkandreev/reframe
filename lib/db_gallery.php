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

function sectionUpdate(int $id, string $name, int $sort): void
{
    $st = db()->prepare('UPDATE sections SET name=:name, sort_order=:sort WHERE id=:id');
    $st->execute(['id' => $id, 'name' => $name, 'sort' => $sort]);
}

function sectionDelete(int $id): void
{
    $st = db()->prepare('DELETE FROM sections WHERE id=:id');
    $st->execute(['id' => $id]);
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

function topicById(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM topics WHERE id=:id');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: null;
}

function topicsAllForSelect(): array
{
    $sql = 'SELECT t.id, t.parent_id, t.name, t.sort_order,
                   p.name AS parent_name, p.sort_order AS parent_sort_order
            FROM topics t
            LEFT JOIN topics p ON p.id=t.parent_id
            ORDER BY
              CASE WHEN t.parent_id IS NULL THEN t.sort_order ELSE p.sort_order END,
              CASE WHEN t.parent_id IS NULL THEN t.name ELSE p.name END,
              CASE WHEN t.parent_id IS NULL THEN 0 ELSE 1 END,
              t.sort_order,
              t.name';
    $rows = db()->query($sql)->fetchAll();

    foreach ($rows as &$row) {
        $isRoot = empty($row['parent_id']);
        $row['level'] = $isRoot ? 0 : 1;
        $row['full_name'] = $isRoot
            ? (string)$row['name']
            : ((string)$row['parent_name'] . ' / ' . (string)$row['name']);
    }
    unset($row);

    return $rows;
}

function topicCreate(string $name, ?int $parentId, int $sortOrder = 1000): int
{
    $st = db()->prepare('INSERT INTO topics(parent_id, name, sort_order) VALUES (:pid,:name,:sort)');
    $st->bindValue('pid', $parentId, $parentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('name', $name, PDO::PARAM_STR);
    $st->bindValue('sort', $sortOrder, PDO::PARAM_INT);
    $st->execute();
    return (int)db()->lastInsertId();
}

function topicUpdate(int $topicId, string $name, ?int $parentId, int $sortOrder = 1000): void
{
    $st = db()->prepare('UPDATE topics SET parent_id=:pid, name=:name, sort_order=:sort WHERE id=:id');
    $st->bindValue('id', $topicId, PDO::PARAM_INT);
    $st->bindValue('pid', $parentId, $parentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('name', $name, PDO::PARAM_STR);
    $st->bindValue('sort', $sortOrder, PDO::PARAM_INT);
    $st->execute();
}

function topicDelete(int $topicId): void
{
    $st = db()->prepare('DELETE FROM topics WHERE id=:id');
    $st->execute(['id' => $topicId]);
}

function photoTopicAttach(int $photoId, int $topicId): void
{
    $st = db()->prepare('INSERT IGNORE INTO photo_topics(photo_id, topic_id) VALUES (:pid,:tid)');
    $st->execute(['pid' => $photoId, 'tid' => $topicId]);
}

function photoTopicDetach(int $photoId, int $topicId): void
{
    $st = db()->prepare('DELETE FROM photo_topics WHERE photo_id=:pid AND topic_id=:tid');
    $st->execute(['pid' => $photoId, 'tid' => $topicId]);
}

function photoTopicsByPhotoId(int $photoId): array
{
    $sql = 'SELECT t.id, t.parent_id, t.name, t.sort_order,
                   p.name AS parent_name,
                   CASE WHEN t.parent_id IS NULL THEN t.name ELSE CONCAT(p.name, " / ", t.name) END AS full_name
            FROM photo_topics pt
            JOIN topics t ON t.id=pt.topic_id
            LEFT JOIN topics p ON p.id=t.parent_id
            WHERE pt.photo_id=:pid
            ORDER BY
              CASE WHEN t.parent_id IS NULL THEN t.sort_order ELSE p.sort_order END,
              CASE WHEN t.parent_id IS NULL THEN t.name ELSE p.name END,
              CASE WHEN t.parent_id IS NULL THEN 0 ELSE 1 END,
              t.sort_order,
              t.name';
    $st = db()->prepare($sql);
    $st->execute(['pid' => $photoId]);
    return $st->fetchAll();
}

function photoTopicsMapByPhotoIds(array $photoIds): array
{
    $photoIds = array_values(array_unique(array_map('intval', $photoIds)));
    if ($photoIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
    $sql = "SELECT pt.photo_id, t.id, t.parent_id, t.name, t.sort_order,
                   p.name AS parent_name,
                   CASE WHEN t.parent_id IS NULL THEN t.name ELSE CONCAT(p.name, ' / ', t.name) END AS full_name
            FROM photo_topics pt
            JOIN topics t ON t.id=pt.topic_id
            LEFT JOIN topics p ON p.id=t.parent_id
            WHERE pt.photo_id IN ($placeholders)
            ORDER BY pt.photo_id,
              CASE WHEN t.parent_id IS NULL THEN t.sort_order ELSE p.sort_order END,
              CASE WHEN t.parent_id IS NULL THEN t.name ELSE p.name END,
              CASE WHEN t.parent_id IS NULL THEN 0 ELSE 1 END,
              t.sort_order,
              t.name";

    $st = db()->prepare($sql);
    $st->execute($photoIds);

    $map = [];
    foreach ($st->fetchAll() as $row) {
        $pid = (int)$row['photo_id'];
        if (!isset($map[$pid])) {
            $map[$pid] = [];
        }
        $map[$pid][] = [
            'id' => (int)$row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            'name' => (string)$row['name'],
            'full_name' => (string)$row['full_name'],
        ];
    }
    return $map;
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
    $st = db()->prepare('INSERT INTO comment_users(display_name, token_hash, token_plain, is_active) VALUES (:n,:h,:p,1)');
    $st->execute(['n' => $displayName, 'h' => $hash, 'p' => $token]);

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

function commenterRegenerateToken(int $id): string
{
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $token);
    $st = db()->prepare('UPDATE comment_users SET token_hash=:h, token_plain=:p WHERE id=:id');
    $st->execute(['h' => $hash, 'p' => $token, 'id' => $id]);
    return $token;
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

function settingGet(string $key, string $default = ''): string
{
    try {
        $st = db()->prepare('SELECT `value` FROM site_settings WHERE `key`=:k');
        $st->execute(['k' => $key]);
        $v = $st->fetchColumn();
        return is_string($v) ? $v : $default;
    } catch (Throwable) {
        return $default;
    }
}

function settingSet(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO site_settings(`key`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $st->execute(['k' => $key, 'v' => $value]);
}
