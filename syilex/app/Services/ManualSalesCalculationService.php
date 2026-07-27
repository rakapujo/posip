<?php

namespace App\Services;

use App\Constants\PromoConstants;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;

class ManualSalesCalculationService
{
    /**
     * Strip client disc 1–4; keep disc 5 + manual header disc (slot 3) for persist/approve paths.
     */
    public static function prepareForPersist(array $data): array
    {
        $data['details'] = array_map(function (array $detail) {
            for ($slot = 1; $slot <= 4; $slot++) {
                unset($detail["diskon_{$slot}_tipe"], $detail["diskon_{$slot}_nilai"]);
            }

            return $detail;
        }, $data['details']);
        $data['discounts'] = [
            ['tipe' => 'none', 'nilai' => 0],
            ['tipe' => 'none', 'nilai' => 0],
            $data['discounts'][2] ?? ['tipe' => 'none', 'nilai' => 0],
        ];

        return $data;
    }

    public static function calculate(array $data, bool $rebuildPromos = false): array
    {
        $products = MasterProduk::whereIn('id', array_column($data['details'], 'product_id'))->get()->keyBy('id');
        $customer = MasterCustomer::with(['tipeCustomer', 'kategoriCustomer'])->find($data['customer_id']);
        $discountMode = SettingService::getDiscountMode();
        $promos = $rebuildPromos
            ? PromoService::getActivePromos(
                null,
                $customer?->tipe_customer_id,
                customerCategoryId: $customer?->kategori_customer_id,
                channel: 'penjualan',
            )
            : collect();
        $details = [];

        foreach ($data['details'] as $index => $item) {
            $product = $products[$item['product_id']];
            $item['konversi'] = PurchaseMasterRules::resolveUnitKonversi(
                $product,
                (string) $item['unit'],
                "details.{$index}.unit",
            );
            $item = self::clampLineDisc5($item);
            $qtyBase = (float) $item['qty'] * (int) $item['konversi'];
            if ($qtyBase !== floor($qtyBase)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'details' => ['Qty base harus berupa bilangan bulat.'],
                ]);
            }

            if ($rebuildPromos) {
                $promo = PromoService::findBestPromo(
                    $product->id,
                    $product->grup_id,
                    $product->kategori_id,
                    (float) $item['qty'],
                    (float) $item['harga_satuan'],
                    $promos,
                    $discountMode,
                );
                if ($promo) {
                    for ($slot = 1; $slot <= PromoConstants::DB_DISCOUNT_SLOTS; $slot++) {
                        $item["diskon_{$slot}_tipe"] = $promo["diskon_{$slot}_tipe"] ?? 'none';
                        $item["diskon_{$slot}_nilai"] = $promo["diskon_{$slot}_nilai"] ?? 0;
                    }
                    $item['promo_id'] = $promo['promo_id'] ?? null;
                    $item['nama_promo'] = $promo['nama_promo'] ?? null;
                } else {
                    for ($slot = 1; $slot <= PromoConstants::DB_DISCOUNT_SLOTS; $slot++) {
                        $item["diskon_{$slot}_tipe"] = 'none';
                        $item["diskon_{$slot}_nilai"] = 0;
                    }
                    $item['promo_id'] = null;
                    $item['nama_promo'] = null;
                }
            }

            $details[] = array_merge(
                SalesCalculationService::applyLineDiscounts($item, $discountMode),
                ['qty_base' => (int) $qtyBase],
            );
        }

        $discounts = self::headerDiscounts($customer, $data);
        $totals = SalesCalculationService::calculateTotals(
            array_sum(array_column($details, 'jumlah')),
            $discounts['values'],
            $data['biaya_kirim'] ?? [],
            $data['biaya_lain'] ?? [],
        );

        return ['details' => $details, 'totals' => $totals, 'labels' => $discounts['labels']];
    }

    private static function clampLineDisc5(array $item): array
    {
        $settings = SettingService::getPromoSettings();
        $tipe = $item['diskon_5_tipe'] ?? 'none';
        $nilai = (float) ($item['diskon_5_nilai'] ?? 0);

        if (! $settings['enabled'] || ! $settings['allow_manual_discount']) {
            $item['diskon_5_tipe'] = 'none';
            $item['diskon_5_nilai'] = 0;
        } elseif ($tipe === 'percent') {
            $item['diskon_5_nilai'] = min($nilai, (float) $settings['max_manual_discount_percent'], 100);
        } elseif ($tipe === 'nominal' && $settings['max_manual_discount_nominal']) {
            $item['diskon_5_nilai'] = min($nilai, (float) $settings['max_manual_discount_nominal']);
        }

        return $item;
    }

    private static function headerDiscounts(?MasterCustomer $customer, array $data): array
    {
        $values = [
            ['tipe' => 'none', 'nilai' => 0],
            ['tipe' => 'none', 'nilai' => 0],
            $data['discounts'][2] ?? ['tipe' => 'none', 'nilai' => 0],
        ];
        $labels = [null, null, null];
        foreach ([[$customer?->tipeCustomer, 'kode_tipe'], [$customer?->kategoriCustomer, 'kode_kategori']] as $index => [$master, $code]) {
            if ($master && $master->isActive() && $master->diskon_tipe !== 'none' && (float) $master->diskon_nilai > 0) {
                $values[$index] = ['tipe' => $master->diskon_tipe, 'nilai' => (float) $master->diskon_nilai];
                $labels[$index] = (string) $master->{$code};
            }
        }

        $manual = &$values[2];
        $settings = SettingService::getPromoSettings();
        if (! $settings['enabled'] || ! $settings['allow_manual_discount']) {
            $manual = ['tipe' => 'none', 'nilai' => 0];
        } elseif ($manual['tipe'] === 'percent') {
            $manual['nilai'] = min((float) $manual['nilai'], (float) $settings['max_manual_discount_percent'], 100);
        } elseif ($manual['tipe'] === 'nominal' && $settings['max_manual_discount_nominal']) {
            $manual['nilai'] = min((float) $manual['nilai'], (float) $settings['max_manual_discount_nominal']);
        }
        $labels[2] = $manual['tipe'] !== 'none' ? 'Disc Manual' : null;

        return compact('values', 'labels');
    }
}
