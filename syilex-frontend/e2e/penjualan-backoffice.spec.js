import { test, expect } from '@playwright/test';
import { getAuthData, injectAuth, authHeaders, laravelApiBase } from './helpers/auth.js';
import { ensureProductStock } from './helpers/pos.js';

/**
 * Backoffice Penjualan E2E
 *
 * Covers routes that had no Playwright coverage yet:
 *   - list smoke for sales / retur / piutang / pembayaran / deposit
 *   - create form shell
 *   - edit draft remaps API fields (unit/qty/harga_satuan) into the form
 */

let apiURL;
let authData;

async function createDraftSale(request, { seedStock = false } = {}) {
    const headers = authHeaders(authData);
    const [custRes, whRes, prodRes] = await Promise.all([
        request.get(`${apiURL}/customers/list?jenis=spesifik`, { headers }),
        request.get(`${apiURL}/warehouses/list?is_saleable=1`, { headers }),
        request.get(`${apiURL}/sales/products`, { headers })
    ]);

    expect(custRes.ok(), `customers/list ${custRes.status()}`).toBeTruthy();
    expect(whRes.ok(), `warehouses/list ${whRes.status()}`).toBeTruthy();

    const customers = (await custRes.json()).data?.customers || [];
    const warehouses = (await whRes.json()).data?.warehouses || [];

    let products = [];
    if (prodRes.ok()) {
        products = (await prodRes.json()).data?.items || [];
    }
    // Prefer non-serial for edit form (qty/harga InputNumber)
    let nonSerial = products.filter((p) => !p.is_serial && p.id != null);
    if (nonSerial.length === 0) {
        const fallback = await request.get(`${apiURL}/produks/list?is_serial=0`, { headers });
        expect(fallback.ok(), `produks/list non-serial ${fallback.status()}`).toBeTruthy();
        nonSerial = ((await fallback.json()).data?.produks || []).filter((p) => !p.is_serial && p.id != null);
    }
    if (nonSerial.length === 0) {
        const createProd = await request.post(`${apiURL}/produks`, {
            headers,
            data: {
                kode_produk: `E2ESL${Date.now().toString().slice(-6)}`,
                nama_produk: 'E2E Sales Produk',
                is_serial: false,
                status: 'active',
                unit_1: 'PCS',
                konversi_1: 1,
                harga_1: 10000,
                unit_2: 'PCS',
                konversi_2: 1,
                harga_2: 10000,
                unit_3: 'PCS',
                konversi_3: 1,
                harga_3: 10000,
                unit_4: 'PCS',
                konversi_4: 1,
                harga_4: 10000,
                minimum_stok: 0
            }
        });
        const createdProd = await createProd.json();
        expect(createProd.ok(), JSON.stringify(createdProd)).toBeTruthy();
        const created = createdProd.data?.produk || createdProd.data?.product;
        // index/show may hide id — resolve via list search
        if (created && !created.id && created.kode_produk) {
            const again = await request.get(
                `${apiURL}/produks/list?is_serial=0&search=${encodeURIComponent(created.kode_produk)}`,
                { headers }
            );
            const found = ((await again.json()).data?.produks || []).find(
                (p) => p.kode_produk === created.kode_produk
            );
            nonSerial = [found || created].filter((p) => p?.id != null);
        } else {
            nonSerial = [created].filter((p) => p?.id != null);
        }
    }
    products = nonSerial;

    let customer = customers.find((c) => c.id != null && c.jenis !== 'walk_in') || customers[0];
    if (!customer?.id) {
        const createCust = await request.post(`${apiURL}/customers`, {
            headers,
            data: {
                kode_customer: `E2E${Date.now().toString().slice(-6)}`,
                nama: 'E2E Customer Penjualan',
                jenis: 'spesifik',
                status: 'active',
                tempo_default: 0
            }
        });
        const created = await createCust.json();
        expect(createCust.ok(), JSON.stringify(created)).toBeTruthy();
        const again = await request.get(`${apiURL}/customers/list?jenis=spesifik`, { headers });
        customer = ((await again.json()).data?.customers || []).find((c) => c.nama === 'E2E Customer Penjualan');
    }

    const warehouse = warehouses.find((w) => w.id != null) || warehouses[0];
    const product =
        products.find((p) => p.id != null && p.unit_1 && Number(p.harga_1 || p.harga_4 || 0) > 0) ||
        products.find((p) => p.id != null) ||
        products[0];

    expect(customer?.id, `customer keys=${customer ? Object.keys(customer) : 'none'} count=${customers.length}`).toBeTruthy();
    expect(warehouse?.id, `warehouse keys=${warehouse ? Object.keys(warehouse) : 'none'} count=${warehouses.length}`).toBeTruthy();
    expect(product?.id, `product keys=${product ? Object.keys(product) : 'none'} count=${products.length}`).toBeTruthy();

    if (seedStock) {
        const stock = await ensureProductStock(request, authData, {
            productId: product.id,
            warehouseId: warehouse.id,
            qty: 100
        });
        if (stock.skipped) {
            return { skipped: true, reason: stock.reason };
        }
    }

    const unit = product.unit_1 || product.units?.[0]?.unit || 'PCS';
    const konversi = product.konversi_1 || product.units?.[0]?.konversi || 1;
    const harga = Number(product.harga_1 || product.harga_4 || product.units?.[0]?.harga_jual || 1000);
    const qty = 3;

    const createRes = await request.post(`${apiURL}/sales`, {
        headers,
        data: {
            tanggal: new Date().toISOString().slice(0, 19).replace('T', ' '),
            customer_id: customer.id,
            warehouse_id: warehouse.id,
            tempo_hari: 0,
            cash_payment: false,
            discounts: [
                { tipe: 'none', nilai: 0 },
                { tipe: 'none', nilai: 0 },
                { tipe: 'none', nilai: 0 }
            ],
            biaya_kirim: { tipe: 'none', nilai: 0 },
            biaya_lain: { tipe: 'none', nilai: 0 },
            details: [
                {
                    product_id: product.id,
                    unit,
                    konversi,
                    qty,
                    harga_satuan: harga,
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

    if (createRes.status() === 403) {
        return { skipped: true, reason: 'sales.create permission missing — run RolePermissionSeeder' };
    }

    const body = await createRes.json();
    expect(createRes.ok(), JSON.stringify(body)).toBeTruthy();
    const sales = body.data?.sales;
    expect(sales?.ulid).toBeTruthy();
    expect(sales?.status).toBe('draft');

    return { sales, unit, qty, harga, product };
}

test.describe('Penjualan Backoffice', () => {
    test.beforeAll(async ({ request }) => {
        apiURL = laravelApiBase();
        authData = await getAuthData(request);
    });

    test.beforeEach(async ({ page }) => {
        await injectAuth(page, authData);
    });

    test('sales list page loads table shell', async ({ page }) => {
        await page.goto('/app/penjualan/sales');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Daftar Penjualan')).toBeVisible({ timeout: 15000 });
        await expect(page.getByPlaceholder('Cari nomor, customer...')).toBeVisible();
        await expect(page.locator('.p-datatable')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Buat Penjualan' })).toBeVisible();
    });

    test('sales create form loads customer and warehouse fields', async ({ page }) => {
        await page.goto('/app/penjualan/sales/create');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Buat Penjualan')).toBeVisible({ timeout: 15000 });
        await expect(page.locator('#customer')).toBeVisible();
        await expect(page.locator('#warehouse')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Tambah Produk' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Simpan' })).toBeDisabled();
    });

    test('edit draft remaps unit qty and harga into form fields', async ({ page, request }) => {
        const draft = await createDraftSale(request);
        if (draft.skipped) {
            test.skip(true, draft.reason);
            return;
        }

        const { sales, unit, qty, harga, product } = draft;

        await page.goto(`/app/penjualan/sales/${sales.ulid}/edit`);
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Edit Penjualan')).toBeVisible({ timeout: 15000 });
        await expect(page.getByText('Belum ada detail produk')).toHaveCount(0);

        // Baris terisi menampilkan kode/nama teks — bukan combobox kosong
        await expect(page.locator('.sales-detail-table .p-datatable-tbody tr')).toHaveCount(1, { timeout: 15000 });
        const kode = product.kode_produk || '';
        if (kode) {
            await expect(page.locator('.sales-detail-table').getByText(kode, { exact: true }).first()).toBeVisible({
                timeout: 10000
            });
        }
        await expect(page.locator('.sales-detail-table').getByText(new RegExp(unit))).toBeVisible();

        const detailRow = page.locator('.sales-detail-table .p-datatable-tbody tr').first();
        const spinbuttons = detailRow.getByRole('spinbutton');
        await expect(spinbuttons.first()).toHaveValue(String(qty));
        await expect(spinbuttons.nth(1)).toHaveAttribute('aria-valuenow', new RegExp(String(Math.round(harga))));

        await request.delete(`${apiURL}/sales/${sales.ulid}`, { headers: authHeaders(authData) });
    });

    test('retur list page loads', async ({ page }) => {
        await page.goto('/app/penjualan/retur');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Daftar Retur Penjualan')).toBeVisible({ timeout: 15000 });
        await expect(page.locator('.p-datatable')).toBeVisible();
    });

    test('piutang list page loads aging and table', async ({ page }) => {
        await page.goto('/app/penjualan/piutang');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Daftar Piutang Customer')).toBeVisible({ timeout: 15000 });
        await expect(page.getByText('Total Piutang Outstanding').locator('visible=true').first()).toBeVisible();
        await expect(page.locator('.p-datatable')).toBeVisible();
    });

    test('pembayaran piutang list page loads', async ({ page }) => {
        await page.goto('/app/penjualan/pembayaran');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Daftar Pembayaran Piutang')).toBeVisible({ timeout: 15000 });
        await expect(page.getByRole('button', { name: 'Buat Pembayaran' })).toBeVisible();
        await expect(page.locator('.p-datatable')).toBeVisible();
    });

    test('deposit list page loads', async ({ page }) => {
        await page.goto('/app/penjualan/deposit');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Daftar Deposit Customer')).toBeVisible({ timeout: 15000 });
        await expect(page.getByText('Total Deposit').locator('visible=true').first()).toBeVisible();
        await expect(page.locator('.p-datatable')).toBeVisible();
        await expect(page.getByPlaceholder('Cari customer, no. dokumen...')).toBeVisible();
    });

    test('draft sales approve then appears completed in list', async ({ page, request }) => {
        // negative_mode sering terkunci setelah ada stock_card — seed stok via adjustment
        const draft = await createDraftSale(request, { seedStock: true });
        if (draft.skipped) {
            test.skip(true, draft.reason);
            return;
        }

        const headers = authHeaders(authData);
        const { sales } = draft;
        const nomor = sales.nomor_dokumen;

        const approveRes = await request.post(`${apiURL}/sales/${sales.ulid}/approve`, { headers });
        if (approveRes.status() === 403) {
            test.skip(true, 'sales.approve permission missing');
            return;
        }
        const approvedBody = await approveRes.json();
        expect(approveRes.ok(), JSON.stringify(approvedBody)).toBeTruthy();
        expect(approvedBody.data?.sales?.status || approvedBody.data?.status).toMatch(/completed|approved/i);

        await page.goto('/app/penjualan/sales');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('.p-datatable')).toBeVisible({ timeout: 20000 });

        if (nomor) {
            const search = page.getByPlaceholder(/cari/i).first();
            if (await search.isVisible().catch(() => false)) {
                await search.fill(nomor);
                await page.waitForTimeout(800);
            }
            await expect(page.getByText(nomor).first()).toBeVisible({ timeout: 15000 });
        }

        await expect(page.getByText(/completed|selesai|lunas/i).first()).toBeVisible({ timeout: 15000 });
    });
});
