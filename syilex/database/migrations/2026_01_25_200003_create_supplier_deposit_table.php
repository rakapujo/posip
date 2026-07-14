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
        Schema::create('supplier_deposit', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('supplier_id')->constrained('master_supplier');
            $table->foreignId('retur_id')->constrained('doc_purchase_return');
            $table->date('tanggal');
            $table->decimal('nominal_awal', 15, 2);
            $table->decimal('nominal_terpakai', 15, 2)->default(0);
            $table->decimal('sisa_deposit', 15, 2);
            $table->enum('status', ['available', 'used_partial', 'used_all'])->default('available');
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('supplier_id');
            $table->index('status');
            $table->index(['supplier_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_deposit');
    }
};
