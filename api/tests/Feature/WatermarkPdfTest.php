<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\DocumentWatermarker;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;

/** INV-08 — PDF pages are imported and stamped (mPDF + FPDI); page count and size are kept. */
it('stamps every page of a PDF for the investor who downloads it', function (): void {
    $source = new Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]);
    $source->WriteHTML('<p>صفحة 1 — page one</p><pagebreak /><p>صفحة 2 — page two</p>');
    $bytes = $source->Output('', Destination::STRING_RETURN);

    $viewer = User::factory()->investor()->create(['name' => 'مستثمر تجريبي', 'email' => 'viewer@sciis.test']);
    $watermarker = app(DocumentWatermarker::class);
    expect($watermarker->supports('application/pdf'))->toBeTrue();

    $stamped = $watermarker->stampPdf($bytes, $viewer);

    expect($stamped)->toStartWith('%PDF-')->and($stamped)->not->toBe($bytes)
        ->and((new PdfParser(StreamReader::createByString($stamped)))->getCatalog())->not->toBeNull();
    $check = new Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]);
    expect($check->setSourceFile(StreamReader::createByString($stamped)))->toBe(2);
});
