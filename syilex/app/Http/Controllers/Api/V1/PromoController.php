<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocPromo;
use App\Models\DocPromoDetail;
use App\Models\MasterGrup;
use App\Models\MasterKategori;
use App\Models\MasterProduk;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromoController extends BaseApiController
{
    /**
     * GET /api/v1/promos
     * List promo dengan filter status (computed), periode, search.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('promo.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat promo.');
        }

        $query = DocPromo::with([
            'customerType:id,ulid,kode_tipe,nama_tipe',
            'customerCategory:id,ulid,kode_kategori,nama_kategori',
            'terminal:id,ulid,kode_terminal,nama_terminal',
            'createdBy:id,name',
            'approvedBy:id,name',
        ])->withCount('details');

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_promo', 'like', "%{$search}%")
                    ->orWhere('nama_promo', 'like', "%{$search}%");
            });
        }

        // Filter by computed display status
        if ($request->filled('status')) {
            $query->byDisplayStatus($request->status);
        }

        // Filter by periode
        if ($request->filled('date_from')) {
            $query->where('tanggal_mulai', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('tanggal_selesai')
                    ->orWhere('tanggal_selesai', '<=', $request->date_to);
            });
        }

        // Sort
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $sortableFields = ['kode_promo', 'nama_promo', 'tanggal_mulai', 'tanggal_selesai', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage = $this->getPerPage($request, 15);
        $promos = $query->paginate($perPage);

        // Attach computed display_status
        $promos->getCollection()->transform(function ($p) {
            $p->display_status = $p->getDisplayStatus();
            return $p;
        });

        return $this->success([
            'items' => $promos->items(),
            'pagination' => [
                'current_page' => $promos->currentPage(),
                'last_page' => $promos->lastPage(),
                'per_page' => $promos->perPage(),
                'total' => $promos->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/promos/{ulid}
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('promo.view')) {
            return $this->forbidden();
        }

        $promo = DocPromo::with([
            'customerType:id,ulid,kode_tipe,nama_tipe',
            'customerCategory:id,ulid,kode_kategori,nama_kategori',
            'terminal:id,ulid,kode_terminal,nama_terminal',
            'createdBy:id,name',
            'updatedBy:id,name',
            'approvedBy:id,name',
            'details',
        ])->where('ulid', $ulid)->first();

        if (!$promo) {
            return $this->notFound('Promo tidak ditemukan.');
        }

        // Resolve target names for each detail
        $promo->details->transform(function ($d) {
            $d->target_name = $this->resolveTargetName($d->target_type, $d->target_id);
            return $d;
        });

        $promo->display_status = $promo->getDisplayStatus();

        return $this->success(['promo' => $promo]);
    }

    /**
     * POST /api/v1/promos
     */
    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()->can('promo.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat promo.');
        }

        $validated = $this->validateRequest($request);

        return DB::transaction(function () use ($validated) {
            $nomorDokumen = SettingService::generateDocumentNumber('promo', 'doc_promo', 'kode_promo');

            $promo = DocPromo::create([
                'kode_promo' => $nomorDokumen,
                'nama_promo' => SettingService::formatName($validated['nama_promo']),
                'deskripsi' => $validated['deskripsi'] ?? null,
                'customer_type_id' => $validated['customer_type_id'] ?? null,
                'customer_category_id' => $validated['customer_category_id'] ?? null,
                'terminal_id' => $validated['terminal_id'] ?? null,
                'tanggal_mulai' => $validated['tanggal_mulai'],
                'tanggal_selesai' => $validated['tanggal_selesai'] ?? null,
                'jam_mulai' => $validated['jam_mulai'] ?? null,
                'jam_selesai' => $validated['jam_selesai'] ?? null,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            foreach ($validated['details'] as $detail) {
                $promo->details()->create($detail);
            }

            $promo->load(['details', 'customerType', 'customerCategory', 'terminal']);
            return $this->created(['promo' => $promo], 'Promo berhasil dibuat.');
        });
    }

    /**
     * PUT /api/v1/promos/{ulid}
     * Hanya bisa edit jika status = draft.
     */
    public function update(Request $request, string $ulid): JsonResponse
    {
        if (!auth()->user()->can('promo.update')) {
            return $this->forbidden();
        }

        $promo = DocPromo::where('ulid', $ulid)->first();
        if (!$promo) {
            return $this->notFound('Promo tidak ditemukan.');
        }

        if (!$promo->isDraft()) {
            return $this->error('Promo hanya bisa diedit saat status draft. Batalkan approval terlebih dahulu.', 422);
        }

        $validated = $this->validateRequest($request);

        return DB::transaction(function () use ($promo, $validated) {
            $promo->update([
                'nama_promo' => SettingService::formatName($validated['nama_promo']),
                'deskripsi' => $validated['deskripsi'] ?? null,
                'customer_type_id' => $validated['customer_type_id'] ?? null,
                'customer_category_id' => $validated['customer_category_id'] ?? null,
                'terminal_id' => $validated['terminal_id'] ?? null,
                'tanggal_mulai' => $validated['tanggal_mulai'],
                'tanggal_selesai' => $validated['tanggal_selesai'] ?? null,
                'jam_mulai' => $validated['jam_mulai'] ?? null,
                'jam_selesai' => $validated['jam_selesai'] ?? null,
                'updated_by' => Auth::id(),
            ]);

            // Replace all details
            $promo->details()->delete();
            foreach ($validated['details'] as $detail) {
                $promo->details()->create($detail);
            }

            $promo->load(['details', 'customerType', 'customerCategory', 'terminal']);
            return $this->success(['promo' => $promo], 'Promo berhasil diupdate.');
        });
    }

    /**
     * DELETE /api/v1/promos/{ulid}
     * Hanya bisa delete jika status = draft.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('promo.delete')) {
            return $this->forbidden();
        }

        $promo = DocPromo::where('ulid', $ulid)->first();
        if (!$promo) {
            return $this->notFound('Promo tidak ditemukan.');
        }

        if (!$promo->isDraft()) {
            return $this->error('Promo hanya bisa dihapus saat status draft.', 422);
        }

        $promo->delete();
        return $this->success(null, 'Promo berhasil dihapus.');
    }

    /**
     * POST /api/v1/promos/{ulid}/approve
     * Transisi: draft → approved
     */
    public function approve(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('promo.approve')) {
            return $this->forbidden();
        }

        $promo = DocPromo::with('details')->where('ulid', $ulid)->first();
        if (!$promo) {
            return $this->notFound('Promo tidak ditemukan.');
        }

        if (!$promo->isDraft()) {
            return $this->error('Hanya promo status draft yang bisa di-approve.', 422);
        }

        // Validate: minimal 1 detail dengan diskon
        if ($promo->details->count() === 0) {
            return $this->error('Promo harus memiliki minimal 1 baris detail.', 422);
        }

        $hasDiscount = $promo->details->contains(function ($d) {
            for ($i = 1; $i <= 4; $i++) {
                if ($d->{"diskon_{$i}_tipe"} !== 'none' && $d->{"diskon_{$i}_nilai"} > 0) {
                    return true;
                }
            }
            return false;
        });

        if (!$hasDiscount) {
            return $this->error('Minimal 1 detail baris harus memiliki diskon (tidak semua none/0).', 422);
        }

        $promo->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        $promo->load(['details', 'customerType', 'customerCategory', 'terminal']);
        return $this->success(['promo' => $promo], 'Promo berhasil di-approve.');
    }

    /**
     * POST /api/v1/promos/{ulid}/cancel
     * Transisi: approved → draft (batalkan approval)
     */
    public function cancel(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('promo.approve')) {
            return $this->forbidden();
        }

        $promo = DocPromo::where('ulid', $ulid)->first();
        if (!$promo) {
            return $this->notFound('Promo tidak ditemukan.');
        }

        if (!$promo->isApproved()) {
            return $this->error('Hanya promo status approved yang bisa dibatalkan.', 422);
        }

        $promo->update([
            'status' => 'draft',
            'approved_at' => null,
            'approved_by' => null,
            'updated_by' => Auth::id(),
        ]);

        $promo->load(['details', 'customerType', 'customerCategory', 'terminal']);
        return $this->success(['promo' => $promo], 'Approval promo berhasil dibatalkan, kembali ke draft.');
    }

    /**
     * POST /api/v1/promos/{ulid}/deactivate
     * Transisi: approved → inactive (matikan manual)
     */
    public function deactivate(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('promo.toggle')) {
            return $this->forbidden();
        }

        $promo = DocPromo::where('ulid', $ulid)->first();
        if (!$promo) {
            return $this->notFound('Promo tidak ditemukan.');
        }

        if (!$promo->isApproved()) {
            return $this->error('Hanya promo status approved/active yang bisa dinonaktifkan.', 422);
        }

        $promo->update([
            'status' => 'inactive',
            'updated_by' => Auth::id(),
        ]);

        $promo->load(['details', 'customerType', 'customerCategory', 'terminal']);
        return $this->success(['promo' => $promo], 'Promo berhasil dinonaktifkan.');
    }

    /**
     * POST /api/v1/promos/{ulid}/reactivate
     * Transisi: inactive → approved
     */
    public function reactivate(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('promo.toggle')) {
            return $this->forbidden();
        }

        $promo = DocPromo::where('ulid', $ulid)->first();
        if (!$promo) {
            return $this->notFound('Promo tidak ditemukan.');
        }

        if (!$promo->isInactive()) {
            return $this->error('Hanya promo status inactive yang bisa diaktifkan kembali.', 422);
        }

        $promo->update([
            'status' => 'approved',
            'updated_by' => Auth::id(),
        ]);

        $promo->load(['details', 'customerType', 'customerCategory', 'terminal']);
        return $this->success(['promo' => $promo], 'Promo berhasil diaktifkan kembali.');
    }

    // ==================== HELPERS ====================

    /**
     * Validasi request untuk store/update.
     */
    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'nama_promo' => 'required|string|max:100',
            'deskripsi' => 'nullable|string',
            'customer_type_id' => 'nullable|exists:master_tipe_customer,id',
            'customer_category_id' => 'nullable|exists:master_kategori_customer,id',
            'terminal_id' => 'nullable|exists:master_pos_terminal,id',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'nullable|date|after_or_equal:tanggal_mulai',
            'jam_mulai' => 'nullable|date_format:H:i',
            'jam_selesai' => 'nullable|date_format:H:i|required_with:jam_mulai|after:jam_mulai',

            'details' => 'required|array|min:1',
            'details.*.target_type' => ['required', Rule::in(['semua', 'produk', 'grup', 'kategori'])],
            'details.*.target_id' => 'nullable|integer',
            'details.*.min_qty' => 'required|integer|min:1',
            'details.*.diskon_1_tipe' => ['required', Rule::in(['percent', 'nominal', 'none'])],
            'details.*.diskon_1_nilai' => 'required|numeric|min:0',
            'details.*.diskon_2_tipe' => ['required', Rule::in(['percent', 'nominal', 'none'])],
            'details.*.diskon_2_nilai' => 'required|numeric|min:0',
            'details.*.diskon_3_tipe' => ['required', Rule::in(['percent', 'nominal', 'none'])],
            'details.*.diskon_3_nilai' => 'required|numeric|min:0',
            'details.*.diskon_4_tipe' => ['required', Rule::in(['percent', 'nominal', 'none'])],
            'details.*.diskon_4_nilai' => 'required|numeric|min:0',
            'details.*.keterangan' => 'nullable|string|max:100',
        ], [
            'details.required' => 'Minimal 1 detail baris diperlukan.',
            'details.min' => 'Minimal 1 detail baris diperlukan.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai harus setelah atau sama dengan tanggal mulai.',
            'jam_selesai.after' => 'Jam selesai harus setelah jam mulai.',
        ]);
    }

    /**
     * Resolve nama target untuk display (dari target_type + target_id).
     */
    private function resolveTargetName(string $targetType, ?int $targetId): ?string
    {
        if ($targetType === 'semua' || $targetId === null) {
            return null;
        }

        return match ($targetType) {
            'produk' => MasterProduk::where('id', $targetId)->value('nama_produk'),
            'grup' => MasterGrup::where('id', $targetId)->value('nama_grup'),
            'kategori' => MasterKategori::where('id', $targetId)->value('nama_kategori'),
            default => null,
        };
    }
}
