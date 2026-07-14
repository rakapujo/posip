<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Setting;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Models\StockCard;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends BaseApiController
{
    /**
     * Get all settings.
     */
    public function index(): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        $settings = SettingService::all();

        // Group settings by group name
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

    /**
     * Get settings by group.
     */
    public function group(string $group): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.view')) {
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

    /**
     * Get a single setting.
     */
    public function show(string $group, string $key): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        $fullKey = "{$group}.{$key}";
        $value = SettingService::get($fullKey);

        if ($value === null) {
            // Check if key exists with null value
            $setting = Setting::where('group', $group)->where('key', $key)->first();
            if (!$setting) {
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
        $locked = $serialProducts > 0 || $serialUnits > 0;

        return compact('serialProducts', 'serialUnits', 'locked');
    }

    /**
     * Guard: Modul Elektronik tak boleh dinonaktifkan selama masih ada produk/unit serial
     * (cegah data serial yatim). Berlaku untuk SEMUA jalur tulis (update/updateGroup/bulkUpdate).
     * Return pesan error bila terkunci, atau null bila boleh.
     */
    private function elektronikLockError(string $group, string $key, mixed $value): ?string
    {
        if ($group !== 'modules' || $key !== 'elektronik_enabled') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        ['locked' => $locked, 'serialProducts' => $serialProducts, 'serialUnits' => $serialUnits] = $this->elektronikLockStatus();
        if (!$locked) {
            return null;
        }

        return "Modul Elektronik tidak dapat dinonaktifkan karena masih ada {$serialProducts} produk serial dan {$serialUnits} unit serial. Hapus/kosongkan data serial terlebih dahulu.";
    }

    /**
     * Update a single setting.
     */
    public function update(Request $request, string $group, string $key): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.update')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'value' => 'present',
            'type' => 'sometimes|in:string,integer,decimal,boolean,json',
        ]);

        $setting = Setting::where('group', $group)->where('key', $key)->first();

        if (!$setting) {
            return $this->error('Setting not found', 404);
        }

        if ($err = $this->elektronikLockError($group, $key, $request->input('value'))) {
            return $this->error($err, 422);
        }

        $setting->value = $request->input('value');

        if ($request->has('type')) {
            $setting->type = $request->input('type');
        }

        $setting->save();

        // Clear cache
        SettingService::clearCache();

        return $this->success([
            'group' => $group,
            'key' => $key,
            'value' => $setting->casted_value,
        ], 'Setting updated successfully');
    }

    /**
     * Update multiple settings in a group.
     */
    public function updateGroup(Request $request, string $group): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.update')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'present',
            'settings.*.type' => 'sometimes|in:string,integer,decimal,boolean,json',
        ]);

        // Special validation for product.price_input_mode
        if ($group === 'product') {
            foreach ($request->input('settings') as $item) {
                if ($item['key'] === 'price_input_mode') {
                    $currentMode = SettingService::getPriceInputMode();
                    $newMode = $item['value'];

                    // If trying to change and products exist, block it
                    if ($currentMode !== $newMode && MasterProduk::exists()) {
                        return $this->error(
                            'Mode input harga tidak dapat diubah karena sudah ada data produk. Hapus semua produk terlebih dahulu untuk mengubah mode.',
                            422
                        );
                    }
                }
            }
        }

        // Special validation for stock.negative_mode
        if ($group === 'stock') {
            foreach ($request->input('settings') as $item) {
                if ($item['key'] === 'negative_mode') {
                    $currentMode = SettingService::get('stock.negative_mode', 'block');
                    $newMode = $item['value'];

                    // If trying to change and stock card has records, block it
                    if ($currentMode !== $newMode && StockCard::exists()) {
                        $count = StockCard::count();
                        return $this->error(
                            "Mode stok negatif tidak dapat diubah karena sudah ada {$count} transaksi tercatat di kartu stok.",
                            422
                        );
                    }
                }
            }
        }

        // Modul Elektronik tak boleh dimatikan selama masih ada produk/unit serial.
        if ($group === 'modules') {
            foreach ($request->input('settings') as $item) {
                if ($err = $this->elektronikLockError($group, $item['key'], $item['value'])) {
                    return $this->error($err, 422);
                }
            }
        }

        $updated = [];

        foreach ($request->input('settings') as $item) {
            // Use firstOrNew to create setting if it doesn't exist
            $setting = Setting::firstOrNew([
                'group' => $group,
                'key' => $item['key'],
            ]);

            $setting->value = $item['value'];

            if (isset($item['type'])) {
                $setting->type = $item['type'];
            }

            $setting->save();
            $updated[] = $item['key'];
        }

        // Clear cache
        SettingService::clearCache();

        return $this->success([
            'group' => $group,
            'updated' => $updated,
        ], count($updated) . ' settings updated successfully');
    }

    /**
     * Bulk update settings.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.update')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'settings' => 'required|array',
        ]);

        $updated = [];

        foreach ($request->input('settings') as $fullKey => $value) {
            $parts = explode('.', $fullKey, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$group, $key] = $parts;

            if ($err = $this->elektronikLockError($group, $key, $value)) {
                return $this->error($err, 422);
            }

            $setting = Setting::where('group', $group)
                ->where('key', $key)
                ->first();

            if ($setting) {
                $setting->value = $value;
                $setting->save();
                $updated[] = $fullKey;
            }
        }

        // Clear cache
        SettingService::clearCache();

        return $this->success([
            'updated' => $updated,
        ], count($updated) . ' settings updated successfully');
    }

    /**
     * Get public settings (no auth required).
     * Returns all settings needed for frontend formatting and business logic.
     */
    public function publicSettings(): JsonResponse
    {
        return $this->success([
            // Store info
            'store' => SettingService::getStoreInfo(),
            // Formatting
            'currency' => SettingService::group('currency'),
            'regional' => SettingService::group('regional'),
            'text' => SettingService::group('text'),
            'number' => SettingService::group('number'),
            // Business logic
            'tax' => SettingService::group('tax'),
            'rounding' => SettingService::group('rounding'),
            'product' => SettingService::group('product'),
            'promo' => SettingService::group('promo'),
            'stock' => SettingService::group('stock'),
            'calculation' => SettingService::group('calculation'),
            // Toggle modul (retail selalu aktif; elektronik on/off) — dipakai FE gate menu/route
            'modules' => SettingService::group('modules'),
        ]);
    }

    /**
     * Get all available timezones grouped by region.
     *
     * Returns the full PHP DateTimeZone list with offsets, so the frontend
     * can render a searchable dropdown without hardcoding choices.
     */
    public function timezones(): JsonResponse
    {
        if (!auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        return $this->success([
            'current' => SettingService::getTimezone(),
            'offset' => SettingService::getTimezoneOffset(),
            'groups' => SettingService::getAvailableTimezones(),
        ]);
    }

    /**
     * Check if price input mode setting is locked (products exist).
     */
    public function checkPriceModeLock(): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.view')) {
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

    /**
     * Check if stock negative mode setting is locked (stock card has records).
     */
    public function checkStockModeLock(): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.view')) {
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

    /**
     * Check if elektronik module can be disabled (locked when serial data exists).
     */
    public function checkElektronikLock(): JsonResponse
    {
        if (!auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        ['locked' => $locked, 'serialProducts' => $serialProducts, 'serialUnits' => $serialUnits] = $this->elektronikLockStatus();

        return $this->success([
            'locked' => $locked,
            'enabled' => SettingService::isElektronikEnabled(),
            'serial_products' => $serialProducts,
            'serial_units' => $serialUnits,
            'message' => $locked
                ? "Modul Elektronik tidak bisa dinonaktifkan: ada {$serialProducts} produk & {$serialUnits} unit serial."
                : 'Modul Elektronik dapat diaktifkan / dinonaktifkan.',
        ]);
    }

    /**
     * Get all document prefixes with info.
     */
    public function getPrefixes(): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.view')) {
            return $this->error('Unauthorized', 403);
        }

        return $this->success([
            'prefixes' => SettingService::getPrefixesWithInfo(),
        ]);
    }

    /**
     * Update a single document prefix.
     */
    public function updatePrefix(Request $request, string $type): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('settings.update')) {
            return $this->error('Unauthorized', 403);
        }

        $request->validate([
            'prefix' => 'required|string|max:10|regex:/^[A-Za-z0-9]+$/',
        ], [
            'prefix.regex' => 'Prefix hanya boleh berisi huruf dan angka',
        ]);

        // Get current prefix info to check if locked
        $prefixes = SettingService::getPrefixesWithInfo();
        $prefixInfo = collect($prefixes)->firstWhere('type', $type);

        if (!$prefixInfo) {
            return $this->error('Tipe dokumen tidak ditemukan', 404);
        }

        if ($prefixInfo['is_locked']) {
            return $this->error(
                "Prefix tidak dapat diubah karena sudah ada {$prefixInfo['document_count']} dokumen dengan prefix ini",
                422
            );
        }

        SettingService::updatePrefix($type, $request->input('prefix'));

        return $this->success([
            'type' => $type,
            'prefix' => strtoupper(trim($request->input('prefix'))),
        ], 'Prefix berhasil diperbarui');
    }
}
