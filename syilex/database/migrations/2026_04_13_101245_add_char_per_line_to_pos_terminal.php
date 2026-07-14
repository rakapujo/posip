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
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->unsignedTinyInteger('char_per_line')->default(42)->after('paper_width');
        });
    }

    public function down(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->dropColumn('char_per_line');
        });
    }
};
