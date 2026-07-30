<script setup>
/**
 * Retur Penjualan BO — cascade Customer → Gudang → Nota (opsional).
 * Mode dokumen: harga efektif terkunci. Mode bebas: tambah produk + harga editable.
 */
import { salesReturnsApi, customersApi, warehousesApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch, nextTick } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useSettingsStore } from '@/stores/settings';
import SerialUnitPicker from '@/components/common/SerialUnitPicker.vue';
import ProductUnitPickerDrawer from '@/components/common/ProductUnitPickerDrawer.vue';
import { buildTakenKeys, findDuplicateProductUnitErrors, productUnitRowHighlight } from '@/utils/productUnitLineHelpers';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
const settingsStore = useSettingsStore();
const salesAllowFree = computed(() => settingsStore.returns.salesAllowFree);
const salesFreeRequireSold = computed(() => settingsStore.returns.salesFreeRequireSold);
const {
    formatQty,
    formatCurrency,
    shouldUppercase,
    getPrimeDateFormatShort,
    toDateTimeString,
    now,
    parseDateTime,
    getQtyMinFractionDigits,
    getQtyMaxFractionDigits,
    getCurrencyMinFractionDigits,
    getCurrencyMaxFractionDigits,
    currencySettings,
    getLocale,
    roundSales
} = useFormatters();

const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Retur Penjualan' : 'Buat Retur Penjualan'));
const isLinked = computed(() => !!form.value.sales_id);

const customers = ref([]);
const warehouses = ref([]);
const returnableSales = ref([]);
const loadingSales = ref(false);
const loading = ref(false);
const saving = ref(false);
const loadingDetails = ref(false);
const errors = ref({});
const expandedRows = ref({});
const returnableMessage = ref('');

const form = ref({
    tanggal: now(),
    customer_id: null,
    warehouse_id: null,
    sales_id: null,
    sales_ulid: null,
    notes: '',
    details: []
});

let uidSeq = 0;
let skipCascadeClear = false;
function nextUid() {
    return `sr-${++uidSeq}`;
}

onMounted(async () => {
    await Promise.all([loadCustomers(), loadWarehouses()]);
    if (isEdit.value) await loadReturn();
});

async function loadCustomers() {
    try {
        const res = await customersApi.getList({ jenis: 'spesifik' });
        if (res.data.success) customers.value = res.data.data.customers || [];
    } catch (e) {
        notify.apiError(e, 'Gagal load customer');
    }
}

async function loadWarehouses() {
    try {
        const res = await warehousesApi.getList();
        if (res.data.success) warehouses.value = res.data.data.warehouses || [];
    } catch (e) {
        notify.apiError(e, 'Gagal load gudang');
    }
}

async function loadReturnableSales() {
    if (!form.value.customer_id || !form.value.warehouse_id) {
        returnableSales.value = [];
        return;
    }
    loadingSales.value = true;
    try {
        const res = await salesReturnsApi.getReturnableSales({
            customer_id: form.value.customer_id,
            warehouse_id: form.value.warehouse_id
        });
        if (res.data.success) returnableSales.value = res.data.data.items || [];
    } catch (e) {
        notify.apiError(e, 'Gagal load penjualan returnable');
        returnableSales.value = [];
    } finally {
        loadingSales.value = false;
    }
}

watch(
    () => [form.value.customer_id, form.value.warehouse_id],
    async () => {
        if (loading.value || skipCascadeClear) return;
        form.value.sales_id = null;
        form.value.sales_ulid = null;
        form.value.details = [];
        returnableMessage.value = '';
        await loadReturnableSales();
    }
);

watch(
    () => form.value.sales_id,
    async (id) => {
        if (loading.value || isEdit.value) return;
        if (!id) {
            form.value.sales_ulid = null;
            form.value.details = [];
            returnableMessage.value = '';
            return;
        }
        const sale = returnableSales.value.find((s) => s.id === id);
        if (!sale) return;
        form.value.sales_ulid = sale.ulid;
        await loadReturnableDetails(sale.ulid);
    }
);

function mapReturnableRow(d) {
    const harga = Number(d.harga_efektif ?? d.harga_satuan ?? 0);
    return {
        _uid: nextUid(),
        sales_detail_id: d.id,
        product_id: d.product_id,
        product: d.product,
        unit: d.unit,
        returnable_base: d.returnable_base,
        qty_base: Number(d.returnable_base || 0),
        harga_satuan: harga,
        is_serial: !!d.product?.is_serial || !!(d.serial_unit_ids && d.serial_unit_ids.length),
        serial_unit_ids: [],
        returnable_units: d.returnable_units || []
    };
}

function lineSubtotal(row) {
    return Math.round(Number(row.qty_base || 0) * Number(row.harga_satuan || 0) * 100) / 100;
}

async function loadReturnableDetails(salesUlid) {
    loadingDetails.value = true;
    returnableMessage.value = '';
    try {
        const res = await salesReturnsApi.getReturnableDetails(salesUlid);
        if (!res.data.success) return;
        const payload = res.data.data;
        const sales = payload.sales || payload;
        const details = sales.details || [];
        if (payload.message && details.length === 0) {
            returnableMessage.value = payload.message;
            form.value.details = [];
            notify.warn('Info', payload.message);
            return;
        }
        form.value.details = details.map(mapReturnableRow);
        if (sales.customer_id) form.value.customer_id = sales.customer_id;
        if (sales.warehouse_id) form.value.warehouse_id = sales.warehouse_id;
    } catch (e) {
        notify.apiError(e, 'Gagal load detail returnable');
        form.value.details = [];
    } finally {
        loadingDetails.value = false;
    }
}

async function loadReturn() {
    loading.value = true;
    skipCascadeClear = true;
    try {
        const res = await salesReturnsApi.get(route.params.ulid);
        if (!res.data.success) return;
        const r = res.data.data.sales_return;
        if (r.status !== 'draft') {
            notify.cannotEdit('Retur yang sudah dikunci/disetujui');
            router.push({ name: 'penjualan-retur' });
            return;
        }
        form.value = {
            tanggal: parseDateTime(r.tanggal),
            customer_id: r.customer_id || r.customer?.id,
            warehouse_id: r.warehouse_id || r.warehouse?.id,
            sales_id: r.sales_id || r.sales?.id || null,
            sales_ulid: r.sales?.ulid || null,
            notes: r.notes || '',
            details: (r.details || []).map((d) => ({
                _uid: nextUid(),
                sales_detail_id: d.sales_detail_id,
                product_id: d.product_id,
                product: d.product,
                unit: d.unit,
                qty_base: Number(d.qty_base),
                returnable_base: Number(d.qty_base),
                harga_satuan: Number(d.harga_satuan || 0),
                is_serial: !!d.product?.is_serial || !!(d.serial_unit_ids && d.serial_unit_ids.length),
                serial_unit_ids: d.serial_unit_ids || []
            }))
        };
        await loadReturnableSales();
        if (r.sales?.ulid) {
            try {
                const det = await salesReturnsApi.getReturnableDetails(r.sales.ulid);
                const map = new Map((det.data.data.sales?.details || []).map((x) => [x.id, x]));
                form.value.details = form.value.details.map((row) => {
                    const src = map.get(row.sales_detail_id);
                    if (!src) return row;
                    return {
                        ...row,
                        returnable_base: (src.returnable_base || 0) + Number(row.qty_base || 0),
                        harga_satuan: Number(src.harga_efektif ?? row.harga_satuan ?? 0)
                    };
                });
            } catch {
                /* ignore */
            }
        }
    } catch (e) {
        notify.apiError(e, 'Gagal load retur');
        router.push({ name: 'penjualan-retur' });
    } finally {
        loading.value = false;
        await nextTick();
        skipCascadeClear = false;
    }
}

function isSerialDetail(d) {
    return !!(d?.is_serial || d?.product?.is_serial || (d?.serial_unit_ids || []).length);
}

function addDetail() {
    form.value.details.push({
        _uid: nextUid(),
        _searchQuery: '',
        sales_detail_id: null,
        product_id: null,
        product: null,
        unit: 'PCS',
        units: [],
        returnable_base: null,
        qty_base: 1,
        harga_satuan: 0,
        is_serial: false,
        serial_unit_ids: []
    });
}

function removeDetail(index) {
    form.value.details.splice(index, 1);
}

// Product picker drawer (free mode only — linked/nota lines keep product as text)
const pickerVisible = ref(false);
const pickerQuery = ref('');
const pickerTargetIndex = ref(-1);

const pickerTakenKeys = computed(() =>
    buildTakenKeys(form.value.details, {
        exceptIndex: pickerTargetIndex.value >= 0 ? pickerTargetIndex.value : undefined,
        unitField: 'unit',
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
    if (!form.value.customer_id || !form.value.warehouse_id) return [];
    const res = await salesReturnsApi.getReturnableProducts({
        search: q,
        customer_id: form.value.customer_id,
        warehouse_id: form.value.warehouse_id
    });
    if (!res.data.success) return [];
    return res.data.data.items || [];
}

function getPickerUnitPrice(product, unitObj) {
    const v = unitObj?.harga_jual;
    if (v == null || v === '') return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
}

const pickerModeHint = computed(() => {
    if (isLinked.value) {
        return 'Mode dokumen: produk dari nota; harga alokasi (bukan edit di picker).';
    }
    if (!salesAllowFree.value) {
        return 'Mode bebas dimatikan — pilih nota penjualan dulu.';
    }
    const hist = salesFreeRequireSold.value
        ? 'Non-serial hanya yang pernah terjual ke customer/gudang ini.'
        : 'Non-serial boleh tanpa histori jual.';
    return `Mode bebas: harga editable. ${hist} Serial tetap wajib SN terjual.`;
});

function applyPickerSelect({ product, unit, konversi, unitObj, is_serial, price }) {
    const index = pickerTargetIndex.value;
    if (index < 0 || !product?.id) return;
    const prevRow = form.value.details[index];
    if (!prevRow) return;

    const rawUnits = product.units || [];
    const seenUnits = new Set();
    const units = rawUnits.filter((u) => {
        if (seenUnits.has(u.unit)) return false;
        seenUnits.add(u.unit);
        return true;
    });
    const selectedPrice = Number(price ?? unitObj?.harga_jual) || 0;
    const isSerial = !!is_serial;
    const nextRow = {
        ...prevRow,
        _searchQuery: '',
        product,
        product_id: product.id,
        is_serial: !!isSerial,
        serial_unit_ids: [],
        units: isSerial ? [{ unit: 'UNIT', konversi: 1, harga_jual: Number(price) || 0 }] : units.length ? units : [{ unit, konversi, harga_jual: price }],
        unit: isSerial ? 'UNIT' : unit,
        harga_satuan: isSerial ? Number(price) || 0 : selectedPrice
    };
    form.value.details[index] = nextRow;
    if (isSerial) {
        expandedRows.value = { ...expandedRows.value, [nextRow._uid]: true };
    }
}

function onPickerTakenClick(row) {
    if (row.is_serial) {
        notify.info('Produk serial sudah ada di form — ubah SN di baris tersebut');
    } else {
        notify.info('Produk + satuan sudah ada di form — ubah qty di baris tersebut');
    }
}

function detailRowClass(data) {
    const kind = productUnitRowHighlight(data, form.value.details, {
        unitField: 'unit',
        isSerial: isSerialDetail
    });
    if (kind === 'dup-unit') return 'row-dup-unit';
    if (kind === 'dup-product') return 'row-dup-product';
    return '';
}

function onUnitChange(row) {
    const selected = (row.units || []).find((u) => u.unit === row.unit);
    if (selected) {
        row.harga_satuan = Number(selected.harga_jual || 0);
    }
}

function onSerialChange(row, units) {
    row.serial_unit_ids = (units || []).map((u) => u.ulid);
    row.qty_base = row.serial_unit_ids.length;
    if (units?.length) {
        const withPrice = units.filter((u) => Number(u.harga_jual) > 0);
        if (withPrice.length) {
            row.harga_satuan = withPrice.reduce((s, u) => s + Number(u.harga_jual || 0), 0) / withPrice.length;
        }
    }
}

function buildPayload() {
    return {
        tanggal: toDateTimeString(form.value.tanggal),
        sales_id: form.value.sales_id || null,
        customer_id: form.value.customer_id,
        warehouse_id: form.value.warehouse_id,
        notes: form.value.notes || null,
        details: form.value.details
            .filter((d) => Number(d.qty_base) > 0 && d.product_id)
            .map((d) => ({
                sales_detail_id: d.sales_detail_id || undefined,
                product_id: d.product_id,
                qty_base: Number(d.qty_base),
                harga_satuan: Number(d.harga_satuan || 0),
                unit: d.unit,
                serial_unit_ids: d.is_serial ? d.serial_unit_ids || [] : undefined
            }))
    };
}

function validate() {
    errors.value = {};
    if (!form.value.customer_id) errors.value.customer_id = 'Customer wajib';
    if (!form.value.warehouse_id) errors.value.warehouse_id = 'Gudang wajib';
    if (!form.value.tanggal) errors.value.tanggal = 'Tanggal wajib';
    if (!salesAllowFree.value && !form.value.sales_id) {
        errors.value.sales_id = 'Nota penjualan wajib (mode bebas dimatikan)';
    }
    const lines = form.value.details.filter((d) => Number(d.qty_base) > 0 && d.product_id);
    if (!lines.length) errors.value.details = 'Minimal satu item dengan qty > 0';
    form.value.details.forEach((d, i) => {
        if (!d.product_id) return;
        if (isLinked.value && d.returnable_base != null && Number(d.qty_base) > Number(d.returnable_base)) {
            errors.value[`details.${i}.qty_base`] = 'Melebihi qty returnable';
        }
        if (d.is_serial && Number(d.qty_base) > 0 && (d.serial_unit_ids || []).length !== Number(d.qty_base)) {
            errors.value[`details.${i}.serial`] = 'Qty serial harus sama dengan jumlah SN';
        }
        if (!isLinked.value && (d.harga_satuan == null || d.harga_satuan < 0)) {
            errors.value[`details.${i}.harga_satuan`] = 'Harga wajib';
        }
    });

    for (const dup of findDuplicateProductUnitErrors(form.value.details, { unitField: 'unit', isSerial: isSerialDetail })) {
        errors.value[`details.${dup.index}.product_id`] = dup.message;
    }

    return Object.keys(errors.value).length === 0;
}

async function save() {
    if (!validate()) {
        notify.formInvalid();
        return;
    }
    saving.value = true;
    try {
        const payload = buildPayload();
        const res = isEdit.value ? await salesReturnsApi.update(route.params.ulid, payload) : await salesReturnsApi.create(payload);
        if (res.data.success) {
            notify.saveSuccess('Retur penjualan', isEdit.value);
            router.push({ name: 'penjualan-retur' });
        }
    } catch (e) {
        notify.apiError(e, 'Gagal simpan retur');
    } finally {
        saving.value = false;
    }
}

const calculated = computed(() => {
    const lines = form.value.details.filter((d) => Number(d.qty_base) > 0);
    const subtotal = Math.round(lines.reduce((s, d) => s + lineSubtotal(d), 0) * 100) / 100;
    const grandTotal = roundSales(subtotal);
    return {
        qty_total: lines.reduce((s, d) => s + Number(d.qty_base || 0), 0),
        subtotal,
        pembulatan: Math.round((grandTotal - subtotal) * 100) / 100,
        grand_total: grandTotal
    };
});

function goBack() {
    router.push({ name: 'penjualan-retur' });
}
</script>

<template>
    <div class="card">
        <div class="flex items-center gap-4 mb-6">
            <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="goBack" />
            <div>
                <h2 class="text-2xl font-semibold m-0">{{ pageTitle }}</h2>
                <small class="text-surface-500">{{ isLinked ? 'Mode dokumen — harga alokasi seperti retur POS (tanpa biaya/pembulatan nota)' : salesAllowFree ? 'Mode bebas — default harga jual sesuai satuan; boleh diubah' : 'Wajib pilih nota penjualan' }}</small>
            </div>
        </div>

        <div v-if="loading" class="text-center py-8"><ProgressSpinner /></div>
        <template v-else>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div>
                    <label class="font-medium">Customer <span class="text-red-500">*</span></label>
                    <Select
                        v-model="form.customer_id"
                        :options="customers"
                        optionLabel="nama"
                        optionValue="id"
                        placeholder="Pilih customer"
                        filter
                        class="w-full"
                        :disabled="isEdit && isLinked"
                        :class="{ 'p-invalid': errors.customer_id }"
                    />
                    <small v-if="errors.customer_id" class="text-red-500">{{ errors.customer_id }}</small>
                </div>
                <div>
                    <label class="font-medium">Gudang <span class="text-red-500">*</span></label>
                    <Select
                        v-model="form.warehouse_id"
                        :options="warehouses"
                        optionLabel="nama_warehouse"
                        optionValue="id"
                        placeholder="Pilih gudang"
                        filter
                        class="w-full"
                        :disabled="isEdit && isLinked"
                        :class="{ 'p-invalid': errors.warehouse_id }"
                    />
                    <small v-if="errors.warehouse_id" class="text-red-500">{{ errors.warehouse_id }}</small>
                </div>
                <div>
                    <label class="font-medium">Tanggal <span class="text-red-500">*</span></label>
                    <DatePicker v-model="form.tanggal" :dateFormat="getPrimeDateFormatShort" class="w-full" showIcon showTime hourFormat="24" :class="{ 'p-invalid': errors.tanggal }" />
                    <small v-if="errors.tanggal" class="text-red-500">{{ errors.tanggal }}</small>
                </div>
                <div>
                    <label class="font-medium">No. Nota Penjualan <span v-if="!salesAllowFree" class="text-red-500">*</span></label>
                    <Select
                        v-model="form.sales_id"
                        :options="returnableSales"
                        optionLabel="nomor_dokumen"
                        optionValue="id"
                        :placeholder="salesAllowFree ? 'Opsional — kosong = bebas' : 'Pilih nota (wajib)'"
                        filter
                        :showClear="salesAllowFree"
                        class="w-full"
                        :loading="loadingSales"
                        :disabled="isEdit || !form.customer_id || !form.warehouse_id"
                        :class="{ 'p-invalid': errors.sales_id }"
                    >
                        <template #option="{ option }">
                            <div>
                                <div class="font-medium">{{ option.nomor_dokumen }}</div>
                                <div class="text-sm text-surface-500">{{ option.customer?.nama }}</div>
                            </div>
                        </template>
                    </Select>
                    <small v-if="errors.sales_id" class="text-red-500">{{ errors.sales_id }}</small>
                    <small v-else class="text-surface-500">Pilih customer & gudang dulu</small>
                </div>
            </div>

            <div v-if="loadingDetails" class="text-center py-4"><ProgressSpinner style="width: 40px; height: 40px" /></div>

            <div class="border border-surface-200 rounded-lg p-4 mb-6" v-if="!loadingDetails">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium m-0">Detail Produk</h3>
                    <Button v-if="!isLinked && salesAllowFree" label="Tambah Produk" icon="pi pi-plus" size="small" @click="addDetail" :disabled="!form.customer_id || !form.warehouse_id" />
                </div>

                <Message v-if="isLinked && form.details.length" severity="info" :closable="false" class="mb-4">Harga memakai alokasi seperti retur POS (tanpa biaya kirim/lain & pembulatan nota). Tidak diedit manual.</Message>
                <Message v-else-if="form.customer_id && form.warehouse_id && !isLinked && salesAllowFree" severity="secondary" :closable="false" class="mb-4">
                    Mode bebas: harga editable.
                    {{ salesFreeRequireSold ? 'Non-serial hanya yang pernah terjual ke customer/gudang ini.' : 'Non-serial boleh tanpa histori jual.' }}
                    Serial tetap wajib SN terjual.
                </Message>
                <Message v-else-if="form.customer_id && form.warehouse_id && !isLinked && !salesAllowFree" severity="warn" :closable="false" class="mb-4">Mode bebas dimatikan — pilih nota penjualan terlebih dahulu.</Message>
                <Message v-if="returnableMessage" severity="warn" :closable="false" class="mb-4">{{ returnableMessage }}</Message>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <DataTable :value="form.details" class="p-datatable-sm sales-return-detail-table" responsiveLayout="scroll" v-if="form.details.length" dataKey="_uid" v-model:expandedRows="expandedRows" :rowClass="detailRowClass">
                    <Column expander style="width: 3rem" />
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>
                    <Column header="Produk" style="min-width: 200px">
                        <template #body="{ data, index }">
                            <div v-if="isLinked || data.sales_detail_id" class="flex flex-col">
                                <span class="font-medium">{{ data.product?.nama_produk }}</span>
                                <span class="text-sm text-surface-500">{{ data.product?.kode_produk }}</span>
                                <span v-if="data.is_serial" class="text-xs text-primary">Serial</span>
                            </div>
                            <div v-else-if="data.product_id && data.product" class="flex flex-col gap-1">
                                <span class="font-medium">{{ data.product?.kode_produk }}</span>
                                <span class="text-sm text-surface-500">{{ data.product?.nama_produk }}</span>
                                <span v-if="data.is_serial" class="text-xs text-primary">Serial</span>
                                <Button
                                    v-if="!data.is_serial"
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
                    <Column header="Satuan" style="width: 120px">
                        <template #body="{ data }">
                            <Select
                                v-if="!isLinked && !data.is_serial && data.units?.length"
                                v-model="data.unit"
                                :options="data.units"
                                optionLabel="unit"
                                optionValue="unit"
                                class="w-full"
                                @change="onUnitChange(data)"
                            />
                            <span v-else>{{ data.unit || '—' }}</span>
                        </template>
                    </Column>
                    <Column v-if="isLinked" header="Returnable" style="width: 100px" bodyClass="text-right">
                        <template #body="{ data }">{{ formatQty(data.returnable_base) }}</template>
                    </Column>
                    <Column header="Qty Retur" style="width: 140px">
                        <template #body="{ data, index }">
                            <template v-if="data.is_serial">
                                <Tag :value="`${data.serial_unit_ids?.length || 0} unit`" severity="info" />
                                <div class="text-xs text-surface-500">pilih unit ↓</div>
                            </template>
                            <InputNumber
                                v-else
                                v-model="data.qty_base"
                                :min="0"
                                :max="isLinked ? data.returnable_base : undefined"
                                :minFractionDigits="getQtyMinFractionDigits"
                                :maxFractionDigits="getQtyMaxFractionDigits"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.qty_base`] }"
                            />
                            <small v-if="errors[`details.${index}.qty_base`]" class="text-red-500">{{ errors[`details.${index}.qty_base`] }}</small>
                            <small v-if="errors[`details.${index}.serial`]" class="text-red-500">{{ errors[`details.${index}.serial`] }}</small>
                        </template>
                    </Column>
                    <Column header="Harga/Unit" style="width: 140px" bodyClass="text-right">
                        <template #body="{ data, index }">
                            <span v-if="isLinked" class="font-medium">{{ formatCurrency(data.harga_satuan) }}</span>
                            <InputNumber
                                v-else
                                v-model="data.harga_satuan"
                                :min="0"
                                :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                :locale="getLocale"
                                :minFractionDigits="getCurrencyMinFractionDigits"
                                :maxFractionDigits="getCurrencyMaxFractionDigits"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.harga_satuan`] }"
                            />
                        </template>
                    </Column>
                    <Column header="Subtotal" style="width: 120px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-medium">{{ formatCurrency(lineSubtotal(data)) }}</span>
                        </template>
                    </Column>
                    <Column v-if="!isLinked" header="" style="width: 50px">
                        <template #body="{ index }">
                            <Button icon="pi pi-trash" severity="danger" text rounded @click="removeDetail(index)" />
                        </template>
                    </Column>
                    <template #expansion="{ data }">
                        <div v-if="data.is_serial && data.product_id" class="px-4 py-3 bg-surface-50 dark:bg-surface-800">
                            <SerialUnitPicker
                                :key="data._uid"
                                :productId="data.product?.ulid || data.product_id"
                                :warehouseId="form.warehouse_id"
                                status="terjual"
                                :customerId="form.customer_id"
                                :salesId="form.sales_id"
                                :saleDetailId="data.sales_detail_id"
                                :modelValue="data.serial_unit_ids"
                                allow-when-disabled
                                @change="(units) => onSerialChange(data, units)"
                            />
                        </div>
                    </template>
                </DataTable>

                <div v-else class="text-center py-8 text-surface-500">
                    <i class="pi pi-box text-4xl mb-4 block"></i>
                    <p class="m-0">{{ form.customer_id && form.warehouse_id ? 'Pilih nota atau tambah produk (mode bebas).' : 'Pilih customer dan gudang terlebih dahulu.' }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6" v-if="form.details.length">
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Catatan</label>
                    <Textarea v-model="form.notes" rows="3" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                </div>
                <div class="border border-surface-200 rounded-lg p-4">
                    <h4 class="font-medium mb-4">Ringkasan</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between"><span class="text-surface-600">Total Qty</span><span class="font-medium">{{ formatQty(calculated.qty_total) }}</span></div>
                        <div class="flex justify-between"><span class="text-surface-600">Subtotal</span><span class="font-medium">{{ formatCurrency(calculated.subtotal) }}</span></div>
                        <div v-if="calculated.pembulatan" class="flex justify-between">
                            <span class="text-surface-600">Pembulatan</span>
                            <span :class="calculated.pembulatan > 0 ? 'text-green-600' : 'text-red-500'" class="font-medium">
                                {{ calculated.pembulatan > 0 ? '+' : '' }}{{ formatCurrency(calculated.pembulatan) }}
                            </span>
                        </div>
                        <Divider />
                        <div class="flex justify-between text-xl font-bold"><span>Nilai Retur</span><span class="text-primary">{{ formatCurrency(calculated.grand_total) }}</span></div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2 mt-6">
                <Button label="Batal" severity="secondary" outlined @click="goBack" :disabled="saving" />
                <Button label="Simpan Draft" icon="pi pi-save" :loading="saving" @click="save" />
            </div>
        </template>

        <ProductUnitPickerDrawer
            v-model:visible="pickerVisible"
            :query="pickerQuery"
            title="Pilih Produk"
            :fetch-products="fetchPickerProducts"
            :get-unit-price="getPickerUnitPrice"
            :taken-keys="pickerTakenKeys"
            :include-serial="true"
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
