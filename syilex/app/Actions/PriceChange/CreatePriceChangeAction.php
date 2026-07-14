<?php

namespace App\Actions\PriceChange;

use App\Models\DocPriceChange;
use App\Models\DocPriceChangeDetail;
use App\Models\MasterProduk;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class CreatePriceChangeAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(array $data): DocPriceChange
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            // Check for locked products (products in any draft or scheduled)
            $productIds = collect($data['details'])->pluck('product_id');
            $lockedProducts = $this->getLockedProductIds();

            $conflictingProducts = $productIds->intersect($lockedProducts);
            if ($conflictingProducts->isNotEmpty()) {
                $productNames = MasterProduk::whereIn('id', $conflictingProducts)
                    ->pluck('nama_produk')
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'details' => ["Produk berikut sudah ada di dokumen perubahan harga yang belum selesai: {$productNames}"],
                ]);
            }

            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber(
                'price_change',
                'doc_price_change',
                'nomor_dokumen'
            );

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Create header
            $priceChange = DocPriceChange::create([
                'nomor_dokumen' => $nomorDokumen,
                'tanggal_pengajuan' => $data['tanggal_pengajuan'],
                'tanggal_berlaku' => $data['tanggal_berlaku'],
                'status' => 'draft',
                'notes' => $notes,
            ]);

            // Get price input mode
            $priceMode = SettingService::getPriceInputMode();

            // Create details
            foreach ($data['details'] as $detail) {
                $this->createDetail($priceChange, $detail, $priceMode);
            }

            // Load relations for response
            $priceChange->load(['details.product', 'createdBy']);

            return $priceChange;
        });
    }

    /**
     * Create a detail record.
     */
    private function createDetail(DocPriceChange $priceChange, array $detail, string $priceMode): void
    {
        $product = MasterProduk::find($detail['product_id']);

        if (!$product) {
            throw ValidationException::withMessages([
                'details' => ["Produk dengan ID {$detail['product_id']} tidak ditemukan."],
            ]);
        }

        // Capture current prices
        $harga1Lama = (float) $product->harga_1;
        $harga2Lama = (float) $product->harga_2;
        $harga3Lama = (float) $product->harga_3;
        $harga4Lama = (float) $product->harga_4;

        // Get new prices
        $harga1Baru = (float) $detail['harga_1_baru'];

        if ($priceMode === 'auto') {
            // Auto-calculate harga_2, 3, 4 from harga_1 based on conversion
            $konversi1 = (float) $product->konversi_1 ?: 1;
            $konversi2 = (float) $product->konversi_2 ?: 1;
            $konversi3 = (float) $product->konversi_3 ?: 1;
            $konversi4 = (float) $product->konversi_4 ?: 1;

            $pricePerBase = $harga1Baru / $konversi1;
            $harga2Baru = round($pricePerBase * $konversi2, 2);
            $harga3Baru = round($pricePerBase * $konversi3, 2);
            $harga4Baru = round($pricePerBase * $konversi4, 2);
        } else {
            // Manual mode - use provided values
            $harga2Baru = (float) ($detail['harga_2_baru'] ?? $harga2Lama);
            $harga3Baru = (float) ($detail['harga_3_baru'] ?? $harga3Lama);
            $harga4Baru = (float) ($detail['harga_4_baru'] ?? $harga4Lama);

            // Validate manual mode prices
            $validationError = $this->validateManualPrices(
                $product,
                $harga1Baru,
                $harga2Baru,
                $harga3Baru,
                $harga4Baru
            );

            if ($validationError) {
                throw ValidationException::withMessages([
                    'details' => ["{$product->nama_produk}: {$validationError}"],
                ]);
            }
        }

        // Format notes
        $detailNotes = isset($detail['notes'])
            ? SettingService::formatName($detail['notes'])
            : null;

        DocPriceChangeDetail::create([
            'price_change_id' => $priceChange->id,
            'product_id' => $detail['product_id'],
            'harga_1_lama' => $harga1Lama,
            'harga_2_lama' => $harga2Lama,
            'harga_3_lama' => $harga3Lama,
            'harga_4_lama' => $harga4Lama,
            'harga_1_baru' => $harga1Baru,
            'harga_2_baru' => $harga2Baru,
            'harga_3_baru' => $harga3Baru,
            'harga_4_baru' => $harga4Baru,
            'alasan' => $detail['alasan'],
            'notes' => $detailNotes,
        ]);
    }

    /**
     * Get IDs of products that are locked (in any draft or scheduled document).
     */
    private function getLockedProductIds(): array
    {
        return DocPriceChangeDetail::whereHas('priceChange', function ($query) {
            $query->whereIn('status', ['draft', 'scheduled']);
        })->pluck('product_id')->toArray();
    }

    /**
     * Validate manual mode prices.
     * Rules:
     * 1. Harga menurun: harga_1 > harga_2 > harga_3 > harga_4 (unless locked)
     * 2. PPU naik: Price Per Unit harus naik untuk unit lebih kecil
     * 3. Locked units: jika konversi = 1, harga harus sama dengan unit sebelumnya
     *
     * @return string|null Error message or null if valid
     */
    private function validateManualPrices(
        MasterProduk $product,
        float $harga1,
        float $harga2,
        float $harga3,
        float $harga4
    ): ?string {
        $konversi1 = (int) $product->konversi_1 ?: 1;
        $konversi2 = (int) $product->konversi_2 ?: 1;
        $konversi3 = (int) $product->konversi_3 ?: 1;
        $konversi4 = 1; // Always 1

        // Determine lock point (first konversi that = 1)
        $lockFrom = null;
        if ($konversi1 === 1) {
            $lockFrom = 1;
        } elseif ($konversi2 === 1) {
            $lockFrom = 2;
        } elseif ($konversi3 === 1) {
            $lockFrom = 3;
        }

        // Calculate PPU (Price Per Unit)
        $ppu1 = $konversi1 > 0 ? $harga1 / $konversi1 : 0;
        $ppu2 = $konversi2 > 0 ? $harga2 / $konversi2 : 0;
        $ppu3 = $konversi3 > 0 ? $harga3 / $konversi3 : 0;
        $ppu4 = $harga4; // konversi_4 = 1

        // Check harga_2 vs harga_1
        if ($harga1 > 0 && $harga2 > 0) {
            if ($lockFrom === 1) {
                // Locked from unit 1: h2 must = h1
                if (abs($harga2 - $harga1) > 0.01) {
                    return 'Harga Unit 2 harus sama dengan Harga Unit 1 (locked karena konversi = 1)';
                }
            } else {
                // Not locked: h2 must be < h1 (harga turun)
                if ($harga2 >= $harga1) {
                    return "Harga Unit 2 harus lebih kecil dari Harga Unit 1 (< " . SettingService::formatCurrency($harga1) . ")";
                }
                // Also check PPU ascending (ppu2 >= ppu1)
                if ($ppu2 < $ppu1) {
                    return "PPU Unit 2 terlalu murah (" . SettingService::formatCurrency(round($ppu2)) . "/unit < " . SettingService::formatCurrency(round($ppu1)) . "/unit)";
                }
            }
        }

        // Check harga_3 vs harga_2
        if ($harga2 > 0 && $harga3 > 0) {
            if ($lockFrom !== null && $lockFrom <= 2) {
                // Locked: h3 must = lock source
                $lockSourceHarga = $lockFrom === 1 ? $harga1 : $harga2;
                if (abs($harga3 - $lockSourceHarga) > 0.01) {
                    return "Harga Unit 3 harus sama dengan Harga Unit {$lockFrom} (locked karena konversi = 1)";
                }
            } else {
                // Not locked: h3 must be < h2 (harga turun)
                if ($harga3 >= $harga2) {
                    return "Harga Unit 3 harus lebih kecil dari Harga Unit 2 (< " . SettingService::formatCurrency($harga2) . ")";
                }
                // Also check PPU ascending (ppu3 >= ppu2)
                if ($ppu3 < $ppu2) {
                    return "PPU Unit 3 terlalu murah (" . SettingService::formatCurrency(round($ppu3)) . "/unit < " . SettingService::formatCurrency(round($ppu2)) . "/unit)";
                }
            }
        }

        // Check harga_4 vs harga_3
        if ($harga3 > 0 && $harga4 > 0) {
            if ($lockFrom !== null && $lockFrom <= 3) {
                // Locked: h4 must = lock source
                $lockSourceHarga = $lockFrom === 1 ? $harga1 : ($lockFrom === 2 ? $harga2 : $harga3);
                if (abs($harga4 - $lockSourceHarga) > 0.01) {
                    return "Harga Unit 4 harus sama dengan Harga Unit {$lockFrom} (locked karena konversi = 1)";
                }
            } else {
                // Not locked: h4 must be < h3 (harga turun)
                if ($harga4 >= $harga3) {
                    return "Harga Unit 4 harus lebih kecil dari Harga Unit 3 (< " . SettingService::formatCurrency($harga3) . ")";
                }
                // Also check PPU ascending (ppu4 >= ppu3)
                if ($ppu4 < $ppu3) {
                    return "PPU Unit 4 terlalu murah (" . SettingService::formatCurrency(round($ppu4)) . "/unit < " . SettingService::formatCurrency(round($ppu3)) . "/unit)";
                }
            }
        }

        return null; // Valid
    }
}
