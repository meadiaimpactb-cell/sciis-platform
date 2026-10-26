<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Services\ApprovalMatrix;
use App\Services\AuditLogger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** ACC-03 — roles & permissions management (super_admin / users.manage). Guard `api`. */
class AdminRoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /admin/roles — roles with their permissions + the full permission catalogue. */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $roles = Role::query()->where('guard_name', 'api')->with('permissions')->orderBy('id')->get();
        $counts = $this->userCounts();

        return $this->ok([
            'roles' => $roles->map(fn (Role $r): array => $this->roleArray($r, (int) ($counts[$r->id] ?? 0)))->values()->all(),
            'permissions' => Permission::query()->where('guard_name', 'api')->orderBy('id')->pluck('name')->values()->all(),
        ]);
    }

    /** GET /admin/roles/approval-matrix — ADM-07: each decision × the roles holding its permissions (read-only, live). */
    public function matrix(Request $request, ApprovalMatrix $matrix): JsonResponse
    {
        $this->assertCan($request);

        $roles = Role::query()->where('guard_name', 'api')->with('permissions')->orderBy('id')->get();

        return $this->ok([
            'decisions' => $matrix->rows($roles, $this->userCounts()),
            'roles' => $roles->map(fn (Role $r): array => ['name' => $r->name, 'is_system' => array_key_exists($r->name, RolePermissionSeeder::ROLES)])->values()->all(),
        ]);
    }

    /** POST /admin/roles {name, permissions[]} */
    public function store(RoleRequest $request): JsonResponse
    {
        $this->assertCan($request);

        $role = Role::create(['name' => $request->validated('name'), 'guard_name' => 'api']);
        $role->syncPermissions((array) $request->validated('permissions', []));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->audit->log('role.created', null, null, ['role' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()], $request->user());

        return response()->json(['data' => $this->roleArray($role->load('permissions'), 0)], 201);
    }

    /** PATCH /admin/roles/{role} {permissions[]} — super_admin keeps every permission (SPEC §1.2). */
    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $this->assertCan($request);
        if ($role->guard_name !== 'api') {
            abort(404);
        }
        if ($role->name === 'super_admin') {
            abort(422, __('messages.roles.super_admin_locked'));
        }

        $before = ['permissions' => $role->permissions->pluck('name')->all()];
        if ($request->has('permissions')) {
            $role->syncPermissions((array) $request->validated('permissions', []));
        }
        if ($request->filled('name') && ! array_key_exists($role->name, RolePermissionSeeder::ROLES)) {
            $role->name = $request->validated('name');
            $role->save();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->load('permissions');
        $this->audit->log('role.updated', null, $before, ['role' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()], $request->user());

        return $this->ok($this->roleArray($role, (int) ($this->userCounts()[$role->id] ?? 0)));
    }

    /** Users per role, read from the pivot (spatie's `users()` relation needs a guard→model mapping we do not configure). @return array<int, int> */
    private function userCounts(): array
    {
        $table = (string) config('permission.table_names.model_has_roles', 'model_has_roles');

        return DB::table($table)->select('role_id', DB::raw('count(*) as total'))->groupBy('role_id')->pluck('total', 'role_id')->map(fn ($n): int => (int) $n)->all();
    }

    /** @return array<string, mixed> */
    private function roleArray(Role $role, int $usersCount): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'users_count' => $usersCount,
            'is_system' => array_key_exists($role->name, RolePermissionSeeder::ROLES),
        ];
    }

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('users.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
