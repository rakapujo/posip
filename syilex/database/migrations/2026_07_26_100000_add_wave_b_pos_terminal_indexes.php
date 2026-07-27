<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave B: hot-path indexes for terminal list + active shift lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            if (! $this->indexExists('master_pos_terminal', 'master_pos_terminal_status_index')) {
                $table->index('status');
            }
        });

        Schema::table('pos_terminal_shifts', function (Blueprint $table) {
            if (! $this->indexExists('pos_terminal_shifts', 'pos_terminal_shifts_terminal_id_ended_at_index')) {
                $table->index(['terminal_id', 'ended_at']);
            }
            if (! $this->indexExists('pos_terminal_shifts', 'pos_terminal_shifts_user_id_ended_at_index')) {
                $table->index(['user_id', 'ended_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            if ($this->indexExists('master_pos_terminal', 'master_pos_terminal_status_index')) {
                $table->dropIndex('master_pos_terminal_status_index');
            }
        });

        Schema::table('pos_terminal_shifts', function (Blueprint $table) {
            if ($this->indexExists('pos_terminal_shifts', 'pos_terminal_shifts_terminal_id_ended_at_index')) {
                $table->dropIndex('pos_terminal_shifts_terminal_id_ended_at_index');
            }
            if ($this->indexExists('pos_terminal_shifts', 'pos_terminal_shifts_user_id_ended_at_index')) {
                $table->dropIndex('pos_terminal_shifts_user_id_ended_at_index');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $index) {
                if (($index->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $db = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$db, $table, $indexName]
        );

        return ((int) ($row->c ?? 0)) > 0;
    }
};
