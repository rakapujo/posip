<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Toggle mode bebas retur jual/beli: returns.sales_allow_free, returns.purchase_allow_free.
 * Default TRUE → perilaku existing (free mode tetap boleh).
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['group' => 'returns', 'key' => 'sales_allow_free', 'value' => 'true', 'type' => 'boolean'],
            ['group' => 'returns', 'key' => 'purchase_allow_free', 'value' => 'true', 'type' => 'boolean'],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('settings')
                ->where('group', $row['group'])
                ->where('key', $row['key'])
                ->exists();

            if (! $exists) {
                DB::table('settings')->insert([
                    'ulid' => (string) Str::ulid(),
                    'group' => $row['group'],
                    'key' => $row['key'],
                    'value' => $row['value'],
                    'type' => $row['type'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'returns')
            ->whereIn('key', ['sales_allow_free', 'purchase_allow_free'])
            ->delete();
    }
};
