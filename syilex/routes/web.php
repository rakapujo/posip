<?php

use App\Http\Controllers\InstallerController;
use App\Http\Middleware\RedirectIfInstalled;
use Illuminate\Support\Facades\Route;

// ── Installation Wizard ──
Route::prefix('install')->middleware(RedirectIfInstalled::class)->group(function () {
    Route::get('/', [InstallerController::class, 'step1'])->name('installer.step1');
    Route::post('/step1', [InstallerController::class, 'step1Post'])->name('installer.step1.post');

    Route::get('/step2', [InstallerController::class, 'step2'])->name('installer.step2');
    Route::post('/step2', [InstallerController::class, 'step2Post'])->name('installer.step2.post');

    Route::get('/step3', [InstallerController::class, 'step3'])->name('installer.step3');
    Route::post('/step3', [InstallerController::class, 'step3Post'])->name('installer.step3.post');

    Route::get('/step4', [InstallerController::class, 'step4'])->name('installer.step4');
    Route::post('/step4', [InstallerController::class, 'step4Post'])->name('installer.step4.post');

    Route::get('/step5', [InstallerController::class, 'step5'])->name('installer.step5');
    Route::post('/step5', [InstallerController::class, 'step5Post'])->name('installer.step5.post');

    Route::get('/step6', [InstallerController::class, 'step6'])->name('installer.step6');
    Route::post('/step6', [InstallerController::class, 'step6Post'])->name('installer.step6.post');

    Route::get('/step7', [InstallerController::class, 'step7'])->name('installer.step7');
    Route::post('/step7', [InstallerController::class, 'step7Post'])->name('installer.step7.post');

    Route::get('/step8', [InstallerController::class, 'step8'])->name('installer.step8');
    Route::post('/step8', [InstallerController::class, 'step8Post'])->name('installer.step8.post');

    Route::get('/installing', [InstallerController::class, 'install'])->name('installer.install');
    Route::post('/run', [InstallerController::class, 'runInstall'])->name('installer.run');
});

// Pollable during install (survives artisan serve restart when .env is written)
Route::get('/install/status', [InstallerController::class, 'installStatus'])->name('installer.status');

// Done page — outside RedirectIfInstalled so it's accessible after lock file creation
Route::get('/install/done', [InstallerController::class, 'done'])->name('installer.done');

// ── Main App (SPA) ──
// All non-file, non-API requests now come through index.php (no more index.html).
// RedirectIfNotInstalled middleware (registered globally in bootstrap/app.php)
// handles the redirect to /install when storage/installed doesn't exist.
// When installed, serve the SPA's index.html content.
Route::fallback(function () {
    if (file_exists(public_path('index.html'))) {
        return response(file_get_contents(public_path('index.html')), 200)
            ->header('Content-Type', 'text/html');
    }
    return response('Page not found', 404);
});
