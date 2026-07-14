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
            $table->unsignedSmallInteger('auto_lock_minutes')->nullable()->after('auto_print_report');
        });
    }

    public function down(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->dropColumn('auto_lock_minutes');
        });
    }
};
