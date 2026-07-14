<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MasterListExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\HandlesSimpleMasterCrud;
use App\Models\MasterKategoriCustomer;

class KategoriCustomerController extends BaseApiController
{
    use HandlesSimpleMasterCrud;

    protected function simpleMasterCrudConfig(): array
    {
        return [
            'model' => MasterKategoriCustomer::class,
            'permission_prefix' => 'kategori-customer',
            'resource_key' => 'kategori_customer',
            'collection_key' => 'kategori_customers',
            'entity_label' => 'Kategori Customer',
            'kode_field' => 'kode_kategori',
            'nama_field' => 'nama_kategori',
            'unique_table' => 'master_kategori_customer',
            'search_fields' => ['kode_kategori', 'nama_kategori'],
            'sortable_fields' => ['kode_kategori', 'nama_kategori', 'keterangan', 'status', 'created_at'],
            'export_filename_prefix' => 'master_kategori_customer',
            'export_factory' => fn ($request) => MasterListExport::kategoriCustomers(
                $request->input('search'),
                $request->input('status'),
            ),
            'list_select' => ['id', 'ulid', 'kode_kategori', 'nama_kategori'],
            'list_order_field' => 'nama_kategori',
            'has_customer_discount' => true,
            'extra_store_rules' => [
                'diskon_tipe' => 'nullable|in:none,percent,nominal',
                'diskon_nilai' => 'nullable|numeric|min:0',
                'keterangan' => 'nullable|string',
            ],
            'extra_update_rules' => [
                'diskon_tipe' => 'nullable|in:none,percent,nominal',
                'diskon_nilai' => 'nullable|numeric|min:0',
                'keterangan' => 'nullable|string',
            ],
            'messages' => [
                'created' => 'Kategori Customer berhasil dibuat',
                'updated' => 'Kategori Customer berhasil diupdate',
                'activated' => 'Kategori Customer berhasil diaktifkan',
                'deactivated' => 'Kategori Customer berhasil dinonaktifkan',
                'deleted' => 'Kategori Customer berhasil dihapus permanen',
                'not_found' => 'Kategori Customer tidak ditemukan',
            ],
            'can_delete' => function (MasterKategoriCustomer $kategoriCustomer) {
                if ($kategoriCustomer->customers()->exists()) {
                    return $this->error('Tidak dapat menghapus Kategori Customer karena masih digunakan oleh Customer', 422);
                }

                return null;
            },
        ];
    }
}
