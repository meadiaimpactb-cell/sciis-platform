<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\AssignmentStatus;
use App\Enums\InterestStatus;
use App\Enums\MeetingStatus;
use App\Enums\MembershipStatus;
use App\Enums\NominationResponse;
use App\Enums\OpportunityStatus;
use App\Enums\Sensitivity;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\DomainException;
use App\Models\EvaluationAssignment;
use App\Models\Interest;
use App\Models\Meeting;
use App\Models\Membership;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * RPT-03 (drill-down), RPT-04 (custom report builder), ADM-08 (bulk export) — whitelisted datasets over the
 * operational tables. Every column carries a sensitivity (doc §6.2):
 *  - public / internal  → any staff member holding `reports.view`;
 *  - confidential        → only with the dataset's domain permission (e.g. investor names need `memberships.review`);
 *  - sensitive personal  → never offered (no e-mails, phones, national ids, documents).
 * Record links (`_link`) are only returned to users who may open the record. Values come back display-ready
 * (localised labels, Y-m-d dates, Latin digits) so the preview, Excel and PDF show the same thing.
 */
final class ReportDatasets
{
    public const DATASETS = ['opportunities', 'memberships', 'interests', 'nominations', 'meetings', 'evaluations', 'accounts'];

    /** Dataset → permissions (any of) that unlock its confidential columns and record links. */
    public const DOMAIN = [
        'opportunities' => ['opportunities.view'],
        'memberships' => ['memberships.review'],
        'interests' => ['nominations.manage'],
        'nominations' => ['nominations.manage'],
        'meetings' => ['meetings.manage'],
        'evaluations' => ['evaluations.assign', 'evaluations.approve'],
        'accounts' => ['users.manage'],
    ];

    /** Date columns a period filter may apply to (first = default). */
    private const DATE_FIELDS = [
        'opportunities' => ['submitted_at', 'created_at', 'updated_at'],
        'memberships' => ['submitted_at', 'created_at', 'updated_at', 'approved_at'],
        'interests' => ['created_at', 'owner_decided_at', 'closed_at'],
        'nominations' => ['sent_at', 'responded_at'],
        'meetings' => ['starts_at', 'created_at'],
        'evaluations' => ['created_at', 'due_at'],
        'accounts' => ['created_at', 'last_login_at'],
    ];

    /** Columns shown when a definition does not pick any. */
    private const DEFAULT_COLUMNS = [
        'opportunities' => ['reference_no', 'name', 'status', 'sector', 'stage', 'assignee', 'submitted_at'],
        'memberships' => ['investor', 'investor_type', 'status', 'assignee', 'submitted_at', 'completion'],
        'interests' => ['opportunity_ref', 'opportunity', 'investor', 'status', 'owner_decision', 'created_at'],
        'nominations' => ['opportunity_ref', 'opportunity', 'investor', 'response', 'match_score', 'sent_at'],
        'meetings' => ['title', 'opportunity_ref', 'status', 'starts_at', 'participants', 'has_minutes'],
        'evaluations' => ['opportunity_ref', 'opportunity', 'evaluator', 'status', 'overall', 'recommendation'],
        'accounts' => ['name', 'type', 'status', 'verified', 'created_at'],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function exists(string $dataset): bool
    {
        return in_array($dataset, self::DATASETS, true);
    }

    public function maxExportRows(): int
    {
        return max(100, $this->settings->int('reports.max_export_rows', 5000));
    }

    /** True when the user holds the dataset's domain permission (confidential columns + record links). */
    public function hasDomainAccess(User $user, string $dataset): bool
    {
        foreach (self::DOMAIN[$dataset] ?? [] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The builder catalog for the web: datasets, the columns this user may pick, filters and grouping options.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(User $user): array
    {
        return array_map(function (string $dataset) use ($user): array {
            $columns = $this->columns($dataset);
            $domain = $this->hasDomainAccess($user, $dataset);

            return [
                'key' => $dataset,
                'label' => __('reporting.datasets.'.$dataset),
                'columns' => array_values(array_map(fn (string $key, array $c): array => [
                    'key' => $key,
                    'label' => $this->columnLabel($key),
                    'sensitivity' => $c['s']->value,
                    'groupable' => (bool) ($c['group'] ?? false),
                    'allowed' => $this->columnAllowed($c, $domain),
                ], array_keys($columns), $columns)),
                'default_columns' => self::DEFAULT_COLUMNS[$dataset],
                'date_fields' => array_map(fn (string $f): array => ['key' => $f, 'label' => $this->columnLabel($f)], self::DATE_FIELDS[$dataset]),
                'filters' => $this->filterOptions($dataset),
                'domain_access' => $domain,
            ];
        }, self::DATASETS);
    }

    /**
     * Runs a definition. `$limit` caps the returned rows (preview); `total` is always the full match count.
     *
     * @param  array{columns?: array<int, string>, filters?: array<string, mixed>, group_by?: string|null, sort?: string|null, dir?: string|null}  $definition
     * @return array{dataset: string, title: string, columns: array<int, array{key: string, label: string}>, rows: array<int, array<string, mixed>>, total: int, grouped: bool, truncated: bool, series: array<int, array{label: string, value: int, key: string}>}
     */
    public function run(User $user, string $dataset, array $definition, ?int $limit = null): array
    {
        if (! $this->exists($dataset)) {
            throw new DomainException(__('reporting.errors.unknown_dataset'), 'report_dataset_unknown', 422);
        }

        $columns = $this->columns($dataset);
        $domain = $this->hasDomainAccess($user, $dataset);
        $picked = $this->pickColumns($dataset, $columns, (array) ($definition['columns'] ?? []), $domain);
        $groupBy = $definition['group_by'] ?? null;
        if ($groupBy !== null && $groupBy !== '') {
            $spec = $columns[$groupBy] ?? null;
            if ($spec === null || ! ($spec['group'] ?? false) || ! $this->columnAllowed($spec, $domain)) {
                throw new DomainException(__('reporting.errors.bad_group'), 'report_group_invalid', 422, ['group_by' => [__('reporting.errors.bad_group')]]);
            }
        } else {
            $groupBy = null;
        }

        $query = $this->filtered($dataset, (array) ($definition['filters'] ?? []));
        $total = (clone $query)->count();
        $cap = $this->maxExportRows();
        $title = __('reporting.datasets.'.$dataset);

        if ($groupBy !== null) {
            $resolve = $columns[$groupBy]['v'];
            $groups = $query->limit($cap)->get()
                ->groupBy(fn (Model $m): string => (string) ($this->scalar($resolve($m)) ?? '—'))
                ->map(fn (Collection $g, string $label): array => ['group' => $label === '' ? '—' : $label, 'count' => $g->count()])
                ->sortByDesc('count')->values();

            $rows = $groups->all();

            return [
                'dataset' => $dataset,
                'title' => $title,
                'columns' => [['key' => 'group', 'label' => $this->columnLabel($groupBy)], ['key' => 'count', 'label' => __('reporting.columns.count')]],
                'rows' => $limit !== null ? array_slice($rows, 0, $limit) : $rows,
                'total' => $total,
                'grouped' => true,
                'truncated' => $total > $cap,
                'series' => array_map(fn (array $r): array => ['label' => (string) $r['group'], 'value' => (int) $r['count'], 'key' => (string) $r['group']], $rows),
            ];
        }

        $sort = (string) ($definition['sort'] ?? '');
        $dir = strtolower((string) ($definition['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $take = min($limit ?? $cap, $cap);
        $models = $query->limit($take)->get();

        $rows = $models->map(function (Model $m) use ($picked, $columns, $dataset, $domain): array {
            $row = [];
            foreach ($picked as $key) {
                $row[$key] = $this->scalar($columns[$key]['v']($m));
            }
            if ($domain) {
                $row['_link'] = $this->link($dataset, $m);
            }

            return $row;
        });

        if ($sort !== '' && in_array($sort, $picked, true)) {
            $rows = $rows->sortBy(fn (array $r) => $r[$sort] ?? '', SORT_NATURAL, $dir === 'desc');
        }

        return [
            'dataset' => $dataset,
            'title' => $title,
            'columns' => array_map(fn (string $k): array => ['key' => $k, 'label' => $this->columnLabel($k)], $picked),
            'rows' => $rows->values()->all(),
            'total' => $total,
            'grouped' => false,
            'truncated' => $total > $take,
            'series' => [],
        ];
    }

    public function columnLabel(string $key): string
    {
        $k = 'reporting.columns.'.$key;
        $label = __($k);

        return $label === $k ? $key : $label;
    }

    // ---------------------------------------------------------------- columns

    /**
     * key => [s: Sensitivity, v: fn(Model): mixed, group?: bool].
     *
     * @return array<string, array{s: Sensitivity, v: \Closure, group?: bool}>
     */
    private function columns(string $dataset): array
    {
        $date = fn (?Carbon $d): ?string => $d?->toDateString();
        $yesNo = fn (bool $b): string => __($b ? 'reporting.yes' : 'reporting.no');
        $month = fn (?Carbon $d): ?string => $d?->format('Y-m');
        $p = Sensitivity::Public;
        $i = Sensitivity::Internal;
        $c = Sensitivity::Confidential;

        return match ($dataset) {
            'opportunities' => [
                'reference_no' => ['s' => $p, 'v' => fn (Opportunity $o) => $o->reference_no],
                'name' => ['s' => $i, 'v' => fn (Opportunity $o) => $o->name],
                'status' => ['s' => $i, 'v' => fn (Opportunity $o) => $o->status->label(), 'group' => true],
                'sector' => ['s' => $p, 'v' => fn (Opportunity $o) => $o->sector?->label(), 'group' => true],
                'stage' => ['s' => $p, 'v' => fn (Opportunity $o) => $o->stage?->label(), 'group' => true],
                'regions' => ['s' => $p, 'v' => fn (Opportunity $o) => $o->regions->map(fn (TaxonomyTerm $t): string => $t->label())->implode('، ')],
                'funding_range' => ['s' => $i, 'v' => fn (Opportunity $o) => $o->fundingRange?->label(), 'group' => true],
                'funding_amount' => ['s' => $c, 'v' => fn (Opportunity $o) => $o->funding_amount === null ? null : (float) $o->funding_amount],
                'organization' => ['s' => $i, 'v' => fn (Opportunity $o) => $o->organization?->name],
                'owner' => ['s' => $c, 'v' => fn (Opportunity $o) => $o->owner?->name],
                'assignee' => ['s' => $i, 'v' => fn (Opportunity $o) => $o->assignedReviewer?->name, 'group' => true],
                'evaluation_result' => ['s' => $c, 'v' => fn (Opportunity $o) => $o->evaluation_result?->label(), 'group' => true],
                'submitted_at' => ['s' => $i, 'v' => fn (Opportunity $o) => $date($o->submitted_at)],
                'review_due_at' => ['s' => $i, 'v' => fn (Opportunity $o) => $date($o->slaDueAt())],
                'overdue' => ['s' => $i, 'v' => fn (Opportunity $o) => $yesNo($o->overdueKind() !== null), 'group' => true],
                'month' => ['s' => $i, 'v' => fn (Opportunity $o) => $month($o->submitted_at), 'group' => true],
            ],
            'memberships' => [
                'investor' => ['s' => $c, 'v' => fn (Membership $m) => $m->user?->name],
                'investor_type' => ['s' => $i, 'v' => fn (Membership $m) => $m->user?->type->label(), 'group' => true],
                'organization' => ['s' => $i, 'v' => fn (Membership $m) => $m->user?->organization?->name],
                'status' => ['s' => $i, 'v' => fn (Membership $m) => $m->status->label(), 'group' => true],
                'assignee' => ['s' => $i, 'v' => fn (Membership $m) => $m->assignedReviewer?->name, 'group' => true],
                'submitted_at' => ['s' => $i, 'v' => fn (Membership $m) => $date($m->submitted_at)],
                'approved_at' => ['s' => $i, 'v' => fn (Membership $m) => $date($m->approved_at)],
                'completion' => ['s' => $i, 'v' => fn (Membership $m) => (int) ($m->user?->investorProfile?->completion_percent ?? 0)],
                'sectors' => ['s' => $i, 'v' => fn (Membership $m) => $m->user?->investorProfile?->sectors->map(fn (TaxonomyTerm $t): string => $t->label())->implode('، ')],
                'regions' => ['s' => $i, 'v' => fn (Membership $m) => $m->user?->investorProfile?->regions->map(fn (TaxonomyTerm $t): string => $t->label())->implode('، ')],
                'investment_range' => ['s' => $i, 'v' => fn (Membership $m) => $m->user?->investorProfile?->investmentRange?->label(), 'group' => true],
                'how_heard' => ['s' => $i, 'v' => fn (Membership $m) => $m->user?->investorProfile?->howHeard?->label(), 'group' => true],
                'month' => ['s' => $i, 'v' => fn (Membership $m) => $month($m->submitted_at ?? $m->created_at), 'group' => true],
            ],
            'interests' => [
                'opportunity_ref' => ['s' => $p, 'v' => fn (Interest $x) => $x->opportunity?->reference_no],
                'opportunity' => ['s' => $i, 'v' => fn (Interest $x) => $x->opportunity?->name, 'group' => true],
                'investor' => ['s' => $c, 'v' => fn (Interest $x) => $x->investor?->name],
                'status' => ['s' => $i, 'v' => fn (Interest $x) => $x->status->label(), 'group' => true],
                'owner_decision' => ['s' => $i, 'v' => fn (Interest $x) => $x->owner_decision?->label(), 'group' => true],
                'source' => ['s' => $i, 'v' => fn (Interest $x) => __($x->nomination_id ? 'reporting.values.via_nomination' : 'reporting.values.direct'), 'group' => true],
                'created_at' => ['s' => $i, 'v' => fn (Interest $x) => $date($x->created_at)],
                'owner_decision_due_at' => ['s' => $i, 'v' => fn (Interest $x) => $date($x->owner_decision_due_at)],
                'month' => ['s' => $i, 'v' => fn (Interest $x) => $month($x->created_at), 'group' => true],
            ],
            'nominations' => [
                'opportunity_ref' => ['s' => $p, 'v' => fn (Nomination $n) => $n->opportunity?->reference_no],
                'opportunity' => ['s' => $i, 'v' => fn (Nomination $n) => $n->opportunity?->name, 'group' => true],
                'investor' => ['s' => $c, 'v' => fn (Nomination $n) => $n->investor?->name],
                'response' => ['s' => $i, 'v' => fn (Nomination $n) => $n->response?->label() ?? __('reporting.values.no_response'), 'group' => true],
                'match_score' => ['s' => $i, 'v' => fn (Nomination $n) => $n->match_score],
                'nominator' => ['s' => $i, 'v' => fn (Nomination $n) => $n->nominator?->name, 'group' => true],
                'sent_at' => ['s' => $i, 'v' => fn (Nomination $n) => $date($n->sent_at)],
                'responded_at' => ['s' => $i, 'v' => fn (Nomination $n) => $date($n->responded_at)],
                'month' => ['s' => $i, 'v' => fn (Nomination $n) => $month($n->sent_at), 'group' => true],
            ],
            'meetings' => [
                'title' => ['s' => $i, 'v' => fn (Meeting $m) => $m->title],
                'opportunity_ref' => ['s' => $p, 'v' => fn (Meeting $m) => $m->opportunity?->reference_no],
                'opportunity' => ['s' => $i, 'v' => fn (Meeting $m) => $m->opportunity?->name, 'group' => true],
                'status' => ['s' => $i, 'v' => fn (Meeting $m) => $m->status?->label(), 'group' => true],
                'starts_at' => ['s' => $i, 'v' => fn (Meeting $m) => $m->starts_at?->format('Y-m-d H:i')],
                'organizer' => ['s' => $i, 'v' => fn (Meeting $m) => $m->organizer?->name, 'group' => true],
                'participants' => ['s' => $i, 'v' => fn (Meeting $m) => (int) $m->participants_count],
                'has_minutes' => ['s' => $i, 'v' => fn (Meeting $m) => $yesNo(filled($m->minutes)), 'group' => true],
                'month' => ['s' => $i, 'v' => fn (Meeting $m) => $month($m->starts_at), 'group' => true],
            ],
            'evaluations' => [
                'opportunity_ref' => ['s' => $p, 'v' => fn (EvaluationAssignment $a) => $a->opportunity?->reference_no],
                'opportunity' => ['s' => $i, 'v' => fn (EvaluationAssignment $a) => $a->opportunity?->name, 'group' => true],
                'evaluator' => ['s' => $i, 'v' => fn (EvaluationAssignment $a) => $a->evaluator?->name, 'group' => true],
                'status' => ['s' => $i, 'v' => fn (EvaluationAssignment $a) => $a->status->label(), 'group' => true],
                'due_at' => ['s' => $i, 'v' => fn (EvaluationAssignment $a) => $date($a->due_at)],
                'coi' => ['s' => $c, 'v' => fn (EvaluationAssignment $a) => $a->coi_declared_at === null ? null : $yesNo((bool) $a->coi_has_conflict), 'group' => true],
                'overall' => ['s' => $c, 'v' => fn (EvaluationAssignment $a) => $a->evaluation?->overall === null ? null : (float) $a->evaluation->overall],
                'recommendation' => ['s' => $c, 'v' => fn (EvaluationAssignment $a) => $a->evaluation?->recommendation?->label(), 'group' => true],
                'submitted_at' => ['s' => $i, 'v' => fn (EvaluationAssignment $a) => $date($a->evaluation?->submitted_at)],
                'month' => ['s' => $i, 'v' => fn (EvaluationAssignment $a) => $month($a->created_at), 'group' => true],
            ],
            'accounts' => [
                'name' => ['s' => $c, 'v' => fn (User $u) => $u->name],
                'type' => ['s' => $i, 'v' => fn (User $u) => $u->type->label(), 'group' => true],
                'status' => ['s' => $i, 'v' => fn (User $u) => $u->status->label(), 'group' => true],
                'verified' => ['s' => $i, 'v' => fn (User $u) => $yesNo($u->email_verified_at !== null), 'group' => true],
                'created_at' => ['s' => $i, 'v' => fn (User $u) => $date($u->created_at)],
                'last_login_at' => ['s' => $i, 'v' => fn (User $u) => $date($u->last_login_at)],
                'month' => ['s' => $i, 'v' => fn (User $u) => $month($u->created_at), 'group' => true],
            ],
        };
    }

    /** @param array{s: Sensitivity} $column */
    private function columnAllowed(array $column, bool $domain): bool
    {
        return match ($column['s']) {
            Sensitivity::Public, Sensitivity::Internal => true,
            Sensitivity::Confidential => $domain,
            Sensitivity::SensitivePersonal => false,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $columns
     * @param  array<int, mixed>  $requested
     * @return array<int, string>
     */
    private function pickColumns(string $dataset, array $columns, array $requested, bool $domain): array
    {
        $requested = array_values(array_unique(array_map('strval', $requested)));
        if ($requested === []) {
            $requested = self::DEFAULT_COLUMNS[$dataset];
        }

        $picked = [];
        foreach ($requested as $key) {
            if (! isset($columns[$key])) {
                throw new DomainException(__('reporting.errors.bad_column', ['column' => $key]), 'report_column_invalid', 422, ['columns' => [__('reporting.errors.bad_column', ['column' => $key])]]);
            }
            if (! $this->columnAllowed($columns[$key], $domain)) {
                // A confidential column the user may not see is dropped (never an error: saved reports stay runnable).
                continue;
            }
            $picked[] = $key;
        }

        if ($picked === []) {
            throw new DomainException(__('reporting.errors.no_columns'), 'report_columns_empty', 422, ['columns' => [__('reporting.errors.no_columns')]]);
        }

        return $picked;
    }

    /** @return array{type: string, id: string|int}|null */
    private function link(string $dataset, Model $m): ?array
    {
        return match ($dataset) {
            'opportunities' => ['type' => 'opportunity', 'id' => $m->uuid],
            'memberships' => ['type' => 'membership', 'id' => $m->id],
            'interests', 'nominations', 'meetings', 'evaluations' => $m->opportunity ? ['type' => 'opportunity', 'id' => $m->opportunity->uuid] : null,
            'accounts' => ['type' => 'user', 'id' => $m->id],
            default => null,
        };
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return is_array($value) ? implode('، ', array_map('strval', $value)) : $value;
    }

    // ---------------------------------------------------------------- queries & filters

    /** @param array<string, mixed> $filters */
    private function filtered(string $dataset, array $filters): Builder
    {
        $list = fn (mixed $v): array => array_values(array_filter(array_map(fn ($x): string => trim((string) $x), is_array($v) ? $v : explode(',', (string) $v)), fn (string $x): bool => $x !== ''));
        $f = fn (string $k): mixed => $filters[$k] ?? null;

        $query = match ($dataset) {
            'opportunities' => Opportunity::query()->whereNotNull('submitted_at')
                ->with(['sector', 'regions', 'fundingRange', 'organization', 'owner', 'assignedReviewer']),
            'memberships' => Membership::query()
                ->with(['user.organization', 'user.investorProfile.sectors', 'user.investorProfile.regions', 'user.investorProfile.investmentRange', 'user.investorProfile.howHeard', 'assignedReviewer']),
            'interests' => Interest::query()->with(['opportunity', 'investor']),
            'nominations' => Nomination::query()->with(['opportunity', 'investor', 'nominator']),
            'meetings' => Meeting::query()->with(['opportunity', 'organizer'])->withCount('participants'),
            'evaluations' => EvaluationAssignment::query()->with(['opportunity', 'evaluator', 'evaluation']),
            'accounts' => User::query()->whereNull('anonymized_at'),
        };

        // Period on a whitelisted date column (bare dates cover the whole day).
        $dateField = in_array($f('date_field'), self::DATE_FIELDS[$dataset], true) ? (string) $f('date_field') : self::DATE_FIELDS[$dataset][0];
        $from = $this->bound($f('from'), false);
        $to = $this->bound($f('to'), true);
        $query->when($from !== null, fn (Builder $q) => $q->where($dateField, '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where($dateField, '<=', $to));

        $status = $list($f('status'));
        if ($status !== []) {
            $column = $dataset === 'nominations' ? 'response' : 'status';
            $none = in_array('none', $status, true);
            $values = array_values(array_diff($status, ['none']));
            $query->where(fn (Builder $q) => $q->when($values !== [], fn (Builder $w) => $w->whereIn($column, $values))->when($none, fn (Builder $w) => $w->orWhereNull($column)));
        }

        $this->termFilter($query, $dataset, 'sectors', $f('sector'));
        $this->termFilter($query, $dataset, 'regions', $f('region'));

        $assignee = $f('assignee');
        if (filled($assignee) && in_array($dataset, ['opportunities', 'memberships'], true)) {
            $assignee === 'none' ? $query->whereNull('assigned_reviewer_id') : $query->where('assigned_reviewer_id', (int) $assignee);
        }

        if ($dataset === 'opportunities' && filter_var($f('overdue'), FILTER_VALIDATE_BOOLEAN)) {
            $query->overdue();
        }

        $type = $list($f('investor_type'));
        if ($type !== [] && in_array($dataset, ['memberships', 'accounts'], true)) {
            $types = array_values(array_filter(array_map(fn (string $t): ?string => match ($t) {
                'individual' => UserType::InvestorIndividual->value,
                'institutional' => UserType::InvestorInstitutional->value,
                default => UserType::tryFrom($t)?->value,
            }, $type)));
            $dataset === 'accounts'
                ? $query->whereIn('type', $types === [] ? ['-'] : $types)
                : $query->whereHas('user', fn (Builder $u) => $u->whereIn('type', $types === [] ? ['-'] : $types));
        }

        $howHeard = $f('how_heard');
        if (filled($howHeard) && $dataset === 'memberships') {
            $howHeard === 'none'
                ? $query->whereHas('user', fn (Builder $u) => $u->whereDoesntHave('investorProfile')->orWhereHas('investorProfile', fn (Builder $p) => $p->whereNull('how_heard_id')))
                : $query->whereHas('user.investorProfile', fn (Builder $p) => $p->where('how_heard_id', $this->termId('how_heard', (string) $howHeard) ?? 0));
        }

        $ids = $list($f('ids'));
        if ($ids !== []) {
            $dataset === 'opportunities' ? $query->whereIn('uuid', $ids) : $query->whereIn($query->getModel()->getQualifiedKeyName(), array_map('intval', $ids));
        }

        return $query->orderByDesc($query->getModel()->getQualifiedKeyName());
    }

    /** Sector / region filter (term key or id; `none` = no sector), directly or through the related opportunity. */
    private function termFilter(Builder $query, string $dataset, string $group, mixed $value): void
    {
        if (! filled($value)) {
            return;
        }
        $id = $value === 'none' ? null : ($this->termId($group, (string) $value) ?? 0);

        $onOpportunity = function (Builder $o) use ($group, $id): void {
            if ($group === 'sectors') {
                $id === null ? $o->whereNull('sector_id') : $o->where('sector_id', $id);

                return;
            }
            $id === null ? $o->whereDoesntHave('regions') : $o->whereHas('regions', fn (Builder $r) => $r->where('taxonomy_terms.id', $id));
        };

        match ($dataset) {
            'opportunities' => $onOpportunity($query),
            'memberships' => $id === null
                ? $query->whereDoesntHave('user.investorProfile.'.$group)
                : $query->whereHas('user.investorProfile.'.$group, fn (Builder $t) => $t->where('taxonomy_terms.id', $id)),
            'accounts' => null,
            default => $query->whereHas('opportunity', $onOpportunity),
        };
    }

    private function termId(string $group, string $value): ?int
    {
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $id = TaxonomyTerm::query()->where('group', $group)->where('key', $value)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function bound(mixed $value, bool $endOfDay): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }
        try {
            $date = Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) === 1 ? ($endOfDay ? $date->endOfDay() : $date->startOfDay()) : $date;
    }

    /** @return array<string, array<int, array{value: string, label: string}>> */
    private function filterOptions(string $dataset): array
    {
        $opts = fn (array $options): array => array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys($options), $options);

        $status = match ($dataset) {
            'opportunities' => array_diff_key(OpportunityStatus::options(), [OpportunityStatus::Draft->value => true]),
            'memberships' => MembershipStatus::options(),
            'interests' => InterestStatus::options(),
            'nominations' => NominationResponse::options() + ['none' => __('reporting.values.no_response')],
            'meetings' => MeetingStatus::options(),
            'evaluations' => AssignmentStatus::options(),
            'accounts' => UserStatus::options(),
        };

        $out = ['status' => $opts($status)];
        if ($dataset !== 'accounts') {
            $out['sector'] = [];
            $out['region'] = [];
        }
        if (in_array($dataset, ['opportunities', 'memberships'], true)) {
            $out['assignee'] = [];
        }
        if ($dataset === 'opportunities') {
            $out['overdue'] = [];
        }
        if ($dataset === 'memberships') {
            $out['investor_type'] = $opts(['individual' => UserType::InvestorIndividual->label(), 'institutional' => UserType::InvestorInstitutional->label()]);
        }
        if ($dataset === 'accounts') {
            $out['investor_type'] = $opts(UserType::options());
        }

        return $out;
    }
}
