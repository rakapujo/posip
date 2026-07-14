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
        Schema::create('doc_transfer', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique();
            $table->foreignId('warehouse_from_id')->constrained('master_warehouse');
            $table->foreignId('warehouse_to_id')->constrained('master_warehouse');
            $table->dateTime('tanggal');
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'approved'])->default('draft');
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->index('status');
            $table->index('tanggal');
            $table->index('warehouse_from_id');
            $table->index('warehouse_to_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_transfer');
    }
};
