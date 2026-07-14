<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingService
{
    /**
     * Cache key for all settings.
     */
    protected const CACHE_KEY = 'app_settings';

    /**
     * Cache TTL in seconds (1 hour).
     */
    protected const CACHE_TTL = 86400;

    /**
     * Get all settings from cache or database.
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()
                ->mapWithKeys(function ($setting) {
                    return ["{$setting->group}.{$setting->key}" => $setting->casted_value];
                })
                ->toArray();
        });
    }

    /**
     * Get a single setting value.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = self::all();
        return $settings[$key] ?? $default;
    }

    /**
     * Apakah Modul Elektronik (serial) aktif.
     * Default TRUE bila baris setting belum ada (instalasi lama / lingkungan tes)
     * supaya seluruh fitur serial existing tetap berjalan tanpa kejutan.
     */
    public static function isElektronikEnabled(): bool
    {
        return (bool) self::get('modules.elektronik_enabled', true);
    }

    /**
     * Get all settings in a group.
     */
    public static function group(string $group): array
    {
        $settings = self::all();
        $result = [];

        foreach ($settings as $key => $value) {
            if (str_starts_with($key, "{$group}.")) {
                $shortKey = str_replace("{$group}.", '', $key);
                $result[$shortKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, mixed $value, ?string $type = null): void
    {
        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Invalid setting key format. Expected 'group.key'");
        }

        [$group, $settingKey] = $parts;

        $setting = Setting::firstOrNew(['group' => $group, 'key' => $settingKey]);
        $setting->value = $value;

        if ($type) {
            $setting->type = $type;
        }

        $setting->save();

        self::clearCache();
    }

    /**
     * Clear settings cache.
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // =========================================================================
    // TEXT FORMATTING
    // =========================================================================

    /**
     * Format code field (always uppercase).
     */
    public static function formatCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtoupper(trim($value));
    }

    /**
     * Format name/text field based on text.uppercase_mode setting.
     * Modes: 'all', 'none', 'code_only'
     */
    public static function formatName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $mode = self::get('text.uppercase_mode', 'none');

        return match ($mode) {
            'all' => strtoupper(trim($value)),
            default => trim($value),
        };
    }

    // =========================================================================
    // TIMEZONE HANDLING
    // =========================================================================

    /**
     * Get application timezone.
     */
    public static function getTimezone(): string
    {
        return self::get('regional.timezone', 'Asia/Jakarta');
    }

    /**
     * Get application timezone as MySQL-compatible offset (e.g. '+07:00').
     *
     * Used to keep MySQL session time_zone in sync with the PHP app timezone
     * so NOW(), CURDATE(), etc. on the database side match Carbon::now() on
     * the PHP side.
     */
    public static function getTimezoneOffset(): string
    {
        try {
            $tz = new \DateTimeZone(self::getTimezone());
            return (new \DateTime('now', $tz))->format('P');
        } catch (\Exception $e) {
            return '+07:00';
        }
    }

    /**
     * Get all available timezones grouped by region.
     *
     * Returns array of:
     *   [
     *     'region' => 'Asia',
     *     'timezones' => [
     *       ['value' => 'Asia/Jakarta', 'label' => 'WIB - Asia/Jakarta', 'offset' => '+07:00'],
     *       ...
     *     ],
     *   ]
     *
     * Indonesian zones get a friendly WIB/WITA/WIT prefix in the label.
     * The list is built from PHP's DateTimeZone::listIdentifiers() so it
     * always reflects the OS tzdata version.
     */
    public static function getAvailableTimezones(): array
    {
        $friendlyLabels = [
            'Asia/Jakarta' => 'WIB - Asia/Jakarta',
            'Asia/Pontianak' => 'WIB - Asia/Pontianak',
            'Asia/Makassar' => 'WITA - Asia/Makassar',
            'Asia/Jayapura' => 'WIT - Asia/Jayapura',
        ];

        $grouped = [];

        foreach (\DateTimeZone::listIdentifiers() as $tz) {
            try {
                $offset = (new \DateTime('now', new \DateTimeZone($tz)))->format('P');
            } catch (\Exception $e) {
                continue;
            }

            // Region = first segment ('Asia/Jakarta' -> 'Asia', 'UTC' -> 'UTC')
            $region = str_contains($tz, '/') ? explode('/', $tz)[0] : $tz;
            $label = ($friendlyLabels[$tz] ?? $tz) . ' (' . $offset . ')';

            $grouped[$region][] = [
                'value' => $tz,
                'label' => $label,
                'offset' => $offset,
            ];
        }

        // Sort each region's entries by label, then return as ordered list of regions.
        ksort($grouped);
        $result = [];
        foreach ($grouped as $region => $timezones) {
            usort($timezones, fn ($a, $b) => strcmp($a['label'], $b['label']));
            $result[] = [
                'region' => $region,
                'timezones' => $timezones,
            ];
        }

        return $result;
    }

    /**
     * Get current time in application timezone.
     */
    public static function now(): Carbon
    {
        return Carbon::now(self::getTimezone());
    }

    /**
     * Parse date with application timezone.
     */
    public static function parseDate(string $date): Carbon
    {
        return Carbon::parse($date, self::getTimezone());
    }

    // =========================================================================
    // NUMBER FORMATTING
    // =========================================================================

    /**
     * Format currency value.
     */
    public static function formatCurrency(float|int $value): string
    {
        $symbol = self::get('currency.symbol', 'Rp');
        $position = self::get('currency.position', 'before');
        $thousandSeparator = self::get('currency.thousand_separator', '.');
        $decimalSeparator = self::get('currency.decimal_separator', ',');
        $decimalPlaces = (int) self::get('currency.decimal_places', 0);

        $formatted = number_format(abs($value), $decimalPlaces, $decimalSeparator, $thousandSeparator);

        // Handle negative values
        $prefix = $value < 0 ? '-' : '';

        return match ($position) {
            'after' => "{$prefix}{$formatted} {$symbol}",
            default => "{$prefix}{$symbol} {$formatted}",
        };
    }

    /**
     * Format quantity value.
     */
    public static function formatQty(float|int $value): string
    {
        $decimalPlaces = (int) self::get('number.qty_decimal_places', 0);
        $thousandSeparator = self::get('currency.thousand_separator', '.');
        $decimalSeparator = self::get('currency.decimal_separator', ',');

        return number_format($value, $decimalPlaces, $decimalSeparator, $thousandSeparator);
    }

    /**
     * Format percentage value.
     */
    public static function formatPercent(float|int $value): string
    {
        $decimalPlaces = (int) self::get('number.percent_decimal_places', 2);
        $decimalSeparator = self::get('currency.decimal_separator', ',');

        return number_format($value, $decimalPlaces, $decimalSeparator, '') . '%';
    }

    // =========================================================================
    // TAX SETTINGS
    // =========================================================================

    /**
     * Get purchase tax settings.
     */
    public static function getPurchaseTaxSettings(): array
    {
        return [
            'name' => self::get('tax.tax_purchase_name', 'PPN'),
            'percent' => (float) self::get('tax.tax_purchase_percent', 11),
            'included_in_hpp' => (bool) self::get('tax.tax_purchase_included_in_hpp', false),
        ];
    }

    /**
     * Get sales tax settings.
     */
    public static function getSalesTaxSettings(): array
    {
        return [
            'name' => self::get('tax.tax_sales_name', 'PPN'),
            'percent' => (float) self::get('tax.tax_sales_percent', 11),
        ];
    }

    /**
     * Calculate tax amount.
     */
    public static function calculateTax(float $amount, float $percent, bool $isInclusive = false): array
    {
        if ($isInclusive) {
            $taxAmount = $amount - ($amount / (1 + ($percent / 100)));
            $baseAmount = $amount - $taxAmount;
        } else {
            $taxAmount = $amount * ($percent / 100);
            $baseAmount = $amount;
        }

        return [
            'base_amount' => round($baseAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($baseAmount + $taxAmount, 2),
        ];
    }

    // =========================================================================
    // ROUNDING
    // =========================================================================

    /**
     * Apply rounding based on settings.
     *
     * @param float $value Value to round
     * @param string $type 'purchase' or 'sales'
     */
    public static function applyRounding(float $value, string $type = 'sales'): float
    {
        $method = self::get("rounding.{$type}_method", 'none');
        $precision = (int) self::get("rounding.{$type}_precision", 0);

        if ($method === 'none' || $precision === 0) {
            return $value;
        }

        return match ($method) {
            'round' => round($value / $precision) * $precision,
            'floor' => floor($value / $precision) * $precision,
            'ceil' => ceil($value / $precision) * $precision,
            default => $value,
        };
    }

    // =========================================================================
    // DOCUMENT NUMBER
    // =========================================================================

    /**
     * Get document prefix.
     */
    public static function getPrefix(string $type): string
    {
        // Default prefix 3 karakter untuk konsistensi.
        // User bisa customize via Settings UI (table `settings` group=prefix).
        $defaults = [
            'purchase_order' => 'POR',
            'purchase_return' => 'RPB', // Retur Pembelian
            'sales' => 'INV',
            'sales_return' => 'RPJ',    // Retur Penjualan
            'payment_hutang' => 'PBH',  // Pembayaran Hutang
            'stock_opname' => 'OPN',
            'adjustment' => 'ADJ',
            'transfer' => 'TRF',
            'repack' => 'RPK',
            'price_change' => 'PCH',
            'hpp_correction' => 'HPC',
            'promo' => 'PRM',
            'serial_intake' => 'PBS', // Pembelian Serial (modul serial A+)
            'serial_change' => 'PDS', // Perubahan Data Serial (modul serial A+)
            'serial_hpp_correction' => 'HPS', // Koreksi HPP Serial (modul serial A+)
        ];

        return self::get("prefix.{$type}", $defaults[$type] ?? strtoupper($type));
    }

    /**
     * Generate document number.
     * Format: {PREFIX}-{YYMM}-{SEQUENCE:4}
     * Example: PO-2501-0001
     */
    public static function generateDocumentNumber(string $type, string $table, string $column = 'nomor_dokumen'): string
    {
        $prefix = self::getPrefix($type);
        $now = self::now();
        $yearMonth = $now->format('ym'); // 2501 for January 2025

        $pattern = "{$prefix}-{$yearMonth}-%";

        // Get the last sequence number for this month (locked to prevent race condition)
        $lastNumber = DB::table($table)
            ->where($column, 'like', $pattern)
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        if ($lastNumber) {
            // Extract sequence from last number
            $parts = explode('-', $lastNumber);
            $lastSequence = (int) end($parts);
            $newSequence = $lastSequence + 1;
        } else {
            $newSequence = 1;
        }

        return sprintf('%s-%s-%04d', $prefix, $yearMonth, $newSequence);
    }

    /**
     * Get all document prefixes with their info.
     * Returns prefix, preview, last generated document, and lock status.
     */
    public static function getPrefixesWithInfo(): array
    {
        $now = self::now();
        $yearMonth = $now->format('ym');

        // Document type configuration — all defaults 3 characters.
        $documentTypes = [
            'purchase_order' => [
                'label' => 'Purchase Order',
                'table' => 'doc_purchase_order',
                'default' => 'POR',
            ],
            'purchase_return' => [
                'label' => 'Retur Pembelian',
                'table' => 'doc_purchase_return',
                'default' => 'RPB',
            ],
            'payment_hutang' => [
                'label' => 'Pembayaran Hutang',
                'table' => 'doc_pembayaran_hutang',
                'default' => 'PBH',
            ],
            'stock_opname' => [
                'label' => 'Stock Opname',
                'table' => 'doc_stock_opname',
                'default' => 'OPN',
            ],
            'adjustment' => [
                'label' => 'Adjustment',
                'table' => 'doc_adjustment',
                'default' => 'ADJ',
            ],
            'transfer' => [
                'label' => 'Transfer',
                'table' => 'doc_transfer',
                'default' => 'TRF',
            ],
            'repack' => [
                'label' => 'Repack',
                'table' => 'doc_repack',
                'default' => 'RPK',
            ],
            'hpp_correction' => [
                'label' => 'Koreksi HPP',
                'table' => 'doc_hpp_correction',
                'default' => 'HPC',
            ],
            'sales' => [
                'label' => 'Penjualan',
                'table' => 'doc_sales',
                'default' => 'INV',
            ],
            'sales_return' => [
                'label' => 'Retur Penjualan',
                'table' => 'doc_sales_returns',
                'default' => 'RPJ',
            ],
            'price_change' => [
                'label' => 'Perubahan Harga',
                'table' => 'doc_price_change',
                'default' => 'PCH',
            ],
            'promo' => [
                'label' => 'Promo',
                'table' => 'doc_promo',
                'default' => 'PRM',
                'number_column' => 'kode_promo', // doc_promo has no `nomor_dokumen`
            ],
            'serial_intake' => [
                'label' => 'Pembelian Serial',
                'table' => 'doc_serial_intake',
                'default' => 'PBS',
            ],
            'serial_change' => [
                'label' => 'Perubahan Data Serial',
                'table' => 'doc_serial_change',
                'default' => 'PDS',
            ],
            'serial_hpp_correction' => [
                'label' => 'Koreksi HPP Serial',
                'table' => 'doc_serial_hpp_correction',
                'default' => 'HPS',
            ],
        ];

        $result = [];

        foreach ($documentTypes as $type => $config) {
            $prefix = self::getPrefix($type);
            $preview = sprintf('%s-%s-0001', $prefix, $yearMonth);

            $lastDocument = null;
            $documentCount = 0;
            $isLocked = false;

            if ($config['table']) {
                // Get document count
                $documentCount = DB::table($config['table'])->count();
                $isLocked = $documentCount > 0;

                // Get last document number (any month). Some tables use a
                // different column name (e.g. doc_promo → kode_promo).
                $numberColumn = $config['number_column'] ?? 'nomor_dokumen';
                $lastDocument = DB::table($config['table'])
                    ->orderByDesc($numberColumn)
                    ->value($numberColumn);
            }

            $result[] = [
                'type' => $type,
                'label' => $config['label'],
                'prefix' => $prefix,
                'default' => $config['default'],
                'preview' => $preview,
                'last_document' => $lastDocument,
                'document_count' => $documentCount,
                'is_locked' => $isLocked,
                'has_table' => $config['table'] !== null,
            ];
        }

        return $result;
    }

    /**
     * Update a single prefix.
     */
    public static function updatePrefix(string $type, string $prefix): bool
    {
        $key = "prefix.{$type}";
        self::set($key, strtoupper(trim($prefix)), 'string');
        return true;
    }

    // =========================================================================
    // STOCK SETTINGS
    // =========================================================================

    /**
     * Check if negative stock is allowed.
     */
    public static function isNegativeStockAllowed(): bool
    {
        return self::get('stock.negative_mode', 'block') === 'allow';
    }

    // =========================================================================
    // PROMO SETTINGS
    // =========================================================================

    /**
     * Get promo settings.
     */
    public static function getPromoSettings(): array
    {
        return [
            'enabled' => (bool) self::get('promo.enabled', true),
            'allow_manual_discount' => (bool) self::get('promo.allow_manual_discount', true),
            'max_manual_discount_percent' => (float) self::get('promo.max_manual_discount_percent', 100),
            'max_manual_discount_nominal' => self::get('promo.max_manual_discount_nominal'),
            // Auto-apply promo modul (baru)
            'auto_apply' => (bool) self::get('promo.auto_apply', true),
            'show_label' => (bool) self::get('promo.show_label', true),
        ];
    }

    // =========================================================================
    // PRODUCT SETTINGS
    // =========================================================================

    /**
     * Get product price input mode.
     * 'auto' - Input from unit_1, others calculated
     * 'manual' - All prices input manually
     */
    public static function getPriceInputMode(): string
    {
        return self::get('product.price_input_mode', 'auto');
    }

    // =========================================================================
    // CALCULATION SETTINGS
    // =========================================================================

    /**
     * Get discount calculation mode.
     * 'sum' - Sum all discounts
     * 'recursive' - Apply discounts recursively
     */
    public static function getDiscountMode(): string
    {
        return self::get('calculation.discount_mode', 'recursive');
    }

    // =========================================================================
    // SCHEDULER SETTINGS
    // =========================================================================

    /**
     * Check if a scheduler is enabled.
     *
     * @param string $type Scheduler type (e.g., 'price_change')
     */
    public static function isSchedulerEnabled(string $type): bool
    {
        return (bool) self::get("scheduler.{$type}_enabled", true);
    }

    /**
     * Get scheduler cooldown in minutes.
     *
     * @param string $type Scheduler type (e.g., 'price_change')
     */
    public static function getSchedulerCooldown(string $type): int
    {
        return (int) self::get("scheduler.{$type}_cooldown", 5);
    }

    /**
     * Get scheduler max documents per batch.
     *
     * @param string $type Scheduler type (e.g., 'price_change')
     */
    public static function getSchedulerMaxBatch(string $type): int
    {
        return (int) self::get("scheduler.{$type}_max_batch", 50);
    }

    // =========================================================================
    // STORE INFO
    // =========================================================================

    /**
     * Get store information.
     */
    public static function getStoreInfo(): array
    {
        return [
            'name' => self::get('store.name', 'POSIP Store'),
            'address' => self::get('store.address', ''),
            'phone' => self::get('store.phone', ''),
            'email' => self::get('store.email', ''),
            'npwp' => self::get('store.npwp', ''),
            'url' => self::get('store.url', ''),
            'receipt_footer' => self::get('store.receipt_footer', 'Terima Kasih!'),
            'logo_url' => self::getLogoUrl(),
            'icon_url' => self::getIconUrl(),
            'login_background_url' => self::getLoginBackgroundUrl(),
        ];
    }

    /**
     * Get store logo full URL.
     */
    public static function getLogoUrl(): ?string
    {
        $logo = self::get('store.logo');
        if (!$logo) {
            return null;
        }

        // If already a full URL, return as-is
        if (filter_var($logo, FILTER_VALIDATE_URL)) {
            return $logo;
        }

        // Otherwise, build the URL
        return asset('storage/' . $logo);
    }

    /**
     * Get store icon full URL.
     */
    public static function getIconUrl(): ?string
    {
        $icon = self::get('store.icon');
        if (!$icon) {
            return null;
        }

        // If already a full URL, return as-is
        if (filter_var($icon, FILTER_VALIDATE_URL)) {
            return $icon;
        }

        // Otherwise, build the URL
        return asset('storage/' . $icon);
    }

    /**
     * Get login background full URL.
     */
    public static function getLoginBackgroundUrl(): ?string
    {
        $bg = self::get('store.login_background');
        if (!$bg) {
            return null;
        }

        // If already a full URL, return as-is
        if (filter_var($bg, FILTER_VALIDATE_URL)) {
            return $bg;
        }

        // Otherwise, build the URL
        return asset('storage/' . $bg);
    }
}
