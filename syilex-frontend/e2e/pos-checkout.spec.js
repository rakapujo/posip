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
        headers: { Accept: 'application/json' },
        data: { email: 'admin@posip.com', password: 'password' }
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
        Authorization: `Bearer ${authData.token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json'
    };
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
    test.beforeAll(async ({ request, baseURL }) => {
        apiURL = baseURL + '/api/v1';

        // Login
        const loginRes = await request.post(`${apiURL}/auth/login`, {
            headers: { Accept: 'application/json' },
            data: { email: 'admin@posip.com', password: 'password' }
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

    // -----------------------------------------------------------------
    // Complete checkout flow — cash payment end-to-end
    // -----------------------------------------------------------------
    test('complete checkout flow with cash payment persists sales', async ({ page, baseURL, request }) => {
        // Capture count sebelum checkout
        const salesCountBefore = await request
            .get(`${apiURL}/pos/sales?per_page=1`, {
                headers: { Authorization: `Bearer ${authData.token}`, Accept: 'application/json' }
            })
            .then((r) => r.json())
            .then((d) => d?.data?.pagination?.total ?? 0);

        await loginAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);
        await page.waitForTimeout(2000);

        // Add 1 product
        const firstProduct = page.locator('div.cursor-pointer.border').first();
        await firstProduct.scrollIntoViewIfNeeded();
        await firstProduct.click();
        await page.waitForTimeout(1500);

        // Unit dialog (pick base unit)
        const dialogHeader = page.getByText('Pilih Satuan');
        if (await dialogHeader.isVisible().catch(() => false)) {
            const dialogContent = page.locator('[role="dialog"], [class*="p-dialog-content"]').first();
            const clickableUnits = dialogContent.locator('div.cursor-pointer');
            const count = await clickableUnits.count();
            if (count > 0) await clickableUnits.nth(count - 1).click();
            else await page.getByText('PCS', { exact: true }).click();
            await page.waitForTimeout(500);
        }

        // Open payment dialog
        await page.keyboard.press('F12');
        await page.waitForTimeout(1000);

        // Proses pembayaran (dengan default TUNAI + auto-fill amount)
        const prosesBtn = page.locator('button:has-text("PROSES PEMBAYARAN")').first();
        await expect(prosesBtn).toBeEnabled({ timeout: 5000 });
        await prosesBtn.click();

        // Tunggu post-checkout modal atau navigation (success state)
        await page.waitForTimeout(3000);

        // Verify sales tercipta di DB via API
        const salesCountAfter = await request
            .get(`${apiURL}/pos/sales?per_page=1`, {
                headers: { Authorization: `Bearer ${authData.token}`, Accept: 'application/json' }
            })
            .then((r) => r.json())
            .then((d) => d?.data?.pagination?.total ?? 0);

        expect(salesCountAfter).toBeGreaterThan(salesCountBefore);
    });

    // -----------------------------------------------------------------
    // Post-checkout receipt/success state visible
    // -----------------------------------------------------------------
    test('post-checkout modal or success indicator appears', async ({ page, baseURL }) => {
        await loginAndNavigate(page, baseURL, '/pos-kasir');
        await waitForPosReady(page);
        await page.waitForTimeout(2000);

        // Add + checkout (sama flow)
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

        // Expect salah satu indikator success: toast sukses, modal receipt, atau cart reset ke 0
        // Polling flexible karena UI flow bisa berubah
        await page.waitForTimeout(2500);

        const successIndicators = [page.getByText(/berhasil|sukses|selesai|receipt|nota/i).first(), page.locator('[class*="toast-success"]').first(), page.getByText(/transaksi baru|nota baru/i).first()];

        let found = false;
        for (const loc of successIndicators) {
            if (await loc.isVisible().catch(() => false)) {
                found = true;
                break;
            }
        }
        // Flexible — kalau tidak ada explicit success indicator, minimal cart harus kembali empty / bayar disabled
        if (!found) {
            const bayarBtn = page.locator('button:has-text("BAYAR")');
            await expect(bayarBtn).toBeDisabled({ timeout: 3000 });
            found = true;
        }
        expect(found).toBe(true);
    });

    // -----------------------------------------------------------------
    // Role-based: user tanpa permission pos.access → tidak boleh masuk POS
    // -----------------------------------------------------------------
    test('user without pos.access permission cannot access POS kasir', async ({ page, baseURL }) => {
        // Pakai admin login dulu, tapi pretend tidak punya permission (manipulasi localStorage)
        await page.goto(`${baseURL}/`);

        const token = authData.token; // existing admin token
        const fakeUser = {
            ulid: 'fake-user-no-perms',
            name: 'Limited User',
            email: 'limited@test.com',
            roles: [],
            permissions: [] // ← NO permissions
        };

        await page.evaluate(
            ({ t, u }) => {
                localStorage.setItem('token', t);
                localStorage.setItem('user', JSON.stringify(u));
                localStorage.setItem('permissions', JSON.stringify([]));
            },
            { t: token, u: fakeUser }
        );

        // Try akses POS
        await page.goto(`${baseURL}/pos-kasir`);
        await page.waitForTimeout(2000);

        // Seharusnya redirect ke access-denied atau dashboard
        const url = page.url();
        const onPosPage = url.includes('/pos-kasir');
        const onDeniedPage = url.includes('/auth/access') || url.includes('/app');

        // Kalau masih di POS, minimal ada error message
        if (onPosPage) {
            // Tidak ada error handling UI yang explicit — biarkan tes ini check redirect
            expect(onDeniedPage).toBe(true);
        } else {
            expect(onDeniedPage).toBe(true);
        }
    });
});
