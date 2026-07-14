<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('master_produk', function (Blueprint $table) {
            $table->decimal('avg_cost', 15, 4)->default(0)->after('minimum_stok')
                ->comment('Global average cost / HPP');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_produk', function (Blueprint $table) {
            $table->dropColumn('avg_cost');
        });
    }
};
