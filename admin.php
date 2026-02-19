<?php

declare(strict_types=1);

const MAX_UPLOAD_BYTES = 3 * 1024 * 1024; // 3 MB

$configPath = __DIR__ . '/deploy-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "deploy-config.php not found. Create it from deploy-config.php.example\n";
    exit;
}

/** @var array<string,mixed> $config */
$config = require $configPath;
$tokenExpected = (string)($config['token'] ?? '');

$tokenIncoming = (string)($_REQUEST['token'] ?? '');
if ($tokenExpected === '' || !hash_equals($tokenExpected, $tokenIncoming)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: invalid token\n";
    exit;
}

$photosDir = __DIR__ . '/photos';
if (!is_dir($photosDir)) {
    mkdir($photosDir, 0775, true);
}

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_category') {
        $rawName = trim((string)($_POST['category_name'] ?? ''));
        $safeName = sanitizeCategoryName($rawName);

        if ($safeName === '') {
            $errors[] = 'Введите корректное имя папки.';
        } else {
            $dir = $photosDir . '/' . $safeName;
            if (is_dir($dir)) {
                $message = 'Папка уже существует.';
            } elseif (mkdir($dir, 0775, true)) {
                $message = 'Папка создана: ' . $safeName;
            } else {
                $errors[] = 'Не удалось создать папку.';
            }
        }
    }

    if ($action === 'upload') {
        $selectedCategory = sanitizeCategoryName((string)($_POST['category'] ?? ''));
        if ($selectedCategory === '') {
            $errors[] = 'Выберите папку для загрузки.';
        } else {
            $categoryDir = $photosDir . '/' . $selectedCategory;
            if (!is_dir($categoryDir)) {
                $errors[] = 'Выбранная папка не существует.';
            } else {
                if (!isset($_FILES['photos'])) {
                    $errors[] = 'Файлы не переданы.';
                } else {
                    $result = handleUploads($_FILES['photos'], $categoryDir);
                    $errors = array_merge($errors, $result['errors']);
                    if ($result['ok'] > 0) {
                        $message = 'Загружено файлов: ' . $result['ok'];
                    }
                }
            }
        }
    }
}

$categories = listCategories($photosDir);
$tokenForUrl = urlencode($tokenIncoming);

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Админка галереи</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-wrap { max-width: 980px; margin: 0 auto; padding: 24px; }
        .admin-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); }
        .admin-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
        .admin-card h2 { margin-top: 0; font-size: 18px; }
        .admin-input, .admin-select { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 10px; font: inherit; }
        .admin-btn { border: 0; background: #1f6feb; color: #fff; padding: 10px 14px; border-radius: 10px; font-weight: 600; cursor: pointer; }
        .admin-help { color: #6b7280; font-size: 13px; }
        .alert-ok { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; }
        .alert-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; }
        .row { display: grid; gap: 8px; margin-bottom: 10px; }
        .category-list { margin: 0; padding-left: 18px; }
        .top-links { margin-bottom: 12px; font-size: 14px; }
    </style>
</head>
<body>
<div class="admin-wrap">
    <h1>Админка загрузки</h1>
    <div class="top-links">
        <a href="./">← В галерею</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert-ok"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <div class="alert-err"><?= htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <div class="admin-grid">
        <section class="admin-card">
            <h2>Создать папку (категорию)</h2>
            <form method="post" action="?token=<?= $tokenForUrl ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($tokenIncoming, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_category">
                <div class="row">
                    <label for="category_name">Имя папки</label>
                    <input class="admin-input" id="category_name" name="category_name" required placeholder="Например: weddings_2026">
                </div>
                <button class="admin-btn" type="submit">Создать</button>
            </form>
            <p class="admin-help">Разрешены буквы/цифры/пробел/._- (остальное отфильтруется).</p>
        </section>

        <section class="admin-card">
            <h2>Загрузка фотографий</h2>
            <form method="post" action="?token=<?= $tokenForUrl ?>" enctype="multipart/form-data">
                <input type="hidden" name="token" value="<?= htmlspecialchars($tokenIncoming, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="upload">

                <div class="row">
                    <label for="category">Папка</label>
                    <select class="admin-select" id="category" name="category" required>
                        <option value="">— Выберите папку —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row">
                    <label for="photos">Фотографии</label>
                    <input id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
                </div>

                <button class="admin-btn" type="submit">Загрузить</button>
            </form>
            <p class="admin-help">Ограничения: только JPG/PNG/WEBP/GIF, максимум 3 МБ на файл.</p>
        </section>

        <section class="admin-card">
            <h2>Текущие категории</h2>
            <?php if ($categories === []): ?>
                <p class="admin-help">Пока нет категорий.</p>
            <?php else: ?>
                <ul class="category-list">
                    <?php foreach ($categories as $cat): ?>
                        <li><?= htmlspecialchars($cat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>
<?php

function sanitizeCategoryName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name) ?? '';
    $name = preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', $name) ?? '';
    $name = trim($name, ". \t\n\r\0\x0B");

    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }

    return $name;
}

/**
 * @param array<string,mixed> $files
 * @return array{ok:int,errors:string[]}
 */
function handleUploads(array $files, string $targetDir): array
{
    $allowedMime = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    $ok = 0;
    $errors = [];

    $names = $files['name'] ?? [];
    $tmpNames = $files['tmp_name'] ?? [];
    $sizes = $files['size'] ?? [];
    $errs = $files['error'] ?? [];

    if (!is_array($names)) {
        $names = [$names];
        $tmpNames = [$tmpNames];
        $sizes = [$sizes];
        $errs = [$errs];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    foreach ($names as $i => $originalName) {
        $errCode = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            $errors[] = "Файл {$originalName}: ошибка загрузки ({$errCode}).";
            continue;
        }

        $size = (int)($sizes[$i] ?? 0);
        if ($size < 1 || $size > MAX_UPLOAD_BYTES) {
            $errors[] = "Файл {$originalName}: превышен лимит 3 МБ.";
            continue;
        }

        $tmp = (string)($tmpNames[$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $errors[] = "Файл {$originalName}: некорректный источник загрузки.";
            continue;
        }

        $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
        if (!in_array($mime, $allowedMime, true)) {
            $errors[] = "Файл {$originalName}: недопустимый тип ({$mime}).";
            continue;
        }

        $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = "Файл {$originalName}: недопустимое расширение.";
            continue;
        }

        $base = pathinfo((string)$originalName, PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $base) ?? 'photo';
        $safeBase = trim($safeBase, '._-');
        if ($safeBase === '') {
            $safeBase = 'photo';
        }

        $finalName = uniqueFileName($targetDir, $safeBase, $ext);
        $dest = $targetDir . '/' . $finalName;

        if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = "Файл {$originalName}: не удалось сохранить.";
            continue;
        }

        @chmod($dest, 0664);
        $ok++;
    }

    if ($finfo) {
        finfo_close($finfo);
    }

    return ['ok' => $ok, 'errors' => $errors];
}

function uniqueFileName(string $dir, string $base, string $ext): string
{
    $candidate = $base . '.' . $ext;
    $n = 1;
    while (file_exists($dir . '/' . $candidate)) {
        $candidate = $base . '_' . $n . '.' . $ext;
        $n++;
    }
    return $candidate;
}

/**
 * @return string[]
 */
function listCategories(string $photosDir): array
{
    $out = [];
    $items = @scandir($photosDir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (is_dir($photosDir . '/' . $item)) {
            $out[] = $item;
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
