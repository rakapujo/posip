<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MasterListExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\HandlesSimpleMasterCrud;
use App\Models\MasterTipeCustomer;

class TipeCustomerController extends BaseApiController
{
    use HandlesSimpleMasterCrud;

    protected function simpleMasterCrudConfig(): array
    {
        return [
            'model' => MasterTipeCustomer::class,
            'permission_prefix' => 'tipe-customer',
            'resource_key' => 'tipe_customer',
            'collection_key' => 'tipe_customers',
            'entity_label' => 'Tipe Customer',
            'kode_field' => 'kode_tipe',
            'nama_field' => 'nama_tipe',
            'unique_table' => 'master_tipe_customer',
            'search_fields' => ['kode_tipe', 'nama_tipe'],
            'sortable_fields' => ['kode_tipe', 'nama_tipe', 'keterangan', 'status', 'created_at'],
            'export_filename_prefix' => 'master_tipe_customer',
            'export_factory' => fn ($request) => MasterListExport::tipeCustomers(
                $request->input('search'),
                $request->input('status'),
            ),
            'list_select' => ['id', 'ulid', 'kode_tipe', 'nama_tipe'],
            'list_order_field' => 'nama_tipe',
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
                'created' => 'Tipe Customer berhasil dibuat',
                'updated' => 'Tipe Customer berhasil diupdate',
                'activated' => 'Tipe Customer berhasil diaktifkan',
                'deactivated' => 'Tipe Customer berhasil dinonaktifkan',
                'deleted' => 'Tipe Customer berhasil dihapus permanen',
                'not_found' => 'Tipe Customer tidak ditemukan',
            ],
            'can_delete' => function (MasterTipeCustomer $tipeCustomer) {
                if ($tipeCustomer->customers()->exists()) {
                    return $this->error('Tidak dapat menghapus Tipe Customer karena masih digunakan oleh Customer', 422);
                }

                return null;
            },
        ];
    }
}
