<?php

/**
 * Jalankan wizard instalasi POSIP via session (sama alur /install step 1–8 + run).
 *
 * Usage: php scripts/run-fresh-install.php
 */

declare(strict_types=1);

$installedLock = __DIR__.'/../storage/installed';
if (is_file($installedLock)) {
    unlink($installedLock);
    echo "Removed storage/installed\n";
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
echo "Database migrated (fresh, empty)\n";

Illuminate\Support\Facades\Artisan::call('optimize:clear');
echo "Cache cleared\n";

session()->start();

session([
    'installer.db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'posip_db'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
    ],
    'installer.store' => [
        'name' => 'Toko Demo POSIP',
        'address' => 'Jl. Raya Contoh No. 1, Jakarta',
        'phone' => '021-5550100',
        'email' => 'toko@posip.local',
        'npwp' => '',
    ],
    'installer.regional' => [
        'timezone' => 'Asia/Jakarta',
        'date_format' => 'DD/MM/YYYY',
        'time_format' => 'HH:mm',
        'currency_code' => 'IDR',
        'currency_symbol' => 'Rp',
        'currency_position' => 'before',
        'thousand_separator' => '.',
        'decimal_separator' => ',',
        'decimal_places' => '0',
        'qty_decimal_places' => '0',
    ],
    'installer.tax' => [
        'tax_purchase_name' => 'PPN',
        'tax_purchase_percent' => '11',
        'tax_purchase_included_in_hpp' => false,
        'tax_sales_name' => 'PPN',
        'tax_sales_percent' => '11',
        'rounding_sales_method' => 'round',
        'rounding_sales_precision' => '100',
        'negative_mode' => 'block',
        'discount_mode' => 'recursive',
        'cost_allocation_mode' => 'by_value',
        'price_input_mode' => 'auto',
        'elektronik_enabled' => true,
    ],
    'installer.promo' => [
        'enabled' => true,
        'allow_manual_discount' => true,
        'max_manual_discount_percent' => '100',
        'max_manual_discount_nominal' => '',
    ],
    'installer.admin' => [
        'name' => 'Super Admin',
        'email' => 'admin@posip.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ],
    'installer.data' => [
        'seed_mode' => 'demo',
        'create_terminal' => true,
        'terminal_kode' => 'KASIR_1',
        'terminal_nama' => 'Kasir Utama',
        'terminal_izinkan_retur' => true,
        'terminal_durasi_retur' => '',
    ],
]);

$controller = app(App\Http\Controllers\InstallerController::class);
$response = $controller->runInstall(Illuminate\Http\Request::create('/install/run', 'POST'));
$payload = json_decode($response->getContent(), true);

if (! ($payload['success'] ?? false)) {
    fwrite(STDERR, "Install failed:\n".$response->getContent()."\n");
    exit(1);
}

echo "\n=== Instalasi selesai ===\n";
foreach ($payload['results'] as $row) {
    $icon = ($row['status'] ?? '') === 'ok' ? 'OK' : '!!';
    echo "  [{$icon}] {$row['step']}\n";
}

$store = App\Services\SettingService::get('store.name');
$users = App\Models\User::count();
$terminals = DB::table('master_pos_terminal')->count();
$products = App\Models\MasterProduk::count();

echo "\nRingkasan:\n";
echo "  Toko: {$store}\n";
echo "  Admin: admin@posip.com / password\n";
echo "  User: {$users} | Produk: {$products} | Terminal: {$terminals}\n";
echo "  Lock: storage/installed\n";
echo "\nBuka app: ".config('app.url')."\n";
echo "Wizard ulang: hapus storage/installed lalu buka /install\n";
