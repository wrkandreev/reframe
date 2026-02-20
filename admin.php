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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $isAjax = (string)($_POST['ajax'] ?? '') === '1'
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    try {
        if ($action === 'create_section') {
            $name = trim((string)($_POST['name'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 1000);
            if ($name === '') throw new RuntimeException('Название раздела пустое');
            sectionCreate($name, $sort);
            $message = 'Раздел создан';
        }

        if ($action === 'update_welcome') {
            $text = trim((string)($_POST['welcome_text'] ?? ''));
            settingSet('welcome_text', $text);
            $message = 'Приветственное сообщение сохранено';
        }

        if ($action === 'upload_before_bulk') {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            if ($sectionId < 1 || !sectionById($sectionId)) throw new RuntimeException('Выбери раздел');
            if (!isset($_FILES['before_bulk'])) throw new RuntimeException('Файлы не переданы');

            $result = saveBulkBefore($_FILES['before_bulk'], $sectionId);
            $message = 'Загружено: ' . $result['ok'];
            $errors = array_merge($errors, $result['errors']);
        }

        if ($action === 'photo_update') {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $code = trim((string)($_POST['code_name'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 1000);
            $descr = trim((string)($_POST['description'] ?? ''));
            $descr = $descr !== '' ? $descr : null;

            if ($photoId < 1) throw new RuntimeException('Некорректный photo_id');
            if ($code === '') throw new RuntimeException('Код фото пустой');

            $st = db()->prepare('UPDATE photos SET code_name=:c, sort_order=:s, description=:d WHERE id=:id');
            $st->execute(['c' => $code, 's' => $sort, 'd' => $descr, 'id' => $photoId]);

            if (isset($_FILES['after']) && (int)($_FILES['after']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $p = photoById($photoId);
                if (!$p) throw new RuntimeException('Фото не найдено');
                $up = saveSingleImage($_FILES['after'], $code . 'р', (int)$p['section_id']);
                photoFileUpsert($photoId, 'after', $up['path'], $up['mime'], $up['size']);
            }

            $message = 'Фото обновлено';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        if ($action === 'photo_delete') {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            if ($photoId > 0) {
                $p = photoById($photoId);
                if ($p) {
                    foreach (['before_path', 'after_path'] as $k) {
                        if (!empty($p[$k])) {
                            $abs = __DIR__ . '/' . ltrim((string)$p[$k], '/');
                            if (is_file($abs)) @unlink($abs);
                        }
                    }
                }
                $st = db()->prepare('DELETE FROM photos WHERE id=:id');
                $st->execute(['id' => $photoId]);
                $message = 'Фото удалено';
            }
        }

        if ($action === 'create_commenter') {
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            if ($displayName === '') throw new RuntimeException('Укажи имя комментатора');
            $u = commenterCreate($displayName);
            $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?viewer=' . urlencode($u['token']);
            $message = 'Комментатор создан: ' . $u['display_name'] . ' | ссылка: ' . $link;
        }

        if ($action === 'delete_commenter') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                commenterDelete($id);
                $message = 'Комментатор удалён (доступ отозван)';
            }
        }

        if ($action === 'delete_comment') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                commentDelete($id);
                $message = 'Комментарий удалён';
            }
        }
    } catch (Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $errors[] = $e->getMessage();
    }
}

$sections = sectionsAll();
$activeSectionId = (int)($_GET['section_id'] ?? ($_POST['section_id'] ?? ($sections[0]['id'] ?? 0)));
$photos = $activeSectionId > 0 ? photosBySection($activeSectionId) : [];
$commenters = commentersAll();
$latestComments = commentsLatest(80);
$welcomeText = settingGet('welcome_text', 'Добро пожаловать в галерею. Выберите раздел слева, чтобы посмотреть фотографии.');
$adminMode = (string)($_GET['mode'] ?? 'media');
if (!in_array($adminMode, ['media', 'comments'], true)) {
    $adminMode = 'media';
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function assetUrl(string $path): string { $f=__DIR__ . '/' . ltrim($path,'/'); $v=is_file($f)?(string)filemtime($f):(string)time(); return $path . '?v=' . rawurlencode($v); }

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
            if ($base === '') $base = 'photo';

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
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Ошибка загрузки');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > MAX_UPLOAD_BYTES) throw new RuntimeException('Превышен лимит 3 МБ');

    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Некорректный источник');

    $mime = mime_content_type($tmp) ?: '';
    if (!isset($allowedMime[$mime])) throw new RuntimeException('Недопустимый тип файла');

    $safeBase = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $baseName) ?? 'photo';
    $safeBase = trim($safeBase, '._-');
    if ($safeBase === '') $safeBase = 'photo';

    $ext = $allowedMime[$mime];
    $dir = __DIR__ . '/photos/section_' . $sectionId;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = uniqueName($dir, $safeBase, $ext);
    $dest = $dir . '/' . $name;

    if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('Не удалось сохранить файл');

    return [
        'path' => 'photos/section_' . $sectionId . '/' . $name,
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
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Админка</title>
  <link rel="icon" type="image/svg+xml" href="<?= h(assetUrl('favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= h(assetUrl('style.css')) ?>">
  <style>.wrap{max-width:1180px;margin:0 auto;padding:24px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;gap:12px;grid-template-columns:320px 1fr}.in{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px}.btn{border:0;background:#1f6feb;color:#fff;padding:8px 12px;border-radius:8px;cursor:pointer}.btn-danger{background:#b42318}.ok{background:#ecfdf3;padding:8px;border-radius:8px;margin-bottom:8px}.err{background:#fef2f2;padding:8px;border-radius:8px;margin-bottom:8px}.tbl{width:100%;border-collapse:collapse}.tbl td,.tbl th{padding:8px;border-bottom:1px solid #eee;vertical-align:top}.sec a{display:block;padding:8px 10px;border-radius:8px;text-decoration:none;color:#111}.sec a.active{background:#eef4ff;color:#1f6feb}.small{font-size:12px;color:#667085}</style>
</head>
<body><div class="wrap">
  <h1>Админка</h1>
  <?php if ($message!==''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
  <?php foreach($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>

  <div class="grid">
    <aside>
      <section class="card">
        <h3>Меню</h3>
        <div class="sec">
          <a class="<?= $adminMode==='media'?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=media<?= $activeSectionId>0 ? '&section_id='.(int)$activeSectionId : '' ?>">Разделы и фото</a>
          <a class="<?= $adminMode==='comments'?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&mode=comments">Комментаторы и комментарии</a>
        </div>
      </section>

      <?php if ($adminMode === 'media'): ?>
      <section class="card">
        <h3>Разделы</h3>
        <div class="sec">
          <?php foreach($sections as $s): ?>
            <a class="<?= (int)$s['id']===$activeSectionId?'active':'' ?>" href="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$s['id'] ?>"><?= h((string)$s['name']) ?> <span class="small">(<?= (int)$s['photos_count'] ?>)</span></a>
          <?php endforeach; ?>
        </div>
        <hr style="border:none;border-top:1px solid #eee;margin:12px 0">
        <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=media">
          <input type="hidden" name="action" value="create_section"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
          <p><input class="in" name="name" placeholder="Новый раздел" required></p>
          <p><input class="in" type="number" name="sort_order" value="1000"></p>
          <button class="btn" type="submit">Создать раздел</button>
        </form>
      </section>

      <?php endif; ?>

      <?php if ($adminMode === 'comments'): ?>
      <section class="card">
        <h3>Комментаторы</h3>
        <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=comments">
          <input type="hidden" name="action" value="create_commenter"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
          <p><input class="in" name="display_name" placeholder="Имя" required></p>
          <button class="btn" type="submit">Создать</button>
        </form>
        <div class="small" style="margin-top:8px">Ссылка доступа показывается в зелёном сообщении после создания.</div>
      </section>
      <?php endif; ?>
    </aside>

    <main>
      <?php if ($adminMode === 'media'): ?>
      <section class="card">
        <h3>Приветственное сообщение (публичная часть)</h3>
        <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=media<?= $activeSectionId>0 ? '&section_id='.(int)$activeSectionId : '' ?>">
          <input type="hidden" name="action" value="update_welcome"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>">
          <p><textarea class="in" name="welcome_text" rows="3" placeholder="Текст приветствия"><?= h($welcomeText) ?></textarea></p>
          <button class="btn" type="submit">Сохранить приветствие</button>
        </form>
      </section>

      <section class="card">
        <h3>Загрузка фото “до” в выбранный раздел</h3>
        <?php if ($activeSectionId > 0): ?>
          <form method="post" enctype="multipart/form-data" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>">
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
        <table class="tbl">
          <tr><th>До</th><th>После</th><th>Поля</th><th>Действия</th></tr>
          <?php foreach($photos as $p): ?>
            <tr>
              <td><?php if (!empty($p['before_file_id'])): ?><img class="js-open" data-full="index.php?action=image&file_id=<?= (int)$p['before_file_id'] ?>" src="index.php?action=image&file_id=<?= (int)$p['before_file_id'] ?>" style="cursor:zoom-in;width:100px;height:70px;object-fit:cover;border:1px solid #e5e7eb;border-radius:6px"><?php endif; ?></td>
              <td><?php if (!empty($p['after_file_id'])): ?><img class="js-open" data-full="index.php?action=image&file_id=<?= (int)$p['after_file_id'] ?>" src="index.php?action=image&file_id=<?= (int)$p['after_file_id'] ?>" style="cursor:zoom-in;width:100px;height:70px;object-fit:cover;border:1px solid #e5e7eb;border-radius:6px"><?php endif; ?></td>
              <td>
                <form class="js-photo-form" method="post" enctype="multipart/form-data" action="admin.php?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=media">
                  <input type="hidden" name="action" value="photo_update"><input type="hidden" name="ajax" value="1"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                  <p><input class="in" name="code_name" value="<?= h((string)$p['code_name']) ?>"></p>
                  <p><input class="in" type="number" name="sort_order" value="<?= (int)$p['sort_order'] ?>"></p>
                  <p><textarea class="in" name="description" placeholder="Комментарий"><?= h((string)($p['description'] ?? '')) ?></textarea></p>
                  <p class="small">Фото после (опционально): <input type="file" name="after" accept="image/jpeg,image/png,image/webp,image/gif"></p>
                  <div class="small js-save-status">Сохраняется автоматически при выходе из карточки.</div>
                </form>
              </td>
              <td>
                <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&section_id=<?= (int)$activeSectionId ?>&mode=media" onsubmit="return confirm('Удалить фото?')">
                  <input type="hidden" name="action" value="photo_delete"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-danger" type="submit">Удалить</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </section>

      <?php endif; ?>

      <?php if ($adminMode === 'comments'): ?>
      <section class="card">
        <h3>Комментаторы и комментарии</h3>
        <table class="tbl"><tr><th>Пользователь</th><th>Действие</th></tr>
          <?php foreach($commenters as $u): ?>
            <tr><td><?= h((string)$u['display_name']) ?></td><td>
              <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=comments" onsubmit="return confirm('Удалить пользователя?')">
                <input type="hidden" name="action" value="delete_commenter"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-danger" type="submit">Удалить доступ</button>
              </form>
            </td></tr>
          <?php endforeach; ?>
        </table>
        <hr style="border:none;border-top:1px solid #eee;margin:12px 0">
        <table class="tbl"><tr><th>Фото</th><th>Пользователь</th><th>Комментарий</th><th></th></tr>
          <?php foreach($latestComments as $c): ?>
            <tr>
              <td><?= h((string)$c['code_name']) ?></td>
              <td><?= h((string)($c['display_name'] ?? '—')) ?></td>
              <td><?= h((string)$c['comment_text']) ?></td>
              <td>
                <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&mode=comments" onsubmit="return confirm('Удалить комментарий?')">
                  <input type="hidden" name="action" value="delete_comment"><input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-danger" type="submit">Удалить</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
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
<script>
(() => {
  const forms = document.querySelectorAll('.js-photo-form');

  forms.forEach((form) => {
    let dirty = false;
    let busy = false;
    let timer = null;
    const status = form.querySelector('.js-save-status');
    const ajaxInput = form.querySelector('input[name="ajax"]');

    const setStatus = (text, isError = false) => {
      if (!status) return;
      status.textContent = text;
      status.style.color = isError ? '#b42318' : '#667085';
    };

    const mark = () => {
      dirty = true;
      setStatus('Есть несохранённые изменения…');
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
      setStatus('Сохраняю…');

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

  const lightbox = document.getElementById('lightbox');
  const img = document.getElementById('lightboxImage');
  if (lightbox && img) {
    document.querySelectorAll('.js-open').forEach((el) => {
      el.addEventListener('click', () => {
        const src = el.getAttribute('data-full');
        if (!src) return;
        img.src = src;
        lightbox.hidden = false;
        document.body.style.overflow = 'hidden';
      });
    });
    lightbox.querySelectorAll('.js-close').forEach((el) => el.addEventListener('click', () => {
      lightbox.hidden = true; img.src = ''; document.body.style.overflow = '';
    }));
  }
})();
</script>
</body></html>
