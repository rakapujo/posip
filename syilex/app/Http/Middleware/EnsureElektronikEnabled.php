<?php

namespace App\Http\Middleware;

use App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate semua endpoint modul Elektronik (serial) di belakang setting
 * `modules.elektronik_enabled`. Saat modul nonaktif → 403 (format BaseApiController).
 *
 * Default ON (lihat SettingService::isElektronikEnabled) → tak mengubah perilaku
 * instalasi lama / lingkungan tes yang belum punya baris setting.
 */
class EnsureElektronikEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!SettingService::isElektronikEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Modul Elektronik (serial) sedang nonaktif. Aktifkan di Pengaturan → Modul.',
            ], 403);
        }

        return $next($request);
    }
}
