<?php

declare(strict_types=1);

use App\Services\SettingsService;

if (! function_exists('setting')) {
    /** Reads a platform setting (cached) with a fallback to config('sciis.defaults'). */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, $default);
    }
}

if (! function_exists('feature')) {
    function feature(string $name): bool
    {
        return (bool) config("sciis.features.{$name}", false);
    }
}

if (! function_exists('request_id')) {
    function request_id(): ?string
    {
        $request = request();

        return $request ? $request->attributes->get('request_id') : null;
    }
}
