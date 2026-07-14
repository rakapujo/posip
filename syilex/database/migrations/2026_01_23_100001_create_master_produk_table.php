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
        Schema::create('master_produk', function (Blueprint $table) {
            // Primary Key
            $table->id();
            $table->char('ulid', 26)->unique();

            // Basic Info
            $table->string('kode_produk', 50)->unique();
            $table->string('barcode', 50)->nullable()->unique();
            $table->string('nama_produk', 255);

            // Relations (all nullable)
            $table->foreignId('brand_id')->nullable()->constrained('master_brand')->nullOnDelete();
            $table->foreignId('tipe_id')->nullable()->constrained('master_tipe')->nullOnDelete();
            $table->foreignId('kategori_id')->nullable()->constrained('master_kategori')->nullOnDelete();
            $table->foreignId('grup_id')->nullable()->constrained('master_grup')->nullOnDelete();

            // Image
            $table->string('gambar', 255)->nullable();

            // Stock
            $table->unsignedInteger('minimum_stok')->default(0);

            // Unit 1 (Terbesar)
            $table->string('unit_1', 30);
            $table->unsignedInteger('konversi_1');
            $table->decimal('harga_1', 15, 2)->default(0);

            // Unit 2
            $table->string('unit_2', 30);
            $table->unsignedInteger('konversi_2');
            $table->decimal('harga_2', 15, 2)->default(0);

            // Unit 3
            $table->string('unit_3', 30);
            $table->unsignedInteger('konversi_3');
            $table->decimal('harga_3', 15, 2)->default(0);

            // Unit 4 (Base - terkecil)
            $table->string('unit_4', 30);
            $table->unsignedInteger('konversi_4')->default(1);
            $table->decimal('harga_4', 15, 2)->default(0);

            // Status
            $table->enum('status', ['active', 'inactive'])->default('active');

            // Audit
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');

            // Indexes
            $table->index('brand_id');
            $table->index('tipe_id');
            $table->index('kategori_id');
            $table->index('grup_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_produk');
    }
};
