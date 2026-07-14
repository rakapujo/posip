import { test, expect } from '@playwright/test';

test.describe('Laporan smoke', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(async ({ page }) => {
        await page.goto('/');
        await page.waitForLoadState('domcontentloaded');
        await page.locator('#email').waitFor({ state: 'visible', timeout: 15000 });
        await page.locator('#email').fill('admin@posip.com');
        await page.locator('#password input').fill('password');
        await page.locator('button[type="submit"]').click();
        await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 30000 });
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
        await expect(page.getByText('Total Produk')).toBeVisible();
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
        await expect(page.getByText('Revenue (Net)')).toBeVisible();
        await expect(page.getByText('Trend Harian')).toBeVisible();
    });

    test('gross profit export button visible for admin', async ({ page }) => {
        await page.goto('/app/laporan/keuangan/gross-profit');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('button', { name: 'Export Excel' })).toBeVisible({ timeout: 10000 });
    });

    test('top customer page loads with export', async ({ page }) => {
        await page.goto('/app/laporan/performa/top-customer');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Top Customer')).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('button', { name: 'Export Excel' })).toBeVisible();
    });

    test('metode pembayaran page loads with export', async ({ page }) => {
        await page.goto('/app/laporan/performa/metode-pembayaran');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Breakdown Metode Pembayaran')).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('button', { name: 'Export Excel' })).toBeVisible();
    });

    test('retur pattern page loads with export', async ({ page }) => {
        await page.goto('/app/laporan/inventory/retur-pattern');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Pattern Retur Penjualan')).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('button', { name: 'Export Excel' })).toBeVisible();
    });

    test('pembulatan financial report loads pdf export', async ({ page }) => {
        await page.goto('/app/laporan/keuangan/pembulatan');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Laporan Pembulatan')).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('button', { name: 'Export PDF' })).toBeVisible();
    });
});
