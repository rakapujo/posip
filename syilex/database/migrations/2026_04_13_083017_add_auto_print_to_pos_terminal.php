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
            $table->boolean('auto_print_receipt')->default(false)->after('auto_open_tray');
            $table->boolean('auto_print_retur')->default(false)->after('auto_print_receipt');
            $table->boolean('auto_print_kas')->default(false)->after('auto_print_retur');
            $table->boolean('auto_print_report')->default(false)->after('auto_print_kas');
        });
    }

    public function down(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->dropColumn(['auto_print_receipt', 'auto_print_retur', 'auto_print_kas', 'auto_print_report']);
        });
    }
};
