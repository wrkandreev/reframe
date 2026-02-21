<?php

declare(strict_types=1);

function commentCountsByPhotoIds(array $photoIds): array
{
    $photoIds = array_values(array_unique(array_map('intval', $photoIds)));
    if ($photoIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
    $st = db()->prepare("SELECT photo_id, COUNT(*) AS cnt FROM photo_comments WHERE photo_id IN ($placeholders) GROUP BY photo_id");
    $st->execute($photoIds);

    $map = [];
    foreach ($st->fetchAll() as $row) {
        $map[(int)$row['photo_id']] = (int)$row['cnt'];
    }
    return $map;
}

function commentsSearch(string $photoQuery, string $userQuery, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $where = [];
    $params = [];

    if ($photoQuery !== '') {
        $where[] = 'p.code_name LIKE :photo';
        $params['photo'] = '%' . $photoQuery . '%';
    }
    if ($userQuery !== '') {
        $where[] = 'COALESCE(u.display_name, "") LIKE :user';
        $params['user'] = '%' . $userQuery . '%';
    }

    $sql = 'SELECT c.id, c.photo_id, c.comment_text, c.created_at, p.code_name, u.display_name
            FROM photo_comments c
            JOIN photos p ON p.id=c.photo_id
            LEFT JOIN comment_users u ON u.id=c.user_id';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY c.id DESC LIMIT ' . $limit;
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function buildTopicTree(array $topics): array
{
    $roots = [];
    $children = [];

    foreach ($topics as $topic) {
        $pid = isset($topic['parent_id']) && $topic['parent_id'] !== null ? (int)$topic['parent_id'] : 0;
        if ($pid === 0) {
            $roots[] = $topic;
            continue;
        }
        if (!isset($children[$pid])) {
            $children[$pid] = [];
        }
        $children[$pid][] = $topic;
    }

    foreach ($roots as &$root) {
        $rootId = (int)$root['id'];
        $root['children'] = $children[$rootId] ?? [];
    }
    unset($root);

    return $roots;
}

function nextSectionSortOrder(): int
{
    $sort = (int)db()->query('SELECT COALESCE(MAX(sort_order), 990) + 10 FROM sections')->fetchColumn();
    return max(10, $sort);
}

function nextTopicSortOrder(?int $parentId): int
{
    $st = db()->prepare('SELECT COALESCE(MAX(sort_order), 990) + 10 FROM topics WHERE parent_id <=> :pid');
    $st->bindValue('pid', $parentId, $parentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->execute();
    $sort = (int)$st->fetchColumn();
    return max(10, $sort);
}

function saveBulkBefore(array $files, int $sectionId): array
{
    $ok = 0;
    $errors = [];

    $names = $files['name'] ?? [];
    $tmp = $files['tmp_name'] ?? [];
    $sizes = $files['size'] ?? [];
    $errs = $files['error'] ?? [];
    if (!is_array($names)) {
        $names = [$names];
        $tmp = [$tmp];
        $sizes = [$sizes];
        $errs = [$errs];
    }

    foreach ($names as $i => $orig) {
        $file = [
            'name' => $orig,
            'tmp_name' => $tmp[$i] ?? '',
            'size' => $sizes[$i] ?? 0,
            'error' => $errs[$i] ?? UPLOAD_ERR_NO_FILE,
        ];

        try {
            $base = (string)pathinfo((string)$orig, PATHINFO_FILENAME);
            $base = trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $base) ?? 'photo', '._-');
            if ($base === '') {
                $base = 'photo';
            }

            $codeName = nextUniqueCodeName($base);
            $photoId = photoCreate($sectionId, $codeName, null, nextSortOrderForSection($sectionId));
            $saved = saveSingleImage($file, $codeName, $sectionId);
            photoFileUpsert($photoId, 'before', $saved['path'], $saved['mime'], $saved['size']);
            $ok++;
        } catch (Throwable $e) {
            $errors[] = (string)$orig . ': ' . $e->getMessage();
        }
    }

    return ['ok' => $ok, 'errors' => $errors];
}

function saveSingleImage(array $file, string $baseName, int $sectionId): array
{
    $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Ошибка загрузки');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Превышен лимит 3 МБ');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Некорректный источник');
    }

    $mime = mime_content_type($tmp) ?: '';
    if (!isset($allowedMime[$mime])) {
        throw new RuntimeException('Недопустимый тип файла');
    }

    $safeBase = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $baseName) ?? 'photo';
    $safeBase = trim($safeBase, '._-');
    if ($safeBase === '') {
        $safeBase = 'photo';
    }

    $ext = $allowedMime[$mime];
    $root = dirname(__DIR__);
    $dir = $root . '/photos/section_' . $sectionId;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = uniqueName($dir, $safeBase, $ext);
    $dest = $dir . '/' . $name;

    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Не удалось сохранить файл');
    }

    $storedRelPath = 'photos/section_' . $sectionId . '/' . $name;
    ensureThumbForSource($root, $storedRelPath);

    return [
        'path' => $storedRelPath,
        'mime' => $mime,
        'size' => $size,
    ];
}

function uniqueName(string $dir, string $base, string $ext): string
{
    $i = 0;
    do {
        $name = $i === 0 ? "{$base}.{$ext}" : "{$base}_{$i}.{$ext}";
        $i++;
    } while (is_file($dir . '/' . $name));
    return $name;
}

function deleteSectionStorage(int $sectionId): void
{
    $dir = dirname(__DIR__) . '/photos/section_' . $sectionId;
    if (!is_dir($dir)) {
        return;
    }

    deleteDirRecursive($dir);
}

function deleteDirRecursive(string $dir): void
{
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDirRecursive($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

function removeSectionImageFiles(int $sectionId): void
{
    $st = db()->prepare('SELECT pf.file_path
                         FROM photo_files pf
                         JOIN photos p ON p.id = pf.photo_id
                         WHERE p.section_id = :sid');
    $st->execute(['sid' => $sectionId]);
    $paths = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($paths)) {
        return;
    }

    $root = dirname(__DIR__);
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }

        $abs = $root . '/' . ltrim($path, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
        deleteThumbBySourcePath($root, $path);
    }
}

function rotateImageOnDisk(string $path, int $degrees): void
{
    $mime = mime_content_type($path) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new RuntimeException('Недопустимый тип файла для поворота');
    }

    if (extension_loaded('imagick')) {
        $im = new Imagick($path);
        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        $im->rotateImage(new ImagickPixel('none'), $degrees);
        $im->setImagePage(0, 0, 0, 0);
        if ($mime === 'image/jpeg') {
            $im->setImageCompressionQuality(92);
        }
        $im->writeImage($path);
        $im->clear();
        $im->destroy();
        return;
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'image/gif' => @imagecreatefromgif($path),
        default => false,
    };
    if (!$src) {
        throw new RuntimeException('Не удалось открыть изображение');
    }

    $bgColor = 0;
    if ($mime === 'image/png' || $mime === 'image/webp') {
        $bgColor = imagecolorallocatealpha($src, 0, 0, 0, 127);
    }

    $rotated = imagerotate($src, -$degrees, $bgColor);
    if (!$rotated) {
        imagedestroy($src);
        throw new RuntimeException('Не удалось повернуть изображение');
    }

    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
    }

    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($rotated, $path, 92),
        'image/png' => imagepng($rotated, $path),
        'image/webp' => function_exists('imagewebp') ? imagewebp($rotated, $path, 92) : false,
        'image/gif' => imagegif($rotated, $path),
        default => false,
    };

    imagedestroy($src);
    imagedestroy($rotated);

    if (!$ok) {
        throw new RuntimeException('Не удалось сохранить повернутое изображение');
    }
}

function nextSortOrderForSection(int $sectionId): int
{
    $st = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM photos WHERE section_id=:sid');
    $st->execute(['sid' => $sectionId]);
    return (int)$st->fetchColumn();
}

function nextUniqueCodeName(string $base): string
{
    $candidate = $base;
    $i = 1;
    while (true) {
        $st = db()->prepare('SELECT 1 FROM photos WHERE code_name=:c LIMIT 1');
        $st->execute(['c' => $candidate]);
        if (!$st->fetchColumn()) {
            return $candidate;
        }
        $candidate = $base . '_' . $i;
        $i++;
    }
}
