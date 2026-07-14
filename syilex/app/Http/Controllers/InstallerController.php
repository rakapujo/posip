<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InstallerController extends Controller
{
    private function steps(): array
    {
        return [
            1 => 'Cek Server',
            2 => 'Database',
            3 => 'Informasi Toko',
            4 => 'Regional & Mata Uang',
            5 => 'Pajak & Perhitungan',
            6 => 'Promo & Diskon',
            7 => 'Akun Admin',
            8 => 'Data Awal',
        ];
    }

    // ========== STEP 1: Server Requirements ==========

    public function step1()
    {
        $checks = $this->checkRequirements();
        $allPassed = collect($checks)->every(fn($c) => $c['passed']);

        return view('installer.step1', [
            'steps' => $this->steps(),
            'current' => 1,
            'checks' => $checks,
            'allPassed' => $allPassed,
        ]);
    }

    public function step1Post()
    {
        $allPassed = collect($this->checkRequirements())->every(fn ($c) => $c['passed']);

        if (!$allPassed) {
            return redirect()->route('installer.step1')
                ->withErrors(['requirements' => 'Server belum memenuhi semua persyaratan. Perbaiki item yang merah terlebih dahulu.']);
        }

        return redirect()->route('installer.step2');
    }

    // ========== STEP 2: Database ==========

    public function step2()
    {
        $data = session('installer.db', [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'posip_db',
            'username' => 'root',
            'password' => '',
        ]);

        return view('installer.step2', [
            'steps' => $this->steps(),
            'current' => 2,
            'data' => $data,
        ]);
    }

    public function step2Post(Request $request)
    {
        $validated = $request->validate([
            'host' => 'required|string',
            'port' => 'required|string',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        // Test connection
        try {
            $pdo = new \PDO(
                "mysql:host={$validated['host']};port={$validated['port']};dbname={$validated['database']}",
                $validated['username'],
                $validated['password'] ?? ''
            );
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            return back()->withInput()->withErrors([
                'database' => 'Koneksi gagal: ' . $e->getMessage(),
            ]);
        }

        session(['installer.db' => $validated]);
        return redirect()->route('installer.step3');
    }

    // ========== STEP 3: Store Info ==========

    public function step3()
    {
        if (!session('installer.db')) {
            return redirect()->route('installer.step2');
        }

        $data = session('installer.store', [
            'name' => '',
            'address' => '',
            'phone' => '',
            'email' => '',
            'npwp' => '',
        ]);

        return view('installer.step3', [
            'steps' => $this->steps(),
            'current' => 3,
            'data' => $data,
        ]);
    }

    public function step3Post(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'address' => 'required|string|max:500',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:100',
            'npwp' => 'nullable|string|max:30',
        ]);

        session(['installer.store' => $validated]);
        return redirect()->route('installer.step4');
    }

    // ========== STEP 4: Regional & Currency ==========

    public function step4()
    {
        if (!session('installer.store')) {
            return redirect()->route('installer.step3');
        }

        $data = session('installer.regional', [
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
        ]);

        $timezones = $this->getTimezoneList();

        return view('installer.step4', [
            'steps' => $this->steps(),
            'current' => 4,
            'data' => $data,
            'timezones' => $timezones,
        ]);
    }

    public function step4Post(Request $request)
    {
        $validated = $request->validate([
            'timezone' => 'required|string',
            'date_format' => 'required|in:DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD',
            'time_format' => 'required|in:HH:mm,hh:mm A',
            'currency_code' => 'required|string|max:10',
            'currency_symbol' => 'required|string|max:10',
            'currency_position' => 'required|in:before,after',
            'thousand_separator' => 'required|string|max:1',
            'decimal_separator' => 'required|string|max:1',
            'decimal_places' => 'required|in:0,1,2',
            'qty_decimal_places' => 'required|in:0,1,2',
        ]);

        session(['installer.regional' => $validated]);
        return redirect()->route('installer.step5');
    }

    // ========== STEP 5: Tax & Calculation ==========

    public function step5()
    {
        if (!session('installer.regional')) {
            return redirect()->route('installer.step4');
        }

        $data = session('installer.tax', [
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
        ]);

        return view('installer.step5', [
            'steps' => $this->steps(),
            'current' => 5,
            'data' => $data,
        ]);
    }

    public function step5Post(Request $request)
    {
        $validated = $request->validate([
            'tax_purchase_name' => 'required|string|max:50',
            'tax_purchase_percent' => 'required|numeric|min:0|max:100',
            'tax_purchase_included_in_hpp' => 'nullable',
            'tax_sales_name' => 'required|string|max:50',
            'tax_sales_percent' => 'required|numeric|min:0|max:100',
            'rounding_sales_method' => 'required|in:none,round,floor,ceil',
            'rounding_sales_precision' => 'required|in:1,10,100,500,1000',
            'negative_mode' => 'required|in:block,warn',
            'discount_mode' => 'required|in:recursive,sum',
            'cost_allocation_mode' => 'required|in:by_value,equal',
            'price_input_mode' => 'required|in:auto,manual',
        ]);

        $validated['tax_purchase_included_in_hpp'] = $request->has('tax_purchase_included_in_hpp');
        $validated['elektronik_enabled'] = $request->has('elektronik_enabled');
        session(['installer.tax' => $validated]);
        return redirect()->route('installer.step6');
    }

    // ========== STEP 6: Promo & Discount ==========

    public function step6()
    {
        if (!session('installer.tax')) {
            return redirect()->route('installer.step5');
        }

        $data = session('installer.promo', [
            'enabled' => true,
            'allow_manual_discount' => true,
            'max_manual_discount_percent' => '100',
            'max_manual_discount_nominal' => '',
        ]);

        return view('installer.step6', [
            'steps' => $this->steps(),
            'current' => 6,
            'data' => $data,
        ]);
    }

    public function step6Post(Request $request)
    {
        $validated = $request->validate([
            'max_manual_discount_percent' => 'required|numeric|min:0|max:100',
            'max_manual_discount_nominal' => 'nullable|numeric|min:0',
        ]);

        $validated['enabled'] = $request->has('enabled');
        $validated['allow_manual_discount'] = $request->has('allow_manual_discount');
        session(['installer.promo' => $validated]);
        return redirect()->route('installer.step7');
    }

    // ========== STEP 7: Admin Account ==========

    public function step7()
    {
        if (!session('installer.promo')) {
            return redirect()->route('installer.step6');
        }

        $data = session('installer.admin', [
            'name' => '',
            'email' => '',
        ]);

        return view('installer.step7', [
            'steps' => $this->steps(),
            'current' => 7,
            'data' => $data,
        ]);
    }

    public function step7Post(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:100',
            'password' => 'required|string|min:8|confirmed',
        ]);

        session(['installer.admin' => $validated]);
        return redirect()->route('installer.step8');
    }

    // ========== STEP 8: Initial Data + POS Terminal ==========

    public function step8()
    {
        if (!session('installer.admin')) {
            return redirect()->route('installer.step7');
        }

        $data = session('installer.data', [
            'seed_mode' => 'demo',
            'create_terminal' => true,
            'terminal_kode' => 'KASIR_1',
            'terminal_nama' => 'Kasir Utama',
            'terminal_izinkan_retur' => true,
            'terminal_durasi_retur' => '',
        ]);

        return view('installer.step8', [
            'steps' => $this->steps(),
            'current' => 8,
            'data' => $data,
        ]);
    }

    public function step8Post(Request $request)
    {
        $validated = $request->validate([
            'seed_mode' => 'required|in:demo,minimal',
            'create_terminal' => 'nullable',
            'terminal_kode' => 'nullable|string|max:20|regex:/^[A-Za-z0-9_]+$/',
            'terminal_nama' => 'nullable|string|max:100',
            'terminal_izinkan_retur' => 'nullable',
            'terminal_durasi_retur' => 'nullable|integer|min:0',
        ]);

        $validated['create_terminal'] = $request->has('create_terminal');
        $validated['terminal_izinkan_retur'] = $request->has('terminal_izinkan_retur');
        session(['installer.data' => $validated]);
        return redirect()->route('installer.install');
    }

    // ========== INSTALL ==========

    public function install()
    {
        $db = session('installer.db');
        $store = session('installer.store');
        $regional = session('installer.regional');
        $tax = session('installer.tax');
        $promo = session('installer.promo');
        $admin = session('installer.admin');
        $data = session('installer.data');

        if (!$db || !$store || !$admin) {
            return redirect()->route('installer.step1')
                ->withErrors(['session' => 'Sesi instalasi tidak lengkap. Silakan mulai dari awal.']);
        }

        return view('installer.installing', [
            'steps' => $this->steps(),
        ]);
    }

    public function installStatus()
    {
        if (!file_exists(storage_path('installed'))) {
            return response()->json(['installed' => false]);
        }

        try {
            $installData = json_decode(File::get(storage_path('installed')), true) ?: [];
        } catch (\Exception $e) {
            $installData = [];
        }

        return response()->json([
            'installed' => true,
            'admin_email' => $installData['admin_email'] ?? null,
            'seed_mode' => $installData['seed_mode'] ?? 'minimal',
        ]);
    }

    public function runInstall(Request $request)
    {
        $db = session('installer.db');
        $store = session('installer.store');
        $regional = session('installer.regional', []);
        $tax = session('installer.tax', []);
        $promo = session('installer.promo', []);
        $admin = session('installer.admin');
        $data = session('installer.data', ['seed_mode' => 'demo']);

        if (!$db || !$store || !$admin) {
            return response()->json(['error' => 'Sesi instalasi tidak lengkap.'], 422);
        }

        // Prevent timeout during install (migrations + seeding can take 60s+)
        set_time_limit(0);
        ini_set('memory_limit', '256M');

        $results = [];
        $appKey = 'base64:' . base64_encode(random_bytes(32));

        try {
            // 1. Bootstrap runtime config from session (defer .env write to avoid
            //    artisan serve restart killing this request mid-flight)
            $this->bootstrapInstallConfig($db, $appKey);
            $results[] = ['step' => 'Menyiapkan konfigurasi', 'status' => 'ok'];

            // 2. Storage link
            if (!file_exists(public_path('storage'))) {
                Artisan::call('storage:link');
            }
            $results[] = ['step' => 'Membuat symlink storage', 'status' => 'ok'];

            // 3. Migrate
            Artisan::call('migrate', ['--force' => true]);
            $results[] = ['step' => 'Migrasi database', 'status' => 'ok'];

            // 4. Seed roles & permissions (demo users only in demo mode)
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RolePermissionSeeder', '--force' => true]);
            $results[] = ['step' => 'Membuat roles & permissions', 'status' => 'ok'];

            if ($data['seed_mode'] === 'demo') {
                Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DemoUserSeeder', '--force' => true]);
                $results[] = ['step' => 'Membuat akun demo', 'status' => 'ok'];
            }

            // 5. Create admin user
            $this->createAdminUser($admin);
            $results[] = ['step' => 'Membuat akun admin', 'status' => 'ok'];

            // 6. Seed settings
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\SettingSeeder', '--force' => true]);
            $this->updateSettings($store, $regional, $tax, $promo);
            $results[] = ['step' => 'Menyimpan pengaturan', 'status' => 'ok'];

            // 7. Seed data
            if ($data['seed_mode'] === 'demo') {
                Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\MasterSeeder', '--force' => true]);
                $results[] = ['step' => 'Seed data demo (brand, produk, supplier, dll)', 'status' => 'ok'];
            } else {
                $this->seedMinimalData();
                $results[] = ['step' => 'Seed data minimal', 'status' => 'ok'];
            }

            // 8. Create POS Terminal (both seed modes)
            if ($data['create_terminal'] ?? false) {
                $this->createPosTerminal($data, $admin);
                $results[] = ['step' => 'Membuat POS Terminal', 'status' => 'ok'];
            }

            // 9. Create lock file (include admin email for done page,
            // since session is invalidated after APP_KEY change)
            File::put(storage_path('installed'), json_encode([
                'installed_at' => now()->toIso8601String(),
                'admin_email' => $admin['email'],
                'seed_mode' => $data['seed_mode'] ?? 'minimal',
            ]));
            $results[] = ['step' => 'Menyelesaikan instalasi', 'status' => 'ok'];

            // 10. Optimize (skip hard failure — optional on shared hosting)
            try {
                Artisan::call('optimize:clear');
                Artisan::call('optimize');
                $results[] = ['step' => 'Optimasi cache', 'status' => 'ok'];
            } catch (\Exception $e) {
                $results[] = ['step' => 'Optimasi cache (manual: php artisan optimize)', 'status' => 'error'];
            }

            // Write .env AFTER response is sent (prevents php artisan serve restart mid-request)
            app()->terminating(function () use ($db, $regional, $appKey) {
                try {
                    $this->createEnvFile($db, $regional, $appKey);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            $results[] = ['step' => 'Menulis file .env', 'status' => 'ok'];

            return response()->json(['results' => $results, 'success' => true]);

        } catch (\Exception $e) {
            $results[] = ['step' => 'Error: ' . $e->getMessage(), 'status' => 'error'];
            return response()->json(['results' => $results, 'success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ========== DONE ==========

    public function done()
    {
        if (!file_exists(storage_path('installed'))) {
            return redirect()->route('installer.step1');
        }

        // Read install metadata from lock file (session is lost after APP_KEY change)
        $adminEmail = 'admin@posip.com';
        $seedMode = 'minimal';
        try {
            $installData = json_decode(File::get(storage_path('installed')), true);
            $adminEmail = $installData['admin_email'] ?? $adminEmail;
            $seedMode = $installData['seed_mode'] ?? $seedMode;
        } catch (\Exception $e) {
            // Fallback to default
        }

        session()->forget('installer');

        return view('installer.done', [
            'adminEmail' => $adminEmail,
            'seedMode' => $seedMode,
        ]);
    }

    // ========== HELPERS ==========

    private function checkRequirements(): array
    {
        return [
            ['label' => 'PHP Version >= 8.2', 'passed' => version_compare(PHP_VERSION, '8.2.0', '>='), 'value' => PHP_VERSION, 'fix' => 'Upgrade PHP ke versi 8.2 atau lebih baru.'],
            ['label' => 'Extension: pdo_mysql', 'passed' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension pdo_mysql di php.ini.'],
            ['label' => 'Extension: mbstring', 'passed' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension mbstring di php.ini.'],
            ['label' => 'Extension: openssl', 'passed' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension openssl di php.ini.'],
            ['label' => 'Extension: tokenizer', 'passed' => extension_loaded('tokenizer'), 'value' => extension_loaded('tokenizer') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension tokenizer di php.ini.'],
            ['label' => 'Extension: xml', 'passed' => extension_loaded('xml'), 'value' => extension_loaded('xml') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension xml di php.ini.'],
            ['label' => 'Extension: ctype', 'passed' => extension_loaded('ctype'), 'value' => extension_loaded('ctype') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension ctype di php.ini.'],
            ['label' => 'Extension: json', 'passed' => extension_loaded('json'), 'value' => extension_loaded('json') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension json di php.ini.'],
            ['label' => 'Extension: bcmath', 'passed' => extension_loaded('bcmath'), 'value' => extension_loaded('bcmath') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension bcmath di php.ini.'],
            ['label' => 'Extension: fileinfo', 'passed' => extension_loaded('fileinfo'), 'value' => extension_loaded('fileinfo') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension fileinfo di php.ini.'],
            ['label' => 'Extension: gd', 'passed' => extension_loaded('gd'), 'value' => extension_loaded('gd') ? 'Installed' : 'Missing', 'fix' => 'Aktifkan extension gd di php.ini.'],
            ['label' => 'Folder storage/ writable', 'passed' => is_writable(storage_path()), 'value' => is_writable(storage_path()) ? 'Writable' : 'Not Writable', 'fix' => 'Jalankan: chmod -R 775 storage'],
            ['label' => 'Folder bootstrap/cache/ writable', 'passed' => is_writable(base_path('bootstrap/cache')), 'value' => is_writable(base_path('bootstrap/cache')) ? 'Writable' : 'Not Writable', 'fix' => 'Jalankan: chmod -R 775 bootstrap/cache'],
            ['label' => 'Memory limit >= 128M', 'passed' => $this->phpMemoryBytes() >= 128 * 1024 * 1024, 'value' => ini_get('memory_limit'), 'fix' => 'Set memory_limit = 256M di php.ini'],
            ['label' => 'Upload max filesize >= 10M', 'passed' => $this->phpUploadBytes() >= 10 * 1024 * 1024, 'value' => ini_get('upload_max_filesize'), 'fix' => 'Set upload_max_filesize = 10M di php.ini'],
            ['label' => 'Folder storage/framework/sessions writable', 'passed' => $this->ensureStorageWritable(), 'value' => is_writable(storage_path('framework/sessions')) ? 'Writable' : 'Not Writable', 'fix' => 'Jalankan: chmod -R 775 storage'],
        ];
    }

    private function ensureStorageWritable(): bool
    {
        $dirs = [
            storage_path(),
            storage_path('app'),
            storage_path('app/public'),
            storage_path('framework'),
            storage_path('framework/cache'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_writable($dir)) {
                @chmod($dir, 0775);
            }
        }

        return is_writable(storage_path('framework/sessions'));
    }

    private function phpMemoryBytes(): int
    {
        $val = trim(ini_get('memory_limit'));
        if ($val === '-1') return PHP_INT_MAX;
        $unit = strtolower(substr($val, -1));
        $num = (int) $val;
        return match ($unit) { 'g' => $num * 1024 * 1024 * 1024, 'm' => $num * 1024 * 1024, 'k' => $num * 1024, default => $num };
    }

    private function phpUploadBytes(): int
    {
        $val = trim(ini_get('upload_max_filesize'));
        $unit = strtolower(substr($val, -1));
        $num = (int) $val;
        return match ($unit) { 'g' => $num * 1024 * 1024 * 1024, 'm' => $num * 1024 * 1024, 'k' => $num * 1024, default => $num };
    }

    private function getTimezoneList(): array
    {
        $friendly = [
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
            $region = str_contains($tz, '/') ? explode('/', $tz)[0] : $tz;
            $label = ($friendly[$tz] ?? $tz) . " ($offset)";
            $grouped[$region][] = ['value' => $tz, 'label' => $label];
        }

        ksort($grouped);
        return $grouped;
    }

    private function bootstrapInstallConfig(array $db, string $appKey): void
    {
        config(['app.key' => $appKey]);
        config([
            'database.connections.mysql.host' => $db['host'],
            'database.connections.mysql.port' => $db['port'],
            'database.connections.mysql.database' => $db['database'],
            'database.connections.mysql.username' => $db['username'],
            'database.connections.mysql.password' => $db['password'] ?? '',
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    private function createEnvFile(array $db, array $regional, ?string $appKey = null): void
    {
        $timezone = $regional['timezone'] ?? 'Asia/Jakarta';
        try {
            $dbTimezone = (new \DateTime('now', new \DateTimeZone($timezone)))->format('P');
        } catch (\Exception $e) {
            $dbTimezone = '+07:00';
        }

        $appKey ??= 'base64:' . base64_encode(random_bytes(32));
        $appUrl = rtrim(preg_replace('#/public/?$#', '', url('/')), '/');
        $parsedUrl = parse_url($appUrl);
        $appHost = $parsedUrl['host'] ?? 'localhost';
        $isHttps = ($parsedUrl['scheme'] ?? 'http') === 'https';
        $sanctumDomains = $this->resolveSanctumDomains($appUrl);

        $env = "APP_NAME=POSIP\n";
        $env .= "APP_ENV=production\n";
        $env .= "APP_KEY={$appKey}\n";
        $env .= "APP_DEBUG=false\n";
        $env .= "APP_URL={$appUrl}\n";
        $env .= "APP_TIMEZONE={$timezone}\n\n";
        $env .= "APP_LOCALE=id\n";
        $env .= "APP_FALLBACK_LOCALE=en\n";
        $env .= "APP_FAKER_LOCALE=id_ID\n\n";
        $env .= "APP_MAINTENANCE_DRIVER=file\n\n";
        $env .= "BCRYPT_ROUNDS=12\n\n";
        $env .= "LOG_CHANNEL=daily\n";
        $env .= "LOG_STACK=daily\n";
        $env .= "LOG_DEPRECATIONS_CHANNEL=null\n";
        $env .= "LOG_LEVEL=warning\n\n";
        $env .= "DB_CONNECTION=mysql\n";
        $env .= "DB_HOST={$db['host']}\n";
        $env .= "DB_PORT={$db['port']}\n";
        $env .= "DB_DATABASE={$db['database']}\n";
        $env .= "DB_USERNAME={$db['username']}\n";
        $dbPass = str_replace('"', '\\"', $db['password'] ?? '');
        $env .= "DB_PASSWORD=\"{$dbPass}\"\n";
        $env .= "DB_TIMEZONE={$dbTimezone}\n\n";
        $env .= "FRONTEND_URL={$appUrl}\n\n";
        $env .= "SESSION_DRIVER=file\n";
        $env .= "SESSION_LIFETIME=120\n";
        $env .= "SESSION_ENCRYPT=true\n";
        $env .= "SESSION_PATH=/\n";
        $env .= "SESSION_DOMAIN=\n";
        $env .= 'SESSION_SECURE_COOKIE=' . ($isHttps ? 'true' : 'false') . "\n";
        $env .= "SESSION_SAME_SITE=lax\n\n";
        $env .= "SANCTUM_STATEFUL_DOMAINS={$sanctumDomains}\n\n";
        $env .= "BROADCAST_CONNECTION=log\n";
        $env .= "FILESYSTEM_DISK=local\n";
        $env .= "QUEUE_CONNECTION=database\n\n";
        $env .= "CACHE_STORE=file\n\n";
        $env .= "MAIL_MAILER=log\n";
        $env .= "MAIL_HOST=127.0.0.1\n";
        $env .= "MAIL_PORT=2525\n";
        $env .= "MAIL_USERNAME=null\n";
        $env .= "MAIL_PASSWORD=null\n";
        $env .= 'MAIL_FROM_ADDRESS="hello@example.com"' . "\n";
        $env .= 'MAIL_FROM_NAME="${APP_NAME}"' . "\n\n";
        $env .= 'VITE_APP_NAME="${APP_NAME}"' . "\n";

        File::put(base_path('.env'), $env);
    }

    private function resolveSanctumDomains(string $appUrl): string
    {
        $parsed = parse_url($appUrl);
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? null;

        $domains = [$host];
        if ($port && !in_array($port, [80, 443], true)) {
            $domains[] = "{$host}:{$port}";
        }

        // Include localhost variants for local dev installs via Laragon
        if ($host !== 'localhost') {
            $domains[] = 'localhost';
            $domains[] = '127.0.0.1';
        }

        return implode(',', array_unique($domains));
    }

    private function createAdminUser(array $admin): void
    {
        $user = \App\Models\User::where('email', $admin['email'])->first();
        if ($user) {
            $user->update([
                'name' => $admin['name'],
                'password' => $admin['password'],
            ]);
        } else {
            $user = \App\Models\User::create([
                'ulid' => (string) Str::ulid(),
                'name' => $admin['name'],
                'email' => $admin['email'],
                'password' => $admin['password'],
                'status' => 'active',
            ]);
        }
        $user->assignRole('super-admin');
    }

    private function updateSettings(array $store, array $regional, array $tax, array $promo): void
    {
        $settings = [
            'store.name' => $store['name'],
            'store.address' => $store['address'],
            'store.phone' => $store['phone'],
            'store.email' => $store['email'],
            'store.npwp' => $store['npwp'] ?? '',
            'regional.timezone' => $regional['timezone'] ?? 'Asia/Jakarta',
            'regional.date_format' => $regional['date_format'] ?? 'DD/MM/YYYY',
            'regional.time_format' => $regional['time_format'] ?? 'HH:mm',
            'currency.code' => $regional['currency_code'] ?? 'IDR',
            'currency.symbol' => $regional['currency_symbol'] ?? 'Rp',
            'currency.position' => $regional['currency_position'] ?? 'before',
            'currency.thousand_separator' => $regional['thousand_separator'] ?? '.',
            'currency.decimal_separator' => $regional['decimal_separator'] ?? ',',
            'currency.decimal_places' => $regional['decimal_places'] ?? 0,
            'number.qty_decimal_places' => $regional['qty_decimal_places'] ?? 0,
            'tax.tax_purchase_name' => $tax['tax_purchase_name'] ?? 'PPN',
            'tax.tax_purchase_percent' => $tax['tax_purchase_percent'] ?? 11,
            'tax.tax_purchase_included_in_hpp' => $tax['tax_purchase_included_in_hpp'] ?? false,
            'tax.tax_sales_name' => $tax['tax_sales_name'] ?? 'PPN',
            'tax.tax_sales_percent' => $tax['tax_sales_percent'] ?? 11,
            'rounding.sales_method' => $tax['rounding_sales_method'] ?? 'round',
            'rounding.sales_precision' => $tax['rounding_sales_precision'] ?? 100,
            'stock.negative_mode' => $tax['negative_mode'] ?? 'block',
            'calculation.discount_mode' => $tax['discount_mode'] ?? 'recursive',
            'calculation.cost_allocation_mode' => $tax['cost_allocation_mode'] ?? 'by_value',
            'product.price_input_mode' => $tax['price_input_mode'] ?? 'auto',
            'modules.elektronik_enabled' => $tax['elektronik_enabled'] ?? true,
            'promo.enabled' => $promo['enabled'] ?? true,
            'promo.allow_manual_discount' => $promo['allow_manual_discount'] ?? true,
            'promo.max_manual_discount_percent' => $promo['max_manual_discount_percent'] ?? 100,
            'promo.max_manual_discount_nominal' => $promo['max_manual_discount_nominal'] ?? null,
        ];

        foreach ($settings as $key => $value) {
            [$group, $settingKey] = explode('.', $key, 2);
            \App\Models\Setting::where('group', $group)
                ->where('key', $settingKey)
                ->update(['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value]);
        }

        \App\Services\SettingService::clearCache();
    }

    private function seedMinimalData(): void
    {
        \App\Models\MasterWarehouse::firstOrCreate(
            ['kode_warehouse' => 'WH_UTAMA'],
            ['ulid' => (string) Str::ulid(), 'nama_warehouse' => 'Gudang Utama', 'is_saleable' => true, 'status' => 'active']
        );

        \App\Models\MasterCustomer::firstOrCreate(
            ['kode_customer' => 'WALKIN'],
            ['ulid' => (string) Str::ulid(), 'nama' => 'Walk-in Customer', 'telepon' => '-', 'jenis' => 'walk_in', 'status' => 'active']
        );

        $methods = [
            ['kode' => 'CASH', 'nama' => 'Tunai', 'metode' => 'tunai', 'jenis' => null],
            ['kode' => 'TRANSFER', 'nama' => 'Transfer Bank', 'metode' => 'non_tunai', 'jenis' => 'bank'],
            ['kode' => 'QRIS', 'nama' => 'QRIS', 'metode' => 'non_tunai', 'jenis' => 'qris'],
        ];

        foreach ($methods as $m) {
            \App\Models\MasterMetodePembayaran::firstOrCreate(
                ['kode_pembayaran' => $m['kode']],
                ['ulid' => (string) Str::ulid(), 'nama_pembayaran' => $m['nama'], 'metode' => $m['metode'], 'jenis' => $m['jenis'], 'biaya_tambahan_tipe' => 'none', 'biaya_tambahan_nilai' => 0, 'status' => 'active']
            );
        }
    }

    private function createPosTerminal(array $data, array $admin): void
    {
        $warehouse = \App\Models\MasterWarehouse::where('status', 'active')
            ->where('is_saleable', true)->first();
        $walkIn = \App\Models\MasterCustomer::where('jenis', 'walk_in')
            ->where('status', 'active')->first();
        $cash = \App\Models\MasterMetodePembayaran::where('metode', 'tunai')
            ->where('status', 'active')->first();
        $adminUser = \App\Models\User::where('email', $admin['email'])->first();

        if (!$warehouse || !$walkIn || !$cash || !$adminUser) {
            return;
        }

        $allMethods = \App\Models\MasterMetodePembayaran::where('status', 'active')->pluck('id');
        $allUsers = \App\Models\User::where('status', 'active')
            ->where('is_protected', false)->pluck('id');

        $terminal = \App\Models\MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => $data['terminal_kode'] ?? 'KASIR_1',
            'nama_terminal' => $data['terminal_nama'] ?? 'Kasir Utama',
            'warehouse_id' => $warehouse->id,
            'default_customer_id' => $walkIn->id,
            'default_metode_pembayaran_id' => $cash->id,
            'auto_open_tray' => false,
            'izinkan_retur' => $data['terminal_izinkan_retur'] ?? true,
            'durasi_retur' => !empty($data['terminal_durasi_retur']) ? (int) $data['terminal_durasi_retur'] : null,
            'status' => 'active',
            'created_by' => $adminUser->id,
        ]);

        $terminal->users()->attach($allUsers);
        $terminal->allowedPaymentMethods()->attach($allMethods);
    }
}
