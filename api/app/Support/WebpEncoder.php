<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;

/** Re-encodes uploaded images to WebP with GD (CMS media, user avatars). */
final class WebpEncoder
{
    /**
     * @param  int  $maxWidth  downscale so the width never exceeds this (aspect ratio kept)
     * @param  int|null  $square  when set, centre-crop to a square of this size (avatars)
     * @return array{bytes: string, width: int, height: int}|null null when GD/WebP is unavailable or the file is not an image
     */
    public static function encode(UploadedFile $file, int $maxWidth = 1920, int $quality = 82, ?int $square = null): ?array
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $raw = @file_get_contents($file->getRealPath());
        if ($raw === false || $raw === '') {
            return null;
        }
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $srcX = 0;
        $srcY = 0;
        if ($square !== null) {
            $side = min($w, $h);
            $srcX = intdiv($w - $side, 2);
            $srcY = intdiv($h - $side, 2);
            $w = $h = $side;
            $targetW = $targetH = min($side, $square);
        } else {
            $targetW = min($w, $maxWidth);
            $targetH = max(1, (int) round($h * $targetW / max(1, $w)));
        }

        $dst = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetW, $targetH, $w, $h);
        imagedestroy($src);

        ob_start();
        $ok = imagewebp($dst, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($dst);

        if (! $ok || $bytes === '') {
            return null;
        }

        return ['bytes' => $bytes, 'width' => $targetW, 'height' => $targetH];
    }
}
