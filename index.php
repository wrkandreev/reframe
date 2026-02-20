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
            commentAdd($photoId, (int)$u['id'], limitText($text, 1000));
        }
    }

    $redirect = './?photo_id=' . $photoId;
    if ($token !== '') {
        $redirect .= '&viewer=' . urlencode($token);
    }
    header('Location: ' . $redirect);
    exit;
}

$sections = sectionsAll();
$activeSectionId = (int)($_GET['section_id'] ?? 0);
$activePhotoId = (int)($_GET['photo_id'] ?? 0);
$welcomeText = settingGet('welcome_text', 'Добро пожаловать в галерею. Выберите раздел слева, чтобы посмотреть фотографии.');

$photo = $activePhotoId > 0 ? photoById($activePhotoId) : null;
$comments = $photo ? commentsByPhoto($activePhotoId) : [];
$photos = $activeSectionId > 0 ? photosBySection($activeSectionId) : [];
$isHomePage = $activeSectionId < 1 && $activePhotoId < 1;

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function assetUrl(string $path): string { $f=__DIR__ . '/' . ltrim($path,'/'); $v=is_file($f)?(string)filemtime($f):(string)time(); return $path . '?v=' . rawurlencode($v); }
function limitText(string $text, int $len): string { return function_exists('mb_substr') ? mb_substr($text, 0, $len) : substr($text, 0, $len); }

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

    if ((string)$f['kind'] !== 'after') {
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
    $img = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_GIF => @imagecreatefromgif($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
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
  <title>Фотогалерея</title>
  <link rel="icon" type="image/svg+xml" href="<?= h(assetUrl('favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= h(assetUrl('style.css')) ?>">
  <style>
    .note{color:#6b7280;font-size:13px}
    .page{display:grid;gap:16px;grid-template-columns:300px minmax(0,1fr)}
    .panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px}
    .sec a{display:block;padding:8px 10px;border-radius:8px;text-decoration:none;color:#111}
    .sec a.active{background:#eef4ff;color:#1f6feb}
    .cards{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
    .card{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff}
    .card img{width:100%;height:130px;object-fit:cover}
    .cap{padding:8px;font-size:13px}
    .detail img{max-width:100%;border-radius:10px;border:1px solid #e5e7eb}
    .stack{display:grid;gap:12px;grid-template-columns:1fr}
    .cmt{border-top:1px solid #eee;padding:8px 0}
    .muted{color:#6b7280;font-size:13px}
    .img-box{position:relative;display:block;background:#f3f4f6}
    .img-box::before{content:'';position:absolute;left:50%;top:50%;width:28px;height:28px;margin:-14px 0 0 -14px;border:3px solid #cbd5e1;border-top-color:#1f6feb;border-radius:50%;opacity:0;pointer-events:none}
    .img-box.is-loading::before{opacity:1;animation:spin .75s linear infinite}
    .img-box.is-loading img{opacity:.38}
    .img-box img{transition:opacity .2s ease}
    .thumb-img-box{height:130px}
    @keyframes spin{to{transform:rotate(360deg)}}
    .sidebar-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .sidebar-head h3{margin:0}
    .sidebar-toggle{display:none}
    .sidebar-toggle,.sidebar-close{border:1px solid #d1d5db;background:#fff;color:#1f2937;border-radius:10px;padding:8px 12px;font-size:14px;font-weight:600;cursor:pointer}
    .sidebar-close{display:none;width:34px;height:34px;padding:0;line-height:1;font-size:24px}
    .sidebar-backdrop{display:none}

    @media (max-width:900px){
      .topbar{display:flex;align-items:center;justify-content:space-between;gap:10px}
      .topbar h1{margin:0;font-size:24px}
      .page{grid-template-columns:1fr}

      .is-inner .sidebar-toggle{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}
      .is-inner .sidebar{position:fixed;top:0;left:0;z-index:40;width:min(86vw,320px);height:100dvh;overflow-y:auto;border-radius:0 12px 12px 0;transform:translateX(-105%);transition:transform .2s ease;padding-top:18px}
      .is-inner.sidebar-open .sidebar{transform:translateX(0)}
      .is-inner .sidebar-close{display:inline-flex;align-items:center;justify-content:center}
      .is-inner .sidebar-backdrop{display:block;position:fixed;inset:0;z-index:30;border:0;padding:0;background:rgba(17,24,39,.45);opacity:0;pointer-events:none;transition:opacity .2s ease}
      .is-inner.sidebar-open .sidebar-backdrop{opacity:1;pointer-events:auto}
    }

    @media (max-width:560px){
      .app{padding:14px}
      .topbar h1{font-size:22px}
    }
  </style>
</head>
<body class="<?= $isHomePage ? 'is-home' : 'is-inner' ?>">
<div class="app">
  <header class="topbar">
    <h1>Фотогалерея</h1>
    <?php if (!$isHomePage): ?>
      <button class="sidebar-toggle js-sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false">Разделы</button>
    <?php endif; ?>
  </header>
  <?php if (!$isHomePage): ?>
    <button class="sidebar-backdrop js-sidebar-close" type="button" aria-label="Закрыть меню разделов"></button>
  <?php endif; ?>
  <div class="page">
    <aside id="sidebar" class="panel sec sidebar">
      <div class="sidebar-head">
        <h3>Разделы</h3>
        <?php if (!$isHomePage): ?>
          <button class="sidebar-close js-sidebar-close" type="button" aria-label="Закрыть меню разделов">×</button>
        <?php endif; ?>
      </div>
      <?php foreach($sections as $s): ?>
        <a class="<?= (int)$s['id']===$activeSectionId?'active':'' ?>" href="?section_id=<?= (int)$s['id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>"><?= h((string)$s['name']) ?> <span class="muted">(<?= (int)$s['photos_count'] ?>)</span></a>
      <?php endforeach; ?>
      <p class="note" style="margin-top:12px"><?= $viewer ? 'Вы авторизованы для комментариев: ' . h((string)$viewer['display_name']) : 'Режим просмотра' ?></p>
    </aside>
    <main>
      <?php if ($activePhotoId > 0 && $photo): ?>
        <section class="panel detail">
          <p><a href="?section_id=<?= (int)$photo['section_id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>">← к разделу</a></p>
          <h2><?= h((string)$photo['code_name']) ?></h2>
          <p class="muted"><?= h((string)($photo['description'] ?? '')) ?></p>
          <div class="stack">
            <?php if (!empty($photo['before_file_id'])): ?><div><div class="muted">До обработки</div><div class="img-box is-loading"><img class="js-public-image" src="?action=image&file_id=<?= (int)$photo['before_file_id'] ?>" alt="" decoding="async" fetchpriority="high"></div></div><?php endif; ?>
            <?php if (!empty($photo['after_file_id'])): ?><div><div class="muted">После обработки (watermark)</div><div class="img-box is-loading"><img class="js-public-image" src="?action=image&file_id=<?= (int)$photo['after_file_id'] ?>" alt="" decoding="async" fetchpriority="high"></div></div><?php endif; ?>
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
            <p class="muted"><?= nl2br(h($welcomeText)) ?></p>
          <?php elseif ($photos === []): ?>
            <p class="muted">В разделе пока нет фотографий.</p>
          <?php else: ?>
            <div class="cards">
              <?php foreach($photos as $p): ?>
                <a class="card" href="?photo_id=<?= (int)$p['id'] ?>&section_id=<?= (int)$activeSectionId ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>" style="text-decoration:none;color:inherit;position:relative">
                  <?php if (!empty($p['before_file_id'])): ?><div class="img-box thumb-img-box is-loading"><img class="js-public-image" src="?action=image&file_id=<?= (int)$p['before_file_id'] ?>" alt="" loading="lazy" decoding="async" fetchpriority="low"></div><?php endif; ?>
                  <?php if (!empty($p['after_file_id'])): ?><span title="Есть обработанная версия" style="position:absolute;top:8px;right:8px;background:rgba(31,111,235,.92);color:#fff;font-size:11px;line-height:1;padding:6px 7px;border-radius:999px">AI</span><?php endif; ?>
                  <div class="cap"><strong><?= h((string)$p['code_name']) ?></strong><br><span class="muted"><?= h((string)($p['description'] ?? '')) ?></span></div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
  </div>

  <footer class="footer">
    <small class="footer-author">by <a href="https://t.me/andr33vru" target="_blank" rel="noopener noreferrer">andr33vru</a></small>
  </footer>
</div>
<script>
(() => {
  document.querySelectorAll('img').forEach((img) => {
    img.addEventListener('contextmenu', (e) => e.preventDefault());
    img.addEventListener('dragstart', (e) => e.preventDefault());
  });

  document.querySelectorAll('.js-public-image').forEach((img) => {
    const box = img.closest('.img-box');
    if (!box) {
      return;
    }

    const clearLoading = () => {
      box.classList.remove('is-loading');
    };

    img.addEventListener('load', clearLoading, { once: true });
    img.addEventListener('error', clearLoading, { once: true });

    if (img.complete) {
      clearLoading();
    }
  });
})();

(() => {
  const body = document.body;
  if (!body.classList.contains('is-inner')) {
    return;
  }

  const toggle = document.querySelector('.js-sidebar-toggle');
  const sidebar = document.getElementById('sidebar');
  const closers = document.querySelectorAll('.js-sidebar-close');
  if (!toggle || !sidebar || closers.length === 0) {
    return;
  }

  const closeSidebar = () => {
    body.classList.remove('sidebar-open');
    toggle.setAttribute('aria-expanded', 'false');
  };

  const openSidebar = () => {
    body.classList.add('sidebar-open');
    toggle.setAttribute('aria-expanded', 'true');
  };

  toggle.addEventListener('click', () => {
    if (body.classList.contains('sidebar-open')) {
      closeSidebar();
      return;
    }

    openSidebar();
  });

  closers.forEach((btn) => {
    btn.addEventListener('click', closeSidebar);
  });

  sidebar.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', closeSidebar);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeSidebar();
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 900) {
      closeSidebar();
    }
  });

  const isPhoneViewport = () => window.matchMedia('(max-width: 768px)').matches;
  let touchStartX = 0;
  let touchStartY = 0;
  let touchStartTime = 0;
  let trackSwipe = false;
  let startFromEdge = false;
  let startInsideSidebar = false;

  document.addEventListener('touchstart', (e) => {
    if (!isPhoneViewport() || e.touches.length !== 1) {
      trackSwipe = false;
      return;
    }

    const touch = e.touches[0];
    touchStartX = touch.clientX;
    touchStartY = touch.clientY;
    touchStartTime = Date.now();
    startFromEdge = touchStartX <= 28;
    startInsideSidebar = body.classList.contains('sidebar-open') && sidebar.contains(e.target);
    trackSwipe = startFromEdge || startInsideSidebar;
  }, { passive: true });

  document.addEventListener('touchmove', (e) => {
    if (!trackSwipe || !isPhoneViewport() || e.touches.length !== 1) {
      return;
    }

    const touch = e.touches[0];
    const deltaX = touch.clientX - touchStartX;
    const deltaY = Math.abs(touch.clientY - touchStartY);
    if (deltaY > 42 && Math.abs(deltaX) < deltaY) {
      trackSwipe = false;
    }
  }, { passive: true });

  document.addEventListener('touchend', (e) => {
    if (!trackSwipe || !isPhoneViewport()) {
      return;
    }

    trackSwipe = false;
    const touch = e.changedTouches[0];
    if (!touch) {
      return;
    }

    const deltaX = touch.clientX - touchStartX;
    const deltaY = Math.abs(touch.clientY - touchStartY);
    const elapsed = Date.now() - touchStartTime;
    if (deltaY > 70 || elapsed > 700) {
      return;
    }

    if (!body.classList.contains('sidebar-open') && startFromEdge && deltaX > 70) {
      openSidebar();
      return;
    }

    if (body.classList.contains('sidebar-open') && startInsideSidebar && deltaX < -70) {
      closeSidebar();
    }
  }, { passive: true });
})();
</script>
</body>
</html>
