import { test, expect } from '@playwright/test';
import { loginViaApi, snap, waitForDataTable, waitForDetailDialog } from './docs-helpers.js';

test.describe('CRUD documentation screenshots', () => {
    test('capture create, edit, detail, delete dialogs', async ({ page, baseURL }) => {
        test.setTimeout(300000);
        const apiURL = `${baseURL}/api/v1`;
        await loginViaApi(page, apiURL, baseURL);

        // Produk
        await page.goto(`${baseURL}/app/master/produk`);
        await waitForDataTable(page);
        await snap(page, 'crud-produk-list');

        await page.getByRole('button', { name: 'Tambah Produk' }).click();
        await expect(page.locator('.p-dialog-title', { hasText: 'Tambah Produk' })).toBeVisible();
        await page.waitForTimeout(800);
        await snap(page, 'crud-produk-form-tambah');
        await page.getByRole('button', { name: 'Batal' }).click();

        const row = page.locator('.p-datatable-tbody tr').first();
        await row.locator('button[aria-label="Lihat Detail"]').click();
        await waitForDetailDialog(page);
        await snap(page, 'crud-produk-detail');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await row.locator('button[aria-label="Edit"]').click();
        await expect(page.locator('.p-dialog-title', { hasText: 'Edit Produk' })).toBeVisible();
        await page.waitForTimeout(600);
        await snap(page, 'crud-produk-form-edit');
        await page.getByRole('button', { name: 'Batal' }).click();

        await row.locator('button[aria-label="Hapus"]').click();
        await expect(page.getByText('Konfirmasi Hapus').first()).toBeVisible({ timeout: 8000 });
        await snap(page, 'crud-produk-hapus-konfirmasi');
        await page.getByRole('button', { name: 'Batal' }).click();

        // Brand — seed one row if empty (demo DB may have no brands)
        await page.goto(`${baseURL}/app/master/brand`);
        await waitForDataTable(page);
        if (await page.getByText('Tidak ada data brand').isVisible().catch(() => false)) {
            await page.getByRole('button', { name: 'Tambah Brand' }).click();
            await expect(page.locator('.p-dialog-title', { hasText: 'Tambah Brand' })).toBeVisible();
            const kode = `E2EB${Date.now().toString().slice(-5)}`;
            await page.getByPlaceholder('Masukkan kode brand').fill(kode);
            await page.getByPlaceholder('Masukkan nama brand').fill('E2E Brand Docs');
            await page.locator('.p-dialog').filter({ hasText: 'Tambah Brand' }).getByRole('button', { name: /Simpan/i }).click();
            await expect(page.locator('.p-dialog-title', { hasText: 'Tambah Brand' })).toBeHidden({ timeout: 15000 });
            await waitForDataTable(page);
        }
        await snap(page, 'crud-brand-list');
        await page.getByRole('button', { name: 'Tambah Brand' }).click();
        await expect(page.locator('.p-dialog-title', { hasText: 'Tambah Brand' })).toBeVisible();
        await snap(page, 'crud-brand-form-tambah');
        await page.getByRole('button', { name: 'Batal' }).click();
        const brandRow = page.locator('.p-datatable-tbody tr').first();
        await expect(brandRow).toBeVisible({ timeout: 10000 });
        await brandRow.locator('button[aria-label="Edit"], .pi-pencil').first().click();
        await expect(page.locator('.p-dialog-title', { hasText: 'Edit Brand' })).toBeVisible({ timeout: 15000 });
        await snap(page, 'crud-brand-form-edit');
        await page.getByRole('button', { name: 'Batal' }).click();

        // PO
        await page.goto(`${baseURL}/app/pembelian/po`);
        await waitForDataTable(page);
        await snap(page, 'crud-po-list');
        await page.getByRole('button', { name: 'Buat PO' }).click();
        await page.waitForURL(/po\/create/, { timeout: 15000 });
        await page.waitForTimeout(1200);
        await snap(page, 'crud-po-form-tambah');

        // Adjustment — gunakan nama file yang sama dengan docs (list + crud-adjustment-list)
        await page.goto(`${baseURL}/app/inventory/adjustment`);
        await waitForDataTable(page);
        await snap(page, '15-adjustment');
        await snap(page, 'crud-adjustment-list');
        await page.getByRole('button', { name: 'Tambah Adjustment' }).click();
        await page.waitForURL(/adjustment\/create/, { timeout: 15000 });
        await page.waitForTimeout(800);
        await snap(page, 'crud-adjustment-form-tambah');

        // User (admin)
        await page.goto(`${baseURL}/app/pengaturan/user`);
        await waitForDataTable(page);
        await snap(page, 'crud-user-list');
        await page.getByRole('button', { name: 'Tambah User' }).click();
        await expect(page.locator('.p-dialog-title', { hasText: 'Tambah User' })).toBeVisible();
        await snap(page, 'crud-user-form-tambah');
    });
});
