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
        Schema::table('pos_terminal_shifts', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('forced_by');
            $table->timestamp('locked_at')->nullable()->after('is_locked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_terminal_shifts', function (Blueprint $table) {
            $table->dropColumn(['is_locked', 'locked_at']);
        });
    }
};
