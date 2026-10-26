<?php

declare(strict_types=1);

use App\Models\Page;
use Database\Seeders\LegalPagesSeeder;
use Database\Seeders\PublicPagesContentSeeder;

/**
 * CMS — editable marketing copy of the public pages (pages.meta.sections), seeded from the web i18n texts.
 */
beforeEach(function (): void {
    $this->seed([LegalPagesSeeder::class, PublicPagesContentSeeder::class]);
    $this->admin = $this->staff('super_admin');
});

it('serves the seeded sections of every public page', function (): void {
    foreach (['home', 'about', 'impact-investing', 'for-investors', 'for-entrepreneurs'] as $slug) {
        $res = $this->getJson("/api/v1/public/pages/{$slug}")->assertOk();
        expect($res->json('data.meta.sections'))->toBeArray()->not->toBeEmpty();
    }

    $home = $this->getJson('/api/v1/public/pages/home')->assertOk();
    expect($home->json('data.meta.sections.hero_eyebrow.ar'))->toBe('منصة وطنية للاستثمار الاجتماعي')
        ->and($home->json('data.meta.sections.hero_eyebrow.en'))->toBe('A national impact-investing platform')
        ->and($home->json('data.meta.sections.how_steps.ar'))->toHaveCount(5)
        ->and($home->json('data.meta.sections.benefits.en'))->toHaveCount(8)
        ->and($home->json('data.meta.hero_images'))->toBe([]);

    $ent = $this->getJson('/api/v1/public/pages/for-entrepreneurs')->assertOk();
    expect($ent->json('data.meta.sections.documents.ar.0.required'))->toBeTrue()
        ->and($ent->json('data.meta.sections.stages.en.0.sla'))->toBe('5 days');
});

it('re-seeding never overwrites admin edits and preserves hero images', function (): void {
    $home = Page::query()->where('slug', 'home')->firstOrFail();
    $meta = $home->meta;
    $meta['sections']['hero_title']['ar'] = 'عنوان معدّل';
    unset($meta['sections']['hero_text']);
    $meta['hero_images'] = [['url' => '/storage/a.webp']];
    $home->forceFill(['meta' => $meta])->save();

    $this->seed(PublicPagesContentSeeder::class);

    $fresh = Page::query()->where('slug', 'home')->firstOrFail()->meta;
    expect($fresh['sections']['hero_title']['ar'])->toBe('عنوان معدّل')
        ->and($fresh['sections']['hero_text']['ar'])->not->toBeEmpty()
        ->and($fresh['hero_images'])->toBe([['url' => '/storage/a.webp']]);
});

it('lets staff save sections via PATCH while keeping hero images', function (): void {
    $home = Page::query()->where('slug', 'home')->firstOrFail();
    $images = [['url' => '/storage/content/h.webp', 'path' => 'content/h.webp', 'alt_ar' => 'صورة', 'alt_en' => null, 'width' => 1600, 'height' => 1200]];
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/pages/{$home->id}", ['meta' => ['hero_images' => $images]])->assertOk();

    $sections = $home->fresh()->meta['sections'];
    $sections['hero_title'] = ['ar' => 'عنوان جديد', 'en' => 'New title'];
    $sections['benefits'] = ['ar' => ['ميزة 1', 'ميزة 2'], 'en' => ['Benefit 1', 'Benefit 2']];

    // sections-only payload: hero_images must survive (server merges top-level meta keys)
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/pages/{$home->id}", ['meta' => ['sections' => $sections]])
        ->assertOk()
        ->assertJsonPath('data.meta.sections.hero_title.en', 'New title')
        ->assertJsonPath('data.meta.hero_images.0.alt_ar', 'صورة');

    $public = $this->getJson('/api/v1/public/pages/home')->assertOk();
    expect($public->json('data.meta.sections.hero_title.ar'))->toBe('عنوان جديد')
        ->and($public->json('data.meta.sections.benefits.en'))->toBe(['Benefit 1', 'Benefit 2'])
        ->and($public->json('data.meta.hero_images.0.url'))->toBe('/storage/content/h.webp');

    // and a hero-images-only payload keeps the sections
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/pages/{$home->id}", ['meta' => ['hero_images' => []]])->assertOk();
    expect($home->fresh()->meta['sections']['hero_title']['en'])->toBe('New title');
});

it('validates section shapes and limits', function (): void {
    $page = Page::query()->where('slug', 'about')->firstOrFail();
    $patch = fn (array $sections) => $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/pages/{$page->id}", ['meta' => ['sections' => $sections]]);

    $patch(['lead' => ['ar' => str_repeat('أ', 2001)]])->assertUnprocessable()->assertJsonValidationErrors(['meta.sections']);
    $patch(['goals' => ['ar' => array_fill(0, 21, 'هدف')]])->assertUnprocessable()->assertJsonValidationErrors(['meta.sections']);
    $patch(['goals' => ['fr' => 'x']])->assertUnprocessable()->assertJsonValidationErrors(['meta.sections']);
    $patch(['Bad Key' => ['ar' => 'x']])->assertUnprocessable()->assertJsonValidationErrors(['meta.sections']);
    $patch(['why' => ['ar' => [['title' => ['nested' => 'no']]]]])->assertUnprocessable()->assertJsonValidationErrors(['meta.sections']);
    $patch(['why' => ['ar' => [['title' => str_repeat('a', 2001), 'text' => 'ok']]]])->assertUnprocessable()->assertJsonValidationErrors(['meta.sections']);

    $patch([
        'lead' => ['ar' => str_repeat('أ', 2000), 'en' => 'Lead'],
        'goals' => ['ar' => array_fill(0, 20, 'هدف'), 'en' => []],
        'why' => ['ar' => [['title' => 'ع', 'text' => 'ن', 'required' => true]], 'en' => null],
    ])->assertOk()->assertJsonPath('data.meta.sections.goals.ar.19', 'هدف');
});

it('forbids non-staff users from editing page sections', function (): void {
    $page = Page::query()->where('slug', 'home')->firstOrFail();
    $payload = ['meta' => ['sections' => ['hero_title' => ['ar' => 'اختراق']]]];

    $this->actingAs($this->approvedInvestor())->patchJson("/api/v1/admin/content/pages/{$page->id}", $payload)->assertForbidden();
    $this->actingAs($this->entrepreneur())->patchJson("/api/v1/admin/content/pages/{$page->id}", $payload)->assertForbidden();

    expect($page->fresh()->meta['sections']['hero_title']['ar'])->not->toBe('اختراق');
});
