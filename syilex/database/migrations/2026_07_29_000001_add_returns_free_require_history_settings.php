<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Flag histori mode bebas (non-serial):
 * - returns.sales_free_require_sold (default true)
 * - returns.purchase_free_require_purchased (default false)
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['group' => 'returns', 'key' => 'sales_free_require_sold', 'value' => 'true', 'type' => 'boolean'],
            ['group' => 'returns', 'key' => 'purchase_free_require_purchased', 'value' => 'false', 'type' => 'boolean'],
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
            ->whereIn('key', ['sales_free_require_sold', 'purchase_free_require_purchased'])
            ->delete();
    }
};
