<?php

declare(strict_types=1);

use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Setting;
use App\Notifications\ContactReceivedNotification;
use App\Services\SettingsService;
use Database\Seeders\LegalPagesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * CMS-02/03/05 + BRD-03 + #32 — public content, legal pages, admin content, media upload, contact routing.
 */
beforeEach(function (): void {
    $this->seed(LegalPagesSeeder::class);
    $this->admin = $this->staff('super_admin');
});

it('serves legal pages with version and published date', function (): void {
    foreach (['privacy-policy', 'terms', 'disclaimer'] as $slug) {
        $res = $this->getJson("/api/v1/public/pages/{$slug}")->assertOk();
        expect($res->json('data.slug'))->toBe($slug)
            ->and($res->json('data.version'))->toBe('1.0')
            ->and($res->json('data.published_at'))->not->toBeNull()
            ->and($res->json('data.updated_at'))->not->toBeNull()
            ->and($res->json('data.body'))->toContain('#');
        // public responses never expose the raw bilingual columns
        expect($res->json('data'))->not->toHaveKey('body_ar');
    }
});

it('returns 404 for unpublished or unknown pages and exposes legal versions in public settings', function (): void {
    Page::query()->where('slug', 'terms')->update(['is_published' => false]);
    $this->getJson('/api/v1/public/pages/terms')->assertNotFound();
    $this->getJson('/api/v1/public/pages/does-not-exist')->assertNotFound();

    $this->getJson('/api/v1/settings/public')->assertOk()
        ->assertJsonPath('data.legal_versions.privacy_policy', '1.0')
        ->assertJsonPath('data.legal_versions.terms', '1.0');
});

it('serves the seeded home page with a hero_images array in meta', function (): void {
    $this->getJson('/api/v1/public/pages/home')->assertOk()
        ->assertJsonPath('data.slug', 'home')
        ->assertJsonPath('data.meta.hero_images', []);
});

it('lists published news with pagination and serves a news detail with its author', function (): void {
    $list = $this->getJson('/api/v1/public/news')->assertOk();
    expect($list->json('meta.total'))->toBe(1)
        ->and($list->json('data.0.slug'))->toBe('founding-phase-launch')
        ->and($list->json('data.0.author.name'))->toBe('د. نورة الشهري');

    $detail = $this->getJson('/api/v1/public/news/founding-phase-launch')->assertOk();
    expect($detail->json('data.title'))->toContain('المرحلة التأسيسية')
        ->and($detail->json('data.author'))->toHaveKeys(['name', 'title', 'bio', 'avatar_url', 'linkedin_url'])
        ->and($detail->json('data'))->not->toHaveKey('author_bio'); // raw columns are staff-only

    NewsPost::query()->create(['slug' => 'draft-post', 'title_ar' => 'مسودة', 'is_published' => false]);
    $this->getJson('/api/v1/public/news/draft-post')->assertNotFound();
    expect($this->getJson('/api/v1/public/news')->json('meta.total'))->toBe(1);
});

it('serves an event detail by slug', function (): void {
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/events', [
        'title_ar' => 'ملتقى المستثمرين', 'title_en' => 'Investors Forum', 'is_published' => true,
        'starts_at' => now()->addDays(10)->toIso8601String(), 'location' => 'الرياض',
    ])->assertCreated()->assertJsonPath('data.slug', 'investors-forum');

    $this->getJson('/api/v1/public/events/investors-forum')->assertOk()
        ->assertJsonPath('data.location', 'الرياض')
        ->assertJsonPath('data.title', 'ملتقى المستثمرين');
    expect($this->getJson('/api/v1/public/events')->json('data.0.slug'))->toBe('investors-forum');
});

it('auto-generates a FAQ slug when the admin form omits it (#32)', function (): void {
    $before = Faq::query()->count();

    $res = $this->actingAs($this->admin)->postJson('/api/v1/admin/content/faqs', [
        'title_ar' => 'سؤال جديد بدون معرّف', 'body_ar' => 'الإجابة', 'category' => 'general', 'is_published' => true,
    ])->assertCreated();
    expect($res->json('data.slug'))->toBe('faq-'.($before + 1))
        ->and($res->json('data.category'))->toBe('general');

    // title_en → Str::slug, made unique on collision
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/faqs', ['title_ar' => 'س', 'title_en' => 'How to join?'])
        ->assertCreated()->assertJsonPath('data.slug', 'how-to-join');
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/faqs', ['title_ar' => 'س', 'title_en' => 'How to join?'])
        ->assertCreated()->assertJsonPath('data.slug', 'how-to-join-2');

    // an explicit slug is still honoured, and a bad one is rejected
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/pages', ['title_ar' => 'صفحة', 'slug' => 'custom-page'])
        ->assertCreated()->assertJsonPath('data.slug', 'custom-page');
    $this->actingAs($this->admin)->postJson('/api/v1/admin/content/pages', ['title_ar' => 'صفحة', 'slug' => 'Bad Slug!'])
        ->assertUnprocessable()->assertJsonValidationErrors(['slug']);
});

it('stores news author fields and returns them to staff', function (): void {
    $res = $this->actingAs($this->admin)->postJson('/api/v1/admin/content/news', [
        'title_ar' => 'خبر', 'title_en' => 'News item', 'body_ar' => 'نص الخبر', 'is_published' => true,
        'author_name' => 'سارة', 'author_title' => 'محللة أثر', 'author_bio' => 'نبذة', 'author_linkedin_url' => 'https://www.linkedin.com/in/sara',
    ])->assertCreated();

    expect($res->json('data.author_name'))->toBe('سارة')
        ->and($res->json('data.author.linkedin_url'))->toBe('https://www.linkedin.com/in/sara');
    $this->getJson('/api/v1/public/news/news-item')->assertOk()->assertJsonPath('data.author.title', 'محللة أثر');
});

it('uploads content media as webp and stores hero images in the home page meta (BRD-03)', function (): void {
    Storage::fake('public');

    $upload = $this->actingAs($this->admin)->post('/api/v1/admin/content/media', [
        'file' => UploadedFile::fake()->image('hero.jpg', 2400, 1200),
    ], ['Accept' => 'application/json'])->assertCreated();

    $path = $upload->json('data.path');
    expect($path)->toEndWith('.webp')
        ->and($upload->json('data.width'))->toBe(1920)
        ->and($upload->json('data.height'))->toBe(960)
        ->and($upload->json('data.url'))->toContain($path);
    Storage::disk('public')->assertExists($path);
    expect(substr((string) Storage::disk('public')->get($path), 0, 4))->toBe('RIFF'); // WebP container

    // non-images / oversize are rejected
    $this->actingAs($this->admin)->post('/api/v1/admin/content/media', ['file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);
    $this->actingAs($this->admin)->post('/api/v1/admin/content/media', ['file' => UploadedFile::fake()->image('big.png')->size(6000)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);

    // staff without settings.manage cannot upload
    $this->actingAs($this->staff('reports_viewer'))->post('/api/v1/admin/content/media', ['file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertForbidden();

    $home = Page::query()->where('slug', 'home')->firstOrFail();
    $images = [
        ['url' => $upload->json('data.url'), 'path' => $path, 'alt_ar' => 'فريق', 'alt_en' => 'Team', 'width' => 1920, 'height' => 960],
    ];
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/pages/{$home->id}", ['meta' => ['hero_images' => $images]])->assertOk();
    $this->getJson('/api/v1/public/pages/home')->assertOk()
        ->assertJsonPath('data.meta.hero_images.0.alt_ar', 'فريق')
        ->assertJsonPath('data.meta.hero_images.0.width', 1920);

    $four = array_fill(0, 4, $images[0]);
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/content/pages/{$home->id}", ['meta' => ['hero_images' => $four]])
        ->assertUnprocessable()->assertJsonValidationErrors(['meta.hero_images']);
});

it('routes contact messages by category, e-mails the sender and notifies the assigned staff user (CMS-05)', function (): void {
    Notification::fake();
    $investorDesk = $this->staff('membership_officer');
    app(SettingsService::class)->set('contact.routing.investor', $investorDesk->id);

    $res = $this->postJson('/api/v1/public/contact', [
        'category' => 'investor', 'name' => 'محمد العمري', 'email' => 'm@example.test', 'message' => 'أرغب بمعرفة متطلبات العضوية في المجلس.',
    ])->assertCreated();
    $ref = $res->json('data.reference_no');
    expect($ref)->toStartWith('C-');

    $message = ContactMessage::query()->where('reference_no', $ref)->firstOrFail();
    expect($message->assigned_to)->toBe($investorDesk->id)->and($message->status)->toBe('new');

    Notification::assertSentOnDemand(ContactReceivedNotification::class, fn (ContactReceivedNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $channels === ['mail'] && array_key_exists('m@example.test', (array) $notifiable->routes['mail']));
    Notification::assertSentTo($investorDesk, ContactReceivedNotification::class, fn (ContactReceivedNotification $n, array $channels): bool => $channels === ['database'] && $n->toArray($investorDesk)['reference_no'] === $ref);

    // unrouted category → unassigned, sender still gets the confirmation, no staff notification
    $res2 = $this->postJson('/api/v1/public/contact', [
        'category' => 'media', 'name' => 'صحفية', 'email' => 'press@example.test', 'message' => 'طلب تصريح إعلامي حول إطلاق المنصة.',
    ])->assertCreated();
    expect(ContactMessage::query()->where('reference_no', $res2->json('data.reference_no'))->value('assigned_to'))->toBeNull();
    Notification::assertSentOnDemandTimes(ContactReceivedNotification::class, 2);
    Notification::assertSentToTimes($investorDesk, ContactReceivedNotification::class, 1);

    // routing settings are seeded (null) and not public
    expect(Setting::query()->where('key', 'contact.routing.investor')->value('is_public'))->toBeFalsy();
});

it('renders the sender confirmation mail with the reference number', function (): void {
    $message = ContactMessage::query()->create(['category' => 'other', 'name' => 'زائر', 'email' => 'v@example.test', 'message' => 'رسالة تجريبية للاختبار.', 'reference_no' => 'C-2026-0099']);
    $mail = (new ContactReceivedNotification($message))->toMail(new AnonymousNotifiable);
    $rendered = (string) $mail->render();

    expect($mail->subject)->toContain('C-2026-0099')->and($rendered)->toContain('C-2026-0099');
});
