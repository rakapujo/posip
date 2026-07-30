import { test, expect } from '@playwright/test';
import { getAuthData, injectAuth } from './helpers/auth.js';
import { waitForDataTable } from './docs-helpers.js';

/**
 * Minimal smoke: hutang supplier list mounts with table shell.
 */
let authData;

test.describe('Supplier hutang smoke @smoke', () => {
    test.beforeAll(async ({ request }) => {
        authData = await getAuthData(request);
    });

    test.beforeEach(async ({ page }) => {
        await injectAuth(page, authData);
    });

    test('hutang page loads datatable', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/app/pembelian/hutang`);
        await page.waitForLoadState('domcontentloaded');
        await page.waitForLoadState('networkidle').catch(() => {});

        const denied = await page.locator('text=Akses Ditolak').isVisible({ timeout: 2000 }).catch(() => false);
        expect(denied).toBeFalsy();

        await waitForDataTable(page, 20000).catch(() => {});
        await expect(page.getByRole('heading', { name: /Daftar Hutang Supplier/i })).toBeVisible({ timeout: 15000 });
        await expect(page.locator('.p-datatable').first()).toBeVisible();
    });
});
