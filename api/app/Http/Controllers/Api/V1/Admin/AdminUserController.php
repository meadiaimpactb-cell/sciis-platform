<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Interest;
use App\Models\Membership;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** ACC-06 — user & role management (super_admin); ADM-06 — the per-user file. */
class AdminUserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /admin/users — filters (`filter[x]` or top-level): type, role, status, search.
     *
     * `users.manage` → full UserResource. Other staff (pickers: assign reviewer/evaluators, meeting participants)
     * get a slim `{id, uuid, name, type, roles}` list and must pass `role=` or `search=`.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $manages = $user->can('users.manage');
        if (! $manages && ! $user->can('opportunities.view') && ! $user->can('meetings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }

        $filter = fn (string $key): mixed => $request->input('filter.'.$key, $request->input($key));
        $type = $filter('type');
        $role = $filter('role');
        $status = $filter('status');
        $search = $filter('search');

        if (! $manages && ! filled($role) && ! filled($search)) {
            abort(403, __('messages.errors.forbidden'));
        }

        $paginator = User::query()
            ->with($manages ? ['organization', 'membership', 'roles'] : ['roles'])
            ->when(filled($type), fn (Builder $q) => $q->where('type', $type))
            ->when(filled($role), fn (Builder $q) => $q->role(is_array($role) ? $role : explode(',', (string) $role)))
            ->when(filled($status), fn (Builder $q) => $q->where('status', $status))
            ->when(filled($search), fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when(! $manages, fn (Builder $q) => $q->active())
            ->orderBy($manages ? 'id' : 'name', $manages ? 'desc' : 'asc')
            ->paginate($this->perPage($request));

        $rows = $manages
            ? UserResource::collection($paginator->items())->resolve($request)
            : array_map(fn (User $u): array => ['id' => $u->id, 'uuid' => $u->uuid, 'name' => $u->name, 'type' => $u->type->value, 'roles' => $u->isStaff() ? $u->getRoleNames()->values()->all() : []], $paginator->items());

        return response()->json([
            'data' => $rows,
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    /**
     * GET /admin/users/{id} — ADM-06 user file: profile basics, roles, status, verification, 2FA, membership + its
     * history, linked opportunities (owners) or interests/nominations (investors), and the user's audit activity.
     *
     * Gate: `users.manage` for any user; `memberships.review` for investors (their membership file). Linked records
     * need `opportunities.view` / `nominations.manage`, the activity trail `audit.view` (or `users.manage`).
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        $manages = $viewer->can('users.manage');
        $membershipOfficer = $viewer->can('memberships.review') && $user->isInvestor();
        if (! $manages && ! $membershipOfficer) {
            abort(403, __('messages.errors.forbidden'));
        }

        // AUD-01 — staff read access to a user file (throttled: one row per viewer per user per 10 minutes).
        $this->audit->logThrottled('user.viewed', $user, $viewer);

        $user->load(['organization', 'membership.reviewer', 'membership.approver', 'investorProfile']);
        $membership = $user->membership;
        $canOpportunities = $viewer->can('opportunities.view');
        $canMatching = $viewer->can('nominations.manage') || $canOpportunities;
        $canActivity = $manages || $viewer->can('audit.view');
        $iso = fn (?\DateTimeInterface $d): ?string => $d?->format(DATE_ATOM);
        $opportunityRef = fn (?Opportunity $o): ?array => $o ? ['uuid' => $o->uuid, 'reference_no' => $o->reference_no, 'name' => $o->name] : null;

        $data = [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'type' => $user->type->value,
            'type_label' => $user->type->label(),
            'status' => $user->status?->value ?? 'active',
            'locale' => $user->locale,
            'preferred_contact' => $user->preferred_contact,
            'email_verified_at' => $iso($user->email_verified_at),
            'phone_verified_at' => $iso($user->phone_verified_at),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'two_factor_confirmed_at' => $iso($user->two_factor_confirmed_at),
            'last_login_at' => $iso($user->last_login_at),
            'anonymized_at' => $iso($user->anonymized_at),
            'created_at' => $iso($user->created_at),
            'roles' => $user->isStaff() ? $user->getRoleNames()->values()->all() : [],
            'organization' => $user->organization ? (new OrganizationResource($user->organization))->resolve($request) : null,
            'completion_percent' => $user->isInvestor() ? (int) ($user->investorProfile?->completion_percent ?? 0) : null,
            'membership' => $membership ? [
                'id' => $membership->id,
                'status' => $membership->status->value,
                'status_label' => $membership->status->label(),
                'submitted_at' => $iso($membership->submitted_at),
                'reviewed_at' => $iso($membership->reviewed_at),
                'approved_at' => $iso($membership->approved_at),
                'terms_accepted_at' => $iso($membership->terms_accepted_at),
                'reviewed_by' => $membership->reviewer?->name,
                'approved_by' => $membership->approver?->name,
                'completion_note' => $membership->completion_note,
                'suspended_reason' => $membership->suspended_reason,
                'decision_note' => $membership->decision_note,
            ] : null,
            'membership_history' => $membership ? $this->membershipHistory($membership) : [],
            'opportunities' => [],
            'interests' => [],
            'nominations' => [],
            'activity' => [],
            'can' => [
                'manage' => $manages,
                'review_membership' => $viewer->can('memberships.review'),
                'view_opportunities' => $canOpportunities,
                'view_matching' => $canMatching,
                'view_activity' => $canActivity,
            ],
        ];

        if ($user->isEntrepreneur() && $canOpportunities) {
            $data['opportunities'] = $user->opportunities()->latest('id')->limit(50)->get()->map(fn (Opportunity $o): array => [
                'uuid' => $o->uuid,
                'reference_no' => $o->reference_no,
                'name' => $o->name,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'submitted_at' => $iso($o->submitted_at),
                'updated_at' => $iso($o->updated_at),
            ])->values()->all();
        }

        if ($user->isInvestor() && $canMatching) {
            $data['interests'] = $user->interests()->with('opportunity')->latest('id')->limit(50)->get()->map(fn (Interest $i): array => [
                'id' => $i->id,
                'opportunity' => $opportunityRef($i->opportunity),
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'created_at' => $iso($i->created_at),
                'closed_at' => $iso($i->closed_at),
            ])->values()->all();
            $data['nominations'] = $user->nominations()->with('opportunity')->latest('id')->limit(50)->get()->map(fn (Nomination $n): array => [
                'id' => $n->id,
                'opportunity' => $opportunityRef($n->opportunity),
                'match_score' => $n->match_score,
                'sent_at' => $iso($n->sent_at),
                'response' => $n->response?->value,
                'response_label' => $n->response?->label(),
                'responded_at' => $iso($n->responded_at),
            ])->values()->all();
        }

        if ($canActivity) {
            $data['activity'] = $this->activity($user, $membership);
        }

        return $this->ok($data);
    }

    /**
     * POST /admin/users — create staff.
     * `password` is optional: when omitted a strong temporary password is generated and the password-reset link is emailed.
     */
    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        $this->assertCan($request);
        $data = $request->validated();
        $generated = empty($data['password']);

        $user = User::query()->create([
            'type' => UserType::Staff,
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => $generated ? $this->temporaryPassword() : $data['password'],
            'locale' => $data['locale'] ?? 'ar',
            'email_verified_at' => now(),
            'phone_verified_at' => isset($data['phone']) ? now() : null,
        ]);
        $user->syncRoles($data['roles']);

        $resetSent = false;
        if ($generated) {
            $resetSent = Password::broker()->sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
        }

        $this->audit->log('user.staff_created', $user, null, ['roles' => $data['roles'], 'password_reset_sent' => $resetSent], $request->user());

        return response()->json([
            'data' => (new UserResource($user->load('roles')))->resolve($request),
            'password_reset_sent' => $resetSent,
            'message' => $resetSent ? __('messages.users.reset_link_sent') : null,
        ], 201);
    }

    /** PATCH /admin/users/{id} {roles[], status, name} */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->assertCan($request);
        $data = $request->validated();
        $before = ['roles' => $user->getRoleNames()->all(), 'status' => $user->status->value];

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['status'])) {
            if ($user->id === $request->user()->id && $data['status'] === 'suspended') {
                abort(422, __('messages.users.cannot_suspend_self'));
            }
            $user->status = UserStatus::from($data['status']);
            if ($user->status === UserStatus::Suspended) {
                $user->tokens()->delete();
            }
        }
        $user->save();

        if (isset($data['roles'])) {
            if (! $user->isStaff()) {
                abort(422, __('messages.users.roles_staff_only'));
            }
            $user->syncRoles($data['roles']);
        }

        $this->audit->log('user.updated_by_admin', $user, $before, ['roles' => $user->getRoleNames()->all(), 'status' => $user->status->value], $request->user());

        return $this->ok((new UserResource($user->load('roles')))->resolve($request));
    }

    /**
     * Membership decisions read back from the audit trail (submitted → reviewed → decided …), oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function membershipHistory(Membership $membership): array
    {
        return AuditLog::query()->with('user')
            ->where('auditable_type', $membership->getMorphClass())
            ->where('auditable_id', $membership->id)
            ->whereIn('action', ['membership.submitted', 'membership.transition'])
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'from' => $log->before['status'] ?? null,
                'to' => $log->after['status'] ?? null,
                'note' => $log->after['note'] ?? null,
                'actor' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'at' => $log->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * Audit rows by the user (actor) or about the user / their membership (subject), newest first (max 100).
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(User $user, ?Membership $membership): array
    {
        return AuditLog::query()->with('user')
            ->where(function (Builder $q) use ($user, $membership): void {
                $q->where('user_id', $user->id)
                    ->orWhere(fn (Builder $s) => $s->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->id));
                if ($membership !== null) {
                    $q->orWhere(fn (Builder $s) => $s->where('auditable_type', $membership->getMorphClass())->where('auditable_id', $membership->id));
                }
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'uuid' => $log->uuid,
                'action' => $log->action,
                'role' => (int) $log->user_id === (int) $user->id ? 'actor' : 'subject',
                'actor' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'before' => $log->before,
                'after' => $log->after,
                'ip' => $log->ip,
                'at' => $log->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** Random password satisfying the staff policy (min 12, mixed case, number, symbol). Never shown to anyone. */
    private function temporaryPassword(): string
    {
        return Str::password(24).'Aa1!';
    }

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('users.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
