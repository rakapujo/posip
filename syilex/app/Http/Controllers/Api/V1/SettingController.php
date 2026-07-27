<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocSerialChange;
use App\Models\DocSerialHppCorrection;
use App\Models\DocSerialIntake;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Models\Setting;
use App\Models\StockCard;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingController extends BaseApiController
{
    /** @var array<string, list<string>> */
    private const ENUMS = [
        'stock.negative_mode' => ['block', 'allow'],
        'product.price_input_mode' => ['auto', 'manual'],
        'calculation.discount_mode' => ['sum', 'recursive'],
        'text.uppercase_mode' => ['all', 'none', 'code_only'],
        'rounding.purchase_method' => ['none', 'round', 'floor', 'ceil'],
        'rounding.sales_method' => ['none', 'round', 'floor', 'ceil'],
        'currency.position' => ['before', 'after'],
        'regional.date_format' => ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'],
        'regional.time_format' => ['HH:mm', 'hh:mm A'],
    ];

    public function index(): JsonResponse
    {
        if (! auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        $settings = SettingService::all();

        $grouped = [];
        foreach ($settings as $key => $value) {
            $parts = explode('.', $key, 2);
            if (count($parts) === 2) {
                $grouped[$parts[0]][$parts[1]] = $value;
            }
        }

        return $this->success([
            'settings' => $grouped,
        ]);
    }

    public function group(string $group): JsonResponse
    {
        if (! auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        $settings = SettingService::group($group);

        if (empty($settings)) {
            return $this->error('Group not found', 404);
        }

        return $this->success([
            'group' => $group,
            'settings' => $settings,
        ]);
    }

    public function show(string $group, string $key): JsonResponse
    {
        if (! auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        $fullKey = "{$group}.{$key}";
        $value = SettingService::get($fullKey);

        if ($value === null) {
            $setting = Setting::where('group', $group)->where('key', $key)->first();
            if (! $setting) {
                return $this->error('Setting not found', 404);
            }
        }

        return $this->success([
            'group' => $group,
            'key' => $key,
            'value' => $value,
        ]);
    }

    private function elektronikLockStatus(): array
    {
        $serialProducts = MasterProduk::where('is_serial', true)->count();
        $serialUnits = SerialUnit::count();
        $serialIntakes = DocSerialIntake::count();
        $serialChangeDrafts = DocSerialChange::draft()->count();
        $serialHppCorrectionDrafts = DocSerialHppCorrection::draft()->count();
        $locked = $serialProducts > 0 || $serialUnits > 0 || $serialIntakes > 0
            || $serialChangeDrafts > 0 || $serialHppCorrectionDrafts > 0;

        return compact(
            'serialProducts',
            'serialUnits',
            'serialIntakes',
            'serialChangeDrafts',
            'serialHppCorrectionDrafts',
            'locked'
        );
    }

    private function elektronikLockError(string $group, string $key, mixed $value): ?string
    {
        if ($group !== 'modules' || $key !== 'elektronik_enabled') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        [
            'locked' => $locked,
            'serialProducts' => $serialProducts,
            'serialUnits' => $serialUnits,
            'serialIntakes' => $serialIntakes,
            'serialChangeDrafts' => $serialChangeDrafts,
            'serialHppCorrectionDrafts' => $serialHppCorrectionDrafts,
        ] = $this->elektronikLockStatus();
        if (! $locked) {
            return null;
        }

        return "Modul Elektronik tidak dapat dinonaktifkan karena masih ada {$serialProducts} produk serial, {$serialUnits} unit serial, {$serialIntakes} dokumen pembelian serial, {$serialChangeDrafts} draft perubahan data serial, dan {$serialHppCorrectionDrafts} draft koreksi HPP serial. Hapus/kosongkan/selesaikan data serial terlebih dahulu.";
    }

    private function modeLockError(string $group, string $key, mixed $newValue): ?string
    {
        if ($group === 'product' && $key === 'price_input_mode') {
            $currentMode = SettingService::getPriceInputMode();
            if ($currentMode !== $newValue && MasterProduk::exists()) {
                return 'Mode input harga tidak dapat diubah karena sudah ada data produk. Hapus semua produk terlebih dahulu untuk mengubah mode.';
            }
        }

        if ($group === 'stock' && $key === 'negative_mode') {
            $currentMode = SettingService::get('stock.negative_mode', 'block');
            if ($currentMode !== $newValue && StockCard::exists()) {
                $count = StockCard::count();

                return "Mode stok negatif tidak dapat diubah karena sudah ada {$count} transaksi tercatat di kartu stok.";
            }
        }

        return null;
    }

    /**
     * Validate value against allowlist enums / ranges. Returns error message or null.
     *
     * @param  array<string, mixed>  $pending  group.key => value (for cross-field checks)
     */
    private function schemaError(string $group, string $key, mixed $value, array $pending = []): ?string
    {
        $full = "{$group}.{$key}";

        if (isset(self::ENUMS[$full]) && ! in_array((string) $value, self::ENUMS[$full], true)) {
            return "Nilai tidak valid untuk {$full}";
        }

        if (in_array($full, ['tax.tax_purchase_percent', 'tax.tax_sales_percent'], true)) {
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                return 'Persentase pajak harus antara 0 dan 100';
            }
        }

        if ($full === 'regional.timezone') {
            if (! in_array((string) $value, \DateTimeZone::listIdentifiers(), true)) {
                return 'Timezone tidak valid';
            }
        }

        if ($group === 'currency' && in_array($key, ['thousand_separator', 'decimal_separator'], true)) {
            $thousand = $pending['currency.thousand_separator']
                ?? ($key === 'thousand_separator' ? $value : SettingService::get('currency.thousand_separator', '.'));
            $decimal = $pending['currency.decimal_separator']
                ?? ($key === 'decimal_separator' ? $value : SettingService::get('currency.decimal_separator', ','));
            if ((string) $thousand === (string) $decimal) {
                return 'Pemisah ribuan dan desimal tidak boleh sama';
            }
        }

        if ($full === 'scheduler.activity_log_retention_days') {
            if (! is_numeric($value) || (int) $value < 30 || (int) $value > 3650) {
                return 'Retensi activity log harus antara 30 dan 3650 hari';
            }
        }

        if ($full === 'scheduler.price_change_max_batch') {
            if (! is_numeric($value) || (int) $value < 1 || (int) $value > 500) {
                return 'Max batch price change harus antara 1 dan 500';
            }
        }

        return null;
    }

    private function writeGuardError(string $group, string $key, mixed $value, array $pending = []): ?string
    {
        return $this->elektronikLockError($group, $key, $value)
            ?? $this->modeLockError($group, $key, $value)
            ?? $this->schemaError($group, $key, $value, $pending);
    }

    public function update(Request $request, string $group, string $key): JsonResponse
    {
        if (! auth()->user()->can('settings.update')) {
            return $this->error('Unauthorized', 403);
        }

        // Client type ignored — keeps boolean cast semantics (GS-BE-3)
        $request->validate([
            'value' => 'present',
        ]);

        $setting = Setting::where('group', $group)->where('key', $key)->first();

        if (! $setting) {
            return $this->error('Setting not found', 404);
        }

        $value = $request->input('value');
        if ($err = $this->writeGuardError($group, $key, $value)) {
            return $this->error($err, 422);
        }

        $old = $setting->casted_value;
        $setting->value = $value;
        $setting->save();

        SettingService::clearCache();

        activity('Settings')
            ->causedBy(auth()->user())
            ->withProperties([
                'group' => $group,
                'key' => $key,
                'old' => $old,
                'new' => $setting->fresh()->casted_value,
            ])
            ->log('Setting diubah');

        return $this->success([
            'group' => $group,
            'key' => $key,
            'value' => $setting->casted_value,
        ], 'Setting updated successfully');
    }

    public function updateGroup(Request $request, string $group): JsonResponse
    {
        if (! auth()->user()->can('settings.update')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'present',
        ]);

        $items = $request->input('settings');
        $pending = [];
        foreach ($items as $item) {
            $pending["{$group}.{$item['key']}"] = $item['value'];
        }

        foreach ($items as $item) {
            if ($err = $this->writeGuardError($group, $item['key'], $item['value'], $pending)) {
                return $this->error($err, 422);
            }
        }

        $changes = [];

        try {
            DB::transaction(function () use ($group, $items, &$changes) {
                foreach ($items as $item) {
                    $setting = Setting::where('group', $group)->where('key', $item['key'])->first();
                    if (! $setting) {
                        throw new \RuntimeException("Setting tidak ditemukan: {$group}.{$item['key']}");
                    }

                    $old = $setting->casted_value;
                    $setting->value = $item['value'];
                    $setting->save();
                    $changes[] = [
                        'key' => $item['key'],
                        'old' => $old,
                        'new' => $setting->casted_value,
                    ];
                }
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        SettingService::clearCache();

        activity('Settings')
            ->causedBy(auth()->user())
            ->withProperties(['group' => $group, 'changes' => $changes])
            ->log('Settings group diubah');

        return $this->success([
            'group' => $group,
            'updated' => array_column($changes, 'key'),
        ], count($changes).' settings updated successfully');
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        if (! auth()->user()->can('settings.update')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'settings' => 'required|array',
        ]);

        $payload = $request->input('settings');
        $pending = [];
        foreach ($payload as $fullKey => $value) {
            if (is_string($fullKey) && str_contains($fullKey, '.')) {
                $pending[$fullKey] = $value;
            }
        }

        foreach ($pending as $fullKey => $value) {
            [$group, $key] = explode('.', $fullKey, 2);
            if ($err = $this->writeGuardError($group, $key, $value, $pending)) {
                return $this->error($err, 422);
            }
        }

        $changes = [];

        DB::transaction(function () use ($pending, &$changes) {
            foreach ($pending as $fullKey => $value) {
                [$group, $key] = explode('.', $fullKey, 2);
                $setting = Setting::where('group', $group)->where('key', $key)->first();
                if (! $setting) {
                    continue;
                }

                $old = $setting->casted_value;
                $setting->value = $value;
                $setting->save();
                $changes[] = [
                    'key' => $fullKey,
                    'old' => $old,
                    'new' => $setting->casted_value,
                ];
            }
        });

        SettingService::clearCache();

        activity('Settings')
            ->causedBy(auth()->user())
            ->withProperties(['changes' => $changes])
            ->log('Settings bulk diubah');

        return $this->success([
            'updated' => array_column($changes, 'key'),
        ], count($changes).' settings updated successfully');
    }

    public function publicSettings(): JsonResponse
    {
        $store = SettingService::getStoreInfo();
        unset($store['npwp']); // privacy: NPWP only via auth paths / public receipt

        return $this->success([
            'store' => $store,
            'currency' => SettingService::group('currency'),
            'regional' => SettingService::group('regional'),
            'text' => SettingService::group('text'),
            'number' => SettingService::group('number'),
            'modules' => SettingService::group('modules'),
        ]);
    }

    public function runtimeSettings(): JsonResponse
    {
        return $this->success([
            'tax' => SettingService::group('tax'),
            'rounding' => SettingService::group('rounding'),
            'product' => SettingService::group('product'),
            'promo' => SettingService::group('promo'),
            'stock' => SettingService::group('stock'),
            'calculation' => SettingService::group('calculation'),
            'returns' => SettingService::group('returns'),
        ]);
    }

    public function timezones(): JsonResponse
    {
        if (! auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        return $this->success([
            'current' => SettingService::getTimezone(),
            'offset' => SettingService::getTimezoneOffset(),
            'groups' => SettingService::getAvailableTimezones(),
        ]);
    }

    public function checkPriceModeLock(): JsonResponse
    {
        if (! auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        $productsExist = MasterProduk::exists();
        $productCount = $productsExist ? MasterProduk::count() : 0;

        return $this->success([
            'locked' => $productsExist,
            'product_count' => $productCount,
            'message' => $productsExist
                ? "Mode input harga terkunci karena sudah ada {$productCount} produk."
                : 'Mode input harga dapat diubah.',
        ]);
    }

    public function checkStockModeLock(): JsonResponse
    {
        if (! auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        $stockCardExists = StockCard::exists();
        $stockCardCount = $stockCardExists ? StockCard::count() : 0;

        return $this->success([
            'locked' => $stockCardExists,
            'stock_card_count' => $stockCardCount,
            'message' => $stockCardExists
                ? "Mode stok negatif terkunci karena sudah ada {$stockCardCount} transaksi di kartu stok."
                : 'Mode stok negatif dapat diubah.',
        ]);
    }

    public function checkElektronikLock(): JsonResponse
    {
        if (! auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        [
            'locked' => $locked,
            'serialProducts' => $serialProducts,
            'serialUnits' => $serialUnits,
            'serialIntakes' => $serialIntakes,
            'serialChangeDrafts' => $serialChangeDrafts,
            'serialHppCorrectionDrafts' => $serialHppCorrectionDrafts,
        ] = $this->elektronikLockStatus();

        return $this->success([
            'locked' => $locked,
            'enabled' => SettingService::isElektronikEnabled(),
            'serial_products' => $serialProducts,
            'serial_units' => $serialUnits,
            'serial_intakes' => $serialIntakes,
            'serial_change_drafts' => $serialChangeDrafts,
            'serial_hpp_correction_drafts' => $serialHppCorrectionDrafts,
            'message' => $locked
                ? "Modul Elektronik tidak bisa dinonaktifkan: ada {$serialProducts} produk, {$serialUnits} unit serial, {$serialIntakes} dokumen pembelian serial, {$serialChangeDrafts} draft perubahan data serial, dan {$serialHppCorrectionDrafts} draft koreksi HPP serial."
                : 'Modul Elektronik dapat diaktifkan / dinonaktifkan.',
        ]);
    }

    public function getPrefixes(): JsonResponse
    {
        if (! auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        return $this->success([
            'prefixes' => SettingService::getPrefixesWithInfo(),
        ]);
    }

    public function updatePrefix(Request $request, string $type): JsonResponse
    {
        if (! auth()->user()->can('settings.update')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'prefix' => 'required|string|size:3|regex:/^[A-Za-z0-9]+$/',
        ], [
            'prefix.regex' => 'Prefix hanya boleh berisi huruf dan angka',
        ]);

        $prefixes = SettingService::getPrefixesWithInfo();
        $prefixInfo = collect($prefixes)->firstWhere('type', $type);

        if (! $prefixInfo) {
            return $this->error('Tipe dokumen tidak ditemukan', 404);
        }

        if ($prefixInfo['is_locked']) {
            return $this->error(
                "Prefix tidak dapat diubah karena sudah ada {$prefixInfo['document_count']} dokumen dengan prefix ini",
                422
            );
        }

        $old = $prefixInfo['prefix'];
        $new = strtoupper(trim($request->input('prefix')));
        SettingService::updatePrefix($type, $new);

        activity('Settings')
            ->causedBy(auth()->user())
            ->withProperties(['type' => $type, 'old' => $old, 'new' => $new])
            ->log('Prefix dokumen diubah');

        return $this->success([
            'type' => $type,
            'prefix' => $new,
        ], 'Prefix berhasil diperbarui');
    }
}
