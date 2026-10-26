<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CMS-02 — news authors with a real bio (E-E-A-T). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_posts', function (Blueprint $table): void {
            $table->string('author_name', 120)->nullable()->after('body_en');
            $table->string('author_title', 160)->nullable()->after('author_name');
            $table->text('author_bio')->nullable()->after('author_title');
            $table->string('author_avatar_url', 500)->nullable()->after('author_bio');
            $table->string('author_linkedin_url', 500)->nullable()->after('author_avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('news_posts', function (Blueprint $table): void {
            $table->dropColumn(['author_name', 'author_title', 'author_bio', 'author_avatar_url', 'author_linkedin_url']);
        });
    }
};
