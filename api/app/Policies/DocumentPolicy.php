<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\InvestorProfile;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\MessagingService;

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

        // MTG-03 — message attachments: whoever may read the thread (never the other party of the record).
        if ($parent instanceof ThreadMessage) {
            $subject = $parent->thread?->subject;

            return $subject !== null && app(MessagingService::class)->canView($user, $subject);
        }

        // INV-01 — investor membership file (CV / CR / authorization letter / impact policy): the owner (above)
        // and the staff who review memberships, nobody else.
        if ($parent instanceof InvestorProfile || ($parent instanceof Organization && $parent->isInvestorOrganization())) {
            return $user->isStaff() && $user->can('memberships.review');
        }

        // Other organization / user documents: owner, or staff with opportunities.view / memberships.review.
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
