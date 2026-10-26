<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Page;
use App\Support\NdaDraft;

/**
 * MSG-02 — the confidentiality undertaking (NDA) as currently in force.
 *  - text:    CMS page `nda` (Admin → Content → Pages), Arabic + English; English falls back to Arabic;
 *             the bundled draft (`NdaDraft`) is used only when the page is missing or empty.
 *  - version: setting `legal.nda_version` (Admin → Settings) — bump it whenever the wording changes.
 * The text hash is sha256(text|version), the same scheme as the other consents (PRV-02).
 */
class NdaService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function version(): string
    {
        return (string) $this->settings->get('legal.nda_version', '1.0');
    }

    /**
     * @return array{version: string, locale: string, title: string, body: string, text_hash: string, pending_legal: bool, updated_at: string|null}
     */
    public function current(?string $locale = null): array
    {
        $requested = in_array($locale, ['ar', 'en'], true) ? $locale : (app()->getLocale() === 'en' ? 'en' : 'ar');
        $page = Page::query()->published()->where('slug', NdaDraft::SLUG)->first();

        $bodyAr = trim((string) ($page?->body_ar ?? ''));
        $bodyEn = trim((string) ($page?->body_en ?? ''));

        if ($requested === 'en' && $bodyEn !== '') {
            [$lang, $body, $title] = ['en', $bodyEn, (string) ($page->title_en ?: NdaDraft::TITLE_EN)];
        } elseif ($bodyAr !== '') {
            [$lang, $body, $title] = ['ar', $bodyAr, (string) ($page->title_ar ?: NdaDraft::TITLE_AR)];
        } elseif ($requested === 'en') {
            [$lang, $body, $title] = ['en', NdaDraft::BODY_EN, NdaDraft::TITLE_EN];
        } else {
            [$lang, $body, $title] = ['ar', NdaDraft::BODY_AR, NdaDraft::TITLE_AR];
        }

        $version = $this->version();

        return [
            'version' => $version,
            'locale' => $lang,
            'title' => $title,
            'body' => $body,
            'text_hash' => $this->hash($body, $version),
            'pending_legal' => (bool) preg_match('/بانتظار الاعتماد القانوني|pending legal approval/iu', $body),
            'updated_at' => $page?->updated_at?->toIso8601String(),
        ];
    }

    public function hash(string $text, string $version): string
    {
        return hash('sha256', $text.'|'.$version);
    }
}
