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
        // 1. Header Penjualan
        Schema::create('doc_sales', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 30)->unique();
            $table->timestamp('tanggal');
            $table->foreignId('terminal_id')->constrained('master_pos_terminal');
            $table->foreignId('shift_id')->constrained('pos_terminal_shifts');
            $table->foreignId('warehouse_id')->constrained('master_warehouse');
            $table->foreignId('customer_id')->constrained('master_customer');
            $table->decimal('subtotal', 15, 2)->default(0);
            // Disc Nota 3 levels (1&2 auto, 3 manual)
            $table->enum('diskon_nota_1_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_nota_1_nilai', 15, 2)->default(0);
            $table->decimal('diskon_nota_1_hasil', 15, 2)->default(0);
            $table->enum('diskon_nota_2_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_nota_2_nilai', 15, 2)->default(0);
            $table->decimal('diskon_nota_2_hasil', 15, 2)->default(0);
            $table->enum('diskon_nota_3_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_nota_3_nilai', 15, 2)->default(0);
            $table->decimal('diskon_nota_3_hasil', 15, 2)->default(0);
            $table->decimal('total_diskon', 15, 2)->default(0);
            $table->decimal('total_setelah_diskon', 15, 2)->default(0);
            // Biaya Kirim & Biaya Lain-Lain (before tax)
            $table->enum('biaya_kirim_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('biaya_kirim_nilai', 15, 2)->default(0);
            $table->decimal('biaya_kirim_hasil', 15, 2)->default(0);
            $table->enum('biaya_lain_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('biaya_lain_nilai', 15, 2)->default(0);
            $table->decimal('biaya_lain_hasil', 15, 2)->default(0);
            $table->decimal('dpp', 15, 2)->default(0);
            $table->string('pajak_nama', 20)->nullable();
            $table->decimal('pajak_persen', 5, 2)->default(0);
            $table->decimal('pajak_nominal', 15, 2)->default(0);
            $table->decimal('pembulatan', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('total_bayar', 15, 2)->default(0);
            $table->decimal('kembalian', 15, 2)->default(0);
            $table->decimal('total_biaya_pembayaran', 15, 2)->default(0);
            $table->enum('status', ['completed', 'voided'])->default('completed');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['terminal_id', 'shift_id']);
            $table->index('tanggal');
            $table->index('customer_id');
        });

        // 2. Detail Item Penjualan
        Schema::create('doc_sales_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_id')->constrained('doc_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('master_produk');
            $table->string('unit', 20);
            $table->integer('konversi')->default(1);
            $table->decimal('qty', 10, 2);
            $table->decimal('qty_base', 10, 2);
            $table->decimal('harga_satuan', 15, 2);
            $table->enum('diskon_1_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_1_nilai', 15, 2)->default(0);
            $table->decimal('diskon_1_hasil', 15, 2)->default(0);
            $table->enum('diskon_2_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_2_nilai', 15, 2)->default(0);
            $table->decimal('diskon_2_hasil', 15, 2)->default(0);
            $table->enum('diskon_3_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_3_nilai', 15, 2)->default(0);
            $table->decimal('diskon_3_hasil', 15, 2)->default(0);
            $table->enum('diskon_4_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_4_nilai', 15, 2)->default(0);
            $table->decimal('diskon_4_hasil', 15, 2)->default(0);
            $table->enum('diskon_5_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_5_nilai', 15, 2)->default(0);
            $table->decimal('diskon_5_hasil', 15, 2)->default(0);
            $table->decimal('diskon_total', 15, 2)->default(0);
            $table->decimal('jumlah', 15, 2);
            $table->decimal('hpp_at_time', 15, 4)->default(0);
        });

        // 3. Pembayaran Multi-Method
        Schema::create('doc_sales_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_id')->constrained('doc_sales')->cascadeOnDelete();
            $table->foreignId('metode_pembayaran_id')->constrained('master_metode_pembayaran');
            $table->decimal('nominal', 15, 2);
            $table->decimal('biaya_tambahan', 15, 2)->default(0);
            $table->string('reference', 100)->nullable();
        });

        // 4. Header Retur Penjualan
        Schema::create('doc_sales_returns', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 30)->unique();
            $table->timestamp('tanggal');
            $table->foreignId('sales_id')->constrained('doc_sales');
            $table->foreignId('terminal_id')->constrained('master_pos_terminal');
            $table->foreignId('shift_id')->constrained('pos_terminal_shifts');
            $table->foreignId('warehouse_id')->constrained('master_warehouse');
            $table->foreignId('customer_id')->constrained('master_customer');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('pajak_nama', 20)->nullable();
            $table->decimal('pajak_persen', 5, 2)->default(0);
            $table->decimal('pajak_nominal', 15, 2)->default(0);
            $table->decimal('pembulatan', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->enum('refund_method', ['cash', 'credit']);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['terminal_id', 'shift_id']);
            $table->index('sales_id');
        });

        // 5. Detail Retur Penjualan
        Schema::create('doc_sales_return_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('doc_sales_returns')->cascadeOnDelete();
            $table->foreignId('sales_detail_id')->constrained('doc_sales_detail');
            $table->foreignId('product_id')->constrained('master_produk');
            $table->string('unit', 20);
            $table->integer('konversi')->default(1);
            $table->decimal('qty', 10, 2);
            $table->decimal('qty_base', 10, 2);
            $table->decimal('harga_satuan', 15, 2);
            $table->decimal('jumlah', 15, 2);
            $table->decimal('hpp_at_time', 15, 4)->default(0);
        });

        // 6. Kas Masuk/Keluar
        Schema::create('pos_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('terminal_id')->constrained('master_pos_terminal');
            $table->foreignId('shift_id')->constrained('pos_terminal_shifts');
            $table->enum('tipe', ['setor_awal', 'kas_masuk', 'kas_keluar']);
            $table->decimal('nominal', 15, 2);
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['terminal_id', 'shift_id']);
        });

        // 7. Kredit Customer (dari retur)
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('customer_id')->constrained('master_customer');
            $table->foreignId('return_id')->nullable()->constrained('doc_sales_returns')->nullOnDelete();
            $table->decimal('nominal_awal', 15, 2);
            $table->decimal('nominal_terpakai', 15, 2)->default(0);
            $table->decimal('sisa_kredit', 15, 2);
            $table->enum('status', ['available', 'used', 'expired'])->default('available');
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
        Schema::dropIfExists('pos_cash_transactions');
        Schema::dropIfExists('doc_sales_return_detail');
        Schema::dropIfExists('doc_sales_returns');
        Schema::dropIfExists('doc_sales_payments');
        Schema::dropIfExists('doc_sales_detail');
        Schema::dropIfExists('doc_sales');
    }
};
