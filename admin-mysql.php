<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/db_gallery.php';

const MAX_UPLOAD_BYTES = 3 * 1024 * 1024;

$configPath = __DIR__ . '/deploy-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('deploy-config.php not found');
}
$config = require $configPath;
$tokenExpected = (string)($config['token'] ?? '');
$tokenIncoming = (string)($_REQUEST['token'] ?? '');
if ($tokenExpected === '' || !hash_equals($tokenExpected, $tokenIncoming)) {
    http_response_code(403);
    exit('Forbidden');
}

$message = '';
$errors = [];

try {
    db();
} catch (Throwable $e) {
    http_response_code(500);
    exit('DB error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_section') {
            $name = trim((string)($_POST['name'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 1000);
            if ($name === '') {
                throw new RuntimeException('Название раздела пустое');
            }
            sectionCreate($name, $sort);
            $message = 'Раздел создан';
        }

        if ($action === 'upload_photo') {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            $codeName = trim((string)($_POST['code_name'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 1000);
            $description = trim((string)($_POST['description'] ?? ''));
            $description = $description !== '' ? $description : null;

            if ($sectionId < 1) throw new RuntimeException('Выбери раздел');
            if ($codeName === '') throw new RuntimeException('Укажи код фото (например АВФ1)');
            if (!isset($_FILES['before'])) throw new RuntimeException('Файл "до" обязателен');

            $section = sectionById($sectionId);
            if (!$section) throw new RuntimeException('Раздел не найден');

            $photoId = photoCreate($sectionId, $codeName, $description, $sortOrder);

            $before = saveImageUpload($_FILES['before'], $codeName, 'before', $sectionId);
            photoFileUpsert($photoId, 'before', $before['path'], $before['mime'], $before['size']);

            if (isset($_FILES['after']) && (int)($_FILES['after']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $after = saveImageUpload($_FILES['after'], $codeName . 'р', 'after', $sectionId);
                photoFileUpsert($photoId, 'after', $after['path'], $after['mime'], $after['size']);
            }

            $message = 'Фото добавлено';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$sections = sectionsAll();
$activeSectionId = (int)($_GET['section_id'] ?? ($sections[0]['id'] ?? 0));
$photos = $activeSectionId > 0 ? photosBySection($activeSectionId) : [];

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function assetUrl(string $path): string { $f=__DIR__ . '/' . ltrim($path,'/'); $v=is_file($f)?(string)filemtime($f):(string)time(); return $path . '?v=' . rawurlencode($v); }

function saveImageUpload(array $file, string $baseName, string $kind, int $sectionId): array
{
    $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException("Ошибка загрузки ({$kind})");
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > MAX_UPLOAD_BYTES) throw new RuntimeException("Файл {$kind}: превышен лимит 3 МБ");

    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException("Файл {$kind}: некорректный источник");

    $mime = mime_content_type($tmp) ?: '';
    if (!isset($allowedMime[$mime])) throw new RuntimeException("Файл {$kind}: недопустимый mime {$mime}");

    $safeBase = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $baseName) ?? 'photo';
    $safeBase = trim($safeBase, '._-');
    if ($safeBase === '') $safeBase = 'photo';

    $ext = $allowedMime[$mime];
    $dir = __DIR__ . '/photos/section_' . $sectionId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Не удалось создать папку раздела');

    $final = uniqueName($dir, $safeBase, $ext);
    $dest = $dir . '/' . $final;
    if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('Не удалось сохранить файл');

    return [
        'path' => 'photos/section_' . $sectionId . '/' . $final,
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
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Админка (MySQL)</title>
  <link rel="stylesheet" href="<?= h(assetUrl('style.css')) ?>">
  <style>.wrap{max-width:1160px;margin:0 auto;padding:24px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;gap:12px;grid-template-columns:1fr 1fr}.full{grid-column:1/-1}.in{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px}.btn{border:0;background:#1f6feb;color:#fff;padding:8px 12px;border-radius:8px;cursor:pointer}.ok{background:#ecfdf3;padding:8px;border-radius:8px;margin-bottom:8px}.err{background:#fef2f2;padding:8px;border-radius:8px;margin-bottom:8px}.tbl{width:100%;border-collapse:collapse}.tbl td,.tbl th{padding:8px;border-bottom:1px solid #eee;vertical-align:top}</style>
</head>
<body><div class="wrap">
  <h1>Админка (MySQL, этап 1)</h1>
  <p><a href="./?">← в галерею</a></p>
  <?php if ($message!==''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
  <?php foreach($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>

  <div class="grid">
    <section class="card">
      <h3>Создать раздел</h3>
      <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>">
        <input type="hidden" name="action" value="create_section"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
        <p><input class="in" name="name" placeholder="Название раздела" required></p>
        <p><input class="in" type="number" name="sort_order" value="1000"></p>
        <button class="btn" type="submit">Создать</button>
      </form>
    </section>

    <section class="card">
      <h3>Добавить фото</h3>
      <form method="post" enctype="multipart/form-data" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>">
        <input type="hidden" name="action" value="upload_photo"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
        <p><select class="in" name="section_id" required><option value="">— Раздел —</option><?php foreach($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$activeSectionId?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></p>
        <p><input class="in" name="code_name" placeholder="Код фото, например АВФ1" required></p>
        <p><input class="in" type="number" name="sort_order" value="1000"></p>
        <p><textarea class="in" name="description" placeholder="Краткое описание (опционально)"></textarea></p>
        <p>Фото до: <input type="file" name="before" accept="image/jpeg,image/png,image/webp,image/gif" required></p>
        <p>Фото после (опционально): <input type="file" name="after" accept="image/jpeg,image/png,image/webp,image/gif"></p>
        <button class="btn" type="submit">Загрузить</button>
      </form>
    </section>

    <section class="card full">
      <h3>Разделы</h3>
      <table class="tbl"><tr><th>ID</th><th>Название</th><th>Порядок</th><th>Фото</th></tr>
        <?php foreach($sections as $s): ?>
          <tr><td><?= (int)$s['id'] ?></td><td><a href="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$s['id'] ?>"><?= h((string)$s['name']) ?></a></td><td><?= (int)$s['sort_order'] ?></td><td><?= (int)$s['photos_count'] ?></td></tr>
        <?php endforeach; ?>
      </table>
    </section>

    <section class="card full">
      <h3>Фото раздела</h3>
      <table class="tbl"><tr><th>ID</th><th>Код</th><th>Превью</th><th>Описание</th><th>Порядок</th></tr>
        <?php foreach($photos as $p): ?>
          <tr>
            <td><?= (int)$p['id'] ?></td>
            <td><?= h((string)$p['code_name']) ?></td>
            <td><?php if (!empty($p['before_path'])): ?><img src="<?= h((string)$p['before_path']) ?>" alt="" style="width:90px;height:60px;object-fit:cover;border:1px solid #e5e7eb;border-radius:6px"><?php endif; ?></td>
            <td><?= h((string)($p['description'] ?? '')) ?></td>
            <td><?= (int)$p['sort_order'] ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </section>
  </div>
</div></body></html>
