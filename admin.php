<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/db_gallery.php';
require_once __DIR__ . '/lib/thumbs.php';
require_once __DIR__ . '/lib/admin_http.php';
require_once __DIR__ . '/lib/admin_helpers.php';
require_once __DIR__ . '/lib/admin_get_actions.php';
require_once __DIR__ . '/lib/admin_post_actions.php';

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

$requestAction = (string)($_REQUEST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    adminHandleGetAction($requestAction);
}

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $isAjax = (string)($_POST['ajax'] ?? '') === '1'
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    try {
        $result = adminHandlePostAction($action, $isAjax, __DIR__);
        $message = (string)($result['message'] ?? '');
        if (isset($result['errors']) && is_array($result['errors']) && $result['errors'] !== []) {
            $errors = array_merge($errors, $result['errors']);
        }
    } catch (Throwable $e) {
        if ($isAjax) {
            adminJsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
        }
        $errors[] = $e->getMessage();
    }
}

$sections = sectionsAll();
$activeSectionId = (int)($_GET['section_id'] ?? ($_POST['section_id'] ?? ($sections[0]['id'] ?? 0)));
$activeSection = $activeSectionId > 0 ? sectionById($activeSectionId) : null;
if (!$activeSection && $sections !== []) {
    $activeSectionId = (int)$sections[0]['id'];
    $activeSection = sectionById($activeSectionId);
}
$photos = $activeSectionId > 0 ? photosBySection($activeSectionId) : [];
$commenters = commentersAll();
$welcomeText = settingGet('welcome_text', 'Добро пожаловать в галерею. Выберите раздел слева, чтобы посмотреть фотографии.');
$watermarkText = settingGet('watermark_text', 'photo.andr33v.ru');
$watermarkBrightness = max(5, min(100, (int)settingGet('watermark_brightness', '35')));
$watermarkAngle = max(-75, min(75, (int)settingGet('watermark_angle', '-28')));
$adminMode = (string)($_GET['mode'] ?? 'photos');
if ($adminMode === 'media') {
    $adminMode = 'photos';
}
if (!in_array($adminMode, ['sections', 'photos', 'topics', 'commenters', 'comments', 'welcome'], true)) {
    $adminMode = 'photos';
}
$previewVersion = (string)time();
$commentPhotoQuery = trim((string)($_GET['comment_photo'] ?? ($_POST['comment_photo'] ?? '')));
$commentUserQuery = trim((string)($_GET['comment_user'] ?? ($_POST['comment_user'] ?? '')));
$filteredComments = commentsSearch($commentPhotoQuery, $commentUserQuery, 200);
$photoCommentCounts = commentCountsByPhotoIds(array_map(static fn(array $p): int => (int)$p['id'], $photos));
$topics = [];
$topicRoots = [];
$photoTopicsMap = [];
$topicTree = [];
$topicsError = '';
try {
    $topics = topicsAllForSelect();
    foreach ($topics as $topic) {
        if ((int)$topic['level'] === 0) {
            $topicRoots[] = $topic;
        }
    }
    $topicTree = buildTopicTree($topics);
    $photoTopicsMap = photoTopicsMapByPhotoIds(array_map(static fn(array $p): int => (int)$p['id'], $photos));
} catch (Throwable $e) {
    $topicsError = 'Тематики недоступны. Запусти миграции: php scripts/migrate.php';
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function assetUrl(string $path): string { $f=__DIR__ . '/' . ltrim($path,'/'); $v=is_file($f)?(string)filemtime($f):(string)time(); return $path . '?v=' . rawurlencode($v); }
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Админка</title>
  <link rel="icon" type="image/svg+xml" href="<?= h(assetUrl('favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= h(assetUrl('style.css')) ?>">
  <style>
    .wrap{max-width:1180px;margin:0 auto;padding:24px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:14px}
    .grid{display:grid;gap:12px;grid-template-columns:320px 1fr}
    .admin-sidebar{position:sticky;top:14px;align-self:start;max-height:calc(100dvh - 28px);overflow:auto}
    .in{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px}
    .btn{border:0;background:#1f6feb;color:#fff;padding:8px 12px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}
    .btn-danger{background:#b42318}
    .btn-secondary{background:#eaf1ff;color:#1f6feb}
    .btn-xs{padding:6px 8px;font-size:12px;line-height:1}
    .ok{background:#ecfdf3;padding:8px;border-radius:8px;margin-bottom:8px}
    .err{background:#fef2f2;padding:8px;border-radius:8px;margin-bottom:8px}
    .tbl{width:100%;border-collapse:collapse}
    .tbl td,.tbl th{padding:8px;border-bottom:1px solid #eee;vertical-align:top}
    .sec a{display:block;padding:8px 10px;border-radius:8px;text-decoration:none;color:#111}
    .sec a.active{background:#eef4ff;color:#1f6feb}
    .small{font-size:12px;color:#667085}
    .inline-form{margin:0}
    .after-slot{display:flex;flex-direction:column;align-items:flex-start;gap:6px}
    .preview-actions{display:flex;gap:6px;margin-top:6px;flex-wrap:nowrap}
    .preview-actions form{margin:0}
    .is-hidden{display:none}
    .topic-editor{display:grid;gap:8px;margin-top:10px}
    .topic-controls{display:flex;gap:8px;align-items:center}
    .topic-controls .in{min-width:0}
    .topic-list{display:flex;flex-wrap:wrap;gap:6px}
    .topic-chip{display:inline-flex;align-items:center;gap:6px;background:#f5f8ff;border:1px solid #dbe7ff;border-radius:999px;padding:4px 8px;font-size:12px;color:#1f3b7a;white-space:nowrap}
    .topic-chip button{border:0;background:transparent;color:#a11b1b;cursor:pointer;font-size:14px;line-height:1;padding:0}
    .topic-empty{font-size:12px;color:#667085}
    .topic-status{font-size:12px;min-height:16px;color:#667085}
    .topic-tree{display:grid;gap:10px}
    .topic-node{border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff}
    .topic-node.level-2{margin-left:20px;border-color:#edf2fb;background:#fbfdff}
    .topic-node-head{font-size:12px;color:#667085;margin:0 0 8px}
    .topic-line{display:flex;align-items:flex-start;gap:8px}
    .topic-row{display:grid;grid-template-columns:minmax(180px,1fr) 110px;gap:8px;align-items:center;flex:1}
    .topic-row .btn{height:36px}
    .topic-delete-btn{height:36px;margin:0}
    .topic-children{display:grid;gap:8px;margin-top:8px}
    @media (max-width:900px){.topic-line{flex-direction:column}.topic-row{grid-template-columns:1fr 110px;width:100%}.topic-row .btn,.topic-delete-btn{width:100%}}
    .row-actions{display:flex;flex-direction:column;align-items:flex-start;gap:8px}
    .modal{position:fixed;inset:0;z-index:90;display:flex;align-items:center;justify-content:center;padding:16px}
    .modal[hidden]{display:none}
    .modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.44)}
    .modal-card{position:relative;z-index:1;max-width:760px;width:min(100%,760px);max-height:80dvh;overflow:auto;background:#fff;border:1px solid #dbe3ef;border-radius:12px;padding:14px;box-shadow:0 24px 48px rgba(15,23,42,.22)}
    .modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
    .modal-close{border:0;background:#eef2ff;color:#1f6feb;width:32px;height:32px;border-radius:8px;font-size:22px;line-height:1;cursor:pointer}
    .comment-row{padding:10px 0;border-top:1px solid #eef2f7}
    .comment-row:first-child{border-top:0;padding-top:0}
    .comment-row-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}
    .comment-row-body{white-space:pre-wrap}
    .comment-search{display:grid;grid-template-columns:minmax(180px,1fr) minmax(180px,1fr) auto auto;gap:8px;align-items:center;margin-bottom:12px}
    .comment-search .btn{height:36px}
    @media (max-width:760px){.comment-search{grid-template-columns:1fr}}
    @media (max-width:960px){.grid{grid-template-columns:1fr}.admin-sidebar{position:static;top:auto;max-height:none;overflow:visible}}
  </style>
</head>
<body><div class="wrap">
  <h1>Админка</h1>
  <?php if ($message!==''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
  <?php foreach($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>

  <div class="grid">
    <aside class="admin-sidebar">
      <section class="card">
        <h3>Меню</h3>
        <div class="sec">
          <a class="<?= $adminMode==='sections'?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=sections<?= $activeSectionId>0 ? '&section_id='.(int)$activeSectionId : '' ?>">Разделы</a>
          <a class="<?= $adminMode==='topics'?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=topics">Тематики</a>
          <a class="<?= $adminMode==='photos'?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=photos<?= $activeSectionId>0 ? '&section_id='.(int)$activeSectionId : '' ?>">Фото</a>
          <a class="<?= $adminMode==='commenters'?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=commenters">Пользователи</a>
          <a class="<?= $adminMode==='comments'?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=comments">Комментарии</a>
          <a class="<?= $adminMode==='welcome'?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=welcome">Настройки</a>
        </div>
      </section>

      <?php if ($adminMode === 'photos'): ?>
      <section class="card">
        <h3>Разделы</h3>
        <div class="sec">
          <?php foreach($sections as $s): ?>
            <a class="<?= (int)$s['id']===$activeSectionId?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=<?= h($adminMode) ?>&section_id=<?= (int)$s['id'] ?>"><?= h((string)$s['name']) ?> <span class="small">(<?= (int)$s['photos_count'] ?>)</span></a>
          <?php endforeach; ?>
        </div>

        <?php if ($adminMode === 'photos' && !$activeSection): ?>
          <div class="small" style="margin-top:8px">Создай раздел во вкладке "Разделы", чтобы загружать фото.</div>
        <?php endif; ?>
      </section>

      <?php endif; ?>

      <?php if ($adminMode === 'commenters'): ?>
      <section class="card">
        <h3>Новый пользователь комментариев</h3>
        <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=commenters">
          <input type="hidden" name="action" value="create_commenter"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
          <p><input class="in" name="display_name" placeholder="Имя" required></p>
          <button class="btn" type="submit">Создать</button>
        </form>
        <div class="small" style="margin-top:8px">Ссылка доступа показывается в зелёном сообщении после создания.</div>
      </section>
      <?php endif; ?>
    </aside>

    <main>
      <?php if ($adminMode === 'welcome'): ?>
      <section class="card">
        <h3>Настройки</h3>
        <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=welcome">
          <input type="hidden" name="action" value="update_settings"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
          <p><textarea class="in" name="welcome_text" rows="5" placeholder="Текст приветствия"><?= h($welcomeText) ?></textarea></p>
          <hr style="border:none;border-top:1px solid #eee;margin:12px 0">
          <h4 style="margin:0 0 8px">Водяной знак (фото "после")</h4>
          <p><input class="in" name="watermark_text" value="<?= h($watermarkText) ?>" placeholder="Текст водяного знака" maxlength="80"></p>
          <p>
            <label class="small" for="wm-brightness">Яркость: <?= (int)$watermarkBrightness ?>%</label>
            <input id="wm-brightness" class="in" type="number" name="watermark_brightness" min="5" max="100" value="<?= (int)$watermarkBrightness ?>">
          </p>
          <p>
            <label class="small" for="wm-angle">Наклон линий (-75..75)</label>
            <input id="wm-angle" class="in" type="number" name="watermark_angle" min="-75" max="75" value="<?= (int)$watermarkAngle ?>">
          </p>
          <button class="btn" type="submit">Сохранить настройки</button>
        </form>
      </section>
      <?php endif; ?>

      <?php if ($adminMode === 'sections'): ?>
      <section class="card">
        <h3>Создать раздел</h3>
        <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=sections">
          <input type="hidden" name="action" value="create_section"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
          <p><input class="in" name="name" placeholder="Новый раздел" required></p>
          <button class="btn" type="submit">Создать раздел</button>
        </form>
      </section>

      <section class="card">
        <h3>Список разделов</h3>
        <?php if ($sections === []): ?>
          <p class="small">Разделов пока нет.</p>
        <?php else: ?>
          <div class="topic-tree">
            <?php foreach($sections as $section): ?>
              <div class="topic-node level-1">
                <div class="topic-line">
                  <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=sections&section_id=<?= (int)$section['id'] ?>" class="topic-row js-section-form">
                    <input type="hidden" name="action" value="update_section"><input type="hidden" name="ajax" value="1"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="section_id" value="<?= (int)$section['id'] ?>">
                    <input class="in" type="text" name="name" value="<?= h((string)$section['name']) ?>" required>
                    <input class="in" type="number" name="sort_order" value="<?= (int)$section['sort_order'] ?>">
                    <div class="small js-save-status" style="grid-column:1 / -1"></div>
                  </form>
                  <form class="inline-form" method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=sections" onsubmit="return confirmSectionDelete()">
                    <input type="hidden" name="action" value="delete_section"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="section_id" value="<?= (int)$section['id'] ?>">
                    <button class="btn btn-danger topic-delete-btn" type="submit">Удалить</button>
                  </form>
                </div>
                <p class="small" style="margin:8px 0 0">Фото: <?= (int)$section['photos_count'] ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php endif; ?>

      <?php if ($adminMode === 'topics'): ?>
      <section class="card">
        <h3>Создать тематику</h3>
        <?php if ($topicsError !== ''): ?>
          <p class="small" style="color:#b42318"><?= h($topicsError) ?></p>
        <?php else: ?>
          <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=topics">
            <input type="hidden" name="action" value="create_topic"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
            <p><input class="in" name="name" placeholder="Название тематики" required></p>
            <p>
              <select class="in" name="parent_id">
                <option value="0">Без родителя (верхний уровень)</option>
                <?php foreach($topicRoots as $root): ?>
                  <option value="<?= (int)$root['id'] ?>">Внутри: <?= h((string)$root['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </p>
            <button class="btn" type="submit">Создать тематику</button>
          </form>
        <?php endif; ?>
      </section>

      <section class="card">
        <h3>Список тематик</h3>
        <?php if ($topicsError !== ''): ?>
          <p class="small" style="color:#b42318"><?= h($topicsError) ?></p>
        <?php elseif ($topics === []): ?>
          <p class="small">Тематик пока нет.</p>
        <?php else: ?>
          <div class="topic-tree">
            <?php foreach($topicTree as $root): ?>
              <div class="topic-node level-1">
                <p class="topic-node-head">Уровень 1</p>
                <div class="topic-line">
                  <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=topics" class="topic-row js-topic-form">
                    <input type="hidden" name="action" value="update_topic"><input type="hidden" name="ajax" value="1"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="topic_id" value="<?= (int)$root['id'] ?>">
                    <input class="in" type="text" name="name" value="<?= h((string)$root['name']) ?>" required>
                    <input class="in" type="number" name="sort_order" value="<?= (int)$root['sort_order'] ?>">
                    <div class="small js-save-status" style="grid-column:1 / -1"></div>
                  </form>
                  <form class="inline-form" method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=topics" onsubmit="return confirm('Удалить тематику? Дочерние тематики и привязки к фото тоже удалятся.')">
                    <input type="hidden" name="action" value="delete_topic"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="topic_id" value="<?= (int)$root['id'] ?>">
                    <button class="btn btn-danger topic-delete-btn" type="submit">Удалить</button>
                  </form>
                </div>

                <?php if (!empty($root['children'])): ?>
                  <div class="topic-children">
                    <?php foreach($root['children'] as $child): ?>
                      <div class="topic-node level-2">
                        <p class="topic-node-head">Уровень 2 · внутри «<?= h((string)$root['name']) ?>»</p>
                        <div class="topic-line">
                          <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=topics" class="topic-row js-topic-form">
                            <input type="hidden" name="action" value="update_topic"><input type="hidden" name="ajax" value="1"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="topic_id" value="<?= (int)$child['id'] ?>">
                            <input class="in" type="text" name="name" value="<?= h((string)$child['name']) ?>" required>
                            <input class="in" type="number" name="sort_order" value="<?= (int)$child['sort_order'] ?>">
                            <div class="small js-save-status" style="grid-column:1 / -1"></div>
                          </form>
                          <form class="inline-form" method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=topics" onsubmit="return confirm('Удалить тематику?')">
                            <input type="hidden" name="action" value="delete_topic"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="topic_id" value="<?= (int)$child['id'] ?>">
                            <button class="btn btn-danger topic-delete-btn" type="submit">Удалить</button>
                          </form>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if ($adminMode === 'photos'): ?>
      <section class="card">
        <h3>Загрузка фото “до” в выбранный раздел</h3>
        <?php if ($activeSectionId > 0): ?>
          <form method="post" enctype="multipart/form-data" action="?token=<?= urlencode($tokenIncoming) ?>&mode=photos&section_id=<?= (int)$activeSectionId ?>">
            <input type="hidden" name="action" value="upload_before_bulk"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="section_id" value="<?= (int)$activeSectionId ?>">
            <p><input type="file" name="before_bulk[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required></p>
            <button class="btn" type="submit">Загрузить массово</button>
          </form>
          <p class="small">После загрузки имя (code_name) заполняется автоматически из имени файла — затем можно отредактировать.</p>
        <?php else: ?>
          <p class="small">Сначала выбери раздел слева.</p>
        <?php endif; ?>
      </section>

      <section class="card">
        <h3>Фото в разделе</h3>
        <?php if ($topicsError !== ''): ?>
          <p class="small" style="color:#b42318"><?= h($topicsError) ?></p>
        <?php endif; ?>
        <table class="tbl">
          <tr><th>До</th><th>После</th><th>Поля</th><th>Действия</th></tr>
          <?php foreach($photos as $p): ?>
            <?php $photoCommentCount = (int)($photoCommentCounts[(int)$p['id']] ?? 0); ?>
            <?php $attachedTopics = $photoTopicsMap[(int)$p['id']] ?? []; ?>
            <tr>
              <td>
                <?php if (!empty($p['before_file_id'])): ?>
                  <img class="js-open js-preview-image" data-photo-id="<?= (int)$p['id'] ?>" data-kind="before" data-full="index.php?action=image&file_id=<?= (int)$p['before_file_id'] ?>&v=<?= urlencode($previewVersion) ?>" src="index.php?action=image&file_id=<?= (int)$p['before_file_id'] ?>&v=<?= urlencode($previewVersion) ?>" style="cursor:zoom-in;width:100px;height:70px;object-fit:cover;border:1px solid #e5e7eb;border-radius:6px">
                  <div class="preview-actions">
                    <form class="inline-form js-rotate-form" method="post" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=photos">
                      <input type="hidden" name="action" value="rotate_photo_file"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="kind" value="before"><input type="hidden" name="direction" value="left">
                      <button class="btn btn-secondary btn-xs" type="submit">↺ 90°</button>
                    </form>
                    <form class="inline-form js-rotate-form" method="post" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=photos">
                      <input type="hidden" name="action" value="rotate_photo_file"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="kind" value="before"><input type="hidden" name="direction" value="right">
                      <button class="btn btn-secondary btn-xs" type="submit">↻ 90°</button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="after-slot js-after-slot" data-photo-id="<?= (int)$p['id'] ?>">
                  <?php if (!empty($p['after_file_id'])): ?>
                    <img class="js-open js-preview-image" data-photo-id="<?= (int)$p['id'] ?>" data-kind="after" data-full="index.php?action=image&file_id=<?= (int)$p['after_file_id'] ?>&v=<?= urlencode($previewVersion) ?>" src="index.php?action=image&file_id=<?= (int)$p['after_file_id'] ?>&v=<?= urlencode($previewVersion) ?>" style="cursor:zoom-in;width:100px;height:70px;object-fit:cover;border:1px solid #e5e7eb;border-radius:6px">
                  <?php else: ?>
                    <div class="small js-after-empty">Фото после не загружено</div>
                  <?php endif; ?>

                  <div class="preview-actions js-after-rotate<?= empty($p['after_file_id']) ? ' is-hidden' : '' ?>">
                    <form class="inline-form js-rotate-form" method="post" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=photos">
                      <input type="hidden" name="action" value="rotate_photo_file"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="kind" value="after"><input type="hidden" name="direction" value="left">
                      <button class="btn btn-secondary btn-xs" type="submit">↺ 90°</button>
                    </form>
                    <form class="inline-form js-rotate-form" method="post" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=photos">
                      <input type="hidden" name="action" value="rotate_photo_file"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="kind" value="after"><input type="hidden" name="direction" value="right">
                      <button class="btn btn-secondary btn-xs" type="submit">↻ 90°</button>
                    </form>
                  </div>

                  <form class="inline-form js-after-upload-form" method="post" enctype="multipart/form-data" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=photos">
                    <input type="hidden" name="action" value="upload_after_file"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="ajax" value="1">
                    <input class="js-after-file-input" type="file" name="after" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
                    <button class="btn btn-secondary btn-xs js-after-pick" type="button"><?= empty($p['after_file_id']) ? 'Загрузить фото после' : 'Изменить фото' ?></button>
                  </form>
                </div>
              </td>
              <td>
                <form class="js-photo-form" method="post" enctype="multipart/form-data" action="admin.php?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=photos">
                  <input type="hidden" name="action" value="photo_update"><input type="hidden" name="ajax" value="1"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                  <p><input class="in" name="code_name" value="<?= h((string)$p['code_name']) ?>"></p>
                  <p><input class="in" type="number" name="sort_order" value="<?= (int)$p['sort_order'] ?>"></p>
                  <p><textarea id="descr-<?= (int)$p['id'] ?>" class="in" name="description" placeholder="Описание фотографии"><?= h((string)($p['description'] ?? '')) ?></textarea></p>
                  <div class="small js-save-status"></div>
                </form>

                <div class="topic-editor js-topic-editor" data-photo-id="<?= (int)$p['id'] ?>" data-endpoint="<?= h('admin.php?token=' . urlencode($tokenIncoming) . '&section_id=' . (int)$activeSectionId . '&mode=photos') ?>">
                  <div class="topic-list js-topic-list">
                    <?php if ($attachedTopics !== []): ?>
                      <?php foreach($attachedTopics as $topic): ?>
                        <span class="topic-chip" data-topic-id="<?= (int)$topic['id'] ?>">
                          <?= h((string)$topic['full_name']) ?>
                          <button class="js-topic-remove" type="button" data-topic-id="<?= (int)$topic['id'] ?>" aria-label="Убрать тематику">×</button>
                        </span>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>

                  <div class="topic-controls">
                    <select class="in js-topic-select" <?= $topics === [] ? 'disabled' : '' ?>>
                      <option value="">Выбери тематику (добавится сразу)</option>
                      <?php foreach($topics as $topic): ?>
                        <option value="<?= (int)$topic['id'] ?>"><?= (int)$topic['level'] === 1 ? '— ' : '' ?><?= h((string)$topic['full_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="topic-status js-topic-status"></div>
                </div>
              </td>
              <td>
                <div class="row-actions">
                  <div class="js-comment-indicator" data-photo-id="<?= (int)$p['id'] ?>" data-photo-name="<?= h((string)$p['code_name']) ?>">
                    <?php if ($photoCommentCount > 0): ?>
                      <button class="btn btn-secondary btn-xs js-open-comments" type="button" data-photo-id="<?= (int)$p['id'] ?>" data-photo-name="<?= h((string)$p['code_name']) ?>" data-comment-count="<?= $photoCommentCount ?>">Комментарии (<?= $photoCommentCount ?>)</button>
                    <?php else: ?>
                      <span class="small">Комментариев нет</span>
                    <?php endif; ?>
                  </div>
                  <form class="inline-form" method="post" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=photos" onsubmit="return confirm('Удалить фото?')">
                    <input type="hidden" name="action" value="photo_delete"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-danger" type="submit">Удалить</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </section>

      <?php endif; ?>

      <?php if ($adminMode === 'commenters'): ?>
      <section class="card">
        <h3>Пользователи комментариев</h3>
        <table class="tbl"><tr><th>Пользователь</th><th>Ссылка</th><th>Действия</th></tr>
          <?php foreach($commenters as $u): ?>
            <?php $viewerLink = !empty($u['token_plain']) ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?viewer=' . urlencode((string)$u['token_plain'])) : ''; ?>
            <tr><td><?= h((string)$u['display_name']) ?></td><td>
              <?php if ($viewerLink !== ''): ?>
                <input class="in" type="text" readonly value="<?= h($viewerLink) ?>" onclick="this.select()" style="min-width:320px">
              <?php else: ?>
                <span class="small">Нет сохранённой ссылки (старый пользователь)</span>
              <?php endif; ?>
            </td><td style="display:flex;gap:8px;flex-wrap:wrap">
              <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=commenters">
                <input type="hidden" name="action" value="regenerate_commenter_token"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn" type="submit">Новая ссылка</button>
              </form>
              <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=commenters" onsubmit="return confirm('Удалить пользователя?')">
                <input type="hidden" name="action" value="delete_commenter"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-danger" type="submit">Удалить доступ</button>
              </form>
            </td></tr>
          <?php endforeach; ?>
        </table>
      </section>
      <?php endif; ?>

      <?php if ($adminMode === 'comments'): ?>
      <section class="card">
        <h3>Комментарии</h3>
        <form method="get" action="admin.php" class="comment-search">
          <input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
          <input type="hidden" name="mode" value="comments">
          <input class="in" type="search" name="comment_photo" value="<?= h($commentPhotoQuery) ?>" placeholder="Поиск по имени фото">
          <input class="in" type="search" name="comment_user" value="<?= h($commentUserQuery) ?>" placeholder="Поиск по пользователю">
          <button class="btn" type="submit">Найти</button>
          <a class="btn btn-secondary" href="?token=<?= urlencode($tokenIncoming) ?>&mode=comments">Сбросить</a>
        </form>

        <?php if ($filteredComments === []): ?>
          <p class="small">Комментарии не найдены.</p>
        <?php else: ?>
          <table class="tbl"><tr><th>Фото</th><th>Пользователь</th><th>Комментарий</th><th>Дата</th><th></th></tr>
            <?php foreach($filteredComments as $c): ?>
              <tr>
                <td><?= h((string)$c['code_name']) ?></td>
                <td><?= h((string)($c['display_name'] ?? '—')) ?></td>
                <td><?= h((string)$c['comment_text']) ?></td>
                <td><?= h((string)$c['created_at']) ?></td>
                <td>
                  <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=comments" onsubmit="return confirm('Удалить комментарий?')">
                    <input type="hidden" name="action" value="delete_comment"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="comment_photo" value="<?= h($commentPhotoQuery) ?>"><input type="hidden" name="comment_user" value="<?= h($commentUserQuery) ?>">
                    <button class="btn btn-danger" type="submit">Удалить</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </section>
      <?php endif; ?>
    </main>
  </div>
</div>
<div class="lightbox" id="lightbox" hidden>
  <div class="lightbox-backdrop js-close"></div>
  <div class="lightbox-content">
    <button class="lightbox-close js-close" type="button" aria-label="Закрыть">×</button>
    <img id="lightboxImage" src="" alt="">
  </div>
</div>
<div class="modal" id="commentsModal" hidden>
  <button class="modal-backdrop js-comments-close" type="button" aria-label="Закрыть окно комментариев"></button>
  <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="commentsModalTitle">
    <div class="modal-head">
      <h3 id="commentsModalTitle" style="margin:0">Комментарии</h3>
      <button class="modal-close js-comments-close" type="button" aria-label="Закрыть окно комментариев">×</button>
    </div>
    <div id="commentsModalBody" class="small">Загрузка...</div>
  </section>
</div>
<script>
(() => {
  const setupAutosave = (formSelector) => {
    document.querySelectorAll(formSelector).forEach((form) => {
    let dirty = false;
    let busy = false;
    let timer = null;
    const status = form.querySelector('.js-save-status');
    const ajaxInput = form.querySelector('input[name="ajax"]');

    const setStatus = (text, isError = false) => {
      if (!status) return;
      status.textContent = text;
      status.style.color = isError ? '#b42318' : '#667085';
      status.style.display = text ? 'block' : 'none';
    };
    setStatus('');

    const mark = () => {
      dirty = true;
    };

    form.querySelectorAll('input,textarea,select').forEach((el) => {
      if (el.type === 'file') {
        el.addEventListener('change', () => {
          if (!el.files || el.files.length === 0) return;
          if (ajaxInput) ajaxInput.value = '0';
          setStatus('Загрузка файла…');
          form.submit();
        });
        return;
      }

      el.addEventListener('input', mark);
      el.addEventListener('change', mark);
      el.addEventListener('blur', () => queueSave(80));
    });

    form.addEventListener('focusout', () => queueSave(120));

    function queueSave(delay) {
      clearTimeout(timer);
      timer = setTimeout(() => {
        if (form.contains(document.activeElement)) return;
        submitNow();
      }, delay);
    }

    async function submitNow() {
      if (!dirty || busy) return;
      busy = true;

      try {
        if (ajaxInput) ajaxInput.value = '1';
        const fd = new FormData(form);
        const endpoint = form.getAttribute('action') || window.location.href;
        const r = await fetch(endpoint, {
          method: 'POST',
          body: fd,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        const raw = await r.text();
        let j = null;
        try {
          j = JSON.parse(raw);
        } catch {
          throw new Error(raw.slice(0, 180) || 'Некорректный ответ сервера');
        }

        if (!r.ok || !j.ok) {
          throw new Error(j?.message || 'Ошибка сохранения');
        }

        dirty = false;
        setStatus('Сохранено');
      } catch (e) {
        console.warn('save failed', e);
        setStatus('Ошибка сохранения: ' + (e?.message || 'unknown'), true);
      } finally {
        busy = false;
      }
    }
    });
  };

  setupAutosave('.js-photo-form');
  setupAutosave('.js-section-form');
  setupAutosave('.js-topic-form');

  window.confirmSectionDelete = () => {
    const first = confirm('Удалить раздел?');
    if (!first) {
      return false;
    }

    return confirm('Будут удалены все фото в разделе (и версии "до", и версии "после"). Продолжить?');
  };

  const adminToken = <?= json_encode($tokenIncoming, JSON_UNESCAPED_UNICODE) ?>;
  const commentsModal = document.getElementById('commentsModal');
  const commentsModalTitle = document.getElementById('commentsModalTitle');
  const commentsModalBody = document.getElementById('commentsModalBody');
  let activePhotoId = 0;
  let activePhotoName = '';
  let activeCommentCount = 0;

  const withFreshVersion = (url) => {
    const u = new URL(url, window.location.href);
    u.searchParams.set('v', String(Date.now()));
    return `${u.pathname}${u.search}`;
  };

  const refreshPreviewImages = (photoId, kind) => {
    document.querySelectorAll(`.js-preview-image[data-photo-id="${photoId}"][data-kind="${kind}"]`).forEach((imgEl) => {
      const src = imgEl.getAttribute('src');
      const full = imgEl.getAttribute('data-full');
      if (src) {
        imgEl.setAttribute('src', withFreshVersion(src));
      }
      if (full) {
        imgEl.setAttribute('data-full', withFreshVersion(full));
      }
    });
  };

  const upsertAfterPreview = (photoId, previewUrl) => {
    const slot = document.querySelector(`.js-after-slot[data-photo-id="${photoId}"]`);
    if (!slot) {
      return;
    }

    const rotateGroup = slot.querySelector('.js-after-rotate');
    if (rotateGroup) {
      rotateGroup.classList.remove('is-hidden');
    }

    const emptyHint = slot.querySelector('.js-after-empty');
    if (emptyHint) {
      emptyHint.remove();
    }

    let imgEl = slot.querySelector('.js-preview-image[data-kind="after"]');
    if (!imgEl) {
      imgEl = document.createElement('img');
      imgEl.className = 'js-open js-preview-image';
      imgEl.dataset.photoId = String(photoId);
      imgEl.dataset.kind = 'after';
      imgEl.style.cursor = 'zoom-in';
      imgEl.style.width = '100px';
      imgEl.style.height = '70px';
      imgEl.style.objectFit = 'cover';
      imgEl.style.border = '1px solid #e5e7eb';
      imgEl.style.borderRadius = '6px';
      slot.prepend(imgEl);
    }

    imgEl.src = previewUrl;
    imgEl.dataset.full = previewUrl;

    const pickBtn = slot.querySelector('.js-after-pick');
    if (pickBtn) {
      pickBtn.textContent = 'Изменить фото';
    }
  };

  document.querySelectorAll('.js-rotate-form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (form.dataset.busy === '1') {
        return;
      }

      const group = form.closest('.preview-actions');
      const buttons = group ? group.querySelectorAll('button') : form.querySelectorAll('button');
      form.dataset.busy = '1';
      buttons.forEach((btn) => {
        btn.disabled = true;
      });

      const fd = new FormData(form);
      fd.set('ajax', '1');
      try {
        const endpoint = form.getAttribute('action') || window.location.href;
        const r = await fetch(endpoint, {
          method: 'POST',
          body: fd,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
          },
        });

        const raw = await r.text();
        let j = null;
        try {
          j = JSON.parse(raw);
        } catch {
          throw new Error(raw.slice(0, 180) || 'Некорректный ответ сервера');
        }

        if (!r.ok || !j.ok) {
          throw new Error(j?.message || 'Ошибка поворота');
        }

        const photoId = Number(fd.get('photo_id') || 0);
        const kind = String(fd.get('kind') || '');
        if (photoId > 0 && (kind === 'before' || kind === 'after')) {
          refreshPreviewImages(photoId, kind);
        }
      } catch (err) {
        alert('Не удалось повернуть фото: ' + (err?.message || 'unknown'));
      } finally {
        form.dataset.busy = '0';
        buttons.forEach((btn) => {
          btn.disabled = false;
        });
      }
    });
  });

  document.querySelectorAll('.js-after-upload-form').forEach((form) => {
    const fileInput = form.querySelector('.js-after-file-input');
    const pickBtn = form.querySelector('.js-after-pick');
    if (!fileInput || !pickBtn) {
      return;
    }

    pickBtn.addEventListener('click', () => {
      if (form.dataset.busy === '1') {
        return;
      }
      fileInput.click();
    });

    fileInput.addEventListener('change', async () => {
      if (!fileInput.files || fileInput.files.length === 0) {
        return;
      }

      if (form.dataset.busy === '1') {
        return;
      }

      form.dataset.busy = '1';
      pickBtn.disabled = true;
      const previousText = pickBtn.textContent;
      pickBtn.textContent = 'Загрузка...';
      let uploaded = false;

      const fd = new FormData(form);
      fd.set('ajax', '1');

      try {
        const endpoint = form.getAttribute('action') || window.location.href;
        const r = await fetch(endpoint, {
          method: 'POST',
          body: fd,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
          },
        });

        const raw = await r.text();
        let j = null;
        try {
          j = JSON.parse(raw);
        } catch {
          throw new Error(raw.slice(0, 180) || 'Некорректный ответ сервера');
        }

        if (!r.ok || !j.ok) {
          throw new Error(j?.message || 'Ошибка загрузки');
        }

        const photoId = Number(fd.get('photo_id') || 0);
        if (photoId > 0 && j.preview_url) {
          upsertAfterPreview(photoId, j.preview_url);
          uploaded = true;
        }
      } catch (err) {
        alert('Не удалось загрузить фото после: ' + (err?.message || 'unknown'));
      } finally {
        form.dataset.busy = '0';
        pickBtn.disabled = false;
        pickBtn.textContent = uploaded ? 'Изменить фото' : (previousText || 'Изменить фото');
        fileInput.value = '';
      }
    });
  });

  const setTopicStatus = (editor, text, isError = false) => {
    const status = editor.querySelector('.js-topic-status');
    if (!status) return;
    status.textContent = text;
    status.style.color = isError ? '#b42318' : '#667085';
  };

  const renderTopicChips = (editor, topics) => {
    const list = editor.querySelector('.js-topic-list');
    if (!list) {
      return;
    }

    list.textContent = '';
    if (!Array.isArray(topics) || topics.length === 0) {
      return;
    }

    topics.forEach((topic) => {
      const chip = document.createElement('span');
      chip.className = 'topic-chip';
      chip.dataset.topicId = String(topic.id || 0);

      const label = document.createElement('span');
      label.textContent = topic.full_name || '';

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'js-topic-remove';
      removeBtn.dataset.topicId = String(topic.id || 0);
      removeBtn.setAttribute('aria-label', 'Убрать тематику');
      removeBtn.textContent = '×';

      chip.append(label, removeBtn);
      list.appendChild(chip);
    });
  };

  const postTopicAction = async (editor, action, topicId) => {
    const photoId = Number(editor.dataset.photoId || 0);
    const endpoint = editor.dataset.endpoint || window.location.href;
    if (photoId < 1 || topicId < 1) {
      throw new Error('Некорректные параметры');
    }

    const select = editor.querySelector('.js-topic-select');
    if (select) select.disabled = true;

    const fd = new FormData();
    fd.set('action', action);
    fd.set('token', adminToken);
    fd.set('photo_id', String(photoId));
    fd.set('topic_id', String(topicId));
    fd.set('ajax', '1');

    try {
      const r = await fetch(endpoint, {
        method: 'POST',
        body: fd,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
      });
      const raw = await r.text();
      let j = null;
      try {
        j = JSON.parse(raw);
      } catch {
        throw new Error(raw.slice(0, 180) || 'Некорректный ответ сервера');
      }

      if (!r.ok || !j.ok) {
        throw new Error(j?.message || 'Ошибка сохранения тематик');
      }

      renderTopicChips(editor, j.topics || []);
      setTopicStatus(editor, j.message || 'Сохранено');
      if (select) {
        select.value = '';
      }
    } finally {
      if (select) select.disabled = false;
    }
  };

  document.querySelectorAll('.js-topic-editor').forEach((editor) => {
    const select = editor.querySelector('.js-topic-select');
    if (!select) {
      return;
    }

    select.addEventListener('change', () => {
      const topicId = Number(select.value || 0);
      if (topicId < 1) {
        return;
      }

      postTopicAction(editor, 'attach_photo_topic', topicId)
        .catch((err) => setTopicStatus(editor, err?.message || 'Ошибка добавления', true));
    });
  });

  document.addEventListener('click', (e) => {
    const removeBtn = e.target.closest('.js-topic-remove');
    if (removeBtn) {
      const editor = removeBtn.closest('.js-topic-editor');
      if (!editor) return;
      const topicId = Number(removeBtn.dataset.topicId || 0);
      if (topicId < 1) return;

      postTopicAction(editor, 'detach_photo_topic', topicId)
        .catch((err) => setTopicStatus(editor, err?.message || 'Ошибка удаления', true));
    }
  });

  const closeCommentsModal = () => {
    if (!commentsModal) return;
    commentsModal.hidden = true;
    document.body.style.overflow = '';
  };

  const renderCommentIndicator = (photoId, photoName, count) => {
    document.querySelectorAll(`.js-comment-indicator[data-photo-id="${photoId}"]`).forEach((wrap) => {
      wrap.textContent = '';
      if (count > 0) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-secondary btn-xs js-open-comments';
        btn.dataset.photoId = String(photoId);
        btn.dataset.photoName = photoName;
        btn.dataset.commentCount = String(count);
        btn.textContent = `Комментарии (${count})`;
        wrap.appendChild(btn);
      } else {
        const span = document.createElement('span');
        span.className = 'small';
        span.textContent = 'Комментариев нет';
        wrap.appendChild(span);
      }
    });
  };

  const renderCommentsList = (comments) => {
    if (!commentsModalBody) return;
    commentsModalBody.textContent = '';

    if (!Array.isArray(comments) || comments.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'small';
      empty.textContent = 'К этой карточке комментариев пока нет.';
      commentsModalBody.appendChild(empty);
      return;
    }

    comments.forEach((item) => {
      const row = document.createElement('article');
      row.className = 'comment-row';
      row.dataset.commentId = String(item.id || 0);

      const head = document.createElement('div');
      head.className = 'comment-row-head';

      const meta = document.createElement('div');
      meta.className = 'small';
      const displayName = item.display_name || '—';
      const createdAt = item.created_at || '';
      meta.textContent = `${displayName}${createdAt ? ' · ' + createdAt : ''}`;

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-danger btn-xs js-delete-comment';
      del.dataset.commentId = String(item.id || 0);
      del.textContent = 'Удалить';

      head.append(meta, del);

      const body = document.createElement('div');
      body.className = 'comment-row-body';
      body.textContent = item.comment_text || '';

      row.append(head, body);
      commentsModalBody.appendChild(row);
    });
  };

  const openCommentsModal = async (photoId, photoName, count) => {
    if (!commentsModal || !commentsModalBody || !commentsModalTitle) return;

    activePhotoId = photoId;
    activePhotoName = photoName;
    activeCommentCount = Number.isFinite(count) ? count : 0;
    commentsModalTitle.textContent = `Комментарии к фото: ${photoName}`;
    commentsModalBody.textContent = 'Загрузка...';
    commentsModal.hidden = false;
    document.body.style.overflow = 'hidden';

    try {
      const endpoint = `${window.location.pathname}?token=${encodeURIComponent(adminToken)}&action=photo_comments&photo_id=${photoId}`;
      const r = await fetch(endpoint, { headers: { 'Accept': 'application/json' } });
      const j = await r.json();
      if (!r.ok || !j.ok) {
        throw new Error(j?.message || 'Не удалось загрузить комментарии');
      }
      renderCommentsList(j.comments || []);
    } catch (e) {
      commentsModalBody.textContent = `Ошибка загрузки: ${e?.message || 'unknown'}`;
    }
  };

  document.addEventListener('click', (e) => {
    const openBtn = e.target.closest('.js-open-comments');
    if (openBtn) {
      const photoId = Number(openBtn.dataset.photoId || 0);
      if (photoId > 0) {
        openCommentsModal(photoId, openBtn.dataset.photoName || '', Number(openBtn.dataset.commentCount || 0));
      }
      return;
    }

    if (e.target.closest('.js-comments-close')) {
      closeCommentsModal();
      return;
    }

    const delBtn = e.target.closest('.js-delete-comment');
    if (!delBtn) {
      return;
    }

    const commentId = Number(delBtn.dataset.commentId || 0);
    if (commentId < 1) {
      return;
    }

    if (!confirm('Удалить комментарий?')) {
      return;
    }

    const fd = new FormData();
    fd.set('action', 'delete_comment');
    fd.set('token', adminToken);
    fd.set('id', String(commentId));
    fd.set('ajax', '1');

    fetch(`${window.location.pathname}?token=${encodeURIComponent(adminToken)}&mode=comments`, {
      method: 'POST',
      body: fd,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
      },
    }).then(async (r) => {
      const j = await r.json();
      if (!r.ok || !j.ok) {
        throw new Error(j?.message || 'Ошибка удаления');
      }

      const row = delBtn.closest('.comment-row');
      if (row) {
        row.remove();
      }
      activeCommentCount = Math.max(0, activeCommentCount - 1);
      renderCommentIndicator(activePhotoId, activePhotoName, activeCommentCount);

      if (commentsModalBody && commentsModalBody.querySelectorAll('.comment-row').length === 0) {
        renderCommentsList([]);
      }
    }).catch((err) => {
      alert('Не удалось удалить комментарий: ' + (err?.message || 'unknown'));
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && commentsModal && !commentsModal.hidden) {
      closeCommentsModal();
    }
  });

  const lightbox = document.getElementById('lightbox');
  const img = document.getElementById('lightboxImage');
  if (lightbox && img) {
    document.addEventListener('click', (e) => {
      const openEl = e.target.closest('.js-open');
      if (!openEl) {
        return;
      }
      const src = openEl.getAttribute('data-full');
      if (!src) return;
      img.src = src;
      lightbox.hidden = false;
      document.body.style.overflow = 'hidden';
    });

    lightbox.querySelectorAll('.js-close').forEach((el) => el.addEventListener('click', () => {
      lightbox.hidden = true; img.src = ''; document.body.style.overflow = '';
    }));
  }
})();
</script>
</body></html>
