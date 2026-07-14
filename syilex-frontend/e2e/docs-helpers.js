import fs from 'fs';
import path from 'path';
import { expect } from '@playwright/test';

export const OUT_DIR = path.resolve(process.cwd(), '../docs/assets/screenshots');

export async function loginViaApi(page, apiURL, baseURL) {
    await page.goto(baseURL);
    const res = await page.request.post(`${apiURL}/auth/login`, {
        headers: { Accept: 'application/json' },
        data: { email: 'admin@posip.com', password: 'password' },
    });
    expect(res.ok()).toBeTruthy();
    const auth = (await res.json()).data;
    await page.evaluate(({ token, user, permissions }) => {
        localStorage.setItem('token', token);
        localStorage.setItem('user', JSON.stringify(user));
        localStorage.setItem('permissions', JSON.stringify(permissions || []));
    }, auth);
}

/** Tunggu DataTable selesai loading (overlay hilang). */
export async function waitForDataTable(page, timeout = 45000) {
    await page.waitForSelector('.p-datatable', { timeout });
    await page.waitForFunction(() => {
        const overlay = document.querySelector('.p-datatable-loading-overlay');
        if (overlay && overlay.offsetParent !== null) return false;
        const spinner = document.querySelector('.p-datatable .p-progressspinner');
        if (spinner && spinner.offsetParent !== null) return false;
        return true;
    }, { timeout });
    await page.waitForTimeout(400);
}

/** Tunggu dialog detail selesai fetch (spinner hilang, ada konten). */
export async function waitForDetailDialog(page, timeout = 45000) {
    const dialog = page.locator('.p-dialog').last();
    await expect(dialog).toBeVisible({ timeout: 15000 });
    await page.waitForFunction(() => {
        const d = document.querySelector('.p-dialog:last-of-type');
        if (!d) return false;
        const loading = d.querySelector('.p-progressspinner');
        if (loading && loading.offsetParent !== null) return false;
        const text = d.innerText || '';
        return text.length > 80 && !text.includes('Memuat');
    }, { timeout });
    await page.waitForTimeout(500);
}

export async function snap(page, name, opts = {}) {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    if (opts.waitTable) {
        await waitForDataTable(page).catch(async () => {
            await page.waitForLoadState('networkidle').catch(() => {});
            await page.waitForTimeout(1200);
        });
    } else {
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.waitForTimeout(800);
    }
    await page.waitForTimeout(opts.delay ?? 300);
    await page.screenshot({
        path: path.join(OUT_DIR, `${name}.png`),
        fullPage: opts.fullPage ?? false,
    });
}

export async function gotoMenu(page, baseURL, route) {
    await page.goto(`${baseURL}${route}`);
    if (await page.locator('text=Akses Ditolak').isVisible({ timeout: 2000 }).catch(() => false)) {
        return false;
    }
    try {
        await waitForDataTable(page);
    } catch {
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.waitForTimeout(1500);
    }
    return true;
}

/** Semua halaman daftar menu operasional (admin super punya akses penuh). */
export const ALL_MENU_ROUTES = [
    ['02-dashboard', '/app', false],
    ['03-master-produk', '/app/master/produk', true],
    ['menu-master-price-change', '/app/master/price-change', true],
    ['menu-master-serial-change', '/app/master/serial-change', true],
    ['menu-master-brand', '/app/master/brand', true],
    ['menu-master-tipe', '/app/master/tipe', true],
    ['menu-master-kategori', '/app/master/kategori', true],
    ['menu-master-grup', '/app/master/grup', true],
    ['menu-master-supplier', '/app/master/supplier', true],
    ['menu-master-customer', '/app/master/customer', true],
    ['menu-master-tipe-customer', '/app/master/tipe-customer', true],
    ['menu-master-kategori-customer', '/app/master/kategori-customer', true],
    ['menu-master-warehouse', '/app/master/warehouse', true],
    ['menu-master-metode-bayar', '/app/master/metode-pembayaran', true],
    ['menu-master-promo', '/app/master/promo', true],
    ['menu-master-print-barcode', '/app/master/print-barcode', false],
    ['04-inventory-stok', '/app/inventory/stok', true],
    ['menu-inventory-kartu-stok', '/app/inventory/kartu-stok', false],
    ['menu-inventory-pergerakan-hpp', '/app/inventory/pergerakan-hpp', true],
    ['menu-inventory-serial-units', '/app/inventory/serial-units', true],
    ['menu-inventory-serial-hpp', '/app/inventory/serial-hpp', true],
    ['menu-inventory-opname', '/app/inventory/opname', true],
    ['15-adjustment', '/app/inventory/adjustment', true],
    ['menu-inventory-transfer', '/app/inventory/transfer', true],
    ['menu-inventory-repack', '/app/inventory/repack', true],
    ['menu-inventory-hpp-correction', '/app/inventory/hpp-correction', true],
    ['menu-inventory-serial-intake', '/app/inventory/serial-intake', true],
    ['05-pembelian-po', '/app/pembelian/po', true],
    ['menu-pembelian-hutang', '/app/pembelian/hutang', true],
    ['menu-pembelian-pembayaran', '/app/pembelian/pembayaran', true],
    ['menu-pembelian-retur', '/app/pembelian/retur', true],
    ['menu-pembelian-deposit', '/app/pembelian/deposit', true],
    ['06-pos-terminal', '/app/pos/terminal', true],
    ['07-pos-shift', '/app/pos/shift', true],
    ['08-laporan-per-nota', '/app/laporan/penjualan/per-nota', true],
    ['menu-laporan-penjualan-barang', '/app/laporan/penjualan/per-barang', true],
    ['menu-laporan-penjualan-pembulatan', '/app/laporan/penjualan/pembulatan', true],
    ['menu-laporan-penjualan-disc-line', '/app/laporan/penjualan/disc-line', true],
    ['menu-laporan-penjualan-disc-nota', '/app/laporan/penjualan/disc-nota', true],
    ['menu-laporan-penjualan-biaya', '/app/laporan/penjualan/biaya', true],
    ['menu-laporan-pembelian-dokumen', '/app/laporan/pembelian/per-dokumen', true],
    ['menu-laporan-pembelian-barang', '/app/laporan/pembelian/per-barang', true],
    ['menu-laporan-pembelian-supplier', '/app/laporan/pembelian/per-supplier', true],
    ['menu-laporan-pembelian-diskon', '/app/laporan/pembelian/diskon', true],
    ['menu-laporan-pembelian-harga', '/app/laporan/pembelian/harga-terakhir', true],
    ['menu-laporan-keuangan-gross', '/app/laporan/keuangan/gross-profit', true],
    ['menu-laporan-keuangan-margin', '/app/laporan/keuangan/margin-per-barang', true],
    ['menu-laporan-keuangan-kas', '/app/laporan/keuangan/arus-kas', true],
    ['menu-laporan-performa-kasir', '/app/laporan/performa/kasir', true],
    ['menu-laporan-performa-metode', '/app/laporan/performa/metode-pembayaran', true],
    ['menu-laporan-performa-customer', '/app/laporan/performa/top-customer', true],
    ['menu-laporan-promo-usage', '/app/laporan/promo/usage', true],
    ['menu-laporan-promo-produk', '/app/laporan/promo/produk', true],
    ['menu-laporan-promo-customer', '/app/laporan/promo/customer', true],
    ['menu-laporan-inventory-retur', '/app/laporan/inventory/retur-pattern', true],
    ['menu-laporan-inventory-deadstock', '/app/laporan/inventory/dead-stock', true],
];
