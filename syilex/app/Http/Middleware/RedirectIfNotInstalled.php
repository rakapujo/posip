<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!file_exists(storage_path('installed'))) {
            // Auto-create .env if missing (shared hosting without CLI)
            if (!file_exists(base_path('.env')) && file_exists(base_path('.env.example'))) {
                copy(base_path('.env.example'), base_path('.env'));
                try {
                    Artisan::call('key:generate', ['--force' => true]);
                } catch (\Exception $e) {
                    // Key generation might fail, but app can still boot
                }
            }

            if (!$request->is('install*')) {
                return redirect()->route('installer.step1');
            }
        }

        return $next($request);
    }
}
