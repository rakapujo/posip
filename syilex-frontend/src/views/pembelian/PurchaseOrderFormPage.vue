<script setup>
import { purchaseOrdersApi, warehousesApi, suppliersApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch, nextTick } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
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
const pageTitle = computed(() => (isEdit.value ? 'Edit Purchase Order' : 'Buat Purchase Order'));

// Data
const suppliers = ref([]);
const warehouses = ref([]);
const loading = ref(false);
const saving = ref(false);
const taxSettings = ref({ name: 'PPN', percent: 11, included_in_hpp: false });

// Form
const form = ref({
    tanggal_po: now(),
    supplier_id: null,
    warehouse_id: null,
    no_doc_referensi: '',
    tempo_hari: 0,
    notes: '',
    // Cash / lunas langsung (hutang dibuat lalu auto-lunas saat approve)
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
    biaya_lain_nama: 'Biaya Lain-lain',
    biaya_lain_tipe: 'none',
    biaya_lain_nilai: 0,
    // Details
    details: []
});

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

// Product search
const productSuggestions = ref([]);
const loadingProducts = ref(false);

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

onMounted(async () => {
    await Promise.all([loadSuppliers(), loadWarehouses(), loadTaxSettings()]);

    if (isEdit.value) {
        await loadPurchaseOrder();
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
        const response = await purchaseOrdersApi.getTaxSettings();
        if (response.data.success) {
            taxSettings.value = response.data.data.tax;
        }
    } catch (error) {
        console.error('Failed to load tax settings:', error);
        notify.apiError(error, 'Gagal load tax settings');
    }
}

async function loadPurchaseOrder() {
    loading.value = true;
    try {
        const response = await purchaseOrdersApi.get(route.params.ulid);
        if (response.data.success) {
            const po = response.data.data.purchase_order;

            if (po.status !== 'draft') {
                notify.cannotEditApproved('PO');
                router.push({ name: 'pembelian-po' });
                return;
            }

            form.value = {
                tanggal_po: parseDateTime(po.tanggal_po),
                supplier_id: po.supplier_id,
                warehouse_id: po.warehouse_id,
                no_doc_referensi: po.no_doc_referensi || '',
                tempo_hari: po.tempo_hari || 0,
                notes: po.notes || '',
                cash_payment: !!po.cash_payment,
                cash_metode: po.cash_metode || 'cash',
                cash_no_referensi: po.cash_no_referensi || '',
                cash_bank_nama: po.cash_bank_nama || '',
                cash_bank_rekening: po.cash_bank_rekening || '',
                diskon_1_tipe: po.diskon_1_tipe || 'none',
                diskon_1_nilai: po.diskon_1_nilai || 0,
                diskon_2_tipe: po.diskon_2_tipe || 'none',
                diskon_2_nilai: po.diskon_2_nilai || 0,
                diskon_3_tipe: po.diskon_3_tipe || 'none',
                diskon_3_nilai: po.diskon_3_nilai || 0,
                biaya_kirim_tipe: po.biaya_kirim_tipe || 'none',
                biaya_kirim_nilai: po.biaya_kirim_nilai || 0,
                biaya_lain_nama: po.biaya_lain_nama || '',
                biaya_lain_tipe: po.biaya_lain_tipe || 'none',
                biaya_lain_nilai: po.biaya_lain_nilai || 0,
                details: po.details.map((d) => ({
                    product_id: d.product_id,
                    product: d.product,
                    unit_used: d.unit_used,
                    unit_konversi: d.unit_konversi,
                    units: getProductUnits(d.product),
                    qty_in_unit: d.qty_in_unit,
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

            await calculateTotals();
        }
    } catch (error) {
        console.error('Failed to load PO:', error);
        notify.loadListError('purchase order');
        router.push({ name: 'pembelian-po' });
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

// Watch supplier change to set default tempo
watch(
    () => form.value.supplier_id,
    (newVal) => {
        if (newVal) {
            const supplier = suppliers.value.find((s) => s.id === newVal);
            if (supplier && supplier.tempo_default !== undefined) {
                form.value.tempo_hari = supplier.tempo_default;
            }
        }
    }
);

// Product autocomplete
async function searchProducts(event) {
    loadingProducts.value = true;
    try {
        const response = await purchaseOrdersApi.getProducts({
            search: event.query
        });
        if (response.data.success) {
            productSuggestions.value = response.data.data.items;
        }
    } catch (error) {
        console.error('Failed to search products:', error);
        notify.apiError(error, 'Gagal search products');
    } finally {
        loadingProducts.value = false;
    }
}

function onProductSelect(event, index) {
    const product = event.value || event;
    if (product && typeof product === 'object' && product.id) {
        // Filter unique units by name
        const rawUnits = product.units || [];
        const seenUnits = new Set();
        const units = rawUnits.filter((u) => {
            if (seenUnits.has(u.unit)) return false;
            seenUnits.add(u.unit);
            return true;
        });

        const defaultUnit = units[0] || { unit: '', konversi: 1 };

        form.value.details[index] = {
            ...form.value.details[index],
            product_id: product.id,
            product: product,
            units: units,
            unit_used: defaultUnit.unit,
            unit_konversi: defaultUnit.konversi,
            qty_in_unit: 1,
            harga_per_unit: 0
        };

        // Try to get last price
        getLastPrice(index, product.id, form.value.supplier_id, defaultUnit.unit);
    }
}

async function getLastPrice(index, productId, supplierId, unit) {
    try {
        const params = { product_id: productId };
        if (supplierId) params.supplier_id = supplierId;
        if (unit) params.unit = unit;

        const response = await purchaseOrdersApi.getLastPrice(params);
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
            // Try to get last price for new unit
            getLastPrice(index, detail.product_id, form.value.supplier_id, detail.unit_used);
        }
    }
    calculateTotals();
}

function addDetail() {
    form.value.details.push({
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
            const payload = buildPayload();
            const response = await purchaseOrdersApi.calculate(payload);
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
        tanggal_po: toDateTimeString(form.value.tanggal_po),
        supplier_id: form.value.supplier_id,
        warehouse_id: form.value.warehouse_id,
        no_doc_referensi: form.value.no_doc_referensi || null,
        tempo_hari: form.value.tempo_hari,
        notes: form.value.notes || null,
        cash_payment: !!form.value.cash_payment,
        cash_metode: form.value.cash_payment ? form.value.cash_metode : null,
        cash_no_referensi: form.value.cash_payment ? form.value.cash_no_referensi || null : null,
        cash_bank_nama: form.value.cash_payment && form.value.cash_metode === 'transfer' ? form.value.cash_bank_nama || null : null,
        cash_bank_rekening: form.value.cash_payment && form.value.cash_metode === 'transfer' ? form.value.cash_bank_rekening || null : null,
        diskon_1_tipe: form.value.diskon_1_tipe,
        diskon_1_nilai: form.value.diskon_1_nilai,
        diskon_2_tipe: form.value.diskon_2_tipe,
        diskon_2_nilai: form.value.diskon_2_nilai,
        diskon_3_tipe: form.value.diskon_3_tipe,
        diskon_3_nilai: form.value.diskon_3_nilai,
        biaya_kirim_tipe: form.value.biaya_kirim_tipe,
        biaya_kirim_nilai: form.value.biaya_kirim_nilai,
        biaya_lain_nama: form.value.biaya_lain_nama || null,
        biaya_lain_tipe: form.value.biaya_lain_tipe,
        biaya_lain_nilai: form.value.biaya_lain_nilai,
        details: form.value.details.map((d) => ({
            product_id: d.product_id,
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
    if (!form.value.tanggal_po) {
        errors.value.tanggal_po = 'Tanggal wajib diisi';
    }
    if (form.value.details.length === 0) {
        errors.value.details = 'Minimal harus ada 1 detail produk';
    }

    // Check for duplicate product + unit combinations
    const seenProductUnits = new Set();
    form.value.details.forEach((detail, index) => {
        if (detail.product_id && detail.unit_used) {
            const key = `${detail.product_id}-${detail.unit_used}`;
            if (seenProductUnits.has(key)) {
                errors.value[`details.${index}.product_id`] = 'Produk dengan satuan yang sama sudah ada';
            } else {
                seenProductUnits.add(key);
            }
        }
    });

    // Validate each detail
    form.value.details.forEach((detail, index) => {
        if (!detail.product_id) {
            errors.value[`details.${index}.product_id`] = 'Produk wajib dipilih';
        }
        if (!detail.unit_used) {
            errors.value[`details.${index}.unit_used`] = 'Satuan wajib dipilih';
        }
        if (!detail.qty_in_unit || detail.qty_in_unit < 1) {
            errors.value[`details.${index}.qty_in_unit`] = 'Qty minimal 1';
        }
        if (detail.harga_per_unit < 0) {
            errors.value[`details.${index}.harga_per_unit`] = 'Harga tidak boleh negatif';
        }
    });

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
            response = await purchaseOrdersApi.update(route.params.ulid, payload);
        } else {
            response = await purchaseOrdersApi.create(payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Purchase order', isEdit.value);
            router.push({ name: 'pembelian-po' });
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
    router.push({ name: 'pembelian-po' });
}

function getProductLabel(product) {
    if (!product) return '';
    return `${product.kode_produk} - ${product.nama_produk}`;
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
    return data.product_id && data.unit_used && data.qty_in_unit > 0 && data.harga_per_unit > 0;
}

// Reset all discounts for a detail item
function resetDiscount(index) {
    const detail = form.value.details[index];
    for (let i = 1; i <= 5; i++) {
        detail[`diskon_${i}_tipe`] = 'none';
        detail[`diskon_${i}_nilai`] = 0;
    }
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
                    <label for="tanggal" class="font-medium">Tanggal PO <span class="text-red-500">*</span></label>
                    <DatePicker id="tanggal" v-model="form.tanggal_po" :dateFormat="getPrimeDateFormatShort" class="w-full" :class="{ 'p-invalid': errors.tanggal_po }" showIcon showTime hourFormat="24" />
                    <small v-if="errors.tanggal_po" class="text-red-500">{{ errors.tanggal_po }}</small>
                </div>

                <!-- Tempo -->
                <div class="flex flex-col gap-2">
                    <label for="tempo" class="font-medium">Tempo (Hari)</label>
                    <InputNumber v-select-on-focus id="tempo" v-model="form.tempo_hari" :min="0" class="w-full" />
                </div>
            </div>

            <!-- Second Row: No Doc Referensi -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <!-- No Doc Referensi -->
                <div class="flex flex-col gap-2">
                    <label for="no_doc_referensi" class="font-medium">No. Doc Referensi</label>
                    <InputText id="no_doc_referensi" v-model="form.no_doc_referensi" placeholder="No. dokumen dari supplier" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                    <small class="text-surface-500">Referensi nomor dokumen dari supplier (opsional)</small>
                </div>
            </div>

            <!-- Cash / Lunas langsung -->
            <div class="mb-6 p-3 rounded-lg border border-surface-200 dark:border-surface-700">
                <div class="flex items-center gap-2">
                    <Checkbox v-model="form.cash_payment" :binary="true" inputId="cash_payment" :disabled="!form.supplier_id" />
                    <label for="cash_payment" class="font-medium cursor-pointer">Cash / Lunas langsung</label>
                    <small v-if="!form.supplier_id" class="text-surface-400">(pilih supplier dulu)</small>
                </div>
                <small class="block text-surface-500 mt-1">Hutang tetap dibuat saat approve, lalu otomatis dilunasi penuh.</small>

                <div v-if="form.cash_payment" class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Metode Bayar</label>
                        <Select v-model="form.cash_metode" :options="cashMetodeOptions" optionLabel="label" optionValue="value" class="w-full" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">No. Referensi <span class="text-surface-400">(bukti/kwitansi)</span></label>
                        <InputText v-model="form.cash_no_referensi" placeholder="No. bukti/kwitansi" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" maxlength="100" />
                    </div>
                    <template v-if="form.cash_metode === 'transfer'">
                        <div class="flex flex-col gap-2">
                            <label class="font-medium">Nama Bank</label>
                            <InputText v-model="form.cash_bank_nama" class="w-full" maxlength="100" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <label class="font-medium">No. Rekening</label>
                            <InputText v-model="form.cash_bank_rekening" class="w-full" maxlength="50" />
                        </div>
                    </template>
                </div>
            </div>

            <!-- Detail Products Section -->
            <div class="border border-surface-200 rounded-lg p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium m-0">Detail Produk</h3>
                    <Button label="Tambah Produk" icon="pi pi-plus" size="small" @click="addDetail" />
                </div>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <DataTable :value="form.details" class="p-datatable-sm" responsiveLayout="scroll" v-if="form.details.length > 0">
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="Produk" style="min-width: 250px">
                        <template #body="{ data, index }">
                            <AutoComplete
                                v-model="data.product"
                                :suggestions="productSuggestions"
                                @complete="searchProducts"
                                @item-select="(e) => onProductSelect(e, index)"
                                :optionLabel="getProductLabel"
                                placeholder="Cari produk..."
                                :loading="loadingProducts"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.product_id`] }"
                                dropdown
                            >
                                <template #option="{ option }">
                                    <div class="flex flex-col">
                                        <span class="font-medium">{{ option.kode_produk }}</span>
                                        <span class="text-sm text-surface-500">{{ option.nama_produk }}</span>
                                    </div>
                                </template>
                            </AutoComplete>
                        </template>
                    </Column>

                    <Column header="Satuan" style="width: 140px">
                        <template #body="{ data, index }">
                            <!-- Show as text if only 1 unit -->
                            <span v-if="data.units && data.units.length === 1" class="font-medium"> {{ data.unit_used }} ({{ data.unit_konversi }}) </span>
                            <!-- Show dropdown if multiple units -->
                            <Select v-else v-model="data.unit_used" :options="data.units" optionValue="unit" placeholder="Satuan" class="w-full" :class="{ 'p-invalid': errors[`details.${index}.unit_used`] }" @change="onUnitChange(index)">
                                <template #value="{ value }">
                                    <span v-if="value"> {{ value }} ({{ data.units.find((u) => u.unit === value)?.konversi || 1 }}) </span>
                                    <span v-else class="text-surface-400">Satuan</span>
                                </template>
                                <template #option="{ option }"> {{ option.unit }} ({{ option.konversi }}) </template>
                            </Select>
                        </template>
                    </Column>

                    <Column header="Qty" style="width: 80px">
                        <template #body="{ data, index }">
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
                        </template>
                    </Column>

                    <Column header="Harga/Unit" style="width: 150px">
                        <template #body="{ data }">
                            <InputNumber
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
                            <div v-if="hasDiscount(data)" class="flex items-center gap-1">
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

                    <Column header="" style="width: 50px">
                        <template #body="{ index }">
                            <Button icon="pi pi-trash" severity="danger" text rounded @click="removeDetail(index)" />
                        </template>
                    </Column>
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

                    <!-- Additional Costs -->
                    <div class="border border-surface-200 rounded-lg p-4">
                        <h4 class="font-medium mb-4">Biaya Tambahan</h4>
                        <div class="space-y-3">
                            <div class="flex gap-2 items-center">
                                <label class="w-28 text-sm">Biaya Kirim</label>
                                <Select v-model="form.biaya_kirim_tipe" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-28" @change="calculateTotals" />
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
                                <InputText v-model="form.biaya_lain_nama" placeholder="Nama Biaya Lain" class="w-28" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                                <Select v-model="form.biaya_lain_tipe" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-28" @change="calculateTotals" />
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
                        <Textarea id="notes" v-model="form.notes" rows="2" class="w-full" placeholder="Catatan untuk PO ini..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
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
            <div class="flex justify-end gap-2">
                <Button label="Batal" severity="secondary" outlined @click="cancel" />
                <Button label="Simpan" icon="pi pi-save" type="submit" :loading="saving" :disabled="form.details.length === 0" />
            </div>
        </form>

        <!-- Discount Dialog -->
        <Dialog v-model:visible="discountDialog" header="Diskon Item" modal :style="{ width: '400px' }" :closable="true" @hide="closeDiscountDialog">
            <template v-if="editingDiscountIndex !== null && form.details[editingDiscountIndex]">
                <div class="space-y-4">
                    <!-- Discount mode info -->
                    <div class="flex items-center gap-2 text-sm">
                        <i class="pi pi-info-circle text-primary"></i>
                        <span class="text-surface-600">Mode Perhitungan:</span>
                        <span class="font-medium">{{ discountModeLabel }}</span>
                    </div>

                    <!-- Product info -->
                    <div class="bg-surface-50 rounded-lg p-3">
                        <div class="font-medium">{{ form.details[editingDiscountIndex].product?.nama_produk || '-' }}</div>
                        <div class="text-sm text-surface-500">
                            {{ formatQty(form.details[editingDiscountIndex].qty_in_unit) }} {{ form.details[editingDiscountIndex].unit_used }} × {{ formatCurrency(form.details[editingDiscountIndex].harga_per_unit) }} =
                            {{ formatCurrency((form.details[editingDiscountIndex].qty_in_unit || 0) * (form.details[editingDiscountIndex].harga_per_unit || 0)) }}
                        </div>
                    </div>

                    <!-- Discount lines -->
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
                        <!-- Show max info for nominal -->
                        <div v-if="form.details[editingDiscountIndex][`diskon_${i}_tipe`] === 'nominal'" class="text-xs text-surface-400 pl-19 ml-16">Max: {{ formatCurrency(getDiscountMaxNominal(form.details[editingDiscountIndex], i)) }}</div>
                    </div>

                    <!-- Summary -->
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
    </div>
</template>
