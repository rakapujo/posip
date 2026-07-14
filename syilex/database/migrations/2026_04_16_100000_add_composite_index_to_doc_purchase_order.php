<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_purchase_order', function (Blueprint $table) {
            $table->index(['supplier_id', 'tanggal_po'], 'doc_po_supplier_tanggal_index');
        });
    }

    public function down(): void
    {
        Schema::table('doc_purchase_order', function (Blueprint $table) {
            $table->dropIndex('doc_po_supplier_tanggal_index');
        });
    }
};
