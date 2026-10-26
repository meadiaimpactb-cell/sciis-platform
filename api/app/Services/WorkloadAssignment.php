<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\OpportunityStatus;
use App\Models\AuditLog;
use App\Models\EvaluationAssignment;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ADM-09 — automatic reviewer assignment and evaluator suggestions.
 *  - Reviewer pool: active staff holding `opportunities.transition`, excluding accounts whose only role is
 *    super_admin. Strategy (setting `assignment.reviewer_strategy`): least_workload (fewest open files in
 *    new / under_review / needs_completion, ties by id) or round_robin (next after the last automatic pick).
 *  - New submissions are assigned automatically when `assignment.auto_assign_reviewer` is on (listener on
 *    OpportunityStatusChanged → new) — audited as `opportunity.auto_assigned`.
 *  - Evaluators: two suggestions by least open workload (assigned / COI declared), excluding anyone who declared a
 *    conflict of interest on this file. A suggestion only — staff still confirm the assignment (EVL-02).
 */
final class WorkloadAssignment
{
    private const OPEN_REVIEW = [OpportunityStatus::New, OpportunityStatus::UnderReview, OpportunityStatus::NeedsCompletion];

    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit) {}

    public function autoAssignEnabled(): bool
    {
        return $this->settings->bool('assignment.auto_assign_reviewer', false);
    }

    public function strategy(): string
    {
        return $this->settings->get('assignment.reviewer_strategy') === 'round_robin' ? 'round_robin' : 'least_workload';
    }

    /** @return Collection<int, User> ordered by id */
    public function reviewerPool(): Collection
    {
        return $this->pool('opportunities.transition');
    }

    /** @return array<int, int> reviewer id => open files */
    public function reviewerWorkloads(): array
    {
        $ids = $this->reviewerPool()->modelKeys();
        $counts = Opportunity::query()->whereIn('assigned_reviewer_id', $ids)
            ->whereIn('status', array_map(fn (OpportunityStatus $s): string => $s->value, self::OPEN_REVIEW))
            ->selectRaw('assigned_reviewer_id, count(*) as total')->groupBy('assigned_reviewer_id')
            ->pluck('total', 'assigned_reviewer_id');

        return collect($ids)->mapWithKeys(fn (int $id): array => [$id => (int) ($counts[$id] ?? 0)])->all();
    }

    /**
     * Next reviewer by the configured strategy. Pass `$workloads` / `$lastId` (by reference) to keep a bulk run fair.
     *
     * @param  array<int, int>|null  $workloads
     */
    public function pickReviewer(?array &$workloads = null, ?int &$lastId = null): ?User
    {
        $pool = $this->reviewerPool();
        if ($pool->isEmpty()) {
            return null;
        }

        if ($this->strategy() === 'round_robin') {
            $lastId ??= $this->lastAutoAssignedId();
            $pick = $pool->first(fn (User $u): bool => $lastId === null || $u->id > $lastId) ?? $pool->first();
            $lastId = $pick->id;

            return $pick;
        }

        $workloads ??= $this->reviewerWorkloads();
        $pick = $pool->sortBy(fn (User $u): array => [$workloads[$u->id] ?? 0, $u->id])->first();
        $workloads[$pick->id] = ($workloads[$pick->id] ?? 0) + 1;

        return $pick;
    }

    /** New submission → reviewer, when enabled and nobody is assigned yet. */
    public function autoAssign(Opportunity $opportunity): ?User
    {
        if (! $this->autoAssignEnabled() || $opportunity->assigned_reviewer_id !== null) {
            return null;
        }

        $reviewer = $this->pickReviewer();
        if ($reviewer === null) {
            return null;
        }

        $opportunity->assigned_reviewer_id = $reviewer->id;
        $opportunity->review_due_at ??= now()->addDays($this->settings->slaDays('review'));
        $opportunity->save();

        $this->audit->log('opportunity.auto_assigned', $opportunity, ['assigned_reviewer_id' => null], ['assigned_reviewer_id' => $reviewer->id, 'strategy' => $this->strategy()]);

        return $reviewer;
    }

    /**
     * EVL-02 helper — up to `$count` evaluators by least open workload, excluding declared conflicts on this file.
     *
     * @return Collection<int, array{id: int, name: string, open_assignments: int}>
     */
    public function suggestEvaluators(Opportunity $opportunity, int $count = 2): Collection
    {
        $conflicted = $opportunity->evaluationAssignments()->where('coi_has_conflict', true)->pluck('evaluator_user_id')->all();
        $pool = $this->pool('evaluations.score')->reject(fn (User $u): bool => in_array($u->id, $conflicted, true));

        $open = EvaluationAssignment::query()->whereIn('evaluator_user_id', $pool->modelKeys())
            ->whereIn('status', [AssignmentStatus::Assigned->value, AssignmentStatus::CoiDeclared->value])
            ->selectRaw('evaluator_user_id, count(*) as total')->groupBy('evaluator_user_id')
            ->pluck('total', 'evaluator_user_id');

        return $pool
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'open_assignments' => (int) ($open[$u->id] ?? 0)])
            ->sortBy(fn (array $s): array => [$s['open_assignments'], $s['id']])
            ->take($count)
            ->values();
    }

    /** @return Collection<int, User> */
    private function pool(string $permission): Collection
    {
        return User::query()->staff()->active()->permission($permission)->with('roles')->orderBy('id')->get()
            ->filter(fn (User $u): bool => $u->getRoleNames()->reject(fn (string $r): bool => $r === 'super_admin')->isNotEmpty())
            ->values();
    }

    private function lastAutoAssignedId(): ?int
    {
        $after = AuditLog::query()->where('action', 'opportunity.auto_assigned')->latest('id')->value('after');
        $after = is_string($after) ? json_decode($after, true) : $after;

        return isset($after['assigned_reviewer_id']) ? (int) $after['assigned_reviewer_id'] : null;
    }
}
