<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ResetController extends BaseApiController
{
    /**
     * Get row counts for all resettable tables
     */
    public function counts()
    {
        if (! auth()->user()->can('settings.reset')) {
            return $this->forbidden();
        }

        $counts = [
            // Master
            'brand' => DB::table('master_brand')->count(),
            'tipe' => DB::table('master_tipe')->count(),
            'kategori' => DB::table('master_kategori')->count(),
            'grup' => DB::table('master_grup')->count(),
            'supplier' => DB::table('master_supplier')->count(),
            'customer' => DB::table('master_customer')->count(),
            'tipe_customer' => DB::table('master_tipe_customer')->count(),
            'kategori_customer' => DB::table('master_kategori_customer')->count(),
            'warehouse' => DB::table('master_warehouse')->count(),
            'metode_pembayaran' => DB::table('master_metode_pembayaran')->count(),
            'produk' => DB::table('master_produk')->count(),
            'pos_terminal' => DB::table('master_pos_terminal')->count(),

            // Transaksi
            'purchase_order' => DB::table('doc_purchase_order')->count(),
            'purchase_return' => DB::table('doc_purchase_return')->count(),
            'sales' => DB::table('doc_sales')->count(),
            'pembayaran_hutang' => DB::table('doc_pembayaran_hutang')->count(),
            'pembayaran_piutang' => DB::table('doc_pembayaran_piutang')->count(),
            'customer_piutang' => DB::table('customer_piutang')->count(),
            'customer_deposit' => DB::table('customer_deposit')->count(),
            'adjustment' => DB::table('doc_adjustment')->count(),
            'transfer' => DB::table('doc_transfer')->count(),
            'repack' => DB::table('doc_repack')->count(),
            'stock_opname' => DB::table('doc_stock_opname')->count(),
            'hpp_correction' => DB::table('doc_hpp_correction')->count(),
            'price_change' => DB::table('doc_price_change')->count(),
            'promo' => DB::table('doc_promo')->count(),
            'shift' => DB::table('pos_terminal_shifts')->count(),
            'supplier_deposit' => DB::table('supplier_deposit')->count(),

            // Modul Serial (A+)
            'serial_intake' => DB::table('doc_serial_intake')->count(),
            'serial_change' => DB::table('doc_serial_change')->count(),
            'serial_hpp_correction' => DB::table('doc_serial_hpp_correction')->count(),

            // Inventory
            'inventory_stock' => DB::table('inventory_stock')->count(),
            'stock_card' => DB::table('stock_card')->count(),
            'serial_units' => DB::table('serial_units')->count(),
            'serial_unit_movements' => DB::table('serial_unit_movements')->count(),

            // Settings
            'settings' => DB::table('settings')->count(),
        ];

        return $this->success($counts);
    }

    /**
     * Reset tables by group or individual
     */
    public function reset(Request $request)
    {
        if (! auth()->user()->can('settings.reset')) {
            return $this->forbidden();
        }

        $request->validate([
            'target' => 'required|string',
            'password' => 'required|string',
            'backup_acknowledged' => 'required|accepted',
        ], [
            'backup_acknowledged.required' => 'Konfirmasi backup wajib sebelum reset.',
            'backup_acknowledged.accepted' => 'Konfirmasi backup wajib sebelum reset.',
        ]);

        // Verify password
        if (! Hash::check($request->password, auth()->user()->password)) {
            return $this->error('Password salah', 422);
        }

        $target = $request->target;

        if ($deny = $this->refuseIfUnsafeGranular($target)) {
            return $deny;
        }

        try {
            $this->toggleForeignKeyChecks(false);

            switch ($target) {
                // ── Group resets ──
                case 'all':
                    $this->resetTransaksi();
                    $this->resetMaster();
                    $this->resetSettings();
                    break;

                case 'master':
                    $this->resetTransaksi(); // must reset transactions first (FK dependency)
                    $this->resetMaster();
                    break;

                case 'transaksi':
                    $this->resetTransaksi();
                    break;

                    // ── Individual master tables ──
                case 'brand':
                    DB::table('master_brand')->truncate();
                    break;

                case 'tipe':
                    DB::table('master_tipe')->truncate();
                    break;

                case 'kategori':
                    DB::table('master_kategori')->truncate();
                    break;

                case 'grup':
                    DB::table('master_grup')->truncate();
                    break;

                case 'supplier':
                    $this->truncateTables([
                        'supplier_hutang',
                        'supplier_deposit',
                        'doc_pembayaran_hutang_deposit',
                        'doc_pembayaran_hutang_detail',
                        'doc_pembayaran_hutang',
                        'doc_purchase_return_detail',
                        'doc_purchase_return',
                        'doc_purchase_order_detail',
                        'doc_purchase_order',
                        'doc_serial_change_detail',
                        'doc_serial_change',
                        'doc_serial_hpp_correction_detail',
                        'doc_serial_hpp_correction',
                        'serial_unit_movements',
                        'serial_units',
                        'doc_serial_intake',
                        'master_supplier',
                    ]);
                    break;

                case 'customer':
                    $this->truncateTables([
                        'doc_pembayaran_piutang_deposit',
                        'doc_pembayaran_piutang_detail',
                        'doc_pembayaran_piutang',
                        'customer_piutang',
                        'customer_deposit',
                        'doc_sales_return_detail',
                        'doc_sales_returns',
                        'doc_sales_payments',
                        'doc_sales_detail',
                        'doc_sales',
                        'master_customer',
                    ]);
                    break;

                case 'tipe_customer':
                    DB::table('master_tipe_customer')->truncate();
                    break;

                case 'kategori_customer':
                    DB::table('master_kategori_customer')->truncate();
                    break;

                case 'warehouse':
                    DB::table('master_warehouse')->truncate();
                    break;

                case 'metode_pembayaran':
                    DB::table('master_metode_pembayaran')->truncate();
                    break;

                case 'produk':
                    $this->truncateTables([
                        'doc_pembayaran_piutang_deposit',
                        'doc_pembayaran_piutang_detail',
                        'doc_pembayaran_piutang',
                        'customer_piutang',
                        'customer_deposit',
                        'doc_pembayaran_hutang_deposit',
                        'doc_pembayaran_hutang_detail',
                        'doc_pembayaran_hutang',
                        'supplier_hutang',
                        'supplier_deposit',
                        'stock_card',
                        'inventory_stock',
                        'history_harga_beli',
                        'price_change_trigger_log',
                        'doc_price_change_detail',
                        'doc_price_change',
                        'doc_hpp_correction_detail',
                        'doc_hpp_correction',
                        'doc_stock_opname_detail',
                        'doc_stock_opname',
                        'doc_repack_output',
                        'doc_repack_input',
                        'doc_repack',
                        'doc_transfer_detail',
                        'doc_transfer',
                        'doc_adjustment_detail',
                        'doc_adjustment',
                        'doc_sales_return_detail',
                        'doc_sales_returns',
                        'doc_sales_payments',
                        'doc_sales_detail',
                        'doc_sales',
                        'doc_purchase_return_detail',
                        'doc_purchase_return',
                        'doc_purchase_order_detail',
                        'doc_purchase_order',
                        'doc_serial_change_detail',
                        'doc_serial_change',
                        'doc_serial_hpp_correction_detail',
                        'doc_serial_hpp_correction',
                        'serial_unit_movements',
                        'serial_units',
                        'doc_serial_intake',
                        'doc_promo_details',
                        'doc_promo',
                        'master_produk',
                    ]);
                    break;

                case 'pos_terminal':
                    $this->truncateTables([
                        'pos_cash_transactions',
                        'doc_pembayaran_piutang_deposit',
                        'doc_pembayaran_piutang_detail',
                        'doc_pembayaran_piutang',
                        'customer_piutang',
                        'customer_deposit',
                        'doc_sales_return_detail',
                        'doc_sales_returns',
                        'doc_sales_payments',
                        'doc_sales_detail',
                        'doc_sales',
                        'pos_terminal_shifts',
                        'pos_terminal_payment_methods',
                        'pos_terminal_users',
                        'master_pos_terminal',
                    ]);
                    break;

                    // ── Individual transaction tables ──
                case 'purchase_order':
                    $this->truncateTables([
                        'supplier_hutang',
                        'doc_pembayaran_hutang_deposit',
                        'doc_pembayaran_hutang_detail',
                        'doc_pembayaran_hutang',
                        'doc_purchase_return_detail',
                        'doc_purchase_return',
                        'doc_purchase_order_detail',
                        'doc_purchase_order',
                    ]);
                    break;

                case 'purchase_return':
                    $this->truncateTables([
                        'doc_purchase_return_detail',
                        'doc_purchase_return',
                    ]);
                    break;

                case 'sales':
                    $this->truncateTables([
                        'doc_pembayaran_piutang_deposit',
                        'doc_pembayaran_piutang_detail',
                        'doc_pembayaran_piutang',
                        'customer_piutang',
                        'customer_deposit',
                        'doc_sales_return_detail',
                        'doc_sales_returns',
                        'doc_sales_payments',
                        'doc_sales_detail',
                        'doc_sales',
                        'pos_cash_transactions',
                    ]);
                    break;

                case 'pembayaran_piutang':
                    $this->truncateTables([
                        'doc_pembayaran_piutang_deposit',
                        'doc_pembayaran_piutang_detail',
                        'doc_pembayaran_piutang',
                    ]);
                    break;

                case 'customer_piutang':
                    $this->truncateTables([
                        'doc_pembayaran_piutang_deposit',
                        'doc_pembayaran_piutang_detail',
                        'doc_pembayaran_piutang',
                        'customer_piutang',
                    ]);
                    break;

                case 'customer_deposit':
                    $this->truncateTables([
                        'doc_pembayaran_piutang_deposit',
                        'customer_deposit',
                    ]);
                    break;

                case 'pembayaran_hutang':
                    $this->truncateTables([
                        'doc_pembayaran_hutang_deposit',
                        'doc_pembayaran_hutang_detail',
                        'doc_pembayaran_hutang',
                    ]);
                    break;

                case 'adjustment':
                    if ($resp = $this->refuseIfHasNonDraft('doc_adjustment', 'adjustment')) {
                        return $resp;
                    }
                    $this->truncateTables([
                        'doc_adjustment_detail',
                        'doc_adjustment',
                    ]);
                    break;

                case 'transfer':
                    if ($resp = $this->refuseIfHasNonDraft('doc_transfer', 'transfer')) {
                        return $resp;
                    }
                    $this->truncateTables([
                        'doc_transfer_detail',
                        'doc_transfer',
                    ]);
                    break;

                case 'repack':
                    if ($resp = $this->refuseIfHasNonDraft('doc_repack', 'repack')) {
                        return $resp;
                    }
                    $this->truncateTables([
                        'doc_repack_output',
                        'doc_repack_input',
                        'doc_repack',
                    ]);
                    break;

                case 'stock_opname':
                    if ($resp = $this->refuseIfHasNonDraft('doc_stock_opname', 'stock_opname')) {
                        return $resp;
                    }
                    $this->truncateTables([
                        'doc_stock_opname_detail',
                        'doc_stock_opname',
                    ]);
                    break;

                case 'hpp_correction':
                    if ($resp = $this->refuseIfHasNonDraft('doc_hpp_correction', 'hpp_correction')) {
                        return $resp;
                    }
                    $this->truncateTables([
                        'doc_hpp_correction_detail',
                        'doc_hpp_correction',
                    ]);
                    break;

                case 'price_change':
                    if ($resp = $this->refuseIfHasNonDraft('doc_price_change', 'price_change')) {
                        return $resp;
                    }
                    $this->truncateTables([
                        'price_change_trigger_log',
                        'doc_price_change_detail',
                        'doc_price_change',
                    ]);
                    break;

                case 'promo':
                    $this->truncateTables([
                        'doc_promo_details',
                        'doc_promo',
                    ]);
                    break;

                case 'serial_intake':
                    DB::table('supplier_hutang')->whereNotNull('serial_intake_id')->delete();
                    DB::table('history_harga_beli')->whereNotNull('serial_intake_id')->delete();
                    $this->truncateTables([
                        'doc_serial_change_detail',
                        'doc_serial_change',
                        'doc_serial_hpp_correction_detail',
                        'doc_serial_hpp_correction',
                        'serial_unit_movements',
                        'serial_units',
                        'doc_serial_intake',
                    ]);
                    break;

                case 'serial_change':
                    if ($resp = $this->refuseIfHasNonDraft('doc_serial_change', 'serial_change')) {
                        return $resp;
                    }
                    $this->truncateTables([
                        'doc_serial_change_detail',
                        'doc_serial_change',
                    ]);
                    break;

                case 'serial_hpp_correction':
                    if ($resp = $this->refuseIfHasNonDraft('doc_serial_hpp_correction', 'serial_hpp_correction')) {
                        return $resp;
                    }
                    $this->truncateTables([
                        'doc_serial_hpp_correction_detail',
                        'doc_serial_hpp_correction',
                    ]);
                    break;

                case 'shift':
                    $this->truncateTables([
                        'pos_cash_transactions',
                        'doc_pembayaran_piutang_deposit',
                        'doc_pembayaran_piutang_detail',
                        'doc_pembayaran_piutang',
                        'customer_piutang',
                        'customer_deposit',
                        'doc_sales_return_detail',
                        'doc_sales_returns',
                        'doc_sales_payments',
                        'doc_sales_detail',
                        'doc_sales',
                        'pos_terminal_shifts',
                    ]);
                    break;

                case 'supplier_deposit':
                    DB::table('supplier_deposit')->truncate();
                    break;

                    // ── Inventory ──
                case 'inventory':
                    $this->truncateTables([
                        'serial_unit_movements',
                        'serial_units',
                        'stock_card',
                        'inventory_stock',
                    ]);
                    break;

                    // ── Settings ──
                case 'settings':
                    $this->resetSettings();
                    break;

                default:
                    return $this->error("Target reset '$target' tidak valid", 422);
            }

            activity('Reset')
                ->causedBy(auth()->user())
                ->withProperties([
                    'target' => $target,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->log("Reset data target: {$target}");

            Log::warning('Database reset executed', [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name,
                'target' => $target,
                'ip' => $request->ip(),
            ]);

            return $this->success(null, "Reset '$target' berhasil");
        } catch (\Exception $e) {
            Log::error('Database reset failed', [
                'user_id' => auth()->id(),
                'target' => $target,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Gagal mereset data: '.$e->getMessage(), 500);
        } finally {
            $this->toggleForeignKeyChecks(true);
        }
    }

    /**
     * Block granular resets that leave stock/serial/AR-AP inconsistent.
     * Prefer group targets: transaksi / master / all.
     */
    private function refuseIfUnsafeGranular(string $target): ?\Illuminate\Http\JsonResponse
    {
        $useTransaksi = "Reset '{$target}' ditolak karena dapat merusak konsistensi stok/serial/piutang-hutang. Gunakan Reset Transaksi (atau Reset Semua).";
        $useMaster = "Reset '{$target}' ditolak karena masih ada data terkait. Gunakan Reset Master (atau Reset Semua).";

        return match ($target) {
            'sales', 'shift' => (
                DB::table('serial_units')->whereNotNull('sale_id')->exists()
                || DB::table('stock_card')->exists()
            ) ? $this->error($useTransaksi, 422) : null,

            'purchase_order', 'purchase_return' => (
                DB::table('stock_card')->exists()
                || DB::table('serial_units')->exists()
                || DB::table('inventory_stock')->where('qty', '!=', 0)->exists()
            ) ? $this->error($useTransaksi, 422) : null,

            'pembayaran_hutang' => DB::table('supplier_hutang')->exists()
                ? $this->error($useTransaksi, 422) : null,

            'pembayaran_piutang' => DB::table('customer_piutang')->exists()
                ? $this->error($useTransaksi, 422) : null,

            'brand', 'tipe', 'kategori', 'grup' => DB::table('master_produk')->exists()
                ? $this->error($useMaster, 422) : null,

            'tipe_customer', 'kategori_customer' => DB::table('master_customer')->exists()
                ? $this->error($useMaster, 422) : null,

            'warehouse' => (
                DB::table('inventory_stock')->exists()
                || DB::table('stock_card')->exists()
                || DB::table('master_pos_terminal')->exists()
                || DB::table('doc_sales')->exists()
                || DB::table('doc_purchase_order')->exists()
            ) ? $this->error($useMaster, 422) : null,

            'metode_pembayaran' => (
                DB::table('pos_terminal_payment_methods')->exists()
                || DB::table('doc_sales_payments')->exists()
            ) ? $this->error($useMaster, 422) : null,

            'inventory' => (
                DB::table('doc_sales')->exists()
                || DB::table('doc_purchase_order')->exists()
                || DB::table('doc_serial_intake')->exists()
                || DB::table('doc_adjustment')->where('status', '!=', 'draft')->exists()
                || DB::table('doc_transfer')->where('status', '!=', 'draft')->exists()
                || DB::table('doc_repack')->where('status', '!=', 'draft')->exists()
                || DB::table('doc_stock_opname')->where('status', '!=', 'draft')->exists()
            ) ? $this->error(
                "Reset 'inventory' ditolak: masih ada dokumen transaksi/non-draft. Gunakan Reset Transaksi dulu (mengosongkan stok + dokumen).",
                422
            ) : null,

            // ponytail: refuse-only (no cascade repair) — use Reset Transaksi / Master / Semua
            'serial_intake' => (
                DB::table('doc_serial_intake')->where('status', '!=', 'draft')->exists()
                || DB::table('stock_card')->exists()
                || DB::table('inventory_stock')->where('qty', '!=', 0)->exists()
                || DB::table('serial_units')->where('status', '!=', 'pending')->exists()
            ) ? $this->error($useTransaksi, 422) : null,

            'supplier', 'customer', 'pos_terminal' => (
                DB::table('stock_card')->exists()
                || DB::table('inventory_stock')->where('qty', '!=', 0)->exists()
                || DB::table('serial_units')->exists()
            ) ? $this->error($useMaster, 422) : null,

            'customer_piutang' => (
                DB::table('doc_sales')->exists()
                || DB::table('doc_pembayaran_piutang')->exists()
            ) ? $this->error($useTransaksi, 422) : null,

            'customer_deposit' => (
                DB::table('doc_sales')->exists()
                || DB::table('doc_pembayaran_piutang')->exists()
            ) ? $this->error($useTransaksi, 422) : null,

            'supplier_deposit' => (
                DB::table('doc_purchase_order')->exists()
                || DB::table('doc_pembayaran_hutang')->exists()
                || DB::table('supplier_hutang')->exists()
            ) ? $this->error($useTransaksi, 422) : null,

            'promo' => DB::table('doc_sales_detail')->whereNotNull('promo_id')->exists()
                ? $this->error($useTransaksi, 422) : null,

            default => null,
        };
    }

    /**
     * Toggle foreign key checks. MySQL-specific statement, guarded for other drivers.
     */
    private function toggleForeignKeyChecks(bool $enabled): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS='.($enabled ? '1' : '0'));
        }
    }

    /**
     * Truncate multiple tables
     */
    private function truncateTables(array $tables): void
    {
        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }
    }

    /**
     * Block granular doc reset when approved/completed rows exist (avoids stock ledger desync).
     */
    private function refuseIfHasNonDraft(string $table, string $target): ?\Illuminate\Http\JsonResponse
    {
        if (DB::table($table)->where('status', '!=', 'draft')->exists()) {
            return $this->error(
                "Reset '{$target}' ditolak: masih ada dokumen non-draft. Gunakan reset Inventory/Transaksi, atau pastikan semua dokumen masih draft.",
                422
            );
        }

        return null;
    }

    /**
     * Reset all transaction-related tables
     */
    private function resetTransaksi(): void
    {
        $this->truncateTables([
            // Sales & POS
            'pos_cash_transactions',
            'doc_pembayaran_piutang_deposit',
            'doc_pembayaran_piutang_detail',
            'doc_pembayaran_piutang',
            'customer_piutang',
            'customer_deposit',
            'doc_sales_return_detail',
            'doc_sales_returns',
            'doc_sales_payments',
            'doc_sales_detail',
            'doc_sales',
            'pos_terminal_shifts',

            // Purchase
            'supplier_hutang',
            'supplier_deposit',
            'doc_pembayaran_hutang_deposit',
            'doc_pembayaran_hutang_detail',
            'doc_pembayaran_hutang',
            'doc_purchase_return_detail',
            'doc_purchase_return',
            'doc_purchase_order_detail',
            'doc_purchase_order',

            // Pembelian Serial + Perubahan Data Serial + Koreksi HPP Serial (modul serial A+)
            'doc_serial_change_detail',
            'doc_serial_change',
            'doc_serial_hpp_correction_detail',
            'doc_serial_hpp_correction',
            'serial_unit_movements',
            'serial_units',
            'doc_serial_intake',

            // Inventory transactions
            'doc_adjustment_detail',
            'doc_adjustment',
            'doc_transfer_detail',
            'doc_transfer',
            'doc_repack_output',
            'doc_repack_input',
            'doc_repack',
            'doc_stock_opname_detail',
            'doc_stock_opname',
            'doc_hpp_correction_detail',
            'doc_hpp_correction',

            // Price change
            'price_change_trigger_log',
            'doc_price_change_detail',
            'doc_price_change',

            // Promo
            'doc_promo_details',
            'doc_promo',

            // History
            'history_harga_beli',

            // Inventory data
            'stock_card',
            'inventory_stock',
        ]);

        // Reset avg_cost on products
        DB::table('master_produk')->update(['avg_cost' => 0]);
    }

    /**
     * Reset all master tables
     */
    private function resetMaster(): void
    {
        $this->truncateTables([
            'pos_terminal_payment_methods',
            'pos_terminal_users',
            'master_pos_terminal',
            'master_produk',
            'master_supplier',
            'master_customer',
            'master_brand',
            'master_tipe',
            'master_kategori',
            'master_grup',
            'master_tipe_customer',
            'master_kategori_customer',
            'master_warehouse',
            'master_metode_pembayaran',
        ]);
    }

    /**
     * Reset settings to defaults by re-running SettingSeeder
     */
    private function resetSettings(): void
    {
        DB::table('settings')->truncate();

        // Re-run SettingSeeder to restore defaults
        $seeder = new \Database\Seeders\SettingSeeder;
        $seeder->setContainer(app());
        $seeder->setCommand(new class extends \Illuminate\Console\Command
        {
            public function info($string, $verbosity = null) {}
        });
        $seeder->run();
        SettingService::clearCache();
    }
}
