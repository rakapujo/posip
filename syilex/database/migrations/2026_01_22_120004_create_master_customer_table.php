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
        Schema::create('master_customer', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('kode_customer', 20)->unique();
            $table->string('nama', 100);
            $table->string('telepon', 20);
            $table->string('email', 100)->nullable();
            $table->text('alamat')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable();
            $table->string('nik', 20)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->foreignId('tipe_customer_id')->nullable()->constrained('master_tipe_customer')->nullOnDelete();
            $table->foreignId('kategori_customer_id')->nullable()->constrained('master_kategori_customer')->nullOnDelete();
            $table->enum('jenis', ['walk_in', 'spesifik'])->default('spesifik');
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
        Schema::dropIfExists('master_customer');
    }
};
