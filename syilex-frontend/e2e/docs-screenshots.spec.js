import { test, expect } from '@playwright/test';
import {
    loginViaApi,
    snap,
    waitForDataTable,
    gotoMenu,
    ALL_MENU_ROUTES,
} from './docs-helpers.js';

async function waitForLoginPage(page, baseURL) {
    await page.goto(baseURL);
    const emailInput = page.locator('input[type="email"], input[placeholder*="@"], input[placeholder*="company"]').first();
    await expect(emailInput).toBeVisible({ timeout: 60000 });
}

test.describe('Documentation screenshots', () => {
    test('capture all menu list screens', async ({ page, baseURL }) => {
        test.setTimeout(600000);
        const apiURL = `${baseURL}/api/v1`;

        await waitForLoginPage(page, baseURL);
        await snap(page, '01-login');

        await loginViaApi(page, apiURL, baseURL);

        for (const [name, route, isTable] of ALL_MENU_ROUTES) {
            if (route === '/app') {
                await page.goto(baseURL + route);
                await page.waitForURL(/\/app/, { timeout: 15000 });
                await page.waitForTimeout(1200);
                await snap(page, name);
                continue;
            }
            const ok = await gotoMenu(page, baseURL, route).catch(() => false);
            if (ok !== false) await snap(page, name, { waitTable: isTable });
        }

        await page.goto(`${baseURL}/app/pos/terminal`);
        await waitForDataTable(page).catch(() => {});
        const startBtn = page.locator('button:has-text("Mulai Shift"), button:has-text("Buka Shift")').first();
        if (await startBtn.isVisible().catch(() => false)) {
            await startBtn.click();
            const setor = page.locator('button:has-text("Simpan"), button:has-text("Mulai")').first();
            if (await setor.isVisible({ timeout: 8000 }).catch(() => false)) {
                await setor.click();
                await page.waitForTimeout(2500);
            }
        }
        await page.goto(`${baseURL}/pos-kasir`);
        const search = page.locator('input[placeholder*="Cari produk"]').first();
        if (await search.isVisible({ timeout: 20000 }).catch(() => false)) {
            await page.waitForTimeout(1000);
            await snap(page, '17-pos-kasir');
        }
    });
});
