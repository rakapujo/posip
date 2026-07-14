<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite enforces enum CHECK constraint from original create migration.
            // Drop and recreate column as a plain string to allow new values.
            Schema::table('price_change_trigger_log', function (Blueprint $table) {
                $table->dropColumn('trigger_type');
            });
            Schema::table('price_change_trigger_log', function (Blueprint $table) {
                $table->string('trigger_type', 20)->after('triggered_at');
            });
            return;
        }

        DB::statement("ALTER TABLE price_change_trigger_log MODIFY COLUMN trigger_type ENUM('auto', 'manual', 'activity', 'login', 'cron') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE price_change_trigger_log MODIFY COLUMN trigger_type ENUM('auto', 'manual') NOT NULL");
    }
};
