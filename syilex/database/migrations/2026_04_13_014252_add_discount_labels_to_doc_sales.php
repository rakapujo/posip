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
        Schema::table('doc_sales', function (Blueprint $table) {
            $table->string('diskon_nota_1_label', 100)->nullable()->after('diskon_nota_1_hasil');
            $table->string('diskon_nota_2_label', 100)->nullable()->after('diskon_nota_2_hasil');
            $table->string('diskon_nota_3_label', 100)->nullable()->after('diskon_nota_3_hasil');
        });
    }

    public function down(): void
    {
        Schema::table('doc_sales', function (Blueprint $table) {
            $table->dropColumn(['diskon_nota_1_label', 'diskon_nota_2_label', 'diskon_nota_3_label']);
        });
    }
};
