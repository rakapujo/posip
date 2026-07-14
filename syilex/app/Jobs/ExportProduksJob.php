<?php

namespace App\Jobs;

use App\Exports\ProduksExport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Job untuk export produks ke Excel secara async.
 *
 * Kenapa async?
 * - Export 10K+ produk di request blocking bisa timeout (30s limit PHP default)
 * - Request menunggu + server load tinggi
 *
 * Flow:
 * 1. Controller dispatch job + return job ID + status
 * 2. Job jalan di worker, generate file ke storage
 * 3. FE poll status endpoint, atau notifikasi saat ready
 *
 * Catatan:
 * - File disimpan di `storage/app/exports/produks_{userId}_{timestamp}.xlsx`
 * - TTL 24 jam (cleanup manual atau via scheduled command)
 */
class ExportProduksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times job boleh di-retry kalau gagal.
     */
    public int $tries = 3;

    /**
     * Timeout per job attempt (detik). Default Laravel 60s.
     * Set 300s (5 menit) untuk export besar.
     */
    public int $timeout = 300;

    /**
     * Backoff antar retry (detik).
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $userId,
        public ?string $search = null,
        public ?int $brandId = null,
        public ?int $tipeId = null,
        public ?int $kategoriId = null,
        public ?int $grupId = null,
        public ?string $status = null,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user) {
            Log::warning("ExportProduksJob: user {$this->userId} tidak ditemukan, skip.");
            return;
        }

        $canViewHpp = $user->can('stok.view_hpp');
        $filename = "exports/produks_{$this->userId}_" . now()->format('Ymd_His') . '.xlsx';

        $export = new ProduksExport(
            $canViewHpp,
            $this->search,
            $this->brandId,
            $this->tipeId,
            $this->kategoriId,
            $this->grupId,
            $this->status,
        );

        // Store file ke local disk (bisa dipindah ke S3 jika perlu)
        Excel::store($export, $filename, 'local');

        Log::info("ExportProduksJob: berhasil generate {$filename} untuk user {$this->userId}");

        // TODO: Notifikasi user (email/database notification) bahwa export ready.
        // $user->notify(new ExportReadyNotification($filename));
    }

    /**
     * Dipanggil saat job gagal (setelah semua retry habis).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ExportProduksJob failed untuk user {$this->userId}", [
            'error' => $exception->getMessage(),
            'filters' => [
                'search' => $this->search,
                'brand_id' => $this->brandId,
            ],
        ]);
    }
}
