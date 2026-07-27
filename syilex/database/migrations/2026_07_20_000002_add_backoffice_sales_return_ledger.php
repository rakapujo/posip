<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_sales_returns', function (Blueprint $table) {
            $table->decimal('nilai_diakui', 15, 2)->nullable()->after('grand_total');
            $table->decimal('selisih', 15, 2)->nullable()->after('nilai_diakui');
            $table->text('catatan_approval')->nullable()->after('selisih');
        });

        Schema::table('customer_piutang', function (Blueprint $table) {
            $table->decimal('nominal_retur', 15, 2)->default(0)->after('nominal_terbayar');
        });
    }

    public function down(): void
    {
        Schema::table('customer_piutang', function (Blueprint $table) {
            $table->dropColumn('nominal_retur');
        });

        Schema::table('doc_sales_returns', function (Blueprint $table) {
            $table->dropColumn(['nilai_diakui', 'selisih', 'catatan_approval']);
        });
    }
};
