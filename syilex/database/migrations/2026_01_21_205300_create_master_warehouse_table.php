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
        Schema::create('master_warehouse', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('kode_warehouse', 20)->unique();
            $table->string('nama_warehouse', 100);
            $table->text('alamat')->nullable();
            $table->string('pic_name', 100)->nullable();
            $table->string('pic_phone', 20)->nullable();
            $table->boolean('is_saleable')->default(true)->comment('true=POS sales, false=internal/BS');
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
        Schema::dropIfExists('master_warehouse');
    }
};
