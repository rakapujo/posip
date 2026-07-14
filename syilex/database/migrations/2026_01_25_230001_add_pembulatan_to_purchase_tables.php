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
        // Add pembulatan to doc_purchase_order
        Schema::table('doc_purchase_order', function (Blueprint $table) {
            $table->decimal('pembulatan', 15, 2)->default(0)->after('pajak_nominal');
        });

        // Add pembulatan to doc_purchase_return
        Schema::table('doc_purchase_return', function (Blueprint $table) {
            $table->decimal('pembulatan', 15, 2)->default(0)->after('pajak_nominal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doc_purchase_order', function (Blueprint $table) {
            $table->dropColumn('pembulatan');
        });

        Schema::table('doc_purchase_return', function (Blueprint $table) {
            $table->dropColumn('pembulatan');
        });
    }
};
