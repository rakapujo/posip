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
        Schema::create('master_metode_pembayaran', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('kode_pembayaran', 20)->unique();
            $table->string('nama_pembayaran', 100);
            $table->enum('metode', ['tunai', 'non_tunai']);
            $table->enum('jenis', ['bank', 'qris', 'credit_card', 'debit_card', 'e_wallet', 'lainnya'])->nullable();
            $table->string('nama_akun', 100)->nullable();
            $table->string('nomor_akun', 50)->nullable();
            $table->string('logo', 255)->nullable();
            $table->string('qr_code', 255)->nullable();
            $table->enum('biaya_tambahan_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('biaya_tambahan_nilai', 15, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('metode');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_metode_pembayaran');
    }
};
