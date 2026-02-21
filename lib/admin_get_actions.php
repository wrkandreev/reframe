<?php

declare(strict_types=1);

function adminHandleGetAction(string $action): void
{
    if ($action !== 'photo_comments') {
        return;
    }

    $photoId = (int)($_GET['photo_id'] ?? 0);
    if ($photoId < 1) {
        adminJsonResponse(['ok' => false, 'message' => 'Некорректный photo_id'], 400);
    }

    $photo = photoById($photoId);
    if (!$photo) {
        adminJsonResponse(['ok' => false, 'message' => 'Фото не найдено'], 404);
    }

    adminJsonResponse([
        'ok' => true,
        'photo' => ['id' => (int)$photo['id'], 'code_name' => (string)$photo['code_name']],
        'comments' => commentsByPhoto($photoId),
    ]);
}
