<script setup>
import { salesApi, warehousesApi, customersApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch, nextTick } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useSettingsStore } from '@/stores/settings';
import SerialUnitPicker from '@/components/common/SerialUnitPicker.vue';
import ProductUnitPickerDrawer from '@/components/common/ProductUnitPickerDrawer.vue';
import {
    buildTakenKeys,
    findDuplicateProductUnitErrors,
    productUnitRowHighlight
} from '@/utils/productUnitLineHelpers';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
const settingsStore = useSettingsStore();
const {
    formatCurrency,
    formatQty,
    shouldUppercase,
    getPrimeDateFormatShort,
    toDateTimeString,
    now,
    parseDateTime,
    getLocale,
    getQtyMinFractionDigits,
    getQtyMaxFractionDigits,
    getPercentMinFractionDigits,
    getPercentMaxFractionDigits,
    getCurrencyMinFractionDigits,
    getCurrencyMaxFractionDigits,
    currencySettings,
    calculationSettings
} = useFormatters();

// Mode
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Penjualan' : 'Buat Penjualan'));
const promoEnabled = computed(() => !!settingsStore.promo?.enabled);
const serialEnabled = computed(() => settingsStore.serialEnabled);
const gettingPromo = ref(false);
let syncingTempoCash = false;

// Data
const customers = ref([]);
const warehouses = ref([]);
const loading = ref(false);
const saving = ref(false);
const taxSettings = ref({ name: 'PPN', percent: 11, included_in_hpp: false });

// Form
const form = ref({
    tanggal_po: now(),
    customer_id: null,
    warehouse_id: null,
    tempo_hari: 0,
    notes: '',
    // Cash / lunas langsung (piutang dibuat lalu auto-lunas saat approve)
    cash_payment: false,
    cash_metode: 'cash',
    cash_no_referensi: '',
    cash_bank_nama: '',
    cash_bank_rekening: '',
    // Header discounts
    diskon_1_tipe: 'none',
    diskon_1_nilai: 0,
    diskon_2_tipe: 'none',
    diskon_2_nilai: 0,
    diskon_3_tipe: 'none',
    diskon_3_nilai: 0,
    // Additional costs
    biaya_kirim_tipe: 'none',
    biaya_kirim_nilai: 0,
    biaya_lain_tipe: 'none',
    biaya_lain_nilai: 0,
    biaya_lain_nama: 'Biaya Lain-lain',
    // Details
    details: []
});

const canAddLines = computed(() => !!form.value.customer_id && !!form.value.warehouse_id);

// Calculated totals (from backend)
const calculated = ref({
    subtotal: 0,
    total_diskon_header: 0,
    total_setelah_diskon: 0,
    biaya_kirim_hasil: 0,
    biaya_lain_hasil: 0,
    total_biaya_tambahan: 0,
    dpp: 0,
    pajak_nominal: 0,
    total_sebelum_pembulatan: 0,
    pembulatan: 0,
    grand_total: 0
});

const discLabels = ref([null, null, null]);

// Discount dialog
const discountDialog = ref(false);
const editingDiscountIndex = ref(null);

// Tipe options - dynamically use currency symbol from settings
const tipeOptions = computed(() => [
    { label: 'Tidak Ada', value: 'none' },
    { label: 'Persen (%)', value: 'percent' },
    { label: `Nominal (${currencySettings.value.symbol})`, value: 'nominal' }
]);

const cashMetodeOptions = [
    { label: 'Cash', value: 'cash' },
    { label: 'Transfer', value: 'transfer' }
];

// Discount mode label from global settings
const discountModeLabel = computed(() => {
    const mode = calculationSettings.value?.discountMode || 'recursive';
    return mode === 'recursive' ? 'Bertingkat (Recursive)' : 'Penjumlahan (Sum)';
});

// Validation
const errors = ref({});

// Row expansion: produk serial auto-expand → pemilih unit
let uidCounter = 0;
const nextUid = () => `d${++uidCounter}`;
const expandedRows = ref({});
function syncExpandedSerial() {
    const map = {};
    for (const d of form.value.details) {
        if (d.is_serial && d._uid) map[d._uid] = true;
    }
    const prev = Object.keys(expandedRows.value || {})
        .sort()
        .join('|');
    const next = Object.keys(map).sort().join('|');
    if (prev !== next) expandedRows.value = map;
}

watch(
    () => form.value.details.map((d) => `${d._uid}:${d.is_serial ? 1 : 0}`).join('|'),
    syncExpandedSerial
);

function onSerialChange(detail, units) {
    const ids = units.map((u) => u.ulid);
    const prev = detail.serial_unit_ids || [];
    const sameIds =
        ids.length === prev.length &&
        [...ids].map(String).sort().join('|') === [...prev].map(String).sort().join('|');
    if (sameIds && Number(detail.qty_in_unit) === ids.length) return;

    detail.serial_unit_ids = ids;
    detail.qty_in_unit = ids.length;
    detail.harga_per_unit = ids.length
        ? units.reduce((s, u) => s + (Number(u.harga_jual) || 0), 0) / ids.length
        : 0;
    if (ids.length) calculateTotals();
}

onMounted(async () => {
    await Promise.all([loadCustomers(), loadWarehouses(), loadTaxSettings()]);

    if (isEdit.value) {
        await loadSales();
    } else {
        healTempoCash();
    }
});

watch(
    () => form.value.warehouse_id,
    (newVal, oldVal) => {
        if (oldVal != null && newVal !== oldVal) {
            let cleared = false;
            form.value.details.forEach((d) => {
                if ((d.serial_unit_ids || []).length || (d.is_serial && Number(d.qty_in_unit) > 0)) {
                    cleared = true;
                }
                d.serial_unit_ids = [];
                if (d.is_serial) d.qty_in_unit = 0;
            });
            if (cleared) notify.warn('Gudang berubah — SN & qty serial dikosongkan.');
        }
    }
);

async function loadCustomers() {
    try {
        const response = await customersApi.getList({ jenis: 'spesifik' });
        if (response.data.success) {
            customers.value = response.data.data.customers;
        }
    } catch (error) {
        console.error('Failed to load customers:', error);
        notify.apiError(error, 'Gagal load customers');
    }
}

async function loadWarehouses() {
    try {
        const response = await warehousesApi.getList({ is_saleable: 1 });
        if (response.data.success) {
            warehouses.value = response.data.data.warehouses;
        }
    } catch (error) {
        console.error('Failed to load warehouses:', error);
        notify.apiError(error, 'Gagal load warehouses');
    }
}

async function loadTaxSettings() {
    try {
        const response = await salesApi.getTaxSettings();
        if (response.data.success) {
            taxSettings.value = response.data.data.tax;
        }
    } catch (error) {
        console.error('Failed to load tax settings:', error);
        notify.apiError(error, 'Gagal load tax settings');
    }
}

async function loadSales() {
    loading.value = true;
    try {
        const response = await salesApi.get(route.params.ulid);
        if (response.data.success) {
            const po = response.data.data.sales;

            if (po.status !== 'draft') {
                notify.cannotEditApproved('Penjualan');
                router.push({ name: 'penjualan-sales' });
                return;
            }

            syncingTempoCash = true;
            form.value = {
                tanggal_po: parseDateTime(po.tanggal),
                customer_id: po.customer_id,
                warehouse_id: po.warehouse_id,
                tempo_hari: po.tempo_hari || 0,
                notes: po.notes || '',
                cash_payment: !!po.cash_payment,
                cash_metode: po.cash_metode || 'cash',
                cash_no_referensi: po.cash_no_referensi || '',
                cash_bank_nama: po.cash_bank_nama || '',
                cash_bank_rekening: po.cash_bank_rekening || '',
                diskon_1_tipe: po.diskon_nota_1_tipe || 'none',
                diskon_1_nilai: po.diskon_nota_1_nilai || 0,
                diskon_2_tipe: po.diskon_nota_2_tipe || 'none',
                diskon_2_nilai: po.diskon_nota_2_nilai || 0,
                diskon_3_tipe: po.diskon_nota_3_tipe || 'none',
                diskon_3_nilai: po.diskon_nota_3_nilai || 0,
                biaya_kirim_tipe: po.biaya_kirim_tipe || 'none',
                biaya_kirim_nilai: po.biaya_kirim_nilai || 0,
                biaya_lain_tipe: po.biaya_lain_tipe || 'none',
                biaya_lain_nilai: po.biaya_lain_nilai || 0,
                biaya_lain_nama: po.biaya_lain_nama || 'Biaya Lain-lain',
                details: po.details.map((d) => {
                    const isSerial = serialEnabled.value && (!!d.product?.is_serial || !!(d.serial_unit_ids && d.serial_unit_ids.length));
                    return {
                        _uid: nextUid(),
                        product_id: d.product_id,
                        product: d.product,
                        is_serial: isSerial,
                        serial_unit_ids: isSerial ? d.serial_unit_ids || [] : null,
                        unit_used: isSerial ? d.unit || 'UNIT' : d.unit,
                        unit_konversi: d.konversi || 1,
                        units: isSerial ? [{ unit: 'UNIT', konversi: 1 }] : getProductUnits(d.product),
                        qty_in_unit: d.qty,
                        harga_per_unit: d.harga_satuan,
                        diskon_1_tipe: d.diskon_1_tipe || 'none',
                        diskon_1_nilai: d.diskon_1_nilai || 0,
                        diskon_2_tipe: d.diskon_2_tipe || 'none',
                        diskon_2_nilai: d.diskon_2_nilai || 0,
                        diskon_3_tipe: d.diskon_3_tipe || 'none',
                        diskon_3_nilai: d.diskon_3_nilai || 0,
                        diskon_4_tipe: d.diskon_4_tipe || 'none',
                        diskon_4_nilai: d.diskon_4_nilai || 0,
                        diskon_5_tipe: d.diskon_5_tipe || 'none',
                        diskon_5_nilai: d.diskon_5_nilai || 0,
                        promo_id: d.promo_id || null,
                        nama_promo: d.nama_promo || d.promo?.nama_promo || null
                    };
                })
            };
            healTempoCash();
            syncExpandedSerial();
            await calculateTotals();
        }
    } catch (error) {
        console.error('Failed to load PO:', error);
        notify.loadListError('penjualan');
        router.push({ name: 'penjualan-sales' });
    } finally {
        loading.value = false;
    }
}

function getProductUnits(product) {
    if (!product) return [];
    const units = [];
    const seenUnits = new Set();

    for (let i = 1; i <= 4; i++) {
        const unit = product[`unit_${i}`];
        if (unit && !seenUnits.has(unit)) {
            seenUnits.add(unit);
            units.push({
                unit: unit,
                konversi: product[`konversi_${i}`]
            });
        }
    }
    return units;
}

function customerTempoDefault(customerId = form.value.customer_id) {
    const customer = customers.value.find((c) => c.id === customerId);
    const tempo = Number(customer?.tempo_default ?? 0);
    return tempo > 0 ? tempo : 1;
}

/** Cash ↔ tempo: cash ON ⇒ tempo 0; cash OFF ⇒ tempo ≥ 1. */
function healTempoCash() {
    syncingTempoCash = true;
    try {
        if (form.value.cash_payment || Number(form.value.tempo_hari) <= 0) {
            form.value.cash_payment = true;
            form.value.tempo_hari = 0;
        } else {
            form.value.cash_payment = false;
            if (Number(form.value.tempo_hari) <= 0) {
                form.value.tempo_hari = customerTempoDefault();
            }
        }
    } finally {
        nextTick(() => {
            syncingTempoCash = false;
        });
    }
}

watch(
    () => form.value.customer_id,
    (newVal) => {
        if (!newVal || syncingTempoCash) return;
        const customer = customers.value.find((c) => c.id === newVal);
        if (!customer || customer.tempo_default === undefined) return;
        syncingTempoCash = true;
        try {
            if (Number(customer.tempo_default) <= 0) {
                form.value.cash_payment = true;
                form.value.tempo_hari = 0;
            } else if (form.value.cash_payment) {
                form.value.tempo_hari = 0;
            } else {
                form.value.tempo_hari = Number(customer.tempo_default);
            }
        } finally {
            nextTick(() => {
                syncingTempoCash = false;
            });
        }
    }
);

watch(
    () => form.value.cash_payment,
    (cash) => {
        if (syncingTempoCash) return;
        syncingTempoCash = true;
        try {
            if (cash) {
                form.value.tempo_hari = 0;
            } else if (Number(form.value.tempo_hari) <= 0) {
                form.value.tempo_hari = customerTempoDefault();
            }
        } finally {
            nextTick(() => {
                syncingTempoCash = false;
            });
        }
    }
);

watch(
    () => form.value.tempo_hari,
    (tempo) => {
        if (syncingTempoCash) return;
        syncingTempoCash = true;
        try {
            if (Number(tempo) <= 0) {
                form.value.cash_payment = true;
                form.value.tempo_hari = 0;
            } else {
                form.value.cash_payment = false;
            }
        } finally {
            nextTick(() => {
                syncingTempoCash = false;
            });
        }
    }
);

function isSerialDetail(d) {
    if (!serialEnabled.value) return false;
    return !!(d?.is_serial || d?.product?.is_serial || (d?.serial_unit_ids || []).length);
}

function findDuplicateLineIndex(productId, unit, exceptIndex) {
    return form.value.details.findIndex((d, i) => i !== exceptIndex && d.product_id === productId && d.unit_used === unit);
}

/** Gabung qty hanya saat ganti satuan bentrok (disengaja). */
function mergeDuplicateLine(index, { silent = false } = {}) {
    const detail = form.value.details[index];
    if (!detail?.product_id || !detail.unit_used) return false;
    if (isSerialDetail(detail)) return false;
    const other = findDuplicateLineIndex(detail.product_id, detail.unit_used, index);
    if (other < 0) return false;
    if (isSerialDetail(form.value.details[other])) return false;

    const keep = Math.min(index, other);
    const drop = Math.max(index, other);
    form.value.details[keep].qty_in_unit = (Number(form.value.details[keep].qty_in_unit) || 0) + (Number(form.value.details[drop].qty_in_unit) || 0);
    form.value.details.splice(drop, 1);
    if (!silent) notify.info('Produk + satuan sudah ada — qty digabung ke baris yang ada');
    calculateTotals();
    return true;
}

/** Draft lama: 2 baris serial sama produk → union SN ke baris pertama. */
function mergeSerialDuplicateLines() {
    const seen = new Map();
    const result = [];
    let merged = false;
    for (const d of form.value.details) {
        if (!isSerialDetail(d) || !d.product_id) {
            result.push(d);
            continue;
        }
        if (!seen.has(d.product_id)) {
            d.is_serial = true;
            d.serial_unit_ids = [...(d.serial_unit_ids || [])];
            seen.set(d.product_id, d);
            result.push(d);
        } else {
            merged = true;
            const keep = seen.get(d.product_id);
            const set = new Set(keep.serial_unit_ids || []);
            for (const id of d.serial_unit_ids || []) set.add(id);
            keep.serial_unit_ids = [...set];
            keep.qty_in_unit = set.size;
        }
    }
    if (merged) form.value.details = result;
    return merged;
}

const pickerVisible = ref(false);
const pickerQuery = ref('');
const pickerTargetIndex = ref(-1);

const pickerTakenKeys = computed(() =>
    buildTakenKeys(form.value.details, {
        exceptIndex: pickerTargetIndex.value >= 0 ? pickerTargetIndex.value : undefined,
        unitField: 'unit_used',
        isSerial: isSerialDetail
    })
);

function openProductPicker(index) {
    const row = form.value.details[index];
    pickerTargetIndex.value = index;
    pickerQuery.value = row?._searchQuery || row?.product?.kode_produk || row?.product?.nama_produk || '';
    pickerVisible.value = true;
}

async function fetchPickerProducts(q) {
    const response = await salesApi.getProducts({
        search: q,
        warehouse_id: form.value.warehouse_id || undefined
    });
    if (!response.data.success) return [];
    return response.data.data.items || [];
}

function getPickerUnitPrice(product, unitObj) {
    const v = unitObj?.harga_jual;
    if (v == null || v === '') return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
}

const pickerModeHint = computed(() =>
    'Mode penjualan BO: produk aktif, harga jual master per satuan. Serial = 1 baris/UNIT (stok gudang dipilih).'
);

function applyPickerSelect({ product, unit, konversi, unitObj, is_serial, price }) {
    const index = pickerTargetIndex.value;
    if (index < 0 || !product?.id) return;

    if (serialEnabled.value && is_serial) {
        const existingIdx = form.value.details.findIndex((d, i) => i !== index && d.product_id === product.id && isSerialDetail(d));
        if (existingIdx >= 0) {
            const keepUid = form.value.details[existingIdx]._uid;
            form.value.details.splice(index, 1);
            nextTick(() => {
                syncExpandedSerial();
                const rowNo = form.value.details.findIndex((d) => d._uid === keepUid) + 1;
                notify.info(`Produk serial sudah ada di baris ${rowNo} — pilih unit di situ`);
            });
            return;
        }

        form.value.details[index] = {
            ...form.value.details[index],
            _searchQuery: '',
            product_id: product.id,
            product,
            is_serial: true,
            serial_unit_ids: [],
            units: [{ unit: 'UNIT', konversi: 1 }],
            unit_used: 'UNIT',
            unit_konversi: 1,
            qty_in_unit: 0,
            harga_per_unit: Number(price) || 0
        };
        syncExpandedSerial();
        return;
    }

    const rawUnits = product.units || [];
    const seenUnits = new Set();
    const units = rawUnits.filter((u) => {
        if (seenUnits.has(u.unit)) return false;
        seenUnits.add(u.unit);
        return true;
    });

    form.value.details[index] = {
        ...form.value.details[index],
        _searchQuery: '',
        product_id: product.id,
        product,
        is_serial: false,
        serial_unit_ids: null,
        units: units.length ? units : [{ unit, konversi, harga_jual: price }],
        unit_used: unit,
        unit_konversi: konversi || 1,
        qty_in_unit: 1,
        harga_per_unit: Number(price ?? unitObj?.harga_jual) || 0
    };
    calculateTotals();
}

function onPickerTakenClick(row) {
    if (row.is_serial) {
        const idx = form.value.details.findIndex((d) => d.product_id === row.product.id && isSerialDetail(d));
        if (idx >= 0) notify.info(`Produk serial sudah ada di baris ${idx + 1} — pilih SN di situ`);
    } else {
        notify.info('Produk + satuan sudah ada di form — ubah qty di baris tersebut');
    }
}

function onUnitChange(index) {
    const detail = form.value.details[index];
    if (detail && detail.units) {
        const selectedUnit = detail.units.find((u) => u.unit === detail.unit_used);
        if (selectedUnit) {
            detail.unit_konversi = selectedUnit.konversi;
            detail.harga_per_unit = selectedUnit.harga_jual || 0;
        }
    }
    if (mergeDuplicateLine(index)) return;
    calculateTotals();
}

function detailRowClass(data) {
    const kind = productUnitRowHighlight(data, form.value.details, {
        unitField: 'unit_used',
        isSerial: isSerialDetail
    });
    if (kind === 'dup-unit') return 'row-dup-unit';
    if (kind === 'dup-product') return 'row-dup-product';
    return '';
}

function emptyDetail() {
    return {
        _uid: nextUid(),
        _searchQuery: '',
        product_id: null,
        product: null,
        is_serial: false,
        serial_unit_ids: null,
        unit_used: '',
        unit_konversi: 1,
        units: [],
        qty_in_unit: 1,
        harga_per_unit: 0,
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
        promo_id: null,
        nama_promo: null
    };
}

function addDetail() {
    if (!canAddLines.value) {
        notify.selectFirst('customer & gudang');
        return;
    }
    form.value.details.push(emptyDetail());
}

/** Sisip baris kosong di bawah baris `index`, fokus ke input cari produk. */
function insertDetailAfter(index) {
    if (!canAddLines.value) {
        notify.selectFirst('customer & gudang');
        return;
    }
    form.value.details.splice(index + 1, 0, emptyDetail());
    nextTick(() => {
        const inputs = document.querySelectorAll('.sales-detail-table .product-search-input');
        const el = inputs[index + 1];
        if (el && typeof el.focus === 'function') el.focus();
    });
}

function applyCalculationResult(calc) {
    const totals = calc?.totals || calc;
    calculated.value = {
        ...totals,
        total_diskon_header: totals?.total_diskon_header ?? totals?.total_diskon ?? 0
    };
    if (calc?.labels) {
        discLabels.value = calc.labels;
    }
    if (totals?.diskon_nota_1_tipe !== undefined) {
        form.value.diskon_1_tipe = totals.diskon_nota_1_tipe || 'none';
        form.value.diskon_1_nilai = totals.diskon_nota_1_nilai || 0;
        form.value.diskon_2_tipe = totals.diskon_nota_2_tipe || 'none';
        form.value.diskon_2_nilai = totals.diskon_nota_2_nilai || 0;
    }
}

async function getPromos() {
    if (!promoEnabled.value) {
        notify.warn('Fitur promo nonaktif di pengaturan.');
        return;
    }
    if (!form.value.customer_id) {
        notify.warn('Pilih customer dulu.');
        return;
    }
    if (form.value.details.length === 0 || form.value.details.every((d) => !d.product_id)) {
        notify.warn('Tambah produk dulu.');
        return;
    }
    if (form.value.details.some((d) => d.product_id && !isDetailReadyForCalc(d))) {
        notify.warn('Lengkapi unit serial / qty produk dulu.');
        return;
    }

    gettingPromo.value = true;
    try {
        const response = await salesApi.calculate({ ...buildPayload(), rebuild_promos: true });
        if (!response.data.success) return;
        const calc = response.data.data.calculation;
        applyCalculationResult(calc);
        const calcDetails = calc?.details || [];
        form.value.details.forEach((detail, i) => {
            const row = calcDetails[i];
            if (!row) return;
            for (let slot = 1; slot <= 4; slot++) {
                detail[`diskon_${slot}_tipe`] = row[`diskon_${slot}_tipe`] || 'none';
                detail[`diskon_${slot}_nilai`] = row[`diskon_${slot}_nilai`] || 0;
            }
            detail.promo_id = row.promo_id || null;
            detail.nama_promo = row.nama_promo || null;
        });
        notify.success('Promo diterapkan. Disc 1–4 terkunci; Disc 5 tetap manual.');
    } catch (error) {
        notify.apiError(error, 'Gagal get promo');
    } finally {
        gettingPromo.value = false;
    }
}

function removeDetail(index) {
    form.value.details.splice(index, 1);
    calculateTotals();
}

// Calculate totals via API
let calculateTimeout = null;
async function calculateTotals() {
    if (calculateTimeout) clearTimeout(calculateTimeout);

    calculateTimeout = setTimeout(async () => {
        if (form.value.details.length === 0) {
            calculated.value = {
                subtotal: 0,
                total_diskon_header: 0,
                total_setelah_diskon: 0,
                biaya_kirim_hasil: 0,
                biaya_lain_hasil: 0,
                total_biaya_tambahan: 0,
                dpp: 0,
                pajak_nominal: 0,
                total_sebelum_pembulatan: 0,
                pembulatan: 0,
                grand_total: 0
            };
            return;
        }

        try {
            const payload = buildPayload({ forCalc: true });
            if (!payload.details.length) {
                calculated.value = {
                    subtotal: 0,
                    total_diskon_header: 0,
                    total_setelah_diskon: 0,
                    biaya_kirim_hasil: 0,
                    biaya_lain_hasil: 0,
                    total_biaya_tambahan: 0,
                    dpp: 0,
                    pajak_nominal: 0,
                    total_sebelum_pembulatan: 0,
                    pembulatan: 0,
                    grand_total: 0
                };
                return;
            }
            const response = await salesApi.calculate(payload);
            if (response.data.success) {
                applyCalculationResult(response.data.data.calculation);
            }
        } catch (error) {
            console.error('Failed to calculate:', error);
            notify.apiError(error, 'Gagal calculate');
        }
    }, 500);
}

function isDetailReadyForCalc(d) {
    if (!d.product_id) return false;
    if (isSerialDetail(d)) {
        return (d.serial_unit_ids || []).length >= 1 && Number(d.qty_in_unit) > 0;
    }
    return Number(d.qty_in_unit) > 0;
}

function buildPayload({ forCalc = false } = {}) {
    let details = form.value.details;
    if (forCalc) {
        details = details.filter(isDetailReadyForCalc);
    }
    return {
        tanggal: toDateTimeString(form.value.tanggal_po),
        customer_id: form.value.customer_id,
        warehouse_id: form.value.warehouse_id,
        tempo_hari: form.value.tempo_hari,
        notes: form.value.notes || null,
        cash_payment: !!form.value.cash_payment,
        cash_metode: form.value.cash_payment ? form.value.cash_metode : null,
        cash_no_referensi: form.value.cash_payment ? form.value.cash_no_referensi || null : null,
        cash_bank_nama: form.value.cash_payment && form.value.cash_metode === 'transfer' ? form.value.cash_bank_nama || null : null,
        cash_bank_rekening: form.value.cash_payment && form.value.cash_metode === 'transfer' ? form.value.cash_bank_rekening || null : null,
        discounts: [
            { tipe: 'none', nilai: 0 },
            { tipe: 'none', nilai: 0 },
            { tipe: form.value.diskon_3_tipe || 'none', nilai: form.value.diskon_3_nilai || 0 }
        ],
        biaya_kirim: { tipe: form.value.biaya_kirim_tipe || 'none', nilai: form.value.biaya_kirim_nilai || 0 },
        biaya_lain: { tipe: form.value.biaya_lain_tipe || 'none', nilai: form.value.biaya_lain_nilai || 0 },
        biaya_lain_nama: form.value.biaya_lain_nama || null,
        details: details.map((d) => ({
            product_id: d.product_id,
            unit: d.unit_used,
            konversi: d.unit_konversi,
            qty: d.qty_in_unit,
            harga_satuan: d.harga_per_unit,
            diskon_1_tipe: d.diskon_1_tipe,
            diskon_1_nilai: d.diskon_1_nilai,
            diskon_2_tipe: d.diskon_2_tipe,
            diskon_2_nilai: d.diskon_2_nilai,
            diskon_3_tipe: d.diskon_3_tipe,
            diskon_3_nilai: d.diskon_3_nilai,
            diskon_4_tipe: d.diskon_4_tipe,
            diskon_4_nilai: d.diskon_4_nilai,
            diskon_5_tipe: d.diskon_5_tipe,
            diskon_5_nilai: d.diskon_5_nilai,
            serial_unit_ids: isSerialDetail(d) ? d.serial_unit_ids || [] : undefined
        }))
    };
}

function syncDetailProducts() {
    form.value.details = form.value.details.filter((d) => {
        if (d.product_id) return true;
        if (d.product && typeof d.product === 'object' && d.product.id) return true;
        if (typeof d.product === 'string' && d.product.trim()) return true;
        return false;
    });

    form.value.details.forEach((detail) => {
        if (detail.product_id || !detail.product?.id) return;
        detail.product_id = detail.product.id;
        if (!detail.units?.length) {
            const rawUnits = detail.product.units || [];
            const seen = new Set();
            detail.units = rawUnits.filter((u) => {
                if (seen.has(u.unit)) return false;
                seen.add(u.unit);
                return true;
            });
        }
        if (!detail.unit_used && detail.units?.[0]) {
            detail.unit_used = detail.units[0].unit;
            detail.unit_konversi = detail.units[0].konversi;
            detail.harga_per_unit = detail.units[0].harga_jual || detail.harga_per_unit || 0;
        }
    });
}

function validate() {
    errors.value = {};
    syncDetailProducts();
    if (mergeSerialDuplicateLines()) {
        notify.info('Baris serial duplikat digabung');
    }

    if (!form.value.customer_id) {
        errors.value.customer_id = 'Customer wajib dipilih';
    }
    if (!form.value.warehouse_id) {
        errors.value.warehouse_id = 'Warehouse wajib dipilih';
    }
    if (!form.value.tanggal_po) {
        errors.value.tanggal_po = 'Tanggal wajib diisi';
    }
    if (form.value.details.length === 0) {
        errors.value.details = 'Minimal harus ada 1 detail produk';
    }

    form.value.details.forEach((detail, index) => {
        if (!detail.product_id) {
            errors.value[`details.${index}.product_id`] = 'Produk wajib dipilih dari daftar';
        }
        if (!detail.unit_used) {
            errors.value[`details.${index}.unit_used`] = 'Satuan wajib dipilih';
        }
        if (isSerialDetail(detail)) {
            const snCount = (detail.serial_unit_ids || []).length;
            if (snCount < 1) {
                errors.value[`details.${index}.qty_in_unit`] = 'Pilih minimal 1 unit serial';
            } else if (Number(detail.qty_in_unit) !== snCount) {
                errors.value[`details.${index}.qty_in_unit`] = 'Qty harus sama dengan jumlah unit serial';
            }
        } else if (!detail.qty_in_unit || detail.qty_in_unit < 1) {
            errors.value[`details.${index}.qty_in_unit`] = 'Qty minimal 1';
        }
        if (detail.harga_per_unit < 0) {
            errors.value[`details.${index}.harga_per_unit`] = 'Harga tidak boleh negatif';
        }
    });

    for (const dup of findDuplicateProductUnitErrors(form.value.details, { unitField: 'unit_used', isSerial: isSerialDetail })) {
        errors.value[`details.${dup.index}.product_id`] = dup.message;
    }

    return Object.keys(errors.value).length === 0;
}

async function save() {
    if (!validate()) {
        const first = Object.values(errors.value).find((v) => typeof v === 'string');
        notify.formInvalid(first || 'Periksa kembali form Anda');
        return;
    }

    saving.value = true;
    try {
        const payload = buildPayload();

        let response;
        if (isEdit.value) {
            response = await salesApi.update(route.params.ulid, payload);
        } else {
            response = await salesApi.create(payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Penjualan', isEdit.value);
            router.push({ name: 'penjualan-sales' });
        }
    } catch (error) {
        console.error('Failed to save PO:', error);
        notify.saveError(error);

        if (error.response?.data?.errors) {
            errors.value = { ...errors.value, ...error.response.data.errors };
        }
    } finally {
        saving.value = false;
    }
}

function cancel() {
    router.push({ name: 'penjualan-sales' });
}

// Calculate item subtotal for display
function getItemSubtotal(detail) {
    const bruto = (detail.qty_in_unit || 0) * (detail.harga_per_unit || 0);
    const mode = calculationSettings.value?.discountMode || 'recursive';

    if (mode === 'sum') {
        // Sum all discounts and apply once
        let totalDiscountPercent = 0;
        let totalDiscountNominal = 0;

        for (let i = 1; i <= 5; i++) {
            const tipe = detail[`diskon_${i}_tipe`];
            const nilai = detail[`diskon_${i}_nilai`] || 0;
            if (tipe === 'percent' && nilai > 0) {
                totalDiscountPercent += nilai;
            } else if (tipe === 'nominal' && nilai > 0) {
                totalDiscountNominal += nilai;
            }
        }

        const discountFromPercent = bruto * (totalDiscountPercent / 100);
        const totalDiscount = discountFromPercent + totalDiscountNominal;
        return Math.max(0, bruto - totalDiscount);
    } else {
        // Recursive: apply each discount to the remaining amount
        let current = bruto;
        for (let i = 1; i <= 5; i++) {
            const tipe = detail[`diskon_${i}_tipe`];
            const nilai = detail[`diskon_${i}_nilai`] || 0;
            if (tipe === 'percent' && nilai > 0) {
                current -= current * (nilai / 100);
            } else if (tipe === 'nominal' && nilai > 0) {
                current -= Math.min(nilai, current);
            }
        }
        return Math.max(0, current);
    }
}

// Calculate total item discount (bruto - subtotal)
function getItemTotalDiscount(detail) {
    const bruto = (detail.qty_in_unit || 0) * (detail.harga_per_unit || 0);
    const subtotal = getItemSubtotal(detail);
    return bruto - subtotal;
}

// Format discount summary string (e.g., "1% + 2.000 + 5%")
function getDiscountSummary(detail) {
    const parts = [];
    for (let i = 1; i <= 5; i++) {
        const tipe = detail[`diskon_${i}_tipe`];
        const nilai = detail[`diskon_${i}_nilai`] || 0;
        if (tipe === 'percent' && nilai > 0) {
            parts.push(`${formatQty(nilai)}%`);
        } else if (tipe === 'nominal' && nilai > 0) {
            parts.push(formatCurrency(nilai));
        }
    }
    return parts.length > 0 ? parts.join(' + ') : '-';
}

// Check if detail has any discount
function hasDiscount(detail) {
    for (let i = 1; i <= 5; i++) {
        const tipe = detail[`diskon_${i}_tipe`];
        const nilai = detail[`diskon_${i}_nilai`] || 0;
        if (tipe !== 'none' && nilai > 0) {
            return true;
        }
    }
    return false;
}

// Open discount dialog
function openDiscountDialog(index) {
    editingDiscountIndex.value = index;
    discountDialog.value = true;
}

// Close discount dialog
function closeDiscountDialog() {
    discountDialog.value = false;
    editingDiscountIndex.value = null;
    calculateTotals();
}

// Check if discount can be edited (product selected, unit selected, qty > 0, harga > 0)
function canEditDiscount(data) {
    if (isSerialDetail(data)) return false;
    return data.product_id && data.unit_used && data.qty_in_unit > 0 && data.harga_per_unit > 0;
}

/** Opsi A: Disc 1–4 terkunci jika baris punya promo otomatis. */
function isPromoLockedSlot(detail, slot) {
    return !!(detail?.promo_id || detail?.nama_promo) && slot >= 1 && slot <= 4;
}

const editingDiscountDetail = computed(() =>
    editingDiscountIndex.value !== null ? form.value.details[editingDiscountIndex.value] : null
);

// Reset all discounts for a detail item
function resetDiscount(index) {
    const detail = form.value.details[index];
    for (let i = 1; i <= 5; i++) {
        detail[`diskon_${i}_tipe`] = 'none';
        detail[`diskon_${i}_nilai`] = 0;
    }
    detail.promo_id = null;
    detail.nama_promo = null;
    calculateTotals();
}

// Get max nominal value for a discount line
function getDiscountMaxNominal(detail, discIndex) {
    const bruto = (detail.qty_in_unit || 0) * (detail.harga_per_unit || 0);
    if (bruto <= 0) return 0;

    const mode = calculationSettings.value?.discountMode || 'recursive';

    if (mode === 'sum') {
        // Sum mode: max = bruto - total percent discount - other nominal discounts
        let totalPercentDiscount = 0;
        let totalOtherNominal = 0;

        for (let i = 1; i <= 5; i++) {
            const tipe = detail[`diskon_${i}_tipe`];
            const nilai = detail[`diskon_${i}_nilai`] || 0;

            if (tipe === 'percent' && nilai > 0) {
                totalPercentDiscount += bruto * (nilai / 100);
            } else if (tipe === 'nominal' && nilai > 0 && i !== discIndex) {
                // Sum other nominal discounts (not current one)
                totalOtherNominal += nilai;
            }
        }

        return Math.max(0, bruto - totalPercentDiscount - totalOtherNominal);
    } else {
        // Recursive mode: max = remaining subtotal after previous discounts
        let current = bruto;

        for (let i = 1; i < discIndex; i++) {
            const tipe = detail[`diskon_${i}_tipe`];
            const nilai = detail[`diskon_${i}_nilai`] || 0;

            if (tipe === 'percent' && nilai > 0) {
                current -= current * (nilai / 100);
            } else if (tipe === 'nominal' && nilai > 0) {
                current -= Math.min(nilai, current);
            }
        }

        return Math.max(0, current);
    }
}

// Handle discount value change with validation
function onDiscountValueChange(discIndex, newValue) {
    if (editingDiscountIndex.value === null) return;

    const detail = form.value.details[editingDiscountIndex.value];
    const tipe = detail[`diskon_${discIndex}_tipe`];
    let nilai = newValue || 0;
    let needsCorrection = false;

    // Clamp to min 0
    if (nilai < 0) {
        nilai = 0;
        needsCorrection = true;
    }

    // Clamp based on type
    if (tipe === 'percent') {
        // Max 100% for percent
        if (nilai > 100) {
            nilai = 100;
            needsCorrection = true;
        }
    } else if (tipe === 'nominal') {
        // Max is calculated based on mode
        const maxNominal = getDiscountMaxNominal(detail, discIndex);
        if (nilai > maxNominal) {
            nilai = maxNominal;
            needsCorrection = true;
        }
    }

    // Update value using nextTick to ensure reactivity works properly
    if (needsCorrection) {
        nextTick(() => {
            detail[`diskon_${discIndex}_nilai`] = nilai;
        });
    }

    calculateTotals();
}
</script>

<template>
    <div class="card">
        <!-- Header -->
        <div class="flex items-center gap-4 mb-6">
            <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="cancel" />
            <div>
                <h2 class="text-2xl font-semibold m-0">{{ pageTitle }}</h2>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex justify-center py-8">
            <ProgressSpinner />
        </div>

        <!-- Form -->
        <form v-else @submit.prevent="save">
            <Message severity="info" :closable="false" class="mb-4">
                Total mengikuti promo aktif saat simpan/approve.
            </Message>

            <!-- Header Fields -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <!-- Customer -->
                <div class="flex flex-col gap-2">
                    <label for="customer" class="font-medium">Customer <span class="text-red-500">*</span></label>
                    <Select id="customer" v-model="form.customer_id" :options="customers" optionLabel="nama" optionValue="id" placeholder="Pilih Customer" filter class="w-full" :class="{ 'p-invalid': errors.customer_id }" />
                    <small v-if="errors.customer_id" class="text-red-500">{{ errors.customer_id }}</small>
                </div>

                <!-- Warehouse -->
                <div class="flex flex-col gap-2">
                    <label for="warehouse" class="font-medium">Warehouse <span class="text-red-500">*</span></label>
                    <Select id="warehouse" v-model="form.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Pilih Warehouse" filter class="w-full" :class="{ 'p-invalid': errors.warehouse_id }" />
                    <small v-if="errors.warehouse_id" class="text-red-500">{{ errors.warehouse_id }}</small>
                </div>

                <!-- Tanggal -->
                <div class="flex flex-col gap-2">
                    <label for="tanggal" class="font-medium">Tanggal <span class="text-red-500">*</span></label>
                    <DatePicker id="tanggal" v-model="form.tanggal_po" :dateFormat="getPrimeDateFormatShort" class="w-full" :class="{ 'p-invalid': errors.tanggal_po }" showIcon showTime hourFormat="24" />
                    <small v-if="errors.tanggal_po" class="text-red-500">{{ errors.tanggal_po }}</small>
                </div>

                <!-- Tempo -->
                <div class="flex flex-col gap-2">
                    <label for="tempo" class="font-medium">Tempo (Hari)</label>
                    <InputNumber
                        v-select-on-focus
                        id="tempo"
                        v-model="form.tempo_hari"
                        :min="0"
                        class="w-full"
                        :disabled="form.cash_payment"
                    />
                    <small v-if="form.cash_payment" class="text-surface-400">Cash → tempo 0</small>
                </div>
            </div>

            <!-- Cash / Lunas langsung -->
            <div class="mb-6 p-3 rounded-lg border border-surface-200 dark:border-surface-700">
                <div class="flex items-center gap-2">
                    <Checkbox v-model="form.cash_payment" :binary="true" inputId="cash_payment" :disabled="!form.customer_id" />
                    <label for="cash_payment" class="font-medium cursor-pointer">Cash / Lunas langsung</label>
                    <small v-if="!form.customer_id" class="text-surface-400">(pilih customer dulu)</small>
                </div>
                <small class="block text-surface-500 mt-1">Cash ON = tempo 0. Tempo ≥ 1 = non-cash. Piutang tetap dibuat saat approve, lalu otomatis dilunasi penuh jika cash.</small>

                <div v-if="form.cash_payment" class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Metode Bayar</label>
                        <Select v-model="form.cash_metode" :options="cashMetodeOptions" optionLabel="label" optionValue="value" class="w-full" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">No. Referensi <span class="text-surface-400">(bukti/kwitansi)</span></label>
                        <InputText v-model="form.cash_no_referensi" placeholder="No. bukti/kwitansi" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" maxlength="50" />
                    </div>
                    <template v-if="form.cash_metode === 'transfer'">
                        <div class="flex flex-col gap-2">
                            <label class="font-medium">Nama Bank</label>
                            <InputText v-model="form.cash_bank_nama" class="w-full" maxlength="50" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <label class="font-medium">No. Rekening</label>
                            <InputText v-model="form.cash_bank_rekening" class="w-full" maxlength="30" />
                        </div>
                    </template>
                </div>
            </div>

            <!-- Detail Products Section -->
            <div class="border border-surface-200 rounded-lg p-4 mb-6">
                <div class="flex items-center justify-between mb-4 gap-2 flex-wrap">
                    <h3 class="text-lg font-medium m-0">Detail Produk</h3>
                    <div class="flex gap-2 flex-wrap">
                        <Button
                            v-if="promoEnabled"
                            label="Get Promo"
                            icon="pi pi-tag"
                            size="small"
                            severity="help"
                            outlined
                            :loading="gettingPromo"
                            :disabled="!form.customer_id || form.details.length === 0"
                            @click="getPromos"
                        />
                        <Button
                            label="Tambah Produk"
                            icon="pi pi-plus"
                            size="small"
                            @click="addDetail"
                            :disabled="!canAddLines"
                            v-tooltip.top="canAddLines ? null : 'Pilih customer & gudang dulu'"
                        />
                    </div>
                </div>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <DataTable
                    :value="form.details"
                    class="p-datatable-sm sales-detail-table"
                    responsiveLayout="scroll"
                    v-if="form.details.length > 0"
                    dataKey="_uid"
                    v-model:expandedRows="expandedRows"
                    :rowClass="detailRowClass"
                >
                    <Column expander style="width: 3rem" />
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="Produk" style="min-width: 250px">
                        <template #body="{ data, index }">
                            <div v-if="data.product_id && data.product" class="flex flex-col gap-1">
                                <span class="font-medium">{{ data.product?.kode_produk }}</span>
                                <span class="text-sm text-surface-500">{{ data.product?.nama_produk }}</span>
                                <span v-if="serialEnabled && data.is_serial" class="text-xs text-primary">Serial</span>
                                <Button
                                    v-if="!(serialEnabled && data.is_serial)"
                                    label="Ganti"
                                    icon="pi pi-search"
                                    size="small"
                                    text
                                    class="!p-0 !w-auto self-start"
                                    @click="openProductPicker(index)"
                                />
                            </div>
                            <div v-else class="flex gap-1">
                                <InputText
                                    v-model="data._searchQuery"
                                    class="w-full product-search-input"
                                    placeholder="Ketik lalu Enter…"
                                    :class="{ 'p-invalid': errors[`details.${index}.product_id`] }"
                                    @keydown.enter.prevent="openProductPicker(index)"
                                />
                                <Button icon="pi pi-search" size="small" @click="openProductPicker(index)" aria-label="Cari produk" />
                            </div>
                            <small v-if="errors[`details.${index}.product_id`]" class="text-red-500">{{ errors[`details.${index}.product_id`] }}</small>
                        </template>
                    </Column>

                    <Column header="Satuan" style="width: 140px">
                        <template #body="{ data, index }">
                            <span v-if="(serialEnabled && data.is_serial) || (data.units && data.units.length === 1)" class="font-medium"> {{ data.unit_used }} ({{ data.unit_konversi }}) </span>
                            <Select v-else v-model="data.unit_used" :options="data.units" optionValue="unit" placeholder="Satuan" class="w-full" :class="{ 'p-invalid': errors[`details.${index}.unit_used`] }" @change="onUnitChange(index)">
                                <template #value="{ value }">
                                    <span v-if="value"> {{ value }} ({{ data.units.find((u) => u.unit === value)?.konversi || 1 }}) </span>
                                    <span v-else class="text-surface-400">Satuan</span>
                                </template>
                                <template #option="{ option }"> {{ option.unit }} ({{ option.konversi }}) </template>
                            </Select>
                        </template>
                    </Column>

                    <Column header="Qty" style="width: 100px">
                        <template #body="{ data, index }">
                            <div v-if="serialEnabled && data.is_serial" class="flex flex-col gap-1">
                                <Tag :value="`${data.serial_unit_ids?.length || 0} unit`" severity="info" />
                                <div class="text-xs text-surface-500">dari pilih unit ↓</div>
                                <small v-if="errors[`details.${index}.qty_in_unit`]" class="text-red-500 text-xs">{{ errors[`details.${index}.qty_in_unit`] }}</small>
                            </div>
                            <div v-else>
                                <InputNumber
                                    v-select-on-focus
                                    v-model="data.qty_in_unit"
                                    :min="1"
                                    :minFractionDigits="getQtyMinFractionDigits"
                                    :maxFractionDigits="getQtyMaxFractionDigits"
                                    :locale="getLocale"
                                    class="w-full"
                                    :class="{ 'p-invalid': errors[`details.${index}.qty_in_unit`] }"
                                    @update:modelValue="calculateTotals"
                                />
                                <small v-if="errors[`details.${index}.qty_in_unit`]" class="text-red-500">{{ errors[`details.${index}.qty_in_unit`] }}</small>
                            </div>
                        </template>
                    </Column>

                    <Column header="Harga/Unit" style="width: 150px">
                        <template #body="{ data }">
                            <div v-if="serialEnabled && data.is_serial">
                                <span class="font-medium">{{ formatCurrency(data.harga_per_unit) }}</span>
                                <div class="text-xs text-surface-500">rata-rata jual</div>
                            </div>
                            <InputNumber
                                v-else
                                v-select-on-focus
                                v-model="data.harga_per_unit"
                                :min="0"
                                :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                :locale="getLocale"
                                :minFractionDigits="getCurrencyMinFractionDigits"
                                :maxFractionDigits="getCurrencyMaxFractionDigits"
                                class="w-full"
                                @update:modelValue="calculateTotals"
                            />
                        </template>
                    </Column>

                    <Column header="Diskon" style="width: 160px">
                        <template #body="{ data, index }">
                            <span v-if="serialEnabled && data.is_serial" class="text-surface-400 text-xs">—</span>
                            <div v-else-if="hasDiscount(data)" class="flex items-center gap-1">
                                <div
                                    :class="['flex-1 rounded p-2 -m-2 transition-colors text-center', canEditDiscount(data) ? 'cursor-pointer hover:bg-surface-100' : 'opacity-40 cursor-not-allowed']"
                                    @click="canEditDiscount(data) && openDiscountDialog(index)"
                                >
                                    <div class="font-medium text-red-500">-{{ formatCurrency(getItemTotalDiscount(data)) }}</div>
                                    <div class="text-xs text-surface-500 mt-1 truncate" :title="getDiscountSummary(data)">
                                        {{ getDiscountSummary(data) }}
                                    </div>
                                </div>
                                <button type="button" class="p-1 rounded hover:bg-surface-200 text-surface-400 hover:text-red-500 transition-colors" @click.stop="resetDiscount(index)" title="Hapus diskon">
                                    <i class="pi pi-times text-xs"></i>
                                </button>
                            </div>
                            <div
                                v-else
                                :class="['rounded p-2 -m-2 transition-colors text-center', canEditDiscount(data) ? 'cursor-pointer hover:bg-surface-100' : 'opacity-40 cursor-not-allowed']"
                                @click="canEditDiscount(data) && openDiscountDialog(index)"
                            >
                                <div class="text-surface-400">
                                    <i class="pi pi-plus-circle"></i>
                                    <span class="text-xs ml-1">Diskon</span>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <Column header="Subtotal" style="width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-medium">{{ formatCurrency(getItemSubtotal(data)) }}</span>
                        </template>
                    </Column>

                    <Column header="" style="width: 90px">
                        <template #body="{ index }">
                            <div class="flex items-center justify-end gap-0">
                                <Button
                                    icon="pi pi-plus"
                                    severity="secondary"
                                    text
                                    rounded
                                    :disabled="!canAddLines"
                                    v-tooltip.top="canAddLines ? 'Tambah baris di bawah' : 'Pilih customer & gudang dulu'"
                                    @click="insertDetailAfter(index)"
                                />
                                <Button icon="pi pi-trash" severity="danger" text rounded v-tooltip.top="'Hapus baris'" @click="removeDetail(index)" />
                            </div>
                        </template>
                    </Column>

                    <template #expansion="{ data }">
                        <div v-if="serialEnabled && data.is_serial && data.product_id" class="px-4 py-3 bg-surface-50 dark:bg-surface-800">
                            <div class="text-sm font-medium mb-2">Pilih unit serial (tersedia di gudang)</div>
                            <SerialUnitPicker
                                :key="data._uid"
                                :productId="data.product?.ulid"
                                :warehouseId="form.warehouse_id"
                                :modelValue="data.serial_unit_ids"
                                :showSell="true"
                                @change="(units) => onSerialChange(data, units)"
                            />
                        </div>
                    </template>
                </DataTable>

                <div v-else class="text-center py-8 text-surface-500">
                    <i class="pi pi-box text-4xl mb-4 block"></i>
                    <p class="m-0">Belum ada detail produk. Klik "Tambah Produk" untuk menambahkan.</p>
                </div>
            </div>

            <!-- Bottom Section: Costs & Totals -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Left: Discounts & Costs -->
                <div class="space-y-4">
                    <!-- Header Discounts -->
                    <div class="border border-surface-200 rounded-lg p-4">
                        <h4 class="font-medium mb-4">Diskon Header</h4>
                        <div class="space-y-3">
                            <div v-for="i in 3" :key="i" class="flex gap-2 items-center">
                                <label class="w-24 text-sm shrink-0">
                                    {{ i <= 2 && discLabels[i - 1] ? discLabels[i - 1] : `Diskon ${i}` }}
                                </label>
                                <Select
                                    v-model="form[`diskon_${i}_tipe`]"
                                    :options="tipeOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    class="w-32"
                                    :disabled="i <= 2"
                                    @change="calculateTotals"
                                />
                                <InputNumber
                                    v-select-on-focus
                                    v-if="form[`diskon_${i}_tipe`] !== 'none'"
                                    v-model="form[`diskon_${i}_nilai`]"
                                    :min="0"
                                    :disabled="i <= 2"
                                    :prefix="form[`diskon_${i}_tipe`] === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="form[`diskon_${i}_tipe`] === 'percent' ? '%' : form[`diskon_${i}_tipe`] === 'nominal' && currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :locale="getLocale"
                                    :minFractionDigits="form[`diskon_${i}_tipe`] === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                                    :maxFractionDigits="form[`diskon_${i}_tipe`] === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                                    class="flex-1"
                                    @update:modelValue="calculateTotals"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Additional Costs -->
                    <div class="border border-surface-200 rounded-lg p-4">
                        <h4 class="font-medium mb-4">Biaya Tambahan</h4>
                        <div class="space-y-3">
                            <div class="flex gap-2 items-center">
                                <label class="w-28 text-sm">Biaya Kirim</label>
                                <Select v-model="form.biaya_kirim_tipe" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-36" @change="calculateTotals" />
                                <InputNumber
                                    v-select-on-focus
                                    v-if="form.biaya_kirim_tipe !== 'none'"
                                    v-model="form.biaya_kirim_nilai"
                                    :min="0"
                                    :prefix="form.biaya_kirim_tipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="form.biaya_kirim_tipe === 'percent' ? '%' : form.biaya_kirim_tipe === 'nominal' && currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :locale="getLocale"
                                    :minFractionDigits="form.biaya_kirim_tipe === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                                    :maxFractionDigits="form.biaya_kirim_tipe === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                                    class="flex-1"
                                    @update:modelValue="calculateTotals"
                                />
                            </div>
                            <div class="flex gap-2 items-center">
                                <InputText v-model="form.biaya_lain_nama" placeholder="Nama Biaya Lain" class="w-40" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                                <Select v-model="form.biaya_lain_tipe" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-36" @change="calculateTotals" />
                                <InputNumber
                                    v-select-on-focus
                                    v-if="form.biaya_lain_tipe !== 'none'"
                                    v-model="form.biaya_lain_nilai"
                                    :min="0"
                                    :prefix="form.biaya_lain_tipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="form.biaya_lain_tipe === 'percent' ? '%' : form.biaya_lain_tipe === 'nominal' && currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :locale="getLocale"
                                    :minFractionDigits="form.biaya_lain_tipe === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                                    :maxFractionDigits="form.biaya_lain_tipe === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                                    class="flex-1"
                                    @update:modelValue="calculateTotals"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="flex flex-col gap-2">
                        <label for="notes" class="font-medium">Catatan</label>
                        <Textarea id="notes" v-model="form.notes" rows="2" class="w-full" placeholder="Catatan untuk penjualan ini..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                    </div>
                </div>

                <!-- Right: Totals -->
                <div class="border border-surface-200 rounded-lg p-4">
                    <h4 class="font-medium mb-4">Ringkasan</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-surface-600">Subtotal</span>
                            <span class="font-medium">{{ formatCurrency(calculated.subtotal) }}</span>
                        </div>
                        <div v-if="calculated.total_diskon_header > 0" class="flex justify-between text-red-500">
                            <span>Diskon Header</span>
                            <span>-{{ formatCurrency(calculated.total_diskon_header) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-surface-600">Setelah Diskon</span>
                            <span>{{ formatCurrency(calculated.total_setelah_diskon) }}</span>
                        </div>
                        <div v-if="calculated.biaya_kirim_hasil > 0" class="flex justify-between">
                            <span class="text-surface-600">Biaya Kirim</span>
                            <span>{{ formatCurrency(calculated.biaya_kirim_hasil) }}</span>
                        </div>
                        <div v-if="calculated.biaya_lain_hasil > 0" class="flex justify-between">
                            <span class="text-surface-600">{{ form.biaya_lain_nama || 'Biaya Lain' }}</span>
                            <span>{{ formatCurrency(calculated.biaya_lain_hasil) }}</span>
                        </div>
                        <Divider />
                        <div class="flex justify-between">
                            <span class="text-surface-600">DPP</span>
                            <span>{{ formatCurrency(calculated.dpp) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-surface-600">{{ taxSettings.name }} ({{ taxSettings.percent }}%)</span>
                            <span>{{ formatCurrency(calculated.pajak_nominal) }}</span>
                        </div>
                        <div v-if="taxSettings.included_in_hpp" class="text-xs text-surface-500">* Pajak termasuk dalam HPP</div>
                        <div v-if="calculated.pembulatan !== 0" class="flex justify-between">
                            <span class="text-surface-600">Pembulatan</span>
                            <span :class="calculated.pembulatan > 0 ? 'text-green-600' : 'text-red-500'"> {{ calculated.pembulatan > 0 ? '+' : '' }}{{ formatCurrency(calculated.pembulatan) }} </span>
                        </div>
                        <Divider />
                        <div class="flex justify-between text-xl font-bold">
                            <span>Grand Total</span>
                            <span class="text-primary">{{ formatCurrency(calculated.grand_total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex flex-wrap justify-end gap-2">
                <Button label="Batal" severity="secondary" outlined @click="cancel" />
                <Button label="Simpan" icon="pi pi-save" type="submit" :loading="saving" :disabled="form.details.length === 0" />
            </div>
        </form>

        <!-- Discount Dialog -->
        <Dialog v-model:visible="discountDialog" header="Diskon Item" modal :style="{ width: 'min(420px, 95vw)' }" :closable="true" @hide="closeDiscountDialog">
            <template v-if="editingDiscountDetail">
                <div class="space-y-4">
                    <!-- Discount mode info -->
                    <div class="flex items-center gap-2 text-sm">
                        <i class="pi pi-info-circle text-primary"></i>
                        <span class="text-surface-600">Mode Perhitungan:</span>
                        <span class="font-medium">{{ discountModeLabel }}</span>
                    </div>

                    <!-- Product info -->
                    <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3">
                        <div class="font-medium">{{ editingDiscountDetail.product?.nama_produk || '-' }}</div>
                        <div class="text-sm text-surface-500">
                            {{ formatQty(editingDiscountDetail.qty_in_unit) }} {{ editingDiscountDetail.unit_used }} × {{ formatCurrency(editingDiscountDetail.harga_per_unit) }} =
                            {{ formatCurrency((editingDiscountDetail.qty_in_unit || 0) * (editingDiscountDetail.harga_per_unit || 0)) }}
                        </div>
                    </div>

                    <!-- Promo otomatis (opsi A: Disc 1–4 terkunci) -->
                    <div
                        v-if="editingDiscountDetail.nama_promo"
                        class="rounded-lg border border-primary/30 bg-primary/5 p-3"
                    >
                        <div class="flex items-center gap-2 font-medium">
                            <i class="pi pi-tag text-primary"></i>
                            <span>{{ editingDiscountDetail.nama_promo }}</span>
                        </div>
                        <small class="block text-surface-500 mt-1">Disc 1–4 terkunci dari promo. Disc 5 tetap bisa diisi manual.</small>
                    </div>

                    <!-- Discount lines -->
                    <div v-for="i in 5" :key="i" class="space-y-1">
                        <div class="flex items-center gap-3">
                            <label class="w-16 text-sm font-medium text-surface-600">Disc {{ i }}</label>
                            <Select
                                v-model="editingDiscountDetail[`diskon_${i}_tipe`]"
                                :options="tipeOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-32"
                                :disabled="isPromoLockedSlot(editingDiscountDetail, i)"
                                @change="calculateTotals"
                            />
                            <InputNumber
                                v-select-on-focus
                                v-if="editingDiscountDetail[`diskon_${i}_tipe`] !== 'none'"
                                v-model="editingDiscountDetail[`diskon_${i}_nilai`]"
                                :min="0"
                                :disabled="isPromoLockedSlot(editingDiscountDetail, i)"
                                :prefix="editingDiscountDetail[`diskon_${i}_tipe`] === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                :suffix="
                                    editingDiscountDetail[`diskon_${i}_tipe`] === 'percent'
                                        ? '%'
                                        : editingDiscountDetail[`diskon_${i}_tipe`] === 'nominal' && currencySettings.position === 'after'
                                          ? ' ' + currencySettings.symbol
                                          : ''
                                "
                                :locale="getLocale"
                                :minFractionDigits="editingDiscountDetail[`diskon_${i}_tipe`] === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                                :maxFractionDigits="editingDiscountDetail[`diskon_${i}_tipe`] === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                                class="flex-1"
                                @update:modelValue="(val) => onDiscountValueChange(i, val)"
                            />
                            <div v-else class="flex-1"></div>
                        </div>
                        <div v-if="editingDiscountDetail[`diskon_${i}_tipe`] === 'nominal'" class="text-xs text-surface-400 pl-19 ml-16">
                            Max: {{ formatCurrency(getDiscountMaxNominal(editingDiscountDetail, i)) }}
                        </div>
                    </div>

                    <!-- Summary -->
                    <Divider />
                    <div class="flex justify-between items-center">
                        <span class="text-surface-600">Total Diskon:</span>
                        <span class="text-xl font-bold text-red-500"> -{{ formatCurrency(getItemTotalDiscount(editingDiscountDetail)) }} </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-surface-600">Subtotal:</span>
                        <span class="text-xl font-bold text-primary">
                            {{ formatCurrency(getItemSubtotal(editingDiscountDetail)) }}
                        </span>
                    </div>
                </div>
            </template>

            <template #footer>
                <Button label="Tutup" severity="secondary" @click="closeDiscountDialog" />
            </template>
        </Dialog>

        <ProductUnitPickerDrawer
            v-model:visible="pickerVisible"
            :query="pickerQuery"
            title="Pilih Produk"
            :fetch-products="fetchPickerProducts"
            :get-unit-price="getPickerUnitPrice"
            :taken-keys="pickerTakenKeys"
            :include-serial="serialEnabled"
            :format-price="formatCurrency"
            :mode-hint="pickerModeHint"
            @select="applyPickerSelect"
            @taken-click="onPickerTakenClick"
        />
    </div>
</template>

<style scoped>
:deep(.row-dup-product) {
    background: color-mix(in srgb, var(--p-orange-100, #ffedd5) 55%, transparent) !important;
}
:deep(.row-dup-unit) {
    background: color-mix(in srgb, var(--p-red-100, #fee2e2) 65%, transparent) !important;
}
.app-dark :deep(.row-dup-product) {
    background: color-mix(in srgb, var(--p-orange-900, #7c2d12) 35%, transparent) !important;
}
.app-dark :deep(.row-dup-unit) {
    background: color-mix(in srgb, var(--p-red-900, #7f1d1d) 40%, transparent) !important;
}
</style>
