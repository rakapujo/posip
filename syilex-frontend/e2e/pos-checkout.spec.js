import { test, expect } from '@playwright/test';
import { getAuthData, injectAuth, authHeaders, laravelApiBase } from './helpers/auth.js';

/**
 * POS Checkout E2E Test
 *
 * Tests the complete POS cashier flow:
 *   Setup terminal via API → Login → Navigate to POS → Search product
 *   → Add to cart → Bayar (F12) → Payment (Enter) → Receipt → New transaction (F8)
 */

let apiURL;
let authData;

async function injectAuthAndNavigate(page, baseURL, path = '/') {
    expect(authData?.token, 'authData must be set in beforeAll').toBeTruthy();
    await injectAuth(page, authData);
    if (path !== '/') {
        await page.goto(baseURL + path);
        await page.waitForLoadState('networkidle');
    }
}

async function fetchPosSalesTotal(request) {
    const res = await request.get(`${apiURL}/sales-report?per_page=1&source=pos`, {
        headers: authHeaders(authData)
    });
    expect(res.ok(), `sales-report ${res.status()}`).toBeTruthy();
    const body = await res.json();
    return body?.data?.pagination?.total ?? 0;
}

// Helper: wait for POS to be ready (handle setor awal if shown)
async function waitForPosReady(page) {
    // Wait for either setor awal dialog OR product search to appear
    const setorBtn = page.locator('button:has-text("Simpan & Mulai")');
    const searchBox = page.locator('input[placeholder*="Cari produk"]').first();

    // Race: whichever appears first
    await Promise.race([setorBtn.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {}), searchBox.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {})]);

    // If setor awal is visible, handle it
    if (await setorBtn.isVisible().catch(() => false)) {
        await setorBtn.click();
        await page.waitForTimeout(1500);
    }

    // Now search box should be visible
    await expect(searchBox).toBeVisible({ timeout: 10000 });
}

test.describe.serial('POS Checkout Flow', () => {
    test.beforeAll(async ({ request }) => {
        apiURL = laravelApiBase();
        authData = await getAuthData(request);
        const headers = authHeaders(authData);

        // Check if terminal exists
        const termListRes = await request.get(`${apiURL}/pos-terminals?per_page=100`, { headers });
        const termBody = await termListRes.json();
        const terminals = termBody?.data?.terminals || [];

        if (terminals.length === 0) {
            // No terminal — create one
            // Get required data
            const [whRes, custRes, pmRes] = await Promise.all([
                request.get(`${apiURL}/warehouses?status=active&per_page=1`, { headers }),
                request.get(`${apiURL}/customers?per_page=100`, { headers }),
                request.get(`${apiURL}/metode-pembayarans?status=active&per_page=100`, { headers })
            ]);
            const warehouses = (await whRes.json()).data?.warehouses || [];
            const customers = (await custRes.json()).data?.customers || [];
            const methods = (await pmRes.json()).data?.metode_pembayarans || [];

            const walkIn = customers.find((c) => c.jenis === 'walk_in') || customers[0];
            const cash = methods.find((m) => m.metode === 'tunai') || methods[0];

            // Create terminal
            await request.post(`${apiURL}/pos-terminals`, {
                headers,
                data: {
                    kode_terminal: 'E2E_001',
                    nama_terminal: 'Terminal E2E Test',
                    warehouse_id: warehouses[0]?.id,
                    default_customer_id: walkIn?.id,
                    default_metode_pembayaran_id: cash?.id,
                    auto_open_tray: false,
                    izinkan_retur: true,
                    durasi_retur: 24,
                    status: 'active',
                    user_ids: [authData.user.id],
                    metode_pembayaran_ids: methods.map((m) => m.id)
                }
            });
        }

        // Get terminal ULID (fresh fetch)
        const freshList = await request.get(`${apiURL}/pos-terminals?per_page=1`, { headers });
        const terminalUlid = (await freshList.json()).data?.terminals?.[0]?.ulid;
        expect(terminalUlid).toBeTruthy();

        // Start shift (tolerate any error — shift might already be active)
        await request.post(`${apiURL}/pos-terminals/${terminalUlid}/start-shift`, { headers });

        // Allow negative stock so E2E can checkout without needing to create PO first
        await request.put(`${apiURL}/settings/stock/negative_mode`, {
            headers,
            data: { value: 'allow' }
        });
    });

    test('POS kasir loads and shows product search', async ({ page, baseURL }) => {
        await injectAuthAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);
    });

    test('F1 focuses product search', async ({ page, baseURL }) => {
        await injectAuthAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);

        await page.keyboard.press('F1');
        const search = page.locator('input[placeholder*="Cari produk"]').first();
        await expect(search).toBeFocused({ timeout: 3000 });
    });

    test('Alt+1/2/3/4 switches tabs', async ({ page, baseURL }) => {
        await injectAuthAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);

        await page.keyboard.press('Alt+2');
        await page.waitForTimeout(500);
        await expect(page.locator('button:has-text("SIMPAN")').first()).toBeVisible({ timeout: 3000 });

        await page.keyboard.press('Alt+1');
        await page.waitForTimeout(500);
        await expect(page.locator('input[placeholder*="Cari produk"]').first()).toBeVisible({ timeout: 3000 });
    });

    test('add product to cart and BAYAR button enables', async ({ page, baseURL }) => {
        await injectAuthAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);
        await page.waitForTimeout(2000);

        const firstProduct = page.locator('div.cursor-pointer.border').first();
        await firstProduct.scrollIntoViewIfNeeded();
        await firstProduct.click();
        await page.waitForTimeout(1500);

        const dialogHeader = page.getByText('Pilih Satuan');
        if (await dialogHeader.isVisible().catch(() => false)) {
            const dialogContent = page.locator('[role="dialog"], [class*="p-dialog-content"]').first();
            const clickableUnits = dialogContent.locator('div.cursor-pointer');
            const count = await clickableUnits.count();
            if (count > 0) {
                await clickableUnits.nth(count - 1).click();
            } else {
                await page.getByText('PCS', { exact: true }).click();
            }
            await page.waitForTimeout(500);
        }

        const bayarBtn = page.locator('button:has-text("BAYAR")');
        await expect(bayarBtn).toBeEnabled({ timeout: 5000 });
    });

    test('F12 opens payment dialog', async ({ page, baseURL }) => {
        await injectAuthAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);
        await page.waitForTimeout(2000);

        const firstProduct = page.locator('div.cursor-pointer.border').first();
        await firstProduct.scrollIntoViewIfNeeded();
        await firstProduct.click();
        await page.waitForTimeout(1500);

        const dialogHeader = page.getByText('Pilih Satuan');
        if (await dialogHeader.isVisible().catch(() => false)) {
            const dialogContent = page.locator('[role="dialog"], [class*="p-dialog-content"]').first();
            const clickableUnits = dialogContent.locator('div.cursor-pointer');
            const count = await clickableUnits.count();
            if (count > 0) await clickableUnits.nth(count - 1).click();
            else await page.getByText('PCS', { exact: true }).click();
            await page.waitForTimeout(500);
        }

        await page.keyboard.press('F12');
        await page.waitForTimeout(1000);
        await expect(page.locator('button:has-text("PROSES PEMBAYARAN")').first()).toBeVisible({ timeout: 5000 });
    });

    test('complete checkout flow with cash payment persists sales', async ({ page, baseURL, request }) => {
        const salesCountBefore = await fetchPosSalesTotal(request);

        await injectAuthAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);
        await page.waitForTimeout(2000);

        const firstProduct = page.locator('div.cursor-pointer.border').first();
        await firstProduct.scrollIntoViewIfNeeded();
        await firstProduct.click();
        await page.waitForTimeout(1500);

        const dialogHeader = page.getByText('Pilih Satuan');
        if (await dialogHeader.isVisible().catch(() => false)) {
            const dialogContent = page.locator('[role="dialog"], [class*="p-dialog-content"]').first();
            const clickableUnits = dialogContent.locator('div.cursor-pointer');
            const count = await clickableUnits.count();
            if (count > 0) await clickableUnits.nth(count - 1).click();
            else await page.getByText('PCS', { exact: true }).click();
            await page.waitForTimeout(500);
        }

        await page.keyboard.press('F12');
        await page.waitForTimeout(1000);

        const prosesBtn = page.locator('button:has-text("PROSES PEMBAYARAN")').first();
        await expect(prosesBtn).toBeEnabled({ timeout: 5000 });
        await prosesBtn.click();
        await page.waitForTimeout(3000);

        const salesCountAfter = await fetchPosSalesTotal(request);
        expect(salesCountAfter).toBeGreaterThan(salesCountBefore);
    });

    test('post-checkout modal or success indicator appears', async ({ page, baseURL }) => {
        await injectAuthAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);
        await page.waitForTimeout(2000);

        const firstProduct = page.locator('div.cursor-pointer.border').first();
        await firstProduct.scrollIntoViewIfNeeded();
        await firstProduct.click();
        await page.waitForTimeout(1500);

        const dialogHeader = page.getByText('Pilih Satuan');
        if (await dialogHeader.isVisible().catch(() => false)) {
            const dialogContent = page.locator('[role="dialog"], [class*="p-dialog-content"]').first();
            const clickableUnits = dialogContent.locator('div.cursor-pointer');
            const count = await clickableUnits.count();
            if (count > 0) await clickableUnits.nth(count - 1).click();
            else await page.getByText('PCS', { exact: true }).click();
            await page.waitForTimeout(500);
        }

        await page.keyboard.press('F12');
        await page.waitForTimeout(1000);
        await page.locator('button:has-text("PROSES PEMBAYARAN")').first().click();
        await page.waitForTimeout(2500);

        const successIndicators = [
            page.getByText(/berhasil|sukses|selesai|receipt|nota/i).first(),
            page.locator('[class*="toast-success"]').first(),
            page.getByText(/transaksi baru|nota baru/i).first()
        ];

        let found = false;
        for (const loc of successIndicators) {
            if (await loc.isVisible().catch(() => false)) {
                found = true;
                break;
            }
        }
        if (!found) {
            const bayarBtn = page.locator('button:has-text("BAYAR")');
            await expect(bayarBtn).toBeDisabled({ timeout: 3000 });
            found = true;
        }
        expect(found).toBe(true);
    });

    test('user without pos.access permission cannot access POS kasir', async ({ page, baseURL }) => {
        // Mock /me supaya fetchUser tidak restore Super Admin dari token admin
        await page.route('**/api/v1/auth/me', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    success: true,
                    data: {
                        user: {
                            ulid: 'e2e-limited',
                            name: 'Limited User',
                            email: 'limited@test.com',
                            roles: [],
                            permissions: []
                        },
                        permissions: []
                    }
                })
            });
        });

        await page.addInitScript(() => {
            localStorage.setItem('token', 'e2e-limited-token');
            localStorage.setItem(
                'user',
                JSON.stringify({
                    ulid: 'e2e-limited',
                    name: 'Limited User',
                    email: 'limited@test.com',
                    roles: [],
                    permissions: []
                })
            );
            localStorage.setItem('permissions', '[]');
        });

        await page.goto(`${baseURL}/pos-kasir`);
        await page.waitForLoadState('networkidle');

        // Guard → accessDenied, bukan layar kasir
        await expect(page).not.toHaveURL(/\/pos-kasir$/);
        const denied =
            (await page.getByText(/akses ditolak|access denied|tidak memiliki/i).first().isVisible().catch(() => false)) ||
            page.url().includes('access') ||
            page.url().includes('/app');
        expect(denied).toBe(true);
    });
});
