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
        Schema::create('master_pos_terminal', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('kode_terminal', 20)->unique();
            $table->string('nama_terminal', 100);
            $table->foreignId('warehouse_id')->constrained('master_warehouse');
            $table->foreignId('default_customer_id')->nullable()->constrained('master_customer')->nullOnDelete();
            $table->foreignId('default_metode_pembayaran_id')->nullable()->constrained('master_metode_pembayaran')->nullOnDelete();
            $table->unsignedBigInteger('template_struk_id')->nullable();
            $table->string('default_printer', 100)->nullable();
            $table->boolean('izinkan_retur')->default(true);
            $table->integer('durasi_retur')->nullable()->comment('0=shift ini, 1+=hari, NULL=unlimited');
            $table->foreignId('active_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('keterangan')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('pos_terminal_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained('master_pos_terminal')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['terminal_id', 'user_id']);
        });

        Schema::create('pos_terminal_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained('master_pos_terminal')->cascadeOnDelete();
            $table->foreignId('metode_pembayaran_id')->constrained('master_metode_pembayaran')->cascadeOnDelete();

            $table->unique(['terminal_id', 'metode_pembayaran_id'], 'pos_terminal_payment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_terminal_payment_methods');
        Schema::dropIfExists('pos_terminal_users');
        Schema::dropIfExists('master_pos_terminal');
    }
};
