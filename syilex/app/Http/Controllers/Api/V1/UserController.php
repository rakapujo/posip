<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\GuardsRoleAssignments;
use App\Models\MasterPosTerminal;
use App\Models\PosTerminalShift;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends BaseApiController
{
    use GuardsRoleAssignments;

    /**
     * Display a listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('user.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = User::with('roles')->visible();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        $sortableFields = ['name', 'email', 'status', 'created_at'];
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        $perPage = $this->getPerPage($request);
        $users = $query->paginate($perPage);

        return $this->success([
            'users' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        if (! auth()->user()->can('user.create')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:users,email',
            'password' => ['required', Password::min(8)->numbers()],
            'pin' => 'required|digits:6',
            'phone' => 'required|string|max:20',
            'role' => 'required|string|exists:roles,name',
            'status' => 'required|in:active,inactive',
            'avatar' => 'nullable|string',
        ]);

        $validated['name'] = SettingService::formatName($validated['name']);

        if ($deny = $this->assertAssignableRole($validated['role'])) {
            return $deny;
        }

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'pin' => $validated['pin'],
                'phone' => $validated['phone'],
                'avatar' => $validated['avatar'] ?? null,
                'status' => $validated['status'],
            ]);

            $user->assignRole($validated['role']);

            activity('User')
                ->causedBy(auth()->user())
                ->performedOn($user)
                ->withProperties(['roles' => [$validated['role']]])
                ->log('Role ditugaskan');

            return $user->load('roles');
        });

        return $this->success([
            'user' => $user,
        ], 'User berhasil dibuat', 201);
    }

    /**
     * Display the specified user.
     */
    public function show(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('user.view')) {
            return $this->error('Unauthorized', 403);
        }

        $user = User::with('roles')->visible()->where('ulid', $ulid)->first();

        if (! $user) {
            return $this->error('User tidak ditemukan', 404);
        }

        return $this->success([
            'user' => $user,
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, string $ulid): JsonResponse
    {
        if (! auth()->user()->can('user.update')) {
            return $this->error('Unauthorized', 403);
        }

        $user = User::where('ulid', $ulid)->first();

        if (! $user) {
            return $this->error('User tidak ditemukan', 404);
        }

        if ($user->isProtected()) {
            return $this->error('User ini tidak dapat diubah', 403);
        }

        $user->load('roles');

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:100', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', Password::min(8)->numbers()],
            'pin' => 'nullable|digits:6',
            'phone' => 'required|string|max:20',
            'role' => 'required|string|exists:roles,name',
            'status' => 'required|in:active,inactive',
            'avatar' => 'nullable|string',
            'unassign_terminals' => 'nullable|boolean',
        ]);

        if ($user->id === auth()->id() && $validated['status'] === 'inactive') {
            return $this->error('Tidak dapat menonaktifkan akun sendiri', 400);
        }

        $currentRole = $user->roles->first()?->name;
        $roleChanging = $currentRole !== $validated['role'];

        if ($roleChanging && $user->id === auth()->id()) {
            return $this->error('Tidak dapat mengubah role akun sendiri', 400);
        }

        if ($deny = $this->assertLastActiveSuperAdminGuard($user, $validated['role'], $validated['status'])) {
            return $deny;
        }

        $hadPosAccess = $user->can('pos.access');
        $willHavePosAccess = Role::findByName($validated['role'], 'web')
            ?->hasPermissionTo('pos.access') ?? false;

        if ($hadPosAccess && ! $willHavePosAccess) {
            $assignedTerminals = DB::table('pos_terminal_users')
                ->join('master_pos_terminal', 'pos_terminal_users.terminal_id', '=', 'master_pos_terminal.id')
                ->where('pos_terminal_users.user_id', $user->id)
                ->select('master_pos_terminal.id', 'master_pos_terminal.ulid', 'master_pos_terminal.nama_terminal')
                ->get();
            if ($assignedTerminals->isNotEmpty() && empty($validated['unassign_terminals'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role baru tidak memiliki akses POS — user masih ter-assign di beberapa terminal',
                    'code' => 'REQUIRES_UNASSIGN_CONFIRMATION',
                    'data' => [
                        'terminals' => $assignedTerminals,
                    ],
                ], 409);
            }
        }

        $validated['name'] = SettingService::formatName($validated['name']);

        if ($deny = $this->assertAssignableRole($validated['role'])) {
            return $deny;
        }

        $isBeingDeactivated = $user->status === 'active' && $validated['status'] === 'inactive';
        $oldRoles = $user->getRoleNames()->all();

        $user = DB::transaction(function () use ($user, $validated, $isBeingDeactivated, $hadPosAccess, $willHavePosAccess, $roleChanging, $oldRoles) {
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->phone = $validated['phone'];
            $user->status = $validated['status'];
            $user->avatar = $validated['avatar'] ?? $user->avatar;

            if (! empty($validated['password'])) {
                $user->password = $validated['password'];
            }

            if (! empty($validated['pin'])) {
                $user->pin = $validated['pin'];
            }

            $user->save();

            if ($isBeingDeactivated) {
                $user->tokens()->delete();
                $this->releaseUserFromPos($user);
            }

            $user->syncRoles([$validated['role']]);

            if ($roleChanging) {
                activity('User')
                    ->causedBy(auth()->user())
                    ->performedOn($user)
                    ->withProperties([
                        'roles_before' => $oldRoles,
                        'roles_after' => [$validated['role']],
                    ])
                    ->log('Role diubah');
            }

            if ($hadPosAccess && ! $willHavePosAccess && ! empty($validated['unassign_terminals'])) {
                $this->releaseUserFromPos($user);
            }

            return $user->load('roles');
        });

        return $this->success([
            'user' => $user,
        ], 'User berhasil diupdate');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('user.delete')) {
            return $this->error('Unauthorized', 403);
        }

        $user = User::where('ulid', $ulid)->first();

        if (! $user) {
            return $this->error('User tidak ditemukan', 404);
        }

        if ($user->isProtected()) {
            return $this->error('User ini tidak dapat dihapus', 403);
        }

        if ($user->id === auth()->id()) {
            return $this->error('Tidak dapat menghapus akun sendiri', 400);
        }

        if ($user->hasRole('super-admin')) {
            $superAdminCount = User::role('super-admin')->count();
            if ($superAdminCount <= 1) {
                return $this->error('Tidak dapat menghapus Super Admin terakhir', 400);
            }
        }

        $relatedRecords = $this->countUserRecords($user->id);
        if ($relatedRecords > 0) {
            return $this->error("Tidak dapat menghapus user karena memiliki {$relatedRecords} data transaksi. Nonaktifkan user sebagai alternatif.", 422);
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $this->releaseUserFromPos($user);
            $user->delete();
        });

        return $this->success(null, 'User berhasil dihapus');
    }

    /**
     * Count user's related records across all transaction tables.
     */
    private function countUserRecords(int $userId): int
    {
        $count = 0;

        $tablesWithCreatedApproved = [
            'doc_purchase_order',
            'doc_adjustment',
            'doc_transfer',
            'doc_repack',
            'doc_stock_opname',
            'doc_hpp_correction',
            'doc_sales',
            'doc_sales_returns',
            'doc_purchase_return',
            'doc_serial_intake',
            'doc_serial_change',
            'doc_serial_hpp_correction',
            'doc_pembayaran_hutang',
            'doc_pembayaran_piutang',
            'doc_price_change',
            'doc_promo',
        ];

        foreach ($tablesWithCreatedApproved as $table) {
            if (! \Schema::hasTable($table)) {
                continue;
            }
            $q = DB::table($table)->where(function ($q) use ($userId, $table) {
                $q->where('created_by', $userId);
                if (\Schema::hasColumn($table, 'approved_by')) {
                    $q->orWhere('approved_by', $userId);
                }
            });
            $count += $q->count();
        }

        if (\Schema::hasTable('stock_card')) {
            $count += DB::table('stock_card')->where('created_by', $userId)->count();
        }

        return $count;
    }

    /**
     * Toggle user status (active/inactive).
     */
    public function toggleStatus(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('user.update')) {
            return $this->error('Unauthorized', 403);
        }

        $user = User::where('ulid', $ulid)->first();

        if (! $user) {
            return $this->error('User tidak ditemukan', 404);
        }

        if ($user->isProtected()) {
            return $this->error('User ini tidak dapat diubah statusnya', 403);
        }

        if ($user->id === auth()->id()) {
            return $this->error('Tidak dapat mengubah status akun sendiri', 400);
        }

        if ($user->hasRole('super-admin') && $user->status === 'active') {
            $activeSuperAdminCount = User::role('super-admin')->where('status', 'active')->count();
            if ($activeSuperAdminCount <= 1) {
                return $this->error('Tidak dapat menonaktifkan Super Admin terakhir yang aktif', 400);
            }
        }

        $user = DB::transaction(function () use ($user) {
            $isBeingDeactivated = $user->status === 'active';
            $user->status = $user->status === 'active' ? 'inactive' : 'active';
            $user->save();

            if ($isBeingDeactivated) {
                $user->tokens()->delete();
                $this->releaseUserFromPos($user);
            }

            return $user->load('roles');
        });

        $statusLabel = $user->status === 'active' ? 'diaktifkan' : 'dinonaktifkan';

        return $this->success([
            'user' => $user,
        ], "User berhasil {$statusLabel}");
    }

    /**
     * Get list of active users for dropdowns.
     */
    public function list(Request $request): JsonResponse
    {
        $actor = auth()->user();
        $allowed = $actor->can('user.view')
            || $actor->can('terminal.create')
            || $actor->can('terminal.edit')
            || ($request->filled('permission') && $actor->can($request->input('permission')));

        if (! $allowed) {
            return $this->error('Unauthorized', 403);
        }

        $query = User::visible()
            ->where('status', 'active')
            ->select('id', 'ulid', 'name', 'email');

        if ($request->filled('permission')) {
            $permission = $request->input('permission');
            // Whitelist: only pos.access is used by terminal UI; block enumeration of other perms.
            if ($permission !== 'pos.access') {
                return $this->error('Filter permission tidak diizinkan', 422);
            }
            $includeIds = array_filter((array) $request->input('include_ids', []));
            $query->where(function ($q) use ($permission, $includeIds) {
                $q->permission($permission);
                if (! empty($includeIds)) {
                    $q->orWhereIn('users.id', $includeIds);
                }
            });
        }

        $users = $query->orderBy('name')->get()->makeVisible('id');

        return $this->success([
            'users' => $users,
        ]);
    }

    /**
     * Get list of available roles.
     */
    public function roles(): JsonResponse
    {
        if (! auth()->user()->canAny(['user.view', 'user.create', 'user.update'])) {
            return $this->error('Unauthorized', 403);
        }

        $roles = Role::select('id', 'name')->orderBy('name')->get();

        if (! $this->actorIsSuperAdmin()) {
            $roles = $roles->reject(fn ($r) => $r->name === 'super-admin')->values();
        }

        return $this->success([
            'roles' => $roles,
        ]);
    }

    /**
     * Clear POS occupancy for a user: end open shifts, free terminals, detach pivot.
     * ponytail: no saldo snapshot (forceRelease path); add if ops need reconciliation notes.
     */
    private function releaseUserFromPos(User $user): void
    {
        PosTerminalShift::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->update([
                'ended_at' => now(),
                'ended_by_force' => true,
                'forced_by' => auth()->id(),
                'closing_notes' => 'Released: user deactivated, deleted, or lost POS access',
            ]);

        MasterPosTerminal::where('active_user_id', $user->id)
            ->update(['active_user_id' => null]);

        DB::table('pos_terminal_users')->where('user_id', $user->id)->delete();
    }

    /**
     * Block demoting / deactivating the last active super-admin via update().
     * Non-SA actors cannot demote any current super-admin.
     */
    private function assertLastActiveSuperAdminGuard(User $user, string $newRole, string $newStatus): ?JsonResponse
    {
        if (! $user->hasRole('super-admin')) {
            return null;
        }

        $demoting = $newRole !== 'super-admin';
        $deactivating = $user->status === 'active' && $newStatus === 'inactive';

        if (! $demoting && ! $deactivating) {
            return null;
        }

        if (! $this->actorIsSuperAdmin()) {
            return $this->error('Tidak dapat mengubah Super Admin', 403);
        }

        $activeSuperAdminCount = User::role('super-admin')->where('status', 'active')->count();
        if ($user->status === 'active' && $activeSuperAdminCount <= 1) {
            if ($demoting) {
                return $this->error('Tidak dapat mendemosi Super Admin terakhir yang aktif', 400);
            }

            return $this->error('Tidak dapat menonaktifkan Super Admin terakhir yang aktif', 400);
        }

        return null;
    }
}
