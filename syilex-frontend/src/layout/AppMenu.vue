<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import AppMenuItem from './AppMenuItem.vue';

const authStore = useAuthStore();
const settingsStore = useSettingsStore();
const can = (perm) => authStore.can(perm);
// Modul Elektronik (serial) on/off — sembunyikan menu serial saat nonaktif
const serialEnabled = computed(() => settingsStore.serialEnabled);
const anyVisible = (items) => items.some((i) => i.visible !== false);

const model = computed(() => {
    // ── Submenu children (built first so parents can check visibility) ──

    const klasifikasiProdukItems = [
        { label: 'Tipe Produk', icon: 'pi pi-fw pi-circle', to: '/app/master/tipe', visible: can('tipe.view') },
        { label: 'Kategori Produk', icon: 'pi pi-fw pi-circle', to: '/app/master/kategori', visible: can('kategori.view') },
        { label: 'Grup Produk', icon: 'pi pi-fw pi-circle', to: '/app/master/grup', visible: can('grup.view') }
    ];

    const klasifikasiCustomerItems = [
        { label: 'Tipe Customer', icon: 'pi pi-fw pi-circle', to: '/app/master/tipe-customer', visible: can('tipe-customer.view') },
        { label: 'Kategori Customer', icon: 'pi pi-fw pi-circle', to: '/app/master/kategori-customer', visible: can('kategori-customer.view') }
    ];

    const laporanPenjualanItems = [
        { label: 'Per Nota', icon: 'pi pi-fw pi-receipt', to: '/app/laporan/penjualan/per-nota', visible: can('laporan.penjualan') },
        { label: 'Per Barang', icon: 'pi pi-fw pi-chart-bar', to: '/app/laporan/penjualan/per-barang', visible: can('laporan.penjualan') },
        { label: 'Pembulatan', icon: 'pi pi-fw pi-calculator', to: '/app/laporan/penjualan/pembulatan', visible: can('laporan.penjualan') },
        { label: 'Disc Line', icon: 'pi pi-fw pi-percentage', to: '/app/laporan/penjualan/disc-line', visible: can('laporan.penjualan') },
        { label: 'Disc Nota', icon: 'pi pi-fw pi-receipt', to: '/app/laporan/penjualan/disc-nota', visible: can('laporan.penjualan') },
        { label: 'Biaya', icon: 'pi pi-fw pi-wallet', to: '/app/laporan/penjualan/biaya', visible: can('laporan.penjualan') }
    ];

    const laporanPembelianItems = [
        { label: 'Per Dokumen', icon: 'pi pi-fw pi-file', to: '/app/laporan/pembelian/per-dokumen', visible: can('laporan.pembelian') },
        { label: 'Per Barang', icon: 'pi pi-fw pi-box', to: '/app/laporan/pembelian/per-barang', visible: can('laporan.pembelian') },
        { label: 'Per Supplier', icon: 'pi pi-fw pi-truck', to: '/app/laporan/pembelian/per-supplier', visible: can('laporan.pembelian') },
        { label: 'Diskon', icon: 'pi pi-fw pi-percentage', to: '/app/laporan/pembelian/diskon', visible: can('laporan.pembelian') && can('po.view_harga') },
        { label: 'Harga Terakhir', icon: 'pi pi-fw pi-history', to: '/app/laporan/pembelian/harga-terakhir', visible: can('laporan.pembelian') }
    ];

    const laporanKeuanganItems = [
        { label: 'Gross Profit', icon: 'pi pi-fw pi-chart-line', to: '/app/laporan/keuangan/gross-profit', visible: can('laporan.keuangan') && can('stok.view_hpp') },
        { label: 'Margin per Barang', icon: 'pi pi-fw pi-percentage', to: '/app/laporan/keuangan/margin-per-barang', visible: can('laporan.keuangan') && can('stok.view_hpp') },
        { label: 'Arus Kas Harian', icon: 'pi pi-fw pi-money-bill', to: '/app/laporan/keuangan/arus-kas', visible: can('laporan.keuangan') }
    ];

    const laporanPerformaItems = [
        { label: 'Performance Kasir', icon: 'pi pi-fw pi-user', to: '/app/laporan/performa/kasir', visible: can('laporan.performa') },
        { label: 'Metode Pembayaran', icon: 'pi pi-fw pi-credit-card', to: '/app/laporan/performa/metode-pembayaran', visible: can('laporan.performa') },
        { label: 'Top Customer', icon: 'pi pi-fw pi-users', to: '/app/laporan/performa/top-customer', visible: can('laporan.performa') }
    ];

    const laporanPromoItems = [
        { label: 'Promo Usage & ROI', icon: 'pi pi-fw pi-chart-pie', to: '/app/laporan/promo/usage', visible: can('laporan.promo') },
        { label: 'Produk Dapat Promo', icon: 'pi pi-fw pi-box', to: '/app/laporan/promo/produk', visible: can('laporan.promo') },
        { label: 'Customer Dapat Promo', icon: 'pi pi-fw pi-users', to: '/app/laporan/promo/customer', visible: can('laporan.promo') }
    ];

    const laporanInventoryItems = [
        { label: 'Retur Pattern', icon: 'pi pi-fw pi-replay', to: '/app/laporan/inventory/retur-pattern', visible: can('laporan.inventory') },
        { label: 'Dead Stock', icon: 'pi pi-fw pi-exclamation-triangle', to: '/app/laporan/inventory/dead-stock', visible: can('laporan.inventory') }
    ];

    // ── Section items ──

    const masterItems = [
        { label: 'Produk', icon: 'pi pi-fw pi-box', to: '/app/master/produk', visible: can('produk.view') },
        { label: 'Perubahan Harga', icon: 'pi pi-fw pi-tag', to: '/app/master/price-change', visible: can('price-change.view') },
        { label: 'Perubahan Data Serial', icon: 'pi pi-fw pi-pencil', to: '/app/master/serial-change', visible: serialEnabled.value && can('serial-change.view') },
        { label: 'Brand Produk', icon: 'pi pi-fw pi-bookmark', to: '/app/master/brand', visible: can('brand.view') },
        { label: 'Klasifikasi Produk', icon: 'pi pi-fw pi-folder', path: '/klasifikasi', items: klasifikasiProdukItems, visible: anyVisible(klasifikasiProdukItems) },
        { label: 'Supplier', icon: 'pi pi-fw pi-truck', to: '/app/master/supplier', visible: can('supplier.view') },
        { label: 'Customer', icon: 'pi pi-fw pi-users', to: '/app/master/customer', visible: can('customer.view') },
        { label: 'Klasifikasi Customer', icon: 'pi pi-fw pi-id-card', path: '/klasifikasi-customer', items: klasifikasiCustomerItems, visible: anyVisible(klasifikasiCustomerItems) },
        { label: 'Warehouse', icon: 'pi pi-fw pi-building', to: '/app/master/warehouse', visible: can('warehouse.view') },
        { label: 'Metode Pembayaran', icon: 'pi pi-fw pi-credit-card', to: '/app/master/metode-pembayaran', visible: can('metode-bayar.view') },
        { label: 'Promo', icon: 'pi pi-fw pi-tag', to: '/app/master/promo', visible: can('promo.view') },
        { label: 'Print Barcode', icon: 'pi pi-fw pi-barcode', to: '/app/master/print-barcode', visible: can('produk.print-barcode') }
    ];

    const inventoryItems = [
        { label: 'Stok', icon: 'pi pi-fw pi-database', to: '/app/inventory/stok', visible: can('stok.view') },
        { label: 'Kartu Stok', icon: 'pi pi-fw pi-history', to: '/app/inventory/kartu-stok', visible: can('stok.view') },
        { label: 'Pergerakan HPP', icon: 'pi pi-fw pi-chart-line', to: '/app/inventory/pergerakan-hpp', visible: can('stok.view_hpp') },
        { label: 'Register Unit Serial', icon: 'pi pi-fw pi-qrcode', to: '/app/inventory/serial-units', visible: serialEnabled.value && can('serial-intake.view') },
        { label: 'Koreksi HPP Serial', icon: 'pi pi-fw pi-dollar', to: '/app/inventory/serial-hpp', visible: serialEnabled.value && can('serial-hpp.view') },
        { label: 'Stock Opname', icon: 'pi pi-fw pi-clipboard', to: '/app/inventory/opname', visible: can('opname.view') },
        { label: 'Adjustment', icon: 'pi pi-fw pi-sliders-h', to: '/app/inventory/adjustment', visible: can('adjustment.view') },
        { label: 'Transfer', icon: 'pi pi-fw pi-arrows-h', to: '/app/inventory/transfer', visible: can('transfer.view') },
        { label: 'Repack', icon: 'pi pi-fw pi-sync', to: '/app/inventory/repack', visible: can('repack.view') },
        { label: 'Koreksi HPP', icon: 'pi pi-fw pi-pencil', to: '/app/inventory/hpp-correction', visible: can('hpp.view') }
    ];

    const pembelianItems = [
        { label: 'Purchase Order', icon: 'pi pi-fw pi-shopping-cart', to: '/app/pembelian/po', visible: can('po.view') },
        { label: 'Purchase Order Serial', icon: 'pi pi-fw pi-qrcode', to: '/app/inventory/serial-intake', visible: serialEnabled.value && can('serial-intake.view') },
        { label: 'Hutang Supplier', icon: 'pi pi-fw pi-wallet', to: '/app/pembelian/hutang', visible: can('hutang.view') },
        { label: 'Pembayaran Hutang', icon: 'pi pi-fw pi-money-bill', to: '/app/pembelian/pembayaran', visible: can('pembayaran-hutang.view') },
        { label: 'Retur Pembelian', icon: 'pi pi-fw pi-replay', to: '/app/pembelian/retur', visible: can('retur-beli.view') },
        { label: 'Deposit Supplier', icon: 'pi pi-fw pi-dollar', to: '/app/pembelian/deposit', visible: can('deposit-supplier.view') }
    ];

    const posItems = [
        { label: 'Shift', icon: 'pi pi-fw pi-clock', to: '/app/pos/shift', visible: can('terminal.view') },
        { label: 'Terminal', icon: 'pi pi-fw pi-desktop', to: '/app/pos/terminal', visible: can('terminal.view') }
    ];

    const laporanItems = [
        { label: 'Penjualan', icon: 'pi pi-fw pi-chart-line', path: '/laporan-penjualan', items: laporanPenjualanItems, visible: anyVisible(laporanPenjualanItems) },
        { label: 'Pembelian', icon: 'pi pi-fw pi-shopping-cart', path: '/laporan-pembelian', items: laporanPembelianItems, visible: anyVisible(laporanPembelianItems) },
        { label: 'Keuangan', icon: 'pi pi-fw pi-wallet', path: '/laporan-keuangan', items: laporanKeuanganItems, visible: anyVisible(laporanKeuanganItems) },
        { label: 'Promo & Diskon', icon: 'pi pi-fw pi-tag', path: '/laporan-promo', items: laporanPromoItems, visible: anyVisible(laporanPromoItems) },
        { label: 'Performa', icon: 'pi pi-fw pi-chart-bar', path: '/laporan-performa', items: laporanPerformaItems, visible: anyVisible(laporanPerformaItems) },
        { label: 'Inventory', icon: 'pi pi-fw pi-database', path: '/laporan-inventory', items: laporanInventoryItems, visible: anyVisible(laporanInventoryItems) }
    ];

    const pengaturanItems = [
        { label: 'User', icon: 'pi pi-fw pi-user', to: '/app/pengaturan/user', visible: can('user.view') },
        { label: 'Role & Permission', icon: 'pi pi-fw pi-lock', to: '/app/pengaturan/role', visible: can('role.view') },
        { label: 'Global Settings', icon: 'pi pi-fw pi-cog', to: '/app/pengaturan/settings', visible: can('settings.view') },
        { label: 'Import Master', icon: 'pi pi-fw pi-upload', to: '/app/pengaturan/import', visible: can('import.master') },
        { label: 'Reset Database', icon: 'pi pi-fw pi-database', to: '/app/pengaturan/reset-database', visible: can('settings.reset') }
    ];

    // ── Build sections (auto-hide if no visible items) ──

    return [
        {
            label: 'Home',
            items: [{ label: 'Dashboard', icon: 'pi pi-fw pi-home', to: '/app' }]
        },
        { label: 'Master Data', items: masterItems, visible: anyVisible(masterItems) },
        { label: 'Inventory', items: inventoryItems, visible: anyVisible(inventoryItems) },
        { label: 'Pembelian', items: pembelianItems, visible: anyVisible(pembelianItems) },
        { label: 'POS', items: posItems, visible: anyVisible(posItems) },
        { label: 'Laporan', items: laporanItems, visible: anyVisible(laporanItems) },
        { label: 'Pengaturan', items: pengaturanItems, visible: anyVisible(pengaturanItems) }
    ];
});
</script>

<template>
    <ul class="layout-menu">
        <template v-for="(item, i) in model" :key="item">
            <app-menu-item v-if="!item.separator" :item="item" :index="i"></app-menu-item>
            <li v-if="item.separator" class="menu-separator"></li>
        </template>
    </ul>
</template>

<style lang="scss" scoped></style>
