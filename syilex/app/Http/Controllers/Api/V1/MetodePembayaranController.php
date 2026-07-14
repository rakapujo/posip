<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MetodePembayaransExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\MasterMetodePembayaran;
use App\Services\MetodePembayaranRules;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MetodePembayaranController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('metode-bayar.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = MasterMetodePembayaran::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_pembayaran', 'like', "%{$search}%")
                    ->orWhere('nama_pembayaran', 'like', "%{$search}%")
                    ->orWhere('nama_akun', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('metode')) {
            $query->where('metode', $request->metode);
        }

        if ($request->filled('jenis')) {
            $query->where('jenis', $request->jenis);
        }

        $sortableFields = ['kode_pembayaran', 'nama_pembayaran', 'metode', 'jenis', 'status', 'created_at'];
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortField, $sortableFields, true)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        $paginated = $query->paginate($this->getPerPage($request));

        return $this->success([
            'metode_pembayarans' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! auth()->user()->can('metode-bayar.create')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate(
            MetodePembayaranRules::storeRules($request),
            MetodePembayaranRules::messages(),
        );

        $validated['kode_pembayaran'] = SettingService::formatCode($validated['kode_pembayaran']);
        $validated['nama_pembayaran'] = SettingService::formatName($validated['nama_pembayaran']);
        $validated = MetodePembayaranRules::normalize($validated);

        $metodePembayaran = MasterMetodePembayaran::create($validated);

        return $this->success([
            'metode_pembayaran' => $metodePembayaran,
        ], 'Metode pembayaran berhasil dibuat', 201);
    }

    public function show(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('metode-bayar.view')) {
            return $this->error('Unauthorized', 403);
        }

        $metodePembayaran = MasterMetodePembayaran::with([
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (! $metodePembayaran) {
            return $this->error('Metode pembayaran tidak ditemukan', 404);
        }

        return $this->success([
            'metode_pembayaran' => $metodePembayaran,
        ]);
    }

    public function update(Request $request, string $ulid): JsonResponse
    {
        if (! auth()->user()->can('metode-bayar.update')) {
            return $this->error('Unauthorized', 403);
        }

        $metodePembayaran = MasterMetodePembayaran::where('ulid', $ulid)->first();

        if (! $metodePembayaran) {
            return $this->error('Metode pembayaran tidak ditemukan', 404);
        }

        $validated = $request->validate(MetodePembayaranRules::updateRules($request));
        $validated['nama_pembayaran'] = SettingService::formatName($validated['nama_pembayaran']);
        $validated = MetodePembayaranRules::normalize($validated);

        if ($metodePembayaran->status === 'active' && $validated['status'] === 'inactive') {
            $message = MetodePembayaranRules::deactivationBlockMessage($metodePembayaran);
            if ($message) {
                return $this->error($message, 422);
            }
        }

        $metodePembayaran->update($validated);

        return $this->success([
            'metode_pembayaran' => $metodePembayaran,
        ], 'Metode pembayaran berhasil diupdate');
    }

    public function toggleStatus(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('metode-bayar.update')) {
            return $this->error('Unauthorized', 403);
        }

        $metodePembayaran = MasterMetodePembayaran::where('ulid', $ulid)->first();

        if (! $metodePembayaran) {
            return $this->error('Metode pembayaran tidak ditemukan', 404);
        }

        if ($metodePembayaran->status === 'active') {
            $message = MetodePembayaranRules::deactivationBlockMessage($metodePembayaran);
            if ($message) {
                return $this->error($message, 422);
            }
        }

        $newStatus = $metodePembayaran->status === 'active' ? 'inactive' : 'active';
        $metodePembayaran->update(['status' => $newStatus]);

        $message = $newStatus === 'active'
            ? 'Metode pembayaran berhasil diaktifkan'
            : 'Metode pembayaran berhasil dinonaktifkan';

        return $this->success(['metode_pembayaran' => $metodePembayaran], $message);
    }

    public function destroy(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('metode-bayar.delete')) {
            return $this->error('Unauthorized', 403);
        }

        $metodePembayaran = MasterMetodePembayaran::where('ulid', $ulid)->first();

        if (! $metodePembayaran) {
            return $this->error('Metode pembayaran tidak ditemukan', 404);
        }

        $message = MetodePembayaranRules::deletionBlockMessage($metodePembayaran);
        if ($message) {
            return $this->error($message, 422);
        }

        $metodePembayaran->delete();

        return $this->success(null, 'Metode pembayaran berhasil dihapus permanen');
    }

    public function export(Request $request)
    {
        if (! auth()->user()->can('metode-bayar.view')) {
            return $this->error('Unauthorized', 403);
        }

        $filename = 'master_metode_pembayaran_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(
            new MetodePembayaransExport(
                $request->input('search'),
                $request->input('status'),
                $request->input('metode'),
            ),
            $filename,
        );
    }

    public function list(Request $request): JsonResponse
    {
        $query = MasterMetodePembayaran::active()
            ->select('id', 'ulid', 'kode_pembayaran', 'nama_pembayaran', 'metode', 'jenis', 'biaya_tambahan_tipe', 'biaya_tambahan_nilai')
            ->orderBy('nama_pembayaran');

        if ($request->filled('metode')) {
            $query->where('metode', $request->metode);
        }

        $metodePembayarans = $query->get()->makeVisible('id');

        return $this->success([
            'metode_pembayarans' => $metodePembayarans,
        ]);
    }
}
