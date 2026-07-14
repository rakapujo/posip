<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\LoginThrottleKeys;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Command;

class ClearLoginThrottleCommand extends Command
{
    protected $signature = 'auth:clear-login-throttle
                            {--all : Reset throttle login untuk localhost + semua email user terdaftar}
                            {--ip= : Reset throttle untuk IP tertentu (contoh: 127.0.0.1)}
                            {--email= : Reset throttle untuk email tertentu (contoh: admin@posip.com)}';

    protected $description = 'Reset rate limit login (Too Many Attempts) — 5 percobaan / 15 menit';

    public function handle(RateLimiter $rateLimiter): int
    {
        $ip = $this->option('ip');
        $email = $this->option('email');
        $all = (bool) $this->option('all');

        if (! $all && $ip === null && $email === null) {
            $this->error('Pilih salah satu: --all, --ip=..., atau --email=...');
            $this->line('Contoh: php artisan auth:clear-login-throttle --ip=127.0.0.1');

            return self::FAILURE;
        }

        $cleared = 0;

        if ($all) {
            foreach (['127.0.0.1', '::1'] as $localhostIp) {
                $cleared += $this->clearIp($rateLimiter, $localhostIp);
            }

            foreach (User::query()->pluck('email') as $userEmail) {
                $cleared += $this->clearEmail($rateLimiter, (string) $userEmail);
            }

            $this->info("Login throttle direset ({$cleared} key): localhost + email user terdaftar.");
            $this->comment('IP lain? Jalankan ulang dengan --ip=<alamat>.');
        }

        if ($ip !== null && $ip !== '') {
            $count = $this->clearIp($rateLimiter, $ip);
            $cleared += $count;
            $this->info("Login throttle direset untuk IP {$ip} ({$count} key).");
        }

        if ($email !== null && $email !== '') {
            $count = $this->clearEmail($rateLimiter, $email);
            $cleared += $count;
            $this->info("Login throttle direset untuk email {$email} ({$count} key).");
        }

        if ($cleared === 0) {
            $this->warn('Tidak ada key throttle aktif yang ditemukan (mungkin sudah expired).');
        }

        return self::SUCCESS;
    }

    private function clearIp(RateLimiter $rateLimiter, string $ip): int
    {
        $keys = [
            LoginThrottleKeys::forIp($ip),
            LoginThrottleKeys::legacyForIp($ip),
        ];

        return $this->clearKeys($rateLimiter, $keys);
    }

    private function clearEmail(RateLimiter $rateLimiter, string $email): int
    {
        return $this->clearKeys($rateLimiter, [LoginThrottleKeys::forEmail($email)]);
    }

    /**
     * @param  list<string>  $keys
     */
    private function clearKeys(RateLimiter $rateLimiter, array $keys): int
    {
        $cleared = 0;

        foreach ($keys as $key) {
            if ($rateLimiter->attempts($key) > 0 || $rateLimiter->availableIn($key) > 0) {
                $cleared++;
            }

            $rateLimiter->clear($key);
        }

        return $cleared;
    }
}
