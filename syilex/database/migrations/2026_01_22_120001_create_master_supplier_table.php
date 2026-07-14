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
        Schema::create('master_supplier', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('kode_supplier', 20)->unique();
            $table->string('nama_supplier', 100);
            $table->string('nama_pic', 100);
            $table->string('telepon', 20);
            $table->string('email', 100)->nullable();
            $table->text('alamat')->nullable();
            $table->string('npwp', 30)->nullable();
            $table->string('bank_nama', 100)->nullable();
            $table->string('bank_rekening', 30)->nullable();
            $table->string('bank_atas_nama', 100)->nullable();
            $table->integer('tempo_default')->default(0)->comment('Default payment term in days');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_supplier');
    }
};
