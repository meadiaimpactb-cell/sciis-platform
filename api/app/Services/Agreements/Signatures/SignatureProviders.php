<?php

declare(strict_types=1);

namespace App\Services\Agreements\Signatures;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * AGR-05 — resolves the configured driver: `sciis.agreements.signature_driver` → class. `simple` is built in; other
 * drivers come from `sciis.agreements.signature_drivers` (key => SignatureProvider class).
 */
final class SignatureProviders
{
    private const BUILT_IN = ['simple' => SimpleSignatureProvider::class];

    public static function make(Container $app): SignatureProvider
    {
        $driver = (string) config('sciis.agreements.signature_driver', 'simple');
        $drivers = array_merge(self::BUILT_IN, (array) config('sciis.agreements.signature_drivers', []));
        $class = $drivers[$driver] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, SignatureProvider::class)) {
            throw new InvalidArgumentException("Unknown agreement signature driver [{$driver}].");
        }

        return $app->make($class);
    }
}
