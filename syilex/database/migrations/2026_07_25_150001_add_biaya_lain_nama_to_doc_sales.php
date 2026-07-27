<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_sales', function (Blueprint $table) {
            $table->string('biaya_lain_nama', 100)->nullable()->after('biaya_lain_hasil');
        });
    }

    public function down(): void
    {
        Schema::table('doc_sales', function (Blueprint $table) {
            $table->dropColumn('biaya_lain_nama');
        });
    }
};
