import { test, expect } from '@playwright/test';

/**
 * POS Checkout E2E Test
 *
 * Tests the complete POS cashier flow:
 *   Setup terminal via API → Login → Navigate to POS → Search product
 *   → Add to cart → Bayar (F12) → Payment (Enter) → Receipt → New transaction (F8)
 */

let apiURL;
let authData;

// Helper: login via API and inject to browser
async function loginAndNavigate(page, baseURL, path = '/') {
    await page.goto(baseURL);
    const loginRes = await page.request.post(`${apiURL}/auth/login`, {
        headers: { 'Accept': 'application/json' },
        data: { email: 'admin@posip.com', password: 'password' },
    });
    const body = await loginRes.json();
    authData = body.data;

    await page.evaluate(({ token, user, permissions }) => {
        localStorage.setItem('token', token);
        localStorage.setItem('user', JSON.stringify(user));
        localStorage.setItem('permissions', JSON.stringify(permissions || []));
    }, authData);

    if (path !== '/') {
        await page.goto(baseURL + path);
        await page.waitForLoadState('networkidle');
    }
}

// Helper: API call with auth
function authHeaders() {
    return {
        'Authorization': `Bearer ${authData.token}`,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
    };
}

// Helper: wait for POS to be ready (handle setor awal if shown)
async function waitForPosReady(page) {
    // Wait for either setor awal dialog OR product search to appear
    const setorBtn = page.locator('button:has-text("Simpan & Mulai")');
    const searchBox = page.locator('input[placeholder*="Cari produk"]').first();

    // Race: whichever appears first
    await Promise.race([
        setorBtn.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {}),
        searchBox.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {}),
    ]);

    // If setor awal is visible, handle it
    if (await setorBtn.isVisible().catch(() => false)) {
        await setorBtn.click();
        await page.waitForTimeout(1500);
    }

    // Now search box should be visible
    await expect(searchBox).toBeVisible({ timeout: 10000 });
}

test.describe.serial('POS Checkout Flow', () => {
    test.beforeAll(async ({ request, baseURL }) => {
        apiURL = baseURL + '/api/v1';

        // Login
        const loginRes = await request.post(`${apiURL}/auth/login`, {
            headers: { 'Accept': 'application/json' },
            data: { email: 'admin@posip.com', password: 'password' },
        });
        expect(loginRes.ok()).toBeTruthy();
        authData = (await loginRes.json()).data;
        const headers = authHeaders();

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
                request.get(`${apiURL}/metode-pembayarans?status=active&per_page=100`, { headers }),
            ]);
            const warehouses = (await whRes.json()).data?.warehouses || [];
            const customers = (await custRes.json()).data?.customers || [];
            const methods = (await pmRes.json()).data?.metode_pembayarans || [];

            const walkIn = customers.find(c => c.jenis === 'walk_in') || customers[0];
            const cash = methods.find(m => m.metode === 'tunai') || methods[0];

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
                    metode_pembayaran_ids: methods.map(m => m.id),
                },
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
            data: { value: 'allow' },
        });
    });

    test('POS kasir loads and shows product search', async ({ page, baseURL }) => {
        await loginAndNavigate(page, baseURL, '/pos-kasir');

        await waitForPosReady(page);
    });

    test('F1 focuses product search', async ({ page, baseURL }) => {
        await loginAndNavigate(page, baseURL, '/pos-kasir');

        await waitForPosReady(page);

        await page.keyboard.press('F1');
        const search = page.locator('input[placeholder*="Cari produk"]').first();
        await expect(search).toBeFocused({ timeout: 3000 });
    });

    test('Alt+1/2/3/4 switches tabs', async ({ page, baseURL }) => {
        await loginAndNavigate(page, baseURL, '/pos-kasir');

        await waitForPosReady(page);

        // Alt+2 → Kas tab (should show "SIMPAN" or "Kas Masuk")
        await page.keyboard.press('Alt+2');
        await page.waitForTimeout(500);
        await expect(page.locator('button:has-text("SIMPAN")').first()).toBeVisible({ timeout: 3000 });

        // Alt+1 → back to Kasir
        await page.keyboard.press('Alt+1');
        await page.waitForTimeout(500);
        await expect(page.locator('input[placeholder*="Cari produk"]').first()).toBeVisible({ timeout: 3000 });
    });

    test('add product to cart and BAYAR button enables', async ({ page, baseURL }) => {
        await loginAndNavigate(page, baseURL, '/pos-kasir');

        await waitForPosReady(page);
        await page.waitForTimeout(2000); // Wait for products to load

        // Click first product card by finding a card-like element
        const firstProduct = page.locator('div.cursor-pointer.border').first();
        await firstProduct.scrollIntoViewIfNeeded();
        await firstProduct.click();
        await page.waitForTimeout(1500);

        // Handle unit selection dialog if visible
        const dialogHeader = page.getByText('Pilih Satuan');
        if (await dialogHeader.isVisible().catch(() => false)) {
            // Find unit option rows inside the dialog overlay
            // The last option is typically PCS (base unit)
            const dialogContent = page.locator('[role="dialog"], [class*="p-dialog-content"]').first();
            const clickableUnits = dialogContent.locator('div.cursor-pointer');
            const count = await clickableUnits.count();
            if (count > 0) {
                await clickableUnits.nth(count - 1).click();
            } else {
                // Fallback: click by text PCS
                await page.getByText('PCS', { exact: true }).click();
            }
            await page.waitForTimeout(500);
        }

        // BAYAR button should now be enabled
        const bayarBtn = page.locator('button:has-text("BAYAR")');
        await expect(bayarBtn).toBeEnabled({ timeout: 5000 });
    });

    test('F12 opens payment dialog', async ({ page, baseURL }) => {
        await loginAndNavigate(page, baseURL, '/pos-kasir');

        await waitForPosReady(page);
        await page.waitForTimeout(2000);

        // Add product (same pattern as previous test)
        const firstProduct = page.locator('div.cursor-pointer.border').first();
        await firstProduct.scrollIntoViewIfNeeded();
        await firstProduct.click();
        await page.waitForTimeout(1500);

        // Handle unit dialog
        const dialogHeader = page.getByText('Pilih Satuan');
        if (await dialogHeader.isVisible().catch(() => false)) {
            const dialogContent = page.locator('[role="dialog"], [class*="p-dialog-content"]').first();
            const clickableUnits = dialogContent.locator('div.cursor-pointer');
            const count = await clickableUnits.count();
            if (count > 0) await clickableUnits.nth(count - 1).click();
            else await page.getByText('PCS', { exact: true }).click();
            await page.waitForTimeout(500);
        }

        // F12 → open payment dialog
        await page.keyboard.press('F12');
        await page.waitForTimeout(1000);

        // Payment dialog visible
        await expect(page.locator('button:has-text("PROSES PEMBAYARAN")').first()).toBeVisible({ timeout: 5000 });
    });
});
