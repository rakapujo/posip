import { test, expect } from '@playwright/test';
import { getAuthData, injectAuth, authHeaders, laravelApiBase } from './helpers/auth.js';

/**
 * PO approve journey — API setup + UI list persistence.
 */

let apiURL;
let authData;

test.describe.serial('PO approve journey', () => {
    test.beforeAll(async ({ request }) => {
        apiURL = laravelApiBase();
        authData = await getAuthData(request);
    });

    test('create draft PO via API, approve, then appears as approved in list UI', async ({ page, request, baseURL }) => {
        const headers = authHeaders(authData);

        const [supRes, whRes, prodRes] = await Promise.all([
            request.get(`${apiURL}/suppliers/list`, { headers }),
            request.get(`${apiURL}/warehouses/list`, { headers }),
            request.get(`${apiURL}/produks/list`, { headers })
        ]);
        expect(supRes.ok()).toBeTruthy();
        expect(whRes.ok()).toBeTruthy();
        expect(prodRes.ok()).toBeTruthy();

        const suppliers = (await supRes.json()).data?.suppliers || [];
        const warehouses = (await whRes.json()).data?.warehouses || [];
        const products = (await prodRes.json()).data?.produks || (await prodRes.json()).data?.items || [];

        const supplier = suppliers.find((s) => s.id != null) || suppliers[0];
        const warehouse = warehouses.find((w) => w.id != null) || warehouses[0];
        const product =
            products.find((p) => p.id != null && !p.is_serial && (p.unit_1 || p.unit_4)) ||
            products.find((p) => p.id != null && !p.is_serial) ||
            products[0];

        expect(supplier?.id, 'need supplier').toBeTruthy();
        expect(warehouse?.id, 'need warehouse').toBeTruthy();
        expect(product?.id, 'need non-serial product').toBeTruthy();

        const unit = product.unit_4 || product.unit_1 || 'PCS';
        const konversi = Number(product.konversi_4 || product.konversi_1 || 1);
        const harga = 1000;

        const createRes = await request.post(`${apiURL}/purchase-orders`, {
            headers,
            data: {
                tanggal_po: new Date().toISOString().slice(0, 19).replace('T', ' '),
                supplier_id: supplier.id,
                warehouse_id: warehouse.id,
                tempo_hari: 0,
                cash_payment: true,
                cash_metode: 'cash',
                diskon_1_tipe: 'none',
                diskon_1_nilai: 0,
                diskon_2_tipe: 'none',
                diskon_2_nilai: 0,
                diskon_3_tipe: 'none',
                diskon_3_nilai: 0,
                biaya_kirim_tipe: 'none',
                biaya_kirim_nilai: 0,
                biaya_lain_tipe: 'none',
                biaya_lain_nilai: 0,
                details: [
                    {
                        product_id: product.id,
                        unit_used: unit,
                        unit_konversi: konversi,
                        qty_in_unit: 2,
                        harga_per_unit: harga,
                        diskon_1_tipe: 'none',
                        diskon_1_nilai: 0,
                        diskon_2_tipe: 'none',
                        diskon_2_nilai: 0,
                        diskon_3_tipe: 'none',
                        diskon_3_nilai: 0,
                        diskon_4_tipe: 'none',
                        diskon_4_nilai: 0,
                        diskon_5_tipe: 'none',
                        diskon_5_nilai: 0
                    }
                ]
            }
        });
        const created = await createRes.json();
        expect(createRes.ok(), JSON.stringify(created)).toBeTruthy();
        const ulid = created.data?.purchase_order?.ulid || created.data?.po?.ulid;
        expect(ulid).toBeTruthy();
        const nomor = created.data?.purchase_order?.nomor_dokumen || created.data?.po?.nomor_dokumen;

        const approveRes = await request.post(`${apiURL}/purchase-orders/${ulid}/approve`, { headers });
        const approved = await approveRes.json();
        expect(approveRes.ok(), JSON.stringify(approved)).toBeTruthy();

        await injectAuth(page, authData);
        await page.goto(`${baseURL}/app/pembelian/po`);
        await page.waitForLoadState('networkidle').catch(() => {});
        await expect(page.locator('.p-datatable')).toBeVisible({ timeout: 20000 });

        if (nomor) {
            const search = page.getByPlaceholder(/cari/i).first();
            if (await search.isVisible().catch(() => false)) {
                await search.fill(nomor);
                await page.waitForTimeout(800);
            }
            await expect(page.getByText(nomor).first()).toBeVisible({ timeout: 15000 });
        }

        await expect(page.getByText(/approved|disetujui/i).first()).toBeVisible({ timeout: 15000 });
    });
});
