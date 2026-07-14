<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fix: MySQL timestamp columns default to ON UPDATE CURRENT_TIMESTAMP
     * which causes started_at to be updated whenever the row is updated.
     * Change to datetime to prevent this behavior.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Change started_at from timestamp to datetime to prevent auto-update
        DB::statement('ALTER TABLE pos_terminal_shifts MODIFY started_at DATETIME NOT NULL');
        DB::statement('ALTER TABLE pos_terminal_shifts MODIFY ended_at DATETIME NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE pos_terminal_shifts MODIFY started_at TIMESTAMP NOT NULL');
        DB::statement('ALTER TABLE pos_terminal_shifts MODIFY ended_at TIMESTAMP NULL');
    }
};
