<?php

declare(strict_types=1);

use App\Support\NdaDraft;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MSG-02 — make sure the editable NDA text exists in the CMS (page `nda`, Arabic + English).
 * Creates the page with the draft text when missing; on existing environments it only fills empty bodies or
 * replaces the short pre-MSG-02 placeholder, so wording already edited by staff is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $page = DB::table('pages')->where('slug', NdaDraft::SLUG)->first();

        if ($page === null) {
            DB::table('pages')->insert([
                'slug' => NdaDraft::SLUG,
                'title_ar' => NdaDraft::TITLE_AR,
                'title_en' => NdaDraft::TITLE_EN,
                'body_ar' => NdaDraft::BODY_AR,
                'body_en' => NdaDraft::BODY_EN,
                'version' => '1.0',
                'is_published' => true,
                'published_at' => $now,
                'sort' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $changes = [];
        $bodyAr = (string) ($page->body_ar ?? '');
        if (trim($bodyAr) === '' || str_contains($bodyAr, NdaDraft::LEGACY_MARKER)) {
            $changes['body_ar'] = NdaDraft::BODY_AR;
            $changes['title_ar'] = NdaDraft::TITLE_AR;
        }
        if (trim((string) ($page->body_en ?? '')) === '') {
            $changes['body_en'] = NdaDraft::BODY_EN;
        }
        if (trim((string) ($page->title_en ?? '')) === '' || $page->title_en === 'Non-Disclosure Agreement') {
            $changes['title_en'] = NdaDraft::TITLE_EN;
        }

        if ($changes !== []) {
            DB::table('pages')->where('id', $page->id)->update($changes + ['updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Content migration: the page is kept (consents reference its text through their snapshots).
    }
};
