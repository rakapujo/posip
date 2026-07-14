<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_tipe_customer', function (Blueprint $table) {
            $table->enum('diskon_tipe', ['none', 'percent', 'nominal'])->default('none')->after('nama_tipe');
            $table->decimal('diskon_nilai', 15, 2)->default(0)->after('diskon_tipe');
        });

        Schema::table('master_kategori_customer', function (Blueprint $table) {
            $table->enum('diskon_tipe', ['none', 'percent', 'nominal'])->default('none')->after('nama_kategori');
            $table->decimal('diskon_nilai', 15, 2)->default(0)->after('diskon_tipe');
        });
    }

    public function down(): void
    {
        Schema::table('master_tipe_customer', function (Blueprint $table) {
            $table->dropColumn(['diskon_tipe', 'diskon_nilai']);
        });

        Schema::table('master_kategori_customer', function (Blueprint $table) {
            $table->dropColumn(['diskon_tipe', 'diskon_nilai']);
        });
    }
};
