<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `deleted_at` column untuk SoftDeletes di master data.
 *
 * Tables:
 * - users
 * - master_produk
 * - master_customer
 * - master_supplier
 *
 * SoftDeletes jaga integritas historis:
 * - Data transaksi yang reference produk/customer/supplier yang "dihapus" tetap valid.
 * - Query default auto-exclude deleted rows (Laravel Eloquent).
 * - Bisa restore via `->restore()` atau query dengan `withTrashed()`.
 */
return new class extends Migration
{
    private array $tables = [
        'users',
        'master_produk',
        'master_customer',
        'master_supplier',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
