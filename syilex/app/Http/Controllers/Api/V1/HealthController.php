<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HealthController
{
    /**
     * Depth health check — returns JSON with status of DB, storage, cache.
     * 200 OK = all healthy; 503 Service Unavailable = at least one check failed.
     *
     * Meant for monitoring tools (UptimeRobot, Grafana, load balancers).
     */
    public function check(): JsonResponse
    {
        $checks = [
            'db' => $this->checkDb(),
            'storage' => $this->checkStorage(),
            'cache' => $this->checkCache(),
        ];

        $allOk = collect($checks)->every(fn ($c) => $c['ok'] === true);

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'env' => config('app.env'),
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $allOk ? 200 : 503);
    }

    private function checkDb(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);
            return ['ok' => true, 'latency_ms' => $latency];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        try {
            $disk = Storage::disk('local');
            $probe = 'health-probe/' . bin2hex(random_bytes(6)) . '.txt';
            $disk->put($probe, 'ok');
            $content = $disk->get($probe);
            $disk->delete($probe);
            return ['ok' => $content === 'ok', 'writable' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health-probe-' . bin2hex(random_bytes(4));
            $value = (string) time();
            Cache::put($key, $value, 10);
            $read = Cache::get($key);
            Cache::forget($key);
            return ['ok' => $read === $value, 'driver' => config('cache.default')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
