<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        if (!$isSqlite) {
            // Step 1: Drop the foreign key constraint on retur_id
            Schema::table('supplier_deposit', function (Blueprint $table) {
                $table->dropForeign(['retur_id']);
            });

            // Step 2: Modify retur_id to be nullable using raw SQL (MySQL only)
            DB::statement('ALTER TABLE supplier_deposit MODIFY retur_id BIGINT UNSIGNED NULL');

            // Step 3: Re-add foreign key constraint (with ON DELETE SET NULL)
            Schema::table('supplier_deposit', function (Blueprint $table) {
                $table->foreign('retur_id')
                      ->references('id')
                      ->on('doc_purchase_return')
                      ->onDelete('set null');
            });
        }

        // Step 4: Add new fields for manual deposits (works on both MySQL and SQLite)
        Schema::table('supplier_deposit', function (Blueprint $table) use ($isSqlite) {
            $table->string('no_referensi', 50)->nullable()->after('retur_id');
            $table->text('keterangan')->nullable()->after('no_referensi');

            // Audit fields
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users');
            $table->timestamp('updated_at')->nullable()->after('created_at');

            // Index for searching
            $table->index('no_referensi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        Schema::table('supplier_deposit', function (Blueprint $table) {
            $table->dropIndex(['no_referensi']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['no_referensi', 'keterangan', 'created_by', 'updated_by', 'updated_at']);
        });

        if ($isSqlite) {
            return;
        }

        // Revert retur_id to NOT NULL (only if all records have retur_id)
        // Note: This will fail if there are manual deposits
        Schema::table('supplier_deposit', function (Blueprint $table) {
            $table->dropForeign(['retur_id']);
        });

        DB::statement('ALTER TABLE supplier_deposit MODIFY retur_id BIGINT UNSIGNED NOT NULL');

        Schema::table('supplier_deposit', function (Blueprint $table) {
            $table->foreign('retur_id')
                  ->references('id')
                  ->on('doc_purchase_return');
        });
    }
};
