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
            $table->unsignedTinyInteger('paper_width')->default(80)->after('auto_print_report');
            $table->string('paper_mode', 10)->default('normal')->after('paper_width');
            $table->unsignedTinyInteger('print_feed_before_cut')->default(4)->after('paper_mode');
        });
    }

    public function down(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->dropColumn(['paper_width', 'paper_mode', 'print_feed_before_cut']);
        });
    }
};
