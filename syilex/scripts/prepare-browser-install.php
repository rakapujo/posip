<?php

/**
 * Siapkan ulang wizard /install untuk dokumentasi Playwright.
 *
 * Usage: php scripts/prepare-browser-install.php
 */

declare(strict_types=1);

$installedLock = __DIR__.'/../storage/installed';
if (is_file($installedLock)) {
    unlink($installedLock);
    echo "Removed storage/installed\n";
} else {
    echo "storage/installed tidak ada (wizard sudah terbuka)\n";
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
echo "Database migrated (fresh, empty)\n";

Illuminate\Support\Facades\Artisan::call('optimize:clear');
echo "Cache cleared\n";
echo "Buka: ".config('app.url')."/install\n";
