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
    $sectionId = (int)($_POST['section_id'] ?? 0);
    $topicId = (int)($_POST['topic_id'] ?? 0);
    $text = trim((string)($_POST['comment_text'] ?? ''));

    if ($token !== '' && $photoId > 0 && $text !== '') {
        $u = commenterByToken($token);
        if ($u) {
            commentAdd($photoId, (int)$u['id'], limitText($text, 1000));
        }
    }

    $redirect = './?photo_id=' . $photoId;
    if ($sectionId > 0) {
        $redirect .= '&section_id=' . $sectionId;
    }
    if ($topicId > 0) {
        $redirect .= '&topic_id=' . $topicId;
    }
    if ($token !== '') {
        $redirect .= '&viewer=' . urlencode($token);
    }
    header('Location: ' . $redirect);
    exit;
}

$sections = sectionsAll();
$activeSectionId = (int)($_GET['section_id'] ?? 0);
$activePhotoId = (int)($_GET['photo_id'] ?? 0);
$activeTopicId = (int)($_GET['topic_id'] ?? 0);
$welcomeText = settingGet('welcome_text', 'Добро пожаловать в галерею. Выберите раздел слева, чтобы посмотреть фотографии.');

$hasVisibleSections = false;
foreach ($sections as $s) {
    if ((int)($s['photos_count'] ?? 0) > 0) {
        $hasVisibleSections = true;
        break;
    }
}

$photo = $activePhotoId > 0 ? photoById($activePhotoId) : null;
if ($photo && $activeSectionId < 1) {
    $activeSectionId = (int)$photo['section_id'];
}

$filterMode = $activeTopicId > 0 ? 'topic' : ($activeSectionId > 0 ? 'section' : 'none');
$comments = $photo ? commentsByPhoto($activePhotoId) : [];
$topics = [];
$topicCounts = [];
$topicTree = [];
$hasVisibleTopics = false;
$visibleTopicTree = [];
try {
    $topics = topicsAllForSelect();
    if ($activeTopicId > 0) {
        if (!topicById($activeTopicId)) {
            $activeTopicId = 0;
            $filterMode = $activeSectionId > 0 ? 'section' : 'none';
        }
    }
    $topicCounts = topicPhotoCounts(null);
    $topicTree = buildTopicTreePublic($topics);
    foreach ($topicTree as $root) {
        $rootCount = (int)($topicCounts[(int)$root['id']] ?? 0);
        $visibleChildren = [];
        foreach (($root['children'] ?? []) as $child) {
            $childCount = (int)($topicCounts[(int)$child['id']] ?? 0);
            if ($childCount < 1) {
                continue;
            }
            $child['visible_count'] = $childCount;
            $visibleChildren[] = $child;
        }

        if ($rootCount < 1 && $visibleChildren === []) {
            continue;
        }

        $root['visible_count'] = $rootCount;
        $root['children'] = $visibleChildren;
        $visibleTopicTree[] = $root;
    }
    $hasVisibleTopics = $visibleTopicTree !== [];
} catch (Throwable) {
    $topics = [];
    $topicCounts = [];
    $topicTree = [];
    $activeTopicId = 0;
    $filterMode = $activeSectionId > 0 ? 'section' : 'none';
    $hasVisibleTopics = false;
    $visibleTopicTree = [];
}

$photos = ($activeSectionId > 0 || $activeTopicId > 0)
    ? photosForPublic($filterMode === 'section' ? $activeSectionId : null, $filterMode === 'topic' ? $activeTopicId : null)
    : [];
$photoCommentCounts = photoCommentCountsByPhotoIds(array_map(static fn(array $p): int => (int)$p['id'], $photos));
$isHomePage = $activeSectionId < 1 && $activePhotoId < 1;
$isTopicMode = $filterMode === 'topic';
$isSectionMode = $filterMode === 'section';

$sectionNames = [];
foreach ($sections as $s) {
    $sectionNames[(int)$s['id']] = (string)$s['name'];
}

$activeTopicName = '';
$activeTopicShortName = '';
foreach ($topics as $t) {
    if ((int)$t['id'] === $activeTopicId) {
        $activeTopicName = (string)$t['full_name'];
        $activeTopicShortName = (string)$t['name'];
        break;
    }
}

$detailTotal = 0;
$detailIndex = 0;
$prevPhotoId = 0;
$nextPhotoId = 0;
$detailSectionId = 0;
$photoTopics = [];
if ($photo) {
    $detailSectionId = (int)$photo['section_id'];
    try {
        $photoTopics = photoTopicsByPhotoId($activePhotoId);
    } catch (Throwable) {
        $photoTopics = [];
    }
    if ($isTopicMode) {
        $detailPhotos = photosForPublic(null, $activeTopicId);
    } else {
        $detailPhotos = photosForPublic($detailSectionId, null);
    }
    if ($activeTopicId > 0 && $detailPhotos !== []) {
        $foundInTopic = false;
        foreach ($detailPhotos as $d) {
            if ((int)$d['id'] === $activePhotoId) {
                $foundInTopic = true;
                break;
            }
        }
        if (!$foundInTopic) {
            $detailPhotos = photosForPublic($detailSectionId, null);
            $activeTopicId = 0;
            $isTopicMode = false;
            $isSectionMode = true;
        }
    }
    $detailTotal = count($detailPhotos);
    foreach ($detailPhotos as $i => $p) {
        if ((int)$p['id'] !== $activePhotoId) {
            continue;
        }

        $detailIndex = $i + 1;
        if ($i > 0) {
            $prevPhotoId = (int)$detailPhotos[$i - 1]['id'];
        }
        if ($i < $detailTotal - 1) {
            $nextPhotoId = (int)$detailPhotos[$i + 1]['id'];
        }
        break;
    }
}

$hasMobilePhotoNav = $activePhotoId > 0 && $photo && $detailTotal > 0;
$hasMobileCatalogNav = !$photo && ($isTopicMode || $isSectionMode);
$bodyClasses = [$isHomePage ? 'is-home' : 'is-inner'];
if ($hasMobilePhotoNav || $hasMobileCatalogNav) {
    $bodyClasses[] = 'has-mobile-nav';
}

$detailLocationLabel = '';
if ($activeTopicId > 0 && $activeTopicName !== '') {
    $detailLocationLabel = 'в тематике «' . $activeTopicName . '»';
} elseif ($detailSectionId > 0 && isset($sectionNames[$detailSectionId])) {
    $detailLocationLabel = 'в разделе «' . $sectionNames[$detailSectionId] . '»';
}

$pageHeading = '';
if ($isTopicMode && $activeTopicShortName !== '') {
    $pageHeading = $activeTopicShortName;
} elseif ($isSectionMode && isset($sectionNames[$activeSectionId])) {
    $pageHeading = $sectionNames[$activeSectionId];
} elseif ($photo && isset($sectionNames[$detailSectionId])) {
    $pageHeading = $sectionNames[$detailSectionId];
}

$catalogLocationLabel = '';
if ($isTopicMode && $activeTopicName !== '') {
    $catalogLocationLabel = 'Тема: ' . $activeTopicName;
} elseif ($isSectionMode && isset($sectionNames[$activeSectionId])) {
    $catalogLocationLabel = 'Раздел: ' . $sectionNames[$activeSectionId];
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function assetUrl(string $path): string { $f=__DIR__ . '/' . ltrim($path,'/'); $v=is_file($f)?(string)filemtime($f):(string)time(); return $path . '?v=' . rawurlencode($v); }
function limitText(string $text, int $len): string { return function_exists('mb_substr') ? mb_substr($text, 0, $len) : substr($text, 0, $len); }

function buildTopicTreePublic(array $topics): array
{
    $roots = [];
    $children = [];

    foreach ($topics as $topic) {
        $parentId = isset($topic['parent_id']) && $topic['parent_id'] !== null ? (int)$topic['parent_id'] : 0;
        if ($parentId === 0) {
            $roots[] = $topic;
            continue;
        }
        if (!isset($children[$parentId])) {
            $children[$parentId] = [];
        }
        $children[$parentId][] = $topic;
    }

    foreach ($roots as &$root) {
        $root['children'] = $children[(int)$root['id']] ?? [];
    }
    unset($root);

    return $roots;
}

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
        $w = max(1, (int)$im->getImageWidth());
        $h = max(1, (int)$im->getImageHeight());
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('rgba(255,255,255,0.16)'));
        $draw->setFontSize(max(12, (int)($w / 46)));
        $draw->setTextAntialias(true);

        $lineText = $text . '   ' . $text . '   ' . $text;
        $stepY = max(28, (int)($h / 10));
        $stepX = max(120, (int)($w / 3));
        for ($y = -$h; $y < $h * 2; $y += $stepY) {
            for ($x = -$w; $x < $w * 2; $x += $stepX) {
                $im->annotateImage($draw, $x, $y, -28, $lineText);
            }
        }

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

    $font = 2;
    $color = imagecolorallocatealpha($img, 255, 255, 255, 96);
    $lineText = $text . '  ' . $text . '  ' . $text;
    $stepY = max(16, imagefontheight($font) + 8);
    $stepX = max(120, (int)($w / 3));
    $row = 0;
    for ($y = -$h; $y < $h * 2; $y += $stepY) {
        $offset = ($row * 22) % $stepX;
        for ($x = -$w - $offset; $x < $w * 2; $x += $stepX) {
            imagestring($img, $font, $x, $y, $lineText, $color);
        }
        $row++;
    }

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
    .topbar{display:none;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
    .topbar h1{margin:0;font-size:24px;line-height:1.2}
    .page{display:grid;gap:16px;grid-template-columns:300px minmax(0,1fr)}
    .panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px}
    .sidebar{position:sticky;top:14px;align-self:start;max-height:calc(100dvh - 28px);overflow:auto}
    .nav-group{border-top:1px solid #e8edf5;padding-top:10px;margin-top:10px}
    .nav-group:first-of-type{border-top:0;margin-top:0;padding-top:0}
    .nav-summary{cursor:pointer;list-style:none;font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;justify-content:space-between;gap:8px}
    .nav-summary::-webkit-details-marker{display:none}
    .nav-summary::after{content:'▾';font-size:12px;color:#6b7280;transition:transform .18s ease}
    .nav-group:not([open]) .nav-summary::after{transform:rotate(-90deg)}
    .nav-list{display:grid;gap:6px;margin-top:8px}
    .nav-link{display:block;padding:10px 12px;border-radius:10px;line-height:1.35;text-decoration:none;color:#111;font-size:13px}
    .nav-link.level-1{padding-left:24px}
    .nav-link.active{background:#eef4ff;color:#1f6feb}
    .cards{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
    .card{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff}
    .card-badges{position:absolute;top:8px;right:8px;display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;z-index:4;pointer-events:none}
    .card-badge{display:inline-flex;align-items:center;justify-content:center;background:rgba(17,24,39,.78);color:#fff;font-size:11px;line-height:1;padding:6px 7px;border-radius:999px}
    .card-badge.ai{background:rgba(31,111,235,.92)}
    .card-badge.comments{background:rgba(3,105,161,.9)}
    .card img{width:100%;height:130px;object-fit:contain;object-position:center;background:#f8fafc}
    .cap{padding:8px;font-size:13px}
    .detail img{max-width:100%;border-radius:10px;border:1px solid #e5e7eb}
    .stack{display:grid;gap:12px;grid-template-columns:1fr}
    .detail-meta{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 12px}
    .detail-meta-link{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid #dbe3ef;background:#f8fbff;color:#1f3b7a;text-decoration:none;font-size:12px;line-height:1.25}
    .cmt{border-top:1px solid #eee;padding:8px 0}
    .muted{color:#6b7280;font-size:13px}
    .pager{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:16px;padding-top:12px;border-top:1px solid #e5e7eb}
    .pager-actions{display:flex;gap:8px;flex-wrap:wrap}
    .pager-link{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#111;text-decoration:none;font-size:14px}
    .pager-link.disabled{opacity:.45;pointer-events:none}
    .mobile-photo-nav,.mobile-catalog-nav{display:none}
    .mobile-nav-link{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:10px;padding:9px 10px;text-decoration:none;font-size:14px}
    .mobile-nav-link.disabled{opacity:.45;pointer-events:none}
    .mobile-nav-meta{font-size:13px;color:#4b5563;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .img-box{position:relative;display:block;overflow:hidden;background:linear-gradient(110deg,#eef2f7 8%,#f8fafc 18%,#eef2f7 33%);background-size:200% 100%;animation:skeleton 1.2s linear infinite}
    .img-box img{display:block;position:relative;z-index:1}
    .thumb-img-box{height:130px}
    .detail .img-box{min-height:200px;border-radius:10px;border:1px solid #e5e7eb}
    .detail .img-box img{max-width:100%;height:auto;border:0;border-radius:0}
    @keyframes skeleton{to{background-position:-200% 0}}
    .sidebar-head{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-bottom:6px}
    .sidebar-toggle{display:none}
    .sidebar-toggle,.sidebar-close{border:1px solid #d1d5db;background:#fff;color:#1f2937;border-radius:10px;padding:8px 12px;font-size:14px;font-weight:600;cursor:pointer}
    .sidebar-close{display:none;width:34px;height:34px;padding:0;line-height:1;font-size:24px}
    .sidebar-backdrop{display:none}

    @media (max-width:900px){
      .topbar{display:flex}
      .topbar h1{font-size:22px}
      .page{grid-template-columns:1fr}
      .sidebar{position:static;max-height:none}
      .pager{display:none}

      .has-mobile-nav .app{padding-bottom:84px}
      .mobile-photo-nav,.mobile-catalog-nav{position:fixed;left:0;right:0;bottom:0;z-index:50;display:grid;align-items:center;gap:8px;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:rgba(255,255,255,.97);backdrop-filter:blur(6px);border-top:1px solid #e5e7eb}
      .mobile-photo-nav{grid-template-columns:auto 1fr auto auto}
      .mobile-catalog-nav{grid-template-columns:auto 1fr}
      .mobile-nav-link{padding:8px 10px;font-size:13px}

      .is-inner .sidebar-toggle{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}
      .is-inner .sidebar{position:fixed;top:0;left:0;z-index:40;width:min(86vw,320px);height:100dvh;overflow-y:auto;border-radius:0 12px 12px 0;transform:translateX(-105%);transition:transform .2s ease;padding-top:18px}
      .is-inner.sidebar-open .sidebar{transform:translateX(0)}
      .is-inner .sidebar-close{display:inline-flex;align-items:center;justify-content:center}
      .is-inner .sidebar-backdrop{display:block;position:fixed;inset:0;z-index:30;border:0;padding:0;background:rgba(17,24,39,.45);opacity:0;pointer-events:none;transition:opacity .2s ease}
      .is-inner.sidebar-open .sidebar-backdrop{opacity:1;pointer-events:auto}
    }

    @media (max-width:560px){
      .app{padding:14px}
    }
  </style>
</head>
<body class="<?= h(implode(' ', $bodyClasses)) ?>">
<div class="app">
  <?php if (!$isHomePage && $pageHeading !== ''): ?>
    <header class="topbar">
      <h1><?= h($pageHeading) ?></h1>
    </header>
  <?php endif; ?>
  <?php if (!$isHomePage): ?>
    <button class="sidebar-backdrop js-sidebar-close" type="button" aria-label="Закрыть меню разделов"></button>
  <?php endif; ?>
  <div class="page">
    <aside id="sidebar" class="panel sidebar">
      <?php if (!$isHomePage): ?>
        <div class="sidebar-head">
          <button class="sidebar-close js-sidebar-close" type="button" aria-label="Закрыть меню разделов">×</button>
        </div>
      <?php endif; ?>

      <?php if ($hasVisibleSections): ?>
        <details class="nav-group" open>
          <summary class="nav-summary">Разделы</summary>
          <div class="nav-list">
            <?php foreach($sections as $s): ?>
              <?php if ((int)($s['photos_count'] ?? 0) < 1) continue; ?>
              <a class="nav-link<?= $isSectionMode && (int)$s['id']===$activeSectionId ? ' active' : '' ?>" href="?section_id=<?= (int)$s['id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>"><?= h((string)$s['name']) ?> <span class="muted">(<?= (int)$s['photos_count'] ?>)</span></a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <?php if ($visibleTopicTree !== [] && $hasVisibleTopics): ?>
        <details class="nav-group" open>
          <summary class="nav-summary">Тематики</summary>
          <div class="nav-list">
            <?php foreach($visibleTopicTree as $root): ?>
              <?php $rootCount = (int)($root['visible_count'] ?? 0); ?>
              <?php if ($rootCount > 0): ?>
                <a class="nav-link<?= $isTopicMode && (int)$root['id'] === $activeTopicId ? ' active' : '' ?>" href="?topic_id=<?= (int)$root['id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>"><?= h((string)$root['name']) ?> <span class="muted">(<?= $rootCount ?>)</span></a>
              <?php endif; ?>

              <?php foreach(($root['children'] ?? []) as $child): ?>
                <?php $childCount = (int)($child['visible_count'] ?? 0); ?>
                <a class="nav-link<?= $rootCount > 0 ? ' level-1' : '' ?><?= $isTopicMode && (int)$child['id'] === $activeTopicId ? ' active' : '' ?>" href="?topic_id=<?= (int)$child['id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>"><?= h((string)$child['name']) ?> <span class="muted">(<?= $childCount ?>)</span></a>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>
      <div class="nav-group">
        <p class="note" style="margin:0"><?= $viewer ? 'Вы авторизованы для комментариев: ' . h((string)$viewer['display_name']) : 'Режим просмотра' ?></p>
      </div>
    </aside>
    <main>
      <?php if ($activePhotoId > 0 && $photo): ?>
        <section class="panel detail">
          <div class="stack">
            <?php if (!empty($photo['before_file_id'])): ?><div><div class="muted">До обработки</div><div class="img-box"><img src="?action=image&file_id=<?= (int)$photo['before_file_id'] ?>" alt="" decoding="async" fetchpriority="high"></div></div><?php endif; ?>
            <?php if (!empty($photo['after_file_id'])): ?><div><div class="muted">После обработки (watermark)</div><div class="img-box"><img src="?action=image&file_id=<?= (int)$photo['after_file_id'] ?>" alt="" decoding="async" fetchpriority="high"></div></div><?php endif; ?>
          </div>

          <h2><?= h((string)$photo['code_name']) ?></h2>
          <div class="detail-meta">
            <a class="detail-meta-link" href="?section_id=<?= (int)$detailSectionId ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>">Раздел: <?= h($sectionNames[$detailSectionId] ?? ('#' . (string)$detailSectionId)) ?></a>
            <?php foreach($photoTopics as $topic): ?>
              <a class="detail-meta-link" href="?topic_id=<?= (int)$topic['id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>">Тематика: <?= h((string)$topic['full_name']) ?></a>
            <?php endforeach; ?>
          </div>
          <p class="muted"><?= h((string)($photo['description'] ?? '')) ?></p>

          <h3 style="margin-top:16px">Комментарии</h3>
          <?php if ($viewer): ?>
            <form class="js-comment-form" method="post" action="?photo_id=<?= (int)$photo['id'] ?><?= $isTopicMode ? '&topic_id=' . $activeTopicId : '&section_id=' . (int)$detailSectionId ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>">
              <input type="hidden" name="action" value="add_comment">
              <input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>">
              <input type="hidden" name="section_id" value="<?= $isSectionMode ? (int)$detailSectionId : 0 ?>">
              <input type="hidden" name="topic_id" value="<?= $isTopicMode ? (int)$activeTopicId : 0 ?>">
              <input type="hidden" name="viewer" value="<?= h($viewerToken) ?>">
              <textarea class="js-comment-textarea" name="comment_text" required autofocus style="width:100%;min-height:80px;border:1px solid #d1d5db;border-radius:8px;padding:8px"></textarea>
              <p><button class="btn" type="submit">Отправить</button></p>
            </form>
          <?php else: ?>
            <p class="muted">Комментарии может оставлять только пользователь с персональной ссылкой.</p>
          <?php endif; ?>

          <?php foreach($comments as $c): ?>
            <div class="cmt"><strong><?= h((string)($c['display_name'] ?? 'Пользователь')) ?></strong> <span class="muted">· <?= h((string)$c['created_at']) ?></span><br><?= nl2br(h((string)$c['comment_text'])) ?></div>
          <?php endforeach; ?>

          <?php if ($detailTotal > 0): ?>
            <div class="pager">
              <div class="muted">Фото <?= (int)$detailIndex ?> из <?= (int)$detailTotal ?><?= $detailLocationLabel !== '' ? ' ' . h($detailLocationLabel) : '' ?></div>
              <div class="pager-actions">
                <a class="pager-link js-prev-photo<?= $prevPhotoId < 1 ? ' disabled' : '' ?>" href="?photo_id=<?= (int)$prevPhotoId ?><?= $isTopicMode ? '&topic_id=' . $activeTopicId : '&section_id=' . (int)$detailSectionId ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>">← Предыдущее</a>
                <a class="pager-link js-next-photo<?= $nextPhotoId < 1 ? ' disabled' : '' ?>" href="?photo_id=<?= (int)$nextPhotoId ?><?= $isTopicMode ? '&topic_id=' . $activeTopicId : '&section_id=' . (int)$detailSectionId ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>">Следующее →</a>
              </div>
            </div>
          <?php endif; ?>
        </section>
      <?php else: ?>
        <section class="panel">
          <?php if ($activeSectionId < 1 && $activeTopicId < 1): ?>
            <p class="muted"><?= nl2br(h($welcomeText)) ?></p>
          <?php elseif ($photos === []): ?>
            <p class="muted">В разделе пока нет фотографий.</p>
          <?php else: ?>
            <div class="cards">
              <?php foreach($photos as $p): ?>
                <?php $cardCommentCount = (int)($photoCommentCounts[(int)$p['id']] ?? 0); ?>
                <a class="card js-photo-card" href="?photo_id=<?= (int)$p['id'] ?><?= $isTopicMode ? '&topic_id=' . $activeTopicId : '&section_id=' . (int)$p['section_id'] ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>" style="text-decoration:none;color:inherit;position:relative">
                  <?php if (!empty($p['before_file_id'])): ?><div class="img-box thumb-img-box"><img src="?action=image&file_id=<?= (int)$p['before_file_id'] ?>" alt="" loading="lazy" decoding="async" fetchpriority="low"></div><?php endif; ?>
                  <div class="card-badges">
                    <?php if ($cardCommentCount > 0): ?><span class="card-badge comments" title="Комментариев: <?= $cardCommentCount ?>">💬 <?= $cardCommentCount ?></span><?php endif; ?>
                    <?php if (!empty($p['after_file_id'])): ?><span class="card-badge ai" title="Есть обработанная версия">AI</span><?php endif; ?>
                  </div>
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
<?php if ($hasMobilePhotoNav): ?>
  <nav class="mobile-photo-nav" aria-label="Навигация по фото">
    <button class="mobile-nav-link js-sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false">Меню</button>
    <div class="mobile-nav-meta">Фото <?= (int)$detailIndex ?> из <?= (int)$detailTotal ?><?= $detailLocationLabel !== '' ? ' ' . h($detailLocationLabel) : '' ?></div>
    <a class="mobile-nav-link js-prev-photo<?= $prevPhotoId < 1 ? ' disabled' : '' ?>" href="?photo_id=<?= (int)$prevPhotoId ?><?= $isTopicMode ? '&topic_id=' . $activeTopicId : '&section_id=' . (int)$detailSectionId ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>" aria-disabled="<?= $prevPhotoId < 1 ? 'true' : 'false' ?>">←</a>
    <a class="mobile-nav-link js-next-photo<?= $nextPhotoId < 1 ? ' disabled' : '' ?>" href="?photo_id=<?= (int)$nextPhotoId ?><?= $isTopicMode ? '&topic_id=' . $activeTopicId : '&section_id=' . (int)$detailSectionId ?><?= $viewerToken!=='' ? '&viewer=' . urlencode($viewerToken) : '' ?>" aria-disabled="<?= $nextPhotoId < 1 ? 'true' : 'false' ?>">→</a>
  </nav>
<?php elseif ($hasMobileCatalogNav): ?>
  <nav class="mobile-catalog-nav" aria-label="Навигация по каталогу">
    <button class="mobile-nav-link js-sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false">Меню</button>
    <div class="mobile-nav-meta"><?= h($catalogLocationLabel !== '' ? $catalogLocationLabel : 'Каталог') ?><?= $photos !== [] ? ' · ' . count($photos) : '' ?></div>
  </nav>
<?php endif; ?>
<script>
(() => {
  document.querySelectorAll('img').forEach((img) => {
    img.addEventListener('contextmenu', (e) => e.preventDefault());
    img.addEventListener('dragstart', (e) => e.preventDefault());
  });
})();

(() => {
  const body = document.body;
  if (!body.classList.contains('is-inner')) {
    return;
  }

  const toggles = Array.from(document.querySelectorAll('.js-sidebar-toggle'));
  const sidebar = document.getElementById('sidebar');
  const closers = document.querySelectorAll('.js-sidebar-close');
  if (toggles.length === 0 || !sidebar || closers.length === 0) {
    return;
  }

  const setExpanded = (value) => {
    toggles.forEach((toggle) => {
      toggle.setAttribute('aria-expanded', value ? 'true' : 'false');
    });
  };

  const closeSidebar = () => {
    body.classList.remove('sidebar-open');
    setExpanded(false);
  };

  const openSidebar = () => {
    body.classList.add('sidebar-open');
    setExpanded(true);
  };

  toggles.forEach((toggle) => {
    toggle.addEventListener('click', () => {
      if (body.classList.contains('sidebar-open')) {
        closeSidebar();
        return;
      }

      openSidebar();
    });
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

(() => {
  const commentTextarea = document.querySelector('.js-comment-textarea');
  const commentForm = commentTextarea ? commentTextarea.closest('.js-comment-form') : null;
  if (commentTextarea) {
    requestAnimationFrame(() => {
      commentTextarea.focus();
      commentTextarea.setSelectionRange(commentTextarea.value.length, commentTextarea.value.length);
    });

    commentTextarea.addEventListener('keydown', (e) => {
      if (!e.shiftKey || e.key !== 'Enter' || e.isComposing) {
        return;
      }
      if (!commentForm) {
        return;
      }
      e.preventDefault();
      if (typeof commentForm.requestSubmit === 'function') {
        commentForm.requestSubmit();
        return;
      }
      commentForm.submit();
    });
  }

  const isEditableTarget = (target) => {
    if (!(target instanceof HTMLElement)) {
      return false;
    }
    return target.isContentEditable || !!target.closest('input, textarea, select, [contenteditable="true"]');
  };

  const enabledNavLink = (selector) => {
    const links = Array.from(document.querySelectorAll(selector));
    return links.find((link) => !link.classList.contains('disabled') && link.getAttribute('aria-disabled') !== 'true') || null;
  };

  const prevPhotoLink = enabledNavLink('.js-prev-photo');
  const nextPhotoLink = enabledNavLink('.js-next-photo');
  const photoCards = Array.from(document.querySelectorAll('.js-photo-card'));
  const catalogLinks = Array.from(document.querySelectorAll('#sidebar .nav-link'));
  let hoveredCardIndex = -1;

  photoCards.forEach((card, index) => {
    card.dataset.cardIndex = String(index);
    card.addEventListener('mouseenter', () => {
      hoveredCardIndex = index;
    });
    card.addEventListener('focus', () => {
      hoveredCardIndex = index;
    });
  });

  const navigatePhotoCards = (direction) => {
    if (photoCards.length === 0) {
      return false;
    }

    const focusedCard = document.activeElement instanceof HTMLElement
      ? document.activeElement.closest('.js-photo-card')
      : null;
    let currentIndex = focusedCard ? Number(focusedCard.dataset.cardIndex || 0) : hoveredCardIndex;

    if (!Number.isInteger(currentIndex) || currentIndex < 0 || currentIndex >= photoCards.length) {
      currentIndex = direction > 0 ? -1 : photoCards.length;
    }

    const nextIndex = Math.max(0, Math.min(photoCards.length - 1, currentIndex + direction));
    if (nextIndex === currentIndex) {
      return false;
    }

    const targetCard = photoCards[nextIndex];
    if (!targetCard || !targetCard.href) {
      return false;
    }
    window.location.href = targetCard.href;
    return true;
  };

  const navigateCatalog = (direction) => {
    if (catalogLinks.length === 0) {
      return false;
    }

    let currentIndex = catalogLinks.findIndex((link) => link.classList.contains('active'));
    if (currentIndex < 0 && document.activeElement instanceof HTMLElement) {
      const focusedLink = document.activeElement.closest('#sidebar .nav-link');
      if (focusedLink) {
        currentIndex = catalogLinks.indexOf(focusedLink);
      }
    }
    if (currentIndex < 0) {
      currentIndex = direction > 0 ? -1 : catalogLinks.length;
    }

    const nextIndex = Math.max(0, Math.min(catalogLinks.length - 1, currentIndex + direction));
    const link = catalogLinks[nextIndex];
    if (!link || !link.href) {
      return false;
    }
    if (nextIndex === currentIndex) {
      return true;
    }
    window.location.href = link.href;
    return true;
  };

  document.addEventListener('keydown', (e) => {
    if (e.defaultPrevented || e.isComposing) {
      return;
    }

    const target = e.target;
    if (isEditableTarget(target)) {
      return;
    }

    if ((e.shiftKey || e.ctrlKey) && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
      const direction = e.key === 'ArrowDown' ? 1 : -1;
      if (navigateCatalog(direction)) {
        e.preventDefault();
      }
      return;
    }

    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) {
      return;
    }

    if (e.key === 'ArrowLeft') {
      if (prevPhotoLink && prevPhotoLink.href) {
        e.preventDefault();
        window.location.href = prevPhotoLink.href;
        return;
      }
      if (navigatePhotoCards(-1)) {
        e.preventDefault();
      }
      return;
    }

    if (e.key === 'ArrowRight') {
      if (nextPhotoLink && nextPhotoLink.href) {
        e.preventDefault();
        window.location.href = nextPhotoLink.href;
        return;
      }
      if (navigatePhotoCards(1)) {
        e.preventDefault();
      }
    }
  });
})();
</script>
</body>
</html>
