import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        // Login sebagai halaman utama
        {
            path: '/',
            name: 'login',
            component: () => import('@/views/pages/auth/Login.vue'),
            meta: { guest: true }
        },
        {
            path: '/auth/access',
            name: 'accessDenied',
            component: () => import('@/views/pages/auth/Access.vue')
        },
        {
            path: '/auth/error',
            name: 'error',
            component: () => import('@/views/pages/auth/Error.vue')
        },

        // POS Kasir - Full screen tanpa AppLayout
        {
            path: '/pos-kasir',
            name: 'pos-kasir',
            component: () => import('@/views/pos/PosKasirPage.vue'),
            meta: { requiresAuth: true, permission: 'pos.access' }
        },

        // App routes (dengan layout) - Protected
        {
            path: '/app',
            component: () => import('@/layout/AppLayout.vue'),
            meta: { requiresAuth: true },
            children: [
                {
                    path: '',
                    name: 'dashboard',
                    component: () => import('@/views/Dashboard.vue')
                },
                // Master Data
                {
                    path: 'master/produk',
                    name: 'master-produk',
                    component: () => import('@/views/master/ProdukPage.vue'),
                    meta: { permission: 'produk.view' }
                },
                {
                    path: 'master/price-change',
                    name: 'master-price-change',
                    component: () => import('@/views/master/PriceChangePage.vue'),
                    meta: { permission: 'price-change.view' }
                },
                {
                    path: 'master/price-change/create',
                    name: 'master-price-change-create',
                    component: () => import('@/views/master/PriceChangeFormPage.vue'),
                    meta: { permission: 'price-change.create' }
                },
                {
                    path: 'master/price-change/:ulid/edit',
                    name: 'master-price-change-edit',
                    component: () => import('@/views/master/PriceChangeFormPage.vue'),
                    meta: { permission: 'price-change.update' }
                },
                // Perubahan Data Serial (modul serial A+)
                {
                    path: 'master/serial-change',
                    name: 'master-serial-change',
                    component: () => import('@/views/master/SerialChangePage.vue'),
                    meta: { permission: 'serial-change.view', requiresElektronik: true }
                },
                {
                    path: 'master/serial-change/create',
                    name: 'master-serial-change-create',
                    component: () => import('@/views/master/SerialChangeFormPage.vue'),
                    meta: { permission: 'serial-change.create', requiresElektronik: true }
                },
                {
                    path: 'master/serial-change/:ulid/edit',
                    name: 'master-serial-change-edit',
                    component: () => import('@/views/master/SerialChangeFormPage.vue'),
                    meta: { permission: 'serial-change.update', requiresElektronik: true }
                },
                // Koreksi HPP Serial (modul serial A+) — grup Inventory
                {
                    path: 'inventory/serial-hpp',
                    name: 'inventory-serial-hpp',
                    component: () => import('@/views/master/SerialHppCorrectionPage.vue'),
                    meta: { permission: 'serial-hpp.view', requiresElektronik: true }
                },
                {
                    path: 'inventory/serial-hpp/create',
                    name: 'inventory-serial-hpp-create',
                    component: () => import('@/views/master/SerialHppCorrectionFormPage.vue'),
                    meta: { permission: 'serial-hpp.create', requiresElektronik: true }
                },
                {
                    path: 'inventory/serial-hpp/:ulid/edit',
                    name: 'inventory-serial-hpp-edit',
                    component: () => import('@/views/master/SerialHppCorrectionFormPage.vue'),
                    meta: { permission: 'serial-hpp.update', requiresElektronik: true }
                },
                {
                    path: 'master/brand',
                    name: 'master-brand',
                    component: () => import('@/views/master/BrandPage.vue'),
                    meta: { permission: 'brand.view' }
                },
                {
                    path: 'master/tipe',
                    name: 'master-tipe',
                    component: () => import('@/views/master/TipePage.vue'),
                    meta: { permission: 'tipe.view' }
                },
                {
                    path: 'master/kategori',
                    name: 'master-kategori',
                    component: () => import('@/views/master/KategoriPage.vue'),
                    meta: { permission: 'kategori.view' }
                },
                {
                    path: 'master/grup',
                    name: 'master-grup',
                    component: () => import('@/views/master/GrupPage.vue'),
                    meta: { permission: 'grup.view' }
                },
                {
                    path: 'master/supplier',
                    name: 'master-supplier',
                    component: () => import('@/views/master/SupplierPage.vue'),
                    meta: { permission: 'supplier.view' }
                },
                {
                    path: 'master/tipe-customer',
                    name: 'master-tipe-customer',
                    component: () => import('@/views/master/TipeCustomerPage.vue'),
                    meta: { permission: 'tipe-customer.view' }
                },
                {
                    path: 'master/kategori-customer',
                    name: 'master-kategori-customer',
                    component: () => import('@/views/master/KategoriCustomerPage.vue'),
                    meta: { permission: 'kategori-customer.view' }
                },
                {
                    path: 'master/customer',
                    name: 'master-customer',
                    component: () => import('@/views/master/CustomerPage.vue'),
                    meta: { permission: 'customer.view' }
                },
                {
                    path: 'master/warehouse',
                    name: 'master-warehouse',
                    component: () => import('@/views/master/WarehousePage.vue'),
                    meta: { permission: 'warehouse.view' }
                },
                {
                    path: 'master/metode-pembayaran',
                    name: 'master-metode-pembayaran',
                    component: () => import('@/views/master/MetodePembayaranPage.vue'),
                    meta: { permission: 'metode-bayar.view' }
                },
                {
                    path: 'master/print-barcode',
                    name: 'master-print-barcode',
                    component: () => import('@/views/master/PrintBarcodePage.vue'),
                    meta: { permission: 'produk.print-barcode' }
                },
                {
                    path: 'master/promo',
                    name: 'master-promo',
                    component: () => import('@/views/master/PromosPage.vue'),
                    meta: { permission: 'promo.view' }
                },
                {
                    path: 'master/promo/create',
                    name: 'master-promo-create',
                    component: () => import('@/views/master/PromoFormPage.vue'),
                    meta: { permission: 'promo.create' }
                },
                {
                    path: 'master/promo/:ulid/edit',
                    name: 'master-promo-edit',
                    component: () => import('@/views/master/PromoFormPage.vue'),
                    meta: { permission: 'promo.update' }
                },
                // Inventory
                {
                    path: 'inventory/stok',
                    name: 'inventory-stok',
                    component: () => import('@/views/inventory/StockPage.vue'),
                    meta: { permission: 'stok.view' }
                },
                {
                    path: 'inventory/kartu-stok',
                    name: 'inventory-kartu-stok',
                    component: () => import('@/views/inventory/StockCardPage.vue'),
                    meta: { permission: 'stok.view' }
                },
                {
                    path: 'inventory/pergerakan-hpp',
                    name: 'inventory-pergerakan-hpp',
                    component: () => import('@/views/inventory/HppMovementPage.vue'),
                    meta: { permission: 'stok.view_hpp' }
                },
                {
                    path: 'inventory/opname',
                    name: 'inventory-opname',
                    component: () => import('@/views/inventory/StockOpnamePage.vue'),
                    meta: { permission: 'opname.view' }
                },
                {
                    path: 'inventory/opname/create',
                    name: 'inventory-opname-create',
                    component: () => import('@/views/inventory/StockOpnameFormPage.vue'),
                    meta: { permission: 'opname.create' }
                },
                {
                    path: 'inventory/opname/:ulid/edit',
                    name: 'inventory-opname-edit',
                    component: () => import('@/views/inventory/StockOpnameFormPage.vue'),
                    meta: { permission: 'opname.create' }
                },
                {
                    path: 'inventory/adjustment',
                    name: 'inventory-adjustment',
                    component: () => import('@/views/inventory/AdjustmentPage.vue'),
                    meta: { permission: 'adjustment.view' }
                },
                {
                    path: 'inventory/adjustment/create',
                    name: 'inventory-adjustment-create',
                    component: () => import('@/views/inventory/AdjustmentFormPage.vue'),
                    meta: { permission: 'adjustment.create' }
                },
                {
                    path: 'inventory/adjustment/:ulid/edit',
                    name: 'inventory-adjustment-edit',
                    component: () => import('@/views/inventory/AdjustmentFormPage.vue'),
                    meta: { permission: 'adjustment.create' }
                },
                {
                    path: 'inventory/transfer',
                    name: 'inventory-transfer',
                    component: () => import('@/views/inventory/TransferPage.vue'),
                    meta: { permission: 'transfer.view' }
                },
                {
                    path: 'inventory/transfer/create',
                    name: 'inventory-transfer-create',
                    component: () => import('@/views/inventory/TransferFormPage.vue'),
                    meta: { permission: 'transfer.create' }
                },
                {
                    path: 'inventory/transfer/:ulid/edit',
                    name: 'inventory-transfer-edit',
                    component: () => import('@/views/inventory/TransferFormPage.vue'),
                    meta: { permission: 'transfer.update' }
                },
                {
                    path: 'inventory/repack',
                    name: 'inventory-repack',
                    component: () => import('@/views/inventory/RepackPage.vue'),
                    meta: { permission: 'repack.view' }
                },
                {
                    path: 'inventory/repack/create',
                    name: 'inventory-repack-create',
                    component: () => import('@/views/inventory/RepackFormPage.vue'),
                    meta: { permission: 'repack.create' }
                },
                {
                    path: 'inventory/repack/:ulid/edit',
                    name: 'inventory-repack-edit',
                    component: () => import('@/views/inventory/RepackFormPage.vue'),
                    meta: { permission: 'repack.update' }
                },
                {
                    path: 'inventory/hpp-correction',
                    name: 'inventory-hpp-correction',
                    component: () => import('@/views/inventory/HppCorrectionPage.vue'),
                    meta: { permission: 'hpp.view' }
                },
                {
                    path: 'inventory/hpp-correction/create',
                    name: 'inventory-hpp-correction-create',
                    component: () => import('@/views/inventory/HppCorrectionFormPage.vue'),
                    meta: { permission: 'hpp.create' }
                },
                {
                    path: 'inventory/hpp-correction/:ulid/edit',
                    name: 'inventory-hpp-correction-edit',
                    component: () => import('@/views/inventory/HppCorrectionFormPage.vue'),
                    meta: { permission: 'hpp.create' }
                },
                // Pembelian Serial (modul serial A+)
                {
                    path: 'inventory/serial-intake',
                    name: 'inventory-serial-intake',
                    component: () => import('@/views/inventory/SerialIntakePage.vue'),
                    meta: { permission: 'serial-intake.view', requiresElektronik: true }
                },
                {
                    path: 'inventory/serial-intake/create',
                    name: 'inventory-serial-intake-create',
                    component: () => import('@/views/inventory/SerialIntakeFormPage.vue'),
                    meta: { permission: 'serial-intake.create', requiresElektronik: true }
                },
                {
                    path: 'inventory/serial-intake/:ulid/edit',
                    name: 'inventory-serial-intake-edit',
                    component: () => import('@/views/inventory/SerialIntakeFormPage.vue'),
                    meta: { permission: 'serial-intake.update', requiresElektronik: true }
                },
                {
                    path: 'inventory/serial-units',
                    name: 'inventory-serial-units',
                    component: () => import('@/views/inventory/SerialUnitRegisterPage.vue'),
                    meta: { permission: 'serial-intake.view', requiresElektronik: true }
                },
                // Pembelian
                {
                    path: 'pembelian/po',
                    name: 'pembelian-po',
                    component: () => import('@/views/pembelian/PurchaseOrderPage.vue'),
                    meta: { permission: 'po.view' }
                },
                {
                    path: 'pembelian/po/create',
                    name: 'pembelian-po-create',
                    component: () => import('@/views/pembelian/PurchaseOrderFormPage.vue'),
                    meta: { permission: 'po.create' }
                },
                {
                    path: 'pembelian/po/:ulid/edit',
                    name: 'pembelian-po-edit',
                    component: () => import('@/views/pembelian/PurchaseOrderFormPage.vue'),
                    meta: { permission: 'po.edit' }
                },
                {
                    path: 'pembelian/hutang',
                    name: 'pembelian-hutang',
                    component: () => import('@/views/pembelian/SupplierHutangPage.vue'),
                    meta: { permission: 'hutang.view' }
                },
                {
                    path: 'pembelian/pembayaran',
                    name: 'pembelian-pembayaran-hutang',
                    component: () => import('@/views/pembelian/PembayaranHutangPage.vue'),
                    meta: { permission: 'pembayaran-hutang.view' }
                },
                {
                    path: 'pembelian/pembayaran/create',
                    name: 'pembelian-pembayaran-hutang-create',
                    component: () => import('@/views/pembelian/PembayaranHutangFormPage.vue'),
                    meta: { permission: 'pembayaran-hutang.create' }
                },
                {
                    path: 'pembelian/pembayaran/:ulid/edit',
                    name: 'pembelian-pembayaran-hutang-edit',
                    component: () => import('@/views/pembelian/PembayaranHutangFormPage.vue'),
                    meta: { permission: 'pembayaran-hutang.update' }
                },
                {
                    path: 'pembelian/retur',
                    name: 'pembelian-retur',
                    component: () => import('@/views/pembelian/PurchaseReturnPage.vue'),
                    meta: { permission: 'retur-beli.view' }
                },
                {
                    path: 'pembelian/retur/create',
                    name: 'pembelian-retur-create',
                    component: () => import('@/views/pembelian/PurchaseReturnFormPage.vue'),
                    meta: { permission: 'retur-beli.create' }
                },
                {
                    path: 'pembelian/retur/:ulid/edit',
                    name: 'pembelian-retur-edit',
                    component: () => import('@/views/pembelian/PurchaseReturnFormPage.vue'),
                    meta: { permission: 'retur-beli.update' }
                },
                {
                    path: 'pembelian/deposit',
                    name: 'pembelian-deposit',
                    component: () => import('@/views/pembelian/SupplierDepositPage.vue'),
                    meta: { permission: 'deposit-supplier.view' }
                },
                // POS
                // pos-kasir is a standalone route at /pos-kasir (full-screen, no sidebar)
                {
                    path: 'pos/shift',
                    name: 'pos-shift',
                    component: () => import('@/views/pos/ShiftPage.vue'),
                    meta: { permission: 'terminal.view' }
                },
                {
                    path: 'pos/terminal',
                    name: 'pos-terminal',
                    component: () => import('@/views/master/PosTerminalPage.vue'),
                    meta: { permission: 'terminal.view' }
                },
                // Laporan - Penjualan
                {
                    path: 'laporan/penjualan/per-nota',
                    name: 'laporan-penjualan-per-nota',
                    component: () => import('@/views/laporan/penjualan/PerNotaPage.vue'),
                    meta: { permission: 'laporan.penjualan' }
                },
                {
                    path: 'laporan/penjualan/per-barang',
                    name: 'laporan-penjualan-per-barang',
                    component: () => import('@/views/laporan/penjualan/PerBarangPage.vue'),
                    meta: { permission: 'laporan.penjualan' }
                },
                {
                    path: 'laporan/penjualan/pembulatan',
                    name: 'laporan-penjualan-pembulatan',
                    component: () => import('@/views/laporan/penjualan/PembulatanReportPage.vue'),
                    meta: { permission: 'laporan.penjualan' }
                },
                {
                    path: 'laporan/penjualan/disc-line',
                    name: 'laporan-penjualan-disc-line',
                    component: () => import('@/views/laporan/penjualan/DiscLineReportPage.vue'),
                    meta: { permission: 'laporan.penjualan' }
                },
                {
                    path: 'laporan/penjualan/disc-nota',
                    name: 'laporan-penjualan-disc-nota',
                    component: () => import('@/views/laporan/penjualan/DiscNotaReportPage.vue'),
                    meta: { permission: 'laporan.penjualan' }
                },
                {
                    path: 'laporan/penjualan/biaya',
                    name: 'laporan-penjualan-biaya',
                    component: () => import('@/views/laporan/penjualan/BiayaReportPage.vue'),
                    meta: { permission: 'laporan.penjualan' }
                },
                // Laporan - Pembelian
                {
                    path: 'laporan/pembelian/per-dokumen',
                    name: 'laporan-pembelian-per-dokumen',
                    component: () => import('@/views/laporan/pembelian/PerDokumenPage.vue'),
                    meta: { permission: 'laporan.pembelian' }
                },
                {
                    path: 'laporan/pembelian/per-barang',
                    name: 'laporan-pembelian-per-barang',
                    component: () => import('@/views/laporan/pembelian/PerBarangPage.vue'),
                    meta: { permission: 'laporan.pembelian' }
                },
                {
                    path: 'laporan/pembelian/per-supplier',
                    name: 'laporan-pembelian-per-supplier',
                    component: () => import('@/views/laporan/pembelian/PerSupplierPage.vue'),
                    meta: { permission: 'laporan.pembelian' }
                },
                {
                    path: 'laporan/pembelian/diskon',
                    name: 'laporan-pembelian-diskon',
                    component: () => import('@/views/laporan/pembelian/DiskonPage.vue'),
                    meta: { permission: 'laporan.pembelian' }
                },
                {
                    path: 'laporan/pembelian/harga-terakhir',
                    name: 'laporan-pembelian-harga-terakhir',
                    component: () => import('@/views/laporan/pembelian/HargaTerakhirPage.vue'),
                    meta: { permission: 'laporan.pembelian' }
                },
                // Laporan - Keuangan (Sprint 1)
                {
                    path: 'laporan/keuangan/gross-profit',
                    name: 'laporan-keuangan-gross-profit',
                    component: () => import('@/views/laporan/keuangan/GrossProfitPage.vue'),
                    meta: { permissions: ['laporan.keuangan', 'stok.view_hpp'] }
                },
                {
                    path: 'laporan/keuangan/margin-per-barang',
                    name: 'laporan-keuangan-margin-per-barang',
                    component: () => import('@/views/laporan/keuangan/MarginPerBarangPage.vue'),
                    meta: { permissions: ['laporan.keuangan', 'stok.view_hpp'] }
                },
                {
                    path: 'laporan/keuangan/arus-kas',
                    name: 'laporan-keuangan-arus-kas',
                    component: () => import('@/views/laporan/keuangan/ArusKasPage.vue'),
                    meta: { permission: 'laporan.keuangan' }
                },
                // Laporan - Performa (Sprint 1 + 3)
                {
                    path: 'laporan/performa/kasir',
                    name: 'laporan-performa-kasir',
                    component: () => import('@/views/laporan/performa/KasirPerformancePage.vue'),
                    meta: { permission: 'laporan.performa' }
                },
                {
                    path: 'laporan/performa/metode-pembayaran',
                    name: 'laporan-performa-metode-pembayaran',
                    component: () => import('@/views/laporan/performa/MetodePembayaranPage.vue'),
                    meta: { permission: 'laporan.performa' }
                },
                {
                    path: 'laporan/performa/top-customer',
                    name: 'laporan-performa-top-customer',
                    component: () => import('@/views/laporan/performa/TopCustomerPage.vue'),
                    meta: { permission: 'laporan.performa' }
                },
                // Laporan - Promo & Diskon (Sprint 2)
                {
                    path: 'laporan/promo/usage',
                    name: 'laporan-promo-usage',
                    component: () => import('@/views/laporan/promo/PromoUsagePage.vue'),
                    meta: { permission: 'laporan.promo' }
                },
                {
                    path: 'laporan/promo/produk',
                    name: 'laporan-promo-produk',
                    component: () => import('@/views/laporan/promo/ProductPromoPage.vue'),
                    meta: { permission: 'laporan.promo' }
                },
                {
                    path: 'laporan/promo/customer',
                    name: 'laporan-promo-customer',
                    component: () => import('@/views/laporan/promo/CustomerPromoPage.vue'),
                    meta: { permission: 'laporan.promo' }
                },
                // Laporan - Inventory (Sprint 3)
                {
                    path: 'laporan/inventory/retur-pattern',
                    name: 'laporan-inventory-retur-pattern',
                    component: () => import('@/views/laporan/inventory/ReturPatternPage.vue'),
                    meta: { permission: 'laporan.inventory' }
                },
                {
                    path: 'laporan/inventory/dead-stock',
                    name: 'laporan-inventory-dead-stock',
                    component: () => import('@/views/laporan/inventory/DeadStockPage.vue'),
                    meta: { permission: 'laporan.inventory' }
                },
                // Pengaturan
                {
                    path: 'pengaturan/user',
                    name: 'pengaturan-user',
                    component: () => import('@/views/pengaturan/UserPage.vue'),
                    meta: { permission: 'user.view' }
                },
                {
                    path: 'pengaturan/role',
                    name: 'pengaturan-role',
                    component: () => import('@/views/pengaturan/RolePage.vue'),
                    meta: { permission: 'role.view' }
                },
                {
                    path: 'pengaturan/settings',
                    name: 'pengaturan-settings',
                    component: () => import('@/views/pengaturan/SettingsPage.vue'),
                    meta: { permission: 'settings.view' }
                },
                {
                    path: 'pengaturan/import',
                    name: 'pengaturan-import',
                    component: () => import('@/views/pengaturan/ImportMasterPage.vue'),
                    meta: { permission: 'import.master' }
                },
                {
                    path: 'pengaturan/reset-database',
                    name: 'pengaturan-reset-database',
                    component: () => import('@/views/pengaturan/ResetDatabasePage.vue'),
                    meta: { permission: 'settings.reset' }
                }
            ]
        },

        // Public receipt
        {
            path: '/struk-online/:ulid',
            name: 'struk-online',
            component: () => import('@/views/public/StrukOnlinePage.vue')
        },

        // 404
        {
            path: '/:pathMatch(.*)*',
            name: 'notfound',
            component: () => import('@/views/pages/NotFound.vue')
        }
    ]
});

// Navigation Guards
router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();

    // Bootstrap: on the very first navigation of a browser session, if we have
    // a stored token, verify it's still valid before any routing decision.
    // Without this, an expired token lets the user see the dashboard for a
    // frame before fetchUser() fails and redirects to login — jarring flash.
    if (!authStore.bootstrapped) {
        if (authStore.token) {
            try {
                await authStore.fetchUser();
            } catch {
                // fetchUser handles logout on 401; ignore here
            }
        }
        authStore.bootstrapped = true;
    }

    // Check if route requires authentication
    const requiresAuth = to.matched.some((record) => record.meta.requiresAuth);
    const isGuestRoute = to.matched.some((record) => record.meta.guest);

    // If authenticated and trying to access guest route (login), redirect to dashboard
    if (isGuestRoute && authStore.isAuthenticated) {
        return next({ name: 'dashboard' });
    }

    // If route requires auth and user is not authenticated
    if (requiresAuth && !authStore.isAuthenticated) {
        return next({ name: 'login', query: { redirect: to.fullPath } });
    }

    // Check permission if specified (single)
    const permission = to.meta.permission;
    if (permission && authStore.isAuthenticated && !authStore.can(permission)) {
        return next({ name: 'accessDenied' });
    }

    const permissions = to.meta.permissions;
    if (Array.isArray(permissions) && permissions.length > 0 && authStore.isAuthenticated) {
        const missing = permissions.some((p) => !authStore.can(p));
        if (missing) {
            return next({ name: 'accessDenied' });
        }
    }

    // Modul Elektronik (serial) nonaktif → blok route serial, arahkan ke dashboard
    if (to.meta.requiresElektronik && authStore.isAuthenticated && !useSettingsStore().serialEnabled) {
        return next({ name: 'dashboard' });
    }

    next();
});

export default router;
