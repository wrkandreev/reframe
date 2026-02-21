<?php

declare(strict_types=1);

function adminHandlePostAction(string $action, bool $isAjax, string $projectRoot, array $deployOptions = []): array
{
    $message = '';
    $errors = [];
    $deployStatus = null;
    $deployOutput = '';

    switch ($action) {
        case 'create_section': {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Название раздела пустое');
            }
            $sort = nextSectionSortOrder();
            sectionCreate($name, $sort);
            $message = 'Раздел создан';
            break;
        }

        case 'update_section': {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 1000);
            if ($sectionId < 1) {
                throw new RuntimeException('Некорректный раздел');
            }
            if ($name === '') {
                throw new RuntimeException('Название раздела пустое');
            }
            if (!sectionById($sectionId)) {
                throw new RuntimeException('Раздел не найден');
            }
            sectionUpdate($sectionId, $name, $sort);
            $message = 'Раздел обновлён';
            if ($isAjax) {
                adminJsonResponse(['ok' => true, 'message' => $message]);
            }
            break;
        }

        case 'create_topic': {
            $name = trim((string)($_POST['name'] ?? ''));
            $parentId = (int)($_POST['parent_id'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Название тематики пустое');
            }

            if ($parentId > 0) {
                $parent = topicById($parentId);
                if (!$parent) {
                    throw new RuntimeException('Родительская тематика не найдена');
                }
                if (!empty($parent['parent_id'])) {
                    throw new RuntimeException('Разрешено только 2 уровня вложенности тематик');
                }
            }

            $sort = nextTopicSortOrder($parentId > 0 ? $parentId : null);
            topicCreate($name, $parentId > 0 ? $parentId : null, $sort);
            $message = 'Тематика создана';
            break;
        }

        case 'update_topic': {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 1000);

            if ($topicId < 1) {
                throw new RuntimeException('Некорректная тематика');
            }
            if ($name === '') {
                throw new RuntimeException('Название тематики пустое');
            }

            $topic = topicById($topicId);
            if (!$topic) {
                throw new RuntimeException('Тематика не найдена');
            }

            $currentParentId = isset($topic['parent_id']) && $topic['parent_id'] !== null ? (int)$topic['parent_id'] : null;
            topicUpdate($topicId, $name, $currentParentId, $sort);
            $message = 'Тематика обновлена';
            if ($isAjax) {
                adminJsonResponse(['ok' => true, 'message' => $message]);
            }
            break;
        }

        case 'delete_topic': {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            if ($topicId < 1) {
                throw new RuntimeException('Некорректная тематика');
            }
            if (!topicById($topicId)) {
                throw new RuntimeException('Тематика не найдена');
            }

            topicDelete($topicId);
            $message = 'Тематика удалена';
            break;
        }

        case 'delete_section': {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            if ($sectionId < 1) {
                throw new RuntimeException('Некорректный раздел');
            }
            if (!sectionById($sectionId)) {
                throw new RuntimeException('Раздел не найден');
            }

            removeSectionImageFiles($sectionId);
            sectionDelete($sectionId);
            deleteSectionStorage($sectionId);
            $message = 'Раздел удалён';
            break;
        }

        case 'update_settings':
        case 'update_welcome': {
            $text = trim((string)($_POST['welcome_text'] ?? ''));
            $wmText = trim((string)($_POST['watermark_text'] ?? 'photo.andr33v.ru'));
            $wmBrightness = (int)($_POST['watermark_brightness'] ?? 35);
            $wmAngle = (int)($_POST['watermark_angle'] ?? -28);

            if ($wmText === '') {
                $wmText = 'photo.andr33v.ru';
            }
            $wmBrightness = max(5, min(100, $wmBrightness));
            $wmAngle = max(-75, min(75, $wmAngle));

            settingSet('welcome_text', $text);
            settingSet('watermark_text', $wmText);
            settingSet('watermark_brightness', (string)$wmBrightness);
            settingSet('watermark_angle', (string)$wmAngle);
            $message = 'Настройки сохранены';
            break;
        }

        case 'check_updates': {
            $branch = (string)($deployOptions['branch'] ?? 'main');
            $deployStatus = adminCheckForUpdates($projectRoot, $branch);
            $state = (string)($deployStatus['state'] ?? '');

            if ($state === 'update_available') {
                $message = 'Найдена новая версия. Можно обновиться.';
            } elseif ($state === 'up_to_date') {
                $message = 'Обновлений нет: установлена актуальная версия.';
            } elseif ($state === 'local_ahead') {
                $message = 'Локальная ветка опережает origin. Автообновление отключено.';
            } else {
                $message = 'Ветка расходится с origin. Нужна ручная синхронизация.';
            }

            break;
        }

        case 'deploy_updates': {
            $branch = (string)($deployOptions['branch'] ?? 'main');
            $scriptPath = (string)($deployOptions['script'] ?? ($projectRoot . '/scripts/deploy.sh'));
            $phpBin = (string)($deployOptions['php_bin'] ?? 'php');

            $deployStatus = adminCheckForUpdates($projectRoot, $branch);
            if (!(bool)($deployStatus['can_deploy'] ?? false)) {
                $state = (string)($deployStatus['state'] ?? '');
                if ($state === 'up_to_date') {
                    $message = 'Обновление не требуется: уже актуальная версия.';
                    break;
                }
                if ($state === 'local_ahead') {
                    throw new RuntimeException('Локальная ветка опережает origin. Автообновление отключено.');
                }
                if ($state === 'diverged') {
                    throw new RuntimeException('Ветка расходится с origin. Выполни ручную синхронизацию.');
                }
                throw new RuntimeException('Нельзя применить обновление в текущем состоянии ветки.');
            }

            $deployResult = adminRunDeployScript($projectRoot, $branch, $scriptPath, $phpBin);
            $deployOutput = (string)($deployResult['output'] ?? '');
            if (!(bool)($deployResult['ok'] ?? false)) {
                throw new RuntimeException('Деплой завершился с ошибкой: ' . ($deployOutput !== '' ? $deployOutput : ('код ' . (int)($deployResult['code'] ?? 1))));
            }

            $deployStatus = adminCheckForUpdates($projectRoot, $branch);
            $message = 'Обновление выполнено.';
            break;
        }

        case 'upload_before_bulk': {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            if ($sectionId < 1 || !sectionById($sectionId)) {
                throw new RuntimeException('Выбери раздел');
            }
            if (!isset($_FILES['before_bulk'])) {
                throw new RuntimeException('Файлы не переданы');
            }

            $result = saveBulkBefore($_FILES['before_bulk'], $sectionId);
            $message = 'Загружено: ' . $result['ok'];
            $errors = array_merge($errors, $result['errors']);
            break;
        }

        case 'photo_update': {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $code = trim((string)($_POST['code_name'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 1000);
            $descr = trim((string)($_POST['description'] ?? ''));
            $descr = $descr !== '' ? $descr : null;

            if ($photoId < 1) {
                throw new RuntimeException('Некорректный photo_id');
            }
            if ($code === '') {
                throw new RuntimeException('Код фото пустой');
            }

            $st = db()->prepare('UPDATE photos SET code_name=:c, sort_order=:s, description=:d WHERE id=:id');
            $st->execute(['c' => $code, 's' => $sort, 'd' => $descr, 'id' => $photoId]);

            if (isset($_FILES['after']) && (int)($_FILES['after']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $p = photoById($photoId);
                if (!$p) {
                    throw new RuntimeException('Фото не найдено');
                }
                $oldAfterPath = (string)($p['after_path'] ?? '');
                $up = saveSingleImage($_FILES['after'], $code . 'р', (int)$p['section_id']);
                photoFileUpsert($photoId, 'after', $up['path'], $up['mime'], $up['size']);

                if ($oldAfterPath !== '' && $oldAfterPath !== $up['path']) {
                    deleteThumbBySourcePath($projectRoot, $oldAfterPath);
                    $oldAbs = $projectRoot . '/' . ltrim($oldAfterPath, '/');
                    if (is_file($oldAbs)) {
                        @unlink($oldAbs);
                    }
                }
            }

            $message = 'Фото обновлено';
            if ($isAjax) {
                adminJsonResponse(['ok' => true, 'message' => $message]);
            }
            break;
        }

        case 'upload_after_file': {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            if ($photoId < 1) {
                throw new RuntimeException('Некорректный photo_id');
            }
            if (!isset($_FILES['after'])) {
                throw new RuntimeException('Файл не передан');
            }

            $photo = photoById($photoId);
            if (!$photo) {
                throw new RuntimeException('Фото не найдено');
            }

            $oldAfterPath = (string)($photo['after_path'] ?? '');
            $up = saveSingleImage($_FILES['after'], (string)$photo['code_name'] . 'р', (int)$photo['section_id']);
            photoFileUpsert($photoId, 'after', $up['path'], $up['mime'], $up['size']);

            if ($oldAfterPath !== '' && $oldAfterPath !== $up['path']) {
                deleteThumbBySourcePath($projectRoot, $oldAfterPath);
                $oldAbs = $projectRoot . '/' . ltrim($oldAfterPath, '/');
                if (is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }

            $updatedPhoto = photoById($photoId);
            $afterFileId = (int)($updatedPhoto['after_file_id'] ?? 0);
            $previewUrl = $afterFileId > 0 ? ('index.php?action=image&file_id=' . $afterFileId . '&v=' . rawurlencode((string)time())) : '';

            $message = 'Фото после обновлено';
            if ($isAjax) {
                adminJsonResponse([
                    'ok' => true,
                    'message' => $message,
                    'photo_id' => $photoId,
                    'preview_url' => $previewUrl,
                ]);
            }
            break;
        }

        case 'attach_photo_topic': {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $topicId = (int)($_POST['topic_id'] ?? 0);
            if ($photoId < 1 || !photoById($photoId)) {
                throw new RuntimeException('Фото не найдено');
            }
            if ($topicId < 1 || !topicById($topicId)) {
                throw new RuntimeException('Тематика не найдена');
            }

            photoTopicAttach($photoId, $topicId);
            $topics = array_map(static fn(array $t): array => [
                'id' => (int)$t['id'],
                'full_name' => (string)$t['full_name'],
            ], photoTopicsByPhotoId($photoId));
            $message = 'Тематика добавлена';

            if ($isAjax) {
                adminJsonResponse(['ok' => true, 'message' => $message, 'photo_id' => $photoId, 'topics' => $topics]);
            }
            break;
        }

        case 'detach_photo_topic': {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $topicId = (int)($_POST['topic_id'] ?? 0);
            if ($photoId < 1 || !photoById($photoId)) {
                throw new RuntimeException('Фото не найдено');
            }
            if ($topicId < 1) {
                throw new RuntimeException('Тематика не найдена');
            }

            photoTopicDetach($photoId, $topicId);
            $topics = array_map(static fn(array $t): array => [
                'id' => (int)$t['id'],
                'full_name' => (string)$t['full_name'],
            ], photoTopicsByPhotoId($photoId));
            $message = 'Тематика удалена';

            if ($isAjax) {
                adminJsonResponse(['ok' => true, 'message' => $message, 'photo_id' => $photoId, 'topics' => $topics]);
            }
            break;
        }

        case 'photo_delete': {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            if ($photoId > 0) {
                $p = photoById($photoId);
                if ($p) {
                    foreach (['before_path', 'after_path'] as $k) {
                        if (!empty($p[$k])) {
                            deleteThumbBySourcePath($projectRoot, (string)$p[$k]);
                            $abs = $projectRoot . '/' . ltrim((string)$p[$k], '/');
                            if (is_file($abs)) {
                                @unlink($abs);
                            }
                        }
                    }
                }
                $st = db()->prepare('DELETE FROM photos WHERE id=:id');
                $st->execute(['id' => $photoId]);
                $message = 'Фото удалено';
            }
            break;
        }

        case 'rotate_photo_file': {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $kind = (string)($_POST['kind'] ?? '');
            $direction = (string)($_POST['direction'] ?? 'right');
            if ($photoId < 1) {
                throw new RuntimeException('Некорректный photo_id');
            }
            if (!in_array($kind, ['before', 'after'], true)) {
                throw new RuntimeException('Некорректный тип файла');
            }

            $photo = photoById($photoId);
            if (!$photo) {
                throw new RuntimeException('Фото не найдено');
            }

            $pathKey = $kind === 'before' ? 'before_path' : 'after_path';
            $relPath = (string)($photo[$pathKey] ?? '');
            if ($relPath === '') {
                throw new RuntimeException('Файл отсутствует');
            }

            $absPath = $projectRoot . '/' . ltrim($relPath, '/');
            if (!is_file($absPath)) {
                throw new RuntimeException('Файл не найден на диске');
            }

            $degrees = $direction === 'left' ? -90 : 90;
            rotateImageOnDisk($absPath, $degrees);
            ensureThumbForSource($projectRoot, $relPath);

            $st = db()->prepare('UPDATE photo_files SET updated_at=CURRENT_TIMESTAMP WHERE photo_id=:pid AND kind=:kind');
            $st->execute(['pid' => $photoId, 'kind' => $kind]);

            $message = 'Изображение повернуто';
            if ($isAjax) {
                adminJsonResponse(['ok' => true, 'message' => $message, 'photo_id' => $photoId, 'kind' => $kind]);
            }
            break;
        }

        case 'create_commenter': {
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            if ($displayName === '') {
                throw new RuntimeException('Укажи имя комментатора');
            }
            $u = commenterCreate($displayName);
            $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?viewer=' . urlencode($u['token']);
            $message = 'Комментатор создан: ' . $u['display_name'] . ' | ссылка: ' . $link;
            break;
        }

        case 'delete_commenter': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                commenterDelete($id);
                $message = 'Комментатор удалён (доступ отозван)';
            }
            break;
        }

        case 'regenerate_commenter_token': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $token = commenterRegenerateToken($id);
                $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?viewer=' . urlencode($token);
                $message = 'Токен обновлён | ссылка: ' . $link;
            }
            break;
        }

        case 'delete_comment': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                commentDelete($id);
                $message = 'Комментарий удалён';
                if ($isAjax) {
                    adminJsonResponse(['ok' => true, 'message' => $message]);
                }
            }
            break;
        }
    }

    return [
        'message' => $message,
        'errors' => $errors,
        'deploy_status' => $deployStatus,
        'deploy_output' => $deployOutput,
    ];
}
