<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(fn ($request): bool => app()->environment('local') || ($request->user('api')?->hasRole('super_admin') ?? false));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user !== null && $user->isStaff() && $user->hasRole('super_admin'));
    }
}
