<?php

namespace App\Providers;

use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Observers\MasterProdukObserver;
use App\Observers\MasterWarehouseObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $limits = [
                Limit::perMinutes(15, 5)->by($request->ip()),
            ];

            $email = strtolower(trim((string) $request->input('email', '')));
            if ($email !== '') {
                $limits[] = Limit::perMinutes(15, 5)->by('email:'.$email);
            }

            return $limits;
        });

        // Sync app + database timezone from global settings.
        //
        // SettingService reads key `regional.timezone` from the settings table
        // (default 'Asia/Jakarta'). This block applies that value to:
        //   - PHP's default timezone (for Carbon::now(), date(), etc.)
        //   - Laravel's config('app.timezone')
        //   - MySQL connection config (future connections)
        //   - Current MySQL session (existing connection)
        //
        // Admin can change the timezone from the settings UI; next request
        // will pick up the new value automatically (SettingService::set()
        // clears its cache).
        try {
            $timezone = \App\Services\SettingService::getTimezone();
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);

            // Convert timezone name to MySQL-compatible offset string.
            $offset = \App\Services\SettingService::getTimezoneOffset();
            config(['database.connections.mysql.timezone' => $offset]);
            config(['database.connections.mariadb.timezone' => $offset]);

            // Apply to the current MySQL connection (may already be open
            // from the settings read above). Silent on failure — future
            // connections will use the updated config.
            try {
                \Illuminate\Support\Facades\DB::statement("SET time_zone = '{$offset}'");
            } catch (\Exception $e) {
                // Connection may not be available, or driver not MySQL.
            }
        } catch (\Exception $e) {
            // Fallback during migrations, tests, or when DB is not available.
            // config/database.php env() defaults keep the app working.
        }

        // Register observers for auto-creating initial inventory stock
        MasterWarehouse::observe(MasterWarehouseObserver::class);
        MasterProduk::observe(MasterProdukObserver::class);
    }
}
