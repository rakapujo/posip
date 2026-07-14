/**
 * Composable for POS Cart state management
 * Handles cart items, customer, discount, hold/resume, and calculation
 */
import { ref, computed, watch } from 'vue';
import { posApi } from '@/api';
import { useNotification } from '@/composables/useNotification';

export function usePosCart(terminalConfig = {}) {
    const notify = useNotification();

    // Discount mode from settings (injected via setTerminalContext)
    const discountMode = ref('recursive'); // 'recursive' or 'sum'

    // ─── Terminal Context ───
    const terminalUlid = ref(terminalConfig.terminalUlid || null);
    const warehouseId = ref(terminalConfig.warehouseId || null);
    const shiftId = ref(terminalConfig.shiftId || null);
    const terminalId = ref(terminalConfig.terminalId || null);
    const negativeStockAllowed = ref(false);

    // ─── Cart Auto-save ───
    // Saves cart state to localStorage every 2 seconds (debounced) so browser
    // crash/refresh doesn't lose items. Separate from hold system (pos_held_*).
    const AUTOSAVE_PREFIX = 'pos_cart_autosave_';
    let saveTimer = null;

    const _getAutoSaveKey = () => AUTOSAVE_PREFIX + terminalUlid.value;

    const _persistCart = () => {
        if (!shiftId.value || !terminalUlid.value || items.value.length === 0) {
            // Nothing to save, or no context — clear stale saves
            if (terminalUlid.value) _clearSavedCart();
            return;
        }
        try {
            const data = {
                customer: customer.value,
                items: items.value.map((i) => ({
                    product_id: i.product_id,
                    product: i.product,
                    is_serial: i.is_serial ?? false,
                    serial_units: i.serial_units ?? null,
                    serial_unit_ids: i.serial_unit_ids ?? null,
                    unit: i.unit,
                    konversi: i.konversi,
                    qty: i.qty,
                    harga_satuan: i.harga_satuan,
                    diskon_1_tipe: i.diskon_1_tipe,
                    diskon_1_nilai: i.diskon_1_nilai,
                    diskon_2_tipe: i.diskon_2_tipe,
                    diskon_2_nilai: i.diskon_2_nilai,
                    diskon_3_tipe: i.diskon_3_tipe,
                    diskon_3_nilai: i.diskon_3_nilai,
                    diskon_4_tipe: i.diskon_4_tipe,
                    diskon_4_nilai: i.diskon_4_nilai,
                    diskon_5_tipe: i.diskon_5_tipe,
                    diskon_5_nilai: i.diskon_5_nilai,
                    diskon_persen: i.diskon_persen,
                    diskon_nominal: i.diskon_nominal,
                    jumlah: i.jumlah,
                    units: i.units,
                    promo_id: i.promo_id,
                    promo_name: i.promo_name,
                    _override_promo: i._override_promo ?? false
                })),
                discounts: discounts.value,
                notaDiscountOverrides: notaDiscountOverrides.value,
                biayaKirim: biayaKirim.value,
                biayaLain: biayaLain.value,
                notes: notes.value,
                shiftId: shiftId.value,
                terminalUlid: terminalUlid.value,
                savedAt: new Date().toISOString()
            };
            localStorage.setItem(_getAutoSaveKey(), JSON.stringify(data));
        } catch {
            /* localStorage full or serialization error — silent */
        }
    };

    const restoreCart = () => {
        if (!terminalUlid.value) return false;
        try {
            const raw = localStorage.getItem(_getAutoSaveKey());
            if (!raw) return false;
            const data = JSON.parse(raw);
            // Only restore if same shift (prevent stale data from old shifts)
            if (data.shiftId !== shiftId.value) {
                _clearSavedCart();
                return false;
            }
            if (!data.items?.length) return false;

            customer.value = data.customer || null;
            discounts.value = data.discounts || [
                { tipe: 'none', nilai: 0 },
                { tipe: 'none', nilai: 0 },
                { tipe: 'none', nilai: 0 }
            ];
            notaDiscountOverrides.value = Array.isArray(data.notaDiscountOverrides) ? data.notaDiscountOverrides : [false, false, false];
            biayaKirim.value = data.biayaKirim || { tipe: 'none', nilai: 0 };
            biayaLain.value = data.biayaLain || { tipe: 'none', nilai: 0 };
            notes.value = data.notes || '';

            items.value = data.items.map((i) => ({
                id: ++itemIdCounter,
                ...i,
                _override_promo: i._override_promo ?? false
            }));
            return true;
        } catch {
            _clearSavedCart();
            return false;
        }
    };

    const _clearSavedCart = () => {
        try {
            localStorage.removeItem(_getAutoSaveKey());
        } catch {
            /* ignore */
        }
    };

    /**
     * Update terminal context (called after activeTerminal API)
     */
    const setTerminalContext = (ctx) => {
        terminalUlid.value = ctx.terminalUlid;
        terminalId.value = ctx.terminalId;
        warehouseId.value = ctx.warehouseId;
        shiftId.value = ctx.shiftId;
        if (ctx.negativeStockAllowed !== undefined) {
            negativeStockAllowed.value = ctx.negativeStockAllowed;
        }
        if (ctx.discountMode) {
            discountMode.value = ctx.discountMode;
        }
    };

    /**
     * Get total base qty used by other cart lines for the same product (excluding given itemId)
     */
    const getUsedBaseQty = (productId, excludeItemId = null) => {
        return items.value.filter((i) => i.product_id === productId && i.id !== excludeItemId).reduce((sum, i) => sum + i.qty * i.konversi, 0);
    };

    /**
     * Get max qty for a cart item (in its unit), respecting stock
     * Returns null if no limit (negative stock allowed)
     */
    const getMaxQty = (item) => {
        if (negativeStockAllowed.value) return null;
        const stok = item.product?.stok || 0;
        const usedByOthers = getUsedBaseQty(item.product_id, item.id);
        const availableBase = stok - usedByOthers;
        return Math.max(0, Math.floor(availableBase / item.konversi));
    };

    // ─── Customer ───
    const customer = ref(null); // { id, ulid, kode_customer, nama, jenis }

    const isWalkIn = computed(() => !customer.value || customer.value.jenis === 'walk_in');

    // ─── Override Flags ───
    // Kasir dapat secara eksplisit skip auto-diskon (nota slot 1/2 dari customer,
    // slot 1-4 line dari promo). Default false = auto-apply seperti biasa. Flag
    // ikut terkirim ke backend agar anti-fraud override bisa di-bypass secara sadar.
    // Reset tiap ganti customer (fresh slate per transaksi).
    const notaDiscountOverrides = ref([false, false, false]);

    const setCustomer = (c) => {
        customer.value = c;
        // Fresh customer → reset all overrides, re-derive auto discounts
        notaDiscountOverrides.value = [false, false, false];
        for (const item of items.value) {
            item._override_promo = false;
        }
        applyCustomerDiscount();
        applyAllPromos();
    };

    /**
     * Auto-apply tipe/kategori customer discount to header slots 1 & 2.
     * Slot 3 is reserved for manual/override. Skips any slot that kasir has
     * explicitly overridden via clearNotaSlot().
     */
    const applyCustomerDiscount = () => {
        const c = customer.value;

        // Slot 1 — tipe_customer
        if (!notaDiscountOverrides.value[0]) {
            const tipe = c?.tipe_customer;
            if (tipe && tipe.diskon_tipe !== 'none' && Number(tipe.diskon_nilai) > 0) {
                setDiscount(1, tipe.diskon_tipe, Number(tipe.diskon_nilai));
            } else {
                setDiscount(1, 'none', 0);
            }
        }

        // Slot 2 — kategori_customer
        if (!notaDiscountOverrides.value[1]) {
            const kat = c?.kategori_customer;
            if (kat && kat.diskon_tipe !== 'none' && Number(kat.diskon_nilai) > 0) {
                setDiscount(2, kat.diskon_tipe, Number(kat.diskon_nilai));
            } else {
                setDiscount(2, 'none', 0);
            }
        }
    };

    // ─── Active Promos ───
    // Populated by PosKasirPage after /pos/active-promos API. Preview-only:
    // backend (CheckoutSalesAction / PromoService) rebuilds at checkout (anti-fraud).
    const activePromos = ref([]);

    const setActivePromos = (promos) => {
        activePromos.value = Array.isArray(promos) ? promos : [];
        applyAllPromos();
    };

    /**
     * Client-side best-promo matching for a single cart item.
     * Mirrors PromoService logic: per slot 1-4 pick highest-rupiah qualifying detail,
     * then pick the promo with highest total-diskon across slots.
     *
     * Honors item._override_promo — when true, kasir has explicitly cleared promo
     * so we leave slots 1-4 at their current values (zero) instead of re-filling.
     */
    const applyPromoToItem = (item) => {
        if (item._override_promo) {
            // Respect explicit override — still recalc in case qty/unit changed
            recalcLine(item);
            return;
        }

        if (activePromos.value.length === 0) {
            for (let i = 1; i <= 4; i++) {
                item[`diskon_${i}_tipe`] = 'none';
                item[`diskon_${i}_nilai`] = 0;
            }
            item.promo_id = null;
            item.promo_name = null;
            recalcLine(item);
            return;
        }

        const productId = item.product_id;
        const grupId = item.product?.grup_id ?? null;
        const kategoriId = item.product?.kategori_id ?? null;
        const qty = item.qty;
        const harga = item.harga_satuan;
        const bruto = qty * harga;
        if (bruto <= 0) return;

        let best = null;

        for (const promo of activePromos.value) {
            if (!Array.isArray(promo.details) || promo.details.length === 0) continue;

            const qualifying = promo.details.filter((d) => {
                let targetOk = false;
                switch (d.target_type) {
                    case 'semua':
                        targetOk = true;
                        break;
                    case 'produk':
                        targetOk = d.target_id === productId;
                        break;
                    case 'grup':
                        targetOk = grupId !== null && d.target_id === grupId;
                        break;
                    case 'kategori':
                        targetOk = kategoriId !== null && d.target_id === kategoriId;
                        break;
                }
                return targetOk && qty >= (Number(d.min_qty) || 1);
            });
            if (qualifying.length === 0) continue;

            const bestSlot = {};
            for (let i = 1; i <= 4; i++) bestSlot[i] = { tipe: 'none', nilai: 0, rupiah: 0 };

            for (const d of qualifying) {
                for (let i = 1; i <= 4; i++) {
                    const tipe = d[`diskon_${i}_tipe`];
                    const nilai = Number(d[`diskon_${i}_nilai`]);
                    if (!tipe || tipe === 'none' || nilai <= 0) continue;
                    const rupiah = tipe === 'percent' ? Math.round((bruto * Math.min(100, nilai)) / 100) : Math.min(bruto, Math.round(nilai));
                    if (rupiah > bestSlot[i].rupiah) {
                        bestSlot[i] = { tipe, nilai, rupiah };
                    }
                }
            }

            let running = bruto;
            let totalDiskon = 0;
            for (let i = 1; i <= 4; i++) {
                const { tipe, nilai } = bestSlot[i];
                if (tipe === 'none' || nilai <= 0) continue;
                const base = discountMode.value === 'recursive' ? running : bruto;
                const disc = tipe === 'percent' ? Math.round((base * Math.min(100, nilai)) / 100) : Math.min(base, Math.round(nilai));
                totalDiskon += disc;
                running -= disc;
            }
            if (totalDiskon <= 0) continue;

            if (!best || totalDiskon > best.totalDiskon) {
                best = { promo_id: promo.id, promo_name: promo.nama_promo, totalDiskon, slots: { ...bestSlot } };
            }
        }

        for (let i = 1; i <= 4; i++) {
            item[`diskon_${i}_tipe`] = best ? best.slots[i].tipe : 'none';
            item[`diskon_${i}_nilai`] = best ? best.slots[i].nilai : 0;
        }
        item.promo_id = best?.promo_id ?? null;
        item.promo_name = best?.promo_name ?? null;
        recalcLine(item);
    };

    const applyAllPromos = () => {
        for (const item of items.value) {
            applyPromoToItem(item);
        }
    };

    // ─── Cart Items ───
    const items = ref([]);
    // Each item: { id (unique key), product_id, product, unit, konversi, qty, harga_satuan, diskon_persen, diskon_nominal, jumlah, units[] }

    let itemIdCounter = 0;

    /**
     * Build available units array from product data
     */
    const buildUnits = (product) => {
        const units = [];
        for (let i = 1; i <= 4; i++) {
            const unit = product[`unit_${i}`];
            const konversi = product[`konversi_${i}`];
            const harga = product[`harga_${i}`];
            if (unit && konversi && harga) {
                units.push({ unit, konversi: Number(konversi), harga: Number(harga) });
            }
        }
        return units;
    };

    /**
     * Add product to cart (or increment qty if same product+unit exists)
     */
    const addItem = (product, unitIndex = 0) => {
        // Produk serial tak bisa ditambah lewat klik/qty — wajib scan nomor seri (SN)
        if (product.is_serial) {
            notify.warn('Produk serial: scan nomor seri (SN) unit untuk menambah ke keranjang');
            return;
        }

        const units = buildUnits(product);
        if (units.length === 0) return;

        const selectedUnit = units[unitIndex] || units[0];

        // Check if same product + same unit already in cart
        const existing = items.value.find((i) => i.product_id === product.id && i.unit === selectedUnit.unit);

        if (existing) {
            // Stock check
            if (!negativeStockAllowed.value) {
                const maxQty = getMaxQty(existing);
                if (maxQty !== null && existing.qty >= maxQty) {
                    notify.warn('Stok tidak mencukupi');
                    return;
                }
            }
            existing.qty += 1;
            applyPromoToItem(existing); // qty changed → promo eligibility may change
            return;
        }

        const item = {
            id: ++itemIdCounter,
            product_id: product.id,
            product: {
                id: product.id,
                ulid: product.ulid,
                kode_produk: product.kode_produk,
                nama_produk: product.nama_produk,
                barcode: product.barcode,
                gambar: product.gambar,
                stok: product.stok,
                // Needed for client-side promo matching (grup/kategori targeting)
                grup_id: product.grup_id ?? null,
                kategori_id: product.kategori_id ?? null
            },
            unit: selectedUnit.unit,
            konversi: selectedUnit.konversi,
            qty: 1,
            harga_satuan: selectedUnit.harga,
            // Diskon 1-4: otomatis dari promo engine, Diskon 5: manual kasir
            diskon_1_tipe: 'none',
            diskon_1_nilai: 0,
            diskon_2_tipe: 'none',
            diskon_2_nilai: 0,
            diskon_3_tipe: 'none',
            diskon_3_nilai: 0,
            diskon_4_tipe: 'none',
            diskon_4_nilai: 0,
            diskon_5_tipe: 'none',
            diskon_5_nilai: 0,
            diskon_persen: 0,
            diskon_nominal: 0,
            jumlah: Number(selectedUnit.harga),
            units,
            promo_id: null,
            promo_name: null,
            _override_promo: false
        };

        // Stock check for new item
        if (!negativeStockAllowed.value) {
            const stok = product.stok || 0;
            const usedByOthers = getUsedBaseQty(product.id);
            const availableBase = stok - usedByOthers;
            if (availableBase < selectedUnit.konversi) {
                notify.warn('Stok tidak mencukupi');
                return;
            }
        }

        items.value.push(item);
        applyPromoToItem(item);
    };

    /**
     * Tambah unit serial (hasil scan SN / lookup) ke keranjang.
     * Satu baris per produk serial; qty = jumlah SN. Harga default = harga_jual unit.
     * @param {Object} unit - { ulid, serial_number, product{...}, harga_jual, grade, battery_*, account_status, catatan }
     * @returns {boolean} berhasil ditambah
     */
    const addSerialUnit = (unit) => {
        const product = unit.product;
        if (!product?.id) return false;

        let line = items.value.find((i) => i.product_id === product.id && i.is_serial);
        if (line) {
            if (line.serial_unit_ids.includes(unit.ulid)) {
                notify.warn(`Unit ${unit.kode_internal || unit.serial_number} sudah ada di keranjang`);
                return false;
            }
            line.serial_units.push(unit);
            line.serial_unit_ids.push(unit.ulid);
            line.qty = line.serial_units.length;
            applyPromoToItem(line);
            return true;
        }

        const harga = Number(unit.harga_jual) || 0;
        const item = {
            id: ++itemIdCounter,
            product_id: product.id,
            product: {
                id: product.id,
                ulid: product.ulid,
                kode_produk: product.kode_produk,
                nama_produk: product.nama_produk,
                barcode: null,
                gambar: product.gambar ?? null,
                stok: null,
                grup_id: product.grup_id ?? null,
                kategori_id: product.kategori_id ?? null
            },
            is_serial: true,
            serial_units: [unit],
            serial_unit_ids: [unit.ulid],
            unit: 'UNIT',
            konversi: 1,
            qty: 1,
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
            diskon_5_nilai: 0,
            diskon_persen: 0,
            diskon_nominal: 0,
            jumlah: harga,
            units: [{ unit: 'UNIT', konversi: 1, harga }],
            promo_id: null,
            promo_name: null,
            _override_promo: false
        };
        items.value.push(item);
        applyPromoToItem(item);
        return true;
    };

    /**
     * Lepas satu SN dari baris serial (atau hapus baris bila kosong).
     */
    const removeSerialUnit = (itemId, ulid) => {
        const line = items.value.find((i) => i.id === itemId);
        if (!line || !line.is_serial) return;
        line.serial_units = line.serial_units.filter((u) => u.ulid !== ulid);
        line.serial_unit_ids = line.serial_unit_ids.filter((u) => u !== ulid);
        if (line.serial_units.length === 0) {
            removeItem(itemId);
            return;
        }
        line.qty = line.serial_units.length;
        applyPromoToItem(line);
    };

    /**
     * Remove item from cart by id
     */
    const removeItem = (itemId) => {
        items.value = items.value.filter((i) => i.id !== itemId);
    };

    /**
     * Update item quantity
     */
    const updateQty = (itemId, qty) => {
        const item = items.value.find((i) => i.id === itemId);
        if (!item) return;
        // Serial: qty mengikuti jumlah SN — tak bisa diubah manual (scan / lepas SN)
        if (item.is_serial) {
            notify.warn('Qty produk serial mengikuti jumlah SN — scan atau lepas SN');
            return;
        }
        let newQty = Math.max(0, qty);
        if (newQty === 0) {
            removeItem(itemId);
            return;
        }
        // Enforce stock limit
        if (!negativeStockAllowed.value) {
            const maxQty = getMaxQty(item);
            if (maxQty !== null && newQty > maxQty) {
                newQty = maxQty;
                if (newQty <= 0) {
                    notify.warn('Stok tidak mencukupi');
                    return;
                }
                notify.warn(`Stok terbatas, max ${maxQty} ${item.unit}`);
            }
        }
        item.qty = newQty;
        applyPromoToItem(item); // qty threshold may cross min_qty boundary
    };

    /**
     * Change unit for a cart item
     */
    const changeUnit = (itemId, unit) => {
        const item = items.value.find((i) => i.id === itemId);
        if (!item) return;
        const u = item.units.find((u) => u.unit === unit);
        if (!u) return;
        item.unit = u.unit;
        item.konversi = u.konversi;
        item.harga_satuan = u.harga;
        applyPromoToItem(item); // harga changed → rupiah-picked slots may change
    };

    /**
     * Set manual line discount (diskon_5)
     */
    const setLineDiscount = (itemId, tipe, nilai) => {
        const item = items.value.find((i) => i.id === itemId);
        if (!item) return;
        item.diskon_5_tipe = tipe || 'none';
        item.diskon_5_nilai = nilai || 0;
        recalcLine(item);
    };

    /**
     * Reset manual line discount (diskon_5 only). Auto-promo slots 1-4 untouched.
     */
    const resetLineDiscount = (itemId) => {
        const item = items.value.find((i) => i.id === itemId);
        if (!item) return;
        item.diskon_5_tipe = 'none';
        item.diskon_5_nilai = 0;
        recalcLine(item);
    };

    /**
     * Clear ALL line discount slots (1-5) — including auto promo AND manual.
     * Sets _override_promo=true so qty/unit changes don't auto-refill slots 1-4.
     * Override flag ikut ke backend payload agar CheckoutSalesAction::applyPromosToItems
     * tidak re-run promo engine untuk item ini. Kasir sadar menghilangkan diskon.
     */
    const clearLineDiscountAll = (itemId) => {
        const item = items.value.find((i) => i.id === itemId);
        if (!item) return;
        for (let i = 1; i <= 5; i++) {
            item[`diskon_${i}_tipe`] = 'none';
            item[`diskon_${i}_nilai`] = 0;
        }
        item.promo_id = null;
        item.promo_name = null;
        item._override_promo = true;
        recalcLine(item);
    };

    /**
     * Re-run promo engine on a single line. Clears override flag and re-applies
     * promo matching. Useful to revert after accidental clearLineDiscountAll.
     */
    const regenerateLineDiscount = (itemId) => {
        const item = items.value.find((i) => i.id === itemId);
        if (!item) return;
        item._override_promo = false;
        applyPromoToItem(item);
    };

    /**
     * Calculate a single discount level against a running value
     */
    const calcDiscLevel = (tipe, nilai, runningValue) => {
        if (tipe === 'none' || !nilai) return 0;
        if (tipe === 'percent') return Math.round((runningValue * Math.min(100, nilai)) / 100);
        if (tipe === 'nominal') return Math.min(runningValue, Math.round(nilai));
        return 0;
    };

    /**
     * Recalculate a single line item with 5-level discounts.
     * Mode 'recursive': each level uses running value after previous discounts.
     * Mode 'sum': all levels use original bruto, results summed.
     */
    const recalcLine = (item) => {
        const bruto = Number(item.qty) * Number(item.harga_satuan);
        let totalDiskon = 0;
        let running = bruto;

        for (let i = 1; i <= 5; i++) {
            const base = discountMode.value === 'recursive' ? running : bruto;
            const d = calcDiscLevel(item[`diskon_${i}_tipe`], item[`diskon_${i}_nilai`], base);
            totalDiskon += d;
            running -= d;
        }

        item.diskon_nominal = totalDiskon;
        item.diskon_persen = bruto > 0 ? (totalDiskon / bruto) * 100 : 0;
        item.jumlah = bruto - totalDiskon;
    };

    /**
     * Clear all items from cart
     */
    const clearCart = () => {
        items.value = [];
        _clearSavedCart();
    };

    // ─── Header Discount (3-level: 1&2 auto, 3 manual) ───
    const discounts = ref([
        { tipe: 'none', nilai: 0 },
        { tipe: 'none', nilai: 0 },
        { tipe: 'none', nilai: 0 }
    ]);

    const setDiscount = (level, tipe, nilai) => {
        if (level >= 1 && level <= 3) {
            discounts.value[level - 1] = { tipe: tipe || 'none', nilai: nilai || 0 };
        }
    };

    const clearDiscounts = () => {
        discounts.value = [
            { tipe: 'none', nilai: 0 },
            { tipe: 'none', nilai: 0 },
            { tipe: 'none', nilai: 0 }
        ];
        // Reset overrides too — this is typically called on cart reset, not UI hapus
        notaDiscountOverrides.value = [false, false, false];
    };

    /**
     * User-facing "Hapus Disc Nota slot N" — clears the slot AND marks override so:
     *   - Subsequent applyCustomerDiscount() won't re-derive this slot
     *   - Payload flag tells backend to skip anti-fraud re-derivation
     *
     * Slot index: 1, 2, or 3 (matches setDiscount level numbering).
     */
    const clearNotaSlot = (level) => {
        if (level < 1 || level > 3) return;
        discounts.value[level - 1] = { tipe: 'none', nilai: 0 };
        notaDiscountOverrides.value[level - 1] = true;
    };

    /**
     * Re-derive nota-level auto discounts (slot 1 = tipe_customer, slot 2 = kategori_customer)
     * from the currently-selected customer. Slot 3 (manual) is preserved. Clears
     * any override flags so auto-derivation resumes.
     */
    const regenerateNotaDiscount = () => {
        notaDiscountOverrides.value = [false, false, false];
        applyCustomerDiscount();
    };

    // ─── Biaya Kirim & Biaya Lain-Lain ───
    const biayaKirim = ref({ tipe: 'none', nilai: 0 });
    const biayaLain = ref({ tipe: 'none', nilai: 0 });

    const setBiayaKirim = (tipe, nilai) => {
        biayaKirim.value = { tipe: tipe || 'none', nilai: nilai || 0 };
    };

    const setBiayaLain = (tipe, nilai) => {
        biayaLain.value = { tipe: tipe || 'none', nilai: nilai || 0 };
    };

    const clearBiaya = () => {
        biayaKirim.value = { tipe: 'none', nilai: 0 };
        biayaLain.value = { tipe: 'none', nilai: 0 };
    };

    const clearHeaderDiscount = () => {
        clearDiscounts();
        clearBiaya();
    };

    // ─── Notes ───
    const notes = ref('');

    // ─── Computed Totals (client-side preview) ───
    const subtotal = computed(() => {
        return items.value.reduce((sum, i) => sum + Number(i.jumlah), 0);
    });

    const itemCount = computed(() => items.value.length);

    const totalQty = computed(() => {
        return items.value.reduce((sum, i) => sum + i.qty, 0);
    });

    // ─── Server-side Calculation ───
    const totals = ref(null);
    const calculating = ref(false);

    /**
     * Call backend to calculate totals (with tax, rounding, etc.)
     */
    const calculateTotals = async (payments = []) => {
        if (items.value.length === 0) {
            totals.value = null;
            return null;
        }
        calculating.value = true;
        try {
            const res = await posApi.calculate({
                subtotal: subtotal.value,
                discounts: discounts.value,
                biaya_kirim: biayaKirim.value,
                biaya_lain: biayaLain.value,
                payments
            });
            totals.value = res.data.data;
            return res.data.data;
        } catch (e) {
            notify.error('Gagal menghitung total');
            return null;
        } finally {
            calculating.value = false;
        }
    };

    // ─── Auto-calculate totals when cart/discounts/biaya change ───
    let calcTimer = null;
    const autoCalculate = () => {
        clearTimeout(calcTimer);
        calcTimer = setTimeout(() => {
            calculateTotals();
        }, 300);
    };

    watch(
        [subtotal, discounts, biayaKirim, biayaLain],
        () => {
            autoCalculate();
        },
        { deep: true }
    );

    // ─── Auto-save cart to localStorage (debounced 2s) ───
    watch(
        [items, customer, discounts, notes, biayaKirim, biayaLain],
        () => {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(_persistCart, 2000);
        },
        { deep: true }
    );

    // ─── Checkout ───
    const checkingOut = ref(false);

    /**
     * Process checkout
     * @param {Array} payments - [{ metode_pembayaran_id, nominal, biaya_tambahan, reference }]
     * @returns {Object|null} sales data or null on failure
     */
    const checkout = async (payments) => {
        if (items.value.length === 0) {
            notify.warn('Keranjang kosong');
            return null;
        }
        checkingOut.value = true;
        try {
            const data = {
                terminal_id: terminalId.value,
                shift_id: shiftId.value,
                warehouse_id: warehouseId.value,
                customer_id: customer.value?.id || null,
                discounts: discounts.value,
                // Per-slot overrides — when true, backend SKIPS anti-fraud auto-derive
                // and respects the frontend value (even if it's 'none'/0). Matches kasir's
                // explicit click on hapus button.
                nota_discount_overrides: notaDiscountOverrides.value,
                biaya_kirim: biayaKirim.value,
                biaya_lain: biayaLain.value,
                notes: notes.value || null,
                items: items.value.map((i) => ({
                    product_id: i.product_id,
                    unit: i.unit,
                    konversi: i.konversi,
                    qty: i.qty,
                    qty_base: Number(i.qty) * Number(i.konversi),
                    harga_satuan: i.harga_satuan,
                    diskon_1_tipe: i.diskon_1_tipe,
                    diskon_1_nilai: i.diskon_1_nilai,
                    diskon_2_tipe: i.diskon_2_tipe,
                    diskon_2_nilai: i.diskon_2_nilai,
                    diskon_3_tipe: i.diskon_3_tipe,
                    diskon_3_nilai: i.diskon_3_nilai,
                    diskon_4_tipe: i.diskon_4_tipe,
                    diskon_4_nilai: i.diskon_4_nilai,
                    diskon_5_tipe: i.diskon_5_tipe,
                    diskon_5_nilai: i.diskon_5_nilai,
                    diskon_total: i.diskon_nominal ?? 0,
                    jumlah: i.jumlah,
                    // Produk serial: ulid unit (SN) yang dijual (qty_base = jumlah SN)
                    serial_unit_ids: i.is_serial ? (i.serial_unit_ids ?? []) : null,
                    // When true, backend skips re-running PromoService for this item
                    override_promo: i._override_promo ?? false
                })),
                payments
            };
            const res = await posApi.checkout(data);
            // Clear cart after successful checkout
            clearCart();
            clearHeaderDiscount();
            notes.value = '';
            return res.data.data?.sales;
        } catch (e) {
            const msg = e.response?.data?.message || 'Gagal memproses checkout';
            notify.error(msg);
            return null;
        } finally {
            checkingOut.value = false;
        }
    };

    // ─── Hold / Resume (localStorage) ───
    const HOLD_MAX = 5;

    const getHoldKey = (index) => `pos_held_${terminalUlid.value}_${index}`;

    /**
     * Get all held transactions for this terminal
     */
    const getHeldTransactions = () => {
        if (!terminalUlid.value) return [];
        const held = [];
        for (let i = 0; i < HOLD_MAX; i++) {
            const raw = localStorage.getItem(getHoldKey(i));
            if (raw) {
                try {
                    const parsed = JSON.parse(raw);
                    parsed._holdIndex = i;
                    held.push(parsed);
                } catch {
                    // ignore corrupted data
                }
            }
        }
        return held;
    };

    const heldCount = ref(0);

    const refreshHeldCount = () => {
        heldCount.value = getHeldTransactions().length;
    };

    /**
     * Hold current cart to localStorage
     */
    const holdCart = () => {
        if (items.value.length === 0) {
            notify.warn('Keranjang kosong, tidak ada yang ditahan');
            return false;
        }
        if (!terminalUlid.value) return false;

        const held = getHeldTransactions();
        if (held.length >= HOLD_MAX) {
            notify.warn(`Maksimal ${HOLD_MAX} transaksi ditahan`);
            return false;
        }

        // Find next available index
        const usedIndices = held.map((h) => h._holdIndex);
        let nextIndex = 0;
        for (let i = 0; i < HOLD_MAX; i++) {
            if (!usedIndices.includes(i)) {
                nextIndex = i;
                break;
            }
        }

        const holdData = {
            customer: customer.value,
            items: items.value.map((i) => ({
                product_id: i.product_id,
                product: i.product,
                is_serial: i.is_serial ?? false,
                serial_units: i.serial_units ?? null,
                serial_unit_ids: i.serial_unit_ids ?? null,
                unit: i.unit,
                konversi: i.konversi,
                qty: i.qty,
                harga_satuan: i.harga_satuan,
                diskon_1_tipe: i.diskon_1_tipe,
                diskon_1_nilai: i.diskon_1_nilai,
                diskon_2_tipe: i.diskon_2_tipe,
                diskon_2_nilai: i.diskon_2_nilai,
                diskon_3_tipe: i.diskon_3_tipe,
                diskon_3_nilai: i.diskon_3_nilai,
                diskon_4_tipe: i.diskon_4_tipe,
                diskon_4_nilai: i.diskon_4_nilai,
                diskon_5_tipe: i.diskon_5_tipe,
                diskon_5_nilai: i.diskon_5_nilai,
                diskon_persen: i.diskon_persen,
                diskon_nominal: i.diskon_nominal,
                jumlah: i.jumlah,
                units: i.units,
                promo_id: i.promo_id ?? null,
                promo_name: i.promo_name ?? null,
                _override_promo: i._override_promo ?? false
            })),
            discounts: discounts.value,
            nota_discount_overrides: notaDiscountOverrides.value,
            biaya_kirim: biayaKirim.value,
            biaya_lain: biayaLain.value,
            notes: notes.value,
            held_at: new Date().toISOString(),
            item_count: items.value.length,
            total: subtotal.value
        };

        localStorage.setItem(getHoldKey(nextIndex), JSON.stringify(holdData));
        clearCart();
        clearHeaderDiscount();
        notes.value = '';
        customer.value = null;
        refreshHeldCount();
        notify.success('Transaksi ditahan');
        return true;
    };

    /**
     * Resume a held transaction into the cart
     * @param {number} holdIndex - The hold slot index
     * @returns {boolean} success
     */
    const resumeHold = (holdIndex) => {
        const raw = localStorage.getItem(getHoldKey(holdIndex));
        if (!raw) return false;

        try {
            const data = JSON.parse(raw);

            // Restore cart
            customer.value = data.customer || null;
            discounts.value = data.discounts || [
                { tipe: 'none', nilai: 0 },
                { tipe: 'none', nilai: 0 },
                { tipe: 'none', nilai: 0 }
            ];
            notaDiscountOverrides.value = Array.isArray(data.nota_discount_overrides) ? data.nota_discount_overrides.slice(0, 3).map(Boolean).concat([false, false, false]).slice(0, 3) : [false, false, false];
            biayaKirim.value = data.biaya_kirim || { tipe: 'none', nilai: 0 };
            biayaLain.value = data.biaya_lain || { tipe: 'none', nilai: 0 };
            notes.value = data.notes || '';

            items.value = (data.items || []).map((i) => ({
                id: ++itemIdCounter,
                product_id: i.product_id,
                product: i.product,
                is_serial: i.is_serial ?? false,
                serial_units: i.serial_units ?? null,
                serial_unit_ids: i.serial_unit_ids ?? null,
                unit: i.unit,
                konversi: i.konversi,
                qty: i.qty,
                harga_satuan: i.harga_satuan,
                diskon_1_tipe: i.diskon_1_tipe,
                diskon_1_nilai: i.diskon_1_nilai,
                diskon_2_tipe: i.diskon_2_tipe,
                diskon_2_nilai: i.diskon_2_nilai,
                diskon_3_tipe: i.diskon_3_tipe,
                diskon_3_nilai: i.diskon_3_nilai,
                diskon_4_tipe: i.diskon_4_tipe,
                diskon_4_nilai: i.diskon_4_nilai,
                diskon_5_tipe: i.diskon_5_tipe,
                diskon_5_nilai: i.diskon_5_nilai,
                diskon_persen: i.diskon_persen,
                diskon_nominal: i.diskon_nominal,
                jumlah: i.jumlah,
                units: i.units,
                promo_id: i.promo_id ?? null,
                promo_name: i.promo_name ?? null,
                _override_promo: i._override_promo ?? false
            }));

            // Re-apply current promos — promo state may have shifted while held.
            // applyPromoToItem respects _override_promo so explicitly-cleared
            // items stay cleared after resume.
            applyAllPromos();

            // Remove from localStorage
            localStorage.removeItem(getHoldKey(holdIndex));
            refreshHeldCount();
            return true;
        } catch {
            return false;
        }
    };

    /**
     * Delete a held transaction
     */
    const deleteHold = (holdIndex) => {
        localStorage.removeItem(getHoldKey(holdIndex));
        refreshHeldCount();
    };

    /**
     * Check if there are held transactions (for close shift warning)
     */
    const hasHeldTransactions = computed(() => heldCount.value > 0);

    // ─── Cart State Check ───
    const hasItems = computed(() => items.value.length > 0);

    /**
     * Full reset of cart state
     */
    const resetAll = () => {
        clearCart();
        clearHeaderDiscount();
        notes.value = '';
        customer.value = null;
        totals.value = null;
    };

    // Labels for header discount slots 1/2/3. Used by receipt printing so struk
    // shows "VIP 10%" instead of generic "Disc Nota 1". Printer code merges
    // these into the sale payload before render.
    const discountLabels = computed(() => {
        const labels = ['', '', ''];
        const c = customer.value;

        const tipe = c?.tipe_customer;
        if (tipe && tipe.diskon_tipe !== 'none' && Number(tipe.diskon_nilai) > 0) {
            labels[0] = tipe.diskon_tipe === 'percent' ? `${tipe.kode_tipe} ${tipe.diskon_nilai}%` : `${tipe.kode_tipe} Rp ${Number(tipe.diskon_nilai).toLocaleString('id')}`;
        }

        const kat = c?.kategori_customer;
        if (kat && kat.diskon_tipe !== 'none' && Number(kat.diskon_nilai) > 0) {
            labels[1] = kat.diskon_tipe === 'percent' ? `${kat.kode_kategori} ${kat.diskon_nilai}%` : `${kat.kode_kategori} Rp ${Number(kat.diskon_nilai).toLocaleString('id')}`;
        }

        const d3 = discounts.value[2];
        if (d3 && d3.tipe !== 'none' && Number(d3.nilai) > 0) {
            labels[2] = 'Disc Manual';
        }

        return labels;
    });

    return {
        // Terminal context
        terminalUlid,
        terminalId,
        warehouseId,
        shiftId,
        setTerminalContext,

        // Customer
        customer,
        isWalkIn,
        setCustomer,

        // Promo engine (preview; backend rebuilds at checkout)
        activePromos,
        setActivePromos,
        applyAllPromos,

        // Cart items
        items,
        addItem,
        addSerialUnit,
        removeSerialUnit,
        removeItem,
        updateQty,
        changeUnit,
        setLineDiscount,
        resetLineDiscount,
        clearLineDiscountAll,
        regenerateLineDiscount,
        getMaxQty,
        clearCart,

        // Header discount (3-level)
        discounts,
        discountLabels,
        setDiscount,
        clearDiscounts,
        clearNotaSlot,
        regenerateNotaDiscount,
        notaDiscountOverrides,
        clearHeaderDiscount,

        // Biaya
        biayaKirim,
        biayaLain,
        setBiayaKirim,
        setBiayaLain,
        clearBiaya,

        // Notes
        notes,

        // Computed
        subtotal,
        itemCount,
        totalQty,
        hasItems,

        // Server calculation
        totals,
        calculating,
        calculateTotals,

        // Checkout
        checkingOut,
        checkout,

        // Hold / Resume
        HOLD_MAX,
        heldCount,
        refreshHeldCount,
        getHeldTransactions,
        holdCart,
        resumeHold,
        deleteHold,
        hasHeldTransactions,

        // Reset
        resetAll,

        // Auto-save (restore on reload)
        restoreCart
    };
}
