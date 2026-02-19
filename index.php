<?php

declare(strict_types=1);

const THUMB_WIDTH = 360;
const THUMB_HEIGHT = 240;

$baseDir = __DIR__;
$photosDir = $baseDir . '/photos';
$thumbsDir = $baseDir . '/thumbs';
$dataDir = $baseDir . '/data';
$lastIndexedFile = $dataDir . '/last_indexed.txt';

ensureDirectories([$photosDir, $thumbsDir, $dataDir]);

$action = $_GET['action'] ?? null;
if ($action === 'image') {
    serveImage($photosDir);
}

$lastIndexedTimestamp = readLastIndexedTimestamp($lastIndexedFile);
$maxTimestamp = $lastIndexedTimestamp;

$categories = scanCategories($photosDir);

foreach ($categories as $categoryName => &$images) {
    $categoryThumbDir = $thumbsDir . '/' . $categoryName;
    if (!is_dir($categoryThumbDir)) {
        mkdir($categoryThumbDir, 0775, true);
    }

    foreach ($images as &$image) {
        $sourcePath = $image['abs_path'];
        $sourceMtime = (int) filemtime($sourcePath);
        $maxTimestamp = max($maxTimestamp, $sourceMtime);

        $thumbExt = 'jpg';
        $thumbName = pathinfo($image['filename'], PATHINFO_FILENAME) . '.jpg';
        $thumbAbsPath = $categoryThumbDir . '/' . $thumbName;
        $thumbWebPath = 'thumbs/' . rawurlencode($categoryName) . '/' . rawurlencode($thumbName);

        $needsThumb = !file_exists($thumbAbsPath)
            || filemtime($thumbAbsPath) < $sourceMtime
            || $sourceMtime > $lastIndexedTimestamp;

        if ($needsThumb) {
            createThumbnail($sourcePath, $thumbAbsPath, THUMB_WIDTH, THUMB_HEIGHT);
        }

        $image['thumb_path'] = $thumbWebPath;
        $image['full_path'] = '?action=image&category=' . rawurlencode($categoryName) . '&file=' . rawurlencode($image['filename']);
        $image['title'] = titleFromFilename($image['filename']);
        $image['mtime'] = $sourceMtime;
    }

    usort($images, static function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'];
    });
}
unset($images, $image);

if ($maxTimestamp > $lastIndexedTimestamp) {
    file_put_contents($lastIndexedFile, (string)$maxTimestamp);
}

$selectedCategory = isset($_GET['category']) ? trim((string)$_GET['category']) : null;
if ($selectedCategory !== null && $selectedCategory !== '' && !isset($categories[$selectedCategory])) {
    http_response_code(404);
    $selectedCategory = null;
}

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Фотогалерея</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app">
    <header class="topbar">
        <h1>Фотогалерея</h1>
        <p class="subtitle">Категории и превью обновляются автоматически при каждом открытии страницы</p>
    </header>

    <?php if ($selectedCategory === null): ?>
        <section class="panel">
            <h2>Категории</h2>
            <?php if (count($categories) === 0): ?>
                <p class="empty">Пока нет папок с фото. Загрузите файлы в <code>photos/&lt;категория&gt;/</code> через FTP.</p>
            <?php else: ?>
                <div class="categories-grid">
                    <?php foreach ($categories as $categoryName => $images): ?>
                        <a class="category-card" href="?category=<?= urlencode($categoryName) ?>">
                            <span class="category-title"><?= htmlspecialchars($categoryName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span class="category-count"><?= count($images) ?> фото</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="panel-header">
                <h2><?= htmlspecialchars($selectedCategory, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                <a class="btn" href="./">← Все категории</a>
            </div>

            <?php $images = $categories[$selectedCategory] ?? []; ?>
            <?php if (count($images) === 0): ?>
                <p class="empty">В этой категории пока нет изображений.</p>
            <?php else: ?>
                <div class="gallery-grid">
                    <?php foreach ($images as $img): ?>
                        <button
                            class="thumb-card js-thumb"
                            data-full="<?= htmlspecialchars($img['full_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            data-title="<?= htmlspecialchars($img['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            type="button"
                        >
                            <img
                                src="<?= htmlspecialchars($img['thumb_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($img['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                loading="lazy"
                            >
                            <span class="thumb-title"><?= htmlspecialchars($img['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <footer class="footer">
        <small>Последняя индексация: <?= file_exists($lastIndexedFile) ? date('Y-m-d H:i:s', (int)trim((string)file_get_contents($lastIndexedFile))) : '—' ?></small>
    </footer>
</div>

<div class="lightbox" id="lightbox" hidden>
    <div class="lightbox-backdrop js-close"></div>
    <div class="lightbox-content">
        <button class="lightbox-close js-close" type="button" aria-label="Закрыть">×</button>
        <img id="lightboxImage" src="" alt="">
        <div id="lightboxTitle" class="lightbox-title"></div>
    </div>
</div>

<script src="app.js" defer></script>
</body>
</html>
<?php

function serveImage(string $photosDir): never
{
    $category = isset($_GET['category']) ? basename((string)$_GET['category']) : '';
    $file = isset($_GET['file']) ? basename((string)$_GET['file']) : '';

    if ($category === '' || $file === '') {
        http_response_code(404);
        exit;
    }

    $path = $photosDir . '/' . $category . '/' . $file;
    if (!is_file($path) || !isImage($path)) {
        http_response_code(404);
        exit;
    }

    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Disposition: inline; filename="image"');
    header('Cache-Control: private, max-age=60');

    readfile($path);
    exit;
}

function titleFromFilename(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['_', '-'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $name = trim($name);

    if ($name === '') {
        return $filename;
    }

    if (function_exists('mb_convert_case')) {
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    return ucwords(strtolower($name));
}

function ensureDirectories(array $dirs): void
{
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}

function readLastIndexedTimestamp(string $path): int
{
    if (!file_exists($path)) {
        return 0;
    }

    $value = trim((string) file_get_contents($path));
    return ctype_digit($value) ? (int)$value : 0;
}

function scanCategories(string $photosDir): array
{
    $result = [];

    $entries = @scandir($photosDir) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $categoryPath = $photosDir . '/' . $entry;
        if (!is_dir($categoryPath)) {
            continue;
        }

        $images = [];
        $files = @scandir($categoryPath) ?: [];
        foreach ($files as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $absPath = $categoryPath . '/' . $filename;
            if (!is_file($absPath) || !isImage($absPath)) {
                continue;
            }

            $images[] = [
                'filename' => $filename,
                'abs_path' => $absPath,
            ];
        }

        $result[$entry] = $images;
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function isImage(string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

function createThumbnail(string $srcPath, string $thumbPath, int $targetWidth, int $targetHeight): void
{
    if (extension_loaded('imagick')) {
        createThumbnailWithImagick($srcPath, $thumbPath, $targetWidth, $targetHeight);
        return;
    }

    createThumbnailWithGd($srcPath, $thumbPath, $targetWidth, $targetHeight);
}

function createThumbnailWithImagick(string $srcPath, string $thumbPath, int $targetWidth, int $targetHeight): void
{
    $imagick = new Imagick($srcPath);
    $imagick->setIteratorIndex(0);
    $imagick->setImageOrientation(Imagick::ORIENTATION_UNDEFINED);
    $imagick->thumbnailImage($targetWidth, $targetHeight, true, true);
    $imagick->setImageFormat('jpeg');
    $imagick->setImageCompressionQuality(82);
    $imagick->writeImage($thumbPath);
    $imagick->clear();
    $imagick->destroy();
}

function createThumbnailWithGd(string $srcPath, string $thumbPath, int $targetWidth, int $targetHeight): void
{
    [$srcW, $srcH, $type] = @getimagesize($srcPath) ?: [0, 0, 0];
    if ($srcW < 1 || $srcH < 1) {
        return;
    }

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
        IMAGETYPE_PNG => @imagecreatefrompng($srcPath),
        IMAGETYPE_GIF => @imagecreatefromgif($srcPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : null,
        default => null,
    };

    if (!$src) {
        return;
    }

    $scale = min($targetWidth / $srcW, $targetHeight / $srcH);
    $dstW = max(1, (int) floor($srcW * $scale));
    $dstH = max(1, (int) floor($srcH * $scale));

    $dst = imagecreatetruecolor($dstW, $dstH);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    imagejpeg($dst, $thumbPath, 82);

    imagedestroy($src);
    imagedestroy($dst);
}
