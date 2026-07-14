<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MasterListExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\HandlesSimpleMasterCrud;
use App\Models\MasterGrup;
use App\Models\MasterKategori;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class GrupController extends BaseApiController
{
    use HandlesSimpleMasterCrud;

    protected function simpleMasterCrudConfig(): array
    {
        return [
            'model' => MasterGrup::class,
            'permission_prefix' => 'grup',
            'resource_key' => 'grup',
            'collection_key' => 'grups',
            'entity_label' => 'Grup Produk',
            'kode_field' => 'kode_grup',
            'nama_field' => 'nama_grup',
            'unique_table' => 'master_grup',
            'search_fields' => ['kode_grup', 'nama_grup'],
            'sortable_fields' => ['kode_grup', 'nama_grup', 'status', 'created_at'],
            'export_filename_prefix' => 'master_grup',
            'export_factory' => function (Request $request) {
                $kategoriId = null;
                if ($request->filled('kategori_ulid')) {
                    $kategori = MasterKategori::where('ulid', $request->kategori_ulid)->first();
                    if ($kategori) {
                        $kategoriId = $kategori->id;
                    }
                }

                return MasterListExport::grups(
                    $request->input('search'),
                    $request->input('status'),
                    $kategoriId,
                );
            },
            'list_select' => ['id', 'ulid', 'kategori_id', 'kode_grup', 'nama_grup'],
            'list_order_field' => 'nama_grup',
            'index_with' => [
                'kategori:id,ulid,kode_kategori,nama_kategori,tipe_id',
                'kategori.tipe:id,ulid,kode_tipe,nama_tipe',
            ],
            'show_relations' => [
                'kategori:id,ulid,kode_kategori,nama_kategori,tipe_id',
                'kategori.tipe:id,ulid,kode_tipe,nama_tipe',
            ],
            'messages' => [
                'created' => 'Grup Produk berhasil dibuat',
                'updated' => 'Grup Produk berhasil diupdate',
                'activated' => 'Grup Produk berhasil diaktifkan',
                'deactivated' => 'Grup Produk berhasil dinonaktifkan',
                'deleted' => 'Grup Produk berhasil dihapus permanen',
                'not_found' => 'Grup Produk tidak ditemukan',
            ],
            'extra_store_rules' => [
                'kategori_ulid' => 'required|string|exists:master_kategori,ulid',
            ],
            'extra_update_rules' => [
                'kategori_ulid' => 'required|string|exists:master_kategori,ulid',
            ],
            'before_store' => function (Request $request, array $validated) {
                $kategori = MasterKategori::where('ulid', $validated['kategori_ulid'])->first();
                if (! $kategori->isActive()) {
                    return $this->error('Kategori Produk tidak aktif', 422);
                }

                return null;
            },
            'before_update' => function (Request $request, $grup, array $validated) {
                $kategori = MasterKategori::where('ulid', $validated['kategori_ulid'])->first();
                if ($kategori->id !== $grup->kategori_id && ! $kategori->isActive()) {
                    return $this->error('Kategori Produk tidak aktif', 422);
                }

                if ($grup->status === 'active' && $validated['status'] === 'inactive' && $grup->products()->exists()) {
                    return $this->error('Tidak dapat menonaktifkan Grup Produk karena masih digunakan oleh produk', 422);
                }

                return null;
            },
            'mutate_store' => fn (array $validated) => $this->mapGrupPayload($validated),
            'mutate_update' => fn (array $validated) => $this->mapGrupPayload($validated),
            'apply_index_filters' => function (Builder $query, Request $request) {
                $this->applyDefaultIndexFilters($query, $request, $this->simpleMasterCrudConfig());

                if ($request->filled('kategori_ulid')) {
                    $kategori = MasterKategori::where('ulid', $request->kategori_ulid)->first();
                    if ($kategori) {
                        $query->where('kategori_id', $kategori->id);
                    }
                }
            },
            'apply_sort' => function (Builder $query, Request $request) {
                $sortField = $request->input('sort_field', 'created_at');
                $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
                $sortable = ['kode_grup', 'nama_grup', 'status', 'created_at'];

                if ($sortField === 'kategori_nama') {
                    $query->join('master_kategori', 'master_grup.kategori_id', '=', 'master_kategori.id')
                        ->orderBy('master_kategori.nama_kategori', $sortOrder)
                        ->select('master_grup.*');
                } elseif ($sortField === 'tipe_nama') {
                    $query->join('master_kategori', 'master_grup.kategori_id', '=', 'master_kategori.id')
                        ->join('master_tipe', 'master_kategori.tipe_id', '=', 'master_tipe.id')
                        ->orderBy('master_tipe.nama_tipe', $sortOrder)
                        ->select('master_grup.*');
                } elseif (in_array($sortField, $sortable, true)) {
                    $query->orderBy($sortField, $sortOrder);
                } else {
                    $query->orderBy('created_at', $sortOrder);
                }
            },
            'apply_list_filters' => function (Builder $query, Request $request) {
                $query->with([
                    'kategori:id,kode_kategori,nama_kategori,tipe_id',
                    'kategori.tipe:id,kode_tipe,nama_tipe',
                ]);

                if ($request->filled('kategori_ulid')) {
                    $kategori = MasterKategori::where('ulid', $request->kategori_ulid)->first();
                    if ($kategori) {
                        $query->where('kategori_id', $kategori->id);
                    }
                }
            },
            'after_store' => fn (MasterGrup $grup) => $this->loadGrupRelations($grup),
            'after_update' => fn (MasterGrup $grup) => $this->loadGrupRelations($grup),
            'after_toggle' => fn (MasterGrup $grup) => $this->loadGrupRelations($grup),
            'before_toggle' => function (MasterGrup $grup) {
                if ($grup->status === 'active' && $grup->products()->exists()) {
                    return $this->error('Tidak dapat menonaktifkan Grup Produk karena masih digunakan oleh produk', 422);
                }

                return null;
            },
            'can_delete' => function (MasterGrup $grup) {
                $productCount = $grup->products()->count();
                if ($productCount > 0) {
                    return $this->error("Tidak dapat menghapus Grup Produk karena masih digunakan oleh {$productCount} produk", 422);
                }

                return null;
            },
        ];
    }

    private function mapGrupPayload(array $validated): array
    {
        $kategori = MasterKategori::where('ulid', $validated['kategori_ulid'])->first();

        $payload = [
            'kategori_id' => $kategori->id,
            'nama_grup' => $validated['nama_grup'],
            'status' => $validated['status'],
        ];

        if (isset($validated['kode_grup'])) {
            $payload['kode_grup'] = $validated['kode_grup'];
        }

        return $payload;
    }

    private function loadGrupRelations(MasterGrup $grup): void
    {
        $grup->load([
            'kategori:id,kode_kategori,nama_kategori,tipe_id',
            'kategori.tipe:id,kode_tipe,nama_tipe',
        ]);
    }
}
