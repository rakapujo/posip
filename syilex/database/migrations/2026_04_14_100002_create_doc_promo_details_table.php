<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_promo_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_id')->constrained('doc_promo')->cascadeOnDelete();

            // Target (apa yang kena)
            // target_type: 'semua' | 'produk' | 'grup' | 'kategori'
            // target_id: FK ke master_produk/master_grup/master_kategori (null jika 'semua')
            $table->enum('target_type', ['semua', 'produk', 'grup', 'kategori'])->default('semua');
            $table->unsignedBigInteger('target_id')->nullable();

            // Syarat
            $table->integer('min_qty')->default(1);

            // 4 Line Diskon (mengisi slot diskon_1 s/d diskon_4 di item)
            $table->enum('diskon_1_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_1_nilai', 15, 2)->default(0);
            $table->enum('diskon_2_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_2_nilai', 15, 2)->default(0);
            $table->enum('diskon_3_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_3_nilai', 15, 2)->default(0);
            $table->enum('diskon_4_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_4_nilai', 15, 2)->default(0);

            $table->string('keterangan', 100)->nullable();

            $table->timestamps();

            // Index untuk matching (per promo, filter by target)
            $table->index(['promo_id', 'target_type', 'target_id'], 'idx_promo_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_promo_details');
    }
};
