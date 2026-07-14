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
        Schema::table('doc_purchase_order', function (Blueprint $table) {
            $table->string('no_doc_referensi', 100)->nullable()->after('warehouse_id')
                ->comment('Nomor dokumen referensi dari supplier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doc_purchase_order', function (Blueprint $table) {
            $table->dropColumn('no_doc_referensi');
        });
    }
};
