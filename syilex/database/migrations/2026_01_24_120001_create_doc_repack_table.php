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
        Schema::create('doc_repack', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique();
            $table->foreignId('warehouse_id')->constrained('master_warehouse');
            $table->enum('tipe', ['pecah', 'gabung']);
            $table->dateTime('tanggal');
            $table->decimal('biaya_repack', 15, 2)->default(0);
            $table->decimal('total_cost_input', 15, 2)->default(0);
            $table->decimal('total_cost_output', 15, 2)->default(0);
            $table->enum('status', ['draft', 'approved'])->default('draft');
            $table->text('notes')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->index('status');
            $table->index('tanggal');
            $table->index('tipe');
            $table->index('warehouse_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_repack');
    }
};
