<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->string('store_name', 150)->nullable()->after('nama_terminal');
            $table->text('store_address')->nullable()->after('store_name');
            $table->string('store_phone', 50)->nullable()->after('store_address');
            $table->string('store_email', 150)->nullable()->after('store_phone');
            $table->string('store_npwp', 30)->nullable()->after('store_email');
            $table->text('receipt_footer')->nullable()->after('store_npwp');
        });
    }

    public function down(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->dropColumn([
                'store_name',
                'store_address',
                'store_phone',
                'store_email',
                'store_npwp',
                'receipt_footer',
            ]);
        });
    }
};
