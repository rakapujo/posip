<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_sales_detail', function (Blueprint $table) {
            // Audit trail: track which promo applied to this line item (null = no promo)
            $table->foreignId('promo_id')->nullable()->after('jumlah')
                ->constrained('doc_promo')->nullOnDelete();
            $table->index('promo_id');
        });
    }

    public function down(): void
    {
        Schema::table('doc_sales_detail', function (Blueprint $table) {
            $table->dropForeign(['promo_id']);
            $table->dropIndex(['promo_id']);
            $table->dropColumn('promo_id');
        });
    }
};
