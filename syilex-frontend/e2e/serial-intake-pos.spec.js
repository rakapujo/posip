import { test, expect } from '@playwright/test';
import { getAuthData, injectAuth, authHeaders, laravelApiBase } from './helpers/auth.js';
import { ensurePosShift, waitForPosReady } from './helpers/pos.js';

/**
 * Serial intake → POS sell journey.
 * API: create+approve intake on the POS terminal warehouse → scan di kasir → checkout.
 */

let apiURL;
let authData;
let serialNumber;
let kodeInternal;
let warehouseId;

test.describe.serial('Serial intake → POS', () => {
    test.setTimeout(90000);

    test.beforeAll(async ({ request }) => {
        apiURL = laravelApiBase();
        authData = await getAuthData(request);
        const headers = authHeaders(authData);

        await request
            .put(`${apiURL}/settings/modules/elektronik_enabled`, { headers, data: { value: true } })
            .catch(() => {});

        const { warehouseId: termWhId } = await ensurePosShift(request, authData, { kode: 'E2E_KASIR' });
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
            ? ((await salesBeforeRes.json())?.data?.pagination?.total ?? 0)
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

        const bayarBtn = page.getByRole('button', { name: 'BAYAR (F12)' });
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
