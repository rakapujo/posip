<?php

namespace App\Actions\Sales;

use App\Models\DocSales;
use App\Models\DocSalesReturn;
use App\Models\DocSalesReturnDetail;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\PosCashTransaction;
use App\Models\PosTerminalShift;
use App\Models\StockCard;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\Sales\Concerns\RevertsSerialUnits;

class ProcessSalesReturnAction
{
    use RequiresAuthenticatedUser;
    use RevertsSerialUnits;

    /**
     * Process a sales return.
     *
     * @param array $data Validated return data
     * @return DocSalesReturn
     */
    public function execute(array $data): DocSalesReturn
    {
        $this->ensureAuthenticated();

        $sales = DocSales::with('details.returnDetails')->findOrFail($data['sales_id']);

        // Validate: sales must be completed
        if (!$sales->isCompleted()) {
            throw ValidationException::withMessages([
                'sales_id' => ['Transaksi asal sudah di-void, tidak dapat diretur.'],
            ]);
        }

        // Validate return items (always in base unit)
        $returnItems = $data['items']; // [{sales_detail_id, product_id, qty, harga_per_base, serial_unit_ids?}]
        $errors = [];

        // Produk serial mana saja (untuk validasi SN)
        $serialMap = MasterProduk::whereIn('id', array_column($returnItems, 'product_id'))
            ->pluck('is_serial', 'id');

        foreach ($returnItems as $i => $item) {
            $qty = (float) ($item['qty'] ?? 0);
            if ($qty <= 0) {
                continue; // Skip zero qty
            }

            $salesDetail = $sales->details->firstWhere('id', $item['sales_detail_id']);
            if (!$salesDetail) {
                $errors[] = "Detail penjualan #{$item['sales_detail_id']} tidak ditemukan.";
                continue;
            }

            // Calculate total already returned for this detail (in base unit)
            $totalReturnedBase = (float) $salesDetail->returnDetails->sum('qty_base');
            $maxReturnableBase = (float) $salesDetail->qty_base - $totalReturnedBase;

            if ($qty > $maxReturnableBase) {
                $product = MasterProduk::find($item['product_id']);
                $errors[] = "Qty retur {$product->nama_produk} melebihi batas. Max: {$maxReturnableBase} PCS.";
            }

            // Produk serial: wajib pilih SN, jumlah SN harus sama dengan qty retur
            if ($serialMap[$item['product_id']] ?? false) {
                $snCount = is_array($item['serial_unit_ids'] ?? null) ? count($item['serial_unit_ids']) : 0;
                if ($snCount < 1) {
                    $errors[] = 'Produk serial wajib memilih nomor seri (SN) yang diretur.';
                } elseif ((float) $snCount !== $qty) {
                    $errors[] = "Jumlah SN ({$snCount}) tidak sama dengan qty retur ({$qty}).";
                }
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages(['items' => $errors]);
        }

        // Filter out zero qty items
        $returnItems = array_filter($returnItems, fn ($item) => (float) ($item['qty'] ?? 0) > 0);

        if (empty($returnItems)) {
            throw ValidationException::withMessages([
                'items' => ['Tidak ada item yang diretur.'],
            ]);
        }

        return DB::transaction(function () use ($data, $sales, $returnItems) {
            $warehouseId = $data['warehouse_id'];
            $productIds = array_column($returnItems, 'product_id');

            // Re-lock shift row untuk prevent race: admin force-release antara controller
            // cek isActive() dan commit di sini. Tanpa lock, retur bisa ter-commit ke shift
            // yang sudah ditutup (silent data drift di laporan shift + kas retur).
            $shift = PosTerminalShift::where('id', $data['shift_id'])->lockForUpdate()->first();
            if (!$shift || $shift->ended_at !== null) {
                throw ValidationException::withMessages([
                    'shift' => ['Shift sudah ditutup. Silakan refresh halaman dan mulai shift baru.'],
                ]);
            }

            // Lock rows
            $stocks = InventoryStock::where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $products = MasterProduk::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Re-calculate harga_per_base from original sales data (don't trust frontend)
            $salesSubtotal = (float) $sales->subtotal;
            $totalSetelahDiskon = (float) $sales->total_setelah_diskon;
            $pajakPersen = (float) $sales->pajak_persen;
            $pool = $totalSetelahDiskon * (1 + $pajakPersen / 100);

            $detailsById = $sales->details->keyBy('id');
            $returnItems = array_map(function ($item) use ($salesSubtotal, $pool, $detailsById) {
                $detail = $detailsById[$item['sales_detail_id']] ?? null;
                if ($detail) {
                    $lineJumlah = (float) $detail->jumlah;
                    $proporsi = $salesSubtotal > 0 ? $lineJumlah / $salesSubtotal : 0;
                    $totalPembelian = $proporsi * $pool;
                    $qtyBase = (float) $detail->qty_base;
                    $item['harga_per_base'] = $qtyBase > 0 ? $totalPembelian / $qtyBase : 0;
                }
                return $item;
            }, $returnItems);

            $subtotal = array_sum(array_map(function ($item) {
                return (float) $item['qty'] * (float) $item['harga_per_base'];
            }, $returnItems));

            // For return, subtotal already includes tax (prorated)
            $subtotal = round($subtotal, 2);

            // Apply rounding using sales rounding settings
            $rounded = SettingService::applyRounding($subtotal, 'sales');
            $pembulatan = $rounded - $subtotal;
            $grandTotal = $rounded;

            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber('sales_return', 'doc_sales_returns');

            // Create return header
            // Note: Since harga_per_base already includes prorated tax, we store grand_total directly
            // The subtotal here equals sum of prorated values (tax already included)
            $salesReturn = DocSalesReturn::create([
                'nomor_dokumen' => $nomorDokumen,
                'tanggal' => now(),
                'sales_id' => $sales->id,
                'terminal_id' => $data['terminal_id'],
                'shift_id' => $data['shift_id'],
                'warehouse_id' => $warehouseId,
                'customer_id' => $sales->customer_id,
                'subtotal' => $subtotal,
                'pajak_nama' => $sales->pajak_nama,
                'pajak_persen' => 0, // Tax already included in prorated price
                'pajak_nominal' => 0,
                'pembulatan' => $pembulatan,
                'grand_total' => $grandTotal,
                'refund_method' => $data['refund_method'],
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            StockCard::$skipObserver = true;

            // Track running stock per product (to handle multiple lines of same product)
            $runningStocks = [];
            foreach ($stocks as $productId => $stock) {
                $runningStocks[$productId] = (float) $stock->qty;
            }

            try {
                // Create details and restore stock
                foreach ($returnItems as $item) {
                    $product = $products[$item['product_id']];
                    $currentStock = $runningStocks[$item['product_id']] ?? 0;
                    $hppBefore = (float) $product->avg_cost;
                    $isSerial = (bool) $product->is_serial && !empty($item['serial_unit_ids']);

                    $baseUnit = $product->unit_1 ?? 'PCS';
                    $qty = (float) $item['qty'];
                    $hargaPerBase = (float) $item['harga_per_base'];
                    $jumlah = round($qty * $hargaPerBase, 2);

                    if ($isSerial) {
                        // Kembalikan unit terpilih → tersedia + movement IN; rekalkulasi avg (Metode A)
                        $reverted = $this->revertSoldUnits(
                            $item['serial_unit_ids'],
                            (int) $sales->id,
                            (int) $item['product_id'],
                            (int) $warehouseId,
                            'SALES_RETURN',
                            (int) $salesReturn->id,
                            $nomorDokumen,
                            $salesReturn->tanggal,
                            (int) $item['sales_detail_id']
                        );
                        $hppAfter = $this->recomputeSerialAvgCost($product);
                        // hpp baris & valuasi = rata cost_per_unit unit yang dikembalikan
                        $cost = round((float) $reverted->sum(fn ($u) => (float) $u->cost_per_unit) / $reverted->count(), 4);
                        $serialIds = $reverted->pluck('ulid')->all();
                    } else {
                        // Retail: hpp dari sales detail; restore avg bila sempat ter-reset 0
                        $salesDetail = $sales->details->firstWhere('id', $item['sales_detail_id']);
                        $hppSales = $salesDetail ? (float) $salesDetail->hpp_at_time : $hppBefore;
                        $hppAfter = $hppBefore;
                        if ($hppBefore == 0 && $hppSales > 0) {
                            $hppAfter = $hppSales;
                            $product->avg_cost = $hppAfter;
                            $product->save();
                            $product->syncAvgCostToInventoryStocks();
                        }
                        $cost = $hppAfter;
                        $serialIds = null;
                    }

                    DocSalesReturnDetail::create([
                        'return_id' => $salesReturn->id,
                        'sales_detail_id' => $item['sales_detail_id'],
                        'product_id' => $item['product_id'],
                        'unit' => $baseUnit,
                        'konversi' => 1, // Always base unit
                        'qty' => $qty,
                        'qty_base' => $qty, // Same as qty since always base unit
                        'harga_satuan' => $hargaPerBase,
                        'jumlah' => $jumlah,
                        'hpp_at_time' => $cost,
                        'serial_unit_ids' => $serialIds,
                    ]);

                    // Restore stock (qty is already in base unit)
                    $newStock = $currentStock + $qty;

                    // Update running stock for next iteration of same product
                    $runningStocks[$item['product_id']] = $newStock;

                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $item['product_id'],
                            'warehouse_id' => $warehouseId,
                        ],
                        [
                            'qty' => $newStock,
                            'avg_cost' => $hppAfter,
                        ]
                    );

                    // Record stock card — SALES_RETURN
                    StockCard::record([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $warehouseId,
                        'transaction_type' => 'SALES_RETURN',
                        'transaction_id' => $salesReturn->id,
                        'transaction_no' => $nomorDokumen,
                        'tanggal' => $salesReturn->tanggal,
                        'qty_in' => $qty,
                        'qty_out' => 0,
                        'cost_per_unit' => $cost,
                        'avg_cost_before' => $hppBefore,
                        'avg_cost_after' => $hppAfter,
                        'notes' => "Retur dari {$sales->nomor_dokumen}",
                    ]);
                }
            } finally {
                StockCard::$skipObserver = false;
            }

            // Handle refund - always cash (credit feature removed)
            PosCashTransaction::create([
                'terminal_id' => $data['terminal_id'],
                'shift_id' => $data['shift_id'],
                'tipe' => 'kas_keluar',
                'nominal' => $grandTotal,
                'keterangan' => "Refund retur {$nomorDokumen}",
                'created_by' => Auth::id(),
            ]);

            // Load relations
            $salesReturn->load([
                'details.product:id,ulid,kode_produk,nama_produk',
                'sales:id,ulid,nomor_dokumen',
                'customer:id,ulid,kode_customer,nama',
            ]);

            return $salesReturn;
        });
    }
}
