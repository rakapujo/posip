<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key middleware.
 *
 * Cegah duplicate submit (misal user double-click checkout, network retry).
 * Jika request datang dengan header `Idempotency-Key: xxx` dan key tersebut
 * sudah pernah diproses dalam TTL, return response original tanpa re-execute.
 *
 * Key-nya disimpan di cache dengan TTL default 10 menit (cukup untuk retry window).
 *
 * Usage di routes:
 * ```
 * Route::post('/pos/checkout', ...)->middleware('idempotency');
 * ```
 *
 * FE client kirim:
 * ```
 * POST /api/v1/pos/checkout
 * Idempotency-Key: {uuid-v4-per-submit}
 * ```
 */
class IdempotencyKey
{
    /** Cache TTL untuk stored response (dalam detik). */
    public const TTL_SECONDS = 600; // 10 menit

    /** Header name untuk idempotency key. */
    public const HEADER_NAME = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER_NAME);

        // Tidak ada header → skip middleware (backward compatible untuk legacy client).
        if (!$key) {
            return $next($request);
        }

        // Validate key format (cegah cache pollution dari key aneh).
        if (!$this->isValidKey($key)) {
            return response()->json([
                'success' => false,
                'message' => 'Idempotency-Key tidak valid. Gunakan UUID v4 atau string alphanumeric 16-128 karakter.',
            ], 400);
        }

        $cacheKey = $this->buildCacheKey($request, $key);

        // Check apakah request ini sudah pernah diproses.
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            // Return response original, tambah header untuk transparency.
            return response()->json($cached['body'], $cached['status'])
                ->header('Idempotent-Replayed', 'true')
                ->header('Idempotency-Key', $key);
        }

        $response = $next($request);

        // Simpan response untuk replay jika request sama datang lagi.
        // Hanya cache response sukses (2xx) — error biar user bisa retry.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true),
            ], self::TTL_SECONDS);
        }

        return $response->header('Idempotency-Key', $key);
    }

    /**
     * Build scoped cache key: per-user + per-route + per-idempotency-key.
     * Ini cegah user A akses response cached user B.
     */
    protected function buildCacheKey(Request $request, string $key): string
    {
        $userId = $request->user()?->id ?? 'guest';
        $route = $request->path();
        return "idempotency:{$userId}:{$route}:{$key}";
    }

    /**
     * Validate key format — alphanumeric + dash/underscore, 16-128 chars.
     */
    protected function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $key);
    }
}
