<script setup>
import { opnamesApi, warehousesApi } from '@/api';
import { useConfirm } from 'primevue/useconfirm';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { useNotification } from '@/composables/useNotification';
import SerialUnitPicker from '@/components/common/SerialUnitPicker.vue';

const notify = useNotification();
const confirm = useConfirm();
const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const { formatQty, formatCurrency, shouldUppercase, getPrimeDateFormatShort, toDateTimeString, now, parseDateTime, isAfterNow, getLocale, getQtyMinFractionDigits, getQtyMaxFractionDigits } = useFormatters();

// Permissions
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

// Mode
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Stock Opname' : 'Buat Stock Opname'));

// Data
const warehouses = ref([]);
const loading = ref(false);
const saving = ref(false);
const loadingAllProducts = ref(false);
const isLoadingFormData = ref(false); // Flag to skip watchers during initial load

// Form
const form = ref({
    warehouse_id: null,
    tanggal_opname: now(),
    mode: 'partial',
    notes: '',
    details: []
});

// Product search (for partial mode)
const productSuggestions = ref([]);
const loadingProducts = ref(false);

// Pagination for full mode
const allProductsLoaded = ref(false);
const currentPage = ref(1);
const totalPages = ref(1);

// Refresh stock
const refreshingStock = ref(false);

// Mode options
const modeOptions = [
    { label: 'Partial - Pilih produk tertentu', value: 'partial' },
    { label: 'Full - Semua produk di warehouse', value: 'full' }
];

// Validation
const errors = ref({});

// Row expansion: produk serial auto-expand → checklist SN hadir di bawah parent
let uidCounter = 0;
const nextUid = () => `d${++uidCounter}`;
const expandedRows = ref({});
function syncExpandedSerial() {
    const map = {};
    for (const d of form.value.details) {
        if (d.is_serial && d._uid) map[d._uid] = true;
    }
    expandedRows.value = map;
}
watch(() => form.value.details.map((d) => `${d._uid}:${d.is_serial ? 1 : 0}`).join('|'), syncExpandedSerial);

// Checklist SN hadir berubah → qty fisik = jumlah hadir; selisih mengikuti
function onSerialPresentChange(detail, ulids) {
    detail.serial_unit_ids_present = ulids;
    detail.qty_physical = ulids.length;
    detail.qty_difference = ulids.length - (detail.qty_system ?? 0);
}

onMounted(async () => {
    await loadWarehouses();

    if (isEdit.value) {
        await loadOpname();
    }
});

// Watch route params change (when navigating from create to edit with same component)
watch(
    () => route.params.ulid,
    async (newUlid) => {
        if (newUlid) {
            // Reset form and load the draft
            await loadOpname();
        }
    }
);

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

async function loadOpname() {
    loading.value = true;
    isLoadingFormData.value = true; // Skip watchers during load
    try {
        const response = await opnamesApi.get(route.params.ulid);
        if (response.data.success) {
            const opname = response.data.data.opname;

            // Check if still draft
            if (opname.status !== 'draft') {
                notify.cannotEditApproved('Stock opname');
                router.push({ name: 'inventory-opname' });
                return;
            }

            form.value = {
                warehouse_id: opname.warehouse_id,
                tanggal_opname: parseDateTime(opname.tanggal_opname),
                mode: opname.mode,
                notes: opname.notes || '',
                details: opname.details.map((d) => ({
                    _uid: nextUid(),
                    product_id: d.product_id,
                    product: d.product,
                    is_serial: !!d.product?.is_serial,
                    serial_unit_ids_present: d.serial_unit_ids_present || (d.product?.is_serial ? [] : null),
                    qty_system: d.qty_system,
                    qty_physical: d.qty_physical,
                    qty_difference: d.qty_difference,
                    avg_cost: d.product?.avg_cost ?? 0,
                    notes: d.notes || ''
                }))
            };
        }
    } catch (error) {
        console.error('Failed to load stock opname:', error);
        notify.loadListError('stock opname');
        router.push({ name: 'inventory-opname' });
    } finally {
        loading.value = false;
        // Use nextTick to ensure form values are set before enabling watchers
        setTimeout(() => {
            isLoadingFormData.value = false;
        }, 100);
    }
}

// Store previous values for confirmation
const previousWarehouseId = ref(null);
const previousMode = ref(null);
const existingDraftWarning = ref(null);

// Check for existing draft when warehouse changes
async function checkExistingDraft(warehouseId) {
    if (!warehouseId || isEdit.value) return false;

    try {
        const response = await opnamesApi.checkDraft({ warehouse_id: warehouseId });
        if (response.data.success && response.data.data.has_draft) {
            const draft = response.data.data.draft;
            existingDraftWarning.value = draft;

            confirm.require({
                message: `Sudah ada draft stock opname untuk warehouse ini: ${draft.nomor_dokumen}. Anda harus menyelesaikan atau menghapus draft tersebut terlebih dahulu.`,
                header: 'Draft Sudah Ada',
                icon: 'pi pi-exclamation-triangle',
                rejectLabel: 'Tutup',
                acceptLabel: 'Lihat Draft',
                rejectClass: 'p-button-secondary p-button-outlined',
                acceptClass: 'p-button-warning',
                accept: () => {
                    router.push({ name: 'inventory-opname-edit', params: { ulid: draft.ulid } });
                },
                reject: () => {
                    form.value.warehouse_id = null;
                    existingDraftWarning.value = null;
                }
            });
            return true;
        }
        existingDraftWarning.value = null;
        return false;
    } catch (error) {
        console.error('Failed to check existing draft:', error);
        return false;
    }
}

// Watch warehouse change
watch(
    () => form.value.warehouse_id,
    async (newVal, oldVal) => {
        // Skip during initial data loading
        if (isLoadingFormData.value) return;

        // Check for existing draft first (only for new opname)
        if (newVal && !isEdit.value) {
            const hasDraft = await checkExistingDraft(newVal);
            if (hasDraft) return;
        }

        // If changing warehouse and has details, confirm reset
        if (oldVal && newVal !== oldVal && form.value.details.length > 0) {
            previousWarehouseId.value = oldVal;
            confirm.require({
                message: 'Mengubah warehouse akan mereset semua detail produk. Lanjutkan?',
                header: 'Konfirmasi',
                icon: 'pi pi-exclamation-triangle',
                rejectLabel: 'Batal',
                acceptLabel: 'Ya, Lanjutkan',
                rejectClass: 'p-button-secondary p-button-outlined',
                accept: () => {
                    form.value.details = [];
                    allProductsLoaded.value = false;
                    currentPage.value = 1;
                    previousWarehouseId.value = null;
                },
                reject: () => {
                    form.value.warehouse_id = previousWarehouseId.value;
                    previousWarehouseId.value = null;
                }
            });
        }
    }
);

// Watch mode change
watch(
    () => form.value.mode,
    (newVal, oldVal) => {
        // Skip during initial data loading
        if (isLoadingFormData.value) return;

        if (oldVal && newVal !== oldVal && form.value.details.length > 0) {
            previousMode.value = oldVal;
            confirm.require({
                message: 'Mengubah mode akan mereset semua detail produk. Lanjutkan?',
                header: 'Konfirmasi',
                icon: 'pi pi-exclamation-triangle',
                rejectLabel: 'Batal',
                acceptLabel: 'Ya, Lanjutkan',
                rejectClass: 'p-button-secondary p-button-outlined',
                accept: () => {
                    form.value.details = [];
                    allProductsLoaded.value = false;
                    currentPage.value = 1;
                    previousMode.value = null;
                },
                reject: () => {
                    form.value.mode = previousMode.value;
                    previousMode.value = null;
                }
            });
        }
    }
);

// Product autocomplete (partial mode)
async function searchProducts(event) {
    if (!form.value.warehouse_id) {
        notify.selectFirst('warehouse');
        return;
    }

    loadingProducts.value = true;
    try {
        const response = await opnamesApi.getProducts({
            warehouse_id: form.value.warehouse_id,
            search: event.query
        });
        if (response.data.success) {
            // Filter out already added products
            const addedProductIds = form.value.details.map((d) => d.product_id);
            productSuggestions.value = response.data.data.items.filter((p) => !addedProductIds.includes(p.id));
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
        const qtySystem = product.stok ?? 0;

        form.value.details[index] = {
            ...form.value.details[index],
            product_id: product.id,
            product: product,
            is_serial: !!product.is_serial,
            serial_unit_ids_present: null, // null = auto (picker centang semua); [] = sengaja kosong
            qty_system: qtySystem,
            qty_physical: qtySystem, // Default to system qty (serial: disetel picker defaultAll)
            qty_difference: 0,
            avg_cost: product.avg_cost ?? 0
        };
    }
}

function addDetail() {
    if (!form.value.warehouse_id) {
        notify.selectFirst('warehouse');
        return;
    }

    form.value.details.push({
        _uid: nextUid(),
        product_id: null,
        product: null,
        is_serial: false,
        serial_unit_ids_present: null,
        qty_system: 0,
        qty_physical: 0,
        qty_difference: 0,
        avg_cost: 0,
        notes: ''
    });
}

function removeDetail(index) {
    form.value.details.splice(index, 1);
}

// ── Scan barcode produk (mode partial) → hitung fisik: scan = +1 qty fisik ──
const scanProduk = ref('');
const scanProdukFeedback = ref(null); // { ok, msg }

async function onScanProduk() {
    const code = (scanProduk.value || '').trim();
    if (!code) return;
    if (!form.value.warehouse_id) {
        notify.selectFirst('warehouse');
        return;
    }
    try {
        const res = await opnamesApi.getProducts({ warehouse_id: form.value.warehouse_id, search: code });
        const items = res.data?.success ? res.data.data.items : [];
        const p = items.find((x) => String(x.barcode) === code) || (items.length === 1 ? items[0] : null);
        if (!p) {
            scanProdukFeedback.value = { ok: false, msg: `Produk barcode "${code}" tidak ditemukan di gudang ini.` };
            return;
        }
        const idx = form.value.details.findIndex((d) => d.product_id === p.id);
        if (idx >= 0) {
            const d = form.value.details[idx];
            if (d.is_serial) {
                scanProdukFeedback.value = { ok: false, msg: `${p.nama_produk} (serial) sudah ada — scan nomor seri di pemilih unit.` };
            } else {
                d.qty_physical = (parseInt(d.qty_physical) || 0) + 1;
                updateDifference(idx);
                scanProdukFeedback.value = { ok: true, msg: `✓ ${p.nama_produk} — qty fisik jadi ${d.qty_physical}.` };
            }
        } else {
            addDetail();
            const i = form.value.details.length - 1;
            onProductSelect({ value: p }, i);
            const d = form.value.details[i];
            if (d.is_serial) {
                scanProdukFeedback.value = { ok: true, msg: `✓ ${p.nama_produk} (serial) ditambahkan — scan nomor seri di pemilih unit.` };
            } else {
                d.qty_physical = 1; // mulai hitung dari 1 (scan = 1 unit fisik)
                updateDifference(i);
                scanProdukFeedback.value = { ok: true, msg: `✓ ${p.nama_produk} ditambahkan — qty fisik 1.` };
            }
        }
    } catch (e) {
        notify.apiError(e, 'Gagal scan produk');
    } finally {
        scanProduk.value = '';
    }
}

function updateDifference(index) {
    const detail = form.value.details[index];
    if (detail) {
        detail.qty_difference = (detail.qty_physical ?? 0) - (detail.qty_system ?? 0);
    }
}

// Load all products for full mode
async function loadAllProducts() {
    if (!form.value.warehouse_id) {
        notify.selectFirst('warehouse');
        return;
    }

    loadingAllProducts.value = true;
    form.value.details = [];
    currentPage.value = 1;
    allProductsLoaded.value = false;

    try {
        await loadProductBatch();
    } finally {
        loadingAllProducts.value = false;
    }
}

async function loadProductBatch() {
    try {
        const response = await opnamesApi.loadAllProducts({
            warehouse_id: form.value.warehouse_id,
            page: currentPage.value,
            per_page: 50
        });

        if (response.data.success) {
            const items = response.data.data.items;
            const pagination = response.data.data.pagination;

            // Add products to details
            items.forEach((product) => {
                form.value.details.push({
                    _uid: nextUid(),
                    product_id: product.id,
                    product: product,
                    is_serial: !!product.is_serial,
                    serial_unit_ids_present: null, // null = auto (picker centang semua); [] = sengaja kosong
                    qty_system: product.stok,
                    qty_physical: product.stok, // Default to system qty
                    qty_difference: 0,
                    avg_cost: product.avg_cost ?? 0,
                    notes: ''
                });
            });

            totalPages.value = pagination.last_page;

            if (currentPage.value >= totalPages.value) {
                allProductsLoaded.value = true;
            }
        }
    } catch (error) {
        console.error('Failed to load products:', error);
        notify.error('Gagal memuat produk');
    }
}

async function loadMoreProducts() {
    if (allProductsLoaded.value || loadingAllProducts.value) return;

    loadingAllProducts.value = true;
    currentPage.value++;

    try {
        await loadProductBatch();
    } finally {
        loadingAllProducts.value = false;
    }
}

function validate() {
    errors.value = {};

    if (!form.value.warehouse_id) {
        errors.value.warehouse_id = 'Warehouse wajib dipilih';
    }

    if (!form.value.tanggal_opname) {
        errors.value.tanggal_opname = 'Tanggal wajib diisi';
    } else if (isAfterNow(form.value.tanggal_opname)) {
        errors.value.tanggal_opname = 'Tanggal tidak boleh lebih dari waktu sekarang';
    }

    if (form.value.details.length === 0) {
        errors.value.details = 'Minimal harus ada 1 detail produk';
    }

    // Validate each detail
    form.value.details.forEach((detail, index) => {
        if (!detail.product_id) {
            errors.value[`details.${index}.product_id`] = 'Produk wajib dipilih';
        }
        if (detail.qty_physical === null || detail.qty_physical === undefined || detail.qty_physical < 0) {
            errors.value[`details.${index}.qty_physical`] = 'Qty fisik wajib diisi dan minimal 0';
        }
    });

    // Check for duplicate products
    const productIds = form.value.details.map((d) => d.product_id).filter(Boolean);
    const uniqueIds = [...new Set(productIds)];
    if (productIds.length !== uniqueIds.length) {
        errors.value.details = 'Tidak boleh ada produk yang sama dalam satu stock opname';
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
        const payload = {
            warehouse_id: form.value.warehouse_id,
            tanggal_opname: toDateTimeString(form.value.tanggal_opname),
            mode: form.value.mode,
            notes: form.value.notes || null,
            details: form.value.details.map((d) => ({
                product_id: d.product_id,
                qty_physical: d.is_serial ? d.serial_unit_ids_present?.length || 0 : d.qty_physical,
                notes: d.notes || null,
                serial_unit_ids_present: d.is_serial ? d.serial_unit_ids_present || [] : null
            }))
        };

        let response;
        if (isEdit.value) {
            response = await opnamesApi.update(route.params.ulid, payload);
        } else {
            response = await opnamesApi.create(payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Stock opname', isEdit.value);
            router.push({ name: 'inventory-opname' });
        }
    } catch (error) {
        console.error('Failed to save stock opname:', error);
        notify.saveError(error);

        // Handle validation errors from server
        if (error.response?.data?.errors) {
            errors.value = { ...errors.value, ...error.response.data.errors };
        }
    } finally {
        saving.value = false;
    }
}

function cancel() {
    router.push({ name: 'inventory-opname' });
}

function getProductLabel(product) {
    if (!product) return '';
    return `${product.kode_produk} - ${product.nama_produk}`;
}

function getDifferenceClass(diff) {
    if (diff > 0) return 'text-green-600 font-semibold';
    if (diff < 0) return 'text-red-500 font-semibold';
    return '';
}

function formatDifference(diff) {
    if (diff > 0) return `+${formatQty(diff)}`;
    return formatQty(diff);
}

// Refresh stock system function
async function refreshStockSystem() {
    if (form.value.details.length === 0) {
        notify.noDataFor('produk', 'refresh');
        return;
    }

    refreshingStock.value = true;
    try {
        const productIds = form.value.details.map((d) => d.product_id).filter(Boolean);
        if (productIds.length === 0) return;

        const response = await opnamesApi.refreshStock({
            warehouse_id: form.value.warehouse_id,
            product_ids: productIds
        });

        if (response.data.success) {
            const updatedItems = response.data.data.items;

            // Update qty_system and avg_cost without changing qty_physical and notes
            form.value.details.forEach((detail, index) => {
                const updated = updatedItems[detail.product_id];
                if (updated) {
                    form.value.details[index].qty_system = updated.stok;
                    form.value.details[index].avg_cost = updated.avg_cost;
                    form.value.details[index].product = {
                        ...form.value.details[index].product,
                        ...updated
                    };
                    // Recalculate difference
                    form.value.details[index].qty_difference = (form.value.details[index].qty_physical ?? 0) - updated.stok;
                }
            });

            notify.refreshed('Stok sistem');
        }
    } catch (error) {
        console.error('Failed to refresh stock:', error);
        notify.refreshError('stok sistem');
    } finally {
        refreshingStock.value = false;
    }
}

// Computed for calculating values
function calculateNilaiPersediaan(detail) {
    const qty = detail.qty_physical ?? 0;
    const avgCost = detail.avg_cost ?? 0;
    return qty * avgCost;
}

function calculateNilaiSelisih(detail) {
    const diff = detail.qty_difference ?? 0;
    const avgCost = detail.avg_cost ?? 0;
    return diff * avgCost;
}

// Summary computed
const summary = computed(() => {
    const total = form.value.details.length;
    const match = form.value.details.filter((d) => d.qty_difference === 0).length;
    const surplus = form.value.details.filter((d) => d.qty_difference > 0).length;
    const shortage = form.value.details.filter((d) => d.qty_difference < 0).length;

    // Calculate totals
    let totalNilaiPersediaan = 0;
    let totalNilaiSelisih = 0;

    form.value.details.forEach((d) => {
        totalNilaiPersediaan += calculateNilaiPersediaan(d);
        totalNilaiSelisih += calculateNilaiSelisih(d);
    });

    return {
        total,
        match,
        surplus,
        shortage,
        totalNilaiPersediaan,
        totalNilaiSelisih
    };
});
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
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Warehouse -->
                <div class="flex flex-col gap-2">
                    <label for="warehouse" class="font-medium">Warehouse <span class="text-red-500">*</span></label>
                    <Select
                        id="warehouse"
                        v-model="form.warehouse_id"
                        :options="warehouses"
                        optionLabel="nama_warehouse"
                        optionValue="id"
                        placeholder="Pilih Warehouse"
                        filter
                        class="w-full"
                        :class="{ 'p-invalid': errors.warehouse_id }"
                        :disabled="isEdit"
                    />
                    <small v-if="errors.warehouse_id" class="text-red-500">{{ errors.warehouse_id }}</small>
                </div>

                <!-- Tanggal Opname -->
                <div class="flex flex-col gap-2">
                    <label for="tanggal_opname" class="font-medium">Tanggal & Jam <span class="text-red-500">*</span></label>
                    <DatePicker id="tanggal_opname" v-model="form.tanggal_opname" showTime hourFormat="24" :maxDate="new Date()" :dateFormat="getPrimeDateFormatShort" class="w-full" :class="{ 'p-invalid': errors.tanggal_opname }" showIcon />
                    <small v-if="errors.tanggal_opname" class="text-red-500">{{ errors.tanggal_opname }}</small>
                </div>

                <!-- Mode -->
                <div class="flex flex-col gap-2">
                    <label for="mode" class="font-medium">Mode <span class="text-red-500">*</span></label>
                    <Select id="mode" v-model="form.mode" :options="modeOptions" optionLabel="label" optionValue="value" class="w-full" filter :disabled="isEdit" />
                </div>
            </div>

            <!-- Notes -->
            <div class="flex flex-col gap-2 mb-6">
                <label for="notes" class="font-medium">Catatan</label>
                <Textarea id="notes" v-model="form.notes" rows="2" class="w-full" placeholder="Catatan stock opname..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
            </div>

            <!-- Details Section -->
            <div class="border border-surface-200 rounded-lg p-4">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <h3 class="text-lg font-medium m-0">Detail Produk</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Partial mode: scan barcode produk → hitung fisik (+1 per scan) -->
                        <IconField v-if="form.mode === 'partial'" iconPosition="left">
                            <InputIcon class="pi pi-qrcode" />
                            <InputText v-model="scanProduk" @keyup.enter="onScanProduk" placeholder="Scan barcode produk lalu Enter…" :disabled="!form.warehouse_id" style="width: 230px" />
                        </IconField>
                        <!-- Refresh stock button -->
                        <Button
                            v-if="form.details.length > 0"
                            label="Refresh Stok Sistem"
                            icon="pi pi-refresh"
                            size="small"
                            severity="secondary"
                            outlined
                            :loading="refreshingStock"
                            @click="refreshStockSystem"
                            v-tooltip.top="'Refresh stok sistem tanpa menghapus input Anda'"
                        />
                        <!-- Partial mode: add button -->
                        <Button v-if="form.mode === 'partial'" label="Tambah" icon="pi pi-plus" size="small" @click="addDetail" />
                        <!-- Full mode: load all button -->
                        <Button v-if="form.mode === 'full'" label="Load Semua Produk" icon="pi pi-download" size="small" :loading="loadingAllProducts" @click="loadAllProducts" :disabled="!form.warehouse_id" />
                    </div>
                </div>
                <small v-if="scanProdukFeedback" :class="scanProdukFeedback.ok ? 'text-green-600' : 'text-red-500'" class="block mb-2 text-xs">{{ scanProdukFeedback.msg }}</small>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <!-- Summary -->
                <div v-if="form.details.length > 0" class="flex flex-wrap gap-4 mb-4 text-sm">
                    <span
                        >Total: <strong>{{ summary.total }}</strong></span
                    >
                    <span class="text-surface-500"
                        >Cocok: <strong>{{ summary.match }}</strong></span
                    >
                    <span class="text-green-600"
                        >Lebih: <strong>{{ summary.surplus }}</strong></span
                    >
                    <span class="text-red-500"
                        >Kurang: <strong>{{ summary.shortage }}</strong></span
                    >
                    <template v-if="canViewHpp">
                        <span class="border-l border-surface-300 pl-4"
                            >Total Nilai: <strong>{{ formatCurrency(summary.totalNilaiPersediaan) }}</strong></span
                        >
                        <span :class="summary.totalNilaiSelisih >= 0 ? 'text-green-600' : 'text-red-500'">
                            Nilai Selisih: <strong>{{ formatCurrency(summary.totalNilaiSelisih) }}</strong>
                        </span>
                    </template>
                </div>

                <!-- Detail Table -->
                <DataTable :value="form.details" class="p-datatable-sm" responsiveLayout="scroll" v-if="form.details.length > 0" scrollable scrollHeight="400px" dataKey="_uid" v-model:expandedRows="expandedRows">
                    <Column expander style="width: 3rem" />
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="Produk" style="min-width: 250px">
                        <template #body="{ data, index }">
                            <!-- Partial mode: autocomplete -->
                            <AutoComplete
                                v-if="form.mode === 'partial'"
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
                                        <span class="text-xs text-surface-400">Stok: {{ formatQty(option.stok) }}</span>
                                    </div>
                                </template>
                            </AutoComplete>
                            <!-- Full mode: display only -->
                            <div v-else>
                                <span class="font-medium">{{ data.product?.kode_produk }}</span>
                                <br />
                                <span class="text-sm text-surface-500">{{ data.product?.nama_produk }}</span>
                            </div>
                            <small v-if="errors[`details.${index}.product_id`]" class="text-red-500">
                                {{ errors[`details.${index}.product_id`] }}
                            </small>
                        </template>
                    </Column>

                    <Column header="Stok Sistem" style="width: 100px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-medium">{{ formatQty(data.qty_system) }}</span>
                        </template>
                    </Column>

                    <Column v-if="canViewHpp" header="HPP/Unit" style="width: 120px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="text-surface-600">{{ formatCurrency(data.avg_cost) }}</span>
                        </template>
                    </Column>

                    <Column header="Stok Fisik" style="width: 130px">
                        <template #body="{ data, index }">
                            <div v-if="data.is_serial">
                                <Tag :value="`${data.serial_unit_ids_present?.length || 0} hadir`" severity="info" />
                                <div class="text-xs text-surface-500 mt-1">centang SN ↓</div>
                            </div>
                            <InputNumber
                                v-else
                                v-select-on-focus
                                v-model="data.qty_physical"
                                :min="0"
                                :locale="getLocale"
                                :minFractionDigits="getQtyMinFractionDigits"
                                :maxFractionDigits="getQtyMaxFractionDigits"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.qty_physical`] }"
                                @update:modelValue="() => updateDifference(index)"
                            />
                        </template>
                    </Column>

                    <Column v-if="canViewHpp" header="Nilai Persediaan" style="width: 140px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-medium">{{ formatCurrency(calculateNilaiPersediaan(data)) }}</span>
                        </template>
                    </Column>

                    <Column header="Selisih" style="width: 100px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span :class="getDifferenceClass(data.qty_difference)">
                                {{ formatDifference(data.qty_difference) }}
                            </span>
                        </template>
                    </Column>

                    <Column v-if="canViewHpp" header="Nilai Selisih" style="width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span :class="getDifferenceClass(data.qty_difference)">
                                {{ formatCurrency(calculateNilaiSelisih(data)) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Notes" style="min-width: 150px">
                        <template #body="{ data }">
                            <InputText v-model="data.notes" class="w-full" placeholder="Catatan..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                        </template>
                    </Column>

                    <Column v-if="form.mode === 'partial'" header="" style="width: 50px">
                        <template #body="{ index }">
                            <Button icon="pi pi-trash" severity="danger" text rounded @click="removeDetail(index)" />
                        </template>
                    </Column>

                    <!-- Checklist SN hadir untuk produk serial (default semua tercentang) -->
                    <template #expansion="{ data }">
                        <div v-if="data.is_serial && data.product_id" class="px-4 py-3 bg-surface-50 dark:bg-surface-800">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="pi pi-qrcode text-primary"></i>
                                <span class="font-medium text-sm"> Centang SN yang HADIR (fisik ditemukan) — {{ data.product?.kode_produk }} {{ data.product?.nama_produk }} </span>
                            </div>
                            <SerialUnitPicker :productId="data.product?.ulid" :warehouseId="form.warehouse_id" :defaultAll="true" :modelValue="data.serial_unit_ids_present" @update:modelValue="(v) => onSerialPresentChange(data, v)" />
                        </div>
                        <div v-else class="px-4 py-2 text-xs text-surface-400">Produk non-serial — isi qty fisik di kolom.</div>
                    </template>
                </DataTable>

                <!-- Load more button for full mode -->
                <div v-if="form.mode === 'full' && form.details.length > 0 && !allProductsLoaded" class="flex justify-center mt-4">
                    <Button label="Load Lebih Banyak" icon="pi pi-angle-down" severity="secondary" outlined :loading="loadingAllProducts" @click="loadMoreProducts" />
                    <span class="ml-4 text-surface-500 text-sm self-center"> Halaman {{ currentPage }} dari {{ totalPages }} </span>
                </div>

                <!-- Empty State -->
                <div v-if="form.details.length === 0" class="text-center py-8 text-surface-500">
                    <i class="pi pi-box text-4xl mb-4 block"></i>
                    <p class="m-0" v-if="form.mode === 'partial'">Belum ada detail produk. Klik "Tambah" untuk menambahkan.</p>
                    <p class="m-0" v-else>Klik "Load Semua Produk" untuk memuat produk dari warehouse.</p>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end gap-2 mt-6">
                <Button label="Batal" severity="secondary" outlined @click="cancel" />
                <Button label="Simpan" icon="pi pi-save" type="submit" :loading="saving" :disabled="form.details.length === 0" />
            </div>
        </form>
    </div>
</template>
