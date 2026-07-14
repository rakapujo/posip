<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MasterListExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\HandlesSimpleMasterCrud;
use App\Models\MasterBrand;

class BrandController extends BaseApiController
{
    use HandlesSimpleMasterCrud;

    protected function simpleMasterCrudConfig(): array
    {
        return [
            'model' => MasterBrand::class,
            'permission_prefix' => 'brand',
            'resource_key' => 'brand',
            'collection_key' => 'brands',
            'entity_label' => 'Brand',
            'kode_field' => 'kode_brand',
            'nama_field' => 'nama_brand',
            'unique_table' => 'master_brand',
            'search_fields' => ['kode_brand', 'nama_brand'],
            'sortable_fields' => ['kode_brand', 'nama_brand', 'status', 'created_at'],
            'export_filename_prefix' => 'master_brand',
            'export_factory' => fn ($request) => MasterListExport::brands(
                $request->input('search'),
                $request->input('status'),
            ),
            'list_select' => ['id', 'ulid', 'kode_brand', 'nama_brand'],
            'list_order_field' => 'nama_brand',
            'messages' => [
                'created' => 'Brand berhasil dibuat',
                'updated' => 'Brand berhasil diupdate',
                'activated' => 'Brand berhasil diaktifkan',
                'deactivated' => 'Brand berhasil dinonaktifkan',
                'deleted' => 'Brand berhasil dihapus permanen',
                'not_found' => 'Brand tidak ditemukan',
            ],
            'can_delete' => function (MasterBrand $brand) {
                $productCount = $brand->products()->count();
                if ($productCount > 0) {
                    return $this->error("Tidak dapat menghapus Brand karena masih digunakan oleh {$productCount} produk", 422);
                }

                return null;
            },
        ];
    }
}
