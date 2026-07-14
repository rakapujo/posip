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
        Schema::create('doc_hpp_correction', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique();
            $table->dateTime('tanggal_koreksi');
            $table->enum('status', ['draft', 'approved'])->default('draft');
            $table->text('notes')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index('tanggal_koreksi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_hpp_correction');
    }
};
