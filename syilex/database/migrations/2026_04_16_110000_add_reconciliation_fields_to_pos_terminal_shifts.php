<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_terminal_shifts', function (Blueprint $table) {
            $table->decimal('saldo_fisik', 18, 2)->nullable()->after('ended_by_force');
            $table->decimal('saldo_system', 18, 2)->nullable()->after('saldo_fisik');
            $table->decimal('selisih', 18, 2)->nullable()->after('saldo_system');
            $table->text('closing_notes')->nullable()->after('selisih');
        });
    }

    public function down(): void
    {
        Schema::table('pos_terminal_shifts', function (Blueprint $table) {
            $table->dropColumn(['saldo_fisik', 'saldo_system', 'selisih', 'closing_notes']);
        });
    }
};
