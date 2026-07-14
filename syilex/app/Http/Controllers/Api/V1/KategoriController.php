<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MasterListExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\HandlesSimpleMasterCrud;
use App\Models\MasterKategori;
use App\Models\MasterTipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class KategoriController extends BaseApiController
{
    use HandlesSimpleMasterCrud;

    protected function simpleMasterCrudConfig(): array
    {
        return [
            'model' => MasterKategori::class,
            'permission_prefix' => 'kategori',
            'resource_key' => 'kategori',
            'collection_key' => 'kategoris',
            'entity_label' => 'Kategori Produk',
            'kode_field' => 'kode_kategori',
            'nama_field' => 'nama_kategori',
            'unique_table' => 'master_kategori',
            'search_fields' => ['kode_kategori', 'nama_kategori'],
            'sortable_fields' => ['kode_kategori', 'nama_kategori', 'status', 'created_at'],
            'export_filename_prefix' => 'master_kategori',
            'export_factory' => function (Request $request) {
                $tipeId = null;
                if ($request->filled('tipe_ulid')) {
                    $tipe = MasterTipe::where('ulid', $request->tipe_ulid)->first();
                    if ($tipe) {
                        $tipeId = $tipe->id;
                    }
                }

                return MasterListExport::kategoris(
                    $request->input('search'),
                    $request->input('status'),
                    $tipeId,
                );
            },
            'list_select' => ['id', 'ulid', 'tipe_id', 'kode_kategori', 'nama_kategori'],
            'list_order_field' => 'nama_kategori',
            'index_with' => ['tipe:id,ulid,kode_tipe,nama_tipe'],
            'show_relations' => ['tipe:id,ulid,kode_tipe,nama_tipe'],
            'messages' => [
                'created' => 'Kategori Produk berhasil dibuat',
                'updated' => 'Kategori Produk berhasil diupdate',
                'activated' => 'Kategori Produk berhasil diaktifkan',
                'deactivated' => 'Kategori Produk berhasil dinonaktifkan',
                'deleted' => 'Kategori Produk berhasil dihapus permanen',
                'not_found' => 'Kategori Produk tidak ditemukan',
            ],
            'extra_store_rules' => [
                'tipe_ulid' => 'required|string|exists:master_tipe,ulid',
            ],
            'extra_update_rules' => [
                'tipe_ulid' => 'required|string|exists:master_tipe,ulid',
            ],
            'before_store' => function (Request $request, array $validated) {
                $tipe = MasterTipe::where('ulid', $validated['tipe_ulid'])->first();
                if (! $tipe->isActive()) {
                    return $this->error('Tipe Produk tidak aktif', 422);
                }

                return null;
            },
            'before_update' => function (Request $request, $kategori, array $validated) {
                $tipe = MasterTipe::where('ulid', $validated['tipe_ulid'])->first();
                if ($tipe->id !== $kategori->tipe_id && ! $tipe->isActive()) {
                    return $this->error('Tipe Produk tidak aktif', 422);
                }

                if ($kategori->status === 'active' && $validated['status'] === 'inactive' && $kategori->grups()->exists()) {
                    return $this->error('Tidak dapat menonaktifkan Kategori Produk karena masih memiliki Grup', 422);
                }

                return null;
            },
            'mutate_store' => fn (array $validated) => $this->mapKategoriPayload($validated),
            'mutate_update' => fn (array $validated) => $this->mapKategoriPayload($validated),
            'apply_index_filters' => function (Builder $query, Request $request) {
                $this->applyDefaultIndexFilters($query, $request, $this->simpleMasterCrudConfig());

                if ($request->filled('tipe_ulid')) {
                    $tipe = MasterTipe::where('ulid', $request->tipe_ulid)->first();
                    if ($tipe) {
                        $query->where('tipe_id', $tipe->id);
                    }
                }
            },
            'apply_sort' => function (Builder $query, Request $request) {
                $sortField = $request->input('sort_field', 'created_at');
                $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
                $sortable = ['kode_kategori', 'nama_kategori', 'status', 'created_at'];

                if ($sortField === 'tipe_nama') {
                    $query->join('master_tipe', 'master_kategori.tipe_id', '=', 'master_tipe.id')
                        ->orderBy('master_tipe.nama_tipe', $sortOrder)
                        ->select('master_kategori.*');
                } elseif (in_array($sortField, $sortable, true)) {
                    $query->orderBy($sortField, $sortOrder);
                } else {
                    $query->orderBy('created_at', $sortOrder);
                }
            },
            'apply_list_filters' => function (Builder $query, Request $request) {
                $query->with('tipe:id,kode_tipe,nama_tipe');

                if ($request->filled('tipe_ulid')) {
                    $tipe = MasterTipe::where('ulid', $request->tipe_ulid)->first();
                    if ($tipe) {
                        $query->where('tipe_id', $tipe->id);
                    }
                }
            },
            'after_store' => fn (MasterKategori $kategori) => $kategori->load('tipe:id,kode_tipe,nama_tipe'),
            'after_update' => fn (MasterKategori $kategori) => $kategori->load('tipe:id,kode_tipe,nama_tipe'),
            'after_toggle' => fn (MasterKategori $kategori) => $kategori->load('tipe:id,kode_tipe,nama_tipe'),
            'before_toggle' => function (MasterKategori $kategori) {
                if ($kategori->status === 'active' && $kategori->grups()->exists()) {
                    return $this->error('Tidak dapat menonaktifkan Kategori Produk karena masih memiliki Grup', 422);
                }

                return null;
            },
            'can_delete' => function (MasterKategori $kategori) {
                if ($kategori->grups()->exists()) {
                    return $this->error('Tidak dapat menghapus Kategori Produk karena masih memiliki Grup', 422);
                }

                return null;
            },
        ];
    }

    private function mapKategoriPayload(array $validated): array
    {
        $tipe = MasterTipe::where('ulid', $validated['tipe_ulid'])->first();

        $payload = [
            'tipe_id' => $tipe->id,
            'nama_kategori' => $validated['nama_kategori'],
            'status' => $validated['status'],
        ];

        if (isset($validated['kode_kategori'])) {
            $payload['kode_kategori'] = $validated['kode_kategori'];
        }

        return $payload;
    }
}
