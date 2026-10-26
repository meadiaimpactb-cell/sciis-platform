<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Growth content (social proof + knowledge): impact stories, partners and curated resources.
 * Same base columns as the other CMS tables (pages/faqs/news_posts/events) so AdminContentController
 * and CmsContentResource handle them generically; type-specific columns are added per table.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['stories', 'partners', 'knowledge_resources'] as $content) {
            Schema::create($content, function (Blueprint $table) use ($content): void {
                $table->id();
                $table->string('slug', 160)->unique();
                $table->string('title_ar');                 // story title · partner name · resource title
                $table->string('title_en')->nullable();
                $table->longText('body_ar')->nullable();    // story summary · partner blurb · resource summary
                $table->longText('body_en')->nullable();
                $table->boolean('is_published')->default(false)->index();
                $table->timestamp('published_at')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->string('link_url', 500)->nullable();
                if ($content === 'stories') {
                    $table->string('sector_ar', 120)->nullable();
                    $table->string('sector_en', 120)->nullable();
                    $table->unsignedInteger('beneficiaries')->nullable();
                    $table->string('impact_metric_ar', 190)->nullable();
                    $table->string('impact_metric_en', 190)->nullable();
                }
                if ($content === 'partners') {
                    // Doc rule: a partner is shown only with an approved name — never an anonymous logo.
                    $table->boolean('approved_for_display')->default(false);
                }
                if ($content === 'knowledge_resources') {
                    $table->string('audience', 20)->default('all')->index(); // investor | entrepreneur | all
                }
                $table->jsonb('meta')->nullable();              // cover / logo / file
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['knowledge_resources', 'partners', 'stories'] as $content) {
            Schema::dropIfExists($content);
        }
    }
};
