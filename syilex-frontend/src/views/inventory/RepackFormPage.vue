<script setup>
import { repacksApi, warehousesApi } from '@/api';
import { useConfirm } from 'primevue/useconfirm';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const notify = useNotification();
const confirm = useConfirm();
const router = useRouter();
const route = useRoute();
const { formatQty, formatCurrency, shouldUppercase, getLocale, getPrimeDateFormatShort, toDateTimeString, now, parseDateTime, isAfterNow, getQtyMinFractionDigits, getQtyMaxFractionDigits, currencySettings } = useFormatters();

// Mode
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Repack' : 'Buat Repack'));

// Data
const warehouses = ref([]);
const loading = ref(false);
const saving = ref(false);
const negativeStockAllowed = ref(false);

// Form
const form = ref({
    warehouse_id: null,
    tipe: 'pecah',
    tanggal: now(),
    biaya_repack: 0,
    notes: '',
    inputs: [],
    outputs: []
});

// Tipe options
const tipeOptions = ref([
    { label: 'Pecah (1 → banyak)', value: 'pecah' },
    { label: 'Gabung (banyak → 1)', value: 'gabung' }
]);

// Store previous tipe for confirmation
const previousTipe = ref(null);

// Computed: Max items based on tipe
const maxInputs = computed(() => (form.value.tipe === 'pecah' ? 1 : Infinity));
const maxOutputs = computed(() => (form.value.tipe === 'gabung' ? 1 : Infinity));
const canAddInput = computed(() => form.value.inputs.length < maxInputs.value);
const canAddOutput = computed(() => form.value.outputs.length < maxOutputs.value);

// Watch tipe change - reset items when tipe changes
watch(
    () => form.value.tipe,
    (newVal, oldVal) => {
        if (oldVal && newVal !== oldVal && (form.value.inputs.length > 0 || form.value.outputs.length > 0)) {
            previousTipe.value = oldVal;
            confirm.require({
                message: 'Mengubah tipe repack akan mereset semua data bahan dan hasil. Lanjutkan?',
                header: 'Konfirmasi',
                icon: 'pi pi-exclamation-triangle',
                rejectLabel: 'Batal',
                acceptLabel: 'Ya, Lanjutkan',
                rejectClass: 'p-button-secondary p-button-outlined',
                accept: () => {
                    form.value.inputs = [];
                    form.value.outputs = [];
                    previousTipe.value = null;
                    notify.dataReset('bahan dan hasil');
                },
                reject: () => {
                    form.value.tipe = previousTipe.value;
                    previousTipe.value = null;
                }
            });
        }
    }
);

// Product search
const productSuggestions = ref([]);
const loadingProducts = ref(false);

// Validation
const errors = ref({});

onMounted(async () => {
    await Promise.all([loadWarehouses(), loadStockSetting()]);

    if (isEdit.value) {
        await loadRepack();
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
        const response = await repacksApi.getStockSetting();
        if (response.data.success) {
            negativeStockAllowed.value = response.data.data.negative_stock_allowed;
        }
    } catch (error) {
        console.error('Failed to load stock setting:', error);
        notify.apiError(error, 'Gagal load stock setting');
    }
}

async function loadRepack() {
    loading.value = true;
    try {
        const response = await repacksApi.get(route.params.ulid);
        if (response.data.success) {
            const repack = response.data.data.repack;

            // Check if still draft
            if (repack.status !== 'draft') {
                notify.cannotEditApproved('Repack');
                router.push({ name: 'inventory-repack' });
                return;
            }

            form.value = {
                warehouse_id: repack.warehouse_id,
                tipe: repack.tipe,
                tanggal: parseDateTime(repack.tanggal),
                biaya_repack: parseFloat(repack.biaya_repack) || 0,
                notes: repack.notes || '',
                inputs: repack.inputs.map((d) => ({
                    product_id: d.product_id,
                    product: d.product,
                    qty: d.qty,
                    stok: 0,
                    avg_cost: 0
                })),
                outputs: repack.outputs.map((d) => ({
                    product_id: d.product_id,
                    product: d.product,
                    qty: d.qty
                }))
            };

            // Refresh stock info for each input
            await refreshStockInfo();
        }
    } catch (error) {
        console.error('Failed to load repack:', error);
        notify.loadListError('repack');
        router.push({ name: 'inventory-repack' });
    } finally {
        loading.value = false;
    }
}

// Refresh stock info for existing inputs
async function refreshStockInfo() {
    if (!form.value.warehouse_id) return;

    for (const input of form.value.inputs) {
        if (input.product_id) {
            try {
                const response = await repacksApi.getProducts({
                    warehouse_id: form.value.warehouse_id,
                    search: input.product?.kode_produk
                });
                if (response.data.success) {
                    const found = response.data.data.items.find((p) => p.id === input.product_id);
                    if (found) {
                        input.stok = found.stok;
                        input.avg_cost = found.avg_cost;
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
const previousWarehouseId = ref(null);

// Watch warehouse change - reset inputs when warehouse changes
watch(
    () => form.value.warehouse_id,
    (newVal, oldVal) => {
        if (oldVal && newVal !== oldVal && form.value.inputs.length > 0) {
            previousWarehouseId.value = oldVal;
            confirm.require({
                message: 'Mengubah gudang akan mereset semua bahan input. Lanjutkan?',
                header: 'Konfirmasi',
                icon: 'pi pi-exclamation-triangle',
                rejectLabel: 'Batal',
                acceptLabel: 'Ya, Lanjutkan',
                rejectClass: 'p-button-secondary p-button-outlined',
                accept: () => {
                    form.value.inputs = [];
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
        notify.selectFirst('gudang');
        return;
    }

    loadingProducts.value = true;
    try {
        const response = await repacksApi.getProducts({
            warehouse_id: form.value.warehouse_id,
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

function onInputProductSelect(event, index) {
    const product = event.value || event;
    if (product && typeof product === 'object' && product.id) {
        // Check if product already in inputs
        const exists = form.value.inputs.some((d, i) => i !== index && d.product_id === product.id);
        if (exists) {
            notify.duplicate('Produk', 'bahan');
            return;
        }

        // Check if product is in outputs
        const inOutputs = form.value.outputs.some((d) => d.product_id === product.id);
        if (inOutputs) {
            notify.conflict('Produk', 'hasil output');
            return;
        }

        const currentInput = form.value.inputs[index];
        form.value.inputs[index] = {
            ...currentInput,
            product_id: product.id,
            product: product,
            stok: product.stok ?? 0,
            avg_cost: product.avg_cost ?? 0,
            qty: currentInput.qty || 1
        };
    }
}

function onOutputProductSelect(event, index) {
    const product = event.value || event;
    if (product && typeof product === 'object' && product.id) {
        // Check if product already in outputs
        const exists = form.value.outputs.some((d, i) => i !== index && d.product_id === product.id);
        if (exists) {
            notify.duplicate('Produk', 'hasil');
            return;
        }

        // Check if product is in inputs
        const inInputs = form.value.inputs.some((d) => d.product_id === product.id);
        if (inInputs) {
            notify.conflict('Produk', 'bahan input');
            return;
        }

        const currentOutput = form.value.outputs[index];
        form.value.outputs[index] = {
            ...currentOutput,
            product_id: product.id,
            product: product,
            qty: currentOutput.qty || 1
        };
    }
}

function addInput() {
    if (!form.value.warehouse_id) {
        notify.selectFirst('gudang');
        return;
    }

    if (!canAddInput.value) {
        notify.warn(form.value.tipe === 'pecah' ? 'Tipe Pecah hanya boleh 1 produk bahan' : 'Batas maksimal bahan tercapai');
        return;
    }

    form.value.inputs.push({
        product_id: null,
        product: null,
        stok: 0,
        avg_cost: 0,
        qty: 1
    });
}

function removeInput(index) {
    form.value.inputs.splice(index, 1);
}

function addOutput() {
    if (!canAddOutput.value) {
        notify.warn(form.value.tipe === 'gabung' ? 'Tipe Gabung hanya boleh 1 produk hasil' : 'Batas maksimal hasil tercapai');
        return;
    }

    form.value.outputs.push({
        product_id: null,
        product: null,
        qty: 1
    });
}

function removeOutput(index) {
    form.value.outputs.splice(index, 1);
}

// Computed: Calculate total input cost (estimated)
const estimatedTotalInputCost = computed(() => {
    return form.value.inputs.reduce((sum, input) => {
        return sum + input.qty * (input.avg_cost || 0);
    }, 0);
});

// Computed: Calculate total output cost (estimated)
const estimatedTotalOutputCost = computed(() => {
    return estimatedTotalInputCost.value + (form.value.biaya_repack || 0);
});

// Computed: Calculate estimated HPP per output unit
const estimatedHppPerOutput = computed(() => {
    const totalOutputQty = form.value.outputs.reduce((sum, o) => sum + (o.qty || 0), 0);
    if (totalOutputQty === 0) return 0;
    return estimatedTotalOutputCost.value / totalOutputQty;
});

function hasInsufficientStock() {
    return form.value.inputs.some((d) => d.qty > d.stok);
}

function validate() {
    errors.value = {};

    if (!form.value.warehouse_id) {
        errors.value.warehouse_id = 'Gudang wajib dipilih';
    }

    if (!form.value.tipe) {
        errors.value.tipe = 'Tipe wajib dipilih';
    }

    if (!form.value.tanggal) {
        errors.value.tanggal = 'Tanggal wajib diisi';
    } else if (isAfterNow(form.value.tanggal)) {
        errors.value.tanggal = 'Tanggal tidak boleh lebih dari waktu sekarang';
    }

    if (form.value.biaya_repack < 0) {
        errors.value.biaya_repack = 'Biaya repack tidak boleh negatif';
    }

    if (form.value.inputs.length === 0) {
        errors.value.inputs = 'Minimal harus ada 1 bahan input';
    } else if (form.value.tipe === 'pecah' && form.value.inputs.length > 1) {
        errors.value.inputs = 'Tipe Pecah hanya boleh 1 produk bahan';
    }

    if (form.value.outputs.length === 0) {
        errors.value.outputs = 'Minimal harus ada 1 hasil output';
    } else if (form.value.tipe === 'gabung' && form.value.outputs.length > 1) {
        errors.value.outputs = 'Tipe Gabung hanya boleh 1 produk hasil';
    }

    // Validate each input
    form.value.inputs.forEach((input, index) => {
        if (!input.product_id) {
            errors.value[`inputs.${index}.product_id`] = 'Produk wajib dipilih';
        }
        if (!input.qty || input.qty < 1) {
            errors.value[`inputs.${index}.qty`] = 'Qty minimal 1';
        }
    });

    // Validate each output
    form.value.outputs.forEach((output, index) => {
        if (!output.product_id) {
            errors.value[`outputs.${index}.product_id`] = 'Produk wajib dipilih';
        }
        if (!output.qty || output.qty < 1) {
            errors.value[`outputs.${index}.qty`] = 'Qty minimal 1';
        }
    });

    // Check for duplicate products in inputs
    const inputProductIds = form.value.inputs.map((d) => d.product_id).filter(Boolean);
    const uniqueInputIds = [...new Set(inputProductIds)];
    if (inputProductIds.length !== uniqueInputIds.length) {
        errors.value.inputs = 'Tidak boleh ada produk bahan yang sama';
    }

    // Check for duplicate products in outputs
    const outputProductIds = form.value.outputs.map((d) => d.product_id).filter(Boolean);
    const uniqueOutputIds = [...new Set(outputProductIds)];
    if (outputProductIds.length !== uniqueOutputIds.length) {
        errors.value.outputs = 'Tidak boleh ada produk hasil yang sama';
    }

    // Check for overlapping products between input and output
    const overlap = inputProductIds.filter((id) => outputProductIds.includes(id));
    if (overlap.length > 0) {
        errors.value.outputs = 'Produk hasil tidak boleh sama dengan produk bahan';
    }

    // Check insufficient stock (warning only if not allowed)
    if (!negativeStockAllowed.value && hasInsufficientStock()) {
        errors.value.insufficient_stock = 'Stok bahan tidak mencukupi';
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
            warehouse_id: form.value.warehouse_id,
            tipe: form.value.tipe,
            tanggal: toDateTimeString(form.value.tanggal),
            biaya_repack: form.value.biaya_repack || 0,
            notes: form.value.notes || null,
            inputs: form.value.inputs.map((d) => ({
                product_id: d.product_id,
                qty: d.qty
            })),
            outputs: form.value.outputs.map((d) => ({
                product_id: d.product_id,
                qty: d.qty
            }))
        };

        let response;
        if (isEdit.value) {
            response = await repacksApi.update(route.params.ulid, payload);
        } else {
            response = await repacksApi.create(payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Repack', isEdit.value);
            router.push({ name: 'inventory-repack' });
        }
    } catch (error) {
        console.error('Failed to save repack:', error);
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
    router.push({ name: 'inventory-repack' });
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <!-- Warehouse -->
                <div class="flex flex-col gap-2">
                    <label for="warehouse" class="font-medium">Gudang <span class="text-red-500">*</span></label>
                    <Select id="warehouse" v-model="form.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Pilih Gudang" filter class="w-full" :class="{ 'p-invalid': errors.warehouse_id }" />
                    <small v-if="errors.warehouse_id" class="text-red-500">{{ errors.warehouse_id }}</small>
                </div>

                <!-- Tipe -->
                <div class="flex flex-col gap-2">
                    <label for="tipe" class="font-medium">Tipe <span class="text-red-500">*</span></label>
                    <Select id="tipe" v-model="form.tipe" :options="tipeOptions" optionLabel="label" optionValue="value" placeholder="Pilih Tipe" class="w-full" filter :class="{ 'p-invalid': errors.tipe }" />
                    <small v-if="errors.tipe" class="text-red-500">{{ errors.tipe }}</small>
                </div>

                <!-- Tanggal -->
                <div class="flex flex-col gap-2">
                    <label for="tanggal" class="font-medium">Tanggal & Jam <span class="text-red-500">*</span></label>
                    <DatePicker id="tanggal" v-model="form.tanggal" showTime hourFormat="24" :maxDate="new Date()" :dateFormat="getPrimeDateFormatShort" class="w-full" :class="{ 'p-invalid': errors.tanggal }" showIcon />
                    <small v-if="errors.tanggal" class="text-red-500">{{ errors.tanggal }}</small>
                </div>

                <!-- Biaya Repack -->
                <div class="flex flex-col gap-2">
                    <label for="biaya_repack" class="font-medium">Biaya Repack</label>
                    <InputNumber
                        v-select-on-focus
                        id="biaya_repack"
                        v-model="form.biaya_repack"
                        :locale="getLocale"
                        :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                        :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                        :minFractionDigits="currencySettings.decimalPlaces"
                        :maxFractionDigits="currencySettings.decimalPlaces"
                        :min="0"
                        class="w-full"
                        :class="{ 'p-invalid': errors.biaya_repack }"
                    />
                    <small v-if="errors.biaya_repack" class="text-red-500">{{ errors.biaya_repack }}</small>
                </div>
            </div>

            <!-- Notes -->
            <div class="flex flex-col gap-2 mb-6">
                <label for="notes" class="font-medium">Catatan</label>
                <Textarea id="notes" v-model="form.notes" rows="2" class="w-full" placeholder="Catatan repack..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- INPUT Section (Bahan) -->
                <div class="border border-red-200 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-medium m-0 text-red-600"><i class="pi pi-arrow-down mr-2"></i>Bahan Input</h3>
                            <Tag v-if="form.tipe === 'pecah'" severity="danger" value="Max 1 Produk" />
                        </div>
                        <Button label="Tambah" icon="pi pi-plus" size="small" severity="danger" outlined @click="addInput" :disabled="!canAddInput" />
                    </div>

                    <small v-if="errors.inputs" class="text-red-500 block mb-4">{{ errors.inputs }}</small>

                    <!-- Warning for insufficient stock -->
                    <Message v-if="!negativeStockAllowed && hasInsufficientStock()" severity="warn" class="mb-4">
                        <span>Perhatian: Stok bahan tidak mencukupi.</span>
                    </Message>

                    <!-- Input Table -->
                    <DataTable :value="form.inputs" class="p-datatable-sm" responsiveLayout="scroll" v-if="form.inputs.length > 0">
                        <Column header="#" style="width: 40px">
                            <template #body="{ index }">{{ index + 1 }}</template>
                        </Column>

                        <Column header="Produk Bahan" style="min-width: 200px">
                            <template #body="{ data, index }">
                                <AutoComplete
                                    v-model="data.product"
                                    :suggestions="productSuggestions"
                                    @complete="searchProducts"
                                    @item-select="(e) => onInputProductSelect(e, index)"
                                    :optionLabel="getProductLabel"
                                    placeholder="Cari produk..."
                                    :loading="loadingProducts"
                                    class="w-full"
                                    :class="{ 'p-invalid': errors[`inputs.${index}.product_id`] }"
                                    dropdown
                                >
                                    <template #option="{ option }">
                                        <div class="flex flex-col">
                                            <span class="font-medium">{{ option.kode_produk }}</span>
                                            <span class="text-sm text-surface-500">{{ option.nama_produk }}</span>
                                            <span class="text-xs text-surface-400">Stok: {{ formatQty(option.stok) }} | HPP: {{ formatCurrency(option.avg_cost) }}</span>
                                        </div>
                                    </template>
                                </AutoComplete>
                            </template>
                        </Column>

                        <Column header="Stok" style="width: 80px" bodyClass="text-right">
                            <template #body="{ data }">
                                <span :class="{ 'text-red-500': data.stok < data.qty }">
                                    {{ formatQty(data.stok) }}
                                </span>
                            </template>
                        </Column>

                        <Column header="Qty" style="width: 100px">
                            <template #body="{ data, index }">
                                <InputNumber
                                    v-select-on-focus
                                    v-model="data.qty"
                                    :min="1"
                                    :locale="getLocale"
                                    :minFractionDigits="getQtyMinFractionDigits"
                                    :maxFractionDigits="getQtyMaxFractionDigits"
                                    class="w-full"
                                    :class="{ 'p-invalid': errors[`inputs.${index}.qty`] || (!negativeStockAllowed && data.qty > data.stok) }"
                                />
                            </template>
                        </Column>

                        <Column header="" style="width: 50px">
                            <template #body="{ index }">
                                <Button icon="pi pi-trash" severity="danger" text rounded @click="removeInput(index)" />
                            </template>
                        </Column>
                    </DataTable>

                    <!-- Empty State -->
                    <div v-else class="text-center py-6 text-surface-500">
                        <i class="pi pi-box text-3xl mb-3 block"></i>
                        <p class="m-0 text-sm">Belum ada bahan input</p>
                    </div>

                    <!-- Input Summary -->
                    <div class="mt-4 pt-4 border-t border-red-200" v-if="form.inputs.length > 0">
                        <div class="flex justify-between text-sm">
                            <span class="text-surface-600">Estimasi Total HPP Input:</span>
                            <span class="font-bold text-red-600">{{ formatCurrency(estimatedTotalInputCost) }}</span>
                        </div>
                    </div>
                </div>

                <!-- OUTPUT Section (Hasil) -->
                <div class="border border-green-200 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-medium m-0 text-green-600"><i class="pi pi-arrow-up mr-2"></i>Hasil Output</h3>
                            <Tag v-if="form.tipe === 'gabung'" severity="success" value="Max 1 Produk" />
                        </div>
                        <Button label="Tambah" icon="pi pi-plus" size="small" severity="success" outlined @click="addOutput" :disabled="!canAddOutput" />
                    </div>

                    <small v-if="errors.outputs" class="text-red-500 block mb-4">{{ errors.outputs }}</small>

                    <!-- Output Table -->
                    <DataTable :value="form.outputs" class="p-datatable-sm" responsiveLayout="scroll" v-if="form.outputs.length > 0">
                        <Column header="#" style="width: 40px">
                            <template #body="{ index }">{{ index + 1 }}</template>
                        </Column>

                        <Column header="Produk Hasil" style="min-width: 200px">
                            <template #body="{ data, index }">
                                <AutoComplete
                                    v-model="data.product"
                                    :suggestions="productSuggestions"
                                    @complete="searchProducts"
                                    @item-select="(e) => onOutputProductSelect(e, index)"
                                    :optionLabel="getProductLabel"
                                    placeholder="Cari produk..."
                                    :loading="loadingProducts"
                                    class="w-full"
                                    :class="{ 'p-invalid': errors[`outputs.${index}.product_id`] }"
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

                        <Column header="Qty Hasil" style="width: 100px">
                            <template #body="{ data, index }">
                                <InputNumber
                                    v-select-on-focus
                                    v-model="data.qty"
                                    :min="1"
                                    :locale="getLocale"
                                    :minFractionDigits="getQtyMinFractionDigits"
                                    :maxFractionDigits="getQtyMaxFractionDigits"
                                    class="w-full"
                                    :class="{ 'p-invalid': errors[`outputs.${index}.qty`] }"
                                />
                            </template>
                        </Column>

                        <Column header="" style="width: 50px">
                            <template #body="{ index }">
                                <Button icon="pi pi-trash" severity="danger" text rounded @click="removeOutput(index)" />
                            </template>
                        </Column>
                    </DataTable>

                    <!-- Empty State -->
                    <div v-else class="text-center py-6 text-surface-500">
                        <i class="pi pi-box text-3xl mb-3 block"></i>
                        <p class="m-0 text-sm">Belum ada hasil output</p>
                    </div>

                    <!-- Output Summary -->
                    <div class="mt-4 pt-4 border-t border-green-200" v-if="form.outputs.length > 0">
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-surface-600">Estimasi Total HPP Output:</span>
                            <span class="font-bold text-green-600">{{ formatCurrency(estimatedTotalOutputCost) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-surface-600">Estimasi HPP/Unit Output:</span>
                            <span class="font-bold text-green-600">{{ formatCurrency(estimatedHppPerOutput) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end gap-2 mt-6">
                <Button label="Batal" severity="secondary" outlined @click="cancel" />
                <Button label="Simpan" icon="pi pi-save" type="submit" :loading="saving" :disabled="form.inputs.length === 0 || form.outputs.length === 0" />
            </div>
        </form>
    </div>
</template>
