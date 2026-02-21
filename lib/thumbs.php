<?php

declare(strict_types=1);

function thumbRelativePathForSource(string $sourceRelPath): string
{
    $normalized = ltrim(str_replace('\\', '/', $sourceRelPath), '/');
    $hash = sha1($normalized);
    $base = (string)pathinfo($normalized, PATHINFO_FILENAME);
    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'photo';
    $safeBase = trim($safeBase, '._-');
    if ($safeBase === '') {
        $safeBase = 'photo';
    }

    return 'thumbs/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $safeBase . '_' . $hash . '.jpg';
}

function thumbAbsolutePathForSource(string $projectRoot, string $sourceRelPath): string
{
    return rtrim($projectRoot, '/') . '/' . ltrim(thumbRelativePathForSource($sourceRelPath), '/');
}

function ensureThumbForSource(string $projectRoot, string $sourceRelPath, int $maxWidth = 520, int $maxHeight = 360, int $quality = 82): ?string
{
    $normalized = ltrim(str_replace('\\', '/', $sourceRelPath), '/');
    if ($normalized === '') {
        return null;
    }

    $sourceAbs = rtrim($projectRoot, '/') . '/' . $normalized;
    if (!is_file($sourceAbs)) {
        return null;
    }

    $thumbRel = thumbRelativePathForSource($normalized);
    $thumbAbs = rtrim($projectRoot, '/') . '/' . ltrim($thumbRel, '/');
    $srcMtime = (int)(filemtime($sourceAbs) ?: 0);
    $thumbMtime = (int)(filemtime($thumbAbs) ?: 0);
    if ($thumbMtime > 0 && $thumbMtime >= $srcMtime) {
        return $thumbRel;
    }

    $dir = dirname($thumbAbs);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    if (extension_loaded('imagick') && createThumbWithImagick($sourceAbs, $thumbAbs, $maxWidth, $maxHeight, $quality)) {
        return $thumbRel;
    }
    if (createThumbWithGd($sourceAbs, $thumbAbs, $maxWidth, $maxHeight, $quality)) {
        return $thumbRel;
    }

    return null;
}

function deleteThumbBySourcePath(string $projectRoot, string $sourceRelPath): void
{
    $normalized = ltrim(str_replace('\\', '/', $sourceRelPath), '/');
    if ($normalized === '') {
        return;
    }

    $abs = thumbAbsolutePathForSource($projectRoot, $normalized);
    if (is_file($abs)) {
        @unlink($abs);
    }
}

function createThumbWithImagick(string $sourceAbs, string $thumbAbs, int $maxWidth, int $maxHeight, int $quality): bool
{
    try {
        $img = new Imagick($sourceAbs);
        if (method_exists($img, 'autoOrient')) {
            $img->autoOrient();
        } else {
            $img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        }
        $img->thumbnailImage(max(1, $maxWidth), max(1, $maxHeight), true, true);
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(max(30, min(95, $quality)));
        $img->stripImage();
        $ok = $img->writeImage($thumbAbs);
        $img->clear();
        $img->destroy();
        return (bool)$ok;
    } catch (Throwable) {
        return false;
    }
}

function createThumbWithGd(string $sourceAbs, string $thumbAbs, int $maxWidth, int $maxHeight, int $quality): bool
{
    [$srcW, $srcH, $type] = @getimagesize($sourceAbs) ?: [0, 0, 0];
    if ($srcW < 1 || $srcH < 1) {
        return false;
    }

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($sourceAbs),
        IMAGETYPE_PNG => @imagecreatefrompng($sourceAbs),
        IMAGETYPE_GIF => @imagecreatefromgif($sourceAbs),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourceAbs) : false,
        default => false,
    };
    if (!$src) {
        return false;
    }

    $scale = min($maxWidth / $srcW, $maxHeight / $srcH, 1);
    $dstW = max(1, (int)round($srcW * $scale));
    $dstH = max(1, (int)round($srcH * $scale));

    $dst = imagecreatetruecolor($dstW, $dstH);
    if ($dst === false) {
        imagedestroy($src);
        return false;
    }

    $bg = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $bg);

    $okCopy = imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    $okSave = $okCopy && imagejpeg($dst, $thumbAbs, max(30, min(95, $quality)));

    imagedestroy($src);
    imagedestroy($dst);
    return (bool)$okSave;
}
