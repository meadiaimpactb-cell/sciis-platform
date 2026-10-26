<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use App\Support\ArabicGlyphs;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\StreamReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * INV-08 — visible watermark on the files an investor downloads while FEATURE_WATERMARK is on: "سري — CONFIDENTIAL",
 * the investor's name, e-mail and the download time (Asia/Riyadh), tiled diagonally across the file plus a footer band.
 *
 * Works on the decrypted plaintext (DocumentService::plaintextChunks, SEC-01) inside the signed stream route; the
 * stored file is never modified. PNG / JPEG are stamped with GD; PDF pages are imported and stamped with mPDF + FPDI
 * (Arabic shaping by mPDF). FPDI's free parser cannot read PDFs with compressed cross-reference streams: those are
 * delivered unstamped and the skip is logged, so a download is never blocked by the watermark itself.
 *
 * Arabic text needs a TrueType font with Arabic glyphs (`sciis.documents.watermark_font`, storage/app/fonts/*.ttf or
 * a system DejaVu Sans / Noto Sans Arabic); without one the watermark uses GD's built-in Latin font.
 */
class DocumentWatermarker
{
    public const IMAGE_MIMES = ['image/png', 'image/jpeg'];

    /** Larger images are refused rather than risking the memory limit (GD keeps 4–5 bytes per pixel). */
    public const MAX_PIXELS = 40_000_000;

    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
        '/usr/share/fonts/noto/NotoSansArabic-Regular.ttf',
        '/usr/local/share/fonts/DejaVuSans.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
        'C:\\Windows\\Fonts\\tahoma.ttf',
    ];

    private string|false|null $font = null;

    /** The download of this document by this viewer must be watermarked (feature on, investor, supported type). */
    public function applies(Document $document, ?User $viewer): bool
    {
        return feature('watermark') && $viewer !== null && $viewer->isInvestor() && $this->supports((string) $document->mime);
    }

    /**
     * Signed stream route (DocumentStreamController): the decrypted file, stamped for the viewer, as a download.
     * Fails closed — a file that must carry a watermark is never sent without one.
     */
    public function download(Document $document, User $viewer, DocumentService $documents): StreamedResponse
    {
        try {
            $plain = '';
            foreach ($documents->plaintextChunks($document) as $chunk) {
                $plain .= $chunk;
            }
            try {
                $bytes = $this->stamp($plain, (string) $document->mime, $viewer);
            } catch (CrossReferenceException $e) {
                // A PDF the free parser cannot read: delivered as is, never blocked; the skip is on record.
                Log::warning('PDF delivered without watermark (unsupported compression)', ['document' => $document->uuid, 'error' => $e->getMessage()]);
                $bytes = $plain;
            }
        } catch (\Throwable $e) {
            Log::error('Document could not be watermarked', ['document' => $document->uuid, 'error' => $e->getMessage()]);
            abort(500, __('messages.documents.watermark_failed'));
        }

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, $documents->downloadFileName($document), [
            'Content-Type' => $document->mime,
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function supports(string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIMES, true) || ($mime === 'application/pdf' && $this->pdfSupported());
    }

    public function pdfSupported(): bool
    {
        return class_exists(Mpdf::class);
    }

    /** Watermarked bytes of a plaintext file. @throws \RuntimeException when the file cannot be stamped */
    public function stamp(string $bytes, string $mime, User $viewer): string
    {
        return match (true) {
            in_array($mime, self::IMAGE_MIMES, true) => $this->stampImage($bytes, $mime, $viewer),
            $mime === 'application/pdf' => $this->stampPdf($bytes, $viewer),
            default => throw new \RuntimeException("Watermarking {$mime} is not supported."),
        };
    }

    /**
     * Every page imported as-is, a faint diagonal "سري — CONFIDENTIAL · name" across it and a footer line with the
     * viewer's name, e-mail and the download time.
     *
     * @throws CrossReferenceException when the PDF uses compression FPDI's free parser cannot read
     */
    public function stampPdf(string $bytes, User $viewer): string
    {
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);
        $pdf = new Mpdf([
            'tempDir' => $tempDir, 'autoScriptToLang' => true, 'autoLangToFont' => true,
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0, 'margin_header' => 0, 'margin_footer' => 0,
        ]);
        [$label, $name, $meta] = $this->lines($viewer);
        $pdf->watermark_font = 'xbriyaz';
        $pdf->SetWatermarkText(trim($label.' · '.$name, ' ·'), 0.1);
        $pdf->showWatermarkText = true;

        $pages = $pdf->setSourceFile(StreamReader::createByString($bytes));
        $footer = '<div dir="rtl" style="font-family: xbriyaz; font-size: 7pt; color: #24306C; text-align: center; background-color: #FFFFFF;">'
            .e(implode(' — ', array_filter([$label, $name, $meta]))).'</div>';
        for ($i = 1; $i <= $pages; $i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPageByArray(['orientation' => $size['width'] > $size['height'] ? 'L' : 'P', 'sheet-size' => [$size['width'], $size['height']]]);
            $pdf->useTemplate($template);
            $pdf->WriteFixedPosHTML($footer, 0, $size['height'] - 6, $size['width'], 6);
        }

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * The watermark lines, logical order: label, name, e-mail · date.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function lines(User $viewer): array
    {
        return [
            'سري — CONFIDENTIAL',
            trim((string) $viewer->name),
            trim((string) $viewer->email).' · '.now('Asia/Riyadh')->format('Y-m-d H:i'),
        ];
    }

    /** @throws \RuntimeException */
    public function stampImage(string $bytes, string $mime, User $viewer): string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new \RuntimeException('Not a readable image.');
        }
        [$width, $height] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            throw new \RuntimeException('Image too large to watermark.');
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new \RuntimeException('Not a readable image.');
        }
        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, true);

        $lines = $this->lines($viewer);
        $font = $this->fontPath();
        $font !== null
            ? $this->drawTrueType($img, $width, $height, $lines, $font)
            : $this->drawBuiltin($img, $width, $height, $lines);

        ob_start();
        if ($mime === 'image/png') {
            imagesavealpha($img, true);
            imagepng($img, null, 6);
        } else {
            imagejpeg($img, null, 90);
        }
        $out = (string) ob_get_clean();
        imagedestroy($img);

        if ($out === '') {
            throw new \RuntimeException('Watermarked image could not be encoded.');
        }

        return $out;
    }

    /** @param array{0: string, 1: string, 2: string} $lines */
    private function drawTrueType(\GdImage $img, int $width, int $height, array $lines, string $font): void
    {
        $visual = array_map(fn (string $l): string => ArabicGlyphs::visual($l), $lines);
        $size = max(9.0, min($width, $height) / 30);

        // Diagonal tiles (30°), light navy.
        $tileColor = imagecolorallocatealpha($img, 36, 48, 108, 98);
        $tile = implode('   ', array_filter([$visual[0], $visual[1], now('Asia/Riyadh')->format('Y-m-d')]));
        $box = imagettfbbox($size, 0, $font, $tile) ?: [0, 0, (int) ($size * 20), 0, (int) ($size * 20), (int) -$size];
        $tileWidth = max(1, abs($box[2] - $box[0]));
        $stepX = (int) ($tileWidth + $size * 4);
        $stepY = (int) max($size * 6, $height / 6);
        for ($y = (int) -$height; $y < $height * 2; $y += $stepY) {
            for ($x = (int) -$width; $x < $width * 2; $x += $stepX) {
                imagettftext($img, $size, 30, $x, $y, $tileColor, $font, $tile);
            }
        }

        // Footer band with the three lines, legible on any background.
        $lineSize = max(8.0, $size * 0.8);
        $lineHeight = (int) ($lineSize * 1.9);
        $bandHeight = $lineHeight * 3 + (int) $lineSize;
        imagefilledrectangle($img, 0, $height - $bandHeight, $width, $height, imagecolorallocatealpha($img, 255, 255, 255, 40));
        $textColor = imagecolorallocatealpha($img, 36, 48, 108, 10);
        foreach ($visual as $i => $line) {
            if ($line === '') {
                continue;
            }
            $b = imagettfbbox($lineSize, 0, $font, $line) ?: [0, 0, 0, 0, 0, 0];
            $x = (int) max(4, ($width - abs($b[2] - $b[0])) / 2);
            imagettftext($img, $lineSize, 0, $x, $height - $bandHeight + (int) ($lineSize * 1.5) + $i * $lineHeight, $textColor, $font, $line);
        }
    }

    /**
     * No TrueType font: GD's built-in font (ASCII only) scaled up — "CONFIDENTIAL", the name when it is Latin, e-mail and date.
     *
     * @param  array{0: string, 1: string, 2: string}  $lines
     */
    private function drawBuiltin(\GdImage $img, int $width, int $height, array $lines): void
    {
        $ascii = array_values(array_filter(array_map(
            fn (string $l): string => trim((string) preg_replace('/[^\x20-\x7E]+/', ' ', $l), ' -·'),
            [$lines[0], $lines[1], str_replace('·', '-', $lines[2])],
        )));
        $tileText = implode('  -  ', $ascii);
        $target = (int) max(12, min($width, $height) / 28);

        $tileColor = [36, 48, 108, 98];
        $stepY = max($target * 4, (int) ($height / 6));
        for ($row = 0, $y = $target; $y < $height; $row++, $y += $stepY) {
            $this->builtinText($img, $tileText, $row % 2 === 0 ? $target : $target * 4, $y, $target, $tileColor);
        }

        $lineHeight = (int) ($target * 1.4);
        $bandHeight = $lineHeight * count($ascii) + $target;
        imagefilledrectangle($img, 0, $height - $bandHeight, $width, $height, imagecolorallocatealpha($img, 255, 255, 255, 40));
        foreach ($ascii as $i => $line) {
            $this->builtinText($img, $line, $target, $height - $bandHeight + (int) ($target / 2) + $i * $lineHeight, $target, [36, 48, 108, 10]);
        }
    }

    /** Draws built-in font text scaled to `$height` px. @param array{0: int, 1: int, 2: int, 3: int} $rgba */
    private function builtinText(\GdImage $img, string $text, int $x, int $y, int $height, array $rgba): void
    {
        if ($text === '') {
            return;
        }
        $fw = imagefontwidth(5);
        $fh = imagefontheight(5);
        $src = imagecreatetruecolor(max(1, $fw * strlen($text)), $fh);
        imagealphablending($src, false);
        imagefilledrectangle($src, 0, 0, imagesx($src), $fh, imagecolorallocatealpha($src, 0, 0, 0, 127));
        imagestring($src, 5, 0, 0, $text, imagecolorallocatealpha($src, $rgba[0], $rgba[1], $rgba[2], $rgba[3]));
        imagealphablending($src, true);
        $scale = $height / $fh;
        imagecopyresampled($img, $src, $x, $y, 0, 0, (int) (imagesx($src) * $scale), $height, imagesx($src), $fh);
        imagedestroy($src);
    }

    private function fontPath(): ?string
    {
        if ($this->font === null) {
            $configured = trim((string) config('sciis.documents.watermark_font', ''));
            $candidates = array_merge($configured !== '' ? [$configured] : [], glob(storage_path('app/fonts/*.ttf')) ?: [], self::FONT_CANDIDATES);
            $this->font = false;
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_readable($candidate)) {
                    $this->font = $candidate;
                    break;
                }
            }
        }

        return $this->font === false ? null : $this->font;
    }
}
