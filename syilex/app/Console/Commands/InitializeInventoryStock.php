<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use Illuminate\Console\Command;

class InitializeInventoryStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:init-stock
                            {--warehouse= : Initialize for specific warehouse ID}
                            {--product= : Initialize for specific product ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize inventory stock records for existing products and warehouses';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $warehouseId = $this->option('warehouse');
        $productId = $this->option('product');

        if ($warehouseId) {
            $this->info("Initializing stock for warehouse ID: {$warehouseId}...");
            $count = InventoryStock::initializeForWarehouse((int) $warehouseId);
            $this->info("Created {$count} stock record(s) for warehouse.");
            return Command::SUCCESS;
        }

        if ($productId) {
            $this->info("Initializing stock for product ID: {$productId}...");
            $count = InventoryStock::initializeForProduct((int) $productId);
            $this->info("Created {$count} stock record(s) for product.");
            return Command::SUCCESS;
        }

        // Initialize all missing stock records
        $this->info('Initializing all missing inventory stock records...');
        $this->info('This may take a while depending on the number of products and warehouses.');

        $count = InventoryStock::initializeAll();

        $this->info("Completed! Created {$count} stock record(s).");

        return Command::SUCCESS;
    }
}
