<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/db_gallery.php';

$action = (string)($_GET['action'] ?? '');
if ($action === 'image') {
    serveImage();
}

$viewerToken = trim((string)($_GET['viewer'] ?? ''));
$viewer = $viewerToken !== '' ? commenterByToken($viewerToken) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'add_comment') {
    $token = trim((string)($_POST['viewer'] ?? ''));
    $photoId = (int)($_POST['photo_id'] ?? 0);
    $text = trim((string)($_POST['comment_text'] ?? ''));

    if ($token !== '' && $photoId > 0 && $text !== '') {
        $u = commenterByToken($token);
        if ($u) {
            commentAdd($photoId, (int)$u['id'], mb_substr($text, 0, 1000));
        }
    }

    $redirect = './index-mysql.php?photo_id=' . $photoId;
    if ($token !== '') {
        $redirect .= '&viewer=' . urlencode($token);
    }
    header('Location: ' . $redirect);
    exit;
}

$sections = sectionsAll();
$activeSectionId = (int)($_GET['section_id'] ?? 0);
$activePhotoId = (int)($_GET['photo_id'] ?? 0);

if ($activePhotoId > 0) {
    $photo = photoById($activePhotoId);
    if (!$photo) {
        http_response_code(404);
        $photo = null;
    }
    $comments = $photo ? commentsByPhoto($activePhotoId) : [];
} else {
    $photo = null;
    $comments = [];
}

$photos = $activeSectionId > 0 ? photosBySection($activeSectionId) : [];

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function assetUrl(string $path): string { $f=__DIR__ . '/' . ltrim($path,'/'); $v=is_file($f)?(string)filemtime($f):(string)time(); return $path . '?v=' . rawurlencode($v); }

function serveImage(): never
{
    $fileId = (int)($_GET['file_id'] ?? 0);
    if ($fileId < 1) {
        http_response_code(404);
        exit;
    }

    $f = photoFileById($fileId);
    if (!$f) {
        http_response_code(404);
        exit;
    }

    $abs = __DIR__ . '/' . ltrim((string)$f['file_path'], '/');
    if (!is_file($abs)) {
        http_response_code(404);
        exit;
    }

    $kind = (string)$f['kind'];
    if ($kind !== 'after') {
        header('Content-Type: ' . ((string)$f['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . (string)filesize($abs));
        header('Cache-Control: private, max-age=60');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($abs);
        exit;
    }

    outputWatermarked($abs, (string)$f['mime_type']);
}

function outputWatermarked(string $path, string $mime): never
{
    $text = 'photo.andr33v.ru';

    if (extension_loaded('imagick')) {
        $im = new Imagick($path);
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('rgba(255,255,255,0.22)'));
        $draw->setFontSize(max(18, (int)($im->getImageWidth() / 24)));
        $draw->setGravity(Imagick::GRAVITY_SOUTHEAST);
        $im->annotateImage($draw, 20, 24, -15, $text);
        header('Content-Type: ' . ($mime !== '' ? $mime : 'image/jpeg'));
        $im->setImageCompressionQuality(88);
        echo $im;
        $im->clear();
        $im->destroy();
        exit;
    }

    [$w, $h, $type] = @getimagesize($path) ?: [0,0,0];
    if ($w < 1 || $h < 1) {
        readfile($path);
        exit;
    }

    $img = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_PNG => imagecreatefrompng($path),
        IMAGETYPE_GIF => imagecreatefromgif($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null,
        default => null,
    };

    if (!$img) {
        readfile($path);
        exit;
    }

    $font = 5;
    $color = imagecolorallocatealpha($img, 255, 255, 255, 90);
    $x = max(5, $w - (imagefontwidth($font) * strlen($text)) - 15);
    $y = max(5, $h - imagefontheight($font) - 12);
    imagestring($img, $font, $x, $y, $text, $color);

    header('Content-Type: image/jpeg');
    imagejpeg($img, null, 88);
    imagedestroy($img);
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Фотогалерея (MySQL)</title>
  <link rel="icon" type="image/svg+xml" href="<?= h(assetUrl('favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= h(assetUrl('style.css')) ?>">
  <style>.note{color:#6b7280;font-size:13px}.page{display:grid;gap:16px;grid-template-columns:300px 1fr}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px}.sec a{display:block;padding:8px 10px;border-radius:8px;text-decoration:none;color:#111}.sec a.active{background:#eef4ff;color:#1f6feb}.cards{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}.card{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff}.card img{width:100%;height:130px;object-fit:cover}.cap{padding:8px;font-size:13px}.detail img{max-width:100%;border-radius:10px;border:1px solid #e5e7eb}.two{display:grid;gap:10px;grid-template-columns:1fr 1fr}.cmt{border-top:1px solid #eee;padding:8px 0}.muted{color:#6b7280;font-size:13px}</style>
</head>
<body>
<div class="app">
  <header class="topbar"><h1>Фотогалерея</h1><p class="subtitle">Простая галерея, которая управляется через файловый менеджер.</p></header>
  <div class="page">
    <aside class="panel sec">
      <h3>Разделы</h3>
      <?php foreach($sections as $s): ?>
        <a class="<?= (int)$s['id']===$activeSectionId?'active':'' ?>" href="?section_id=<?= (int)$s['id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>"><?= h((string)$s['name']) ?> <span class="muted">(<?= (int)$s['photos_count'] ?>)</span></a>
      <?php endforeach; ?>
      <p class="note" style="margin-top:12px"><?= $viewer ? 'Вы авторизованы для комментариев: ' . h((string)$viewer['display_name']) : 'Режим просмотра' ?></p>
      <p><a href="admin-mysql.php?token=<?= h(urlencode((string)($_GET['token'] ?? ''))) ?>">Админка MySQL</a></p>
    </aside>
    <main>
      <?php if ($activePhotoId > 0 && $photo): ?>
        <section class="panel detail">
          <p><a href="?section_id=<?= (int)$photo['section_id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>">← к разделу</a></p>
          <h2><?= h((string)$photo['code_name']) ?></h2>
          <p class="muted"><?= h((string)($photo['description'] ?? '')) ?></p>
          <div class="two">
            <?php if (!empty($photo['before_file_id'])): ?><div><div class="muted">До обработки</div><img src="?action=image&file_id=<?= (int)$photo['before_file_id'] ?>" alt=""></div><?php endif; ?>
            <?php if (!empty($photo['after_file_id'])): ?><div><div class="muted">После обработки (watermark)</div><img src="?action=image&file_id=<?= (int)$photo['after_file_id'] ?>" alt=""></div><?php endif; ?>
          </div>

          <h3 style="margin-top:16px">Комментарии</h3>
          <?php if ($viewer): ?>
            <form method="post" action="?photo_id=<?= (int)$photo['id'] ?>&viewer=<?= urlencode($viewerToken) ?>">
              <input type="hidden" name="action" value="add_comment">
              <input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>">
              <input type="hidden" name="viewer" value="<?= h($viewerToken) ?>">
              <textarea name="comment_text" required style="width:100%;min-height:80px;border:1px solid #d1d5db;border-radius:8px;padding:8px"></textarea>
              <p><button class="btn" type="submit">Отправить</button></p>
            </form>
          <?php else: ?>
            <p class="muted">Комментарии может оставлять только пользователь с персональной ссылкой.</p>
          <?php endif; ?>

          <?php foreach($comments as $c): ?>
            <div class="cmt"><strong><?= h((string)($c['display_name'] ?? 'Пользователь')) ?></strong> <span class="muted">· <?= h((string)$c['created_at']) ?></span><br><?= nl2br(h((string)$c['comment_text'])) ?></div>
          <?php endforeach; ?>
        </section>
      <?php else: ?>
        <section class="panel">
          <h3>Фотографии</h3>
          <?php if ($activeSectionId < 1): ?>
            <p class="muted">Выберите раздел слева.</p>
          <?php elseif ($photos === []): ?>
            <p class="muted">В разделе пока нет фотографий.</p>
          <?php else: ?>
            <div class="cards">
              <?php foreach($photos as $p): ?>
                <a class="card" href="?photo_id=<?= (int)$p['id'] ?>&section_id=<?= (int)$activeSectionId ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>" style="text-decoration:none;color:inherit">
                  <?php if (!empty($p['before_file_id'])): ?><img src="?action=image&file_id=<?= (int)$p['before_file_id'] ?>" alt=""><?php endif; ?>
                  <div class="cap"><strong><?= h((string)$p['code_name']) ?></strong><br><span class="muted"><?= h((string)($p['description'] ?? '')) ?></span></div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>
