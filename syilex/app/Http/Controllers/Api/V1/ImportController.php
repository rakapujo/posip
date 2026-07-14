<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportController extends BaseApiController
{
    /**
     * Map of entity => config
     */
    private function getEntityConfig(): array
    {
        return [
            'brand' => [
                'permission' => 'brand.create',
                'table' => 'master_brand',
                'kode_field' => 'kode_brand',
                'columns' => ['kode_brand', 'nama_brand', 'status'],
                'headings' => ['Kode Brand', 'Nama Brand', 'Status'],
                'required' => ['kode_brand', 'nama_brand'],
            ],
            'tipe' => [
                'permission' => 'tipe.create',
                'table' => 'master_tipe',
                'kode_field' => 'kode_tipe',
                'columns' => ['kode_tipe', 'nama_tipe', 'status'],
                'headings' => ['Kode Tipe', 'Nama Tipe', 'Status'],
                'required' => ['kode_tipe', 'nama_tipe'],
            ],
            'kategori' => [
                'permission' => 'kategori.create',
                'table' => 'master_kategori',
                'kode_field' => 'kode_kategori',
                'columns' => ['kode_kategori', 'nama_kategori', 'kode_tipe', 'status'],
                'headings' => ['Kode Kategori', 'Nama Kategori', 'Kode Tipe', 'Status'],
                'required' => ['kode_kategori', 'nama_kategori', 'kode_tipe'],
                'lookups' => ['kode_tipe' => ['table' => 'master_tipe', 'kode_field' => 'kode_tipe', 'target_field' => 'tipe_id']],
            ],
            'grup' => [
                'permission' => 'grup.create',
                'table' => 'master_grup',
                'kode_field' => 'kode_grup',
                'columns' => ['kode_grup', 'nama_grup', 'kode_kategori', 'status'],
                'headings' => ['Kode Grup', 'Nama Grup', 'Kode Kategori', 'Status'],
                'required' => ['kode_grup', 'nama_grup', 'kode_kategori'],
                'lookups' => ['kode_kategori' => ['table' => 'master_kategori', 'kode_field' => 'kode_kategori', 'target_field' => 'kategori_id']],
            ],
            'supplier' => [
                'permission' => 'supplier.create',
                'table' => 'master_supplier',
                'kode_field' => 'kode_supplier',
                'columns' => ['kode_supplier', 'nama_supplier', 'nama_pic', 'telepon', 'email', 'alamat', 'npwp', 'bank_nama', 'bank_rekening', 'bank_atas_nama', 'tempo_default', 'status'],
                'headings' => ['Kode Supplier', 'Nama Supplier', 'PIC', 'Telepon', 'Email', 'Alamat', 'NPWP', 'Bank', 'No. Rekening', 'Atas Nama', 'Tempo (Hari)', 'Status'],
                'required' => ['kode_supplier', 'nama_supplier'],
            ],
            'warehouse' => [
                'permission' => 'warehouse.create',
                'table' => 'master_warehouse',
                'kode_field' => 'kode_warehouse',
                'columns' => ['kode_warehouse', 'nama_warehouse', 'alamat', 'pic_name', 'pic_phone', 'is_saleable', 'status'],
                'headings' => ['Kode Warehouse', 'Nama Warehouse', 'Alamat', 'PIC', 'Telepon PIC', 'Dapat Dijual (POS)', 'Status'],
                'required' => ['kode_warehouse', 'nama_warehouse'],
            ],
            'tipe_customer' => [
                'permission' => 'tipe-customer.create',
                'table' => 'master_tipe_customer',
                'kode_field' => 'kode_tipe',
                'columns' => ['kode_tipe', 'nama_tipe', 'keterangan', 'status'],
                'headings' => ['Kode Tipe', 'Nama Tipe', 'Keterangan', 'Status'],
                'required' => ['kode_tipe', 'nama_tipe'],
            ],
            'kategori_customer' => [
                'permission' => 'kategori-customer.create',
                'table' => 'master_kategori_customer',
                'kode_field' => 'kode_kategori',
                'columns' => ['kode_kategori', 'nama_kategori', 'keterangan', 'status'],
                'headings' => ['Kode Kategori', 'Nama Kategori', 'Keterangan', 'Status'],
                'required' => ['kode_kategori', 'nama_kategori'],
            ],
            'customer' => [
                'permission' => 'customer.create',
                'table' => 'master_customer',
                'kode_field' => 'kode_customer',
                'columns' => ['kode_customer', 'nama', 'telepon', 'email', 'alamat', 'nik', 'npwp', 'jenis', 'kode_tipe', 'kode_kategori', 'status'],
                'headings' => ['Kode Customer', 'Nama', 'Telepon', 'Email', 'Alamat', 'NIK', 'NPWP', 'Jenis', 'Kode Tipe', 'Kode Kategori', 'Status'],
                'required' => ['kode_customer', 'nama', 'telepon'],
                'lookups' => [
                    'kode_tipe' => ['table' => 'master_tipe_customer', 'kode_field' => 'kode_tipe', 'target_field' => 'tipe_customer_id', 'optional' => true],
                    'kode_kategori' => ['table' => 'master_kategori_customer', 'kode_field' => 'kode_kategori', 'target_field' => 'kategori_customer_id', 'optional' => true],
                ],
            ],
            'metode_pembayaran' => [
                'permission' => 'metode-bayar.create',
                'table' => 'master_metode_pembayaran',
                'kode_field' => 'kode_pembayaran',
                'columns' => ['kode_pembayaran', 'nama_pembayaran', 'metode', 'jenis', 'nama_akun', 'nomor_akun', 'biaya_tambahan_tipe', 'biaya_tambahan_nilai', 'status'],
                'headings' => ['Kode Pembayaran', 'Nama Pembayaran', 'Metode', 'Jenis', 'Nama Akun', 'Nomor Akun', 'Biaya Tambahan Tipe', 'Biaya Tambahan Nilai', 'Status'],
                'required' => ['kode_pembayaran', 'nama_pembayaran', 'metode'],
            ],
            'produk' => [
                'permission' => 'produk.create',
                'table' => 'master_produk',
                'kode_field' => 'kode_produk',
                'columns' => ['kode_produk', 'barcode', 'nama_produk', 'kode_brand', 'kode_tipe', 'kode_kategori', 'kode_grup', 'unit_1', 'konversi_1', 'harga_1', 'unit_2', 'konversi_2', 'harga_2', 'unit_3', 'konversi_3', 'harga_3', 'unit_4', 'harga_4', 'minimum_stok', 'status', 'is_serial'],
                'headings' => ['Kode Produk', 'Barcode', 'Nama Produk', 'Kode Brand', 'Kode Tipe', 'Kode Kategori', 'Kode Grup', 'Unit 1', 'Konversi 1', 'Harga 1', 'Unit 2', 'Konversi 2', 'Harga 2', 'Unit 3', 'Konversi 3', 'Harga 3', 'Unit 4', 'Harga 4', 'Minimum Stok', 'Status', 'Serial'],
                'required' => ['kode_produk', 'nama_produk', 'unit_1', 'konversi_1', 'harga_1', 'unit_2', 'konversi_2', 'harga_2', 'unit_3', 'konversi_3', 'harga_3', 'unit_4', 'harga_4'],
                'lookups' => [
                    'kode_brand' => ['table' => 'master_brand', 'kode_field' => 'kode_brand', 'target_field' => 'brand_id', 'optional' => true],
                    'kode_tipe' => ['table' => 'master_tipe', 'kode_field' => 'kode_tipe', 'target_field' => 'tipe_id', 'optional' => true],
                    'kode_kategori' => ['table' => 'master_kategori', 'kode_field' => 'kode_kategori', 'target_field' => 'kategori_id', 'optional' => true],
                    'kode_grup' => ['table' => 'master_grup', 'kode_field' => 'kode_grup', 'target_field' => 'grup_id', 'optional' => true],
                ],
            ],
        ];
    }

    /**
     * Download import template
     */
    public function template(Request $request, string $entity)
    {
        $configs = $this->getEntityConfig();
        if (!isset($configs[$entity])) {
            return $this->error("Entity '$entity' tidak valid", 422);
        }

        $config = $configs[$entity];

        if (!auth()->user()->can($config['permission'])) {
            return $this->forbidden();
        }

        $headings = $config['headings'];
        $samples = $this->getSampleData($entity);

        return Excel::download(
            new \App\Exports\ImportTemplateExport($headings, $samples),
            "template_import_{$entity}.xlsx"
        );
    }

    /**
     * Import data from Excel
     */
    public function import(Request $request, string $entity)
    {
        // Global import gate — harus punya import.master
        if (!auth()->user()->can('import.master')) {
            return $this->forbidden('Anda tidak memiliki akses import master.');
        }

        $configs = $this->getEntityConfig();
        if (!isset($configs[$entity])) {
            return $this->error("Entity '$entity' tidak valid", 422);
        }

        $config = $configs[$entity];

        if (!auth()->user()->can($config['permission'])) {
            return $this->forbidden();
        }

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
            'mode' => 'required|in:create,upsert',
        ]);

        $file = $request->file('file');
        $mode = $request->input('mode');

        try {
            // Read Excel file
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray(null, true, true, false);

            if (count($rows) < 2) {
                return $this->error('File kosong atau hanya berisi header', 422);
            }

            // Remove header row
            $headerRow = array_shift($rows);

            // Validate header matches expected columns
            $expectedHeadings = $config['headings'];
            $headerClean = array_map('trim', array_slice($headerRow, 0, count($expectedHeadings)));

            // Allow header with "No" column prefix (from export): header sudah di-shift di atas,
            // tinggal buang kolom "No" dari tiap baris data (JANGAN shift $rows lagi — itu akan
            // membuang baris data pertama).
            if (strtolower(trim($headerRow[0] ?? '')) === 'no') {
                $rows = array_map(function ($row) {
                    array_shift($row);
                    return $row;
                }, $rows);
            }

            $results = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            // Build lookup caches
            $lookupCaches = [];
            if (isset($config['lookups'])) {
                foreach ($config['lookups'] as $field => $lookup) {
                    $lookupCaches[$field] = DB::table($lookup['table'])
                        ->pluck('id', $lookup['kode_field'])
                        ->toArray();
                }
            }

            // Chunking: proses per batch 500 row supaya transaction kecil + memory efisien.
            // File 50K row tidak akan crash memory, dan gagal di tengah tidak kehilangan progress.
            $chunkSize = 500;
            $chunks = array_chunk($rows, $chunkSize, true); // preserve keys untuk index row number

            foreach ($chunks as $chunk) {
                DB::beginTransaction();
                try {
                    $this->processImportChunk($chunk, $config, $lookupCaches, $entity, $mode, $results);
                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $results['errors'][] = "Batch gagal (chunk size {$chunkSize}): {$e->getMessage()}";
                    // Continue ke chunk berikutnya — jangan abort semuanya.
                }
            }

            $message = "{$results['created']} dibuat";
            if ($results['updated'] > 0) $message .= ", {$results['updated']} diupdate";
            if ($results['skipped'] > 0) $message .= ", {$results['skipped']} dilewati";
            if (count($results['errors']) > 0) $message .= ", " . count($results['errors']) . " error";

            return $this->success($results, $message);

        } catch (\Exception $e) {
            // Safety rollback jika transaction masih terbuka (jarang terjadi).
            try { DB::rollBack(); } catch (\Throwable $_) {}
            return $this->error('Gagal import: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Process satu batch (chunk) row import.
     * Dijalankan di dalam DB transaction oleh caller.
     */
    private function processImportChunk(array $chunk, array $config, array $lookupCaches, string $entity, string $mode, array &$results): void
    {
        $kodeField = $config['kode_field'];

        foreach ($chunk as $index => $row) {
            $rowNum = $index + 2; // Excel row number (1-based + header)

            // Skip empty rows
            if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            // Map columns to fields
            $data = [];
            foreach ($config['columns'] as $i => $col) {
                $data[$col] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            // Gate Modul Elektronik: tolak baris produk serial saat modul nonaktif.
            if ($entity === 'produk' && $this->parseBool($data['is_serial'] ?? '') && !SettingService::isElektronikEnabled()) {
                $results['errors'][] = "Baris {$rowNum}: Produk serial tidak bisa diimpor — Modul Elektronik nonaktif.";
                continue;
            }

            // Check required fields — produk serial: cuma kode + nama (unit/harga di-scaffold).
            $required = $config['required'];
            if ($entity === 'produk' && $this->parseBool($data['is_serial'] ?? '')) {
                $required = ['kode_produk', 'nama_produk'];
            }
            $missing = [];
            foreach ($required as $req) {
                if (empty($data[$req])) {
                    $missing[] = $req;
                }
            }
            if (!empty($missing)) {
                $results['errors'][] = "Baris {$rowNum}: Kolom wajib kosong: " . implode(', ', $missing);
                continue;
            }

            // Process status
            $statusField = 'status';
            if (isset($data[$statusField])) {
                $data[$statusField] = $this->parseStatus($data[$statusField]);
            } else {
                $data[$statusField] = 'active';
            }

            // Process lookups
            $lookupError = false;
            if (isset($config['lookups'])) {
                foreach ($config['lookups'] as $field => $lookup) {
                    if (!empty($data[$field])) {
                        $lookupKey = strtoupper(trim($data[$field]));
                        $id = $lookupCaches[$field][$data[$field]]
                            ?? $lookupCaches[$field][$lookupKey]
                            ?? null;

                        if ($id === null) {
                            $isOptional = $lookup['optional'] ?? false;
                            if (!$isOptional) {
                                $results['errors'][] = "Baris {$rowNum}: {$field} '{$data[$field]}' tidak ditemukan";
                                $lookupError = true;
                            }
                        } else {
                            $data[$lookup['target_field']] = $id;
                        }
                    } elseif (!($lookup['optional'] ?? false)) {
                        $results['errors'][] = "Baris {$rowNum}: {$field} wajib diisi";
                        $lookupError = true;
                    }
                    unset($data[$field]);
                }
            }
            if ($lookupError) continue;

            // Entity-specific processing
            $data = $this->processEntityData($entity, $data);

            // Format code and name
            $data[$kodeField] = SettingService::formatCode($data[$kodeField]);
            foreach ($data as $key => $value) {
                if (str_starts_with($key, 'nama_') || $key === 'nama') {
                    $data[$key] = SettingService::formatName($value);
                }
            }

            // Add audit fields
            $data['created_by'] = auth()->id();
            $data['updated_by'] = auth()->id();

            // Upsert
            $existing = DB::table($config['table'])->where($kodeField, $data[$kodeField])->first();

            // Produk: is_serial IMMUTABLE (cegah desync) — tolak flip status serial produk existing.
            if ($entity === 'produk' && $existing && (bool) $existing->is_serial !== (bool) ($data['is_serial'] ?? false)) {
                $results['errors'][] = "Baris {$rowNum}: status Serial produk '{$data[$kodeField]}' tidak bisa diubah via import (immutable).";
                $results['skipped']++;
                continue;
            }

            if ($existing) {
                if ($mode === 'upsert') {
                    $updateData = $data;
                    unset($updateData[$kodeField], $updateData['created_by']);
                    $updateData['updated_at'] = now();

                    DB::table($config['table'])->where('id', $existing->id)->update($updateData);
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            } else {
                $data['ulid'] = (string) \Symfony\Component\Uid\Ulid::generate();
                $data['created_at'] = now();
                $data['updated_at'] = now();

                DB::table($config['table'])->insert($data);
                $results['created']++;
            }
        }
    }

    /**
     * Parse status string to active/inactive
     */
    private function parseStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['aktif', 'active', 'ya', 'yes', '1'])) {
            return 'active';
        }
        return 'inactive';
    }

    /**
     * Parse boolean-ish string (Ya/Tidak, Yes/No, Serial, 1/0).
     */
    private function parseBool(?string $val): bool
    {
        return in_array(strtolower(trim((string) $val)), ['ya', 'yes', 'serial', '1', 'true'], true);
    }

    /**
     * Process entity-specific data transformations
     */
    private function processEntityData(string $entity, array $data): array
    {
        switch ($entity) {
            case 'warehouse':
                $data['is_saleable'] = in_array(strtolower($data['is_saleable'] ?? ''), ['ya', 'yes', '1', 'true']);
                break;

            case 'customer':
                $jenis = strtolower($data['jenis'] ?? 'spesifik');
                $data['jenis'] = in_array($jenis, ['walk_in', 'walk in', 'walkin']) ? 'walk_in' : 'spesifik';
                break;

            case 'metode_pembayaran':
                $metode = strtolower($data['metode'] ?? 'tunai');
                $data['metode'] = $metode === 'tunai' ? 'tunai' : 'non_tunai';

                $jenisMap = [
                    'bank' => 'bank', 'bank transfer' => 'bank',
                    'qris' => 'qris',
                    'credit card' => 'credit_card', 'kartu kredit' => 'credit_card',
                    'debit card' => 'debit_card', 'kartu debit' => 'debit_card',
                    'e-wallet' => 'e_wallet', 'e_wallet' => 'e_wallet', 'ewallet' => 'e_wallet',
                    'lainnya' => 'lainnya', 'other' => 'lainnya',
                ];
                $data['jenis'] = $jenisMap[strtolower($data['jenis'] ?? '')] ?? null;

                $biayaTipeMap = [
                    'nominal' => 'nominal',
                    'persen' => 'persen', 'percent' => 'persen', '%' => 'persen',
                ];
                $data['biaya_tambahan_tipe'] = $biayaTipeMap[strtolower($data['biaya_tambahan_tipe'] ?? '')] ?? 'none';
                $data['biaya_tambahan_nilai'] = is_numeric($data['biaya_tambahan_nilai'] ?? '') ? (float) $data['biaya_tambahan_nilai'] : 0;
                break;

            case 'produk':
                $isSerial = $this->parseBool($data['is_serial'] ?? '');
                $data['is_serial'] = $isSerial;
                $data['avg_cost'] = 0;

                if ($isSerial) {
                    // Serial: scaffold sama dgn ProdukController::applySerialScaffolding (UNIT/1/0).
                    foreach ([1, 2, 3, 4] as $i) {
                        $data["unit_{$i}"] = 'UNIT';
                        $data["konversi_{$i}"] = 1;
                        $data["harga_{$i}"] = 0;
                    }
                    $data['minimum_stok'] = 0;
                    $data['barcode'] = null;
                    break;
                }

                // Numeric fields
                foreach (['konversi_1', 'konversi_2', 'konversi_3'] as $f) {
                    $data[$f] = (int) ($data[$f] ?? 1);
                    if ($data[$f] < 1) $data[$f] = 1;
                }
                $data['konversi_4'] = 1; // Always 1

                foreach (['harga_1', 'harga_2', 'harga_3', 'harga_4'] as $f) {
                    $val = (float) ($data[$f] ?? 0);
                    $data[$f] = $val < 0 ? 0 : $val;
                }

                $data['minimum_stok'] = max(0, (int) ($data['minimum_stok'] ?? 0));

                // Unit uppercase
                foreach (['unit_1', 'unit_2', 'unit_3', 'unit_4'] as $f) {
                    $data[$f] = strtoupper(trim($data[$f] ?? ''));
                }

                // Barcode: empty string → null
                if (empty($data['barcode'])) {
                    $data['barcode'] = null;
                }
                break;

            case 'supplier':
                $data['tempo_default'] = (int) ($data['tempo_default'] ?? 0);
                break;
        }

        // Remove empty optional strings → null
        foreach ($data as $key => $value) {
            if ($value === '' || $value === '-') {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /**
     * Get sample data for template (5 rows per entity)
     */
    private function getSampleData(string $entity): array
    {
        return match ($entity) {
            'brand' => [
                ['BRD001', 'Brand Alpha', 'Aktif'],
                ['BRD002', 'Brand Beta', 'Aktif'],
                ['BRD003', 'Brand Gamma', 'Aktif'],
                ['BRD004', 'Brand Delta', 'Nonaktif'],
                ['BRD005', 'Brand Epsilon', 'Aktif'],
            ],
            'tipe' => [
                ['TIP001', 'Makanan', 'Aktif'],
                ['TIP002', 'Minuman', 'Aktif'],
                ['TIP003', 'Snack', 'Aktif'],
                ['TIP004', 'Bumbu', 'Aktif'],
                ['TIP005', 'Frozen Food', 'Nonaktif'],
            ],
            'kategori' => [
                // headings: Kode Kategori, Nama Kategori, Kode Tipe, Status
                ['KAT001', 'Makanan Ringan', 'TIP001', 'Aktif'],
                ['KAT002', 'Makanan Berat', 'TIP001', 'Aktif'],
                ['KAT003', 'Minuman Dingin', 'TIP002', 'Aktif'],
                ['KAT004', 'Minuman Panas', 'TIP002', 'Aktif'],
                ['KAT005', 'Snack Import', 'TIP003', 'Nonaktif'],
            ],
            'grup' => [
                // headings: Kode Grup, Nama Grup, Kode Kategori, Status
                ['GRP001', 'Keripik', 'KAT001', 'Aktif'],
                ['GRP002', 'Biskuit', 'KAT001', 'Aktif'],
                ['GRP003', 'Nasi', 'KAT002', 'Aktif'],
                ['GRP004', 'Jus', 'KAT003', 'Aktif'],
                ['GRP005', 'Teh', 'KAT004', 'Nonaktif'],
            ],
            'supplier' => [
                // headings: Kode, Nama, PIC, Telepon, Email, Alamat, NPWP, Bank, No Rek, Atas Nama, Tempo, Status
                ['SUP001', 'PT Sumber Jaya', 'Budi', '081234567890', 'budi@sumberjaya.com', 'Jl. Industri No. 10', '01.234.567.8-901.000', 'BCA', '1234567890', 'PT Sumber Jaya', 30, 'Aktif'],
                ['SUP002', 'CV Maju Bersama', 'Siti', '081298765432', 'siti@majubersama.com', 'Jl. Raya No. 5', '', 'Mandiri', '0987654321', 'CV Maju Bersama', 14, 'Aktif'],
                ['SUP003', 'UD Sentosa', 'Andi', '087812345678', '', 'Jl. Pasar No. 3', '', '', '', '', 0, 'Aktif'],
                ['SUP004', 'PT Global Indo', 'Dewi', '089912345678', 'dewi@global.com', 'Jl. Gatot Subroto No. 8', '02.345.678.9-012.000', 'BRI', '1122334455', 'PT Global Indo', 45, 'Aktif'],
                ['SUP005', 'Toko Abadi', 'Rudi', '081312345678', '', 'Jl. Merdeka No. 1', '', '', '', '', 7, 'Nonaktif'],
            ],
            'warehouse' => [
                // headings: Kode, Nama, Alamat, PIC, Telepon PIC, Dapat Dijual, Status
                ['WH001', 'Gudang Utama', 'Jl. Gudang No. 1', 'Ahmad', '081234567890', 'Ya', 'Aktif'],
                ['WH002', 'Gudang Cabang', 'Jl. Cabang No. 5', 'Budi', '081298765432', 'Ya', 'Aktif'],
                ['WH003', 'Gudang Produksi', 'Jl. Industri No. 3', 'Citra', '087812345678', 'Tidak', 'Aktif'],
                ['WH004', 'Gudang Transit', 'Jl. Transit No. 2', '', '', 'Tidak', 'Aktif'],
                ['WH005', 'Gudang Lama', 'Jl. Lama No. 10', '', '', 'Tidak', 'Nonaktif'],
            ],
            'tipe_customer' => [
                // headings: Kode Tipe, Nama Tipe, Keterangan, Status
                ['TC001', 'Retail', 'Customer retail / eceran', 'Aktif'],
                ['TC002', 'Grosir', 'Customer grosir / partai besar', 'Aktif'],
                ['TC003', 'Reseller', 'Customer reseller', 'Aktif'],
                ['TC004', 'Member', 'Customer member terdaftar', 'Aktif'],
                ['TC005', 'VIP', 'Customer VIP / prioritas', 'Nonaktif'],
            ],
            'kategori_customer' => [
                // headings: Kode Kategori, Nama Kategori, Keterangan, Status
                ['KC001', 'Toko', 'Toko / warung', 'Aktif'],
                ['KC002', 'Restoran', 'Restoran / cafe', 'Aktif'],
                ['KC003', 'Hotel', 'Hotel / penginapan', 'Aktif'],
                ['KC004', 'Kantor', 'Perkantoran', 'Aktif'],
                ['KC005', 'Online Shop', 'Toko online', 'Nonaktif'],
            ],
            'customer' => [
                // headings: Kode, Nama, Telepon, Email, Alamat, NIK, NPWP, Jenis, Kode Tipe, Kode Kategori, Status
                ['CST001', 'Toko Makmur', '081234567890', 'makmur@email.com', 'Jl. Pasar No. 1', '', '', 'Spesifik', 'TC001', 'KC001', 'Aktif'],
                ['CST002', 'Resto Sederhana', '081298765432', '', 'Jl. Kuliner No. 5', '', '', 'Spesifik', 'TC002', 'KC002', 'Aktif'],
                ['CST003', 'Budi Santoso', '087812345678', 'budi@email.com', 'Jl. Merdeka No. 3', '3201234567890001', '', 'Spesifik', 'TC003', '', 'Aktif'],
                ['CST004', 'Walk-In Customer', '000000000000', '', '', '', '', 'Walk_in', '', '', 'Aktif'],
                ['CST005', 'Hotel Nusantara', '089912345678', 'info@hotelnusantara.com', 'Jl. Sudirman No. 10', '', '03.456.789.0-123.000', 'Spesifik', 'TC004', 'KC003', 'Nonaktif'],
            ],
            'metode_pembayaran' => [
                // headings: Kode, Nama, Metode, Jenis, Nama Akun, Nomor Akun, Biaya Tipe, Biaya Nilai, Status
                ['CASH', 'Tunai', 'Tunai', '', '', '', '', '', 'Aktif'],
                ['BCA01', 'Transfer BCA', 'Non Tunai', 'Bank', 'BCA', '1234567890', '', '', 'Aktif'],
                ['QRIS01', 'QRIS', 'Non Tunai', 'QRIS', 'QRIS Store', '', 'Persen', 0.7, 'Aktif'],
                ['DEBIT01', 'Kartu Debit', 'Non Tunai', 'Debit Card', '', '', 'Nominal', 2500, 'Aktif'],
                ['GOPAY01', 'GoPay', 'Non Tunai', 'E-Wallet', 'GoPay Merchant', '', 'Persen', 1.5, 'Nonaktif'],
            ],
            'produk' => [
                // headings: Kode, Barcode, Nama, Kode Brand, Kode Tipe, Kode Kategori, Kode Grup, Unit1, Konv1, Harga1, Unit2, Konv2, Harga2, Unit3, Konv3, Harga3, Unit4, Harga4, Min Stok, Status, Serial
                ['PRD001', '8991234567890', 'Mie Goreng Spesial', 'BRD001', 'TIP001', 'KAT001', 'GRP001', 'KARTON', 48, 120000, 'PACK', 12, 30000, 'RENTENG', 6, 15000, 'PCS', 2500, 10, 'Aktif', 'Tidak'],
                ['PRD002', '8991234567891', 'Air Mineral 600ml', 'BRD002', 'TIP002', 'KAT003', 'GRP004', 'KARTON', 24, 48000, 'PACK', 6, 12000, 'PACK', 6, 12000, 'BTL', 2000, 24, 'Aktif', 'Tidak'],
                ['PRD003', '', 'Gula Pasir 1kg', 'BRD003', 'TIP004', 'KAT002', '', 'SAK', 50, 750000, 'SAK', 50, 750000, 'SAK', 50, 750000, 'KG', 15000, 5, 'Aktif', 'Tidak'],
                ['PRD004', '8991234567893', 'Keripik Kentang 100g', 'BRD001', 'TIP003', 'KAT001', 'GRP002', 'DUS', 24, 180000, 'PACK', 6, 45000, 'PACK', 6, 45000, 'PCS', 7500, 12, 'Aktif', 'Tidak'],
                ['PRD005', '8991234567894', 'Kopi Bubuk 200g', 'BRD004', 'TIP002', 'KAT004', 'GRP005', 'DUS', 20, 300000, 'PACK', 10, 150000, 'PACK', 10, 150000, 'PCS', 15000, 0, 'Nonaktif', 'Tidak'],
                // Produk serial: kosongkan unit/konversi/harga/barcode/min-stok → auto-scaffold (UNIT/1/0).
                ['LAP001', '', 'MacBook Air M2 13"', 'BRD001', 'TIP003', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Aktif', 'Ya'],
                ['HP0001', '', 'iPhone 15 Pro 256GB', 'BRD002', 'TIP003', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Aktif', 'Ya'],
            ],
            default => [],
        };
    }
}
