<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocSales;
use App\Models\DocSalesReturn;
use App\Models\MasterPosTerminal;
use App\Models\PosCashTransaction;
use App\Models\PosTerminalShift;
use App\Models\User;
use App\Services\SettingService;
use App\Services\TerminalMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PosTerminalController extends BaseApiController
{
    /**
     * Display a listing of POS terminals.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('terminal.view')) {
            return $this->error('Unauthorized', 403);
        }

        $query = MasterPosTerminal::with([
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'activeUser:id,ulid,name',
            'activeShift:id,ulid,terminal_id,started_at',
            'users:id,ulid',
        ])
        ->withCount(['users', 'allowedPaymentMethods']);

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Sort (whitelist allowed columns)
        $sortableFields = ['kode_terminal', 'nama_terminal', 'status', 'created_at'];
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        // Paginate
        $perPage = $this->getPerPage($request);
        $terminals = $query->paginate($perPage);

        return $this->success([
            'terminals' => $terminals->items(),
            'pagination' => [
                'current_page' => $terminals->currentPage(),
                'last_page' => $terminals->lastPage(),
                'per_page' => $terminals->perPage(),
                'total' => $terminals->total(),
            ],
        ]);
    }

    /**
     * Store a newly created POS terminal.
     */
    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()->can('terminal.create')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'kode_terminal' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9_]+$/',
                'unique:master_pos_terminal,kode_terminal',
            ],
            'nama_terminal' => 'required|string|max:100',
            'warehouse_id' => [
                'required',
                Rule::exists('master_warehouse', 'id')->where('status', 'active')->where('is_saleable', true),
            ],
            'default_customer_id' => [
                'required',
                Rule::exists('master_customer', 'id')->where('status', 'active')->where('jenis', 'walk_in'),
            ],
            'default_metode_pembayaran_id' => [
                'required',
                Rule::exists('master_metode_pembayaran', 'id')->where('status', 'active')->where('metode', 'tunai'),
            ],
            'default_printer' => 'nullable|string|max:100',
            'mail_driver' => 'nullable|in:none,smtp,resend',
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:255',
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:tls,ssl',
            'smtp_username' => 'nullable|string|max:255',
            'smtp_password' => 'nullable|string|max:1000',
            'resend_api_key' => 'nullable|string|max:1000',
            'auto_open_tray' => 'required|boolean',
            'auto_print_receipt' => 'boolean',
            'auto_print_retur' => 'boolean',
            'auto_print_kas' => 'boolean',
            'auto_print_report' => 'boolean',
            'auto_lock_minutes' => 'nullable|integer|min:1|max:120',
            'paper_width' => 'in:58,80',
            'char_per_line' => 'integer|min:20|max:72',
            'paper_mode' => 'in:normal,compact',
            'print_feed_before_cut' => 'integer|min:0|max:6',
            'izinkan_retur' => 'required|boolean',
            'durasi_retur' => 'nullable|integer|min:0',
            'keterangan' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
            'metode_pembayaran_ids' => 'required|array|min:1',
            'metode_pembayaran_ids.*' => Rule::exists('master_metode_pembayaran', 'id')->where('status', 'active'),
        ], [
            'kode_terminal.regex' => 'Kode hanya boleh berisi huruf, angka, dan underscore (_)',
        ]);

        if ($err = $this->prepareMailSettings($validated)) {
            return $err;
        }

        if ($err = $this->defaultMetodeMustBeAllowed($validated)) {
            return $err;
        }
        // Format kode and nama based on settings
        $validated['kode_terminal'] = SettingService::formatCode($validated['kode_terminal']);
        $validated['nama_terminal'] = SettingService::formatName($validated['nama_terminal']);

        // Format keterangan if provided
        if (!empty($validated['keterangan'])) {
            $validated['keterangan'] = SettingService::formatName($validated['keterangan']);
        }

        // Extract pivot data
        $userIds = $validated['user_ids'] ?? [];
        $metodePembayaranIds = $validated['metode_pembayaran_ids'] ?? [];
        unset($validated['user_ids'], $validated['metode_pembayaran_ids']);

        // Guard: semua user harus punya permission pos.access
        if ($invalid = $this->usersWithoutPosAccess($userIds)) {
            return $this->error(
                'User berikut tidak memiliki akses POS: ' . $invalid->pluck('name')->implode(', '),
                422
            );
        }

        // Create terminal + pivots atomically
        $terminal = DB::transaction(function () use ($validated, $userIds, $metodePembayaranIds) {
            $terminal = MasterPosTerminal::create($validated);
            if (! empty($userIds)) {
                $terminal->users()->attach($userIds);
            }
            if (! empty($metodePembayaranIds)) {
                $terminal->allowedPaymentMethods()->attach($metodePembayaranIds);
            }

            return $terminal;
        });

        return $this->success([
            'terminal' => $terminal,
        ], 'Terminal berhasil dibuat', 201);
    }

    /**
     * Display the specified POS terminal.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('terminal.view')) {
            return $this->error('Unauthorized', 403);
        }

        $terminal = MasterPosTerminal::with([
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'defaultCustomer:id,ulid,kode_customer,nama,jenis,tipe_customer_id,kategori_customer_id',
            'defaultCustomer.tipeCustomer:id,ulid,kode_tipe,nama_tipe,diskon_tipe,diskon_nilai',
            'defaultCustomer.kategoriCustomer:id,ulid,kode_kategori,nama_kategori,diskon_tipe,diskon_nilai',
            'defaultMetodePembayaran:id,ulid,kode_pembayaran,nama_pembayaran',
            'activeUser:id,ulid,name',
            'users:id,ulid,name,email',
            'allowedPaymentMethods:id,ulid,kode_pembayaran,nama_pembayaran',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$terminal) {
            return $this->error('Terminal tidak ditemukan', 404);
        }

        // Make id visible on related models for form mapping
        if ($terminal->users) {
            $terminal->users->each->makeVisible('id');
        }
        if ($terminal->allowedPaymentMethods) {
            $terminal->allowedPaymentMethods->each->makeVisible('id');
        }
        if ($terminal->warehouse) {
            $terminal->warehouse->makeVisible('id');
        }
        if ($terminal->defaultCustomer) {
            $terminal->defaultCustomer->makeVisible('id');
        }
        if ($terminal->defaultMetodePembayaran) {
            $terminal->defaultMetodePembayaran->makeVisible('id');
        }
        $terminal->makeVisible('id');

        return $this->success([
            'terminal' => $terminal,
        ]);
    }

    /**
     * Update the specified POS terminal.
     * Note: kode_terminal cannot be changed after creation.
     */
    public function update(Request $request, string $ulid): JsonResponse
    {
        if (!auth()->user()->can('terminal.edit')) {
            return $this->error('Unauthorized', 403);
        }

        $terminal = MasterPosTerminal::where('ulid', $ulid)->first();

        if (!$terminal) {
            return $this->error('Terminal tidak ditemukan', 404);
        }

        // Block edit if terminal is active
        if ($terminal->isInUse()) {
            return $this->error('Terminal sedang digunakan. Tutup paksa terlebih dahulu.', 422);
        }

        $validated = $request->validate([
            'nama_terminal' => 'required|string|max:100',
            'warehouse_id' => [
                'required',
                Rule::exists('master_warehouse', 'id')->where('status', 'active')->where('is_saleable', true),
            ],
            'default_customer_id' => [
                'required',
                Rule::exists('master_customer', 'id')->where('status', 'active')->where('jenis', 'walk_in'),
            ],
            'default_metode_pembayaran_id' => [
                'required',
                Rule::exists('master_metode_pembayaran', 'id')->where('status', 'active')->where('metode', 'tunai'),
            ],
            'default_printer' => 'nullable|string|max:100',
            'mail_driver' => 'nullable|in:none,smtp,resend',
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:255',
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:tls,ssl',
            'smtp_username' => 'nullable|string|max:255',
            'smtp_password' => 'nullable|string|max:1000',
            'resend_api_key' => 'nullable|string|max:1000',
            'auto_open_tray' => 'required|boolean',
            'auto_print_receipt' => 'boolean',
            'auto_print_retur' => 'boolean',
            'auto_print_kas' => 'boolean',
            'auto_print_report' => 'boolean',
            'auto_lock_minutes' => 'nullable|integer|min:1|max:120',
            'paper_width' => 'in:58,80',
            'char_per_line' => 'integer|min:20|max:72',
            'paper_mode' => 'in:normal,compact',
            'print_feed_before_cut' => 'integer|min:0|max:6',
            'izinkan_retur' => 'required|boolean',
            'durasi_retur' => 'nullable|integer|min:0',
            'keterangan' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
            'metode_pembayaran_ids' => 'required|array|min:1',
            'metode_pembayaran_ids.*' => Rule::exists('master_metode_pembayaran', 'id')->where('status', 'active'),
        ]);

        if ($err = $this->prepareMailSettings($validated, $terminal)) {
            return $err;
        }

        if ($err = $this->defaultMetodeMustBeAllowed($validated)) {
            return $err;
        }

        // Format nama based on settings
        $validated['nama_terminal'] = SettingService::formatName($validated['nama_terminal']);

        // Format keterangan if provided
        if (!empty($validated['keterangan'])) {
            $validated['keterangan'] = SettingService::formatName($validated['keterangan']);
        }

        // Extract pivot data
        $userIds = $validated['user_ids'] ?? [];
        $metodePembayaranIds = $validated['metode_pembayaran_ids'] ?? [];
        unset($validated['user_ids'], $validated['metode_pembayaran_ids']);

        // Guard: semua user harus punya permission pos.access
        if ($invalid = $this->usersWithoutPosAccess($userIds)) {
            return $this->error(
                'User berikut tidak memiliki akses POS: ' . $invalid->pluck('name')->implode(', '),
                422
            );
        }

        DB::transaction(function () use ($terminal, $validated, $userIds, $metodePembayaranIds) {
            $terminal->update($validated);
            $terminal->users()->sync($userIds);
            $terminal->allowedPaymentMethods()->sync($metodePembayaranIds);
        });

        return $this->success([
            'terminal' => $terminal->fresh(),
        ], 'Terminal berhasil diupdate');
    }

    /**
     * Toggle status (activate/deactivate) the specified terminal.
     */
    public function toggleStatus(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('terminal.toggle-status')) {
            return $this->error('Unauthorized', 403);
        }

        $terminal = MasterPosTerminal::where('ulid', $ulid)->first();

        if (!$terminal) {
            return $this->error('Terminal tidak ditemukan', 404);
        }

        // Block toggle if terminal is active
        if ($terminal->isInUse()) {
            return $this->error('Terminal sedang digunakan. Tutup paksa terlebih dahulu.', 422);
        }

        $newStatus = $terminal->status === 'active' ? 'inactive' : 'active';
        $terminal->update(['status' => $newStatus]);

        $message = $newStatus === 'active'
            ? 'Terminal berhasil diaktifkan'
            : 'Terminal berhasil dinonaktifkan';

        return $this->success(['terminal' => $terminal], $message);
    }

    /**
     * Permanently delete the specified terminal.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('terminal.delete')) {
            return $this->error('Unauthorized', 403);
        }

        $terminal = MasterPosTerminal::where('ulid', $ulid)->first();

        if (!$terminal) {
            return $this->error('Terminal tidak ditemukan', 404);
        }

        // Block delete if terminal is in use
        if ($terminal->isInUse()) {
            return $this->error('Terminal sedang digunakan. Tutup paksa terlebih dahulu.', 422);
        }

        $salesCount = DocSales::where('terminal_id', $terminal->id)->count();
        $cashCount = PosCashTransaction::where('terminal_id', $terminal->id)->count();
        $returnCount = DocSalesReturn::where('terminal_id', $terminal->id)->count();
        if ($salesCount > 0 || $cashCount > 0 || $returnCount > 0) {
            return $this->error(
                'Terminal tidak dapat dihapus karena sudah dipakai transaksi. Nonaktifkan saja.',
                422
            );
        }

        // Permanently delete (pivots cascade)
        $terminal->delete();

        return $this->success(null, 'Terminal berhasil dihapus');
    }

    /**
     * Force release an active terminal (clear active_user_id).
     *
     * Optional body: saldo_fisik (jika admin dapat info dari kasir via telepon), closing_notes.
     * Always snapshots saldo_system untuk audit trail — supervisor bisa reconcile retroactively.
     */
    public function forceRelease(string $ulid, Request $request): JsonResponse
    {
        if (!auth()->user()->can('terminal.force-release')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'saldo_fisik' => 'nullable|numeric|min:0',
            'closing_notes' => 'nullable|string|max:1000',
        ]);

        $terminal = MasterPosTerminal::where('ulid', $ulid)->first();

        if (!$terminal) {
            return $this->error('Terminal tidak ditemukan', 404);
        }

        if (!$terminal->isInUse()) {
            return $this->error('Terminal tidak sedang digunakan', 422);
        }

        return DB::transaction(function () use ($terminal, $request) {
            $terminal = MasterPosTerminal::whereKey($terminal->id)->lockForUpdate()->first();

            if (!$terminal->isInUse()) {
                return $this->error('Terminal tidak sedang digunakan', 422);
            }

            $activeShift = PosTerminalShift::where('terminal_id', $terminal->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            $shiftUlid = null;
            if ($activeShift) {
                $saldoSystem = $this->computeSaldoSystem($activeShift->id);
                $saldoFisik = $request->filled('saldo_fisik') ? (float) $request->saldo_fisik : null;
                $selisih = $saldoFisik !== null ? round($saldoFisik - $saldoSystem, 2) : null;

                $activeShift->update([
                    'ended_at' => now(),
                    'ended_by_force' => true,
                    'forced_by' => auth()->id(),
                    'saldo_system' => $saldoSystem,
                    'saldo_fisik' => $saldoFisik,
                    'selisih' => $selisih,
                    'closing_notes' => $request->closing_notes,
                ]);
                $shiftUlid = $activeShift->ulid;
            }

            $terminal->update(['active_user_id' => null]);

            return $this->success([
                'terminal' => $terminal,
                'shift_ulid' => $shiftUlid,
                'saldo_system' => $activeShift ? (float) $activeShift->saldo_system : null,
                'saldo_fisik' => $activeShift ? ($activeShift->saldo_fisik !== null ? (float) $activeShift->saldo_fisik : null) : null,
                'selisih' => $activeShift ? ($activeShift->selisih !== null ? (float) $activeShift->selisih : null) : null,
            ], 'Terminal berhasil ditutup paksa');
        });
    }

    /**
     * Start a shift on a terminal.
     */
    public function startShift(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $terminal = MasterPosTerminal::where('ulid', $ulid)->first();

        if (!$terminal) {
            return $this->error('Terminal tidak ditemukan', 404);
        }

        return DB::transaction(function () use ($terminal) {
        $terminal = MasterPosTerminal::where('id', $terminal->id)->lockForUpdate()->first();

        if (!$terminal->isActive()) {
            return $this->error('Terminal tidak aktif', 422);
        }

        if ($terminal->isInUse()) {
            return $this->error('Terminal sedang digunakan oleh user lain', 422);
        }

        // Check if terminal configuration is complete
        $missing = [];
        if (!$terminal->warehouse_id || !$terminal->warehouse()->where('status', 'active')->where('is_saleable', true)->exists()) {
            $missing[] = 'Warehouse';
        }
        if (!$terminal->default_customer_id || !$terminal->defaultCustomer()->where('status', 'active')->exists()) {
            $missing[] = 'Default Customer';
        }
        if (!$terminal->default_metode_pembayaran_id || !$terminal->defaultMetodePembayaran()->where('status', 'active')->exists()) {
            $missing[] = 'Default Metode Pembayaran';
        }
        if ($terminal->allowedPaymentMethods()->where('status', 'active')->count() === 0) {
            $missing[] = 'Metode Pembayaran';
        }
        if ($terminal->users()->whereNull('users.deleted_at')->count() === 0) {
            $missing[] = 'User';
        }
        if (!empty($missing)) {
            return $this->error('Konfigurasi terminal belum lengkap: ' . implode(', ', $missing), 422);
        }

        // Check if user is assigned to this terminal
        $userId = auth()->id();
        $isAssigned = $terminal->users()->where('users.id', $userId)->exists();
        if (!$isAssigned) {
            return $this->error('Anda tidak ditugaskan ke terminal ini', 403);
        }

        // Check if user already has an active shift on another terminal
        $existingShift = PosTerminalShift::where('user_id', $userId)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();
        if ($existingShift) {
            return $this->error('Anda masih memiliki shift aktif di terminal lain', 422);
        }

        // Set active user and create shift record
        $terminal->update(['active_user_id' => $userId]);

        $shift = PosTerminalShift::create([
            'terminal_id' => $terminal->id,
            'user_id' => $userId,
            'started_at' => now(),
        ]);

        $terminal->load([
            'activeUser:id,ulid,name',
            'activeShift:id,ulid,terminal_id,started_at',
        ]);

        return $this->success([
            'terminal' => $terminal,
            'shift' => $shift,
        ], 'Shift berhasil dimulai');
        }); // end DB::transaction
    }

    /**
     * End the current shift on a terminal.
     *
     * Optional body: saldo_fisik (cash physically counted by kasir), closing_notes.
     * Auto-computes saldo_system from transactions and stores selisih (saldo_fisik - saldo_system).
     */
    public function endShift(string $ulid, Request $request): JsonResponse
    {
        if (! auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'saldo_fisik' => 'nullable|numeric|min:0',
            'closing_notes' => 'nullable|string|max:1000',
        ]);

        $terminal = MasterPosTerminal::where('ulid', $ulid)->first();

        if (!$terminal) {
            return $this->error('Terminal tidak ditemukan', 404);
        }

        if (!$terminal->isInUse()) {
            return $this->error('Terminal tidak sedang digunakan', 422);
        }

        if ($terminal->active_user_id !== auth()->id()) {
            return $this->error('Hanya user yang sedang menggunakan terminal yang dapat mengakhiri shift', 403);
        }

        return DB::transaction(function () use ($terminal, $request) {
            $terminal = MasterPosTerminal::whereKey($terminal->id)->lockForUpdate()->first();

            if (!$terminal->isInUse()) {
                return $this->error('Terminal tidak sedang digunakan', 422);
            }

            if ($terminal->active_user_id !== auth()->id()) {
                return $this->error('Hanya user yang sedang menggunakan terminal yang dapat mengakhiri shift', 403);
            }

            $activeShift = PosTerminalShift::where('terminal_id', $terminal->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            $shiftUlid = null;
            if ($activeShift) {
                $saldoSystem = $this->computeSaldoSystem($activeShift->id);
                $saldoFisik = $request->filled('saldo_fisik') ? (float) $request->saldo_fisik : null;
                $selisih = $saldoFisik !== null ? round($saldoFisik - $saldoSystem, 2) : null;

                $activeShift->update([
                    'ended_at' => now(),
                    'ended_by_force' => false,
                    'saldo_system' => $saldoSystem,
                    'saldo_fisik' => $saldoFisik,
                    'selisih' => $selisih,
                    'closing_notes' => $request->closing_notes,
                ]);
                $shiftUlid = $activeShift->ulid;
            }

            $terminal->update(['active_user_id' => null]);

            return $this->success([
                'terminal' => $terminal,
                'shift_ulid' => $shiftUlid,
                'saldo_system' => $activeShift ? (float) $activeShift->saldo_system : null,
                'saldo_fisik' => $activeShift ? ($activeShift->saldo_fisik !== null ? (float) $activeShift->saldo_fisik : null) : null,
                'selisih' => $activeShift ? ($activeShift->selisih !== null ? (float) $activeShift->selisih : null) : null,
            ], 'Shift berhasil diakhiri');
        });
    }

    /**
     * Compute system saldo (cash on hand expected) for a shift from cash transactions + cash sales.
     * Mirrors PosController::shiftReport saldoKas calculation.
     */
    private function computeSaldoSystem(int $shiftId): float
    {
        $cashTx = \App\Models\PosCashTransaction::byShift($shiftId)->get();

        $setorAwal = (float) $cashTx->where('tipe', 'setor_awal')->sum('nominal');
        $kasMasuk = (float) $cashTx->where('tipe', 'kas_masuk')->sum('nominal');

        $kasKeluarManual = (float) $cashTx->where('tipe', 'kas_keluar')->sum('nominal');
        $refundTunai = (float) $cashTx->where('tipe', 'refund_retur')->sum('nominal');

        $tunaiMethodIds = \App\Models\MasterMetodePembayaran::where('metode', 'tunai')
            ->pluck('id')
            ->toArray();

        $completedSales = \App\Models\DocSales::where('shift_id', $shiftId)
            ->where('status', 'completed')
            ->with('payments:id,sales_id,metode_pembayaran_id,nominal')
            ->get();

        $penjualanTunaiNet = 0.0;
        foreach ($completedSales as $sale) {
            $cashReceived = (float) $sale->payments
                ->whereIn('metode_pembayaran_id', $tunaiMethodIds)
                ->sum('nominal');
            $kembalian = (float) ($sale->kembalian ?? 0);
            $penjualanTunaiNet += ($cashReceived - $kembalian);
        }

        return round($setorAwal + $penjualanTunaiNet + $kasMasuk - $kasKeluarManual - $refundTunai, 2);
    }

    /**
     * Get list of active terminals for dropdowns.
     */
    public function list(): JsonResponse
    {
        if (! auth()->user()->can('terminal.view') && ! auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $terminals = MasterPosTerminal::active()
            ->select('id', 'ulid', 'kode_terminal', 'nama_terminal', 'warehouse_id')
            ->orderBy('nama_terminal')
            ->get()
            ->makeVisible('id');

        return $this->success([
            'terminals' => $terminals,
        ]);
    }

    /**
     * Summary shift aktif — dipakai halaman Settings untuk warn admin sebelum ubah
     * setting fiskal (pajak/diskon/pembulatan) yang akan mempengaruhi transaksi berjalan.
     */
    public function activeShiftsSummary(): JsonResponse
    {
        if (!auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        $shifts = PosTerminalShift::whereNull('ended_at')
            ->with([
                'terminal:id,ulid,kode_terminal,nama_terminal',
                'user:id,name',
            ])
            ->orderByDesc('started_at')
            ->get(['id', 'terminal_id', 'user_id', 'started_at']);

        return $this->success([
            'count' => $shifts->count(),
            'shifts' => $shifts,
        ]);
    }

    /**
     * Send a plain-text test email using terminal mail config.
     */
    public function mailTest(Request $request, string $ulid): JsonResponse
    {
        if (! auth()->user()->can('terminal.edit')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'to_email' => 'required|email|max:255',
        ]);

        $terminal = MasterPosTerminal::where('ulid', $ulid)->first();
        if (! $terminal) {
            return $this->error('Terminal tidak ditemukan', 404);
        }
        if (! $terminal->isMailConfigured()) {
            return $this->error('Email struk belum dikonfigurasi pada terminal ini.', 422);
        }

        try {
            TerminalMailer::send(
                $terminal,
                $validated['to_email'],
                "Test email — {$terminal->nama_terminal}",
                "Ini email uji dari terminal {$terminal->kode_terminal} ({$terminal->nama_terminal}).\nDriver: {$terminal->mail_driver}."
            );
        } catch (\Throwable $e) {
            return $this->error('Gagal mengirim: '.$e->getMessage(), 422);
        }

        return $this->success(null, 'Email uji berhasil dikirim.');
    }

    private function defaultMetodeMustBeAllowed(array $validated): ?JsonResponse
    {
        $defaultId = (int) ($validated['default_metode_pembayaran_id'] ?? 0);
        $allowed = array_map('intval', $validated['metode_pembayaran_ids'] ?? []);
        if ($defaultId && ! in_array($defaultId, $allowed, true)) {
            return $this->validationError([
                'default_metode_pembayaran_id' => ['Default metode harus termasuk dalam metode yang diizinkan.'],
            ]);
        }

        return null;
    }

    private function prepareMailSettings(array &$validated, ?MasterPosTerminal $terminal = null): ?JsonResponse
    {
        $driver = $validated['mail_driver'] ?? 'none';
        $validated['mail_driver'] = $driver;

        if ($driver === 'none') {
            foreach (['mail_from_address', 'mail_from_name', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'resend_api_key'] as $field) {
                $validated[$field] = null;
            }

            return null;
        }

        $errors = [];
        if (empty($validated['mail_from_address'])) $errors['mail_from_address'][] = 'Alamat pengirim wajib diisi.';

        if ($driver === 'smtp') {
            if (empty($validated['smtp_host'])) $errors['smtp_host'][] = 'SMTP host wajib diisi.';
            if (empty($validated['smtp_port'])) $errors['smtp_port'][] = 'SMTP port wajib diisi.';
            $validated['resend_api_key'] = null;
            if ($terminal && empty($validated['smtp_password'])) unset($validated['smtp_password']);
        }

        if ($driver === 'resend') {
            $key = $validated['resend_api_key'] ?? $terminal?->resend_api_key;
            if (empty($key)) $errors['resend_api_key'][] = 'Resend API key wajib diisi.';
            $validated['smtp_host'] = null;
            $validated['smtp_port'] = null;
            $validated['smtp_encryption'] = null;
            $validated['smtp_username'] = null;
            $validated['smtp_password'] = null;
            if ($terminal && empty($validated['resend_api_key'])) unset($validated['resend_api_key']);
        }

        return empty($errors) ? null : $this->validationError($errors);
    }

    /**
     * Return User collection yang TIDAK punya permission pos.access (via role atau direct).
     * Kembalikan null kalau semua valid — pakai pattern `if ($invalid = ...)` di caller.
     */
    private function usersWithoutPosAccess(array $userIds)
    {
        if (empty($userIds)) return null;
        $withAccess = User::visible()
            ->whereIn('id', $userIds)
            ->permission('pos.access')
            ->pluck('id')
            ->all();
        $missing = array_diff($userIds, $withAccess);
        if (empty($missing)) return null;
        return User::whereIn('id', $missing)->select('id', 'name')->get();
    }
}
