<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_hutang', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_hutang', 'nominal_retur')) {
                $table->decimal('nominal_retur', 15, 2)->default(0)->after('nominal_terbayar');
            }
        });

        // Avoid doctrine/dbal: raw MODIFY for nullable FKs
        DB::statement('ALTER TABLE doc_sales_returns MODIFY sales_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE doc_sales_return_detail MODIFY sales_detail_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('supplier_hutang', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_hutang', 'nominal_retur')) {
                $table->dropColumn('nominal_retur');
            }
        });

        DB::statement('ALTER TABLE doc_sales_returns MODIFY sales_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE doc_sales_return_detail MODIFY sales_detail_id BIGINT UNSIGNED NOT NULL');
    }
};
