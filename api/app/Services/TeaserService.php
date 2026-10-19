<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Opportunity;
use App\Models\Teaser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * MAT-05 — teaser (public-sensitivity summary) with dual approval: council (matching officer) + owner.
 * Editing the text resets both approvals and bumps the version.
 */
class TeaserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<int, string> $highlights */
    public function upsert(Opportunity $opportunity, User $actor, string $summaryAr, ?string $summaryEn, array $highlights): Teaser
    {
        return DB::transaction(function () use ($opportunity, $actor, $summaryAr, $summaryEn, $highlights): Teaser {
            $teaser = $opportunity->teaser()->first();
            $before = $teaser?->only(['summary_ar', 'summary_en', 'highlights', 'version']);

            if ($teaser === null) {
                $teaser = new Teaser(['opportunity_id' => $opportunity->id, 'version' => 1]);
            } else {
                $teaser->version = $teaser->version + 1;
            }

            $teaser->summary_ar = $summaryAr;
            $teaser->summary_en = $summaryEn;
            $teaser->highlights = array_values(array_filter(array_map('strval', $highlights)));
            $teaser->approved_by_owner_at = null;
            $teaser->approved_by_council_at = null;
            $teaser->approved_by_user_id = null;
            $teaser->save();

            $this->audit->log('teaser.updated', $teaser, $before, $teaser->only(['summary_ar', 'summary_en', 'highlights', 'version']), $actor);

            return $teaser;
        });
    }

    public function approveByCouncil(Opportunity $opportunity, User $actor): Teaser
    {
        $teaser = $opportunity->teaser()->first() ?? throw new DomainException(__('messages.teaser.missing'), 'teaser_missing', 422);

        $teaser->approved_by_council_at = now();
        $teaser->approved_by_user_id = $actor->id;
        $teaser->save();

        $this->audit->log('teaser.approved_by_council', $teaser, null, ['version' => $teaser->version], $actor);

        return $teaser;
    }

    public function approveByOwner(Opportunity $opportunity, User $owner): Teaser
    {
        if (! $opportunity->isOwnedBy($owner)) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
        $teaser = $opportunity->teaser()->first() ?? throw new DomainException(__('messages.teaser.missing'), 'teaser_missing', 422);

        $teaser->approved_by_owner_at = now();
        $teaser->save();

        $this->audit->log('teaser.approved_by_owner', $teaser, null, ['version' => $teaser->version], $owner);

        return $teaser;
    }
}
