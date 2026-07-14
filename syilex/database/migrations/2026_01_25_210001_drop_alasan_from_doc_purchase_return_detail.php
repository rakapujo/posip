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
        Schema::table('doc_purchase_return_detail', function (Blueprint $table) {
            if (Schema::hasColumn('doc_purchase_return_detail', 'alasan')) {
                $table->dropColumn('alasan');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doc_purchase_return_detail', function (Blueprint $table) {
            $table->string('alasan', 255)->nullable()->after('subtotal');
        });
    }
};
