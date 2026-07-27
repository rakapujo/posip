<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave B (B2.5): pos_cash_transactions.tipe gains 'refund_retur' so refund-from-retur rows
 * are identifiable by column instead of a `keterangan LIKE 'Refund retur%'` string match.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite enforces the enum CHECK constraint from the original create migration.
            Schema::table('pos_cash_transactions', function (Blueprint $table) {
                $table->dropColumn('tipe');
            });
            Schema::table('pos_cash_transactions', function (Blueprint $table) {
                $table->string('tipe', 20)->after('shift_id');
            });
        } else {
            DB::statement("ALTER TABLE pos_cash_transactions MODIFY COLUMN tipe ENUM('setor_awal', 'kas_masuk', 'kas_keluar', 'refund_retur') NOT NULL");
        }

        DB::table('pos_cash_transactions')
            ->where('tipe', 'kas_keluar')
            ->where('keterangan', 'like', 'Refund retur%')
            ->update(['tipe' => 'refund_retur']);
    }

    public function down(): void
    {
        DB::table('pos_cash_transactions')
            ->where('tipe', 'refund_retur')
            ->update(['tipe' => 'kas_keluar']);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE pos_cash_transactions MODIFY COLUMN tipe ENUM('setor_awal', 'kas_masuk', 'kas_keluar') NOT NULL");
    }
};
