<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_purchase_return', function (Blueprint $table) {
            $table->foreignId('serial_intake_id')
                ->nullable()
                ->after('po_id')
                ->constrained('doc_serial_intake')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('doc_purchase_return', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serial_intake_id');
        });
    }
};
