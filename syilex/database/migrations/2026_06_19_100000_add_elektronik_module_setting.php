<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Toggle Modul Elektronik (serial): setting `modules.elektronik_enabled`.
 * Default TRUE → instalasi lama tetap berperilaku sama (semua fitur serial aktif).
 * Idempotent: hanya insert bila belum ada (aman dijalankan ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')
            ->where('group', 'modules')
            ->where('key', 'elektronik_enabled')
            ->exists();

        if (!$exists) {
            DB::table('settings')->insert([
                'ulid' => (string) Str::ulid(),
                'group' => 'modules',
                'key' => 'elektronik_enabled',
                'value' => 'true',
                'type' => 'boolean',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'modules')
            ->where('key', 'elektronik_enabled')
            ->delete();
    }
};
