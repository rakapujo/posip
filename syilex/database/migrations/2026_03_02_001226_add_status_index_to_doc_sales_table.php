<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite index (status, tanggal) to doc_sales for dashboard
     * and report queries that filter by status='completed' + date range.
     */
    public function up(): void
    {
        Schema::table('doc_sales', function (Blueprint $table) {
            $table->index(['status', 'tanggal'], 'doc_sales_status_tanggal_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doc_sales', function (Blueprint $table) {
            $table->dropIndex('doc_sales_status_tanggal_index');
        });
    }
};
