<?php

declare(strict_types=1);

use App\Models\Faq;

/** The static site re-fetches its data only when this fingerprint changes: any add / edit / delete must change it. */
it('changes the public content version when public content is added, edited or deleted', function (): void {
    $version = fn (): string => $this->getJson('/api/v1/public/content-version')->assertOk()->json('data.version');
    $v0 = $version();
    expect($v0)->toMatch('/^[0-9a-f]{16}$/')->and($version())->toBe($v0);

    $faq = Faq::query()->create(['slug' => 'q1', 'title_ar' => 'سؤال', 'body_ar' => 'جواب', 'is_published' => true, 'published_at' => now()]);
    $v1 = $version();
    expect($v1)->not->toBe($v0);

    $this->travel(5)->seconds();
    $faq->update(['body_ar' => 'جواب محدث']);
    $v2 = $version();
    expect($v2)->not->toBe($v1);

    $faq->delete();
    expect($version())->not->toBe($v2);
});

it('keeps the content version when only private activity happens (logins, profile edits)', function (): void {
    $user = App\Models\User::factory()->entrepreneur()->create();
    $before = $this->getJson('/api/v1/public/content-version')->json('data.version');

    $this->travel(5)->seconds();
    $user->forceFill(['last_login_at' => now(), 'name' => 'اسم آخر'])->save();

    expect($this->getJson('/api/v1/public/content-version')->json('data.version'))->toBe($before);
});

/** Public read endpoints are briefly cacheable by the browser (per language); taxonomies and private data are not. */
it('sends a short private, per-language browser cache on public content', function (): void {
    $response = $this->getJson('/api/v1/public/faqs')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=60')
        ->and($response->headers->get('Vary'))->toContain('Accept-Language');

    expect($this->getJson('/api/v1/settings/public')->assertOk()->headers->get('Cache-Control'))->toContain('max-age=60');
    expect((string) $this->getJson('/api/v1/taxonomies')->assertOk()->headers->get('Cache-Control'))->not->toContain('max-age=60');
});
