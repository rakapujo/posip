<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\TipeController;
use App\Http\Controllers\Api\V1\KategoriController;
use App\Http\Controllers\Api\V1\GrupController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TipeCustomerController;
use App\Http\Controllers\Api\V1\KategoriCustomerController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\MetodePembayaranController;
use App\Http\Controllers\Api\V1\ProdukController;
use App\Http\Controllers\Api\V1\InventoryStockController;
use App\Http\Controllers\Api\V1\StockCardController;
use App\Http\Controllers\Api\V1\AdjustmentController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\RepackController;
use App\Http\Controllers\Api\V1\StockOpnameController;
use App\Http\Controllers\Api\V1\HppCorrectionController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseReturnController;
use App\Http\Controllers\Api\V1\SupplierHutangController;
use App\Http\Controllers\Api\V1\SupplierDepositController;
use App\Http\Controllers\Api\V1\PembayaranHutangController;
use App\Http\Controllers\Api\V1\PriceChangeController;
use App\Http\Controllers\Api\V1\PromoController;
use App\Http\Controllers\Api\V1\PosTerminalController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\SalesReturnController;
use App\Http\Controllers\Api\V1\CashTransactionController;
use App\Http\Controllers\Api\V1\SalesProductReportController;
use App\Http\Controllers\Api\V1\SalesReportController;
use App\Http\Controllers\Api\V1\SalesFinancialReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\PurchaseReport\DiskonReportController as PurchaseDiskonReportController;
use App\Http\Controllers\Api\V1\PurchaseReport\DropdownsController as PurchaseReportDropdownsController;
use App\Http\Controllers\Api\V1\PurchaseReport\HargaTerakhirReportController;
use App\Http\Controllers\Api\V1\PurchaseReport\PerBarangReportController as PurchasePerBarangReportController;
use App\Http\Controllers\Api\V1\PurchaseReport\PerDokumenReportController as PurchasePerDokumenReportController;
use App\Http\Controllers\Api\V1\PurchaseReport\PerSupplierReportController as PurchasePerSupplierReportController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ResetController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\SerialIntakeController;
use App\Http\Controllers\Api\V1\SerialChangeController;
use App\Http\Controllers\Api\V1\SerialHppCorrectionController;
use App\Http\Controllers\Api\V1\SerialUnitController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ClientErrorLogController;
use App\Http\Controllers\Api\V1\Reports\AnalyticReportExportController;
use App\Http\Controllers\Api\V1\Reports\GrossProfitReportController;
use App\Http\Controllers\Api\V1\Reports\MarginPerBarangReportController;
use App\Http\Controllers\Api\V1\Reports\CashFlowReportController;
use App\Http\Controllers\Api\V1\Reports\KasirPerformanceReportController;
use App\Http\Controllers\Api\V1\Reports\PromoUsageReportController;
use App\Http\Controllers\Api\V1\Reports\ProductPromoReportController;
use App\Http\Controllers\Api\V1\Reports\CustomerPromoReportController;
use App\Http\Controllers\Api\V1\Reports\PaymentMethodReportController;
use App\Http\Controllers\Api\V1\Reports\TopCustomerReportController;
use App\Http\Controllers\Api\V1\Reports\ReturPatternReportController;
use App\Http\Controllers\Api\V1\Reports\DeadStockReportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// API Version 1
Route::prefix('v1')->group(function () {

    // Public routes (no auth required)
    // Rate limit login: 5 attempts per 15 minutes (per IP + per email, reset via auth:clear-login-throttle)
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/settings/public', [SettingController::class, 'publicSettings']);

    // Depth health check for monitoring tools (UptimeRobot, Grafana, etc.)
    Route::get('/health', [HealthController::class, 'check'])
        ->middleware('throttle:60,1');

    Route::get('/public/receipt/{ulid}', [PosController::class, 'publicReceipt'])->middleware('throttle:30,1');

    // Protected routes (auth required)
    Route::middleware('auth:sanctum')->group(function () {

        // Client-side error reporting (rate limited, auth required for user context)
        Route::post('/client-errors', [ClientErrorLogController::class, 'store'])
            ->middleware('throttle:30,1');

        // Auth routes
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/preferences', [AuthController::class, 'getPreferences']);
            Route::put('/preferences', [AuthController::class, 'updatePreferences']);
        });

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Settings routes
        Route::prefix('settings')->group(function () {
            Route::get('/', [SettingController::class, 'index']);
            Route::get('/price-mode-lock', [SettingController::class, 'checkPriceModeLock']);
            Route::get('/stock-mode-lock', [SettingController::class, 'checkStockModeLock']);
            Route::get('/elektronik-lock', [SettingController::class, 'checkElektronikLock']);
            Route::get('/prefixes', [SettingController::class, 'getPrefixes']);
            Route::get('/timezones', [SettingController::class, 'timezones']);
            Route::get('/group/{group}', [SettingController::class, 'group']);
            Route::get('/{group}/{key}', [SettingController::class, 'show']);

            // Write operations — rate limited
            Route::middleware('throttle:20,1')->group(function () {
                Route::put('/bulk', [SettingController::class, 'bulkUpdate']);
                Route::put('/prefixes/{type}', [SettingController::class, 'updatePrefix']);
                Route::put('/group/{group}', [SettingController::class, 'updateGroup']);
                Route::put('/{group}/{key}', [SettingController::class, 'update']);
            });
        });

        // Import routes
        Route::prefix('import')->group(function () {
            Route::get('/template/{entity}', [ImportController::class, 'template']);
            Route::post('/{entity}', [ImportController::class, 'import'])->middleware('throttle:10,1');
        });

        // Pembelian Serial (modul serial A+) — alur draft → approved
        Route::prefix('serial-intakes')->middleware('feature.elektronik')->group(function () {
            Route::get('/', [SerialIntakeController::class, 'index']);
            Route::post('/', [SerialIntakeController::class, 'store'])->middleware('throttle:30,1');
            Route::post('/calculate', [SerialIntakeController::class, 'calculate']);
            Route::get('/{serialIntake}', [SerialIntakeController::class, 'show']);
            Route::put('/{serialIntake}', [SerialIntakeController::class, 'update'])->middleware('throttle:30,1');
            Route::delete('/{serialIntake}', [SerialIntakeController::class, 'destroy']);
            Route::post('/{serialIntake}/approve', [SerialIntakeController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Perubahan Data Serial (modul serial A+) — koreksi unit tersedia, draft → approved
        Route::prefix('serial-changes')->middleware('feature.elektronik')->group(function () {
            Route::get('/', [SerialChangeController::class, 'index']);
            Route::get('/units', [SerialChangeController::class, 'units']);
            Route::post('/', [SerialChangeController::class, 'store'])->middleware('throttle:30,1');
            Route::get('/{serialChange}', [SerialChangeController::class, 'show']);
            Route::put('/{serialChange}', [SerialChangeController::class, 'update'])->middleware('throttle:30,1');
            Route::delete('/{serialChange}', [SerialChangeController::class, 'destroy']);
            Route::post('/{serialChange}/approve', [SerialChangeController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Register Unit Serial (read-only) — telusuri unit per produk + status + asal dokumen
        Route::prefix('serial-units')->middleware('feature.elektronik')->group(function () {
            Route::get('/', [SerialUnitController::class, 'index']);
            Route::get('/available', [SerialUnitController::class, 'available']);
            Route::get('/lookup', [SerialUnitController::class, 'lookup']);
            Route::get('/peek-kode', [SerialUnitController::class, 'peekKode']);
            Route::get('/export', [SerialUnitController::class, 'export']);
        });

        // Koreksi HPP Serial (modul serial A+) — koreksi harga_modal & cost_per_unit per-unit
        Route::prefix('serial-hpp-corrections')->middleware('feature.elektronik')->group(function () {
            Route::get('/', [SerialHppCorrectionController::class, 'index']);
            Route::get('/units', [SerialHppCorrectionController::class, 'units']);
            Route::post('/', [SerialHppCorrectionController::class, 'store'])->middleware('throttle:30,1');
            Route::get('/{serialHppCorrection}', [SerialHppCorrectionController::class, 'show']);
            Route::put('/{serialHppCorrection}', [SerialHppCorrectionController::class, 'update'])->middleware('throttle:30,1');
            Route::delete('/{serialHppCorrection}', [SerialHppCorrectionController::class, 'destroy']);
            Route::post('/{serialHppCorrection}/approve', [SerialHppCorrectionController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Reset Database routes
        Route::prefix('reset')->middleware('throttle:30,1')->group(function () {
            Route::get('/counts', [ResetController::class, 'counts']);
            Route::post('/', [ResetController::class, 'reset']);
        });

        // Backup & Restore Database routes
        Route::prefix('backup')->group(function () {
            Route::get('/info', [BackupController::class, 'info']);
            Route::post('/download', [BackupController::class, 'download'])->middleware('throttle:10,1');
            Route::post('/restore', [BackupController::class, 'restore'])->middleware('throttle:10,1');
        });

        // Upload routes
        Route::prefix('uploads')->group(function () {
            Route::post('/', [UploadController::class, 'upload']);
            Route::delete('/', [UploadController::class, 'delete']);
            Route::get('/folders', [UploadController::class, 'folders']);
        });

        // User Management routes
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::get('/list', [UserController::class, 'list']);
            Route::get('/roles', [UserController::class, 'roles']);
            Route::get('/{ulid}', [UserController::class, 'show']);

            // Write operations — rate limited
            Route::middleware('throttle:30,1')->group(function () {
                Route::post('/', [UserController::class, 'store']);
                Route::put('/{ulid}', [UserController::class, 'update']);
                Route::patch('/{ulid}/toggle-status', [UserController::class, 'toggleStatus']);
                Route::delete('/{ulid}', [UserController::class, 'destroy']);
            });
        });

        // Role Management routes
        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::get('/permissions', [RoleController::class, 'permissions']);
            Route::get('/{id}', [RoleController::class, 'show']);

            // Write operations — rate limited
            Route::middleware('throttle:30,1')->group(function () {
                Route::post('/', [RoleController::class, 'store']);
                Route::put('/{id}', [RoleController::class, 'update']);
                Route::delete('/{id}', [RoleController::class, 'destroy']);
            });
        });

        // Master Data - Warehouse routes
        Route::prefix('warehouses')->group(function () {
            Route::get('/', [WarehouseController::class, 'index']);
            Route::post('/', [WarehouseController::class, 'store']);
            Route::get('/list', [WarehouseController::class, 'list']);
            Route::get('/export', [WarehouseController::class, 'export']);
            Route::get('/{ulid}', [WarehouseController::class, 'show']);
            Route::put('/{ulid}', [WarehouseController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [WarehouseController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [WarehouseController::class, 'destroy']);
        });

        // Master Data - Brand routes
        Route::prefix('brands')->group(function () {
            Route::get('/', [BrandController::class, 'index']);
            Route::post('/', [BrandController::class, 'store']);
            Route::get('/list', [BrandController::class, 'list']);
            Route::get('/export', [BrandController::class, 'export']);
            Route::get('/{ulid}', [BrandController::class, 'show']);
            Route::put('/{ulid}', [BrandController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [BrandController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [BrandController::class, 'destroy']);
        });

        // Master Data - Tipe Produk routes
        Route::prefix('tipes')->group(function () {
            Route::get('/', [TipeController::class, 'index']);
            Route::post('/', [TipeController::class, 'store']);
            Route::get('/list', [TipeController::class, 'list']);
            Route::get('/export', [TipeController::class, 'export']);
            Route::get('/{ulid}', [TipeController::class, 'show']);
            Route::put('/{ulid}', [TipeController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [TipeController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [TipeController::class, 'destroy']);
        });

        // Master Data - Kategori Produk routes
        Route::prefix('kategoris')->group(function () {
            Route::get('/', [KategoriController::class, 'index']);
            Route::post('/', [KategoriController::class, 'store']);
            Route::get('/list', [KategoriController::class, 'list']);
            Route::get('/export', [KategoriController::class, 'export']);
            Route::get('/{ulid}', [KategoriController::class, 'show']);
            Route::put('/{ulid}', [KategoriController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [KategoriController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [KategoriController::class, 'destroy']);
        });

        // Master Data - Grup Produk routes
        Route::prefix('grups')->group(function () {
            Route::get('/', [GrupController::class, 'index']);
            Route::post('/', [GrupController::class, 'store']);
            Route::get('/list', [GrupController::class, 'list']);
            Route::get('/export', [GrupController::class, 'export']);
            Route::get('/{ulid}', [GrupController::class, 'show']);
            Route::put('/{ulid}', [GrupController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [GrupController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [GrupController::class, 'destroy']);
        });

        // Master Data - Supplier routes
        Route::prefix('suppliers')->group(function () {
            Route::get('/', [SupplierController::class, 'index']);
            Route::post('/', [SupplierController::class, 'store']);
            Route::get('/list', [SupplierController::class, 'list']);
            Route::get('/export', [SupplierController::class, 'export']);
            Route::get('/{ulid}', [SupplierController::class, 'show']);
            Route::put('/{ulid}', [SupplierController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [SupplierController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [SupplierController::class, 'destroy']);
        });

        // Master Data - Tipe Customer routes
        Route::prefix('tipe-customers')->group(function () {
            Route::get('/', [TipeCustomerController::class, 'index']);
            Route::post('/', [TipeCustomerController::class, 'store']);
            Route::get('/list', [TipeCustomerController::class, 'list']);
            Route::get('/export', [TipeCustomerController::class, 'export']);
            Route::get('/{ulid}', [TipeCustomerController::class, 'show']);
            Route::put('/{ulid}', [TipeCustomerController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [TipeCustomerController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [TipeCustomerController::class, 'destroy']);
        });

        // Master Data - Kategori Customer routes
        Route::prefix('kategori-customers')->group(function () {
            Route::get('/', [KategoriCustomerController::class, 'index']);
            Route::post('/', [KategoriCustomerController::class, 'store']);
            Route::get('/list', [KategoriCustomerController::class, 'list']);
            Route::get('/export', [KategoriCustomerController::class, 'export']);
            Route::get('/{ulid}', [KategoriCustomerController::class, 'show']);
            Route::put('/{ulid}', [KategoriCustomerController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [KategoriCustomerController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [KategoriCustomerController::class, 'destroy']);
        });

        // Master Data - Customer routes
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index']);
            Route::post('/', [CustomerController::class, 'store']);
            Route::get('/list', [CustomerController::class, 'list']);
            Route::get('/export', [CustomerController::class, 'export']);
            Route::get('/{ulid}', [CustomerController::class, 'show']);
            Route::put('/{ulid}', [CustomerController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [CustomerController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [CustomerController::class, 'destroy']);
        });

        // Master Data - Metode Pembayaran routes
        Route::prefix('metode-pembayarans')->group(function () {
            Route::get('/', [MetodePembayaranController::class, 'index']);
            Route::post('/', [MetodePembayaranController::class, 'store']);
            Route::get('/list', [MetodePembayaranController::class, 'list']);
            Route::get('/export', [MetodePembayaranController::class, 'export']);
            Route::get('/{ulid}', [MetodePembayaranController::class, 'show']);
            Route::put('/{ulid}', [MetodePembayaranController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [MetodePembayaranController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [MetodePembayaranController::class, 'destroy']);
        });

        // Master Data - Produk routes
        Route::prefix('produks')->group(function () {
            Route::get('/', [ProdukController::class, 'index']);
            Route::post('/', [ProdukController::class, 'store']);
            Route::get('/list', [ProdukController::class, 'list']);
            Route::get('/price-mode', [ProdukController::class, 'getPriceMode']);
            Route::get('/export', [ProdukController::class, 'export']);
            Route::get('/{ulid}', [ProdukController::class, 'show']);
            Route::put('/{ulid}', [ProdukController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [ProdukController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [ProdukController::class, 'destroy']);
            Route::delete('/{ulid}/image', [ProdukController::class, 'deleteImage']);
        });

        // Inventory - Stock routes (view only)
        Route::prefix('inventory/stocks')->group(function () {
            Route::get('/', [InventoryStockController::class, 'index']);
            Route::get('/summary', [InventoryStockController::class, 'summary']);
            Route::get('/valuation-by-warehouse', [InventoryStockController::class, 'valuationByWarehouse']);
            Route::get('/export', [InventoryStockController::class, 'export']);
            Route::get('/by-product/{ulid}', [InventoryStockController::class, 'showByProduct']);
        });

        // Inventory - Stock Card routes (view only)
        Route::prefix('inventory/stock-cards')->group(function () {
            Route::get('/', [StockCardController::class, 'index']);
            Route::get('/summary', [StockCardController::class, 'summary']);
            Route::get('/hpp-summary', [StockCardController::class, 'hppSummary']);
            Route::get('/export', [StockCardController::class, 'export']);
        });

        // Inventory - Adjustment routes
        Route::prefix('adjustments')->group(function () {
            Route::get('/', [AdjustmentController::class, 'index']);
            Route::post('/', [AdjustmentController::class, 'store']);
            Route::get('/products', [AdjustmentController::class, 'getProducts']);
            Route::get('/stock-setting', [AdjustmentController::class, 'getStockSetting']);
            Route::get('/{ulid}', [AdjustmentController::class, 'show']);
            Route::put('/{ulid}', [AdjustmentController::class, 'update']);
            Route::delete('/{ulid}', [AdjustmentController::class, 'destroy']);
            Route::post('/{ulid}/approve', [AdjustmentController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Inventory - Transfer routes
        Route::prefix('transfers')->group(function () {
            Route::get('/', [TransferController::class, 'index']);
            Route::post('/', [TransferController::class, 'store']);
            Route::get('/products', [TransferController::class, 'getProducts']);
            Route::get('/stock-setting', [TransferController::class, 'getStockSetting']);
            Route::get('/pattern-summary', [TransferController::class, 'patternSummary']);
            Route::get('/{ulid}', [TransferController::class, 'show']);
            Route::put('/{ulid}', [TransferController::class, 'update']);
            Route::delete('/{ulid}', [TransferController::class, 'destroy']);
            Route::post('/{ulid}/approve', [TransferController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Inventory - Repack routes
        Route::prefix('repacks')->group(function () {
            Route::get('/', [RepackController::class, 'index']);
            Route::post('/', [RepackController::class, 'store']);
            Route::get('/products', [RepackController::class, 'getProducts']);
            Route::get('/stock-setting', [RepackController::class, 'getStockSetting']);
            Route::get('/{ulid}', [RepackController::class, 'show']);
            Route::put('/{ulid}', [RepackController::class, 'update']);
            Route::delete('/{ulid}', [RepackController::class, 'destroy']);
            Route::post('/{ulid}/approve', [RepackController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Inventory - Stock Opname routes
        Route::prefix('opnames')->group(function () {
            Route::get('/', [StockOpnameController::class, 'index']);
            Route::post('/', [StockOpnameController::class, 'store']);
            Route::get('/products', [StockOpnameController::class, 'getProducts']);
            Route::get('/all-products', [StockOpnameController::class, 'loadAllProducts']);
            Route::get('/stock-setting', [StockOpnameController::class, 'getStockSetting']);
            Route::get('/check-draft', [StockOpnameController::class, 'checkDraft']);
            Route::post('/refresh-stock', [StockOpnameController::class, 'refreshStock']);
            Route::get('/{ulid}', [StockOpnameController::class, 'show']);
            Route::put('/{ulid}', [StockOpnameController::class, 'update']);
            Route::delete('/{ulid}', [StockOpnameController::class, 'destroy']);
            Route::post('/{ulid}/approve', [StockOpnameController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Inventory - HPP Correction routes
        Route::prefix('hpp-corrections')->group(function () {
            Route::get('/', [HppCorrectionController::class, 'index']);
            Route::post('/', [HppCorrectionController::class, 'store']);
            Route::get('/check-draft', [HppCorrectionController::class, 'checkDraft']);
            Route::get('/products', [HppCorrectionController::class, 'getProducts']);
            Route::get('/locked-products', [HppCorrectionController::class, 'getLockedProducts']);
            Route::get('/alasan-options', [HppCorrectionController::class, 'getAlasanOptions']);
            Route::get('/{ulid}', [HppCorrectionController::class, 'show']);
            Route::put('/{ulid}', [HppCorrectionController::class, 'update']);
            Route::delete('/{ulid}', [HppCorrectionController::class, 'destroy']);
            Route::post('/{ulid}/approve', [HppCorrectionController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Pembelian - Purchase Order routes
        Route::prefix('purchase-orders')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index']);
            Route::get('/list', [PurchaseOrderController::class, 'list']);
            Route::post('/', [PurchaseOrderController::class, 'store']);
            Route::get('/products', [PurchaseOrderController::class, 'getProducts']);
            Route::get('/last-price', [PurchaseOrderController::class, 'getLastPrice']);
            Route::get('/price-history', [PurchaseOrderController::class, 'getPriceHistory']);
            Route::get('/tax-settings', [PurchaseOrderController::class, 'getTaxSettings']);
            Route::post('/calculate', [PurchaseOrderController::class, 'calculate']);
            Route::get('/{ulid}', [PurchaseOrderController::class, 'show']);
            Route::put('/{ulid}', [PurchaseOrderController::class, 'update']);
            Route::delete('/{ulid}', [PurchaseOrderController::class, 'destroy']);
            Route::post('/{ulid}/approve', [PurchaseOrderController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Pembelian - Supplier Hutang routes
        Route::prefix('supplier-hutangs')->group(function () {
            Route::get('/', [SupplierHutangController::class, 'index']);
            Route::get('/summary', [SupplierHutangController::class, 'summary']);
            Route::get('/aging-summary', [SupplierHutangController::class, 'agingSummary']);
            Route::get('/by-supplier', [SupplierHutangController::class, 'bySupplier']);
            Route::get('/export', [SupplierHutangController::class, 'export']);
            Route::get('/{ulid}', [SupplierHutangController::class, 'show']);
        });

        // Pembelian - Purchase Return routes
        Route::prefix('purchase-returns')->group(function () {
            Route::get('/', [PurchaseReturnController::class, 'index']);
            Route::post('/', [PurchaseReturnController::class, 'store']);
            Route::get('/products', [PurchaseReturnController::class, 'getProducts']);
            Route::get('/last-price', [PurchaseReturnController::class, 'getLastPrice']);
            Route::get('/tax-settings', [PurchaseReturnController::class, 'getTaxSettings']);
            Route::get('/stock-setting', [PurchaseReturnController::class, 'getStockSetting']);
            Route::post('/calculate', [PurchaseReturnController::class, 'calculate']);
            Route::get('/po/{poUlid}/returnable-details', [PurchaseReturnController::class, 'getReturnableDetails']);
            Route::get('/{ulid}', [PurchaseReturnController::class, 'show']);
            Route::put('/{ulid}', [PurchaseReturnController::class, 'update']);
            Route::delete('/{ulid}', [PurchaseReturnController::class, 'destroy']);
            Route::post('/{ulid}/lock', [PurchaseReturnController::class, 'lock'])->middleware('throttle:30,1');
            Route::post('/{ulid}/approve', [PurchaseReturnController::class, 'approve'])->middleware('throttle:30,1');
        });

        // Pembelian - Supplier Deposit routes
        Route::prefix('supplier-deposits')->group(function () {
            Route::get('/', [SupplierDepositController::class, 'index']);
            Route::get('/summary', [SupplierDepositController::class, 'summary']);
            Route::get('/by-supplier', [SupplierDepositController::class, 'bySupplier']);
            Route::get('/export', [SupplierDepositController::class, 'export']);
            Route::post('/', [SupplierDepositController::class, 'store']);
            Route::get('/{ulid}', [SupplierDepositController::class, 'show']);
            Route::get('/{ulid}/usage', [SupplierDepositController::class, 'usage']);
            Route::put('/{ulid}', [SupplierDepositController::class, 'update']);
            Route::delete('/{ulid}', [SupplierDepositController::class, 'destroy']);
        });

        // Pembelian - Pembayaran Hutang routes
        Route::prefix('pembayaran-hutangs')->group(function () {
            Route::get('/', [PembayaranHutangController::class, 'index']);
            Route::post('/', [PembayaranHutangController::class, 'store']);
            Route::get('/outstanding-hutangs', [PembayaranHutangController::class, 'getOutstandingHutangs']);
            Route::get('/available-deposits', [PembayaranHutangController::class, 'getAvailableDeposits']);
            Route::get('/{ulid}', [PembayaranHutangController::class, 'show']);
            Route::put('/{ulid}', [PembayaranHutangController::class, 'update']);
            Route::delete('/{ulid}', [PembayaranHutangController::class, 'destroy']);
            Route::post('/{ulid}/complete', [PembayaranHutangController::class, 'complete'])->middleware('throttle:30,1');
        });

        // Master - Price Change routes
        Route::prefix('price-changes')->group(function () {
            Route::get('/', [PriceChangeController::class, 'index']);
            Route::post('/', [PriceChangeController::class, 'store']);
            Route::get('/products', [PriceChangeController::class, 'getProducts']);
            Route::get('/locked-products', [PriceChangeController::class, 'getLockedProducts']);
            Route::get('/has-other-drafts', [PriceChangeController::class, 'hasOtherDrafts']);
            Route::get('/alasan-options', [PriceChangeController::class, 'getAlasanOptions']);
            Route::get('/pending-count', [PriceChangeController::class, 'getPendingCount']);
            Route::get('/{ulid}', [PriceChangeController::class, 'show']);
            Route::put('/{ulid}', [PriceChangeController::class, 'update']);
            Route::delete('/{ulid}', [PriceChangeController::class, 'destroy']);
            Route::post('/{ulid}/approve', [PriceChangeController::class, 'approve'])->middleware('throttle:30,1');
            Route::post('/{ulid}/cancel', [PriceChangeController::class, 'cancel'])->middleware('throttle:30,1');
            Route::post('/{ulid}/apply', [PriceChangeController::class, 'apply'])->middleware('throttle:30,1');
        });

        // Master - Promo
        Route::prefix('promos')->group(function () {
            Route::get('/', [PromoController::class, 'index']);
            Route::post('/', [PromoController::class, 'store']);
            Route::get('/{ulid}', [PromoController::class, 'show']);
            Route::put('/{ulid}', [PromoController::class, 'update']);
            Route::delete('/{ulid}', [PromoController::class, 'destroy']);
            Route::post('/{ulid}/approve', [PromoController::class, 'approve'])->middleware('throttle:30,1');
            Route::post('/{ulid}/cancel', [PromoController::class, 'cancel'])->middleware('throttle:30,1');
            Route::post('/{ulid}/deactivate', [PromoController::class, 'deactivate'])->middleware('throttle:30,1');
            Route::post('/{ulid}/reactivate', [PromoController::class, 'reactivate'])->middleware('throttle:30,1');
        });

        // POS - Terminal routes
        Route::prefix('pos-terminals')->group(function () {
            Route::get('/', [PosTerminalController::class, 'index']);
            Route::post('/', [PosTerminalController::class, 'store']);
            Route::get('/list', [PosTerminalController::class, 'list']);
            Route::get('/active-shifts-summary', [PosTerminalController::class, 'activeShiftsSummary']);
            Route::get('/{ulid}', [PosTerminalController::class, 'show']);
            Route::put('/{ulid}', [PosTerminalController::class, 'update']);
            Route::patch('/{ulid}/toggle-status', [PosTerminalController::class, 'toggleStatus']);
            Route::delete('/{ulid}', [PosTerminalController::class, 'destroy']);
            Route::post('/{ulid}/force-release', [PosTerminalController::class, 'forceRelease'])->middleware('throttle:30,1');
            Route::post('/{ulid}/start-shift', [PosTerminalController::class, 'startShift'])->middleware('throttle:30,1');
            Route::post('/{ulid}/end-shift', [PosTerminalController::class, 'endShift'])->middleware('throttle:30,1');
        });

        // POS - Shift routes (read-only)
        Route::prefix('shifts')->group(function () {
            Route::get('/', [ShiftController::class, 'index']);
            Route::get('/daily-summary', [ShiftController::class, 'dailySummary']);
        });

        // POS - Kasir routes
        Route::prefix('pos')->group(function () {
            Route::get('/active-terminal', [PosController::class, 'activeTerminal']);
            Route::get('/active-promos', [PosController::class, 'activePromos']);
            Route::get('/products', [PosController::class, 'products']);
            Route::get('/products/barcode/{barcode}', [PosController::class, 'productByBarcode']);
            Route::post('/calculate', [PosController::class, 'calculate']);
            Route::get('/history', [PosController::class, 'history']);
            Route::get('/sales/{ulid}', [PosController::class, 'show']);
            Route::get('/shift-report/{shiftUlid}', [PosController::class, 'shiftReport']);

            // Returns (read)
            Route::get('/returns/search-sales', [SalesReturnController::class, 'searchSales']);
            Route::get('/returns/sales/{ulid}', [SalesReturnController::class, 'salesDetail']);
            Route::get('/returns', [SalesReturnController::class, 'index']);

            // Cash transactions (read)
            Route::get('/cash', [CashTransactionController::class, 'index']);
            Route::get('/cash/summary', [CashTransactionController::class, 'summary']);

            // Write operations — rate limited
            Route::middleware('throttle:60,1')->group(function () {
                // Checkout pakai idempotency untuk cegah double-submit (double-click / network retry)
                Route::post('/checkout', [PosController::class, 'checkout'])->middleware('idempotency');
                Route::post('/cash', [CashTransactionController::class, 'store']);
                Route::post('/returns', [SalesReturnController::class, 'store']);
            });

            // Sensitive write operations — stricter rate limit
            Route::middleware('throttle:30,1')->group(function () {
                Route::post('/sales/{ulid}/void', [PosController::class, 'void']);
            });

            // Screen lock/unlock
            Route::post('/lock', [PosController::class, 'lockShift']);
            Route::post('/unlock', [PosController::class, 'unlockShift']);
        });

        // Laporan - Sales Report routes
        Route::prefix('sales-report')->group(function () {
            Route::get('/dropdowns', [SalesReportController::class, 'dropdowns']);
            Route::get('/export', [SalesReportController::class, 'export']);
            Route::get('/{ulid}', [SalesReportController::class, 'show']);
            Route::get('/', [SalesReportController::class, 'index']);
        });

        // Laporan - Sales Product Report routes
        Route::prefix('sales-product-report')->group(function () {
            Route::get('/dropdowns', [SalesProductReportController::class, 'dropdowns']);
            Route::get('/export', [SalesProductReportController::class, 'export']);
            Route::get('/{productUlid}', [SalesProductReportController::class, 'show']);
            Route::get('/', [SalesProductReportController::class, 'index']);
        });

        // Laporan - Sales Financial Report routes
        Route::prefix('sales-financial-report')->group(function () {
            Route::get('/dropdowns', [SalesFinancialReportController::class, 'dropdowns']);

            // Export routes (must be before data routes)
            Route::get('/pembulatan/export', [SalesFinancialReportController::class, 'exportPembulatan']);
            Route::get('/disc-line/export', [SalesFinancialReportController::class, 'exportDiscLine']);
            Route::get('/disc-nota/export', [SalesFinancialReportController::class, 'exportDiscNota']);
            Route::get('/biaya/export', [SalesFinancialReportController::class, 'exportBiaya']);

            // Data routes
            Route::get('/pembulatan', [SalesFinancialReportController::class, 'pembulatan']);
            Route::get('/disc-line', [SalesFinancialReportController::class, 'discLine']);
            Route::get('/disc-line/{salesUlid}', [SalesFinancialReportController::class, 'discLineDetail']);
            Route::get('/disc-nota', [SalesFinancialReportController::class, 'discNota']);
            Route::get('/biaya', [SalesFinancialReportController::class, 'biaya']);
        });

        // Laporan - Purchase Report routes (split per tipe laporan untuk maintainability)
        Route::prefix('purchase-report')->group(function () {
            Route::get('/dropdowns', [PurchaseReportDropdownsController::class, 'dropdowns']);

            // Export routes (must be before parameterized routes)
            Route::get('/diskon/export', [PurchaseDiskonReportController::class, 'exportDiskon']);
            Route::get('/per-dokumen/export', [PurchasePerDokumenReportController::class, 'exportPerDokumen']);
            Route::get('/per-supplier/export', [PurchasePerSupplierReportController::class, 'exportPerSupplier']);
            Route::get('/per-barang/export', [PurchasePerBarangReportController::class, 'exportPerBarang']);
            Route::get('/harga-terakhir/export', [HargaTerakhirReportController::class, 'exportHargaTerakhir']);

            // Data routes
            Route::get('/per-dokumen', [PurchasePerDokumenReportController::class, 'perDokumen']);
            Route::get('/per-dokumen/{ulid}', [PurchasePerDokumenReportController::class, 'showPo']);
            Route::get('/per-barang', [PurchasePerBarangReportController::class, 'perBarang']);
            Route::get('/per-barang/{productUlid}', [PurchasePerBarangReportController::class, 'showBarang']);
            Route::get('/per-supplier', [PurchasePerSupplierReportController::class, 'perSupplier']);
            Route::get('/per-supplier/{supplierId}', [PurchasePerSupplierReportController::class, 'showSupplier']);
            Route::get('/diskon', [PurchaseDiskonReportController::class, 'diskon']);
            Route::get('/harga-terakhir', [HargaTerakhirReportController::class, 'hargaTerakhir']);
        });

        // Laporan - Keuangan (Sprint 1)
        Route::prefix('reports/gross-profit')->group(function () {
            Route::get('/summary', [GrossProfitReportController::class, 'summary']);
            Route::get('/daily', [GrossProfitReportController::class, 'daily']);
            Route::get('/daily/export', [AnalyticReportExportController::class, 'grossProfitDaily']);
            Route::get('/by-kategori', [GrossProfitReportController::class, 'byKategori']);
            Route::get('/by-kategori/export', [AnalyticReportExportController::class, 'grossProfitByKategori']);
            Route::get('/top-products', [GrossProfitReportController::class, 'topProducts']);
            Route::get('/top-products/export', [AnalyticReportExportController::class, 'grossProfitTopProducts']);
        });

        Route::prefix('reports/margin-per-barang')->group(function () {
            Route::get('/summary', [MarginPerBarangReportController::class, 'summary']);
            Route::get('/', [MarginPerBarangReportController::class, 'index']);
            Route::get('/export', [AnalyticReportExportController::class, 'marginPerBarang']);
        });

        Route::prefix('reports/cash-flow')->group(function () {
            Route::get('/summary', [CashFlowReportController::class, 'summary']);
            Route::get('/daily', [CashFlowReportController::class, 'daily']);
            Route::get('/daily/export', [AnalyticReportExportController::class, 'cashFlowDaily']);
        });

        Route::prefix('reports/kasir-performance')->group(function () {
            Route::get('/', [KasirPerformanceReportController::class, 'index']);
            Route::get('/export', [AnalyticReportExportController::class, 'kasirPerformance']);
        });

        // Laporan - Promo Suite (Sprint 2)
        Route::prefix('reports/promo-usage')->group(function () {
            Route::get('/summary', [PromoUsageReportController::class, 'summary']);
            Route::get('/export', [AnalyticReportExportController::class, 'promoUsage']);
            Route::get('/{promoUlid}', [PromoUsageReportController::class, 'show']);
            Route::get('/', [PromoUsageReportController::class, 'index']);
        });

        Route::prefix('reports/product-promo')->group(function () {
            Route::get('/by-product', [ProductPromoReportController::class, 'byProduct']);
            Route::get('/by-product/export', [AnalyticReportExportController::class, 'productPromoByProduct']);
            Route::get('/by-promo', [ProductPromoReportController::class, 'byPromo']);
            Route::get('/by-promo/export', [AnalyticReportExportController::class, 'productPromoByPromo']);
        });

        Route::prefix('reports/customer-promo')->group(function () {
            Route::get('/summary', [CustomerPromoReportController::class, 'summary']);
            Route::get('/summary/export', [AnalyticReportExportController::class, 'customerPromoSummary']);
            Route::get('/by-tipe', [CustomerPromoReportController::class, 'byTipe']);
            Route::get('/by-tipe/export', [AnalyticReportExportController::class, 'customerPromoByTipe']);
            Route::get('/by-kategori', [CustomerPromoReportController::class, 'byKategori']);
            Route::get('/by-kategori/export', [AnalyticReportExportController::class, 'customerPromoByKategori']);
            Route::get('/by-customer', [CustomerPromoReportController::class, 'byCustomer']);
            Route::get('/by-customer/export', [AnalyticReportExportController::class, 'customerPromoByCustomer']);
            Route::get('/customer/{customerUlid}', [CustomerPromoReportController::class, 'showCustomer']);
        });

        // Laporan - Operational (Sprint 3)
        Route::get('/reports/payment-method/breakdown', [PaymentMethodReportController::class, 'breakdown']);
        Route::get('/reports/payment-method/breakdown/export', [AnalyticReportExportController::class, 'paymentMethodBreakdown']);
        Route::get('/reports/customer/top', [TopCustomerReportController::class, 'top']);
        Route::get('/reports/customer/top/export', [AnalyticReportExportController::class, 'topCustomer']);
        Route::get('/reports/retur/pattern', [ReturPatternReportController::class, 'pattern']);
        Route::get('/reports/retur/pattern/export', [AnalyticReportExportController::class, 'returPattern']);
        Route::get('/reports/inventory/dead-stock', [DeadStockReportController::class, 'index']);
        Route::get('/reports/inventory/dead-stock/export', [AnalyticReportExportController::class, 'deadStock']);
    });
});
