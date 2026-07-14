<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends BaseApiController
{
    /**
     * Display a listing of roles.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('role.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = Role::select('roles.*')
            ->selectSub(
                DB::table('model_has_roles')
                    ->join('users', 'model_has_roles.model_id', '=', 'users.id')
                    ->selectRaw('count(*)')
                    ->whereColumn('model_has_roles.role_id', 'roles.id')
                    ->whereNull('users.deleted_at'),
                'users_count'
            );

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        // Sort (whitelist allowed columns)
        $allowedSorts = ['name', 'created_at', 'updated_at'];
        $sortField = in_array($request->input('sort_field'), $allowedSorts)
            ? $request->input('sort_field')
            : 'name';
        $sortOrder = $request->input('sort_order') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = $this->getPerPage($request);
        $roles = $query->paginate($perPage);

        return $this->success([
            'roles' => $roles->items(),
            'pagination' => [
                'current_page' => $roles->currentPage(),
                'last_page' => $roles->lastPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
            ],
        ]);
    }

    /**
     * Show a single role with its permissions.
     */
    public function show(int $id): JsonResponse
    {
        if (!auth()->user()->can('role.view')) {
            return $this->error('Unauthorized', 403);
        }

        $role = Role::with('permissions:id,name')
            ->select('roles.*')
            ->selectSub(
                DB::table('model_has_roles')
                    ->join('users', 'model_has_roles.model_id', '=', 'users.id')
                    ->selectRaw('count(*)')
                    ->whereColumn('model_has_roles.role_id', 'roles.id')
                    ->whereNull('users.deleted_at'),
                'users_count'
            )
            ->find($id);

        if (!$role) {
            return $this->notFound('Role tidak ditemukan');
        }

        return $this->success([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => $role->users_count,
                'permissions' => $role->permissions->pluck('name')->toArray(),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()->can('role.create')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name', 'regex:/^[a-z0-9-]+$/'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ], [
            'name.regex' => 'Nama role hanya boleh huruf kecil, angka, dan tanda hubung (-)',
            'name.unique' => 'Nama role sudah digunakan',
            'permissions.required' => 'Pilih minimal 1 permission',
            'permissions.min' => 'Pilih minimal 1 permission',
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($validated['permissions']);

        return $this->created([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
            ],
        ], 'Role berhasil dibuat');
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!auth()->user()->can('role.update')) {
            return $this->error('Unauthorized', 403);
        }

        $role = Role::find($id);

        if (!$role) {
            return $this->notFound('Role tidak ditemukan');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($role->id), 'regex:/^[a-z0-9-]+$/'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ], [
            'name.regex' => 'Nama role hanya boleh huruf kecil, angka, dan tanda hubung (-)',
            'name.unique' => 'Nama role sudah digunakan',
            'permissions.required' => 'Pilih minimal 1 permission',
            'permissions.min' => 'Pilih minimal 1 permission',
        ]);

        $role->update(['name' => $validated['name']]);

        // Super-admin override: always sync ALL permissions
        if ($role->name === 'super-admin') {
            $role->syncPermissions(Permission::pluck('name')->toArray());
        } else {
            $role->syncPermissions($validated['permissions']);
        }

        return $this->success([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
            ],
        ], 'Role berhasil diperbarui');
    }

    /**
     * Remove the specified role.
     */
    public function destroy(int $id): JsonResponse
    {
        if (!auth()->user()->can('role.delete')) {
            return $this->error('Unauthorized', 403);
        }

        $role = Role::find($id);

        if (!$role) {
            return $this->notFound('Role tidak ditemukan');
        }

        // Guard: super-admin cannot be deleted
        if ($role->name === 'super-admin') {
            return $this->error('Role super-admin tidak dapat dihapus', 422);
        }

        // Guard: role with users cannot be deleted (exclude soft-deleted users)
        $usersCount = DB::table('model_has_roles')
            ->join('users', 'model_has_roles.model_id', '=', 'users.id')
            ->where('model_has_roles.role_id', $id)
            ->whereNull('users.deleted_at')
            ->count();
        if ($usersCount > 0) {
            return $this->error(
                "Tidak dapat menghapus karena masih digunakan oleh {$usersCount} user",
                422
            );
        }

        $role->delete();

        return $this->success(null, 'Role berhasil dihapus');
    }

    /**
     * Get all permissions grouped for matrix UI.
     */
    public function permissions(): JsonResponse
    {
        if (!auth()->user()->can('role.update')) {
            return $this->error('Unauthorized', 403);
        }

        $allPermissions = Permission::pluck('name')->sort()->values()->toArray();

        $groupDefinitions = [
            [
                'label' => 'Sistem & Settings',
                'modules' => [
                    ['label' => 'User', 'prefix' => 'user'],
                    ['label' => 'Role', 'prefix' => 'role'],
                    ['label' => 'Settings', 'prefix' => 'settings'],
                ],
            ],
            [
                'label' => 'Master Data',
                'modules' => [
                    ['label' => 'Warehouse', 'prefix' => 'warehouse'],
                    ['label' => 'Brand', 'prefix' => 'brand'],
                    ['label' => 'Tipe Produk', 'prefix' => 'tipe'],
                    ['label' => 'Kategori Produk', 'prefix' => 'kategori'],
                    ['label' => 'Grup Produk', 'prefix' => 'grup'],
                    ['label' => 'Supplier', 'prefix' => 'supplier'],
                    ['label' => 'Tipe Customer', 'prefix' => 'tipe-customer'],
                    ['label' => 'Kategori Customer', 'prefix' => 'kategori-customer'],
                    ['label' => 'Customer', 'prefix' => 'customer'],
                    ['label' => 'Diskon Customer', 'prefix' => 'customer-discount'],
                    ['label' => 'Metode Bayar', 'prefix' => 'metode-bayar'],
                    ['label' => 'Produk', 'prefix' => 'produk'],
                    ['label' => 'Perubahan Data Serial', 'prefix' => 'serial-change'],
                    ['label' => 'Import', 'prefix' => 'import'],
                ],
            ],
            [
                'label' => 'Inventory',
                'modules' => [
                    ['label' => 'Stok', 'prefix' => 'stok'],
                    ['label' => 'Adjustment', 'prefix' => 'adjustment'],
                    ['label' => 'Transfer', 'prefix' => 'transfer'],
                    ['label' => 'Repack', 'prefix' => 'repack'],
                    ['label' => 'Stock Opname', 'prefix' => 'opname'],
                    ['label' => 'HPP Correction', 'prefix' => 'hpp'],
                    ['label' => 'Koreksi HPP Serial', 'prefix' => 'serial-hpp'],
                ],
            ],
            [
                'label' => 'Pembelian',
                'modules' => [
                    ['label' => 'Purchase Order', 'prefix' => 'po'],
                    ['label' => 'Purchase Order Serial', 'prefix' => 'serial-intake'],
                    ['label' => 'Hutang', 'prefix' => 'hutang'],
                    ['label' => 'Retur Pembelian', 'prefix' => 'retur-beli'],
                    ['label' => 'Deposit Supplier', 'prefix' => 'deposit-supplier'],
                    ['label' => 'Pembayaran Hutang', 'prefix' => 'pembayaran-hutang'],
                ],
            ],
            [
                'label' => 'Perubahan Harga',
                'modules' => [
                    ['label' => 'Perubahan Harga', 'prefix' => 'price-change'],
                ],
            ],
            [
                'label' => 'Promo & Diskon',
                'modules' => [
                    ['label' => 'Promo', 'prefix' => 'promo'],
                ],
            ],
            [
                'label' => 'POS',
                'modules' => [
                    ['label' => 'Terminal', 'prefix' => 'terminal'],
                    ['label' => 'POS Kasir', 'prefix' => 'pos'],
                ],
            ],
            [
                'label' => 'Laporan',
                'modules' => [
                    ['label' => 'Laporan', 'prefix' => 'laporan'],
                ],
            ],
        ];

        $groups = [];

        foreach ($groupDefinitions as $group) {
            $modules = [];

            foreach ($group['modules'] as $module) {
                $permissions = $this->mapPermissions($allPermissions, $module['prefix']);

                if (!empty($permissions)) {
                    $modules[] = [
                        'label' => $module['label'],
                        'prefix' => $module['prefix'],
                        'permissions' => $permissions,
                    ];
                }
            }

            if (!empty($modules)) {
                $groups[] = [
                    'label' => $group['label'],
                    'modules' => $modules,
                ];
            }
        }

        return $this->success([
            'groups' => $groups,
            'all_permissions' => $allPermissions,
        ]);
    }

    /**
     * Map permissions for a given prefix.
     * E.g., prefix "brand" with permissions ["brand.view", "brand.create"]
     * returns {"view": "brand.view", "create": "brand.create"}
     */
    private function mapPermissions(array $allPermissions, string $prefix): array
    {
        $mapped = [];

        foreach ($allPermissions as $permission) {
            // Match permissions that start with the prefix followed by a dot
            if (str_starts_with($permission, $prefix . '.')) {
                $action = substr($permission, strlen($prefix) + 1);
                $mapped[$action] = $permission;
            }
        }

        return $mapped;
    }
}
