<?php

declare(strict_types=1);

use App\Models\KnowledgeResource;
use App\Models\Partner;
use App\Models\Setting;
use App\Models\Story;
use App\Services\SettingsService;
use Database\Seeders\DemoStoriesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Growth content — impact stories, partners (approved names only) and knowledge resources through the shared CMS
 * endpoints, their public visibility, and the social / per-audience contact settings.
 */
beforeEach(function (): void {
    // Arabic by default regardless of the local .env (test requests send `Accept-Language: en-us` by default).
    config(['sciis.features.english' => false]);
    $this->admin = $this->staff('super_admin');
});

it('creates, updates, lists and deletes impact stories and only publishes what is published', function (): void {
    $res = $this->actingAs($this->admin)->postJson('/api/v1/admin/content/stories', [
        'title_ar' => 'قصة أثر', 'title_en' => 'Impact story', 'body_ar' => 'ملخص القصة', 'body_en' => 'Story summary',
        'sector_ar' => 'التعليم', 'sector_en' => 'Education', 'beneficiaries' => 1200,
        'impact_metric_ar' => 'ارتفاع التوظيف 40٪', 'impact_metric_en' => 'Employment up 40%',
        'link_url' => 'https://example.org/story', 'is_published' => true, 'sort' => 2,
        'meta' => ['cover' => ['url' => 'https://cdn.example.org/c.webp', 'alt_ar' => 'غلاف', 'width' => 1200, 'height' => 675],
            'logo' => ['url' => 'https://cdn.example.org/l.webp', 'alt_ar' => 'شعار', 'width' => 400, 'height' => 200]],
        // keys of other types are dropped silently (no silently-discarded-attribute exception)
        'location' => 'الرياض', 'audience' => 'investor', 'author_name' => 'x',
    ])->assertCreated();

    expect($res->json('data.slug'))->toBe('impact-story')
        ->and($res->json('data.sector_ar'))->toBe('التعليم')
        ->and($res->json('data.beneficiaries'))->toBe(1200)
        ->and($res->json('data.meta.logo.width'))->toBe(400);
    $id = $res->json('data.id');

    // a draft never leaks publicly
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/stories', ['title_ar' => 'مسودة'])->assertCreated()->assertJsonPath('data.slug', 'story-2');

    $this->app['auth']->forgetGuards();
    $public = $this->getJson('/api/v1/public/stories')->assertOk();
    expect($public->json('data'))->toHaveCount(1)
        ->and($public->json('data.0.title'))->toBe('قصة أثر')
        ->and($public->json('data.0.sector'))->toBe('التعليم')
        ->and($public->json('data.0.impact_metric'))->toBe('ارتفاع التوظيف 40%') // stored with the Latin % (LatinDigits)
        ->and($public->json('data.0.link_url'))->toBe('https://example.org/story')
        ->and($public->json('data.0'))->not->toHaveKey('sector_ar'); // raw bilingual columns are staff-only
    config(['sciis.features.english' => true]);
    $this->getJson('/api/v1/public/stories', ['Accept-Language' => 'en'])->assertOk()->assertJsonPath('data.0.sector', 'Education');

    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/stories/{$id}", ['beneficiaries' => 1500, 'meta' => ['cover' => null]])
        ->assertOk()->assertJsonPath('data.beneficiaries', 1500)
        ->assertJsonPath('data.meta.cover', null)
        ->assertJsonPath('data.meta.logo.url', 'https://cdn.example.org/l.webp'); // meta merged per key

    // unsafe links are rejected
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/stories/{$id}", ['link_url' => 'javascript:alert(1)'])
        ->assertUnprocessable()->assertJsonValidationErrors(['link_url']);

    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/content/stories/{$id}")->assertOk();
    expect(Story::query()->find($id))->toBeNull();
    $this->getJson('/api/v1/public/stories')->assertOk()->assertJsonCount(0, 'data');
});

it('shows partners only when published and approved for display (never anonymous logos)', function (): void {
    $logo = ['url' => 'https://cdn.example.org/p.webp', 'alt_ar' => 'شعار الشريك', 'width' => 320, 'height' => 120];

    // publishing without the display approval is refused
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/partners', ['title_ar' => 'شريك', 'is_published' => true, 'meta' => ['logo' => $logo]])
        ->assertUnprocessable()->assertJsonValidationErrors(['approved_for_display']);

    $res = $this->actingAs($this->admin)->postJson('/api/v1/admin/content/partners', [
        'title_ar' => 'مؤسسة الأثر', 'title_en' => 'Impact Foundation', 'link_url' => 'https://impact.example.org', 'sort' => 1, 'meta' => ['logo' => $logo],
    ])->assertCreated();
    $id = $res->json('data.id');
    expect($res->json('data.approved_for_display'))->toBeFalse();
    $this->getJson('/api/v1/public/partners')->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/partners/{$id}", ['is_published' => true])->assertUnprocessable();
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/partners/{$id}", ['is_published' => true, 'approved_for_display' => true])->assertOk();

    // an approved-but-unpublished partner and a published one whose approval flag was forced off in the DB stay hidden
    Partner::query()->create(['slug' => 'hidden-a', 'title_ar' => 'مخفي', 'approved_for_display' => true, 'is_published' => false]);
    Partner::query()->create(['slug' => 'hidden-b', 'title_ar' => 'مخفي', 'approved_for_display' => false, 'is_published' => true]);

    $this->app['auth']->forgetGuards();
    $public = $this->getJson('/api/v1/public/partners')->assertOk();
    expect($public->json('data'))->toHaveCount(1)
        ->and($public->json('data.0.name'))->toBe('مؤسسة الأثر')
        ->and($public->json('data.0.meta.logo.alt_ar'))->toBe('شعار الشريك')
        ->and($public->json('data.0'))->not->toHaveKey('approved_for_display');
    config(['sciis.features.english' => true]);
    $this->getJson('/api/v1/public/partners', ['Accept-Language' => 'en'])->assertJsonPath('data.0.name', 'Impact Foundation');

    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/content/partners/{$id}")->assertOk();
    $this->getJson('/api/v1/public/partners')->assertOk()->assertJsonCount(0, 'data');
});

it('manages knowledge resources with an audience filter and uploaded files', function (): void {
    Storage::fake('public');

    $file = $this->actingAs($this->admin)->post('/api/v1/admin/content/files', [
        'file' => UploadedFile::fake()->create('guide.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    Storage::disk('public')->assertExists($file->json('data.path'));
    expect($file->json('data.name'))->toBe('guide.pdf');

    // only document types are accepted
    $this->actingAs($this->admin)->post('/api/v1/admin/content/files', ['file' => UploadedFile::fake()->create('x.html', 2, 'text/html')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);

    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/resources', [
        'title_ar' => 'دليل المستثمر', 'audience' => 'investor', 'is_published' => true,
        'meta' => ['file' => ['url' => $file->json('data.url'), 'path' => $file->json('data.path'), 'name' => 'guide.pdf', 'size' => 204800, 'mime' => 'application/pdf']],
    ])->assertCreated()->assertJsonPath('data.audience', 'investor');
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/resources', ['title_ar' => 'دليل صاحب المشروع', 'audience' => 'entrepreneur', 'link_url' => 'https://example.org/guide', 'is_published' => true])->assertCreated();
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/resources', ['title_ar' => 'مرجع عام', 'is_published' => true])->assertCreated()->assertJsonPath('data.audience', 'all');
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/resources', ['title_ar' => 'مسودة'])->assertCreated();
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/resources', ['title_ar' => 'خطأ', 'audience' => 'everyone'])->assertUnprocessable()->assertJsonValidationErrors(['audience']);

    $this->app['auth']->forgetGuards();
    expect($this->getJson('/api/v1/public/resources')->assertOk()->json('data'))->toHaveCount(3);
    $investor = $this->getJson('/api/v1/public/resources?audience=investor')->assertOk()->json('data');
    expect(collect($investor)->pluck('title')->all())->toEqualCanonicalizing(['دليل المستثمر', 'مرجع عام'])
        ->and(collect($investor)->firstWhere('title', 'دليل المستثمر')['url'])->toBe($file->json('data.url'))
        ->and(collect($investor)->firstWhere('title', 'دليل المستثمر')['file']['name'])->toBe('guide.pdf');
    expect(collect($this->getJson('/api/v1/public/resources?audience=entrepreneur')->json('data'))->firstWhere('title', 'دليل صاحب المشروع')['url'])->toBe('https://example.org/guide');

    expect(KnowledgeResource::query()->count())->toBe(4);
});

it('keeps growth CRUD staff-only and refuses deleting core content types', function (): void {
    $this->postJson('/api/v1/admin/content/stories', ['title_ar' => 'x'])->assertUnauthorized();
    $this->actingAs($this->staff('reports_viewer'))->postJson('/api/v1/admin/content/stories', ['title_ar' => 'x'])->assertForbidden();
    $this->actingAs($this->approvedInvestor())->getJson('/api/v1/admin/content/partners')->assertForbidden();

    $page = $this->actingAs($this->admin)->postJson('/api/v1/admin/content/pages', ['title_ar' => 'صفحة'])->assertCreated();
    $this->actingAs($this->admin)->deleteJson('/api/v1/admin/content/pages/'.$page->json('data.id'))->assertMethodNotAllowed();
    $this->actingAs($this->admin)->deleteJson('/api/v1/admin/content/stories/999999')->assertNotFound();
    $this->actingAs($this->admin)->getJson('/api/v1/admin/content/stories')->assertOk()->assertJsonPath('meta.total', 0);
});

it('seeds two labelled demo stories only into an empty table', function (): void {
    $this->seed(DemoStoriesSeeder::class);
    expect(Story::query()->count())->toBe(2)
        ->and(Story::query()->pluck('title_ar')->every(fn (string $t): bool => str_contains($t, '(افتراضي)')))->toBeTrue();

    $this->seed(DemoStoriesSeeder::class); // idempotent: table not empty → skipped
    expect(Story::query()->count())->toBe(2)->and(Partner::query()->count())->toBe(0);
});

it('exposes social links and per-audience e-mails in public settings and validates them', function (): void {
    foreach (['platform.email_investors', 'platform.email_entrepreneurs', 'platform.social.x', 'platform.social.linkedin', 'platform.social.instagram', 'platform.social.youtube'] as $key) {
        $row = Setting::query()->where('key', $key)->first();
        expect($row)->not->toBeNull()->and($row->value)->toBeNull()->and($row->is_public)->toBeTrue();
    }

    $this->getJson('/api/v1/settings/public')->assertOk()
        ->assertJsonPath('data.platform.email_investors', null)
        ->assertJsonPath('data.platform.social.x', null)
        ->assertJsonPath('data.platform.social.youtube', null);

    $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', ['settings' => [
        'platform.email_investors' => 'investors@sciis.sa',
        'platform.email_entrepreneurs' => 'projects@sciis.sa',
        'platform.social.x' => 'https://x.com/sciis',
        'platform.social.linkedin' => 'https://www.linkedin.com/company/sciis',
    ]])->assertOk();

    $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', ['settings' => ['platform.social.instagram' => 'javascript:alert(1)']])
        ->assertUnprocessable()->assertJsonValidationErrors(['settings.platform.social.instagram']);
    $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', ['settings' => ['platform.email_investors' => 'not-an-email']])
        ->assertUnprocessable();

    app(SettingsService::class)->flush();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/settings/public')->assertOk()
        ->assertJsonPath('data.platform.email_investors', 'investors@sciis.sa')
        ->assertJsonPath('data.platform.email_entrepreneurs', 'projects@sciis.sa')
        ->assertJsonPath('data.platform.social.x', 'https://x.com/sciis')
        ->assertJsonPath('data.platform.social.linkedin', 'https://www.linkedin.com/company/sciis')
        ->assertJsonPath('data.platform.social.instagram', null);
});
