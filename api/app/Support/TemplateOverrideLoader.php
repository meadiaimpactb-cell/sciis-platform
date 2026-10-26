<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\NotificationTemplateService;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;

/**
 * NTF-01 — wraps the file loader and lays admin overrides over the `notifications` group.
 * Every other group is passed through untouched.
 */
class TemplateOverrideLoader implements Loader
{
    public function __construct(private readonly Loader $inner, private readonly \Closure $templates) {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if ($group !== NotificationTemplateService::GROUP || ($namespace !== null && $namespace !== '*')) {
            return $lines;
        }

        /** @var NotificationTemplateService $service */
        $service = ($this->templates)();
        foreach ($service->overrides($locale) as $key => $value) {
            Arr::set($lines, $key, $value);
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->inner->namespaces();
    }

    /** @param  array<int, mixed>  $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}
