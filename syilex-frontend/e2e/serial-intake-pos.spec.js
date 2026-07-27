import { test, expect } from '@playwright/test';
import { getAuthData, injectAuth, authHeaders, laravelApiBase } from './helpers/auth.js';

/**
 * Serial intake → POS sell journey.
 * API: create+approve intake on the POS terminal warehouse → scan di kasir → checkout.
 */

let apiURL;
let authData;
let serialNumber;
let kodeInternal;
let warehouseId;

async function waitForPosReady(page) {
    const setorBtn = page.locator('button:has-text("Simpan & Mulai")');
    const searchBox = page.locator('input[placeholder*="Cari produk"]').first();
    await Promise.race([
        setorBtn.waitFor({ state: 'visible', timeout: 20000 }).catch(() => {}),
        searchBox.waitFor({ state: 'visible', timeout: 20000 }).catch(() => {})
    ]);
    if (await setorBtn.isVisible().catch(() => false)) {
        await setorBtn.click();
        await page.waitForTimeout(1500);
    }
    await expect(searchBox).toBeVisible({ timeout: 15000 });
}

/** Mirror pos-checkout: reuse/create any terminal, return { ulid, warehouseId }. */
async function ensurePosTerminal(request, headers) {
    const termListRes = await request.get(`${apiURL}/pos-terminals?per_page=100`, { headers });
    let terminals = (await termListRes.json()).data?.terminals || [];

    if (terminals.length === 0) {
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
        const createRes = await request.post(`${apiURL}/pos-terminals`, {
            headers,
            data: {
                kode_terminal: 'E2E_SER',
                nama_terminal: 'Terminal E2E Serial',
                warehouse_id: warehouses[0]?.id,
                default_customer_id: walkIn?.id,
                default_metode_pembayaran_id: cash?.id,
                auto_open_tray: false,
                izinkan_retur: true,
                durasi_retur: 24,
                status: 'active',
                user_ids: [authData.user.id],
                metode_pembayaran_ids: methods.map((m) => m.id).filter(Boolean)
            }
        });
        expect(createRes.ok(), JSON.stringify(await createRes.json())).toBeTruthy();
        const fresh = await request.get(`${apiURL}/pos-terminals?per_page=100`, { headers });
        terminals = (await fresh.json()).data?.terminals || [];
    }

    const terminal = terminals[0];
    expect(terminal?.ulid).toBeTruthy();
    const whId = terminal.warehouse_id || terminal.warehouse?.id;
    expect(whId, 'terminal warehouse_id').toBeTruthy();
    await request.post(`${apiURL}/pos-terminals/${terminal.ulid}/start-shift`, { headers });
    return { ulid: terminal.ulid, warehouseId: whId };
}

test.describe.serial('Serial intake → POS', () => {
    test.setTimeout(90000);

    test.beforeAll(async ({ request }) => {
        apiURL = laravelApiBase();
        authData = await getAuthData(request);
        const headers = authHeaders(authData);

        await request
            .put(`${apiURL}/settings/modules/elektronik_enabled`, { headers, data: { value: true } })
            .catch(() => {});
        await request.put(`${apiURL}/settings/stock/negative_mode`, {
            headers,
            data: { value: 'allow' }
        });

        const { warehouseId: termWhId } = await ensurePosTerminal(request, headers);
        warehouseId = termWhId;

        const [prodRes, whRes, supRes] = await Promise.all([
            request.get(`${apiURL}/produks?per_page=100&is_serial=1`, { headers }),
            request.get(`${apiURL}/warehouses/list`, { headers }),
            request.get(`${apiURL}/suppliers/list`, { headers })
        ]);

        let products = (await prodRes.json()).data?.produks || (await prodRes.json()).data?.items || [];
        if (!prodRes.ok() || products.length === 0) {
            const all = await request.get(`${apiURL}/produks/list`, { headers });
            const allItems = (await all.json()).data?.produks || (await all.json()).data?.items || [];
            products = allItems.filter((p) => p.is_serial);
        }

        let product = products.find((p) => p.ulid);
        if (!product?.ulid) {
            const createProd = await request.post(`${apiURL}/produks`, {
                headers,
                data: {
                    kode_produk: `E2ESN${Date.now().toString().slice(-6)}`,
                    nama_produk: 'E2E Serial Phone',
                    is_serial: true,
                    status: 'active',
                    unit_1: 'UNIT',
                    konversi_1: 1,
                    harga_1: 0,
                    unit_2: 'UNIT',
                    konversi_2: 1,
                    harga_2: 0,
                    unit_3: 'UNIT',
                    konversi_3: 1,
                    harga_3: 0,
                    unit_4: 'UNIT',
                    konversi_4: 1,
                    harga_4: 0,
                    minimum_stok: 0
                }
            });
            const body = await createProd.json();
            expect(createProd.ok(), JSON.stringify(body)).toBeTruthy();
            product = body.data?.produk || body.data?.product;
        }

        const warehouses = (await whRes.json()).data?.warehouses || [];
        const suppliers = (await supRes.json()).data?.suppliers || [];
        const warehouse =
            warehouses.find((w) => Number(w.id) === Number(warehouseId) && w.ulid) ||
            warehouses.find((w) => w.ulid);
        const supplier = suppliers.find((s) => s.ulid) || suppliers[0];

        expect(product?.ulid, 'need serial product ulid').toBeTruthy();
        expect(warehouse?.ulid, 'need warehouse ulid matching POS terminal').toBeTruthy();
        expect(supplier?.ulid, 'need supplier ulid').toBeTruthy();
        // Keep terminal warehouse as source of truth
        warehouseId = warehouse.id;

        serialNumber = `E2E-SN-${Date.now()}`;

        const intakeRes = await request.post(`${apiURL}/serial-intakes`, {
            headers,
            data: {
                product_id: product.ulid,
                warehouse_id: warehouse.ulid,
                supplier_id: supplier.ulid,
                units: [
                    {
                        serial_number: serialNumber,
                        harga_modal: 1000000,
                        harga_jual: 1500000,
                        grade: 'A',
                        battery_condition: 'Original',
                        battery_health: 95,
                        battery_cycle_count: 10,
                        account_status: 'unlocked',
                        catatan: 'e2e intake'
                    }
                ]
            }
        });
        const intakeBody = await intakeRes.json();
        expect(intakeRes.ok(), JSON.stringify(intakeBody)).toBeTruthy();
        const intakeUlid = intakeBody.data?.serial_intake?.ulid;
        expect(intakeUlid).toBeTruthy();

        const approveRes = await request.post(`${apiURL}/serial-intakes/${intakeUlid}/approve`, { headers });
        const approveBody = await approveRes.json();
        expect(approveRes.ok(), JSON.stringify(approveBody)).toBeTruthy();

        const showRes = await request.get(`${apiURL}/serial-intakes/${intakeUlid}`, { headers });
        const showBody = await showRes.json();
        const unit =
            showBody.data?.serial_intake?.units?.find((u) => u.serial_number === serialNumber) ||
            showBody.data?.serial_intake?.units?.[0];
        kodeInternal = unit?.kode_internal || null;

        if (!kodeInternal) {
            const lookupRes = await request.get(`${apiURL}/serial-units/lookup`, {
                headers,
                params: { code: serialNumber, warehouse_id: warehouseId }
            });
            if (lookupRes.ok()) {
                kodeInternal = (await lookupRes.json()).data?.unit?.kode_internal || null;
            }
        }
    });

    test('approved serial unit can be scanned and checked out on POS', async ({ page, baseURL, request }) => {
        expect(serialNumber).toBeTruthy();
        const headers = authHeaders(authData);

        const salesBeforeRes = await request.get(`${apiURL}/sales-report?per_page=1&source=pos`, { headers });
        const salesBefore = salesBeforeRes.ok()
            ? (await salesBeforeRes.json())?.data?.pagination?.total ?? 0
            : 0;

        const scanCode = kodeInternal || serialNumber;
        const preLookup = await request.get(`${apiURL}/serial-units/lookup`, {
            headers,
            params: { code: scanCode, warehouse_id: warehouseId }
        });
        expect(preLookup.ok(), `lookup ${scanCode}: ${preLookup.status()}`).toBeTruthy();

        await injectAuth(page, authData);
        await page.goto(`${baseURL}/pos-kasir`);
        await waitForPosReady(page);
        await page.waitForTimeout(1500);

        const search = page.locator('input[placeholder*="Cari produk"]').first();
        await search.fill(scanCode);
        await search.press('Enter');
        await page.waitForTimeout(2000);

        let addBtn = page.getByRole('button', { name: /Tambah ke Keranjang/i });
        if (!(await addBtn.isVisible().catch(() => false)) && kodeInternal && scanCode !== serialNumber) {
            await search.fill(serialNumber);
            await search.press('Enter');
            await page.waitForTimeout(2000);
            addBtn = page.getByRole('button', { name: /Tambah ke Keranjang/i });
        }
        if (!(await addBtn.isVisible().catch(() => false))) {
            const snRow = page.getByText(serialNumber).first();
            if (await snRow.isVisible().catch(() => false)) {
                await snRow.click();
                await page.waitForTimeout(800);
            }
        }

        await expect(addBtn).toBeVisible({ timeout: 10000 });
        await addBtn.click();
        await page.waitForTimeout(800);

        const bayarBtn = page.locator('button:has-text("BAYAR")');
        await expect(bayarBtn).toBeEnabled({ timeout: 5000 });
        await page.keyboard.press('F12');
        await page.waitForTimeout(1000);

        const prosesBtn = page.locator('button:has-text("PROSES PEMBAYARAN")').first();
        await expect(prosesBtn).toBeEnabled({ timeout: 5000 });
        await prosesBtn.click();
        await page.waitForTimeout(3000);

        const salesAfterRes = await request.get(`${apiURL}/sales-report?per_page=1&source=pos`, { headers });
        if (salesAfterRes.ok()) {
            const salesAfter = (await salesAfterRes.json())?.data?.pagination?.total ?? 0;
            expect(salesAfter).toBeGreaterThan(salesBefore);
        } else {
            await expect(page.getByText(/berhasil|sukses|nota/i).first()).toBeVisible({ timeout: 5000 });
        }
    });
});
