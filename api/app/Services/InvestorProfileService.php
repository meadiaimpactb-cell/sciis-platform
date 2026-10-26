<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserType;
use App\Models\InvestorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * INV-01 — single code path to apply the sectioned profile payload (basics / interests / experience /
 * declarations) used by both `PUT /investor/profile` and the registration step (ACC-01).
 */
class InvestorProfileService
{
    /** interests.*_ids key → relation name */
    public const RELATIONS = [
        'sector_ids' => 'sectors',
        'stage_ids' => 'stages',
        'investment_type_ids' => 'investmentTypes',
        'region_ids' => 'regions',
        'cause_ids' => 'causes',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated `UpdateProfileRequest` shape
     */
    public function apply(InvestorProfile $profile, array $data, ?User $actor = null): InvestorProfile
    {
        DB::transaction(function () use ($profile, $data, $actor): void {
            $before = $profile->only(InvestorProfile::COMPLETION_FIELDS);

            $experience = (array) ($data['experience'] ?? []);
            // INV-01 — the impact / ESG policy belongs to institutional investors only.
            if ($profile->user?->type !== UserType::InvestorInstitutional) {
                unset($experience['impact_policy']);
            }

            $profile->fill(array_merge(
                (array) ($data['basics'] ?? []),
                collect((array) ($data['interests'] ?? []))->except(array_keys(self::RELATIONS))->all(),
                $experience,
                (array) ($data['declarations'] ?? []),
            ));
            $profile->save();

            $interests = (array) ($data['interests'] ?? []);
            foreach (self::RELATIONS as $key => $relation) {
                if (array_key_exists($key, $interests)) {
                    $profile->{$relation}()->sync((array) ($interests[$key] ?? []));
                }
            }

            // FRM-01 — additional questions (already validated by the FormAnswers rule), stamped with the schema version.
            if (array_key_exists('extra_answers', $data) && is_array($data['extra_answers'])) {
                $profile->forceFill(['extra_answers' => app(FormSchemaService::class)->record('investor_profile', $data['extra_answers'], $profile->extra_answers)]);
            }

            $profile->completion_percent = $profile->computeCompletion();
            // INV-02 — "last updated" moves on every saved section, even when only taxonomy picks changed.
            $profile->touch();

            $this->audit->log('investor_profile.updated', $profile, $before, $profile->only(InvestorProfile::COMPLETION_FIELDS), $actor ?? $profile->user);
        });

        return $profile->refresh();
    }

    /**
     * Keys of the completion items that are still empty (same universe as `computeCompletion()`).
     *
     * @return list<string>
     */
    public static function missingItems(InvestorProfile $profile): array
    {
        $missing = [];
        foreach (InvestorProfile::COMPLETION_FIELDS as $field) {
            $value = $profile->getAttribute($field);
            if ($value === null || $value === '' || $value === [] || $value === false) {
                $missing[] = $field === 'investment_range_id' ? 'investment_range' : ($field === 'how_heard_id' ? 'how_heard' : $field);
            }
        }
        foreach (InvestorProfile::COMPLETION_RELATIONS as $relation) {
            if (! $profile->{$relation}()->exists()) {
                $missing[] = $relation === 'investmentTypes' ? 'investment_types' : $relation;
            }
        }

        return $missing;
    }
}
