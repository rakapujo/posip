import { test, expect } from '@playwright/test';
import { getAuthData, injectAuth } from './helpers/auth.js';

let authData;

test.describe('Laporan smoke', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(async ({ request }) => {
        authData = await getAuthData(request);
    });

    test.beforeEach(async ({ page }) => {
        await injectAuth(page, authData);
    });

    test('penjualan per nota page loads table shell', async ({ page }) => {
        await page.goto('/app/laporan/penjualan/per-nota');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('toolbar').getByText('Penjualan per Nota')).toBeVisible({ timeout: 10000 });
        await expect(page.getByPlaceholder('Cari no. invoice, customer...')).toBeVisible();
        await expect(page.locator('.p-datatable')).toBeVisible();
    });

    test('penjualan per barang page loads summary cards', async ({ page }) => {
        await page.goto('/app/laporan/penjualan/per-barang');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('toolbar').getByText('Penjualan per Barang')).toBeVisible({ timeout: 10000 });
        await expect(page.getByText('Produk', { exact: true }).first()).toBeVisible();
        await expect(page.getByPlaceholder('Cari kode, nama produk...')).toBeVisible();
    });

    test('pembelian per dokumen page loads', async ({ page }) => {
        await page.goto('/app/laporan/pembelian/per-dokumen');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('toolbar').getByText('Laporan Pembelian per Dokumen')).toBeVisible({ timeout: 10000 });
        await expect(page.getByText('Jumlah PO')).toBeVisible();
    });

    test('gross profit page loads summary cards', async ({ page }) => {
        await page.goto('/app/laporan/keuangan/gross-profit');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Laporan Gross Profit')).toBeVisible({ timeout: 10000 });
        await expect(page.getByText('Setelah dikurangi retur', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Trend Harian')).toBeVisible();
    });

    test('gross profit export button visible for admin', async ({ page }) => {
        await page.goto('/app/laporan/keuangan/gross-profit');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('button', { name: 'Export Excel', exact: true })).toBeVisible({ timeout: 10000 });
    });

    test('top customer page loads with export', async ({ page }) => {
        await page.goto('/app/laporan/performa/top-customer');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('toolbar').getByText('Top Customer')).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('button', { name: 'Export Excel', exact: true })).toBeVisible();
    });

    test('metode pembayaran page loads with export', async ({ page }) => {
        await page.goto('/app/laporan/performa/metode-pembayaran');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Breakdown Metode Pembayaran')).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('button', { name: 'Export Excel', exact: true })).toBeVisible();
    });

    test('retur pattern page loads with export', async ({ page }) => {
        await page.goto('/app/laporan/inventory/retur-pattern');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Pattern Retur Penjualan')).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('button', { name: 'Export Excel', exact: true })).toBeVisible();
    });

    test('pembulatan financial report loads pdf export', async ({ page }) => {
        await page.goto('/app/laporan/penjualan/pembulatan');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Laporan Pembulatan')).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('button', { name: 'Export PDF' })).toBeVisible();
    });
});
