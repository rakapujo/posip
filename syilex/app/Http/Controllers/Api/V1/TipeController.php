<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MasterListExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\HandlesSimpleMasterCrud;
use App\Models\MasterTipe;

class TipeController extends BaseApiController
{
    use HandlesSimpleMasterCrud;

    protected function simpleMasterCrudConfig(): array
    {
        return [
            'model' => MasterTipe::class,
            'permission_prefix' => 'tipe',
            'resource_key' => 'tipe',
            'collection_key' => 'tipes',
            'entity_label' => 'Tipe Produk',
            'kode_field' => 'kode_tipe',
            'nama_field' => 'nama_tipe',
            'unique_table' => 'master_tipe',
            'search_fields' => ['kode_tipe', 'nama_tipe'],
            'sortable_fields' => ['kode_tipe', 'nama_tipe', 'status', 'created_at'],
            'export_filename_prefix' => 'master_tipe',
            'export_factory' => fn ($request) => MasterListExport::tipes(
                $request->input('search'),
                $request->input('status'),
            ),
            'list_select' => ['id', 'ulid', 'kode_tipe', 'nama_tipe'],
            'list_order_field' => 'nama_tipe',
            'messages' => [
                'created' => 'Tipe Produk berhasil dibuat',
                'updated' => 'Tipe Produk berhasil diupdate',
                'activated' => 'Tipe Produk berhasil diaktifkan',
                'deactivated' => 'Tipe Produk berhasil dinonaktifkan',
                'deleted' => 'Tipe Produk berhasil dihapus permanen',
                'not_found' => 'Tipe Produk tidak ditemukan',
            ],
            'can_delete' => function (MasterTipe $tipe) {
                if ($tipe->kategoris()->exists()) {
                    return $this->error('Tidak dapat menghapus Tipe Produk karena masih memiliki Kategori', 422);
                }

                return null;
            },
        ];
    }
}
