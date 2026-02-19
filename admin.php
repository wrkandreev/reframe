<?php

declare(strict_types=1);

const MAX_UPLOAD_BYTES = 3 * 1024 * 1024;

$rootDir = __DIR__;
$photosDir = $rootDir . '/photos';
$thumbsDir = $rootDir . '/thumbs';
$dataDir = $rootDir . '/data';
$sortFile = $dataDir . '/sort.json';

@mkdir($photosDir, 0775, true);
@mkdir($thumbsDir, 0775, true);
@mkdir($dataDir, 0775, true);

$configPath = __DIR__ . '/deploy-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'deploy-config.php not found';
    exit;
}
$config = require $configPath;
$tokenExpected = (string)($config['token'] ?? '');
$tokenIncoming = (string)($_REQUEST['token'] ?? '');

if ($tokenExpected === '' || !hash_equals($tokenExpected, $tokenIncoming)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$sortData = loadSortData($sortFile);
$sortData = reconcileSortData($photosDir, $sortData);
saveSortData($sortFile, $sortData);
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_category') {
        $name = sanitizeCategoryName((string)($_POST['category_name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Некорректное имя папки.';
        } else {
            $dir = $photosDir . '/' . $name;
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                $errors[] = 'Не удалось создать папку.';
            } else {
                $message = 'Папка создана: ' . $name;
                $sortData['categories'][$name] = nextSortIndex($sortData['categories']);
            }
        }
    }

    if ($action === 'category_update') {
        $current = sanitizeCategoryName((string)($_POST['category_current'] ?? ''));
        $newName = sanitizeCategoryName((string)($_POST['category_new_name'] ?? ''));
        $sortIndex = (int)($_POST['category_sort'] ?? 1000);

        if ($current === '' || !is_dir($photosDir . '/' . $current)) {
            $errors[] = 'Категория не найдена.';
        } else {
            if ($newName !== '' && $newName !== $current) {
                $oldDir = $photosDir . '/' . $current;
                $newDir = $photosDir . '/' . $newName;
                $oldThumb = $thumbsDir . '/' . $current;
                $newThumb = $thumbsDir . '/' . $newName;

                if (is_dir($newDir)) {
                    $errors[] = 'Категория с таким именем уже существует.';
                } else {
                    rename($oldDir, $newDir);
                    if (is_dir($oldThumb)) {
                        @rename($oldThumb, $newThumb);
                    }

                    if (isset($sortData['categories'][$current])) {
                        $sortData['categories'][$newName] = $sortData['categories'][$current];
                        unset($sortData['categories'][$current]);
                    }
                    if (isset($sortData['photos'][$current])) {
                        $sortData['photos'][$newName] = $sortData['photos'][$current];
                        unset($sortData['photos'][$current]);
                    }
                    $current = $newName;
                    $message = 'Категория переименована.';
                }
            }

            $sortData['categories'][$current] = $sortIndex;
            $message = $message ?: 'Категория обновлена.';
        }
    }

    if ($action === 'category_delete') {
        $category = sanitizeCategoryName((string)($_POST['category_current'] ?? ''));
        if ($category === '' || !is_dir($photosDir . '/' . $category)) {
            $errors[] = 'Категория не найдена.';
        } else {
            rrmdir($photosDir . '/' . $category);
            rrmdir($thumbsDir . '/' . $category);
            unset($sortData['categories'][$category], $sortData['photos'][$category]);
            $message = 'Категория удалена: ' . $category;
        }
    }

    if ($action === 'upload') {
        $category = sanitizeCategoryName((string)($_POST['category'] ?? ''));
        if ($category === '' || !is_dir($photosDir . '/' . $category)) {
            $errors[] = 'Выберите существующую категорию.';
        } elseif (!isset($_FILES['photos'])) {
            $errors[] = 'Файлы не переданы.';
        } else {
            $result = handleUploads($_FILES['photos'], $photosDir . '/' . $category, $sortData, $category);
            $errors = array_merge($errors, $result['errors']);
            if ($result['ok'] > 0) {
                $message = 'Загружено: ' . $result['ok'];
            }
        }
    }

    if ($action === 'photo_update') {
        $category = sanitizeCategoryName((string)($_POST['category'] ?? ''));
        $currentFile = basename((string)($_POST['photo_current'] ?? ''));
        $newBase = sanitizeFileBase((string)($_POST['photo_new_name'] ?? ''));
        $sortIndex = (int)($_POST['photo_sort'] ?? 1000);

        $src = $photosDir . '/' . $category . '/' . $currentFile;
        if ($category === '' || $currentFile === '' || !is_file($src)) {
            $errors[] = 'Фото не найдено.';
        } else {
            $finalName = $currentFile;
            if ($newBase !== '') {
                $ext = strtolower(pathinfo($currentFile, PATHINFO_EXTENSION));
                $candidate = uniqueFileNameForRename($photosDir . '/' . $category, $newBase, $ext, $currentFile);
                if ($candidate !== $currentFile) {
                    $dst = $photosDir . '/' . $category . '/' . $candidate;
                    if (@rename($src, $dst)) {
                        $oldThumb = $thumbsDir . '/' . $category . '/' . pathinfo($currentFile, PATHINFO_FILENAME) . '.jpg';
                        $newThumb = $thumbsDir . '/' . $category . '/' . pathinfo($candidate, PATHINFO_FILENAME) . '.jpg';
                        if (is_file($oldThumb)) {
                            @rename($oldThumb, $newThumb);
                        }
                        if (isset($sortData['photos'][$category][$currentFile])) {
                            $sortData['photos'][$category][$candidate] = $sortData['photos'][$category][$currentFile];
                            unset($sortData['photos'][$category][$currentFile]);
                        }
                        $finalName = $candidate;
                    }
                }
            }

            $sortData['photos'][$category][$finalName] = $sortIndex;
            $message = 'Фото обновлено.';
        }
    }

    if ($action === 'photo_delete') {
        $category = sanitizeCategoryName((string)($_POST['category'] ?? ''));
        $file = basename((string)($_POST['photo_current'] ?? ''));
        $src = $photosDir . '/' . $category . '/' . $file;
        if ($category === '' || $file === '' || !is_file($src)) {
            $errors[] = 'Фото не найдено.';
        } else {
            @unlink($src);
            $thumb = $thumbsDir . '/' . $category . '/' . pathinfo($file, PATHINFO_FILENAME) . '.jpg';
            if (is_file($thumb)) {
                @unlink($thumb);
            }
            unset($sortData['photos'][$category][$file]);
            $message = 'Фото удалено.';
        }
    }

    saveSortData($sortFile, $sortData);
}

$categories = listCategories($photosDir, $sortData);
$selectedCategory = sanitizeCategoryName((string)($_GET['edit_category'] ?? ($_POST['category'] ?? '')));
$photos = $selectedCategory !== '' ? listPhotos($photosDir, $thumbsDir, $selectedCategory, $sortData) : [];

?><!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Админка галереи</title>
<link rel="stylesheet" href="style.css">
<style>
.admin-wrap{max-width:1150px;margin:0 auto;padding:24px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:14px}
.grid{display:grid;gap:12px;grid-template-columns:1fr 1fr}.full{grid-column:1/-1}.in{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px}
.btn{border:0;background:#1f6feb;color:#fff;padding:8px 12px;border-radius:8px;cursor:pointer}.btn-danger{background:#b42318}.muted{color:#667085;font-size:13px}
.table{width:100%;border-collapse:collapse}.table td,.table th{padding:8px;border-bottom:1px solid #eee;vertical-align:top}.ok{background:#ecfdf3;padding:8px;border-radius:8px;margin-bottom:8px}.err{background:#fef2f2;padding:8px;border-radius:8px;margin-bottom:8px}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
</style>
</head><body><div class="admin-wrap">
<h1>Админка галереи</h1>
<p><a href="./">← В галерею</a></p>
<?php if ($message !== ''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>

<div class="grid">
  <section class="card">
    <h3>Создать папку</h3>
    <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>">
      <input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="action" value="create_category">
      <input class="in" name="category_name" placeholder="например: Тест" required>
      <p style="margin-top:8px"><button class="btn" type="submit">Создать</button></p>
    </form>
  </section>

  <section class="card full">
    <h3>Категории (редактирование / сортировка / удаление)</h3>
    <table class="table"><tr><th>Категория</th><th>Порядок</th><th>Новое имя</th><th>Действия</th></tr>
      <?php foreach ($categories as $c): ?>
      <tr>
        <td><a href="?token=<?= urlencode($tokenIncoming) ?>&edit_category=<?= urlencode($c) ?>"><?= h($c) ?></a></td>
        <td>
          <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>">
            <input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="action" value="category_update">
            <input type="hidden" name="category_current" value="<?= h($c) ?>">
            <input class="in" name="category_sort" type="number" value="<?= (int)($sortData['categories'][$c] ?? 1000) ?>">
        </td>
        <td><input class="in" name="category_new_name" value="<?= h($c) ?>"></td>
        <td style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn" type="submit">Сохранить</button>
          </form>
          <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>" onsubmit="return confirm('Удалить категорию и все фото?')">
            <input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="action" value="category_delete"><input type="hidden" name="category_current" value="<?= h($c) ?>">
            <button class="btn btn-danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="card full">
    <h3>Фото в категории: <?= h($selectedCategory ?: '—') ?></h3>
    <?php if ($selectedCategory !== ''): ?>
      <form method="post" enctype="multipart/form-data" action="?token=<?= urlencode($tokenIncoming) ?>&edit_category=<?= urlencode($selectedCategory) ?>" style="margin:10px 0 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="action" value="upload">
        <input type="hidden" name="category" value="<?= h($selectedCategory) ?>">
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
        <button class="btn" type="submit">Загрузить в «<?= h($selectedCategory) ?>»</button>
      </form>
      <p class="muted" style="margin-top:-6px">Только JPG/PNG/WEBP/GIF, максимум 3 МБ на файл.</p>
    <?php endif; ?>
    <?php if ($selectedCategory === ''): ?>
      <p class="muted">Сначала выбери категорию в блоке выше (клик по её названию).</p>
    <?php elseif ($photos === []): ?>
      <p class="muted">В категории пока нет фото.</p>
    <?php else: ?>
      <table class="table"><tr><th>Превью</th><th>Фото</th><th>Порядок</th><th>Новое имя (без расширения)</th><th>Действия</th></tr>
      <?php foreach ($photos as $p): ?>
        <tr>
          <td><?php if ($p['thumb'] !== ''): ?><img src="<?= h($p['thumb']) ?>" alt="" style="width:78px;height:52px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb"><?php endif; ?></td>
          <td><?= h($p['file']) ?></td>
          <td>
            <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&edit_category=<?= urlencode($selectedCategory) ?>">
              <input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="action" value="photo_update">
              <input type="hidden" name="category" value="<?= h($selectedCategory) ?>"><input type="hidden" name="photo_current" value="<?= h($p['file']) ?>">
              <input class="in" type="number" name="photo_sort" value="<?= (int)$p['sort'] ?>">
          </td>
          <td><input class="in" name="photo_new_name" value="<?= h(pathinfo($p['file'], PATHINFO_FILENAME)) ?>"></td>
          <td style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn" type="submit">Сохранить</button>
            </form>
            <form method="post" action="?token=<?= urlencode($tokenIncoming) ?>&edit_category=<?= urlencode($selectedCategory) ?>" onsubmit="return confirm('Удалить фото?')">
              <input type="hidden" name="token" value="<?= h($tokenIncoming) ?>"><input type="hidden" name="action" value="photo_delete">
              <input type="hidden" name="category" value="<?= h($selectedCategory) ?>"><input type="hidden" name="photo_current" value="<?= h($p['file']) ?>">
              <button class="btn btn-danger" type="submit">Удалить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </section>
</div></div></body></html>
<?php
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sanitizeCategoryName(string $name): string { $name=trim($name); $name=preg_replace('/[^\p{L}\p{N}\s._-]+/u','',$name)??''; return trim($name,". \t\n\r\0\x0B"); }
function sanitizeFileBase(string $name): string { $name=trim($name); $name=preg_replace('/[^\p{L}\p{N}._-]+/u','_',$name)??''; return trim($name,'._-'); }
function loadSortData(string $file): array { if(!is_file($file)) return ['categories'=>[],'photos'=>[]]; $d=json_decode((string)file_get_contents($file),true); return is_array($d)?['categories'=>(array)($d['categories']??[]),'photos'=>(array)($d['photos']??[])]:['categories'=>[],'photos'=>[]]; }
function saveSortData(string $file, array $data): void { file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); }
function nextSortIndex(array $map): int { return $map===[]?10:((int)max(array_map('intval',$map))+10); }
function listCategories(string $photosDir, array $sortData): array { $out=[]; foreach((@scandir($photosDir)?:[]) as $x){ if($x==='.'||$x==='..')continue; if(is_dir($photosDir.'/'.$x))$out[]=$x; } usort($out, fn($a,$b)=>((int)($sortData['categories'][$a]??1000)<=> (int)($sortData['categories'][$b]??1000)) ?: strnatcasecmp($a,$b)); return $out; }
function listPhotos(string $photosDir, string $thumbsDir, string $category, array $sortData): array { $out=[]; $dir=$photosDir.'/'.$category; foreach((@scandir($dir)?:[]) as $f){ if($f==='.'||$f==='..')continue; $p=$dir.'/'.$f; if(!is_file($p)||!isImageExt($f)) continue; $thumbAbs=$thumbsDir.'/'.$category.'/'.pathinfo($f, PATHINFO_FILENAME).'.jpg'; $thumb=is_file($thumbAbs)?('thumbs/'.rawurlencode($category).'/'.rawurlencode(pathinfo($f, PATHINFO_FILENAME).'.jpg')):''; $out[]=['file'=>$f,'sort'=>(int)($sortData['photos'][$category][$f]??1000),'thumb'=>$thumb]; } usort($out, fn($a,$b)=>($a['sort']<=>$b['sort']) ?: strnatcasecmp($a['file'],$b['file'])); return $out; }

function reconcileSortData(string $photosDir, array $sortData): array {
    $clean=['categories'=>[],'photos'=>[]];
    $cats=[];
    foreach((@scandir($photosDir)?:[]) as $c){
        if($c==='.'||$c==='..') continue;
        if(!is_dir($photosDir.'/'.$c)) continue;
        $cats[]=$c;
    }
    foreach($cats as $c){
        $clean['categories'][$c]=(int)($sortData['categories'][$c] ?? 1000);
        $clean['photos'][$c]=[];
        foreach((@scandir($photosDir.'/'.$c)?:[]) as $f){
            if($f==='.'||$f==='..') continue;
            if(!is_file($photosDir.'/'.$c.'/'.$f) || !isImageExt($f)) continue;
            $clean['photos'][$c][$f]=(int)($sortData['photos'][$c][$f] ?? 1000);
        }
    }
    return $clean;
}
function isImageExt(string $file): bool { return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp','gif'], true); }
function rrmdir(string $dir): void { if(!is_dir($dir)) return; $it=scandir($dir)?:[]; foreach($it as $x){ if($x==='.'||$x==='..')continue; $p=$dir.'/'.$x; if(is_dir($p)) rrmdir($p); else @unlink($p);} @rmdir($dir); }
function uniqueFileNameForRename(string $dir,string $base,string $ext,string $current): string{ $n=0; do{ $cand=$n===0?"{$base}.{$ext}":"{$base}_{$n}.{$ext}"; if($cand===$current||!file_exists($dir.'/'.$cand)) return $cand; $n++; }while(true); }
function handleUploads(array $files, string $targetDir, array &$sortData, string $category): array {
    $allowedMime=['image/jpeg','image/png','image/webp','image/gif']; $allowedExt=['jpg','jpeg','png','webp','gif']; $ok=0; $errors=[];
    $names=$files['name']??[]; $tmp=$files['tmp_name']??[]; $sizes=$files['size']??[]; $errs=$files['error']??[];
    if(!is_array($names)){ $names=[$names]; $tmp=[$tmp]; $sizes=[$sizes]; $errs=[$errs]; }
    $finfo=finfo_open(FILEINFO_MIME_TYPE);
    foreach($names as $i=>$orig){ if((int)($errs[$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){$errors[]="{$orig}: ошибка загрузки";continue;}
        $size=(int)($sizes[$i]??0); if($size<1||$size>MAX_UPLOAD_BYTES){$errors[]="{$orig}: >3MB";continue;}
        $tmpFile=(string)($tmp[$i]??''); if($tmpFile===''||!is_uploaded_file($tmpFile)){ $errors[]="{$orig}: источник"; continue;}
        $mime=$finfo?(string)finfo_file($finfo,$tmpFile):''; if(!in_array($mime,$allowedMime,true)){ $errors[]="{$orig}: тип {$mime}"; continue;}
        $ext=strtolower(pathinfo((string)$orig, PATHINFO_EXTENSION)); if(!in_array($ext,$allowedExt,true)){ $errors[]="{$orig}: расширение"; continue;}
        $base=sanitizeFileBase(pathinfo((string)$orig, PATHINFO_FILENAME)); if($base==='')$base='photo'; $name=uniqueFileNameForRename($targetDir,$base,$ext,'');
        if(!move_uploaded_file($tmpFile,$targetDir.'/'.$name)){ $errors[]="{$orig}: не сохранить"; continue; }
        $sortData['photos'][$category][$name]=nextSortIndex((array)($sortData['photos'][$category]??[])); $ok++;
    }
    if($finfo)finfo_close($finfo);
    return ['ok'=>$ok,'errors'=>$errors];
}
