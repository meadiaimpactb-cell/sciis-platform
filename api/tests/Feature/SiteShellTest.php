<?php

declare(strict_types=1);

use App\Models\NewsPost;

/** Static hosting: news/event URLs without a prerendered page get the SPA shell only when the item is published. */
it('serves the site shell for a published item and a real 404 otherwise', function (): void {
    $public = public_path();
    @file_put_contents($public.'/200.html', '<html>shell</html>');
    @file_put_contents($public.'/404.html', '<html>not found</html>');

    NewsPost::query()->create(['slug' => 'live-item', 'title_ar' => 'خبر', 'body_ar' => 'نص', 'is_published' => true, 'published_at' => now()->subMinute()]);
    NewsPost::query()->create(['slug' => 'draft-item', 'title_ar' => 'مسودة', 'body_ar' => 'نص', 'is_published' => false]);

    $this->get('/news/live-item')->assertOk()->assertSee('shell', false);
    $this->get('/en/news/live-item')->assertOk()->assertSee('shell', false);
    $this->get('/news/draft-item')->assertNotFound()->assertSee('not found', false)->assertHeader('X-Robots-Tag', 'noindex');
    $this->get('/news/no-such-item')->assertNotFound();
    $this->get('/events/no-such-event')->assertNotFound();

    @unlink($public.'/200.html');
    @unlink($public.'/404.html');
});
