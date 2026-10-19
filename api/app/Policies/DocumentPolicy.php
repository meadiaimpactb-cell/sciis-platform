<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\Opportunity;
use App\Models\User;

/** DOC-02 — signed URL only after a record-level check. */
class DocumentPolicy
{
    public function download(User $user, Document $document): bool
    {
        if ($document->owner_user_id === $user->id) {
            return true;
        }

        $parent = $document->documentable;

        if ($parent instanceof Opportunity) {
            return app(OpportunityPolicy::class)->viewDocuments($user, $parent);
        }

        // Organization / user documents: owner, or staff with opportunities.view / memberships.review.
        if ($user->isStaff()) {
            return $user->can('opportunities.view') || $user->can('memberships.review');
        }

        return false;
    }

    public function review(User $user, Document $document): bool
    {
        return $user->isStaff() && $user->can('opportunities.transition');
    }
}
