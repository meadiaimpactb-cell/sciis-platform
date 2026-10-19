<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Document;
use App\Models\EvaluationAssignment;
use App\Models\Interest;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Policies\EvaluationAssignmentPolicy;
use App\Policies\InterestPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\OpportunityPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Opportunity::class => OpportunityPolicy::class,
        Document::class => DocumentPolicy::class,
        Interest::class => InterestPolicy::class,
        EvaluationAssignment::class => EvaluationAssignmentPolicy::class,
        Membership::class => MembershipPolicy::class,
    ];

    public function boot(): void
    {
        // super_admin passes every gate except explicit record-level denials handled in policies.
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->isStaff() && $user->hasRole('super_admin') ? true : null;
        });
    }
}
