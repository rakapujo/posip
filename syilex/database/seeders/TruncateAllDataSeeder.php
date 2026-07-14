<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TruncateAllDataSeeder extends Seeder
{
    /**
     * Truncate all data except settings table.
     * Run: php artisan db:seed --class=TruncateAllDataSeeder
     */
    public function run(): void
    {
        // Tables to KEEP (will not be truncated)
        $keepTables = [
            'settings',
            'migrations',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'password_reset_tokens',
            'sessions',
        ];

        // Get all tables in database
        $tables = DB::select('SHOW TABLES');
        $dbName = DB::getDatabaseName();
        $tableKey = "Tables_in_{$dbName}";

        $allTables = [];
        foreach ($tables as $table) {
            $allTables[] = $table->$tableKey;
        }

        // Filter out tables to keep
        $tablesToTruncate = array_filter($allTables, function ($table) use ($keepTables) {
            return !in_array($table, $keepTables);
        });

        $this->command->info('=== TRUNCATE ALL DATA (EXCEPT SETTINGS) ===');
        $this->command->info('');

        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $this->command->info('Foreign key checks disabled.');

        // Truncate tables in order (details first, then headers, then masters)
        $truncateOrder = [
            // 1. Price Change (newest)
            'price_change_trigger_log',
            'doc_price_change_detail',
            'doc_price_change',

            // 2. Pembayaran Hutang
            'doc_pembayaran_hutang_detail',
            'doc_pembayaran_hutang_deposit',
            'doc_pembayaran_hutang',

            // 3. Purchase Return
            'doc_purchase_return_detail',
            'doc_purchase_return',

            // 4. Purchase Order
            'doc_purchase_order_detail',
            'doc_purchase_order',

            // 5. HPP Correction
            'doc_hpp_correction_detail',
            'doc_hpp_correction',

            // 6. Stock Opname
            'doc_stock_opname_detail',
            'doc_stock_opname',

            // 7. Repack
            'doc_repack_output',
            'doc_repack_input',
            'doc_repack',

            // 8. Transfer
            'doc_transfer_detail',
            'doc_transfer',

            // 9. Adjustment
            'doc_adjustment_detail',
            'doc_adjustment',

            // 10. Stock & History
            'stock_card',
            'inventory_stock',
            'history_harga_beli',
            'supplier_hutang',
            'supplier_deposit',

            // 11. Master tables
            'master_produk',
            'master_customer',
            'master_kategori_customer',
            'master_tipe_customer',
            'master_supplier',
            'master_grup',
            'master_kategori',
            'master_tipe',
            'master_brand',
            'master_metode_pembayaran',
            'master_warehouse',

            // 12. User & Permission (clear pivot tables first)
            'model_has_roles',
            'model_has_permissions',
            'role_has_permissions',
            'personal_access_tokens',
            'users',

            // Note: Keep roles and permissions (they're from seeder)
            // 'roles',
            // 'permissions',
        ];

        $truncated = 0;
        $skipped = 0;

        foreach ($truncateOrder as $table) {
            if (Schema::hasTable($table)) {
                try {
                    DB::table($table)->truncate();
                    $this->command->info("✓ Truncated: {$table}");
                    $truncated++;
                } catch (\Exception $e) {
                    $this->command->error("✗ Failed to truncate {$table}: " . $e->getMessage());
                }
            } else {
                $this->command->warn("- Skipped (not exists): {$table}");
                $skipped++;
            }
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->command->info('');
        $this->command->info('Foreign key checks re-enabled.');

        $this->command->info('');
        $this->command->info("=== COMPLETE ===");
        $this->command->info("Truncated: {$truncated} tables");
        $this->command->info("Skipped: {$skipped} tables");
        $this->command->info('');
        $this->command->info('Settings table preserved.');
        $this->command->info('');
        $this->command->warn('NOTE: Run UserSeeder to recreate default users:');
        $this->command->warn('php artisan db:seed --class=UserSeeder');
    }
}
