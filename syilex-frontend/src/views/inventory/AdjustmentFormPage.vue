<script setup>
import { adjustmentsApi, warehousesApi } from '@/api';
import { useConfirm } from 'primevue/useconfirm';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import SerialUnitPicker from '@/components/common/SerialUnitPicker.vue';

const notify = useNotification();
const confirm = useConfirm();
const router = useRouter();
const route = useRoute();
const { formatQty, shouldUppercase, getPrimeDateFormatShort, toDateTimeString, now, parseDateTime, isAfterNow, getLocale, getQtyMinFractionDigits, getQtyMaxFractionDigits } = useFormatters();

// Mode
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Adjustment Stok' : 'Buat Adjustment Stok'));

// Data
const warehouses = ref([]);
const loading = ref(false);
const saving = ref(false);
const negativeStockAllowed = ref(false);

// Form
const form = ref({
    warehouse_id: null,
    tanggal: now(),
    keterangan: '',
    details: []
});

// Product search
const productSuggestions = ref([]);
const loadingProducts = ref(false);

// Jenis options
const jenisOptions = [
    { label: 'Debit (Masuk)', value: 'debit' },
    { label: 'Kredit (Keluar)', value: 'kredit' }
];

// Validation
const errors = ref({});

// Row expansion: produk serial (kredit) auto-expand → pemilih unit di bawah parent
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

// Status fate unit serial saat keluar (adjustment manual): Rusak / Hilang (default Rusak)
const serialOutStatusOptions = [
    { label: 'Rusak', value: 'rusak' },
    { label: 'Hilang', value: 'hilang' }
];

// Pilihan unit serial berubah → qty & stok_akhir; rekonsiliasi status fate per unit (default 'rusak')
function onSerialChange(detail, ulids) {
    detail.serial_unit_ids = ulids;
    detail.qty = ulids.length;
    detail.stok_akhir = calculateStokAkhir(detail.stok_sistem, 'kredit', ulids.length);
    const prev = detail.serial_unit_statuses || {};
    const next = {};
    for (const ulid of ulids) next[ulid] = prev[ulid] || 'rusak';
    detail.serial_unit_statuses = next;
}

// Objek unit terpilih (untuk tampilkan kode_internal/SN di samping pemilih status)
function onSerialUnitsObjs(detail, units) {
    detail.serial_unit_objs = units;
}

onMounted(async () => {
    await Promise.all([loadWarehouses(), loadStockSetting()]);

    if (isEdit.value) {
        await loadAdjustment();
    }
});

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

async function loadStockSetting() {
    try {
        const response = await adjustmentsApi.getStockSetting();
        if (response.data.success) {
            negativeStockAllowed.value = response.data.data.negative_stock_allowed;
        }
    } catch (error) {
        console.error('Failed to load stock setting:', error);
        notify.apiError(error, 'Gagal load stock setting');
    }
}

async function loadAdjustment() {
    loading.value = true;
    try {
        const response = await adjustmentsApi.get(route.params.ulid);
        if (response.data.success) {
            const adj = response.data.data.adjustment;

            // Check if still draft
            if (adj.status !== 'draft') {
                notify.cannotEditApproved('Adjustment');
                router.push({ name: 'inventory-adjustment' });
                return;
            }

            form.value = {
                warehouse_id: adj.warehouse_id,
                tanggal: parseDateTime(adj.tanggal),
                keterangan: adj.keterangan || '',
                details: adj.details.map((d) => ({
                    _uid: nextUid(),
                    product_id: d.product_id,
                    product: d.product,
                    is_serial: !!d.product?.is_serial,
                    serial_unit_ids: d.serial_unit_ids || (d.product?.is_serial ? [] : null),
                    serial_unit_statuses: d.serial_unit_statuses || (d.product?.is_serial ? {} : null),
                    serial_unit_objs: [],
                    jenis: d.jenis,
                    stok_sistem: d.stok_sistem,
                    qty: d.qty,
                    stok_akhir: d.stok_akhir,
                    notes: d.notes || ''
                }))
            };
        }
    } catch (error) {
        console.error('Failed to load adjustment:', error);
        notify.loadListError('adjustment');
        router.push({ name: 'inventory-adjustment' });
    } finally {
        loading.value = false;
    }
}

// Store previous warehouse for confirmation
const previousWarehouseId = ref(null);

// Watch warehouse change - reset details when warehouse changes
watch(
    () => form.value.warehouse_id,
    (newVal, oldVal) => {
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

// Product autocomplete
async function searchProducts(event) {
    if (!form.value.warehouse_id) {
        notify.selectFirst('warehouse');
        return;
    }

    loadingProducts.value = true;
    try {
        const response = await adjustmentsApi.getProducts({
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
    // PrimeVue 4 AutoComplete @item-select event: { originalEvent, value }
    const product = event.value || event;
    if (product && typeof product === 'object' && product.id) {
        const currentDetail = form.value.details[index];
        const stokSistem = product.stok ?? 0;
        const isSerial = !!product.is_serial;
        // Produk serial hanya boleh kredit (keluar); qty diturunkan dari unit dipilih
        const jenis = isSerial ? 'kredit' : currentDetail.jenis || 'kredit';
        const qty = isSerial ? 0 : currentDetail.qty || 1;

        form.value.details[index] = {
            ...currentDetail,
            product_id: product.id,
            product: product,
            is_serial: isSerial,
            serial_unit_ids: isSerial ? [] : null,
            serial_unit_statuses: isSerial ? {} : null,
            serial_unit_objs: [],
            jenis: jenis,
            qty: qty,
            stok_sistem: stokSistem,
            stok_akhir: calculateStokAkhir(stokSistem, jenis, qty)
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
        serial_unit_ids: null,
        serial_unit_statuses: null,
        serial_unit_objs: [],
        jenis: 'kredit',
        stok_sistem: 0,
        qty: 1,
        stok_akhir: 0,
        notes: ''
    });
}

function removeDetail(index) {
    form.value.details.splice(index, 1);
}

// ── Scan barcode produk → tambah baris / tambah qty (reuse getProducts, tanpa endpoint baru) ──
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
        const res = await adjustmentsApi.getProducts({ warehouse_id: form.value.warehouse_id, search: code });
        const items = res.data?.success ? res.data.data.items : [];
        const p = items.find((x) => String(x.barcode) === code) || (items.length === 1 ? items[0] : null);
        if (!p) {
            scanProdukFeedback.value = { ok: false, msg: `Produk barcode "${code}" tidak ditemukan di gudang ini.` };
            scanProduk.value = '';
            return;
        }
        const idx = form.value.details.findIndex((d) => d.product_id === p.id);
        if (idx >= 0) {
            const d = form.value.details[idx];
            if (d.is_serial) {
                scanProdukFeedback.value = { ok: false, msg: `${p.nama_produk} (serial) sudah ada — scan nomor seri di pemilih unit.` };
            } else {
                d.qty = (parseInt(d.qty) || 0) + 1;
                updateStokAkhir(idx);
                scanProdukFeedback.value = { ok: true, msg: `✓ ${p.nama_produk} — qty jadi ${d.qty}.` };
            }
        } else {
            addDetail();
            onProductSelect({ value: p }, form.value.details.length - 1);
            const d = form.value.details[form.value.details.length - 1];
            scanProdukFeedback.value = d.is_serial ? { ok: true, msg: `✓ ${p.nama_produk} (serial) ditambahkan — scan nomor seri di pemilih unit.` } : { ok: true, msg: `✓ ${p.nama_produk} ditambahkan.` };
        }
    } catch (e) {
        notify.apiError(e, 'Gagal scan produk');
    } finally {
        scanProduk.value = '';
    }
}

function calculateStokAkhir(stokSistem, jenis, qty) {
    const q = parseInt(qty) || 0;
    return jenis === 'debit' ? stokSistem + q : stokSistem - q;
}

function updateStokAkhir(index) {
    const detail = form.value.details[index];
    if (detail) {
        detail.stok_akhir = calculateStokAkhir(detail.stok_sistem, detail.jenis, detail.qty);
    }
}

function hasNegativeStock() {
    return form.value.details.some((d) => d.stok_akhir < 0);
}

function getNegativeStockItems() {
    return form.value.details.filter((d) => d.stok_akhir < 0);
}

function validate() {
    errors.value = {};

    if (!form.value.warehouse_id) {
        errors.value.warehouse_id = 'Warehouse wajib dipilih';
    }

    if (!form.value.tanggal) {
        errors.value.tanggal = 'Tanggal wajib diisi';
    } else if (isAfterNow(form.value.tanggal)) {
        errors.value.tanggal = 'Tanggal tidak boleh lebih dari waktu sekarang';
    }

    if (form.value.details.length === 0) {
        errors.value.details = 'Minimal harus ada 1 detail produk';
    }

    // Validate each detail
    form.value.details.forEach((detail, index) => {
        if (!detail.product_id) {
            errors.value[`details.${index}.product_id`] = 'Produk wajib dipilih';
        }
        if (detail.is_serial) {
            if (detail.jenis !== 'kredit') {
                errors.value[`details.${index}.qty`] = 'Produk serial hanya bisa keluar (kredit)';
            } else if (!detail.serial_unit_ids || detail.serial_unit_ids.length < 1) {
                errors.value[`details.${index}.qty`] = 'Pilih minimal 1 unit serial';
            }
        } else if (!detail.qty || detail.qty < 1) {
            errors.value[`details.${index}.qty`] = 'Qty minimal 1';
        }
    });

    // Check for duplicate products
    const productIds = form.value.details.map((d) => d.product_id).filter(Boolean);
    const uniqueIds = [...new Set(productIds)];
    if (productIds.length !== uniqueIds.length) {
        errors.value.details = 'Tidak boleh ada produk yang sama dalam satu adjustment';
    }

    // Check negative stock (warning only if not allowed)
    if (!negativeStockAllowed.value && hasNegativeStock()) {
        const negItems = getNegativeStockItems();
        errors.value.negative_stock = `Stok akan menjadi negatif untuk: ${negItems.map((d) => d.product?.nama_produk || 'Unknown').join(', ')}`;
    }

    return Object.keys(errors.value).filter((k) => k !== 'negative_stock').length === 0;
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
            tanggal: toDateTimeString(form.value.tanggal),
            keterangan: form.value.keterangan || null,
            details: form.value.details.map((d) => ({
                product_id: d.product_id,
                jenis: d.jenis,
                qty: d.is_serial ? d.serial_unit_ids?.length || 0 : d.qty,
                notes: d.notes || null,
                serial_unit_ids: d.is_serial ? d.serial_unit_ids || [] : null,
                serial_unit_statuses: d.is_serial ? d.serial_unit_statuses || {} : null
            }))
        };

        let response;
        if (isEdit.value) {
            response = await adjustmentsApi.update(route.params.ulid, payload);
        } else {
            response = await adjustmentsApi.create(payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Adjustment', isEdit.value);
            router.push({ name: 'inventory-adjustment' });
        }
    } catch (error) {
        console.error('Failed to save adjustment:', error);
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
    router.push({ name: 'inventory-adjustment' });
}

function getProductLabel(product) {
    if (!product) return '';
    return `${product.kode_produk} - ${product.nama_produk}`;
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
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Warehouse -->
                <div class="flex flex-col gap-2">
                    <label for="warehouse" class="font-medium">Warehouse <span class="text-red-500">*</span></label>
                    <Select id="warehouse" v-model="form.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Pilih Warehouse" filter class="w-full" :class="{ 'p-invalid': errors.warehouse_id }" />
                    <small v-if="errors.warehouse_id" class="text-red-500">{{ errors.warehouse_id }}</small>
                </div>

                <!-- Tanggal -->
                <div class="flex flex-col gap-2">
                    <label for="tanggal" class="font-medium">Tanggal & Jam <span class="text-red-500">*</span></label>
                    <DatePicker id="tanggal" v-model="form.tanggal" showTime hourFormat="24" :maxDate="new Date()" :dateFormat="getPrimeDateFormatShort" class="w-full" :class="{ 'p-invalid': errors.tanggal }" showIcon />
                    <small v-if="errors.tanggal" class="text-red-500">{{ errors.tanggal }}</small>
                </div>

                <!-- Placeholder for alignment -->
                <div></div>
            </div>

            <!-- Keterangan -->
            <div class="flex flex-col gap-2 mb-6">
                <label for="keterangan" class="font-medium">Keterangan</label>
                <Textarea id="keterangan" v-model="form.keterangan" rows="2" class="w-full" placeholder="Alasan adjustment..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
            </div>

            <!-- Details Section -->
            <div class="border border-surface-200 rounded-lg p-4">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <h3 class="text-lg font-medium m-0">Detail Produk</h3>
                    <div class="flex items-center gap-2">
                        <IconField iconPosition="left">
                            <InputIcon class="pi pi-qrcode" />
                            <InputText v-model="scanProduk" @keyup.enter="onScanProduk" placeholder="Scan barcode produk lalu Enter…" :disabled="!form.warehouse_id" style="width: 240px" />
                        </IconField>
                        <Button label="Tambah" icon="pi pi-plus" size="small" @click="addDetail" />
                    </div>
                </div>
                <small v-if="scanProdukFeedback" :class="scanProdukFeedback.ok ? 'text-green-600' : 'text-red-500'" class="block mb-2 text-xs">{{ scanProdukFeedback.msg }}</small>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <!-- Warning for negative stock -->
                <Message v-if="!negativeStockAllowed && hasNegativeStock()" severity="warn" class="mb-4">
                    <span>Perhatian: Beberapa produk akan memiliki stok negatif setelah adjustment. Adjustment tidak dapat disetujui jika stok negatif tidak diizinkan.</span>
                </Message>
                <Message v-else-if="negativeStockAllowed && hasNegativeStock()" severity="info" class="mb-4">
                    <span>Info: Beberapa produk akan memiliki stok negatif. Stok negatif diizinkan oleh sistem.</span>
                </Message>

                <!-- Detail Table -->
                <DataTable :value="form.details" class="p-datatable-sm" responsiveLayout="scroll" v-if="form.details.length > 0" dataKey="_uid" v-model:expandedRows="expandedRows">
                    <Column expander style="width: 3rem" />
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
                                        <span class="text-xs text-surface-400">Stok: {{ formatQty(option.stok) }}</span>
                                    </div>
                                </template>
                            </AutoComplete>
                            <small v-if="errors[`details.${index}.product_id`]" class="text-red-500">
                                {{ errors[`details.${index}.product_id`] }}
                            </small>
                        </template>
                    </Column>

                    <Column header="Stok Sistem" style="width: 100px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-medium">{{ formatQty(data.stok_sistem) }}</span>
                        </template>
                    </Column>

                    <Column header="Jenis" style="width: 140px">
                        <template #body="{ data, index }">
                            <Select
                                v-model="data.jenis"
                                :options="jenisOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full"
                                :disabled="data.is_serial"
                                @update:modelValue="() => updateStokAkhir(index)"
                                v-tooltip.top="data.is_serial ? 'Produk serial hanya bisa keluar (kredit)' : ''"
                            />
                        </template>
                    </Column>

                    <Column header="Qty" style="width: 110px">
                        <template #body="{ data, index }">
                            <div v-if="data.is_serial">
                                <Tag :value="`${data.serial_unit_ids?.length || 0} unit`" severity="info" />
                                <div class="text-xs text-surface-500 mt-1">dari pilih unit ↓</div>
                            </div>
                            <InputNumber
                                v-else
                                v-select-on-focus
                                v-model="data.qty"
                                :min="1"
                                :locale="getLocale"
                                :minFractionDigits="getQtyMinFractionDigits"
                                :maxFractionDigits="getQtyMaxFractionDigits"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.qty`] }"
                                @update:modelValue="() => updateStokAkhir(index)"
                            />
                            <small v-if="errors[`details.${index}.qty`]" class="text-red-500 block">{{ errors[`details.${index}.qty`] }}</small>
                        </template>
                    </Column>

                    <Column header="Stok Akhir" style="width: 100px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span
                                class="font-medium"
                                :class="{
                                    'text-red-500': data.stok_akhir < 0,
                                    'text-green-600': data.jenis === 'debit'
                                }"
                            >
                                {{ formatQty(data.stok_akhir) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Notes" style="min-width: 150px">
                        <template #body="{ data }">
                            <InputText v-model="data.notes" class="w-full" placeholder="Catatan..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                        </template>
                    </Column>

                    <Column header="" style="width: 50px">
                        <template #body="{ index }">
                            <Button icon="pi pi-trash" severity="danger" text rounded @click="removeDetail(index)" />
                        </template>
                    </Column>

                    <!-- Pemilih unit serial (kredit/keluar) tepat di bawah baris produknya -->
                    <template #expansion="{ data }">
                        <div v-if="data.is_serial && data.product_id" class="px-4 py-3 bg-surface-50 dark:bg-surface-800">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="pi pi-qrcode text-primary"></i>
                                <span class="font-medium text-sm"> Pilih Unit Serial yang Dikeluarkan — {{ data.product?.kode_produk }} {{ data.product?.nama_produk }} </span>
                            </div>
                            <SerialUnitPicker
                                :productId="data.product?.ulid"
                                :warehouseId="form.warehouse_id"
                                :modelValue="data.serial_unit_ids || []"
                                @update:modelValue="(v) => onSerialChange(data, v)"
                                @change="(units) => onSerialUnitsObjs(data, units)"
                            />

                            <!-- Status fate per unit terpilih (Rusak/Hilang) -->
                            <div v-if="(data.serial_unit_objs || []).length" class="mt-3">
                                <div class="text-xs font-medium mb-1">Status unit keluar (per unit):</div>
                                <div class="flex flex-col gap-1">
                                    <div v-for="u in data.serial_unit_objs" :key="u.ulid" class="flex items-center gap-2 text-xs">
                                        <span class="font-mono font-medium">{{ u.kode_internal || u.serial_number }}</span>
                                        <span class="text-surface-500 font-mono">SN {{ u.serial_number }}</span>
                                        <Select v-model="data.serial_unit_statuses[u.ulid]" :options="serialOutStatusOptions" optionLabel="label" optionValue="value" class="ml-auto w-32" />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-4 py-2 text-xs text-surface-400">Produk non-serial — tanpa pemilihan unit.</div>
                    </template>
                </DataTable>

                <!-- Empty State -->
                <div v-else class="text-center py-8 text-surface-500">
                    <i class="pi pi-box text-4xl mb-4 block"></i>
                    <p class="m-0">Belum ada detail produk. Klik "Tambah" untuk menambahkan.</p>
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
