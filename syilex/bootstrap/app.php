<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            \App\Http\Middleware\CleanupActivityLog::class,
            \App\Http\Middleware\PreventApiCaching::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\RedirectIfNotInstalled::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Alias untuk per-route middleware
        $middleware->alias([
            'idempotency' => \App\Http\Middleware\IdempotencyKey::class,
            'feature.elektronik' => \App\Http\Middleware\EnsureElektronikEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render custom BusinessException dengan format BaseApiController::error()
        // supaya response API konsisten dan FE bisa handle seragam.
        $exceptions->render(function (\App\Exceptions\BusinessException $e, \Illuminate\Http\Request $request) {
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null; // biarkan Laravel handle untuk web routes
            }

            $payload = [
                'success' => false,
                'message' => $e->getMessage(),
            ];

            $context = $e->getContext();
            if (!empty($context)) {
                $payload['errors'] = $context;
            }

            return response()->json($payload, $e->getStatusCode());
        });
    })->create();
