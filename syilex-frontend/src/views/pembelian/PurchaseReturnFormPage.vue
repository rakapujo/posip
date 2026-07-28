<script setup>
import { purchaseReturnsApi, warehousesApi, suppliersApi, purchaseOrdersApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch, nextTick } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useSettingsStore } from '@/stores/settings';
import { useAuthStore } from '@/stores/auth';
import SerialUnitPicker from '@/components/common/SerialUnitPicker.vue';
import ProductUnitPickerDrawer from '@/components/common/ProductUnitPickerDrawer.vue';
import { buildTakenKeys, findDuplicateProductUnitErrors, productUnitRowHighlight } from '@/utils/productUnitLineHelpers';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
const settingsStore = useSettingsStore();
const authStore = useAuthStore();
const serialEnabled = computed(() => settingsStore.serialEnabled);
const canViewHarga = computed(() => authStore.can('po.view_harga'));
const purchaseAllowFree = computed(() => settingsStore.returns.purchaseAllowFree);
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
const pageTitle = computed(() => (isEdit.value ? 'Edit Retur Pembelian' : 'Buat Retur Pembelian'));
const isDocLinked = computed(() => !!form.value.po_id || !!form.value.serial_intake_id);

// Data
const suppliers = ref([]);
const warehouses = ref([]);
const purchaseOrders = ref([]);
const loadingPOs = ref(false);
const serialIntakes = ref([]);
const loadingPbs = ref(false);
const loading = ref(false);
const saving = ref(false);
const taxSettings = ref({ name: 'PPN', percent: 11, included_in_hpp: false });

// Form
const form = ref({
    tanggal: now(),
    supplier_id: null,
    warehouse_id: null,
    po_id: null,
    serial_intake_id: null,
    notes: '',
    // Header discounts
    diskon_1_tipe: 'none',
    diskon_1_nilai: 0,
    diskon_2_tipe: 'none',
    diskon_2_nilai: 0,
    diskon_3_tipe: 'none',
    diskon_3_nilai: 0,
    // Details
    details: []
});

const canAddLines = computed(() => !!form.value.supplier_id && !!form.value.warehouse_id);

// Calculated totals (from backend)
const calculated = ref({
    subtotal: 0,
    total_diskon_header: 0,
    dpp: 0,
    pajak_nominal: 0,
    total_sebelum_pembulatan: 0,
    pembulatan: 0,
    nilai_kalkulasi: 0
});

// Discount dialog
const discountDialog = ref(false);
const editingDiscountIndex = ref(null);

// Tipe options
const tipeOptions = computed(() => [
    { label: 'Tidak Ada', value: 'none' },
    { label: 'Persen (%)', value: 'percent' },
    { label: `Nominal (${currencySettings.value.symbol})`, value: 'nominal' }
]);

// Discount mode label
const discountModeLabel = computed(() => {
    const mode = calculationSettings.value?.discountMode || 'recursive';
    return mode === 'recursive' ? 'Bertingkat (Recursive)' : 'Penjumlahan (Sum)';
});

// Validation
const errors = ref({});

// Row expansion: produk serial auto-expand → pemilih unit di bawah parent
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
watch(() => form.value.details.map((d) => `${d._uid}:${d.is_serial ? 1 : 0}`).join('|'), syncExpandedSerial);

// Unit serial dipilih → qty = jumlah unit; harga = rata-rata harga_modal (preview total)
function onSerialReturnChange(detail, units) {
    const ids = units.map((u) => u.ulid);
    const prev = detail.serial_unit_ids || [];
    const sameIds =
        ids.length === prev.length &&
        [...ids].map(String).sort().join('|') === [...prev].map(String).sort().join('|');
    if (sameIds && Number(detail.qty_in_unit) === ids.length) return;

    detail.serial_unit_ids = ids;
    detail.qty_in_unit = ids.length;
    detail.harga_per_unit = ids.length ? units.reduce((s, u) => s + (Number(u.harga_modal) || 0), 0) / ids.length : 0;
    if (ids.length) calculateTotals();
}

onMounted(async () => {
    await Promise.all([loadSuppliers(), loadWarehouses(), loadTaxSettings()]);

    if (isEdit.value) {
        await loadPurchaseReturn();
    }
});

async function loadSuppliers() {
    try {
        const response = await suppliersApi.getList();
        if (response.data.success) {
            suppliers.value = response.data.data.suppliers;
        }
    } catch (error) {
        console.error('Failed to load suppliers:', error);
        notify.apiError(error, 'Gagal load suppliers');
    }
}

async function loadWarehouses() {
    try {
        const response = await warehousesApi.getList();
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
        const response = await purchaseReturnsApi.getTaxSettings();
        if (response.data.success) {
            taxSettings.value = response.data.data.tax;
        }
    } catch (error) {
        console.error('Failed to load tax settings:', error);
        notify.apiError(error, 'Gagal load tax settings');
    }
}

async function loadPurchaseOrders(supplierId, warehouseId) {
    if (!supplierId || !warehouseId) {
        purchaseOrders.value = [];
        return;
    }

    loadingPOs.value = true;
    try {
        const response = await purchaseOrdersApi.getList({ supplier_id: supplierId, warehouse_id: warehouseId });
        if (response.data.success) {
            purchaseOrders.value = response.data.data.items;
        }
    } catch (error) {
        console.error('Failed to load purchase orders:', error);
        purchaseOrders.value = [];
    } finally {
        loadingPOs.value = false;
    }
}

async function loadSerialIntakes(supplierId, warehouseId) {
    if (!serialEnabled.value || !supplierId || !warehouseId) {
        serialIntakes.value = [];
        return;
    }

    loadingPbs.value = true;
    try {
        const response = await purchaseReturnsApi.listSerialIntakes({
            supplier_id: supplierId,
            warehouse_id: warehouseId
        });
        if (response.data.success) {
            serialIntakes.value = response.data.data.items;
        }
    } catch (error) {
        console.error('Failed to load serial intakes:', error);
        serialIntakes.value = [];
    } finally {
        loadingPbs.value = false;
    }
}

/** Satu dropdown: PO + PBS (value = po:ID | pbs:ID). Backend tetap XOR po_id / serial_intake_id. */
const docRefKey = ref(null);
const loadingDocs = computed(() => loadingPOs.value || loadingPbs.value);
const docRefOptions = computed(() => {
    const poOpts = (purchaseOrders.value || []).map((p) => ({
        key: `po:${p.id}`,
        jenis: 'PO',
        nomor_dokumen: p.nomor_dokumen,
        label: `PO · ${p.nomor_dokumen}`,
        subtitle: p.tanggal_po || ''
    }));
    const pbsOpts = (serialIntakes.value || []).map((p) => ({
        key: `pbs:${p.id}`,
        jenis: 'PBS',
        nomor_dokumen: p.nomor_dokumen,
        label: `PBS · ${p.nomor_dokumen}`,
                subtitle: [
                    p.product?.kode_produk,
                    (p.returnable_unit_count ?? p.total_unit) != null
                        ? `${p.returnable_unit_count ?? p.total_unit} tersedia`
                        : null
                ]
                    .filter(Boolean)
                    .join(' · ')
    }));
    return [...poOpts, ...pbsOpts];
});

function syncDocRefFromForm() {
    if (form.value.po_id) {
        docRefKey.value = `po:${form.value.po_id}`;
    } else if (form.value.serial_intake_id) {
        docRefKey.value = `pbs:${form.value.serial_intake_id}`;
    } else {
        docRefKey.value = null;
    }
}

function clearLinkedDetails() {
    form.value.details = form.value.details.filter((d) => !d.po_detail_id && !d.from_serial_intake);
    poReturnableMessage.value = '';
    pbsReturnableMessage.value = '';
    calculateTotals();
}

// Watch for supplier/warehouse change to load POs + PBS (only when not loading edit data)
watch(
    () => [form.value.supplier_id, form.value.warehouse_id],
    async ([newSupplierId, newWarehouseId], [oldSupplierId, oldWarehouseId]) => {
        if (loading.value) return;

        purchaseOrders.value = [];
        serialIntakes.value = [];
        if (newSupplierId !== oldSupplierId || newWarehouseId !== oldWarehouseId) {
            form.value.po_id = null;
            form.value.serial_intake_id = null;
            docRefKey.value = null;
        }

        if (newSupplierId && newWarehouseId) {
            const loads = [loadPurchaseOrders(newSupplierId, newWarehouseId)];
            if (serialEnabled.value) loads.push(loadSerialIntakes(newSupplierId, newWarehouseId));
            else serialIntakes.value = [];
            await Promise.all(loads);
        }
    }
);

// State for returnable details
const loadingPoDetails = ref(false);
const poReturnableMessage = ref('');
const pbsReturnableMessage = ref('');

watch(docRefKey, async (newKey, oldKey) => {
    if (loading.value) return;

    poReturnableMessage.value = '';
    pbsReturnableMessage.value = '';

    if (!newKey) {
        form.value.po_id = null;
        form.value.serial_intake_id = null;
        if (oldKey) clearLinkedDetails();
        return;
    }

    const [jenis, idStr] = String(newKey).split(':');
    const id = Number(idStr);
    if (!id) return;

    if (jenis === 'po') {
        form.value.serial_intake_id = null;
        form.value.po_id = id;
        await loadReturnableDetails(id);
        return;
    }

    if (jenis === 'pbs') {
        form.value.po_id = null;
        form.value.serial_intake_id = id;
        await loadSerialIntakeReturnable(id);
    }
});

async function loadSerialIntakeReturnable(intakeId) {
    const intake = serialIntakes.value.find((p) => p.id === intakeId);
    if (!intake?.ulid) {
        console.error('PBS not found or missing ulid');
        return;
    }

    loadingPoDetails.value = true;
    try {
        const response = await purchaseReturnsApi.getSerialIntakeReturnable(intake.ulid);
        if (!response.data.success) return;

        const data = response.data.data;
        if (data.message && (!data.units || data.units.length === 0)) {
            pbsReturnableMessage.value = data.message;
            form.value.details = [];
            notify.warn('Info', data.message);
            return;
        }

        const product = data.product;
        const unitUlids = (data.units || []).map((u) => u.ulid);
        form.value.details = [
            {
                _uid: nextUid(),
                from_serial_intake: true,
                is_serial: true,
                serial_unit_ids: unitUlids,
                product_id: data.serial_intake.product_id || product?.id,
                po_detail_id: null,
                product,
                units: getProductUnits(product),
                unit_used: 'UNIT',
                unit_konversi: 1,
                qty_in_unit: unitUlids.length,
                qty_max: unitUlids.length,
                harga_per_unit: data.harga_per_unit ?? 0,
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
        ];
        expandedRows.value = { [form.value.details[0]._uid]: true };
        await calculateTotals();
        notify.itemsLoaded(unitUlids.length, 'unit', 'PBS');
    } catch (error) {
        console.error('Failed to load PBS returnable:', error);
        notify.loadDetailError('data PBS');
    } finally {
        loadingPoDetails.value = false;
    }
}

async function loadReturnableDetails(poId) {
    // Find the PO ulid from the purchaseOrders list
    const po = purchaseOrders.value.find((p) => p.id === poId);
    if (!po || !po.ulid) {
        console.error('PO not found or missing ulid');
        return;
    }

    loadingPoDetails.value = true;
    try {
        const response = await purchaseReturnsApi.getReturnableDetails(po.ulid);
        if (response.data.success) {
            const data = response.data.data;

            // Check if all items already returned
            if (data.message) {
                poReturnableMessage.value = data.message;
                form.value.details = [];
                notify.warn('Info', data.message);
                return;
            }

            // Populate form.details with returnable items
            form.value.details = data.details.map((d) => ({
                _uid: nextUid(),
                is_serial: false,
                po_detail_id: d.po_detail_id,
                product_id: d.product_id,
                product: d.product,
                units: d.units,
                unit_used: d.unit_used,
                unit_konversi: d.unit_konversi,
                qty_in_unit: d.qty_available_unit, // Default to max available
                qty_max: d.qty_available_unit, // Store max for validation
                qty_ordered: d.qty_ordered,
                qty_returned: d.qty_returned_unit,
                harga_per_unit: d.harga_per_unit,
                diskon_1_tipe: d.diskon_1_tipe || 'none',
                diskon_1_nilai: d.diskon_1_nilai || 0,
                diskon_2_tipe: d.diskon_2_tipe || 'none',
                diskon_2_nilai: d.diskon_2_nilai || 0,
                diskon_3_tipe: d.diskon_3_tipe || 'none',
                diskon_3_nilai: d.diskon_3_nilai || 0,
                diskon_4_tipe: d.diskon_4_tipe || 'none',
                diskon_4_nilai: d.diskon_4_nilai || 0,
                diskon_5_tipe: d.diskon_5_tipe || 'none',
                diskon_5_nilai: d.diskon_5_nilai || 0
            }));

            // Calculate totals
            await calculateTotals();

            notify.itemsLoaded(data.details.length, 'item', 'PO');
        }
    } catch (error) {
        console.error('Failed to load returnable details:', error);
        notify.loadDetailError('data PO');
    } finally {
        loadingPoDetails.value = false;
    }
}

async function loadPurchaseReturn() {
    loading.value = true;
    try {
        const response = await purchaseReturnsApi.get(route.params.ulid);
        if (response.data.success) {
            const retur = response.data.data.purchase_return;

            if (retur.status !== 'draft') {
                notify.cannotEdit('Retur yang sudah dikunci/disetujui');
                router.push({ name: 'pembelian-retur' });
                return;
            }

            // Load POs + PBS for the supplier + warehouse first (without triggering watcher to clear refs)
            if (retur.supplier_id && retur.warehouse_id) {
                const loads = [loadPurchaseOrders(retur.supplier_id, retur.warehouse_id)];
                if (serialEnabled.value) loads.push(loadSerialIntakes(retur.supplier_id, retur.warehouse_id));
                else serialIntakes.value = [];
                await Promise.all(loads);
            }

            form.value = {
                tanggal: parseDateTime(retur.tanggal),
                supplier_id: retur.supplier_id,
                warehouse_id: retur.warehouse_id,
                po_id: retur.po_id || null,
                serial_intake_id: retur.serial_intake_id || null,
                notes: retur.notes || '',
                diskon_1_tipe: retur.diskon_1_tipe || 'none',
                diskon_1_nilai: retur.diskon_1_nilai || 0,
                diskon_2_tipe: retur.diskon_2_tipe || 'none',
                diskon_2_nilai: retur.diskon_2_nilai || 0,
                diskon_3_tipe: retur.diskon_3_tipe || 'none',
                diskon_3_nilai: retur.diskon_3_nilai || 0,
                details: retur.details.map((d) => ({
                    _uid: nextUid(),
                    from_serial_intake: !!retur.serial_intake_id,
                    is_serial: serialEnabled.value && !!d.product?.is_serial,
                    serial_unit_ids: d.serial_unit_ids || (serialEnabled.value && d.product?.is_serial ? [] : null),
                    product_id: d.product_id,
                    po_detail_id: d.po_detail_id || null,
                    product: d.product,
                    unit_used: d.unit_used,
                    unit_konversi: d.unit_konversi,
                    units: getProductUnits(d.product),
                    qty_in_unit: d.qty_in_unit,
                    qty_max: d.qty_max || null, // From backend if PO linked
                    harga_per_unit: d.harga_per_unit,
                    diskon_1_tipe: d.diskon_1_tipe || 'none',
                    diskon_1_nilai: d.diskon_1_nilai || 0,
                    diskon_2_tipe: d.diskon_2_tipe || 'none',
                    diskon_2_nilai: d.diskon_2_nilai || 0,
                    diskon_3_tipe: d.diskon_3_tipe || 'none',
                    diskon_3_nilai: d.diskon_3_nilai || 0,
                    diskon_4_tipe: d.diskon_4_tipe || 'none',
                    diskon_4_nilai: d.diskon_4_nilai || 0,
                    diskon_5_tipe: d.diskon_5_tipe || 'none',
                    diskon_5_nilai: d.diskon_5_nilai || 0
                }))
            };

            syncDocRefFromForm();
            await calculateTotals();
        }
    } catch (error) {
        console.error('Failed to load retur:', error);
        notify.loadListError('retur pembelian');
        router.push({ name: 'pembelian-retur' });
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

function isSerialDetail(d) {
    if (!serialEnabled.value) return false;
    return !!(d?.is_serial || d?.product?.is_serial || (d?.serial_unit_ids || []).length);
}

// Product picker drawer (free mode only — PO/PBS-linked lines keep product as text)
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
    const response = await purchaseReturnsApi.getProducts({ search: q });
    if (!response.data.success) return [];
    return response.data.data.items || [];
}

function getPickerUnitPrice(product, unitObj) {
    return Number(unitObj?.harga_jual ?? 0);
}

function onPickerTakenClick(row) {
    if (row.is_serial) {
        notify.info('Produk serial sudah ada di form — ubah SN di baris tersebut');
    } else {
        notify.info('Produk + satuan sudah ada di form — ubah qty di baris tersebut');
    }
}

function applyPickerSelect({ product, unit, konversi, is_serial, price }) {
    const index = pickerTargetIndex.value;
    if (index < 0 || !product?.id) return;

    // Produk serial: kunci satuan 'UNIT', qty & harga diturunkan dari unit dipilih
    if (serialEnabled.value && is_serial) {
        form.value.details[index] = {
            ...form.value.details[index],
            _searchQuery: '',
            product_id: product.id,
            product: product,
            is_serial: true,
            serial_unit_ids: [],
            units: [{ unit: 'UNIT', konversi: 1 }],
            unit_used: 'UNIT',
            unit_konversi: 1,
            qty_in_unit: 0,
            harga_per_unit: 0
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
        product: product,
        is_serial: false,
        serial_unit_ids: null,
        units: units.length ? units : [{ unit, konversi, harga_jual: price }],
        unit_used: unit,
        unit_konversi: konversi || 1,
        qty_in_unit: 1,
        harga_per_unit: 0
    };

    // Try to get last price
    getLastPrice(index, product.id, form.value.supplier_id, unit);
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

async function getLastPrice(index, productId, supplierId, unit) {
    try {
        const params = { product_id: productId };
        if (supplierId) params.supplier_id = supplierId;
        if (unit) params.unit = unit;

        const response = await purchaseReturnsApi.getLastPrice(params);
        if (response.data.success && response.data.data.last_price) {
            form.value.details[index].harga_per_unit = response.data.data.last_price.harga_per_unit;
        }
    } catch (error) {
        console.error('Failed to get last price:', error);
        notify.apiError(error, 'Gagal get last price');
    }
}

function onUnitChange(index) {
    const detail = form.value.details[index];
    if (detail && detail.units) {
        const selectedUnit = detail.units.find((u) => u.unit === detail.unit_used);
        if (selectedUnit) {
            detail.unit_konversi = selectedUnit.konversi;
            getLastPrice(index, detail.product_id, form.value.supplier_id, detail.unit_used);
        }
    }
    calculateTotals();
}

function addDetail() {
    if (isDocLinked.value) return;
    if (!canAddLines.value) {
        notify.selectFirst('supplier & gudang');
        return;
    }
    form.value.details.push({
        _uid: nextUid(),
        _searchQuery: '',
        is_serial: false,
        serial_unit_ids: null,
        product_id: null,
        product: null,
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
        diskon_5_nilai: 0
    });
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
                dpp: 0,
                pajak_nominal: 0,
                total_sebelum_pembulatan: 0,
                pembulatan: 0,
                nilai_kalkulasi: 0
            };
            return;
        }

        try {
            const payload = buildPayload();
            const response = await purchaseReturnsApi.calculate(payload);
            if (response.data.success) {
                calculated.value = response.data.data.calculation;
            }
        } catch (error) {
            console.error('Failed to calculate:', error);
            notify.apiError(error, 'Gagal calculate');
        }
    }, 500);
}

function buildPayload() {
    return {
        tanggal: toDateTimeString(form.value.tanggal),
        supplier_id: form.value.supplier_id,
        warehouse_id: form.value.warehouse_id,
        po_id: form.value.po_id || null,
        serial_intake_id: form.value.serial_intake_id || null,
        notes: form.value.notes || null,
        diskon_1_tipe: form.value.diskon_1_tipe,
        diskon_1_nilai: form.value.diskon_1_nilai,
        diskon_2_tipe: form.value.diskon_2_tipe,
        diskon_2_nilai: form.value.diskon_2_nilai,
        diskon_3_tipe: form.value.diskon_3_tipe,
        diskon_3_nilai: form.value.diskon_3_nilai,
        details: form.value.details.map((d) => ({
            product_id: d.product_id,
            po_detail_id: d.po_detail_id || null,
            serial_unit_ids: d.is_serial ? d.serial_unit_ids || [] : null,
            unit_used: d.unit_used,
            unit_konversi: d.unit_konversi,
            qty_in_unit: d.qty_in_unit,
            harga_per_unit: d.harga_per_unit,
            diskon_1_tipe: d.diskon_1_tipe,
            diskon_1_nilai: d.diskon_1_nilai,
            diskon_2_tipe: d.diskon_2_tipe,
            diskon_2_nilai: d.diskon_2_nilai,
            diskon_3_tipe: d.diskon_3_tipe,
            diskon_3_nilai: d.diskon_3_nilai,
            diskon_4_tipe: d.diskon_4_tipe,
            diskon_4_nilai: d.diskon_4_nilai,
            diskon_5_tipe: d.diskon_5_tipe,
            diskon_5_nilai: d.diskon_5_nilai
        }))
    };
}

function validate() {
    errors.value = {};

    if (!form.value.supplier_id) {
        errors.value.supplier_id = 'Supplier wajib dipilih';
    }
    if (!form.value.warehouse_id) {
        errors.value.warehouse_id = 'Warehouse wajib dipilih';
    }
    if (!form.value.tanggal) {
        errors.value.tanggal = 'Tanggal wajib diisi';
    }
    if (!purchaseAllowFree.value && !form.value.po_id && !form.value.serial_intake_id) {
        errors.value.doc_ref = 'Dokumen referensi wajib (mode bebas dimatikan)';
    }
    if (form.value.details.length === 0) {
        errors.value.details = 'Minimal harus ada 1 detail produk';
    }

    // Validate each detail
    form.value.details.forEach((detail, index) => {
        if (!detail.product_id) {
            errors.value[`details.${index}.product_id`] = 'Produk wajib dipilih';
        }
        if (!detail.unit_used) {
            errors.value[`details.${index}.unit_used`] = 'Satuan wajib dipilih';
        }
        if (detail.is_serial) {
            if (!detail.serial_unit_ids || detail.serial_unit_ids.length < 1) {
                errors.value[`details.${index}.qty_in_unit`] = 'Pilih minimal 1 unit serial';
            }
        } else if (!detail.qty_in_unit || detail.qty_in_unit <= 0) {
            errors.value[`details.${index}.qty_in_unit`] = 'Qty harus lebih dari 0';
        }
        // Validate qty against qty_max when PO is selected
        if (detail.qty_max && detail.qty_in_unit > detail.qty_max) {
            errors.value[`details.${index}.qty_in_unit`] = `Qty melebihi sisa PO (max: ${formatQty(detail.qty_max)})`;
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
        notify.formInvalid();
        return;
    }

    saving.value = true;
    try {
        const payload = buildPayload();

        let response;
        if (isEdit.value) {
            response = await purchaseReturnsApi.update(route.params.ulid, payload);
        } else {
            response = await purchaseReturnsApi.create(payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Retur pembelian', isEdit.value);
            router.push({ name: 'pembelian-retur' });
        }
    } catch (error) {
        console.error('Failed to save retur:', error);
        notify.saveError(error);

        if (error.response?.data?.errors) {
            errors.value = { ...errors.value, ...error.response.data.errors };
        }
    } finally {
        saving.value = false;
    }
}

function cancel() {
    router.push({ name: 'pembelian-retur' });
}

// Calculate item subtotal for display
function getItemSubtotal(detail) {
    const bruto = (detail.qty_in_unit || 0) * (detail.harga_per_unit || 0);
    const mode = calculationSettings.value?.discountMode || 'recursive';

    if (mode === 'sum') {
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

function getItemTotalDiscount(detail) {
    const bruto = (detail.qty_in_unit || 0) * (detail.harga_per_unit || 0);
    const subtotal = getItemSubtotal(detail);
    return bruto - subtotal;
}

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

function openDiscountDialog(index) {
    editingDiscountIndex.value = index;
    discountDialog.value = true;
}

function closeDiscountDialog() {
    discountDialog.value = false;
    editingDiscountIndex.value = null;
    calculateTotals();
}

function canEditDiscount(data) {
    return data.product_id && data.unit_used && data.qty_in_unit > 0 && data.harga_per_unit > 0;
}

function resetDiscount(index) {
    const detail = form.value.details[index];
    for (let i = 1; i <= 5; i++) {
        detail[`diskon_${i}_tipe`] = 'none';
        detail[`diskon_${i}_nilai`] = 0;
    }
    calculateTotals();
}

function getDiscountMaxNominal(detail, discIndex) {
    const bruto = (detail.qty_in_unit || 0) * (detail.harga_per_unit || 0);
    if (bruto <= 0) return 0;

    const mode = calculationSettings.value?.discountMode || 'recursive';

    if (mode === 'sum') {
        let totalPercentDiscount = 0;
        let totalOtherNominal = 0;

        for (let i = 1; i <= 5; i++) {
            const tipe = detail[`diskon_${i}_tipe`];
            const nilai = detail[`diskon_${i}_nilai`] || 0;

            if (tipe === 'percent' && nilai > 0) {
                totalPercentDiscount += bruto * (nilai / 100);
            } else if (tipe === 'nominal' && nilai > 0 && i !== discIndex) {
                totalOtherNominal += nilai;
            }
        }

        return Math.max(0, bruto - totalPercentDiscount - totalOtherNominal);
    } else {
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

function onDiscountValueChange(discIndex, newValue) {
    if (editingDiscountIndex.value === null) return;

    const detail = form.value.details[editingDiscountIndex.value];
    const tipe = detail[`diskon_${discIndex}_tipe`];
    let nilai = newValue || 0;
    let needsCorrection = false;

    if (nilai < 0) {
        nilai = 0;
        needsCorrection = true;
    }

    if (tipe === 'percent') {
        if (nilai > 100) {
            nilai = 100;
            needsCorrection = true;
        }
    } else if (tipe === 'nominal') {
        const maxNominal = getDiscountMaxNominal(detail, discIndex);
        if (nilai > maxNominal) {
            nilai = maxNominal;
            needsCorrection = true;
        }
    }

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
            <!-- Header Fields -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <!-- Supplier -->
                <div class="flex flex-col gap-2">
                    <label for="supplier" class="font-medium">Supplier <span class="text-red-500">*</span></label>
                    <Select id="supplier" v-model="form.supplier_id" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Pilih Supplier" filter class="w-full" :class="{ 'p-invalid': errors.supplier_id }" />
                    <small v-if="errors.supplier_id" class="text-red-500">{{ errors.supplier_id }}</small>
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
                    <DatePicker id="tanggal" v-model="form.tanggal" :dateFormat="getPrimeDateFormatShort" class="w-full" :class="{ 'p-invalid': errors.tanggal }" showIcon showTime hourFormat="24" />
                    <small v-if="errors.tanggal" class="text-red-500">{{ errors.tanggal }}</small>
                </div>

                <!-- No. Referensi (PO + PBS digabung) -->
                <div class="flex flex-col gap-2">
                    <label for="doc_ref" class="font-medium">No. Referensi <span v-if="!purchaseAllowFree" class="text-red-500">*</span></label>
                    <Select
                        id="doc_ref"
                        v-model="docRefKey"
                        :options="docRefOptions"
                        optionLabel="label"
                        optionValue="key"
                        :placeholder="purchaseAllowFree ? (serialEnabled ? 'Pilih PO / PBS (opsional)' : 'Pilih PO (opsional)') : serialEnabled ? 'Pilih PO / PBS (wajib)' : 'Pilih PO (wajib)'"
                        filter
                        :showClear="purchaseAllowFree"
                        class="w-full"
                        :loading="loadingDocs"
                        :disabled="!form.supplier_id || !form.warehouse_id"
                        :class="{ 'p-invalid': errors.doc_ref }"
                        filterBy="label,nomor_dokumen,jenis,subtitle"
                    >
                        <template #option="{ option }">
                            <div class="flex items-start gap-2">
                                <Tag :value="option.jenis" :severity="option.jenis === 'PO' ? 'info' : 'help'" class="text-xs shrink-0 mt-0.5" />
                                <div class="flex flex-col min-w-0">
                                    <span class="font-medium">{{ option.nomor_dokumen }}</span>
                                    <span v-if="option.subtitle" class="text-sm text-surface-500 truncate">{{ option.subtitle }}</span>
                                </div>
                            </div>
                        </template>
                        <template #value="{ value, placeholder }">
                            <span v-if="value">{{ docRefOptions.find((o) => o.key === value)?.label || value }}</span>
                            <span v-else class="text-surface-400">{{ placeholder }}</span>
                        </template>
                    </Select>
                    <small v-if="errors.doc_ref" class="text-red-500">{{ errors.doc_ref }}</small>
                    <small v-else class="text-surface-500">{{ purchaseAllowFree ? 'Kosongkan = mode bebas (FIFO hutang). Isi = net hutang dokumen itu.' : 'Wajib pilih dokumen referensi (mode bebas dimatikan).' }}</small>
                </div>
            </div>

            <!-- Detail Products Section -->
            <div class="border border-surface-200 rounded-lg p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium m-0">Detail Produk</h3>
                    <div class="flex items-center gap-2">
                        <ProgressSpinner v-if="loadingPoDetails" style="width: 24px; height: 24px" />
                        <Button
                            v-if="purchaseAllowFree || isDocLinked"
                            label="Tambah Produk"
                            icon="pi pi-plus"
                            size="small"
                            @click="addDetail"
                            :disabled="isDocLinked || !canAddLines"
                            v-tooltip.top="
                                isDocLinked
                                    ? 'Item sudah diisi dari dokumen referensi'
                                    : !canAddLines
                                      ? 'Pilih supplier & gudang dulu'
                                      : !purchaseAllowFree
                                        ? 'Mode bebas dimatikan'
                                        : null
                            "
                        />
                    </div>
                </div>

                <!-- PO Info Message -->
                <Message v-if="form.po_id && form.details.length > 0" severity="info" :closable="false" class="mb-4">
                    <div class="flex items-center gap-2">
                        <i class="pi pi-info-circle"></i>
                        <span>Item dari PO. Qty maksimal = sisa yang belum diretur. Approve men-net hutang PO ini.</span>
                    </div>
                </Message>

                <Message v-else-if="form.serial_intake_id && form.details.length > 0" severity="info" :closable="false" class="mb-4">
                    <div class="flex items-center gap-2">
                        <i class="pi pi-info-circle"></i>
                        <span>Unit dari PBS. Pilih SN yang diretur; approve men-net hutang PBS ini.</span>
                    </div>
                </Message>

                <Message v-else-if="form.supplier_id && form.warehouse_id && !isDocLinked && purchaseAllowFree" severity="secondary" :closable="false" class="mb-4">
                    Mode bebas: tambah produk manual. Approve men-net hutang supplier secara FIFO.
                </Message>

                <Message v-else-if="form.supplier_id && form.warehouse_id && !isDocLinked && !purchaseAllowFree" severity="warn" :closable="false" class="mb-4">
                    Mode bebas dimatikan — pilih dokumen referensi terlebih dahulu.
                </Message>

                <!-- PO All Returned Warning -->
                <Message v-if="poReturnableMessage" severity="warn" :closable="false" class="mb-4">
                    {{ poReturnableMessage }}
                </Message>
                <Message v-if="pbsReturnableMessage" severity="warn" :closable="false" class="mb-4">
                    {{ pbsReturnableMessage }}
                </Message>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <DataTable :value="form.details" class="p-datatable-sm purchase-return-detail-table" responsiveLayout="scroll" v-if="form.details.length > 0" dataKey="_uid" v-model:expandedRows="expandedRows" :rowClass="detailRowClass">
                    <Column expander style="width: 3rem" />
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="Produk" style="min-width: 220px">
                        <template #body="{ data, index }">
                            <!-- Dari PO / PBS: teks saja, tidak bisa diganti -->
                            <div v-if="data.po_detail_id || data.from_serial_intake" class="flex flex-col">
                                <span class="font-medium">{{ data.product?.kode_produk }}</span>
                                <span class="text-sm text-surface-500">{{ data.product?.nama_produk }}</span>
                                <span v-if="serialEnabled && data.is_serial" class="text-xs text-primary">Serial</span>
                            </div>
                            <div v-else-if="data.product_id && data.product" class="flex flex-col gap-1">
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

                    <Column header="Satuan" style="width: 130px">
                        <template #body="{ data, index }">
                            <!-- Read-only when from PO or single unit -->
                            <span v-if="data.po_detail_id || (data.units && data.units.length === 1)" class="font-medium"> {{ data.unit_used }} ({{ data.unit_konversi }}) </span>
                            <!-- Editable when manual and multiple units -->
                            <Select v-else v-model="data.unit_used" :options="data.units" optionValue="unit" placeholder="Satuan" class="w-full" :class="{ 'p-invalid': errors[`details.${index}.unit_used`] }" @change="onUnitChange(index)">
                                <template #value="{ value }">
                                    <span v-if="value"> {{ value }} ({{ data.units.find((u) => u.unit === value)?.konversi || 1 }}) </span>
                                    <span v-else class="text-surface-400">Satuan</span>
                                </template>
                                <template #option="{ option }"> {{ option.unit }} ({{ option.konversi }}) </template>
                            </Select>
                        </template>
                    </Column>

                    <Column header="Qty" style="width: 120px">
                        <template #body="{ data, index }">
                            <div v-if="serialEnabled && data.is_serial" class="flex flex-col gap-1">
                                <Tag :value="`${data.serial_unit_ids?.length || 0} unit`" severity="info" />
                                <div class="text-xs text-surface-500">dari pilih unit ↓</div>
                                <small v-if="errors[`details.${index}.qty_in_unit`]" class="text-red-500 text-xs">
                                    {{ errors[`details.${index}.qty_in_unit`] }}
                                </small>
                            </div>
                            <div v-else class="flex flex-col gap-1">
                                <InputNumber
                                    v-select-on-focus
                                    v-model="data.qty_in_unit"
                                    :min="0.0001"
                                    :max="data.qty_max || undefined"
                                    :minFractionDigits="getQtyMinFractionDigits"
                                    :maxFractionDigits="getQtyMaxFractionDigits"
                                    :locale="getLocale"
                                    class="w-full"
                                    :class="{ 'p-invalid': errors[`details.${index}.qty_in_unit`] }"
                                    @update:modelValue="calculateTotals"
                                />
                                <div v-if="data.qty_max" class="text-xs text-surface-500">Max: {{ formatQty(data.qty_max) }}</div>
                                <small v-if="errors[`details.${index}.qty_in_unit`]" class="text-red-500 text-xs">
                                    {{ errors[`details.${index}.qty_in_unit`] }}
                                </small>
                            </div>
                        </template>
                    </Column>

                    <Column v-if="canViewHarga" header="Harga/Unit" style="width: 140px">
                        <template #body="{ data }">
                            <!-- Serial: harga = rata-rata modal unit (read-only) -->
                            <div v-if="serialEnabled && data.is_serial">
                                <span class="font-medium">{{ formatCurrency(data.harga_per_unit) }}</span>
                                <div class="text-xs text-surface-500">rata-rata modal</div>
                            </div>
                            <!-- Read-only when from PO -->
                            <span v-else-if="data.po_detail_id" class="font-medium">
                                {{ formatCurrency(data.harga_per_unit) }}
                            </span>
                            <!-- Editable when manual -->
                            <InputNumber
                                v-select-on-focus
                                v-else
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

                    <Column v-if="canViewHarga" header="Diskon" style="width: 150px">
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

                    <Column v-if="canViewHarga" header="Subtotal" style="width: 120px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-medium">{{ formatCurrency(getItemSubtotal(data)) }}</span>
                        </template>
                    </Column>

                    <Column header="" style="width: 50px">
                        <template #body="{ data, index }">
                            <Button icon="pi pi-trash" severity="danger" text rounded @click="removeDetail(index)" :disabled="!!data.po_detail_id || !!data.from_serial_intake" v-tooltip.top="data.po_detail_id || data.from_serial_intake ? 'Item dari dokumen referensi tidak bisa dihapus' : ''" />
                        </template>
                    </Column>

                    <!-- Pemilih unit serial yang diretur (tepat di bawah baris produknya) -->
                    <template #expansion="{ data }">
                        <div v-if="serialEnabled && data.is_serial && data.product_id" class="px-4 py-3 bg-surface-50 dark:bg-surface-800">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="pi pi-qrcode text-primary"></i>
                                <span class="font-medium text-sm"> Pilih Unit Serial yang Diretur — {{ data.product?.kode_produk }} {{ data.product?.nama_produk }} </span>
                            </div>
                            <SerialUnitPicker
                                :key="data._uid"
                                :productId="data.product?.ulid"
                                :warehouseId="form.warehouse_id"
                                :intakeId="form.serial_intake_id || undefined"
                                :modelValue="data.serial_unit_ids"
                                @change="(units) => onSerialReturnChange(data, units)"
                            />
                            <div class="text-xs text-surface-500 mt-1">Harga retur memakai harga modal tiap unit (rata-rata ditampilkan di kolom Harga).</div>
                        </div>
                        <div v-else class="px-4 py-2 text-xs text-surface-400">Produk non-serial — isi qty & harga di kolom.</div>
                    </template>
                </DataTable>

                <div v-else class="text-center py-8 text-surface-500">
                    <i class="pi pi-box text-4xl mb-4 block"></i>
                    <p class="m-0">Belum ada detail produk. Klik "Tambah Produk" untuk menambahkan.</p>
                </div>
            </div>

            <!-- Bottom Section: Discounts & Totals -->
            <div v-if="canViewHarga" class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Left: Header Discounts & Notes -->
                <div class="space-y-4">
                    <!-- Header Discounts -->
                    <div class="border border-surface-200 rounded-lg p-4">
                        <h4 class="font-medium mb-4">Diskon Header</h4>
                        <div class="space-y-3">
                            <div v-for="i in 3" :key="i" class="flex gap-2 items-center">
                                <label class="w-24 text-sm">Diskon {{ i }}</label>
                                <Select v-model="form[`diskon_${i}_tipe`]" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-32" @change="calculateTotals" />
                                <InputNumber
                                    v-select-on-focus
                                    v-if="form[`diskon_${i}_tipe`] !== 'none'"
                                    v-model="form[`diskon_${i}_nilai`]"
                                    :min="0"
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

                    <!-- Notes -->
                    <div class="flex flex-col gap-2">
                        <label for="notes" class="font-medium">Catatan</label>
                        <Textarea id="notes" v-model="form.notes" rows="2" class="w-full" placeholder="Catatan untuk retur ini..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
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
                        <Divider />
                        <div class="flex justify-between">
                            <span class="text-surface-600">DPP</span>
                            <span>{{ formatCurrency(calculated.dpp) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-surface-600">{{ taxSettings.name }} ({{ taxSettings.percent }}%)</span>
                            <span>{{ formatCurrency(calculated.pajak_nominal) }}</span>
                        </div>
                        <div v-if="calculated.pembulatan !== 0" class="flex justify-between">
                            <span class="text-surface-600">Pembulatan</span>
                            <span :class="calculated.pembulatan > 0 ? 'text-green-600' : 'text-red-500'"> {{ calculated.pembulatan > 0 ? '+' : '' }}{{ formatCurrency(calculated.pembulatan) }} </span>
                        </div>
                        <Divider />
                        <div class="flex justify-between text-xl font-bold">
                            <span>Nilai Kalkulasi</span>
                            <span class="text-primary">{{ formatCurrency(calculated.nilai_kalkulasi) }}</span>
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
        <Dialog v-if="canViewHarga" v-model:visible="discountDialog" header="Diskon Item" modal :style="{ width: '400px' }" :closable="true" @hide="closeDiscountDialog">
            <template v-if="editingDiscountIndex !== null && form.details[editingDiscountIndex]">
                <div class="space-y-4">
                    <div class="flex items-center gap-2 text-sm">
                        <i class="pi pi-info-circle text-primary"></i>
                        <span class="text-surface-600">Mode Perhitungan:</span>
                        <span class="font-medium">{{ discountModeLabel }}</span>
                    </div>

                    <div class="bg-surface-50 rounded-lg p-3">
                        <div class="font-medium">{{ form.details[editingDiscountIndex].product?.nama_produk || '-' }}</div>
                        <div class="text-sm text-surface-500">
                            {{ formatQty(form.details[editingDiscountIndex].qty_in_unit) }} {{ form.details[editingDiscountIndex].unit_used }} × {{ formatCurrency(form.details[editingDiscountIndex].harga_per_unit) }} =
                            {{ formatCurrency((form.details[editingDiscountIndex].qty_in_unit || 0) * (form.details[editingDiscountIndex].harga_per_unit || 0)) }}
                        </div>
                    </div>

                    <div v-for="i in 5" :key="i" class="space-y-1">
                        <div class="flex items-center gap-3">
                            <label class="w-16 text-sm font-medium text-surface-600">Disc {{ i }}</label>
                            <Select v-model="form.details[editingDiscountIndex][`diskon_${i}_tipe`]" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-32" @change="calculateTotals" />
                            <InputNumber
                                v-select-on-focus
                                v-if="form.details[editingDiscountIndex][`diskon_${i}_tipe`] !== 'none'"
                                v-model="form.details[editingDiscountIndex][`diskon_${i}_nilai`]"
                                :min="0"
                                :prefix="form.details[editingDiscountIndex][`diskon_${i}_tipe`] === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                :suffix="
                                    form.details[editingDiscountIndex][`diskon_${i}_tipe`] === 'percent'
                                        ? '%'
                                        : form.details[editingDiscountIndex][`diskon_${i}_tipe`] === 'nominal' && currencySettings.position === 'after'
                                          ? ' ' + currencySettings.symbol
                                          : ''
                                "
                                :locale="getLocale"
                                :minFractionDigits="form.details[editingDiscountIndex][`diskon_${i}_tipe`] === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                                :maxFractionDigits="form.details[editingDiscountIndex][`diskon_${i}_tipe`] === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                                class="flex-1"
                                @update:modelValue="(val) => onDiscountValueChange(i, val)"
                            />
                            <div v-else class="flex-1"></div>
                        </div>
                        <div v-if="form.details[editingDiscountIndex][`diskon_${i}_tipe`] === 'nominal'" class="text-xs text-surface-400 pl-19 ml-16">Max: {{ formatCurrency(getDiscountMaxNominal(form.details[editingDiscountIndex], i)) }}</div>
                    </div>

                    <Divider />
                    <div class="flex justify-between items-center">
                        <span class="text-surface-600">Total Diskon:</span>
                        <span class="text-xl font-bold text-red-500"> -{{ formatCurrency(getItemTotalDiscount(form.details[editingDiscountIndex])) }} </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-surface-600">Subtotal:</span>
                        <span class="text-xl font-bold text-primary">
                            {{ formatCurrency(getItemSubtotal(form.details[editingDiscountIndex])) }}
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
