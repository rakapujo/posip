<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing performance indexes:
 * - `status` columns (sering difilter di list queries)
 * - `nomor_dokumen` columns (search LIKE)
 *
 * Semua index idempotent (cek ada atau tidak dulu).
 */
return new class extends Migration
{
    /**
     * Daftar tabel yang perlu index `status`.
     * Format: table => [columns that need index]
     */
    private array $statusTables = [
        'doc_sales'              => ['status'],
        'doc_adjustment'         => ['status'],
        'doc_transfer'           => ['status'],
        'doc_repack'             => ['status'],
        'doc_stock_opname'       => ['status'],
        'doc_hpp_correction'     => ['status'],
        'doc_purchase_order'     => ['status'],
        'doc_purchase_return'    => ['status'],
        'doc_pembayaran_hutang'  => ['status'],
        'doc_price_change'       => ['status'],
        'doc_promo'              => ['status'],
    ];

    /**
     * Daftar tabel yang perlu index `nomor_dokumen`.
     */
    private array $nomorDokumenTables = [
        'doc_sales',
        'doc_adjustment',
        'doc_transfer',
        'doc_repack',
        'doc_stock_opname',
        'doc_hpp_correction',
        'doc_purchase_order',
        'doc_purchase_return',
        'doc_pembayaran_hutang',
        'doc_price_change',
    ];

    public function up(): void
    {
        // Status indexes
        foreach ($this->statusTables as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                foreach ($columns as $col) {
                    if (Schema::hasColumn($tableName, $col) && !$this->indexExists($tableName, "{$tableName}_{$col}_index")) {
                        $table->index($col);
                    }
                }
            });
        }

        // Nomor dokumen indexes
        foreach ($this->nomorDokumenTables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'nomor_dokumen') && !$this->indexExists($tableName, "{$tableName}_nomor_dokumen_index")) {
                    $table->index('nomor_dokumen');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->statusTables as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                foreach ($columns as $col) {
                    if ($this->indexExists($tableName, "{$tableName}_{$col}_index")) {
                        $table->dropIndex("{$tableName}_{$col}_index");
                    }
                }
            });
        }

        foreach ($this->nomorDokumenTables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if ($this->indexExists($tableName, "{$tableName}_nomor_dokumen_index")) {
                    $table->dropIndex("{$tableName}_nomor_dokumen_index");
                }
            });
        }
    }

    /**
     * Check apakah index sudah exist (SQLite + MySQL compatible).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $result = \DB::select(
                "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?",
                [$table, $indexName]
            );
            return !empty($result);
        }

        // MySQL/MariaDB
        $result = \DB::select(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$table, $indexName]
        );
        return !empty($result);
    }
};
