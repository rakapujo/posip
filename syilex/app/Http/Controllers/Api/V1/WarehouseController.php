<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MasterListExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\HandlesSimpleMasterCrud;
use App\Models\MasterWarehouse;
use App\Services\SettingService;
use App\Services\WarehouseRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends BaseApiController
{
    use HandlesSimpleMasterCrud;

    protected function simpleMasterCrudConfig(): array
    {
        return [
            'model' => MasterWarehouse::class,
            'permission_prefix' => 'warehouse',
            'resource_key' => 'warehouse',
            'collection_key' => 'warehouses',
            'entity_label' => 'Warehouse',
            'kode_field' => 'kode_warehouse',
            'nama_field' => 'nama_warehouse',
            'unique_table' => 'master_warehouse',
            'search_fields' => ['kode_warehouse', 'nama_warehouse', 'alamat', 'pic_name'],
            'sortable_fields' => ['kode_warehouse', 'nama_warehouse', 'status', 'created_at'],
            'export_filename_prefix' => 'master_warehouse',
            'export_factory' => fn (Request $request) => MasterListExport::warehouses(
                $request->input('search'),
                $request->input('status'),
                $request->input('is_saleable'),
            ),
            'list_select' => ['id', 'ulid', 'kode_warehouse', 'nama_warehouse', 'is_saleable'],
            'list_order_field' => 'nama_warehouse',
            'messages' => [
                'created' => 'Warehouse berhasil dibuat',
                'updated' => 'Warehouse berhasil diupdate',
                'activated' => 'Warehouse berhasil diaktifkan',
                'deactivated' => 'Warehouse berhasil dinonaktifkan',
                'deleted' => 'Warehouse berhasil dihapus permanen',
                'not_found' => 'Warehouse tidak ditemukan',
            ],
            'extra_store_rules' => [
                'alamat' => 'nullable|string',
                'pic_name' => 'nullable|string|max:100',
                'pic_phone' => 'nullable|string|max:20',
                'is_saleable' => 'required|boolean',
            ],
            'extra_update_rules' => [
                'alamat' => 'nullable|string',
                'pic_name' => 'nullable|string|max:100',
                'pic_phone' => 'nullable|string|max:20',
                'is_saleable' => 'required|boolean',
            ],
            'mutate_store' => fn (array $validated) => $this->formatWarehouseNames($validated),
            'mutate_update' => fn (array $validated) => $this->formatWarehouseNames($validated),
            'apply_index_filters' => function (Builder $query, Request $request) {
                $this->applyDefaultIndexFilters($query, $request, $this->simpleMasterCrudConfig());

                if ($request->filled('is_saleable')) {
                    $query->where('is_saleable', $request->boolean('is_saleable'));
                }
            },
            'apply_list_filters' => function (Builder $query, Request $request) {
                if ($request->filled('is_saleable')) {
                    $query->where('is_saleable', $request->boolean('is_saleable'));
                }
            },
            'before_update' => function (Request $request, MasterWarehouse $warehouse, array $validated) {
                if ($warehouse->status === 'active' && $validated['status'] === 'inactive') {
                    return $this->warehouseDeactivationBlockResponse($warehouse);
                }

                return null;
            },
            'before_toggle' => function (MasterWarehouse $warehouse) {
                if ($warehouse->status === 'active') {
                    return $this->warehouseDeactivationBlockResponse($warehouse);
                }

                return null;
            },
            'after_show' => fn (MasterWarehouse $warehouse) => $warehouse->makeVisible('id'),
            'can_delete' => function (MasterWarehouse $warehouse) {
                $terminalCount = $warehouse->posTerminals()->count();
                if ($terminalCount > 0) {
                    return $this->error("Tidak dapat menghapus Gudang karena masih digunakan oleh {$terminalCount} terminal POS", 422);
                }

                if ($warehouse->inventoryStocks()->where('qty', '!=', 0)->exists()) {
                    return $this->error('Tidak dapat menghapus Gudang karena masih memiliki stok. Pastikan stok = 0 untuk semua produk di gudang ini.', 422);
                }

                $stockCardCount = $warehouse->stockCards()->count();
                if ($stockCardCount > 0) {
                    return $this->error("Tidak dapat menghapus Gudang karena sudah memiliki {$stockCardCount} riwayat kartu stok", 422);
                }

                return null;
            },
        ];
    }

    private function formatWarehouseNames(array $validated): array
    {
        if (! empty($validated['pic_name'])) {
            $validated['pic_name'] = SettingService::formatName($validated['pic_name']);
        }

        return $validated;
    }

    private function warehouseDeactivationBlockResponse(MasterWarehouse $warehouse): JsonResponse
    {
        $message = WarehouseRules::deactivationBlockMessage($warehouse);
        if ($message) {
            return $this->error($message, 422);
        }

        return null;
    }
}
