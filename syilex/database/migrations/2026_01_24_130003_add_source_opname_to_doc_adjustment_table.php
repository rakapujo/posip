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
        Schema::table('doc_adjustment', function (Blueprint $table) {
            $table->enum('source', ['manual', 'opname'])->default('manual')->after('status');
            $table->foreignId('opname_id')->nullable()->after('source')->constrained('doc_stock_opname')->nullOnDelete();

            $table->index('source');
            $table->index('opname_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doc_adjustment', function (Blueprint $table) {
            $table->dropForeign(['opname_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['opname_id']);
            $table->dropColumn(['source', 'opname_id']);
        });
    }
};
