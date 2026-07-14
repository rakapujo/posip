<?php

namespace App\Http\Middleware;

use App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CleanupActivityLog
{
    protected const CACHE_KEY = 'activity_log_cleanup_cooldown';
    protected const LOCK_KEY = 'activity_log_cleanup_running';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldCleanup()) {
            $cooldownMinutes = SettingService::getSchedulerCooldown('activity_log');
            Cache::put(self::CACHE_KEY, true, now()->addMinutes($cooldownMinutes));

            $this->runCleanup();
        }

        return $next($request);
    }

    protected function shouldCleanup(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        if (!SettingService::isSchedulerEnabled('activity_log')) {
            return false;
        }

        if (Cache::has(self::CACHE_KEY)) {
            return false;
        }

        return true;
    }

    protected function runCleanup(): void
    {
        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (!$lock->get()) {
            return;
        }

        try {
            Artisan::call('activitylog:clean');

            Log::info('Activity log cleaned via middleware trigger', [
                'user_id' => auth()->id(),
                'retention_days' => config('activitylog.delete_records_older_than_days'),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in activity log cleanup', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->forceRelease();
        }
    }
}
