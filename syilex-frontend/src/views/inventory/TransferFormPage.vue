<script setup>
import { transfersApi, warehousesApi } from '@/api';
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
const {
    formatQty,
    formatCurrency,
    shouldUppercase,
    getPrimeDateFormatShort,
    toDateTimeString,
    now,
    parseDateTime,
    isAfterNow,
    getLocale,
    getQtyMinFractionDigits,
    getQtyMaxFractionDigits,
    getCurrencyMinFractionDigits,
    getCurrencyMaxFractionDigits,
    currencySettings
} = useFormatters();

// Props InputNumber mata uang (samakan dengan form lain)
const moneyProps = {
    min: 0,
    prefix: currencySettings.value.position === 'before' ? currencySettings.value.symbol + ' ' : '',
    suffix: currencySettings.value.position === 'after' ? ' ' + currencySettings.value.symbol : '',
    locale: getLocale.value,
    minFractionDigits: getCurrencyMinFractionDigits.value,
    maxFractionDigits: getCurrencyMaxFractionDigits.value
};

// Total biaya tambahan (untuk hint)
const totalBiaya = computed(() => (Number(form.value.biaya_kirim) || 0) + (Number(form.value.biaya_lain) || 0));

// Mode
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Transfer Antar Gudang' : 'Buat Transfer Antar Gudang'));

// Data
const warehouses = ref([]);
const loading = ref(false);
const saving = ref(false);
const negativeStockAllowed = ref(false);

// Form
const form = ref({
    warehouse_from_id: null,
    warehouse_to_id: null,
    tanggal: now(),
    notes: '',
    biaya_kirim: 0,
    biaya_lain: 0,
    biaya_lain_nama: '',
    masuk_hpp: false,
    details: []
});

// Product search
const productSuggestions = ref([]);
const loadingProducts = ref(false);

// Validation
const errors = ref({});

// Row expansion: baris produk serial auto-expand → pemilih unit tampil tepat di bawah parent
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

// Computed: Filter warehouses for destination (exclude source)
const warehouseToOptions = computed(() => {
    if (!form.value.warehouse_from_id) return warehouses.value;
    return warehouses.value.filter((w) => w.id !== form.value.warehouse_from_id);
});

onMounted(async () => {
    await Promise.all([loadWarehouses(), loadStockSetting()]);

    if (isEdit.value) {
        await loadTransfer();
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
        const response = await transfersApi.getStockSetting();
        if (response.data.success) {
            negativeStockAllowed.value = response.data.data.negative_stock_allowed;
        }
    } catch (error) {
        console.error('Failed to load stock setting:', error);
        notify.apiError(error, 'Gagal load stock setting');
    }
}

async function loadTransfer() {
    loading.value = true;
    try {
        const response = await transfersApi.get(route.params.ulid);
        if (response.data.success) {
            const transfer = response.data.data.transfer;

            // Check if still draft
            if (transfer.status !== 'draft') {
                notify.cannotEditApproved('Transfer');
                router.push({ name: 'inventory-transfer' });
                return;
            }

            form.value = {
                warehouse_from_id: transfer.warehouse_from_id,
                warehouse_to_id: transfer.warehouse_to_id,
                tanggal: parseDateTime(transfer.tanggal),
                notes: transfer.notes || '',
                biaya_kirim: Number(transfer.biaya_kirim) || 0,
                biaya_lain: Number(transfer.biaya_lain) || 0,
                biaya_lain_nama: transfer.biaya_lain_nama || '',
                masuk_hpp: !!transfer.masuk_hpp,
                details: transfer.details.map((d) => ({
                    _uid: nextUid(),
                    product_id: d.product_id,
                    product: d.product,
                    qty: d.qty,
                    stok: 0, // Will be refreshed
                    is_serial: !!d.product?.is_serial,
                    serial_unit_ids: d.serial_unit_ids || (d.product?.is_serial ? [] : null)
                }))
            };

            // Refresh stock info for each detail
            await refreshStockInfo();
        }
    } catch (error) {
        console.error('Failed to load transfer:', error);
        notify.loadListError('transfer');
        router.push({ name: 'inventory-transfer' });
    } finally {
        loading.value = false;
    }
}

// Refresh stock info for existing details
async function refreshStockInfo() {
    if (!form.value.warehouse_from_id) return;

    for (const detail of form.value.details) {
        if (detail.product_id) {
            try {
                const response = await transfersApi.getProducts({
                    warehouse_from_id: form.value.warehouse_from_id,
                    search: detail.product?.kode_produk
                });
                if (response.data.success) {
                    const found = response.data.data.items.find((p) => p.id === detail.product_id);
                    if (found) {
                        detail.stok = found.stok;
                    }
                }
            } catch (error) {
                console.error('Failed to refresh stock:', error);
                notify.apiError(error, 'Gagal refresh stock');
            }
        }
    }
}

// Store previous warehouse for confirmation
const previousWarehouseFromId = ref(null);

// Watch source warehouse change - reset details when warehouse changes
watch(
    () => form.value.warehouse_from_id,
    (newVal, oldVal) => {
        // Reset destination if same as source
        if (form.value.warehouse_to_id === newVal) {
            form.value.warehouse_to_id = null;
        }

        if (oldVal && newVal !== oldVal && form.value.details.length > 0) {
            previousWarehouseFromId.value = oldVal;
            confirm.require({
                message: 'Mengubah gudang asal akan mereset semua detail produk. Lanjutkan?',
                header: 'Konfirmasi',
                icon: 'pi pi-exclamation-triangle',
                rejectLabel: 'Batal',
                acceptLabel: 'Ya, Lanjutkan',
                rejectClass: 'p-button-secondary p-button-outlined',
                accept: () => {
                    form.value.details = [];
                    previousWarehouseFromId.value = null;
                },
                reject: () => {
                    form.value.warehouse_from_id = previousWarehouseFromId.value;
                    previousWarehouseFromId.value = null;
                }
            });
        }
    }
);

// Product autocomplete
async function searchProducts(event) {
    if (!form.value.warehouse_from_id) {
        notify.selectFirst('gudang asal');
        return;
    }

    loadingProducts.value = true;
    try {
        const response = await transfersApi.getProducts({
            warehouse_from_id: form.value.warehouse_from_id,
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

        form.value.details[index] = {
            ...currentDetail,
            product_id: product.id,
            product: product,
            stok: product.stok ?? 0,
            qty: currentDetail.qty || 1,
            is_serial: !!product.is_serial,
            serial_unit_ids: product.is_serial ? [] : null
        };
    }
}

function addDetail() {
    if (!form.value.warehouse_from_id) {
        notify.selectFirst('gudang asal');
        return;
    }

    form.value.details.push({
        _uid: nextUid(),
        product_id: null,
        product: null,
        stok: 0,
        qty: 1,
        is_serial: false,
        serial_unit_ids: null
    });
}

function removeDetail(index) {
    form.value.details.splice(index, 1);
}

function hasInsufficientStock() {
    return form.value.details.some((d) => d.qty > d.stok);
}

function getInsufficientStockItems() {
    return form.value.details.filter((d) => d.qty > d.stok);
}

function validate() {
    errors.value = {};

    if (!form.value.warehouse_from_id) {
        errors.value.warehouse_from_id = 'Gudang asal wajib dipilih';
    }

    if (!form.value.warehouse_to_id) {
        errors.value.warehouse_to_id = 'Gudang tujuan wajib dipilih';
    }

    if (form.value.warehouse_from_id && form.value.warehouse_to_id && form.value.warehouse_from_id === form.value.warehouse_to_id) {
        errors.value.warehouse_to_id = 'Gudang tujuan harus berbeda dengan gudang asal';
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
            if (!detail.serial_unit_ids || detail.serial_unit_ids.length < 1) {
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
        errors.value.details = 'Tidak boleh ada produk yang sama dalam satu transfer';
    }

    // Check insufficient stock (warning only if not allowed)
    if (!negativeStockAllowed.value && hasInsufficientStock()) {
        const insufficientItems = getInsufficientStockItems();
        errors.value.insufficient_stock = `Stok tidak mencukupi untuk: ${insufficientItems.map((d) => d.product?.nama_produk || 'Unknown').join(', ')}`;
    }

    return Object.keys(errors.value).filter((k) => k !== 'insufficient_stock').length === 0;
}

async function save() {
    if (!validate()) {
        notify.formInvalid();
        return;
    }

    saving.value = true;
    try {
        const payload = {
            warehouse_from_id: form.value.warehouse_from_id,
            warehouse_to_id: form.value.warehouse_to_id,
            tanggal: toDateTimeString(form.value.tanggal),
            notes: form.value.notes || null,
            biaya_kirim: Number(form.value.biaya_kirim) || 0,
            biaya_lain: Number(form.value.biaya_lain) || 0,
            biaya_lain_nama: form.value.biaya_lain_nama || null,
            masuk_hpp: !!form.value.masuk_hpp,
            details: form.value.details.map((d) => ({
                product_id: d.product_id,
                qty: d.is_serial ? d.serial_unit_ids?.length || 0 : d.qty,
                serial_unit_ids: d.is_serial ? d.serial_unit_ids || [] : null
            }))
        };

        let response;
        if (isEdit.value) {
            response = await transfersApi.update(route.params.ulid, payload);
        } else {
            response = await transfersApi.create(payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Transfer', isEdit.value);
            router.push({ name: 'inventory-transfer' });
        }
    } catch (error) {
        console.error('Failed to save transfer:', error);
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
    router.push({ name: 'inventory-transfer' });
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
                <!-- Warehouse From -->
                <div class="flex flex-col gap-2">
                    <label for="warehouse_from" class="font-medium">Gudang Asal <span class="text-red-500">*</span></label>
                    <Select
                        id="warehouse_from"
                        v-model="form.warehouse_from_id"
                        :options="warehouses"
                        optionLabel="nama_warehouse"
                        optionValue="id"
                        placeholder="Pilih Gudang Asal"
                        filter
                        class="w-full"
                        :class="{ 'p-invalid': errors.warehouse_from_id }"
                    />
                    <small v-if="errors.warehouse_from_id" class="text-red-500">{{ errors.warehouse_from_id }}</small>
                </div>

                <!-- Warehouse To -->
                <div class="flex flex-col gap-2">
                    <label for="warehouse_to" class="font-medium">Gudang Tujuan <span class="text-red-500">*</span></label>
                    <Select
                        id="warehouse_to"
                        v-model="form.warehouse_to_id"
                        :options="warehouseToOptions"
                        optionLabel="nama_warehouse"
                        optionValue="id"
                        placeholder="Pilih Gudang Tujuan"
                        filter
                        class="w-full"
                        :class="{ 'p-invalid': errors.warehouse_to_id }"
                    />
                    <small v-if="errors.warehouse_to_id" class="text-red-500">{{ errors.warehouse_to_id }}</small>
                </div>

                <!-- Tanggal -->
                <div class="flex flex-col gap-2">
                    <label for="tanggal" class="font-medium">Tanggal & Jam <span class="text-red-500">*</span></label>
                    <DatePicker id="tanggal" v-model="form.tanggal" showTime hourFormat="24" :maxDate="new Date()" :dateFormat="getPrimeDateFormatShort" class="w-full" :class="{ 'p-invalid': errors.tanggal }" showIcon />
                    <small v-if="errors.tanggal" class="text-red-500">{{ errors.tanggal }}</small>
                </div>
            </div>

            <!-- Notes -->
            <div class="flex flex-col gap-2 mb-6">
                <label for="notes" class="font-medium">Catatan</label>
                <Textarea id="notes" v-model="form.notes" rows="2" class="w-full" placeholder="Catatan transfer..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
            </div>

            <!-- Biaya Kirim & Biaya Lain -->
            <div class="border border-surface-200 rounded-lg p-4 mb-6">
                <h3 class="text-lg font-medium m-0 mb-4">Biaya Pengiriman (opsional)</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Biaya Kirim</label>
                        <InputNumber v-model="form.biaya_kirim" v-bind="moneyProps" fluid />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Biaya Lain</label>
                        <InputNumber v-model="form.biaya_lain" v-bind="moneyProps" fluid />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Nama Biaya Lain</label>
                        <InputText v-model="form.biaya_lain_nama" placeholder="mis. Asuransi, packing…" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                    </div>
                </div>

                <div class="flex items-start gap-2 mt-4">
                    <Checkbox v-model="form.masuk_hpp" :binary="true" inputId="masuk_hpp" :disabled="totalBiaya <= 0" />
                    <div class="flex flex-col">
                        <label for="masuk_hpp" class="font-medium cursor-pointer">Masukkan biaya ke HPP</label>
                        <small class="text-surface-500">
                            Dicentang → biaya dibagi adil ke tiap produk (proporsional nilai) dan menambah HPP: produk <b>serial</b> hanya unit yang dipindah; produk <b>non-serial</b> menaikkan HPP rata-rata global produk. Tidak dicentang → biaya
                            hanya dicatat sebagai informasi (HPP tidak berubah).
                        </small>
                    </div>
                </div>
                <small v-if="totalBiaya > 0" class="block mt-2 text-xs text-surface-500">
                    Total biaya: <b>{{ formatCurrency(totalBiaya) }}</b> — dialokasikan saat transfer disetujui.
                </small>
            </div>

            <!-- Details Section -->
            <div class="border border-surface-200 rounded-lg p-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium m-0">Detail Produk</h3>
                    <Button label="Tambah" icon="pi pi-plus" size="small" @click="addDetail" />
                </div>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <!-- Warning for insufficient stock -->
                <Message v-if="!negativeStockAllowed && hasInsufficientStock()" severity="warn" class="mb-4">
                    <span>Perhatian: Beberapa produk memiliki stok tidak mencukupi di gudang asal. Transfer tidak dapat disetujui jika stok negatif tidak diizinkan.</span>
                </Message>
                <Message v-else-if="negativeStockAllowed && hasInsufficientStock()" severity="info" class="mb-4">
                    <span>Info: Beberapa produk memiliki stok tidak mencukupi. Stok negatif diizinkan oleh sistem.</span>
                </Message>

                <!-- Detail Table -->
                <DataTable :value="form.details" class="p-datatable-sm" responsiveLayout="scroll" v-if="form.details.length > 0" dataKey="_uid" v-model:expandedRows="expandedRows">
                    <Column expander style="width: 3rem" />
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="Produk" style="min-width: 300px">
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

                    <Column header="Stok Tersedia" style="width: 120px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-medium" :class="{ 'text-red-500': data.stok < data.qty }">
                                {{ formatQty(data.stok) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Qty Transfer" style="width: 140px">
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
                                :class="{ 'p-invalid': errors[`details.${index}.qty`] || (!negativeStockAllowed && data.qty > data.stok) }"
                            />
                            <small v-if="errors[`details.${index}.qty`]" class="text-red-500 block">{{ errors[`details.${index}.qty`] }}</small>
                        </template>
                    </Column>

                    <Column header="" style="width: 50px">
                        <template #body="{ index }">
                            <Button icon="pi pi-trash" severity="danger" text rounded @click="removeDetail(index)" />
                        </template>
                    </Column>

                    <!-- Pemilih unit serial: tampil tepat di bawah baris produknya -->
                    <template #expansion="{ data }">
                        <div v-if="data.is_serial && data.product_id" class="px-4 py-3 bg-surface-50 dark:bg-surface-800">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="pi pi-qrcode text-primary"></i>
                                <span class="font-medium text-sm"> Pilih Unit Serial — {{ data.product?.kode_produk }} {{ data.product?.nama_produk }} </span>
                            </div>
                            <SerialUnitPicker :productId="data.product?.ulid" :warehouseId="form.warehouse_from_id" v-model="data.serial_unit_ids" />
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
