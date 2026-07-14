<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_promo', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('kode_promo', 20)->unique();
            $table->string('nama_promo', 100);
            $table->text('deskripsi')->nullable();

            // Batasan (siapa yang dapat promo)
            $table->foreignId('customer_type_id')->nullable()->constrained('master_tipe_customer')->nullOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained('master_pos_terminal')->nullOnDelete();

            // Periode (kapan promo aktif)
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai')->nullable();
            $table->time('jam_mulai')->nullable();
            $table->time('jam_selesai')->nullable();

            // Status (computed: active/expired/upcoming dihitung dari tanggal)
            $table->enum('status', ['draft', 'approved', 'inactive'])->default('draft');
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Indexes (untuk scopeEffective)
            $table->index('status');
            $table->index(['status', 'tanggal_mulai', 'tanggal_selesai'], 'idx_promo_effective');
            $table->index('customer_type_id');
            $table->index('terminal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_promo');
    }
};
