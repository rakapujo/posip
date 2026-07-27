import { test, expect } from '@playwright/test';
import { getAuthData, injectAuth } from './helpers/auth.js';
import { ALL_MENU_ROUTES, waitForDataTable } from './docs-helpers.js';

/**
 * Parametrized shell smoke for every operational list menu.
 * Asserts route mounts without access-denied and shows a table or page content.
 * Does NOT replace Feature PHPUnit for business depth.
 *
 * Independent tests (workers:1 in playwright.config) — one flaky route must not skip the rest.
 */

let authData;

test.describe('Menu shell smoke @smoke', () => {
    test.setTimeout(60000);

    test.beforeAll(async ({ request }) => {
        authData = await getAuthData(request);
    });

    test.beforeEach(async ({ page }) => {
        await injectAuth(page, authData);
    });

    for (const [name, route, isTable] of ALL_MENU_ROUTES) {
        test(`${name} loads ${route}`, async ({ page, baseURL }) => {
            await page.goto(`${baseURL}${route}`);
            await page.waitForLoadState('domcontentloaded');
            await page.waitForLoadState('networkidle').catch(() => {});

            const denied = await page
                .locator('text=Akses Ditolak')
                .isVisible({ timeout: 2000 })
                .catch(() => false);
            expect(denied, `${route} must not show Akses Ditolak for admin`).toBeFalsy();

            if (isTable) {
                await waitForDataTable(page, 20000).catch(() => {});
                const table = page.locator('.p-datatable');
                const empty = page.getByText(/tidak ada data|belum ada|no data|pilih produk|silakan/i).first();
                const shell = page.locator('.layout-main, .layout-content, .card, main').first();
                const hasTable = await table.isVisible().catch(() => false);
                const hasEmpty = await empty.isVisible().catch(() => false);
                const hasShell = await shell.isVisible().catch(() => false);
                expect(
                    hasTable || hasEmpty || hasShell,
                    `${route} should show datatable, empty/filter hint, or page shell`
                ).toBeTruthy();
            } else {
                const body = page.locator('#app, .layout-main, .layout-content, main').first();
                await expect(body).toBeVisible({ timeout: 15000 });
                const text = await page.locator('body').innerText();
                expect(text.length, `${route} should render content`).toBeGreaterThan(40);
            }
        });
    }

    test('print barcode page opens shell', async ({ page, baseURL }) => {
        await page.goto(`${baseURL}/app/master/print-barcode`);
        await page.waitForLoadState('networkidle').catch(() => {});
        await expect(page.getByText(/barcode|cetak|produk/i).first()).toBeVisible({ timeout: 15000 });
    });
});
