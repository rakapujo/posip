<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial — revisi identitas unit.
 *
 * `serial_number` TIDAK lagi unik (boleh sama, bahkan dalam 1 produk — di ponsel SN
 * bisa kembar/typo dari supplier). Identitas unik unit dipindah ke `kode_internal`:
 * kode internal toko, UNIQUE global, auto-generate (KI-{id}) bila kosong, boleh override.
 *
 * Kolom nullable di level DB (auto-gen butuh PK id yang baru ada setelah insert →
 * diisi model hook `created`); keunikan dijamin UNIQUE index + non-null dijamin app-level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->string('kode_internal', 40)->nullable()->after('serial_number');
        });

        // Backfill unit existing: KI-{id} (portable utk MySQL & SQLite; id unik → tak bentrok).
        foreach (DB::table('serial_units')->whereNull('kode_internal')->pluck('id') as $id) {
            DB::table('serial_units')->where('id', $id)->update([
                'kode_internal' => 'KI-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT),
            ]);
        }

        Schema::table('serial_units', function (Blueprint $table) {
            $table->unique('kode_internal');
        });
    }

    public function down(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->dropUnique(['kode_internal']);
            $table->dropColumn('kode_internal');
        });
    }
};
