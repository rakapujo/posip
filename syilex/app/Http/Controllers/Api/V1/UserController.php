<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends BaseApiController
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('user.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = User::with('roles')->visible();

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        // Sort (whitelist allowed columns)
        $sortableFields = ['name', 'email', 'status', 'created_at'];
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        // Paginate
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
        // Check permission
        if (!auth()->user()->can('user.create')) {
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

        // Format name based on settings
        $validated['name'] = SettingService::formatName($validated['name']);

        // Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'pin' => $validated['pin'],
            'phone' => $validated['phone'],
            'avatar' => $validated['avatar'] ?? null,
            'status' => $validated['status'],
        ]);

        // Assign role
        $user->assignRole($validated['role']);

        // Load roles
        $user->load('roles');

        return $this->success([
            'user' => $user,
        ], 'User berhasil dibuat', 201);
    }

    /**
     * Display the specified user.
     */
    public function show(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('user.view')) {
            return $this->error('Unauthorized', 403);
        }

        $user = User::with('roles')->visible()->where('ulid', $ulid)->first();

        if (!$user) {
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
        // Check permission
        if (!auth()->user()->can('user.update')) {
            return $this->error('Unauthorized', 403);
        }

        $user = User::where('ulid', $ulid)->first();

        if (!$user) {
            return $this->error('User tidak ditemukan', 404);
        }

        // Prevent updating protected users
        if ($user->isProtected()) {
            return $this->error('User ini tidak dapat diubah', 403);
        }

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

        // Prevent deactivating self
        if ($user->id === auth()->id() && $validated['status'] === 'inactive') {
            return $this->error('Tidak dapat menonaktifkan akun sendiri', 400);
        }

        // Guard: role baru kehilangan pos.access sementara user masih ter-assign di terminal.
        // Minta konfirmasi admin (via flag unassign_terminals) sebelum detach pivot.
        $hadPosAccess = $user->can('pos.access');
        $willHavePosAccess = Role::where('name', $validated['role'])
            ->first()
            ?->hasPermissionTo('pos.access') ?? false;

        if ($hadPosAccess && !$willHavePosAccess) {
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

        // Format name based on settings
        $validated['name'] = SettingService::formatName($validated['name']);

        // Check if status is being changed to inactive
        $isBeingDeactivated = $user->status === 'active' && $validated['status'] === 'inactive';

        // Update user
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->phone = $validated['phone'];
        $user->status = $validated['status'];
        $user->avatar = $validated['avatar'] ?? $user->avatar;

        // Update password if provided
        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        // Update PIN if provided
        if (!empty($validated['pin'])) {
            $user->pin = $validated['pin'];
        }

        $user->save();

        // If user is being deactivated, revoke all their tokens (kick them out)
        if ($isBeingDeactivated) {
            $user->tokens()->delete();
        }

        // Sync role (remove old, assign new)
        $user->syncRoles([$validated['role']]);

        // Jika admin konfirmasi unassign, detach user dari semua terminal.
        // Hanya relevan saat role baru kehilangan pos.access.
        if ($hadPosAccess && !$willHavePosAccess && !empty($validated['unassign_terminals'])) {
            DB::table('pos_terminal_users')->where('user_id', $user->id)->delete();
        }

        // Load roles
        $user->load('roles');

        return $this->success([
            'user' => $user,
        ], 'User berhasil diupdate');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('user.delete')) {
            return $this->error('Unauthorized', 403);
        }

        $user = User::where('ulid', $ulid)->first();

        if (!$user) {
            return $this->error('User tidak ditemukan', 404);
        }

        // Prevent deleting protected users
        if ($user->isProtected()) {
            return $this->error('User ini tidak dapat dihapus', 403);
        }

        // Prevent deleting self
        if ($user->id === auth()->id()) {
            return $this->error('Tidak dapat menghapus akun sendiri', 400);
        }

        // Prevent deleting super-admin if only one left
        if ($user->hasRole('super-admin')) {
            $superAdminCount = User::role('super-admin')->count();
            if ($superAdminCount <= 1) {
                return $this->error('Tidak dapat menghapus Super Admin terakhir', 400);
            }
        }

        // Check if user has related records (transactions)
        $relatedRecords = $this->countUserRecords($user->id);
        if ($relatedRecords > 0) {
            return $this->error("Tidak dapat menghapus user karena memiliki {$relatedRecords} data transaksi. Nonaktifkan user sebagai alternatif.", 422);
        }

        $user->delete();

        return $this->success(null, 'User berhasil dihapus');
    }

    /**
     * Count user's related records across all transaction tables.
     */
    private function countUserRecords(int $userId): int
    {
        $count = 0;

        // Check Purchase Orders
        $count += \DB::table('doc_purchase_order')
            ->where('created_by', $userId)
            ->orWhere('approved_by', $userId)
            ->count();

        // Check Adjustments
        if (\Schema::hasTable('doc_adjustment')) {
            $count += \DB::table('doc_adjustment')
                ->where('created_by', $userId)
                ->orWhere('approved_by', $userId)
                ->count();
        }

        // Check Transfers
        if (\Schema::hasTable('doc_transfer')) {
            $count += \DB::table('doc_transfer')
                ->where('created_by', $userId)
                ->orWhere('approved_by', $userId)
                ->count();
        }

        // Check Repacks
        if (\Schema::hasTable('doc_repack')) {
            $count += \DB::table('doc_repack')
                ->where('created_by', $userId)
                ->orWhere('approved_by', $userId)
                ->count();
        }

        // Check Stock Opname
        if (\Schema::hasTable('doc_stock_opname')) {
            $count += \DB::table('doc_stock_opname')
                ->where('created_by', $userId)
                ->orWhere('approved_by', $userId)
                ->count();
        }

        // Check HPP Corrections
        if (\Schema::hasTable('doc_hpp_correction')) {
            $count += \DB::table('doc_hpp_correction')
                ->where('created_by', $userId)
                ->orWhere('approved_by', $userId)
                ->count();
        }

        // Check Stock Cards
        if (\Schema::hasTable('stock_card')) {
            $count += \DB::table('stock_card')
                ->where('created_by', $userId)
                ->count();
        }

        return $count;
    }

    /**
     * Toggle user status (active/inactive).
     */
    public function toggleStatus(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('user.update')) {
            return $this->error('Unauthorized', 403);
        }

        $user = User::where('ulid', $ulid)->first();

        if (!$user) {
            return $this->error('User tidak ditemukan', 404);
        }

        // Prevent toggling protected users
        if ($user->isProtected()) {
            return $this->error('User ini tidak dapat diubah statusnya', 403);
        }

        // Prevent deactivating self
        if ($user->id === auth()->id()) {
            return $this->error('Tidak dapat mengubah status akun sendiri', 400);
        }

        // Prevent deactivating last super-admin
        if ($user->hasRole('super-admin') && $user->status === 'active') {
            $activeSuperAdminCount = User::role('super-admin')->where('status', 'active')->count();
            if ($activeSuperAdminCount <= 1) {
                return $this->error('Tidak dapat menonaktifkan Super Admin terakhir yang aktif', 400);
            }
        }

        $isBeingDeactivated = $user->status === 'active';
        $user->status = $user->status === 'active' ? 'inactive' : 'active';
        $user->save();

        // If user is being deactivated, revoke all their tokens (kick them out)
        if ($isBeingDeactivated) {
            $user->tokens()->delete();
        }

        $user->load('roles');

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
        $query = User::visible()
            ->where('status', 'active')
            ->select('id', 'ulid', 'name', 'email');

        // Optional filter: only users that have given permission (via role or direct).
        // `include_ids` tetap ditampilkan walau tidak match permission — berguna saat
        // edit form agar user yang sudah ter-assign tidak hilang dari dropdown meski
        // role-nya baru saja kehilangan permission.
        if ($request->filled('permission')) {
            $permission = $request->input('permission');
            $includeIds = array_filter((array) $request->input('include_ids', []));
            $query->where(function ($q) use ($permission, $includeIds) {
                $q->permission($permission);
                if (!empty($includeIds)) {
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
        $roles = Role::select('id', 'name')->orderBy('name')->get();

        return $this->success([
            'roles' => $roles,
        ]);
    }
}
