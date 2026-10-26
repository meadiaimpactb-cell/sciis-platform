<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotificationTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** NTF-01 — notification texts (mail, SMS, in-app) editable without code; settings.manage. */
class AdminNotificationTemplateController extends Controller
{
    private const LOCALES = ['ar', 'en'];

    public function __construct(private readonly NotificationTemplateService $templates) {}

    /** GET /admin/notification-templates → {items: [{key, group, placeholders, ar: {default, value}, en: {…}}]} */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $defaults = array_map(fn (string $l): array => $this->templates->defaults($l), array_combine(self::LOCALES, self::LOCALES));
        $overrides = array_map(fn (string $l): array => $this->templates->overrides($l), array_combine(self::LOCALES, self::LOCALES));

        $items = [];
        foreach (array_keys($defaults['ar']) as $key) {
            $item = ['key' => $key, 'group' => strtok($key, '.'), 'placeholders' => NotificationTemplateService::placeholders($defaults['ar'][$key])];
            foreach (self::LOCALES as $locale) {
                $item[$locale] = ['default' => $defaults[$locale][$key] ?? null, 'value' => $overrides[$locale][$key] ?? null];
            }
            $items[] = $item;
        }

        return $this->ok(['items' => $items]);
    }

    /** PUT /admin/notification-templates {key, locale, value|null} — null restores the default text. */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $data = $request->validate([
            'locale' => ['required', Rule::in(self::LOCALES)],
            'key' => ['required', 'string', 'max:150'],
            'value' => ['present', 'nullable', 'string', 'max:2000'],
        ]);
        $defaults = $this->templates->defaults($data['locale']);
        if (! array_key_exists($data['key'], $defaults)) {
            throw ValidationException::withMessages(['key' => __('messages.templates.unknown_key')]);
        }

        // An emptied field (trimmed to null by the middleware) restores the default text.
        $value = $data['value'] === null ? null : trim($data['value']);
        // Only placeholders the notification actually provides can be used.
        $unknown = $value === null ? [] : array_diff(NotificationTemplateService::placeholders($value), NotificationTemplateService::placeholders($defaults[$data['key']]));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['value' => __('messages.templates.unknown_placeholders', ['names' => ':'.implode(', :', $unknown)])]);
        }

        $this->templates->set($data['locale'], $data['key'], $value === $defaults[$data['key']] ? null : $value, $request->user());

        return $this->ok(['key' => $data['key'], 'locale' => $data['locale'], 'value' => $this->templates->overrides($data['locale'])[$data['key']] ?? null]);
    }
}
