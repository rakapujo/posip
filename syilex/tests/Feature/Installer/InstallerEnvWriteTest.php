<?php

namespace Tests\Feature\Installer;

use App\Http\Controllers\InstallerController;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

class InstallerEnvWriteTest extends TestCase
{
    public function test_run_install_writes_env_before_optimize_and_has_no_terminating(): void
    {
        $src = file_get_contents((new ReflectionClass(InstallerController::class))->getFileName());
        $runStart = strpos($src, 'function runInstall');
        $this->assertNotFalse($runStart);
        $runChunk = substr($src, $runStart, 9000);

        $this->assertStringNotContainsString('app()->terminating', $runChunk);
        $envPos = strpos($runChunk, 'createEnvFile');
        $optPos = strpos($runChunk, "Artisan::call('optimize')");
        $this->assertNotFalse($envPos);
        $this->assertNotFalse($optPos);
        $this->assertLessThan($optPos, $envPos);
    }

    public function test_create_env_file_puts_database_from_payload(): void
    {
        File::shouldReceive('put')
            ->once()
            ->withArgs(function (string $path, string $contents) {
                return str_ends_with(str_replace('\\', '/', $path), '/.env')
                    && str_contains($contents, 'DB_DATABASE=acme_posip')
                    && str_contains($contents, 'DB_USERNAME=acme_user');
            });

        $controller = new InstallerController();
        $method = (new ReflectionClass($controller))->getMethod('createEnvFile');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'acme_posip',
            'username' => 'acme_user',
            'password' => 'secret',
        ], [
            'timezone' => 'Asia/Jakarta',
        ], 'base64:dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdA==');
    }
}
