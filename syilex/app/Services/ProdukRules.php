<?php

namespace App\Services;

use App\Models\MasterGrup;
use App\Models\MasterKategori;
use Illuminate\Support\Facades\DB;

class ProdukRules
{
    public static function inactiveKategoriBlockMessage(?int $kategoriId): ?string
    {
        if (! $kategoriId) {
            return null;
        }

        $kategori = MasterKategori::find($kategoriId);
        if ($kategori && ! $kategori->isActive()) {
            return 'Kategori Produk tidak aktif';
        }

        return null;
    }

    public static function inactiveGrupBlockMessage(?int $grupId): ?string
    {
        if (! $grupId) {
            return null;
        }

        $grup = MasterGrup::find($grupId);
        if ($grup && ! $grup->isActive()) {
            return 'Grup Produk tidak aktif';
        }

        return null;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public static function masterReferenceErrors(?int $kategoriId, ?int $grupId): ?array
    {
        $errors = [];

        if ($message = self::inactiveKategoriBlockMessage($kategoriId)) {
            $errors['kategori_id'] = [$message];
        }

        if ($message = self::inactiveGrupBlockMessage($grupId)) {
            $errors['grup_id'] = [$message];
        }

        return $errors !== [] ? $errors : null;
    }

    /**
     * Validate units and prices according to business rules.
     *
     * @return true|string True if valid, error message otherwise
     */
    public static function validateUnitsAndPrices(array $data): bool|string
    {
        $konversi_1 = (int) $data['konversi_1'];
        $konversi_2 = (int) $data['konversi_2'];
        $konversi_3 = (int) $data['konversi_3'];
        $konversi_4 = (int) $data['konversi_4']; // Always 1

        if ($konversi_1 < $konversi_2) {
            return 'Konversi Unit 1 harus lebih besar dari Konversi Unit 2';
        }
        if ($konversi_1 === $konversi_2 && $konversi_1 > 1) {
            return 'Konversi Unit 1 dan Unit 2 tidak boleh sama (kecuali = 1)';
        }

        if ($konversi_2 < $konversi_3) {
            return 'Konversi Unit 2 harus lebih besar dari Konversi Unit 3';
        }
        if ($konversi_2 === $konversi_3 && $konversi_2 > 1) {
            return 'Konversi Unit 2 dan Unit 3 tidak boleh sama (kecuali = 1)';
        }

        if ($konversi_3 < $konversi_4) {
            return 'Konversi Unit 3 harus lebih besar atau sama dengan Konversi Unit 4';
        }

        $priceMode = SettingService::getPriceInputMode();

        $units = [
            1 => strtoupper(trim((string) ($data['unit_1'] ?? ''))),
            2 => strtoupper(trim((string) ($data['unit_2'] ?? ''))),
            3 => strtoupper(trim((string) ($data['unit_3'] ?? ''))),
            4 => strtoupper(trim((string) ($data['unit_4'] ?? ''))),
        ];

        $lockFrom = null;
        if ($konversi_1 === 1) {
            $lockFrom = 1;
        } elseif ($konversi_2 === 1) {
            $lockFrom = 2;
        } elseif ($konversi_3 === 1) {
            $lockFrom = 3;
        }

        $harga_1 = (float) ($data['harga_1'] ?? 0);
        $harga_2 = (float) ($data['harga_2'] ?? 0);
        $harga_3 = (float) ($data['harga_3'] ?? 0);
        $harga_4 = (float) ($data['harga_4'] ?? 0);

        if ($priceMode === 'manual') {
            $ppu_1 = $konversi_1 > 0 ? $harga_1 / $konversi_1 : 0;
            $ppu_2 = $konversi_2 > 0 ? $harga_2 / $konversi_2 : 0;
            $ppu_3 = $konversi_3 > 0 ? $harga_3 / $konversi_3 : 0;
            $ppu_4 = $harga_4;

            if ($harga_1 > 0 && $harga_2 > 0) {
                if ($lockFrom === 1) {
                    if (abs($harga_2 - $harga_1) > 0.01) {
                        return 'Harga Unit 2 harus sama dengan Harga Unit 1 (locked)';
                    }
                } else {
                    if ($harga_2 >= $harga_1) {
                        $formatted = SettingService::formatCurrency($harga_1);

                        return "Harga Unit 2 harus lebih kecil dari Harga Unit 1 (< {$formatted})";
                    }
                    if ($ppu_2 < $ppu_1) {
                        $ppuFormatted1 = SettingService::formatCurrency(round($ppu_1));
                        $ppuFormatted2 = SettingService::formatCurrency(round($ppu_2));

                        return "PPU Unit 2 terlalu murah ({$ppuFormatted2}/unit < {$ppuFormatted1}/unit)";
                    }
                }
            }

            if ($harga_2 > 0 && $harga_3 > 0) {
                if ($lockFrom !== null && $lockFrom <= 2) {
                    $lockSourceHarga = $lockFrom === 1 ? $harga_1 : $harga_2;
                    if (abs($harga_3 - $lockSourceHarga) > 0.01) {
                        return "Harga Unit 3 harus sama dengan Harga Unit {$lockFrom} (locked)";
                    }
                } else {
                    if ($harga_3 >= $harga_2) {
                        $formatted = SettingService::formatCurrency($harga_2);

                        return "Harga Unit 3 harus lebih kecil dari Harga Unit 2 (< {$formatted})";
                    }
                    if ($ppu_3 < $ppu_2) {
                        $ppuFormatted2 = SettingService::formatCurrency(round($ppu_2));
                        $ppuFormatted3 = SettingService::formatCurrency(round($ppu_3));

                        return "PPU Unit 3 terlalu murah ({$ppuFormatted3}/unit < {$ppuFormatted2}/unit)";
                    }
                }
            }

            if ($harga_3 > 0 && $harga_4 > 0) {
                if ($lockFrom !== null && $lockFrom <= 3) {
                    $lockSourceHarga = $lockFrom === 1 ? $harga_1 : ($lockFrom === 2 ? $harga_2 : $harga_3);
                    if (abs($harga_4 - $lockSourceHarga) > 0.01) {
                        return "Harga Unit 4 harus sama dengan Harga Unit {$lockFrom} (locked)";
                    }
                } else {
                    if ($harga_4 >= $harga_3) {
                        $formatted = SettingService::formatCurrency($harga_3);

                        return "Harga Unit 4 harus lebih kecil dari Harga Unit 3 (< {$formatted})";
                    }
                    if ($ppu_4 < $ppu_3) {
                        $ppuFormatted3 = SettingService::formatCurrency(round($ppu_3));
                        $ppuFormatted4 = SettingService::formatCurrency(round($ppu_4));

                        return "PPU Unit 4 terlalu murah ({$ppuFormatted4}/unit < {$ppuFormatted3}/unit)";
                    }
                }
            }
        }

        if ($lockFrom !== null) {
            $sourceUnit = $units[$lockFrom];

            for ($i = $lockFrom + 1; $i <= 4; $i++) {
                if ($units[$i] !== $sourceUnit) {
                    return "Unit {$i} harus sama dengan Unit {$lockFrom} ({$sourceUnit}) karena Konversi = 1";
                }
                $currentKonversi = (int) $data["konversi_{$i}"];
                if ($currentKonversi !== 1) {
                    return "Konversi Unit {$i} harus = 1 karena mengikuti Unit {$lockFrom}";
                }
            }
        }

        $checkedUnits = [];
        $unlockLimit = $lockFrom ?? 4;

        for ($i = 1; $i <= $unlockLimit; $i++) {
            $unitName = $units[$i];
            foreach ($checkedUnits as $prevIndex => $prevName) {
                if ($unitName === $prevName) {
                    return "Unit {$i} ({$unitName}) tidak boleh sama dengan Unit {$prevIndex} kecuali melalui mekanisme auto-lock (konversi = 1)";
                }
            }
            $checkedUnits[$i] = $unitName;
        }

        return true;
    }

    /**
     * Calculate prices based on harga_1 (AUTO mode).
     * harga_n = (harga_1 / konversi_1) * konversi_n
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function calculateAutoPrices(array $data): array
    {
        $harga_1 = (float) $data['harga_1'];
        $konversi_1 = (int) $data['konversi_1'];

        if ($konversi_1 > 0) {
            $basePrice = $harga_1 / $konversi_1;
            $data['harga_2'] = round($basePrice * (int) $data['konversi_2'], 2);
            $data['harga_3'] = round($basePrice * (int) $data['konversi_3'], 2);
            $data['harga_4'] = round($basePrice * 1, 2);
        }

        return $data;
    }

    public static function barcodeDuplicateMessage(?string $barcode, ?int $excludeProductId = null): ?string
    {
        if ($barcode === null || $barcode === '') {
            return null;
        }

        $q = DB::table('master_produk')->where('barcode', $barcode)->whereNull('deleted_at');
        if ($excludeProductId) {
            $q->where('id', '!=', $excludeProductId);
        }

        return $q->exists() ? "Barcode '{$barcode}' sudah dipakai produk lain" : null;
    }
}
