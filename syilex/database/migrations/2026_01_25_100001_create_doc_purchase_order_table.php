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
        Schema::create('doc_purchase_order', function (Blueprint $table) {
            // IDENTITAS
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique();

            // REFERENSI
            $table->date('tanggal_po');
            $table->foreignId('supplier_id')->constrained('master_supplier');
            $table->foreignId('warehouse_id')->constrained('master_warehouse');

            // SUBTOTAL (Sum detail)
            $table->decimal('subtotal', 15, 2)->default(0);

            // DISKON HEADER (3 Line Bertingkat)
            $table->enum('diskon_1_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_1_nilai', 15, 2)->default(0);
            $table->decimal('diskon_1_hasil', 15, 2)->default(0);
            $table->enum('diskon_2_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_2_nilai', 15, 2)->default(0);
            $table->decimal('diskon_2_hasil', 15, 2)->default(0);
            $table->enum('diskon_3_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('diskon_3_nilai', 15, 2)->default(0);
            $table->decimal('diskon_3_hasil', 15, 2)->default(0);
            $table->decimal('total_diskon_header', 15, 2)->default(0);
            $table->decimal('total_setelah_diskon', 15, 2)->default(0);

            // BIAYA TAMBAHAN (Support % dan Rp)
            $table->enum('biaya_kirim_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('biaya_kirim_nilai', 15, 2)->default(0);
            $table->decimal('biaya_kirim_hasil', 15, 2)->default(0);
            $table->string('biaya_lain_nama', 100)->nullable();
            $table->enum('biaya_lain_tipe', ['percent', 'nominal', 'none'])->default('none');
            $table->decimal('biaya_lain_nilai', 15, 2)->default(0);
            $table->decimal('biaya_lain_hasil', 15, 2)->default(0);
            $table->decimal('total_biaya_tambahan', 15, 2)->default(0);

            // PAJAK (dari Settings)
            $table->decimal('dpp', 15, 2)->default(0);
            $table->string('pajak_nama', 50)->nullable();
            $table->decimal('pajak_persen', 5, 2)->default(0);
            $table->decimal('pajak_nominal', 15, 2)->default(0);

            // TOTAL
            $table->decimal('grand_total', 15, 2)->default(0);

            // TEMPO
            $table->integer('tempo_hari')->default(0);
            $table->date('tanggal_jatuh_tempo')->nullable();

            // NOTES
            $table->text('notes')->nullable();

            // STATUS
            $table->enum('status', ['draft', 'approved'])->default('draft');
            $table->datetime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');

            // AUDIT
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            // INDEXES
            $table->index('tanggal_po');
            $table->index('status');
            $table->index('tanggal_jatuh_tempo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_purchase_order');
    }
};
