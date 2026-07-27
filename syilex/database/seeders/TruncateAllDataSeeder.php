<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated Incomplete vs API Reset Database. Prefer POST /api/v1/reset (settings.reset).
 */
class TruncateAllDataSeeder extends Seeder
{
    public function run(): void
    {
        throw new \RuntimeException(
            'TruncateAllDataSeeder is deprecated and incomplete. Use API Reset Database (POST /api/v1/reset) with permission settings.reset.'
        );
    }
}
