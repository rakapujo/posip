import { expect } from '@playwright/test';
import { authHeaders, laravelApiBase } from './auth.js';

/** Numeric user id (hidden on login payload) — needed for pos-terminals.user_ids */
export async function resolveAuthUserId(request, authData) {
    if (authData.user?.id != null) return Number(authData.user.id);

    const apiURL = laravelApiBase();
    const headers = authHeaders(authData);
    const email = authData.user?.email;
    const ulid = authData.user?.ulid;

    const listRes = await request.get(`${apiURL}/users/list`, { headers });
    expect(listRes.ok(), `users/list ${listRes.status()}`).toBeTruthy();
    const users = (await listRes.json()).data?.users || [];
    const match =
        users.find((u) => email && u.email === email) ||
        users.find((u) => ulid && u.ulid === ulid) ||
        users.find((u) => u.id != null);
    expect(match?.id, 'resolveAuthUserId').toBeTruthy();
    authData.user.id = match.id;
    return Number(match.id);
}

/**
 * Ensure E2E admin has an active POS shift on a dedicated terminal.
 * Does not force-release other users' live shifts.
 */
export async function ensurePosShift(request, authData, { kode = 'E2E_KASIR' } = {}) {
    const apiURL = laravelApiBase();
    const headers = authHeaders(authData);
    const userId = await resolveAuthUserId(request, authData);

    const activeRes = await request.get(`${apiURL}/pos/active-terminal`, { headers });
    if (activeRes.ok()) {
        const terminal = (await activeRes.json()).data?.terminal;
        const warehouseId = terminal?.warehouse_id || terminal?.warehouse?.id;
        expect(terminal?.ulid && warehouseId).toBeTruthy();
        return { ulid: terminal.ulid, warehouseId, kode: terminal.kode_terminal };
    }

    const [whRes, custRes, pmRes, listRes] = await Promise.all([
        request.get(`${apiURL}/warehouses/list?is_saleable=1`, { headers }),
        request.get(`${apiURL}/customers/list?jenis=walk_in`, { headers }),
        request.get(`${apiURL}/metode-pembayarans/list`, { headers }),
        request.get(`${apiURL}/pos-terminals?per_page=100`, { headers })
    ]);

    const warehouses = (await whRes.json()).data?.warehouses || [];
    const customers = (await custRes.json()).data?.customers || [];
    const methods =
        (await pmRes.json()).data?.metode_pembayarans ||
        (await pmRes.json()).data?.items ||
        [];
    let terminals = (await listRes.json()).data?.terminals || [];

    // End our own open shift on any other terminal first
    for (const t of terminals) {
        if (Number(t.active_user_id) === Number(userId)) {
            await request.post(`${apiURL}/pos-terminals/${t.ulid}/end-shift`, {
                headers,
                data: {}
            });
        }
    }

    let terminal = terminals.find((t) => t.kode_terminal === kode);

    const walkIn = customers.find((c) => c.jenis === 'walk_in' && c.id != null) || customers.find((c) => c.id != null);
    const cash =
        methods.find((m) => m.metode === 'tunai' && m.id != null) || methods.find((m) => m.id != null);
    const warehouse = warehouses.find((w) => w.id != null) || warehouses[0];

    if (!terminal) {
        expect(warehouse?.id && walkIn?.id && cash?.id, 'warehouse/walk-in/tunai for POS terminal').toBeTruthy();
        const createRes = await request.post(`${apiURL}/pos-terminals`, {
            headers,
            data: {
                kode_terminal: kode,
                nama_terminal: 'Terminal E2E Kasir',
                warehouse_id: warehouse.id,
                default_customer_id: walkIn.id,
                default_metode_pembayaran_id: cash.id,
                auto_open_tray: false,
                izinkan_retur: true,
                durasi_retur: 24,
                status: 'active',
                user_ids: [userId],
                metode_pembayaran_ids: methods.map((m) => m.id).filter(Boolean)
            }
        });
        const createdBody = await createRes.json();
        expect(createRes.ok(), `create terminal ${JSON.stringify(createdBody)}`).toBeTruthy();
        const fresh = await request.get(`${apiURL}/pos-terminals?per_page=100`, { headers });
        terminals = (await fresh.json()).data?.terminals || [];
        terminal = terminals.find((t) => t.kode_terminal === kode) || createdBody.data?.terminal;
    }

    expect(terminal?.ulid, `terminal ${kode}`).toBeTruthy();

    // Ensure admin is assigned (update only when free)
    const assigned = (terminal.users || []).some(
        (u) => Number(u.pivot?.user_id) === Number(userId) || u.ulid === authData.user.ulid
    );
    if (!assigned && !terminal.active_user_id) {
        expect(warehouse?.id && walkIn?.id && cash?.id, 'ids for terminal update').toBeTruthy();
        const detailRes = await request.get(`${apiURL}/pos-terminals/${terminal.ulid}`, { headers });
        const detail = (await detailRes.json()).data?.terminal || terminal;
        const existingUserIds = (detail.users || terminal.users || [])
            .map((u) => u.pivot?.user_id || u.id)
            .filter(Boolean);
        const metodeIds =
            (detail.allowed_payment_methods || []).map((m) => m.id).filter(Boolean) ||
            methods.map((m) => m.id).filter(Boolean);
        const putRes = await request.put(`${apiURL}/pos-terminals/${terminal.ulid}`, {
            headers,
            data: {
                nama_terminal: detail.nama_terminal || terminal.nama_terminal,
                warehouse_id: detail.warehouse_id || terminal.warehouse_id || warehouse.id,
                default_customer_id: detail.default_customer_id || terminal.default_customer_id || walkIn.id,
                default_metode_pembayaran_id:
                    detail.default_metode_pembayaran_id || terminal.default_metode_pembayaran_id || cash.id,
                auto_open_tray: !!detail.auto_open_tray,
                izinkan_retur: detail.izinkan_retur !== false,
                durasi_retur: detail.durasi_retur ?? 24,
                status: 'active',
                user_ids: [...new Set([...existingUserIds.map(Number), Number(userId)])],
                metode_pembayaran_ids: metodeIds.length ? metodeIds : methods.map((m) => m.id).filter(Boolean)
            }
        });
        expect(putRes.ok(), `assign user ${await putRes.text()}`).toBeTruthy();
    }

    if (Number(terminal.active_user_id) === Number(userId)) {
        const warehouseId = terminal.warehouse_id || terminal.warehouse?.id || warehouse?.id;
        return { ulid: terminal.ulid, warehouseId, kode: terminal.kode_terminal };
    }

    if (terminal.active_user_id && Number(terminal.active_user_id) !== Number(userId)) {
        // Our dedicated terminal stuck — force-release only E2E_* terminals
        if (String(terminal.kode_terminal || '').startsWith('E2E_')) {
            const release = await request.post(`${apiURL}/pos-terminals/${terminal.ulid}/force-release`, {
                headers,
                data: { closing_notes: 'E2E reset' }
            });
            expect(release.ok(), `force-release ${await release.text()}`).toBeTruthy();
        } else {
            throw new Error(`Terminal ${terminal.kode_terminal} dipakai user lain — buat kode E2E_ lain`);
        }
    }

    const startRes = await request.post(`${apiURL}/pos-terminals/${terminal.ulid}/start-shift`, { headers });
    const startBody = await startRes.json();
    expect(startRes.ok(), `start-shift ${JSON.stringify(startBody)}`).toBeTruthy();

    const warehouseId =
        startBody.data?.terminal?.warehouse_id ||
        terminal.warehouse_id ||
        terminal.warehouse?.id ||
        warehouse?.id;
    return { ulid: terminal.ulid, warehouseId, kode: terminal.kode_terminal };
}

/** Wait for POS search (or handle setor awal). */
export async function waitForPosReady(page, timeout = 20000) {
    const setorBtn = page.locator('button:has-text("Simpan & Mulai")');
    const searchBox = page.locator('input[placeholder*="Cari produk"]').first();

    await Promise.race([
        setorBtn.waitFor({ state: 'visible', timeout }).catch(() => {}),
        searchBox.waitFor({ state: 'visible', timeout }).catch(() => {})
    ]);

    if (await setorBtn.isVisible().catch(() => false)) {
        await setorBtn.click();
        await page.waitForTimeout(1500);
    }

    await expect(searchBox).toBeVisible({ timeout: 15000 });
}

/**
 * Seed stock via adjustment debit+approve. negative_mode is often locked after stock_cards exist.
 */
export async function ensureProductStock(request, authData, { productId, warehouseId, qty = 50 }) {
    const apiURL = laravelApiBase();
    const headers = authHeaders(authData);
    expect(productId && warehouseId, 'productId + warehouseId').toBeTruthy();

    const createRes = await request.post(`${apiURL}/adjustments`, {
        headers,
        data: {
            warehouse_id: warehouseId,
            tanggal: new Date().toISOString().slice(0, 19).replace('T', ' '),
            keterangan: 'E2E stock seed',
            details: [{ product_id: productId, jenis: 'debit', qty }]
        }
    });
    const createBody = await createRes.json();
    if (createRes.status() === 403) {
        return { skipped: true, reason: 'adjustment.create permission missing' };
    }
    expect(createRes.ok(), `adjustment create ${JSON.stringify(createBody)}`).toBeTruthy();
    const ulid = createBody.data?.adjustment?.ulid || createBody.data?.ulid;
    expect(ulid, 'adjustment ulid').toBeTruthy();

    const approveRes = await request.post(`${apiURL}/adjustments/${ulid}/approve`, { headers });
    const approveBody = await approveRes.json();
    if (approveRes.status() === 403) {
        return { skipped: true, reason: 'adjustment.approve permission missing' };
    }
    expect(approveRes.ok(), `adjustment approve ${JSON.stringify(approveBody)}`).toBeTruthy();
    return { ok: true, ulid };
}
