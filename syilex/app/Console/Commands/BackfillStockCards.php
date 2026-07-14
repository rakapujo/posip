<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use App\Models\StockCard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillStockCards extends Command
{
    protected $signature = 'stock:backfill-cards {--dry-run : Show what would be created without creating}';
    protected $description = 'Create initial stock_card entries for inventory_stock records without stock_card entries';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? 'DRY RUN - No changes will be made' : 'Starting backfill...');

        // Find all inventory_stock records that have qty > 0 but no stock_card entries
        $inventoryStocks = InventoryStock::with(['product:id,kode_produk,nama_produk', 'warehouse:id,kode_warehouse,nama_warehouse'])
            ->where('qty', '!=', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('stock_card')
                    ->whereColumn('stock_card.product_id', 'inventory_stock.product_id')
                    ->whereColumn('stock_card.warehouse_id', 'inventory_stock.warehouse_id');
            })
            ->get();

        if ($inventoryStocks->isEmpty()) {
            $this->info('No inventory_stock records need backfilling. All records already have stock_card entries.');
            return Command::SUCCESS;
        }

        $this->info("Found {$inventoryStocks->count()} inventory_stock records to backfill.");

        $created = 0;
        foreach ($inventoryStocks as $stock) {
            $productCode = $stock->product?->kode_produk ?? 'Unknown';
            $warehouseCode = $stock->warehouse?->kode_warehouse ?? 'Unknown';

            $this->line("  - {$productCode} @ {$warehouseCode}: qty={$stock->qty}, avg_cost={$stock->avg_cost}");

            if (!$dryRun) {
                StockCard::$skipObserver = true;

                StockCard::create([
                    'product_id' => $stock->product_id,
                    'warehouse_id' => $stock->warehouse_id,
                    'transaction_type' => 'ADJUSTMENT_IN',
                    'transaction_no' => null,
                    'tanggal' => $stock->created_at ?? now(),
                    'qty_in' => $stock->qty > 0 ? $stock->qty : 0,
                    'qty_out' => $stock->qty < 0 ? abs($stock->qty) : 0,
                    'qty_balance' => $stock->qty,
                    'cost_per_unit' => $stock->avg_cost,
                    'total_cost' => $stock->qty * $stock->avg_cost,
                    'avg_cost_before' => 0,
                    'avg_cost_after' => $stock->avg_cost,
                    'notes' => 'Initial balance (backfilled)',
                ]);

                StockCard::$skipObserver = false;
                $created++;
            }
        }

        if ($dryRun) {
            $this->info("Would create {$inventoryStocks->count()} stock_card entries.");
        } else {
            $this->info("Successfully created {$created} stock_card entries.");
        }

        return Command::SUCCESS;
    }
}
