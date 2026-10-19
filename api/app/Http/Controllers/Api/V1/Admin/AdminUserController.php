<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ACC-06 — user & role management (super_admin). */
class AdminUserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /admin/users */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $paginator = User::query()
            ->with(['organization', 'membership', 'roles'])
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->input('type')))
            ->when($request->filled('role'), fn (Builder $q) => $q->role($request->input('role')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->input('search').'%')->orWhere('email', 'like', '%'.$request->input('search').'%')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => UserResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /admin/users — create staff. */
    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        $this->assertCan($request);
        $data = $request->validated();

        $user = User::query()->create([
            'type' => UserType::Staff,
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'ar',
            'email_verified_at' => now(),
            'phone_verified_at' => isset($data['phone']) ? now() : null,
        ]);
        $user->syncRoles($data['roles']);

        $this->audit->log('user.staff_created', $user, null, ['roles' => $data['roles']], $request->user());

        return response()->json(['data' => (new UserResource($user->load('roles')))->resolve($request)], 201);
    }

    /** PATCH /admin/users/{id} {roles[], status} */
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

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('users.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
